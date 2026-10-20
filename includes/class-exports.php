<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Exports {
    
    public function __construct() {
    }
    
    /**
     * Spreadsheet-safe text cell for fputcsv: a value starting with
     * = + - @ tab or CR gets a leading single quote, so Excel / Sheets show
     * it as text instead of running it as a formula (display names are
     * member-controlled). Use on free-text cells only (names, emails,
     * profile text) — never on money (credits are negative) or on phone or
     * date columns.
     */
    public static function csv_cell($value) {
        $value = (string) $value;
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
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
        
        // Always ensure current and next year are available for exports
        if (!in_array($current_year, $all_seasons)) {
            $all_seasons[] = $current_year;
        }
        if (!in_array($next_year, $all_seasons)) {
            $all_seasons[] = $next_year;
        }
        
        rsort($all_seasons); // Re-sort after adding current/next year
        
        // Export interfaces show all seasons with data
        return empty($all_seasons) ? array($next_year, $current_year, $previous_year) : $all_seasons;
    }
    
    public function render_export_page() {
        if (isset($_POST['action'])) {
            $this->handle_export_action();
        }
        
        ?>
        <div class="wrap">
            <h1>Export Data</h1>
            
            <div class="import-export-section">
                <h3>Team Lists Export</h3>
                <p>Export basic team assignment data with member names, emails, roles, and contact information.</p>
                
                <form method="post">
                    <table class="form-table-compact">
                        <tr>
                            <th><label for="team_lists_season">Season</label></th>
                            <td>
                                <select name="team_lists_season" required>
                                    <option value="">Select Season</option>
                                    <?php 
                                    $available_seasons = $this->get_available_seasons();
                                    $current_year = date('Y');
                                    foreach ($available_seasons as $available_season): ?>
                                        <option value="<?php echo esc_attr($available_season); ?>" <?php selected($available_season, $current_year); ?>><?php echo esc_html($available_season); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p>
                        <input type="submit" class="button button-primary" value="Export Team Lists">
                        <input type="hidden" name="action" value="export_team_lists">
                        <?php wp_nonce_field('export_team_lists', 'export_nonce'); ?>
                    </p>
                </form>
            </div>
            
            <div class="import-export-section">
                <h3>MUS Membership Report</h3>
                <p>Export membership report with MUS sport categories, member types, and gender classifications.</p>
                
                <form method="post">
                    <table class="form-table-compact">
                        <tr>
                            <th><label for="teams_season">Season</label></th>
                            <td>
                                <select name="teams_season" required>
                                    <option value="">Select Season</option>
                                    <?php 
                                    $available_seasons = $this->get_available_seasons();
                                    $current_year = date('Y');
                                    foreach ($available_seasons as $available_season): ?>
                                        <option value="<?php echo esc_attr($available_season); ?>" <?php selected($available_season, $current_year); ?>><?php echo esc_html($available_season); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p>
                        <input type="submit" class="button button-primary" value="Export MUS Membership Report">
                        <input type="hidden" name="action" value="export_mus_report">
                        <?php wp_nonce_field('export_mus_report', 'export_nonce'); ?>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function handle_export_action() {
        if (!isset($_POST['export_nonce']) || !wp_verify_nonce($_POST['export_nonce'], $_POST['action'])) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }
        
        switch ($_POST['action']) {
            case 'export_mus_report':
                $this->export_mus_report();
                break;
            case 'export_team_lists':
                $this->export_team_lists();
                break;
        }
    }
    
    private function export_team_lists() {
        global $wpdb;
        
        $season = isset($_POST['team_lists_season']) ? sanitize_text_field($_POST['team_lists_season']) : '';
        if (!preg_match('/^\d{4}$/D', $season)) {
            echo '<div class="notice notice-error"><p>Pick a season.</p></div>';
            return;
        }

        // The person is the row's account (user_id); the email snapshot is
        // only used for rows with no account id, so a changed email never
        // drops anyone. Rows with no account still list, by email.
        $team_assignments = $wpdb->get_results($wpdb->prepare("
            SELECT 
                p.team,
                p.email,
                p.role,
                p.name,
                um_birth.meta_value as birth_date,
                um_mobile.meta_value as mobile_number
            FROM (
                SELECT ta.team, ta.role,
                    COALESCE(u.ID, ue.ID) AS uid,
                    COALESCE(u.user_email, ue.user_email, ta.email) AS email,
                    COALESCE(u.display_name, ue.display_name, '') AS name
                FROM {$wpdb->prefix}team_assignments ta
                LEFT JOIN {$wpdb->users} u ON ta.user_id > 0 AND u.ID = ta.user_id
                LEFT JOIN {$wpdb->users} ue ON (ta.user_id IS NULL OR ta.user_id = 0) AND ue.user_email = ta.email
                WHERE ta.season = %s AND ta.is_active = 1
            ) p
            LEFT JOIN {$wpdb->usermeta} um_birth ON um_birth.user_id = p.uid AND um_birth.meta_key = 'birth_date'
            LEFT JOIN {$wpdb->usermeta} um_mobile ON um_mobile.user_id = p.uid AND um_mobile.meta_key = 'mobile_number'
            ORDER BY p.team, p.role, p.name
        ", $season));
        
        if (empty($team_assignments)) {
            echo '<div class="notice notice-warning"><p>No team assignments found for the selected season.</p></div>';
            return;
        }
        
        $filename = "team_lists_{$season}_" . date('Y-m-d') . ".csv";
        
        // Clean output buffer to prevent HTML insertion
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");

        $header = array('Team', 'Name', 'Email', 'Role', 'Birth Date', 'Mobile Number');
        fputcsv($output, $header);
        
        foreach ($team_assignments as $assignment) {
            $row = array(
                $assignment->team,
                self::csv_cell($assignment->name),
                self::csv_cell($assignment->email),
                str_replace('_', ' ', ucwords($assignment->role, '_')),
                $assignment->birth_date ?: '',
                $assignment->mobile_number ?: ''
            );
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    private function export_mus_report() {
        global $wpdb;
        
        $season = isset($_POST['teams_season']) ? sanitize_text_field($_POST['teams_season']) : '';
        if (!preg_match('/^\d{4}$/D', $season)) {
            echo '<div class="notice notice-error"><p>Pick a season.</p></div>';
            return;
        }

        // Get comprehensive member data including Ultimate Member profile
        // fields. The person is the row's account (user_id); the email
        // snapshot is only used for rows with no account id, so a changed
        // email never drops anyone. Rows with no account still list.
        // Gender is read per person below (two UM keys).
        $members = $wpdb->get_results($wpdb->prepare("
            SELECT 
                p.team,
                p.email,
                p.role,
                p.registration_status,
                p.uid,
                p.name,
                um_birth.meta_value as birth_date,
                um_mobile.meta_value as mobile_number,
                um_inst1.meta_value as institution1,
                um_inst2.meta_value as institution2,
                um_degree1.meta_value as degree1type,
                um_degree2.meta_value as degree2type,
                um_degree1start.meta_value as degree1startdate,
                um_degree2start.meta_value as degree2startdate,
                um_degree1end.meta_value as degree1enddate,
                um_degree2end.meta_value as degree2enddate
            FROM (
                SELECT ta.team, ta.role, ta.registration_status,
                    COALESCE(u.ID, ue.ID) AS uid,
                    COALESCE(u.user_email, ue.user_email, ta.email) AS email,
                    COALESCE(u.display_name, ue.display_name, '') AS name
                FROM {$wpdb->prefix}team_assignments ta
                LEFT JOIN {$wpdb->users} u ON ta.user_id > 0 AND u.ID = ta.user_id
                LEFT JOIN {$wpdb->users} ue ON (ta.user_id IS NULL OR ta.user_id = 0) AND ue.user_email = ta.email
                WHERE ta.season = %s AND ta.is_active = 1
            ) p
            LEFT JOIN {$wpdb->usermeta} um_birth ON p.uid = um_birth.user_id AND um_birth.meta_key = 'birth_date'
            LEFT JOIN {$wpdb->usermeta} um_mobile ON p.uid = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            LEFT JOIN {$wpdb->usermeta} um_inst1 ON p.uid = um_inst1.user_id AND um_inst1.meta_key = 'institution1'
            LEFT JOIN {$wpdb->usermeta} um_inst2 ON p.uid = um_inst2.user_id AND um_inst2.meta_key = 'institution2'
            LEFT JOIN {$wpdb->usermeta} um_degree1 ON p.uid = um_degree1.user_id AND um_degree1.meta_key = 'degree1type'
            LEFT JOIN {$wpdb->usermeta} um_degree2 ON p.uid = um_degree2.user_id AND um_degree2.meta_key = 'degree2type'
            LEFT JOIN {$wpdb->usermeta} um_degree1start ON p.uid = um_degree1start.user_id AND um_degree1start.meta_key = 'degree1startdate'
            LEFT JOIN {$wpdb->usermeta} um_degree2start ON p.uid = um_degree2start.user_id AND um_degree2start.meta_key = 'degree2startdate'
            LEFT JOIN {$wpdb->usermeta} um_degree1end ON p.uid = um_degree1end.user_id AND um_degree1end.meta_key = 'degree1enddate'
            LEFT JOIN {$wpdb->usermeta} um_degree2end ON p.uid = um_degree2end.user_id AND um_degree2end.meta_key = 'degree2enddate'
            ORDER BY p.team, p.role, p.name
        ", $season));
        
        if (empty($members)) {
            echo '<div class="notice notice-warning"><p>No team assignments found for the selected season.</p></div>';
            return;
        }

        // One query loads every listed account's meta for the gender reads.
        $uids = array_values(array_unique(array_filter(array_map('intval', wp_list_pluck($members, 'uid')))));
        if (!empty($uids)) {
            update_meta_cache('user', $uids);
        }
        
        $filename = "mus_membership_report_{$season}_" . date('Y-m-d') . ".csv";
        
        // Clean output buffer to prevent HTML insertion
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");

        $header = array(
            'Team', 'Name', 'Email', 'Role', 'Birth Date', 'Mobile Number', 'Gender',
            'MUS Sport Category', 'Member Type', 'Registration Status'
        );
        fputcsv($output, $header);
        
        foreach ($members as $member) {
            // Determine MUS Sport Category
            $mus_category = $this->determine_mus_category($member);
            
            // Determine Member Type based on role
            $member_type = $this->determine_member_type($member->role, $member->registration_status);
            
            // Normalize gender (current UM key first, older serialized key
            // as fallback); no account = not specified.
            $gender = $this->normalize_gender($member->uid ? TeamOversight_Database::get_member_gender(intval($member->uid)) : '');
            
            $row = array(
                $member->team,
                self::csv_cell($member->name),
                self::csv_cell($member->email),
                $this->format_role_name($member->role),
                $member->birth_date ?: '',
                $member->mobile_number ?: '',
                $gender,
                $mus_category,
                $member_type,
                self::csv_cell(ucfirst((string) $member->registration_status))
            );
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    private function determine_mus_category($member) {
        // Use degree2 fields if they exist, otherwise use degree1 fields
        $institution = $member->institution2 ?: $member->institution1;
        $degree_type = $member->degree2type ?: $member->degree1type;
        $start_date = $member->degree2startdate ?: $member->degree1startdate;
        $end_date = $member->degree2enddate ?: $member->degree1enddate;
        
        if (!$institution) {
            return 'Other';
        }
        
        // Check if Melbourne University
        $is_melb_uni = (stripos($institution, 'Melbourne') !== false && stripos($institution, 'University') !== false);
        
        if ($is_melb_uni) {
            if (!$degree_type) {
                return 'Other';
            }
            
            // Determine if staff based on degree type
            if (stripos($degree_type, 'staff') !== false || stripos($degree_type, 'employee') !== false) {
                return 'Melbourne University - Staff';
            }
            
            // Determine student vs alumni status
            $is_current_student = true;
            if ($end_date) {
                $is_current_student = (strtotime($end_date) > time());
            }
            
            if ($is_current_student) {
                // Determine undergrad vs postgrad
                if (stripos($degree_type, 'bachelor') !== false || stripos($degree_type, 'undergraduate') !== false) {
                    return 'Melbourne University - Undergrad. Student';
                } elseif (stripos($degree_type, 'master') !== false || stripos($degree_type, 'phd') !== false || stripos($degree_type, 'doctorate') !== false || stripos($degree_type, 'postgraduate') !== false) {
                    return 'Melbourne University - Postgrad. Student';
                } else {
                    // Default to undergrad if unclear
                    return 'Melbourne University - Undergrad. Student';
                }
            } else {
                return 'Melbourne University - Alumni';
            }
        } else {
            // Other university
            if ($degree_type) {
                return 'Student / Alumni of another university';
            } else {
                return 'Other';
            }
        }
    }
    
    private function determine_member_type($role, $status) {
        if ($status === 'inactive') {
            return 'Non-Active';
        }
        
        // Convert role to lowercase for easier matching
        $role_lower = strtolower($role);
        
        if (strpos($role_lower, 'playing_member') !== false || 
            strpos($role_lower, 'training_only') !== false || 
            strpos($role_lower, 'team_manager') !== false) {
            return 'Active Member';
        } elseif (strpos($role_lower, 'coach') !== false || 
                  strpos($role_lower, 'assistant_coach') !== false) {
            return 'Coach / Instructor';
        } elseif (strpos($role_lower, 'supporter') !== false) {
            return 'Officials';
        } else {
            // Default to Active Member for unknown roles
            return 'Active Member';
        }
    }
    
    private function normalize_gender($gender) {
        if (!$gender) {
            return 'Not Specified';
        }
        
        $gender_lower = strtolower(trim($gender));
        
        if (in_array($gender_lower, array('male', 'm', 'man'))) {
            return 'Male';
        } elseif (in_array($gender_lower, array('female', 'f', 'woman'))) {
            return 'Female';
        } elseif (in_array($gender_lower, array('non-binary', 'nonbinary', 'nb', 'other'))) {
            return 'Non-Binary';
        } else {
            // Anything else ("prefer not to say", unexpected values) is
            // reported as not specified, never echoed.
            return 'Not Specified';
        }
    }
    
    private function format_role_name($role) {
        return str_replace('_', ' ', ucwords($role, '_'));
    }
}