<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Trials {

    /** One-off "saved" notice shown on the rego page after a save (per user). */
    const FLASH_PREFIX = 'murvc_trial_flash_';

    /**
     * Start of the Trial Fee record when an admin waives the fee (Trial
     * Applications → Waive fee). Distinct from rule-based waivers so the
     * submission handler can tell a club decision apart and never re-charge
     * it when the player edits their application.
     */
    const CLUB_WAIVER_PREFIX = 'Waived by club — ';

    private static $refund_hooks_registered = false;

    /**
     * Database-wide name for the per-season submission lock. Includes the
     * table prefix so two sites sharing a database server never contend.
     * MySQL caps lock names at 64 characters.
     */
    public static function submission_lock_name($season) {
        global $wpdb;
        return substr('murvc_trial_' . $wpdb->prefix . intval($season), 0, 64);
    }

    /**
     * Take the lock, waiting up to 10 seconds (a save takes milliseconds,
     * so a real wait is rare). Returns true when held, false on a genuine
     * timeout, or null when the database doesn't support named locks — in
     * which case the save goes ahead unlocked rather than failing every
     * applicant (the form's own one-submission-at-a-time guard still helps).
     */
    private static function acquire_submission_lock($lock) {
        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock));
        if ($got === null) {
            return null;
        }
        return intval($got) === 1;
    }

    private static function release_submission_lock($lock, $locked) {
        global $wpdb;
        if ($locked === true) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
        }
    }

    /**
     * Trial numbers are never reused. A per-season high-water mark (a
     * non-autoloaded option) remembers the highest number ever handed out,
     * so deleting the newest application doesn't pass its number to the
     * next applicant. Read and raised inside the submission lock.
     */
    private static function trial_number_mark_option($season) {
        return 'team_oversight_trial_number_mark_' . intval($season);
    }

    /** The mark, straight from the database: a cached copy could be stale by the time the lock is ours. */
    private static function get_trial_number_mark($season) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::trial_number_mark_option($season)
        )));
    }

    /** Raise the mark to $number; it never goes down. The caller holds the lock. */
    private static function raise_trial_number_mark($season, $number) {
        $number = intval($number);
        if ($number > self::get_trial_number_mark($season)) {
            update_option(self::trial_number_mark_option($season), $number, false);
        }
    }

    /**
     * Keep a trial number from ever being handed out again. For deleting an
     * application: call it before the delete, so numbers given out before
     * the mark existed are covered too.
     */
    public static function retire_trial_number($season, $number) {
        $lock = self::submission_lock_name($season);
        $locked = self::acquire_submission_lock($lock);
        self::raise_trial_number_mark($season, $number);
        self::release_submission_lock($lock, $locked);
    }

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_shortcode('team_trial_form', array($this, 'render_trial_form'));
        add_action('wp_ajax_submit_trial_application', array($this, 'handle_trial_submission'));
        add_action('wp_ajax_nopriv_submit_trial_application', array($this, 'handle_trial_submission'));

        // Trial fee payment via WooCommerce: the form saves the application as
        // awaiting_payment, sends the user to checkout with the configured fee
        // product, and the paid order flips the application to pending.
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'attach_application_to_order_item'), 10, 3);
        add_action('woocommerce_order_status_processing', array($this, 'handle_trial_payment'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_trial_payment'));
        add_filter('woocommerce_get_item_data', array($this, 'display_application_in_cart'), 10, 2);

        // "Pay the trial fee" on an unpaid application: rebuild the cart
        // line and hand them to checkout, without re-submitting the form.
        add_action('template_redirect', array($this, 'maybe_resume_trial_payment'));

        // A paid trial fee refunded or cancelled in WooCommerce leaves the
        // application as it is; admins are alerted on Logs and Trial
        // Applications instead. Registered once: other code instantiates
        // this class just to read the fee product.
        if (!self::$refund_hooks_registered) {
            self::$refund_hooks_registered = true;
            add_action('woocommerce_order_refunded', array($this, 'handle_trial_refund'), 10, 2);
            add_action('woocommerce_order_status_refunded', array($this, 'handle_trial_refund'));
            add_action('woocommerce_order_status_cancelled', array($this, 'handle_trial_cancelled'));
            add_action('admin_notices', array($this, 'render_refund_notice'));
        }
    }
    
    public function init() {

    }

    /**
     * Split a stored assigned_team into its teams. Single and bulk accept
     * write one bare code; Finalise writes a comma-joined list of bare
     * codes with " (T/O)" after Training Only teams ("SL2M, JPLM (T/O)").
     * Returns a list of array('code' => 'JPLM', 'training_only' => true).
     */
    public static function parse_assigned_team($assigned_team) {
        $teams = array();
        foreach (explode(',', (string) $assigned_team) as $token) {
            $training_only = (strpos($token, '(T/O)') !== false);
            $code = trim(str_replace('(T/O)', '', $token));
            if ($code !== '') {
                $teams[] = array('code' => $code, 'training_only' => $training_only);
            }
        }
        return $teams;
    }

    /**
     * Player-facing wording for an assigned_team value: each team's name
     * from that season's config (the code only if the team is missing
     * from it), "(training only)" in words, joined "A, B and C". Empty
     * string when nothing is assigned.
     */
    public static function describe_assigned_team($assigned_team, $season) {
        $database = new TeamOversight_Database();
        $config = $database->get_teams_config($season);
        $names = array();
        foreach (self::parse_assigned_team($assigned_team) as $team) {
            $name = isset($config[$team['code']]['name']) ? $config[$team['code']]['name'] : $team['code'];
            $names[] = $name . ($team['training_only'] ? ' (training only)' : '');
        }
        if (count($names) > 1) {
            $last = array_pop($names);
            return implode(', ', $names) . ' and ' . $last;
        }
        return count($names) === 1 ? $names[0] : '';
    }

    public static function get_history_options() {
        return array(
            'renegades_last_season' => 'I played VVL for Renegades last season (no transfer needed)',
            // "but not last season" keeps this from also describing last
            // season's players, who then picked it and were charged.
            'renegades_previously' => 'I\'ve played VVL for Renegades before, but not last season, and haven\'t played VVL for any other club since (no transfer needed)',
            'transfer' => 'The last club I played VVL for was a different club (club transfer required)',
            'never_played' => 'I\'ve never played VVL — this will be my first Volleyball Victoria League season',
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
            'YSL15' => 'Youth State League U/15',
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
        // Both UM gender keys, current one first (see get_member_gender).
        $gender = TeamOversight_Database::get_member_gender($user_id);

        $map = array('male' => 'mens', 'female' => 'womens');
        $key = strtolower($gender);

        return array(
            'gender' => $gender,
            'competition' => isset($map[$key]) ? $map[$key] : null,
        );
    }

    /**
     * The competition an application is for, 'mens' or 'womens', or null when
     * neither the application nor the account says (1.76.0). The stored
     * "Trialling For" answer wins (it is what the player was asked, or what
     * their profile gender gave at submission); an application without one
     * falls back to the account's profile gender, through
     * get_competition_from_profile(). $application is a trial_applications
     * row (form_data and user_id are read). This is the coach portal's own
     * reading of an applicant's competition, shared so the "not selected"
     * notice judges a team's gender the same way; the portal keeps its copy.
     */
    public static function application_competition($application) {
        if (!is_object($application)) {
            return null;
        }
        $form_data = !empty($application->form_data) ? json_decode((string) $application->form_data, true) : array();
        $competition = null;
        if (is_array($form_data) && !empty($form_data['Trialling For'])) {
            $answer = (string) $form_data['Trialling For'];
            if (stripos($answer, 'wom') === 0) {
                $competition = 'womens';
            } elseif (stripos($answer, 'men') === 0) {
                $competition = 'mens';
            }
        }
        if ($competition === null && !empty($application->user_id)) {
            $profile = self::get_competition_from_profile($application->user_id);
            $competition = $profile['competition'];
        }
        return $competition;
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
    
    /**
     * Rebuild the form's raw input values from a stored application, so
     * revisiting the page opens the form pre-filled for editing.
     *
     * Applications store human-readable answers (the form_data snapshot)
     * rather than raw inputs, so this maps labels back to their option
     * keys. Working from the snapshot means every application ever
     * submitted is editable with no data migration. If an option's label
     * has since been renamed, that one field simply comes back unselected
     * and the applicant re-picks it — nothing is lost from the stored copy.
     */
    public static function get_form_input($application) {
        $data = $application->form_data ? json_decode($application->form_data, true) : array();
        $data = is_array($data) ? $data : array();
        $get = function ($key) use ($data) {
            return isset($data[$key]) ? (string) $data[$key] : '';
        };

        // Stored label -> option key, with "Other: x" unpacked.
        $level_key = function ($stored, &$other) {
            $other = '';
            if ($stored === '') {
                return '';
            }
            if (strpos($stored, 'Other: ') === 0) {
                $other = substr($stored, strlen('Other: '));
                return 'other';
            }
            $key = array_search($stored, self::get_level_options(), true);
            return $key === false ? '' : $key;
        };

        $history = array_search($get('VVL History'), self::get_history_options(), true);
        if ($history === false) {
            // Applications saved under an earlier wording of an option.
            $legacy = array(
                'I\'ve played VVL for Renegades before, and haven\'t played VVL for any other club since (no transfer needed)' => 'renegades_previously',
            );
            $history = isset($legacy[$get('VVL History')]) ? $legacy[$get('VVL History')] : false;
        }

        $last_level_other = '';
        $transfer_level_other = '';
        $input = array(
            'season' => (string) $application->season,
            'vvl_history' => $history === false ? '' : $history,
            'last_level' => $level_key($get('Last Level Played'), $last_level_other),
            'last_level_other' => $last_level_other,
            'transfer_season' => '',
            'transfer_club' => '',
            'transfer_club_other' => '',
            'transfer_level' => $level_key($get('Transfer: Level Played'), $transfer_level_other),
            'transfer_level_other' => $transfer_level_other,
            'international' => $get('International Player') === 'Yes' ? 'yes' : 'no',
            'country_origin' => $get('Country of Origin'),
            'registered_abroad' => 'no',
            'registered_country' => '',
            'gender_trialling' => '',
            'interested_teams' => json_decode($application->interested_teams, true) ?: array(),
            'preferred_positions' => json_decode($application->preferred_positions, true) ?: array(),
            'unavailable_dates' => $get('Unavailable Trial Dates'),
            'venues' => array(),
            'two_sessions' => strtolower($get('Can Attend 2 Sessions/Week')),
            'absence_periods' => $get('Absence Periods (Apr-Sep)'),
            'experience' => $get('Volleyball Experience'),
        );

        $season_key = array_search($get('Transfer: Last VVL Season'), self::get_transfer_season_options(), true);
        $input['transfer_season'] = $season_key === false ? '' : (string) $season_key;

        // Club: a listed club verbatim, or "Other/Interstate/International: name".
        $club = $get('Transfer: Previous Club');
        $specials = array('Other' => '__other', 'Interstate' => '__interstate', 'International' => '__international');
        if ($club !== '') {
            $matched = false;
            foreach ($specials as $prefix => $value) {
                if (strpos($club, $prefix . ': ') === 0) {
                    $input['transfer_club'] = $value;
                    $input['transfer_club_other'] = substr($club, strlen($prefix . ': '));
                    $matched = true;
                    break;
                }
            }
            if (!$matched && in_array($club, self::get_transfer_clubs(), true)) {
                $input['transfer_club'] = $club;
            }
        }

        $registered = $get('Registered Player In Another Country');
        if (strpos($registered, 'Yes') === 0) {
            $input['registered_abroad'] = 'yes';
            $dash = strpos($registered, ' — ');
            $input['registered_country'] = $dash !== false ? substr($registered, $dash + strlen(' — ')) : '';
        }

        // Only meaningful when the form had to ask (profile gender unset).
        $trialling = $get('Trialling For');
        if (stripos($trialling, 'wom') === 0) {
            $input['gender_trialling'] = 'womens';
        } elseif (stripos($trialling, 'men') === 0) {
            $input['gender_trialling'] = 'mens';
        }

        $venue_labels = array_filter(array_map('trim', explode(',', $get('Venues Unable To Attend'))));
        foreach ($venue_labels as $label) {
            $key = array_search($label, self::get_venue_options(), true);
            if ($key !== false) {
                $input['venues'][] = $key;
            }
        }

        return $input;
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

        $profile_validation = $this->validate_user_profile($user->ID);

        // Their live application for the season trials are currently
        // about — one season only, so the trial number is findable by
        // revisiting this page but last season's confirmation never
        // lingers once the next season opens.
        global $wpdb;
        $trial_season = self::get_current_trial_season();
        $my_application = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE user_id = %d AND season = %s
                AND application_status IN ('awaiting_payment', 'pending', 'accepted')
            ORDER BY FIELD(application_status, 'accepted', 'pending', 'awaiting_payment'), id DESC
            LIMIT 1
        ", $user->ID, $trial_season));
        // ^ Same pick as the save handler, so the page always shows (and
        // edits) the application a save would update.

        $fee_product = $this->get_trial_fee_product();
        $fee_rules = self::get_trial_fee_rules();
        $unpaid = ($my_application && $my_application->application_status === 'awaiting_payment');

        // Coming back to the page reopens the application for editing, while
        // its season is still taking applications and until a team has been
        // assigned — after that, changes go through the club.
        $editing = $my_application
            && in_array($my_application->application_status, array('pending', 'awaiting_payment'), true)
            && self::applications_open($my_application->season);
        $locked = ($my_application && $my_application->application_status === 'accepted');
        $form_input = $editing ? self::get_form_input($my_application) : null;

        // One-off notice from the save that brought them back here.
        $flash = get_transient(self::FLASH_PREFIX . $user->ID);
        if ($flash !== false) {
            delete_transient(self::FLASH_PREFIX . $user->ID);
        }

        ob_start();
        ?>
        <div id="trial-application-form">
            <h3><?php echo $editing ? 'Edit your trial application' : 'Trial Application Form'; ?></h3>

            <?php if ($flash !== false): ?>
                <div class="trial-flash" id="trial-flash"><p><strong><?php echo esc_html($flash); ?></strong></p></div>
                <script>
                    // Arrived here from a save: start at the top of the page,
                    // then hand scroll behaviour back to the browser.
                    window.scrollTo(0, 0);
                    window.addEventListener('load', function () {
                        window.scrollTo(0, 0);
                        if ('scrollRestoration' in history) { history.scrollRestoration = 'auto'; }
                    });
                </script>
            <?php endif; ?>

            <?php if ($my_application): ?>
                <div class="trial-status-panel<?php echo $unpaid ? ' trial-status-unpaid' : ''; ?>">
                    <div class="trial-number-badge">
                        <span class="trial-number-label">Your Trial Number</span>
                        <span class="trial-number-value">#<?php echo intval($my_application->trial_number); ?></span>
                    </div>
                    <div class="trial-status-text">
                        <?php if ($unpaid): ?>
                            <p><strong>Your <?php echo esc_html($my_application->season); ?> application has not been paid for yet.</strong> Coaches can see it, but can't select you for a team until the trial fee is paid.</p>
                            <?php if ($fee_product): ?>
                                <p class="trial-pay-actions">
                                    <a class="button button-primary" href="<?php echo esc_url(self::resume_payment_url($my_application->id)); ?>">Pay the trial fee<?php echo $fee_product->get_price() !== '' ? ' — ' . esc_html(wp_strip_all_tags(wc_price($fee_product->get_price()))) : ''; ?></a>
                                    <span class="trial-pay-note">Already paid? Contact the club and we'll sort it out.</span>
                                </p>
                            <?php else: ?>
                                <p class="trial-pay-note">Please contact the club to arrange payment.</p>
                            <?php endif; ?>
                        <?php elseif ($my_application->application_status === 'accepted'): ?>
                            <?php
                            // Finalise stores a list like "SL2M, JPLM (T/O)";
                            // players see team names, never the codes.
                            $assigned_name = self::describe_assigned_team($my_application->assigned_team, $my_application->season);
                            if ($assigned_name === '') {
                                $assigned_name = 'a team';
                            }
                            ?>
                            <p><strong>Congratulations — you've been assigned to <?php echo esc_html($assigned_name); ?> for <?php echo esc_html($my_application->season); ?>.</strong></p>
                        <?php else: ?>
                            <p><strong>Your <?php echo esc_html($my_application->season); ?> trial application is confirmed.</strong> When trials are busy, a coach may ask you for your trial number to speed things up — many players write it on their hand or arm on the day.</p>
                            <?php if ($editing): ?>
                                <p class="trial-edit-note">Need to change something? Your answers are below — update them and save until applications close. Your trial number stays the same.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php
                        // 1.76.0: a team offer sent to this player, so one who lost the email can find it. '' for everyone with no offer.
                        if (class_exists('TeamOversight_Offers_Player')) {
                            echo TeamOversight_Offers_Player::trial_form_line($user->ID, $my_application->season);
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($locked): ?>
                <div class="trial-closed-notice" style="border: 2px solid #e0e0e0; background: #f9f9f9; border-radius: 8px; padding: 16px 20px;">
                    <p style="margin: 0;">Your application can no longer be changed here now that you've been assigned to a team. If something needs updating, please contact the club.</p>
                </div>
        </div>
                <?php return ob_get_clean(); ?>
            <?php endif; ?>

            <?php if (!self::applications_open()): ?>
                <div class="trial-closed-notice" style="border: 2px solid #e0e0e0; background: #f9f9f9; border-radius: 8px; padding: 16px 20px;">
                    <p style="margin: 0 0 6px 0;"><strong>Trial applications are currently closed.</strong></p>
                    <p style="margin: 0;">Keep an eye on the club's socials and emails for when applications open for the next season.</p>
                </div>
        </div>
                <?php return ob_get_clean(); ?>
            <?php endif; ?>

            <?php if (!$profile_validation['is_complete']): ?>
                <div class="trial-profile-incomplete" style="border: 2px solid #dc3232; background: #fdf0f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;">
                    <p style="margin: 0 0 6px 0;"><strong>⚠ A few profile details are missing:</strong> <?php echo esc_html(implode(', ', $profile_validation['missing_fields'])); ?></p>
                    <p style="margin: 0;">We need these for trial contact and team eligibility. <a class="button button-primary" href="<?php echo home_url('/um-member-profile-custom/?profiletab=main&um_action=edit'); ?>" target="_blank">Edit Profile</a> <small>then refresh this page.</small></p>
                </div>
            <?php endif; ?>

            <?php
            // Is the viewer under the free-age threshold? (DOB is on file.)
            $viewer_free_by_age = false;
            if ($fee_product && intval($fee_rules['free_under']) > 0) {
                $viewer_dob = get_user_meta($user->ID, 'birth_date', true);
                $viewer_ts = $viewer_dob ? strtotime(str_replace('/', '-', $viewer_dob)) : false;
                if ($viewer_ts && (new DateTime('@' . $viewer_ts))->diff(new DateTime())->y < intval($fee_rules['free_under'])) {
                    $viewer_free_by_age = true;
                }
            }
            ?>
            <?php if ($fee_product): ?>
                <?php
                // Who the fee applies to is read from the configured rules,
                // so the sentence stays true when a rule is toggled.
                $who = array();
                if (!empty($fee_rules['charge_new'])) { $who[] = 'new to VVL'; }
                if (!empty($fee_rules['charge_transfer'])) { $who[] = 'transferring from another club'; }
                if (!empty($fee_rules['charge_lapsed'])) { $who[] = 'returning after a season away'; }
                if (!empty($fee_rules['charge_returning'])) { $who[] = 'returning from last season'; }
                $who_text = '';
                if (count($who) === 1) {
                    $who_text = $who[0];
                } elseif (count($who) > 1) {
                    $last = array_pop($who);
                    $who_text = implode(', ', $who) . ' or ' . $last;
                }
                ?>
                <div class="trial-fee-notice" id="trial-fee-notice">
                    <p><strong>Trial registration fee: <?php echo wp_kses_post($fee_product->get_price_html()); ?></strong><br>
                    <?php if ($who_text !== ''): ?>The fee applies to players <strong><?php echo esc_html($who_text); ?></strong> — after<?php else: ?>After<?php endif; ?> submitting you'll be taken to the checkout to pay. Coaches can't select you for a team until the fee is paid.<?php if (empty($fee_rules['charge_returning'])): ?> If you played for Renegades last season you can ignore this message, as your yearly membership covers your cost.<?php endif; ?></p>
                    <?php if ($viewer_free_by_age): ?>
                        <p><strong>You're under <?php echo intval($fee_rules['free_under']); ?>, so trials are free if you only pick Youth State League teams</strong> (U/17 and U/15). Trialling for Junior Premier League or a senior team means the fee applies as above.</p>
                    <?php endif; ?>
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
                    <p><strong>Before you start:</strong> make sure you can commit to the team for the season. <a href="<?php echo esc_url($training_info_url); ?>" target="_blank">Check the training venues and days</a> and pick teams whose sessions you can actually attend.</p>
                </div>
            <?php endif; ?>
            <div class="trial-prefill-section">
                <h4>Your Details</h4>
                <p class="prefill-note">These details come from your account. <a href="<?php echo esc_url($profile_edit_url); ?>" target="_blank">Edit your profile</a> to change them, then refresh this page.</p>
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
                                <?php if ($editing): ?>
                                    <?php // An application belongs to one season; editing never moves it. ?>
                                    <option value="<?php echo esc_attr($my_application->season); ?>" selected><?php echo esc_html($my_application->season); ?></option>
                                <?php else: ?>
                                    <?php foreach (self::get_open_seasons() as $i => $open_season): ?>
                                        <option value="<?php echo esc_attr($open_season); ?>" <?php selected($i, 0); ?>><?php echo esc_html($open_season); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>What's your VVL playing history? <span class="required">*</span><br>
                            <small style="font-weight: normal; color: #666;">VVL (Volleyball Victoria League) only — social competitions and leagues outside Volleyball Victoria don't count here.</small>
                        </th>
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
                        <th>Which season did you last play VVL? <span class="required">*</span></th>
                        <td>
                            <select name="transfer_season">
                                <option value="">Select Season</option>
                                <?php foreach (self::get_transfer_season_options() as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="transfer-section" style="display: none;">
                        <th>For which club did you play your last season <span class="required">*</span></th>
                        <td>
                            <select name="transfer_club">
                                <option value="">Select Club</option>
                                <?php foreach (self::get_transfer_clubs() as $club): ?>
                                    <option value="<?php echo esc_attr($club); ?>"><?php echo esc_html($club); ?></option>
                                <?php endforeach; ?>
                                <option value="__other">Other Victorian club</option>
                                <option value="__interstate">Interstate club</option>
                                <option value="__international">International club</option>
                            </select>
                            <input type="text" name="transfer_club_other" maxlength="100" placeholder="Club name" style="display: none; margin-top: 6px;">
                        </td>
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
                            <?php
                            $team_groups = array('mens' => "Men's Teams", 'womens' => "Women's Teams", 'mixed' => 'Mixed / Open Teams');
                            // The applicant's own competition leads, so
                            // nobody scrolls past a greyed-out block to
                            // reach the teams they can actually pick.
                            if (!empty($competition['competition']) && isset($team_groups[$competition['competition']])) {
                                $team_groups = array($competition['competition'] => $team_groups[$competition['competition']]) + $team_groups;
                            }
                            // Teams are configured per season, so each open
                            // season gets its own set of groups; the season
                            // selector shows the matching set and disables
                            // the others so they can never submit.
                            foreach (self::get_open_seasons() as $open_season):
                                $teams_config = $database->get_teams_config($open_season);
                                // Same order as the Configuration page: by
                                // code, naturally. The JS then sinks teams the
                                // applicant can't pick to the bottom of each
                                // group, keeping this order among the rest.
                                ksort($teams_config, SORT_NATURAL | SORT_FLAG_CASE);
                                $team_order = 0;
                            ?>
                            <div class="teams-checkboxes" data-season="<?php echo esc_attr($open_season); ?>">
                                <?php
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
                                            <label class="team-option" data-order="<?php echo intval($team_order++); ?>" data-gender="<?php echo esc_attr($team['gender']); ?>" data-age-rule="<?php echo esc_attr($team['age_rule']); ?>">
                                                <input type="checkbox" name="interested_teams[]" value="<?php echo esc_attr($code); ?>">
                                                <?php echo esc_html($team['name']); ?><span class="ineligible-reason"></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($teams_config)): ?>
                                    <p class="description">No teams have been set up for the <?php echo esc_html($open_season); ?> season yet.</p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
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
                    <input type="submit" id="submit-trial-btn" class="button button-primary" value="<?php echo $editing ? 'Save changes' : 'Submit Trial Application'; ?>" <?php echo !$profile_validation['is_complete'] ? 'disabled' : ''; ?>>
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

                // Only the chosen season's teams are visible and submittable.
                $('.teams-checkboxes').each(function() {
                    var on = $(this).attr('data-season') === String(seasonYear);
                    $(this).toggle(on);
                    if (!on) { $(this).find('input').prop('disabled', true).prop('checked', false); }
                });

                $('.teams-checkboxes[data-season="' + seasonYear + '"] .team-option').each(function() {
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

                // Teams they can pick first, in code order; the rest sink
                // to the bottom of their group, still in code order.
                $('.teams-checkboxes[data-season="' + seasonYear + '"] .team-group').each(function() {
                    var $group = $(this);
                    var sorted = $group.children('.team-option').get().sort(function(a, b) {
                        var ia = $(a).hasClass('team-ineligible') ? 1 : 0;
                        var ib = $(b).hasClass('team-ineligible') ? 1 : 0;
                        if (ia !== ib) { return ia - ib; }
                        return parseInt($(a).attr('data-order'), 10) - parseInt($(b).attr('data-order'), 10);
                    });
                    $group.append(sorted);
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

            $('select[name="transfer_club"]').on('change', function() {
                var v = $(this).val();
                $('input[name="transfer_club_other"]').toggle(v === '__other' || v === '__interstate' || v === '__international');
            });

            // Editing: re-open the saved answers. Values go in first, then
            // change events fire so conditional sections and team
            // eligibility come up exactly as they were submitted.
            var murvcSaved = <?php echo $form_input ? wp_json_encode($form_input, JSON_HEX_TAG | JSON_HEX_AMP) : 'null'; ?>;
            if (murvcSaved) {
                var $f = $('#trial-form');
                var setRadio = function (name, value) {
                    if (value) { $f.find('input[name="' + name + '"][value="' + value + '"]').prop('checked', true).trigger('change'); }
                };
                var setField = function (name, value) {
                    if (value !== '' && value !== null && value !== undefined) { $f.find('[name="' + name + '"]').val(value).trigger('change'); }
                };
                var setChecks = function (name, values) {
                    $.each(values || [], function (i, v) {
                        $f.find('input[name="' + name + '[]"][value="' + v + '"]').prop('checked', true);
                    });
                };

                setRadio('gender_trialling', murvcSaved.gender_trialling);
                setRadio('vvl_history', murvcSaved.vvl_history);
                setField('last_level', murvcSaved.last_level);
                setField('last_level_other', murvcSaved.last_level_other);
                setField('transfer_season', murvcSaved.transfer_season);
                setField('transfer_club', murvcSaved.transfer_club);
                setField('transfer_club_other', murvcSaved.transfer_club_other);
                setField('transfer_level', murvcSaved.transfer_level);
                setField('transfer_level_other', murvcSaved.transfer_level_other);
                setRadio('international', murvcSaved.international);
                setField('country_origin', murvcSaved.country_origin);
                setRadio('registered_abroad', murvcSaved.registered_abroad);
                setField('registered_country', murvcSaved.registered_country);
                setRadio('two_sessions', murvcSaved.two_sessions);
                setField('unavailable_dates', murvcSaved.unavailable_dates);
                setField('absence_periods', murvcSaved.absence_periods);
                setField('experience', murvcSaved.experience);
                setChecks('preferred_positions', murvcSaved.preferred_positions);
                setChecks('venues', murvcSaved.venues);

                // Teams last: tick them inside their season's block, then let
                // the eligibility pass re-order the list around the ticks.
                $.each(murvcSaved.interested_teams || [], function (i, code) {
                    $f.find('.teams-checkboxes[data-season="' + murvcSaved.season + '"] input[value="' + code + '"]').prop('checked', true);
                });
                updateTeamEligibility();
            }

            // Handle form submission
            $('#trial-form').on('submit', function(e) {
                e.preventDefault();

                // One submission at a time: a second tap while the first is
                // still sending (easy on a slow phone) is ignored. The flag
                // lives on the form element, so it holds even if this handler
                // were ever bound twice.
                var $form = $(this);
                if ($form.data('submitting')) {
                    return;
                }

                // Check if submit button is disabled (profile incomplete)
                if ($('#submit-trial-btn').prop('disabled')) {
                    alert('Please complete your profile information before submitting your trial application.');
                    return;
                }

                var formData = $form.serialize();
                var $btn = $('#submit-trial-btn');
                var btnLabel = $btn.val();
                $form.data('submitting', true);
                $btn.prop('disabled', true).val('Sending…');
                // Let the player try again after a failure.
                var unlock = function () {
                    $form.data('submitting', false);
                    $btn.prop('disabled', false).val(btnLabel);
                };

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            if (response.data.redirect) {
                                // Fee due: straight to checkout.
                                $('#trial-application-form').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                                window.location.href = response.data.redirect;
                            } else if (response.data.reload) {
                                // Reload the rego page: the green panel and the
                                // saved-answers form, with a notice on top.
                                $('#submit-trial-btn').prop('disabled', true).val('Saved — reloading…');
                                // Land at the top of the page, not back down by
                                // the Save button: a reload normally restores
                                // the old scroll position.
                                if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
                                window.scrollTo(0, 0);
                                window.location.reload();
                            } else {
                                $('#trial-application-form').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            }
                        } else {
                            unlock();
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        unlock();
                        alert('There was an error submitting your application. Please try again.');
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

        .trial-flash {
            background: #46b450;
            color: #fff;
            border-radius: 8px;
            padding: 10px 16px;
            margin-bottom: 12px;
        }

        .trial-flash p {
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

        /* Unpaid: this is a to-do, not a confirmation. */
        .trial-status-panel.trial-status-unpaid {
            background: #fdf0f0;
            border-color: #dc3232;
        }

        .trial-status-unpaid .trial-number-badge {
            background: #dc3232;
        }

        .trial-pay-actions {
            margin: 10px 0 0 0 !important;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .trial-pay-note {
            font-size: 13px;
            color: #666;
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
        if (!isset($_POST['trial_nonce']) || !wp_verify_nonce($_POST['trial_nonce'], 'trial_application')) {
            wp_send_json_error(array('message' => 'Security check failed — please reload the page and try again.'));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in to submit an application.'));
        }
        
        $user = wp_get_current_user();

        if (!self::applications_open()) {
            wp_send_json_error(array('message' => 'Trial applications are currently closed.'));
        }

        // Validate profile completeness before allowing submission
        $profile_validation = $this->validate_user_profile($user->ID);
        if (!$profile_validation['is_complete']) {
            wp_send_json_error(array('message' => 'Please complete your profile information before submitting your trial application. Missing fields: ' . implode(', ', $profile_validation['missing_fields'])));
        }
        $season = sanitize_text_field($_POST['season']);
        if (!self::applications_open($season)) {
            wp_send_json_error(array('message' => 'Applications for the ' . $season . ' season are closed.'));
        }
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
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

        // Checkbox groups can't be marked required in the browser, so these
        // are the gaps that reach here: say which one, not just "required".
        if (empty($interested_teams)) {
            wp_send_json_error(array('message' => 'Pick at least one team you are trialling for.'));
        }
        if (empty($preferred_positions)) {
            wp_send_json_error(array('message' => 'Pick at least one position you are trialling for.'));
        }
        if (empty($venues)) {
            $venue_options = self::get_venue_options();
            wp_send_json_error(array('message' => 'Tick at least one training venue option — choose "' . $venue_options['all'] . '" if none are a problem.'));
        }

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
                $other = isset($_POST[$field . '_other']) ? sanitize_text_field(wp_unslash($_POST[$field . '_other'])) : '';
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
            // wp_unslash: WordPress adds magic-quote slashes to all input,
            // so "I've" would otherwise be stored as "I\'ve".
            'Unavailable Trial Dates' => isset($_POST['unavailable_dates']) ? sanitize_textarea_field(wp_unslash($_POST['unavailable_dates'])) : '',
            'Absence Periods (Apr-Sep)' => isset($_POST['absence_periods']) ? sanitize_textarea_field(wp_unslash($_POST['absence_periods'])) : '',
            'Volleyball Experience' => isset($_POST['experience']) ? sanitize_textarea_field(wp_unslash($_POST['experience'])) : '',
        );

        if ($history === 'renegades_last_season' || $history === 'renegades_previously') {
            $last_level = $resolve_level('last_level');
            if ($last_level === '') {
                wp_send_json_error(array('message' => 'Please select the level at which you last played.'));
            }
            $form_data['Last Level Played'] = $last_level;
        }

        $season_options = self::get_transfer_season_options();
        $transfer_season = '';
        if ($history === 'transfer') {
            $transfer_season = isset($_POST['transfer_season']) ? sanitize_text_field($_POST['transfer_season']) : '';
            $transfer_club = self::resolve_transfer_club(
                isset($_POST['transfer_club']) ? sanitize_text_field($_POST['transfer_club']) : '',
                isset($_POST['transfer_club_other']) ? sanitize_text_field(wp_unslash($_POST['transfer_club_other'])) : ''
            );
            $transfer_level = $resolve_level('transfer_level');
            if (!isset($season_options[$transfer_season]) || $transfer_club === null || $transfer_level === '') {
                wp_send_json_error(array('message' => 'Please complete the club transfer details.'));
            }
            $form_data['Transfer: Last VVL Season'] = $season_options[$transfer_season];
            $form_data['Transfer: Previous Club'] = $transfer_club;
            $form_data['Transfer: Level Played'] = $transfer_level;
        }

        $registered_abroad_flag = false;
        if ($international === 'yes') {
            $country_origin = isset($_POST['country_origin']) ? sanitize_text_field(wp_unslash($_POST['country_origin'])) : '';
            $registered_abroad = isset($_POST['registered_abroad']) ? sanitize_text_field($_POST['registered_abroad']) : '';
            if ($country_origin === '' || !in_array($registered_abroad, array('yes', 'no'), true)) {
                wp_send_json_error(array('message' => 'Please complete the international player details.'));
            }
            $form_data['International Player'] = 'Yes';
            $form_data['Country of Origin'] = $country_origin;
            if ($registered_abroad === 'yes') {
                $registered_abroad_flag = true;
                $registered_country = isset($_POST['registered_country']) ? sanitize_text_field(wp_unslash($_POST['registered_country'])) : '';
                $form_data['Registered Player In Another Country'] = 'Yes' . ($registered_country !== '' ? ' — ' . $registered_country : '');
            } else {
                $form_data['Registered Player In Another Country'] = 'No';
            }
        } else {
            $form_data['International Player'] = 'No';
        }

        // VV registration status: every applicant gets one. ITC only
        // applies when registered abroad AND trialling for a P1 team;
        // otherwise their VVL history decides.
        $trialling_p1 = false;
        foreach ($interested_teams as $team_code) {
            if (self::is_p1_team($team_code)) {
                $trialling_p1 = true;
            }
        }
        $form_data['Registration Type'] = self::classify_registration($season, $history, $transfer_season, $registered_abroad_flag, $trialling_p1, isset($form_data['Transfer: Level Played']) ? $form_data['Transfer: Level Played'] : '');

        // Snapshot the account details as they were at submission time.
        $form_data['Contact Number (at submission)'] = $prefill['mobile'];
        $form_data['Date of Birth (at submission)'] = $prefill['birth_date'];

        global $wpdb;

        // Serialise saves for this season. Without this, two requests landing
        // together (a double-tap on a slow phone) both see "no application
        // yet" and both insert — a duplicate with two trial numbers. The
        // same lock covers the next-number read (MAX(trial_number) and the
        // high-water mark) and the mark's update below, so two different
        // players can't be handed the same number either.
        $lock = self::submission_lock_name($season);
        $locked = self::acquire_submission_lock($lock);
        if ($locked === false) {
            wp_send_json_error(array('message' => 'Lots of people are applying right now — please wait a moment and press the button again.'));
        }

        // One application per person per season: submitting again edits it.
        // Once a team has been assigned (accepted) it's locked, which also
        // stops a second submission minting a duplicate with a new trial
        // number — the old guard only looked at pending applications.
        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE user_id = %d AND season = %s
                AND application_status IN ('accepted', 'pending', 'awaiting_payment', 'expired')
            ORDER BY FIELD(application_status, 'accepted', 'pending', 'awaiting_payment', 'expired'), id DESC
            LIMIT 1
        ", $user->ID, $season));

        if ($existing && $existing->application_status === 'accepted') {
            self::release_submission_lock($lock, $locked);
            wp_send_json_error(array('message' => 'You have already been assigned to a team for ' . $season . ', so your application can no longer be changed here. Please contact the club if something needs updating.'));
        }

        // Fee rules: returning Renegades players and juniors trial free by
        // default; new/transferring players pay when a product is set.
        $fee_product = $this->get_trial_fee_product();
        $fee_decision = self::trial_fee_decision($history, $prefill['birth_date'], $interested_teams, $season);
        $charge_fee = $fee_product && $fee_decision['payable'];

        // Editing a live (pending) application: it was either paid for or
        // waived. Paid stays paid — an edit never charges twice. Waived is
        // re-assessed, so editing into a paying category (switching to a
        // club transfer, a junior adding a senior team) makes the fee due,
        // closing the "apply free, then edit" gap. A pending application
        // marked Payable can only have got there by being paid (online or
        // Mark as Paid), so that's the test.
        $is_edit = ($existing && $existing->application_status === 'pending');
        $already_paid = false;
        $club_waived = false;
        $previous_fee = '';
        if ($is_edit) {
            $previous = json_decode((string) $existing->form_data, true);
            $previous_fee = (is_array($previous) && isset($previous['Trial Fee'])) ? (string) $previous['Trial Fee'] : '';
            $already_paid = intval($existing->order_id) > 0 || $previous_fee === 'Payable';
            // An admin's "Waive fee" is a club decision: it stands whatever
            // the player changes afterwards.
            $club_waived = strpos($previous_fee, self::CLUB_WAIVER_PREFIX) === 0;
        }

        if ($already_paid) {
            $charge_fee = false;
            $form_data['Trial Fee'] = 'Payable';
        } elseif ($club_waived) {
            $charge_fee = false;
            $form_data['Trial Fee'] = $previous_fee;
        } else {
            $form_data['Trial Fee'] = $charge_fee ? 'Payable' : 'Waived — ' . ($fee_decision['reason'] ?: 'no fee product configured');
        }
        if ($is_edit) {
            // Coaches may already have looked, so say when it last changed.
            $form_data['Last Edited'] = wp_date('j M Y, g:ia');
        }

        $application_data = array(
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'name' => $user->display_name,
            'season' => $season,
            'interested_teams' => json_encode($interested_teams),
            'preferred_positions' => json_encode($preferred_positions),
            'is_transfer_player' => $is_transfer_player,
            'form_data' => wp_json_encode($form_data),
            'application_status' => $charge_fee ? 'awaiting_payment' : 'pending'
        );
        $application_formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s');

        if ($existing) {
            // Edit (pending) or retry (unpaid / expired): the same row, so the
            // trial number, coach verdicts and notes all carry over.
            $updated = $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                $application_data,
                array('id' => $existing->id),
                $application_formats,
                array('%d')
            );
            $application_id = ($updated !== false) ? intval($existing->id) : 0;
            $trial_number = intval($existing->trial_number);

            // Record when it was last saved. Stamped with the database's
            // NOW() to match created_date's clock.
            if ($application_id) {
                $wpdb->query($wpdb->prepare("
                    UPDATE {$wpdb->prefix}trial_applications SET updated_date = NOW() WHERE id = %d
                ", $application_id));
            }
        } else {
            // Assign the next per-season trial number at submission time:
            // one past the highest ever handed out, so a deleted newest
            // application's number never goes to someone else.
            $trial_number = max(
                intval($wpdb->get_var($wpdb->prepare("
                    SELECT MAX(trial_number) FROM {$wpdb->prefix}trial_applications WHERE season = %s
                ", $season))),
                self::get_trial_number_mark($season)
            ) + 1;
            $application_data['trial_number'] = $trial_number;
            $application_formats[] = '%d';

            $inserted = $wpdb->insert($wpdb->prefix . 'trial_applications', $application_data, $application_formats);
            $application_id = $inserted ? intval($wpdb->insert_id) : 0;
            if ($application_id) {
                self::raise_trial_number_mark($season, $trial_number);
            }
        }

        // The row is written; the next request may now look.
        self::release_submission_lock($lock, $locked);

        if (!$application_id) {
            wp_send_json_error(array('message' => 'There was an error submitting your application. Please try again.'));
        }

        // (1.76.0 to 1.79.1 re-checked an automatic "not selected" hold here.
        // Since 1.80.0 that email is only sent when someone presses Send, for
        // one team, so an application save has nothing to tell it.)

        // At most one email per save, describing where the application now
        // stands: fee due, a confirmed application changed, or confirmed
        // (a new application, or an unpaid one that no longer owes). The
        // fee-due email waits 15 minutes and goes only if it is still
        // unpaid (1.80.3), since the player is usually at the checkout now.
        if ($charge_fee) {
            TeamOversight_Trial_Emails::schedule_payment_nudge($application_id);
        } else {
            TeamOversight_Trial_Emails::send_for_id($is_edit ? 'edited' : 'submitted', $application_id);
        }

        if (!$charge_fee) {
            // Back to the rego page itself, where the green panel shows the
            // trial number and status and the form is ready for more edits.
            // A one-off notice above it confirms what just happened.
            $flash = $is_edit
                ? 'Your changes have been saved.'
                : 'Thanks — your application has been submitted. Your trial number is below.';
            set_transient(self::FLASH_PREFIX . $user->ID, $flash, 5 * MINUTE_IN_SECONDS);
            wp_send_json_success(array('message' => $flash, 'reload' => true));
        }

        $checkout_url = $this->add_fee_to_cart($fee_product, $application_id);

        if (!$checkout_url) {
            wp_send_json_error(array('message' => 'Your application was saved, but the trial fee could not be added to your cart. Please contact the club to arrange payment.'));
        }

        wp_send_json_success(array(
            'message' => $is_edit
                ? 'Your changes have been saved, but they mean the trial fee now applies. Your trial number is still <strong>#' . $trial_number . '</strong>. Taking you to the checkout&hellip;'
                : 'Application saved — your trial number is <strong>#' . $trial_number . '</strong>. Taking you to the checkout to pay the trial fee&hellip;',
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

    /**
     * Link that puts an unpaid application's fee back in the cart and
     * sends the applicant to checkout. Nonced and bound to the
     * application, so it can only ever resume the owner's own payment.
     */
    public static function resume_payment_url($application_id) {
        $application_id = intval($application_id);
        return wp_nonce_url(
            add_query_arg('murvc_pay_trial', $application_id, get_permalink()),
            'murvc_pay_trial_' . $application_id
        );
    }

    /**
     * Handles that link. Re-adds the configured fee product for an
     * application that is genuinely the current user's and genuinely
     * unpaid, then redirects to checkout. Anything else falls through to
     * the page with a flash message rather than a blank failure.
     */
    public function maybe_resume_trial_payment() {
        if (empty($_GET['murvc_pay_trial']) || !is_user_logged_in()) {
            return;
        }

        $application_id = intval($_GET['murvc_pay_trial']);
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'murvc_pay_trial_' . $application_id)) {
            return;
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $owned = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}trial_applications
            WHERE id = %d AND user_id = %d AND application_status = 'awaiting_payment'
        ", $application_id, $user_id));

        $target = remove_query_arg(array('murvc_pay_trial', '_wpnonce'));
        if (!$owned) {
            // Already paid, or not theirs: send them back to the page,
            // which will show whatever the real status is.
            wp_safe_redirect($target);
            exit;
        }

        $product = $this->get_trial_fee_product();
        $checkout_url = $product ? $this->add_fee_to_cart($product, $application_id) : false;

        wp_safe_redirect($checkout_url ? $checkout_url : $target);
        exit;
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
            // Show the trial number the player was given (status panel,
            // emails), not the row id the cart line carries.
            global $wpdb;
            $trial_number = intval($wpdb->get_var($wpdb->prepare(
                "SELECT trial_number FROM {$wpdb->prefix}trial_applications WHERE id = %d",
                intval($cart_item['murvc_trial_application_id'])
            )));
            if ($trial_number > 0) {
                $item_data[] = array(
                    'key' => 'Trial number',
                    'value' => '#' . $trial_number
                );
            }
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

            $paid = $wpdb->query($wpdb->prepare("
                UPDATE {$wpdb->prefix}trial_applications
                SET application_status = 'pending', order_id = %d
                WHERE id = %d AND application_status IN ('awaiting_payment', 'expired')
            ", $order_id, $application_id));
            // This runs for both processing and completed; only the first
            // actually flips the row, so the receipt goes out once.
            if ($paid) {
                TeamOversight_Trial_Emails::send_for_id('paid', $application_id);
            }
        }
    }

    /**
     * The order's trial-fee items whose application this order paid for:
     * item id => application. None for an order that never paid (unpaid,
     * then cancelled by the stock-hold timeout), so that raises no alert.
     */
    private function paid_trial_items($order) {
        global $wpdb;

        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $application_id = intval($item->get_meta('_murvc_trial_application_id'));
            if (!$application_id) {
                continue;
            }
            $application = $wpdb->get_row($wpdb->prepare("
                SELECT id, user_id, season, trial_number FROM {$wpdb->prefix}trial_applications
                WHERE id = %d AND order_id = %d
            ", $application_id, $order->get_id()));
            if ($application) {
                $items[$item_id] = $application;
            }
        }
        return $items;
    }

    /**
     * A trial-fee order refunded in WooCommerce, fully or partly (setting
     * the status to Refunded creates a refund too). Called with the refund,
     * or with just the order on the status change, when every refund on it
     * is checked. The application keeps counting as paid; each refund of
     * each trial fee is logged once (order item meta) for an admin to
     * follow up.
     */
    public function handle_trial_refund($order_id, $refund_id = 0) {
        if (!function_exists('wc_get_order') || !class_exists('TeamOversight_Log')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || !is_callable(array($order, 'get_refunds'))) {
            return;
        }
        $paid = $this->paid_trial_items($order);
        if (empty($paid)) {
            return;
        }

        $other_products = false;
        foreach ($order->get_items() as $item) {
            if (!intval($item->get_meta('_murvc_trial_application_id'))) {
                $other_products = true;
            }
        }
        $refunds = $refund_id ? array(wc_get_order($refund_id)) : $order->get_refunds();

        foreach ($refunds as $refund) {
            if (!$refund || !is_callable(array($refund, 'get_amount'))) {
                continue;
            }

            // How much of the refund was each trial fee. A refund with no
            // line items (just an amount typed in) can't say, so it counts
            // against every trial fee on the order.
            $amounts = array();
            $itemised = false;
            foreach ($refund->get_items() as $refund_item) {
                $itemised = true;
                $refunded_id = intval($refund_item->get_meta('_refunded_item_id'));
                if (isset($paid[$refunded_id])) {
                    $amounts[$refunded_id] = (isset($amounts[$refunded_id]) ? $amounts[$refunded_id] : 0)
                        + abs(floatval($refund_item->get_total()) + floatval($refund_item->get_total_tax()));
                }
            }
            if (!$itemised) {
                foreach (array_keys($paid) as $item_id) {
                    $amounts[$item_id] = abs(floatval($refund->get_amount()));
                }
            }

            foreach ($amounts as $item_id => $amount) {
                if ($amount < 0.005
                    || !wc_add_order_item_meta($item_id, '_murvc_refund_alerted_' . intval($refund->get_id()), current_time('mysql'), true)) {
                    continue; // nothing refunded on it, or already alerted
                }
                $application = $paid[$item_id];
                TeamOversight_Log::add(
                    'trial_fee_refunded',
                    'Order #' . intval($order_id) . ': $' . number_format($amount, 2) . ' refunded in WooCommerce'
                        . (!$itemised && $other_products ? ' (not itemised, and the order has other products too: check it was the trial fee)' : '')
                        . ' on the trial fee for the ' . $application->season . ' application (trial #' . intval($application->trial_number) . '), which still counts as paid. If they should still pay, follow it up with the player.',
                    array('user_id' => intval($application->user_id), 'amount' => $amount)
                );
            }
        }
    }

    /**
     * A trial-fee order cancelled after it paid for the application.
     * Cancelling doesn't return the money by itself; the application keeps
     * counting as paid and an admin is asked to check, once per trial fee.
     */
    public function handle_trial_cancelled($order_id) {
        if (!function_exists('wc_get_order') || !class_exists('TeamOversight_Log')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach ($this->paid_trial_items($order) as $item_id => $application) {
            if (!wc_add_order_item_meta($item_id, '_murvc_refund_alerted_cancelled', current_time('mysql'), true)) {
                continue; // already alerted
            }
            $item = $order->get_item($item_id);
            $amount = $item ? floatval($item->get_total()) + floatval($item->get_total_tax()) : 0.0;
            TeamOversight_Log::add(
                'trial_fee_refunded',
                'Order #' . intval($order_id) . ' was cancelled in WooCommerce after paying the trial fee for the ' . $application->season . ' application (trial #' . intval($application->trial_number) . '), which still counts as paid. If the money went back and they should still pay, follow it up with the player.',
                array('user_id' => intval($application->user_id), 'amount' => $amount)
            );
        }
    }

    /** Trial Applications: recent trial-fee refunds and cancellations to follow up. */
    public function render_refund_notice() {
        if (!class_exists('TeamOversight_Log') || !isset($_GET['page']) || $_GET['page'] !== 'team-oversight-trials') {
            return;
        }
        TeamOversight_Log::render_alert_notice(
            array('trial_fee_refunded'),
            'Trial fees refunded or cancelled in WooCommerce in the last 30 days. Those applications still count as paid: follow up anyone who should still pay.'
        );
    }

    // Unpaid applications used to be marked 'expired' after 7 days
    // (expire_stale_awaiting). Since 1.63.0 they stay 'awaiting_payment'
    // until paid, so coaches keep seeing them, flagged and sorted last;
    // 'expired' rows were revived by a one-off migration. 'expired' stays
    // accepted wherever payment or a resubmission revives an application.

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

    /**
     * VVL clubs a transferring player can be coming from. Editable on the
     * Configuration page (one club per line); this hardcoded list is the
     * default when nothing has been saved.
     */
    public static function get_transfer_clubs() {
        $clubs = get_option('team_oversight_transfer_clubs', null);
        if (is_array($clubs)) {
            $clubs = array_values(array_filter(array_map('trim', $clubs)));
            if (!empty($clubs)) {
                return $clubs;
            }
        }
        return self::get_default_transfer_clubs();
    }

    public static function get_default_transfer_clubs() {
        return array(
            'Alliance Volleyball Club',
            'Carrum Downs Royals Volleyball Club',
            'Dandenong Volleyball Club',
            'Derrimut Knights',
            'Eastside Hawks Volleyball Club',
            'Heidelberg Volleyball Club Inc',
            'KVA Future Stars Academy',
            'La Trobe University Volleyball Club',
            'Macedon Ranges Volleyball Association',
            'Manningham Volleyball Association',
            'Maroondah Volleyball',
            'Mazenod Volleyball Club',
            'Melbourne University Renegades Volleyball Club',
            'Monash University Volleyball Club',
            'Mornington Volleyball Club',
            'Oakleigh Volleyball Club',
            'Phantoms Volleyball Club',
            'South Gippsland',
            'Strive Volleyball',
            'VIP',
            'Volley Friends United Melbourne',
            'Volleyball Horsham',
            'VR1 Volley',
            'Western Region Volleyball',
            'Yarra Ranges Volleyball Club',
        );
    }

    /**
     * Years offered for "the season you last played VVL": a structured
     * dropdown so registration type can be derived reliably. Values are
     * 'YYYY', plus 'earlier' for anything before the listed range.
     */
    public static function get_transfer_season_options() {
        $options = array();
        $year = intval(date('Y'));
        for ($y = $year; $y >= $year - 12; $y--) {
            $options[strval($y)] = strval($y);
        }
        $options['earlier'] = ($year - 13) . ' or earlier';
        return $options;
    }

    /**
     * Trial fee rules (Configuration → Trial application rules). Simple
     * if-then: which history categories pay the trial fee, and an age
     * below which trials are always free.
     */
    public static function get_trial_fee_rules() {
        $defaults = array(
            'charge_transfer' => 1,   // last VVL club was another club
            'charge_new' => 1,        // never played VVL
            'charge_returning' => 0,  // played for Renegades LAST season: free,
                                      // their yearly membership covers it
            'charge_lapsed' => 1,     // Renegades history but sat a season out:
                                      // no current membership, so they pay
            'free_under' => 18,       // under this age: always free (0 disables)
        );
        $rules = get_option('team_oversight_trial_fee_rules', array());
        return array_merge($defaults, is_array($rules) ? $rules : array());
    }

    /** VVL age rules that identify a Youth State League team. */
    public static function youth_age_rules() {
        return array('u17', 'u15');
    }

    /**
     * Is every team they picked a Youth State League team? The junior age
     * exemption hangs on this: a junior trialling only for YSL is free,
     * but the same junior reaching up to JPL or a senior team pays like
     * anyone else. An empty or unrecognised selection is not YSL-only —
     * the form requires at least one team, so this only guards callers
     * that pass nothing.
     */
    public static function is_youth_only($interested_teams, $season = null) {
        $interested_teams = array_filter((array) $interested_teams);
        if (empty($interested_teams)) {
            return false;
        }

        $database = new TeamOversight_Database();
        $config = $database->get_teams_config($season);
        $youth = self::youth_age_rules();

        foreach ($interested_teams as $code) {
            if (!isset($config[$code]) || !in_array($config[$code]['age_rule'], $youth, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Should this applicant pay the trial fee? The junior exemption comes
     * first but only covers a YSL-only selection; otherwise the
     * per-history-category rule decides. Returns array(payable, reason) —
     * reason explains a waiver for the admin trail.
     */
    public static function trial_fee_decision($history, $birth_date, $interested_teams = array(), $season = null) {
        $rules = self::get_trial_fee_rules();

        $free_under = intval($rules['free_under']);
        if ($free_under > 0 && $birth_date && self::is_youth_only($interested_teams, $season)) {
            $ts = strtotime(str_replace('/', '-', $birth_date));
            if ($ts) {
                $age = (new DateTime('@' . $ts))->diff(new DateTime())->y;
                if ($age < $free_under) {
                    return array('payable' => false, 'reason' => 'under ' . $free_under . ', Youth State League only');
                }
            }
        }

        // Renegades history splits in two: last season's players are covered
        // by the yearly membership they already paid, whereas someone back
        // after a season away holds no current membership and pays like
        // anyone else.
        if ($history === 'renegades_last_season') {
            return !empty($rules['charge_returning'])
                ? array('payable' => true, 'reason' => '')
                : array('payable' => false, 'reason' => 'played for Renegades last season');
        }
        if ($history === 'renegades_previously') {
            return !empty($rules['charge_lapsed'])
                ? array('payable' => true, 'reason' => '')
                : array('payable' => false, 'reason' => 'returning Renegades player');
        }

        $charged = ($history === 'transfer') ? !empty($rules['charge_transfer']) : !empty($rules['charge_new']);
        return $charged
            ? array('payable' => true, 'reason' => '')
            : array('payable' => false, 'reason' => 'fee disabled for this category');
    }

    /** Premier League 1 team codes start with PL1 (PL1M / PL1W). */
    public static function is_p1_team($code) {
        return strpos((string) $code, 'PL1') === 0;
    }

    /**
     * VV registration status for every applicant:
     *  - ITC           registered with an overseas federation AND
     *                  trialling for a Premier League 1 team (the ITC only
     *                  matters at P1 — at any other level their history
     *                  decides)
     *  - Club Transfer last VVL club was a different club, as recently as
     *                  the season before the one applied for
     *  - Free Agent    played VVL elsewhere but skipped at least one season,
     *                  or last played Youth State League there (club
     *                  decision, 1.74.0: no club transfer from YSL)
     *  - Returning     Renegades history, no other VVL club since
     *  - New           never played VVL
     * An admin can set a different one per application (Trial
     * Applications), which registration_type() then returns.
     */
    public static function classify_registration($app_season, $history, $last_season_raw, $registered_abroad, $trialling_p1 = false, $transfer_level = '') {
        if ($registered_abroad && $trialling_p1) {
            return 'ITC';
        }
        if ($history === 'transfer') {
            if (self::is_youth_level($transfer_level)) {
                return 'Free Agent';
            }
            $last = ($last_season_raw === 'earlier') ? 0 : intval($last_season_raw);
            return ($last >= intval($app_season) - 1) ? 'Club Transfer' : 'Free Agent';
        }
        if ($history === 'never_played') {
            return 'New';
        }
        return 'Returning';
    }

    /** The registration types, as stored and shown. */
    public static function registration_types() {
        return array('ITC', 'Club Transfer', 'Free Agent', 'Returning', 'New');
    }

    /**
     * Is this level (a get_level_options() key, or the label stored with
     * the application) Youth State League?
     */
    public static function is_youth_level($level) {
        $level = trim((string) $level);
        return strpos($level, 'YSL') === 0 || stripos($level, 'Youth State League') === 0;
    }

    /**
     * An application's registration type, the one place every screen and
     * export reads it from: the admin's override when there is one, else
     * the type worked out at submission, with the YSL rule applied to
     * applications saved before it (their stored type is never rewritten).
     * $application: a trial_applications row.
     */
    public static function registration_type($application) {
        $application = (object) $application;
        $override = isset($application->registration_override) ? trim((string) $application->registration_override) : '';
        if ($override !== '' && in_array($override, self::registration_types(), true)) {
            return $override;
        }
        return self::worked_out_registration_type($application);
    }

    /** The registration type from the answers alone (no override). */
    public static function worked_out_registration_type($application) {
        $application = (object) $application;
        $form_data = isset($application->form_data) ? $application->form_data : null;
        $form_data = is_array($form_data) ? $form_data : ($form_data ? json_decode((string) $form_data, true) : array());
        $form_data = is_array($form_data) ? $form_data : array();
        if (isset($form_data['Registration Type']) && $form_data['Registration Type'] !== '') {
            $type = $form_data['Registration Type'];
        } else {
            $type = (isset($application->is_transfer_player) && intval($application->is_transfer_player) === 1) ? 'Club Transfer' : '';
        }
        if ($type === 'Club Transfer' && isset($form_data['Transfer: Level Played']) && self::is_youth_level($form_data['Transfer: Level Played'])) {
            $type = 'Free Agent';
        }
        return $type;
    }

    /**
     * Resolve the submitted transfer-club selection into the stored value:
     * a listed club verbatim, or "Other/Interstate/International: <name>".
     * Null when invalid or incomplete.
     */
    public static function resolve_transfer_club($select, $other) {
        $select = trim((string) $select);
        $other = trim((string) $other);
        $specials = array(
            '__other' => 'Other',
            '__interstate' => 'Interstate',
            '__international' => 'International',
        );

        if (isset($specials[$select])) {
            return $other !== '' ? $specials[$select] . ': ' . $other : null;
        }
        if (in_array($select, self::get_transfer_clubs(), true)) {
            return $select;
        }
        return null;
    }

    /**
     * Seasons currently open for trial applications. Never-configured
     * default: current + next year (matching pre-1.28 behaviour), unless
     * the old global switch was turned off.
     */
    public static function get_open_seasons() {
        $open = get_option('team_oversight_trial_open_seasons', null);
        if (is_array($open)) {
            $open = array_values(array_filter(array_map('strval', $open)));
            sort($open);
            return $open;
        }
        if (get_option('team_oversight_trials_open', '1') !== '1') {
            return array();
        }
        return array(date('Y'), strval(intval(date('Y')) + 1));
    }

    /**
     * The season trials are currently about: the latest season open for
     * applications. When nothing is open, the latest season anyone has
     * applied for — so an accepted player still sees their offer after
     * applications close, but never a season older than the newest one.
     */
    public static function get_current_trial_season() {
        global $wpdb;

        $open = self::get_open_seasons();
        if (!empty($open)) {
            return strval(max(array_map('intval', $open)));
        }

        $latest = $wpdb->get_var("SELECT MAX(season) FROM {$wpdb->prefix}trial_applications");
        return $latest ? strval($latest) : date('Y');
    }

    /**
     * Is the club taking trial applications — for a specific season, or
     * at all (null)?
     */
    public static function applications_open($season = null) {
        $open = self::get_open_seasons();
        if ($season === null) {
            return !empty($open);
        }
        return in_array(strval($season), $open, true);
    }

    public function validate_user_profile($user_id) {
        // Only what a trial application genuinely needs: contact details
        // plus DOB and gender (age rules and competition). MUS/degree
        // fields are a FEE matter — the annual profile wall collects them,
        // and fee class is resolved at invoicing, not at registration.
        $required_fields = array(
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'mobile_number' => 'Contact Number',
            'birth_date' => 'Date of Birth',
            'gender' => 'Gender',
        );
        
        $missing_fields = array();
        $is_complete = true;
        
        foreach ($required_fields as $field => $label) {
            // Gender lives under two UM keys; the profile form writes
            // gender_dropdown, so checking only `gender` told members who
            // had filled it in that it was missing.
            $value = ($field === 'gender')
                ? TeamOversight_Database::get_member_gender($user_id)
                : get_user_meta($user_id, $field, true);
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
    
}