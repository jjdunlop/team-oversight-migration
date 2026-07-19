<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Fees {
    
    public function __construct() {
        add_action('wp_ajax_save_fee_matrix', array($this, 'save_fee_matrix'));
        add_action('wp_ajax_save_version', array($this, 'save_version'));
        add_action('wp_ajax_restore_version', array($this, 'restore_version'));
        add_action('wp_ajax_save_team', array($this, 'save_team'));
        add_action('wp_ajax_update_team', array($this, 'update_team'));
        add_action('wp_ajax_delete_team', array($this, 'delete_team'));
        add_action('wp_ajax_reset_default_teams', array($this, 'reset_default_teams'));
    }

    /**
     * Sanitized gender + age-limit meta from a team save/update request.
     */
    private function get_posted_team_meta() {
        $gender = isset($_POST['team_gender']) ? sanitize_text_field($_POST['team_gender']) : 'mixed';
        if (!in_array($gender, array('mens', 'womens', 'mixed'), true)) {
            $gender = 'mixed';
        }
        $age_rule = isset($_POST['team_age_rule']) ? sanitize_text_field($_POST['team_age_rule']) : '';
        if (!array_key_exists($age_rule, TeamOversight_Database::get_age_rules())) {
            $age_rule = '';
        }
        return array('gender' => $gender, 'age_rule' => $age_rule);
    }
    
    /**
     * Manually-entered season start/end dates (Configuration page), used
     * for pro-rata fees and the payment schedule. Null until both are set.
     */
    public static function get_season_dates($season) {
        $all = get_option('team_oversight_season_dates', array());
        if (!empty($all[$season]['start']) && !empty($all[$season]['end'])) {
            return $all[$season];
        }
        return null;
    }

    /**
     * Pro-rata factor for a player's season fee: joined on/before the season
     * start owes the full amount; joined later owes the fraction of the
     * season remaining from their join date. 1.0 when no dates are set.
     */
    public static function get_pro_rata_factor($email, $season) {
        $dates = self::get_season_dates($season);
        if (!$dates) {
            return 1.0;
        }

        global $wpdb;
        $join = $wpdb->get_var($wpdb->prepare("
            SELECT MIN(start_date) FROM {$wpdb->prefix}team_assignments
            WHERE email = %s AND season = %s AND is_active = 1
        ", $email, $season));

        $join_ts = $join ? strtotime($join) : current_time('timestamp');
        $start = strtotime($dates['start']);
        $end = strtotime($dates['end']);

        if ($end <= $start || $join_ts <= $start) {
            return 1.0;
        }
        if ($join_ts >= $end) {
            return 0.0;
        }
        return ($end - $join_ts) / ($end - $start);
    }

    public function import_price_matrix($season = null) {
        global $wpdb;
        
        if (!$season) {
            $season = date('Y');
        }
        
        $price_matrix_file = TEAM_OVERSIGHT_PLUGIN_DIR . 'price-matrix.csv';
        
        if (!file_exists($price_matrix_file)) {
            $price_matrix_file = ABSPATH . 'Price Matrix.csv';
        }
        
        if (file_exists($price_matrix_file)) {
            $handle = fopen($price_matrix_file, 'r');
            
            if ($handle !== FALSE) {
                $header = fgetcsv($handle);
                
                // Clear current active fees for this season only
                $wpdb->update(
                    $wpdb->prefix . 'fee_matrix',
                    array('is_active' => 0),
                    array('season' => $season, 'is_active' => 1),
                    array('%d'),
                    array('%s', '%d')
                );
                
                $version = 'csv-import-' . $season . '-' . time();
                
                while (($data = fgetcsv($handle)) !== FALSE) {
                    if (count($data) >= 3 && !empty($data[0])) {
                        $fee_class = sanitize_text_field($data[0]);
                        $team_role = sanitize_text_field($data[1]);
                        $fee_amount = floatval($data[2]);
                        
                        $wpdb->insert(
                            $wpdb->prefix . 'fee_matrix',
                            array(
                                'version' => $version,
                                'season' => $season,
                                'fee_class' => $fee_class,
                                'team_role' => $team_role,
                                'fee_amount' => $fee_amount,
                                'is_active' => 1
                            ),
                            array('%s', '%s', '%s', '%s', '%f', '%d')
                        );
                    }
                }
                
                fclose($handle);
                return true;
            }
        }
        
        return false;
    }
    
    public function get_active_fee_matrix($season = null) {
        global $wpdb;
        
        if (!$season) {
            $season = date('Y');
        }
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fee_matrix 
            WHERE season = %s AND is_active = 1
            ORDER BY fee_class, team_role
        ", $season));
    }
    
    public function get_saved_versions() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT version, version_name, created_date, COUNT(*) as fee_count
            FROM {$wpdb->prefix}fee_matrix_versions v
            LEFT JOIN {$wpdb->prefix}fee_matrix f ON v.version = f.version
            GROUP BY v.version, v.version_name, v.created_date
            ORDER BY v.created_date DESC
        ");
    }
    
    public function save_fee_matrix() {
        if (!wp_verify_nonce($_POST['fee_nonce'], 'save_fee_matrix')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $fees = $_POST['fees'] ?? array();
        $season = sanitize_text_field($_POST['season'] ?? date('Y'));
        
        // Update fees for the specific season only
        foreach ($fees as $fee_id => $fee_amount) {
            $fee_amount = floatval($fee_amount);
            $wpdb->update(
                $wpdb->prefix . 'fee_matrix',
                array('fee_amount' => $fee_amount),
                array('id' => intval($fee_id), 'season' => $season, 'is_active' => 1),
                array('%f'),
                array('%d', '%s', '%d')
            );
        }
        
        wp_send_json_success('Fees updated successfully for ' . $season . ' season');
    }
    
    public function save_version() {
        if (!wp_verify_nonce($_POST['version_nonce'], 'save_version')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $version_name = sanitize_text_field($_POST['version_name']);
        if (empty($version_name)) {
            wp_send_json_error('Version name is required');
        }
        
        $version = sanitize_title($version_name) . '-' . time();
        
        // Save version metadata
        $wpdb->insert(
            $wpdb->prefix . 'fee_matrix_versions',
            array(
                'version' => $version,
                'version_name' => $version_name,
                'created_by' => wp_get_current_user()->display_name
            ),
            array('%s', '%s', '%s')
        );
        
        // Copy current active fees to this version
        $current_fees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fee_matrix WHERE is_active = 1");
        
        foreach ($current_fees as $fee) {
            $wpdb->insert(
                $wpdb->prefix . 'fee_matrix',
                array(
                    'version' => $version,
                    'fee_class' => $fee->fee_class,
                    'team_role' => $fee->team_role,
                    'fee_amount' => $fee->fee_amount,
                    'is_active' => 0
                ),
                array('%s', '%s', '%s', '%f', '%d')
            );
        }
        
        wp_send_json_success('Version saved successfully');
    }
    
    public function restore_version() {
        if (!wp_verify_nonce($_POST['version_nonce'], 'restore_version')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $version = sanitize_text_field($_POST['version']);
        
        // Deactivate current fees
        $wpdb->update(
            $wpdb->prefix . 'fee_matrix',
            array('is_active' => 0),
            array('is_active' => 1),
            array('%d'),
            array('%d')
        );
        
        // Copy version fees as new active fees
        $version_fees = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fee_matrix WHERE version = %s
        ", $version));
        
        $new_version = 'restored-' . time();
        
        foreach ($version_fees as $fee) {
            $wpdb->insert(
                $wpdb->prefix . 'fee_matrix',
                array(
                    'version' => $new_version,
                    'fee_class' => $fee->fee_class,
                    'team_role' => $fee->team_role,
                    'fee_amount' => $fee->fee_amount,
                    'is_active' => 1
                ),
                array('%s', '%s', '%s', '%f', '%d')
            );
        }
        
        wp_send_json_success('Version restored successfully');
    }
    
    private function get_remembered_season() {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            return get_user_meta($user_id, 'team_oversight_selected_season', true);
        }
        return false;
    }
    
    private function remember_season($season) {
        $user_id = get_current_user_id();
        if ($user_id > 0 && !empty($season)) {
            update_user_meta($user_id, 'team_oversight_selected_season', $season);
        }
    }
    
    private function get_current_season() {
        // Priority: URL parameter -> user memory -> current year
        if (isset($_GET['season']) && !empty($_GET['season'])) {
            $season = sanitize_text_field($_GET['season']);
            $this->remember_season($season); // Remember the selection
            return $season;
        }
        
        $remembered_season = $this->get_remembered_season();
        if (!empty($remembered_season)) {
            return $remembered_season;
        }
        
        return date('Y');
    }
    
    public function render_fee_matrix_page() {
        global $wpdb;
        
        // Get selected season using memory system
        $selected_season = $this->get_current_season();
        
        // Handle CSV import for current season
        if (isset($_POST['action']) && $_POST['action'] === 'import_price_matrix') {
            if ($this->import_price_matrix($selected_season)) {
                echo '<div class="notice notice-success"><p>Price matrix imported successfully for ' . esc_html($selected_season) . ' season!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to import price matrix. Please check the file exists.</p></div>';
            }
        }

        // Save season start/end dates
        if (isset($_POST['action']) && $_POST['action'] === 'save_season_dates') {
            if (isset($_POST['season_dates_nonce']) && wp_verify_nonce($_POST['season_dates_nonce'], 'save_season_dates') && current_user_can('manage_options')) {
                $start = (isset($_POST['season_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['season_start'])) ? $_POST['season_start'] : '';
                $end = (isset($_POST['season_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['season_end'])) ? $_POST['season_end'] : '';

                if ($start && $end && $start < $end) {
                    $all_dates = get_option('team_oversight_season_dates', array());
                    $all_dates[$selected_season] = array('start' => $start, 'end' => $end);
                    update_option('team_oversight_season_dates', $all_dates);
                    echo '<div class="notice notice-success"><p>Season dates saved for ' . esc_html($selected_season) . ': ' . esc_html($start) . ' &ndash; ' . esc_html($end) . '. Pro-rata fees and the payment schedule now use these dates.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Please provide valid dates with the start before the end.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }
        
        $fee_matrix = $this->get_fee_matrix_for_season($selected_season);
        
        // Get available seasons for the dropdown
        $available_seasons = $this->get_available_seasons();
        
        ?>
        <div class="wrap">
            <h1>Configuration</h1>
            
            <!-- Season Selector -->
            <div class="season-filter" style="margin-bottom: 20px;">
                <label for="season-select"><strong>Season:</strong></label>
                <select id="season-select" onchange="location.href=this.value;" style="margin-left: 10px;">
                    <?php foreach ($available_seasons as $season): ?>
                        <option value="<?php echo admin_url('admin.php?page=team-oversight-fees&season=' . $season); ?>" <?php selected($selected_season, $season); ?>><?php echo esc_html($season); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Season Context Note -->
            <div class="notice notice-info" style="margin-bottom: 20px;">
                <p><strong>Configuration for <?php echo esc_html($selected_season); ?> Season</strong> - Each season has its own independent fee structure and team settings.</p>
            </div>
            
            <!-- Season Dates -->
            <?php $season_dates = self::get_season_dates($selected_season); ?>
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
                <h2 style="margin-top: 0;">Season Dates</h2>
                <p class="description">Used for pro-rata fees (players confirmed after the start owe the remaining fraction of the season) and the payment schedule (fees fall due linearly between these dates, driving the "overdue" amount members see).</p>
                <form method="post">
                    <label>Season start
                        <input type="date" name="season_start" value="<?php echo esc_attr($season_dates ? $season_dates['start'] : ''); ?>" required>
                    </label>
                    <label style="margin-left: 15px;">Season end
                        <input type="date" name="season_end" value="<?php echo esc_attr($season_dates ? $season_dates['end'] : ''); ?>" required>
                    </label>
                    <input type="hidden" name="action" value="save_season_dates">
                    <?php wp_nonce_field('save_season_dates', 'season_dates_nonce'); ?>
                    <input type="submit" class="button button-primary" value="Save Season Dates" style="margin-left: 15px;">
                    <?php if (!$season_dates): ?>
                        <span class="description" style="margin-left: 10px; color: #996800;">No dates set — fees are charged in full and nothing shows as overdue.</span>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Configuration Content with Side-by-Side Layout -->
            <div style="display: flex; gap: 30px; align-items: flex-start;">
                
                <!-- Left Column: Fee Matrix -->
                <div style="flex: 1; min-width: 500px;">
                    <h2>Fee Matrix</h2>
                    
                    <?php if (empty($fee_matrix)): ?>
                        <!-- No fee matrix exists for this season, show import option -->
                        <div class="notice notice-warning">
                            <p><strong>No fee matrix found for <?php echo esc_html($selected_season); ?> season.</strong> Import the Price Matrix CSV to create one for this season.</p>
                        </div>
                        
                        <form method="post">
                            <input type="hidden" name="action" value="import_price_matrix">
                            <input type="hidden" name="season" value="<?php echo esc_attr($selected_season); ?>">
                            <p>
                                <input type="submit" class="button button-primary" value="Import Price Matrix for <?php echo esc_html($selected_season); ?>">
                                <span class="description">Imports from price-matrix.csv in the plugin directory</span>
                            </p>
                        </form>
                        
                    <?php else: ?>
                        
                        <!-- Fee Configuration Editor -->
                        <form id="fee-matrix-form">
                            <?php wp_nonce_field('save_fee_matrix', 'fee_nonce'); ?>
                            <input type="hidden" name="season" value="<?php echo esc_attr($selected_season); ?>">
                            
                            <div class="fee-matrix-actions" style="margin-bottom: 20px;">
                                <button type="button" id="save-fees" class="button button-primary" onclick="saveFees()">Save Changes</button>
                                <div id="save-status" style="display: inline-block; margin-left: 10px;"></div>
                            </div>
                            
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>Fee Class</th>
                                        <th>Team Role</th>
                                        <th>Fee Amount ($)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fee_matrix as $fee): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($fee->fee_class); ?></strong></td>
                                            <td><?php echo esc_html($fee->team_role); ?></td>
                                            <td>
                                                $<input type="number" 
                                                       name="fees[<?php echo $fee->id; ?>]" 
                                                       value="<?php echo number_format($fee->fee_amount, 2, '.', ''); ?>" 
                                                       step="0.01" 
                                                       min="0" 
                                                       style="width: 80px; text-align: right;"
                                                       onchange="markChanged()">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                        
                    <?php endif; ?>
                </div>
                
                <!-- Right Column: Team Management -->
                <div style="flex: 1; min-width: 500px;">
                    <h2>Team Management</h2>
                    
                    <div class="team-management-section">
                        <p style="margin-bottom: 15px; color: #666;">Manage team names, codes, and configuration for the <?php echo esc_html($selected_season); ?> season.</p>
                        
                        <!-- Add New Team Form -->
                        <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                            <h3 style="margin-top: 0;">Add New Team</h3>
                            <form id="add-team-form">
                                <table class="form-table-compact" style="margin: 0;">
                                    <tr>
                                        <th style="width: 100px;"><label for="team-code">Team Code</label></th>
                                        <td><input type="text" id="team-code" name="team_code" placeholder="e.g. PLD1-M" style="width: 150px;" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-name">Team Name</label></th>
                                        <td><input type="text" id="team-name" name="team_name" placeholder="e.g. Melbourne University Renegades Blue" style="width: 300px;" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-gender">Gender</label></th>
                                        <td>
                                            <select id="team-gender" name="team_gender">
                                                <option value="mens">Men's</option>
                                                <option value="womens">Women's</option>
                                                <option value="mixed">Mixed / Open</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-age-rule">Age Eligibility</label></th>
                                        <td>
                                            <select id="team-age-rule" name="team_age_rule">
                                                <?php foreach (TeamOversight_Database::get_age_rules() as $rule_key => $rule_label): ?>
                                                    <option value="<?php echo esc_attr($rule_key); ?>"><?php echo esc_html($rule_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description">DOB cutoffs follow the VVL By-Laws and shift automatically each season.</p>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin: 15px 0 0 0;">
                                    <button type="button" id="save-team" class="button button-primary" onclick="saveTeam()">Add Team</button>
                                    <span id="team-save-status" style="margin-left: 10px;"></span>
                                </p>
                            </form>
                        </div>
                        
                        <!-- Current Teams List -->
                        <div>
                            <h3>Current Teams</h3>
                            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Team Code</th>
                                        <th>Team Name</th>
                                        <th style="width: 90px;">Gender</th>
                                        <th style="width: 80px;">Age</th>
                                        <th style="width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="teams-tbody">
                                    <?php
                                    $database = new TeamOversight_Database();
                                    $teams_config = $database->get_teams_config();
                                    $gender_labels = array('mens' => "Men's", 'womens' => "Women's", 'mixed' => 'Mixed');
                                    foreach ($teams_config as $code => $team):
                                    ?>
                                        <tr data-team-code="<?php echo esc_attr($code); ?>">
                                            <td><strong><?php echo esc_html($code); ?></strong></td>
                                            <td class="team-name-cell">
                                                <span class="display-value"><?php echo esc_html($team['name']); ?></span>
                                                <input type="text" class="edit-value" value="<?php echo esc_attr($team['name']); ?>" style="display: none; width: 100%;">
                                            </td>
                                            <td class="team-gender-cell">
                                                <span class="display-value"><?php echo esc_html($gender_labels[$team['gender']]); ?></span>
                                                <select class="edit-value" style="display: none;">
                                                    <option value="mens" <?php selected($team['gender'], 'mens'); ?>>Men's</option>
                                                    <option value="womens" <?php selected($team['gender'], 'womens'); ?>>Women's</option>
                                                    <option value="mixed" <?php selected($team['gender'], 'mixed'); ?>>Mixed</option>
                                                </select>
                                            </td>
                                            <td class="team-age-cell">
                                                <span class="display-value"><?php echo $team['age_rule'] ? strtoupper($team['age_rule']) : 'Open'; ?></span>
                                                <select class="edit-value" style="display: none;">
                                                    <?php foreach (TeamOversight_Database::get_age_rules() as $rule_key => $rule_label): ?>
                                                        <option value="<?php echo esc_attr($rule_key); ?>" <?php selected($team['age_rule'], $rule_key); ?>><?php echo esc_html($rule_key ? strtoupper($rule_key) : 'Open'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small edit-team-btn" onclick="editTeam('<?php echo esc_js($code); ?>')">Edit</button>
                                                <button type="button" class="button button-small save-team-btn" onclick="saveTeamEdit('<?php echo esc_js($code); ?>')" style="display: none;">Save</button>
                                                <button type="button" class="button button-small cancel-team-btn" onclick="cancelTeamEdit('<?php echo esc_js($code); ?>')" style="display: none;">Cancel</button>
                                                <button type="button" class="button button-small button-link-delete" onclick="deleteTeam('<?php echo esc_js($code); ?>')">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p style="margin-top: 15px;">
                                <button type="button" class="button" onclick="resetDefaultTeams()">Load default club team list</button>
                                <span class="description">Replaces all teams above with the plugin's current default list (with gender + age limits). Existing team assignments keep their old codes.</span>
                            </p>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
        
        <script>
        let hasChanges = false;
        
        function markChanged() {
            hasChanges = true;
            document.getElementById('save-fees').style.background = '#dc3232';
        }
        
        function saveFees() {
            const form = document.getElementById('fee-matrix-form');
            const formData = new FormData(form);
            formData.append('action', 'save_fee_matrix');
            
            const statusDiv = document.getElementById('save-status');
            statusDiv.textContent = 'Saving...';
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.textContent = '✓ Saved';
                    statusDiv.style.color = '#0073aa';
                    document.getElementById('save-fees').style.background = '';
                    hasChanges = false;
                } else {
                    statusDiv.textContent = '✗ Error: ' + (data.data || 'Unknown error');
                    statusDiv.style.color = '#dc3232';
                }
                setTimeout(() => statusDiv.textContent = '', 3000);
            })
            .catch(error => {
                statusDiv.textContent = '✗ Error: ' + error.message;
                statusDiv.style.color = '#dc3232';
            });
        }
        
        // Team Management Functions
        function saveTeam() {
            const teamCode = document.getElementById('team-code').value.trim();
            const teamName = document.getElementById('team-name').value.trim();
            const teamGender = document.getElementById('team-gender').value;
            const teamAgeRule = document.getElementById('team-age-rule').value;

            if (!teamCode || !teamName) {
                alert('Please fill in both team code and team name.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_team');
            formData.append('team_code', teamCode);
            formData.append('team_name', teamName);
            formData.append('team_gender', teamGender);
            formData.append('team_age_rule', teamAgeRule);
            formData.append('team_nonce', '<?php echo wp_create_nonce('save_team'); ?>');
            
            const statusDiv = document.getElementById('team-save-status');
            statusDiv.textContent = 'Saving...';
            statusDiv.style.color = '';
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.textContent = '✓ Team added successfully';
                    statusDiv.style.color = '#0073aa';
                    
                    // Reset form
                    document.getElementById('team-code').value = '';
                    document.getElementById('team-name').value = '';
                    document.getElementById('team-age-rule').value = '';

                    // Add new row to teams table
                    addTeamRow(teamCode, teamName, teamGender, teamAgeRule);
                } else {
                    statusDiv.textContent = '✗ Error: ' + (data.data || 'Unknown error');
                    statusDiv.style.color = '#dc3232';
                }
                setTimeout(() => statusDiv.textContent = '', 3000);
            })
            .catch(error => {
                statusDiv.textContent = '✗ Error: ' + error.message;
                statusDiv.style.color = '#dc3232';
            });
        }
        
        function addTeamRow(code, name, gender, ageRule) {
            const genderLabels = {mens: "Men's", womens: "Women's", mixed: 'Mixed'};
            const tbody = document.getElementById('teams-tbody');
            const newRow = document.createElement('tr');
            newRow.setAttribute('data-team-code', code);
            newRow.innerHTML = `
                <td><strong>${escapeHtml(code)}</strong></td>
                <td class="team-name-cell">
                    <span class="display-value">${escapeHtml(name)}</span>
                    <input type="text" class="edit-value" value="${escapeHtml(name)}" style="display: none; width: 100%;">
                </td>
                <td class="team-gender-cell">
                    <span class="display-value">${genderLabels[gender] || gender}</span>
                    <select class="edit-value" style="display: none;">
                        <option value="mens"${gender === 'mens' ? ' selected' : ''}>Men's</option>
                        <option value="womens"${gender === 'womens' ? ' selected' : ''}>Women's</option>
                        <option value="mixed"${gender === 'mixed' ? ' selected' : ''}>Mixed</option>
                    </select>
                </td>
                <td class="team-age-cell">
                    <span class="display-value">${ageRule ? ageRule.toUpperCase() : 'Open'}</span>
                    <select class="edit-value" style="display: none;">
                        <option value=""${ageRule === '' ? ' selected' : ''}>Open</option>
                        <option value="u19"${ageRule === 'u19' ? ' selected' : ''}>U19</option>
                        <option value="u17"${ageRule === 'u17' ? ' selected' : ''}>U17</option>
                        <option value="u15"${ageRule === 'u15' ? ' selected' : ''}>U15</option>
                    </select>
                </td>
                <td>
                    <button type="button" class="button button-small edit-team-btn" onclick="editTeam('${escapeHtml(code)}')">Edit</button>
                    <button type="button" class="button button-small save-team-btn" onclick="saveTeamEdit('${escapeHtml(code)}')" style="display: none;">Save</button>
                    <button type="button" class="button button-small cancel-team-btn" onclick="cancelTeamEdit('${escapeHtml(code)}')" style="display: none;">Cancel</button>
                    <button type="button" class="button button-small button-link-delete" onclick="deleteTeam('${escapeHtml(code)}')">Delete</button>
                </td>
            `;
            tbody.appendChild(newRow);
        }

        function editTeam(teamCode) {
            const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
            row.querySelectorAll('.display-value').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.edit-value').forEach(el => el.style.display = 'inline-block');
            row.querySelector('.edit-team-btn').style.display = 'none';
            row.querySelector('.save-team-btn').style.display = 'inline-block';
            row.querySelector('.cancel-team-btn').style.display = 'inline-block';
            row.querySelector('.team-name-cell .edit-value').focus();
        }

        function cancelTeamEdit(teamCode) {
            const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
            row.querySelectorAll('.display-value').forEach(el => el.style.display = '');
            row.querySelectorAll('.edit-value').forEach(el => el.style.display = 'none');
            row.querySelector('.edit-team-btn').style.display = 'inline-block';
            row.querySelector('.save-team-btn').style.display = 'none';
            row.querySelector('.cancel-team-btn').style.display = 'none';
        }

        function saveTeamEdit(teamCode) {
            const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
            const newName = row.querySelector('.team-name-cell .edit-value').value.trim();
            const newGender = row.querySelector('.team-gender-cell .edit-value').value;
            const newAgeRule = row.querySelector('.team-age-cell .edit-value').value;

            if (!newName) {
                alert('Team name cannot be empty.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_team');
            formData.append('team_code', teamCode);
            formData.append('team_name', newName);
            formData.append('team_gender', newGender);
            formData.append('team_age_rule', newAgeRule);
            formData.append('team_nonce', '<?php echo wp_create_nonce('update_team'); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const genderLabels = {mens: "Men's", womens: "Women's", mixed: 'Mixed'};
                    row.querySelector('.team-name-cell .display-value').textContent = newName;
                    row.querySelector('.team-gender-cell .display-value').textContent = genderLabels[newGender] || newGender;
                    row.querySelector('.team-age-cell .display-value').textContent = newAgeRule ? newAgeRule.toUpperCase() : 'Open';
                    cancelTeamEdit(teamCode);
                } else {
                    alert('Error updating team: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error updating team: ' + error.message);
            });
        }

        function resetDefaultTeams() {
            if (!confirm('Replace ALL configured teams with the default club team list (including gender and age limits)?\n\nExisting team assignments are not changed, but any assignment using an old team code will show that code without a matching team entry.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'reset_default_teams');
            formData.append('team_nonce', '<?php echo wp_create_nonce('reset_default_teams'); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }
        
        function deleteTeam(teamCode) {
            if (!confirm('Are you sure you want to delete this team? This action cannot be undone.\\n\\nTeam Code: ' + teamCode)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_team');
            formData.append('team_code', teamCode);
            formData.append('team_nonce', '<?php echo wp_create_nonce('delete_team'); ?>');
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove row from table
                    const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
                    if (row) {
                        row.remove();
                    }
                    
                    // Show success message temporarily
                    const tempMessage = document.createElement('div');
                    tempMessage.textContent = '✓ Team deleted successfully';
                    tempMessage.style.color = '#0073aa';
                    tempMessage.style.fontWeight = 'bold';
                    tempMessage.style.margin = '10px 0';
                    
                    const teamsTable = document.querySelector('.team-management-section h3');
                    teamsTable.parentNode.insertBefore(tempMessage, teamsTable.nextSibling);
                    
                    setTimeout(() => tempMessage.remove(), 3000);
                } else {
                    alert('Error deleting team: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error deleting team: ' + error.message);
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        </script>
        
        <?php
    }
    
    public function get_available_seasons() {
        global $wpdb;
        
        // Get all seasons from database (from multiple tables for comprehensive list)
        $seasons_assignments = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments ORDER BY season DESC");
        $seasons_invoices = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_invoices ORDER BY season DESC");
        $seasons_trials = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}trial_applications ORDER BY season DESC");
        
        // Merge and deduplicate all seasons from different tables
        $all_seasons = array_unique(array_merge($seasons_assignments, $seasons_invoices, $seasons_trials));
        rsort($all_seasons); // Sort descending
        
        $current_year = date('Y');
        $next_year = $current_year + 1;
        $previous_year = $current_year - 1;
        
        // Always ensure current and next year are available
        if (!in_array($current_year, $all_seasons)) {
            $all_seasons[] = $current_year;
        }
        if (!in_array($next_year, $all_seasons)) {
            $all_seasons[] = $next_year;
        }
        
        rsort($all_seasons); // Re-sort after adding current/next year
        
        // For fee matrix, show limited recent seasons (previous, current, next)
        $recent_seasons = array($next_year, $current_year, $previous_year);
        $filtered_seasons = array();
        foreach ($all_seasons as $season) {
            if (in_array($season, $recent_seasons)) {
                $filtered_seasons[] = $season;
            }
        }
        // Ensure we have at least the three key years
        foreach ($recent_seasons as $year) {
            if (!in_array($year, $filtered_seasons)) {
                $filtered_seasons[] = $year;
            }
        }
        rsort($filtered_seasons);
        return $filtered_seasons;
    }
    
    public function get_fee_matrix_for_season($season) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fee_matrix 
            WHERE season = %s AND is_active = 1
            ORDER BY fee_class, team_role
        ", $season));
    }
    
    // Keep existing methods for backwards compatibility
    public function determine_fee_class($email, $season = null) {
        $user = get_user_by('email', $email);
        if (!$user) return 'Full Adult';
        
        // Default to current year if no season provided
        if (!$season) {
            $season = date('Y');
        }
        
        $birth_date = get_user_meta($user->ID, 'birth_date', true);
        $degree1_type = get_user_meta($user->ID, 'degree1type', true);
        $institution1 = get_user_meta($user->ID, 'institution1', true);
        $degree1_enddate = get_user_meta($user->ID, 'degree1enddate', true);
        
        // Calculate age
        if ($birth_date) {
            $today = new DateTime();
            $birthdate_obj = new DateTime($birth_date);
            $age = $today->diff($birthdate_obj)->y;
            
            if ($age < 19) {
                // Check if they're in junior leagues - use correct season
                $assigned_teams = $this->get_user_teams($email, $season);
                foreach ($assigned_teams as $team) {
                    if (strpos($team, 'JPL') === 0 || strpos($team, 'YSL') === 0) {
                        return 'JPL/YSL';
                    }
                }
                return 'Junior U/19 (VVL)';
            }
        }
        
        // Check if alumni (graduated) - this is critical for correct billing
        if ($degree1_enddate && strtotime($degree1_enddate) < time()) {
            return 'Full Adult'; // Alumni get adult rates regardless of institution
        }
        
        // Check if staff - staff get adult rates
        if ($degree1_type === 'Staff') {
            return 'Full Adult';
        }
        
        // Check education status for current students only
        if ($institution1 === 'Melbourne University') {
            return 'Melb Uni Student'; // Current Melbourne Uni students
        } elseif ($institution1 === 'Other University') {
            return 'Other Student'; // Current other university students
        }
        
        // Default to Full Adult for 'Other' institution or no institution data
        return 'Full Adult';
    }
    
    public function generate_invoice($email, $season) {
        global $wpdb;
        
        // Check if invoice already exists for this user and season
        $existing_invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, invoice_amount FROM {$wpdb->prefix}team_invoices 
            WHERE email = %s AND season = %s
        ", $email, $season));
        
        if ($existing_invoice) {
            // Invoice already exists - update it if needed with new minimum fee calculation
            return $this->update_existing_invoice($email, $season, $existing_invoice->id);
        }
        
        // Get all active assignments for this user and season
        $assignments = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_assignments 
            WHERE email = %s AND season = %s AND is_active = 1
        ", $email, $season));
        
        if (empty($assignments)) {
            return false;
        }
        
        // Determine fee class
        $fee_class = $this->determine_fee_class($email, $season);
        
        // Get all applicable fees across ALL assignments
        $applicable_fees = array();
        foreach ($assignments as $assignment) {
            $fee = $this->get_fee($fee_class, $assignment->role, $season);
            if ($fee !== null) {
                $applicable_fees[] = $fee;
            }
        }
        
        if (empty($applicable_fees)) {
            // No fees found for the user's roles - this might be a configuration issue
            $assignment_roles = array_map(function($a) { return $a->role; }, $assignments);
            error_log('Invoice generation failed for ' . $email . ' - Fee Class: ' . $fee_class . ', Roles: ' . implode(', ', $assignment_roles));
            
            // Check what fees are available in the fee matrix
            $available_fees = $wpdb->get_results("
                SELECT fee_class, team_role FROM {$wpdb->prefix}fee_matrix 
                WHERE is_active = 1
                ORDER BY fee_class, team_role
            ");
            
            $fee_combinations = array();
            foreach ($available_fees as $fee) {
                $fee_combinations[] = $fee->fee_class . ' + ' . $fee->team_role;
            }
            error_log('Available fee combinations: ' . implode(', ', $fee_combinations));
            
            return false;
        }
        
        // Apply minimum fee rule - user pays the LOWEST fee across all their roles
        $minimum_fee = min($applicable_fees);

        // Pro-rata: mid-season joiners owe the remaining fraction of the season.
        $minimum_fee = round($minimum_fee * self::get_pro_rata_factor($email, $season), 2);

        // Create single invoice for all assignments
        $user = get_user_by('email', $email);
        $name = $user ? $user->display_name : $email;
        
        // Generate sequential invoice reference: SEASON-MBRFEE-NNNN
        $invoice_reference = $this->generate_invoice_reference($season);
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'team_invoices',
            array(
                'user_id' => $user ? $user->ID : null,
                'email' => $email,
                'name' => $name,
                'season' => $season,
                'invoice_amount' => $minimum_fee,
                'outstanding_amount' => $minimum_fee,
                'invoice_reference' => $invoice_reference
            ),
            array('%d', '%s', '%s', '%s', '%f', '%f', '%s')
        );
        
        if ($result === false) {
            // Log error for debugging
            error_log('Failed to create invoice for ' . $email . ' in season ' . $season . ': ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    public function get_fee($fee_class, $team_role, $season = null) {
        global $wpdb;
        
        if (!$season) {
            $season = date('Y');
        }
        
        return $wpdb->get_var($wpdb->prepare("
            SELECT fee_amount FROM {$wpdb->prefix}fee_matrix 
            WHERE fee_class = %s AND team_role = %s AND season = %s AND is_active = 1
        ", $fee_class, $team_role, $season));
    }
    
    private function get_user_teams($email, $season) {
        global $wpdb;
        
        $teams = $wpdb->get_col($wpdb->prepare("
            SELECT team FROM {$wpdb->prefix}team_assignments 
            WHERE email = %s AND season = %s AND is_active = 1
        ", $email, $season));
        
        return $teams;
    }
    
    private function update_existing_invoice($email, $season, $invoice_id) {
        global $wpdb;
        
        // Get all current active assignments for this user and season
        $assignments = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_assignments 
            WHERE email = %s AND season = %s AND is_active = 1
        ", $email, $season));
        
        if (empty($assignments)) {
            return true; // No active assignments, keep existing invoice
        }
        
        // Recalculate minimum fee based on all current assignments
        $fee_class = $this->determine_fee_class($email, $season);
        $applicable_fees = array();
        
        foreach ($assignments as $assignment) {
            $fee = $this->get_fee($fee_class, $assignment->role, $season);
            if ($fee !== null) {
                $applicable_fees[] = $fee;
            }
        }
        
        if (!empty($applicable_fees)) {
            $minimum_fee = min($applicable_fees);
            $minimum_fee = round($minimum_fee * self::get_pro_rata_factor($email, $season), 2);

            // Update existing invoice with new minimum fee if it's different
            $current_invoice = $wpdb->get_row($wpdb->prepare("
                SELECT invoice_amount, outstanding_amount FROM {$wpdb->prefix}team_invoices 
                WHERE id = %d
            ", $invoice_id));
            
            if ($current_invoice && $current_invoice->invoice_amount != $minimum_fee) {
                // Calculate new outstanding amount proportionally
                $payment_made = $current_invoice->invoice_amount - $current_invoice->outstanding_amount;
                $new_outstanding = max(0, $minimum_fee - $payment_made);
                
                $wpdb->update(
                    $wpdb->prefix . 'team_invoices',
                    array(
                        'invoice_amount' => $minimum_fee,
                        'outstanding_amount' => $new_outstanding
                    ),
                    array('id' => $invoice_id),
                    array('%f', '%f'),
                    array('%d')
                );
            }
        }
        
        return true;
    }
    
    private function generate_invoice_reference($season) {
        global $wpdb;
        
        // Get the highest invoice number for this season
        $last_invoice = $wpdb->get_var($wpdb->prepare("
            SELECT invoice_reference FROM {$wpdb->prefix}team_invoices 
            WHERE season = %s AND invoice_reference LIKE %s
            ORDER BY invoice_reference DESC
            LIMIT 1
        ", $season, $season . '-MBRFEE-%'));
        
        $next_number = 100; // Start at 0100
        
        if ($last_invoice) {
            // Extract the number from the last invoice reference
            $parts = explode('-', $last_invoice);
            if (count($parts) >= 3) {
                $last_number = intval($parts[2]);
                $next_number = $last_number + 1;
            }
        }
        
        return $season . '-MBRFEE-' . sprintf('%04d', $next_number);
    }
    
    public function save_team() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'save_team')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $team_code = sanitize_text_field($_POST['team_code']);
        $team_name = sanitize_text_field($_POST['team_name']);
        
        if (empty($team_code) || empty($team_name)) {
            wp_send_json_error('Team code and name are required');
        }
        
        // For now, we'll store teams in WordPress options
        // This is a simplified approach - in production you might want a dedicated table
        $teams = get_option('team_oversight_teams', array());
        
        // Check if team code already exists
        if (isset($teams[$team_code])) {
            wp_send_json_error('Team code already exists');
        }
        
        $teams[$team_code] = $team_name;
        update_option('team_oversight_teams', $teams);

        $meta = get_option('team_oversight_team_meta', array());
        $meta[$team_code] = $this->get_posted_team_meta();
        update_option('team_oversight_team_meta', $meta);

        wp_send_json_success('Team added successfully');
    }
    
    public function update_team() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'update_team')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $team_code = sanitize_text_field($_POST['team_code']);
        $team_name = sanitize_text_field($_POST['team_name']);
        
        if (empty($team_code) || empty($team_name)) {
            wp_send_json_error('Team code and name are required');
        }
        
        $teams = get_option('team_oversight_teams', array());
        
        // Check if team code exists
        if (!isset($teams[$team_code])) {
            wp_send_json_error('Team code not found');
        }
        
        $teams[$team_code] = $team_name;
        update_option('team_oversight_teams', $teams);

        $meta = get_option('team_oversight_team_meta', array());
        $meta[$team_code] = $this->get_posted_team_meta();
        update_option('team_oversight_team_meta', $meta);

        wp_send_json_success('Team updated successfully');
    }
    
    public function delete_team() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'delete_team')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $team_code = sanitize_text_field($_POST['team_code']);
        
        if (empty($team_code)) {
            wp_send_json_error('Team code is required');
        }
        
        $teams = get_option('team_oversight_teams', array());
        
        // Check if team code exists
        if (!isset($teams[$team_code])) {
            wp_send_json_error('Team code not found');
        }
        
        // Check if team is currently being used in assignments
        global $wpdb;
        $usage_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}team_assignments 
            WHERE team = %s AND is_active = 1
        ", $team_code));
        
        if ($usage_count > 0) {
            wp_send_json_error('Cannot delete team: ' . $usage_count . ' active assignments exist for this team');
        }
        
        unset($teams[$team_code]);
        update_option('team_oversight_teams', $teams);

        $meta = get_option('team_oversight_team_meta', array());
        if (isset($meta[$team_code])) {
            unset($meta[$team_code]);
            update_option('team_oversight_team_meta', $meta);
        }

        wp_send_json_success('Team deleted successfully');
    }

    public function reset_default_teams() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'reset_default_teams')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $defaults = TeamOversight_Database::get_default_teams();
        $names = array();
        $meta = array();
        foreach ($defaults as $code => $team) {
            $names[$code] = $team['name'];
            $meta[$code] = array('gender' => $team['gender'], 'age_rule' => $team['age_rule']);
        }
        update_option('team_oversight_teams', $names);
        update_option('team_oversight_team_meta', $meta);

        wp_send_json_success('Default team list loaded');
    }
}