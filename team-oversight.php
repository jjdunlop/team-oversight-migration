<?php
/**
 * Plugin Name: Team Oversight
 * Description: Volleyball team management system with trial applications, fee calculation, and oversight dashboard.
 * Version: 1.0.0
 * Author: Team Management System
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: team-oversight
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TEAM_OVERSIGHT_VERSION', '1.0.0');
define('TEAM_OVERSIGHT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TEAM_OVERSIGHT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Activation hook
register_activation_hook(__FILE__, 'team_oversight_activate');

function team_oversight_activate() {
    team_oversight_create_tables();
    flush_rewrite_rules();
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
    
    // Initialize components
    new TeamOversight_Database();
    new TeamOversight_Fees();
    new TeamOversight_Trials();
    new TeamOversight_Imports();
    new TeamOversight_Exports();
    
    // Initialize admin interface
    if (is_admin()) {
        new TeamOversight_Admin();
    }
}
