<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merging duplicate member accounts (1.71.0).
 *
 * One person, several WordPress accounts (usually re-registered with a new
 * email). The admin picks the account that lives on (the keeper); the other
 * accounts are folded into it and deleted. plan() works out what would
 * happen and writes nothing; apply() does it; undo() reverses it from the
 * backup kept in {prefix}team_account_merges.
 *
 * What a merge does, in this order (the order matters):
 *  1. Checks (blockers / confirmations), under a named lock, against a fresh
 *     plan whose hash must match the preview the admin saw.
 *  2. Shuts the extra accounts out: their sessions are destroyed and a
 *     short-lived flag refuses their logins until the merge is over.
 *  3. In one database transaction: profile fields onto the keeper (copy
 *     when the keeper's is empty, groups copied whole, unions for lists),
 *     roles, every row that names an extra account by user id (orders,
 *     coupon uses, club tables, comments...) re-pointed to the keeper, the
 *     WooCommerce Analytics rows re-synced, then a check that nothing still
 *     points at an extra, then the backup row. Commit.
 *  4. The extra accounts are deleted with wp_delete_user(extra, keeper),
 *     with Ultimate Member's "account deleted" email switched off.
 *
 * What it never does: change money. A merge re-points rows; it never
 * re-prices an invoice, moves a fee, merges two invoices or trial
 * applications, or writes a fee timeline change. When both sides hold club
 * records for the same season (invoices, team places, fee segments, trial
 * applications or fee timeline changes) the merge is refused, and the admin
 * sorts that season out first with the usual tools (Payment Management,
 * the Fee timeline card, Trial Applications). The keeper keeps its own
 * login email and password: an extra account's login stops working.
 *
 * Every row it changes is recorded (table, key, column, old and new value),
 * so undo() can put each one back, but only where it still holds the value
 * the merge wrote (anything changed since is left alone and reported).
 */
class TeamOversight_Account_Merge {

    const LOCK = 'murvc_account_merge';
    const BLOCK_TRANSIENT = 'murvc_merge_block_';
    const MERGED_META = 'murvc_merged_accounts';
    // Orders moved one by one through WooCommerce; above this, use WP-CLI.
    const MAX_ORDERS_WEB = 400;

    // Roles a merged-away account may hold. Anything else (administrator,
    // editor, shop manager...) is a staff account and is merged by hand.
    const MEMBER_ROLES = array('customer', 'subscriber', 'non-member', 'associate-member', 'full-member', 'life-member');
    const TIER_ROLES = array('life-member', 'full-member', 'associate-member');

    private static $hooks_added = false;

    /** Recorded changes for the merge in progress (see record()). */
    private static $changes = array();

    public static function register_hooks() {
        if (self::$hooks_added) {
            return;
        }
        self::$hooks_added = true;
        // Refuse logins for an account while it is being merged away.
        add_filter('authenticate', array(__CLASS__, 'block_login_while_merging'), 99, 1);
    }

    public static function block_login_while_merging($user) {
        if ($user instanceof WP_User && get_transient(self::BLOCK_TRANSIENT . $user->ID)) {
            return new WP_Error('murvc_merging', 'This account is being merged with another one. Please try again in a minute.');
        }
        return $user;
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'team_account_merges';
    }

    /** The merge journal: one row per merge, holding its summary and backup. */
    public static function table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE " . self::table() . " (
                id int(11) NOT NULL AUTO_INCREMENT,
                kept_user_id bigint(20) unsigned NOT NULL,
                merged_user_ids varchar(255) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'done',
                summary longtext DEFAULT NULL,
                backup longtext DEFAULT NULL,
                created_by bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                undone_by bigint(20) unsigned DEFAULT NULL,
                undone_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY kept_user_id (kept_user_id),
                KEY status (status)
            ) ENGINE=InnoDB $charset_collate;";
    }

    // ------------------------------------------------------------------
    // Club tables
    // ------------------------------------------------------------------

    /**
     * Club tables that hold a person by account AND email, with a season.
     * table => array(user column, email column ('' = leave the email as
     * history), name column, season column). A row belongs to an account
     * when user_id = ID, or user_id is empty and email = the account email.
     */
    private static function person_tables() {
        return array(
            'team_assignments' => array('user_id', 'email', '', 'season'),
            'team_invoices' => array('user_id', 'email', 'name', 'season'),
            'team_fee_segments' => array('user_id', 'email', '', 'season'),
            'trial_applications' => array('user_id', 'email', 'name', 'season'),
            // The email too, or a later account with the old address would
            // pick up this person's class history by email.
            'team_assignment_changes' => array('user_id', 'email', '', 'season'),
        );
    }

    /** Columns that hold an account id and are simply re-pointed. */
    private static function id_columns() {
        return array(
            array('team_invoice_payments', 'user_id'),
            array('team_memberships', 'user_id'),
            array('team_memberships', 'granted_by'),
            array('team_activity_log', 'subject_user_id'),
            array('team_activity_log', 'actor_id'),
            array('team_assignment_changes', 'recorded_by'),
            array('team_attendance', 'marked_by'),
            array('team_program_attendance', 'user_id'),
            array('team_program_attendance', 'marked_by'),
            array('team_trial_notes', 'author_id'),
            array('team_trial_selections', 'created_by'),
            array('team_trial_selections', 'offer_accepted_by'),
            array('team_invoices', 'fee_overridden_by'),
            array('team_invoices', 'payment_arrangement_by'),
        );
    }

    private static function table_exists($table) {
        global $wpdb;
        static $cache = array();
        if (!isset($cache[$table])) {
            $cache[$table] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        }
        return $cache[$table];
    }

    /** Rows of a person table that belong to an account (ids, seasons). */
    private static function person_rows($short, $user, $columns) {
        global $wpdb;
        $table = $wpdb->prefix . $short;
        if (!self::table_exists($table)) {
            return array();
        }
        list($ucol, $ecol, , $scol) = $columns;
        $email_col = $ecol !== '' ? $ecol : 'email';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, `$scol` AS season FROM `$table` WHERE `$ucol` = %d OR ((`$ucol` IS NULL OR `$ucol` = 0) AND `$email_col` = %s)",
            $user->ID, $user->user_email
        ));
    }

    // ------------------------------------------------------------------
    // Profile fields (usermeta)
    // ------------------------------------------------------------------

    /** Keys never copied: per-account login, security, caches and form junk. */
    private static function never_copy($key) {
        global $wpdb;
        static $exact = null;
        if ($exact === null) {
            $exact = array_flip(array(
                'session_tokens', 'default_password_nag', '_application_passwords', 'account_secret_hash',
                'account_secret_hash_expiry', 'password_rst_attempts', 'um_set_password_required', 'user_activation_key',
                'user_activation_status', 'um_account_secure_fields', 'um_member_directory_data', 'synced_gravatar_hashed_id',
                'synced_profile_photo', '_um_last_login_backup', 'last_update', 'full_name', 'nickname',
                'profile_photo_metadata_temp', 'submitted', 'form_id', 'timestamp', 'request', 'um_request', '_wpnonce',
                '_wp_http_referer', 'g-recaptcha-response', 'user_login', 'username', 'user_registered', 'user_email_address',
                'shipping_method', '_woocommerce_load_saved_cart_after_login', '_woocommerce_tracks_anon_id', 'admin_color',
                'use_ssl', 'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'show_admin_bar_front', 'locale',
                'dismissed_wp_pointers', 'show_welcome_panel', 'community-events-location', 'nav_menu_recently_edited',
                'team_oversight_selected_season', 'email_users_accept_notifications', 'email_users_accept_mass_emails',
                'acf_user_settings', 'account_status', 'description_html', '_wc_email_verified_' . rtrim($wpdb->get_blog_prefix(), '_'),
                '_wc_email_verification_key_' . rtrim($wpdb->get_blog_prefix(), '_'),
            ));
        }
        if (isset($exact[$key])) {
            return true;
        }
        $blog = preg_quote(rtrim($wpdb->get_blog_prefix(), '_'), '/');
        $patterns = array(
            '/^' . $blog . '_(?:capabilities|user_level)$/',
            '/^(?:_?itsec|_two_factor_)/',
            '/^(?:um_user_profile_url_slug_|um_email_as_username_)/',
            '/_metadata_temp$/',
            '/^_woocommerce_persistent_cart/',
            '/^_wc_order_attribution_/',
            '/^wc_(?:order_count|money_spent|last_order)_/',
            '/^_(?:order_count|money_spent|last_order)$/',
            '/^' . $blog . '_(?:ac_preferences_|user-settings|dashboard_quick_press_last_post_id$|persisted_preferences$|media_library_mode$)/',
            '/^(?:meta-box-order_|metaboxhidden_|closedpostboxes_|screen_layout_)/',
            '/^manage.*(?:columnshidden|allcolumns)$/',
            '/_per_page$/',
            '/^(?:ac_preferences_|wpforms_|woocommerce_admin_|sbi_|cff_|wpcode_|aioseo_|_aioseo_|bookly_|seen_more_|leadin_|cf_pointer|thwcfd_|wppb_|wp_mail_smtp_|_wc_plugin_framework_)/',
            '/^dismissed_.*_notice$/',
            '/^murvc_(?:coach|trial)_flash/',
        );
        foreach ($patterns as $p) {
            if (preg_match($p, $key)) {
                return true;
            }
        }
        return false;
    }

    /** Groups that only make sense together: copied whole, or not at all. */
    private static function key_groups() {
        return array(
            'Address' => array('street_address', 'City', 'postal_code', 'country'),
            'Degree' => array('institution1', 'degree1type', 'degree1startdate', 'degree1enddate'),
            'Second degree' => array('second_degree_check', 'institution2', 'degree2type', 'degree2startdate', 'degree2enddate', 'studentid2'),
            'Billing address' => array('billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country', 'billing_phone'),
            'Shipping address' => array('shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_phone'),
            'Playing top' => array('playing_top', 'playing_top_number', 'playing_top_size'),
        );
    }

    /** Single member-entered fields, copied when the keeper's is empty. */
    private static function copy_if_empty($key) {
        static $keys = null;
        if ($keys === null) {
            $keys = array_flip(array(
                'first_name', 'last_name', 'preferred_name', 'mobile_number', 'phone_number', 'description', 'birth_date',
                'StudentID', 'MUSEligibilityCategory', 'institution_other', 'institution1_33', 'VVLRego', 'Origin',
                'StudentOrigin', 'IsStudent', 'StudentCourse', 'StudentInst', 'StudentInt', 'StudentInt2',
                'StudentStartDate', 'StudentScholarship', 'StudentScholarship2', 'implied_mus_status',
                'secondary_user_email', 'junior_confirmation', 'mus_acknowledgement', 'club_rules_acknowledgement',
                'use_gdpr_agreement', 'use_terms_conditions_agreement', 'mu_sport_health_check',
                'mu_sport_covidsafe_campus', 'rtp_agreement', 'rtp_agreement_new', 'sex', 'Gender1',
                'team_2025', '_team_2025', 'fee_type_2025', '_fee_type_2025', 'discount_check_2025',
                '_discount_check_2025', 'top_number', '_top_number', '_team_history', 'murvc_shirt_credit',
                'profile_privacy', 'profile_noindex', 'hide_in_members', 'um_show_last_login',
            ));
        }
        return isset($keys[$key]) || preg_match('/^intended_?membership/i', $key);
    }

    private static function is_empty_value($values) {
        foreach ((array) $values as $v) {
            if (is_string($v) && trim($v) === '') {
                continue;
            }
            $u = maybe_unserialize($v);
            if (is_array($u)) {
                $flat = array_filter(array_map(function ($x) {
                    return is_scalar($x) ? trim((string) $x) : (empty($x) ? '' : 'x');
                }, $u), 'strlen');
                if (empty($flat)) {
                    continue;
                }
                return false;
            }
            if ($u !== null && $u !== false && trim((string) $u) !== '') {
                return false;
            }
        }
        return true;
    }

    /** All meta rows of an account: key => list of raw values. */
    private static function meta_rows($user_id) {
        global $wpdb;
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id", $user_id)) as $r) {
            $out[$r->meta_key][] = $r->meta_value;
        }
        return $out;
    }

    private static function short($value) {
        $v = maybe_unserialize($value);
        if (is_array($v)) {
            $v = implode(', ', array_map(function ($x) {
                return is_scalar($x) ? (string) $x : (is_array($x) ? implode('/', array_map('strval', array_filter($x, 'is_scalar'))) : '');
            }, $v));
        }
        $v = trim(wp_strip_all_tags((string) $v));
        return mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '...' : $v;
    }

    private static function digits($phone) {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($d) === 11 && strpos($d, '61') === 0) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        return $d;
    }

    private static function gender_of($meta) {
        foreach (array('gender_dropdown', 'gender') as $k) {
            if (!empty($meta[$k])) {
                $v = maybe_unserialize($meta[$k][0]);
                if (is_array($v)) {
                    $v = reset($v);
                }
                $v = trim((string) $v);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    private static function dob_of($meta) {
        $raw = isset($meta['birth_date'][0]) ? trim((string) $meta['birth_date'][0]) : '';
        $raw = str_replace(array('/', '.'), '-', $raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }

    /**
     * Work out the keeper's profile after the merge: returns
     * array('set' => key => list of values to write, 'copied' => key => text,
     * 'conflicts' => key => array(keeper, other), 'merged' => key => note,
     * 'not_copied' => keys left on the other accounts).
     */
    private static function plan_meta($keep, $extras) {
        $km = self::meta_rows($keep->ID);
        $set = array();
        $copied = array();
        $conflicts = array();
        $merged = array();
        $not_copied = array();
        $extra_meta = array();
        foreach ($extras as $e) {
            $extra_meta[$e->ID] = self::meta_rows($e->ID);
        }
        $current = function ($key) use (&$set, $km) {
            return isset($set[$key]) ? $set[$key] : (isset($km[$key]) ? $km[$key] : array());
        };

        // Groups first, so their keys aren't treated one by one.
        $grouped = array();
        foreach (self::key_groups() as $label => $keys) {
            foreach ($keys as $k) {
                $grouped[$k] = $label;
            }
            $keeper_empty = true;
            foreach ($keys as $k) {
                if (!self::is_empty_value($current($k))) {
                    $keeper_empty = false;
                    break;
                }
            }
            foreach ($extra_meta as $eid => $em) {
                $has = false;
                foreach ($keys as $k) {
                    if (isset($em[$k]) && !self::is_empty_value($em[$k])) {
                        $has = true;
                        break;
                    }
                }
                if (!$has) {
                    continue;
                }
                $summary = array();
                foreach ($keys as $k) {
                    if (isset($em[$k]) && !self::is_empty_value($em[$k])) {
                        $summary[] = self::short($em[$k][0]);
                    }
                }
                if ($keeper_empty) {
                    foreach ($keys as $k) {
                        if (isset($em[$k])) {
                            $set[$k] = $em[$k];
                        }
                    }
                    $copied[$label] = implode(', ', $summary) . " (from #$eid)";
                    $keeper_empty = false;
                } else {
                    $mine = array();
                    foreach ($keys as $k) {
                        $v = $current($k);
                        if (!self::is_empty_value($v)) {
                            $mine[] = self::short($v[0]);
                        }
                    }
                    if (implode('|', $mine) !== implode('|', $summary)) {
                        $conflicts[$label] = array(implode(', ', $mine), implode(', ', $summary) . " (#$eid)");
                    }
                }
            }
        }

        // Emergency contacts: union of whole contacts, two slots.
        $slots = array(
            array('emergency_contact_name', 'emergency_contact_number', 'emergency_contact_relationship'),
            array('emergency_contact_name_37', 'emergency_contact_number_38', 'emergency_contact_relationship_39'),
        );
        $contacts = array();
        $sources = array_merge(array($keep->ID => $km), $extra_meta);
        foreach ($sources as $sid => $m) {
            foreach ($slots as $slot) {
                $name = isset($m[$slot[0]][0]) ? trim((string) $m[$slot[0]][0]) : '';
                $num = isset($m[$slot[1]][0]) ? trim((string) $m[$slot[1]][0]) : '';
                $rel = isset($m[$slot[2]][0]) ? trim((string) $m[$slot[2]][0]) : '';
                if ($name === '' && $num === '') {
                    continue;
                }
                $id = self::digits($num) !== '' ? 'n' . self::digits($num) : 'm' . mb_strtolower($name);
                if (!isset($contacts[$id])) {
                    $contacts[$id] = array($name, $num, $rel, $sid);
                }
            }
        }
        $list = array_values($contacts);
        foreach ($slots as $i => $slot) {
            $grouped[$slot[0]] = $grouped[$slot[1]] = $grouped[$slot[2]] = 'Emergency contacts';
            if (!isset($list[$i])) {
                continue;
            }
            if ((int) $list[$i][3] !== (int) $keep->ID || $i > 0) {
                $was = array(
                    isset($km[$slot[0]][0]) ? (string) $km[$slot[0]][0] : '',
                    isset($km[$slot[1]][0]) ? (string) $km[$slot[1]][0] : '',
                    isset($km[$slot[2]][0]) ? (string) $km[$slot[2]][0] : '',
                );
                $now = array($list[$i][0], $list[$i][1], $list[$i][2]);
                if ($was !== $now) {
                    $set[$slot[0]] = array($now[0]);
                    $set[$slot[1]] = array($now[1]);
                    $set[$slot[2]] = array($now[2]);
                    $merged['Emergency contact ' . ($i + 1)] = trim($now[0] . ' ' . $now[1]) . ((int) $list[$i][3] !== (int) $keep->ID ? ' (from #' . $list[$i][3] . ')' : '');
                }
            }
        }
        if (count($list) > 2) {
            $extra_contacts = array();
            foreach (array_slice($list, 2) as $c) {
                $extra_contacts[] = trim($c[0] . ' ' . $c[1]);
            }
            $conflicts['Emergency contacts'] = array('two kept', 'not kept (only two fit): ' . implode('; ', $extra_contacts));
        }

        // Gender: one field under two keys; copy both rows when the keeper has none.
        $grouped['gender'] = $grouped['gender_dropdown'] = 'Gender';
        $kg = self::gender_of($km);
        foreach ($extra_meta as $eid => $em) {
            $eg = self::gender_of($em);
            if ($eg === '') {
                continue;
            }
            if ($kg === '') {
                foreach (array('gender_dropdown', 'gender') as $k) {
                    if (isset($em[$k])) {
                        $set[$k] = $em[$k];
                    }
                }
                $copied['Gender'] = $eg . " (from #$eid)";
                $kg = $eg;
            } elseif (strcasecmp($kg, $eg) !== 0) {
                $conflicts['Gender'] = array($kg, $eg . " (#$eid)");
            }
        }

        $kept_profile_year = isset($km['profile_confirmed_year'][0]) ? $km['profile_confirmed_year'][0] : null;
        $special = array('team_history', 'profile_confirmed_year', 'murvc_overdue_last_reminded', '_um_last_login',
            'wc_last_active', 'paying_customer', 'billing_email', 'wc_square_customer_id', 'profile_photo', 'cover_photo',
            self::MERGED_META);

        foreach ($extra_meta as $eid => $em) {
            foreach ($em as $key => $values) {
                if (isset($grouped[$key]) || in_array($key, $special, true) || preg_match('/^murvc_rtp_\d{4}$/', $key)) {
                    continue;
                }
                if (self::never_copy($key)) {
                    continue;
                }
                if (!self::copy_if_empty($key)) {
                    if (!self::is_empty_value($values)) {
                        $not_copied[$key] = true;
                    }
                    continue;
                }
                if (self::is_empty_value($values)) {
                    continue;
                }
                $mine = $current($key);
                if (self::is_empty_value($mine)) {
                    $set[$key] = $values;
                    $copied[$key] = self::short($values[0]) . " (from #$eid)";
                } elseif (self::short($mine[0]) !== self::short($values[0])) {
                    // Most private wins for Ultimate Member's privacy choices.
                    if ($key === 'profile_privacy' && maybe_unserialize($values[0]) === 'Only me') {
                        $set[$key] = $values;
                        $merged[$key] = 'Only me (the more private choice)';
                    } elseif ($key === 'profile_noindex' && (string) $values[0] === '1') {
                        $set[$key] = $values;
                        $merged[$key] = 'hidden from search (the more private choice)';
                    } elseif (!isset($conflicts[$key])) {
                        $conflicts[$key] = array(self::short($mine[0]), self::short($values[0]) . " (#$eid)");
                    }
                }
            }
        }

        // Lists and stamps.
        $history = array();
        $history_text = array();
        foreach ($sources as $sid => $m) {
            if (empty($m['team_history'])) {
                continue;
            }
            $v = maybe_unserialize($m['team_history'][0]);
            if (is_array($v)) {
                foreach ($v as $row) {
                    if (is_array($row) && isset($row['year'], $row['team'])) {
                        $history[(string) $row['year'] . '|' . (string) $row['team']] = array('year' => $row['year'], 'team' => $row['team']);
                    }
                }
            } elseif (trim((string) $v) !== '') {
                $history_text[] = (string) $v;
            }
        }
        if (!empty($history)) {
            ksort($history);
            $new = array_values($history);
            $old = isset($km['team_history'][0]) ? maybe_unserialize($km['team_history'][0]) : array();
            if (!is_array($old) || count($old) !== count($new)) {
                $set['team_history'] = array(maybe_serialize($new));
                $merged['team_history'] = count($new) . ' team history rows (combined)';
            }
        }
        if (!empty($history_text)) {
            $conflicts['team_history (old text)'] = array('kept as a note in the merge record', self::short(implode('; ', $history_text)));
        }

        foreach ($sources as $sid => $m) {
            foreach ($m as $key => $values) {
                if (!preg_match('/^murvc_rtp_\d{4}$/', $key)) {
                    continue;
                }
                $union = array();
                foreach ($sources as $m2) {
                    if (!empty($m2[$key])) {
                        $v = maybe_unserialize($m2[$key][0]);
                        $union = array_merge($union, is_array($v) ? $v : array());
                    }
                }
                $union = array_values(array_unique(array_map('strval', $union)));
                $old = isset($km[$key][0]) ? maybe_unserialize($km[$key][0]) : array();
                if (!is_array($old) || count($old) !== count($union)) {
                    $set[$key] = array(maybe_serialize($union));
                    $merged[$key] = implode(', ', $union);
                }
            }
        }

        foreach (array('murvc_overdue_last_reminded', '_um_last_login', 'wc_last_active') as $key) {
            $best = isset($km[$key][0]) ? (string) $km[$key][0] : '';
            $from = 0;
            foreach ($extra_meta as $eid => $em) {
                $v = isset($em[$key][0]) ? (string) $em[$key][0] : '';
                if ($v !== '' && ($best === '' || strcmp($v, $best) > 0)) {
                    $best = $v;
                    $from = $eid;
                }
            }
            if ($from) {
                $set[$key] = array($best);
            }
        }

        $paying = !empty($km['paying_customer'][0]);
        foreach ($extra_meta as $em) {
            if (!$paying && !empty($em['paying_customer'][0])) {
                $set['paying_customer'] = array('1');
                $paying = true;
            }
        }

        if (self::is_empty_value(isset($km['billing_email']) ? $km['billing_email'] : array())) {
            $set['billing_email'] = array($keep->user_email);
        }

        $square = array();
        foreach ($extra_meta as $eid => $em) {
            if (!empty($em['wc_square_customer_id'][0])) {
                $square[$eid] = $em['wc_square_customer_id'][0];
            }
        }
        if (self::is_empty_value(isset($km['wc_square_customer_id']) ? $km['wc_square_customer_id'] : array()) && count($square) === 1) {
            $set['wc_square_customer_id'] = array_values($square);
            $merged['wc_square_customer_id'] = 'Square customer from #' . key($square) . ' (its saved cards come too)';
        }

        // The annual profile check: the latest year confirmed, unless the
        // merged profile is missing what that year's check needed.
        $best_year = is_numeric($kept_profile_year) ? (int) $kept_profile_year : 0;
        foreach ($extra_meta as $em) {
            if (!empty($em['profile_confirmed_year'][0]) && is_numeric($em['profile_confirmed_year'][0])) {
                $best_year = max($best_year, (int) $em['profile_confirmed_year'][0]);
            }
        }
        if ($best_year && (string) $best_year !== (string) $kept_profile_year) {
            $mus = $current('MUSEligibilityCategory');
            $complete = !self::is_empty_value($mus) && $kg !== '';
            if ($complete || $best_year < (int) wp_date('Y')) {
                $set['profile_confirmed_year'] = array((string) $best_year);
                $merged['profile_confirmed_year'] = (string) $best_year;
            }
        }

        // The merge record (aliases), kept as a list on the keeper.
        $list = isset($km[self::MERGED_META][0]) ? maybe_unserialize($km[self::MERGED_META][0]) : array();
        $list = is_array($list) ? $list : array();
        foreach ($extras as $e) {
            $em = $extra_meta[$e->ID];
            $inner = isset($em[self::MERGED_META][0]) ? maybe_unserialize($em[self::MERGED_META][0]) : array();
            if (is_array($inner)) {
                $list = array_merge($list, $inner);
            }
            $list[] = array(
                'user_id' => (int) $e->ID,
                'user_login' => $e->user_login,
                'user_email' => $e->user_email,
                'display_name' => $e->display_name,
                'user_registered' => $e->user_registered,
                'merged_at' => current_time('mysql'),
                'merged_by' => get_current_user_id(),
            );
        }
        $set[self::MERGED_META] = array(maybe_serialize($list));

        return array(
            'set' => $set,
            'copied' => $copied,
            'conflicts' => $conflicts,
            'merged' => $merged,
            'not_copied' => array_keys($not_copied),
        );
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    private static function roles_of($user) {
        return array_values(array_map('strval', (array) $user->roles));
    }

    /** Orders of an account (every status, trash included). */
    private static function order_ids($user_id) {
        if (!function_exists('wc_get_orders')) {
            return array();
        }
        $statuses = array_merge(array_keys(wc_get_order_statuses()), array('trash', 'checkout-draft', 'auto-draft'));
        return array_map('intval', wc_get_orders(array(
            'customer_id' => (int) $user_id,
            'type' => 'shop_order',
            'status' => $statuses,
            'limit' => -1,
            'return' => 'ids',
        )));
    }

    private static function account_summary($user) {
        $meta = self::meta_rows($user->ID);
        return array(
            'id' => (int) $user->ID,
            'name' => $user->display_name,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'registered' => $user->user_registered,
            'roles' => self::roles_of($user),
            'status' => isset($meta['account_status'][0]) ? (string) $meta['account_status'][0] : '',
            'dob' => self::dob_of($meta),
        );
    }

    /**
     * Work out a merge without changing anything. $keep_id lives on;
     * $merge_ids are folded into it. Returns an array (see below) or a
     * WP_Error when the accounts can't even be looked at.
     */
    public static function plan($keep_id, $merge_ids) {
        global $wpdb;

        $keep_id = (int) $keep_id;
        $merge_ids = array_values(array_unique(array_filter(array_map('intval', (array) $merge_ids))));
        $merge_ids = array_values(array_diff($merge_ids, array($keep_id)));
        if (!$keep_id || empty($merge_ids)) {
            return new WP_Error('murvc_merge_input', 'Choose the account to keep and at least one other account.');
        }
        if (count($merge_ids) > 6) {
            return new WP_Error('murvc_merge_input', 'Merge at most six accounts into one at a time.');
        }
        $keep = get_userdata($keep_id);
        if (!$keep) {
            return new WP_Error('murvc_merge_input', "Account #$keep_id doesn't exist.");
        }
        $extras = array();
        foreach ($merge_ids as $id) {
            $u = get_userdata($id);
            if (!$u) {
                return new WP_Error('murvc_merge_input', "Account #$id doesn't exist.");
            }
            $extras[$id] = $u;
        }

        $blockers = array();
        $confirm = array();
        $warnings = array();
        $moves = array();

        // Who they are.
        $keep_meta = self::meta_rows($keep_id);
        $keep_status = isset($keep_meta['account_status'][0]) ? (string) $keep_meta['account_status'][0] : '';
        if (in_array($keep_status, array('inactive', 'rejected'), true)) {
            $blockers[] = "The account to keep (#$keep_id) is $keep_status in Ultimate Member. Approve it first, or keep another account.";
        }
        if ((int) $keep_id === get_current_user_id() || in_array(get_current_user_id(), $merge_ids, true)) {
            if (in_array(get_current_user_id(), $merge_ids, true)) {
                $blockers[] = "You can't merge away the account you're logged in with.";
            }
        }
        $kdob = self::dob_of($keep_meta);
        $extra_dobs = array();
        foreach ($extras as $e) {
            $roles = self::roles_of($e);
            $staff = array_diff($roles, self::MEMBER_ROLES);
            if (!empty($staff) || is_super_admin($e->ID)) {
                $blockers[] = "#{$e->ID} has a staff role (" . implode(', ', $staff ?: array('super admin')) . '). Staff accounts are merged by hand.';
            }
            $em = self::meta_rows($e->ID);
            $estatus = isset($em['account_status'][0]) ? (string) $em['account_status'][0] : '';
            if (in_array($estatus, array('inactive', 'rejected'), true)) {
                $confirm['status_' . $e->ID] = "#{$e->ID} was set to $estatus by an admin. Merge it anyway?";
            }
            $edob = self::dob_of($em);
            $extra_dobs[$e->ID] = $edob;
            if ($kdob !== '' && $edob !== '' && $kdob !== $edob) {
                $confirm['dob_' . $e->ID] = "Dates of birth differ: #$keep_id $kdob, #{$e->ID} $edob. Are these really the same person?";
            }
            // Ella and Bella with one birthday: twins look like duplicates.
            $kfirst = strtolower(trim((string) (isset($keep_meta['first_name'][0]) ? $keep_meta['first_name'][0] : '')));
            $efirst = strtolower(trim((string) (isset($em['first_name'][0]) ? $em['first_name'][0] : '')));
            if ($kfirst !== '' && $efirst !== '' && $kfirst !== $efirst) {
                $confirm['first_' . $e->ID] = "First names differ: #$keep_id \"{$keep_meta['first_name'][0]}\", #{$e->ID} \"{$em['first_name'][0]}\". Is this the same person, not a twin or sibling?";
            }
            if (!empty($em['session_tokens'][0])) {
                $warnings[] = "#{$e->ID} is logged in somewhere right now; the merge signs it out.";
            }
        }
        // With no birth date on the account kept, the others still have to agree.
        $dated = array_filter($extra_dobs, 'strlen');
        if ($kdob === '' && count(array_unique($dated)) > 1) {
            $parts = array();
            foreach ($dated as $id => $d) {
                $parts[] = "#$id $d";
            }
            $confirm['dob_mismatch'] = 'Dates of birth differ: ' . implode(', ', $parts) . '. Are these really the same person?';
        }

        // WooCommerce.
        $orders = array();
        foreach ($extras as $e) {
            $orders[$e->ID] = self::order_ids($e->ID);
        }
        $order_total = array_sum(array_map('count', $orders));
        $moves['Orders'] = $order_total;
        if ($order_total > self::MAX_ORDERS_WEB && !(defined('WP_CLI') && WP_CLI)) {
            $blockers[] = "$order_total orders to move is more than one page load can safely do. Use the WP-CLI command instead.";
        }
        $ids_in = implode(',', array_map('intval', $merge_ids));
        $moves['Coupon uses'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon' WHERE pm.meta_key = '_used_by' AND pm.meta_value IN ('" . implode("','", array_map('intval', $merge_ids)) . "')");
        $moves['Fee payment lines in orders'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_murvc_fee_payment_user' AND meta_value IN ('" . implode("','", array_map('intval', $merge_ids)) . "')");
        $moves['Comments and reviews'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id IN ($ids_in)");
        if (self::table_exists($wpdb->prefix . 'woocommerce_api_keys') && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_api_keys WHERE user_id IN ($ids_in)")) {
            $blockers[] = 'One of the other accounts owns WooCommerce REST API keys. Merge it by hand.';
        }
        if (self::table_exists($wpdb->prefix . 'woocommerce_payment_tokens')) {
            $tokens = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id IN ($ids_in)");
            if ($tokens) {
                $warnings[] = "$tokens saved card(s) on the other accounts only carry over when they belong to the same Square customer as the account kept; otherwise they are removed from the website (not from Square).";
            }
        }
        foreach ($extras as $e) {
            $req = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'user_request' AND post_status IN ('request-pending','request-confirmed') AND post_title = %s", $e->user_email));
            if ($req) {
                $blockers[] = "#{$e->ID} has a personal-data request (export or erasure) waiting. Finish it first.";
            }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s AND session_expiry > %d", (string) $e->ID, time()))) {
                $warnings[] = "#{$e->ID} has a shopping cart open; it will be emptied.";
            }
            $restricted = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'customer_email' AND meta_value LIKE %s", '%' . $wpdb->esc_like($e->user_email) . '%'));
            if (!empty($restricted)) {
                $warnings[] = 'Coupon(s) ' . implode(', ', array_map('intval', $restricted)) . " are limited to #{$e->ID}'s email; add the kept email to them if needed.";
            }
        }

        // Club records: refuse when two accounts hold the same season.
        $labels = array(
            'team_assignments' => 'team places',
            'team_invoices' => 'club fee invoices',
            'team_fee_segments' => 'fee history',
            'trial_applications' => 'trial applications',
            'team_assignment_changes' => 'fee timeline changes',
        );
        $all_users = array($keep_id => $keep) + $extras;
        $by_table = array();
        foreach (self::person_tables() as $short => $cols) {
            $count = 0;
            foreach ($all_users as $uid => $u) {
                foreach (self::person_rows($short, $u, $cols) as $row) {
                    $by_table[$short][(string) $row->season][$uid] = true;
                    if ($uid !== $keep_id) {
                        $count++;
                    }
                }
            }
            $moves[ucfirst($labels[$short])] = $count;
        }
        // Fee history and fee timeline changes are never deleted (removing a
        // team place leaves them), so when two accounts both hold them for a
        // season nothing in the admin pages can clear the clash: say so,
        // rather than send the admin to tools that can't help (and so no
        // other advice for that season either).
        $history = array('team_fee_segments', 'team_assignment_changes');
        $history_clash = array();
        foreach ($history as $t) {
            foreach (isset($by_table[$t]) ? $by_table[$t] : array() as $season => $who) {
                if (count($who) > 1) {
                    $history_clash[$season] = array_unique(array_merge(isset($history_clash[$season]) ? $history_clash[$season] : array(), array_keys($who)));
                }
            }
        }
        foreach ($history_clash as $season => $who) {
            $blockers[] = 'Both #' . implode(' and #', $who) . " have $season fee history (from team places, now or in the past). Two accounts' fee history for one season can't be combined, and it can't be cleared from the admin pages, so these accounts can't be merged here. Leave them as they are, or have the website maintainer merge them by hand.";
        }
        foreach ($by_table as $short => $seasons) {
            if (in_array($short, $history, true)) {
                continue;
            }
            foreach ($seasons as $season => $who) {
                if (count($who) > 1 && !isset($history_clash[$season])) {
                    $blockers[] = 'Both #' . implode(' and #', array_keys($who)) . " have $season {$labels[$short]}. Sort that season out first (Payment Management, the Fee timeline card or Trial Applications), so only one account holds it.";
                }
            }
        }
        $blockers = array_values(array_unique($blockers));
        foreach (self::id_columns() as $pair) {
            list($short, $col) = $pair;
            $table = $wpdb->prefix . $short;
            if (!self::table_exists($table)) {
                continue;
            }
            $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE `$col` IN ($ids_in)");
            if ($n) {
                $label = array(
                    'team_invoice_payments' => 'Fee payments recorded',
                    'team_memberships' => 'Membership grants',
                    'team_activity_log' => 'Activity log lines',
                    'team_program_attendance' => 'Club program ticks',
                    'team_trial_notes' => 'Trial notes written',
                    'team_trial_selections' => 'Trial verdicts recorded',
                );
                $key = isset($label[$short]) && $col !== 'granted_by' && $col !== 'actor_id' && $col !== 'marked_by' ? $label[$short] : 'Other club records (staff columns)';
                $moves[$key] = (isset($moves[$key]) ? $moves[$key] : 0) + $n;
            }
        }
        $att = $wpdb->prefix . 'team_attendance';
        if (self::table_exists($att)) {
            $n = 0;
            foreach ($extras as $e) {
                $n += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$att` WHERE user_id = %d OR person_key = %s OR ((user_id IS NULL OR user_id = 0) AND person_key = %s)", $e->ID, 'u' . $e->ID, strtolower($e->user_email)));
            }
            $moves['Training attendance ticks'] = $n;
        }
        $acc = $wpdb->prefix . 'team_accreditations';
        if (self::table_exists($acc)) {
            $has_keep = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$acc` WHERE email = %s", $keep->user_email));
            $n = 0;
            foreach ($extras as $e) {
                $mine = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$acc` WHERE email = %s", $e->user_email));
                if ($mine && ($has_keep || $n)) {
                    $blockers[] = 'More than one of these accounts has a Volleyball Victoria accreditation row. Sort that out first.';
                }
                $n += $mine;
            }
            $moves['VV accreditation rows'] = $n;
        }
        foreach (array('team_oversight_committee_access') as $option) {
            $v = get_option($option);
            if (is_array($v)) {
                foreach ($merge_ids as $id) {
                    if (isset($v[$id])) {
                        $moves['Committee access'] = 1;
                    }
                }
            }
        }

        // Other plugins with member data must say how they merge.
        $rsl = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'rsl_') . '%'));
        if (!empty($rsl) && !has_action('murvc_account_merge_apply')) {
            $blockers[] = 'The Reds Social League tables exist but nothing says how to merge their rows yet.';
        }
        $extra_blockers = apply_filters('murvc_account_merge_blockers', array(), $keep_id, $merge_ids);
        if (is_array($extra_blockers)) {
            $blockers = array_merge($blockers, $extra_blockers);
        }

        // Roles after.
        $roles_before = self::roles_of($keep);
        $roles_after = $roles_before;
        foreach ($extras as $e) {
            foreach (self::roles_of($e) as $r) {
                if (in_array($r, array('customer', 'subscriber'), true) && !in_array($r, $roles_after, true)) {
                    $roles_after[] = $r;
                }
            }
        }
        if (!in_array('customer', $roles_after, true)) {
            $roles_after[] = 'customer';
        }
        $tier = self::merged_tier($keep, $extras);
        $roles_after = array_values(array_diff($roles_after, self::TIER_ROLES));
        if ($tier) {
            $roles_after[] = $tier;
            $roles_after = array_values(array_diff($roles_after, array('non-member')));
        }

        $profile = self::plan_meta($keep, $extras);

        $photo = '';
        if (self::is_empty_value(isset($keep_meta['profile_photo']) ? $keep_meta['profile_photo'] : array())) {
            foreach ($extras as $e) {
                if (self::photo_files($e->ID)) {
                    $photo = "Profile photo from #{$e->ID}";
                    break;
                }
            }
        }

        $plan = array(
            'keep' => self::account_summary($keep),
            'merge' => array_values(array_map(array(__CLASS__, 'account_summary'), $extras)),
            'blockers' => array_values(array_unique($blockers)),
            'confirm' => $confirm,
            'warnings' => array_values(array_unique($warnings)),
            'moves' => array_filter($moves),
            'profile' => array(
                'copied' => $profile['copied'],
                'merged' => $profile['merged'],
                'conflicts' => $profile['conflicts'],
                'not_copied' => $profile['not_copied'],
                'photo' => $photo,
            ),
            'roles_before' => $roles_before,
            'roles_after' => array_values(array_unique($roles_after)),
            'login' => array(
                'keeps' => array($keep->user_login, $keep->user_email),
                'stops' => array_values(array_unique(array_merge(...array_map(function ($e) {
                    return array($e->user_login, $e->user_email);
                }, array_values($extras))))),
            ),
            'orders' => $orders,
        );
        $plan['hash'] = md5(wp_json_encode(array(
            $keep_id, $merge_ids, $plan['blockers'], array_keys($plan['confirm']), $plan['moves'],
            array_keys($profile['set']), $plan['roles_after'],
        )));
        return $plan;
    }

    /** The highest tier after a merge: from the merged grants, else the highest tier role held. */
    private static function merged_tier($keep, $extras) {
        global $wpdb;
        $ids = array_merge(array((int) $keep->ID), array_map('intval', array_keys($extras)));
        $in = implode(',', $ids);
        $today = current_time('Y-m-d');
        $grants = $wpdb->get_col($wpdb->prepare("SELECT tier FROM {$wpdb->prefix}team_memberships WHERE user_id IN ($in) AND start_date <= %s AND end_date >= %s", $today, $today));
        $has_any = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}team_memberships WHERE user_id IN ($in)");
        foreach (self::TIER_ROLES as $tier) {
            if (in_array($tier, $grants, true)) {
                return $tier;
            }
        }
        if ($has_any) {
            return null;
        }
        $held = array();
        foreach (array_merge(array($keep), array_values($extras)) as $u) {
            $held = array_merge($held, self::roles_of($u));
        }
        foreach (self::TIER_ROLES as $tier) {
            if (in_array($tier, $held, true)) {
                return $tier;
            }
        }
        return null;
    }

    private static function photo_dir($user_id) {
        $up = wp_upload_dir();
        return trailingslashit($up['basedir']) . 'ultimatemember/' . (int) $user_id . '/';
    }

    /** The account's profile photo files (name => base64), or empty. */
    private static function photo_files($user_id) {
        $dir = self::photo_dir($user_id);
        $out = array();
        foreach ((array) glob($dir . 'profile_photo*') as $f) {
            if (is_file($f) && filesize($f) < 5 * MB_IN_BYTES) {
                $out[basename($f)] = base64_encode((string) file_get_contents($f));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Applying
    // ------------------------------------------------------------------

    /** Remember one changed value so undo can put it back. */
    private static function record($table, $pk_col, $pk, $col, $old, $new) {
        self::$changes[] = array($table, $pk_col, (string) $pk, $col, $old, $new);
    }

    /** UPDATE one column on the given primary keys, recording each row. */
    private static function repoint($table, $col, $pk_col, $rows, $new) {
        global $wpdb;
        foreach ($rows as $row) {
            $old = $row[$col];
            if ((string) $old === (string) $new) {
                continue;
            }
            $done = $wpdb->update($table, array($col => $new), array($pk_col => $row[$pk_col]));
            if ($done === false) {
                throw new Exception("Could not update $table #{$row[$pk_col]}.");
            }
            self::record($table, $pk_col, $row[$pk_col], $col, $old, $new);
        }
    }

    /** One entry inside an option's array by key path (the whole value for array()); null when absent. */
    private static function option_at($value, $path) {
        foreach ($path as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }
        return $value;
    }

    /** $value with the entry at $path set to $new (removed when $new is null). */
    private static function option_set($value, $path, $new) {
        if (!$path) {
            return $new;
        }
        $k = array_shift($path);
        $value = is_array($value) ? $value : array();
        if (!$path) {
            if ($new === null) {
                unset($value[$k]);
            } else {
                $value[$k] = $new;
            }
            return $value;
        }
        $value[$k] = self::option_set(isset($value[$k]) ? $value[$k] : array(), $path, $new);
        return $value;
    }

    /**
     * Change one entry of an option and record it, so undo puts back that
     * entry alone (and only while it still holds what the merge wrote).
     */
    private static function change_option_entry($option, $path, $new, &$backup) {
        $all = get_option($option);
        $before = self::option_at($all, $path);
        if ($before === $new) {
            return;
        }
        if (update_option($option, self::option_set($all, $path, $new)) === false) {
            throw new Exception("Could not update the $option setting.");
        }
        $backup['option_entries'][] = array($option, $path, $before, $new);
    }

    /**
     * Merge $merge_ids into $keep_id. $confirmed lists the confirmation
     * codes the admin ticked; $expected_hash is the preview's hash (refused
     * if the situation changed since). Returns array('id' => merge id,
     * 'summary' => ...) or a WP_Error.
     */
    public static function apply($keep_id, $merge_ids, $confirmed = array(), $expected_hash = null) {
        global $wpdb;

        if (!current_user_can('manage_options') && !(defined('WP_CLI') && WP_CLI)) {
            return new WP_Error('murvc_merge_cap', 'Not allowed.');
        }
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', self::lock_name()))) {
            return new WP_Error('murvc_merge_lock', 'Another merge is running. Try again in a minute.');
        }
        try {
            return self::apply_locked($keep_id, $merge_ids, (array) $confirmed, $expected_hash);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
        }
    }

    private static function lock_name() {
        global $wpdb;
        return self::LOCK . '_' . $wpdb->prefix;
    }

    private static function apply_locked($keep_id, $merge_ids, $confirmed, $expected_hash) {
        global $wpdb;

        $plan = self::plan($keep_id, $merge_ids);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if ($expected_hash !== null && $expected_hash !== $plan['hash']) {
            return new WP_Error('murvc_merge_changed', 'Something changed since the preview. Look at the preview again before merging.');
        }
        if (!empty($plan['blockers'])) {
            return new WP_Error('murvc_merge_blocked', 'This merge is blocked: ' . implode(' ', $plan['blockers']));
        }
        $missing = array_diff(array_keys($plan['confirm']), $confirmed);
        if (!empty($missing)) {
            return new WP_Error('murvc_merge_confirm', 'Tick the confirmation(s) first: ' . implode(' ', array_intersect_key($plan['confirm'], array_flip($missing))));
        }

        $keep = get_userdata($keep_id);
        $extras = array();
        foreach ($plan['merge'] as $m) {
            $extras[$m['id']] = get_userdata($m['id']);
        }

        // Shut the extra accounts out for the duration.
        foreach ($extras as $e) {
            set_transient(self::BLOCK_TRANSIENT . $e->ID, 1, 10 * MINUTE_IN_SECONDS);
            if (class_exists('WP_Session_Tokens')) {
                WP_Session_Tokens::get_instance($e->ID)->destroy_all();
            }
        }

        // Backup of the accounts that will be deleted, and of the keeper's profile.
        $backup = array('users' => array(), 'usermeta' => array(), 'photos' => array(), 'keep' => array(), 'lookup' => array(), 'deleted_rows' => array(), 'inserted' => array(), 'option_entries' => array(), 'max_ids' => array());
        foreach ($extras as $e) {
            $backup['users'][$e->ID] = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID = %d", $e->ID), ARRAY_A);
            $backup['usermeta'][$e->ID] = $wpdb->get_results($wpdb->prepare("SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key <> 'session_tokens' ORDER BY umeta_id", $e->ID), ARRAY_A);
            $backup['photos'][$e->ID] = self::photo_files($e->ID);
        }
        $backup['keep']['user'] = $wpdb->get_row($wpdb->prepare("SELECT user_registered, display_name FROM {$wpdb->users} WHERE ID = %d", $keep_id), ARRAY_A);
        $backup['keep']['photos'] = self::photo_files($keep_id);
        $keep_meta_before = $wpdb->get_results($wpdb->prepare("SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key <> 'session_tokens' ORDER BY umeta_id", $keep_id), ARRAY_A);
        $backup['keep']['usermeta'] = $keep_meta_before;

        self::$changes = array();
        $summary = array('moves' => $plan['moves'], 'orders' => array(), 'profile' => $plan['profile'], 'roles_before' => $plan['roles_before'], 'roles_after' => $plan['roles_after'], 'notes' => array());
        // Who was involved, as they were: Merge history shows and searches
        // the merged accounts by these once they are gone.
        foreach (array_merge(array($plan['keep']), $plan['merge']) as $p) {
            $summary['people'][$p['id']] = array('name' => $p['name'], 'email' => $p['email'], 'login' => $p['login']);
        }

        // The newest club row ids now: undo refuses if the kept account has
        // rows made after the merge in a season the merge moved.
        foreach (array_merge(array_keys(self::person_tables()), array('team_invoice_payments')) as $short) {
            if (self::table_exists($wpdb->prefix . $short)) {
                $backup['max_ids'][$short] = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM `{$wpdb->prefix}$short`");
            }
        }

        $wpdb->query('START TRANSACTION');
        try {
            self::apply_profile($keep, $extras, $plan, $backup, $summary);
            self::apply_club_rows($keep, $extras, $backup);
            if (class_exists('TeamOversight_Duplicates')) {
                // "Not the same person" decisions follow the person, backed up.
                $dec = TeamOversight_Duplicates::rekey_decisions($keep_id, array_keys($extras));
                $dtable = TeamOversight_Duplicates::decisions_table();
                foreach ($dec['deleted'] as $r) {
                    $backup['deleted_rows'][$dtable][] = $r;
                }
                foreach ($dec['inserted'] as $id) {
                    $backup['inserted'][$dtable][] = $id;
                }
            }
            self::apply_woocommerce($keep, $extras, $plan, $backup, $summary);
            do_action('murvc_account_merge_apply', $keep_id, array_keys($extras), self::$changes);
            self::verify($keep, $extras);
            // Saved with the record now, so undo has it even if this request
            // dies after the commit; refreshed once the merge is complete.
            self::record_keeper_changes($keep_id, $keep_meta_before, $backup);
            clean_user_cache($keep_id);
            $summary['roles_final'] = self::roles_of(new WP_User($keep_id));

            $wpdb->insert(self::table(), array(
                'kept_user_id' => $keep_id,
                'merged_user_ids' => implode(',', array_keys($extras)),
                'status' => 'done',
                'summary' => wp_json_encode($summary),
                'backup' => wp_json_encode(array_merge($backup, array('changes' => self::$changes))),
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ));
            $merge_id = (int) $wpdb->insert_id;
            if (!$merge_id) {
                throw new Exception('Could not save the merge record: ' . $wpdb->last_error);
            }
            $wpdb->query('COMMIT');
        } catch (Exception $ex) {
            $wpdb->query('ROLLBACK');
            // Object-cache entries written inside the rolled-back transaction are stale.
            wp_cache_flush();
            foreach ($extras as $e) {
                delete_transient(self::BLOCK_TRANSIENT . $e->ID);
            }
            error_log('MURVC account merge into user #' . (int) $keep_id . ' rolled back: ' . $ex->getMessage());
            return new WP_Error('murvc_merge_failed', 'Nothing was changed: ' . $ex->getMessage());
        }
        // Rows were re-pointed with SQL (order item meta, comments, club
        // tables), and other requests may have cached the old values while
        // the transaction ran: start the object cache afresh, as undo does.
        wp_cache_flush();

        // After the commit: the photo file, then delete the extra accounts.
        if ($plan['profile']['photo'] !== '') {
            self::copy_photo($keep_id, $extras, $summary);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $blocked_mail = array();
        foreach ($extras as $e) {
            $blocked_mail[] = strtolower($e->user_email);
        }
        $mail_guard = function ($return, $atts) use ($blocked_mail) {
            $to = is_array($atts['to']) ? $atts['to'] : explode(',', (string) $atts['to']);
            foreach ($to as $addr) {
                if (in_array(strtolower(trim($addr)), $blocked_mail, true)) {
                    return false;
                }
            }
            return $return;
        };
        add_filter('pre_wp_mail', $mail_guard, 10, 2);
        if (function_exists('UM')) {
            UM()->user()->send_mail_on_delete = false;
        }
        foreach ($extras as $e) {
            if (!wp_delete_user($e->ID, $keep_id)) {
                $summary['notes'][] = "Account #{$e->ID} could not be deleted; it has no data left, so delete it by hand.";
            }
            delete_transient(self::BLOCK_TRANSIENT . $e->ID);
        }
        remove_filter('pre_wp_mail', $mail_guard, 10);
        if (function_exists('um_reset_user')) {
            um_reset_user();
        }

        self::after_merge($keep_id, array_keys($extras), $summary);

        // Now with the photo and the role sync too.
        self::record_keeper_changes($keep_id, $keep_meta_before, $backup);
        $wpdb->update(self::table(), array(
            'summary' => wp_json_encode($summary),
            'backup' => wp_json_encode(array_merge($backup, array('changes' => self::$changes))),
        ), array('id' => $merge_id));

        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add('account_merged', sprintf(
                'Merged account(s) #%s into #%d: %d order(s) moved, %d profile field(s) copied. Merge record %d.',
                implode(', #', array_keys($extras)), $keep_id, isset($plan['moves']['Orders']) ? $plan['moves']['Orders'] : 0,
                count($plan['profile']['copied']), $merge_id
            ), array('user_id' => $keep_id));
        }
        do_action('murvc_account_merged', $keep_id, array_keys($extras), $merge_id);

        return array('id' => $merge_id, 'summary' => $summary);
    }

    private static function apply_profile($keep, $extras, $plan, &$backup, &$summary) {
        global $wpdb;
        $profile = self::plan_meta($keep, $extras);
        foreach ($profile['set'] as $key => $values) {
            delete_user_meta($keep->ID, $key);
            foreach ($values as $v) {
                // add_user_meta() unslashes, so slash first to keep backslashes.
                add_user_meta($keep->ID, $key, wp_slash(maybe_unserialize($v)));
            }
        }
        $summary['profile_keys_written'] = array_keys($profile['set']);

        // Names: keep display_name in step if a name was filled in.
        if (isset($profile['set']['first_name']) || isset($profile['set']['last_name'])) {
            $first = (string) get_user_meta($keep->ID, 'first_name', true);
            $last = (string) get_user_meta($keep->ID, 'last_name', true);
            $display = trim($first . ' ' . $last);
            if ($display !== '') {
                $wpdb->update($wpdb->users, array('display_name' => $display), array('ID' => $keep->ID));
            }
        }

        // Earliest join date.
        $earliest = $keep->user_registered;
        foreach ($extras as $e) {
            if (strcmp($e->user_registered, $earliest) < 0) {
                $earliest = $e->user_registered;
            }
        }
        if ($earliest !== $keep->user_registered) {
            $wpdb->update($wpdb->users, array('user_registered' => $earliest), array('ID' => $keep->ID));
        }

        // Ultimate Member status: approve quietly when another account was approved.
        $kstatus = (string) get_user_meta($keep->ID, 'account_status', true);
        if (in_array($kstatus, array('awaiting_email_confirmation', 'awaiting_admin_review', 'checkmail', 'pending'), true)) {
            $any_approved = false;
            foreach ($extras as $e) {
                if ((string) get_user_meta($e->ID, 'account_status', true) === 'approved') {
                    $any_approved = true;
                }
            }
            if ($any_approved && function_exists('UM')) {
                UM()->common()->users()->approve($keep->ID, true, true);
                UM()->common()->users()->reset_activation_link($keep->ID);
                $summary['notes'][] = 'The kept account had never confirmed its email; it is now approved (another account was).';
            }
        }

        // Roles.
        $user = new WP_User($keep->ID);
        foreach ($plan['roles_after'] as $r) {
            if (!in_array($r, (array) $user->roles, true) && !in_array($r, self::TIER_ROLES, true)) {
                $user->add_role($r);
            }
        }
        if (in_array('non-member', $plan['roles_after'], true) === false && in_array('non-member', (array) $user->roles, true)) {
            $user->remove_role('non-member');
        }
    }

    private static function apply_club_rows($keep, $extras, &$backup) {
        global $wpdb;

        foreach (self::person_tables() as $short => $cols) {
            $table = $wpdb->prefix . $short;
            if (!self::table_exists($table)) {
                continue;
            }
            list($ucol, $ecol, $ncol) = $cols;
            foreach ($extras as $e) {
                $email_col = $ecol !== '' ? $ecol : 'email';
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM `$table` WHERE `$ucol` = %d OR ((`$ucol` IS NULL OR `$ucol` = 0) AND `$email_col` = %s)",
                    $e->ID, $e->user_email
                ), ARRAY_A);
                self::repoint($table, $ucol, 'id', $rows, $keep->ID);
                if ($ecol !== '') {
                    self::repoint($table, $ecol, 'id', $rows, $keep->user_email);
                }
                if ($ncol !== '') {
                    self::repoint($table, $ncol, 'id', $rows, $keep->display_name);
                }
            }
        }

        $ids_in = implode(',', array_map('intval', array_keys($extras)));
        foreach (self::id_columns() as $pair) {
            list($short, $col) = $pair;
            $table = $wpdb->prefix . $short;
            if (!self::table_exists($table)) {
                continue;
            }
            $rows = $wpdb->get_results("SELECT id, `$col` FROM `$table` WHERE `$col` IN ($ids_in)", ARRAY_A);
            self::repoint($table, $col, 'id', $rows, $keep->ID);
        }

        // Training attendance: re-key to the keeper; drop a duplicate tick
        // for the same team and session (the keeper's is kept).
        $att = $wpdb->prefix . 'team_attendance';
        if (self::table_exists($att)) {
            foreach ($extras as $e) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM `$att` WHERE user_id = %d OR person_key = %s OR ((user_id IS NULL OR user_id = 0) AND person_key = %s)",
                    $e->ID, 'u' . $e->ID, strtolower($e->user_email)
                ), ARRAY_A);
                foreach ($rows as $row) {
                    $clash = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM `$att` WHERE person_key = %s AND team = %s AND session_date = %s AND id <> %d",
                        'u' . $keep->ID, $row['team'], $row['session_date'], $row['id']
                    ));
                    if ($clash) {
                        $backup['deleted_rows'][$att][] = $row;
                        $wpdb->delete($att, array('id' => $row['id']));
                        continue;
                    }
                    self::repoint($att, 'person_key', 'id', array($row), 'u' . $keep->ID);
                    self::repoint($att, 'user_id', 'id', array($row), $keep->ID);
                    self::repoint($att, 'email', 'id', array($row), $keep->user_email);
                }
            }
        }

        $acc = $wpdb->prefix . 'team_accreditations';
        if (self::table_exists($acc)) {
            foreach ($extras as $e) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, email FROM `$acc` WHERE email = %s", $e->user_email), ARRAY_A);
                self::repoint($acc, 'email', 'id', $rows, $keep->user_email);
            }
        }

        // Committee access: the higher level wins. Each entry is changed and
        // recorded on its own, so undo never touches anyone else's access.
        $access = get_option('team_oversight_committee_access');
        if (is_array($access)) {
            foreach ($extras as $e) {
                if (isset($access[$e->ID])) {
                    $level = $access[$e->ID];
                    $mine = self::option_at(get_option('team_oversight_committee_access'), array($keep->ID));
                    if ($mine === null || $level === 'edit') {
                        self::change_option_entry('team_oversight_committee_access', array($keep->ID), $level, $backup);
                    }
                    self::change_option_entry('team_oversight_committee_access', array($e->ID), null, $backup);
                }
            }
        }

        // Club Program supervisors: each program's list, and the older club-wide one.
        $merged = array_map('intval', array_keys($extras));
        $swap = function ($ids) use ($merged, $keep) {
            $out = array();
            foreach ((array) $ids as $id) {
                $out[] = in_array((int) $id, $merged, true) ? (int) $keep->ID : (int) $id;
            }
            return array_values(array_unique($out));
        };
        $programs = get_option('team_oversight_programs');
        if (is_array($programs)) {
            foreach ($programs as $key => $program) {
                if (!empty($program['supervisors']) && is_array($program['supervisors']) && array_intersect(array_map('intval', $program['supervisors']), $merged)) {
                    self::change_option_entry('team_oversight_programs', array($key, 'supervisors'), $swap($program['supervisors']), $backup);
                }
            }
        }
        $club_wide = get_option('team_oversight_program_supervisors');
        if (is_array($club_wide) && array_intersect(array_map('intval', $club_wide), $merged)) {
            self::change_option_entry('team_oversight_program_supervisors', array(), $swap($club_wide), $backup);
        }

        if (class_exists('TeamOversight_Fee_Timeline')) {
            TeamOversight_Fee_Timeline::flush_cache();
        }
    }

    private static function apply_woocommerce($keep, $extras, $plan, &$backup, &$summary) {
        global $wpdb;
        if (!function_exists('wc_get_order')) {
            return;
        }

        // Make sure the keeper is an Analytics customer before syncing orders.
        $user = new WP_User($keep->ID);
        if (!in_array('customer', (array) $user->roles, true)) {
            $user->add_role('customer');
        }
        $store = '\Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore';
        $sched = '\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler';
        $keep_cid = class_exists($store) ? $store::get_customer_id_by_user_id($keep->ID) : 0;
        if (class_exists($store) && !$keep_cid) {
            $store::update_registered_customer($keep->ID);
            $keep_cid = $store::get_customer_id_by_user_id($keep->ID);
            if (!$keep_cid) {
                // Without it the moved orders would drop out of Analytics (and
                // the club's kit and program counts) once the old row is deleted.
                throw new Exception('WooCommerce Analytics would not take the kept account as a customer.');
            }
        }

        $held = self::hold_last_active(array($keep->ID));
        foreach ($extras as $e) {
            $moved = array();
            foreach ($plan['orders'][$e->ID] as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order || (int) $order->get_customer_id() !== (int) $e->ID) {
                    continue;
                }
                $order->set_customer_id($keep->ID);
                $order->save();
                $order->add_order_note(sprintf('Customer account #%d merged into #%d.', $e->ID, $keep->ID), 0, false);
                self::record('order', 'id', $order_id, 'customer_id', $e->ID, $keep->ID);
                $moved[] = $order_id;
            }
            $summary['orders'][$e->ID] = $moved;

            // Analytics: re-sync the moved orders and their refunds, then
            // anything else (guest orders matched by email) on the old row.
            if (class_exists($sched) && method_exists($sched, 'import')) {
                foreach ($moved as $order_id) {
                    $sched::import($order_id);
                    $order = wc_get_order($order_id);
                    if ($order) {
                        foreach ($order->get_refunds() as $refund) {
                            $sched::import($refund->get_id());
                        }
                    }
                }
            }
            $old_cid = class_exists($store) ? $store::get_customer_id_by_user_id($e->ID) : 0;
            if ($old_cid && $keep_cid) {
                $backup['lookup'][$e->ID] = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_customer_lookup WHERE customer_id = %d", $old_cid), ARRAY_A);
                foreach (array('wc_order_stats' => 'order_id', 'wc_order_product_lookup' => 'order_item_id') as $short => $pk) {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT `$pk`, customer_id FROM {$wpdb->prefix}$short WHERE customer_id = %d", $old_cid), ARRAY_A);
                    self::repoint($wpdb->prefix . $short, 'customer_id', $pk, $rows, $keep_cid);
                }
            }

            // Club fee payment lines in orders.
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT im.meta_id, im.order_item_id, im.meta_value, oi.order_id FROM {$wpdb->prefix}woocommerce_order_itemmeta im JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = im.order_item_id WHERE im.meta_key = '_murvc_fee_payment_user' AND im.meta_value = %s",
                (string) $e->ID
            ), ARRAY_A);
            foreach ($items as $item) {
                self::repoint($wpdb->prefix . 'woocommerce_order_itemmeta', 'meta_value', 'meta_id', array($item), (string) $keep->ID);
                wp_cache_delete('order-items-' . $item['order_id'], 'orders');
            }

            // Coupon uses.
            $uses = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_coupon' WHERE pm.meta_key = '_used_by' AND pm.meta_value = %s",
                (string) $e->ID
            ), ARRAY_A);
            foreach ($uses as $use) {
                self::repoint($wpdb->postmeta, 'meta_value', 'meta_id', array($use), (string) $keep->ID);
                clean_post_cache((int) $use['post_id']);
            }

            // Square: the guest index, and saved cards (moved only when they
            // belong to the same Square customer as the keeper; otherwise
            // removed here, never through Square's API).
            $sq = $wpdb->prefix . 'woocommerce_square_customers';
            if (self::table_exists($sq)) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT square_id, user_id FROM `$sq` WHERE user_id = %d", $e->ID), ARRAY_A);
                self::repoint($sq, 'user_id', 'square_id', $rows, $keep->ID);
            }
            $tok = $wpdb->prefix . 'woocommerce_payment_tokens';
            if (self::table_exists($tok)) {
                $tokens = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$tok` WHERE user_id = %d", $e->ID), ARRAY_A);
                if (!empty($tokens)) {
                    $same = (string) get_user_meta($keep->ID, 'wc_square_customer_id', true) !== '' && (string) get_user_meta($keep->ID, 'wc_square_customer_id', true) === (string) get_user_meta($e->ID, 'wc_square_customer_id', true);
                    if ($same) {
                        self::repoint($tok, 'user_id', 'token_id', $tokens, $keep->ID);
                    } else {
                        foreach ($tokens as $t) {
                            $backup['deleted_rows'][$tok][] = $t;
                            $tm = $wpdb->prefix . 'woocommerce_payment_tokenmeta';
                            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM `$tm` WHERE payment_token_id = %d", $t['token_id']), ARRAY_A) as $m) {
                                $backup['deleted_rows'][$tm][] = $m;
                            }
                            $wpdb->delete($tm, array('payment_token_id' => $t['token_id']));
                            $wpdb->delete($tok, array('token_id' => $t['token_id']));
                        }
                        $summary['notes'][] = count($tokens) . " saved card(s) on #{$e->ID} belonged to another Square customer and were removed from the website (still in Square).";
                    }
                }
            }

            // Comments and reviews written by the account.
            $comments = $wpdb->get_results($wpdb->prepare("SELECT comment_ID, user_id FROM {$wpdb->comments} WHERE user_id = %d", $e->ID), ARRAY_A);
            self::repoint($wpdb->comments, 'user_id', 'comment_ID', $comments, $keep->ID);
        }

        self::restore_last_active($held);

        // The keeper's order caches are recomputed from the moved orders.
        $suffix = rtrim($wpdb->get_blog_prefix(), '_');
        foreach (array('wc_order_count_', 'wc_money_spent_', 'wc_last_order_') as $k) {
            delete_user_meta($keep->ID, $k . $suffix);
        }
        if (class_exists($store)) {
            $store::update_registered_customer($keep->ID);
        }
        if (class_exists('\Automattic\WooCommerce\Admin\API\Reports\Cache')) {
            \Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
        }
    }

    /**
     * WooCommerce marks an order's new customer as active today whenever the
     * customer changes, which would make a merged (or restored) account look
     * like the person was just on the site. Hold the dates around order moves.
     */
    private static function hold_last_active($user_ids) {
        $held = array();
        foreach ($user_ids as $id) {
            $held[(int) $id] = get_user_meta((int) $id, 'wc_last_active', true);
        }
        return $held;
    }

    private static function restore_last_active($held) {
        foreach ($held as $id => $value) {
            if ((string) get_user_meta($id, 'wc_last_active', true) === (string) $value) {
                continue;
            }
            if ((string) $value === '') {
                delete_user_meta($id, 'wc_last_active');
            } else {
                update_user_meta($id, 'wc_last_active', $value);
            }
        }
    }

    /** Nothing may still point at an extra account before it is deleted. */
    private static function verify($keep, $extras) {
        global $wpdb;
        foreach ($extras as $e) {
            if (!empty(self::order_ids($e->ID))) {
                throw new Exception("orders are still linked to #{$e->ID}.");
            }
            foreach (self::person_tables() as $short => $cols) {
                $table = $wpdb->prefix . $short;
                if (self::table_exists($table) && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE `{$cols[0]}` = %d", $e->ID))) {
                    throw new Exception("$short rows are still linked to #{$e->ID}.");
                }
            }
            foreach (self::id_columns() as $pair) {
                $table = $wpdb->prefix . $pair[0];
                if (self::table_exists($table) && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE `{$pair[1]}` = %d", $e->ID))) {
                    throw new Exception("{$pair[0]}.{$pair[1]} still names #{$e->ID}.");
                }
            }
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_murvc_fee_payment_user' AND meta_value = %s", (string) $e->ID))) {
                throw new Exception("fee payment lines still name #{$e->ID}.");
            }
            $access = get_option('team_oversight_committee_access');
            if (is_array($access) && isset($access[$e->ID])) {
                throw new Exception("committee access still names #{$e->ID}.");
            }
            $lists = array(get_option('team_oversight_program_supervisors'));
            foreach ((array) get_option('team_oversight_programs') as $program) {
                $lists[] = is_array($program) && isset($program['supervisors']) ? $program['supervisors'] : array();
            }
            foreach ($lists as $list) {
                if (is_array($list) && in_array($e->ID, array_map('intval', $list), true)) {
                    throw new Exception("a Club Program supervisor list still names #{$e->ID}.");
                }
            }
        }
    }

    private static function copy_photo($keep_id, $extras, &$summary) {
        foreach ($extras as $e) {
            $files = self::photo_files($e->ID);
            if (empty($files)) {
                continue;
            }
            $dir = self::photo_dir($keep_id);
            if (!wp_mkdir_p($dir)) {
                $summary['notes'][] = 'The profile photo could not be copied (folder not writable).';
                return;
            }
            $main = '';
            foreach ($files as $name => $data) {
                file_put_contents($dir . $name, base64_decode($data));
                if (preg_match('/^profile_photo\.[a-z0-9]+$/i', $name)) {
                    $main = $name;
                }
            }
            if ($main !== '') {
                update_user_meta($keep_id, 'profile_photo', $main);
            }
            $summary['photo_copied'] = array_keys($files);
            return;
        }
    }

    /**
     * What the merge changed on the kept account, before and after, so undo
     * puts back exactly those fields, and only while they still hold what
     * the merge wrote. Roles are a difference (summary), and security and
     * cache fields are never put back (undo_skips_meta()).
     */
    private static function record_keeper_changes($keep_id, $keep_meta_before, &$backup) {
        global $wpdb;
        $meta_before = array();
        foreach ($keep_meta_before as $m) {
            $meta_before[$m['meta_key']][] = $m['meta_value'];
        }
        $meta_after = self::meta_map($keep_id);
        $backup['keep']['meta_before'] = array();
        $backup['keep']['meta_after'] = array();
        foreach (array_unique(array_merge(array_keys($meta_before), array_keys($meta_after))) as $key) {
            $b = isset($meta_before[$key]) ? $meta_before[$key] : array();
            $a = isset($meta_after[$key]) ? $meta_after[$key] : array();
            if ($a !== $b && !self::undo_skips_meta($key)) {
                $backup['keep']['meta_before'][$key] = $b;
                $backup['keep']['meta_after'][$key] = $a;
            }
        }
        $backup['keep']['user_after'] = $wpdb->get_row($wpdb->prepare("SELECT user_registered, display_name FROM {$wpdb->users} WHERE ID = %d", $keep_id), ARRAY_A);
        unset($backup['keep']['usermeta']); // the whole profile is never put back
    }

    /** An account's usermeta as key => list of values (in the order they were added). */
    private static function meta_map($user_id) {
        global $wpdb;
        $map = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id", $user_id), ARRAY_A) as $m) {
            $map[$m['meta_key']][] = $m['meta_value'];
        }
        return $map;
    }

    /**
     * Fields undo never writes back to the kept account: roles (handled as
     * a difference), sign-in and security state, and WooCommerce's caches.
     */
    private static function undo_skips_meta($key) {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix();
        return in_array($key, array($prefix . 'capabilities', $prefix . 'user_level', 'session_tokens', '_application_passwords'), true)
            || (bool) preg_match('/^(_two_factor|itsec|wc_order_count_|wc_money_spent_|wc_last_order_)/', $key);
    }

    private static function after_merge($keep_id, $merged_ids, &$summary) {
        clean_user_cache($keep_id);
        wp_cache_delete($keep_id, 'user_meta');
        foreach ($merged_ids as $id) {
            clean_user_cache($id);
            wp_cache_delete($id, 'user_meta');
        }
        // Tier roles: the merged grants decide (as the daily sync would);
        // with no grants at all, the highest tier role any account held.
        global $wpdb;
        $has_grants = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_memberships WHERE user_id = %d", $keep_id));
        if ($has_grants && class_exists('TeamOversight_Memberships')) {
            (new TeamOversight_Memberships())->sync_user_roles($keep_id);
        } elseif (!$has_grants) {
            $tier = null;
            foreach ($summary['roles_after'] as $r) {
                if (in_array($r, self::TIER_ROLES, true)) {
                    $tier = $r;
                }
            }
            $user = new WP_User($keep_id);
            foreach (self::TIER_ROLES as $r) {
                if ($r === $tier && !in_array($r, (array) $user->roles, true)) {
                    $user->add_role($r);
                } elseif ($r !== $tier && in_array($r, (array) $user->roles, true)) {
                    $user->remove_role($r);
                }
            }
        }
        if (function_exists('UM')) {
            UM()->user()->remove_cache($keep_id);
            UM()->user()->generate_profile_slug($keep_id, true);
            UM()->user()->remove_cache($keep_id);
        }
        if (class_exists('TeamOversight_Fee_Timeline')) {
            TeamOversight_Fee_Timeline::flush_cache();
        }
        if (class_exists('TeamOversight_Duplicates')) {
            TeamOversight_Duplicates::after_merge($keep_id, $merged_ids);
        }
        $summary['roles_final'] = array_values((array) (new WP_User($keep_id))->roles);
    }

    // ------------------------------------------------------------------
    // Undo
    // ------------------------------------------------------------------

    public static function get($merge_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $merge_id), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['summary'] = json_decode((string) $row['summary'], true);
        return $row;
    }

    public static function recent($limit = 50) {
        global $wpdb;
        if (!self::table_exists(self::table())) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, kept_user_id, merged_user_ids, status, created_by, created_at, undone_by, undone_at, summary FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
        foreach ($rows as &$r) {
            $s = json_decode((string) $r['summary'], true);
            $r['people'] = is_array($s) && !empty($s['people']) ? $s['people'] : array();
            unset($r['summary']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Reverse a merge from its backup: recreate the deleted accounts with
     * their own ids, put the keeper's profile back, and move every recorded
     * row back where it still holds the value the merge wrote. Returns
     * array('restored' => n, 'skipped' => list) or a WP_Error.
     */
    public static function undo($merge_id) {
        global $wpdb;
        if (!current_user_can('manage_options') && !(defined('WP_CLI') && WP_CLI)) {
            return new WP_Error('murvc_merge_cap', 'Not allowed.');
        }
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', self::lock_name()))) {
            return new WP_Error('murvc_merge_lock', 'Another merge is running. Try again in a minute.');
        }
        try {
            return self::undo_locked((int) $merge_id);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
        }
    }

    /**
     * Why a merge record can't be undone now ('' when it can): undone
     * already, the kept account gone (merged away since), or a newer merge
     * involving the kept account (undo that one first: the newest first).
     */
    public static function undo_blocker($row) {
        global $wpdb;
        if (!is_array($row) || $row['status'] !== 'done') {
            return 'It has already been undone.';
        }
        $keep_id = (int) $row['kept_user_id'];
        if (!get_userdata($keep_id)) {
            return "The kept account #$keep_id no longer exists (merged into another account since?). Undo that merge first.";
        }
        $later = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . " WHERE status = 'done' AND id > %d AND (kept_user_id = %d OR FIND_IN_SET(%s, merged_user_ids)) ORDER BY id DESC LIMIT 1",
            (int) $row['id'], $keep_id, (string) $keep_id
        ));
        if ($later) {
            return "Merge record $later also involves account #$keep_id and was made after this one. Undo that one first.";
        }
        return '';
    }

    private static function undo_locked($merge_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $merge_id), ARRAY_A);
        if (!$row) {
            return new WP_Error('murvc_undo', "Merge $merge_id doesn't exist.");
        }
        $why = self::undo_blocker($row);
        if ($why !== '') {
            return new WP_Error('murvc_undo', "Merge $merge_id can't be undone: $why");
        }
        $backup = json_decode((string) $row['backup'], true);
        if (!is_array($backup)) {
            return new WP_Error('murvc_undo', 'The backup for this merge is unreadable.');
        }
        $keep_id = (int) $row['kept_user_id'];

        // Records made for the kept account since, in the seasons this merge
        // moved, would end up split across two accounts (what plan() refuses).
        $tables = self::person_tables();
        $labels = array('team_assignments' => 'team places', 'team_invoices' => 'club fee invoices', 'team_fee_segments' => 'fee history rows',
            'trial_applications' => 'trial applications', 'team_assignment_changes' => 'fee timeline changes');
        // Every season the merge moved anything in, checked across every
        // club table (a moved trial application can lead to a new team
        // place and invoice on the kept account, for example).
        $moved_seasons = array();
        foreach ($backup['changes'] as $c) {
            foreach ($tables as $short => $cols) {
                if ($c[0] === $wpdb->prefix . $short && $c[3] === $cols[0]) {
                    $season = $wpdb->get_var($wpdb->prepare("SELECT `{$cols[3]}` FROM `{$c[0]}` WHERE `{$c[1]}` = %s", $c[2]));
                    if ($season !== null) {
                        $moved_seasons[(string) $season] = true;
                    }
                }
            }
        }
        $since = array();
        if ($moved_seasons) {
            $in = "'" . implode("','", array_map('esc_sql', array_keys($moved_seasons))) . "'";
            foreach ($tables as $short => $cols) {
                if (!isset($backup['max_ids'][$short])) {
                    continue;
                }
                $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$wpdb->prefix}$short` WHERE `{$cols[0]}` = %d AND `{$cols[3]}` IN ($in) AND id > %d", $keep_id, (int) $backup['max_ids'][$short]));
                if ($n) {
                    $since[] = "$n new {$labels[$short]}";
                }
            }
            if (isset($backup['max_ids']['team_invoice_payments'])) {
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}team_invoice_payments p JOIN {$wpdb->prefix}team_invoices i ON i.id = p.invoice_id WHERE p.id > %d AND i.season IN ($in) AND i.user_id = %d",
                    (int) $backup['max_ids']['team_invoice_payments'], $keep_id
                ));
                if ($n) {
                    $since[] = "$n new payment(s)";
                }
            }
            if ($since) {
                $since[count($since) - 1] .= ' in ' . implode(', ', array_keys($moved_seasons));
            }
        }
        if ($since) {
            return new WP_Error('murvc_undo', "Since the merge, account #$keep_id has " . implode('; ', $since) . '. Undoing now would split those seasons across two accounts, so the merge stays. Sort those records out first, or leave the merge in place.');
        }

        // The old accounts must be recreatable exactly.
        foreach ($backup['users'] as $uid => $u) {
            if (get_userdata((int) $uid)) {
                return new WP_Error('murvc_undo', "An account #$uid exists again, so this merge can't be undone.");
            }
            if (username_exists($u['user_login'])) {
                return new WP_Error('murvc_undo', "Someone now uses the login {$u['user_login']}, so this merge can't be undone.");
            }
            if (email_exists($u['user_email'])) {
                return new WP_Error('murvc_undo', "Someone now uses the email {$u['user_email']}, so this merge can't be undone.");
            }
        }

        $skipped = array();
        $restored = 0;
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($backup['users'] as $uid => $u) {
                if ($wpdb->insert($wpdb->users, $u) === false) {
                    throw new Exception("could not recreate account #$uid: " . $wpdb->last_error);
                }
                foreach ($backup['usermeta'][$uid] as $m) {
                    $wpdb->insert($wpdb->usermeta, array('umeta_id' => $m['umeta_id'], 'user_id' => (int) $uid, 'meta_key' => $m['meta_key'], 'meta_value' => $m['meta_value']));
                }
            }

            // The kept account: only the fields the merge changed, and only
            // where they still hold what the merge wrote (roles afterwards,
            // as a difference; security fields never).
            if (isset($backup['keep']['meta_after'])) {
                foreach ($backup['keep']['meta_after'] as $key => $after) {
                    $now = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id", $keep_id, $key));
                    if ($now !== array_map('strval', $after)) {
                        $skipped[] = "the kept account's $key (changed since)";
                        continue;
                    }
                    $wpdb->delete($wpdb->usermeta, array('user_id' => $keep_id, 'meta_key' => $key));
                    foreach ($backup['keep']['meta_before'][$key] as $v) {
                        $wpdb->insert($wpdb->usermeta, array('user_id' => $keep_id, 'meta_key' => $key, 'meta_value' => $v));
                    }
                    $restored++;
                }
                foreach (array('user_registered', 'display_name') as $col) {
                    $was = isset($backup['keep']['user'][$col]) ? (string) $backup['keep']['user'][$col] : null;
                    $made = isset($backup['keep']['user_after'][$col]) ? (string) $backup['keep']['user_after'][$col] : null;
                    if ($was === null || $made === null || $was === $made) {
                        continue;
                    }
                    if ($wpdb->query($wpdb->prepare("UPDATE {$wpdb->users} SET `$col` = %s WHERE ID = %d AND `$col` = %s", $was, $keep_id, $made))) {
                        $restored++;
                    } else {
                        $skipped[] = "the kept account's $col (changed since)";
                    }
                }
            } else {
                $skipped[] = "the kept account's profile (this merge kept no record of what it changed there)";
            }

            // Rows back where they were (only if still as the merge left them).
            foreach (array_reverse($backup['changes']) as $c) {
                list($table, $pk_col, $pk, $col, $old, $new) = $c;
                if ($table === 'order') {
                    continue;
                }
                if ($old === null) {
                    $n = $wpdb->query($wpdb->prepare("UPDATE `$table` SET `$col` = NULL WHERE `$pk_col` = %s AND `$col` = %s", $pk, $new));
                } else {
                    $n = $wpdb->query($wpdb->prepare("UPDATE `$table` SET `$col` = %s WHERE `$pk_col` = %s AND `$col` = %s", $old, $pk, $new));
                }
                if ($n) {
                    $restored++;
                } else {
                    $skipped[] = "$table #$pk ($col)";
                }
            }
            // Rows the merge added (re-keyed decisions) go before the rows it deleted come back.
            foreach ((array) (isset($backup['inserted']) ? $backup['inserted'] : array()) as $table => $ids) {
                foreach ((array) $ids as $id) {
                    $wpdb->delete($table, array('id' => (int) $id));
                }
            }
            foreach ($backup['deleted_rows'] as $table => $rows) {
                foreach ($rows as $r) {
                    if ($wpdb->insert($table, $r) === false) {
                        $skipped[] = "$table #" . (isset($r['id']) ? (int) $r['id'] : 0) . ' (could not be put back)';
                    }
                }
            }
            // Setting entries (committee access, program supervisors), one by one.
            foreach (array_reverse(isset($backup['option_entries']) ? $backup['option_entries'] : array()) as $oe) {
                list($option, $path, $before, $after) = $oe;
                $all = get_option($option);
                if (self::option_at($all, $path) == $after) {
                    update_option($option, self::option_set($all, $path, $before));
                    $restored++;
                } else {
                    $skipped[] = "$option" . ($path ? ' (' . implode(' > ', $path) . ')' : '') . ' (changed since)';
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $ex) {
            $wpdb->query('ROLLBACK');
            wp_cache_flush();
            return new WP_Error('murvc_undo', 'Nothing was changed: ' . $ex->getMessage());
        }
        wp_cache_flush();

        // The kept account's roles: take off the ones the merge added and put
        // back the ones it removed, leaving any other change since (a staff
        // role taken away, say) alone. Membership tiers are re-synced below.
        $summary = json_decode((string) $row['summary'], true);
        if (isset($summary['roles_before'], $summary['roles_final'])) {
            $user = new WP_User($keep_id);
            foreach (array_diff($summary['roles_final'], $summary['roles_before']) as $r) {
                if (in_array($r, (array) $user->roles, true)) {
                    $user->remove_role($r);
                }
            }
            foreach (array_diff($summary['roles_before'], $summary['roles_final']) as $r) {
                if (!in_array($r, (array) $user->roles, true)) {
                    $user->add_role($r);
                }
            }
        }

        // Orders through WooCommerce, then Analytics for both accounts.
        $store = '\Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore';
        $sched = '\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler';
        foreach ($backup['users'] as $uid => $u) {
            if (class_exists($store)) {
                $store::update_registered_customer((int) $uid);
            }
        }
        $held = self::hold_last_active(array_keys($backup['users']));
        foreach ($backup['changes'] as $c) {
            if ($c[0] !== 'order') {
                continue;
            }
            $order = wc_get_order((int) $c[2]);
            if (!$order || (int) $order->get_customer_id() !== (int) $c[5]) {
                $skipped[] = 'order #' . (int) $c[2];
                continue;
            }
            $order->set_customer_id((int) $c[4]);
            $order->save();
            $order->add_order_note(sprintf('Account merge undone: customer back to #%d.', (int) $c[4]), 0, false);
            if (class_exists($sched) && method_exists($sched, 'import')) {
                $sched::import($order->get_id());
                foreach ($order->get_refunds() as $refund) {
                    $sched::import($refund->get_id());
                }
            }
            $restored++;
        }
        self::restore_last_active($held);
        // WooCommerce's order count, total spent and last order for the
        // kept account may have been rebuilt with the moved orders; it
        // recomputes them on demand.
        $suffix = rtrim($wpdb->get_blog_prefix(), '_');
        foreach (array('wc_order_count_', 'wc_money_spent_', 'wc_last_order_') as $k) {
            delete_user_meta($keep_id, $k . $suffix);
        }
        // Rows on the old Analytics customer id now belong to the recreated one.
        foreach ($backup['lookup'] as $uid => $old_row) {
            $new_cid = class_exists($store) ? $store::get_customer_id_by_user_id((int) $uid) : 0;
            if ($new_cid && !empty($old_row['customer_id'])) {
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wc_order_stats SET customer_id = %d WHERE customer_id = %d", $new_cid, $old_row['customer_id']));
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wc_order_product_lookup SET customer_id = %d WHERE customer_id = %d", $new_cid, $old_row['customer_id']));
            }
        }
        if (class_exists($store)) {
            $store::update_registered_customer($keep_id);
        }
        if (class_exists('\Automattic\WooCommerce\Admin\API\Reports\Cache')) {
            \Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
        }

        // Photos.
        foreach ($backup['photos'] as $uid => $files) {
            if (empty($files)) {
                continue;
            }
            $dir = self::photo_dir((int) $uid);
            wp_mkdir_p($dir);
            foreach ($files as $name => $data) {
                file_put_contents($dir . $name, base64_decode($data));
            }
        }
        if (!empty($summary['photo_copied']) && empty($backup['keep']['photos'])) {
            foreach ($summary['photo_copied'] as $name) {
                @unlink(self::photo_dir($keep_id) . basename($name));
            }
        }

        $ids = array_merge(array($keep_id), array_map('intval', array_keys($backup['users'])));
        foreach ($ids as $id) {
            clean_user_cache($id);
            if (function_exists('UM')) {
                UM()->user()->remove_cache($id);
                UM()->user()->generate_profile_slug($id, true);
                UM()->user()->remove_cache($id);
            }
            if (class_exists('TeamOversight_Memberships')) {
                (new TeamOversight_Memberships())->sync_user_roles($id);
            }
        }
        if (class_exists('TeamOversight_Fee_Timeline')) {
            TeamOversight_Fee_Timeline::flush_cache();
        }
        if (class_exists('TeamOversight_Duplicates')) {
            TeamOversight_Duplicates::forget_scan();
        }

        // What undo left as it was stays on the record (the page lists it).
        $summary = is_array($summary) ? $summary : array();
        $summary['undo_skipped'] = $skipped;
        $wpdb->update(self::table(), array('status' => 'undone', 'undone_by' => get_current_user_id(), 'undone_at' => current_time('mysql'), 'summary' => wp_json_encode($summary)), array('id' => $merge_id));
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add('account_merge_undone', sprintf(
                'Undid merge record %d: account(s) #%s recreated from #%d. %d change(s) put back%s.',
                $merge_id, implode(', #', array_keys($backup['users'])), $keep_id, $restored,
                $skipped ? ', ' . count($skipped) . ' left as they were (changed since)' : ''
            ), array('user_id' => $keep_id));
        }
        return array('restored' => $restored, 'skipped' => $skipped);
    }
}
