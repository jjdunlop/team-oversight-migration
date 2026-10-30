<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coaches (1.81.0): one profile per coach, typed by the committee, from
 * which every public coach list is drawn. This class holds the tables, the
 * rules, the readers and the writers; TeamOversight_Coaches_Admin draws the
 * screens and calls them.
 *
 * The rules, in one place:
 *  - A profile is shown only while it is published AND passes guard_sql():
 *    a public name, a consent date, a consent method that is consent (not
 *    blank, not "Asked not to be shown"), a consent scope that covers the
 *    display mode, and for a coach under 18 a parent or guardian date.
 *    set_status() is the only writer of 'published' and does it through
 *    guard_sql(); enforce_guard() hides a published profile a save took out
 *    of it; the public read applies guard_sql() again. publish_problem() is
 *    the same rule in PHP, worded for the editor.
 *  - Nothing on a card is read from the coach's account. The account link
 *    (user_id) is used only to suggest team places and to check ticks, and
 *    is set only from a team place (1.83.0).
 *  - Team coaching is opt-in: one tick per (profile, team place), judged by
 *    tick_problem() wherever a tick is read. The rule is here from 1.81.0 so
 *    every reader shares it; the ticks and their writers come in 1.83.0.
 *  - Every profile writer validates first, writes with one compare-and-set
 *    UPDATE or INSERT whose affected-row count gates the side effects, adds
 *    one history row (team_coach_changes, never pruned) and one line in the
 *    activity log (the three LOG_* types). save_programs() is held to the
 *    hash of the list the page was drawn with instead, and the editor-role
 *    writers are add_role() / remove_role(); both record and log the same
 *    way. Nothing deletes a row, and nothing here writes team_assignments or
 *    team_assignment_changes.
 *  - The Coach bios editor role (ROLE) holds CAP directly; administrators
 *    hold CAP through a user_has_cap filter, so nothing is stored for them.
 *    The role is granted only on the Editors box (grant_editor()), because
 *    the Users screen keeps one role; the role watch records it when the
 *    role goes any other way (a save on Users → Edit keeps only the role in
 *    its dropdown) and never puts it back by itself.
 *  - Coach photos no card shows are kept out of the site's public media
 *    listing (REST, embeds, and attachment pages with their feeds) for
 *    anyone without upload_files (photo_withheld_ids()), from the moment
 *    one is uploaded on the Coaches editor. The file itself still opens by
 *    its link.
 *
 * Tables: team_coach_profiles (one row per coach, never deleted),
 * team_coach_ticks (used from 1.83.0) and team_coach_changes (the history).
 * All three are InnoDB and registered with the account merge
 * (id_columns()). Contract: design/coach-bios-tech-design.md sections 3 to
 * 8 and 9.8.
 */
class TeamOversight_Coaches {

    /** The capability every Coaches screen and the handler check. Administrators hold it through grant_admins(). */
    const CAP = 'murvc_manage_coaches';
    /** The Coach bios editor role: CAP, read, view_admin_dashboard (WooCommerce's wp-admin gate) and upload_files (the media picker). */
    const ROLE = 'murvc_coach_bios_editor';

    /** key => array(label, accent, filter, order). Absent = DEFAULT_PROGRAMS. Non-autoloaded. */
    const PROGRAMS_OPTION = 'team_oversight_coach_programs';
    /** season => array(at, by): the seasons whose team coaching is on the website (1.83.0). Non-autoloaded. */
    const ANNOUNCED_OPTION = 'team_oversight_coach_announced';
    /** The fingerprint of the public team lines (1.83.0). Non-autoloaded. */
    const LINE_STATE_OPTION = 'team_oversight_coach_line_state';

    /** The three log types Coaches writes (membership channel). */
    const LOG_PROFILE = 'coach_profile';
    const LOG_WEBSITE = 'coach_website';
    const LOG_EDITOR = 'coach_editor';

    /** Transients: the handler's flash (+ user id, 300 s), a stale save's typed fields (+ user id _ profile id, 1800 s), the pages using the shortcode (12 h), the last page-load sweep (60 s). */
    const FLASH_PREFIX = 'murvc_coaches_flash_';
    const DRAFT_PREFIX = 'murvc_coaches_draft_';
    const PAGES_TRANSIENT = 'murvc_coaches_pages';
    const SWEPT_TRANSIENT = 'murvc_coaches_swept';
    /** The named lock around making a profile from a team place and linking one (1.83.0). */
    const LINK_LOCK = 'murvc_coach_link';
    /** Posted with every upload from the Coaches editor's media picker, so the file is withheld before any save. */
    const UPLOAD_PARAM = 'murvc_coach_photo';

    /** Field limits, in characters (mb_strlen). A longer value is refused, never cut short. */
    const NAME_MAX = 80;
    const INTRO_MAX = 90;
    const BIO_MAX = 1200;
    const BIO_AIM_WORDS = 80;
    const HIGHLIGHTS = 3;
    const HIGHLIGHT_MAX = 100;
    const BACKGROUND_MAX = 120;
    const OTHER_MAX = 60;

    /** A shown profile not checked for this many days "needs checking". */
    const CHECK_AFTER_DAYS = 300;
    /** The most ids one bulk action takes, and the most rows the Profiles list shows. */
    const BULK_MAX = 200;
    const LIST_MAX = 500;
    /** An uncleared photo, or a hidden profile's photo file, is flagged after this many days. */
    const PHOTO_STALE_DAYS = 14;

    const ACCREDITATIONS = array('l1' => 'Level 1', 'l2a' => 'Level 2 Associate', 'l2s' => 'Level 2 State',
        'l3a' => 'Level 3 Associate', 'l3n' => 'Level 3 National', 'other' => 'Other');
    const CONSENT_METHODS = array('email' => 'Agreed by email', 'form' => 'Agreed on a form',
        'in_person' => 'Agreed in person', 'message' => 'Agreed by text or message', 'declined' => 'Asked not to be shown');
    // The methods that count as consent: every CONSENT_METHODS key except declined (test A checks this).
    const CONSENT_GIVEN = array('email', 'form', 'in_person', 'message');
    const CONSENT_SCOPES = array('full' => 'Full profile', 'name_only' => 'Name and role only');
    const PROGRAM_ROLES = array('lead' => 'Lead coach', 'coach' => 'Coach', 'assistant' => 'Assistant coach');
    const TEAM_ROLES = array('coach' => 'Head coach', 'assistant_coach' => 'Assistant coach');
    // key => array(label, filter order, accent)
    const TEAM_GROUPS = array('state_league' => array('State League', 20, 'blue'),
        'junior' => array('Junior teams', 30, 'red'), 'premier' => array('Premier League', 40, 'blue'));

    /** The programs while PROGRAMS_OPTION has never been saved. The orders interleave with TEAM_GROUPS for the /coaches/ filter buttons. */
    const DEFAULT_PROGRAMS = array(
        'academy' => array('label' => 'Junior Academy', 'accent' => 'red', 'filter' => 1, 'order' => 10),
        'mixed' => array('label' => 'Mixed Training Program', 'accent' => 'blue', 'filter' => 1, 'order' => 50),
        'social' => array('label' => 'Reds Social League', 'accent' => 'blue', 'filter' => 1, 'order' => 60),
        'post_season' => array('label' => 'Post-Season', 'accent' => 'blue', 'filter' => 1, 'order' => 70),
        'pathway' => array('label' => 'Pathway', 'accent' => 'blue', 'filter' => 0, 'order' => 80),
    );

    /** Set by revoke_editor() around its own remove_role(), so the role watch doesn't report the Editors box. */
    private static $own_role_change = false;
    /** Account ids the role watch noted this request (id => true), checked once at shutdown. */
    private static $role_watch = array();

    /** Request caches. Every writer resets them (record_change()), and flush_cache() does for tests. */
    private static $withheld = null;
    private static $stats = null;
    private static $lost = null;
    private static $roles_cache = array();

    /**
     * Add the Coaches hooks, once per request (team_oversight_init() calls
     * it): the role and the two wp-admin gates, the role watch, and the
     * photo-file hooks (9.8: REST, embeds, attachment pages, and the
     * Coaches editor's uploads). Static and guarded, never from a
     * constructor.
     */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('init', array(__CLASS__, 'ensure_role'));
        add_filter('user_has_cap', array(__CLASS__, 'grant_admins'), 10, 1);
        // Ultimate Member's wp-admin block reads can_access_wpadmin; WooCommerce's reads view_admin_dashboard (a role cap).
        add_filter('um_user_permissions_filter', array(__CLASS__, 'um_permissions'), 10, 2);
        add_filter('default_option_um_role_' . self::ROLE . '_meta', array(__CLASS__, 'um_role_default'), 10, 1);
        // The role watch: the role going any way but the Editors box.
        add_action('set_user_role', array(__CLASS__, 'on_role_set'), 10, 3);
        add_action('remove_user_role', array(__CLASS__, 'on_role_removed'), 10, 2);
        // Coach photos no card shows stay out of the public media listing.
        add_filter('rest_attachment_query', array(__CLASS__, 'rest_media_query'), 10, 2);
        add_filter('rest_prepare_attachment', array(__CLASS__, 'rest_media_item'), 10, 3);
        add_filter('oembed_request_post_id', array(__CLASS__, 'oembed_post_id'), 10, 2);
        add_action('template_redirect', array(__CLASS__, 'withhold_attachment_page'), 5);
        add_action('add_attachment', array(__CLASS__, 'on_photo_uploaded'), 10, 1);
    }

    /** Forget everything cached this request (tests; every writer does it too). */
    public static function flush_cache() {
        self::$withheld = null;
        self::$stats = null;
        self::$lost = null;
        self::$roles_cache = array();
    }

    // ------------------------------------------------------------------
    // Tables (one definition for migrate_database(), create_tables() and
    // team_oversight_create_tables())
    // ------------------------------------------------------------------

    public static function profiles_table() {
        global $wpdb;
        return $wpdb->prefix . 'team_coach_profiles';
    }

    public static function ticks_table() {
        global $wpdb;
        return $wpdb->prefix . 'team_coach_ticks';
    }

    public static function changes_table() {
        global $wpdb;
        return $wpdb->prefix . 'team_coach_changes';
    }

    /** Short name => CREATE statement, for the guarded create loop. Nothing existing is ever altered. */
    public static function tables_sql() {
        return array(
            'team_coach_profiles' => self::profiles_table_sql(),
            'team_coach_ticks' => self::ticks_table_sql(),
            'team_coach_changes' => self::changes_table_sql(),
        );
    }

    /**
     * One row per coach, never deleted. user_id is the linked account (set
     * only from a team place; NULL = none, any number of NULLs under the
     * unique key). No email, phone or name snapshot: everything public is
     * typed by the committee. version is the edit counter: an editor save
     * that changes something (or an Update while published), Publish, Hide
     * and enforce_guard() raise it; Still correct (mark_checked(), which
     * stamps only checked_at) and an account merge's re-point of user_id
     * leave it alone.
     */
    public static function profiles_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_coach_profiles (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned DEFAULT NULL,
                status varchar(10) NOT NULL DEFAULT 'draft',
                display_mode varchar(10) NOT NULL DEFAULT 'full',
                public_name varchar(100) NOT NULL DEFAULT '',
                intro varchar(120) NOT NULL DEFAULT '',
                bio text DEFAULT NULL,
                accreditation varchar(20) NOT NULL DEFAULT '',
                accreditation_other varchar(60) NOT NULL DEFAULT '',
                highlights text DEFAULT NULL,
                playing_background varchar(150) NOT NULL DEFAULT '',
                program_roles text DEFAULT NULL,
                photo_id bigint(20) unsigned DEFAULT NULL,
                photo_cleared_id bigint(20) unsigned DEFAULT NULL,
                consent_date date DEFAULT NULL,
                consent_method varchar(20) NOT NULL DEFAULT '',
                consent_scope varchar(10) NOT NULL DEFAULT '',
                is_minor tinyint(1) NOT NULL DEFAULT 0,
                guardian_date date DEFAULT NULL,
                checked_at datetime DEFAULT NULL,
                version int(11) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_id (user_id),
                KEY status (status)
            ) ENGINE=InnoDB $charset_collate;";
    }

    /**
     * The tick on one team place (1.83.0): keyed by team_assignments.id plus
     * a snapshot of that row's season, team (bare code) and role when it was
     * ticked. state is shown, off or cleared (with cleared_reason). Rows are
     * never deleted; re-ticking updates the same row.
     */
    public static function ticks_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_coach_ticks (
                id int(11) NOT NULL AUTO_INCREMENT,
                profile_id int(11) NOT NULL,
                assignment_id int(11) NOT NULL,
                season varchar(10) NOT NULL,
                team varchar(50) NOT NULL,
                role varchar(50) NOT NULL,
                state varchar(10) NOT NULL DEFAULT 'shown',
                shown_by bigint(20) unsigned DEFAULT NULL,
                shown_at datetime NOT NULL,
                cleared_at datetime DEFAULT NULL,
                cleared_by bigint(20) unsigned DEFAULT NULL,
                cleared_reason varchar(20) NOT NULL DEFAULT '',
                seen_by bigint(20) unsigned DEFAULT NULL,
                seen_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY profile_assignment (profile_id, assignment_id),
                KEY assignment_id (assignment_id),
                KEY season_state (season, state)
            ) ENGINE=InnoDB $charset_collate;";
    }

    /**
     * The history: one row per change, with old and new values, NEVER pruned
     * (the activity log is), like team_assignment_changes. profile_id 0 is a
     * club-wide event (programs, the editor role, announcing a season).
     * recorded_by NULL = the daily check.
     */
    public static function changes_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE {$wpdb->prefix}team_coach_changes (
                id int(11) NOT NULL AUTO_INCREMENT,
                profile_id int(11) NOT NULL DEFAULT 0,
                event varchar(30) NOT NULL,
                detail text DEFAULT NULL,
                source varchar(20) NOT NULL DEFAULT '',
                recorded_by bigint(20) unsigned DEFAULT NULL,
                recorded_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY profile_id (profile_id, id)
            ) ENGINE=InnoDB $charset_collate;";
    }

    // ------------------------------------------------------------------
    // The role, the capability and the two wp-admin gates
    // ------------------------------------------------------------------

    private static function role_caps() {
        return array('read' => true, self::CAP => true, 'view_admin_dashboard' => true, 'upload_files' => true);
    }

    /**
     * Make sure the Coach bios editor role exists with its four
     * capabilities. A missing capability is added after a has_cap() check,
     * so the roles option is written once. The role is never removed, on
     * deactivation included.
     */
    public static function ensure_role() {
        $role = get_role(self::ROLE);
        if (!$role) {
            add_role(self::ROLE, 'Coach bios editor', self::role_caps());
            return;
        }
        foreach (self::role_caps() as $cap => $grant) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap, $grant);
            }
        }
    }

    /** user_has_cap: anyone with manage_options holds CAP too. Nothing is written to the database. */
    public static function grant_admins($allcaps) {
        if (is_array($allcaps) && !empty($allcaps['manage_options'])) {
            $allcaps[self::CAP] = true;
        }
        return $allcaps;
    }

    /** um_user_permissions_filter: a CAP holder may enter wp-admin past Ultimate Member's block. */
    public static function um_permissions($perms, $user_id = 0) {
        if (is_array($perms) && intval($user_id) > 0 && user_can(intval($user_id), self::CAP)) {
            $perms['can_access_wpadmin'] = 1;
        }
        return $perms;
    }

    /**
     * default_option_um_role_<ROLE>_meta: while nobody has configured the
     * role in Ultimate Member, give it priority -1, so it never wins over a
     * member role when UM picks a user's priority role (a role with no
     * settings counts as 0 and could tie with customer or the tier role).
     */
    public static function um_role_default($default) {
        $meta = is_array($default) ? $default : array();
        $meta['_um_priority'] = -1;
        return $meta;
    }

    // ------------------------------------------------------------------
    // The role watch
    // ------------------------------------------------------------------

    /** set_user_role: core's set_role() (Users → Edit) dropped every role but the dropdown's. */
    public static function on_role_set($user_id, $role, $old_roles = array()) {
        if (in_array(self::ROLE, (array) $old_roles, true) && $role !== self::ROLE) {
            self::watch_role($user_id);
        }
    }

    /** remove_user_role: the role was removed, and not by the Editors box. */
    public static function on_role_removed($user_id, $role) {
        if ($role === self::ROLE && !self::$own_role_change) {
            self::watch_role($user_id);
        }
    }

    private static function watch_role($user_id) {
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return;
        }
        if (empty(self::$role_watch)) {
            add_action('shutdown', array(__CLASS__, 'check_lost_roles'));
        }
        self::$role_watch[$user_id] = true;
    }

    /**
     * At shutdown: for each account the watch noted, if it still exists and
     * still lacks the role (a role editor's extra-roles box may have put it
     * back later in the same save), record editor_lost and log it. Never
     * re-adds the role: the To do panel and the Editors box offer Re-add or
     * Leave off.
     */
    public static function check_lost_roles() {
        $ids = array_keys(self::$role_watch);
        self::$role_watch = array();
        foreach ($ids as $user_id) {
            clean_user_cache($user_id);
            $user = get_userdata($user_id);
            if (!$user || in_array(self::ROLE, (array) $user->roles, true)) {
                continue;
            }
            self::record_change(0, 'editor_lost', array('user' => $user_id), 'users', get_current_user_id());
            self::log(self::LOG_EDITOR, 'Coach bios editor role removed outside the Editors box for user #' . $user_id . '.', $user_id);
        }
    }

    // ------------------------------------------------------------------
    // The rules
    // ------------------------------------------------------------------

    /** A season string: four digits. */
    public static function valid_season($s) {
        return is_string($s) && preg_match('/^\d{4}$/D', $s) === 1;
    }

    /**
     * The publish guard as an SQL condition, for the table alias $a (one to
     * three lower-case letters, else it throws). No placeholders. Every
     * column it reads is NOT NULL or tested with IS (NOT) NULL, so NOT (...)
     * is never NULL. The method list is written out literally and equals
     * CONSENT_GIVEN (test A checks both), so a blank method and declined
     * both fail. set_status(), enforce_guard(), the public read and
     * photo_withheld_ids() all use this one function.
     */
    public static function guard_sql($a = 'p') {
        if (!is_string($a) || preg_match('/^[a-z]{1,3}$/D', $a) !== 1) {
            throw new InvalidArgumentException('guard_sql() takes a table alias of one to three lower-case letters.');
        }
        return "({$a}.public_name <> '' AND {$a}.consent_date IS NOT NULL AND {$a}.consent_method IN ('email','form','in_person','message') AND (({$a}.display_mode = 'full' AND {$a}.consent_scope = 'full') OR ({$a}.display_mode = 'name_only' AND {$a}.consent_scope IN ('full','name_only'))) AND ({$a}.is_minor = 0 OR {$a}.guardian_date IS NOT NULL))";
    }

    /**
     * The PHP mirror of guard_sql(), clause for clause, plus "the consent
     * date is not after today": '' when the profile may be published, else
     * the first sentence that applies, in the order the editor fixes them.
     * $p is a profile row (object or array).
     */
    public static function publish_problem($p) {
        $p = (object) $p;
        $name = isset($p->public_name) ? trim((string) $p->public_name) : '';
        $date = isset($p->consent_date) ? (string) $p->consent_date : '';
        $method = isset($p->consent_method) ? (string) $p->consent_method : '';
        $scope = isset($p->consent_scope) ? (string) $p->consent_scope : '';
        $mode = isset($p->display_mode) ? (string) $p->display_mode : '';
        if ($name === '') {
            return 'Add a public name.';
        }
        if ($date === '') {
            return 'Record the date they agreed.';
        }
        if ($date > current_time('Y-m-d')) {
            return 'The consent date can\'t be in the future.';
        }
        if ($method === 'declined') {
            return 'They asked not to be shown.';
        }
        if (!in_array($method, self::CONSENT_GIVEN, true)) {
            return 'Record how they agreed.';
        }
        if ($mode === 'full' && $scope === 'name_only') {
            return 'They agreed to Name and role only: choose Name only, or record a Full profile answer.';
        }
        if (!array_key_exists($scope, self::CONSENT_SCOPES)) {
            return 'Record what they agreed to.';
        }
        if ($mode !== 'full' && $mode !== 'name_only') {
            return 'Choose Full profile or Name only.';
        }
        if (!empty($p->is_minor) && (!isset($p->guardian_date) || (string) $p->guardian_date === '')) {
            return 'Under 18: record the date a parent or guardian agreed.';
        }
        return '';
    }

    /** A team place's stop date, '' when it has none (NULL, blank or the zero date). */
    private static function end_of($row) {
        $end = isset($row->end_date) ? (string) $row->end_date : '';
        return ($end === '' || $end === '0000-00-00') ? '' : $end;
    }

    /**
     * Is a team_assignments row a current place on $today ('YYYY-MM-DD')?
     * 2027+: active and no stop date, or one after today (the stop date is
     * the first day off the team, and is_active lags the daily job). Earlier
     * seasons: active and no end date, or one on or after today. A future
     * start counts as current.
     */
    public static function place_is_current($row, $today) {
        if (!$row || intval(isset($row->is_active) ? $row->is_active : 0) !== 1) {
            return false;
        }
        $end = self::end_of($row);
        if ($end === '') {
            return true;
        }
        $season = isset($row->season) ? (string) $row->season : '';
        return TeamOversight_Fees::timeline_applies($season) ? $end > $today : $end >= $today;
    }

    /**
     * Does a place belong to the account? A place with an account id belongs
     * only to that account; one without goes by email, and only when the
     * account has one (the coach portal's identity rule, in PHP).
     */
    public static function place_matches_account($place, $account_id, $account_email) {
        $place_user = intval(isset($place->user_id) ? $place->user_id : 0);
        if ($place_user > 0) {
            return $place_user === intval($account_id);
        }
        $account_email = (string) $account_email;
        return $account_email !== '' && strtolower((string) (isset($place->email) ? $place->email : '')) === strtolower($account_email);
    }

    /**
     * '' when a tick is live, else why not, checked in this order: removed
     * (no place), changed (the place's season, team or role is not the
     * tick's snapshot: this covers a change to a playing role), account (no
     * linked account, or the place isn't the account's), ended (not
     * current). $tick may carry the snapshot as season / team / role or,
     * from a joined row, as t_season / t_team / t_role. Every reader of
     * ticks and the daily check use this one function.
     */
    public static function tick_problem($tick, $place, $account_id, $account_email, $today) {
        if (!$place || empty($place->id)) {
            return 'removed';
        }
        foreach (array('season', 'team', 'role') as $field) {
            $snap = isset($tick->{'t_' . $field}) ? $tick->{'t_' . $field} : (isset($tick->$field) ? $tick->$field : '');
            if ((string) $snap !== (string) (isset($place->$field) ? $place->$field : '')) {
                return 'changed';
            }
        }
        if (intval($account_id) < 1 || !self::place_matches_account($place, $account_id, $account_email)) {
            return 'account';
        }
        if (!self::place_is_current($place, (string) $today)) {
            return 'ended';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Readers: profiles
    // ------------------------------------------------------------------

    /** One profile row as an object, or null. */
    public static function get_profile($id) {
        global $wpdb;
        $id = intval($id);
        if ($id < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::profiles_table() . ' WHERE id = %d', $id));
        return $row ? $row : null;
    }

    /** The profile linked to that account, or null. */
    public static function profile_for_account($user_id) {
        global $wpdb;
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::profiles_table() . ' WHERE user_id = %d LIMIT 1', $user_id));
        return $row ? $row : null;
    }

    /**
     * The Profiles list: at most LIST_MAX rows, by public name, each with
     * account_id / account_email from the linked account (NULL when there is
     * none or it is gone: used only to judge ticks, never printed). $filter
     * is '' (all), shown, hidden, check (needs checking), consent (no
     * consent, or a parent's missing) or photo (waiting to be cleared); an
     * unknown filter is all. $search is matched against the public name.
     */
    public static function list_profiles($filter = '', $search = '') {
        global $wpdb;
        $where = array();
        $args = array();
        list($filter_sql, $filter_args) = self::filter_where(is_string($filter) ? $filter : '');
        if ($filter_sql !== '') {
            $where[] = $filter_sql;
            $args = array_merge($args, $filter_args);
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = 'p.public_name LIKE %s';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $args[] = self::LIST_MAX;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT p.*, u.ID AS account_id, u.user_email AS account_email FROM ' . self::profiles_table() . " p
             LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id"
            . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY p.public_name, p.id LIMIT %d',
            $args
        ));
        $rows = is_array($rows) ? $rows : array();
        foreach ($rows as $row) {
            self::$roles_cache[intval($row->id)] = $row->program_roles;
        }
        return $rows;
    }

    /**
     * The counts behind the Profiles filter links, from one grouped query
     * (shared with todo()): all, shown, hidden, check, consent, photo.
     */
    public static function profile_counts() {
        $s = self::stats();
        return array(
            'all' => $s['total'],
            'shown' => $s['shown'],
            'hidden' => $s['hidden'],
            'check' => $s['needs_check'],
            'consent' => $s['consent'],
            'photo' => $s['photo'],
        );
    }

    /** The history of one profile (0 = the club-wide events), newest first; each row also carries `data`, its decoded detail. */
    public static function history($profile_id, $limit = 50) {
        global $wpdb;
        $limit = max(1, min(500, intval($limit)));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::changes_table() . ' WHERE profile_id = %d ORDER BY id DESC LIMIT %d',
            intval($profile_id), $limit
        ));
        $rows = is_array($rows) ? $rows : array();
        foreach ($rows as $row) {
            $data = json_decode((string) $row->detail, true);
            $row->data = is_array($data) ? $data : array();
        }
        return $rows;
    }

    /** A profile's highlights as a list of strings (from a row, its JSON, or a list). */
    public static function highlights_of($row) {
        $raw = is_object($row) ? (isset($row->highlights) ? $row->highlights : null) : $row;
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $out = array();
        foreach (is_array($list) ? $list : array() as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = (string) $item;
            }
        }
        return array_slice($out, 0, self::HIGHLIGHTS);
    }

    /** A profile's program roles, program key => role, keeping only programs that exist and roles in PROGRAM_ROLES. */
    public static function program_roles_of($row) {
        $raw = is_object($row) ? (isset($row->program_roles) ? $row->program_roles : null) : $row;
        $map = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $programs = self::programs();
        $out = array();
        foreach (is_array($map) ? $map : array() as $key => $role) {
            $key = (string) $key;
            if (isset($programs[$key]) && is_string($role) && array_key_exists($role, self::PROGRAM_ROLES)) {
                $out[$key] = $role;
            }
        }
        return $out;
    }

    /**
     * profile id => the grid role line for the Profiles list ("Lead coach,
     * Junior Academy · Coach, Reds Social League"), drafts included, with the
     * program parts in programs() order. The program roles come from the
     * rows list_profiles() just read; ids it didn't read cost one query for
     * all of them. Team parts (live ticks in $season, marked "(after Show
     * YYYY)" while it isn't announced) join these from 1.83.0, when ticks
     * exist.
     */
    public static function list_role_lines(array $profile_ids, $season) {
        global $wpdb;
        $ids = array();
        foreach ($profile_ids as $id) {
            if (is_scalar($id) && intval($id) > 0) {
                $ids[intval($id)] = true;
            }
        }
        $ids = array_keys($ids);
        if (empty($ids)) {
            return array();
        }
        $missing = array();
        foreach ($ids as $id) {
            if (!array_key_exists($id, self::$roles_cache)) {
                $missing[] = $id;
            }
        }
        if (!empty($missing)) {
            $found = $wpdb->get_results('SELECT id, program_roles FROM ' . self::profiles_table() . ' WHERE id IN (' . implode(',', array_map('intval', $missing)) . ')');
            foreach (is_array($found) ? $found : array() as $row) {
                self::$roles_cache[intval($row->id)] = $row->program_roles;
            }
        }
        $programs = self::programs();
        $out = array();
        foreach ($ids as $id) {
            $roles = self::program_roles_of(isset(self::$roles_cache[$id]) ? self::$roles_cache[$id] : null);
            $parts = array();
            foreach ($programs as $key => $program) {
                if (isset($roles[$key])) {
                    $parts[] = self::PROGRAM_ROLES[$roles[$key]] . ', ' . $program['label'];
                }
            }
            $out[$id] = implode(' · ', $parts);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Readers: programs and the announced seasons
    // ------------------------------------------------------------------

    /**
     * The programs a profile can hold a role on: key => array(label, accent
     * (blue | red), filter (0 | 1), order (0 to 999)), ordered by order then
     * key. The saved list, or DEFAULT_PROGRAMS while it has never been
     * saved. Keys never change once made.
     */
    public static function programs() {
        $saved = get_option(self::PROGRAMS_OPTION, null);
        $list = is_array($saved) ? $saved : self::DEFAULT_PROGRAMS;
        $out = array();
        foreach ($list as $key => $program) {
            if (!is_string($key) || preg_match('/^[a-z0-9_]{1,30}$/D', $key) !== 1 || !is_array($program)) {
                continue;
            }
            $label = isset($program['label']) && is_scalar($program['label']) ? trim((string) $program['label']) : '';
            if ($label === '') {
                continue;
            }
            $out[$key] = array(
                'label' => $label,
                'accent' => (isset($program['accent']) && $program['accent'] === 'red') ? 'red' : 'blue',
                'filter' => empty($program['filter']) ? 0 : 1,
                'order' => isset($program['order']) ? max(0, min(999, intval($program['order']))) : 0,
            );
        }
        $keys = array_keys($out);
        usort($keys, function ($a, $b) use ($out) {
            if ($out[$a]['order'] !== $out[$b]['order']) {
                return $out[$a]['order'] < $out[$b]['order'] ? -1 : 1;
            }
            return strcmp($a, $b);
        });
        $sorted = array();
        foreach ($keys as $key) {
            $sorted[$key] = $out[$key];
        }
        return $sorted;
    }

    /** The hash Programs and settings draws and save_programs() checks: md5 of the stored option exactly as stored. */
    public static function programs_hash() {
        return md5(serialize(get_option(self::PROGRAMS_OPTION)));
    }

    /** The seasons whose team coaching is on the website: season => array(at, by), newest first. */
    public static function announced() {
        $saved = get_option(self::ANNOUNCED_OPTION, array());
        $out = array();
        foreach (is_array($saved) ? $saved : array() as $season => $entry) {
            $season = (string) $season;
            if (!self::valid_season($season)) {
                continue;
            }
            $out[$season] = array(
                'at' => is_array($entry) && isset($entry['at']) ? (string) $entry['at'] : '',
                'by' => is_array($entry) && isset($entry['by']) ? intval($entry['by']) : 0,
            );
        }
        krsort($out);
        return $out;
    }

    public static function is_announced($season) {
        $season = is_scalar($season) ? (string) $season : '';
        if (!self::valid_season($season)) {
            return false;
        }
        $announced = self::announced();
        return isset($announced[$season]);
    }

    /** The newest announced season, or ''. */
    public static function website_season() {
        $seasons = array_keys(self::announced());
        return empty($seasons) ? '' : (string) $seasons[0];
    }

    // ------------------------------------------------------------------
    // Photo files outside the cards (9.8)
    // ------------------------------------------------------------------

    /**
     * The attachment ids the site keeps out of its public media listing:
     * every photo a profile holds, plus both ids of every photo_changed
     * history row (so a removed or swapped photo stays out) and every
     * photo_uploaded one (a file uploaded on the Coaches editor, saved on a
     * profile or not), minus the ids a profile shows right now (published,
     * passing guard_sql(), Full profile and its photo cleared). Two queries,
     * cached for the request.
     */
    public static function photo_withheld_ids() {
        global $wpdb;
        if (self::$withheld !== null) {
            return self::$withheld;
        }
        $held = array();
        $shown = array();
        $rows = $wpdb->get_results(
            "SELECT p.photo_id, (p.status = 'published' AND " . self::guard_sql('p') . " AND p.display_mode = 'full' AND p.photo_cleared_id = p.photo_id) AS shown
             FROM " . self::profiles_table() . ' p WHERE p.photo_id IS NOT NULL AND p.photo_id > 0'
        );
        foreach (is_array($rows) ? $rows : array() as $row) {
            $id = intval($row->photo_id);
            $held[$id] = true;
            if (intval($row->shown) === 1) {
                $shown[$id] = true;
            }
        }
        $details = $wpdb->get_col("SELECT detail FROM " . self::changes_table() . " WHERE event IN ('photo_changed', 'photo_uploaded')");
        foreach (is_array($details) ? $details : array() as $detail) {
            $data = json_decode((string) $detail, true);
            foreach (array('old', 'new') as $side) {
                if (is_array($data) && isset($data[$side]) && intval($data[$side]) > 0) {
                    $held[intval($data[$side])] = true;
                }
            }
        }
        self::$withheld = array_values(array_map('intval', array_keys(array_diff_key($held, $shown))));
        return self::$withheld;
    }

    /**
     * rest_attachment_query: leave withheld photos out of /wp/v2/media for
     * anyone without upload_files. WP_Query ignores post__not_in when
     * post__in is set (the include argument), so withheld ids are taken out
     * of post__in too.
     */
    public static function rest_media_query($args, $request = null) {
        if (current_user_can('upload_files')) {
            return $args;
        }
        $ids = self::photo_withheld_ids();
        if (empty($ids) || !is_array($args)) {
            return $args;
        }
        if (!empty($args['post__in'])) {
            $left = array_values(array_diff(array_map('intval', (array) $args['post__in']), $ids));
            $args['post__in'] = empty($left) ? array(0) : $left;
        }
        $not_in = isset($args['post__not_in']) ? array_map('intval', (array) $args['post__not_in']) : array();
        $args['post__not_in'] = array_values(array_unique(array_merge($not_in, $ids)));
        return $args;
    }

    /**
     * rest_prepare_attachment: /wp/v2/media/<id> of a withheld photo answers
     * as a missing post (404, rest_post_invalid_id), for anyone without
     * upload_files. It returns a response rather than a WP_Error because core
     * applies this filter twice for attachments, and the attachments
     * controller reads the first result as a response.
     */
    public static function rest_media_item($response, $post = null, $request = null) {
        if (!$post || current_user_can('upload_files') || !in_array(intval($post->ID), self::photo_withheld_ids(), true)) {
            return $response;
        }
        return new WP_REST_Response(array('code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.', 'data' => array('status' => 404)), 404);
    }

    /** Is this id a withheld coach photo? Reads photo_withheld_ids() only for an attachment. */
    private static function is_withheld($id) {
        $id = intval($id);
        return $id > 0 && get_post_type($id) === 'attachment' && in_array($id, self::photo_withheld_ids(), true);
    }

    /**
     * oembed_request_post_id: the embed endpoint (/oembed/1.0/embed?url=...)
     * answers a withheld photo's address as an unknown one (404), for anyone
     * without upload_files. Otherwise it would hand out the file's address,
     * its title and the uploader's name.
     */
    public static function oembed_post_id($post_id, $url = '') {
        if (intval($post_id) < 1 || current_user_can('upload_files') || !self::is_withheld($post_id)) {
            return $post_id;
        }
        return 0;
    }

    /**
     * template_redirect, priority 5: a withheld photo's attachment page is
     * the theme's 404 for anyone without upload_files, its feed and embed
     * included. set_404() keeps is_feed and the photo in the query, so the
     * photo is taken out of the query too: the request then gets the 404
     * template, never a feed or an embed that prints the file. The
     * canonical redirect is switched off for this request, or it would send
     * a 404 reached through ?attachment_id= on to the attachment's address
     * (the file itself where attachment pages are off); it is off too when
     * ?p= or ?page_id= names a withheld photo, which finds nothing, because
     * core would redirect that to the photo's address (its file name).
     */
    public static function withhold_attachment_page() {
        global $wp_query;
        $attachment = is_attachment();
        $missed = is_404() || (is_singular() && $wp_query instanceof WP_Query && empty($wp_query->post_count));
        if ((!$attachment && !$missed) || current_user_can('upload_files')) {
            return;
        }
        $asked = $missed ? max(intval(get_query_var('p')), intval(get_query_var('page_id')), intval(get_query_var('attachment_id'))) : 0;
        if ($asked > 0 && self::is_withheld($asked)) {
            add_filter('redirect_canonical', '__return_false');
        }
        $id = $attachment ? intval(get_queried_object_id()) : 0;
        if ($id < 1 || !self::is_withheld($id)) {
            return;
        }
        $wp_query->set_404();
        $wp_query->posts = array();
        $wp_query->post = null;
        $wp_query->post_count = 0;
        $wp_query->is_feed = false;
        $wp_query->queried_object = null;
        $wp_query->queried_object_id = null;
        $GLOBALS['post'] = null;
        status_header(404);
        add_filter('redirect_canonical', '__return_false');
    }

    /**
     * add_attachment: a file uploaded through the Coaches editor's media
     * picker (the upload carries UPLOAD_PARAM, see
     * TeamOversight_Coaches_Admin::upload_params()) by someone holding CAP
     * is recorded as photo_uploaded, so photo_withheld_ids() keeps it out of
     * the public listing from the moment it lands, saved on a profile or
     * not. The upload itself is core's, behind its own nonce.
     */
    public static function on_photo_uploaded($post_id) {
        $post_id = intval($post_id);
        if ($post_id < 1 || empty($_POST[self::UPLOAD_PARAM]) || !current_user_can(self::CAP) || !current_user_can('upload_files') || get_post_type($post_id) !== 'attachment') {
            return;
        }
        self::record_change(0, 'photo_uploaded', array('new' => $post_id), 'editor', get_current_user_id());
        self::log(self::LOG_PROFILE, 'Coach photo uploaded on the Coaches editor: attachment #' . $post_id . '.');
    }

    // ------------------------------------------------------------------
    // Writers: fields
    // ------------------------------------------------------------------

    /** Every field an editor sets, at its blank value: the shape clean_fields() returns. */
    private static function blank_fields() {
        return array(
            'public_name' => '',
            'intro' => '',
            'bio' => '',
            'accreditation' => '',
            'accreditation_other' => '',
            'highlights' => array(),
            'playing_background' => '',
            'program_roles' => array(),
            'display_mode' => 'full',
            'photo_id' => 0,
            'photo_cleared_id' => 0,
            'consent_date' => '',
            'consent_method' => '',
            'consent_scope' => '',
            'is_minor' => 0,
            'guardian_date' => '',
        );
    }

    /**
     * Check and clean what an editor typed: wp_unslash()ed input in,
     * array($fields, $errors) out. $fields has the columns an editor sets
     * (public_name, intro, bio, accreditation, accreditation_other,
     * highlights (a list), playing_background, program_roles (key => role),
     * display_mode, photo_id, photo_cleared_id, consent_date,
     * consent_method, consent_scope, is_minor, guardian_date; blank = '' or
     * 0). The input uses the same names, except that the Photo cleared box
     * posts photo_cleared (photo_cleared_id is read when it is absent, so
     * the output can be cleaned again unchanged).
     *
     * A value over its limit is refused with a message naming the field and
     * the limit, never cut short. The photo counts as cleared only when the
     * posted cleared id equals it and it is an image in the Media Library.
     * Contact details are refused in every public field.
     */
    public static function clean_fields(array $data) {
        $errors = array();
        $today = current_time('Y-m-d');
        $line = function ($key) use ($data) {
            return isset($data[$key]) && is_scalar($data[$key]) ? sanitize_text_field((string) $data[$key]) : '';
        };
        $code = function ($key) use ($data) {
            return isset($data[$key]) && is_scalar($data[$key]) ? sanitize_key((string) $data[$key]) : '';
        };
        $fields = self::blank_fields();
        $public = array();

        $fields['public_name'] = $line('public_name');
        if ($fields['public_name'] === '') {
            $errors[] = 'Add a public name.';
        }
        $public['The public name'] = array($fields['public_name'], self::NAME_MAX);
        $fields['intro'] = $line('intro');
        $public['The one-line intro'] = array($fields['intro'], self::INTRO_MAX);
        // The browser posts each line break as \r\n; count it as one character, as the editor's counter does.
        $bio = isset($data['bio']) && is_scalar($data['bio']) ? str_replace(array("\r\n", "\r"), "\n", (string) $data['bio']) : '';
        $fields['bio'] = sanitize_textarea_field($bio);
        $public['The short bio'] = array($fields['bio'], self::BIO_MAX);
        $fields['playing_background'] = $line('playing_background');
        $public['The playing background'] = array($fields['playing_background'], self::BACKGROUND_MAX);

        // Highlights: the first HIGHLIGHTS boxes; blanks are dropped.
        $posted = isset($data['highlights']) ? $data['highlights'] : array();
        if (is_string($posted)) {
            $decoded = json_decode($posted, true);
            $posted = is_array($decoded) ? $decoded : array($posted);
        }
        $n = 0;
        foreach (array_slice(array_values(is_array($posted) ? $posted : array()), 0, self::HIGHLIGHTS) as $item) {
            $n++;
            $text = is_scalar($item) ? sanitize_text_field((string) $item) : '';
            if ($text !== '') {
                $fields['highlights'][] = $text;
                $public['Highlight ' . $n] = array($text, self::HIGHLIGHT_MAX);
            }
        }

        // Accreditation: '' (not set: no chip) or a key; Other needs text that isn't a "none" answer.
        $acc = $code('accreditation');
        $other = $line('accreditation_other');
        if ($acc !== '' && !array_key_exists($acc, self::ACCREDITATIONS)) {
            $errors[] = 'Choose an accreditation from the list.';
            $acc = '';
        }
        if ($acc !== 'other') {
            $other = '';
        } else {
            $plain = trim((string) preg_replace('/\s+/u', ' ', $other));
            if ($plain === '') {
                $acc = '';
                $other = '';
            } elseif (preg_match('/^(none|none yet|nil|n\/?a|unaccredited|not accredited|not yet|no|-+)$/i', $plain) === 1) {
                $errors[] = 'Leave accreditation as Not set: the card simply shows no chip.';
            } else {
                $public['The Other accreditation'] = array($other, self::OTHER_MAX);
            }
        }
        $fields['accreditation'] = $acc;
        $fields['accreditation_other'] = $other;

        // Program roles: a blank role is no role.
        $posted = isset($data['program_roles']) ? $data['program_roles'] : array();
        if (is_string($posted)) {
            $decoded = json_decode($posted, true);
            $posted = is_array($decoded) ? $decoded : array();
        }
        $programs = self::programs();
        foreach (is_array($posted) ? $posted : array() as $key => $role) {
            $role = is_scalar($role) ? sanitize_key((string) $role) : '';
            if ($role === '') {
                continue;
            }
            $key = (string) $key;
            if (!isset($programs[$key])) {
                $errors[] = 'A program on this page no longer exists. Reload the page and set the roles again.';
            } elseif (!array_key_exists($role, self::PROGRAM_ROLES)) {
                $errors[] = 'Choose a role for ' . $programs[$key]['label'] . ' from the list.';
            } else {
                $fields['program_roles'][$key] = $role;
            }
        }
        ksort($fields['program_roles']);

        $mode = $code('display_mode');
        if ($mode === '') {
            $mode = 'full';
        }
        if ($mode !== 'full' && $mode !== 'name_only') {
            $errors[] = 'Choose Full profile or Name only.';
            $mode = 'full';
        }
        $fields['display_mode'] = $mode;

        // Photo: the attachment id as posted (the editor checks a newly chosen one is an image; a saved
        // one whose file has gone since is kept, so other fixes still save). Cleared only when the posted
        // cleared id is this photo and it is an image.
        $photo = isset($data['photo_id']) && is_scalar($data['photo_id']) ? max(0, intval($data['photo_id'])) : 0;
        $cleared = array_key_exists('photo_cleared', $data) ? $data['photo_cleared'] : (array_key_exists('photo_cleared_id', $data) ? $data['photo_cleared_id'] : 0);
        $cleared = is_scalar($cleared) ? intval($cleared) : 0;
        $fields['photo_id'] = $photo;
        $fields['photo_cleared_id'] = ($photo > 0 && $cleared === $photo && wp_attachment_is_image($photo)) ? $photo : 0;

        // Consent: a draft may leave any of it blank; Publish needs it all (publish_problem()).
        $dates = array('consent_date' => 'The consent date', 'guardian_date' => 'The parent or guardian date');
        foreach ($dates as $key => $label) {
            $typed = $line($key);
            if ($typed === '') {
                continue;
            }
            $date = TeamOversight_Fee_Timeline::parse_date($typed);
            if ($date === null) {
                $errors[] = $label . ' isn\'t a real date.';
            } elseif ($date > $today) {
                $errors[] = $label . ' can\'t be in the future.';
            } else {
                $fields[$key] = $date;
            }
        }
        $method = $code('consent_method');
        if ($method !== '' && !array_key_exists($method, self::CONSENT_METHODS)) {
            $errors[] = 'Choose how they agreed from the list.';
            $method = '';
        }
        $fields['consent_method'] = $method;
        $scope = $code('consent_scope');
        if ($scope !== '' && !array_key_exists($scope, self::CONSENT_SCOPES)) {
            $errors[] = 'Choose what they agreed to from the list.';
            $scope = '';
        }
        $fields['consent_scope'] = $scope;
        $fields['is_minor'] = empty($data['is_minor']) ? 0 : 1;

        // Lengths, then no contact details in anything the website prints.
        foreach ($public as $label => $check) {
            $length = mb_strlen($check[0], 'UTF-8');
            if ($length > $check[1]) {
                $errors[] = $label . ' is ' . $length . ' characters; the limit is ' . $check[1] . '.';
            }
            if (preg_match('/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', $check[0]) === 1) {
                $errors[] = 'Public text can\'t contain an email address.';
            }
            if (preg_match('/\+?\(?\d(?:[\s.\-()]*\d){8,}/', $check[0]) === 1) {
                $errors[] = 'Public text can\'t contain a phone number. If those are years, separate them with commas or \'to\'.';
            }
        }

        return array($fields, array_values(array_unique($errors)));
    }

    /** A stored profile row in clean_fields()' shape, to compare a save against. */
    private static function row_fields($row) {
        $f = self::blank_fields();
        foreach (array('public_name', 'intro', 'bio', 'accreditation', 'accreditation_other', 'playing_background', 'display_mode', 'consent_method', 'consent_scope', 'consent_date', 'guardian_date') as $key) {
            $f[$key] = isset($row->$key) && $row->$key !== null ? (string) $row->$key : $f[$key];
        }
        $f['highlights'] = self::highlights_of($row);
        $roles = json_decode((string) $row->program_roles, true);
        $f['program_roles'] = array();
        foreach (is_array($roles) ? $roles : array() as $key => $role) {
            $f['program_roles'][(string) $key] = (string) $role;
        }
        ksort($f['program_roles']);
        $f['photo_id'] = intval($row->photo_id);
        $f['photo_cleared_id'] = intval($row->photo_cleared_id);
        $f['is_minor'] = intval($row->is_minor);
        return $f;
    }

    /** field => array(old, new) for every field that differs; the photo fields have events of their own. */
    private static function diff(array $old, array $new) {
        $out = array();
        foreach ($new as $key => $value) {
            if ($key === 'photo_id' || $key === 'photo_cleared_id') {
                continue;
            }
            $was = isset($old[$key]) ? $old[$key] : null;
            if (is_array($value) ? ((array) $was === $value) : ((string) $was === (string) $value)) {
                continue;
            }
            $out[$key] = array($was, $value);
        }
        return $out;
    }

    /** The column => SQL value expression every profile save writes, in one order (NULL for a blank text, date or id). */
    private static function column_sql() {
        return array(
            'display_mode' => '%s',
            'public_name' => '%s',
            'intro' => '%s',
            'bio' => "NULLIF(%s, '')",
            'accreditation' => '%s',
            'accreditation_other' => '%s',
            'highlights' => "NULLIF(%s, '')",
            'playing_background' => '%s',
            'program_roles' => "NULLIF(%s, '')",
            'photo_id' => 'NULLIF(%d, 0)',
            'photo_cleared_id' => 'NULLIF(%d, 0)',
            'consent_date' => "NULLIF(%s, '')",
            'consent_method' => '%s',
            'consent_scope' => '%s',
            'is_minor' => '%d',
            'guardian_date' => "NULLIF(%s, '')",
        );
    }

    /** The values for column_sql(), in its order (highlights and program roles as JSON). */
    private static function column_values(array $f) {
        $out = array();
        foreach (array_keys(self::column_sql()) as $column) {
            $value = $f[$column];
            if ($column === 'highlights') {
                $value = empty($value) ? '' : (string) wp_json_encode(array_values($value));
            } elseif ($column === 'program_roles') {
                ksort($value);
                $value = empty($value) ? '' : (string) wp_json_encode($value);
            }
            $out[] = $value;
        }
        return $out;
    }

    /** The words a save's log line uses for the fields it changed. Never values. */
    private static function field_words(array $keys) {
        $words = array(
            'public_name' => 'name', 'intro' => 'intro', 'bio' => 'bio', 'accreditation' => 'accreditation',
            'accreditation_other' => 'accreditation', 'highlights' => 'highlights', 'playing_background' => 'background',
            'program_roles' => 'programs', 'display_mode' => 'display', 'consent_date' => 'consent', 'consent_method' => 'consent',
            'consent_scope' => 'consent', 'is_minor' => 'consent', 'guardian_date' => 'consent', 'photo' => 'photo',
        );
        $out = array();
        foreach ($keys as $key) {
            if (isset($words[$key])) {
                $out[$words[$key]] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * The photo events of a save, in order, as array(event, detail):
     * photo_changed (the old and new attachment ids, 0 = none), then
     * photo_uncleared / photo_cleared when the cleared photo changed.
     */
    private static function photo_events(array $was, array $now) {
        $events = array();
        if ($was['photo_id'] !== $now['photo_id']) {
            $events[] = array('photo_changed', array('old' => $was['photo_id'], 'new' => $now['photo_id']));
        }
        $cleared_before = ($was['photo_id'] > 0 && $was['photo_cleared_id'] === $was['photo_id']) ? $was['photo_id'] : 0;
        $cleared_after = ($now['photo_id'] > 0 && $now['photo_cleared_id'] === $now['photo_id']) ? $now['photo_id'] : 0;
        if ($cleared_before !== $cleared_after) {
            if ($cleared_before > 0) {
                $events[] = array('photo_uncleared', array('photo' => $cleared_before));
            }
            if ($cleared_after > 0) {
                $events[] = array('photo_cleared', array('photo' => $cleared_after));
            }
        }
        return $events;
    }

    /** Record photo_events() for a save. Returns whether anything was recorded. */
    private static function record_photo($id, array $was, array $now, $actor, $at) {
        $events = self::photo_events($was, $now);
        foreach ($events as $event) {
            self::record_change($id, $event[0], $event[1], 'editor', $actor, $at);
        }
        return !empty($events);
    }

    /** A writer's result. $open: line => the profile id the flash links that line to (the same-name warning). */
    private static function result($ok, $type, $message, array $lines = array(), $id = 0, array $open = array()) {
        return array(
            'ok' => (bool) $ok,
            'type' => $type,
            'message' => (string) $message,
            'lines' => array_values($lines),
            'id' => intval($id),
            'open' => $open,
        );
    }

    // ------------------------------------------------------------------
    // Writers: profiles
    // ------------------------------------------------------------------

    /**
     * Make a new draft profile (version 1) from what an editor typed (the
     * posted fields, or clean_fields()' output: it is cleaned here again).
     * Making one from a team place ($place_id, under the link lock) comes in
     * 1.83.0; until then a place id is refused. Records created (the fields
     * typed) and the photo events, and logs. Never inserts a tick. Warns
     * when another profile has the same public name.
     */
    public static function create_profile(array $fields, $place_id, $actor) {
        global $wpdb;
        if (intval($place_id) > 0) {
            return self::result(false, 'error', 'A profile can\'t be made from a team place yet. Use Add New.');
        }
        list($clean, $errors) = self::clean_fields($fields);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was saved.', $errors);
        }
        $columns = self::column_sql();
        $now = current_time('mysql');
        $args = array_merge(self::column_values($clean), array($now, $now));
        $done = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::profiles_table() . ' (user_id, status, ' . implode(', ', array_keys($columns)) . ', version, created_at, updated_at)
             VALUES (NULL, \'draft\', ' . implode(', ', $columns) . ', 1, %s, %s)',
            $args
        ));
        $id = $done ? intval($wpdb->insert_id) : 0;
        if ($id < 1) {
            return self::result(false, 'error', 'The profile could not be saved. Try again.');
        }
        $blank = self::blank_fields();
        self::record_change($id, 'created', self::diff($blank, $clean), 'editor', $actor, $now);
        self::record_photo($id, $blank, $clean, $actor, $now);
        self::log(self::LOG_PROFILE, 'Coach profile #' . $id . ': created (draft).');
        $same = self::same_name($id, $clean['public_name']);
        return self::result(true, empty($same) ? 'success' : 'warning', 'Saved as a draft.', array_keys($same), $id, $same);
    }

    /**
     * The save's same-name warning: the other profiles whose public name
     * equals this one (the collation folds case and accents), at most three,
     * by #id, as line => that profile's id (the flash links each line to
     * it). Plain text; the To do panel lists the pair too.
     */
    private static function same_name($id, $name) {
        global $wpdb;
        $name = (string) $name;
        if ($name === '') {
            return array();
        }
        $others = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::profiles_table() . ' WHERE public_name = %s AND id <> %d ORDER BY id LIMIT 3',
            $name, intval($id)
        ));
        $lines = array();
        foreach (is_array($others) ? $others : array() as $other) {
            $lines['Profile #' . intval($other) . ' is also called ' . $name . ': check it isn\'t the same person.'] = intval($other);
        }
        return $lines;
    }

    /**
     * Save an editor's changes to a profile drawn at $expect_version (the
     * posted fields, or clean_fields()' output: cleaned here again). One
     * UPDATE ... WHERE id AND version, raising the version and, while the
     * profile is published, stamping checked_at. A stale version saves
     * nothing: the cleaned fields go into the DRAFT_PREFIX transient for this
     * editor (1800 s) and the result says who changed it and when.
     *
     * Records updated (field => array(old, new)), the photo events, or
     * checked when a published profile was saved unchanged (the history row
     * and checked_at carry the same time, which is how the editor finds who
     * last checked it); then enforce_guard() may hide it. A draft saved with
     * nothing changed writes nothing (Nothing changed.), so every raise of
     * the version has its history row. Warns when another profile has the
     * same public name, and when the photo changed while the old file is
     * still in Media.
     */
    public static function update_profile($id, array $fields, $expect_version, $actor) {
        global $wpdb;
        $id = intval($id);
        $old = self::get_profile($id);
        if (!$old) {
            return self::result(false, 'error', 'That profile was not found. Reload the page.', array(), $id);
        }
        list($clean, $errors) = self::clean_fields($fields);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was saved.', $errors, $id);
        }
        if (intval($old->version) !== intval($expect_version)) {
            return self::stale($id, $clean, $actor);
        }
        $was = self::row_fields($old);
        $published = (string) $old->status === 'published';
        $diff = self::diff($was, $clean);
        if (!$published && empty($diff) && empty(self::photo_events($was, $clean))) {
            $same = self::same_name($id, $clean['public_name']);
            return self::result(true, empty($same) ? 'success' : 'warning', 'Nothing changed.', array_keys($same), $id, $same);
        }
        $now = current_time('mysql');
        $set = array();
        foreach (self::column_sql() as $column => $value_sql) {
            $set[] = $column . ' = ' . $value_sql;
        }
        $args = self::column_values($clean);
        $args[] = $now;
        if ($published) {
            $args[] = $now;
        }
        $args[] = $id;
        $args[] = intval($expect_version);
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::profiles_table() . ' SET ' . implode(', ', $set) . ', version = version + 1, updated_at = %s'
            . ($published ? ', checked_at = %s' : '') . ' WHERE id = %d AND version = %d',
            $args
        ));
        if ($done === false) {
            return self::result(false, 'error', 'The profile could not be saved. Try again.', array(), $id);
        }
        if ($done !== 1) {
            return self::stale($id, $clean, $actor);
        }

        if (!empty($diff)) {
            self::record_change($id, 'updated', $diff, 'editor', $actor, $now);
        }
        $photo = self::record_photo($id, $was, $clean, $actor, $now);
        if (empty($diff) && !$photo && $published) {
            self::record_change($id, 'checked', array(), 'editor', $actor, $now);
        }
        $words = self::field_words(array_merge(array_keys($diff), $photo ? array('photo') : array()));
        self::log(self::LOG_PROFILE, 'Coach profile #' . $id . ': saved' . (empty($words) ? ' with no changes' : ' (' . implode(', ', $words) . ')') . '.', intval($old->user_id));

        $guard = self::enforce_guard($id, $actor);
        $same = self::same_name($id, $clean['public_name']);
        $lines = array_keys($same);
        if ($was['photo_id'] > 0 && $was['photo_id'] !== $clean['photo_id'] && get_post_type($was['photo_id']) === 'attachment') {
            $lines[] = 'The previous photo file (attachment #' . $was['photo_id'] . ') is still in Media. Remove photo only takes a photo off the profile: if the file shouldn\'t stay, ask an administrator to delete it in Media.';
        }
        if ($guard['type'] === 'warning') {
            return self::result(true, 'warning', $guard['message'], $lines, $id, $same);
        }
        return self::result(true, empty($lines) ? 'success' : 'warning', $published ? 'Profile updated.' : 'Saved as a draft.', $lines, $id, $same);
    }

    /**
     * A save drawn at an old version: keep what was typed for this editor
     * and say who changed the profile since, and when. That is whoever last
     * raised the version: every writer that raises it stamps updated_at and
     * its history row with one time, while Still correct (a bulk checked
     * row) and the ticks leave the version alone, so their rows are skipped.
     */
    private static function stale($id, array $clean, $actor) {
        global $wpdb;
        $fresh = self::get_profile($id);
        if (!$fresh) {
            return self::result(false, 'error', 'That profile was not found. Reload the page.', array(), $id);
        }
        if (intval($actor) > 0) {
            set_transient(self::DRAFT_PREFIX . intval($actor) . '_' . intval($id), $clean, 1800);
        }
        $last = $wpdb->get_row($wpdb->prepare(
            'SELECT recorded_by FROM ' . self::changes_table() . " WHERE profile_id = %d AND recorded_at = %s AND event NOT LIKE 'tick\\_%%' AND NOT (event = 'checked' AND source = 'bulk') ORDER BY id DESC LIMIT 1",
            intval($id), (string) $fresh->updated_at
        ));
        $who = $last ? self::person_name($last->recorded_by) : 'someone';
        $when = mysql2date('j M Y, g:i a', $fresh->updated_at);
        return self::result(false, 'error', 'This profile was changed by ' . $who . ' at ' . $when . ' after you opened it. What you typed is below: check it against the History and save again.', array(), $id);
    }

    /**
     * Hide a published profile that no longer passes guard_sql() (a save
     * changed its consent or display): one UPDATE ... WHERE published AND
     * NOT guard. Returns a result whose type is 'warning' with "Saved and
     * hidden from the website: <the problem>" when it hid it, else 'success'
     * with no message. Records hidden_by_guard and logs only when it hid it.
     */
    public static function enforce_guard($id, $actor) {
        global $wpdb;
        $id = intval($id);
        $now = current_time('mysql');
        $hid = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::profiles_table() . " p SET p.status = 'draft', p.version = p.version + 1, p.updated_at = %s WHERE p.id = %d AND p.status = 'published' AND NOT " . self::guard_sql('p'),
            $now, $id
        ));
        if ($hid !== 1) {
            return self::result(true, 'success', '', array(), $id);
        }
        $row = self::get_profile($id);
        $problem = $row ? self::publish_problem($row) : '';
        self::record_change($id, 'hidden_by_guard', array('reason' => $problem), 'guard', $actor, $now);
        self::log(self::LOG_PROFILE, 'Coach profile #' . $id . ': saved and hidden (consent no longer covers it).', $row ? intval($row->user_id) : 0);
        return self::result(true, 'warning', 'Saved and hidden from the website: ' . $problem, array(), $id);
    }

    /**
     * Publish ($status 'published') or hide ('draft') a profile. The only
     * writer of 'published': Publish re-reads the row and refuses with
     * publish_problem() (the message is the bare sentence), then writes
     * WHERE draft AND guard_sql(), stamping checked_at. Hide writes WHERE
     * published; hiding twice changes nothing the second time. $source is
     * where it was pressed (editor, list, bulk). Records published or
     * hidden and logs.
     */
    public static function set_status($id, $status, $actor, $source) {
        global $wpdb;
        $id = intval($id);
        $source = in_array($source, array('editor', 'list', 'bulk', 'season'), true) ? $source : 'editor';
        $row = self::get_profile($id);
        if (!$row) {
            return self::result(false, 'error', 'That profile was not found. Reload the page.', array(), $id);
        }
        $table = self::profiles_table();
        $now = current_time('mysql');
        if ($status === 'published') {
            if ((string) $row->status === 'published') {
                return self::result(true, 'success', 'It is already shown on the website.', array(), $id);
            }
            $problem = self::publish_problem($row);
            if ($problem !== '') {
                return self::result(false, 'error', $problem, array(), $id);
            }
            $done = $wpdb->query($wpdb->prepare(
                "UPDATE $table p SET p.status = 'published', p.version = p.version + 1, p.checked_at = %s, p.updated_at = %s WHERE p.id = %d AND p.status = 'draft' AND " . self::guard_sql('p'),
                $now, $now, $id
            ));
            if ($done !== 1) {
                $fresh = self::get_profile($id);
                if ($fresh && (string) $fresh->status === 'published') {
                    return self::result(true, 'success', 'It is already shown on the website.', array(), $id);
                }
                $problem = $fresh ? self::publish_problem($fresh) : '';
                return self::result(false, 'error', $problem !== '' ? $problem : 'That profile changed while you were looking at it. Reload the page and try again.', array(), $id);
            }
            self::record_change($id, 'published', array(), $source, $actor, $now);
            self::log(self::LOG_PROFILE, 'Coach profile #' . $id . ': published.', intval($row->user_id));
            return self::result(true, 'success', 'Profile published.', array(), $id);
        }
        if ($status !== 'draft') {
            return self::result(false, 'error', 'Choose Publish or Hide.', array(), $id);
        }
        $done = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = 'draft', version = version + 1, updated_at = %s WHERE id = %d AND status = 'published'",
            $now, $id
        ));
        if ($done !== 1) {
            return self::result(true, 'success', 'It is already hidden from the website.', array(), $id);
        }
        self::record_change($id, 'hidden', array(), $source, $actor, $now);
        self::log(self::LOG_PROFILE, 'Coach profile #' . $id . ': hidden from the website.', intval($row->user_id));
        return self::result(true, 'success', 'Hidden from the website.', array(), $id);
    }

    /**
     * Still correct: stamp checked_at on shown profiles (at most BULK_MAX
     * ids; one compare-and-set UPDATE per id, so each affected id records
     * its own checked row). The edit counter is left alone, so an editor
     * with the profile open can still save. One log line naming the ids.
     */
    public static function mark_checked(array $ids, $actor) {
        global $wpdb;
        $clean = array();
        foreach ($ids as $id) {
            if (is_scalar($id) && intval($id) > 0) {
                $clean[intval($id)] = true;
            }
        }
        $clean = array_slice(array_keys($clean), 0, self::BULK_MAX);
        $now = current_time('mysql');
        $done = array();
        foreach ($clean as $id) {
            $n = $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::profiles_table() . " SET checked_at = %s WHERE id = %d AND status = 'published'",
                $now, $id
            ));
            if ($n === 0) {
                // MySQL counts changed rows only: a profile already stamped this second (just published) matched without changing.
                $n = intval($wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . self::profiles_table() . " WHERE id = %d AND status = 'published' AND checked_at = %s",
                    $id, $now
                )));
            }
            if ($n === 1) {
                self::record_change($id, 'checked', array(), 'bulk', $actor, $now);
                $done[] = $id;
            }
        }
        if (!empty($done)) {
            self::log(self::LOG_PROFILE, 'Coach profile' . (count($done) === 1 ? ' #' : 's #') . implode(', #', $done) . ': marked still correct.');
        }
        $skipped = count($clean) - count($done);
        $lines = $skipped > 0 ? array($skipped === 1 ? '1 profile isn\'t shown on the website, so it wasn\'t marked.' : $skipped . ' profiles aren\'t shown on the website, so they weren\'t marked.') : array();
        if (empty($done)) {
            return self::result(false, 'warning', 'Nothing was marked: only shown profiles can be marked still correct.', array());
        }
        return self::result(true, empty($lines) ? 'success' : 'warning', (count($done) === 1 ? '1 profile' : count($done) . ' profiles') . ' marked still correct.', $lines);
    }

    // ------------------------------------------------------------------
    // Writers: programs
    // ------------------------------------------------------------------

    /** A new program's key, made from its label once and never changed: lower-case letters, digits and _, unique with _2, _3... */
    private static function program_key($label, array $taken) {
        $base = preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', sanitize_title($label)));
        $base = $base === '' || ctype_digit($base) ? 'program' . ($base === '' ? '' : '_' . $base) : $base;
        $base = substr($base, 0, 30);
        $key = $base;
        for ($n = 2; isset($taken[$key]); $n++) {
            $key = substr($base, 0, 30 - strlen('_' . $n)) . '_' . $n;
        }
        return $key;
    }

    /**
     * Save Programs and settings' program table. $rows is keyed by program
     * key: array(label, accent, filter, order, remove) for an existing
     * program (one left out is kept as it is); any entry under a key that is
     * not an existing program, with a label, is a new program (its key is
     * made from the label, e.g. $rows['new'] = array('label' => ...,
     * 'accent' => ...)). Refused when the stored option no longer hashes to
     * $expect_hash (programs_hash() when the page was drawn), and refused
     * while a removed program still has a role on any profile. Labels are
     * required and at most 60 characters, accents blue or red, orders 0 to
     * 999. Records programs_saved (each added and removed program's entry,
     * each changed one's old and new entry) and logs.
     */
    public static function save_programs(array $rows, $expect_hash, $actor) {
        global $wpdb;
        if ((string) $expect_hash !== self::programs_hash()) {
            return self::result(false, 'error', 'The programs were changed after this page loaded, so nothing was saved. Reload the page and try again.');
        }
        $current = self::programs();
        $next = array();
        $errors = array();
        $added = array();
        $removed = array();
        $changed = array();
        $read = function ($row, $program, $label_name) use (&$errors) {
            $label = isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field((string) $row['label']) : $program['label'];
            if ($label === '') {
                $errors[] = 'Every program needs a label.';
            } elseif (mb_strlen($label, 'UTF-8') > 60) {
                $errors[] = 'The label "' . $label_name . '" is ' . mb_strlen($label, 'UTF-8') . ' characters; the limit is 60.';
            }
            $accent = isset($row['accent']) && is_scalar($row['accent']) && (string) $row['accent'] !== '' ? (string) $row['accent'] : $program['accent'];
            if ($accent !== 'blue' && $accent !== 'red') {
                $errors[] = 'Choose blue or red for ' . $label_name . '.';
                $accent = $program['accent'];
            }
            $order = $program['order'];
            if (isset($row['order'])) {
                $typed = is_scalar($row['order']) ? trim((string) $row['order']) : '';
                if (preg_match('/^\d{1,3}$/D', $typed) === 1) {
                    $order = intval($typed);
                } else {
                    $errors[] = 'The order for ' . $label_name . ' must be a whole number from 0 to 999.';
                }
            }
            $filter = array_key_exists('filter', $row) ? (empty($row['filter']) ? 0 : 1) : $program['filter'];
            return array('label' => $label, 'accent' => $accent, 'filter' => $filter, 'order' => $order);
        };
        foreach ($current as $key => $program) {
            $row = isset($rows[$key]) && is_array($rows[$key]) ? $rows[$key] : null;
            if ($row === null) {
                $next[$key] = $program;
                continue;
            }
            if (!empty($row['remove'])) {
                $removed[] = $key;
                continue;
            }
            $next[$key] = $read($row, $program, $program['label']);
            if ($next[$key] !== $program) {
                $changed[] = $key;
            }
        }
        $last_order = 0;
        foreach ($next as $program) {
            $last_order = max($last_order, $program['order']);
        }
        foreach ($rows as $key => $row) {
            if (isset($current[(string) $key]) || !is_array($row)) {
                continue;
            }
            $label = isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            if ($label === '') {
                continue;
            }
            $defaults = array('label' => $label, 'accent' => 'blue', 'filter' => 1, 'order' => min(999, $last_order + 10));
            $program = $read($row, $defaults, $label);
            $new_key = self::program_key($label, array_merge($current, $next));
            $next[$new_key] = $program;
            $added[] = $new_key;
            $last_order = max($last_order, $program['order']);
        }

        // A removed program must have no role on any profile, drafts included.
        if (!empty($removed)) {
            $held = array_fill_keys($removed, 0);
            $stored = $wpdb->get_col('SELECT program_roles FROM ' . self::profiles_table() . ' WHERE program_roles IS NOT NULL');
            foreach (is_array($stored) ? $stored : array() as $json) {
                $map = json_decode((string) $json, true);
                foreach ($removed as $key) {
                    if (is_array($map) && !empty($map[$key])) {
                        $held[$key]++;
                    }
                }
            }
            foreach ($held as $key => $n) {
                if ($n > 0) {
                    $errors[] = ($n === 1 ? '1 profile still has' : $n . ' profiles still have') . ' a role on ' . $current[$key]['label'] . ': set ' . ($n === 1 ? 'it' : 'them') . ' to blank first.';
                }
            }
        }
        if (!empty($errors)) {
            return self::result(false, 'error', 'The programs were not saved.', array_values(array_unique($errors)));
        }
        if (empty($added) && empty($removed) && empty($changed)) {
            return self::result(true, 'success', 'Nothing changed.');
        }
        update_option(self::PROGRAMS_OPTION, $next, false);
        // The values, not just the keys, so the history alone rebuilds every past list (a removed program's
        // entry, a renamed one's old label).
        $detail = array('added' => array(), 'removed' => array(), 'changed' => array());
        foreach ($added as $key) {
            $detail['added'][$key] = $next[$key];
        }
        foreach ($removed as $key) {
            $detail['removed'][$key] = $current[$key];
        }
        foreach ($changed as $key) {
            $detail['changed'][$key] = array($current[$key], $next[$key]);
        }
        self::record_change(0, 'programs_saved', $detail, 'settings', $actor);
        $parts = array();
        foreach (array('added' => $added, 'removed' => $removed, 'changed' => $changed) as $word => $keys) {
            if (!empty($keys)) {
                $parts[] = $word . ' ' . implode(', ', $keys);
            }
        }
        self::log(self::LOG_WEBSITE, 'Programs saved: ' . implode('; ', $parts) . '.');
        return self::result(true, 'success', 'Programs saved.');
    }

    // ------------------------------------------------------------------
    // Writers: the Coach bios editor role (the Editors box)
    // ------------------------------------------------------------------

    /** Only someone who can manage the site and promote users changes who holds the role. */
    private static function may_change_editors($actor) {
        return intval($actor) > 0 && user_can(intval($actor), 'manage_options') && user_can(intval($actor), 'promote_users');
    }

    private static function plain_name($user) {
        return wp_specialchars_decode((string) $user->display_name, ENT_QUOTES);
    }

    /** A history row's person as plain text: the daily check for nobody, and a note for an account that has gone. */
    private static function person_name($user_id) {
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return 'the daily check';
        }
        $user = get_userdata($user_id);
        return $user ? self::plain_name($user) : 'an account that no longer exists';
    }

    /**
     * Give the role to the account with that email address, else that login.
     * add_role() keeps customer and the tier role. Refuses administrators
     * (they hold CAP already). Records editor_added and logs.
     */
    public static function grant_editor($who, $actor) {
        if (!self::may_change_editors($actor)) {
            return self::result(false, 'error', 'Only an administrator can change who holds the Coach bios editor role.');
        }
        $who = trim((string) $who);
        if ($who === '') {
            return self::result(false, 'error', 'Type an email address or login.');
        }
        $user = get_user_by('email', $who);
        if (!$user) {
            $user = get_user_by('login', $who);
        }
        if (!$user) {
            return self::result(false, 'error', 'No account has that email address or login.');
        }
        return self::give_role($user, $actor, 'Coach bios editor role given to user #' . intval($user->ID) . '.');
    }

    /** The Editors box's Re-add, for an account in lost_editors() only. */
    public static function grant_editor_by_id($user_id, $actor) {
        if (!self::may_change_editors($actor)) {
            return self::result(false, 'error', 'Only an administrator can change who holds the Coach bios editor role.');
        }
        $user_id = intval($user_id);
        if (!in_array($user_id, self::lost_editors(), true)) {
            return self::result(false, 'error', 'That account isn\'t on the lost list any more. Reload the page.');
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return self::result(false, 'error', 'That account no longer exists.');
        }
        return self::give_role($user, $actor, 'Coach bios editor role given back to user #' . $user_id . ' (it was removed outside the Editors box).');
    }

    private static function give_role($user, $actor, $log_message) {
        if (user_can($user, 'manage_options')) {
            return self::result(false, 'error', self::plain_name($user) . ' is an administrator, so already has the Coaches screens.');
        }
        if (in_array(self::ROLE, (array) $user->roles, true)) {
            return self::result(true, 'success', self::plain_name($user) . ' already holds the Coach bios editor role.');
        }
        self::ensure_role();
        $user->add_role(self::ROLE);
        self::record_change(0, 'editor_added', array('user' => intval($user->ID)), 'settings', $actor);
        self::log(self::LOG_EDITOR, $log_message, intval($user->ID));
        return self::result(true, 'success', 'Gave the Coach bios editor role to ' . self::plain_name($user) . '. Their other roles are unchanged.');
    }

    /**
     * Take the role off an account (the Editors box's Remove). remove_role()
     * keeps every other role; the role watch is told it was the Editors box.
     * Records editor_removed and logs.
     */
    public static function revoke_editor($user_id, $actor) {
        if (!self::may_change_editors($actor)) {
            return self::result(false, 'error', 'Only an administrator can change who holds the Coach bios editor role.');
        }
        $user = get_userdata(intval($user_id));
        if (!$user) {
            return self::result(false, 'error', 'That account no longer exists.');
        }
        if (!in_array(self::ROLE, (array) $user->roles, true)) {
            return self::result(true, 'success', self::plain_name($user) . ' doesn\'t hold the Coach bios editor role.');
        }
        self::$own_role_change = true;
        $user->remove_role(self::ROLE);
        self::$own_role_change = false;
        self::record_change(0, 'editor_removed', array('user' => intval($user->ID)), 'settings', $actor);
        self::log(self::LOG_EDITOR, 'Coach bios editor role removed from user #' . intval($user->ID) . ' on the Editors box.', intval($user->ID));
        return self::result(true, 'success', 'Removed the Coach bios editor role from ' . self::plain_name($user) . '. Their other roles are unchanged.');
    }

    /** The Editors box's Leave off, for an account in lost_editors() only: records editor_lost_ok. Never touches roles. */
    public static function leave_editor_off($user_id, $actor) {
        if (!self::may_change_editors($actor)) {
            return self::result(false, 'error', 'Only an administrator can change who holds the Coach bios editor role.');
        }
        $user_id = intval($user_id);
        if (!in_array($user_id, self::lost_editors(), true)) {
            return self::result(false, 'error', 'That account isn\'t on the lost list any more. Reload the page.');
        }
        self::record_change(0, 'editor_lost_ok', array('user' => $user_id), 'settings', $actor);
        self::log(self::LOG_EDITOR, 'Coach bios editor role left off for user #' . $user_id . ' (it was removed outside the Editors box).', $user_id);
        return self::result(true, 'success', 'Left the role off. That account is no longer listed.');
    }

    /**
     * Account ids that lost the role outside the Editors box: their newest
     * editor_added / editor_removed / editor_lost / editor_lost_ok row is
     * editor_added or editor_lost, the account still exists and it doesn't
     * hold the role now. Catches the role going by any route, one that fired
     * no hook included. An administrator is left out: one made an
     * administrator on Users → Edit loses the role that way, but holds CAP
     * anyway and Re-add refuses them. One history query plus cache_users().
     * Writes nothing.
     */
    public static function lost_editors() {
        return array_keys(self::lost_editor_dates());
    }

    /** lost_editors() with the time of each account's newest history row: user id => recorded_at. */
    public static function lost_editor_dates() {
        global $wpdb;
        if (self::$lost !== null) {
            return self::$lost;
        }
        $rows = $wpdb->get_results('SELECT event, detail, recorded_at FROM ' . self::changes_table() . " WHERE profile_id = 0 AND event IN ('editor_added', 'editor_removed', 'editor_lost', 'editor_lost_ok') ORDER BY id");
        $newest = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $data = json_decode((string) $row->detail, true);
            $user_id = is_array($data) && isset($data['user']) ? intval($data['user']) : 0;
            if ($user_id > 0) {
                $newest[$user_id] = $row;
            }
        }
        $ids = array();
        foreach ($newest as $user_id => $row) {
            if ($row->event === 'editor_added' || $row->event === 'editor_lost') {
                $ids[] = $user_id;
            }
        }
        $out = array();
        if (!empty($ids)) {
            cache_users($ids);
            foreach ($ids as $user_id) {
                $user = get_userdata($user_id);
                if ($user && !in_array(self::ROLE, (array) $user->roles, true) && !user_can($user, 'manage_options')) {
                    $out[$user_id] = (string) $newest[$user_id]->recorded_at;
                }
            }
        }
        self::$lost = $out;
        return $out;
    }

    // ------------------------------------------------------------------
    // History and the activity log
    // ------------------------------------------------------------------

    /**
     * Add one history row: detail is JSON (NULL when empty), $actor 0 is
     * stored as NULL (the daily check). $at defaults to now; a writer passes
     * its own time so the row matches the times it stamped. Every writer
     * calls this, so it also drops the request caches.
     */
    private static function record_change($profile_id, $event, array $detail, $source, $actor, $at = '') {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::changes_table() . " (profile_id, event, detail, source, recorded_by, recorded_at) VALUES (%d, %s, NULLIF(%s, ''), %s, NULLIF(%d, 0), %s)",
            intval($profile_id), (string) $event, empty($detail) ? '' : (string) wp_json_encode($detail), (string) $source, intval($actor), $at !== '' ? $at : current_time('mysql')
        ));
        self::flush_cache();
    }

    /**
     * The one way Coaches writes to the activity log: $type must be one of
     * LOG_PROFILE, LOG_WEBSITE or LOG_EDITOR (anything else is a programming
     * error the tests catch; here it writes nothing rather than throw after a
     * committed write). Messages name profiles and accounts by #id only and
     * are clipped to 500 characters.
     */
    private static function log($type, $message, $user_id = 0) {
        if (!in_array($type, array(self::LOG_PROFILE, self::LOG_WEBSITE, self::LOG_EDITOR), true) || !class_exists('TeamOversight_Log')) {
            return;
        }
        $message = mb_substr((string) $message, 0, 500);
        TeamOversight_Log::add($type, $message, $user_id ? array('user_id' => intval($user_id)) : array());
    }

    // ------------------------------------------------------------------
    // The To do panel
    // ------------------------------------------------------------------

    private static function given_sql() {
        return "'" . implode("','", self::CONSENT_GIVEN) . "'";
    }

    /** No consent recorded (date, how or what), on a profile that didn't ask not to be shown. */
    private static function consent_missing_sql($a) {
        return "({$a}.consent_method <> 'declined' AND ({$a}.consent_date IS NULL OR {$a}.consent_method NOT IN (" . self::given_sql() . ") OR {$a}.consent_scope NOT IN ('full','name_only')))";
    }

    /** Marked under 18 with no parent or guardian date. */
    private static function guardian_missing_sql($a) {
        return "({$a}.consent_method <> 'declined' AND {$a}.is_minor = 1 AND {$a}.guardian_date IS NULL)";
    }

    /**
     * A photo that isn't cleared and whose file is still in Media (a
     * declined profile's photo is a file to delete instead: photo_left_over;
     * one whose file has gone can't be cleared, and the editor says so
     * beside the photo).
     */
    private static function photo_waiting_sql($a) {
        global $wpdb;
        return "({$a}.photo_id > 0 AND ({$a}.photo_cleared_id IS NULL OR {$a}.photo_cleared_id <> {$a}.photo_id) AND {$a}.consent_method <> 'declined'"
            . " AND EXISTS (SELECT 1 FROM {$wpdb->posts} pw WHERE pw.ID = {$a}.photo_id AND pw.post_type = 'attachment'))";
    }

    /** Shown and not checked since check_cutoff(): one %s placeholder. */
    private static function needs_check_sql($a) {
        return "({$a}.status = 'published' AND ({$a}.checked_at IS NULL OR {$a}.checked_at < %s))";
    }

    /** CHECK_AFTER_DAYS ago, in site time. */
    private static function check_cutoff() {
        return wp_date('Y-m-d H:i:s', time() - self::CHECK_AFTER_DAYS * DAY_IN_SECONDS);
    }

    /** A Profiles list filter as array(SQL condition, placeholder values). */
    private static function filter_where($filter) {
        if ($filter === 'shown') {
            return array("p.status = 'published'", array());
        }
        if ($filter === 'hidden') {
            return array("p.status <> 'published'", array());
        }
        if ($filter === 'check') {
            return array(self::needs_check_sql('p'), array(self::check_cutoff()));
        }
        if ($filter === 'consent') {
            return array('(' . self::consent_missing_sql('p') . ' OR ' . self::guardian_missing_sql('p') . ')', array());
        }
        if ($filter === 'photo') {
            return array(self::photo_waiting_sql('p'), array());
        }
        return array('', array());
    }

    /** Every profile count the list and the To do panel need, from one grouped query (cached for the request). */
    private static function stats() {
        global $wpdb;
        if (self::$stats !== null) {
            return self::$stats;
        }
        $guard = self::guard_sql('p');
        // A cleared photo gone from Media matters only where it would show: a published Full profile.
        $gone = "p.status = 'published' AND p.display_mode = 'full' AND p.photo_id > 0 AND p.photo_cleared_id = p.photo_id AND p.consent_method <> 'declined' AND (a.ID IS NULL OR a.post_type <> 'attachment' OR a.post_mime_type NOT LIKE 'image/%%')";
        $consent = '(' . self::consent_missing_sql('p') . ' OR ' . self::guardian_missing_sql('p') . ')';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN p.status = 'published' THEN 1 ELSE 0 END) AS shown,
                SUM(CASE WHEN p.status <> 'published' THEN 1 ELSE 0 END) AS hidden,
                SUM(CASE WHEN " . self::needs_check_sql('p') . " THEN 1 ELSE 0 END) AS needs_check,
                SUM(CASE WHEN $consent THEN 1 ELSE 0 END) AS consent,
                SUM(CASE WHEN " . self::photo_waiting_sql('p') . " THEN 1 ELSE 0 END) AS photo,
                SUM(CASE WHEN p.status <> 'published' AND " . self::consent_missing_sql('p') . " THEN 1 ELSE 0 END) AS consent_draft,
                SUM(CASE WHEN p.status = 'published' AND NOT $guard THEN 1 ELSE 0 END) AS unguarded,
                MIN(CASE WHEN p.status = 'published' AND NOT $guard THEN p.id END) AS unguarded_first,
                SUM(CASE WHEN " . self::guardian_missing_sql('p') . " THEN 1 ELSE 0 END) AS guardian,
                MIN(CASE WHEN " . self::guardian_missing_sql('p') . " THEN p.id END) AS guardian_first,
                SUM(CASE WHEN $gone THEN 1 ELSE 0 END) AS photo_gone,
                MIN(CASE WHEN $gone THEN p.id END) AS photo_gone_first
             FROM " . self::profiles_table() . " p
             LEFT JOIN {$wpdb->posts} a ON a.ID = p.photo_id",
            self::check_cutoff()
        ));
        $out = array();
        foreach (array('total', 'shown', 'hidden', 'needs_check', 'consent', 'photo', 'consent_draft', 'unguarded', 'unguarded_first', 'guardian', 'guardian_first', 'photo_gone', 'photo_gone_first') as $key) {
            $out[$key] = $row && isset($row->$key) ? intval($row->$key) : 0;
        }
        self::$stats = $out;
        return $out;
    }

    private static function page_url($slug, array $args = array(), $anchor = '') {
        $url = admin_url('admin.php?page=' . $slug);
        foreach ($args as $key => $value) {
            $url .= '&' . $key . '=' . rawurlencode((string) $value);
        }
        return $url . ($anchor !== '' ? '#' . $anchor : '');
    }

    private static function profile_url($id) {
        return self::page_url('club-coaches-edit', array('profile' => intval($id)));
    }

    private static function todo_row($key, $state, $text, $url = '', $label = '', $media_url = '') {
        return array('key' => $key, 'state' => $state, 'text' => $text, 'url' => $url, 'label' => $label, 'media_url' => $media_url);
    }

    /**
     * The To do panel's rows for the Profiles screen: a list of array(key,
     * state ('warn' | 'bad'), text, url, label, media_url), the 'bad' rows
     * first. Only what needs doing is listed, so it clears itself when the
     * thing is fixed; it never blocks anything. Text is plain (the caller
     * escapes it) and names profiles by #id or public name and accounts by
     * #id, never an email. The Editors links and the Media links are given
     * to administrators only. $season is the focus season (the website
     * season, else this year). A fixed number of queries, however many
     * profiles there are. Writes nothing.
     */
    public static function todo($season) {
        global $wpdb;
        $season = is_scalar($season) && self::valid_season((string) $season) ? (string) $season : (string) current_time('Y');
        $profiles = self::profiles_table();
        $changes = self::changes_table();
        $admin = current_user_can('manage_options') && current_user_can('promote_users');
        $today = current_time('Y-m-d');
        $stale_cutoff = wp_date('Y-m-d H:i:s', time() - self::PHOTO_STALE_DAYS * DAY_IN_SECONDS);
        $s = self::stats();
        $rows = array();
        $list = function ($filter) {
            return self::page_url('club-coaches', array('filter' => $filter));
        };

        // Consent.
        if ($s['unguarded'] > 0) {
            $text = $s['unguarded'] === 1
                ? '1 shown profile doesn\'t have the consent it needs, so the website leaves it out: open it and fix the consent, or hide it.'
                : $s['unguarded'] . ' shown profiles don\'t have the consent they need, so the website leaves them out: open each one and fix the consent, or hide it.';
            if ($s['consent_draft'] > 0) {
                $text .= ' ' . ($s['consent_draft'] === 1 ? '1 other profile has' : $s['consent_draft'] . ' other profiles have') . ' no consent recorded.';
            }
            $rows[] = self::todo_row('consent_missing', 'bad', $text, $s['unguarded'] === 1 ? self::profile_url($s['unguarded_first']) : $list('consent'), $s['unguarded'] === 1 ? 'Open the profile' : 'Show them');
        } elseif ($s['consent_draft'] > 0) {
            $rows[] = self::todo_row('consent_missing', 'warn', $s['consent_draft'] === 1
                ? '1 profile has no consent recorded, so it can\'t be published.'
                : $s['consent_draft'] . ' profiles have no consent recorded, so they can\'t be published.', $list('consent'), 'Show them');
        }
        if ($s['guardian'] > 0) {
            $rows[] = self::todo_row('guardian_missing', 'warn', $s['guardian'] === 1
                ? '1 profile marked under 18 has no parent or guardian date.'
                : $s['guardian'] . ' profiles marked under 18 have no parent or guardian date.', $s['guardian'] === 1 ? self::profile_url($s['guardian_first']) : $list('consent'), $s['guardian'] === 1 ? 'Open the profile' : 'Show them');
        }

        // Photos: waiting, gone from Media, waiting too long, and files left on hidden profiles.
        if ($s['photo'] > 0) {
            $rows[] = self::todo_row('photo_waiting', 'warn', $s['photo'] === 1 ? '1 photo is waiting to be cleared.' : $s['photo'] . ' photos are waiting to be cleared.', $list('photo'), 'Show them');
        }
        if ($s['photo_gone'] > 0) {
            $rows[] = self::todo_row('photo_gone', 'bad', $s['photo_gone'] === 1
                ? '1 cleared photo is no longer in the Media Library: initials show instead.'
                : $s['photo_gone'] . ' cleared photos are no longer in the Media Library: initials show instead.', $s['photo_gone'] === 1 ? self::profile_url($s['photo_gone_first']) : self::page_url('club-coaches'), $s['photo_gone'] === 1 ? 'Open the profile' : 'Profiles');
        }
        $left_rows = array();
        $left = $wpdb->get_results("SELECT p.id, p.photo_id, p.consent_method FROM $profiles p JOIN {$wpdb->posts} a ON a.ID = p.photo_id AND a.post_type = 'attachment' WHERE p.status <> 'published' AND p.photo_id > 0 ORDER BY p.id LIMIT " . self::BULK_MAX);
        $left = is_array($left) ? $left : array();
        $hidden_at = array();
        $hidden_ids = array();
        foreach ($left as $row) {
            if ($row->consent_method !== 'declined') {
                $hidden_ids[] = intval($row->id);
            }
        }
        if (!empty($hidden_ids)) {
            $last = $wpdb->get_results("SELECT c.profile_id, c.event, c.recorded_at FROM $changes c
                JOIN (SELECT profile_id, MAX(id) AS id FROM $changes WHERE event IN ('published', 'hidden', 'hidden_by_guard') AND profile_id IN (" . implode(',', $hidden_ids) . ') GROUP BY profile_id) m ON m.id = c.id');
            foreach (is_array($last) ? $last : array() as $row) {
                if ($row->event !== 'published' && (string) $row->recorded_at < $stale_cutoff) {
                    $hidden_at[intval($row->profile_id)] = true;
                }
            }
        }
        foreach ($left as $row) {
            $id = intval($row->id);
            $photo = intval($row->photo_id);
            $declined = $row->consent_method === 'declined';
            if (!$declined && !isset($hidden_at[$id])) {
                continue;
            }
            $left_rows[$id] = self::todo_row('photo_left_over', $declined ? 'bad' : 'warn',
                'Profile #' . $id . ($declined ? ' asked not to be shown' : ' is hidden') . ' but still has a photo file (attachment #' . $photo . '): ask an administrator to delete it in Media' . ($declined ? '' : ' if it shouldn\'t stay') . '. Remove photo alone leaves the file there; once the file is deleted, open the profile and click Remove photo.',
                self::profile_url($id), 'Open the profile', $admin ? admin_url('upload.php?item=' . $photo) : '');
        }
        $stale = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, MAX(c.recorded_at) AS changed_at FROM $profiles p
             JOIN $changes c ON c.profile_id = p.id AND c.event = 'photo_changed'
             WHERE " . self::photo_waiting_sql('p') . '
             GROUP BY p.id HAVING changed_at < %s ORDER BY changed_at LIMIT 20',
            $stale_cutoff
        ));
        foreach (is_array($stale) ? $stale : array() as $row) {
            $id = intval($row->id);
            if (isset($left_rows[$id])) {
                continue;
            }
            $days = TeamOversight_Fee_Timeline::days_between(substr((string) $row->changed_at, 0, 10), $today);
            $rows[] = self::todo_row('photo_stale', 'warn', 'Profile #' . $id . '\'s photo has waited ' . $days . ' days to be cleared. Uploaded files can be opened by their link: clear it, or choose another (or click Remove photo) and ask an administrator to delete this file in Media.', self::profile_url($id), 'Open the profile');
        }
        foreach (array_slice($left_rows, 0, 20) as $row) {
            $rows[] = $row;
        }

        // Shown profiles not checked for a while.
        if ($s['needs_check'] > 0) {
            $months = intval(round(self::CHECK_AFTER_DAYS / 30));
            $rows[] = self::todo_row('needs_checking', 'warn', ($s['needs_check'] === 1
                ? '1 shown profile hasn\'t been checked for ' . $months . ' months.'
                : $s['needs_check'] . ' shown profiles haven\'t been checked for ' . $months . ' months.')
                . ' Open each and press Update, or tick them on the list and choose Still correct.', $list('check'), 'Show them');
        }

        // A linked account that was deleted.
        $gone = $wpdb->get_col("SELECT p.id FROM $profiles p LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id WHERE p.user_id IS NOT NULL AND u.ID IS NULL ORDER BY p.id LIMIT 20");
        foreach (is_array($gone) ? $gone : array() as $id) {
            $rows[] = self::todo_row('link_gone', 'bad', 'Profile #' . intval($id) . ' is linked to an account that no longer exists. Open it and click Unlink account.', self::profile_url($id), 'Open the profile');
        }

        // Two profiles with one name.
        $same = $wpdb->get_results("SELECT p.public_name, COUNT(*) AS n FROM $profiles p WHERE p.public_name <> '' GROUP BY p.public_name HAVING COUNT(*) > 1 ORDER BY p.public_name LIMIT 20");
        foreach (is_array($same) ? $same : array() as $row) {
            $n = intval($row->n);
            $rows[] = self::todo_row('same_name', 'warn', ($n === 2 ? 'Two' : $n) . ' profiles are called ' . $row->public_name . ': check they aren\'t the same person.', self::page_url('club-coaches', array('s' => $row->public_name)), 'Show them');
        }

        // Shown profiles that appear on no page: no program role and no team shown in the focus season.
        $team_sql = self::is_announced($season)
            ? $wpdb->prepare('EXISTS (SELECT 1 FROM ' . self::ticks_table() . " t WHERE t.profile_id = p.id AND t.state = 'shown' AND t.season = %s)", $season)
            : '0';
        $shown = $wpdb->get_results("SELECT p.id, p.program_roles, $team_sql AS has_team FROM $profiles p WHERE p.status = 'published' AND " . self::guard_sql('p') . ' ORDER BY p.id LIMIT ' . self::LIST_MAX);
        $no_role = array();
        foreach (is_array($shown) ? $shown : array() as $row) {
            if (intval($row->has_team) !== 1 && empty(self::program_roles_of($row))) {
                $no_role[] = intval($row->id);
            }
        }
        if (!empty($no_role)) {
            $n = count($no_role);
            $rows[] = self::todo_row('no_role', 'warn', $n === 1
                ? '1 shown profile has no program role and no team shown, so it appears on no page.'
                : $n . ' shown profiles have no program role and no team shown, so they appear on no page.', $n === 1 ? self::profile_url($no_role[0]) : $list('shown'), $n === 1 ? 'Open the profile' : 'Show them');
        }

        // The editor role: enough holders, and any lost outside the Editors box.
        $editors_url = $admin ? self::page_url('club-coaches-settings', array(), 'editors') : '';
        $holders = count(get_users(array('role' => self::ROLE, 'fields' => 'ID', 'number' => 3, 'count_total' => false)));
        if ($holders < 3) {
            $rows[] = self::todo_row('editors', 'warn', ($holders === 0
                ? 'Nobody holds the Coach bios editor role yet.'
                : 'Only ' . $holders . ($holders === 1 ? ' person holds' : ' people hold') . ' the Coach bios editor role.')
                . ' Keep at least three.', $editors_url, $editors_url !== '' ? 'Editors' : '');
        }
        foreach (self::lost_editors() as $user_id) {
            $rows[] = self::todo_row('editor_lost', 'warn', 'User #' . intval($user_id) . ' lost the Coach bios editor role outside the Editors box (a save on Users → Edit keeps only the role in its dropdown). Re-add it on the Editors box, or choose Leave off there if that was meant.', $editors_url, $editors_url !== '' ? 'Editors' : '');
        }

        $bad = array();
        $warn = array();
        foreach ($rows as $row) {
            if ($row['state'] === 'bad') {
                $bad[] = $row;
            } else {
                $warn[] = $row;
            }
        }
        return array_merge($bad, $warn);
    }
}
