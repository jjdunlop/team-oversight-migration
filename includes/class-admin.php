<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_search_users', array($this, 'ajax_search_users'));
        add_action('wp_ajax_update_assignment', array($this, 'ajax_update_assignment'));
        add_action('wp_ajax_delete_assignment', array($this, 'ajax_delete_assignment'));
        add_action('wp_ajax_update_invoice', array($this, 'ajax_update_invoice'));
        add_action('wp_ajax_delete_invoice', array($this, 'ajax_delete_invoice'));
        add_action('wp_ajax_bulk_accept_trials', array($this, 'ajax_bulk_accept_trials'));
        add_action('wp_ajax_bulk_reject_trials', array($this, 'ajax_bulk_reject_trials'));
    }
    
    public function add_admin_menu() {
        // Club-wide membership management (tiers, grants, member list).
        add_menu_page(
            'Club Membership',
            'Club Membership',
            'manage_options',
            'club-membership',
            array($this, 'members_page'),
            'dashicons-id-alt',
            30
        );

        add_submenu_page(
            'club-membership',
            'Members',
            'Members',
            'manage_options',
            'club-membership',
            array($this, 'members_page')
        );

        add_submenu_page(
            'club-membership',
            'Membership History',
            'Membership History',
            'manage_options',
            'club-membership-history',
            array($this, 'membership_history_page')
        );

        // VVL competition machinery: teams, trials, assignments, fees.
        add_menu_page(
            'VVL Oversight',
            'VVL Oversight',
            'manage_options',
            'team-oversight',
            array($this, 'dashboard_page'),
            'dashicons-groups',
            31
        );

        add_submenu_page(
            'team-oversight',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'team-oversight',
            array($this, 'dashboard_page')
        );

        add_submenu_page(
            'team-oversight',
            'Trial Applications',
            'Trial Applications',
            'manage_options',
            'team-oversight-trials',
            array($this, 'trials_page')
        );
        
        add_submenu_page(
            'team-oversight',
            'Team Assignments',
            'Team Assignments',
            'manage_options',
            'team-oversight-assignments',
            array($this, 'assignments_page')
        );
        
        add_submenu_page(
            'team-oversight',
            'Payment Management',
            'Payment Management',
            'manage_options',
            'team-oversight-invoices',
            array($this, 'invoices_page')
        );

        add_submenu_page(
            'team-oversight',
            'Player Readiness',
            'Player Readiness',
            'manage_options',
            'team-oversight-readiness',
            array($this, 'readiness_page')
        );
        
        add_submenu_page(
            'team-oversight',
            'Configuration',
            'Configuration',
            'manage_options',
            'team-oversight-fees',
            array($this, 'fees_page')
        );
        
        add_submenu_page(
            'team-oversight',
            'Import Data',
            'Import Data',
            'manage_options',
            'team-oversight-import',
            array($this, 'import_page')
        );
        
        add_submenu_page(
            'team-oversight',
            'Export Data',
            'Export Data',
            'manage_options',
            'team-oversight-export',
            array($this, 'export_page')
        );
    }
    
    public function enqueue_admin_scripts() {
        if (isset($_GET['page']) && (strpos($_GET['page'], 'team-oversight') !== false || strpos($_GET['page'], 'club-membership') !== false)) {
            wp_enqueue_style('team-oversight-admin', TEAM_OVERSIGHT_PLUGIN_URL . 'assets/admin.css', array(), TEAM_OVERSIGHT_VERSION);
            wp_enqueue_script('team-oversight-admin', TEAM_OVERSIGHT_PLUGIN_URL . 'assets/admin.js', array('jquery'), TEAM_OVERSIGHT_VERSION, true);
        }
    }
    
    public function dashboard_page() {
        global $wpdb;
        
        $seasons = $this->get_available_seasons('main');
        $current_season = $this->get_current_season();
        
        ?>
        <div class="wrap">
            <h1>VVL Oversight Dashboard</h1>
            
            <div class="season-filter">
                <label for="season-select">Season:</label>
                <select id="season-select" onchange="location.href=this.value;">
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?php echo admin_url('admin.php?page=team-oversight&season=' . $season); ?>" 
                                <?php selected($current_season, $season); ?>>
                            <?php echo esc_html($season); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php $this->render_team_summary_table($current_season); ?>
            <?php $this->render_team_detail_table($current_season); ?>
        </div>
        <?php
    }
    
    public function members_page() {
        $members_page = new TeamOversight_Members_Page();
        $members_page->render_page();
    }

    public function membership_history_page() {
        $members_page = new TeamOversight_Members_Page();
        $members_page->render_history_page();
    }

    public function trials_page() {
        if (isset($_POST['action']) && $_POST['action'] === 'save_trial_settings') {
            $this->save_trial_settings();
        }

        if (isset($_POST['action']) && $_POST['action'] === 'finalise_selections') {
            $this->finalise_selections();
        }

        if (isset($_POST['action']) && in_array($_POST['action'], array('accept_trial', 'reject_trial', 'undo_trial', 'mark_trial_paid'), true)) {
            $this->process_trial_action();
        }

        $this->render_trials_table();
    }

    /**
     * Convert coach "Selected" verdicts into real team assignments + fee
     * invoices, and mark the applications accepted. Idempotent: existing
     * active assignments are skipped, so it can be run repeatedly as
     * coaches make late additions.
     */
    private function finalise_selections() {
        if (!isset($_POST['finalise_nonce']) || !wp_verify_nonce($_POST['finalise_nonce'], 'finalise_selections')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        global $wpdb;
        $season = $this->get_current_season();

        $selected = $wpdb->get_results($wpdb->prepare("
            SELECT s.application_id, s.team, s.status, a.user_id, a.email
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.status IN ('selected', 'training_only')
                AND a.season = %s
                AND a.application_status IN ('pending', 'accepted')
            ORDER BY s.application_id, s.team
        ", $season));

        if (empty($selected)) {
            echo '<div class="notice notice-info"><p>No confirmed coach selections to finalise for ' . esc_html($season) . '.</p></div>';
            return;
        }

        $by_app = array();
        foreach ($selected as $row) {
            $by_app[$row->application_id]['user_id'] = intval($row->user_id);
            $by_app[$row->application_id]['email'] = $row->email;
            $by_app[$row->application_id]['teams'][] = array(
                'team' => $row->team,
                // Training Only verdicts finalise as training-only members
                // (and pick up the cheaper training-only fee automatically).
                'role' => $row->status === 'training_only' ? 'training_only' : 'playing_member',
            );
        }

        $fees = new TeamOversight_Fees();
        $assignments_created = 0;

        foreach ($by_app as $application_id => $info) {
            foreach ($info['teams'] as $team_entry) {
                // Only a playing assignment blocks creation — someone already
                // on the team as coach/manager can still be added as a player.
                $exists = $wpdb->get_var($wpdb->prepare("
                    SELECT id FROM {$wpdb->prefix}team_assignments
                    WHERE email = %s AND season = %s AND team = %s AND is_active = 1
                        AND role IN ('playing_member', 'training_only')
                ", $info['email'], $season, $team_entry['team']));

                if (!$exists) {
                    $wpdb->insert(
                        $wpdb->prefix . 'team_assignments',
                        array(
                            'user_id' => $info['user_id'],
                            'email' => $info['email'],
                            'season' => $season,
                            'team' => $team_entry['team'],
                            'role' => $team_entry['role'],
                            'registration_status' => 'active',
                            'start_date' => date('Y-m-d'),
                            'is_active' => 1
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
                    );
                    $assignments_created++;
                }
            }

            $fees->generate_invoice($info['email'], $season);

            $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                array(
                    'application_status' => 'accepted',
                    'assigned_team' => implode(', ', array_map(function ($t) {
                        return $t['team'] . ($t['role'] === 'training_only' ? ' (T/O)' : '');
                    }, $info['teams']))
                ),
                array('id' => $application_id),
                array('%s', '%s'),
                array('%d')
            );
        }

        echo '<div class="notice notice-success"><p>Finalised ' . count($by_app) . ' application(s): ' . $assignments_created . ' new team assignment(s) created, invoices generated, applications marked accepted.</p></div>';
    }

    private function save_trial_settings() {
        if (!isset($_POST['trial_settings_nonce']) || !wp_verify_nonce($_POST['trial_settings_nonce'], 'save_trial_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        $product_id = intval($_POST['trial_fee_product']);
        update_option('team_oversight_trial_fee_product', $product_id);

        $training_url = isset($_POST['training_info_url']) ? esc_url_raw(trim($_POST['training_info_url'])) : '';
        update_option('team_oversight_training_info_url', $training_url);

        if ($product_id) {
            echo '<div class="notice notice-success"><p>Trial fee product saved. New applications will be sent to checkout to pay before review.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Trial fee disabled. Applications now submit directly without payment.</p></div>';
        }
    }
    
    public function assignments_page() {
        if (isset($_POST['action']) && ($_POST['action'] === 'add_assignment' || $_POST['action'] === 'deactivate_assignment')) {
            $this->save_team_assignment();
        }
        
        $this->render_assignments_table();
    }
    
    public function invoices_page() {
        if (isset($_POST['action']) && $_POST['action'] === 'save_payment_settings') {
            $this->save_payment_settings();
        }

        $this->render_invoices_table();
    }

    private function save_payment_settings() {
        if (!isset($_POST['payment_settings_nonce']) || !wp_verify_nonce($_POST['payment_settings_nonce'], 'save_payment_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        $product_id = intval($_POST['payment_product']);
        update_option(TeamOversight_Payments::PAYMENT_PRODUCT_OPTION, $product_id);

        if ($product_id) {
            echo '<div class="notice notice-success"><p>Payment product saved. Members can now pay any amount against their fees via the [member_fees] page.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Online fee payment disabled.</p></div>';
        }
    }
    
    public function readiness_page() {
        $readiness = new TeamOversight_Readiness();
        $readiness->render_admin_page($this->get_current_season());
    }

    public function fees_page() {
        $fees = new TeamOversight_Fees();
        $fees->render_fee_matrix_page();
    }
    
    public function import_page() {
        $imports = new TeamOversight_Imports();
        $imports->render_import_page();
    }
    
    public function export_page() {
        $exports = new TeamOversight_Exports();
        $exports->render_export_page();
    }
    
    private function get_seasons() {
        global $wpdb;
        
        $seasons = $wpdb->get_col("
            SELECT DISTINCT season 
            FROM {$wpdb->prefix}team_assignments 
            ORDER BY season DESC
        ");
        
        if (empty($seasons)) {
            $current_year = date('Y');
            $seasons = array($current_year, $current_year - 1, $current_year - 2);
        }
        
        return $seasons;
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

    public function get_available_seasons($type = 'main') {
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
        
        if ($type === 'export') {
            // Export interfaces show all seasons with data
            return empty($all_seasons) ? array($next_year, $current_year, $previous_year) : $all_seasons;
        } else {
            // Main interfaces show limited recent seasons (previous, current, next)
            $recent_seasons = array($next_year, $current_year, $previous_year);
            // Also include any seasons from database that fall within this range
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
    }
    
    private function render_team_summary_table($season) {
        global $wpdb;
        
        $database = new TeamOversight_Database();
        $teams = $database->get_teams();
        
        $team_stats = array();
        
        foreach ($teams as $team_code => $team_name) {
            // Get team members count (approved players)
            $member_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(DISTINCT ta.email) 
                FROM {$wpdb->prefix}team_assignments ta
                WHERE ta.team = %s AND ta.season = %s AND ta.is_active = 1 
                AND ta.role IN ('playing_member', 'team_manager', 'coach', 'assistant_coach')
            ", $team_code, $season));
            
            if ($member_count > 0) {
                // Get accreditation percentage
                $accredited_count = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT ta.email) 
                    FROM {$wpdb->prefix}team_assignments ta
                    JOIN {$wpdb->prefix}team_accreditations acc ON ta.email = acc.email
                    WHERE ta.team = %s AND ta.season = %s AND ta.is_active = 1
                    AND ta.role IN ('playing_member', 'team_manager', 'coach', 'assistant_coach')
                    AND acc.vvid IS NOT NULL AND acc.accreditation_list IS NOT NULL
                ", $team_code, $season));
                
                $accredited_percentage = $member_count > 0 ? round(($accredited_count / $member_count) * 100, 1) : 0;
                
                // Get outstanding fees percentage and total
                $outstanding_data = $wpdb->get_row($wpdb->prepare("
                    SELECT 
                        COUNT(DISTINCT CASE WHEN inv.outstanding_amount > 0 THEN inv.email END) as outstanding_count,
                        SUM(CASE WHEN inv.outstanding_amount > 0 THEN inv.outstanding_amount ELSE 0 END) as total_outstanding
                    FROM {$wpdb->prefix}team_assignments ta
                    LEFT JOIN {$wpdb->prefix}team_invoices inv ON ta.email = inv.email AND inv.season = ta.season
                    WHERE ta.team = %s AND ta.season = %s AND ta.is_active = 1
                    AND ta.role IN ('playing_member', 'team_manager', 'coach', 'assistant_coach')
                ", $team_code, $season));
                
                $outstanding_percentage = $member_count > 0 ? round(($outstanding_data->outstanding_count / $member_count) * 100, 1) : 0;
                $total_outstanding = $outstanding_data->total_outstanding ?: 0;
                
                $team_stats[$team_code] = array(
                    'name' => $team_name,
                    'member_count' => $member_count,
                    'accredited_percentage' => $accredited_percentage,
                    'outstanding_percentage' => $outstanding_percentage,
                    'total_outstanding' => $total_outstanding
                );
            }
        }
        
        ?>
        <div style="margin-bottom: 30px;">
            <h2>Team Summary</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Total Approved Players</th>
                        <th>% Accredited</th>
                        <th>% Outstanding Fees</th>
                        <th>Total Outstanding Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($team_stats as $team_code => $stats): ?>
                        <tr>
                            <td><strong><?php echo esc_html($stats['name']); ?></strong></td>
                            <td><?php echo $stats['member_count']; ?></td>
                            <td>
                                <span style="color: <?php echo $stats['accredited_percentage'] >= 80 ? 'green' : ($stats['accredited_percentage'] >= 50 ? 'orange' : 'red'); ?>">
                                    <?php echo $stats['accredited_percentage']; ?>%
                                </span>
                            </td>
                            <td>
                                <span style="color: <?php echo $stats['outstanding_percentage'] <= 20 ? 'green' : ($stats['outstanding_percentage'] <= 50 ? 'orange' : 'red'); ?>">
                                    <?php echo $stats['outstanding_percentage']; ?>%
                                </span>
                            </td>
                            <td>$<?php echo number_format($stats['total_outstanding'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function render_team_detail_table($season) {
        global $wpdb;
        
        $database = new TeamOversight_Database();
        $teams = $database->get_teams();
        
        ?>
        <div>
            <h2>Team Details</h2>
            <?php foreach ($teams as $team_code => $team_name): 
                
                $team_members = $wpdb->get_results($wpdb->prepare("
                    SELECT 
                        ta.email,
                        ta.team,
                        u.display_name as name,
                        ta.role,
                        acc.vvid,
                        acc.accreditation_list,
                        inv.invoice_amount,
                        inv.outstanding_amount,
                        inv.invoice_reference,
                        um_degree1.meta_value as degree1_type,
                        um_inst1.meta_value as institution1,
                        um_birthdate.meta_value as birth_date
                    FROM {$wpdb->prefix}team_assignments ta
                    JOIN {$wpdb->users} u ON ta.email = u.user_email
                    LEFT JOIN {$wpdb->prefix}team_accreditations acc ON ta.email = acc.email
                    LEFT JOIN {$wpdb->prefix}team_invoices inv ON ta.email = inv.email AND inv.season = ta.season
                    LEFT JOIN {$wpdb->usermeta} um_degree1 ON u.ID = um_degree1.user_id AND um_degree1.meta_key = 'degree1type'
                    LEFT JOIN {$wpdb->usermeta} um_inst1 ON u.ID = um_inst1.user_id AND um_inst1.meta_key = 'institution1'
                    LEFT JOIN {$wpdb->usermeta} um_birthdate ON u.ID = um_birthdate.user_id AND um_birthdate.meta_key = 'birth_date'
                    WHERE ta.team = %s AND ta.season = %s AND ta.is_active = 1
                    ORDER BY ta.role, u.display_name
                ", $team_code, $season));
                
                if (!empty($team_members)): 
            ?>
                <div style="margin-bottom: 40px;">
                    <h3><?php echo esc_html($team_name); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>MUS Category</th>
                                <th>Payment Status</th>
                                <th>Outstanding</th>
                                <th>Accreditations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_members as $member): 
                                $fees = new TeamOversight_Fees();
                                $mus_category = $fees->determine_fee_class($member->email);
                                
                                $payment_status = 'N/A';
                                $outstanding = 0;
                                if ($member->outstanding_amount !== null) {
                                    $outstanding = $member->outstanding_amount;
                                    if ($outstanding <= 0) {
                                        $payment_status = '✓ Paid';
                                    } elseif ($outstanding < $member->invoice_amount) {
                                        $payment_status = '◐ Partial';
                                    } else {
                                        $payment_status = '⚠ Outstanding';
                                    }
                                }
                                
                                $accreditations = $this->get_member_accreditations($member, $mus_category);
                            ?>
                                <tr>
                                    <td><?php echo esc_html($member->name); ?></td>
                                    <td><?php echo esc_html(str_replace('_', ' ', ucwords($member->role, '_'))); ?></td>
                                    <td>
                                        <?php if ($mus_category === 'Unknown'): ?>
                                            <a href="<?php echo home_url('/account/'); ?>" target="_blank" style="color: #d63384;"><?php echo esc_html($mus_category); ?></a>
                                        <?php else: ?>
                                            <?php echo esc_html($mus_category); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="color: <?php echo strpos($payment_status, '✓') !== false ? 'green' : (strpos($payment_status, '◐') !== false ? 'orange' : (strpos($payment_status, '⚠') !== false ? 'red' : 'gray')); ?>">
                                            <?php echo $payment_status; ?>
                                        </span>
                                    </td>
                                    <td>$<?php echo number_format($outstanding, 2); ?></td>
                                    <td><?php echo $accreditations; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; endforeach; ?>
        </div>
        <?php
    }
    
    private function get_member_accreditations($member, $mus_category) {
        // Handle both JSON format (legacy) and plain text format (current)
        if ($member->accreditation_list) {
            // Try JSON format first (for any legacy data)
            $accreditation_data = json_decode($member->accreditation_list, true);
            if ($accreditation_data && is_array($accreditation_data)) {
                // Handle JSON format (legacy logic)
                $accreditations = array();
                foreach ($accreditation_data as $accreditation) {
                    if (isset($accreditation['Type']) && isset($accreditation['Level']) && isset($accreditation['Status'])) {
                        if ($accreditation['Status'] === 'Current' && in_array($accreditation['Type'], ['VA Coach', 'VA Referee'])) {
                            $type_abbrev = $accreditation['Type'] === 'VA Coach' ? 'VV' : 'VV';
                            $role = $accreditation['Type'] === 'VA Coach' ? 'Coach' : 'Referee';
                            $accreditations[] = $type_abbrev . ' ' . $accreditation['Level'] . ' ' . $role;
                        }
                    }
                }
                return implode(', ', $accreditations);
            } else {
                // Handle plain text format (current CSV import format)
                return esc_html($member->accreditation_list);
            }
        }
        
        // Return empty string if no accreditations
        return '';
    }
    
    private function render_trials_table() {
        global $wpdb;

        $season = $this->get_current_season();

        // Tidy up unpaid applications older than 7 days before listing.
        TeamOversight_Trials::expire_stale_awaiting();

        $trials = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE season = %s
            ORDER BY created_date DESC
        ", $season));

        // Coach selection verdicts for this season, keyed by application.
        $selections_by_app = array();
        $selection_rows = $wpdb->get_results($wpdb->prepare("
            SELECT application_id, team, status FROM {$wpdb->prefix}team_trial_selections
            WHERE season = %s ORDER BY team
        ", $season));
        foreach ($selection_rows as $selection) {
            $selections_by_app[$selection->application_id][] = $selection;
        }

        $awaiting_finalise = intval($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT s.application_id)
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.status IN ('selected', 'training_only') AND a.season = %s AND a.application_status = 'pending'
        ", $season)));

        ?>
        <div class="wrap">
            <h1>Trial Applications</h1>

            <div class="season-filter">
                <label for="season-select">Season:</label>
                <select id="season-select" onchange="location.href=this.value;">
                    <?php
                    $available_seasons = $this->get_available_seasons('main');
                    foreach ($available_seasons as $available_season): ?>
                        <option value="<?php echo admin_url('admin.php?page=team-oversight-trials&season=' . $available_season); ?>" <?php selected($season, $available_season); ?>><?php echo esc_html($available_season); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php $trial_fee_product_id = intval(get_option('team_oversight_trial_fee_product')); ?>
            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">
                    Trial settings — fee product
                    <?php if ($trial_fee_product_id): ?>
                        <span style="color: #1a7a2e;">(active: #<?php echo $trial_fee_product_id; ?> <?php echo esc_html(get_the_title($trial_fee_product_id)); ?>)</span>
                    <?php else: ?>
                        <span style="color: #996800;">(no fee — applications submit directly)</span>
                    <?php endif; ?>
                </summary>
                <p class="description">When a product is selected, submitting the trial form saves the application as "Awaiting Payment", adds this product to the cart and sends the applicant to checkout. The application only becomes reviewable once the order is paid. Unpaid applications expire after 7 days.</p>
                <form method="post">
                    <select name="trial_fee_product" style="max-width: 400px;">
                        <option value="0">No fee — applications submit directly</option>
                        <?php
                        $products = get_posts(array(
                            'post_type' => 'product',
                            'post_status' => 'publish',
                            'numberposts' => 500,
                            'orderby' => 'title',
                            'order' => 'ASC',
                        ));
                        foreach ($products as $product_post): ?>
                            <option value="<?php echo $product_post->ID; ?>" <?php selected($trial_fee_product_id, $product_post->ID); ?>><?php echo esc_html($product_post->post_title); ?> (#<?php echo $product_post->ID; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p style="margin: 12px 0 0 0;">
                        <label for="training_info_url"><strong>Training info page URL</strong></label><br>
                        <input type="url" name="training_info_url" id="training_info_url" value="<?php echo esc_attr(get_option('team_oversight_training_info_url')); ?>" style="width: 420px;" placeholder="https://members.renegades.com.au/training-times/">
                        <span class="description">Linked at the top of the trial form so players can pick teams by training venue and day. Leave empty to hide the link.</span>
                    </p>
                    <p>
                        <input type="submit" class="button button-primary" value="Save Trial Settings">
                        <input type="hidden" name="action" value="save_trial_settings">
                        <?php wp_nonce_field('save_trial_settings', 'trial_settings_nonce'); ?>
                    </p>
                </form>
            </details>

            <?php if ($awaiting_finalise): ?>
                <div class="notice notice-info" style="padding: 10px 15px; display: flex; align-items: center; gap: 15px;">
                    <span><strong><?php echo $awaiting_finalise; ?> application(s)</strong> have confirmed coach selections awaiting finalisation (team assignment + fee invoice).</span>
                    <form method="post" style="margin: 0;" onsubmit="return confirm('Finalise all coach-selected applications for <?php echo esc_attr($season); ?>?\n\nThis creates team assignments for every Selected verdict, generates fee invoices, and marks the applications accepted. Safe to run repeatedly.');">
                        <input type="hidden" name="action" value="finalise_selections">
                        <?php wp_nonce_field('finalise_selections', 'finalise_nonce'); ?>
                        <input type="submit" class="button button-primary" value="Finalise Coach Selections">
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!empty($trials)): ?>
                <div class="trials-filters" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <label for="search-trials">Search:</label>
                            <input type="text" id="search-trials" placeholder="Search name, email, teams..." style="width: 200px;">
                        </div>
                        <div>
                            <label for="filter-status">Status:</label>
                            <select id="filter-status">
                                <option value="">All Statuses</option>
                                <option value="awaiting_payment">Awaiting Payment</option>
                                <option value="pending">Pending</option>
                                <option value="accepted">Accepted</option>
                                <option value="rejected">Rejected</option>
                                <option value="expired">Expired (unpaid)</option>
                            </select>
                        </div>
                        <div>
                            <label for="filter-transfer">Transfer Player:</label>
                            <select id="filter-transfer">
                                <option value="">All</option>
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" style="margin: 10px 0; padding: 15px; background: #f0f8ff; border: 1px solid #007cba; border-radius: 4px;">
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <strong>Bulk Actions:</strong>
                        </div>
                        <div>
                            <label for="bulk-team-select">Assign Selected to Team:</label>
                            <select id="bulk-team-select" style="width: 200px;">
                                <option value="">Select Team</option>
                                <?php 
                                $database = new TeamOversight_Database();
                                $teams = $database->get_teams();
                                foreach ($teams as $code => $name): 
                                ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="bulk-accept-btn" class="button button-primary" disabled>Accept Selected</button>
                        </div>
                        <div>
                            <button type="button" id="bulk-reject-btn" class="button" disabled>Reject Selected</button>
                        </div>
                        <div>
                            <span id="selected-count" style="font-style: italic; color: #666;">0 selected</span>
                        </div>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped" id="trials-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="select-all-trials" title="Select all visible trials">
                            </th>
                            <th style="width: 55px;">Trial #</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Interested Teams</th>
                            <th>Positions</th>
                            <th>Transfer Player</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trials as $trial):
                            $interested_teams = json_decode($trial->interested_teams, true);
                            $positions = json_decode($trial->preferred_positions, true);
                            $form_data = !empty($trial->form_data) ? json_decode($trial->form_data, true) : array();
                            $gender_trialling = isset($form_data['Trialling For']) ? $form_data['Trialling For'] : '';
                        ?>
                            <tr data-trial-id="<?php echo $trial->id; ?>" data-status="<?php echo esc_attr($trial->application_status); ?>" data-transfer="<?php echo $trial->is_transfer_player ? '1' : '0'; ?>">
                                <td>
                                    <?php if ($trial->application_status === 'pending'): ?>
                                        <input type="checkbox" class="trial-checkbox" value="<?php echo $trial->id; ?>" data-email="<?php echo esc_attr($trial->email); ?>" data-season="<?php echo esc_attr($trial->season); ?>">
                                    <?php endif; ?>
                                </td>
                                <td><strong>#<?php echo intval($trial->trial_number); ?></strong></td>
                                <td><?php echo esc_html($trial->name); ?></td>
                                <td><?php echo esc_html($trial->email); ?></td>
                                <td>
                                    <?php echo esc_html(implode(', ', $interested_teams)); ?>
                                    <?php if ($gender_trialling): ?><br><small>(<?php echo esc_html($gender_trialling); ?>)</small><?php endif; ?>
                                    <?php if (!empty($selections_by_app[$trial->id])): ?>
                                        <br>
                                        <?php foreach ($selections_by_app[$trial->id] as $selection):
                                            $chip_classes = array('selected' => 'status-accepted', 'training_only' => 'status-awaiting_payment', 'tentative' => 'status-pending', 'rejected' => 'status-rejected');
                                            $chip_class = isset($chip_classes[$selection->status]) ? $chip_classes[$selection->status] : 'status-pending';
                                        ?>
                                            <span class="<?php echo $chip_class; ?>" style="margin-right: 3px;"><?php echo esc_html($selection->team . ': ' . ucwords(str_replace('_', ' ', $selection->status))); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(implode(', ', $positions)); ?></td>
                                <td><?php echo $trial->is_transfer_player ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <span class="status-<?php echo esc_attr($trial->application_status); ?>">
                                        <?php echo esc_html(ucwords(str_replace('_', ' ', $trial->application_status))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($trial->order_id)): ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . intval($trial->order_id) . '&action=edit')); ?>">Order #<?php echo intval($trial->order_id); ?></a>
                                    <?php elseif ($trial->application_status === 'awaiting_payment'): ?>
                                        <span style="color: #996800;">Unpaid</span>
                                    <?php elseif ($trial->application_status === 'expired'): ?>
                                        <span style="color: #a00;">Never paid</span>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($trial->application_status === 'awaiting_payment' || $trial->application_status === 'expired'): ?>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="trial_id" value="<?php echo $trial->id; ?>">
                                            <input type="hidden" name="action" value="mark_trial_paid">
                                            <input type="submit" class="button" value="Mark as Paid" onclick="return confirm('Mark this application as paid (payment received outside the site)? It will move to the review queue.')">
                                            <?php wp_nonce_field('process_trial', 'trial_nonce'); ?>
                                        </form>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="trial_id" value="<?php echo $trial->id; ?>">
                                            <input type="hidden" name="action" value="reject_trial">
                                            <input type="submit" class="button" value="Reject" onclick="return confirm('Are you sure?')">
                                            <?php wp_nonce_field('process_trial', 'trial_nonce'); ?>
                                        </form>
                                    <?php elseif ($trial->application_status === 'pending'): ?>
                                        <form method="post" style="display: inline;">
                                            <select name="assigned_team" required style="max-width: 150px; font-size: 12px;">
                                                <option value="">Select Team</option>
                                                <?php 
                                                $database = new TeamOversight_Database();
                                                $teams = $database->get_teams();
                                                foreach ($teams as $code => $name): 
                                                ?>
                                                    <option value="<?php echo esc_attr($code); ?>" title="<?php echo esc_attr($name); ?>"><?php echo esc_html($code); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="trial_id" value="<?php echo $trial->id; ?>">
                                            <input type="hidden" name="action" value="accept_trial">
                                            <input type="submit" class="button button-primary" value="Accept">
                                            <?php wp_nonce_field('process_trial', 'trial_nonce'); ?>
                                        </form>
                                        
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="trial_id" value="<?php echo $trial->id; ?>">
                                            <input type="hidden" name="action" value="reject_trial">
                                            <input type="submit" class="button" value="Reject" onclick="return confirm('Are you sure?')">
                                            <?php wp_nonce_field('process_trial', 'trial_nonce'); ?>
                                        </form>
                                    <?php elseif ($trial->application_status === 'accepted'): ?>
                                        <span><?php echo esc_html($trial->assigned_team); ?></span>
                                        <form method="post" style="display: inline; margin-left: 10px;">
                                            <input type="hidden" name="trial_id" value="<?php echo $trial->id; ?>">
                                            <input type="hidden" name="action" value="undo_trial">
                                            <input type="submit" class="button" value="Undo Assignment" onclick="return confirm('Are you sure you want to undo this assignment?')">
                                            <?php wp_nonce_field('process_trial', 'trial_nonce'); ?>
                                        </form>
                                    <?php elseif ($trial->application_status === 'rejected'): ?>
                                        <span>Rejected</span>
                                        <form method="post" style="display: inline; margin-left: 10px;">
                                            <input type="hidden" name="trial_id" value="<?php echo $trial->id; ?>">
                                            <input type="hidden" name="action" value="undo_trial">
                                            <input type="submit" class="button" value="Undo Rejection" onclick="return confirm('Are you sure you want to undo this rejection?')">
                                            <?php wp_nonce_field('process_trial', 'trial_nonce'); ?>
                                        </form>
                                    <?php else: ?>
                                        <?php echo esc_html($trial->assigned_team ?: 'N/A'); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($form_data)): ?>
                                        <button type="button" class="button button-small" onclick="toggleTrialDetails(<?php echo $trial->id; ?>)">Details</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($form_data)): ?>
                                <tr class="trial-details-row" id="trial-details-<?php echo $trial->id; ?>" style="display: none;">
                                    <td colspan="10" style="background: #f8f9fa; padding: 15px 25px;">
                                        <table class="trial-details-table">
                                            <?php foreach ($form_data as $question => $answer): ?>
                                                <?php if ($answer !== '' && $answer !== null): ?>
                                                    <tr>
                                                        <th style="text-align: left; padding: 3px 25px 3px 0; white-space: nowrap; vertical-align: top; color: #555;"><?php echo esc_html($question); ?></th>
                                                        <td style="padding: 3px 0;"><?php echo nl2br(esc_html(is_array($answer) ? implode(', ', $answer) : $answer)); ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </table>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No trial applications found for this season.</p>
            <?php endif; ?>
            
            <style>
            .bulk-actions {
                background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%);
                border-left: 4px solid #007cba;
            }
            .bulk-actions button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .trial-checkbox {
                transform: scale(1.2);
                margin: 0;
            }
            #select-all-trials {
                transform: scale(1.2);
                margin: 0;
            }
            .status-pending {
                color: #856404;
                background-color: #fff3cd;
                border: 1px solid #ffeaa7;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .status-accepted {
                color: #155724;
                background-color: #d4edda;
                border: 1px solid #c3e6cb;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .status-awaiting_payment {
                color: #1864ab;
                background-color: #e7f5ff;
                border: 1px solid #74c0fc;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .status-expired {
                color: #666;
                background-color: #eee;
                border: 1px solid #ccc;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            .status-rejected {
                color: #721c24;
                background-color: #f8d7da;
                border: 1px solid #f5c6cb;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
            }
            #selected-count {
                font-weight: bold;
                padding: 5px 10px;
                background: rgba(0, 124, 186, 0.1);
                border-radius: 3px;
            }
            </style>
            
            <script>
            // Trial applications filtering
            function toggleTrialDetails(trialId) {
                const row = document.getElementById('trial-details-' + trialId);
                if (row) {
                    row.style.display = row.style.display === 'none' ? '' : 'none';
                }
            }

            function filterTrials() {
                const search = document.getElementById('search-trials').value.toLowerCase();
                const statusFilter = document.getElementById('filter-status').value;
                const transferFilter = document.getElementById('filter-transfer').value;
                
                const rows = document.querySelectorAll('#trials-table tbody tr');
                let visiblePendingCount = 0;
                
                rows.forEach(row => {
                    // Detail rows collapse whenever filters change.
                    if (row.classList.contains('trial-details-row')) {
                        row.style.display = 'none';
                        return;
                    }

                    const text = row.textContent.toLowerCase();
                    const status = row.dataset.status;
                    const transfer = row.dataset.transfer;

                    const matchesSearch = search === '' || text.includes(search);
                    const matchesStatus = statusFilter === '' || status === statusFilter;
                    const matchesTransfer = transferFilter === '' || transfer === transferFilter;

                    const visible = matchesSearch && matchesStatus && matchesTransfer;
                    row.style.display = visible ? '' : 'none';
                    
                    if (visible && status === 'pending') {
                        visiblePendingCount++;
                    }
                });
                
                // Update select all checkbox and bulk actions based on filtered results
                updateSelectAllState();
                updateBulkActionButtons();
            }
            
            // Bulk operations functionality
            function updateSelectedCount() {
                const selectedCheckboxes = document.querySelectorAll('.trial-checkbox:checked');
                const count = selectedCheckboxes.length;
                document.getElementById('selected-count').textContent = count + ' selected';
                updateBulkActionButtons();
            }
            
            function updateBulkActionButtons() {
                const selectedCheckboxes = document.querySelectorAll('.trial-checkbox:checked');
                const hasSelection = selectedCheckboxes.length > 0;
                
                document.getElementById('bulk-accept-btn').disabled = !hasSelection;
                document.getElementById('bulk-reject-btn').disabled = !hasSelection;
            }
            
            function updateSelectAllState() {
                const visibleCheckboxes = Array.from(document.querySelectorAll('.trial-checkbox')).filter(cb => {
                    return cb.closest('tr').style.display !== 'none';
                });
                const checkedCheckboxes = visibleCheckboxes.filter(cb => cb.checked);
                const selectAllCheckbox = document.getElementById('select-all-trials');
                
                if (visibleCheckboxes.length === 0) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = false;
                } else if (checkedCheckboxes.length === visibleCheckboxes.length) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = true;
                } else if (checkedCheckboxes.length > 0) {
                    selectAllCheckbox.indeterminate = true;
                    selectAllCheckbox.checked = false;
                } else {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = false;
                }
            }
            
            // Select all functionality
            document.getElementById('select-all-trials').addEventListener('change', function() {
                const visibleCheckboxes = Array.from(document.querySelectorAll('.trial-checkbox')).filter(cb => {
                    return cb.closest('tr').style.display !== 'none';
                });
                
                visibleCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                
                updateSelectedCount();
            });
            
            // Individual checkbox change
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('trial-checkbox')) {
                    updateSelectedCount();
                    updateSelectAllState();
                }
            });
            
            // Bulk accept functionality
            document.getElementById('bulk-accept-btn').addEventListener('click', function() {
                const selectedCheckboxes = document.querySelectorAll('.trial-checkbox:checked');
                const teamSelect = document.getElementById('bulk-team-select');
                
                if (selectedCheckboxes.length === 0) {
                    alert('Please select at least one trial application.');
                    return;
                }
                
                if (!teamSelect.value) {
                    alert('Please select a team for the assignments.');
                    teamSelect.focus();
                    return;
                }
                
                if (!confirm(`Accept ${selectedCheckboxes.length} trial application(s) and assign to ${teamSelect.options[teamSelect.selectedIndex].text}?`)) {
                    return;
                }
                
                performBulkAction('bulk_accept', selectedCheckboxes, teamSelect.value);
            });
            
            // Bulk reject functionality
            document.getElementById('bulk-reject-btn').addEventListener('click', function() {
                const selectedCheckboxes = document.querySelectorAll('.trial-checkbox:checked');
                
                if (selectedCheckboxes.length === 0) {
                    alert('Please select at least one trial application.');
                    return;
                }
                
                if (!confirm(`Reject ${selectedCheckboxes.length} trial application(s)?`)) {
                    return;
                }
                
                performBulkAction('bulk_reject', selectedCheckboxes);
            });
            
            // Perform bulk action via AJAX
            function performBulkAction(action, checkboxes, team = null) {
                const trialIds = Array.from(checkboxes).map(cb => cb.value);
                const button = action === 'bulk_accept' ? document.getElementById('bulk-accept-btn') : document.getElementById('bulk-reject-btn');
                
                // Disable buttons and show loading
                button.disabled = true;
                button.textContent = button.textContent.replace('Selected', 'Processing...');
                
                const formData = new FormData();
                formData.append('action', action + '_trials');
                formData.append('trial_ids', JSON.stringify(trialIds));
                if (team) {
                    formData.append('assigned_team', team);
                }
                formData.append('bulk_trial_nonce', '<?php echo wp_create_nonce('bulk_trial_action'); ?>');
                
                fetch(ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.data.message);
                        location.reload(); // Refresh the page to show updated status
                    } else {
                        alert('Error: ' + (data.data || 'Unknown error occurred'));
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                })
                .finally(() => {
                    // Reset button states
                    button.disabled = false;
                    button.textContent = button.textContent.replace('Processing...', 'Selected');
                });
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'a' && e.target.closest('#trials-table')) {
                    e.preventDefault();
                    document.getElementById('select-all-trials').click();
                }
            });
            
            // Event listeners for filtering
            document.getElementById('search-trials').addEventListener('input', filterTrials);
            document.getElementById('filter-status').addEventListener('change', filterTrials);
            document.getElementById('filter-transfer').addEventListener('change', filterTrials);
            
            // Initialize bulk action buttons state
            updateBulkActionButtons();
            </script>
        </div>
        <?php
    }
    
    private function render_invoices_table() {
        global $wpdb;
        
        $season = $this->get_current_season();
        
        // One row per invoice: assignments are aggregated into a team list
        // (multi-team players and coach+player dual roles would otherwise
        // duplicate the row per assignment).
        $invoices = $wpdb->get_results($wpdb->prepare("
            SELECT
                inv.*,
                MAX(u.display_name) AS name,
                GROUP_CONCAT(DISTINCT ta.team ORDER BY ta.team SEPARATOR '|') AS team_codes
            FROM {$wpdb->prefix}team_invoices inv
            LEFT JOIN {$wpdb->users} u ON inv.email = u.user_email
            LEFT JOIN {$wpdb->prefix}team_assignments ta ON inv.email = ta.email AND inv.season = ta.season AND ta.is_active = 1
            WHERE inv.season = %s
            GROUP BY inv.id
            ORDER BY inv.created_date DESC
        ", $season));
        
        $database = new TeamOversight_Database();
        $teams = $database->get_teams();
        
        // Payments ledger for this season's invoices, keyed by invoice.
        $payments_by_invoice = array();
        $payment_rows = $wpdb->get_results($wpdb->prepare("
            SELECT p.invoice_id, p.amount, p.source, DATE(p.created_date) AS paid_date
            FROM {$wpdb->prefix}team_invoice_payments p
            JOIN {$wpdb->prefix}team_invoices i ON i.id = p.invoice_id
            WHERE i.season = %s
            ORDER BY p.created_date
        ", $season));
        foreach ($payment_rows as $payment) {
            if (!isset($payments_by_invoice[$payment->invoice_id])) {
                $payments_by_invoice[$payment->invoice_id] = array('count' => 0, 'lines' => array());
            }
            $payments_by_invoice[$payment->invoice_id]['count']++;
            $payments_by_invoice[$payment->invoice_id]['lines'][] = $payment->paid_date . ': $' . number_format($payment->amount, 2) . ' (' . $payment->source . ')';
        }

        $season_dates = TeamOversight_Fees::get_season_dates($season);

        ?>
        <div class="wrap">
            <h1>Payment Management</h1>

            <div class="season-filter">
                <label for="season-select">Season:</label>
                <select id="season-select" onchange="location.href=this.value;">
                    <?php
                    $available_seasons = $this->get_available_seasons('main');
                    foreach ($available_seasons as $available_season): ?>
                        <option value="<?php echo admin_url('admin.php?page=team-oversight-invoices&season=' . $available_season); ?>" <?php selected($season, $available_season); ?>><?php echo esc_html($available_season); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($season_dates): ?>
                    <span class="description" style="margin-left: 15px;">Season runs <?php echo esc_html($season_dates['start']); ?> &ndash; <?php echo esc_html($season_dates['end']); ?>; fees fall due linearly between these dates.</span>
                <?php else: ?>
                    <span class="description" style="margin-left: 15px; color: #996800;">No season dates set — nothing shows as overdue and pro-rata is off. Set them in <a href="<?php echo admin_url('admin.php?page=team-oversight-fees&season=' . $season); ?>">Configuration</a>.</span>
                <?php endif; ?>
            </div>

            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">
                    Payment settings — member payment product
                    <?php $payment_product_id = intval(get_option(TeamOversight_Payments::PAYMENT_PRODUCT_OPTION)); ?>
                    <?php if ($payment_product_id): ?>
                        <span style="color: #1a7a2e;">(active: #<?php echo $payment_product_id; ?> <?php echo esc_html(get_the_title($payment_product_id)); ?>)</span>
                    <?php else: ?>
                        <span style="color: #996800;">(not set — members cannot pay online)</span>
                    <?php endif; ?>
                </summary>
                <p class="description">The product used when members pay fees via the [member_fees] page. Its price is overridden with whatever amount the member chooses, so create a dedicated product (e.g. "Membership Fee Payment") with price 0 and select it here. Paid orders reduce the member's outstanding balance automatically.</p>
                <form method="post">
                    <select name="payment_product" style="max-width: 400px;">
                        <option value="0">Disabled — no online fee payment</option>
                        <?php
                        $products = get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC'));
                        foreach ($products as $product_post): ?>
                            <option value="<?php echo $product_post->ID; ?>" <?php selected($payment_product_id, $product_post->ID); ?>><?php echo esc_html($product_post->post_title); ?> (#<?php echo $product_post->ID; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <input type="submit" class="button button-primary" value="Save">
                    <input type="hidden" name="action" value="save_payment_settings">
                    <?php wp_nonce_field('save_payment_settings', 'payment_settings_nonce'); ?>
                </form>
            </details>

            <?php if (!empty($invoices)): ?>
                <div class="invoices-filters" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <label for="search-invoices">Search:</label>
                            <input type="text" id="search-invoices" placeholder="Search name, email, invoice number..." style="width: 200px;">
                        </div>
                        <div>
                            <label for="filter-team">Team:</label>
                            <select id="filter-team">
                                <option value="">All Teams</option>
                                <?php foreach ($teams as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter-status">Payment Status:</label>
                            <select id="filter-status">
                                <option value="">All Statuses</option>
                                <option value="paid">Paid</option>
                                <option value="partial">Partial</option>
                                <option value="outstanding">Outstanding</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped" id="invoices-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Team</th>
                            <th>Fee Info</th>
                            <th>Season Fee</th>
                            <th>Paid</th>
                            <th>Outstanding</th>
                            <th>Overdue</th>
                            <th>Payments</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): 
                            $payment_status = 'outstanding';
                            if ($invoice->outstanding_amount <= 0) {
                                $payment_status = 'paid';
                            } elseif ($invoice->outstanding_amount < $invoice->invoice_amount) {
                                $payment_status = 'partial';
                            }
                            
                            // Get user meta for fee calculation transparency
                            $user = get_user_by('email', $invoice->email);
                            $fee_info = 'N/A';
                            if ($user) {
                                $birth_date = get_user_meta($user->ID, 'birth_date', true);
                                $institution1 = get_user_meta($user->ID, 'institution1', true);
                                $degree1_type = get_user_meta($user->ID, 'degree1type', true);
                                $degree1_enddate = get_user_meta($user->ID, 'degree1enddate', true);
                                
                                $age = 'N/A';
                                if ($birth_date) {
                                    $today = new DateTime();
                                    $birthdate_obj = new DateTime($birth_date);
                                    $age = $today->diff($birthdate_obj)->y;
                                }
                                
                                $institution_short = '';
                                if ($institution1 === 'Melbourne University') {
                                    $institution_short = 'Melb Uni';
                                } elseif ($institution1 === 'Other University') {
                                    $institution_short = 'Other Uni';
                                } elseif ($institution1 === 'Other') {
                                    $institution_short = 'Other';
                                }
                                
                                $degree_short = '';
                                if ($degree1_type === 'Undergraduate') {
                                    $degree_short = 'UG';
                                } elseif ($degree1_type === 'Postgraduate') {
                                    $degree_short = 'PG';
                                } elseif ($degree1_type === 'Staff') {
                                    $degree_short = 'Staff';
                                }
                                
                                $status = 'Current';
                                if ($degree1_enddate && strtotime($degree1_enddate) < time()) {
                                    $status = 'Alumni';
                                }
                                
                                // Calculate fee class using the updated logic
                                $fees = new TeamOversight_Fees();
                                $calculated_fee_class = $fees->determine_fee_class($invoice->email, $invoice->season);
                                
                                $fee_info = "Age: {$age} | {$institution_short} | {$degree_short} | {$status} → {$calculated_fee_class}";
                            }
                        ?>
                            <tr data-invoice-id="<?php echo $invoice->id; ?>" data-team="<?php echo esc_attr($invoice->team_codes ?: ''); ?>" data-status="<?php echo $payment_status; ?>">
                                <td><?php echo $invoice->id; ?></td>
                                <td><?php echo esc_html($invoice->name); ?></td>
                                <td><?php echo esc_html($invoice->email); ?></td>
                                <td><?php echo esc_html($invoice->team_codes ? str_replace('|', ', ', $invoice->team_codes) : 'N/A'); ?></td>
                                <td style="font-size: 11px; max-width: 200px;" title="<?php echo esc_attr($fee_info); ?>"><?php echo esc_html($fee_info); ?></td>
                                <td class="editable-invoice-amount" data-field="invoice_amount" data-value="<?php echo esc_attr($invoice->invoice_amount); ?>">
                                    <span class="display-value">$<?php echo number_format($invoice->invoice_amount, 2); ?></span>
                                    <input type="number" class="edit-value" value="<?php echo $invoice->invoice_amount; ?>" step="0.01" min="0" style="display: none; width: 80px;">
                                </td>
                                <td>$<?php echo number_format(max(0, $invoice->invoice_amount - $invoice->outstanding_amount), 2); ?></td>
                                <td class="editable-outstanding-amount" data-field="outstanding_amount" data-value="<?php echo esc_attr($invoice->outstanding_amount); ?>">
                                    <span class="display-value">$<?php echo number_format($invoice->outstanding_amount, 2); ?></span>
                                    <input type="number" class="edit-value" value="<?php echo $invoice->outstanding_amount; ?>" step="0.01" min="0" style="display: none; width: 80px;">
                                </td>
                                <td>
                                    <?php $overdue = TeamOversight_Payments::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season); ?>
                                    <?php if ($overdue > 0): ?>
                                        <span style="color: #a00; font-weight: 600;">$<?php echo number_format($overdue, 2); ?></span>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($payments_by_invoice[$invoice->id])): ?>
                                        <span title="<?php echo esc_attr(implode("\n", $payments_by_invoice[$invoice->id]['lines'])); ?>" style="cursor: help; text-decoration: underline dotted;"><?php echo intval($payments_by_invoice[$invoice->id]['count']); ?></span>
                                    <?php else: ?>
                                        0
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button edit-invoice" onclick="editInvoice(this)">Edit</button>
                                    <button type="button" class="button button-primary save-invoice" onclick="saveInvoice(this)" style="display: none;">Save</button>
                                    <button type="button" class="button cancel-edit-invoice" onclick="cancelEditInvoice(this)" style="display: none;">Cancel</button>
                                    <button type="button" class="button button-link-delete delete-invoice" onclick="deleteInvoice(this)" style="display: none; color: #a00;">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No invoices found for this season.</p>
            <?php endif; ?>
            
            <script>
            // Invoice filtering
            function filterInvoices() {
                const search = document.getElementById('search-invoices').value.toLowerCase();
                const teamFilter = document.getElementById('filter-team').value;
                const statusFilter = document.getElementById('filter-status').value;
                
                const rows = document.querySelectorAll('#invoices-table tbody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const teams = (row.dataset.team || '').split('|');
                    const status = row.dataset.status;

                    const matchesSearch = search === '' || text.includes(search);
                    const matchesTeam = teamFilter === '' || teams.includes(teamFilter);
                    const matchesStatus = statusFilter === '' || status === statusFilter;

                    row.style.display = matchesSearch && matchesTeam && matchesStatus ? '' : 'none';
                });
            }

            document.getElementById('search-invoices').addEventListener('input', filterInvoices);
            document.getElementById('filter-team').addEventListener('change', filterInvoices);
            document.getElementById('filter-status').addEventListener('change', filterInvoices);
            
            // Invoice inline editing
            function editInvoice(button) {
                const row = button.closest('tr');
                row.querySelectorAll('.display-value').forEach(span => span.style.display = 'none');
                row.querySelectorAll('.edit-value').forEach(input => input.style.display = 'inline-block');
                row.querySelector('.edit-invoice').style.display = 'none';
                row.querySelector('.save-invoice').style.display = 'inline-block';
                row.querySelector('.cancel-edit-invoice').style.display = 'inline-block';
                row.querySelector('.delete-invoice').style.display = 'inline-block';
            }
            
            function cancelEditInvoice(button) {
                const row = button.closest('tr');
                row.querySelectorAll('.display-value').forEach(span => span.style.display = 'inline-block');
                row.querySelectorAll('.edit-value').forEach(input => input.style.display = 'none');
                row.querySelector('.edit-invoice').style.display = 'inline-block';
                row.querySelector('.save-invoice').style.display = 'none';
                row.querySelector('.cancel-edit-invoice').style.display = 'none';
                row.querySelector('.delete-invoice').style.display = 'none';
            }
            
            function saveInvoice(button) {
                const row = button.closest('tr');
                const invoiceId = row.dataset.invoiceId;
                const data = {
                    action: 'update_invoice',
                    invoice_id: invoiceId,
                    invoice_amount: row.querySelector('.editable-invoice-amount .edit-value').value,
                    outstanding_amount: row.querySelector('.editable-outstanding-amount .edit-value').value,
                    nonce: '<?php echo wp_create_nonce('update_invoice'); ?>'
                };
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: Object.keys(data).map(key => key + '=' + encodeURIComponent(data[key])).join('&')
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        // Update display values
                        row.querySelector('.editable-invoice-amount .display-value').textContent = '$' + parseFloat(data.invoice_amount).toFixed(2);
                        row.querySelector('.editable-outstanding-amount .display-value').textContent = '$' + parseFloat(data.outstanding_amount).toFixed(2);
                        
                        // Update payment status
                        const outstanding = parseFloat(data.outstanding_amount);
                        const invoice = parseFloat(data.invoice_amount);
                        let status = 'outstanding';
                        if (outstanding <= 0) {
                            status = 'paid';
                        } else if (outstanding < invoice) {
                            status = 'partial';
                        }
                        row.dataset.status = status;
                        
                        cancelEditInvoice(button);
                        alert('Invoice updated successfully');
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
            
            function deleteInvoice(button) {
                if (!confirm('Are you sure you want to delete this invoice? This action cannot be undone.')) {
                    return;
                }
                
                const row = button.closest('tr');
                const invoiceId = row.dataset.invoiceId;
                
                const data = {
                    action: 'delete_invoice',
                    invoice_id: invoiceId,
                    nonce: '<?php echo wp_create_nonce('delete_invoice'); ?>'
                };
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: Object.keys(data).map(key => key + '=' + encodeURIComponent(data[key])).join('&')
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        row.remove();
                        alert('Invoice deleted successfully');
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
            </script>
        </div>
        <?php
    }
    
    private function render_assignments_table() {
        global $wpdb;
        
        $season = $this->get_current_season();
        
        $assignments = $wpdb->get_results($wpdb->prepare("
            SELECT 
                ta.*,
                u.display_name as name
            FROM {$wpdb->prefix}team_assignments ta
            JOIN {$wpdb->users} u ON ta.email = u.user_email
            WHERE ta.season = %s
            ORDER BY ta.is_active DESC, ta.team, ta.role, u.display_name
        ", $season));
        
        ?>
        <div class="wrap">
            <h1>Team Assignments</h1>
            
            <div class="season-filter">
                <label for="season-select">Season:</label>
                <select id="season-select" onchange="location.href=this.value;">
                    <?php 
                    $available_seasons = $this->get_available_seasons('main');
                    foreach ($available_seasons as $available_season): ?>
                        <option value="<?php echo admin_url('admin.php?page=team-oversight-assignments&season=' . $available_season); ?>" <?php selected($season, $available_season); ?>><?php echo esc_html($available_season); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="add-assignment-form">
                <h3>Add New Assignment</h3>
                <form method="post">
                    <table class="form-table-compact">
                        <tr>
                            <th><label for="user_name">User Name</label></th>
                            <td>
                                <input type="text" name="user_name" id="user_name" placeholder="Search by name..." autocomplete="off">
                                <div id="name-suggestions" style="display: none; position: absolute; background: white; border: 1px solid #ccc; max-height: 200px; overflow-y: auto; z-index: 1000; width: 300px;"></div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="user_email">User Email</label></th>
                            <td>
                                <input type="email" name="user_email" id="user_email" required autocomplete="off" placeholder="Search by email...">
                                <div id="email-suggestions" style="display: none; position: absolute; background: white; border: 1px solid #ccc; max-height: 200px; overflow-y: auto; z-index: 1000; width: 300px;"></div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="team">Team</label></th>
                            <td>
                                <select name="team" required>
                                    <option value="">Select Team</option>
                                    <?php 
                                    $database = new TeamOversight_Database();
                                    $teams = $database->get_teams();
                                    foreach ($teams as $code => $name): 
                                    ?>
                                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="role">Role</label></th>
                            <td>
                                <select name="role" required>
                                    <option value="">Select Role</option>
                                    <?php 
                                    $roles = $database->get_roles();
                                    foreach ($roles as $key => $role_name): 
                                    ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($role_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="start_date">Start Date</label></th>
                            <td><input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required></td>
                        </tr>
                    </table>
                    
                    <p>
                        <input type="submit" class="button button-primary" value="Add Assignment">
                        <input type="hidden" name="action" value="add_assignment">
                        <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
                        <?php wp_nonce_field('add_assignment', 'assignment_nonce'); ?>
                    </p>
                </form>
            </div>
            
            <?php if (!empty($assignments)): ?>
                <div class="assignments-filters" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div>
                            <label for="search-assignments">Search:</label>
                            <input type="text" id="search-assignments" placeholder="Search name, email, team..." style="width: 200px;">
                        </div>
                        <div>
                            <label for="filter-team">Team:</label>
                            <select id="filter-team">
                                <option value="">All Teams</option>
                                <?php foreach ($teams as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter-role">Role:</label>
                            <select id="filter-role">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $key => $role_name): ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($role_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter-status">Status:</label>
                            <select id="filter-status">
                                <option value="">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped" id="assignments-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Team</th>
                            <th>Role</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr data-assignment-id="<?php echo $assignment->id; ?>" data-team="<?php echo esc_attr($assignment->team); ?>" data-role="<?php echo esc_attr($assignment->role); ?>" data-status="<?php echo esc_attr($assignment->registration_status); ?>" <?php echo !$assignment->is_active ? 'style="opacity: 0.6; background-color: #f9f9f9;"' : ''; ?>>
                                <td><?php echo esc_html($assignment->name); ?></td>
                                <td><?php echo esc_html($assignment->email); ?></td>
                                <td class="editable-team" data-field="team" data-value="<?php echo esc_attr($assignment->team); ?>">
                                    <span class="display-value"><?php echo esc_html($assignment->team); ?></span>
                                    <select class="edit-value" style="display: none;">
                                        <?php foreach ($teams as $code => $name): ?>
                                            <option value="<?php echo esc_attr($code); ?>" <?php selected($assignment->team, $code); ?>><?php echo esc_html($code); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="editable-role" data-field="role" data-value="<?php echo esc_attr($assignment->role); ?>">
                                    <span class="display-value"><?php echo esc_html(str_replace('_', ' ', ucwords($assignment->role, '_'))); ?></span>
                                    <select class="edit-value" style="display: none;">
                                        <?php foreach ($roles as $key => $role_name): ?>
                                            <option value="<?php echo esc_attr($key); ?>" <?php selected($assignment->role, $key); ?>><?php echo esc_html($role_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="editable-date" data-field="start_date" data-value="<?php echo esc_attr($assignment->start_date); ?>">
                                    <span class="display-value"><?php echo esc_html($assignment->start_date); ?></span>
                                    <input type="date" class="edit-value" value="<?php echo esc_attr($assignment->start_date); ?>" style="display: none;">
                                </td>
                                <?php if (current_user_can('manage_options')): ?>
                                    <td class="editable-end-date" data-field="end_date" data-value="<?php echo esc_attr($assignment->end_date); ?>">
                                        <span class="display-value"><?php echo esc_html($assignment->end_date ?: ''); ?></span>
                                        <input type="date" class="edit-value" value="<?php echo esc_attr($assignment->end_date); ?>" style="display: none;">
                                    </td>
                                <?php else: ?>
                                    <td><?php echo esc_html($assignment->end_date ?: ''); ?></td>
                                <?php endif; ?>
                                <td class="editable-status" data-field="registration_status" data-value="<?php echo esc_attr($assignment->registration_status); ?>">
                                    <span class="display-value">
                                        <?php 
                                        $status_display = ucfirst($assignment->registration_status);
                                        $status_icon = $assignment->registration_status === 'active' ? '🟢' : '🔴';
                                        $end_date_indicator = '';
                                        if ($assignment->end_date && $assignment->end_date < date('Y-m-d')) {
                                            $end_date_indicator = ' (End: ' . $assignment->end_date . ')';
                                        }
                                        echo $status_icon . ' ' . $status_display . $end_date_indicator;
                                        ?>
                                    </span>
                                    <select class="edit-value" style="display: none;">
                                        <option value="active" <?php selected($assignment->registration_status, 'active'); ?>>Active</option>
                                        <option value="inactive" <?php selected($assignment->registration_status, 'inactive'); ?>>Inactive</option>
                                    </select>
                                </td>
                                <td>
                                    <button type="button" class="button edit-assignment" onclick="editAssignment(this)">Edit</button>
                                    <button type="button" class="button button-primary save-assignment" onclick="saveAssignment(this)" style="display: none;">Save</button>
                                    <button type="button" class="button cancel-edit" onclick="cancelEdit(this)" style="display: none;">Cancel</button>
                                    <button type="button" class="button button-link-delete delete-assignment" onclick="deleteAssignment(this)" style="display: none; color: #a00;">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No team assignments found for this season.</p>
            <?php endif; ?>
            
            <script>
            // Name search functionality
            document.getElementById('user_name').addEventListener('input', function() {
                const query = this.value;
                if (query.length < 2) {
                    document.getElementById('name-suggestions').style.display = 'none';
                    return;
                }
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=search_users&query=' + encodeURIComponent(query) + '&search_type=name&nonce=' + '<?php echo wp_create_nonce('search_users'); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    const suggestions = document.getElementById('name-suggestions');
                    if (data.success && data.data.length > 0) {
                        suggestions.innerHTML = data.data.map(user => 
                            `<div class="user-suggestion" onclick="selectUser('${user.email}', '${user.name}', 'name')" style="padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;">
                                <strong>${user.name}</strong><br><small>${user.email}</small>
                            </div>`
                        ).join('');
                        suggestions.style.display = 'block';
                    } else {
                        suggestions.style.display = 'none';
                    }
                });
            });

            // Email search functionality
            document.getElementById('user_email').addEventListener('input', function() {
                const query = this.value;
                if (query.length < 2) {
                    document.getElementById('email-suggestions').style.display = 'none';
                    return;
                }
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=search_users&query=' + encodeURIComponent(query) + '&search_type=email&nonce=' + '<?php echo wp_create_nonce('search_users'); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    const suggestions = document.getElementById('email-suggestions');
                    if (data.success && data.data.length > 0) {
                        suggestions.innerHTML = data.data.map(user => 
                            `<div class="user-suggestion" onclick="selectUser('${user.email}', '${user.name}', 'email')" style="padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;">
                                <strong>${user.name}</strong><br><small>${user.email}</small>
                            </div>`
                        ).join('');
                        suggestions.style.display = 'block';
                    } else {
                        suggestions.style.display = 'none';
                    }
                });
            });
            
            function selectUser(email, name, source) {
                document.getElementById('user_email').value = email;
                document.getElementById('user_name').value = name;
                document.getElementById('email-suggestions').style.display = 'none';
                document.getElementById('name-suggestions').style.display = 'none';
            }
            
            // Hide suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#user_email') && !e.target.closest('#email-suggestions')) {
                    document.getElementById('email-suggestions').style.display = 'none';
                }
                if (!e.target.closest('#user_name') && !e.target.closest('#name-suggestions')) {
                    document.getElementById('name-suggestions').style.display = 'none';
                }
            });
            
            // Table filtering
            function filterTable() {
                const search = document.getElementById('search-assignments').value.toLowerCase();
                const teamFilter = document.getElementById('filter-team').value;
                const roleFilter = document.getElementById('filter-role').value;
                const statusFilter = document.getElementById('filter-status').value;
                
                const rows = document.querySelectorAll('#assignments-table tbody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const team = row.dataset.team;
                    const role = row.dataset.role;
                    const status = row.dataset.status;
                    
                    const matchesSearch = search === '' || text.includes(search);
                    const matchesTeam = teamFilter === '' || team === teamFilter;
                    const matchesRole = roleFilter === '' || role === roleFilter;
                    const matchesStatus = statusFilter === '' || status === statusFilter;
                    
                    row.style.display = matchesSearch && matchesTeam && matchesRole && matchesStatus ? '' : 'none';
                });
            }
            
            document.getElementById('search-assignments').addEventListener('input', filterTable);
            document.getElementById('filter-team').addEventListener('change', filterTable);
            document.getElementById('filter-role').addEventListener('change', filterTable);
            document.getElementById('filter-status').addEventListener('change', filterTable);
            
            // Inline editing
            function editAssignment(button) {
                const row = button.closest('tr');
                row.querySelectorAll('.display-value').forEach(span => span.style.display = 'none');
                row.querySelectorAll('.edit-value').forEach(input => input.style.display = 'inline-block');
                row.querySelector('.edit-assignment').style.display = 'none';
                row.querySelector('.save-assignment').style.display = 'inline-block';
                row.querySelector('.cancel-edit').style.display = 'inline-block';
                row.querySelector('.delete-assignment').style.display = 'inline-block';
            }
            
            function cancelEdit(button) {
                const row = button.closest('tr');
                row.querySelectorAll('.display-value').forEach(span => span.style.display = 'inline-block');
                row.querySelectorAll('.edit-value').forEach(input => input.style.display = 'none');
                row.querySelector('.edit-assignment').style.display = 'inline-block';
                row.querySelector('.save-assignment').style.display = 'none';
                row.querySelector('.cancel-edit').style.display = 'none';
                row.querySelector('.delete-assignment').style.display = 'none';
            }
            
            function saveAssignment(button) {
                const row = button.closest('tr');
                const assignmentId = row.dataset.assignmentId;
                const data = {
                    action: 'update_assignment',
                    assignment_id: assignmentId,
                    team: row.querySelector('.editable-team .edit-value').value,
                    role: row.querySelector('.editable-role .edit-value').value,
                    start_date: row.querySelector('.editable-date .edit-value').value,
                    registration_status: row.querySelector('.editable-status .edit-value').value,
                    nonce: '<?php echo wp_create_nonce('update_assignment'); ?>'
                };
                
                // Add end_date if the field exists (admin only)
                const endDateField = row.querySelector('.editable-end-date .edit-value');
                if (endDateField) {
                    data.end_date = endDateField.value;
                }
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: Object.keys(data).map(key => key + '=' + encodeURIComponent(data[key])).join('&')
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        // Update display values
                        row.querySelector('.editable-team .display-value').textContent = data.team;
                        row.querySelector('.editable-role .display-value').textContent = row.querySelector('.editable-role .edit-value option:checked').textContent;
                        row.querySelector('.editable-date .display-value').textContent = data.start_date;
                        row.querySelector('.editable-status .display-value').textContent = row.querySelector('.editable-status .edit-value option:checked').textContent;
                        
                        // Update data attributes
                        row.dataset.team = data.team;
                        row.dataset.role = data.role;
                        row.dataset.status = data.registration_status;
                        
                        cancelEdit(button);
                        alert('Assignment updated successfully');
                        
                        // Refresh page to show updated status indicators
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
            
            function deleteAssignment(button) {
                if (!confirm('Are you sure you want to delete this assignment? This action cannot be undone.')) {
                    return;
                }
                
                const row = button.closest('tr');
                const assignmentId = row.dataset.assignmentId;
                
                const data = {
                    action: 'delete_assignment',
                    assignment_id: assignmentId,
                    nonce: '<?php echo wp_create_nonce('delete_assignment'); ?>'
                };
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: Object.keys(data).map(key => key + '=' + encodeURIComponent(data[key])).join('&')
                })
                .then(response => response.json())
                .then(response => {
                    if (response.success) {
                        row.remove();
                        alert('Assignment deleted successfully');
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }
            </script>
        </div>
        <?php
    }
    
    private function process_trial_action() {
        if (!wp_verify_nonce($_POST['trial_nonce'], 'process_trial')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        
        global $wpdb;
        $trial_id = intval($_POST['trial_id']);
        $action = sanitize_text_field($_POST['action']);
        
        if ($action === 'accept_trial') {
            $assigned_team = sanitize_text_field($_POST['assigned_team']);
            
            if (empty($assigned_team)) {
                echo '<div class="notice notice-error"><p>Please select a team.</p></div>';
                return;
            }
            
            $trial = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d
            ", $trial_id));
            
            if ($trial && $trial->application_status === 'pending') {
                $wpdb->update(
                    $wpdb->prefix . 'trial_applications',
                    array(
                        'application_status' => 'accepted',
                        'assigned_team' => $assigned_team
                    ),
                    array('id' => $trial_id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                $wpdb->insert(
                    $wpdb->prefix . 'team_assignments',
                    array(
                        'user_id' => intval($trial->user_id),
                        'email' => $trial->email,
                        'season' => $trial->season,
                        'team' => $assigned_team,
                        'role' => 'playing_member',
                        'registration_status' => 'active',
                        'start_date' => date('Y-m-d'),
                        'is_active' => 1
                    ),
                    array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
                );
                
                $fees = new TeamOversight_Fees();
                $fees->generate_invoice($trial->email, $trial->season);
                
                echo '<div class="notice notice-success"><p>Trial application accepted and player assigned to team.</p></div>';
            }
            
        } elseif ($action === 'reject_trial') {
            $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                array('application_status' => 'rejected'),
                array('id' => $trial_id),
                array('%s'),
                array('%d')
            );
            
            echo '<div class="notice notice-success"><p>Trial application rejected.</p></div>';

        } elseif ($action === 'mark_trial_paid') {
            // Manual override for payments made outside the site (cash, EFT).
            $wpdb->query($wpdb->prepare("
                UPDATE {$wpdb->prefix}trial_applications
                SET application_status = 'pending'
                WHERE id = %d AND application_status IN ('awaiting_payment', 'expired')
            ", $trial_id));

            echo '<div class="notice notice-success"><p>Application marked as paid and moved to the review queue.</p></div>';

        } elseif ($action === 'undo_trial') {
            // Get trial info
            $trial = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d
            ", $trial_id));
            
            if ($trial && $trial->application_status === 'accepted') {
                // Remove only the PLAYING assignments the acceptance created
                // — never staff roles (a playing coach keeps coaching).
                // assigned_team may be a multi-team list like "SL2M, JPLM (T/O)".
                $undo_teams = array_filter(array_map(function ($team_code) {
                    return trim(str_replace('(T/O)', '', $team_code));
                }, explode(',', (string) $trial->assigned_team)));

                foreach ($undo_teams as $undo_team) {
                    $wpdb->query($wpdb->prepare("
                        DELETE FROM {$wpdb->prefix}team_assignments
                        WHERE email = %s AND season = %s AND team = %s
                            AND role IN ('playing_member', 'training_only')
                    ", $trial->email, $trial->season, $undo_team));
                }
                
                // Remove invoice if exists
                $wpdb->delete(
                    $wpdb->prefix . 'team_invoices',
                    array(
                        'email' => $trial->email,
                        'season' => $trial->season
                    ),
                    array('%s', '%s')
                );
                
                // Reset trial to pending
                $wpdb->update(
                    $wpdb->prefix . 'trial_applications',
                    array(
                        'application_status' => 'pending',
                        'assigned_team' => null
                    ),
                    array('id' => $trial_id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                echo '<div class="notice notice-success"><p>Trial assignment undone successfully. Player returned to pending status.</p></div>';
            } elseif ($trial && $trial->application_status === 'rejected') {
                // Reset rejected trial to pending
                $wpdb->update(
                    $wpdb->prefix . 'trial_applications',
                    array('application_status' => 'pending'),
                    array('id' => $trial_id),
                    array('%s'),
                    array('%d')
                );
                
                echo '<div class="notice notice-success"><p>Trial rejection undone successfully. Player returned to pending status.</p></div>';
            }
        }
    }
    
    public function ajax_bulk_accept_trials() {
        // Security check
        if (!wp_verify_nonce($_POST['bulk_trial_nonce'], 'bulk_trial_action')) {
            wp_send_json_error('Security check failed.');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        global $wpdb;
        
        $trial_ids = json_decode(stripslashes($_POST['trial_ids']), true);
        $assigned_team = sanitize_text_field($_POST['assigned_team']);
        
        if (empty($trial_ids) || !is_array($trial_ids)) {
            wp_send_json_error('No trial applications selected.');
            return;
        }
        
        if (empty($assigned_team)) {
            wp_send_json_error('Please select a team.');
            return;
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = array();
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($trial_ids as $trial_id) {
                $trial_id = intval($trial_id);
                
                // Get trial info
                $trial = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d
                ", $trial_id));
                
                if (!$trial) {
                    $errors[] = "Trial ID $trial_id not found.";
                    $error_count++;
                    continue;
                }
                
                if ($trial->application_status !== 'pending') {
                    $errors[] = "Trial for {$trial->name} is not pending.";
                    $error_count++;
                    continue;
                }
                
                // Update trial status
                $update_result = $wpdb->update(
                    $wpdb->prefix . 'trial_applications',
                    array(
                        'application_status' => 'accepted',
                        'assigned_team' => $assigned_team
                    ),
                    array('id' => $trial_id),
                    array('%s', '%s'),
                    array('%d')
                );
                
                if ($update_result === false) {
                    $errors[] = "Failed to update trial for {$trial->name}.";
                    $error_count++;
                    continue;
                }
                
                // Create team assignment
                $assignment_result = $wpdb->insert(
                    $wpdb->prefix . 'team_assignments',
                    array(
                        'user_id' => intval($trial->user_id),
                        'email' => $trial->email,
                        'season' => $trial->season,
                        'team' => $assigned_team,
                        'role' => 'playing_member',
                        'registration_status' => 'active',
                        'start_date' => date('Y-m-d'),
                        'is_active' => 1
                    ),
                    array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
                );
                
                if ($assignment_result === false) {
                    $errors[] = "Failed to create assignment for {$trial->name}.";
                    $error_count++;
                    continue;
                }
                
                // Generate invoice
                $fees = new TeamOversight_Fees();
                $invoice_result = $fees->generate_invoice($trial->email, $trial->season);
                
                if (!$invoice_result) {
                    $errors[] = "Failed to generate invoice for {$trial->name}.";
                    $error_count++;
                    continue;
                }
                
                $success_count++;
            }
            
            if ($error_count === 0) {
                $wpdb->query('COMMIT');
                $message = "Successfully accepted $success_count trial application(s) and assigned to team.";
                wp_send_json_success(array('message' => $message));
            } else {
                $wpdb->query('ROLLBACK');
                $error_summary = "Processed $success_count successfully, $error_count failed. Errors: " . implode(' ', $errors);
                wp_send_json_error($error_summary);
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }
    
    public function ajax_bulk_reject_trials() {
        // Security check
        if (!wp_verify_nonce($_POST['bulk_trial_nonce'], 'bulk_trial_action')) {
            wp_send_json_error('Security check failed.');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.');
            return;
        }
        
        global $wpdb;
        
        $trial_ids = json_decode(stripslashes($_POST['trial_ids']), true);
        
        if (empty($trial_ids) || !is_array($trial_ids)) {
            wp_send_json_error('No trial applications selected.');
            return;
        }
        
        $success_count = 0;
        $error_count = 0;
        $errors = array();
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($trial_ids as $trial_id) {
                $trial_id = intval($trial_id);
                
                // Get trial info
                $trial = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d
                ", $trial_id));
                
                if (!$trial) {
                    $errors[] = "Trial ID $trial_id not found.";
                    $error_count++;
                    continue;
                }
                
                if ($trial->application_status !== 'pending') {
                    $errors[] = "Trial for {$trial->name} is not pending.";
                    $error_count++;
                    continue;
                }
                
                // Update trial status
                $update_result = $wpdb->update(
                    $wpdb->prefix . 'trial_applications',
                    array('application_status' => 'rejected'),
                    array('id' => $trial_id),
                    array('%s'),
                    array('%d')
                );
                
                if ($update_result === false) {
                    $errors[] = "Failed to reject trial for {$trial->name}.";
                    $error_count++;
                    continue;
                }
                
                $success_count++;
            }
            
            if ($error_count === 0) {
                $wpdb->query('COMMIT');
                $message = "Successfully rejected $success_count trial application(s).";
                wp_send_json_success(array('message' => $message));
            } else {
                $wpdb->query('ROLLBACK');
                $error_summary = "Processed $success_count successfully, $error_count failed. Errors: " . implode(' ', $errors);
                wp_send_json_error($error_summary);
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }
    
    private function save_team_assignment() {
        if (!wp_verify_nonce($_POST['assignment_nonce'], $_POST['action'])) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        
        global $wpdb;
        
        if ($_POST['action'] === 'add_assignment') {
            $user_email = sanitize_email($_POST['user_email']);
            $team = sanitize_text_field($_POST['team']);
            $role = sanitize_text_field($_POST['role']);
            $season = sanitize_text_field($_POST['season']);
            $start_date = sanitize_text_field($_POST['start_date']);
            
            if (!is_email($user_email)) {
                echo '<div class="notice notice-error"><p>Invalid email address.</p></div>';
                return;
            }
            
            $user = get_user_by('email', $user_email);
            if (!$user) {
                echo '<div class="notice notice-error"><p>User not found. Please ensure the user has an account first.</p></div>';
                return;
            }
            
            $existing = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_assignments 
                WHERE email = %s AND team = %s AND role = %s AND season = %s AND is_active = 1
            ", $user_email, $team, $role, $season));
            
            if ($existing) {
                echo '<div class="notice notice-error"><p>This assignment already exists.</p></div>';
                return;
            }
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'team_assignments',
                array(
                    'user_id' => $user->ID,
                    'email' => $user_email,
                    'season' => $season,
                    'team' => $team,
                    'role' => $role,
                    'registration_status' => 'active',
                    'start_date' => $start_date,
                    'is_active' => 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );
            
            if ($result) {
                $fees = new TeamOversight_Fees();
                $invoice_result = $fees->generate_invoice($user_email, $season);
                
                if ($invoice_result) {
                    echo '<div class="notice notice-success"><p>Team assignment added successfully and invoice generated.</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>Team assignment added successfully but invoice could not be generated. Please check fee matrix configuration.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Failed to add team assignment.</p></div>';
            }
            
        } elseif ($_POST['action'] === 'deactivate_assignment') {
            $assignment_id = intval($_POST['assignment_id']);
            
            $result = $wpdb->update(
                $wpdb->prefix . 'team_assignments',
                array(
                    'is_active' => 0,
                    'end_date' => date('Y-m-d')
                ),
                array('id' => $assignment_id),
                array('%d', '%s'),
                array('%d')
            );
            
            if ($result) {
                echo '<div class="notice notice-success"><p>Assignment deactivated successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to deactivate assignment.</p></div>';
            }
        }
    }
    
    public function ajax_search_users() {
        if (!wp_verify_nonce($_POST['nonce'], 'search_users')) {
            wp_send_json_error('Security check failed');
        }
        
        $query = sanitize_text_field($_POST['query']);
        $search_type = sanitize_text_field($_POST['search_type'] ?? 'both');
        
        if (strlen($query) < 2) {
            wp_send_json_error('Query too short');
        }
        
        global $wpdb;
        
        // Build search conditions based on search type
        $search_condition = '';
        if ($search_type === 'name') {
            $search_condition = 'u.display_name LIKE %s';
            $params = ['%' . $query . '%'];
        } elseif ($search_type === 'email') {
            $search_condition = 'u.user_email LIKE %s';
            $params = ['%' . $query . '%'];
        } else {
            $search_condition = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params = ['%' . $query . '%', '%' . $query . '%'];
        }
        
        $users = $wpdb->get_results($wpdb->prepare("
            SELECT u.user_email as email, u.display_name as name
            FROM {$wpdb->users} u
            WHERE {$search_condition}
            AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id = u.ID AND um.meta_key = 'account_status' AND um.meta_value = 'approved')
            ORDER BY u.display_name
            LIMIT 6
        ", ...$params));
        
        wp_send_json_success($users);
    }
    
    public function ajax_update_assignment() {
        if (!wp_verify_nonce($_POST['nonce'], 'update_assignment')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $assignment_id = intval($_POST['assignment_id']);
        $team = sanitize_text_field($_POST['team']);
        $role = sanitize_text_field($_POST['role']);
        $start_date = sanitize_text_field($_POST['start_date']);
        $registration_status = sanitize_text_field($_POST['registration_status']);
        $end_date = sanitize_text_field($_POST['end_date'] ?? '');
        
        // Prepare update data
        $update_data = array(
            'team' => $team,
            'role' => $role,
            'start_date' => $start_date,
            'registration_status' => $registration_status
        );
        $update_format = array('%s', '%s', '%s', '%s');
        
        // Handle end_date and status logic
        if (current_user_can('manage_options') && isset($_POST['end_date'])) {
            if (!empty($end_date)) {
                $update_data['end_date'] = $end_date;
                $update_format[] = '%s';
                
                // Auto-set status to inactive if end date is in the past
                if ($end_date < date('Y-m-d')) {
                    $update_data['registration_status'] = 'inactive';
                    $update_data['is_active'] = 0;
                    $update_format[] = '%d';
                } else {
                    $update_data['is_active'] = $registration_status === 'active' ? 1 : 0;
                    $update_format[] = '%d';
                }
            } else {
                // Clear end_date if empty string provided
                $update_data['end_date'] = null;
                $update_format[] = '%s';
                $update_data['is_active'] = $registration_status === 'active' ? 1 : 0;
                $update_format[] = '%d';
            }
        } else {
            // Regular status update - set end_date if changing to inactive
            if ($registration_status === 'inactive') {
                // Get current assignment to check if end_date is already set
                $current_assignment = $wpdb->get_row($wpdb->prepare(
                    "SELECT end_date FROM {$wpdb->prefix}team_assignments WHERE id = %d",
                    $assignment_id
                ));
                
                if (!$current_assignment->end_date) {
                    $update_data['end_date'] = date('Y-m-d');
                    $update_format[] = '%s';
                }
            }
            $update_data['is_active'] = $registration_status === 'active' ? 1 : 0;
            $update_format[] = '%d';
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'team_assignments',
            $update_data,
            array('id' => $assignment_id),
            $update_format,
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Assignment updated successfully');
        } else {
            wp_send_json_error('Failed to update assignment');
        }
    }
    
    public function ajax_delete_assignment() {
        if (!wp_verify_nonce($_POST['nonce'], 'delete_assignment')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;

        $assignment_id = intval($_POST['assignment_id']);

        // Capture the row first so trial applications can be synced after.
        $assignment = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_assignments WHERE id = %d
        ", $assignment_id));

        $result = $wpdb->delete(
            $wpdb->prefix . 'team_assignments',
            array('id' => $assignment_id),
            array('%d')
        );

        if ($result !== false) {
            if ($assignment && in_array($assignment->role, array('playing_member', 'training_only'), true)) {
                $this->sync_trial_after_assignment_removal($assignment->email, $assignment->season, $assignment->team);
            }
            wp_send_json_success('Assignment deleted successfully');
        } else {
            wp_send_json_error('Failed to delete assignment');
        }
    }

    /**
     * Keep trial applications in step when a playing assignment is removed:
     * drop the team from the accepted list (back to pending if none remain)
     * and downgrade the coach verdict for that team to Tentative — the
     * player stays visible on the coach's board as flagged, but the next
     * finalisation run won't silently recreate the assignment (it only
     * processes Selected / Training Only verdicts).
     */
    private function sync_trial_after_assignment_removal($email, $season, $team) {
        global $wpdb;

        $application = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE season = %s AND email = %s AND application_status = 'accepted'
        ", $season, $email));

        if (!$application) {
            return;
        }

        // assigned_team tokens look like "SL2M" or "JPLM (T/O)".
        $tokens = array_filter(array_map('trim', explode(',', (string) $application->assigned_team)));
        $remaining = array();
        $removed = false;
        foreach ($tokens as $token) {
            if (trim(str_replace('(T/O)', '', $token)) === $team) {
                $removed = true;
            } else {
                $remaining[] = $token;
            }
        }

        if (!$removed) {
            return;
        }

        // Downgrade the verdict to Tentative so the coach still sees the
        // player flagged, without finalisation recreating the assignment.
        $wpdb->update(
            $wpdb->prefix . 'team_trial_selections',
            array('status' => 'tentative', 'updated_date' => current_time('mysql')),
            array('application_id' => $application->id, 'team' => $team),
            array('%s', '%s'),
            array('%d', '%s')
        );

        if (empty($remaining)) {
            $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                array('application_status' => 'pending', 'assigned_team' => null),
                array('id' => $application->id),
                array('%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->update(
                $wpdb->prefix . 'trial_applications',
                array('assigned_team' => implode(', ', $remaining)),
                array('id' => $application->id),
                array('%s'),
                array('%d')
            );
        }
    }
    
    public function ajax_update_invoice() {
        if (!wp_verify_nonce($_POST['nonce'], 'update_invoice')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $invoice_id = intval($_POST['invoice_id']);
        $invoice_amount = floatval($_POST['invoice_amount']);
        $outstanding_amount = floatval($_POST['outstanding_amount']);
        
        $result = $wpdb->update(
            $wpdb->prefix . 'team_invoices',
            array(
                'invoice_amount' => $invoice_amount,
                'outstanding_amount' => $outstanding_amount
            ),
            array('id' => $invoice_id),
            array('%f', '%f'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Invoice updated successfully');
        } else {
            wp_send_json_error('Failed to update invoice');
        }
    }
    
    public function ajax_delete_invoice() {
        if (!wp_verify_nonce($_POST['nonce'], 'delete_invoice')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        $invoice_id = intval($_POST['invoice_id']);
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'team_invoices',
            array('id' => $invoice_id),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success('Invoice deleted successfully');
        } else {
            wp_send_json_error('Failed to delete invoice');
        }
    }
    
}