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
    }
    
    public function init() {
        
    }
    
    public function render_trial_form($atts = array()) {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to submit a trial application. <a href="' . wp_login_url() . '">Login here</a></p>';
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
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'trial_applications',
            array(
                'user_id' => $user->ID,
                'email' => $user->user_email,
                'name' => $user->display_name,
                'season' => $season,
                'interested_teams' => json_encode($interested_teams),
                'preferred_positions' => json_encode($preferred_positions),
                'is_transfer_player' => $is_transfer_player,
                'application_status' => 'pending'
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
        
        if ($result) {
            wp_send_json_success(array('message' => 'Your trial application has been submitted successfully! You will be contacted regarding team assignments.'));
        } else {
            wp_send_json_error(array('message' => 'There was an error submitting your application. Please try again.'));
        }
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