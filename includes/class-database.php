<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Database {

    private static $shared = null;
    private static $hooks_registered = false;

    /** The one shared TeamOversight_Database (1.78.0). `new` is harmless anywhere: the constructor adds no hook any more. */
    public static function shared() {
        if (!(self::$shared instanceof self)) {
            self::$shared = new self();
        }
        return self::$shared;
    }

    /** Run the (idempotent, guarded) migration once per request, on init (called from team_oversight_init()). Before 1.78.0 every `new` added its own init hook. */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action('init', array(self::shared(), 'init'));
    }

    public function __construct() {
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
        
        // Expand version column if needed. Only when it isn't already
        // varchar(50) NOT NULL: this runs on every request, and even a
        // no-op MODIFY takes an exclusive lock on the table.
        $version_column = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}fee_matrix LIKE 'version'");
        if ($version_column && (strtolower($version_column->Type) !== 'varchar(50)' || $version_column->Null !== 'NO')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}fee_matrix MODIFY COLUMN version varchar(50) NOT NULL");
        }
        
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

        // Add trial_number to trial_applications (added in 1.5.0): a per-season
        // sequential number players can write on themselves at trials.
        $trial_number_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}trial_applications LIKE 'trial_number'");
        if (empty($trial_number_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}trial_applications ADD COLUMN trial_number int(11) DEFAULT NULL AFTER season");

            // Backfill existing applications sequentially per season.
            $existing = $wpdb->get_results("
                SELECT id, season FROM {$wpdb->prefix}trial_applications
                ORDER BY season, created_date, id
            ");
            $counters = array();
            foreach ($existing as $row) {
                $counters[$row->season] = isset($counters[$row->season]) ? $counters[$row->season] + 1 : 1;
                $wpdb->update(
                    $wpdb->prefix . 'trial_applications',
                    array('trial_number' => $counters[$row->season]),
                    array('id' => $row->id),
                    array('%d'),
                    array('%d')
                );
            }
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

        // Coach selection board tables (added in 1.6.0): per-team verdicts on
        // applications (tentative/selected/rejected) and shared coach notes.
        $selections_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_trial_selections'");
        if (!$selections_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta("CREATE TABLE {$wpdb->prefix}team_trial_selections (
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
            ) $charset_collate;");
            dbDelta("CREATE TABLE {$wpdb->prefix}team_trial_notes (
                id int(11) NOT NULL AUTO_INCREMENT,
                application_id int(11) NOT NULL,
                author_id bigint(20) unsigned NOT NULL,
                note text NOT NULL,
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY application_id (application_id)
            ) $charset_collate;");
        }

        // Fee segments (added in 1.9.0): a dated history of each player's
        // fee role per season, so mid-season role changes (training-only ->
        // playing) charge each period at its own rate.
        $segments_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_fee_segments'");
        if (!$segments_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta("CREATE TABLE {$wpdb->prefix}team_fee_segments (
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
            ) $charset_collate;");
        }

        // 1.65.0: each fee segment also records every active team bucket
        // (team, role, fee class) as JSON, so the fee (the highest bucket)
        // and its breakdown can be worked out per stretch. NULL on older
        // segments (single fee_role).
        $buckets_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_fee_segments LIKE 'buckets'");
        if (empty($buckets_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_fee_segments ADD COLUMN buckets text DEFAULT NULL");
        }

        // Fee payment ledger (added in 1.7.0): every payment applied to an
        // invoice, whether from an online order or recorded manually.
        $payments_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_invoice_payments'");
        if (!$payments_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta("CREATE TABLE {$wpdb->prefix}team_invoice_payments (
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
            ) $charset_collate;");
        }

        // Widen assigned_team so multi-team finalisation fits (added in 1.6.0).
        // Only when it isn't already a nullable varchar(255): a no-op MODIFY
        // still locks trial_applications, queueing page loads behind it.
        $assigned_column = $wpdb->get_row("SHOW COLUMNS FROM {$wpdb->prefix}trial_applications LIKE 'assigned_team'");
        if ($assigned_column && (strtolower($assigned_column->Type) !== 'varchar(255)' || $assigned_column->Null !== 'YES')) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}trial_applications MODIFY COLUMN assigned_team varchar(255) DEFAULT NULL");
        }

        // Club program attendance ticks (added in 1.35.0). One row per
        // booking per session; absence means "not marked".
        $attendance_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_program_attendance'");
        if (!$attendance_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta("CREATE TABLE {$wpdb->prefix}team_program_attendance (
                id int(11) NOT NULL AUTO_INCREMENT,
                program_key varchar(60) NOT NULL,
                session_label varchar(60) NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                attended tinyint(1) NOT NULL DEFAULT 1,
                marked_by bigint(20) unsigned DEFAULT NULL,
                marked_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_booking (program_key, session_label, order_id),
                KEY session_lookup (program_key, session_label)
            ) $charset_collate;");
        }

        // 1.60.0: coaches can edit their own notes; updated_date marks a
        // note as edited so other coaches know it changed after they read it.
        $note_updated_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_trial_notes LIKE 'updated_date'");
        if (empty($note_updated_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_trial_notes ADD COLUMN updated_date datetime DEFAULT NULL");
        }

        // 1.62.2: free text was saved without wp_unslash(), so WordPress's
        // magic-quote slashes were stored too ("I\'ve", \"quoted\"). Undo
        // them once in what was saved: notes, application answers, team
        // names, grant and payment notes.
        if (!get_option('team_oversight_unslash_fixed')) {
            self::repair_magic_quotes();
            update_option('team_oversight_unslash_fixed', 1, false);
        }

        // 1.67.0: team-assignment memberships run to 1 April of the next
        // year (not 31 Dec). Extend the ones already running, once.
        if (!get_option('team_oversight_assignment_grants_april') && class_exists('TeamOversight_Memberships')) {
            TeamOversight_Memberships::extend_assignment_grants_to_april();
            update_option('team_oversight_assignment_grants_april', 1, false);
        }

        // 1.63.0: unpaid applications no longer expire after 7 days. Bring
        // back the ones that did, so coaches see them as Payment pending
        // (their trial numbers were never released).
        if (!get_option('team_oversight_expired_revived')) {
            $wpdb->query("UPDATE {$wpdb->prefix}trial_applications SET application_status = 'awaiting_payment' WHERE application_status = 'expired'");
            update_option('team_oversight_expired_revived', 1, false);
        }

        // Coach-portal attendance (added in 1.59.0): one row per player, per
        // team, per session date. person_key is 'u<user id>' (or the
        // lowercased email for a legacy email-only roster row), so the
        // unique key holds without NULLs and two coaches marking the same
        // player at once can't create a duplicate.
        $team_attendance_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_attendance'");
        if (!$team_attendance_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta("CREATE TABLE {$wpdb->prefix}team_attendance (
                id int(11) NOT NULL AUTO_INCREMENT,
                person_key varchar(191) NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                email varchar(255) NOT NULL DEFAULT '',
                season varchar(10) NOT NULL,
                team varchar(50) NOT NULL,
                session_date date NOT NULL,
                marked_by bigint(20) unsigned DEFAULT NULL,
                marked_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_mark (person_key, team, session_date),
                KEY season_person (season, person_key)
            ) $charset_collate;");
        }

        // Activity log table (added in 1.20.0).
        $log_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_activity_log'");
        if (!$log_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta("CREATE TABLE {$wpdb->prefix}team_activity_log (
                id int(11) NOT NULL AUTO_INCREMENT,
                event_type varchar(40) NOT NULL,
                subject_user_id bigint(20) unsigned DEFAULT NULL,
                actor_id bigint(20) unsigned DEFAULT NULL,
                amount decimal(10,2) DEFAULT NULL,
                message varchar(500) DEFAULT '',
                created_date datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_type (event_type),
                KEY subject_user_id (subject_user_id),
                KEY created_date (created_date)
            ) $charset_collate;");
        }

        // Import price matrix if fee_matrix table is empty
        $existing_fees = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fee_matrix WHERE is_active = 1");
        if ($existing_fees == 0) {
            require_once TEAM_OVERSIGHT_PLUGIN_DIR . 'includes/class-fees.php';
            $fees = new TeamOversight_Fees();
            $fees->import_price_matrix();
        }

        // 1.21.0: dedupe by database constraint, not check-then-insert.
        // Concurrent order hooks (Square webhook + admin save) could both
        // pass the SELECT dedupe and double-apply; a UNIQUE index on
        // order_item_id makes duplicates impossible (NULLs — manual
        // payments, manual grants — are exempt by MySQL semantics).
        $pay_unique = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}team_invoice_payments WHERE Key_name = 'uniq_order_item'");
        if (empty($pay_unique)) {
            $wpdb->query("
                DELETE p1 FROM {$wpdb->prefix}team_invoice_payments p1
                JOIN {$wpdb->prefix}team_invoice_payments p2
                    ON p1.order_item_id = p2.order_item_id AND p1.id > p2.id
                WHERE p1.order_item_id IS NOT NULL
            ");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_invoice_payments ADD UNIQUE KEY uniq_order_item (order_item_id)");
        }
        $mem_unique = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}team_memberships WHERE Key_name = 'uniq_order_item'");
        if (empty($mem_unique)) {
            $wpdb->query("
                DELETE m1 FROM {$wpdb->prefix}team_memberships m1
                JOIN {$wpdb->prefix}team_memberships m2
                    ON m1.order_item_id = m2.order_item_id AND m1.id > m2.id
                WHERE m1.order_item_id IS NOT NULL
            ");
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_memberships ADD UNIQUE KEY uniq_order_item (order_item_id)");
        }

        // 1.18.0: the payments ledger became the single source of truth
        // (paid = SUM(ledger), outstanding = fee - paid). One-time backfill:
        // wherever the old derived paid (fee - outstanding) exceeds what the
        // ledger holds, record the difference as a reconciliation row, then
        // resync every invoice's outstanding from the ledger.
        if (!get_option('team_oversight_ledger_backfilled')) {
            $wpdb->query("
                INSERT INTO {$wpdb->prefix}team_invoice_payments (invoice_id, user_id, amount, source, note)
                SELECT i.id, i.user_id,
                    ROUND(i.invoice_amount - i.outstanding_amount - COALESCE(p.total, 0), 2),
                    'reconciliation',
                    'Backfill of payments recorded before the ledger became the source of truth'
                FROM {$wpdb->prefix}team_invoices i
                LEFT JOIN (
                    SELECT invoice_id, SUM(amount) AS total
                    FROM {$wpdb->prefix}team_invoice_payments GROUP BY invoice_id
                ) p ON p.invoice_id = i.id
                WHERE ROUND(i.invoice_amount - i.outstanding_amount - COALESCE(p.total, 0), 2) >= 0.01
            ");
            $wpdb->query("
                UPDATE {$wpdb->prefix}team_invoices i
                LEFT JOIN (
                    SELECT invoice_id, SUM(amount) AS total
                    FROM {$wpdb->prefix}team_invoice_payments GROUP BY invoice_id
                ) p ON p.invoice_id = i.id
                SET i.outstanding_amount = GREATEST(0, ROUND(i.invoice_amount - COALESCE(p.total, 0), 2))
            ");
            add_option('team_oversight_ledger_backfilled', 1, '', 'no');
        }

        // 1.48.0: teams became per-season. Give every season that has (or
        // is about to have) data its own copy of the global team list, so
        // editing 2027's teams never touches 2026's. One-time; the bare
        // list stays behind as the fallback for seasons never seeded.
        if (!get_option('team_oversight_teams_seasoned')) {
            $seasons = array_merge(
                (array) $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments"),
                (array) $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}trial_applications"),
                (array) $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_invoices"),
                (array) get_option('team_oversight_created_seasons', array()),
                array(date('Y'), strval(intval(date('Y')) + 1))
            );
            foreach (array_unique(array_map('strval', $seasons)) as $season) {
                if (preg_match('/^\d{4}$/', $season)) {
                    self::seed_season_teams($season);
                }
            }
            add_option('team_oversight_teams_seasoned', 1, '', 'no');
        }

        // 1.54.0: applicants can edit their application after submitting.
        // updated_date records the latest edit, so the 7-day unpaid expiry
        // counts from when the fee became due rather than from first
        // submission (otherwise an edit that makes the fee payable, or a
        // resubmitted expired application, would re-expire at once).
        $updated_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}trial_applications LIKE 'updated_date'");
        if (empty($updated_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}trial_applications ADD COLUMN updated_date datetime DEFAULT NULL");
        }

        // From the 2027 season a Selected / Training Only verdict is an
        // offer: the coach records when the player has accepted it, and
        // Finalise only places accepted offers. Existing verdicts start as
        // not accepted. One statement, so the three columns arrive together.
        $offer_accepted_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_trial_selections LIKE 'offer_accepted'");
        if (empty($offer_accepted_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_trial_selections
                ADD COLUMN offer_accepted tinyint(1) NOT NULL DEFAULT 0,
                ADD COLUMN offer_accepted_at datetime DEFAULT NULL,
                ADD COLUMN offer_accepted_by bigint(20) unsigned DEFAULT NULL");
        }

        // 1.74.0: an admin's call on an application's VV registration type
        // (blank = worked out from the answers: see
        // TeamOversight_Trials::registration_type()), with who and when.
        $registration_override_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}trial_applications LIKE 'registration_override'");
        if (empty($registration_override_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}trial_applications
                ADD COLUMN registration_override varchar(20) DEFAULT NULL,
                ADD COLUMN registration_override_by bigint(20) unsigned DEFAULT NULL,
                ADD COLUMN registration_override_at datetime DEFAULT NULL");
        }

        // 1.75.0: a confirmed player's position on their team for the
        // season (a label only), by account (or email with none). Read
        // before the verdict's team_position, which is where it starts.
        $team_positions_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_positions'");
        if (!$team_positions_exists) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta(self::team_positions_table_sql());
        }

        // 1.74.0: the position a coach gives a player with a verdict for
        // their team, separate from the positions on the application.
        $team_position_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_trial_selections LIKE 'team_position'");
        if (empty($team_position_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_trial_selections ADD COLUMN team_position varchar(20) DEFAULT NULL");
        }

        // 1.68.5: a fee set by hand with Edit Fee sticks. fee_overridden
        // marks it (with when and by whom), so no automatic re-price changes
        // it; fee_calculated_logged is the calculated amount last logged
        // while it was kept, so each different amount is logged once.
        // Existing invoices start as automatic (0). One statement, so the
        // four columns arrive together.
        $fee_overridden_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_invoices LIKE 'fee_overridden'");
        if (empty($fee_overridden_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_invoices
                ADD COLUMN fee_overridden tinyint(1) NOT NULL DEFAULT 0,
                ADD COLUMN fee_overridden_at datetime DEFAULT NULL,
                ADD COLUMN fee_overridden_by bigint(20) unsigned DEFAULT NULL,
                ADD COLUMN fee_calculated_logged decimal(10,2) DEFAULT NULL");
        }

        // 1.69.0: a payment arrangement agreed with the club (Payment
        // Management). The invoice still shows as behind, but never flags
        // the player as not to train. Existing invoices start with none.
        // One statement, so the four columns arrive together.
        $arrangement_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_invoices LIKE 'payment_arrangement'");
        if (empty($arrangement_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_invoices
                ADD COLUMN payment_arrangement tinyint(1) NOT NULL DEFAULT 0,
                ADD COLUMN payment_arrangement_note varchar(255) DEFAULT NULL,
                ADD COLUMN payment_arrangement_at datetime DEFAULT NULL,
                ADD COLUMN payment_arrangement_by bigint(20) unsigned DEFAULT NULL");
        }

        // 1.69.0: when the fee was last raised (by a re-price or Edit Fee),
        // so a fee raised mid-season is behind from that day at the
        // earliest, not from the season start. Not backfilled: existing
        // invoices start with none.
        $fee_raised_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}team_invoices LIKE 'fee_raised_at'");
        if (empty($fee_raised_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}team_invoices ADD COLUMN fee_raised_at datetime DEFAULT NULL");
        }

        // 1.70.0: the fee timeline's change history (see
        // assignment_changes_table_sql()). Nothing writes to it yet.
        $changes_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_assignment_changes'");
        if (!$changes_exists) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta(self::assignment_changes_table_sql());
        }

        // 1.71.0: the account-merge journal (summary + backup per merge) and
        // the 'not the same person' decisions for Duplicate accounts.
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_account_merges'") && class_exists('TeamOversight_Account_Merge')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta(TeamOversight_Account_Merge::table_sql());
        }
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}team_duplicate_decisions'") && class_exists('TeamOversight_Duplicates')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta(TeamOversight_Duplicates::decisions_table_sql());
        }

        // 1.76.0: offers and deposits. Four new tables, each created behind
        // its own SHOW TABLES check so a normal load issues no CREATE or
        // ALTER, and nothing existing is altered (fee_matrix,
        // team_trial_selections, team_invoices, team_invoice_payments and
        // trial_applications keep their columns). Offers are how selection
        // works from the 2027 season (1.79.0: no switch); earlier seasons
        // never read these tables.
        foreach (self::offer_tables_sql() as $short => $sql) {
            if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$short}'")) {
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);
            }
        }

        // 1.80.0: one "not selected" notice per (application, team), sent
        // only when someone presses a button. Additive: four new columns
        // (team, source, acted_by, acted_at; a row from before keeps team ''
        // and stays readable as history) and a unique key on (application_id,
        // team) in place of the old unique key on application_id alone (that
        // key would allow one notice per application; the new one is added
        // FIRST, so the table is never without a unique rule). No column is
        // dropped or renamed and no row is deleted. Each step sits behind its
        // own SHOW check, so a normal load issues no ALTER; the one-off data
        // step (per-application rows mapped to the teams that rejected them)
        // is TeamOversight_Not_Selected::migrate_legacy_rows(), behind its
        // own option flag.
        $ns_table = $wpdb->prefix . 'team_not_selected';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ns_table}'")) {
            if (empty($wpdb->get_results("SHOW COLUMNS FROM {$ns_table} LIKE 'team'"))) {
                $wpdb->query("ALTER TABLE {$ns_table} ADD COLUMN team varchar(50) NOT NULL DEFAULT '' AFTER application_id, ADD COLUMN source varchar(10) NOT NULL DEFAULT '', ADD COLUMN acted_by bigint(20) unsigned DEFAULT NULL, ADD COLUMN acted_at datetime DEFAULT NULL");
            }
            if (!empty($wpdb->get_results("SHOW INDEX FROM {$ns_table} WHERE Key_name = 'application'"))) {
                if (empty($wpdb->get_results("SHOW INDEX FROM {$ns_table} WHERE Key_name = 'application_team'"))) {
                    $wpdb->query("ALTER TABLE {$ns_table} ADD UNIQUE KEY application_team (application_id, team)");
                }
                if (!empty($wpdb->get_results("SHOW INDEX FROM {$ns_table} WHERE Key_name = 'application_team'"))) {
                    $wpdb->query("ALTER TABLE {$ns_table} DROP INDEX application");
                }
            }
            if (class_exists('TeamOversight_Not_Selected') && method_exists('TeamOversight_Not_Selected', 'migrate_legacy_rows')) {
                TeamOversight_Not_Selected::migrate_legacy_rows();
            }
        }

        // 1.81.0: coach profiles, their team ticks and their history
        // (TeamOversight_Coaches::tables_sql()). Each behind its own SHOW TABLES
        // check; nothing existing is altered.
        if (class_exists('TeamOversight_Coaches')) {
            foreach (TeamOversight_Coaches::tables_sql() as $short => $sql) {
                if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$short}'")) {
                    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                    dbDelta($sql);
                }
            }
        }
    }

    /**
     * 1.76.0: the offers feature's tables, short name => CREATE statement
     * (migrate_database(), create_tables() and team_oversight_create_tables()
     * all use this one list). See design/offer-deposit-tech-design.md
     * section 1 for what each column is for.
     */
    public static function offer_tables_sql() {
        return array(
            'team_offers' => self::offers_table_sql(),
            'team_offer_emails' => self::offer_emails_table_sql(),
            'team_not_selected' => self::not_selected_table_sql(),
            'fee_matrix_limits' => self::fee_matrix_limits_table_sql(),
        );
    }

    /**
     * One row per offer cycle (a new cycle for the same player and team is
     * a new row; rows are NEVER deleted). `live` is 1 while the offer is not
     * finished and NULL after, and the unique key (application_id, team,
     * live) treats NULLs as distinct, so the database allows one live offer
     * per application and team. A person table for account merges (user_id,
     * email, name snapshot, season).
     */
    public static function offers_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_offers (
                id int(11) NOT NULL AUTO_INCREMENT,
                application_id int(11) NOT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                email varchar(255) NOT NULL DEFAULT '',
                name varchar(255) NOT NULL DEFAULT '',
                season varchar(10) NOT NULL,
                team varchar(50) NOT NULL,
                role varchar(20) NOT NULL,
                state varchar(24) NOT NULL,
                live tinyint(1) DEFAULT NULL,
                requested_by bigint(20) unsigned DEFAULT NULL,
                requested_at datetime NOT NULL,
                decided_by bigint(20) unsigned DEFAULT NULL,
                decided_at datetime DEFAULT NULL,
                quote_basis varchar(8) DEFAULT NULL,
                quote_amount decimal(10,2) DEFAULT NULL,
                quote_fee_class varchar(100) DEFAULT NULL,
                quote_start date DEFAULT NULL,
                deposit_percent decimal(5,2) DEFAULT NULL,
                deposit_target decimal(10,2) DEFAULT NULL,
                quote_released decimal(10,2) DEFAULT NULL,
                target_dropped_at datetime DEFAULT NULL,
                sent_at datetime DEFAULT NULL,
                reply_by date DEFAULT NULL,
                deposit_due date DEFAULT NULL,
                extended_count tinyint(3) unsigned NOT NULL DEFAULT 0,
                reminder_count tinyint(3) unsigned NOT NULL DEFAULT 0,
                last_reminded_at datetime DEFAULT NULL,
                accepted_at datetime DEFAULT NULL,
                declined_at datetime DEFAULT NULL,
                decline_reason varchar(500) DEFAULT NULL,
                invoice_id int(11) DEFAULT NULL,
                locked_in_at datetime DEFAULT NULL,
                place_id int(11) DEFAULT NULL,
                placed_at datetime DEFAULT NULL,
                placement_error varchar(255) DEFAULT NULL,
                pricing_error varchar(255) DEFAULT NULL,
                last_retry_at datetime DEFAULT NULL,
                cap_logged decimal(10,2) DEFAULT NULL,
                closed_by bigint(20) unsigned DEFAULT NULL,
                closed_at datetime DEFAULT NULL,
                closed_reason varchar(255) DEFAULT NULL,
                settlement varchar(10) NOT NULL DEFAULT '',
                settled_amount decimal(10,2) DEFAULT NULL,
                settled_by bigint(20) unsigned DEFAULT NULL,
                settled_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY live_offer (application_id, team, live),
                KEY season_state (season, state),
                KEY user_season (user_id, season),
                KEY email_season (email(191), season),
                KEY invoice (invoice_id)
            ) ENGINE=InnoDB $charset_collate;";
    }

    /**
     * The offer email outbox: one row per message ever queued, unique on
     * dedupe_key so a message is queued once and sent once. Holds no
     * address, name or account id (resolved from the offer at send time),
     * so it is not an account-merge table. `context` is a small JSON note
     * from whoever queued it (see design/offer-api.md).
     */
    public static function offer_emails_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_offer_emails (
                id int(11) NOT NULL AUTO_INCREMENT,
                dedupe_key varchar(80) NOT NULL,
                event varchar(20) NOT NULL,
                offer_id int(11) NOT NULL DEFAULT 0,
                application_id int(11) NOT NULL DEFAULT 0,
                status varchar(10) NOT NULL,
                tries tinyint(3) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                claimed_at datetime DEFAULT NULL,
                sent_at datetime DEFAULT NULL,
                note varchar(255) DEFAULT NULL,
                context text DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dedupe (dedupe_key),
                KEY queue (status, id),
                KEY offer (offer_id)
            ) ENGINE=InnoDB $charset_collate;";
    }

    /**
     * The "not selected" notices (1.80.0): one row per (application, team)
     * that someone pressed Send or Don't send for (team = the team code;
     * a row from before 1.80.0 has team '' and was one per application).
     * Rows are never deleted. acted_by (who pressed it) is an account id,
     * registered with the account merge (id_columns()); the application id
     * leads to the player, so the table holds no other person data.
     */
    public static function not_selected_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_not_selected (
                id int(11) NOT NULL AUTO_INCREMENT,
                application_id int(11) NOT NULL,
                team varchar(50) NOT NULL DEFAULT '',
                season varchar(10) NOT NULL,
                state varchar(10) NOT NULL,
                since datetime NOT NULL,
                send_after datetime NOT NULL,
                queued_at datetime DEFAULT NULL,
                source varchar(10) NOT NULL DEFAULT '',
                acted_by bigint(20) unsigned DEFAULT NULL,
                acted_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY application_team (application_id, team),
                KEY due (state, send_after)
            ) ENGINE=InnoDB $charset_collate;";
    }

    /**
     * The second amount in every fee matrix cell: the upper limit the club
     * guarantees a fee will not exceed. One row per season, fee class and
     * role; no row = no limit. fee_matrix itself is not altered, so
     * everything that prices a real fee is untouched and a blank fee is
     * still simply no fee_matrix row.
     */
    public static function fee_matrix_limits_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}fee_matrix_limits (
                id int(11) NOT NULL AUTO_INCREMENT,
                season varchar(10) NOT NULL,
                fee_class varchar(100) NOT NULL,
                team_role varchar(50) NOT NULL,
                upper_limit decimal(10,2) NOT NULL,
                updated_by bigint(20) unsigned DEFAULT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY cell (season, fee_class, team_role)
            ) ENGINE=InnoDB $charset_collate;";
    }

    /**
     * The fee timeline's change history (1.70.0, used from the 2027
     * season): one row per change to a team place's dates, team or role,
     * with who made it, when, and the fee and amount due before and after.
     * It is the only record of a removed row, of who changed which date,
     * and of when a change put more money due (the behind clock's floor),
     * so it is NEVER pruned, unlike team_activity_log. Explicitly InnoDB.
     * One definition for migrate_database(), create_tables() and
     * team_oversight_create_tables().
     */
    /**
     * 1.75.0: confirmed players' positions on their team for a season (a
     * label coaches set on the team list; nothing is priced or placed by
     * it). One definition, shared by migrate_database(), create_tables()
     * and activation. A person table for account merges.
     */
    public static function team_positions_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_positions (
                id int(11) NOT NULL AUTO_INCREMENT,
                season varchar(10) NOT NULL,
                team varchar(50) NOT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                email varchar(255) NOT NULL DEFAULT '',
                position varchar(20) NOT NULL,
                updated_by bigint(20) unsigned DEFAULT NULL,
                updated_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY season_team (season, team),
                KEY user_id (user_id),
                KEY email (email)
            ) $charset_collate;";
    }

    public static function assignment_changes_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_assignment_changes (
                id int(11) NOT NULL AUTO_INCREMENT,
                assignment_id int(11) DEFAULT NULL,
                user_id bigint(20) unsigned DEFAULT NULL,
                email varchar(255) NOT NULL,
                season varchar(10) NOT NULL,
                team varchar(50) NOT NULL DEFAULT '',
                role varchar(50) NOT NULL DEFAULT '',
                action varchar(20) NOT NULL,
                source varchar(20) NOT NULL DEFAULT '',
                old_start date DEFAULT NULL,
                new_start date DEFAULT NULL,
                old_end date DEFAULT NULL,
                new_end date DEFAULT NULL,
                old_class varchar(100) DEFAULT NULL,
                new_class varchar(100) DEFAULT NULL,
                fee_before decimal(10,2) DEFAULT NULL,
                fee_after decimal(10,2) DEFAULT NULL,
                due_before decimal(10,2) DEFAULT NULL,
                due_after decimal(10,2) DEFAULT NULL,
                note varchar(255) DEFAULT NULL,
                recorded_at datetime NOT NULL,
                recorded_by bigint(20) unsigned DEFAULT NULL,
                PRIMARY KEY (id),
                KEY season_user (season, user_id),
                KEY email_season (email(191), season),
                KEY assignment_id (assignment_id)
            ) ENGINE=InnoDB $charset_collate;";
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
                    fee_overridden tinyint(1) NOT NULL DEFAULT 0,
                    fee_overridden_at datetime DEFAULT NULL,
                    fee_overridden_by bigint(20) unsigned DEFAULT NULL,
                    fee_calculated_logged decimal(10,2) DEFAULT NULL,
                    payment_arrangement tinyint(1) NOT NULL DEFAULT 0,
                    payment_arrangement_note varchar(255) DEFAULT NULL,
                    payment_arrangement_at datetime DEFAULT NULL,
                    payment_arrangement_by bigint(20) unsigned DEFAULT NULL,
                    fee_raised_at datetime DEFAULT NULL,
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
            'team_fee_segments' => "
            CREATE TABLE {$wpdb->prefix}team_fee_segments (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned DEFAULT NULL,
                email varchar(255) NOT NULL,
                season varchar(10) NOT NULL,
                fee_role varchar(50) NOT NULL,
                buckets text DEFAULT NULL,
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
                offer_accepted tinyint(1) NOT NULL DEFAULT 0,
                offer_accepted_at datetime DEFAULT NULL,
                offer_accepted_by bigint(20) unsigned DEFAULT NULL,
                team_position varchar(20) DEFAULT NULL,
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
            ",
            'team_assignment_changes' => self::assignment_changes_table_sql(),
            'team_positions' => self::team_positions_table_sql(),
        );
        // 1.76.0: offers and deposits.
        foreach (self::offer_tables_sql() as $short => $sql) {
            $tables[$short] = $sql;
        }
        if (class_exists('TeamOversight_Account_Merge')) {
            $tables['team_account_merges'] = TeamOversight_Account_Merge::table_sql();
        }
        if (class_exists('TeamOversight_Duplicates')) {
            $tables['team_duplicate_decisions'] = TeamOversight_Duplicates::decisions_table_sql();
        }
        // 1.81.0: coach profiles, their team ticks and their history.
        if (class_exists('TeamOversight_Coaches')) {
            foreach (TeamOversight_Coaches::tables_sql() as $short => $sql) {
                $tables[$short] = $sql;
            }
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        foreach ($tables as $table_name => $sql) {
            dbDelta($sql);
        }
    }
    
    /**
     * The current club team list, seeded when no teams are configured.
     * gender: mens|womens|mixed. age_rule: see get_age_rules().
     */
    public static function get_default_teams() {
        $teams = self::default_team_list();
        // 1.76.0: every default team carries its division level.
        foreach ($teams as $code => $team) {
            $teams[$code]['level'] = self::default_level_for_code($code);
        }
        return $teams;
    }

    /**
     * The division level a team code gets when none is stored (1.76.0):
     * a pure function of the code. Lower number = higher division; teams
     * in the same division share a number. PL1 = 1, PL2 = 2, JPL = 3,
     * SL1 = 4, SL2 = 5, SL3 = 6, YSL17 (U17) = 7, YSL15 (U15) = 8; any
     * other code = 0, meaning 'not set' (the team is never suggested as a
     * lower division and Configuration lists it in red). The older code
     * style (SLD1B-W, JPLD1-B, PLD1-M: the prefix with an optional D) is
     * read too, so a season seeded from older codes still gets levels.
     */
    public static function default_level_for_code($code) {
        $code = strtoupper(trim((string) $code));
        if (preg_match('/^JPL/', $code)) {
            return 3;
        }
        if (preg_match('/^YSL(17|15)/', $code, $m)) {
            return $m[1] === '17' ? 7 : 8;
        }
        if (preg_match('/^SLD?([1-3])(?!\d)/', $code, $m)) {
            return 3 + intval($m[1]);
        }
        if (preg_match('/^PLD?([1-2])(?!\d)/', $code, $m)) {
            return intval($m[1]);
        }
        return 0;
    }

    /** The default club team list without levels (get_default_teams() adds them). */
    private static function default_team_list() {
        return array(
            'PL1M' => array('name' => 'Premier League 1 Men', 'gender' => 'mens', 'age_rule' => '', 'shirts' => 2),
            'PL1W' => array('name' => 'Premier League 1 Women', 'gender' => 'womens', 'age_rule' => '', 'shirts' => 2),
            'PL2M' => array('name' => 'Premier League 2 Men', 'gender' => 'mens', 'age_rule' => '', 'shirts' => 2),
            'PL2W' => array('name' => 'Premier League 2 Women', 'gender' => 'womens', 'age_rule' => '', 'shirts' => 2),
            'SL1M-B' => array('name' => 'State League 1 Men Blue', 'gender' => 'mens', 'age_rule' => ''),
            'SL1M-W' => array('name' => 'State League 1 Men White', 'gender' => 'mens', 'age_rule' => ''),
            'SL1W-B' => array('name' => 'State League 1 Women Blue', 'gender' => 'womens', 'age_rule' => ''),
            'SL1W-W' => array('name' => 'State League 1 Women White', 'gender' => 'womens', 'age_rule' => ''),
            'SL2M' => array('name' => 'State League 2 Men', 'gender' => 'mens', 'age_rule' => ''),
            'SL2W' => array('name' => 'State League 2 Women', 'gender' => 'womens', 'age_rule' => ''),
            'SL3M-R' => array('name' => 'State League 3 Men Red', 'gender' => 'mens', 'age_rule' => ''),
            'SL3M-B' => array('name' => 'State League 3 Men Blue', 'gender' => 'mens', 'age_rule' => ''),
            'SL3W' => array('name' => 'State League 3 Women', 'gender' => 'womens', 'age_rule' => ''),
            'JPLM' => array('name' => 'Junior Premier League Men', 'gender' => 'mens', 'age_rule' => 'u19'),
            'JPLW' => array('name' => 'Junior Premier League Women', 'gender' => 'womens', 'age_rule' => 'u19'),
            'YSL17B1-B' => array('name' => 'Youth State League 17 Boys Blue', 'gender' => 'mens', 'age_rule' => 'u17', 'shirts' => 0),
            'YSL17B1-R' => array('name' => 'Youth State League 17 Boys Red', 'gender' => 'mens', 'age_rule' => 'u17', 'shirts' => 0),
            'YSL17G1' => array('name' => 'Youth State League 17 Girls', 'gender' => 'womens', 'age_rule' => 'u17', 'shirts' => 0),
            'YSL15B' => array('name' => 'Youth State League 15 Boys', 'gender' => 'mens', 'age_rule' => 'u15', 'shirts' => 0),
            'YSL15G' => array('name' => 'Youth State League 15 Girls', 'gender' => 'womens', 'age_rule' => 'u15', 'shirts' => 0),
        );
    }

    /**
     * Age eligibility rules per the VVL By-Laws. The DOB cutoff is computed
     * from the season year (get_dob_cutoff), so nothing needs updating when
     * the season rolls over:
     *  - u19 (JPL/junior): must not turn 19 during the season's calendar year
     *  - u17 (YSL): age 16 or younger as of 31 August of the season year
     *  - u15 (YSL): age 14 or younger as of 31 August of the season year
     */
    public static function get_age_rules() {
        return array(
            '' => 'Open age',
            'u19' => 'U19 — Junior (JPL: no 19th birthday in the season year)',
            'u17' => 'U17 — YSL (16 or younger on 31 August)',
            'u15' => 'U15 — YSL (14 or younger on 31 August)',
        );
    }

    /**
     * Earliest allowed date of birth for an age rule in a given season.
     * Players must be born ON or AFTER this date. Null = no restriction.
     */
    public static function get_dob_cutoff($age_rule, $season) {
        $season = intval($season);
        switch ($age_rule) {
            case 'u19':
                return ($season - 18) . '-01-01';
            case 'u17':
                return ($season - 17) . '-09-01';
            case 'u15':
                return ($season - 15) . '-09-01';
        }
        return null;
    }

    /**
     * The youngest VVL competition a player is still young enough for in a
     * season, from their date of birth (UM `YYYY/MM/DD` or `YYYY-MM-DD`):
     * 'U15', 'U17', 'JPL' or 'Senior comps only'. Uses the same cutoffs as
     * the team age rules, so it rolls over with the season. '' when the
     * date of birth is missing or unreadable.
     */
    public static function get_lowest_eligible_competition($birth_date, $season) {
        $birth_date = str_replace('/', '-', trim((string) $birth_date));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            return '';
        }
        $ladder = array('u15' => 'U15', 'u17' => 'U17', 'u19' => 'JPL');
        foreach ($ladder as $rule => $label) {
            if ($birth_date >= self::get_dob_cutoff($rule, $season)) {
                return $label;
            }
        }
        return 'Senior comps only';
    }

    // ------------------------------------------------------------------
    // Teams are configured PER SEASON (1.48.0+). The registry options
    // (team_oversight_teams / team_oversight_team_meta) are keyed by
    // "CODE-YYYY", so SL2M-2027 and SL2M-2026 are separate entries that
    // can be renamed, re-ruled or deleted independently. Assignment,
    // application and selection rows keep the bare code plus their own
    // season column — that pair IS the seasoned identity, so no data row
    // ever needs rewriting and "returning to SL2M" still reads naturally.
    // Bare (un-suffixed) keys are the pre-1.48 global list and remain as
    // the fallback for any season with no entries of its own.
    // ------------------------------------------------------------------

    /**
     * Remove a magic-quote slash — a backslash directly before ' " or \ —
     * and nothing else, so other backslashes in the text survive.
     */
    public static function unslash_text($text) {
        return is_string($text) ? preg_replace('/\\\\([\'"\\\\])/', '$1', $text) : $text;
    }

    /**
     * One-off repair (1.62.2) of free text saved before input was
     * unslashed: coach notes, application answers, team names, membership
     * grant notes and shirt-credit notes. Only rows that change are written.
     */
    private static function repair_magic_quotes() {
        global $wpdb;

        $notes = $wpdb->get_results("SELECT id, note FROM {$wpdb->prefix}team_trial_notes WHERE INSTR(note, CHAR(92)) > 0");
        foreach ($notes as $row) {
            $fixed = self::unslash_text($row->note);
            if ($fixed !== $row->note) {
                $wpdb->update($wpdb->prefix . 'team_trial_notes', array('note' => $fixed), array('id' => $row->id), array('%s'), array('%d'));
            }
        }

        $apps = $wpdb->get_results("SELECT id, form_data FROM {$wpdb->prefix}trial_applications WHERE INSTR(form_data, CHAR(92)) > 0");
        foreach ($apps as $row) {
            $data = json_decode($row->form_data, true);
            if (!is_array($data)) {
                continue;
            }
            $changed = false;
            foreach ($data as $key => $value) {
                $fixed = self::unslash_text($value);
                if ($fixed !== $value) {
                    $data[$key] = $fixed;
                    $changed = true;
                }
            }
            if ($changed) {
                $wpdb->update($wpdb->prefix . 'trial_applications', array('form_data' => wp_json_encode($data)), array('id' => $row->id), array('%s'), array('%d'));
            }
        }

        $teams = get_option('team_oversight_teams', array());
        if (is_array($teams)) {
            $fixed_teams = array_map(array(__CLASS__, 'unslash_text'), $teams);
            if ($fixed_teams !== $teams) {
                update_option('team_oversight_teams', $fixed_teams);
            }
        }

        $grants = $wpdb->get_results("SELECT id, note FROM {$wpdb->prefix}team_memberships WHERE INSTR(note, CHAR(92)) > 0");
        foreach ($grants as $row) {
            $fixed = self::unslash_text($row->note);
            if ($fixed !== $row->note) {
                $wpdb->update($wpdb->prefix . 'team_memberships', array('note' => $fixed), array('id' => $row->id), array('%s'), array('%d'));
            }
        }

        $credits = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'murvc_shirt_credit' AND INSTR(meta_value, CHAR(92)) > 0");
        foreach ($credits as $row) {
            $credit = maybe_unserialize($row->meta_value);
            if (is_array($credit) && isset($credit['note'])) {
                $fixed = self::unslash_text($credit['note']);
                if ($fixed !== $credit['note']) {
                    $credit['note'] = $fixed;
                    update_user_meta($row->user_id, 'murvc_shirt_credit', $credit);
                }
            }
        }
    }

    /**
     * A member's gender, as a plain string ('' when unset).
     *
     * UM holds it under two keys. `gender_dropdown` is what the Member
     * Profile and rego forms write today (plain string); `gender` is the
     * older key (serialized array) left on earlier accounts. The current
     * key wins, so a member who changes their gender on the profile form
     * is never overridden by a stale older value; `gender` is only the
     * fallback. Every gender read should come through here.
     */
    public static function get_member_gender($user_id) {
        $current = trim((string) get_user_meta($user_id, 'gender_dropdown', true));
        if ($current !== '') {
            return $current;
        }
        $older = maybe_unserialize(get_user_meta($user_id, 'gender', true));
        if (is_array($older)) {
            $older = reset($older);
        }
        return is_string($older) ? trim($older) : '';
    }

    /** "SL2M" + "2027" -> "SL2M-2027". */
    public static function season_team_key($code, $season) {
        return trim($code) . '-' . intval($season);
    }

    /** "SL2M-2027" -> array('SL2M', '2027'); "SL2M" -> array('SL2M', null). */
    public static function split_team_key($key) {
        if (preg_match('/^(.*)-(\d{4})$/', trim((string) $key), $m)) {
            return array($m[1], $m[2]);
        }
        return array(trim((string) $key), null);
    }

    /**
     * The season a team list is wanted for when the caller has none in
     * hand: the admin's remembered season, else the current year.
     */
    public static function default_team_season() {
        $remembered = get_option('team_oversight_selected_season');
        return preg_match('/^\d{4}$/', (string) $remembered) ? strval($remembered) : date('Y');
    }

    /**
     * Raw registry entries for one season, keyed by BARE code. Falls back
     * to the un-suffixed global list when the season has none of its own.
     * $registry is the option array (names or meta).
     */
    private static function entries_for_season($registry, $season) {
        $seasoned = array();
        $bare = array();
        foreach ((array) $registry as $key => $value) {
            list($code, $key_season) = self::split_team_key($key);
            if ($key_season === null) {
                $bare[$code] = $value;
            } elseif ($key_season === strval(intval($season))) {
                $seasoned[$code] = $value;
            }
        }
        return !empty($seasoned) ? $seasoned : $bare;
    }

    /** Does the registry hold any entries suffixed for this season? */
    public static function season_has_teams($season) {
        foreach ((array) get_option('team_oversight_teams', array()) as $key => $name) {
            list(, $key_season) = self::split_team_key($key);
            if ($key_season === strval(intval($season))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Give a season its own team entries, copied from another season (or
     * from the bare global list). No-op when the season already has any,
     * so re-running is safe. Returns how many teams were copied.
     */
    public static function seed_season_teams($season, $from_season = null) {
        if (self::season_has_teams($season)) {
            return 0;
        }

        $names = get_option('team_oversight_teams', array());
        $meta = get_option('team_oversight_team_meta', array());
        $source_names = $from_season !== null ? self::entries_for_season($names, $from_season) : self::entries_for_season($names, 0);
        $source_meta = $from_season !== null ? self::entries_for_season($meta, $from_season) : self::entries_for_season($meta, 0);

        if (empty($source_names)) {
            return 0;
        }
        foreach ($source_names as $code => $name) {
            $key = self::season_team_key($code, $season);
            $names[$key] = $name;
            if (isset($source_meta[$code])) {
                $meta[$key] = $source_meta[$code];
            }
        }
        update_option('team_oversight_teams', $names);
        update_option('team_oversight_team_meta', $meta);
        return count($source_names);
    }

    /**
     * Team names for a season, keyed by bare code. Seeds the club's default
     * list (into the season) the first time nothing is configured at all.
     */
    public function get_teams($season = null) {
        $season = $season === null ? self::default_team_season() : $season;
        $all = get_option('team_oversight_teams', array());

        if (empty($all)) {
            $defaults = self::get_default_teams();
            $names = array();
            $meta = array();
            foreach ($defaults as $code => $team) {
                $key = self::season_team_key($code, $season);
                $names[$key] = $team['name'];
                $meta[$key] = array(
                    'gender' => $team['gender'],
                    'age_rule' => $team['age_rule'],
                    'shirts' => isset($team['shirts']) ? $team['shirts'] : 1,
                    'level' => isset($team['level']) ? intval($team['level']) : self::default_level_for_code($code),
                );
            }
            update_option('team_oversight_teams', $names);
            update_option('team_oversight_team_meta', $meta);
            $all = $names;
        }

        return self::entries_for_season($all, $season);
    }

    /**
     * Teams for a season with their gender and age-rule metadata:
     * code => array(name, gender: mens|womens|mixed, age_rule: ''|u19|u17|u15).
     * Gender falls back to a guess from the team code, and legacy max_age
     * metadata maps onto the matching rule.
     */
    public function get_teams_config($season = null) {
        $season = $season === null ? self::default_team_season() : $season;
        $names = $this->get_teams($season);
        $meta = self::entries_for_season(get_option('team_oversight_team_meta', array()), $season);

        $config = array();
        foreach ($names as $code => $name) {
            $team_meta = isset($meta[$code]) && is_array($meta[$code]) ? $meta[$code] : array();
            $gender = isset($team_meta['gender']) && in_array($team_meta['gender'], array('mens', 'womens', 'mixed'), true)
                ? $team_meta['gender']
                : self::derive_team_gender($code);

            $age_rule = '';
            if (isset($team_meta['age_rule']) && array_key_exists($team_meta['age_rule'], self::get_age_rules())) {
                $age_rule = $team_meta['age_rule'];
            } elseif (!empty($team_meta['max_age'])) {
                // Teams saved before age rules existed used a plain age number.
                $legacy_map = array(19 => 'u19', 18 => 'u19', 17 => 'u17', 16 => 'u17', 15 => 'u15', 14 => 'u15');
                $legacy_age = intval($team_meta['max_age']);
                $age_rule = isset($legacy_map[$legacy_age]) ? $legacy_map[$legacy_age] : '';
            }

            $config[$code] = array(
                'name' => $name,
                'gender' => $gender,
                'age_rule' => $age_rule,
                // Playing shirts a player must purchase for this team
                // (Premier teams 2, YSL 0 — supplied; default 1).
                'shirts' => isset($team_meta['shirts']) ? max(0, intval($team_meta['shirts'])) : 1,
                // Division level (1.76.0): the stored level, else the
                // default for the code; 0 = not set. Lower = higher division.
                'level' => (isset($team_meta['level']) && is_numeric($team_meta['level']))
                    ? max(0, min(20, intval($team_meta['level'])))
                    : self::default_level_for_code($code),
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