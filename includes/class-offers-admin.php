<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The committee's side of offers (1.76.0): VVL Oversight > Offers to
 * approve (slug team-oversight-offers, manage_options), with its count
 * badge and tabs (To approve, Offers out, Locked in, Held deposits, Closed,
 * Not selected), bulk approval, and the treasurer's Held deposits list.
 *
 * The page draws and the handler posts; every state change goes through a
 * TeamOversight_Offers method (approve(), approve_many(), reject(),
 * admin_remind(), admin_extend(), admin_move_to_declined(), admin_withdraw(),
 * and the money methods pull_out(), retry_placement(), mark_credit(),
 * forfeit_held(), apply_to_new_offer(), reverse_forfeit()), which check
 * can_approve() and the compare-and-set themselves. Nothing here writes to
 * team_offers, and a page render writes nothing (the one exception is the
 * once-a-minute reconcile() the design asks the page to run).
 *
 * Rules (design section 7 and 10):
 *  - ONE admin-post action, murvc_offer_admin, for every button. Each button
 *    has its own nonce: murvc_offer_admin_<offer_action>_<id>; the bulk
 *    buttons share murvc_offer_admin_bulk. manage_options and can_approve()
 *    are checked before anything runs. Post/Redirect/Get with a flash summary.
 *  - GET parameters are validated: season /^\d{4}$/D, tab from a whitelist,
 *    page an integer, team a short code, sort from a whitelist.
 *  - The committee never approves a number it did not see: every Approve
 *    posts the quote and deposit the row showed, and approve() refuses a row
 *    whose figures moved (a "changed, review" line, with the new figures).
 *  - Names, notes and reasons are member-controlled text: escaped on the page,
 *    csv_cell() in the CSV, never put into inline JavaScript.
 *  - The Held deposits list is the money builder's ledger-derived
 *    TeamOversight_Offers::held_deposits() / forfeited_deposits(); this
 *    class runs no SQL on team_offers (it reads through list_offers() and
 *    counts_by_state()).
 */
class TeamOversight_Offers_Admin {

    const SLUG = 'team-oversight-offers';
    /** The VVL Oversight top-level menu. */
    const PARENT_SLUG = 'team-oversight';
    const PER_PAGE = 40;
    /** The most rows one list loads (list_offers() caps at 500). */
    const LOAD_LIMIT = 500;
    /** At most 200 offers in one bulk request. */
    const BULK_MAX = 200;
    const FLASH_PREFIX = 'murvc_offer_admin_flash_';
    const RECONCILE_TRANSIENT = 'murvc_offers_admin_reconcile';
    const CSV_NONCE = 'murvc_offer_held_csv';

    /** tab slug => label. The default tab is the first. */
    const TABS = array(
        'approve' => 'To approve',
        'out' => 'Waiting on players',
        'locked' => 'Locked in',
        'held' => 'Held deposits',
        'closed' => 'Closed',
        'notselected' => 'Not selected',
        'setup' => 'Setup',
    );

    /** The single-row buttons (offer_action). */
    const ACTIONS = array('approve', 'reject', 'remind', 'extend', 'move_declined', 'withdraw', 'retry', 'pull_out', 'mark_credit', 'forfeit', 'apply', 'reverse', 'resend', 'ns_send', 'ns_stop');
    /**
     * The buttons above the To approve list, and (1.80.0) Send all above the
     * Not selected tab's "not told yet" list. (1.79.x's cancel_ns,
     * release_ns, ns_release_held and ns_cancel_held are gone: a page from
     * before gets "This page has expired" and changes nothing.)
     */
    const BULK_ACTIONS = array('bulk_approve', 'approve_all', 'bulk_reject', 'ns_send_all');

    /** Per-request caches (the murvc_offers_cache_flushed action clears them). */
    private static $load_cache = array();
    private static $held_cache = array();
    private static $forfeited_cache = array();
    private static $teams_cache = array();

    /** What the page is showing now: season, tab, paged, team, sort (every button posts it back). */
    private static $view = array('season' => '', 'tab' => 'approve', 'paged' => 1, 'team' => '', 'sort' => 'age');

    /** Per-row <form>s, printed after the table (a button points at its form with the form="" attribute, so forms never nest). */
    private static $forms = array();

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_murvc_offer_admin', array(__CLASS__, 'handle_action'));
        add_action('admin_post_murvc_offer_held_csv', array(__CLASS__, 'held_csv'));
        add_action('murvc_offers_cache_flushed', array(__CLASS__, 'reset_cache'));
        if (is_admin()) {
            // After TeamOversight_Admin::add_admin_menu() (priority 10) has made the VVL Oversight menu.
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 20);
        }
    }

    /** Forget what this request loaded (every offer write fires murvc_offers_cache_flushed). */
    public static function reset_cache() {
        self::$load_cache = array();
        self::$held_cache = array();
        self::$forfeited_cache = array();
    }

    // ------------------------------------------------------------------
    // The menu entry
    // ------------------------------------------------------------------

    /**
     * Register "Offers" under VVL Oversight, right after Trial
     * Applications, with the count badge (TeamOversight_Offers::menu_title()
     * reads a cached option: the menu costs no query). Always in the menu
     * since 1.79.0 (offers are how selection works from 2027; there is no
     * switch). Skipped when the page is already registered, so a second
     * registration (the dashboard tile's builder adding one) can't
     * duplicate it.
     */
    public static function add_menu() {
        global $submenu;
        if (isset($submenu[self::PARENT_SLUG]) && is_array($submenu[self::PARENT_SLUG])) {
            foreach ($submenu[self::PARENT_SLUG] as $item) {
                if (isset($item[2]) && $item[2] === self::SLUG) {
                    return;
                }
            }
        }
        add_submenu_page(
            self::PARENT_SLUG,
            'Offers',
            TeamOversight_Offers::menu_title(),
            'manage_options',
            self::SLUG,
            array(__CLASS__, 'render_page')
        );
        self::move_after_trials();
    }

    /** Put the new entry straight after Trial Applications (add_submenu_page appends). */
    private static function move_after_trials() {
        global $submenu;
        if (!isset($submenu[self::PARENT_SLUG]) || !is_array($submenu[self::PARENT_SLUG])) {
            return;
        }
        $items = $submenu[self::PARENT_SLUG];
        $mine = null;
        foreach ($items as $key => $item) {
            if (isset($item[2]) && $item[2] === self::SLUG) {
                $mine = $item;
                unset($items[$key]);
                break;
            }
        }
        if ($mine === null) {
            return;
        }
        $out = array();
        $placed = false;
        foreach ($items as $item) {
            $out[] = $item;
            if (!$placed && isset($item[2]) && $item[2] === 'team-oversight-trials') {
                $out[] = $mine;
                $placed = true;
            }
        }
        if (!$placed) {
            $out[] = $mine;
        }
        $submenu[self::PARENT_SLUG] = $out;
    }

    /** The page's address, e.g. page_url(array('tab' => 'held', 'season' => '2027')). */
    public static function page_url($args = array()) {
        return add_query_arg(array_merge(array('page' => self::SLUG), (array) $args), admin_url('admin.php'));
    }

    // ------------------------------------------------------------------
    // Validated GET parameters
    // ------------------------------------------------------------------

    private static function valid_season($value) {
        return is_string($value) && preg_match('/^\d{4}$/D', $value) === 1;
    }

    /** The season in view: ?season=, else the remembered season (if offers apply to it), else default_season(). */
    public static function current_season() {
        $got = isset($_GET['season']) ? wp_unslash($_GET['season']) : '';
        $user_id = get_current_user_id();
        if (self::valid_season($got)) {
            if ($user_id > 0 && TeamOversight_Offers::applies($got) && get_user_meta($user_id, 'team_oversight_selected_season', true) !== $got) {
                update_user_meta($user_id, 'team_oversight_selected_season', $got);
            }
            return $got;
        }
        $remembered = $user_id > 0 ? get_user_meta($user_id, 'team_oversight_selected_season', true) : '';
        if (self::valid_season($remembered) && TeamOversight_Offers::applies($remembered)) {
            return $remembered;
        }
        return self::default_season();
    }

    /**
     * The season the page opens on when nothing picks one (1.79.1): the
     * oldest season with requests awaiting approval, else the oldest with
     * offers out to players (sent or accepted), else this calendar year when
     * offers apply to it, else next year (the first offers season, before
     * 2027). So in January 2027, during the 2027 public trials, it opens on
     * 2027, not 2028; and when the next season's internal trials begin, its
     * first requests bring the page to it. $active: active_by_season()'s
     * answer, when the caller already has it.
     */
    public static function default_season($active = null) {
        $active = is_array($active) ? $active : TeamOversight_Offers::active_by_season();
        foreach (array('awaiting', 'out') as $key) {
            foreach ($active as $season => $n) {
                if (intval($n[$key]) > 0 && TeamOversight_Offers::applies((string) $season)) {
                    return (string) $season;
                }
            }
        }
        $year = intval(current_time('Y'));
        foreach (array($year, $year + 1) as $candidate) {
            if (TeamOversight_Offers::applies((string) $candidate)) {
                return (string) $candidate;
            }
        }
        return (string) ($year + 1);
    }

    /**
     * A line when requests are waiting in seasons the page is not showing
     * (1.79.1; the menu badge counts every season): "6 requests are waiting
     * for approval in 2027" with a link to each. '' when there are none.
     */
    private static function other_seasons_note($season) {
        $bits = array();
        $total = 0;
        foreach (TeamOversight_Offers::active_by_season() as $other => $n) {
            if ((string) $other === (string) $season || intval($n['awaiting']) <= 0) {
                continue;
            }
            $total += intval($n['awaiting']);
            $bits[] = '<a href="' . esc_url(self::page_url(array('tab' => 'approve', 'season' => (string) $other))) . '">' . self::h($other) . ' (' . intval($n['awaiting']) . ')</a>';
        }
        if ($total === 0) {
            return '';
        }
        return '<div class="notice notice-warning inline"><p><strong>' . intval($total) . ' request' . ($total === 1 ? ' is' : 's are') . ' waiting for approval in another season:</strong> ' . implode(', ', $bits) . '. The menu count includes ' . ($total === 1 ? 'it' : 'them') . '.</p></div>';
    }

    public static function current_tab() {
        $tab = isset($_GET['tab']) ? wp_unslash($_GET['tab']) : '';
        return (is_string($tab) && isset(self::TABS[$tab])) ? $tab : 'approve';
    }

    private static function get_paged() {
        $paged = isset($_GET['paged']) ? wp_unslash($_GET['paged']) : 1;
        return is_scalar($paged) ? max(1, intval($paged)) : 1;
    }

    private static function clean_team($value) {
        return (is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $value) === 1) ? $value : '';
    }

    private static function get_team_filter() {
        return isset($_GET['team']) ? self::clean_team(wp_unslash($_GET['team'])) : '';
    }

    private static function get_sort() {
        $sort = isset($_GET['sort']) ? wp_unslash($_GET['sort']) : '';
        return $sort === 'team' ? 'team' : 'age';
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private static function h($text) {
        return esc_html((string) $text);
    }

    /** A display name as plain text. Names are stored entity-encoded (& as &amp;): decode once, then the caller escapes once. */
    private static function plain_name($text) {
        return wp_specialchars_decode((string) $text, ENT_QUOTES);
    }

    private static function name_html($text) {
        return esc_html(self::plain_name($text));
    }

    private static function d($date) {
        $valid = TeamOversight_Fee_Timeline::parse_date((string) $date);
        return $valid === null ? '' : date('j M Y', strtotime($valid));
    }

    private static function dt($datetime) {
        $datetime = (string) $datetime;
        return strlen($datetime) >= 10 ? self::d(substr($datetime, 0, 10)) : '';
    }

    private static function money_c($cents) {
        return TeamOversight_Offers::money(intval($cents) / 100);
    }

    private static function money_plain($cents) {
        return number_format(intval($cents) / 100, 2, '.', '');
    }

    private static function chip($text, $class = '') {
        return '<span class="mo-chip' . ($class !== '' ? ' mo-chip-' . esc_attr($class) : '') . '">' . esc_html((string) $text) . '</span>';
    }

    /** "Name (TST1 playing)" as plain text, for the flash summary. */
    private static function offer_label($offer) {
        $user = ($offer && $offer->user_id) ? get_userdata($offer->user_id) : false;
        $name = $user ? $user->display_name : ($offer ? $offer->name : '');
        return trim(self::plain_name($name)) . ($offer ? ' (' . $offer->team . ' ' . strtolower(TeamOversight_Offers::role_name($offer->role)) . ')' : '');
    }

    private static function staff_name($user_id) {
        $user = intval($user_id) > 0 ? get_userdata(intval($user_id)) : false;
        return $user ? self::plain_name($user->display_name) : '';
    }

    private static function state_label($state) {
        $all = TeamOversight_Offers::states();
        return isset($all[$state]) ? $all[$state] : (string) $state;
    }

    private static function player_link($offer, $season) {
        $user = $offer->user_id ? get_userdata($offer->user_id) : false;
        if (!$user) {
            return '<strong>' . self::name_html($offer->name) . '</strong> ' . self::chip('account no longer exists', 'red');
        }
        return '<a href="' . esc_url(TeamOversight_Member_Lookup::profile_url($user->ID, '', $season)) . '"><strong>' . self::name_html($user->display_name) . '</strong></a>';
    }

    /** The team's name beside its code, "SL2M State League 2 Men", from the season's team list. */
    private static function team_html($team, $season) {
        $cfg = self::teams_config($season);
        $name = (isset($cfg[$team]) && !empty($cfg[$team]['name'])) ? $cfg[$team]['name'] : '';
        return '<strong>' . esc_html($team) . '</strong>' . ($name !== '' && $name !== $team ? '<br><span class="mo-small">' . self::name_html($name) . '</span>' : '');
    }

    private static function teams_config($season) {
        if (!isset(self::$teams_cache[$season])) {
            $db = new TeamOversight_Database();
            self::$teams_cache[$season] = $db->get_teams_config($season);
        }
        return self::$teams_cache[$season];
    }

    /** A TeamOversight_Fees with its matrix lookups cached, for a list (one shared instance: a new one re-adds its AJAX hooks). */
    private static function list_fees() {
        $fees = TeamOversight_Offers::fees();
        $fees->enable_rate_cache();
        return $fees;
    }

    /**
     * One list of offers in some states for a season, oldest first, cached
     * for the request. array(rows, total): total can exceed rows when more
     * than LOAD_LIMIT match (the page says so).
     */
    private static function load_offers(array $states, $season) {
        $key = $season . '|' . implode(',', $states);
        if (!isset(self::$load_cache[$key])) {
            $result = TeamOversight_Offers::list_offers(array('season' => $season, 'states' => $states, 'order' => 'oldest', 'limit' => self::LOAD_LIMIT));
            self::$load_cache[$key] = array('rows' => $result['rows'], 'total' => intval($result['total']));
        }
        return self::$load_cache[$key];
    }

    /** A Held deposits row with every documented key present (the money builder's row shape, design/offer-api.md 6.10 C), so a missing key can never raise a notice. */
    private static function held_defaults($row) {
        $row = is_array($row) ? $row : array();
        $row = array_merge(array(
            'invoice_id' => 0, 'user_id' => 0, 'email' => '', 'name' => '', 'season' => '',
            'paid_cents' => 0, 'invoice_cents' => 0, 'credit_cents' => 0,
            'offer_id' => 0, 'team' => '', 'ended_state' => '', 'ended_at' => '',
            'settlement' => '', 'decision' => 'none', 'decided_by' => 0, 'decided_at' => '',
            'settled_cents' => 0, 'can_apply' => false, 'apply_offer_id' => 0,
        ), $row);
        if (!in_array($row['decision'], array('none', 'pending', 'credit'), true)) {
            $row['decision'] = 'none';
        }
        return $row;
    }

    /** A Forfeited row with every documented key present. */
    private static function forfeited_defaults($row) {
        $row = is_array($row) ? $row : array();
        return array_merge(array(
            'offer_id' => 0, 'user_id' => 0, 'email' => '', 'name' => '', 'season' => '', 'team' => '',
            'ended_state' => '', 'forfeited_cents' => 0, 'settled_by' => 0, 'settled_at' => '', 'invoice_id' => 0,
        ), $row);
    }

    private static function held_rows($season) {
        if (!isset(self::$held_cache[$season])) {
            $rows = TeamOversight_Offers::held_deposits($season);
            self::$held_cache[$season] = is_array($rows) ? array_map(array(__CLASS__, 'held_defaults'), array_values($rows)) : array();
        }
        return self::$held_cache[$season];
    }

    private static function forfeited_rows($season) {
        if (!isset(self::$forfeited_cache[$season])) {
            $rows = TeamOversight_Offers::forfeited_deposits($season);
            self::$forfeited_cache[$season] = is_array($rows) ? array_map(array(__CLASS__, 'forfeited_defaults'), array_values($rows)) : array();
        }
        return self::$forfeited_cache[$season];
    }

    // ------------------------------------------------------------------
    // The flash summary (Post/Redirect/Get)
    // ------------------------------------------------------------------

    private static function result($ok, $message, array $lines = array(), $type = '') {
        return array(
            'ok' => (bool) $ok,
            'type' => $type !== '' ? $type : ($ok ? 'success' : 'error'),
            'message' => (string) $message,
            'lines' => array_values($lines),
        );
    }

    private static function set_flash(array $result) {
        set_transient(self::FLASH_PREFIX . get_current_user_id(), $result, 300);
    }

    /** The summary the last button left for this administrator (shown once), or null. */
    public static function take_flash() {
        $key = self::FLASH_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash)) {
            return null;
        }
        delete_transient($key);
        return $flash;
    }

    private static function render_flash() {
        $flash = self::take_flash();
        if ($flash === null) {
            return;
        }
        $type = isset($flash['type']) && in_array($flash['type'], array('success', 'warning', 'error'), true) ? $flash['type'] : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . self::h($flash['message']) . '</p>';
        if (!empty($flash['lines'])) {
            echo '<ul class="mo-lines">';
            foreach ($flash['lines'] as $line) {
                echo '<li>' . self::h($line) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Per-row forms (a button elsewhere in the row points at them with form="")
    // ------------------------------------------------------------------

    /** The hidden fields that bring the administrator back to the same list after a button. */
    private static function return_fields() {
        $v = self::$view;
        $out = '<input type="hidden" name="return_tab" value="' . esc_attr($v['tab']) . '">'
            . '<input type="hidden" name="return_season" value="' . esc_attr($v['season']) . '">'
            . '<input type="hidden" name="return_paged" value="' . intval($v['paged']) . '">'
            . '<input type="hidden" name="return_team" value="' . esc_attr($v['team']) . '">'
            . '<input type="hidden" name="return_sort" value="' . esc_attr($v['sort']) . '">';
        return $out;
    }

    /** The posted field that names the thing a button acts on. */
    private static function id_field($act) {
        if ($act === 'resend') {
            return 'row_id';
        }
        if ($act === 'ns_send' || $act === 'ns_stop') {
            // 1.80.0: a "not selected" row is one verdict (one application and team).
            return 'selection_id';
        }
        return 'offer_id';
    }

    /**
     * Queue one single-button form (printed by flush_forms() after the
     * table) and return its id for the button's form="" attribute.
     * $hidden: name => value. $confirm: static text for a confirm box.
     */
    private static function row_form($act, $id, array $hidden = array(), $confirm = '') {
        $fid = 'mo-f-' . $act . '-' . intval($id) . '-' . count(self::$forms);
        $html = '<form id="' . esc_attr($fid) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '') . '>'
            . '<input type="hidden" name="action" value="murvc_offer_admin">'
            . '<input type="hidden" name="offer_action" value="' . esc_attr($act) . '">'
            . '<input type="hidden" name="' . esc_attr(self::id_field($act)) . '" value="' . intval($id) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('murvc_offer_admin_' . $act . '_' . intval($id))) . '">';
        foreach ($hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        $html .= self::return_fields() . '</form>';
        self::$forms[] = $html;
        return $fid;
    }

    private static function flush_forms() {
        if (empty(self::$forms)) {
            return;
        }
        echo '<div class="mo-forms" aria-hidden="false">' . implode('', self::$forms) . '</div>';
        self::$forms = array();
    }

    private static function button($label, $fid, $class = 'button', $disabled = false, $title = '') {
        return '<button type="submit" form="' . esc_attr($fid) . '" class="' . esc_attr($class) . '"' . ($disabled ? ' disabled' : '') . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '') . '>' . esc_html($label) . '</button>';
    }

    private static function disabled_button($label, $title = '', $class = 'button') {
        return '<button type="button" class="' . esc_attr($class) . '" disabled' . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '') . '>' . esc_html($label) . '</button>';
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    /**
     * At most once a minute, run the safety net the design asks the page to
     * run (state repair, the locked-in mirror, the money catch-ups). A
     * failure is logged and never stops the page.
     */
    private static function maybe_reconcile() {
        if (get_transient(self::RECONCILE_TRANSIENT) !== false) {
            return;
        }
        set_transient(self::RECONCILE_TRANSIENT, current_time('mysql'), 60);
        try {
            TeamOversight_Offers::reconcile();
        } catch (\Throwable $e) {
            TeamOversight_Log::add('offer_alert', 'The reconcile run when the Offers page loaded failed: ' . TeamOversight_Offers::cut($e->getMessage(), 200));
        }
    }

    /** VVL Oversight > Offers to approve. */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        self::$forms = array();
        $season = self::current_season();
        $tab = self::current_tab();
        self::$view = array('season' => $season, 'tab' => $tab, 'paged' => self::get_paged(), 'team' => self::get_team_filter(), 'sort' => self::get_sort());
        self::maybe_reconcile();

        echo '<div class="wrap murvc-offers">';
        self::styles();
        echo '<h1 class="wp-heading-inline">Offers</h1>';
        self::render_season_form($season, $tab);
        echo '<hr class="wp-header-end">';
        self::render_flash();
        echo TeamOversight_Offers::flash_notice();

        echo self::other_seasons_note($season);
        if (!TeamOversight_Offers::applies($season)) {
            echo '<div class="notice notice-warning inline"><p>Offers apply from the ' . self::h(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . ' season. Pick a later season.</p></div></div>';
            return;
        }

        self::render_status_strip($season);
        self::render_tabs($season, $tab);
        echo '<div class="mo-tab">';
        echo self::tab_intro($tab);
        switch ($tab) {
            case 'setup':
                self::render_setup_tab($season);
                break;
            case 'out':
                self::render_out_tab($season);
                break;
            case 'locked':
                self::render_locked_tab($season);
                break;
            case 'held':
                self::render_held_tab($season);
                break;
            case 'closed':
                self::render_closed_tab($season);
                break;
            case 'notselected':
                self::render_not_selected_tab($season);
                break;
            default:
                self::render_approve_tab($season);
                break;
        }
        echo '</div>';
        if ($tab !== 'setup') {
            echo TeamOversight_Offers_Setup::render_glossary();
        }
        self::flush_forms();
        self::script();
        echo '</div>';
    }

    /**
     * Two sentences under every tab: what it is and what to do here, in the
     * committee's words. (To approve, Held deposits and Not selected also
     * carry their own longer description further down.)
     */
    private static function tab_intro($tab) {
        $lines = array(
            'approve' => 'Offers the coaches have asked for. <strong>What to do here:</strong> check each one and press Approve (the offer goes live and its dates start; the player is emailed and the coaches are copied, once this website may send offer emails) or Decline (the coach sees Not approved and the player is told nothing).',
            'out' => 'Offers that have been emailed and are waiting for the player: either to reply (Offer sent) or to pay the deposit (Accepted). <strong>What to do here:</strong> look at the overdue ones first. Remind emails the player again; +7 days and Move to declined email nobody; Withdraw emails the player.',
            'locked' => 'Players whose deposit is complete. They are put on the team automatically. <strong>What to do here:</strong> check nobody is stuck on Not placed (use Retry placement), and use Player pulled out when someone leaves (the player is not emailed: you handle it with them yourself).',
            'held' => 'Deposit money players have paid that is not on a live offer, and the deposits the club kept. Most deposits are settled the moment the offer ends: a decline or a move to Declined keeps the deposit (it goes on the Forfeited list below, where Reverse forfeit gives it back), and a withdrawal holds it as credit to refund. <strong>What to do here (treasurer):</strong> refund the rows marked Credit, and decide the ones waiting for a decision (a place removed outside the Offers page, or a reversed forfeit): Refund owed or Club keeps it.',
            'closed' => 'Every offer that has ended, newest first, and what happened to the deposit. <strong>Nothing to do here:</strong> it is the record.',
            'notselected' => 'Players a team has turned down, one row per player and team. Nothing is sent automatically: the email goes when a coach presses Send "not selected" email on the player\'s card, or when you press Send here, and it is about that one team only. <strong>What to do here:</strong> look at anyone not told for a while (oldest first): Send, Send all, or Don\'t send for a player who should not get one.',
            'setup' => 'Everything that has to be right for offers to work, read live. <strong>What to do here:</strong> fix anything marked Fix, glance at anything marked Check.',
        );
        return isset($lines[$tab]) ? '<p class="description" style="max-width: 900px;">' . wp_kses($lines[$tab], array('strong' => array())) . '</p>' : '';
    }

    /** The Setup tab: the live checklist, the pages the offers link to, who can approve, the season numbers and the daily check. */
    private static function render_setup_tab($season) {
        $return = self::page_url(array('tab' => 'setup', 'season' => $season));
        $settings = TeamOversight_Offers::settings($season);
        echo '<p style="max-width: 900px;"><strong>' . self::h(TeamOversight_Offers::controls_sentence()) . '</strong></p>';
        echo TeamOversight_Offers_Setup::render_checks($season, array('return' => $return));
        echo '<h3 style="margin: 22px 0 4px;">Offer settings for ' . self::h($season) . '</h3>';
        echo '<p>' . self::h(TeamOversight_Offers_Setup::settings_sentence($settings)) . '. <a href="' . esc_url(TeamOversight_Offers_Setup::config_url($season, 'offer-settings')) . '">Change them on Configuration</a>.</p>';
        echo '<p class="description" style="max-width: 900px;">' . self::h(TeamOversight_Offers_Setup::settings_example($season)) . '</p>';
        echo '<h3 style="margin: 22px 0 4px;">Pages the offers link to</h3>';
        echo '<p class="description" style="max-width: 900px;">Where the buttons in the offer emails and on the offer page go. A page marked as a guess would send players somewhere that may not exist. Fix them under <a href="' . esc_url(TeamOversight_Offers_Setup::emails_url('offers', 'offer-pages')) . '">Emails, Offers and selection</a>.</p>';
        echo TeamOversight_Offers_Setup::render_pages_table($season);
        echo '<h3 style="margin: 22px 0 4px;">Where everything is edited</h3>';
        echo '<table class="widefat striped" style="max-width: 1100px;"><tbody>';
        $where = array(
            array('The permission to send offer emails', 'Configuration, Offers and deposits (also shown at the top of this page and on Emails)', TeamOversight_Offers_Setup::config_url($season, 'offers-and-deposits')),
            array('Deadlines and the deposit percent', 'Configuration, Offers and deposits, ' . $season . ' offer settings', TeamOversight_Offers_Setup::config_url($season, 'offer-settings')),
            array('Fees and upper limits', 'Configuration, Fee Matrix', TeamOversight_Offers_Setup::config_url($season, 'fee-matrix')),
            array('Division levels', 'Configuration, Team Management', TeamOversight_Offers_Setup::config_url($season, 'team-management')),
            array('The wording of every offer email', 'Emails, Offers and selection', TeamOversight_Offers_Setup::emails_url('offers', 'offer-emails')),
            array('The commitments and the deposit policy', 'Emails, Offers and selection, Commitments and deposit policy', TeamOversight_Offers_Setup::emails_url('offers', 'offer-terms')),
            array('The Early Offer explanation, the offer page lines, the coach portal words', 'Emails, Offers and selection, Offer wording', TeamOversight_Offers_Setup::emails_url('offers', 'offer-wording')),
            array('The Academy and Social League lines, the pages emails link to', 'Emails, Offers and selection, Pages and links', TeamOversight_Offers_Setup::emails_url('offers', 'offer-pages')),
            array('Who emails come from', 'Emails, Overview, Sender', TeamOversight_Offers_Setup::emails_url('overview', 'sender')),
            array('How it all fits together, in plain English', 'How it works, Offers and deposits', admin_url('admin.php?page=' . TeamOversight_Help_Page::PAGE_SLUG . '#mh-offers')),
        );
        foreach ($where as $row) {
            echo '<tr><td><strong>' . self::h($row[0]) . '</strong></td><td>' . self::h($row[1]) . '</td><td><a href="' . esc_url($row[2]) . '">Open</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function styles() {
        ?>
        <style>
            .murvc-offers .mo-scroll { overflow-x: auto; }
            .murvc-offers table.widefat td, .murvc-offers table.widefat th { vertical-align: top; }
            .murvc-offers .mo-chip { display: inline-block; padding: 1px 8px; margin: 0 4px 3px 0; border-radius: 10px; font-size: 11px; line-height: 1.6; background: #f0f0f1; border: 1px solid #c3c4c7; color: #1d2327; }
            .murvc-offers .mo-chip-red { background: #fcf0f1; border-color: #d63638; color: #8a2424; }
            .murvc-offers .mo-chip-amber { background: #fcf9e8; border-color: #dba617; color: #6e4c00; }
            .murvc-offers .mo-chip-green { background: #edfaef; border-color: #00a32a; color: #00450c; }
            .murvc-offers .mo-red { color: #b32d2e; }
            .murvc-offers .mo-amber { color: #8a5d00; }
            .murvc-offers .mo-small { font-size: 12px; color: #50575e; }
            .murvc-offers .mo-box { background: #fff; border: 1px solid #c3c4c7; padding: 10px 14px; margin: 10px 0; }
            .murvc-offers .mo-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0; }
            .murvc-offers .mo-forms form { display: inline; margin: 0; }
            .murvc-offers .mo-lines { list-style: disc; margin: 0 0 8px 20px; }
            .murvc-offers td .button { margin: 0 4px 4px 0; }
            .murvc-offers input[type="text"].mo-note { width: 100%; max-width: 220px; }
            .murvc-offers .mo-nowrap { white-space: nowrap; }
        </style>
        <?php
    }

    private static function script() {
        ?>
        <script>
            (function () {
                var all = document.getElementById('mo-select-all');
                if (!all) { return; }
                all.addEventListener('change', function () {
                    var boxes = document.querySelectorAll('input.mo-sel');
                    for (var i = 0; i < boxes.length; i++) {
                        if (!boxes[i].disabled) { boxes[i].checked = all.checked; }
                    }
                });
            })();
        </script>
        <?php
    }

    private static function render_season_form($season, $tab) {
        $years = array();
        $first = intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM);
        $last = max($first, intval(current_time('Y')) + 1);
        for ($y = $last; $y >= $first; $y--) {
            $years[] = (string) $y;
        }
        // Seasons created on Configuration beyond next year are offered too.
        foreach ((array) TeamOversight_Offers::fees()->get_available_seasons() as $created) {
            if (preg_match('/^\d{4}$/D', (string) $created) === 1 && intval($created) >= $first && !in_array((string) $created, $years, true)) {
                $years[] = (string) $created;
            }
        }
        rsort($years, SORT_STRING);
        if (!in_array($season, $years, true)) {
            array_unshift($years, $season);
        }
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="mo-bar" style="display: inline-flex; margin-left: 12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <label for="mo-season">Season</label>
            <select name="season" id="mo-season">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo esc_attr($y); ?>"<?php selected($season, $y); ?>><?php echo esc_html($y); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="Show">
        </form>
        <?php
    }

    private static function render_tabs($season, $active) {
        $counts = TeamOversight_Offers::counts_by_state($season);
        $finished = 0;
        foreach (array('not_approved', 'cancelled', 'declined', 'moved_declined', 'withdrawn', 'left') as $state) {
            $finished += intval($counts[$state]);
        }
        $badge = array(
            'approve' => intval($counts['awaiting_approval']),
            'out' => intval($counts['sent']) + intval($counts['accepted']),
            'locked' => intval($counts['locked_in']),
            'closed' => $finished,
        );
        echo '<h2 class="nav-tab-wrapper" style="margin-top: 14px;">';
        foreach (self::TABS as $slug => $label) {
            $text = $label . (isset($badge[$slug]) ? ' (' . $badge[$slug] . ')' : '');
            echo '<a class="nav-tab' . ($slug === $active ? ' nav-tab-active' : '') . '" href="' . esc_url(self::page_url(array('tab' => $slug, 'season' => $season))) . '">' . esc_html($text) . '</a>';
        }
        echo '</h2>';
    }

    // ------------------------------------------------------------------
    // The status strip: the email permission, emails waiting, problems
    // ------------------------------------------------------------------

    private static function email_event_label($event) {
        $labels = array(
            'offer' => 'Offer',
            'reminder' => 'Reminder',
            'withdrawn' => 'Offer withdrawn',
            'coach_accepted' => 'Coaches: accepted',
            'coach_declined' => 'Coaches: declined',
            'coach_overdue' => 'Coaches: deadline not met',
            'not_selected' => 'Not selected',
            'locked_in' => 'You\'re locked in',
        );
        return isset($labels[$event]) ? $labels[$event] : (string) $event;
    }

    /**
     * What a person has to put right, read from live data (it clears itself
     * when fixed): offers locked in but not placed, pricing errors, an
     * account that no longer exists, deposits waiting for the treasurer.
     * Returns a list of array(text, tab).
     */
    public static function problems($season) {
        $out = array();
        $live = array();
        foreach (array(array(TeamOversight_Offers::AWAITING), array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), array(TeamOversight_Offers::LOCKED_IN)) as $states) {
            $load = self::load_offers($states, $season);
            foreach ($load['rows'] as $offer) {
                $live[] = $offer;
            }
        }
        $ids = array();
        foreach ($live as $offer) {
            if ($offer->user_id) {
                $ids[] = intval($offer->user_id);
            }
        }
        if (!empty($ids)) {
            cache_users(array_values(array_unique($ids)));
        }
        foreach ($live as $offer) {
            $label = self::offer_label($offer);
            if ($offer->state === TeamOversight_Offers::LOCKED_IN) {
                if ((string) $offer->placement_error !== '') {
                    $out[] = array('text' => $label . ' is locked in but not on the team: ' . $offer->placement_error, 'tab' => 'locked');
                } elseif (empty($offer->placed_at) && empty($offer->place_id)) {
                    $out[] = array('text' => $label . ' is locked in and has not been placed yet.', 'tab' => 'locked');
                }
                if ((string) $offer->pricing_error !== '') {
                    $out[] = array('text' => $label . ' could not be priced: ' . $offer->pricing_error, 'tab' => 'locked');
                }
            }
            if (!$offer->user_id || !get_userdata($offer->user_id)) {
                $out[] = array('text' => $label . ' belongs to an account that no longer exists.', 'tab' => $offer->state === TeamOversight_Offers::AWAITING ? 'approve' : ($offer->state === TeamOversight_Offers::LOCKED_IN ? 'locked' : 'out'));
            }
        }
        $waiting = 0;
        foreach (self::held_rows($season) as $row) {
            if (isset($row['decision']) && $row['decision'] === 'pending') {
                $waiting++;
            }
        }
        if ($waiting > 0) {
            $out[] = array('text' => $waiting . ' held deposit' . ($waiting === 1 ? ' is' : 's are') . ' waiting for the treasurer to decide: Refund owed or Club keeps it.', 'tab' => 'held');
        }
        // 1.80.0: Rejected players nobody has told for a week or more (nothing goes by itself, so the committee is the safety net).
        $untold = class_exists('TeamOversight_Not_Selected') ? TeamOversight_Not_Selected::untold_count($season, TeamOversight_Not_Selected::UNTOLD_FLAG_DAYS) : 0;
        if ($untold > 0) {
            $out[] = array('text' => $untold . ' rejection' . ($untold === 1 ? '' : 's') . ' from more than ' . TeamOversight_Not_Selected::UNTOLD_FLAG_DAYS . ' days ago ' . ($untold === 1 ? 'has' : 'have') . ' not been sent a "not selected" email. Nothing is sent automatically: Send, or Don\'t send, on the Not selected tab (or ask the coach).', 'tab' => 'notselected');
        }
        return $out;
    }

    private static function render_status_strip($season) {
        echo TeamOversight_Offers::render_gate_box(self::page_url(array('tab' => self::$view['tab'], 'season' => $season)));

        if (class_exists('TeamOversight_Offer_Emails')) {
            $counts = (array) TeamOversight_Offer_Emails::status_counts();
            $count = function ($key) use ($counts) {
                return isset($counts[$key]) ? intval($counts[$key]) : 0;
            };
            $waiting = $count('pending') + $count('sending');
            echo '<div class="mo-box"><strong>Offer emails</strong> ';
            echo self::chip($waiting . ' waiting to send', $waiting > 0 ? 'amber' : '');
            echo self::chip($count('sent') . ' sent');
            if ($count('held') > 0) {
                echo self::chip($count('held') . ' held (older than a day: send or discard them above)', 'amber');
            }
            if ($count('failed') > 0) {
                echo self::chip($count('failed') . ' failed', 'red');
            }
            if ($count('unknown') > 0) {
                echo self::chip($count('unknown') . ' not sure if sent', 'red');
            }
            if (!empty($counts['oldest_pending'])) {
                echo ' <span class="mo-small">Oldest waiting since ' . self::h(self::dt($counts['oldest_pending'])) . '.</span>';
            }
            $problem_rows = (array) TeamOversight_Offer_Emails::problem_rows(20);
            if (!empty($problem_rows)) {
                echo '<table class="widefat striped" style="margin-top: 8px;"><thead><tr><th>Message</th><th>Offer</th><th>Status</th><th>Tries</th><th>Queued</th><th></th></tr></thead><tbody>';
                foreach ($problem_rows as $row) {
                    $note = isset($row['note']) ? (string) $row['note'] : '';
                    $fid = self::row_form('resend', $row['id'], array(), 'Send this message again? The person may receive it twice if the first one did get through.');
                    echo '<tr><td>' . self::h(self::email_event_label($row['event'])) . '</td>'
                        . '<td>' . ($row['offer_id'] ? '#' . intval($row['offer_id']) : '') . '</td>'
                        . '<td>' . self::chip($row['status'] === 'unknown' ? 'not sure if it went out' : 'failed', 'red') . ($note !== '' ? '<br><span class="mo-small">' . self::h(TeamOversight_Offers::cut($note, 160)) . '</span>' : '') . '</td>'
                        . '<td>' . intval($row['tries']) . '</td>'
                        . '<td class="mo-nowrap">' . self::h(self::dt($row['created_at'])) . '</td>'
                        . '<td>' . self::button('Resend', $fid) . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
        }

        list($daily_text, $daily_state) = TeamOversight_Offers_Setup::daily_words();
        if ($daily_state !== 'ok') {
            echo '<div class="notice notice-' . ($daily_state === 'bad' ? 'error' : 'warning') . ' inline"><p>' . self::h($daily_text) . ' ' . TeamOversight_Offers_Setup::run_daily_button(self::page_url(array('tab' => self::$view['tab'], 'season' => $season))) . '</p></div>';
        }

        $problems = self::problems($season);
        if (!empty($problems)) {
            echo '<div class="notice notice-warning inline"><p><strong>Needs attention</strong></p><ul class="mo-lines">';
            foreach ($problems as $problem) {
                echo '<li>' . self::h($problem['text']) . ' <a href="' . esc_url(self::page_url(array('tab' => $problem['tab'], 'season' => $season))) . '">Open</a></li>';
            }
            echo '</ul></div>';
        }
    }

    // ------------------------------------------------------------------
    // Pagination
    // ------------------------------------------------------------------

    private static function paginate($total, $paged, array $args = array()) {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($pages < 2) {
            return;
        }
        $base = add_query_arg('paged', '%#%', self::page_url($args));
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(array(
            'base' => $base,
            'format' => '',
            'current' => $paged,
            'total' => $pages,
            'type' => 'plain',
        )) . '</div></div>';
    }

    private static function view_args() {
        $v = self::$view;
        $args = array('tab' => $v['tab'], 'season' => $v['season']);
        if ($v['team'] !== '') {
            $args['team'] = $v['team'];
        }
        if ($v['sort'] !== 'age') {
            $args['sort'] = $v['sort'];
        }
        return $args;
    }

    // ------------------------------------------------------------------
    // Data for the To approve rows (batched: no query set per row)
    // ------------------------------------------------------------------

    private static function id_list(array $ids) {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /** application id => the trial_applications columns the page uses. */
    private static function applications(array $ids) {
        global $wpdb;
        $ids = self::id_list($ids);
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $rows = $wpdb->get_results("SELECT id, user_id, trial_number, form_data, is_transfer_player, registration_override, application_status FROM {$wpdb->prefix}trial_applications WHERE id IN (" . implode(',', $ids) . ')');
        foreach ((array) $rows as $row) {
            $out[intval($row->id)] = $row;
        }
        return $out;
    }

    /** "application id|team" => the coach's verdict status. */
    private static function verdicts(array $ids) {
        global $wpdb;
        $ids = self::id_list($ids);
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $rows = $wpdb->get_results("SELECT application_id, team, status FROM {$wpdb->prefix}team_trial_selections WHERE application_id IN (" . implode(',', $ids) . ')');
        foreach ((array) $rows as $row) {
            $out[intval($row->application_id) . '|' . $row->team] = (string) $row->status;
        }
        return $out;
    }

    /** Notes the offer code writes itself ("Offer for X approved and sent ...") say nothing the offer row doesn't. */
    private static function is_system_note($text) {
        return preg_match('/^(Offer for .+ (sent to the committee|approved and sent|not approved)|Offer request for |Reminder sent to the player|Gave the player 7 more days|Moved .+ offer to Declined|Withdrew the )/', (string) $text) === 1;
    }

    /** application id => array(note, author, date): the latest note a person wrote. */
    private static function last_notes(array $ids) {
        global $wpdb;
        $ids = self::id_list($ids);
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $rows = $wpdb->get_results("SELECT application_id, author_id, note, created_date FROM {$wpdb->prefix}team_trial_notes WHERE application_id IN (" . implode(',', $ids) . ') ORDER BY id DESC');
        foreach ((array) $rows as $row) {
            $app = intval($row->application_id);
            // A note the system wrote (no author, or the offer code's own wording) says nothing the offer row doesn't.
            if (isset($out[$app]) || intval($row->author_id) === 0 || self::is_system_note($row->note)) {
                continue;
            }
            $out[$app] = array('note' => (string) $row->note, 'author' => self::staff_name($row->author_id), 'date' => self::dt($row->created_date));
        }
        return $out;
    }

    /**
     * user id => the player's OTHER offers in the season that still matter
     * (not a cancelled request or a refusal), from ONE list when the season
     * has few enough offers, else one read per player.
     */
    private static function others_for(array $offers, $season) {
        $want = array();
        foreach ($offers as $offer) {
            if ($offer->user_id) {
                $want[intval($offer->user_id)] = true;
            }
        }
        $out = array();
        if (empty($want)) {
            return $out;
        }
        $all = TeamOversight_Offers::list_offers(array('season' => $season, 'order' => 'newest', 'limit' => self::LOAD_LIMIT));
        if ($all['total'] <= count($all['rows'])) {
            $source = $all['rows'];
        } else {
            $source = array();
            foreach (array_keys($want) as $user_id) {
                $source = array_merge($source, TeamOversight_Offers::for_user($user_id, $season));
            }
        }
        foreach ($source as $other) {
            if (!isset($want[intval($other->user_id)]) || in_array($other->state, array(TeamOversight_Offers::CANCELLED, TeamOversight_Offers::NOT_APPROVED), true)) {
                continue;
            }
            $out[intval($other->user_id)][] = $other;
        }
        return $out;
    }

    private static function age_rule_label($rule) {
        $labels = array('u19' => 'U19', 'u17' => 'U17', 'u15' => 'U15');
        return isset($labels[$rule]) ? $labels[$rule] : strtoupper((string) $rule);
    }

    /**
     * Everything the To approve rows need, for the rows on one page, in a
     * fixed number of queries plus what the quote itself costs per row.
     * Returns offer id => array(quote, limit, block, warn, app, reg, note,
     * others, held_cents, standing, overage).
     */
    public static function approve_context(array $offers, $season) {
        $ctx = array();
        if (empty($offers)) {
            return $ctx;
        }
        $fees = self::list_fees();
        $people = array();
        $user_ids = array();
        $app_ids = array();
        foreach ($offers as $offer) {
            $user = $offer->user_id ? get_userdata($offer->user_id) : false;
            $people[$offer->id] = array('user_id' => intval($offer->user_id), 'email' => $user ? $user->user_email : $offer->email);
            if ($offer->user_id) {
                $user_ids[] = intval($offer->user_id);
            }
            $app_ids[] = intval($offer->application_id);
        }
        $user_ids = self::id_list($user_ids);
        if (!empty($user_ids)) {
            cache_users($user_ids);
            update_meta_cache('user', $user_ids);
        }
        TeamOversight_Fee_Timeline::preload_people(array_values($people), array($season));
        $apps = self::applications($app_ids);
        $verdicts = self::verdicts($app_ids);
        $block_context = TeamOversight_Offers::block_context($app_ids); // a higher team's claim (two queries for the page)
        $notes = self::last_notes($app_ids);
        $others = self::others_for($offers, $season);
        $standings = TeamOversight_Payments::get_fee_standings($people, $season);
        $cfg = self::teams_config($season);
        $cutoff_problem = TeamOversight_Fee_Timeline::validate_start($season, current_time('Y-m-d'));
        $coach_notice = array();

        foreach ($offers as $offer) {
            $user = $offer->user_id ? get_userdata($offer->user_id) : false;
            $app = isset($apps[$offer->application_id]) ? $apps[$offer->application_id] : null;
            $quote = TeamOversight_Offers::compute_quote($offer->user_id, $season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'send', 'fees' => $fees));
            $limit = $quote['fee_class'] !== '' ? TeamOversight_Fees::get_limit($quote['fee_class'], TeamOversight_Fees::charged_as($offer->role), $season) : null;
            $block = '';
            $warn = array();
            $verdict_key = intval($offer->application_id) . '|' . $offer->team;
            $higher = TeamOversight_Offers::higher_team_block($offer->application_id, $offer->team, $season, $block_context);
            if (!$user) {
                $block = 'The player\'s account no longer exists.';
            } elseif (!$app || !in_array($app->application_status, array('pending', 'accepted'), true)) {
                $block = 'The application is no longer open for an offer.';
            } elseif (!isset($verdicts[$verdict_key]) || TeamOversight_Offers::role_for_verdict($verdicts[$verdict_key]) !== $offer->role) {
                $block = 'The coach\'s verdict changed after the request: decline this one and ask the coach to send it again.';
            } elseif ($higher['blocked']) {
                $block = $higher['admin_message'];
            } elseif ($cutoff_problem !== null) {
                $block = 'Too late to send: ' . $cutoff_problem;
            } elseif (!$quote['ok']) {
                $block = $quote['error_admin'] !== '' ? $quote['error_admin'] : $quote['error_public'];
            }
            if (!isset($coach_notice[$offer->team])) {
                $contacts = TeamOversight_Offers::team_contacts($offer->team, $season, 0, 0);
                $coach_notice[$offer->team] = $contacts['none'] ? $contacts['notice'] : '';
            }
            if ($coach_notice[$offer->team] !== '') {
                $warn[] = $coach_notice[$offer->team];
            }

            // Over the team's age rule (VV can grant an exemption, so it warns and never blocks).
            $overage = '';
            $rule = isset($cfg[$offer->team]['age_rule']) ? $cfg[$offer->team]['age_rule'] : '';
            $age_cutoff = $rule !== '' ? TeamOversight_Database::get_dob_cutoff($rule, $season) : null;
            if ($age_cutoff && $user) {
                $born = get_user_meta($user->ID, 'birth_date', true);
                $born_ts = $born ? strtotime(str_replace('/', '-', (string) $born)) : false;
                if ($born_ts && date('Y-m-d', $born_ts) < $age_cutoff) {
                    $overage = 'Over the ' . self::age_rule_label($rule) . ' limit for this team (born ' . date('d/m/Y', $born_ts) . '): needs a Volleyball Victoria exemption.';
                }
            }

            // What else this player holds, and what is paid on their season invoice.
            $mine = array();
            foreach (isset($others[intval($offer->user_id)]) ? $others[intval($offer->user_id)] : array() as $other) {
                if ($other->id !== $offer->id) {
                    $mine[] = $other;
                }
            }
            $held_cents = 0;
            if (!empty($mine)) {
                $held_cents = TeamOversight_Offers::paid_toward_deposit_cents($mine[0]);
            }

            $standing = isset($standings[$offer->id]) ? $standings[$offer->id] : null;
            $standing_text = '';
            if ($standing && (!empty($standing['other_seasons']) || in_array($standing['state'], array('behind', 'flagged', 'arrangement'), true))) {
                $standing_text = TeamOversight_Payments::describe_standing($standing);
            }

            $ctx[$offer->id] = array(
                'quote' => $quote,
                'limit' => $limit,
                'block' => $block,
                'warn' => $warn,
                'app' => $app,
                'reg' => $app ? TeamOversight_Trials::registration_type($app) : '',
                'note' => isset($notes[intval($offer->application_id)]) ? $notes[intval($offer->application_id)] : null,
                'others' => $mine,
                'held_cents' => $held_cents,
                'standing' => $standing_text,
                'flagged' => $standing && !empty($standing['flagged']),
                'overage' => $overage,
            );
        }
        return $ctx;
    }

    // ------------------------------------------------------------------
    // Tab 1: To approve
    // ------------------------------------------------------------------

    private static function render_approve_tab($season) {
        $team_filter = self::$view['team'];
        $sort = self::$view['sort'];
        $load = self::load_offers(array(TeamOversight_Offers::AWAITING), $season);
        $rows = $load['rows'];
        $settings = TeamOversight_Offers::settings($season);
        $basis = TeamOversight_Offers::basis_now($season);

        echo '<p class="description">Approving works out the quote now and fixes it (with the reply-by and deposit dates), then emails the player and copies the team\'s coaches. '
            . ($basis === 'upper'
                ? 'Fees for ' . self::h($season) . ' are not released yet, so offers go out as <strong>Early Offers</strong>, quoted on the <strong>upper limit</strong> for the player\'s category (the season fee is set later and is never more than that).'
                : 'Fees for ' . self::h($season) . ' are released, so offers go out quoted on the <strong>real fee</strong>.')
            . ' Deposit ' . self::h(rtrim(rtrim(number_format($settings['percent'], 2, '.', ''), '0'), '.')) . '%, reply in ' . intval($settings['reply_days']) . ' days, deposit due the later of ' . self::h(self::d($settings['deposit_date'])) . ' and ' . intval($settings['deposit_days']) . ' days after sending. <a href="' . esc_url(TeamOversight_Offers_Setup::config_url($season, 'offer-settings')) . '">Change these numbers</a>.</p>';
        // 1.79.1: approving with the email permission closed still makes the offer live (dates running, visible to the player); only the email waits.
        if (!TeamOversight_Offers::emails_live() && !empty($rows)) {
            echo '<div class="notice notice-error inline"><p><strong>This website may not send offer emails yet.</strong> Approving now still fixes the quote and starts the player\'s reply-by and deposit dates today, and the player can see the offer on their own pages, but nobody is emailed until an administrator allows this website to send offer emails (the box above). If that is more than a day later, the email is held for you to send or discard. On the live website, allow sending before you approve.</p></div>';
        }
        if ($load['total'] > count($rows)) {
            echo '<div class="notice notice-warning inline"><p>Showing the oldest ' . intval(count($rows)) . ' of ' . intval($load['total']) . ' requests.</p></div>';
        }
        if (empty($rows)) {
            echo '<p>Nothing is waiting for approval.</p>';
            return;
        }

        $teams = array();
        foreach ($rows as $offer) {
            $teams[$offer->team] = isset($teams[$offer->team]) ? $teams[$offer->team] + 1 : 1;
        }
        ksort($teams);
        $filtered = array();
        foreach ($rows as $offer) {
            if ($team_filter === '' || $offer->team === $team_filter) {
                $filtered[] = $offer;
            }
        }
        if ($sort === 'team') {
            usort($filtered, function ($a, $b) {
                $c = strcmp($a->team, $b->team);
                return $c !== 0 ? $c : ($a->id - $b->id);
            });
        }
        $total = count($filtered);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min(self::get_paged(), $pages);
        self::$view['paged'] = $paged;
        $page_rows = array_slice($filtered, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="mo-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="tab" value="approve">
            <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
            <label for="mo-team">Team</label>
            <select name="team" id="mo-team">
                <option value="">All teams (<?php echo intval(count($rows)); ?>)</option>
                <?php foreach ($teams as $code => $n): ?>
                    <option value="<?php echo esc_attr($code); ?>"<?php selected($team_filter, $code); ?>><?php echo esc_html($code . ' (' . $n . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="mo-sort">Order</label>
            <select name="sort" id="mo-sort">
                <option value="age"<?php selected($sort, 'age'); ?>>Oldest request first</option>
                <option value="team"<?php selected($sort, 'team'); ?>>By team, then oldest</option>
            </select>
            <input type="submit" class="button" value="Filter">
        </form>
        <?php

        $ctx = self::approve_context($page_rows, $season);
        $approvable = 0;
        foreach ($page_rows as $offer) {
            if ($ctx[$offer->id]['block'] === '') {
                $approvable++;
            }
        }

        // The bulk buttons (the rows' checkboxes and shown figures point at this form).
        $closed = !TeamOversight_Offers::emails_live();
        $closed_words = ' This website may not send offer emails yet: their reply-by and deposit dates start today, but nobody is emailed until you allow it.';
        echo '<form id="mo-bulk" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mo-bar">'
            . '<input type="hidden" name="action" value="murvc_offer_admin">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('murvc_offer_admin_bulk')) . '">'
            . self::return_fields();
        echo '<button type="submit" class="button button-primary" name="offer_action" value="bulk_approve"'
            . ($closed ? ' onclick="return confirm(' . esc_attr(wp_json_encode('Approve the selected offers?' . $closed_words)) . ');"' : '') . '>Approve selected</button>';
        echo '<button type="submit" class="button" name="offer_action" value="approve_all"' . ($approvable > 0 ? '' : ' disabled')
            . ' onclick="return confirm(' . esc_attr(wp_json_encode('Approve all ' . $approvable . ' offers shown on this page? ' . ($closed ? trim($closed_words) : 'Each player is emailed.'))) . ');">Approve all shown (' . intval($approvable) . ')</button>';
        echo '<span style="margin-left: 12px;"></span>';
        echo '<input type="text" class="mo-note" name="note" maxlength="255" placeholder="Note for the coach (optional)" aria-label="Note for the coach">';
        echo '<button type="submit" class="button" name="offer_action" value="bulk_reject" onclick="return confirm(' . esc_attr(wp_json_encode('Decline the selected requests? The coaches will see Not approved; the players are told nothing.')) . ');">Decline selected</button>';
        echo '</form>';

        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr>'
            . '<td class="check-column"><input type="checkbox" id="mo-select-all" aria-label="Select all"></td>'
            . '<th>Player</th><th>Team</th><th>Requested</th><th title="The fee class, the upper limit or fee the quote is based on, and the deposit that would be asked for.">Quote</th>'
            . '<th title="Other offers this player holds this season (a second place can raise their fee), and money already paid on their invoice.">Also holds</th>'
            . '<th title="What they still owe from an earlier season, as a share of their fee and how long they have been behind. It can lead you to decline.">Earlier balance</th><th>Latest note</th><th style="min-width: 190px;">Actions</th>'
            . '</tr></thead><tbody>';
        foreach ($page_rows as $offer) {
            self::approve_row($offer, $ctx[$offer->id], $season);
        }
        echo '</tbody></table></div>';
        self::paginate($total, $paged, array_merge(self::view_args()));
    }

    private static function approve_row($offer, array $c, $season) {
        $q = $c['quote'];
        $blocked = $c['block'] !== '';
        $shown_quote = $q['ok'] ? number_format($q['amount'], 2, '.', '') : '';
        $shown_target = $q['ok'] ? number_format($q['target'], 2, '.', '') : '';

        echo '<tr>';
        // Select (the figures the row showed travel with the bulk form).
        echo '<th class="check-column"><input type="checkbox" class="mo-sel" name="sel[]" value="' . intval($offer->id) . '" form="mo-bulk" aria-label="Select this request">';
        if (!$blocked) {
            echo '<input type="hidden" name="shown_quote[' . intval($offer->id) . ']" value="' . esc_attr($shown_quote) . '" form="mo-bulk">'
                . '<input type="hidden" name="shown_target[' . intval($offer->id) . ']" value="' . esc_attr($shown_target) . '" form="mo-bulk">';
        }
        echo '</th>';

        // Player.
        echo '<td>' . self::player_link($offer, $season) . '<br>';
        if ($c['app'] && $c['app']->trial_number) {
            echo '<span class="mo-small">Trial #' . intval($c['app']->trial_number) . '</span> ';
        }
        if ($c['reg'] !== '') {
            echo self::chip($c['reg']);
        }
        if ($c['overage'] !== '') {
            echo '<br><span class="mo-chip mo-chip-amber">Over age</span> <span class="mo-small">' . self::h($c['overage']) . '</span>';
        }
        echo '</td>';

        // Team and role.
        echo '<td>' . self::team_html($offer->team, $season) . '<br>' . self::chip(TeamOversight_Offers::role_name($offer->role));
        foreach ($c['warn'] as $warn) {
            echo '<br><span class="mo-amber mo-small">' . self::h($warn) . '</span>';
        }
        echo '</td>';

        // Requested by, when.
        $today = current_time('Y-m-d');
        $requested_date = substr((string) $offer->requested_at, 0, 10);
        $age_days = TeamOversight_Fee_Timeline::parse_date($requested_date) !== null ? max(0, TeamOversight_Fee_Timeline::days_between($requested_date, $today)) : 0;
        echo '<td>' . self::h(self::staff_name($offer->requested_by) !== '' ? self::staff_name($offer->requested_by) : 'A coach') . '<br><span class="mo-small">' . self::h(self::dt($offer->requested_at)) . ($age_days > 0 ? ' (' . intval($age_days) . ' day' . ($age_days === 1 ? '' : 's') . ' ago)' : ' (today)') . '</span></td>';

        // The cell, the quote and the deposit.
        echo '<td>';
        // An Early Offer is named as one (TeamOversight_Offers::is_early() is the one place that decides).
        if (TeamOversight_Offers::is_early($offer)) {
            echo self::chip('Early Offer', 'amber') . '<br>';
        }
        if ($q['fee_class'] !== '') {
            echo self::h($q['fee_class']) . '<br><span class="mo-small">' . ($c['limit'] !== null ? 'Limit ' . self::h(TeamOversight_Offers::money($c['limit'])) : 'No limit set') . '</span><br>';
        }
        if ($q['ok']) {
            echo '<strong>' . self::h(TeamOversight_Offers::money($q['amount'])) . '</strong> <span class="mo-small">' . ($q['basis'] === 'upper' ? 'upper limit' : 'real fee') . '</span><br>'
                . 'Deposit <strong>' . self::h(TeamOversight_Offers::money($q['target'])) . '</strong>';
            if (!empty($q['places'])) {
                echo '<br><span class="mo-small">Counts ' . self::h(implode(', ', $q['places'])) . '</span>';
            }
        }
        if ($blocked) {
            echo '<div class="mo-red mo-small"><strong>' . self::h($c['block']) . '</strong></div>';
        }
        echo '</td>';

        // Also holds.
        echo '<td>';
        if (empty($c['others'])) {
            echo '<span class="mo-small">Nothing else</span>';
        } else {
            foreach ($c['others'] as $other) {
                echo self::chip($other->team . ' ' . strtolower(TeamOversight_Offers::role_name($other->role)) . ': ' . strtolower(self::state_label($other->state)), $other->state === TeamOversight_Offers::LOCKED_IN ? 'green' : '') . ' ';
            }
            if ($c['held_cents'] > 0) {
                echo '<br><span class="mo-small">' . self::h(self::money_c($c['held_cents'])) . ' paid on their ' . self::h($season) . ' invoice</span>';
            }
        }
        echo '</td>';

        // Earlier balance (percentages and weeks, as the coaches' pill).
        echo '<td>';
        echo $c['standing'] !== '' ? '<span class="' . ($c['flagged'] ? 'mo-red' : 'mo-amber') . '">' . self::h($c['standing']) . '</span>' : '<span class="mo-small">Nothing owing</span>';
        echo '</td>';

        // Latest note.
        echo '<td>';
        if ($c['note']) {
            echo '<span class="mo-small">' . self::h(TeamOversight_Offers::cut($c['note']['note'], 160)) . '<br>' . self::h(trim($c['note']['author'] . ($c['note']['date'] !== '' ? ', ' . $c['note']['date'] : ''))) . '</span>';
        } else {
            echo '<span class="mo-small">None</span>';
        }
        echo '</td>';

        // Actions.
        echo '<td>';
        if (!$blocked) {
            // 1.79.1: with the email permission closed, Approve asks first (the offer goes live without an email).
            $fa = self::row_form('approve', $offer->id, array('shown_quote' => $shown_quote, 'shown_target' => $shown_target, 'shown_state' => TeamOversight_Offers::AWAITING),
                TeamOversight_Offers::emails_live() ? '' : 'Approve this offer? This website may not send offer emails yet: the reply-by and deposit dates start today, but the player is not emailed until you allow it.');
            echo self::button('Approve', $fa, 'button button-primary');
        } else {
            echo self::disabled_button('Approve', $c['block'], 'button button-primary');
        }
        $fr = self::row_form('reject', $offer->id, array('shown_state' => TeamOversight_Offers::AWAITING), 'Decline this request? The coach will see Not approved; the player is told nothing.');
        echo self::button('Decline', $fr);
        echo '<br><input type="text" class="mo-note" name="note" form="' . esc_attr($fr) . '" maxlength="255" placeholder="Note for the coach (optional)" aria-label="Note for the coach">';
        echo '</td></tr>';
    }

    // ------------------------------------------------------------------
    // Tab 2: Offers out
    // ------------------------------------------------------------------

    private static function render_out_tab($season) {
        $load =self::load_offers(array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), $season);
        $rows = $load['rows'];
        if ($load['total'] > count($rows)) {
            echo '<div class="notice notice-warning inline"><p>Showing the oldest ' . intval(count($rows)) . ' of ' . intval($load['total']) . ' offers.</p></div>';
        }
        if (empty($rows)) {
            echo '<p>No offers are waiting on a player: none are waiting for a reply or a deposit.</p>';
            return;
        }
        $status = array();
        foreach ($rows as $offer) {
            $status[$offer->id] = TeamOversight_Offers::deadline_status($offer);
        }
        usort($rows, function ($a, $b) use ($status) {
            $sa = $status[$a->id];
            $sb = $status[$b->id];
            if ($sa['overdue'] !== $sb['overdue']) {
                return $sa['overdue'] ? -1 : 1;
            }
            if ($sa['overdue']) {
                return $sa['days_late'] !== $sb['days_late'] ? $sb['days_late'] - $sa['days_late'] : $a->id - $b->id;
            }
            $da = $a->state === TeamOversight_Offers::SENT ? (string) $a->reply_by : (string) $a->deposit_due;
            $db = $b->state === TeamOversight_Offers::SENT ? (string) $b->reply_by : (string) $b->deposit_due;
            $c = strcmp($da, $db);
            return $c !== 0 ? $c : $a->id - $b->id;
        });
        $total = count($rows);
        $paged = min(self::get_paged(), max(1, (int) ceil($total / self::PER_PAGE)));
        self::$view['paged'] = $paged;
        $page_rows = array_slice($rows, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        $user_ids = array();
        $accepted = array();
        $offer_ids = array();
        foreach ($page_rows as $offer) {
            if ($offer->user_id) {
                $user_ids[] = intval($offer->user_id);
            }
            $offer_ids[] = $offer->id;
            if ($offer->state === TeamOversight_Offers::ACCEPTED) {
                $accepted[] = $offer;
            }
        }
        if (!empty($user_ids)) {
            cache_users(self::id_list($user_ids));
        }
        $paid = !empty($accepted) ? TeamOversight_Offers::paid_toward_deposit_map($accepted) : array();
        $mail = class_exists('TeamOversight_Offer_Emails') ? TeamOversight_Offer_Emails::status_for_offers($offer_ids) : array();
        $now = strtotime(current_time('mysql'));

        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Team</th><th>Where it is</th><th>Fee and deposit</th><th>Emails and nudges</th><th style="min-width: 220px;">Actions</th></tr></thead><tbody>';
        foreach ($page_rows as $offer) {
            $st = $status[$offer->id];
            $is_sent = $offer->state === TeamOversight_Offers::SENT;
            $date = $is_sent ? $offer->reply_by : $offer->deposit_due;
            echo '<tr><td>' . self::player_link($offer, $season) . '</td>';
            echo '<td>' . self::team_html($offer->team, $season) . '<br>' . self::chip(TeamOversight_Offers::role_name($offer->role)) . '</td>';
            echo '<td>' . self::chip(self::state_label($offer->state), $is_sent ? '' : 'green') . '<br>'
                . ($is_sent ? 'Reply by ' : 'Deposit due ') . '<strong>' . self::h(self::d($date)) . '</strong>';
            if ($st['overdue']) {
                echo '<br>' . self::chip('Overdue ' . $st['days_late'] . ' day' . ($st['days_late'] === 1 ? '' : 's'), 'red');
            }
            echo '<br><span class="mo-small">Sent ' . self::h(self::dt($offer->sent_at)) . ($offer->accepted_at ? ', accepted ' . self::h(self::dt($offer->accepted_at)) : '') . '</span></td>';

            echo '<td>' . self::h(TeamOversight_Offers::fee_words($offer)) . '<br>';
            if ($is_sent) {
                echo 'Deposit ' . self::h(TeamOversight_Offers::money($offer->deposit_target !== null ? $offer->deposit_target : 0));
            } else {
                $target = TeamOversight_Offers::effective_target($offer);
                $paid_cents = isset($paid[$offer->id]) ? intval($paid[$offer->id]) : 0;
                echo 'Deposit ' . self::h(TeamOversight_Offers::money($target)) . '<br><span class="mo-small">' . self::h(self::money_c($paid_cents)) . ' paid</span>';
            }
            echo '</td>';

            echo '<td>';
            $offer_mail = isset($mail[$offer->id]) ? $mail[$offer->id] : array();
            if (isset($offer_mail['offer'])) {
                echo self::chip('Offer email: ' . $offer_mail['offer'], in_array($offer_mail['offer'], array('failed', 'unknown'), true) ? 'red' : '') . '<br>';
            }
            $reminded = $offer->last_reminded_at ? self::dt($offer->last_reminded_at) : '';
            echo '<span class="mo-small">' . ($offer->reminder_count ? intval($offer->reminder_count) . ' reminder' . ($offer->reminder_count === 1 ? '' : 's') . ', last ' . self::h($reminded) : 'No reminders')
                . ($offer->extended_count ? '<br>' . intval($offer->extended_count) . ' extension' . ($offer->extended_count === 1 ? '' : 's') : '') . '</span>';
            echo '</td>';

            // Actions.
            $shown = array('shown_state' => $offer->state, 'shown_date' => (string) $date);
            echo '<td>';
            $too_soon = $offer->last_reminded_at && strtotime((string) $offer->last_reminded_at) > $now - DAY_IN_SECONDS;
            if ($too_soon) {
                echo self::disabled_button('Remind', 'Reminded in the last 24 hours.');
            } else {
                echo self::button('Remind', self::row_form('remind', $offer->id, $shown, 'Send the offer email to this player again?'), 'button', false, 'Emails the player the offer again.');
            }
            echo self::button('+7 days', self::row_form('extend', $offer->id, $shown), 'button', false, 'Give them 7 more days from today. Emails nobody.');
            if ($st['overdue']) {
                $money = !$is_sent && isset($paid[$offer->id]) && intval($paid[$offer->id]) > 0;
                echo self::button('Move to declined', self::row_form('move_declined', $offer->id, $shown, 'Move this offer to Declined? The offer closes and the player is not emailed; they see it as closed on their offer page.' . ($money ? ' The deposit they paid is kept (forfeited).' : '')), 'button', false, 'Closes the offer. Emails nobody.');
            }
            $holds = !$is_sent && isset($paid[$offer->id]) && intval($paid[$offer->id]) > 0;
            echo self::button('Withdraw', self::row_form('withdraw', $offer->id, $shown, 'Withdraw this offer? The player is emailed.' . ($holds ? ' Their deposit stays on their account as credit to refund.' : '')), 'button', false, 'Emails the player a short note.');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::paginate($total, $paged, self::view_args());
    }

    // ------------------------------------------------------------------
    // Tab 3: Locked in
    // ------------------------------------------------------------------

    private static function render_locked_tab($season) {
        $load = self::load_offers(array(TeamOversight_Offers::LOCKED_IN), $season);
        $rows = $load['rows'];
        if ($load['total'] > count($rows)) {
            echo '<div class="notice notice-warning inline"><p>Showing the oldest ' . intval(count($rows)) . ' of ' . intval($load['total']) . ' offers.</p></div>';
        }
        if (empty($rows)) {
            echo '<p>Nobody is locked in yet.</p>';
            return;
        }
        // Players who are not on the team yet come first.
        usort($rows, function ($a, $b) {
            $pa = empty($a->placed_at) && empty($a->place_id) ? 0 : 1;
            $pb = empty($b->placed_at) && empty($b->place_id) ? 0 : 1;
            if ($pa !== $pb) {
                return $pa - $pb;
            }
            return strcmp((string) $b->locked_in_at, (string) $a->locked_in_at);
        });
        $total = count($rows);
        $paged = min(self::get_paged(), max(1, (int) ceil($total / self::PER_PAGE)));
        self::$view['paged'] = $paged;
        $page_rows = array_slice($rows, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        $user_ids = array();
        $offer_ids = array();
        foreach ($page_rows as $offer) {
            if ($offer->user_id) {
                $user_ids[] = intval($offer->user_id);
            }
            $offer_ids[] = $offer->id;
        }
        if (!empty($user_ids)) {
            cache_users(self::id_list($user_ids));
        }
        $mail = class_exists('TeamOversight_Offer_Emails') ? TeamOversight_Offer_Emails::status_for_offers($offer_ids) : array();
        $today = current_time('Y-m-d');

        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Team</th><th>Locked in</th><th>Placement</th><th>Fee</th><th style="min-width: 260px;">Actions</th></tr></thead><tbody>';
        foreach ($page_rows as $offer) {
            $placed = !empty($offer->placed_at) || !empty($offer->place_id);
            $shown = array('shown_state' => $offer->state);
            echo '<tr><td>' . self::player_link($offer, $season) . '</td>';
            echo '<td>' . self::team_html($offer->team, $season) . '<br>' . self::chip(TeamOversight_Offers::role_name($offer->role)) . '</td>';
            echo '<td>' . self::h(self::dt($offer->locked_in_at));
            $offer_mail = isset($mail[$offer->id]) ? $mail[$offer->id] : array();
            if (isset($offer_mail['locked_in'])) {
                echo '<br>' . self::chip('Email: ' . $offer_mail['locked_in'], in_array($offer_mail['locked_in'], array('failed', 'unknown'), true) ? 'red' : '');
            }
            echo '</td>';
            echo '<td>';
            if ($placed) {
                echo self::chip('Placed ' . self::dt($offer->placed_at), 'green');
            } else {
                echo self::chip('Not placed', 'red');
                if ((string) $offer->placement_error !== '') {
                    echo '<br><span class="mo-red mo-small">' . self::h($offer->placement_error) . '</span>';
                }
            }
            if ((string) $offer->pricing_error !== '') {
                echo '<br><span class="mo-red mo-small">Pricing: ' . self::h($offer->pricing_error) . '</span>';
            }
            echo '</td>';
            echo '<td>' . self::h(TeamOversight_Offers::fee_words($offer)) . '<br><span class="mo-small">Deposit ' . self::h(TeamOversight_Offers::money($offer->deposit_target !== null ? $offer->deposit_target : 0)) . '</span></td>';

            echo '<td>';
            if (!$placed) {
                $fretry = self::row_form('retry', $offer->id, $shown);
                echo '<label class="mo-small" for="' . esc_attr($fretry) . '-d">Start</label> <input type="date" id="' . esc_attr($fretry) . '-d" name="start" form="' . esc_attr($fretry) . '" value="' . esc_attr($today) . '" required> '
                    . self::button('Retry placement', $fretry) . '<br>';
            }
            $fpull = self::row_form('pull_out', $offer->id, $shown, 'Record that this player pulled out? They are taken off the team and nobody is emailed: you handle it with them yourself.');
            echo '<select name="who_broke" form="' . esc_attr($fpull) . '" aria-label="Who broke the commitment">'
                . '<option value="player">The player pulled out (deposit forfeited)</option>'
                . '<option value="club">The club broke it (deposit kept as credit)</option></select> '
                . self::button('Player pulled out', $fpull, 'button', false, 'Takes them off the team. Emails nobody.') . '<br>';
            echo self::button('Withdraw (club)', self::row_form('withdraw', $offer->id, $shown, 'Withdraw this player? They are taken off the team, emailed, and their deposit stays on their account as credit.'), 'button', false, 'Emails the player a short note.');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::paginate($total, $paged, self::view_args());
    }

    // ------------------------------------------------------------------
    // Tab 4: Held deposits, and the Forfeited list
    // ------------------------------------------------------------------

    private static function decision_label($decision) {
        if ($decision === 'credit') {
            return 'Credit (the club refunds it)';
        }
        if ($decision === 'pending') {
            return 'Waiting for a decision';
        }
        return 'No decision';
    }

    /** Credit held, per decision: array(decision => array(count, cents)). */
    public static function held_totals(array $rows) {
        $totals = array('pending' => array(0, 0), 'none' => array(0, 0), 'credit' => array(0, 0));
        foreach ($rows as $row) {
            $decision = isset($row['decision']) && isset($totals[$row['decision']]) ? $row['decision'] : 'none';
            $totals[$decision][0]++;
            $totals[$decision][1] += intval($row['credit_cents']);
        }
        return $totals;
    }

    private static function csv_form($list, $season, $label) {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display: inline-block; margin: 0 8px 0 0;">'
            . '<input type="hidden" name="action" value="murvc_offer_held_csv">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::CSV_NONCE)) . '">'
            . '<input type="hidden" name="season" value="' . esc_attr($season) . '">'
            . '<input type="hidden" name="list" value="' . esc_attr($list) . '">'
            . '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
    }

    private static function render_held_tab($season) {
        self::render_held_lists(self::held_rows($season), self::forfeited_rows($season), $season);
    }

    /**
     * Draw the Held deposits and Forfeited lists from rows in the money
     * builder's shapes (TeamOversight_Offers::held_deposits() and
     * forfeited_deposits()). Public so the lists can be drawn from rows a test
     * made. Mark credit and Forfeit post the decision and the amount the row
     * showed, so a button on a stale page is refused (held_stale()).
     */
    public static function render_held_lists(array $held, array $forfeited, $season) {
        $held = array_map(array(__CLASS__, 'held_defaults'), array_values($held));
        $forfeited = array_map(array(__CLASS__, 'forfeited_defaults'), array_values($forfeited));
        $totals = self::held_totals($held);
        $payment_url = admin_url('admin.php?page=team-oversight-invoices&season=' . rawurlencode($season));

        echo '<h3 style="margin-top: 6px;">Held deposits</h3>';
        echo '<p class="description">Money players have paid above their fee, where they have no live offer. Worked out from the ledger, so a late payment or an overshoot shows here too. '
            . 'Most deposits are settled by the site the moment the offer ends, with nobody asked: when the club withdraws an offer the deposit is held as credit (<strong>Credit (the club refunds it)</strong> below), and when the player declines after paying or is moved to Declined it is kept (it leaves this list and goes on the <strong>Forfeited</strong> list further down, where <strong>Reverse forfeit</strong> gives it back). '
            . 'Only a few wait for a decision: a locked-in player whose place was removed outside the Offers page, a reversed forfeit, and money the site could not settle. For those, press <strong>Refund owed</strong> when the club broke its commitment (it withdrew the offer, dropped the player, or the fee came in above the maximum it quoted) or <strong>Club keeps it</strong> when the player broke theirs (declined after paying, missed the deadline, or pulled out). '
            . 'Refund a deposit in <a href="' . esc_url($payment_url) . '">Payment Management</a> with a negative Record Payment, and put the order number in the note; the row leaves this list when the credit reaches zero.</p>';
        echo '<div class="mo-bar">';
        echo self::chip($totals['pending'][0] . ' waiting for a decision: ' . self::money_c($totals['pending'][1]), $totals['pending'][0] > 0 ? 'amber' : '');
        echo self::chip($totals['none'][0] . ' with no decision: ' . self::money_c($totals['none'][1]));
        echo self::chip($totals['credit'][0] . ' to refund: ' . self::money_c($totals['credit'][1]), $totals['credit'][0] > 0 ? 'green' : '');
        echo self::csv_form('held', $season, 'Download held deposits (CSV)');
        echo self::csv_form('forfeited', $season, 'Download forfeited (CSV)');
        echo '</div>';

        if (empty($held)) {
            echo '<p>No deposits are being held.</p>';
        } else {
            echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Latest offer</th><th>Paid</th><th>Invoice</th><th>Credit held</th><th>Decision</th><th style="min-width: 240px;">Actions</th></tr></thead><tbody>';
            foreach ($held as $row) {
                $offer_id = intval($row['offer_id']);
                $decision = isset($row['decision']) ? $row['decision'] : 'none';
                echo '<tr><td>';
                if (intval($row['user_id']) > 0 && get_userdata(intval($row['user_id']))) {
                    echo '<a href="' . esc_url(TeamOversight_Member_Lookup::profile_url(intval($row['user_id']), '', $row['season'])) . '"><strong>' . self::name_html($row['name']) . '</strong></a>';
                } else {
                    echo '<strong>' . self::name_html($row['name']) . '</strong> ' . self::chip('account no longer exists', 'red');
                }
                echo '<br><span class="mo-small">' . self::h($row['season']) . '</span></td>';
                echo '<td>' . self::h($row['team']) . '<br><span class="mo-small">' . self::h(self::state_label($row['ended_state'])) . ', ' . self::h(self::dt($row['ended_at'])) . '</span></td>';
                echo '<td>' . self::h(self::money_c($row['paid_cents'])) . '</td>';
                echo '<td>' . self::h(self::money_c($row['invoice_cents'])) . '</td>';
                echo '<td><strong>' . self::h(self::money_c($row['credit_cents'])) . '</strong></td>';
                echo '<td>' . self::chip(self::decision_label($decision), $decision === 'pending' ? 'amber' : ($decision === 'credit' ? 'green' : ''));
                if (intval($row['decided_by']) > 0 || (string) $row['decided_at'] !== '') {
                    echo '<br><span class="mo-small">' . self::h(trim(self::staff_name($row['decided_by']) . ' ' . self::dt($row['decided_at']))) . '</span>';
                }
                echo '</td><td>';
                // The decision and the amount this row showed travel with the button (held_stale() refuses it if either has moved).
                $shown = array('shown_decision' => $decision, 'shown_credit' => intval($row['credit_cents']));
                if ($decision !== 'credit' && $offer_id > 0) {
                    echo self::button('Refund owed', self::row_form('mark_credit', $offer_id, $shown, 'Mark this as credit the club will refund? It stays on their account until you record the refund.'), 'button', false, 'The club broke its side: the club will refund it. Emails nobody.');
                    echo self::button('Club keeps it', self::row_form('forfeit', $offer_id, $shown, 'Forfeit this deposit? The club keeps the money and a negative forfeit line is written to the ledger.'), 'button', false, 'The player broke theirs: the club keeps it. Emails nobody.');
                }
                if (!empty($row['can_apply']) && intval($row['apply_offer_id']) > 0 && $offer_id > 0) {
                    echo self::button('Apply to a new offer', self::row_form('apply', $offer_id, array('to_offer_id' => intval($row['apply_offer_id'])), 'Use this money toward the player\'s new accepted offer? It may lock them in.'));
                }
                echo ' <a class="button" href="' . esc_url($payment_url) . '">Payment Management</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        $forfeit_total = 0;
        foreach ($forfeited as $row) {
            $forfeit_total += intval($row['forfeited_cents']);
        }
        echo '<h3 style="margin-top: 24px;">Forfeited</h3>';
        echo '<p class="description">Deposits the club kept. A forfeit nets the ledger to zero, so the ledger and the bank differ by this amount: this list is the record. Total ' . self::h(self::money_c($forfeit_total)) . ' across ' . intval(count($forfeited)) . ' offer' . (count($forfeited) === 1 ? '' : 's') . '.</p>';
        if (empty($forfeited)) {
            echo '<p>No deposits have been forfeited.</p>';
            return;
        }
        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Offer</th><th>Forfeited</th><th>By</th><th></th></tr></thead><tbody>';
        foreach ($forfeited as $row) {
            echo '<tr><td>';
            if (intval($row['user_id']) > 0 && get_userdata(intval($row['user_id']))) {
                echo '<a href="' . esc_url(TeamOversight_Member_Lookup::profile_url(intval($row['user_id']), '', $row['season'])) . '"><strong>' . self::name_html($row['name']) . '</strong></a>';
            } else {
                echo '<strong>' . self::name_html($row['name']) . '</strong>';
            }
            echo '<br><span class="mo-small">' . self::h($row['season']) . '</span></td>';
            echo '<td>#' . intval($row['offer_id']) . ' ' . self::h($row['team']) . '<br><span class="mo-small">' . self::h(self::state_label($row['ended_state'])) . '</span></td>';
            echo '<td><strong>' . self::h(self::money_c($row['forfeited_cents'])) . '</strong></td>';
            echo '<td>' . self::h(self::staff_name($row['settled_by']) !== '' ? self::staff_name($row['settled_by']) : 'The system') . '<br><span class="mo-small">' . self::h(self::dt($row['settled_at'])) . '</span></td>';
            echo '<td>' . (intval($row['offer_id']) > 0 ? self::button('Reverse forfeit', self::row_form('reverse', intval($row['offer_id']), array(), 'Reverse this forfeit? The money goes back on their account and waits on Held deposits for you to decide again: Refund owed or Club keeps it.')) : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // ------------------------------------------------------------------
    // Tab 5: Closed
    // ------------------------------------------------------------------

    private static function settlement_text($offer) {
        $amount = $offer->settled_amount !== null ? TeamOversight_Offers::money($offer->settled_amount) : '';
        switch ($offer->settlement) {
            case 'pending':
                return 'Deposit waiting for a decision';
            case 'credit':
                return 'Credit' . ($amount !== '' ? ' ' . $amount : '') . ' (club refunds)';
            case 'forfeited':
                return 'Forfeited' . ($amount !== '' ? ' ' . $amount : '');
            case 'carried':
                return 'Deposit carried to another offer';
        }
        return '';
    }

    private static function render_closed_tab($season) {
        $finished = array(
            TeamOversight_Offers::NOT_APPROVED, TeamOversight_Offers::CANCELLED, TeamOversight_Offers::DECLINED,
            TeamOversight_Offers::MOVED_DECLINED, TeamOversight_Offers::WITHDRAWN, TeamOversight_Offers::LEFT,
        );
        $paged = self::get_paged();
        $result = TeamOversight_Offers::list_offers(array('season' => $season, 'states' => $finished, 'order' => 'newest', 'limit' => self::PER_PAGE, 'offset' => ($paged - 1) * self::PER_PAGE));
        $total = intval($result['total']);
        $paged = min($paged, max(1, (int) ceil($total / self::PER_PAGE)));
        if ($paged !== self::get_paged()) {
            $result = TeamOversight_Offers::list_offers(array('season' => $season, 'states' => $finished, 'order' => 'newest', 'limit' => self::PER_PAGE, 'offset' => ($paged - 1) * self::PER_PAGE));
        }
        self::$view['paged'] = $paged;
        if (empty($result['rows'])) {
            echo '<p>No offers have ended yet.</p>';
            return;
        }
        $user_ids = array();
        foreach ($result['rows'] as $offer) {
            if ($offer->user_id) {
                $user_ids[] = intval($offer->user_id);
            }
        }
        if (!empty($user_ids)) {
            cache_users(self::id_list($user_ids));
        }
        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Team</th><th>How it ended</th><th>When</th><th>Deposit</th></tr></thead><tbody>';
        foreach ($result['rows'] as $offer) {
            $by = self::staff_name($offer->closed_by);
            echo '<tr><td>' . self::player_link($offer, $season) . '</td>';
            echo '<td>' . self::team_html($offer->team, $season) . '<br>' . self::chip(TeamOversight_Offers::role_name($offer->role)) . '</td>';
            echo '<td>' . self::chip(self::state_label($offer->state), in_array($offer->state, array('not_approved', 'declined', 'moved_declined', 'left'), true) ? 'red' : '');
            if ((string) $offer->closed_reason !== '') {
                echo '<br><span class="mo-small">' . self::h($offer->closed_reason) . '</span>';
            }
            if ((string) $offer->decline_reason !== '' && in_array($offer->state, TeamOversight_Offers::DECLINED_STATES, true)) {
                echo '<br><span class="mo-small">Their reason: ' . self::h(TeamOversight_Offers::cut($offer->decline_reason, 200)) . '</span>';
            }
            echo '</td>';
            echo '<td class="mo-nowrap">' . self::h(self::dt($offer->closed_at)) . ($by !== '' ? '<br><span class="mo-small">' . self::h($by) . '</span>' : '') . '</td>';
            echo '<td>' . self::h(self::settlement_text($offer)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::paginate($total, $paged, self::view_args());
    }

    // ------------------------------------------------------------------
    // Tab 6: Not selected
    // ------------------------------------------------------------------

    /**
     * The committee's safety net for "not selected" emails (1.80.0): every
     * (player, team) rejection not told yet, oldest verdict first, with how
     * many days ago, Send / Don't send each and Send all; then every notice
     * sent or marked Don't send; then the notices from before 1.80.0 (one
     * per player), kept as history. Nothing here sends anything by itself.
     */
    private static function render_not_selected_tab($season) {
        if (!class_exists('TeamOversight_Not_Selected')) {
            echo '<p>Not selected emails are not available.</p>';
            return;
        }
        $live = TeamOversight_Offers::emails_live();
        echo '<p class="description" style="max-width: 900px;">A "not selected" email is about <strong>one team</strong>: it tells the player they weren\'t selected for that team, says (when they applied for more than one) that their other applications are decided separately, never mentions another team\'s decision, and suggests lower divisions to trial for (or, when there are none, the Mixed Training Program and the Reds Social League, or the Junior Academy for juniors). '
            . 'It is sent <strong>only when someone presses Send</strong>: the team\'s coach on the player\'s card in the coach portal, or you here. Once per player and team. '
            . 'It is addressed like the offer emails: from the club\'s sender, Reply-To the coach who pressed Send (when you press it here: you, if you coach or assistant-coach that team, else its head coach, else its first assistant coach; the trial applications address when the team has no coach on file), with every coach and assistant coach of the team copied, the Reply-To coach included, and signed by the Reply-To coach. Coaches of the player\'s other teams are never copied. '
            . 'Only players who applied for the team are listed (a team can turn down anyone at trials, but the email answers an application). A player who already has a place this season is listed but left out of Send all, and their email suggests no other way in. An email that was pressed but never went (discarded with the held messages, switched off, or things changed) counts as not told and can be sent again. '
            . ($live ? 'This website may send offer emails, so a Send goes out within minutes.' : '<strong>This website may not send offer emails yet</strong>, so a Send waits in the queue until that permission is given (and if that is more than a day away, it is held for you to send or discard then).') . '</p>';

        $untold = TeamOversight_Not_Selected::untold($season);
        echo '<h3 style="margin: 18px 0 4px;">Not told yet (' . count($untold) . ')</h3>';
        $sendable = array();
        $placed_n = 0;
        foreach ($untold as $row) {
            // Send all takes only the ones due (1.80.0 review): nothing stopping it, and no place held this season.
            if (TeamOversight_Not_Selected::is_due($row)) {
                $sendable[] = intval($row['selection_id']);
            } elseif (empty($row['reason']) && !empty($row['placed'])) {
                $placed_n++;
            }
        }
        if (!empty($sendable)) {
            $n = count($sendable);
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mo-bar">'
                . '<input type="hidden" name="action" value="murvc_offer_admin">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('murvc_offer_admin_bulk')) . '">'
                . self::return_fields();
            foreach ($sendable as $id) {
                echo '<input type="hidden" name="ns_sel[]" value="' . intval($id) . '">';
            }
            echo '<button type="submit" class="button" name="offer_action" value="ns_send_all" onclick="return confirm(' . esc_attr(wp_json_encode('Send ' . $n . ' "not selected" email(s), one for each player and team listed as ready? Each tells the player they weren\'t selected for that one team, with that team\'s head coach as the Reply-To and copied in (you, where you coach the team) and its other coaches copied in. Players who already have a place this season are left out. This can\'t be undone.')) . ');">Send all ' . intval($n) . '</button>';
            echo ' <span class="mo-small">Players who can\'t be sent one (the reason is beside them)' . ($placed_n ? ', and ' . intval($placed_n) . ' who already ' . ($placed_n === 1 ? 'has' : 'have') . ' a place this season,' : '') . ' are left out.</span>';
            echo '</form>';
        } elseif ($placed_n) {
            echo '<p class="mo-small">Nobody is ready to send all at once: ' . intval($placed_n) . ' already ' . ($placed_n === 1 ? 'has' : 'have') . ' a place this season (Send or Don\'t send each, if you like).</p>';
        }
        self::render_untold_rows($untold, $season);

        echo '<h3 style="margin: 22px 0 4px;">Sent, and Don\'t send</h3>';
        self::render_record_rows(TeamOversight_Not_Selected::record($season), $season);

        $legacy = TeamOversight_Not_Selected::legacy_rows($season);
        if (!empty($legacy)) {
            echo '<h3 style="margin: 22px 0 4px;">Before 1.80.0 (one notice per player)</h3>';
            echo '<p class="description" style="max-width: 900px;">Until 1.80.0 the notice was one per player, scheduled automatically once every team had said no. These are kept as a record and are never sent now. Ones that had gone out (or that you cancelled) were copied to each team that had the player Rejected; every other rejection is in Not told yet above.</p>';
            self::render_legacy_rows($legacy, $season);
        }
    }

    /**
     * The "not told yet" table, from TeamOversight_Not_Selected::untold()
     * shaped rows (selection_id, application_id, team, team_name, name,
     * trial_number, verdict_at, days, reason, and optionally placed, dropped
     * and notice). A player who already has a place this season says so
     * (and is not in Send all); a notice pressed before whose email never
     * went says what happened to it. Public so a test can draw rows it made.
     */
    public static function render_untold_rows(array $rows, $season) {
        if (empty($rows)) {
            echo '<p>Every player turned down for ' . self::h($season) . ' has been told (or there is nobody to tell).</p>';
            return;
        }
        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Team</th><th>Rejected</th><th>Can it be sent?</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $sel = intval($row['selection_id']);
            $days = intval(isset($row['days']) ? $row['days'] : 0);
            $reason = isset($row['reason']) && is_array($row['reason']) ? $row['reason'] : null;
            $team_name = isset($row['team_name']) ? (string) $row['team_name'] : (string) $row['team'];
            echo '<tr><td>' . self::name_html(isset($row['name']) ? $row['name'] : '') . '<br><span class="mo-small">Application #' . intval($row['application_id']) . (!empty($row['trial_number']) ? ', trial #' . intval($row['trial_number']) : '') . '</span></td>';
            echo '<td>' . self::team_html(isset($row['team']) ? $row['team'] : '', $season) . '</td>';
            echo '<td class="mo-nowrap">' . self::h(self::d(substr(isset($row['verdict_at']) ? (string) $row['verdict_at'] : '', 0, 10))) . '<br><span class="mo-small">'
                . ($days === 0 ? 'today' : $days . ' day' . ($days === 1 ? '' : 's') . ' ago') . '</span>'
                . ($days >= TeamOversight_Not_Selected::UNTOLD_FLAG_DAYS && TeamOversight_Not_Selected::is_due($row) ? ' ' . self::chip('over a week', 'amber') : '') . '</td>';
            $placed = !empty($row['placed']);
            $notice = isset($row['notice']) && is_array($row['notice']) ? $row['notice'] : null;
            echo '<td>' . ($reason ? self::chip('No', 'red') . '<br><span class="mo-small">' . self::h($reason['text']) . '</span>' : self::chip('Yes', 'green'))
                . ($placed && !$reason ? '<br><span class="mo-small">Already has a place this season (an offer they accepted, or placed), so not in Send all.</span>' : '')
                . (!empty($row['dropped']) && $notice ? '<br><span class="mo-small">Pressed before, but never sent: ' . self::h(TeamOversight_Not_Selected::status_text($notice)) . '</span>' : '')
                . '</td>';
            echo '<td>';
            if (!$reason) {
                echo self::button('Send', self::row_form('ns_send', $sel, array(), 'Email ' . self::plain_name(isset($row['name']) ? $row['name'] : '') . ' that they weren\'t selected for ' . $team_name . '? The email is only about ' . $team_name . '. Replies go to you if you coach it, else to its head coach, and every coach of the team is copied in, including the one replies go to. It can only be sent once.' . ($placed ? ' They already have a place this season, so the email suggests no other way in.' : '')), 'button', false, 'Tell them they weren\'t selected for ' . $team_name . ' only.');
            }
            echo self::button('Don\'t send', self::row_form('ns_stop', $sel, array(), 'Never send ' . self::plain_name(isset($row['name']) ? $row['name'] : '') . ' a "not selected" email for ' . $team_name . '? It is kept as a record and their coach\'s card will say the club decided not to send it.'), 'button', false, 'Kept as a record; never sent.');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * The sent / Don't send table, from TeamOversight_Not_Selected::record()
     * shaped rows (application_id, name, trial_number, team, team_name,
     * state, text, by_name, at, verdict, email_status). Public so a test
     * can draw rows it made.
     */
    public static function render_record_rows(array $rows, $season) {
        if (empty($rows)) {
            echo '<p>No "not selected" email has been sent or stopped for ' . self::h($season) . ' yet.</p>';
            return;
        }
        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Team</th><th>What happened</th><th>Email</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $state = isset($row['state']) ? (string) $row['state'] : '';
            $verdict = isset($row['verdict']) ? (string) $row['verdict'] : '';
            echo '<tr><td>' . self::name_html(isset($row['name']) ? $row['name'] : '') . '<br><span class="mo-small">Application #' . intval($row['application_id']) . (!empty($row['trial_number']) ? ', trial #' . intval($row['trial_number']) : '') . '</span></td>';
            echo '<td>' . self::team_html(isset($row['team']) ? $row['team'] : '', $season) . '</td>';
            $email = isset($row['email_status']) ? (string) $row['email_status'] : '';
            // The chip follows where the email really is (1.80.0 review), never a green Sent beside "not sent".
            if ($state === 'stopped') {
                $what = array('Don\'t send', '');
            } elseif ($state === 'failed') {
                $what = array('Not queued', 'red');
            } elseif ($email === 'sent' || ($email === '' && !empty($row['legacy']))) {
                $what = array('Sent', 'green');
            } elseif ($email === 'skipped') {
                $what = array('Not sent', 'red');
            } elseif ($email === 'failed' || $email === 'unknown') {
                $what = array('Problem', 'red');
            } elseif ($email === 'held') {
                $what = array('Held', 'amber');
            } else {
                $what = array('Waiting to send', '');
            }
            echo '<td>' . self::chip($what[0], $what[1])
                . ' ' . self::h(isset($row['text']) ? $row['text'] : '')
                . ($state === 'sent' && $email === 'skipped' && $verdict === 'rejected' ? '<br><span class="mo-small">The player was never told: while they are still Rejected and their application is open, it is under Not told yet above and can be sent again.</span>' : '')
                . (!empty($row['legacy']) ? '<br><span class="mo-small">From the one-per-player notice before 1.80.0.</span>' : '')
                . ($verdict !== 'rejected' && $state !== 'failed' ? '<br><span class="mo-small">' . ($verdict === '' ? 'The team has no verdict on them now.' : 'Their verdict for this team is now ' . self::h(strtolower(str_replace('_', ' ', $verdict))) . '.') . '</span>' : '')
                . '</td>';
            echo '<td>' . ($email !== '' ? self::chip($email, in_array($email, array('failed', 'unknown'), true) ? 'red' : '') : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** The notices from before 1.80.0 (TeamOversight_Not_Selected::legacy_rows()), read-only. Public so a test can draw rows it made. */
    public static function render_legacy_rows(array $rows, $season) {
        if (empty($rows)) {
            return;
        }
        $words = array('scheduled' => 'Was scheduled', 'held' => 'Was waiting for you', 'queued' => 'Sent', 'cancelled' => 'Cancelled');
        echo '<div class="mo-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Then</th><th>Dates</th><th>Email</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $state = isset($row['state']) ? (string) $row['state'] : '';
            echo '<tr><td>' . self::name_html(isset($row['name']) ? $row['name'] : '') . '<br><span class="mo-small">Application #' . intval($row['application_id']) . (!empty($row['trial_number']) ? ', trial #' . intval($row['trial_number']) : '') . '</span></td>';
            echo '<td>' . self::chip(isset($words[$state]) ? $words[$state] : $state, '') . '</td>';
            echo '<td class="mo-nowrap"><span class="mo-small">From ' . self::h(self::dt(isset($row['since']) ? $row['since'] : '')) . (!empty($row['queued_at']) ? '<br>Queued ' . self::h(self::dt($row['queued_at'])) : '') . '</span></td>';
            $email = isset($row['email_status']) ? (string) $row['email_status'] : '';
            echo '<td>' . ($email !== '' ? self::chip($email, '') : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // ------------------------------------------------------------------
    // The buttons (admin-post murvc_offer_admin)
    // ------------------------------------------------------------------

    /** The posted field that holds a button's target id. */
    private static function target_id($act, array $data) {
        $field = self::id_field($act);
        return isset($data[$field]) && is_scalar($data[$field]) ? max(0, intval($data[$field])) : 0;
    }

    /** The nonce action a button's form was made with, or '' when the request names no valid button. */
    private static function nonce_action($act, array $data) {
        if (in_array($act, self::BULK_ACTIONS, true)) {
            return 'murvc_offer_admin_bulk';
        }
        if (!in_array($act, self::ACTIONS, true)) {
            return '';
        }
        $id = self::target_id($act, $data);
        return $id > 0 ? 'murvc_offer_admin_' . $act . '_' . $id : '';
    }

    /** Where to send the administrator after a button: the list they were on. */
    private static function return_url(array $data) {
        $tab = isset($data['return_tab']) && is_string($data['return_tab']) && isset(self::TABS[$data['return_tab']]) ? $data['return_tab'] : 'approve';
        $args = array('tab' => $tab);
        if (isset($data['return_season']) && self::valid_season($data['return_season'])) {
            $args['season'] = $data['return_season'];
        }
        $paged = isset($data['return_paged']) && is_scalar($data['return_paged']) ? max(1, intval($data['return_paged'])) : 1;
        if ($paged > 1) {
            $args['paged'] = $paged;
        }
        $team = isset($data['return_team']) ? self::clean_team($data['return_team']) : '';
        if ($team !== '') {
            $args['team'] = $team;
        }
        if (isset($data['return_sort']) && $data['return_sort'] === 'team') {
            $args['sort'] = 'team';
        }
        return self::page_url($args);
    }

    /**
     * admin-post murvc_offer_admin: every single and bulk button. Checks
     * manage_options, then the button's own nonce, runs the action
     * (process_action()), leaves a flash summary and redirects back to the
     * list the administrator was on.
     */
    public static function handle_action() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions', '', array('response' => 403));
        }
        $data = wp_unslash($_POST);
        $act = isset($data['offer_action']) && is_string($data['offer_action']) ? sanitize_key($data['offer_action']) : '';
        $nonce_action = self::nonce_action($act, $data);
        $nonce = isset($data['_wpnonce']) && is_string($data['_wpnonce']) ? $data['_wpnonce'] : '';
        if ($nonce_action === '' || $nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) {
            wp_die('This page has expired. Go back, reload it and try again.', '', array('response' => 403));
        }
        $result = self::process_action($data, get_current_user_id());
        self::set_flash($result);
        wp_safe_redirect(self::return_url($data));
        exit;
    }

    /**
     * Run one button. $data: the unslashed POST (offer_action and its
     * fields). No nonce here (handle_action() checked it); the actor must
     * pass can_approve(), and every TeamOversight_Offers method checks it
     * again. Returns array(ok, type (success|warning|error), message, lines).
     */
    public static function process_action(array $data, $actor_id) {
        $actor_id = intval($actor_id);
        $act = isset($data['offer_action']) && is_string($data['offer_action']) ? sanitize_key($data['offer_action']) : '';
        if (!TeamOversight_Offers::can_approve($actor_id)) {
            return self::result(false, 'Only administrators can do this.');
        }
        if (in_array($act, self::BULK_ACTIONS, true)) {
            return self::process_bulk($act, $data, $actor_id);
        }
        if (!in_array($act, self::ACTIONS, true)) {
            return self::result(false, 'That button is not recognised: reload the page.');
        }
        $id = self::target_id($act, $data);
        if ($id <= 0) {
            return self::result(false, 'No offer was given: reload the page.');
        }
        if ($act === 'resend') {
            return self::do_resend($id, $actor_id);
        }
        if ($act === 'ns_send' || $act === 'ns_stop') {
            return self::do_not_selected($act, $id, $actor_id);
        }
        $offer = TeamOversight_Offers::get($id);
        if (!$offer) {
            return self::result(false, 'That offer no longer exists: reload the page.');
        }
        $label = self::offer_label($offer);
        $shown_state = isset($data['shown_state']) && is_string($data['shown_state']) ? sanitize_key($data['shown_state']) : '';
        $shown = array('state' => $shown_state);

        // The core methods compare the state the page showed for remind, move to declined and withdraw. These three have no such check there.
        if (in_array($act, array('extend', 'retry', 'pull_out'), true) && $shown_state !== '' && $shown_state !== $offer->state) {
            return self::result(false, $label . ': this offer changed after the page loaded (it is now ' . strtolower(self::state_label($offer->state)) . '). Reload and check it.');
        }
        // Mark credit and Forfeit decide about money: refuse them when the decision or the amount the row showed has moved.
        if (in_array($act, array('mark_credit', 'forfeit'), true)) {
            $moved = self::held_stale($offer, $data);
            if ($moved !== '') {
                return self::result(false, $label . ': ' . $moved);
            }
        }

        switch ($act) {
            case 'approve':
                $quote = isset($data['shown_quote']) && is_scalar($data['shown_quote']) ? (string) $data['shown_quote'] : '';
                $target = isset($data['shown_target']) && is_scalar($data['shown_target']) ? (string) $data['shown_target'] : '';
                if (!is_numeric($quote) || !is_numeric($target)) {
                    return self::result(false, $label . ': the figures you were looking at did not come with the button. Reload the page and approve again.');
                }
                $r = TeamOversight_Offers::approve($id, $actor_id, array('shown_quote' => $quote, 'shown_target' => $target, 'fees' => self::list_fees()));
                if (!empty($r['ok']) && !empty($r['changed']) && !empty($r['coach_notice'])) {
                    $r['message'] .= ' ' . $r['coach_notice'];
                }
                break;
            case 'reject':
                $r = TeamOversight_Offers::reject($id, $actor_id, isset($data['note']) && is_string($data['note']) ? $data['note'] : '');
                break;
            case 'remind':
                $r = TeamOversight_Offers::admin_remind($id, $actor_id, $shown);
                break;
            case 'extend':
                $r = TeamOversight_Offers::admin_extend($id, $actor_id, array('state' => $shown_state, 'date' => isset($data['shown_date']) && is_string($data['shown_date']) ? $data['shown_date'] : ''));
                break;
            case 'move_declined':
                $r = TeamOversight_Offers::admin_move_to_declined($id, $actor_id, $shown);
                break;
            case 'withdraw':
                $r = TeamOversight_Offers::admin_withdraw($id, $actor_id, $shown);
                break;
            case 'retry':
                $start = isset($data['start']) && is_string($data['start']) ? trim($data['start']) : '';
                $invalid = TeamOversight_Fee_Timeline::validate_start($offer->season, $start);
                if ($invalid !== null) {
                    return self::result(false, $label . ': ' . $invalid);
                }
                $r = TeamOversight_Offers::retry_placement($id, $actor_id, $start);
                break;
            case 'pull_out':
                $who = isset($data['who_broke']) && is_string($data['who_broke']) ? $data['who_broke'] : '';
                if (!in_array($who, array('player', 'club'), true)) {
                    return self::result(false, $label . ': choose who broke the commitment.');
                }
                $r = TeamOversight_Offers::pull_out($id, $actor_id, $who);
                break;
            case 'mark_credit':
                $r = TeamOversight_Offers::mark_credit($id, $actor_id);
                break;
            case 'forfeit':
                $r = TeamOversight_Offers::forfeit_held($id, $actor_id);
                break;
            case 'apply':
                $to = isset($data['to_offer_id']) && is_scalar($data['to_offer_id']) ? intval($data['to_offer_id']) : 0;
                if ($to <= 0) {
                    return self::result(false, $label . ': no offer to apply it to was given. Reload the page.');
                }
                $r = TeamOversight_Offers::apply_to_new_offer($id, $to, $actor_id);
                break;
            case 'reverse':
                $r = TeamOversight_Offers::reverse_forfeit($id, $actor_id);
                break;
            default:
                return self::result(false, 'That button is not recognised: reload the page.');
        }
        return self::from_core($label, $r);
    }

    /**
     * Has the held deposit this button was drawn for moved since the page
     * loaded? The form posted the decision and the credit (in cents) the row
     * showed; if the ledger-derived list now has that offer's row with a
     * different decision or amount, return why in words, else ''. A row that
     * is no longer listed is left to the money method, which does its own
     * compare-and-set. Reads only.
     */
    private static function held_stale($offer, array $data) {
        $shown_decision = isset($data['shown_decision']) && is_string($data['shown_decision']) ? $data['shown_decision'] : '';
        $shown_credit = (isset($data['shown_credit']) && is_scalar($data['shown_credit']) && preg_match('/^\d{1,12}$/D', (string) $data['shown_credit']) === 1) ? intval($data['shown_credit']) : null;
        if ($shown_decision === '' && $shown_credit === null) {
            return '';
        }
        foreach (self::held_rows($offer->season) as $row) {
            if (intval($row['offer_id']) !== intval($offer->id)) {
                continue;
            }
            if ($shown_decision !== '' && $row['decision'] !== $shown_decision) {
                return 'this deposit changed after the page loaded (it now reads "' . self::decision_label($row['decision']) . '"). Reload and check it.';
            }
            if ($shown_credit !== null && intval($row['credit_cents']) !== $shown_credit) {
                return 'the amount held changed after the page loaded (it is now ' . self::money_c($row['credit_cents']) . '). Reload and check it.';
            }
            return '';
        }
        return '';
    }

    /** A TeamOversight_Offers result as a one-line summary. */
    private static function from_core($label, array $r) {
        $message = isset($r['message']) ? (string) $r['message'] : '';
        if (!empty($r['ok']) && !empty($r['changed']) && isset($r['amount_cents']) && intval($r['amount_cents']) > 0 && strpos($message, '$') === false) {
            $message .= ' (' . self::money_c($r['amount_cents']) . ')';
        }
        if (!empty($r['ok']) && isset($r['code']) && $r['code'] === 'already') {
            // A double click or a stale page repeating a finished step: say so, and don't dress it up as a new success.
            $message = ($message === '' || $message === 'Already done.') ? 'Nothing to do: it was already done.' : 'Nothing to do: ' . $message;
        }
        return self::result(!empty($r['ok']), $label . ': ' . $message);
    }

    private static function do_resend($row_id, $actor_id) {
        if (!class_exists('TeamOversight_Offer_Emails')) {
            return self::result(false, 'Offer emails are not available.');
        }
        $r = TeamOversight_Offer_Emails::resend($row_id, $actor_id);
        return self::result(!empty($r['ok']), isset($r['message']) ? (string) $r['message'] : '');
    }

    /** A name and team for a flash line about one verdict row ("Kim Lee (SL1M)"). */
    private static function selection_label($selection_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT s.team, a.name FROM {$wpdb->prefix}team_trial_selections s LEFT JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id WHERE s.id = %d", intval($selection_id)));
        return $row ? trim(self::plain_name((string) $row->name)) . ' (' . $row->team . ')' : 'Verdict #' . intval($selection_id);
    }

    /** Send or Don't send one "not selected" email (1.80.0): $selection_id is the verdict row the page showed. */
    private static function do_not_selected($act, $selection_id, $actor_id) {
        if (!class_exists('TeamOversight_Not_Selected')) {
            return self::result(false, 'Not selected emails are not available.');
        }
        $label = self::selection_label($selection_id);
        $r = $act === 'ns_send' ? TeamOversight_Not_Selected::admin_send($selection_id, $actor_id) : TeamOversight_Not_Selected::admin_stop($selection_id, $actor_id);
        return self::result(!empty($r['ok']), $label . ': ' . (isset($r['message']) ? (string) $r['message'] : ''));
    }

    /**
     * Send all on the Not selected tab: the verdict rows the page listed as
     * ready to send (ns_sel[]), each through the same admin_send() as its
     * own button, so every rule is checked again one by one.
     */
    private static function process_ns_bulk($act, array $data, $actor_id) {
        if (!class_exists('TeamOversight_Not_Selected')) {
            return self::result(false, 'Not selected emails are not available.');
        }
        $ids = (isset($data['ns_sel']) && is_array($data['ns_sel'])) ? self::id_list(array_filter($data['ns_sel'], 'is_scalar')) : array();
        if (empty($ids)) {
            return self::result(false, 'There was nobody to send on the page you were looking at. Reload it.');
        }
        if (count($ids) > 500) {
            return self::result(false, 'That is too many at once (the most is 500).');
        }
        $done = 0;
        $already = 0;
        $lines = array();
        foreach ($ids as $id) {
            $r = TeamOversight_Not_Selected::admin_send($id, $actor_id);
            if (!empty($r['ok']) && !empty($r['changed'])) {
                $done++;
            } elseif (!empty($r['ok'])) {
                $already++;
            } else {
                $lines[] = self::selection_label($id) . ': ' . (isset($r['message']) ? (string) $r['message'] : '');
            }
        }
        $message = $done . ' "not selected" email(s) ' . (TeamOversight_Offers::emails_live() ? 'on their way' : 'queued: they wait until this website may send offer emails') . '.'
            . ($already ? ' ' . $already . ' had already been sent.' : '');
        if ($lines) {
            $message .= ' ' . count($lines) . ' could not be sent:';
        }
        return self::result(count($lines) === 0, $message, self::cap_lines($lines), count($lines) === 0 ? 'success' : ($done > 0 ? 'warning' : 'error'));
    }

    /** Approve selected, Approve all shown and Decline selected. */
    private static function process_bulk($act, array $data, $actor_id) {
        if ($act === 'ns_send_all') {
            return self::process_ns_bulk($act, $data, $actor_id);
        }
        $sel = (isset($data['sel']) && is_array($data['sel'])) ? self::id_list(array_filter($data['sel'], 'is_scalar')) : array();
        $shown_quote = (isset($data['shown_quote']) && is_array($data['shown_quote'])) ? $data['shown_quote'] : array();
        $shown_target = (isset($data['shown_target']) && is_array($data['shown_target'])) ? $data['shown_target'] : array();
        $ids = $act === 'approve_all' ? self::id_list(array_keys($shown_quote)) : $sel;
        if (empty($ids)) {
            return self::result(false, $act === 'approve_all' ? 'There is nothing on this page that can be approved.' : 'Tick at least one request first.');
        }
        if (count($ids) > self::BULK_MAX) {
            return self::result(false, 'That is too many at once (the most is ' . self::BULK_MAX . '). Use a smaller page.');
        }

        if ($act === 'bulk_reject') {
            $note = isset($data['note']) && is_string($data['note']) ? $data['note'] : '';
            $done = 0;
            $already = 0;
            $lines = array();
            foreach ($ids as $id) {
                $offer = TeamOversight_Offers::get($id);
                $r = TeamOversight_Offers::reject($id, $actor_id, $note);
                if (!empty($r['ok']) && !empty($r['changed'])) {
                    $done++;
                } elseif (!empty($r['ok'])) {
                    $already++;
                } else {
                    $lines[] = self::offer_label($offer) . ': ' . $r['message'];
                }
            }
            $message = $done . ' declined' . (count($lines) ? ', ' . count($lines) . ' refused' : '') . ($already ? ', ' . $already . ' already declined' : '') . '. The coaches see Not approved; the players are told nothing.';
            return self::result(count($lines) === 0, $message, self::cap_lines($lines), count($lines) === 0 ? 'success' : ($done > 0 ? 'warning' : 'error'));
        }

        // Approve: each row goes in with the figures it showed; a row whose figures did not come is refused here.
        $items = array();
        $missing = array();
        foreach ($ids as $id) {
            if (isset($shown_quote[$id], $shown_target[$id]) && is_scalar($shown_quote[$id]) && is_scalar($shown_target[$id]) && is_numeric($shown_quote[$id]) && is_numeric($shown_target[$id])) {
                $items[$id] = array('shown_quote' => (string) $shown_quote[$id], 'shown_target' => (string) $shown_target[$id]);
            } else {
                $missing[$id] = 'the figures you were looking at did not come with it (it could not be priced, or the page is out of date).';
            }
        }
        $lines = array();
        foreach ($missing as $id => $why) {
            $lines[] = self::offer_label(TeamOversight_Offers::get($id)) . ': ' . $why;
        }
        $approved = array();
        $already = array();
        if (!empty($items)) {
            $offers = array();
            foreach (array_keys($items) as $id) {
                $offer = TeamOversight_Offers::get($id);
                if ($offer) {
                    $offers[] = $offer;
                }
            }
            $people = array();
            $seasons = array();
            foreach ($offers as $offer) {
                $user = $offer->user_id ? get_userdata($offer->user_id) : false;
                $people[] = array('user_id' => intval($offer->user_id), 'email' => $user ? $user->user_email : $offer->email);
                $seasons[$offer->season] = true;
            }
            TeamOversight_Fee_Timeline::preload_people($people, array_keys($seasons));
            $r = TeamOversight_Offers::approve_many($items, $actor_id, array('fees' => self::list_fees()));
            $approved = $r['approved'];
            $already = $r['already'];
            foreach ($r['refused'] as $id => $why) {
                $changed_new = isset($r['changed'][$id]) ? ' (changed, review)' : '';
                $lines[] = self::offer_label(TeamOversight_Offers::get($id)) . $changed_new . ': ' . $why;
            }
        }
        $refused = count($lines);
        $message = count($approved) . ' approved' . ($refused ? ', ' . $refused . ' refused' : '') . (count($already) ? ', ' . count($already) . ' already approved' : '') . '.';
        if (!empty($approved)) {
            $message .= TeamOversight_Offers::emails_live()
                ? ' The offer emails are queued.'
                : ' The offer emails are queued, but this website may not send offer emails yet: those players are not emailed until you allow it, and their reply-by dates are already running.';
        }
        return self::result($refused === 0, $message, self::cap_lines($lines), $refused === 0 ? 'success' : (count($approved) > 0 ? 'warning' : 'error'));
    }

    /** At most 15 lines in a flash, then "and N more". */
    private static function cap_lines(array $lines) {
        if (count($lines) <= 15) {
            return $lines;
        }
        $more = count($lines) - 15;
        $lines = array_slice($lines, 0, 15);
        $lines[] = 'and ' . $more . ' more.';
        return $lines;
    }

    // ------------------------------------------------------------------
    // The Held deposits CSV (admin-post murvc_offer_held_csv)
    // ------------------------------------------------------------------

    /**
     * The CSV for one list as text (UTF-8 with a BOM, as the other exports).
     * $list: 'held' (the Held deposits tab) or 'forfeited' (the Forfeited
     * list). Names and emails go through csv_cell(); money columns are plain
     * numbers (credits are negative-free, but a leading + or - is real).
     */
    public static function csv_text($list, $season) {
        return self::csv_from_rows($list, $list === 'forfeited' ? self::forfeited_rows($season) : self::held_rows($season));
    }

    /** The CSV for one list from rows in the money builder's shapes (csv_text() reads them; a test passes its own). */
    public static function csv_from_rows($list, array $rows) {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        if ($list === 'forfeited') {
            fputcsv($out, array('Player', 'Email', 'Season', 'Team', 'Offer', 'How it ended', 'Forfeited', 'Settled by', 'Settled at'));
            foreach (array_map(array(__CLASS__, 'forfeited_defaults'), array_values($rows)) as $row) {
                fputcsv($out, array(
                    TeamOversight_Exports::csv_cell(self::plain_name($row['name'])),
                    TeamOversight_Exports::csv_cell($row['email']),
                    $row['season'],
                    TeamOversight_Exports::csv_cell($row['team']),
                    intval($row['offer_id']),
                    self::state_label($row['ended_state']),
                    self::money_plain($row['forfeited_cents']),
                    TeamOversight_Exports::csv_cell(self::staff_name($row['settled_by'])),
                    (string) $row['settled_at'],
                ));
            }
        } else {
            fputcsv($out, array('Player', 'Email', 'Season', 'Team', 'Offer', 'How it ended', 'Ended', 'Paid', 'Invoice amount', 'Credit held', 'Decision', 'Decided by', 'Decided at', 'Settled amount'));
            foreach (array_map(array(__CLASS__, 'held_defaults'), array_values($rows)) as $row) {
                fputcsv($out, array(
                    TeamOversight_Exports::csv_cell(self::plain_name($row['name'])),
                    TeamOversight_Exports::csv_cell($row['email']),
                    $row['season'],
                    TeamOversight_Exports::csv_cell($row['team']),
                    intval($row['offer_id']),
                    self::state_label($row['ended_state']),
                    (string) $row['ended_at'],
                    self::money_plain($row['paid_cents']),
                    self::money_plain($row['invoice_cents']),
                    self::money_plain($row['credit_cents']),
                    self::decision_label(isset($row['decision']) ? $row['decision'] : 'none'),
                    TeamOversight_Exports::csv_cell(self::staff_name($row['decided_by'])),
                    (string) $row['decided_at'],
                    self::money_plain($row['settled_cents']),
                ));
            }
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string) $csv;
    }

    /**
     * What the CSV button sends, or why it may not: the checks of held_csv()
     * without the headers and the exit, so they can be tested. $request is
     * the unslashed POST/GET. Returns array(ok, status, message, filename,
     * csv): on failure ok is false with an HTTP status and a message.
     * Administrators only (manage_options and can_approve()), the button's
     * own nonce, the season validated (else the page's season) and the list
     * from a whitelist. Reads only.
     */
    public static function csv_payload(array $request) {
        $fail = function ($status, $message) {
            return array('ok' => false, 'status' => $status, 'message' => $message, 'filename' => '', 'csv' => '');
        };
        if (!current_user_can('manage_options') || !TeamOversight_Offers::can_approve(get_current_user_id())) {
            return $fail(403, 'Insufficient permissions');
        }
        $nonce = isset($request['_wpnonce']) && is_string($request['_wpnonce']) ? $request['_wpnonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::CSV_NONCE)) {
            return $fail(403, 'This page has expired. Go back, reload it and try again.');
        }
        $season = isset($request['season']) && self::valid_season($request['season']) ? $request['season'] : self::current_season();
        $list = isset($request['list']) && $request['list'] === 'forfeited' ? 'forfeited' : 'held';
        return array(
            'ok' => true,
            'status' => 200,
            'message' => '',
            'filename' => ($list === 'forfeited' ? 'forfeited-deposits-' : 'held-deposits-') . $season . '.csv',
            'csv' => self::csv_text($list, $season),
        );
    }

    /** admin-post murvc_offer_held_csv: the Held deposits or Forfeited CSV. Read-only; manage_options and its own nonce. */
    public static function held_csv() {
        $payload = self::csv_payload(wp_unslash($_REQUEST));
        if (!$payload['ok']) {
            wp_die(esc_html($payload['message']), '', array('response' => $payload['status']));
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $payload['filename'] . '"');
        echo $payload['csv'];
        exit;
    }
}
