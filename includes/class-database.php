<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Database {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Run database migration to ensure tables are up to date
        $this->migrate_database();
    }
    
    public function migrate_database() {
        global $wpdb;
        
        // Add is_active column to fee_matrix if it doesn't exist
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}fee_matrix LIKE 'is_active'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}fee_matrix ADD COLUMN is_active tinyint(1) DEFAULT 0");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}fee_matrix ADD INDEX is_active (is_active)");
        }
        
        // Add payment_status and payment_date columns to team_accreditations if they don't exist
        $payment_status_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_accreditations LIKE 'payment_status'");
        if (empty($payment_status_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_accreditations ADD COLUMN payment_status varchar(100) DEFAULT NULL AFTER accreditation_list");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_accreditations ADD COLUMN payment_date date DEFAULT NULL AFTER payment_status");
        }
        
        // Expand version column if needed
        $wpdb->query("ALTER TABLE {$wpdb->prefix}fee_matrix MODIFY COLUMN version varchar(50) NOT NULL");
        
        // Create fee_matrix_versions table if it doesn't exist
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fee_matrix_versions'");
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$wpdb->prefix}fee_matrix_versions (
                id int(11) NOT NULL AUTO_INCREMENT,
                version varchar(50) NOT NULL UNIQUE,
                version_name varchar(100) NOT NULL,
                description text DEFAULT NULL,
                is_active tinyint(1) DEFAULT 0,
                created_by varchar(100) DEFAULT NULL,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY is_active (is_active)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        // Add season column to fee_matrix if it doesn't exist
        $season_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}fee_matrix LIKE 'season'");
        if (empty($season_column_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}fee_matrix ADD COLUMN season varchar(10) DEFAULT NULL AFTER version");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}fee_matrix ADD INDEX season (season)");
            
            // Update existing records to have current year as season if they don't have one
            $current_year = date('Y');
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}fee_matrix SET season = %s WHERE season IS NULL", $current_year));
        }
        
        // Create team_memberships table if it doesn't exist (added in 1.1.0)
        $memberships_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_memberships'");
        if (!$memberships_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$wpdb->prefix}team_memberships (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                tier varchar(30) NOT NULL,
                start_date date NOT NULL,
                end_date date NOT NULL,
                source varchar(20) NOT NULL DEFAULT 'manual',
                order_id bigint(20) unsigned DEFAULT NULL,
                order_item_id bigint(20) unsigned DEFAULT NULL,
                product_id bigint(20) unsigned DEFAULT NULL,
                granted_by bigint(20) unsigned DEFAULT NULL,
                note varchar(255) DEFAULT '',
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY tier (tier),
                KEY end_date (end_date),
                KEY order_item_id (order_item_id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }

        // Import price matrix if fee_matrix table is empty
        $existing_fees = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fee_matrix WHERE is_active = 1");
        if ($existing_fees == 0) {
            require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-fees.php';
            $fees = new TeamOversight_Fees();
            $fees->import_price_matrix();
        }
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $tables = array(
            'team_accreditations' => "
                CREATE TABLE {$wpdb->prefix}team_accreditations (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    email varchar(255) NOT NULL,
                    vvid varchar(50) DEFAULT NULL,
                    accreditation_list text DEFAULT NULL,
                    payment_status varchar(100) DEFAULT NULL,
                    payment_date date DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY email (email)
                ) $charset_collate;
            ",
            'team_invoices' => "
                CREATE TABLE {$wpdb->prefix}team_invoices (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    email varchar(255) NOT NULL,
                    name varchar(255) NOT NULL,
                    season varchar(10) NOT NULL,
                    invoice_amount decimal(10,2) NOT NULL,
                    outstanding_amount decimal(10,2) NOT NULL,
                    invoice_reference varchar(100) NOT NULL,
                    created_date datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY email (email),
                    KEY season (season),
                    KEY invoice_reference (invoice_reference)
                ) $charset_collate;
            ",
            'team_assignments' => "
                CREATE TABLE {$wpdb->prefix}team_assignments (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    email varchar(255) NOT NULL,
                    season varchar(10) NOT NULL,
                    team varchar(50) NOT NULL,
                    role varchar(50) NOT NULL,
                    registration_status varchar(20) DEFAULT 'active',
                    start_date date DEFAULT NULL,
                    end_date date DEFAULT NULL,
                    is_active tinyint(1) DEFAULT 1,
                    created_date datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY email (email),
                    KEY season (season),
                    KEY team (team),
                    KEY is_active (is_active)
                ) $charset_collate;
            ",
            'fee_matrix' => "
                CREATE TABLE {$wpdb->prefix}fee_matrix (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    version varchar(50) NOT NULL,
                    season varchar(10) DEFAULT NULL,
                    fee_class varchar(100) NOT NULL,
                    team_role varchar(50) NOT NULL,
                    fee_amount decimal(10,2) NOT NULL,
                    is_active tinyint(1) DEFAULT 0,
                    created_date datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY version (version),
                    KEY season (season),
                    KEY fee_class (fee_class),
                    KEY team_role (team_role),
                    KEY is_active (is_active)
                ) $charset_collate;
            ",
            'fee_matrix_versions' => "
                CREATE TABLE {$wpdb->prefix}fee_matrix_versions (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    version varchar(50) NOT NULL UNIQUE,
                    version_name varchar(100) NOT NULL,
                    description text DEFAULT NULL,
                    is_active tinyint(1) DEFAULT 0,
                    created_by varchar(100) DEFAULT NULL,
                    created_date datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY is_active (is_active)
                ) $charset_collate;
            ",
            'trial_applications' => "
                CREATE TABLE {$wpdb->prefix}trial_applications (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    user_id int(11) NOT NULL,
                    email varchar(255) NOT NULL,
                    name varchar(255) NOT NULL,
                    season varchar(10) NOT NULL,
                    interested_teams text NOT NULL,
                    preferred_positions text NOT NULL,
                    is_transfer_player tinyint(1) DEFAULT 0,
                    application_status varchar(20) DEFAULT 'pending',
                    assigned_team varchar(50) DEFAULT NULL,
                    created_date datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY user_id (user_id),
                    KEY email (email),
                    KEY season (season),
                    KEY application_status (application_status)
                ) $charset_collate;
            "
        );
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        foreach ($tables as $table_name => $sql) {
            dbDelta($sql);
        }
    }
    
    public function get_teams() {
        // Get teams from WordPress options (dynamic teams)
        $custom_teams = get_option('team_oversight_teams', array());
        
        // Default teams as fallback
        $default_teams = array(
            'PLD1-M' => 'Premier League Division 1 Men',
            'PLD1-W' => 'Premier League Division 1 Women',
            'PLD2-M' => 'Premier League Division 2 Men',
            'PLD2-W' => 'Premier League Division 2 Women',
            'SLD1B-M' => 'State League Division 1 Blue Men',
            'SLD1W-M' => 'State League Division 1 White Men',
            'SLD2-M' => 'State League Division 2 Men',
            'SLD3-M' => 'State League Division 3 Men',
            'SLD1B-W' => 'State League Division 1 Blue Women',
            'SLD1W-W' => 'State League Division 1 White Women',
            'SLD2-W' => 'State League Division 2 Women',
            'SLD3-W' => 'State League Division 3 Women',
            'YSL17D1B-B' => 'Youth State League 17 Division 1 Blue Boys',
            'YSL17D1R-B' => 'Youth State League 17 Division 1 Red Boys',
            'YSL17D1W-B' => 'Youth State League 17 Division 1 White Boys',
            'YSL17D1A-G' => 'Youth State League 17 Division 1 Atlas Girls',
            'YSL17D1B-G' => 'Youth State League 17 Division 1 Blue Girls',
            'JPLD1-B' => 'Junior Premier League Division 1 Boys',
            'JPLD1-G' => 'Junior Premier League Division 1 Girls'
        );
        
        // Initialize with default teams if no custom teams exist
        if (empty($custom_teams)) {
            update_option('team_oversight_teams', $default_teams);
            return $default_teams;
        }
        
        return $custom_teams;
    }
    
    public function get_positions() {
        return array(
            'setter' => 'Setter',
            'middle' => 'Middle',
            'outside' => 'Outside',
            'opposite' => 'Opposite',
            'libero' => 'Libero',
            'universal' => 'Universal'
        );
    }
    
    public function get_roles() {
        return array(
            'coach' => 'Coach',
            'assistant_coach' => 'Assistant Coach',
            'team_manager' => 'Team Manager',
            'playing_member' => 'Playing Member',
            'training_only' => 'Training Only Member',
            'supporter' => 'Supporter'
        );
    }
    
    public function get_fee_classes() {
        return array(
            'Junior U/19 (VVL)' => 'Junior U/19 (VVL)',
            'Melb Uni Student' => 'Melb Uni Student',
            'Other Student' => 'Other Student',
            'JPL/YSL' => 'JPL/YSL',
            'Full Adult' => 'Full Adult'
        );
    }
    
    public function get_mus_categories() {
        return array(
            'melb_uni_undergrad' => 'Melbourne University - Undergrad. Student',
            'melb_uni_postgrad' => 'Melbourne University - Postgrad. Student',
            'melb_uni_alumni' => 'Melbourne University - Alumni',
            'melb_uni_staff' => 'Melbourne University - Staff',
            'other_uni_student' => 'Student / Alumni of another university',
            'other' => 'Other'
        );
    }
}