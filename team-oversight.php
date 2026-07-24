<?php
/**
 * Plugin Name: Team Oversight
 * Description: MURVC club management - club membership tiers (Club Membership menu) and VVL team oversight: trials, assignments, fees and dashboard (VVL Oversight menu).
 * Version: 1.13.1
 * Author: Team Management System
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: team-oversight
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TEAM_OVERSIGHT_VERSION', '1.13.1');
define('TEAM_OVERSIGHT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TEAM_OVERSIGHT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Activation hook
register_activation_hook(__FILE__, 'team_oversight_activate');

function team_oversight_activate() {
    team_oversight_create_tables();
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'team_oversight_deactivate');

function team_oversight_deactivate() {
    wp_clear_scheduled_hook('team_oversight_membership_sync');
}

function team_oversight_create_tables() {
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
                fee_class varchar(100) NOT NULL,
                team_role varchar(50) NOT NULL,
                fee_amount decimal(10,2) NOT NULL,
                is_active tinyint(1) DEFAULT 0,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY version (version),
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
        'team_memberships' => "
            CREATE TABLE {$wpdb->prefix}team_memberships (
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
            ) $charset_collate;
        ",
        'team_fee_segments' => "
            CREATE TABLE {$wpdb->prefix}team_fee_segments (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned DEFAULT NULL,
                email varchar(255) NOT NULL,
                season varchar(10) NOT NULL,
                fee_role varchar(50) NOT NULL,
                start_date date NOT NULL,
                end_date date DEFAULT NULL,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY email (email),
                KEY season (season)
            ) $charset_collate;
        ",
        'team_invoice_payments' => "
            CREATE TABLE {$wpdb->prefix}team_invoice_payments (
                id int(11) NOT NULL AUTO_INCREMENT,
                invoice_id int(11) NOT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                order_id bigint(20) unsigned DEFAULT NULL,
                order_item_id bigint(20) unsigned DEFAULT NULL,
                amount decimal(10,2) NOT NULL,
                source varchar(20) NOT NULL DEFAULT 'online',
                note varchar(255) DEFAULT '',
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY invoice_id (invoice_id),
                KEY user_id (user_id),
                KEY order_item_id (order_item_id)
            ) $charset_collate;
        ",
        'team_trial_selections' => "
            CREATE TABLE {$wpdb->prefix}team_trial_selections (
                id int(11) NOT NULL AUTO_INCREMENT,
                application_id int(11) NOT NULL,
                season varchar(10) NOT NULL,
                team varchar(50) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'tentative',
                created_by bigint(20) unsigned DEFAULT NULL,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                updated_date datetime DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY app_team (application_id, team),
                KEY season (season),
                KEY team (team)
            ) $charset_collate;
        ",
        'team_trial_notes' => "
            CREATE TABLE {$wpdb->prefix}team_trial_notes (
                id int(11) NOT NULL AUTO_INCREMENT,
                application_id int(11) NOT NULL,
                author_id bigint(20) unsigned NOT NULL,
                note text NOT NULL,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY application_id (application_id)
            ) $charset_collate;
        ",
        'trial_applications' => "
            CREATE TABLE {$wpdb->prefix}trial_applications (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id int(11) NOT NULL,
                email varchar(255) NOT NULL,
                name varchar(255) NOT NULL,
                season varchar(10) NOT NULL,
                trial_number int(11) DEFAULT NULL,
                interested_teams text NOT NULL,
                preferred_positions text NOT NULL,
                is_transfer_player tinyint(1) DEFAULT 0,
                form_data longtext DEFAULT NULL,
                application_status varchar(20) DEFAULT 'pending',
                assigned_team varchar(255) DEFAULT NULL,
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

// Load text domain for translations
add_action('init', 'team_oversight_load_textdomain');

function team_oversight_load_textdomain() {
    load_plugin_textdomain('team-oversight', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Load components only when WordPress is fully loaded
add_action('plugins_loaded', 'team_oversight_init');

function team_oversight_init() {
    // Load all classes
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-database.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-admin.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-fees.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-trials.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-imports.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-exports.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-memberships.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-members-page.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-stats-page.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-coach-portal.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-payments.php';
    require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-readiness.php';

    // Initialize components
    new TeamOversight_Database();
    new TeamOversight_Fees();
    new TeamOversight_Trials();
    new TeamOversight_Imports();
    new TeamOversight_Exports();
    new TeamOversight_Memberships();
    new TeamOversight_Stats_Page(); // hooks the daily snapshot onto the membership cron
    new TeamOversight_Coach_Portal();
    new TeamOversight_Payments();
    new TeamOversight_Readiness();
    
    // Initialize admin interface
    if (is_admin()) {
        new TeamOversight_Admin();
    }
}
