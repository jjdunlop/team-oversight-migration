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
        
        // Add order_id column to trial_applications for trial fee orders (added in 1.2.0)
        $order_id_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}trial_applications LIKE 'order_id'");
        if (empty($order_id_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}trial_applications ADD COLUMN order_id bigint(20) unsigned DEFAULT NULL AFTER assigned_team");
        }

        // Add form_data column to trial_applications for the full questionnaire (added in 1.3.0)
        $form_data_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}trial_applications LIKE 'form_data'");
        if (empty($form_data_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}trial_applications ADD COLUMN form_data longtext DEFAULT NULL AFTER is_transfer_player");
        }

        // Key assignments and invoices by user ID so email changes don't break
        // linkage (added in 1.3.0). Email is kept as a display/import snapshot.
        foreach (array('team_assignments', 'team_invoices') as $table) {
            $user_id_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}{$table} LIKE 'user_id'");
            if (empty($user_id_exists)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}{$table} ADD COLUMN user_id bigint(20) unsigned DEFAULT NULL AFTER id");
                $wpdb->query("ALTER TABLE {$wpdb->prefix}{$table} ADD INDEX user_id (user_id)");
                // Backfill from current accounts by email match.
                $wpdb->query("
                    UPDATE {$wpdb->prefix}{$table} t
                    JOIN {$wpdb->users} u ON u.user_email = t.email
                    SET t.user_id = u.ID
                    WHERE t.user_id IS NULL
                ");
            }
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
                user_id bigint(20) unsigned DEFAULT NULL,
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
                user_id bigint(20) unsigned DEFAULT NULL,
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
                form_data longtext DEFAULT NULL,
                    application_status varchar(20) DEFAULT 'pending',
                    assigned_team varchar(50) DEFAULT NULL,
                    order_id bigint(20) unsigned DEFAULT NULL,
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
    
    /**
     * The current club team list, seeded when no teams are configured.
     * gender: mens|womens|mixed. max_age: player must be UNDER this age on
     * 31 Dec of the season year (0 = open age).
     */
    public static function get_default_teams() {
        return array(
            'PL1M' => array('name' => 'Melbourne University Renegades', 'gender' => 'mens', 'max_age' => 0),
            'PL1W' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 0),
            'PL2M' => array('name' => 'Melbourne University Renegades', 'gender' => 'mens', 'max_age' => 0),
            'PL2W' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 0),
            'SL1M-B' => array('name' => 'Melbourne University Renegades Blue', 'gender' => 'mens', 'max_age' => 0),
            'SL1M-W' => array('name' => 'Melbourne University Renegades White', 'gender' => 'mens', 'max_age' => 0),
            'SL1W-B' => array('name' => 'Melbourne University Renegades Blue', 'gender' => 'womens', 'max_age' => 0),
            'SL1W-W' => array('name' => 'Melbourne University Renegades White', 'gender' => 'womens', 'max_age' => 0),
            'SL2M' => array('name' => 'Melbourne University Renegades', 'gender' => 'mens', 'max_age' => 0),
            'SL2W' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 0),
            'SL3M-R' => array('name' => 'Melbourne University Renegades Red', 'gender' => 'mens', 'max_age' => 0),
            'SL3M-B' => array('name' => 'Melbourne University Renegades Blue', 'gender' => 'mens', 'max_age' => 0),
            'SL3W' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 0),
            'JPLM' => array('name' => 'Melbourne University Renegades', 'gender' => 'mens', 'max_age' => 19),
            'JPLW' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 19),
            'YSL17B1-B' => array('name' => 'Melbourne University Renegades Blue', 'gender' => 'mens', 'max_age' => 17),
            'YSL17B1-R' => array('name' => 'Melbourne University Renegades Red', 'gender' => 'mens', 'max_age' => 17),
            'YSL17G1' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 17),
            'YSL15B' => array('name' => 'Melbourne University Renegades', 'gender' => 'mens', 'max_age' => 15),
            'YSL15G' => array('name' => 'Melbourne University Renegades', 'gender' => 'womens', 'max_age' => 15),
        );
    }

    public function get_teams() {
        // Get teams from WordPress options (dynamic teams)
        $custom_teams = get_option('team_oversight_teams', array());

        // Seed defaults (names + meta) if no teams are configured yet.
        if (empty($custom_teams)) {
            $defaults = self::get_default_teams();
            $names = array();
            $meta = array();
            foreach ($defaults as $code => $team) {
                $names[$code] = $team['name'];
                $meta[$code] = array('gender' => $team['gender'], 'max_age' => $team['max_age']);
            }
            update_option('team_oversight_teams', $names);
            update_option('team_oversight_team_meta', $meta);
            return $names;
        }

        return $custom_teams;
    }

    /**
     * Teams with their gender and age-limit metadata:
     * code => array(name, gender: mens|womens|mixed, max_age: int, 0 = open).
     * Gender falls back to a guess from the team code for teams saved before
     * metadata existed.
     */
    public function get_teams_config() {
        $names = $this->get_teams();
        $meta = get_option('team_oversight_team_meta', array());

        $config = array();
        foreach ($names as $code => $name) {
            $team_meta = isset($meta[$code]) && is_array($meta[$code]) ? $meta[$code] : array();
            $gender = isset($team_meta['gender']) && in_array($team_meta['gender'], array('mens', 'womens', 'mixed'), true)
                ? $team_meta['gender']
                : self::derive_team_gender($code);
            $config[$code] = array(
                'name' => $name,
                'gender' => $gender,
                'max_age' => isset($team_meta['max_age']) ? intval($team_meta['max_age']) : 0,
            );
        }
        return $config;
    }

    /**
     * Best-effort gender from a team code, used only for teams saved before
     * the gender setting existed. Handles both conventions:
     *  - legacy: colour in the body, gender as the suffix (SLD1B-W = Blue, Women)
     *  - current: gender in the body, colour as the suffix (SL1W-B = Women, Blue)
     */
    public static function derive_team_gender($code) {
        $parts = explode('-', strtoupper(trim($code)));
        $first = $parts[0];
        $last = count($parts) > 1 ? end($parts) : '';

        // Legacy style: explicit gender letter as its own suffix segment.
        if ($last === 'M') {
            return 'mens';
        }
        if ($last === 'W' || $last === 'G') {
            return 'womens';
        }

        // Current style: gender letter inside the first segment (SL1M-B, YSL17G1, JPLM).
        if (preg_match('/(M|B)\d*$/', $first)) {
            return 'mens';
        }
        if (preg_match('/(W|G)\d*$/', $first)) {
            return 'womens';
        }

        // Remaining suffix B: legacy "Boys" (JPLD1-B).
        if ($last === 'B') {
            return 'mens';
        }

        return 'mixed';
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