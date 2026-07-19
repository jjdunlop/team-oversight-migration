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
    
    public function render_trial_form($atts = array()) {
        if (!is_user_logged_in()) {
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            return '<div class="trial-login-required" style="border: 2px solid #e0e0e0; border-radius: 8px; padding: 20px; background: #f9f9f9;">'
                . '<p><strong>You must be logged in with your club account to submit a trial application.</strong></p>'
                . '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in to continue</a></p>'
                . '</div>';
        }
        
        $user = wp_get_current_user();
        $database = new TeamOversight_Database();
        $fees = new TeamOversight_Fees();
        
        // Calculate MUS status and validate profile
        $mus_status = $fees->determine_fee_class($user->user_email);
        $profile_validation = $this->validate_user_profile($user->ID);
        
        ob_start();
        ?>
        <div id="trial-application-form">
            <h3>Trial Application Form</h3>
            
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

            <form id="trial-form" method="post">
                <table class="form-table">
                    <tr>
                        <th><label for="season">Season</label></th>
                        <td>
                            <select name="season" id="season" required>
                                <option value="">Select Season</option>
                                <?php 
                                $current_year = date('Y');
                                $next_year = $current_year + 1;
                                $previous_year = $current_year - 1;
                                ?>
                                <option value="<?php echo $previous_year; ?>"><?php echo $previous_year; ?></option>
                                <option value="<?php echo $current_year; ?>" selected><?php echo $current_year; ?></option>
                                <option value="<?php echo $next_year; ?>"><?php echo $next_year; ?></option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="interested_teams">Teams of Interest</label></th>
                        <td>
                            <div class="teams-checkboxes">
                                <?php 
                                $teams = $database->get_teams();
                                $team_groups = array(
                                    'Premier League' => array(),
                                    'State League' => array(),
                                    'Youth State League' => array(),
                                    'Junior Premier League' => array()
                                );
                                
                                foreach ($teams as $code => $name) {
                                    if (strpos($code, 'PLD') === 0) {
                                        $team_groups['Premier League'][$code] = $name;
                                    } elseif (strpos($code, 'SLD') === 0) {
                                        $team_groups['State League'][$code] = $name;
                                    } elseif (strpos($code, 'YSL') === 0) {
                                        $team_groups['Youth State League'][$code] = $name;
                                    } elseif (strpos($code, 'JPL') === 0) {
                                        $team_groups['Junior Premier League'][$code] = $name;
                                    }
                                }
                                
                                foreach ($team_groups as $group_name => $group_teams): 
                                    if (!empty($group_teams)):
                                ?>
                                    <div class="team-group">
                                        <h4><?php echo esc_html($group_name); ?></h4>
                                        <?php foreach ($group_teams as $code => $name): ?>
                                            <label>
                                                <input type="checkbox" name="interested_teams[]" value="<?php echo esc_attr($code); ?>">
                                                <?php echo esc_html($name); ?>
                                            </label><br>
                                        <?php endforeach; ?>
                                    </div>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="preferred_positions">Preferred Positions</label></th>
                        <td>
                            <?php 
                            $positions = $database->get_positions();
                            foreach ($positions as $key => $position): 
                            ?>
                                <label>
                                    <input type="checkbox" name="preferred_positions[]" value="<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($position); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="is_transfer_player">Transfer Player Status</label></th>
                        <td>
                            <label>
                                <input type="radio" name="is_transfer_player" value="0" checked>
                                No - I did not play for a different club last year
                            </label><br>
                            <label>
                                <input type="radio" name="is_transfer_player" value="1">
                                Yes - I played for a different club last year
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="additional_comments">Additional Comments</label></th>
                        <td>
                            <textarea name="additional_comments" rows="4" cols="50" placeholder="Any additional information you'd like to share..."></textarea>
                        </td>
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
        $interested_teams = isset($_POST['interested_teams']) ? array_map('sanitize_text_field', $_POST['interested_teams']) : array();
        $preferred_positions = isset($_POST['preferred_positions']) ? array_map('sanitize_text_field', $_POST['preferred_positions']) : array();
        $is_transfer_player = intval($_POST['is_transfer_player']);
        $additional_comments = sanitize_textarea_field($_POST['additional_comments']);
        
        if (empty($season) || empty($interested_teams) || empty($preferred_positions)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }
        
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
            'application_status' => $fee_product ? 'awaiting_payment' : 'pending'
        );
        $application_formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s');

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
        } else {
            $inserted = $wpdb->insert($wpdb->prefix . 'trial_applications', $application_data, $application_formats);
            $application_id = $inserted ? intval($wpdb->insert_id) : 0;
        }

        if (!$application_id) {
            wp_send_json_error(array('message' => 'There was an error submitting your application. Please try again.'));
        }

        if (!$fee_product) {
            wp_send_json_success(array('message' => 'Your trial application has been submitted successfully! You will be contacted regarding team assignments.'));
        }

        $checkout_url = $this->add_fee_to_cart($fee_product, $application_id);

        if (!$checkout_url) {
            wp_send_json_error(array('message' => 'Your application was saved, but the trial fee could not be added to your cart. Please contact the club to arrange payment.'));
        }

        wp_send_json_success(array(
            'message' => 'Application saved! Taking you to the checkout to pay the trial fee&hellip;',
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
                    'email' => $application->email,
                    'season' => $application->season,
                    'team' => $assigned_team,
                    'role' => 'playing_member',
                    'registration_status' => 'active',
                    'start_date' => date('Y-m-d'),
                    'is_active' => 1
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d')
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
            'birth_date' => 'Date of Birth',
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