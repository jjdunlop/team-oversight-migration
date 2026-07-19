<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Trials {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_shortcode('team_trial_form', array($this, 'render_trial_form'));
        add_action('wp_ajax_submit_trial_application', array($this, 'handle_trial_submission'));
        add_action('wp_ajax_nopriv_submit_trial_application', array($this, 'handle_trial_submission'));
        add_action('wp_ajax_check_profile_status', array($this, 'check_profile_status'));
        add_action('wp_ajax_nopriv_check_profile_status', array($this, 'check_profile_status'));

        // Trial fee payment via WooCommerce: the form saves the application as
        // awaiting_payment, sends the user to checkout with the configured fee
        // product, and the paid order flips the application to pending.
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'attach_application_to_order_item'), 10, 3);
        add_action('woocommerce_order_status_processing', array($this, 'handle_trial_payment'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_trial_payment'));
        add_filter('woocommerce_get_item_data', array($this, 'display_application_in_cart'), 10, 2);
    }
    
    public function init() {

    }

    public static function get_history_options() {
        return array(
            'renegades_last_season' => 'I played with Renegades last season (no transfer needed)',
            'renegades_previously' => 'I have played with Renegades previously, and have not played VVL for any other club since leaving Renegades (no transfer needed)',
            'transfer' => 'I am transferring from a different club (transfer needed)',
            'never_played' => 'I have never played in Volleyball Victoria League',
        );
    }

    public static function get_level_options() {
        return array(
            'PL1' => 'Premier League 1',
            'PL2' => 'Premier League 2',
            'JPL' => 'Junior Premier League U/19',
            'SL1' => 'State League 1',
            'SL2' => 'State League 2',
            'SL3' => 'State League 3',
            'YSL17' => 'Youth State League U/17',
        );
    }

    public static function get_position_options() {
        return array(
            'middle' => 'Middle',
            'setter' => 'Setter',
            'libero' => 'Libero',
            'pass_hitter' => 'Pass Hitter / Opposite / Other Hitter',
            'not_sure' => 'Not sure yet',
        );
    }

    public static function get_venue_options() {
        return array(
            'all' => 'I can train at all venues',
            'ivanhoe' => 'Ivanhoe Grammar School',
            'maribyrnong' => 'Maribyrnong College',
            'mus' => 'Melbourne University Sport',
            'bundha' => 'Bundha Sports Centre',
        );
    }

    /**
     * VV competition (mens/womens) derived from the profile gender field.
     * Male -> mens, Female -> womens. Anything else — unset, Non-Binary,
     * Other, Prefer not to say — returns null and the form asks the
     * competition question directly, since VV only runs men's and women's
     * competitions and the choice is then the player's to make.
     */
    public static function get_competition_from_profile($user_id) {
        $gender = get_user_meta($user_id, 'gender', true);
        $gender = maybe_unserialize($gender);
        if (is_array($gender)) {
            $gender = reset($gender);
        }
        $gender = is_string($gender) ? trim($gender) : '';

        $map = array('male' => 'mens', 'female' => 'womens');
        $key = strtolower($gender);

        return array(
            'gender' => $gender,
            'competition' => isset($map[$key]) ? $map[$key] : null,
        );
    }

    /**
     * Account data shown read-only on the trial form. Never taken from the
     * submitted POST — always re-read from the account at submission time.
     */
    public static function get_prefill_data($user_id) {
        $user = get_userdata($user_id);
        return array(
            'first_name' => get_user_meta($user_id, 'first_name', true),
            'last_name' => get_user_meta($user_id, 'last_name', true),
            'email' => $user ? $user->user_email : '',
            'mobile' => get_user_meta($user_id, 'mobile_number', true),
            'birth_date' => get_user_meta($user_id, 'birth_date', true),
            'institution' => get_user_meta($user_id, 'institution1', true),
        );
    }
    
    public function render_trial_form($atts = array()) {
        if (!is_user_logged_in()) {
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            $register_url = function_exists('um_get_core_page') ? um_get_core_page('register') : wp_registration_url();
            return '<div class="trial-login-required" style="border: 2px solid #e0e0e0; border-radius: 8px; padding: 25px; background: #f9f9f9; max-width: 640px;">'
                . '<h3 style="margin-top: 0;">Trial registrations are linked to your club account</h3>'
                . '<p>Please log in before filling out the trial form. We do this so that:</p>'
                . '<ul style="margin: 10px 0 15px 20px; list-style: disc;">'
                . '<li>we have up-to-date <strong>contact and emergency details</strong> for you at trials and training;</li>'
                . '<li>your details are <strong>filled in for you</strong> — no retyping your name, phone number and date of birth;</li>'
                . '<li>your application, trial fee payment and any team offer are all <strong>kept together</strong> on your membership.</li>'
                . '</ul>'
                . '<p>'
                . '<a class="button button-primary" href="' . esc_url($login_url) . '">Log in to continue</a> '
                . '<a class="button" href="' . esc_url($register_url) . '" style="margin-left: 8px;">New to the club? Create an account</a>'
                . '</p>'
                . '</div>';
        }
        
        $user = wp_get_current_user();
        $database = new TeamOversight_Database();
        $fees = new TeamOversight_Fees();

        // Calculate MUS status and validate profile
        $mus_status = $fees->determine_fee_class($user->user_email);
        $profile_validation = $this->validate_user_profile($user->ID);

        // Their most recent live application, so the trial number is always
        // findable by revisiting this page.
        global $wpdb;
        $my_application = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE user_id = %d AND season >= %s
                AND application_status IN ('awaiting_payment', 'pending', 'accepted')
            ORDER BY created_date DESC
            LIMIT 1
        ", $user->ID, date('Y')));

        ob_start();
        ?>
        <div id="trial-application-form">
            <h3>Trial Application Form</h3>

            <?php if ($my_application): ?>
                <div class="trial-status-panel">
                    <div class="trial-number-badge">
                        <span class="trial-number-label">Your Trial Number</span>
                        <span class="trial-number-value">#<?php echo intval($my_application->trial_number); ?></span>
                    </div>
                    <div class="trial-status-text">
                        <?php if ($my_application->application_status === 'awaiting_payment'): ?>
                            <p><strong>Your <?php echo esc_html($my_application->season); ?> application has been received but is awaiting payment.</strong> Submit the form below again to return to the checkout, or contact the club if you believe you have already paid.</p>
                        <?php elseif ($my_application->application_status === 'accepted'): ?>
                            <?php
                            $assigned_config = $database->get_teams_config();
                            $assigned_name = isset($assigned_config[$my_application->assigned_team]) ? $assigned_config[$my_application->assigned_team]['name'] : $my_application->assigned_team;
                            ?>
                            <p><strong>Congratulations — you've been assigned to <?php echo esc_html($assigned_name); ?> for <?php echo esc_html($my_application->season); ?>.</strong></p>
                        <?php else: ?>
                            <p><strong>Your <?php echo esc_html($my_application->season); ?> trial application is confirmed.</strong> When trials are busy, a coach may ask you for your trial number to speed things up — many players write it on their hand or arm on the day.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- MUS Status Display Section -->
            <div id="mus-status-section" class="mus-status-container">
                <div class="mus-status-header">
                    <h4>Your Melbourne University Status (MUS)</h4>
                </div>
                
                <div class="mus-status-content">
                    <div class="mus-status-display <?php echo $profile_validation['is_complete'] ? 'complete' : 'incomplete'; ?>">
                        <div class="status-indicator">
                            <?php if ($profile_validation['is_complete']): ?>
                                <span class="status-icon complete">✓</span>
                                <strong>Calculated MUS: <?php echo esc_html($mus_status); ?></strong>
                            <?php else: ?>
                                <span class="status-icon incomplete">⚠</span>
                                <strong>Profile Incomplete</strong>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$profile_validation['is_complete']): ?>
                            <div class="missing-fields">
                                <p><strong>Please complete the following profile fields to calculate your MUS status:</strong></p>
                                <ul>
                                    <?php foreach ($profile_validation['missing_fields'] as $field): ?>
                                        <li><?php echo esc_html($field); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="mus-explanation">
                                <p>Your fee category has been calculated based on your profile information. If this doesn't look correct, please update your profile.</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="profile-actions">
                            <a href="<?php echo home_url('/um-member-profile-custom/?profiletab=main&um_action=edit'); ?>" class="button button-primary" target="_blank">
                                Edit Profile
                            </a>
                            <button type="button" id="refresh-status" class="button button-secondary">
                                Refresh Status
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php $fee_product = $this->get_trial_fee_product(); ?>
            <?php if ($fee_product): ?>
                <div class="trial-fee-notice">
                    <p><strong>Trial registration fee: <?php echo wp_kses_post($fee_product->get_price_html()); ?></strong><br>
                    After submitting this form you will be taken to the checkout to pay. Your application is only reviewed once payment is complete.</p>
                </div>
            <?php endif; ?>

            <?php
            // Prefilled account details: shown read-only. Changing them
            // happens on the profile, not here.
            $prefill = self::get_prefill_data($user->ID);
            $competition = self::get_competition_from_profile($user->ID);
            $profile_edit_url = home_url('/um-member-profile-custom/?profiletab=main&um_action=edit');
            $training_info_url = get_option('team_oversight_training_info_url');
            ?>
            <?php if ($training_info_url): ?>
                <div class="trial-training-notice">
                    <p><strong>Before you start:</strong> team selection depends on when and where each team trains — <a href="<?php echo esc_url($training_info_url); ?>" target="_blank">check the training venues and days</a> so you pick teams whose sessions you can actually attend.</p>
                </div>
            <?php endif; ?>
            <div class="trial-prefill-section">
                <h4>Your Details</h4>
                <p class="prefill-note">These details come from your account. <a href="<?php echo esc_url($profile_edit_url); ?>" target="_blank">Edit your profile</a> to change them, then use the Refresh Status button above.</p>
                <table class="trial-prefill-table">
                    <tr><th>First Name</th><td><?php echo esc_html($prefill['first_name'] ?: '—'); ?></td></tr>
                    <tr><th>Last Name</th><td><?php echo esc_html($prefill['last_name'] ?: '—'); ?></td></tr>
                    <tr><th>Email Address</th><td><?php echo esc_html($prefill['email']); ?></td></tr>
                    <tr><th>Contact Number</th><td><?php echo esc_html($prefill['mobile'] ?: '— missing, please add it to your profile'); ?></td></tr>
                    <tr><th>Date of Birth</th><td><?php echo esc_html($prefill['birth_date'] ?: '— missing, please add it to your profile'); ?></td></tr>
                    <tr><th>Gender</th><td><?php echo esc_html($competition['gender'] ?: '— missing, please add it to your profile'); ?></td></tr>
                    <?php if ($competition['competition']): ?>
                        <tr><th>Trialling For</th><td><?php echo $competition['competition'] === 'mens' ? "Men's competition" : "Women's competition"; ?> <small style="color: #666;">(based on your profile gender — edit your profile if this is wrong)</small></td></tr>
                    <?php endif; ?>
                    <tr><th>Institution</th><td><?php echo esc_html($prefill['institution'] ?: '—'); ?></td></tr>
                </table>
            </div>

            <form id="trial-form" method="post">
                <table class="form-table">
                    <tr>
                        <th><label for="season">Season</label></th>
                        <td>
                            <select name="season" id="season" required>
                                <?php $current_year = date('Y'); ?>
                                <option value="<?php echo $current_year; ?>" selected><?php echo $current_year; ?></option>
                                <option value="<?php echo $current_year + 1; ?>"><?php echo $current_year + 1; ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>What is your history within Volleyball Victoria League? <span class="required">*</span></th>
                        <td>
                            <?php foreach (self::get_history_options() as $key => $label): ?>
                                <label><input type="radio" name="vvl_history" value="<?php echo esc_attr($key); ?>" required> <?php echo esc_html($label); ?></label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr class="returning-section" style="display: none;">
                        <th>Select the level at which you played last <span class="required">*</span></th>
                        <td>
                            <select name="last_level">
                                <option value="">Select Level</option>
                                <?php foreach (self::get_level_options() as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                                <option value="other">Other</option>
                            </select>
                            <input type="text" name="last_level_other" placeholder="Other level" style="display: none;">
                        </td>
                    </tr>

                    <tr class="transfer-section" style="display: none;">
                        <th>Enter the season you last played VVL (e.g. <?php echo $current_year - 1; ?>) <span class="required">*</span></th>
                        <td><input type="text" name="transfer_season" maxlength="10"></td>
                    </tr>
                    <tr class="transfer-section" style="display: none;">
                        <th>For which club did you play your last season <span class="required">*</span></th>
                        <td><input type="text" name="transfer_club" maxlength="100"></td>
                    </tr>
                    <tr class="transfer-section" style="display: none;">
                        <th>Select the level at which you played <span class="required">*</span></th>
                        <td>
                            <select name="transfer_level">
                                <option value="">Select Level</option>
                                <?php foreach (self::get_level_options() as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                                <option value="other">Other</option>
                            </select>
                            <input type="text" name="transfer_level_other" placeholder="Other level" style="display: none;">
                        </td>
                    </tr>

                    <tr>
                        <th>Are you an international player/student? <span class="required">*</span></th>
                        <td>
                            <label><input type="radio" name="international" value="no" required checked> No</label><br>
                            <label><input type="radio" name="international" value="yes"> Yes</label>
                        </td>
                    </tr>
                    <tr class="international-section" style="display: none;">
                        <th>What is your country of origin? <span class="required">*</span></th>
                        <td><input type="text" name="country_origin" maxlength="100"></td>
                    </tr>
                    <tr class="international-section" style="display: none;">
                        <th>Are you a registered volleyball player in another country? <span class="required">*</span></th>
                        <td>
                            <label><input type="radio" name="registered_abroad" value="no" checked> No</label><br>
                            <label><input type="radio" name="registered_abroad" value="yes"> Yes</label>
                            <input type="text" name="registered_country" placeholder="Which country?" style="display: none;">
                        </td>
                    </tr>

                    <?php if (!$competition['competition']): ?>
                        <tr>
                            <th>Volleyball Victoria runs men's and women's competitions. Which competition are you trialling for? <span class="required">*</span></th>
                            <td>
                                <label><input type="radio" name="gender_trialling" value="mens" required> Men's</label><br>
                                <label><input type="radio" name="gender_trialling" value="womens"> Women's</label>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <th>Select the team/s you are trialling for <span class="required">*</span></th>
                        <td>
                            <?php if ($training_info_url): ?>
                                <p class="description" style="margin-top: 0;"><a href="<?php echo esc_url($training_info_url); ?>" target="_blank">Training venues and days for each team</a></p>
                            <?php endif; ?>
                            <div class="teams-checkboxes">
                                <?php
                                $teams_config = $database->get_teams_config();
                                $team_groups = array('mens' => "Men's Teams", 'womens' => "Women's Teams", 'mixed' => 'Mixed / Open Teams');
                                foreach ($team_groups as $group_key => $group_label):
                                    $group_teams = array_filter($teams_config, function ($t) use ($group_key) {
                                        return $t['gender'] === $group_key;
                                    });
                                    if (empty($group_teams)) {
                                        continue;
                                    }
                                ?>
                                    <div class="team-group">
                                        <h4><?php echo esc_html($group_label); ?></h4>
                                        <?php foreach ($group_teams as $code => $team): ?>
                                            <label class="team-option" data-gender="<?php echo esc_attr($team['gender']); ?>" data-age-rule="<?php echo esc_attr($team['age_rule']); ?>">
                                                <input type="checkbox" name="interested_teams[]" value="<?php echo esc_attr($code); ?>">
                                                <?php echo esc_html($team['name']); ?><span class="ineligible-reason"></span>
                                            </label><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th>Select the position/s you are trialling for <span class="required">*</span></th>
                        <td>
                            <?php foreach (self::get_position_options() as $key => $label): ?>
                                <label><input type="checkbox" name="preferred_positions[]" value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($label); ?></label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th>List any trial dates you are unavailable to attend</th>
                        <td>
                            <textarea name="unavailable_dates" rows="2" cols="50" placeholder="e.g. Sat 8 Nov, Sun 16 Nov"></textarea>
                            <p class="description">If you are unable to make any of the trial dates, please email coaches_women@renegades.com.au or coaches_men@renegades.com.au</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Training venues will vary — select any venues you can't attend <span class="required">*</span></th>
                        <td>
                            <?php foreach (self::get_venue_options() as $key => $label): ?>
                                <label><input type="checkbox" name="venues[]" value="<?php echo esc_attr($key); ?>"> <?php echo esc_html($label); ?></label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Can you attend 2 regular training sessions per week? <span class="required">*</span></th>
                        <td>
                            <label><input type="radio" name="two_sessions" value="yes" required> Yes</label><br>
                            <label><input type="radio" name="two_sessions" value="no"> No</label>
                        </td>
                    </tr>

                    <tr>
                        <th>Please list any period/s of absence during the competition season (April&ndash;September)</th>
                        <td><textarea name="absence_periods" rows="2" cols="50"></textarea></td>
                    </tr>

                    <tr>
                        <th>Tell us about your volleyball experience and at what level you have previously played</th>
                        <td><textarea name="experience" rows="4" cols="50"></textarea></td>
                    </tr>
                </table>

                <p>
                    <input type="submit" id="submit-trial-btn" class="button button-primary" value="Submit Trial Application" <?php echo !$profile_validation['is_complete'] ? 'disabled' : ''; ?>>
                    <input type="hidden" name="action" value="submit_trial_application">
                    <?php wp_nonce_field('trial_application', 'trial_nonce'); ?>
                </p>

                <?php if (!$profile_validation['is_complete']): ?>
                    <div class="profile-incomplete-notice">
                        <p><strong>⚠ Profile Incomplete:</strong> Please complete your profile information above before submitting your trial application.</p>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Team eligibility: grey out teams the player can't trial for,
            // based on competition (profile gender or the form question) and
            // each team's VVL age rule. Cutoffs follow the VVL By-Laws:
            // U19 = born on/after 1 Jan (season-18); U17/U15 (YSL) = born
            // on/after 1 Sep of (season-17)/(season-15).
            var murvcDob = '<?php echo esc_js($prefill['birth_date']); ?>';
            var murvcComp = '<?php echo esc_js($competition['competition'] ? $competition['competition'] : ''); ?>';

            function murvcDobCutoff(rule, seasonYear) {
                if (rule === 'u19') return (seasonYear - 18) + '-01-01';
                if (rule === 'u17') return (seasonYear - 17) + '-09-01';
                if (rule === 'u15') return (seasonYear - 15) + '-09-01';
                return null;
            }

            function murvcFormatDate(iso) {
                var p = iso.split('-');
                return p[2] + '/' + p[1] + '/' + p[0];
            }

            function updateTeamEligibility() {
                var seasonYear = parseInt($('#season').val(), 10);
                var comp = murvcComp || $('input[name="gender_trialling"]:checked').val() || '';
                var dob = murvcDob ? new Date(murvcDob.replace(/\//g, '-') + 'T00:00:00') : null;
                if (dob && isNaN(dob.getTime())) { dob = null; }

                $('.team-option').each(function() {
                    var $opt = $(this);
                    var gender = $opt.attr('data-gender');
                    var rule = $opt.attr('data-age-rule');
                    var reason = '';

                    if (comp && gender !== 'mixed' && gender !== comp) {
                        reason = gender === 'mens' ? "men's team" : "women's team";
                    } else if (rule && dob) {
                        var cutoff = murvcDobCutoff(rule, seasonYear);
                        if (cutoff && dob < new Date(cutoff + 'T00:00:00')) {
                            reason = rule.toUpperCase() + ': must be born on or after ' + murvcFormatDate(cutoff);
                        }
                    }

                    var $cb = $opt.find('input');
                    $cb.prop('disabled', !!reason);
                    if (reason) { $cb.prop('checked', false); }
                    $opt.toggleClass('team-ineligible', !!reason);
                    $opt.find('.ineligible-reason').text(reason ? ' — not eligible (' + reason + ')' : '');
                });
            }

            $('#season').on('change', updateTeamEligibility);
            $(document).on('change', 'input[name="gender_trialling"]', updateTeamEligibility);
            updateTeamEligibility();

            // Conditional sections
            $('input[name="vvl_history"]').on('change', function() {
                var v = $(this).val();
                $('.returning-section').toggle(v === 'renegades_last_season' || v === 'renegades_previously');
                $('.transfer-section').toggle(v === 'transfer');
            });

            $('input[name="international"]').on('change', function() {
                $('.international-section').toggle($('input[name="international"]:checked').val() === 'yes');
            });

            $('input[name="registered_abroad"]').on('change', function() {
                $('input[name="registered_country"]').toggle($('input[name="registered_abroad"]:checked').val() === 'yes');
            });

            $('select[name="last_level"]').on('change', function() {
                $('input[name="last_level_other"]').toggle($(this).val() === 'other');
            });

            $('select[name="transfer_level"]').on('change', function() {
                $('input[name="transfer_level_other"]').toggle($(this).val() === 'other');
            });

            // Handle form submission
            $('#trial-form').on('submit', function(e) {
                e.preventDefault();
                
                // Check if submit button is disabled (profile incomplete)
                if ($('#submit-trial-btn').prop('disabled')) {
                    alert('Please complete your profile information before submitting your trial application.');
                    return;
                }
                
                var formData = $(this).serialize();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            $('#trial-application-form').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            if (response.data.redirect) {
                                window.location.href = response.data.redirect;
                            }
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('There was an error submitting your application. Please try again.');
                    }
                });
            });
            
            // Handle refresh status button
            $('#refresh-status').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Checking...');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'check_profile_status'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the MUS status section
                            $('#mus-status-section').replaceWith(response.data.html);
                            
                            // Update submit button state
                            if (response.data.is_complete) {
                                $('#submit-trial-btn').prop('disabled', false);
                                $('.profile-incomplete-notice').hide();
                            } else {
                                $('#submit-trial-btn').prop('disabled', true);
                                $('.profile-incomplete-notice').show();
                            }
                        }
                        button.prop('disabled', false).text('Refresh Status');
                    },
                    error: function() {
                        alert('Error checking profile status. Please try again.');
                        button.prop('disabled', false).text('Refresh Status');
                    }
                });
            });
        });
        </script>
        
        <style>
        /* MUS Status Section */
        .mus-status-container {
            margin-bottom: 30px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
        }
        
        .mus-status-header {
            background: #0073aa;
            color: white;
            padding: 15px 20px;
            border-radius: 6px 6px 0 0;
        }
        
        .mus-status-header h4 {
            margin: 0;
            font-size: 18px;
        }
        
        .mus-status-content {
            padding: 20px;
        }
        
        .mus-status-display {
            background: white;
            padding: 20px;
            border-radius: 6px;
            border-left: 5px solid #ddd;
        }
        
        .mus-status-display.complete {
            border-left-color: #46b450;
        }
        
        .mus-status-display.incomplete {
            border-left-color: #dc3232;
        }
        
        .status-indicator {
            margin-bottom: 15px;
        }
        
        .status-icon {
            display: inline-block;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            text-align: center;
            line-height: 24px;
            font-weight: bold;
            margin-right: 10px;
            color: white;
        }
        
        .status-icon.complete {
            background: #46b450;
        }
        
        .status-icon.incomplete {
            background: #dc3232;
        }
        
        .missing-fields {
            margin: 15px 0;
        }
        
        .missing-fields ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .missing-fields li {
            margin: 5px 0;
            color: #dc3232;
        }
        
        .mus-explanation {
            margin: 15px 0;
            color: #666;
        }
        
        .profile-actions {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }
        
        .profile-actions .button {
            margin-right: 10px;
        }
        
        .profile-incomplete-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
        }
        
        .profile-incomplete-notice p {
            margin: 0;
        }

        .trial-fee-notice {
            background: #e7f5ff;
            border: 1px solid #74c0fc;
            color: #1864ab;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .trial-fee-notice p {
            margin: 0;
        }

        .trial-training-notice {
            background: #fff8e1;
            border: 1px solid #ffd54f;
            color: #7a5b00;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .trial-training-notice p {
            margin: 0;
        }

        .trial-status-panel {
            display: flex;
            align-items: center;
            gap: 20px;
            background: #f0f7f0;
            border: 2px solid #46b450;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .trial-number-badge {
            text-align: center;
            background: #46b450;
            color: #fff;
            border-radius: 8px;
            padding: 10px 18px;
            flex-shrink: 0;
        }

        .trial-number-label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .trial-number-value {
            display: block;
            font-size: 28px;
            font-weight: bold;
            line-height: 1.2;
        }

        .trial-status-text p {
            margin: 0;
        }

        .team-option.team-ineligible {
            opacity: 0.5;
        }

        .team-option .ineligible-reason {
            color: #a00;
            font-size: 12px;
        }

        .trial-prefill-section {
            margin-bottom: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #f9f9f9;
            padding: 15px 20px;
        }

        .trial-prefill-section h4 {
            margin: 0 0 5px 0;
        }

        .prefill-note {
            color: #666;
            font-size: 13px;
            margin: 0 0 10px 0;
        }

        .trial-prefill-table {
            border-collapse: collapse;
        }

        .trial-prefill-table th {
            text-align: left;
            padding: 4px 20px 4px 0;
            color: #444;
            font-weight: 600;
            white-space: nowrap;
        }

        .trial-prefill-table td {
            padding: 4px 0;
            color: #222;
        }

        .required {
            color: #dc3232;
        }
        
        #submit-trial-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Teams Section */
        .teams-checkboxes {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background: #fafafa;
        }
        
        .team-group {
            margin-bottom: 15px;
        }
        
        .team-group h4 {
            margin: 0 0 5px 0;
            font-weight: bold;
            color: #333;
        }
        
        .team-group label {
            display: block;
            margin: 2px 0;
            font-size: 13px;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    public function handle_trial_submission() {
        if (!wp_verify_nonce($_POST['trial_nonce'], 'trial_application')) {
            wp_die('Security check failed');
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in to submit an application.'));
        }
        
        $user = wp_get_current_user();
        
        // Validate profile completeness before allowing submission
        $profile_validation = $this->validate_user_profile($user->ID);
        if (!$profile_validation['is_complete']) {
            wp_send_json_error(array('message' => 'Please complete your profile information before submitting your trial application. Missing fields: ' . implode(', ', $profile_validation['missing_fields'])));
        }
        $season = sanitize_text_field($_POST['season']);
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();
        $interested_teams = isset($_POST['interested_teams']) ? array_values(array_intersect(array_map('sanitize_text_field', (array) $_POST['interested_teams']), array_keys($teams_config))) : array();
        $preferred_positions = isset($_POST['preferred_positions']) ? array_values(array_intersect(array_map('sanitize_text_field', (array) $_POST['preferred_positions']), array_keys(self::get_position_options()))) : array();

        $history = isset($_POST['vvl_history']) ? sanitize_text_field($_POST['vvl_history']) : '';
        $international = isset($_POST['international']) ? sanitize_text_field($_POST['international']) : '';

        // Competition comes from the profile gender when it maps to Men's or
        // Women's; the form question is only honoured when it doesn't.
        $competition = self::get_competition_from_profile($user->ID);
        $gender_trialling = $competition['competition'];
        if (!$gender_trialling) {
            $gender_trialling = isset($_POST['gender_trialling']) ? sanitize_text_field($_POST['gender_trialling']) : '';
        }
        $two_sessions = isset($_POST['two_sessions']) ? sanitize_text_field($_POST['two_sessions']) : '';
        $venues = isset($_POST['venues']) ? array_values(array_intersect(array_map('sanitize_text_field', (array) $_POST['venues']), array_keys(self::get_venue_options()))) : array();

        if (empty($season) || empty($interested_teams) || empty($preferred_positions)
            || !array_key_exists($history, self::get_history_options())
            || !in_array($international, array('yes', 'no'), true)
            || !in_array($gender_trialling, array('mens', 'womens'), true)
            || !in_array($two_sessions, array('yes', 'no'), true)
            || empty($venues)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        $is_transfer_player = ($history === 'transfer') ? 1 : 0;

        // Eligibility is enforced server-side: the greyed-out checkboxes are
        // a courtesy, this is the authority.
        $prefill = self::get_prefill_data($user->ID);
        $dob_normalized = null;
        if (!empty($prefill['birth_date'])) {
            $birth_ts = strtotime(str_replace('/', '-', $prefill['birth_date']));
            if ($birth_ts) {
                $dob_normalized = date('Y-m-d', $birth_ts);
            }
        }

        foreach ($interested_teams as $team_code) {
            $team = $teams_config[$team_code];
            if ($team['gender'] !== 'mixed' && $gender_trialling && $team['gender'] !== $gender_trialling) {
                wp_send_json_error(array('message' => $team['name'] . ' is a ' . ($team['gender'] === 'mens' ? "men's" : "women's") . ' team and does not match the competition you are trialling for.'));
            }
            $cutoff = TeamOversight_Database::get_dob_cutoff($team['age_rule'], $season);
            if ($cutoff && $dob_normalized && $dob_normalized < $cutoff) {
                wp_send_json_error(array('message' => $team['name'] . ' is a ' . strtoupper($team['age_rule']) . ' team; for the ' . intval($season) . ' season you must be born on or after ' . date('j F Y', strtotime($cutoff)) . ' to play in it.'));
            }
        }

        $level_options = self::get_level_options();
        $history_options = self::get_history_options();
        $venue_options = self::get_venue_options();

        $resolve_level = function ($field) use ($level_options) {
            $value = isset($_POST[$field]) ? sanitize_text_field($_POST[$field]) : '';
            if ($value === 'other') {
                $other = isset($_POST[$field . '_other']) ? sanitize_text_field($_POST[$field . '_other']) : '';
                return $other !== '' ? 'Other: ' . $other : '';
            }
            return isset($level_options[$value]) ? $level_options[$value] : '';
        };

        // Human-readable answers, stored as a snapshot with the application.
        $form_data = array(
            'VVL History' => $history_options[$history],
            'Trialling For' => ($gender_trialling === 'mens' ? "Men's" : "Women's") . ($competition['competition'] ? '' : ' (asked on form)'),
            'Gender (profile)' => $competition['gender'],
            'Can Attend 2 Sessions/Week' => ucfirst($two_sessions),
            'Venues Unable To Attend' => implode(', ', array_map(function ($v) use ($venue_options) {
                return $venue_options[$v];
            }, $venues)),
            'Unavailable Trial Dates' => isset($_POST['unavailable_dates']) ? sanitize_textarea_field($_POST['unavailable_dates']) : '',
            'Absence Periods (Apr-Sep)' => isset($_POST['absence_periods']) ? sanitize_textarea_field($_POST['absence_periods']) : '',
            'Volleyball Experience' => isset($_POST['experience']) ? sanitize_textarea_field($_POST['experience']) : '',
        );

        if ($history === 'renegades_last_season' || $history === 'renegades_previously') {
            $last_level = $resolve_level('last_level');
            if ($last_level === '') {
                wp_send_json_error(array('message' => 'Please select the level at which you last played.'));
            }
            $form_data['Last Level Played'] = $last_level;
        }

        if ($history === 'transfer') {
            $transfer_season = isset($_POST['transfer_season']) ? sanitize_text_field($_POST['transfer_season']) : '';
            $transfer_club = isset($_POST['transfer_club']) ? sanitize_text_field($_POST['transfer_club']) : '';
            $transfer_level = $resolve_level('transfer_level');
            if ($transfer_season === '' || $transfer_club === '' || $transfer_level === '') {
                wp_send_json_error(array('message' => 'Please complete the club transfer details.'));
            }
            $form_data['Transfer: Last VVL Season'] = $transfer_season;
            $form_data['Transfer: Previous Club'] = $transfer_club;
            $form_data['Transfer: Level Played'] = $transfer_level;
        }

        if ($international === 'yes') {
            $country_origin = isset($_POST['country_origin']) ? sanitize_text_field($_POST['country_origin']) : '';
            $registered_abroad = isset($_POST['registered_abroad']) ? sanitize_text_field($_POST['registered_abroad']) : '';
            if ($country_origin === '' || !in_array($registered_abroad, array('yes', 'no'), true)) {
                wp_send_json_error(array('message' => 'Please complete the international player details.'));
            }
            $form_data['International Player'] = 'Yes';
            $form_data['Country of Origin'] = $country_origin;
            if ($registered_abroad === 'yes') {
                $registered_country = isset($_POST['registered_country']) ? sanitize_text_field($_POST['registered_country']) : '';
                $form_data['Registered Player In Another Country'] = 'Yes' . ($registered_country !== '' ? ' — ' . $registered_country : '');
            } else {
                $form_data['Registered Player In Another Country'] = 'No';
            }
        } else {
            $form_data['International Player'] = 'No';
        }

        // Snapshot the account details as they were at submission time.
        $form_data['Contact Number (at submission)'] = $prefill['mobile'];
        $form_data['Date of Birth (at submission)'] = $prefill['birth_date'];

        global $wpdb;

        $existing_application = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}trial_applications
            WHERE user_id = %d AND season = %s AND application_status = 'pending'
        ", $user->ID, $season));

        if ($existing_application) {
            wp_send_json_error(array('message' => 'You already have a pending application for this season.'));
        }

        $fee_product = $this->get_trial_fee_product();

        $application_data = array(
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'name' => $user->display_name,
            'season' => $season,
            'interested_teams' => json_encode($interested_teams),
            'preferred_positions' => json_encode($preferred_positions),
            'is_transfer_player' => $is_transfer_player,
            'form_data' => wp_json_encode($form_data),
            'application_status' => $fee_product ? 'awaiting_payment' : 'pending'
        );
        $application_formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s');

        // Reuse an abandoned unpaid application for this season instead of
        // stacking duplicates when someone retries after leaving checkout.
        $awaiting_id = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}trial_applications
            WHERE user_id = %d AND season = %s AND application_status IN ('awaiting_payment', 'expired')
        ", $user->ID, $season));

        if ($awaiting_id) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                $application_data,
                array('id' => $awaiting_id),
                $application_formats,
                array('%d')
            );
            $application_id = ($updated !== false) ? intval($awaiting_id) : 0;
            $trial_number = intval($wpdb->get_var($wpdb->prepare("
                SELECT trial_number FROM {$wpdb->prefix}trial_applications WHERE id = %d
            ", $awaiting_id)));
        } else {
            // Assign the next per-season trial number at submission time.
            $trial_number = intval($wpdb->get_var($wpdb->prepare("
                SELECT MAX(trial_number) FROM {$wpdb->prefix}trial_applications WHERE season = %s
            ", $season))) + 1;
            $application_data['trial_number'] = $trial_number;
            $application_formats[] = '%d';

            $inserted = $wpdb->insert($wpdb->prefix . 'trial_applications', $application_data, $application_formats);
            $application_id = $inserted ? intval($wpdb->insert_id) : 0;
        }

        if (!$application_id) {
            wp_send_json_error(array('message' => 'There was an error submitting your application. Please try again.'));
        }

        if (!$fee_product) {
            wp_send_json_success(array('message' => 'Your trial application has been submitted successfully! Your trial number is <strong>#' . $trial_number . '</strong> — a coach may ask you for it at trials, so make a note of it (it is also shown whenever you revisit this page). You will be contacted regarding team assignments.'));
        }

        $checkout_url = $this->add_fee_to_cart($fee_product, $application_id);

        if (!$checkout_url) {
            wp_send_json_error(array('message' => 'Your application was saved, but the trial fee could not be added to your cart. Please contact the club to arrange payment.'));
        }

        wp_send_json_success(array(
            'message' => 'Application saved — your trial number is <strong>#' . $trial_number . '</strong>. Taking you to the checkout to pay the trial fee&hellip;',
            'redirect' => $checkout_url
        ));
    }

    /**
     * The WooCommerce product used as the trial registration fee, or null
     * if none is configured (in which case applications submit directly).
     */
    public function get_trial_fee_product() {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $product_id = intval(get_option('team_oversight_trial_fee_product'));
        if (!$product_id) {
            return null;
        }

        $product = wc_get_product($product_id);
        return ($product && $product->is_purchasable()) ? $product : null;
    }

    private function add_fee_to_cart($product, $application_id) {
        if (!function_exists('WC')) {
            return false;
        }

        if (WC()->cart === null && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (WC()->cart === null) {
            return false;
        }

        // Drop any earlier trial-fee line so retries don't stack.
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (!empty($cart_item['murvc_trial_application_id'])) {
                WC()->cart->remove_cart_item($cart_item_key);
            }
        }

        $added = WC()->cart->add_to_cart(
            $product->get_id(),
            1,
            0,
            array(),
            array('murvc_trial_application_id' => $application_id)
        );

        return $added ? wc_get_checkout_url() : false;
    }

    public function display_application_in_cart($item_data, $cart_item) {
        if (!empty($cart_item['murvc_trial_application_id'])) {
            $item_data[] = array(
                'key' => 'Trial application',
                'value' => '#' . intval($cart_item['murvc_trial_application_id'])
            );
        }
        return $item_data;
    }

    public function attach_application_to_order_item($item, $cart_item_key, $values) {
        if (!empty($values['murvc_trial_application_id'])) {
            $item->add_meta_data('_murvc_trial_application_id', intval($values['murvc_trial_application_id']), true);
        }
    }

    /**
     * Paid order containing a trial fee -> application becomes reviewable.
     */
    public function handle_trial_payment($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        global $wpdb;

        foreach ($order->get_items() as $item) {
            $application_id = intval($item->get_meta('_murvc_trial_application_id'));
            if (!$application_id) {
                continue;
            }

            $wpdb->query($wpdb->prepare("
                UPDATE {$wpdb->prefix}trial_applications
                SET application_status = 'pending', order_id = %d
                WHERE id = %d AND application_status IN ('awaiting_payment', 'expired')
            ", $order_id, $application_id));
        }
    }

    /**
     * Applications left unpaid for 7+ days are marked expired so the review
     * list stays clean. Resubmitting the form (or a late payment coming
     * through) revives them.
     */
    public static function expire_stale_awaiting() {
        global $wpdb;

        $wpdb->query("
            UPDATE {$wpdb->prefix}trial_applications
            SET application_status = 'expired'
            WHERE application_status = 'awaiting_payment'
                AND created_date < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
    }
    
    public function get_trial_applications($season = null) {
        global $wpdb;
        
        $where_clause = "WHERE 1=1";
        $params = array();
        
        if ($season) {
            $where_clause .= " AND season = %s";
            $params[] = $season;
        }
        
        $query = "
            SELECT 
                ta.*,
                u.user_email,
                u.display_name
            FROM {$wpdb->prefix}trial_applications ta
            JOIN {$wpdb->users} u ON ta.user_id = u.ID
            {$where_clause}
            ORDER BY ta.created_date DESC
        ";
        
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, ...$params));
        } else {
            return $wpdb->get_results($query);
        }
    }
    
    public function process_trial_application($application_id, $action, $assigned_team = null) {
        global $wpdb;
        
        $application = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d
        ", $application_id));
        
        if (!$application) {
            return false;
        }
        
        if ($action === 'accept' && $assigned_team) {
            $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                array(
                    'application_status' => 'accepted',
                    'assigned_team' => $assigned_team
                ),
                array('id' => $application_id),
                array('%s', '%s'),
                array('%d')
            );
            
            $wpdb->insert(
                $wpdb->prefix . 'team_assignments',
                array(
                    'user_id' => intval($application->user_id),
                    'email' => $application->email,
                    'season' => $application->season,
                    'team' => $assigned_team,
                    'role' => 'playing_member',
                    'registration_status' => 'active',
                    'start_date' => date('Y-m-d'),
                    'is_active' => 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );
            
            $fees = new TeamOversight_Fees();
            $fees->generate_invoice($application->email, $application->season);
            
            return true;
            
        } elseif ($action === 'reject') {
            $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                array('application_status' => 'rejected'),
                array('id' => $application_id),
                array('%s'),
                array('%d')
            );
            
            return true;
        }
        
        return false;
    }
    
    public function validate_user_profile($user_id) {
        $required_fields = array(
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'mobile_number' => 'Contact Number',
            'birth_date' => 'Date of Birth',
            'gender' => 'Gender',
            'degree1type' => 'Degree Type',
            'institution1' => 'Institution',
            'degree1enddate' => 'Degree End Date'
        );
        
        $missing_fields = array();
        $is_complete = true;
        
        foreach ($required_fields as $field => $label) {
            $value = get_user_meta($user_id, $field, true);
            if (empty($value)) {
                $missing_fields[] = $label;
                $is_complete = false;
            }
        }
        
        return array(
            'is_complete' => $is_complete,
            'missing_fields' => $missing_fields
        );
    }
    
    public function check_profile_status() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }
        
        $user = wp_get_current_user();
        $fees = new TeamOversight_Fees();
        
        // Calculate MUS status and validate profile
        $mus_status = $fees->determine_fee_class($user->user_email);
        $profile_validation = $this->validate_user_profile($user->ID);
        
        // Generate the HTML for the MUS status section
        ob_start();
        ?>
        <div id="mus-status-section" class="mus-status-container">
            <div class="mus-status-header">
                <h4>Your Melbourne University Status (MUS)</h4>
            </div>
            
            <div class="mus-status-content">
                <div class="mus-status-display <?php echo $profile_validation['is_complete'] ? 'complete' : 'incomplete'; ?>">
                    <div class="status-indicator">
                        <?php if ($profile_validation['is_complete']): ?>
                            <span class="status-icon complete">✓</span>
                            <strong>Calculated MUS: <?php echo esc_html($mus_status); ?></strong>
                        <?php else: ?>
                            <span class="status-icon incomplete">⚠</span>
                            <strong>Profile Incomplete</strong>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!$profile_validation['is_complete']): ?>
                        <div class="missing-fields">
                            <p><strong>Please complete the following profile fields to calculate your MUS status:</strong></p>
                            <ul>
                                <?php foreach ($profile_validation['missing_fields'] as $field): ?>
                                    <li><?php echo esc_html($field); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="mus-explanation">
                            <p>Your fee category has been calculated based on your profile information. If this doesn't look correct, please update your profile.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="profile-actions">
                        <a href="<?php echo home_url('/um-member-profile-custom/?profiletab=main&um_action=edit'); ?>" class="button button-primary" target="_blank">
                            Edit Profile
                        </a>
                        <button type="button" id="refresh-status" class="button button-secondary">
                            Refresh Status
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'is_complete' => $profile_validation['is_complete'],
            'mus_status' => $mus_status,
            'missing_fields' => $profile_validation['missing_fields']
        ));
    }
}