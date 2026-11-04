<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club program attendance tracking.
 *
 * Casual club programs (Mixed Development, and anything else run the same
 * way) sell one WooCommerce product per program, with a variation per
 * session date. Everything needed to answer "who is coming on Monday?" is
 * therefore already in the orders: this class reads it and presents a
 * session roster with contact and emergency details for whoever is
 * supervising on the night.
 *
 * Admin: Club Programs → one sub-page per program, plus Settings.
 * Front end: [session_attendance] for supervisors who aren't WP admins.
 *
 * 1.84.0 (program bookings, readers and fixes): a program reads every one
 * of its products (program_products(): current, prepared, earlier, each
 * with a kind), the parser reads full dates and month labels and tries
 * only a product's listed years, refunded lines leave the roll and the
 * figures (refund_state() is the ONE rule), history and stats read every
 * product (get_program_history()), and both portal shortcodes are never
 * cached or indexed (no_cache()).
 *
 * 1.85.0 (setup, sessions, switch): writers are a program's OWN supervisors
 * and administrators (can_write_sessions(); the legacy club-wide list only
 * views); the admin page has tabs (Roll and stats, Sessions, Setup, To
 * refund) and a portal shows Roll / Sessions links only once the Sessions
 * tab is available, so a legacy program's portal is drawn exactly as
 * before; a program selling dated sessions has its own roll, one card per
 * order line with per-line ticks (team_program_line_ticks) and its earlier
 * date-label products under Earlier seasons; a paid order with session
 * lines is imported into the shop's analytics at once; the Settings screen
 * saves through admin-post under the setup lock.
 */
class TeamOversight_Programs {

    const PROGRAMS_OPTION = 'team_oversight_programs';
    const SUPERVISORS_OPTION = 'team_oversight_program_supervisors';
    /** 1.84.0: turns the refund rule off. For the golden harness only (it must then match 1.83.1 exactly). */
    const REFUND_FILTER = 'murvc_program_refund_rule';
    /** 1.84.0: the shortcodes that draw a supervisor portal (names, mobiles, emergency details). */
    const PORTAL_SHORTCODES = array('session_attendance', 'mixed_dev_attendance');
    /** 1.85.0: the Settings screen's notice after its admin-post save. */
    const SETTINGS_FLASH = 'murvc_program_settings_flash_';

    /** Request caches (1.84.0), dropped by flush_program_caches(). */
    private static $products_cache = array();
    private static $history_cache = array();
    /** 1.85.0: static hooks added once, and the orders to import into analytics at shutdown. */
    private static $hooks_added = false;
    private static $imports = array();
    private static $imports_hooked = false;

    public function __construct() {
        // 1.84.0: a page holding a portal shortcode is never cached or
        // indexed. Static, so the admin's extra instance adds nothing twice.
        add_action('template_redirect', array(__CLASS__, 'maybe_no_cache'), 9);
        // Attendance ticks post back and redirect (PRG) so a refresh never
        // re-submits; state is explicit, not a flip.
        add_action('template_redirect', array($this, 'maybe_handle_attendance'));
        // Instant marking + a poll so supervisors on different courts see
        // each other's ticks without reloading.
        add_action('wp_ajax_murvc_mark_attendance', array($this, 'ajax_mark_attendance'));
        add_action('wp_ajax_murvc_attendance_state', array($this, 'ajax_attendance_state'));
        add_shortcode('session_attendance', array($this, 'render_shortcode'));
        // Mixed Development runs weekly and has its own page, so it gets a
        // dedicated shortcode — a stable place to hang program-specific
        // behaviour without touching the generic one.
        add_shortcode('mixed_dev_attendance', array($this, 'render_mixed_dev_shortcode'));
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * Configured programs: key => array(name, product_id). Seeds itself
     * from the Mixed Development product the first time it's needed.
     */
    public static function get_programs() {
        $programs = get_option(self::PROGRAMS_OPTION, null);
        if (is_array($programs) && !empty($programs)) {
            return $programs;
        }

        global $wpdb;
        $product_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product' AND post_status = 'publish'
                AND post_title LIKE '%Mixed Development%'
            ORDER BY ID DESC LIMIT 1
        ");
        if (!$product_id) {
            return array();
        }
        return array(
            // Mixed Dev runs Tuesdays; the variation labels carry no year,
            // so the weekday is what pins each one to the right year.
            'mixed-dev' => array('name' => 'Mixed Development', 'product_id' => intval($product_id), 'weekday' => 2),
        );
    }

    /** ISO weekday (1 Mon … 7 Sun) a program runs on, or 0 if unset. */
    public static function get_program_weekday($program) {
        if (empty($program['weekday'])) {
            return 0;
        }
        $value = $program['weekday'];
        if (is_numeric($value)) {
            $n = intval($value);
            return ($n >= 1 && $n <= 7) ? $n : 0;
        }
        $ts = strtotime((string) $value);
        return $ts ? intval(date('N', $ts)) : 0;
    }

    public static function get_program($key) {
        $programs = self::get_programs();
        return isset($programs[$key]) ? $programs[$key] : null;
    }

    /** Legacy club-wide supervisor list (pre-1.35), still honoured. */
    public static function get_supervisors() {
        $ids = get_option(self::SUPERVISORS_OPTION, array());
        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    /** Supervisors for one program: its own list plus any legacy club-wide ones. */
    public static function get_program_supervisors($program_key) {
        $program = self::get_program($program_key);
        $own = ($program && !empty($program['supervisors']) && is_array($program['supervisors']))
            ? array_map('intval', $program['supervisors'])
            : array();
        return array_values(array_unique(array_merge($own, self::get_supervisors())));
    }

    /**
     * Admins always; otherwise a supervisor of that program (or of any
     * program when no key is given).
     */
    public static function user_can_view($program_key = null, $user_id = null) {
        $user_id = $user_id ? intval($user_id) : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        if ($program_key !== null) {
            return in_array($user_id, self::get_program_supervisors($program_key), true);
        }
        foreach (array_keys(self::get_programs()) as $key) {
            if (in_array($user_id, self::get_program_supervisors($key), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 1.85.0 (design 5.1, D26): who may write a program's sessions: an
     * administrator, or someone on the program's OWN supervisor list. The
     * legacy club-wide list still views a roll but never writes. A null user
     * is the current one; 0 is nobody.
     */
    public static function can_write_sessions($program_key, $user_id = null) {
        $user_id = $user_id === null ? get_current_user_id() : intval($user_id);
        if ($user_id <= 0) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $program = self::get_program((string) $program_key);
        if (!is_array($program) || empty($program['supervisors']) || !is_array($program['supervisors'])) {
            return false;
        }
        return in_array($user_id, array_map('intval', $program['supervisors']), true);
    }

    /**
     * 1.85.0: the static hooks, added once (the constructor's instance hooks
     * stay as they are): the analytics import of a paid or refunded order
     * with session lines (design 4.12, D22), and the Settings screen's
     * admin-post save (design 6, B16).
     */
    public static function register_hooks() {
        if (self::$hooks_added) {
            return;
        }
        self::$hooks_added = true;
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'queue_import'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'queue_import'));
        add_action('woocommerce_order_refunded', array(__CLASS__, 'queue_refund_import'), 10, 2);
        add_action('admin_post_murvc_program_settings', array(__CLASS__, 'handle_settings_post'));
    }

    /** Whether an order has a line of a session product (one made by Club Manager). */
    private static function order_has_sessions($order) {
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof WC_Order_Item_Product && (string) get_post_meta($item->get_product_id(), '_murvc_session_product', true) === '1') {
                return true;
            }
        }
        return false;
    }

    private static function queue($id) {
        $id = intval($id);
        if ($id <= 0) {
            return;
        }
        self::$imports[$id] = true;
        if (!self::$imports_hooked) {
            self::$imports_hooked = true;
            add_action('shutdown', array(__CLASS__, 'run_pending_imports'));
        }
    }

    /** D22: an order with session lines that is paid joins the analytics tables at shutdown, so the roll shows it at once. */
    public static function queue_import($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order(intval($order_id));
        if ($order instanceof WC_Order && self::order_has_sessions($order)) {
            self::queue($order->get_id());
        }
    }

    /** D22: its refund too (and the order again). */
    public static function queue_refund_import($order_id, $refund_id = 0) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order(intval($order_id));
        if ($order instanceof WC_Order && self::order_has_sessions($order)) {
            self::queue(intval($refund_id));
            self::queue($order->get_id());
        }
    }

    /**
     * Each queued id once, through the account merge's own call
     * (OrdersScheduler::import()), never letting a failure reach the
     * customer's request. Public so a test can run it in process.
     */
    public static function run_pending_imports() {
        $ids = array_keys(self::$imports);
        self::$imports = array();
        $sched = '\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler';
        if (empty($ids) || !class_exists($sched) || !method_exists($sched, 'import')) {
            return;
        }
        foreach ($ids as $id) {
            try {
                $sched::import($id);
            } catch (Throwable $e) {
                // The scheduler's own run catches it up later.
                continue;
            }
        }
    }

    /**
     * Design 6.4 step 4: the program's current product becomes $product_id.
     * Switch and Switch back only, under TeamOversight_Program_Setup's lock:
     * the option read fresh (never a cached copy), only that entry's
     * product_id set, then read back and confirmed (that entry changed,
     * every other unchanged). False on any doubt.
     */
    public static function set_current_product($key, $product_id) {
        $key = (string) $key;
        $all = TeamOversight_Program_Setup::read_option_fresh(self::PROGRAMS_OPTION);
        if ($all === null) {
            return false;
        }
        if (empty($all)) {
            $all = self::get_programs();
        }
        if (!isset($all[$key]) || !is_array($all[$key])) {
            return false;
        }
        $before = $all;
        $all[$key]['product_id'] = intval($product_id);
        // Compare against the row, not an older autoloaded copy.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete(self::PROGRAMS_OPTION, 'options');
        update_option(self::PROGRAMS_OPTION, $all);
        wp_cache_delete(self::PROGRAMS_OPTION, 'options');
        $after = TeamOversight_Program_Setup::read_option_fresh(self::PROGRAMS_OPTION);
        $ok = is_array($after) && isset($after[$key]['product_id']) && is_scalar($after[$key]['product_id']) && intval($after[$key]['product_id']) === intval($product_id);
        if ($ok) {
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $other) {
                if ((string) $other === $key) {
                    continue;
                }
                if ((isset($before[$other]) ? serialize($before[$other]) : null) !== (isset($after[$other]) ? serialize($after[$other]) : null)) {
                    $ok = false;
                    break;
                }
            }
        }
        self::flush_program_caches();
        if (class_exists('TeamOversight_Program_Sessions')) {
            TeamOversight_Program_Sessions::flush_cache();
        }
        return $ok;
    }

    /** Programs this user may view (admins: all). */
    public static function get_viewable_programs($user_id = null) {
        $user_id = $user_id ? intval($user_id) : get_current_user_id();
        $programs = self::get_programs();
        if (user_can($user_id, 'manage_options')) {
            return $programs;
        }
        $allowed = array();
        foreach ($programs as $key => $program) {
            if (in_array($user_id, self::get_program_supervisors($key), true)) {
                $allowed[$key] = $program;
            }
        }
        return $allowed;
    }

    // ------------------------------------------------------------------
    // A program's products and their kinds (1.84.0)
    // ------------------------------------------------------------------

    /**
     * How a product's order lines get their date: 'dated' (made by Club
     * Manager, _murvc_session_product), else 'month' (a month attribute),
     * else 'legacy_date' (the date label read with the weekday rule).
     */
    public static function detect_kind($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return 'legacy_date';
        }
        if ((string) get_post_meta($product_id, '_murvc_session_product', true) === '1') {
            return 'dated';
        }
        $attributes = get_post_meta($product_id, '_product_attributes', true);
        if (is_array($attributes) && (isset($attributes['month']) || isset($attributes['pa_month']))) {
            return 'month';
        }
        return 'legacy_date';
    }

    /**
     * Every product of a program: the current one (team_oversight_programs),
     * the prepared one and the earlier ones (the setup option, structurally
     * clean), each once (the first role wins). Each is array(product_id,
     * kind, weekday, years, role); years null means the current window, two
     * years back to one ahead. Request cached.
     */
    public static function program_products($key) {
        $key = (string) $key;
        if (isset(self::$products_cache[$key])) {
            return self::$products_cache[$key];
        }
        $list = array();
        $seen = array();
        $program = self::get_program($key);
        if (is_array($program)) {
            $weekday = self::get_program_weekday($program);
            $current = isset($program['product_id']) && is_scalar($program['product_id']) ? intval($program['product_id']) : 0;
            if ($current > 0) {
                $list[] = array('product_id' => $current, 'kind' => self::detect_kind($current), 'weekday' => $weekday, 'years' => null, 'role' => 'current');
                $seen[$current] = true;
            }
            if (class_exists('TeamOversight_Program_Setup')) {
                $setup = TeamOversight_Program_Setup::get($key);
                $prepared = intval($setup['prepared_product']);
                if ($prepared > 0 && !isset($seen[$prepared])) {
                    $list[] = array('product_id' => $prepared, 'kind' => self::detect_kind($prepared), 'weekday' => $weekday, 'years' => null, 'role' => 'prepared');
                    $seen[$prepared] = true;
                }
                foreach ($setup['past_products'] as $past) {
                    $pid = intval($past['product_id']);
                    if ($pid <= 0 || isset($seen[$pid])) {
                        continue;
                    }
                    $list[] = array('product_id' => $pid, 'kind' => $past['kind'], 'weekday' => $past['weekday'], 'years' => $past['years'], 'role' => 'past');
                    $seen[$pid] = true;
                }
            }
        }
        self::$products_cache[$key] = $list;
        return $list;
    }

    /** The kind of a program's current product ('' when there is no such program). */
    public static function current_kind($key) {
        foreach (self::program_products($key) as $product) {
            if ($product['role'] === 'current') {
                return $product['kind'];
            }
        }
        return '';
    }

    /**
     * Drop the request caches of program_products() and get_program_history().
     * TeamOversight_Program_Setup::save() and save_settings() call it after
     * they write; tests call it (with TeamOversight_Program_Setup::flush_cache())
     * after changing a stand-in option.
     */
    public static function flush_program_caches() {
        self::$products_cache = array();
        self::$history_cache = array();
    }

    // ------------------------------------------------------------------
    // Attendance ticks
    // ------------------------------------------------------------------

    /**
     * order_id => array(attended, by, at) for one session. Includes who
     * ticked it so supervisors on different courts can see each other's
     * marks rather than wondering.
     */
    public static function get_attendance($program_key, $session_label) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT a.order_id, a.attended, a.marked_at, u.display_name AS marked_by
            FROM {$wpdb->prefix}team_program_attendance a
            LEFT JOIN {$wpdb->users} u ON u.ID = a.marked_by
            WHERE a.program_key = %s AND a.session_label = %s
        ", $program_key, $session_label));

        $marked = array();
        foreach ($rows as $row) {
            $marked[intval($row->order_id)] = array(
                'attended' => (bool) intval($row->attended),
                'by' => $row->marked_by ? $row->marked_by : '',
                'at' => $row->marked_at ? date('g:ia', strtotime($row->marked_at)) : '',
            );
        }
        return $marked;
    }

    public static function set_attendance($program_key, $session_label, $order_id, $attended, $user_id = 0) {
        global $wpdb;

        $wpdb->query($wpdb->prepare("
            INSERT INTO {$wpdb->prefix}team_program_attendance
                (program_key, session_label, order_id, user_id, attended, marked_by, marked_at)
            VALUES (%s, %s, %d, %d, %d, %d, %s)
            ON DUPLICATE KEY UPDATE attended = VALUES(attended), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)
        ", $program_key, $session_label, intval($order_id), intval($user_id), $attended ? 1 : 0, get_current_user_id(), current_time('mysql')));
    }

    /** Shared by both AJAX endpoints: validate and return the request context. */
    private function ajax_context() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'murvc_attendance')) {
            wp_send_json_error('Security check failed');
        }
        $program_key = isset($_POST['program_key']) ? sanitize_key($_POST['program_key']) : '';
        $program = self::get_program($program_key);
        if (!$program || !self::user_can_view($program_key)) {
            wp_send_json_error('You do not have access to this program');
        }
        // 1.85.0 (B31): each field behind isset; a dated roll posts the line and the date, no label.
        $item = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
        $date = isset($_POST['session_date']) ? sanitize_text_field(wp_unslash($_POST['session_date'])) : '';
        return array(
            'key' => $program_key,
            'program' => $program,
            'session' => isset($_POST['session_label']) ? sanitize_text_field(wp_unslash($_POST['session_label'])) : '',
            'item' => $item,
            'date' => $date,
            'dated' => isset($_POST['order_item_id'], $_POST['session_date']) && self::current_kind($program_key) === 'dated',
        );
    }

    // ------------------------------------------------------------------
    // Per-line ticks for dated sessions (1.85.0, design 4.12, 3.9, D19)
    // ------------------------------------------------------------------

    /** order_item_id => array(attended, by, at) for one dated session (index session_lookup). */
    public static function get_line_attendance($program_key, $session_date) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT t.order_item_id, t.attended, t.marked_at, u.display_name AS marked_by
            FROM {$wpdb->prefix}team_program_line_ticks t
            LEFT JOIN {$wpdb->users} u ON u.ID = t.marked_by
            WHERE t.program_key = %s AND t.session_date = %s
        ", (string) $program_key, (string) $session_date));
        $marked = array();
        foreach ((array) $rows as $row) {
            $marked[intval($row->order_item_id)] = array(
                'attended' => (bool) intval($row->attended),
                'by' => $row->marked_by ? $row->marked_by : '',
                'at' => $row->marked_at ? date('g:ia', strtotime($row->marked_at)) : '',
            );
        }
        return $marked;
    }

    /**
     * Tick or untick one order line of a dated session (design 4.12): the
     * user may view the program; the line's product is one of the
     * program's; its variation's session date is the posted date; its order
     * is paid (processing or completed, an order, never a refund). The
     * account comes from the order, never from the post. Returns
     * array(ok, message).
     */
    public static function mark_line($user_id, $program_key, $order_item_id, $session_date, $attended) {
        global $wpdb;
        $user_id = intval($user_id);
        $program_key = sanitize_key((string) $program_key);
        $fail = function ($message) {
            return array('ok' => false, 'message' => $message);
        };
        if (!is_array(self::get_program($program_key)) || $user_id <= 0 || !self::user_can_view($program_key, $user_id)) {
            return $fail('You do not have access to this program');
        }
        $session_date = (string) $session_date;
        if (!self::is_ymd($session_date) || !checkdate(intval(substr($session_date, 5, 2)), intval(substr($session_date, 8, 2)), intval(substr($session_date, 0, 4)))) {
            return $fail('That isn\'t a session date.');
        }
        if (!class_exists('WC_Order_Factory')) {
            return $fail('The shop isn\'t available.');
        }
        $item = WC_Order_Factory::get_order_item(intval($order_item_id));
        if (!($item instanceof WC_Order_Item_Product)) {
            return $fail('That booking isn\'t one of this program\'s.');
        }
        $products = array();
        foreach (self::program_products($program_key) as $product) {
            $products[] = intval($product['product_id']);
        }
        if (!in_array(intval($item->get_product_id()), $products, true)) {
            return $fail('That booking isn\'t one of this program\'s.');
        }
        $variation_id = intval($item->get_variation_id());
        if ($variation_id <= 0 || (string) get_post_meta($variation_id, '_murvc_session_date', true) !== $session_date) {
            return $fail('That booking isn\'t for this session.');
        }
        $order = wc_get_order($item->get_order_id());
        if (!($order instanceof WC_Order) || !in_array($order->get_status(), array('processing', 'completed'), true)) {
            return $fail('That booking isn\'t paid.');
        }
        $customer = intval($order->get_customer_id());
        $args = array($program_key, intval($item->get_product_id()), $session_date, intval($item->get_id()), intval($order->get_id()));
        if ($customer > 0) {
            $args[] = $customer;
        }
        $args[] = $attended ? 1 : 0;
        $args[] = $user_id;
        $args[] = current_time('mysql');
        $wpdb->query($wpdb->prepare("
            INSERT INTO {$wpdb->prefix}team_program_line_ticks
                (program_key, product_id, session_date, order_item_id, order_id, user_id, attended, marked_by, marked_at)
            VALUES (%s, %d, %s, %d, %d, " . ($customer > 0 ? '%d' : 'NULL') . ", %d, %d, %s)
            ON DUPLICATE KEY UPDATE attended = VALUES(attended), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)
        ", $args));
        if ($wpdb->last_error !== '') {
            return $fail('The tick couldn\'t be saved. Try again.');
        }
        return array('ok' => true, 'message' => '');
    }

    /** The marks of a dated session as the dated roll's script reads them, keyed by order line, plus their hash. */
    private function marked_line_state($program_key, $session_date) {
        $state = array();
        foreach (self::get_line_attendance($program_key, $session_date) as $item_id => $entry) {
            $state[$item_id] = array(
                'attended' => (bool) $entry['attended'],
                'note' => ($entry['attended'] && $entry['by'])
                    ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                    : '',
            );
        }
        ksort($state);
        return array($state, md5((string) wp_json_encode($state)));
    }

    /**
     * The marks for a session as the roster JS reads them, plus a short
     * hash of them so a poll can ask "anything changed since?" cheaply.
     * One indexed query; the bookings query never runs here — the page
     * works out how many are here from each card's booking quantity.
     */
    private function marked_state($program_key, $session_label) {
        $state = array();
        foreach (self::get_attendance($program_key, $session_label) as $order_id => $entry) {
            $state[$order_id] = array(
                'attended' => (bool) $entry['attended'],
                'note' => ($entry['attended'] && $entry['by'])
                    ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                    : '',
            );
        }
        ksort($state);
        return array($state, md5((string) wp_json_encode($state)));
    }

    public function ajax_mark_attendance() {
        $ctx = $this->ajax_context();

        if ($ctx['dated']) {
            $done = self::mark_line(get_current_user_id(), $ctx['key'], $ctx['item'], $ctx['date'], !empty($_POST['attended']));
            if (!$done['ok']) {
                wp_send_json_error($done['message']);
            }
            $marked = self::get_line_attendance($ctx['key'], $ctx['date']);
            $entry = isset($marked[$ctx['item']]) ? $marked[$ctx['item']] : null;
            wp_send_json_success(array(
                'attended' => (bool) ($entry && $entry['attended']),
                'note' => ($entry && $entry['attended'] && $entry['by'])
                    ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                    : '',
            ));
        }

        self::set_attendance(
            $ctx['key'],
            $ctx['session'],
            intval($_POST['order_id']),
            !empty($_POST['attended']),
            intval($_POST['attendee_user_id'])
        );

        $marked = self::get_attendance($ctx['key'], $ctx['session']);
        $entry = isset($marked[intval($_POST['order_id'])]) ? $marked[intval($_POST['order_id'])] : null;

        // The "here" count is worked out on the page from the cards.
        wp_send_json_success(array(
            'attended' => (bool) ($entry && $entry['attended']),
            'note' => ($entry && $entry['attended'] && $entry['by'])
                ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                : '',
        ));
    }

    /**
     * The 20-second poll: only the marks, and nothing at all but the hash
     * when they haven't changed since the hash the page sent ('since').
     */
    public function ajax_attendance_state() {
        $ctx = $this->ajax_context();

        if ($ctx['dated']) {
            list($state, $hash) = $this->marked_line_state($ctx['key'], $ctx['date']);
        } else {
            list($state, $hash) = $this->marked_state($ctx['key'], $ctx['session']);
        }
        $since = isset($_POST['since']) ? sanitize_text_field(wp_unslash($_POST['since'])) : '';
        if ($since !== '' && hash_equals($hash, $since)) {
            wp_send_json_success(array('unchanged' => true, 'hash' => $hash));
        }

        wp_send_json_success(array(
            'marked' => (object) $state,
            'hash' => $hash,
        ));
    }

    /**
     * No-JS fallback: process an attendance tick before output, then
     * redirect back so a refresh can't resubmit. State is explicit.
     */
    public function maybe_handle_attendance() {
        if (!isset($_POST['murvc_attendance_action']) || $_POST['murvc_attendance_action'] !== 'mark') {
            return;
        }
        if (!is_user_logged_in() || !isset($_POST['murvc_attendance_nonce'])
            || !wp_verify_nonce($_POST['murvc_attendance_nonce'], 'murvc_attendance')) {
            return;
        }

        $program_key = isset($_POST['program_key']) ? sanitize_key($_POST['program_key']) : '';
        if ($program_key === '' || !self::get_program($program_key) || !self::user_can_view($program_key)) {
            return;
        }

        if (isset($_POST['order_item_id'], $_POST['session_date'])) {
            // 1.85.0: a dated roll's tick, checked against the line, the session and a paid order.
            self::mark_line(get_current_user_id(), $program_key, absint($_POST['order_item_id']), sanitize_text_field(wp_unslash($_POST['session_date'])), !empty($_POST['attended']));
        } elseif (isset($_POST['session_label'], $_POST['order_id'])) {
            self::set_attendance(
                $program_key,
                sanitize_text_field(wp_unslash($_POST['session_label'])),
                intval($_POST['order_id']),
                !empty($_POST['attended']),
                isset($_POST['attendee_user_id']) ? intval($_POST['attendee_user_id']) : 0
            );
        }

        wp_safe_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    // ------------------------------------------------------------------
    // Sessions and attendance (read straight from paid orders)
    // ------------------------------------------------------------------

    /**
     * Sessions for a program: every published variation's date attribute,
     * parsed to a real date so they sort and "today" can be found.
     * Returns array of array(label, date) sorted chronologically.
     * $years (1.84.0): the years the labels belong to (an earlier product's
     * list); null keeps the window of two years back to one ahead.
     */
    public static function get_sessions($product_id, $weekday = 0, $years = null) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT pm.meta_value AS label
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('attribute_date', 'attribute_pa_date')
            WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'publish'
                AND pm.meta_value <> ''
        ", intval($product_id)));

        $year = intval(wp_date('Y'));
        $sessions = array();
        foreach ($rows as $row) {
            $label = trim($row->label);
            if (isset($sessions[$label])) {
                continue;
            }
            $date = self::parse_session_date($label, $year, $weekday, $years);
            $sessions[$label] = array('label' => $label, 'date' => $date);
        }

        uasort($sessions, function ($a, $b) {
            return strcmp((string) $a['date'], (string) $b['date']);
        });
        return $sessions;
    }

    /**
     * "14-Jul" / "14 Jul" / "2026-07-14" -> Y-m-d. Empty when unparseable
     * (those sessions still list, just without date intelligence).
     *
     * Year-less labels are ambiguous, so when the program runs on a known
     * weekday we pick the candidate year where the date actually falls on
     * that day — the only reliable way to tell a "13-May" that belongs to
     * last season from this season's dates. Ties (impossible for a weekly
     * program, since weekdays shift each year) resolve to the year nearest
     * today, and anything that matches no candidate falls back to $year.
     *
     * 1.84.0: two shapes that carry their own year are read directly, before
     * that rule (and ignore $years): "Sun 24 Jan 2027" (the labels Club
     * Manager makes; the day name must match the date, else '') and
     * "October 2026" (a month: the first of it). $years, when given, is the
     * only list of years a year-less label is tried in (an earlier product's
     * own years), and with no weekday match it falls back to the listed year
     * nearest $year (a tie takes the later one). Without $years the rule is
     * exactly as before.
     */
    public static function parse_session_date($label, $year, $weekday = 0, $years = null) {
        $label = trim((string) $label);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)) {
            return $label;
        }

        $short_months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
        if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) (\d{1,2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{4})$/D', $label, $m)) {
            $month = intval(array_search($m[3], $short_months, true)) + 1;
            $day = intval($m[2]);
            $full_year = intval($m[4]);
            if (!checkdate($month, $day, $full_year)) {
                return '';
            }
            // Never strtotime() with a year appended: build the date and check its day name.
            if (gmdate('D', gmmktime(0, 0, 0, $month, $day, $full_year)) !== $m[1]) {
                return '';
            }
            return sprintf('%04d-%02d-%02d', $full_year, $month, $day);
        }
        $long_months = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
        if (preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December) (\d{4})$/D', $label, $m)) {
            return sprintf('%04d-%02d-01', intval($m[2]), intval(array_search($m[1], $long_months, true)) + 1);
        }

        $normalised = str_replace(array('-', '.', '/'), ' ', $label);

        $listed = null;
        if (is_array($years) && !empty($years)) {
            $listed = array();
            foreach ($years as $listed_year) {
                if (is_int($listed_year) || (is_string($listed_year) && preg_match('/^\d{4}$/D', $listed_year))) {
                    $listed[] = intval($listed_year);
                }
            }
            $listed = array_values(array_unique($listed));
            sort($listed);
            if (empty($listed)) {
                $listed = null;
            }
        }
        $candidates = $listed !== null ? $listed : array($year - 2, $year - 1, $year, $year + 1);

        if ($weekday >= 1 && $weekday <= 7) {
            $today = strtotime(current_time('Y-m-d'));
            $best = '';
            $best_distance = null;
            foreach ($candidates as $candidate) {
                $ts = strtotime($normalised . ' ' . $candidate);
                if (!$ts || intval(date('N', $ts)) !== $weekday) {
                    continue;
                }
                $distance = abs($ts - $today);
                if ($best_distance === null || $distance < $best_distance) {
                    $best = date('Y-m-d', $ts);
                    $best_distance = $distance;
                }
            }
            if ($best !== '') {
                return $best;
            }
        }

        $fallback = $year;
        if ($listed !== null) {
            $fallback = null;
            $fallback_distance = null;
            foreach ($listed as $candidate) {
                // Ascending, so on a tie the later year wins.
                $distance = abs($candidate - intval($year));
                if ($fallback_distance === null || $distance <= $fallback_distance) {
                    $fallback = $candidate;
                    $fallback_distance = $distance;
                }
            }
        }
        $ts = strtotime($normalised . ' ' . $fallback);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /** The session to show by default: today, else the next upcoming, else the last one. */
    public static function default_session($sessions) {
        $today = current_time('Y-m-d');
        $upcoming = '';
        $last = '';
        foreach ($sessions as $label => $session) {
            if ($session['date'] === $today) {
                return $label;
            }
            if ($session['date'] !== '' && $session['date'] > $today && $upcoming === '') {
                $upcoming = $label;
            }
            $last = $label;
        }
        return $upcoming !== '' ? $upcoming : $last;
    }

    // ------------------------------------------------------------------
    // Refunds (1.84.0, design 4.9.1): one rule for the roll, the figures,
    // and (from 1.85.0) To refund and the dated roll.
    // ------------------------------------------------------------------

    /** Whether refunds take lines off. Off only through REFUND_FILTER (the golden harness). */
    public static function refund_rule_on() {
        return (bool) apply_filters(self::REFUND_FILTER, true);
    }

    /**
     * Refunds against these order lines: item id => array(qty, amount), the
     * refunded quantity (the sum of -_qty on refund lines whose
     * _refunded_item_id is the line) and amount (the sum of -(_line_total +
     * _line_tax)). Only lines with a refund row are listed. One query per
     * 1,000 lines; array() when the rule is off.
     */
    public static function refunds_for_items(array $item_ids) {
        global $wpdb;
        if (!self::refund_rule_on()) {
            return array();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
        $refunds = array();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT ref.meta_value AS item_id,
                    SUM(-1 * CAST(COALESCE(q.meta_value, '0') AS DECIMAL(12,4))) AS refunded_qty,
                    SUM(-1 * (CAST(COALESCE(t.meta_value, '0') AS DECIMAL(16,6)) + CAST(COALESCE(x.meta_value, '0') AS DECIMAL(16,6)))) AS refunded_amount
                FROM {$wpdb->prefix}woocommerce_order_items ri
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta ref
                    ON ref.order_item_id = ri.order_item_id AND ref.meta_key = '_refunded_item_id'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta q
                    ON q.order_item_id = ri.order_item_id AND q.meta_key = '_qty'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta t
                    ON t.order_item_id = ri.order_item_id AND t.meta_key = '_line_total'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta x
                    ON x.order_item_id = ri.order_item_id AND x.meta_key = '_line_tax'
                WHERE ri.order_item_type = 'line_item' AND ref.meta_value IN ({$placeholders})
                GROUP BY ref.meta_value
            ", $chunk));
            foreach ((array) $rows as $row) {
                $refunds[intval($row->item_id)] = array('qty' => (float) $row->refunded_qty, 'amount' => (float) $row->refunded_amount);
            }
        }
        return $refunds;
    }

    /**
     * The same aggregate for every refund line of one product (refund lines
     * carry the product's _product_id), for the history reader: one query.
     * array() when the rule is off.
     */
    public static function refunds_for_product($product_id) {
        global $wpdb;
        if (!self::refund_rule_on()) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT ref.meta_value AS item_id,
                SUM(-1 * CAST(COALESCE(q.meta_value, '0') AS DECIMAL(12,4))) AS refunded_qty,
                SUM(-1 * (CAST(COALESCE(t.meta_value, '0') AS DECIMAL(16,6)) + CAST(COALESCE(x.meta_value, '0') AS DECIMAL(16,6)))) AS refunded_amount
            FROM {$wpdb->prefix}woocommerce_order_items ri
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta ref
                ON ref.order_item_id = ri.order_item_id AND ref.meta_key = '_refunded_item_id'
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta rp
                ON rp.order_item_id = ri.order_item_id AND rp.meta_key = '_product_id' AND rp.meta_value = %d
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta q
                ON q.order_item_id = ri.order_item_id AND q.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta t
                ON t.order_item_id = ri.order_item_id AND t.meta_key = '_line_total'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta x
                ON x.order_item_id = ri.order_item_id AND x.meta_key = '_line_tax'
            WHERE ri.order_item_type = 'line_item'
            GROUP BY ref.meta_value
        ", intval($product_id)));
        $refunds = array();
        foreach ((array) $rows as $row) {
            $refunds[intval($row->item_id)] = array('qty' => (float) $row->refunded_qty, 'amount' => (float) $row->refunded_amount);
        }
        return $refunds;
    }

    /**
     * THE rule (design 4.9.1): 'none', 'part' or 'full' for one order line.
     * $refund is null (no refund row) or array(qty, amount) from
     * refunds_for_items() / refunds_for_product(). A line is fully refunded
     * when its refunded quantity reaches its quantity, or its refunded
     * amount (above half a cent) reaches its own total incl. GST less half a
     * cent; any other refund is a part refund. A line with no refund row is
     * never refunded. Every reader of "is this line refunded" calls this.
     */
    public static function refund_state($qty, $line_total_incl_tax, $refund) {
        if (!is_array($refund)) {
            return 'none';
        }
        $refunded_qty = isset($refund['qty']) ? (float) $refund['qty'] : 0.0;
        $refunded_amount = isset($refund['amount']) ? (float) $refund['amount'] : 0.0;
        if ($refunded_qty > 0 && $refunded_qty >= (float) $qty) {
            return 'full';
        }
        if ($refunded_amount > 0.004 && $refunded_amount >= (float) $line_total_incl_tax - 0.005) {
            return 'full';
        }
        if ($refunded_qty > 0 || $refunded_amount > 0.004) {
            return 'part';
        }
        return 'none';
    }

    /** Places left on a line: 0 when fully refunded, else its quantity less the refunded quantity. */
    public static function places_after_refunds($qty, $state, $refund) {
        if ($state === 'full') {
            return 0;
        }
        $refunded_qty = (is_array($refund) && isset($refund['qty'])) ? (int) round((float) $refund['qty']) : 0;
        return max(0, intval($qty) - $refunded_qty);
    }

    /**
     * One order's refunds through WooCommerce's own refund objects (so it
     * never lags behind the analytics tables): lines => item id =>
     * array(qty, amount), refunded => the order's refunded total, unlinked
     * => what was refunded that is tied to no line (0 below half a cent).
     * Read only. Used by the tests now and by To refund from 1.85.0.
     */
    public static function refund_summary_for_order($order_id) {
        $summary = array('lines' => array(), 'refunded' => 0.0, 'unlinked' => 0.0);
        $order = function_exists('wc_get_order') ? wc_get_order(intval($order_id)) : false;
        if (!$order || !($order instanceof WC_Order)) {
            return $summary;
        }
        $tied = 0.0;
        foreach ($order->get_refunds() as $refund) {
            foreach ($refund->get_items() as $item) {
                $line_id = intval($item->get_meta('_refunded_item_id'));
                if ($line_id <= 0) {
                    continue;
                }
                $qty = -1 * (float) $item->get_quantity();
                $amount = -1 * ((float) $item->get_total() + (float) $item->get_total_tax());
                if (!isset($summary['lines'][$line_id])) {
                    $summary['lines'][$line_id] = array('qty' => 0.0, 'amount' => 0.0);
                }
                $summary['lines'][$line_id]['qty'] += $qty;
                $summary['lines'][$line_id]['amount'] += $amount;
                $tied += $amount;
            }
        }
        foreach ($summary['lines'] as $line_id => $line) {
            $summary['lines'][$line_id]['amount'] = round($line['amount'], 2);
        }
        $refunded = (float) $order->get_total_refunded();
        $unlinked = $refunded - $tied;
        $summary['refunded'] = round($refunded, 2);
        $summary['unlinked'] = $unlinked < 0.005 ? 0.0 : round($unlinked, 2);
        return $summary;
    }

    /**
     * Everyone booked into one session of a program, from paid orders.
     * Storage-agnostic (order item tables + analytics), so it keeps
     * working under HPOS. Quantity > 1 means they booked guests too.
     *
     * One card per order (the legacy rule). 1.84.0: read per line, with the
     * refund rule: a fully refunded line is left out (an order with no line
     * left has no card), qty is the most places left on any of the order's
     * kept lines, and a card with a part refund also carries
     * refunded_amount (the kept lines' refunded amounts) and part_refunded
     * (true). With the rule off every card is exactly 1.83.1's.
     */
    public static function get_attendees($product_id, $session_label) {
        global $wpdb;

        $lines = $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_item_id, oi.order_id,
                qty.meta_value AS qty,
                lt.meta_value AS line_total,
                ltx.meta_value AS line_tax,
                cl.user_id, cl.email, cl.first_name, cl.last_name,
                os.date_created AS ordered
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta sess
                ON sess.order_item_id = oi.order_item_id AND sess.meta_key IN ('date', 'pa_date') AND sess.meta_value = %s
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta lt
                ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta ltx
                ON ltx.order_item_id = oi.order_item_id AND ltx.meta_key = '_line_tax'
            JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = oi.order_id
            LEFT JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = os.customer_id
            WHERE os.status IN ('wc-processing', 'wc-completed') AND oi.order_item_type = 'line_item'
            ORDER BY cl.last_name, cl.first_name, oi.order_id, oi.order_item_id
        ", intval($product_id), $session_label));

        // The one extra query per roll: refunds against these lines.
        $refunds = self::refunds_for_items(wp_list_pluck((array) $lines, 'order_item_id'));

        // Group by order in first-seen (SQL) order, as one card per order.
        $orders = array();
        $seen_items = array();
        foreach ((array) $lines as $line) {
            $item_id = intval($line->order_item_id);
            if (isset($seen_items[$item_id])) {
                continue;
            }
            $seen_items[$item_id] = true;
            $order_id = intval($line->order_id);
            $qty = max(1, intval($line->qty));
            $refund = isset($refunds[$item_id]) ? $refunds[$item_id] : null;
            $state = self::refund_state($qty, (float) $line->line_total + (float) $line->line_tax, $refund);
            if (!isset($orders[$order_id])) {
                $orders[$order_id] = array('row' => $line, 'kept' => 0, 'qty' => 0, 'refunded_amount' => 0.0, 'part' => false);
            }
            if ($state === 'full') {
                continue;
            }
            $orders[$order_id]['kept']++;
            $orders[$order_id]['qty'] = max($orders[$order_id]['qty'], self::places_after_refunds($qty, $state, $refund));
            if ($state === 'part') {
                $orders[$order_id]['part'] = true;
                $orders[$order_id]['refunded_amount'] += (float) $refund['amount'];
            }
        }

        $rows = array();
        foreach ($orders as $order) {
            if ($order['kept'] > 0) {
                $rows[] = $order;
            }
        }

        // Load every attendee's account and profile meta in two queries,
        // not two per person.
        $user_ids = array();
        foreach ($rows as $order) {
            $user_ids[] = intval($order['row']->user_id);
        }
        $user_ids = array_values(array_unique(array_filter($user_ids)));
        if (!empty($user_ids) && function_exists('cache_users')) {
            cache_users($user_ids);
        }

        $attendees = array();
        foreach ($rows as $order) {
            $row = $order['row'];
            $user_id = intval($row->user_id);
            $account = $user_id ? get_userdata($user_id) : false;
            $email = $account ? $account->user_email : (string) $row->email;
            $name = trim(trim((string) $row->first_name) . ' ' . trim((string) $row->last_name));
            if ($name === '' && $account) {
                $name = $account->display_name;
            }

            $attendee = array(
                'order_id' => intval($row->order_id),
                'user_id' => $user_id,
                'name' => $name !== '' ? $name : ($email ?: 'Unknown'),
                'email' => $email,
                'mobile' => $user_id ? get_user_meta($user_id, 'mobile_number', true) : '',
                'qty' => $order['qty'],
                'ordered' => $row->ordered,
                'has_account' => (bool) $account,
            );
            if ($order['part']) {
                $attendee['refunded_amount'] = round($order['refunded_amount'], 2);
                $attendee['part_refunded'] = true;
            }
            $attendees[] = $attendee;
        }
        return $attendees;
    }

    // ------------------------------------------------------------------
    // History across a program's products (1.84.0, design 4.13)
    // ------------------------------------------------------------------

    /**
     * Every paid line of every product of a program (current, prepared,
     * earlier), one query per product plus one refund query per product,
     * request cached per program and rule state. Fully refunded lines are
     * left out while the refund rule is on. Each row: order_item_id,
     * order_id, product_id, kind, label (raw meta, '' when none), date
     * (Y-m-d, '' when undated), user_id (0 for none), email (lowercased,
     * '' for none), player_key ('' when none), qty (at least 1), places
     * (after refunds) and refund_state. "New here", "Nth session" and the
     * stats are all worked out from these rows.
     */
    public static function get_program_history($key) {
        $key = (string) $key;
        $cache_key = $key . '|' . (self::refund_rule_on() ? '1' : '0');
        if (!isset(self::$history_cache[$cache_key])) {
            self::$history_cache[$cache_key] = self::history_rows(self::program_products($key));
        }
        return self::$history_cache[$cache_key];
    }

    /** The row reader behind get_program_history() and the two kept wrappers. */
    private static function history_rows(array $products) {
        $rule = self::refund_rule_on();
        $year = intval(wp_date('Y'));
        $rows = array();
        foreach ($products as $product) {
            $product_id = intval($product['product_id']);
            if ($product_id <= 0) {
                continue;
            }
            $kind = in_array($product['kind'], array('dated', 'legacy_date', 'month', 'paid_date'), true) ? $product['kind'] : 'legacy_date';
            $weekday = isset($product['weekday']) ? $product['weekday'] : 0;
            $years = isset($product['years']) ? $product['years'] : null;
            $lines = self::history_lines($product_id, $kind);
            $refunds = $rule ? self::refunds_for_product($product_id) : array();
            $parsed = array();
            foreach ((array) $lines as $line) {
                $item_id = intval($line->order_item_id);
                $label = $line->label === null ? '' : (string) $line->label;
                $date = '';
                if ($kind === 'paid_date') {
                    $date = self::is_ymd($line->paid_on) ? (string) $line->paid_on : '';
                } else {
                    if ($kind === 'dated') {
                        // The line's own date, else its variation's, else the label.
                        foreach (array($line->line_date, $line->var_date) as $candidate) {
                            if (self::is_ymd($candidate)) {
                                $date = trim((string) $candidate);
                                break;
                            }
                        }
                    }
                    if ($date === '' && ($kind !== 'dated' || $label !== '')) {
                        if (!isset($parsed[$label])) {
                            $parsed[$label] = self::parse_session_date($label, $year, $weekday, $years);
                        }
                        $date = $parsed[$label];
                    }
                }
                $qty = max(1, intval($line->qty));
                $refund = isset($refunds[$item_id]) ? $refunds[$item_id] : null;
                $state = self::refund_state($qty, (float) $line->line_total + (float) $line->line_tax, $refund);
                if ($state === 'full') {
                    continue;
                }
                $rows[] = array(
                    'order_item_id' => $item_id,
                    'order_id' => intval($line->order_id),
                    'product_id' => $product_id,
                    'kind' => $kind,
                    'label' => $label,
                    'date' => $date,
                    'user_id' => intval($line->user_id),
                    'email' => $line->email !== null ? strtolower((string) $line->email) : '',
                    'player_key' => $line->player_key !== null ? trim((string) $line->player_key) : '',
                    'qty' => $qty,
                    'places' => self::places_after_refunds($qty, $state, $refund),
                    'refund_state' => $state,
                );
            }
        }
        return $rows;
    }

    private static function is_ymd($value) {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', trim($value)) === 1;
    }

    /**
     * One product's paid lines, by kind (design 3.2, 3.5). legacy_date keeps
     * the 1.83.1 query's shape (paid statuses, an inner join on the date
     * label, every label including '') so Mixed Development's history is
     * unchanged; refund lines never carry a date label, so they never match.
     * The other kinds read only orders (parent_id 0), since refund records
     * sit in wc_order_stats under paid statuses too.
     */
    private static function history_lines($product_id, $kind) {
        global $wpdb;
        $p = $wpdb->prefix;
        $label = "''";
        $joins = '';
        $extra = '';
        $where = '';
        if ($kind === 'legacy_date' || $kind === 'month') {
            $keys = $kind === 'month' ? "'month', 'pa_month'" : "'date', 'pa_date'";
            $label = 'MAX(sess.meta_value)';
            $joins = "JOIN {$p}woocommerce_order_itemmeta sess
                ON sess.order_item_id = oi.order_item_id AND sess.meta_key IN ({$keys})";
            if ($kind === 'month') {
                $where = 'AND os.parent_id = 0';
            }
        } elseif ($kind === 'dated') {
            $label = 'MAX(lbl.meta_value)';
            $joins = "LEFT JOIN {$p}woocommerce_order_itemmeta lbl
                ON lbl.order_item_id = oi.order_item_id AND lbl.meta_key IN ('date', 'pa_date')
            LEFT JOIN {$p}woocommerce_order_itemmeta lsd
                ON lsd.order_item_id = oi.order_item_id AND lsd.meta_key = '_murvc_session_date'
            LEFT JOIN {$p}woocommerce_order_itemmeta var
                ON var.order_item_id = oi.order_item_id AND var.meta_key = '_variation_id'
            LEFT JOIN {$wpdb->postmeta} vsd
                ON vsd.post_id = CAST(var.meta_value AS UNSIGNED) AND vsd.meta_key = '_murvc_session_date'";
            $extra = ', MAX(lsd.meta_value) AS line_date, MAX(vsd.meta_value) AS var_date';
            $where = "AND oi.order_item_type = 'line_item' AND os.parent_id = 0";
        } else {
            $extra = ", MAX(DATE(COALESCE(NULLIF(os.date_paid, '0000-00-00 00:00:00'), os.date_created))) AS paid_on";
            $where = "AND oi.order_item_type = 'line_item' AND os.parent_id = 0";
        }
        return $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_item_id,
                MAX(oi.order_id) AS order_id,
                {$label} AS label,
                MAX(qty.meta_value) AS qty,
                MAX(lt.meta_value) AS line_total,
                MAX(ltx.meta_value) AS line_tax,
                MAX(pk.meta_value) AS player_key,
                MAX(cl.user_id) AS user_id,
                LOWER(MAX(cl.email)) AS email
                {$extra}
            FROM {$p}woocommerce_order_items oi
            JOIN {$p}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            {$joins}
            LEFT JOIN {$p}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
            LEFT JOIN {$p}woocommerce_order_itemmeta lt
                ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
            LEFT JOIN {$p}woocommerce_order_itemmeta ltx
                ON ltx.order_item_id = oi.order_item_id AND ltx.meta_key = '_line_tax'
            LEFT JOIN {$p}woocommerce_order_itemmeta pk
                ON pk.order_item_id = oi.order_item_id AND pk.meta_key = '_murvc_player_key'
            JOIN {$p}wc_order_stats os ON os.order_id = oi.order_id
            LEFT JOIN {$p}wc_customer_lookup cl ON cl.customer_id = os.customer_id
            WHERE os.status IN ('wc-processing', 'wc-completed') {$where}
            GROUP BY oi.order_item_id
        ", intval($product_id)));
    }

    /**
     * How many sessions of this program each person had already booked
     * BEFORE the given date ('' = all dated ones), across every product of
     * the program, so someone who books three weeks ahead still reads as a
     * first-timer at their first one. Returns person => player key => count,
     * the person keyed by 'u<user id>' and by the lowercased email (a line
     * counts under both); '' is a line with no player. Read it with
     * count_prior().
     */
    public static function program_prior_counts($key, $before_date) {
        return self::prior_counts_from_rows(self::get_program_history($key), (string) $before_date);
    }

    private static function prior_counts_from_rows(array $rows, $before_date) {
        $seen = array();
        $counts = array();
        foreach ($rows as $row) {
            // Undated lines can't be ordered, so they don't count as prior.
            if ($row['date'] === '' || ($before_date !== '' && $row['date'] >= $before_date)) {
                continue;
            }
            // One count per session per person and player: the label for
            // date and month labels, the date for dated sessions, the line
            // for a fee dated by payment.
            if ($row['kind'] === 'dated') {
                $session = 'd' . $row['date'];
            } elseif ($row['kind'] === 'paid_date') {
                $session = 'i' . $row['order_item_id'];
            } else {
                $session = 'l' . $row['label'];
            }
            $distinct = $row['product_id'] . "\n" . $session . "\n" . $row['user_id'] . "\n" . $row['email'] . "\n" . $row['player_key'];
            if (isset($seen[$distinct])) {
                continue;
            }
            $seen[$distinct] = true;
            $people = array();
            if ($row['user_id'] > 0) {
                $people[] = 'u' . $row['user_id'];
            }
            if ($row['email'] !== '') {
                $people[] = $row['email'];
            }
            foreach ($people as $person) {
                if (!isset($counts[$person][$row['player_key']])) {
                    $counts[$person][$row['player_key']] = 0;
                }
                $counts[$person][$row['player_key']]++;
            }
        }
        return $counts;
    }

    /**
     * A person's earlier sessions from program_prior_counts(): by account
     * first, else by email. With no player, the person's total over every
     * player (the number before 1.84.0). With a player, that player's count
     * plus the lines with no player, so a child whose parent bought months
     * or the trial is not New here, while a second child is.
     */
    public static function count_prior($counts, $user_id, $email, $player_key = '') {
        $user_id = intval($user_id);
        $entry = null;
        if ($user_id && isset($counts['u' . $user_id])) {
            $entry = $counts['u' . $user_id];
        } else {
            $email = strtolower((string) $email);
            if ($email !== '' && isset($counts[$email])) {
                $entry = $counts[$email];
            }
        }
        if ($entry === null) {
            return 0;
        }
        if (!is_array($entry)) {
            return intval($entry);
        }
        $player_key = (string) $player_key;
        if ($player_key === '') {
            return intval(array_sum($entry));
        }
        return (isset($entry[$player_key]) ? intval($entry[$player_key]) : 0) + (isset($entry['']) ? intval($entry['']) : 0);
    }

    /**
     * Kept for any caller outside the plugin (1.84.0): one product's prior
     * counts, read as the program readers read them (kind legacy_date, the
     * given weekday, the current window), keyed 'u<user id>' or email with
     * the person's total, as before. The plugin calls program_prior_counts().
     */
    public static function get_prior_session_counts($product_id, $before_date, $weekday = 0) {
        $rows = self::history_rows(array(array('product_id' => intval($product_id), 'kind' => 'legacy_date', 'weekday' => $weekday, 'years' => null, 'role' => 'current')));
        $counts = array();
        foreach (self::prior_counts_from_rows($rows, (string) $before_date) as $person => $by_player) {
            $counts[$person] = intval(array_sum($by_player));
        }
        return $counts;
    }

    /**
     * Participation over a date range, from paid bookings.
     *
     * A **participant** is one person, however many sessions they booked.
     * A **newcomer** is someone whose first-ever session for this program
     * falls inside the range — so the split is against all of history, not
     * just the window, and last year's regulars never read as new.
     * **New to the club** is a different question answered separately:
     * their account was created inside the range. A long-standing member
     * trying the program is a newcomer but not new to the club, and the
     * two numbers are worth seeing side by side.
     * **Spots** counts guests too (a booking of 3 is one participant but
     * three spots), which is the number that matters for court capacity.
     *
     * $from/$to are Y-m-d and inclusive; either may be '' for unbounded.
     * Sessions whose date label can't be parsed are excluded and counted
     * separately rather than silently dropped.
     *
     * 1.84.0: read from every product of the program (get_program_history()),
     * with places after refunds (a fully refunded line is not there at all).
     * Month and one-off fee lines (kinds month and paid_date) count for
     * people (participants, newcomers, returning, new to the club) and for
     * first-ever, never for sessions or spots; they are counted as
     * no_session_date. A person is the account (or the email of a guest
     * order) plus the player when a line names one, so an account with two
     * players is two participants; a player's first-ever is the earlier of
     * their own lines and the account's lines with no player. New to the
     * club counts each account once.
     */
    public static function program_participation_stats($key, $from, $to) {
        $has_no_session_kinds = false;
        foreach (self::program_products($key) as $product) {
            if ($product['kind'] === 'month' || $product['kind'] === 'paid_date') {
                $has_no_session_kinds = true;
            }
        }
        return self::stats_from_rows(self::get_program_history($key), (string) $from, (string) $to, $has_no_session_kinds);
    }

    /**
     * Kept for any caller outside the plugin (1.84.0): one product's stats,
     * read as the program readers read them (kind legacy_date, the given
     * weekday, the current window). The plugin calls
     * program_participation_stats().
     */
    public static function get_participation_stats($product_id, $from, $to, $weekday = 0) {
        $rows = self::history_rows(array(array('product_id' => intval($product_id), 'kind' => 'legacy_date', 'weekday' => $weekday, 'years' => null, 'role' => 'current')));
        return self::stats_from_rows($rows, (string) $from, (string) $to, false);
    }

    /** The two passes behind both stats readers, over history rows. */
    private static function stats_from_rows(array $rows, $from, $to, $has_no_session_kinds) {
        global $wpdb;

        $first_ever = array();   // person => earliest session date, all time
        $in_range = array();     // person => sessions booked in range
        $sessions = array();     // date => label, people, spots, newcomers
        $undated = 0;
        $spots = 0;
        $no_session_date = 0;

        // Pass one: everyone's first-ever session, across all of history.
        $bookings = array();
        $person_users = array();
        $player_base = array();  // person with a player => the same account with no player
        foreach ($rows as $row) {
            $is_session = ($row['kind'] === 'legacy_date' || $row['kind'] === 'dated');
            // As before: a line whose session label is blank isn't a booking
            // of any session (a dated line may still carry its own date).
            if ($row['kind'] === 'legacy_date' && $row['label'] === '') {
                continue;
            }
            if ($row['kind'] === 'dated' && $row['label'] === '' && $row['date'] === '') {
                continue;
            }
            $date = $row['date'];
            if ($date === '') {
                $undated++;
                continue;
            }

            $base = self::person_key((object) array('user_id' => $row['user_id'], 'email' => $row['email'], 'order_id' => $row['order_id']));
            $person = $base;
            if ($row['player_key'] !== '') {
                $person = $base . '|' . $row['player_key'];
                $player_base[$person] = $base;
            }
            $person_users[$person] = intval($row['user_id']);
            if (!isset($first_ever[$person]) || $date < $first_ever[$person]) {
                $first_ever[$person] = $date;
            }
            $bookings[] = array('person' => $person, 'date' => $date, 'label' => $row['label'], 'qty' => intval($row['places']), 'session' => $is_session);
        }
        // A player's first-ever: the earlier of their own lines and the
        // account's lines with no player (a 2026 month or trial).
        foreach ($player_base as $person => $base) {
            if (isset($first_ever[$base]) && $first_ever[$base] < $first_ever[$person]) {
                $first_ever[$person] = $first_ever[$base];
            }
        }

        // Pass two: only what falls inside the window.
        foreach ($bookings as $booking) {
            if (($from !== '' && $booking['date'] < $from) || ($to !== '' && $booking['date'] > $to)) {
                continue;
            }

            $person = $booking['person'];
            $in_range[$person] = isset($in_range[$person]) ? $in_range[$person] + 1 : 1;
            if (!$booking['session']) {
                // A month or a one-off fee: a person, not a session.
                $no_session_date++;
                continue;
            }
            $spots += $booking['qty'];

            $date = $booking['date'];
            if (!isset($sessions[$date])) {
                $sessions[$date] = array('label' => $booking['label'], 'date' => $date, 'people' => array(), 'spots' => 0, 'newcomers' => 0);
            }
            $sessions[$date]['spots'] += $booking['qty'];
            if (!isset($sessions[$date]['people'][$person])) {
                $sessions[$date]['people'][$person] = true;
                if (isset($first_ever[$person]) && $first_ever[$person] === $date) {
                    $sessions[$date]['newcomers']++;
                }
            }
        }

        $newcomers = 0;
        $user_ids = array();
        $no_account = 0;
        foreach ($in_range as $person => $count) {
            if (isset($first_ever[$person]) && $first_ever[$person] >= $from && ($to === '' || $first_ever[$person] <= $to)) {
                $newcomers++;
            }
            if (!empty($person_users[$person])) {
                $user_ids[] = $person_users[$person];
            } else {
                $no_account++;
            }
        }
        // Each account once, however many players it booked for.
        $user_ids = array_values(array_unique($user_ids));

        // New to the club: account created inside the window. Bookings made
        // without an account can't answer this, so they're reported apart.
        $new_to_club = 0;
        if (!empty($user_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $registered = $wpdb->get_col($wpdb->prepare("
                SELECT DATE(user_registered) FROM {$wpdb->users} WHERE ID IN ({$placeholders})
            ", $user_ids));
            foreach ($registered as $date) {
                if ($date >= $from && ($to === '' || $date <= $to)) {
                    $new_to_club++;
                }
            }
        }

        ksort($sessions);
        foreach ($sessions as $date => $session) {
            $sessions[$date]['people'] = count($session['people']);
        }

        $participants = count($in_range);
        return array(
            'participants' => $participants,
            'newcomers' => $newcomers,
            'returning' => $participants - $newcomers,
            'new_to_club' => $new_to_club,
            'no_account' => $no_account,
            'sessions' => count($sessions),
            'spots' => $spots,
            'repeat_bookings' => array_sum($in_range),
            'by_session' => array_values($sessions),
            'undated' => $undated,
            // 1.84.0: month and one-off fee lines in the range, and whether the program sells any.
            'no_session_date' => $no_session_date,
            'has_no_session_kinds' => (bool) $has_no_session_kinds,
        );
    }

    /**
     * One person across orders: their account if they have one, else the
     * email they ordered with, else the order itself (a guest checkout with
     * no email recorded can't be linked to anything else).
     */
    private static function person_key($row) {
        if (intval($row->user_id)) {
            return 'u' . intval($row->user_id);
        }
        if ($row->email) {
            return $row->email;
        }
        return 'o' . intval($row->order_id);
    }

    /** A roll card's earlier sessions (no player on the legacy roll): count_prior() by account, else email. */
    private static function prior_count_for($counts, $attendee) {
        return self::count_prior($counts, $attendee['user_id'], $attendee['email']);
    }

    private static function ordinal($n) {
        $n = intval($n);
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n . 'th';
        }
        $suffixes = array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th');
        return $n . $suffixes[$n % 10];
    }

    // ------------------------------------------------------------------
    // Shared rendering (front end + admin use the same roster)
    // ------------------------------------------------------------------

    /**
     * Participation stats for a program over a date range, defaulting to
     * year to date. Sits above the roster on the admin page.
     */
    public static function render_stats($program, $program_key, $base_url) {
        $default_from = wp_date('Y') . '-01-01';
        $default_to = current_time('Y-m-d');

        // An absent parameter means "first visit, show year to date"; an
        // empty one means the range was deliberately opened up.
        $from = isset($_GET['from']) ? self::sanitise_date(wp_unslash($_GET['from'])) : $default_from;
        $to = isset($_GET['to']) ? self::sanitise_date(wp_unslash($_GET['to'])) : $default_to;
        if ($from !== '' && $to !== '' && $from > $to) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        // 1.84.0: every product of the program (its history across seasons).
        // The "Bookings without a session date" tile shows only for a program
        // selling months or a one-off fee; its PHP tags sit at column 0 so
        // any other program's page prints exactly what it did before.
        $stats = !empty(self::program_products($program_key))
            ? self::program_participation_stats($program_key, $from, $to)
            : self::get_participation_stats($program['product_id'], $from, $to, self::get_program_weekday($program));

        $ranges = array(
            'Year to date' => array($default_from, $default_to),
            'Last 12 months' => array(date('Y-m-d', strtotime('-1 year', strtotime($default_to))), $default_to),
            'All time' => array('', ''),
        );

        ob_start();
        ?>
        <style>
        .murvc-program-stats { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 14px 16px; margin: 15px 0 20px; }
        .murvc-stats-range { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
        .murvc-stats-range label { color: #555; }
        .murvc-stats-presets { margin-left: auto; font-size: 13px; }
        .murvc-stats-presets a { margin-left: 12px; }
        .murvc-stat-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
        .murvc-stat { background: #f6f7f7; border-radius: 4px; padding: 10px 12px; border-left: 4px solid #c3c4c7; }
        .murvc-stat.stat-new { border-left-color: #e07b00; background: #fff9f2; }
        .murvc-stat.stat-return { border-left-color: #2b7a2f; background: #f5fbf5; }
        .murvc-stat.stat-club { border-left-color: #2b4d80; background: #f4f7fc; }
        .murvc-stat-n { display: block; font-size: 26px; font-weight: 600; line-height: 1.1; }
        .murvc-stat-l { display: block; font-weight: 600; font-size: 13px; margin-top: 2px; }
        .murvc-stat-s { display: block; color: #777; font-size: 11px; margin-top: 1px; }
        .murvc-stats-note { margin: 12px 0 0; }
        .murvc-stats-breakdown { margin-top: 12px; }
        .murvc-stats-breakdown summary { cursor: pointer; font-weight: 600; }
        .murvc-stats-breakdown table { margin-top: 8px; max-width: 620px; }
        .murvc-muted-small { color: #999; font-size: 11px; margin-left: 6px; }
        </style>
        <div class="murvc-program-stats">
            <form method="get" class="murvc-stats-range">
                <?php foreach (array('page', 'program', 'session') as $keep): ?>
                    <?php if (isset($_GET[$keep])): ?>
                        <input type="hidden" name="<?php echo esc_attr($keep); ?>" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET[$keep]))); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <label>From <input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label>
                <label>To <input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label>
                <input type="submit" class="button" value="Update">
                <span class="murvc-stats-presets">
                    <?php foreach ($ranges as $label => $range): ?>
                        <a href="<?php echo esc_url(add_query_arg(array('from' => $range[0], 'to' => $range[1]), $base_url)); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </span>
            </form>

            <div class="murvc-stat-tiles">
                <div class="murvc-stat">
                    <span class="murvc-stat-n"><?php echo intval($stats['participants']); ?></span>
                    <span class="murvc-stat-l">Participants</span>
                    <span class="murvc-stat-s">distinct people</span>
                </div>
                <div class="murvc-stat stat-new">
                    <span class="murvc-stat-n"><?php echo intval($stats['newcomers']); ?></span>
                    <span class="murvc-stat-l">Newcomers</span>
                    <span class="murvc-stat-s">first time at this program</span>
                </div>
                <div class="murvc-stat stat-return">
                    <span class="murvc-stat-n"><?php echo intval($stats['returning']); ?></span>
                    <span class="murvc-stat-l">Returning</span>
                    <span class="murvc-stat-s">had been before this range</span>
                </div>
                <div class="murvc-stat stat-club">
                    <span class="murvc-stat-n"><?php echo intval($stats['new_to_club']); ?></span>
                    <span class="murvc-stat-l">New to the club</span>
                    <span class="murvc-stat-s">
                        account created in this range<?php if ($stats['no_account'] > 0): ?>; <?php echo intval($stats['no_account']); ?> booked without one<?php endif; ?>
                    </span>
                </div>
                <div class="murvc-stat">
                    <span class="murvc-stat-n"><?php echo intval($stats['sessions']); ?></span>
                    <span class="murvc-stat-l">Sessions</span>
                    <span class="murvc-stat-s">with at least one booking</span>
                </div>
                <div class="murvc-stat">
                    <span class="murvc-stat-n"><?php echo intval($stats['spots']); ?></span>
                    <span class="murvc-stat-l">Spots booked</span>
                    <span class="murvc-stat-s">
                        guests included<?php if ($stats['sessions'] > 0): ?>, <?php echo esc_html(number_format($stats['spots'] / $stats['sessions'], 1)); ?> per session<?php endif; ?>
                    </span>
                </div>
<?php if (!empty($stats['has_no_session_kinds'])): ?>
                <div class="murvc-stat">
                    <span class="murvc-stat-n"><?php echo intval($stats['no_session_date']); ?></span>
                    <span class="murvc-stat-l">Bookings without a session date</span>
                    <span class="murvc-stat-s">month passes and one-off fees, counted for people, not sessions</span>
                </div>
<?php endif; ?>
            </div>

            <p class="description murvc-stats-note">
                <?php if ($from === '' && $to === ''): ?>
                    All sessions on record.
                <?php else: ?>
                    <?php echo esc_html($from !== '' ? date('j M Y', strtotime($from)) : 'the beginning'); ?>
                    to <?php echo esc_html($to !== '' ? date('j M Y', strtotime($to)) : 'today'); ?>.
                <?php endif; ?>
                Counted from paid bookings, not attendance ticks, so it holds whether or not anyone marked people off.
                <?php if ($from === ''): ?>
                    With no start date every participant counts as a newcomer — there is no history before the range for anyone to return from.
                <?php endif; ?>
                <?php if ($stats['undated'] > 0): ?>
                    <strong><?php echo intval($stats['undated']); ?> booking<?php echo $stats['undated'] === 1 ? '' : 's'; ?></strong>
                    sit on session labels with no readable date and are excluded.
                <?php endif; ?>
            </p>

            <?php if (!empty($stats['by_session'])): ?>
                <details class="murvc-stats-breakdown">
                    <summary>Session by session</summary>
                    <table class="wp-list-table widefat striped">
                        <thead><tr><th>Session</th><th>Participants</th><th>Newcomers</th><th>Spots</th></tr></thead>
                        <tbody>
                            <?php foreach ($stats['by_session'] as $session): ?>
                                <tr>
                                    <td><?php echo esc_html(date('D j M Y', strtotime($session['date']))); ?>
                                        <span class="murvc-muted-small"><?php echo esc_html($session['label']); ?></span></td>
                                    <td><?php echo intval($session['people']); ?></td>
                                    <td><?php echo $session['newcomers'] > 0 ? intval($session['newcomers']) : '—'; ?></td>
                                    <td><?php echo intval($session['spots']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function sanitise_date($value) {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * Session roster: picker + attendee cards. $base_url decides where the
     * picker links (admin page or the front-end page hosting the shortcode).
     * 1.84.0: a card with a part refund gets a "Part refunded $X" chip (just
     * "Part refunded" when only places were refunded, for $0); its
     * PHP tags sit at column 0 so every other card prints exactly what it
     * did before (the golden harness compares the markup).
     */
    public static function render_roster($program, $selected_label, $base_url, $query_arg = 'session', $options = array()) {
        // 1.85.0: years (an earlier product's own years, for its labels) and
        // legacy (true = this product's legacy roll, never the dated one).
        $options = array_merge(array('show_history' => true, 'program_key' => '', 'years' => null, 'legacy' => false), $options);
        $program_key = $options['program_key'];
        $weekday = self::get_program_weekday($program);

        // 1.84.0: history reads every product of the program. With no key
        // given (no caller does that today), the program whose product this is.
        $history_key = (string) $program_key;
        if ($history_key === '') {
            foreach (self::get_programs() as $candidate_key => $candidate) {
                if (is_array($candidate) && isset($candidate['product_id']) && is_scalar($candidate['product_id'])
                    && intval($candidate['product_id']) === intval($program['product_id'])) {
                    $history_key = (string) $candidate_key;
                    break;
                }
            }
        }

        // 1.85.0: a program selling dated sessions has its own roll, one card per line.
        if (empty($options['legacy']) && $history_key !== '' && self::current_kind($history_key) === 'dated') {
            return self::render_dated_roster($program, $history_key, (string) $selected_label, $base_url, $options);
        }

        $sessions = self::get_sessions($program['product_id'], $weekday, $options['years']);

        if (empty($sessions)) {
            $kind = $history_key !== '' ? self::current_kind($history_key) : '';
            if ($kind === 'month' || $kind === 'paid_date') {
                // A month or a one-off fee is not a session (design 3.2).
                return '<p>' . esc_html($program['name']) . ' sells months or a one-off fee, not dated sessions, so it has no roll. The figures above count its bookings.</p>';
            }
            return '<p>No sessions found for ' . esc_html($program['name']) . '. Check the program\'s product has published date variations.</p>';
        }

        if ($selected_label === '' || !isset($sessions[$selected_label])) {
            $selected_label = self::default_session($sessions);
        }

        $attendees = self::get_attendees($program['product_id'], $selected_label);
        $people = count($attendees);
        $spots = 0;
        $guests = 0;
        foreach ($attendees as $attendee) {
            $spots += $attendee['qty'];
            $guests += $attendee['qty'] - 1;
        }
        $session_date = $sessions[$selected_label]['date'];
        $is_today = ($session_date !== '' && $session_date === current_time('Y-m-d'));

        // First-timers: nobody has "attended before" without prior sessions,
        // across every product of the program (1.84.0).
        $prior_counts = array();
        if ($options['show_history']) {
            $prior_counts = $history_key !== ''
                ? self::program_prior_counts($history_key, $session_date)
                : self::prior_counts_from_rows(self::history_rows(array(array('product_id' => intval($program['product_id']), 'kind' => 'legacy_date', 'weekday' => $weekday, 'years' => null, 'role' => 'current'))), (string) $session_date);
        }
        $first_timers = 0;
        if ($options['show_history']) {
            foreach ($attendees as $attendee) {
                if (self::prior_count_for($prior_counts, $attendee) === 0) {
                    $first_timers++;
                }
            }
        }

        // Ticked-off attendees drop to the bottom so the "still to arrive"
        // list stays short as a session fills up.
        $marked = $program_key !== '' ? self::get_attendance($program_key, $selected_label) : array();
        $checked_in = 0;
        foreach ($attendees as $index => $attendee) {
            $entry = isset($marked[$attendee['order_id']]) ? $marked[$attendee['order_id']] : null;
            $attendees[$index]['attended'] = ($entry && $entry['attended']);
            $attendees[$index]['marked_note'] = ($entry && $entry['attended'] && $entry['by'])
                ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                : '';
            if ($attendees[$index]['attended']) {
                $checked_in += $attendee['qty'];
            }
        }
        usort($attendees, function ($a, $b) {
            if ($a['attended'] !== $b['attended']) {
                return $a['attended'] ? 1 : -1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        ob_start();
        ?>
        <div class="murvc-sessions coach-portal">
            <div class="murvc-session-picker">
                <label for="murvc-session-select"><strong>Session:</strong></label>
                <select id="murvc-session-select" onchange="location.href=this.value;">
                    <?php foreach ($sessions as $label => $session): ?>
                        <option value="<?php echo esc_url(add_query_arg($query_arg, rawurlencode($label), $base_url)); ?>" <?php selected($selected_label, $label); ?>>
                            <?php
                            // Show the resolved date so year-less labels
                            // ("13-May") can't be mistaken for this season.
                            echo esc_html($session['date'] !== '' ? date('D j M Y', strtotime($session['date'])) : $label);
                            echo ($session['date'] !== '' && $session['date'] === current_time('Y-m-d')) ? ' — today' : '';
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="murvc-session-summary">
                    <strong><?php echo intval($spots); ?></strong> booked
                    <?php if ($guests > 0): ?>(<?php echo intval($people); ?> accounts + <?php echo intval($guests); ?> guest<?php echo $guests === 1 ? '' : 's'; ?>)<?php endif; ?>
                    <?php if ($first_timers > 0): ?> &middot; <strong><?php echo intval($first_timers); ?></strong> first-timer<?php echo $first_timers === 1 ? '' : 's'; ?><?php endif; ?>
                    <?php if ($program_key !== ''): ?> &middot; <strong class="murvc-checked-count"><?php echo intval($checked_in); ?></strong> here<?php endif; ?>
                    <?php if ($is_today): ?><span class="murvc-today-flag">TODAY</span><?php endif; ?>
                </span>
            </div>

            <?php if (!empty($attendees)): ?>
                <p class="murvc-session-search">
                    <label class="screen-reader-text" for="murvc-attendee-search">Search attendees</label>
                    <input type="search" id="murvc-attendee-search" placeholder="Search name or email…" autocomplete="off">
                    <span class="murvc-search-count"></span>
                </p>
            <?php endif; ?>

            <?php if (empty($attendees)): ?>
                <p>Nobody has booked this session yet.</p>
            <?php else: ?>
                <?php foreach ($attendees as $attendee): ?>
                    <?php
                    $prior = $options['show_history'] ? self::prior_count_for($prior_counts, $attendee) : null;
                    $is_first = ($prior === 0);
                    ?>
                    <div class="coach-applicant-card<?php echo $is_first ? ' murvc-first-timer' : ''; ?><?php echo !empty($attendee['attended']) ? ' murvc-attended' : ''; ?>"
                         data-qty="<?php echo intval($attendee['qty']); ?>"
                         data-search="<?php echo esc_attr(strtolower($attendee['name'] . ' ' . $attendee['email'])); ?>">
                        <div class="cac-header">
                            <span class="cac-name"><?php echo esc_html($attendee['name']); ?></span>
                            <span class="cac-chips">
                                <?php if ($is_first): ?>
                                    <span class="verdict-chip chip-firsttimer" title="First session of this program — say hello, check they know where to go, and confirm their emergency contact.">👋 New here</span>
                                <?php elseif ($prior !== null && $prior > 0): ?>
                                    <span class="verdict-chip chip-regular" title="Has booked <?php echo intval($prior); ?> earlier session(s) of this program."><?php echo esc_html(self::ordinal($prior + 1)); ?> session</span>
                                <?php endif; ?>
                                <?php if ($attendee['qty'] > 1): ?>
                                    <span class="verdict-chip chip-guests" title="Booked <?php echo intval($attendee['qty']); ?> spots — bringing <?php echo intval($attendee['qty'] - 1); ?> guest(s) whose names we don't have">
                                        &times;<?php echo intval($attendee['qty']); ?> (<?php echo intval($attendee['qty'] - 1); ?> guest<?php echo ($attendee['qty'] - 1) === 1 ? '' : 's'; ?>)
                                    </span>
                                <?php endif; ?>
<?php if (!empty($attendee['part_refunded'])): ?>
                                    <span class="verdict-chip chip-refund" title="Part of this booking was refunded in the shop">Part refunded<?php echo esc_html((float) $attendee['refunded_amount'] >= 0.005 ? ' $' . number_format((float) $attendee['refunded_amount'], 2) : ''); ?></span>
<?php endif; ?>
                                <?php if (!$attendee['has_account']): ?>
                                    <span class="verdict-chip chip-guestorder" title="Ordered without a club account — no profile or emergency contact on file">No account</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="cac-meta">
                            <?php if ($attendee['email']): ?>
                                <a href="mailto:<?php echo esc_attr($attendee['email']); ?>"><?php echo esc_html($attendee['email']); ?></a>
                            <?php endif; ?>
                            <?php if ($attendee['mobile']): ?>
                                &middot; <a href="tel:<?php echo esc_attr(TeamOversight_Coach_Portal::phone_tel_href($attendee['mobile'])); ?>"><?php echo esc_html(TeamOversight_Coach_Portal::format_phone($attendee['mobile'])); ?></a>
                            <?php endif; ?>
                            &middot; <small>order #<?php echo intval($attendee['order_id']); ?></small>
                        </div>
                        <div class="cac-footer">
                            <span class="cac-expanders">
                                <?php // By account only: a guest order's email proves nothing about who placed it. ?>
                                <?php echo TeamOversight_Coach_Portal::render_emergency_details($attendee['email'], $attendee['user_id'], false); ?>
                            </span>
                            <?php if ($program_key !== ''): ?>
                                <span class="cac-actions">
                                    <?php if (!empty($attendee['marked_note'])): ?>
                                        <small class="murvc-marked-note"><?php echo esc_html($attendee['marked_note']); ?></small>
                                    <?php endif; ?>
                                    <form method="post" class="murvc-attendance-form"
                                          data-order="<?php echo intval($attendee['order_id']); ?>"
                                          data-user="<?php echo intval($attendee['user_id']); ?>">
                                        <input type="hidden" name="murvc_attendance_action" value="mark">
                                        <input type="hidden" name="program_key" value="<?php echo esc_attr($program_key); ?>">
                                        <input type="hidden" name="session_label" value="<?php echo esc_attr($selected_label); ?>">
                                        <input type="hidden" name="order_id" value="<?php echo intval($attendee['order_id']); ?>">
                                        <input type="hidden" name="attendee_user_id" value="<?php echo intval($attendee['user_id']); ?>">
                                        <input type="hidden" name="attended" value="<?php echo !empty($attendee['attended']) ? '0' : '1'; ?>">
                                        <?php wp_nonce_field('murvc_attendance', 'murvc_attendance_nonce'); ?>
                                        <?php if (!empty($attendee['attended'])): ?>
                                            <button type="submit" class="button murvc-untick">✔ Here — undo</button>
                                        <?php else: ?>
                                            <button type="submit" class="button button-primary murvc-tick">Mark here</button>
                                        <?php endif; ?>
                                    </form>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var root = document.querySelector('.murvc-sessions');
            if (!root) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('murvc_attendance')); ?>;
            var programKey = <?php echo wp_json_encode($program_key); ?>;
            var sessionLabel = <?php echo wp_json_encode($selected_label); ?>;

            // --- Search ---------------------------------------------------
            var box = document.getElementById('murvc-attendee-search');
            var counter = document.querySelector('.murvc-search-count');
            if (box) {
                box.addEventListener('input', function () {
                    var term = box.value.toLowerCase().trim();
                    var shown = 0;
                    root.querySelectorAll('.coach-applicant-card').forEach(function (card) {
                        var match = term === '' || (card.dataset.search || '').indexOf(term) !== -1;
                        card.style.display = match ? '' : 'none';
                        if (match) { shown++; }
                    });
                    counter.textContent = term === '' ? '' : shown + ' match' + (shown === 1 ? '' : 'es');
                });
            }

            if (!programKey) { return; }

            // --- Marking (no page reload) --------------------------------
            function applyState(card, attended, note) {
                var form = card.querySelector('.murvc-attendance-form');
                var button = form.querySelector('button');
                var noteEl = card.querySelector('.murvc-marked-note');
                card.classList.toggle('murvc-attended', attended);
                form.querySelector('input[name="attended"]').value = attended ? '0' : '1';
                button.textContent = attended ? '✔ Here — undo' : 'Mark here';
                button.className = attended ? 'button murvc-untick' : 'button button-primary murvc-tick';
                if (note) {
                    if (!noteEl) {
                        noteEl = document.createElement('small');
                        noteEl.className = 'murvc-marked-note';
                        form.parentNode.insertBefore(noteEl, form);
                    }
                    noteEl.textContent = note;
                } else if (noteEl) {
                    noteEl.remove();
                }
                // Ticked people drop to the bottom of their list.
                var list = card.parentNode;
                if (attended) { list.appendChild(card); }
            }

            // "Here" = booked spots on the cards marked present, counted on
            // the page so the server never re-runs the bookings query.
            function refreshCount() {
                var el = document.querySelector('.murvc-checked-count');
                if (!el) { return; }
                var count = 0;
                root.querySelectorAll('.coach-applicant-card.murvc-attended').forEach(function (card) {
                    count += parseInt(card.dataset.qty, 10) || 1;
                });
                el.textContent = count;
            }

            root.addEventListener('submit', function (event) {
                var form = event.target.closest('.murvc-attendance-form');
                if (!form) { return; }
                event.preventDefault();

                var button = form.querySelector('button');
                var card = form.closest('.coach-applicant-card');
                var wanted = form.querySelector('input[name="attended"]').value === '1';
                button.disabled = true;
                var original = button.textContent;
                button.textContent = 'Saving…';

                var body = new URLSearchParams({
                    action: 'murvc_mark_attendance',
                    nonce: nonce,
                    program_key: programKey,
                    session_label: sessionLabel,
                    order_id: form.dataset.order,
                    attendee_user_id: form.dataset.user,
                    attended: wanted ? '1' : '0'
                });

                fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body})
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        button.disabled = false;
                        if (!res || !res.success) {
                            button.textContent = original;
                            alert(res && res.data ? res.data : 'Could not save — please try again.');
                            return;
                        }
                        applyState(card, res.data.attended, res.data.note);
                        refreshCount();
                    })
                    .catch(function () {
                        button.disabled = false;
                        button.textContent = original;
                        alert('Network problem — please try again.');
                    });
            });

            // --- Stay in sync with other supervisors ----------------------
            // Several people can be marking at once (multiple courts), so
            // poll for everyone else's ticks; our own writes are atomic
            // upserts server-side, so nothing is lost either way. The poll
            // sends the hash of the marks it last saw and gets nothing back
            // but the hash when nobody has ticked since; it skips while the
            // tab is hidden and catches up as soon as it's shown again.
            var lastHash = '';
            function poll() {
                if (document.hidden) { return; }
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new URLSearchParams({
                        action: 'murvc_attendance_state',
                        nonce: nonce,
                        program_key: programKey,
                        session_label: sessionLabel,
                        since: lastHash
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { return; }
                        lastHash = res.data.hash || '';
                        if (res.data.unchanged || !res.data.marked) { return; }
                        root.querySelectorAll('.coach-applicant-card').forEach(function (card) {
                            var form = card.querySelector('.murvc-attendance-form');
                            if (!form) { return; }
                            var state = res.data.marked[form.dataset.order];
                            var attended = !!(state && state.attended);
                            if (attended !== card.classList.contains('murvc-attended')) {
                                applyState(card, attended, state ? state.note : '');
                            }
                        });
                        refreshCount();
                    })
                    .catch(function () {});
            }
            setInterval(poll, 20000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) { poll(); }
            });
        })();
        </script>
        <?php echo self::render_styles(); ?>
        <?php
        return ob_get_clean();
    }

    private static function render_styles() {
        return '<style>
        .murvc-sessions { max-width: 760px; }
        .murvc-sessions h2, .murvc-sessions h3, .murvc-sessions summary,
        .murvc-sessions select, .murvc-sessions p, .murvc-sessions a { font-family: inherit; }
        .murvc-session-picker { margin: 0 0 15px 0; padding: 10px 14px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 6px; }
        .murvc-session-picker select { max-width: 220px; margin: 0 10px; }
        .murvc-session-summary { font-size: 13px; color: #50575e; }
        .murvc-today-flag { background: #1a7a2e; color: #fff; border-radius: 3px; padding: 1px 6px; font-size: 11px; font-weight: 700; margin-left: 6px; }
        .murvc-sessions .coach-applicant-card { border: 1px solid #dcdcde; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; background: #fff; }
        .murvc-sessions .cac-header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .murvc-sessions .cac-name { font-weight: 600; font-size: 15px; }
        .murvc-sessions .cac-chips { margin-left: auto; }
        .murvc-sessions .cac-meta { font-size: 13px; color: #555; margin: 4px 0; }
        .murvc-sessions .verdict-chip { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .murvc-sessions .chip-guests { background: #eee6f7; color: #4b2e83; border: 1px solid #b9a3d8; }
        .murvc-sessions .chip-guestorder { background: #fdeee0; color: #8a4b00; border: 1px solid #e0a86b; }
        .murvc-sessions .chip-firsttimer { background: #e7f1fa; color: #1d4f7c; border: 1px solid #9cc3e5; }
        .murvc-sessions .chip-regular { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
        .murvc-sessions .chip-refund { background: #fdf2f2; color: #8a1f11; border: 1px solid #e3a6a0; }
        .murvc-sessions .murvc-first-timer { border-left: 4px solid #2271b1; }
        .murvc-sessions .murvc-attended { opacity: 0.62; background: #f6f7f7; }
        .murvc-sessions .murvc-attended .cac-name { text-decoration: line-through; }
        .murvc-session-search { margin: 0 0 12px 0; }
        .murvc-session-search input { width: 100%; max-width: 320px; padding: 7px 10px; font-size: 15px; }
        .murvc-search-count { font-size: 12px; color: #646970; margin-left: 8px; }
        .murvc-sessions .cac-footer { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .murvc-sessions .cac-actions { margin-left: auto; }
        .murvc-sessions .murvc-tick, .murvc-sessions .murvc-untick { padding: 6px 14px; font-size: 14px; }
        .murvc-marked-note { color: #646970; font-size: 11px; margin-right: 8px; }
        @media (max-width: 640px) {
            .murvc-sessions .cac-actions { margin-left: 0; width: 100%; }
            .murvc-sessions .murvc-tick, .murvc-sessions .murvc-untick { width: 100%; padding: 10px; }
        }
        .murvc-sessions .coach-app-details summary { display: inline-block; cursor: pointer; font-size: 13px; font-weight: 600; padding: 4px 10px; border: 1px solid #1d3d6e; border-radius: 4px; color: #1d3d6e; background: #fff; }
        .murvc-sessions .coach-app-details summary::-webkit-details-marker { display: none; }
        .murvc-sessions .coach-app-details summary::after { content: " ▾"; font-size: 11px; }
        .murvc-sessions .coach-app-details[open] summary::after { content: " ▴"; }
        .murvc-sessions .coach-emergency { font-size: 13px; margin: 8px 0 2px 0; padding: 6px 8px; background: #fff7f0; border-left: 3px solid #e07b00; border-radius: 4px; }
        @media (max-width: 640px) {
            .murvc-sessions .cac-chips { margin-left: 0; width: 100%; }
        }
        </style>';
    }

    // ------------------------------------------------------------------
    // The dated roll (1.85.0, design 4.12, 3.5, B12, B13)
    // ------------------------------------------------------------------

    /**
     * A dated product's session dates (any status but the Bin, sessions
     * only): date => array(label, slots (start => array(ids, label, closed,
     * cancelled)), closed (every session of the date closed), cancelled (any
     * cancelled)), in date order, slots by start.
     */
    public static function dated_dates($product_id) {
        $dates = array();
        if (!class_exists('TeamOversight_Program_Sessions')) {
            return $dates;
        }
        foreach (TeamOversight_Program_Sessions::children($product_id) as $row) {
            if ($row['date'] === '' || $row['status'] === 'trash') {
                continue;
            }
            $date = $row['date'];
            if (!isset($dates[$date])) {
                $dates[$date] = array('label' => TeamOversight_Program_Sessions::date_label($date), 'slots' => array(), 'closed' => true, 'cancelled' => false);
            }
            $slot = $row['slot'];
            if (!isset($dates[$date]['slots'][$slot])) {
                $dates[$date]['slots'][$slot] = array(
                    'ids' => array(),
                    'label' => $row['time_label'] !== '' ? $row['time_label'] : TeamOversight_Program_Sessions::time_label($slot),
                    'closed' => false,
                    'cancelled' => false,
                );
            }
            $dates[$date]['slots'][$slot]['ids'][] = $row['id'];
            if ($row['state'] === 'cancelled') {
                $dates[$date]['cancelled'] = true;
                $dates[$date]['slots'][$slot]['cancelled'] = true;
            }
            if ($row['state'] === 'closed') {
                $dates[$date]['slots'][$slot]['closed'] = true;
            } else {
                $dates[$date]['closed'] = false;
            }
        }
        ksort($dates);
        foreach ($dates as $date => $day) {
            ksort($dates[$date]['slots']);
        }
        return $dates;
    }

    /**
     * Design 3.5: one card per paid order line of these variations (any
     * status of the variation), matched by _variation_id, never by label.
     * Fully refunded lines are left out unless $keep_refunded (a cancelled
     * session's roll keeps them, marked Refunded: B12). Each card:
     * order_item_id, order_id, variation_id, user_id, name (the account's),
     * player, player_key, email, mobile, qty (places after refunds),
     * refund_state, refunded_amount, ordered, has_account.
     */
    public static function get_line_attendees($product_id, array $variation_ids, $keep_refunded = false) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $variation_ids))));
        if (empty($ids)) {
            return array();
        }
        $p = $wpdb->prefix;
        $args = array(intval($product_id));
        foreach ($ids as $id) {
            $args[] = (string) $id;
        }
        $in = implode(',', array_fill(0, count($ids), '%s'));
        $lines = $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_item_id, oi.order_id,
                MAX(var.meta_value) AS variation_id,
                MAX(qty.meta_value) AS qty,
                MAX(lt.meta_value) AS line_total,
                MAX(ltx.meta_value) AS line_tax,
                MAX(pl.meta_value) AS player,
                MAX(pk.meta_value) AS player_key,
                MAX(cl.user_id) AS user_id,
                MAX(cl.email) AS email,
                MAX(cl.first_name) AS first_name,
                MAX(cl.last_name) AS last_name,
                MAX(os.date_created) AS ordered
            FROM {$p}woocommerce_order_items oi
            JOIN {$p}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            JOIN {$p}woocommerce_order_itemmeta var
                ON var.order_item_id = oi.order_item_id AND var.meta_key = '_variation_id' AND var.meta_value IN ({$in})
            LEFT JOIN {$p}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
            LEFT JOIN {$p}woocommerce_order_itemmeta lt
                ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
            LEFT JOIN {$p}woocommerce_order_itemmeta ltx
                ON ltx.order_item_id = oi.order_item_id AND ltx.meta_key = '_line_tax'
            LEFT JOIN {$p}woocommerce_order_itemmeta pl
                ON pl.order_item_id = oi.order_item_id AND pl.meta_key = 'Player'
            LEFT JOIN {$p}woocommerce_order_itemmeta pk
                ON pk.order_item_id = oi.order_item_id AND pk.meta_key = '_murvc_player_key'
            JOIN {$p}wc_order_stats os ON os.order_id = oi.order_id
            LEFT JOIN {$p}wc_customer_lookup cl ON cl.customer_id = os.customer_id
            WHERE os.status IN ('wc-processing', 'wc-completed') AND os.parent_id = 0 AND oi.order_item_type = 'line_item'
            GROUP BY oi.order_item_id, oi.order_id
            ORDER BY MAX(cl.last_name), MAX(cl.first_name), oi.order_id, oi.order_item_id
        ", $args));
        $refunds = self::refunds_for_items(wp_list_pluck((array) $lines, 'order_item_id'));

        $user_ids = array();
        foreach ((array) $lines as $line) {
            $user_ids[] = intval($line->user_id);
        }
        $user_ids = array_values(array_unique(array_filter($user_ids)));
        if (!empty($user_ids) && function_exists('cache_users')) {
            cache_users($user_ids);
        }

        $cards = array();
        foreach ((array) $lines as $line) {
            $item_id = intval($line->order_item_id);
            $qty = max(1, intval($line->qty));
            $refund = isset($refunds[$item_id]) ? $refunds[$item_id] : null;
            $state = self::refund_state($qty, (float) $line->line_total + (float) $line->line_tax, $refund);
            if ($state === 'full' && !$keep_refunded) {
                continue;
            }
            $user_id = intval($line->user_id);
            $account = $user_id ? get_userdata($user_id) : false;
            $email = $account ? $account->user_email : (string) $line->email;
            $name = trim(trim((string) $line->first_name) . ' ' . trim((string) $line->last_name));
            if ($name === '' && $account) {
                $name = $account->display_name;
            }
            $cards[] = array(
                'order_item_id' => $item_id,
                'order_id' => intval($line->order_id),
                'variation_id' => intval($line->variation_id),
                'user_id' => $user_id,
                'name' => $name !== '' ? $name : ($email ?: 'Unknown'),
                'player' => $line->player !== null ? trim((string) $line->player) : '',
                'player_key' => $line->player_key !== null ? trim((string) $line->player_key) : '',
                'email' => $email,
                'mobile' => $user_id ? get_user_meta($user_id, 'mobile_number', true) : '',
                'qty' => self::places_after_refunds($qty, $state, $refund),
                'refund_state' => $state,
                'refunded_amount' => is_array($refund) ? round(max(0.0, (float) $refund['amount']), 2) : 0.0,
                'ordered' => $line->ordered,
                'has_account' => (bool) $account,
            );
        }
        return $cards;
    }

    /** A name for comparing: entities decoded, trimmed, spaces squeezed, lowercased. */
    private static function name_key($name) {
        $name = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /**
     * The roll of a program selling dated sessions (design 4.12): a picker
     * of session dates (one Tuesday is one entry), a slot filter when the
     * date has more than one slot, one card per order line (both slots of a
     * night separate) with the name on the booking (1.86.1: the account
     * holder's, as the line recorded it at checkout; "Booked by" the
     * account only when a 1.86.0 line names someone else), a cancelled
     * session's Refund due / Refunded chips (B12), per-line ticks, and
     * Earlier seasons: the program's date-label products, each with today's
     * legacy roll (B13).
     */
    private static function render_dated_roster($program, $key, $selected_date, $base_url, $options) {
        $pid = intval($program['product_id']);
        $name = isset($program['name']) ? (string) $program['name'] : $key;

        // Earlier seasons: the legacy roll of one of the program's date-label products.
        $earlier = array();
        foreach (self::program_products($key) as $product) {
            if ($product['role'] === 'past' && $product['kind'] === 'legacy_date') {
                $earlier[intval($product['product_id'])] = $product;
            }
        }
        $past_id = isset($_GET['past']) && is_scalar($_GET['past']) ? absint($_GET['past']) : 0;
        if ($past_id > 0 && isset($earlier[$past_id])) {
            $product = $earlier[$past_id];
            $title = class_exists('TeamOversight_Program_Setup') ? TeamOversight_Program_Setup::product_title($past_id) : '#' . $past_id;
            $selected = isset($_GET['session']) && is_scalar($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
            $back = remove_query_arg(array('past', 'session', 'slot'), $base_url);
            return '<p class="murvc-earlier-head"><a href="' . esc_url($back) . '">&larr; ' . esc_html($name) . '</a> &middot; Earlier season: <strong>' . esc_html($title) . '</strong></p>'
                . self::render_roster(
                    array('name' => $title, 'product_id' => $past_id, 'weekday' => $product['weekday']),
                    $selected,
                    add_query_arg('past', $past_id, $back),
                    'session',
                    array('program_key' => $key, 'years' => $product['years'], 'legacy' => true, 'show_history' => $options['show_history'])
                );
        }
        $earlier_html = '';
        if (!empty($earlier)) {
            $links = array();
            foreach ($earlier as $id => $product) {
                $title = class_exists('TeamOversight_Program_Setup') ? TeamOversight_Program_Setup::product_title($id) : '#' . $id;
                $links[] = '<a href="' . esc_url(add_query_arg('past', $id, remove_query_arg(array('session', 'slot'), $base_url))) . '">' . esc_html($title . ' (' . implode(', ', (array) $product['years']) . ')') . '</a>';
            }
            $earlier_html = '<p class="murvc-earlier">Earlier seasons: ' . implode(' &middot; ', $links) . '</p>';
        }

        $dates = self::dated_dates($pid);
        if (empty($dates)) {
            return '<div class="murvc-sessions coach-portal"><p>No sessions yet for ' . esc_html($name) . '. They are added on the Sessions tab.</p>' . $earlier_html . '</div>';
        }
        $today = current_time('Y-m-d');
        if ($selected_date === '' || !isset($dates[$selected_date])) {
            $selected_date = '';
            $last = '';
            foreach (array_keys($dates) as $date) {
                if ($date === $today) {
                    $selected_date = $date;
                    break;
                }
                if ($date > $today && $selected_date === '') {
                    $selected_date = $date;
                }
                $last = $date;
            }
            if ($selected_date === '') {
                $selected_date = $last;
            }
        }
        $day = $dates[$selected_date];
        $many_slots = count($day['slots']) > 1;
        $slot_filter = isset($_GET['slot']) && is_scalar($_GET['slot']) ? sanitize_text_field(wp_unslash($_GET['slot'])) : '';
        if (!$many_slots || !isset($day['slots'][$slot_filter])) {
            $slot_filter = '';
        }

        $slot_of = array();
        $all_ids = array();
        foreach ($day['slots'] as $start => $slot) {
            foreach ($slot['ids'] as $id) {
                $slot_of[$id] = $start;
                $all_ids[] = $id;
            }
        }
        $cards = array();
        $slot_places = array();
        foreach (self::get_line_attendees($pid, $all_ids, true) as $card) {
            $start = isset($slot_of[$card['variation_id']]) ? $slot_of[$card['variation_id']] : '';
            $cancelled = $start !== '' && !empty($day['slots'][$start]['cancelled']);
            if ($card['refund_state'] === 'full' && !$cancelled) {
                continue;
            }
            $card['slot'] = $start;
            $card['cancelled'] = $cancelled;
            if ($card['refund_state'] !== 'full') {
                $slot_places[$start] = (isset($slot_places[$start]) ? $slot_places[$start] : 0) + $card['qty'];
            }
            if ($slot_filter === '' || $start === $slot_filter) {
                $cards[] = $card;
            }
        }

        $counts = $options['show_history'] ? self::program_prior_counts($key, $selected_date) : array();
        $marked = self::get_line_attendance($key, $selected_date);
        $spots = 0;
        $guests = 0;
        $people = 0;
        $first_timers = 0;
        $checked_in = 0;
        foreach ($cards as $index => $card) {
            $entry = isset($marked[$card['order_item_id']]) ? $marked[$card['order_item_id']] : null;
            $cards[$index]['attended'] = ($entry && $entry['attended']);
            $cards[$index]['marked_note'] = ($entry && $entry['attended'] && $entry['by']) ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '') : '';
            $cards[$index]['prior'] = $options['show_history'] ? self::count_prior($counts, $card['user_id'], $card['email'], $card['player_key']) : null;
            if ($card['refund_state'] === 'full') {
                continue;
            }
            $people++;
            $spots += $card['qty'];
            $guests += max(0, $card['qty'] - 1);
            if ($cards[$index]['prior'] === 0) {
                $first_timers++;
            }
            if ($cards[$index]['attended']) {
                $checked_in += $card['qty'];
            }
        }
        usort($cards, function ($a, $b) {
            if ($a['attended'] !== $b['attended']) {
                return $a['attended'] ? 1 : -1;
            }
            return strcasecmp($a['player'] !== '' ? $a['player'] : $a['name'], $b['player'] !== '' ? $b['player'] : $b['name']);
        });

        $base = remove_query_arg(array('slot', 'past'), $base_url);
        $date_url = add_query_arg('session', $selected_date, $base);
        ob_start();
        ?>
        <div class="murvc-sessions murvc-dated coach-portal">
            <div class="murvc-session-picker">
                <label for="murvc-session-select"><strong>Session:</strong></label>
                <select id="murvc-session-select" onchange="location.href=this.value;">
                    <?php foreach ($dates as $date => $entry): ?>
                        <option value="<?php echo esc_url(add_query_arg('session', $date, $base)); ?>" <?php selected($selected_date, $date); ?>>
                            <?php
                            echo esc_html($entry['label']);
                            echo $date === $today ? ' — today' : '';
                            echo $entry['cancelled'] ? ' (cancelled)' : ($entry['closed'] ? ' (bookings closed)' : '');
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="murvc-session-summary">
                    <strong><?php echo intval($spots); ?></strong> booked
                    <?php if ($guests > 0): ?>(<?php echo intval($people); ?> bookings + <?php echo intval($guests); ?> guest<?php echo $guests === 1 ? '' : 's'; ?>)<?php endif; ?>
                    <?php if ($first_timers > 0): ?> &middot; <strong><?php echo intval($first_timers); ?></strong> first-timer<?php echo $first_timers === 1 ? '' : 's'; ?><?php endif; ?>
                    &middot; <strong class="murvc-checked-count"><?php echo intval($checked_in); ?></strong> here
                    <?php if ($selected_date === $today): ?><span class="murvc-today-flag">TODAY</span><?php endif; ?>
                </span>
                <?php if ($many_slots): ?>
                    <p class="murvc-slot-filter">
                        <?php
                        $filter_links = array();
                        $all_label = 'All (' . intval(array_sum($slot_places)) . ')';
                        $filter_links[] = $slot_filter === '' ? '<strong>' . esc_html($all_label) . '</strong>' : '<a href="' . esc_url($date_url) . '">' . esc_html($all_label) . '</a>';
                        foreach ($day['slots'] as $start => $slot) {
                            $label = TeamOversight_Program_Sessions::time_label($start) . ' (' . intval(isset($slot_places[$start]) ? $slot_places[$start] : 0) . ')';
                            $filter_links[] = $slot_filter === $start ? '<strong>' . esc_html($label) . '</strong>' : '<a href="' . esc_url(add_query_arg('slot', $start, $date_url)) . '">' . esc_html($label) . '</a>';
                        }
                        echo implode(' &middot; ', $filter_links);
                        ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php echo $earlier_html; ?>
            <?php foreach ($day['slots'] as $start => $slot): ?>
                <?php if (!empty($slot['cancelled']) && ($slot_filter === '' || $slot_filter === $start)): ?>
                    <p class="murvc-cancelled-banner">Cancelled by the club<?php echo $many_slots ? ': ' . esc_html($slot['label']) : ''; ?></p>
                <?php elseif (!empty($slot['closed']) && ($slot_filter === '' || $slot_filter === $start)): ?>
                    <p class="murvc-closed-note">Bookings closed<?php echo $many_slots ? ': ' . esc_html($slot['label']) : ''; ?></p>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!empty($cards)): ?>
                <p class="murvc-session-search">
                    <label class="screen-reader-text" for="murvc-attendee-search">Search attendees</label>
                    <input type="search" id="murvc-attendee-search" placeholder="Search name or email…" autocomplete="off">
                    <span class="murvc-search-count"></span>
                </p>
            <?php endif; ?>

            <?php if (empty($cards)): ?>
                <p>Nobody has booked this session yet.</p>
            <?php else: ?>
                <?php foreach ($cards as $card): ?>
                    <?php
                    $prior = $card['prior'];
                    $is_first = ($prior === 0 && $card['refund_state'] !== 'full');
                    $shown_name = $card['player'] !== '' ? $card['player'] : $card['name'];
                    // The holder's own line (its Player is the account's name) needs no "Booked by".
                    $booked_by = $card['player'] !== '' && self::name_key($card['player']) !== self::name_key($card['name']);
                    ?>
                    <div class="coach-applicant-card<?php echo $is_first ? ' murvc-first-timer' : ''; ?><?php echo !empty($card['attended']) ? ' murvc-attended' : ''; ?>"
                         data-item="<?php echo intval($card['order_item_id']); ?>"
                         data-qty="<?php echo intval($card['qty']); ?>"
                         data-search="<?php echo esc_attr(strtolower($card['player'] . ' ' . $card['name'] . ' ' . $card['email'])); ?>">
                        <div class="cac-header">
                            <span class="cac-name"><?php echo esc_html($shown_name); ?></span>
                            <span class="cac-chips">
                                <?php if ($is_first): ?>
                                    <span class="verdict-chip chip-firsttimer" title="First session of this program — say hello, check they know where to go, and confirm their emergency contact.">👋 New here</span>
                                <?php elseif ($prior !== null && $prior > 0): ?>
                                    <span class="verdict-chip chip-regular" title="Has booked <?php echo intval($prior); ?> earlier session(s) of this program."><?php echo esc_html(self::ordinal($prior + 1)); ?> session</span>
                                <?php endif; ?>
                                <?php if ($many_slots && $card['slot'] !== '' && isset($day['slots'][$card['slot']])): ?>
                                    <span class="verdict-chip chip-slot"><?php echo esc_html($day['slots'][$card['slot']]['label']); ?></span>
                                <?php endif; ?>
                                <?php if ($card['qty'] > 1): ?>
                                    <span class="verdict-chip chip-guests" title="Booked <?php echo intval($card['qty']); ?> spots — bringing <?php echo intval($card['qty'] - 1); ?> guest(s) whose names we don't have">&times;<?php echo intval($card['qty']); ?> (<?php echo intval($card['qty'] - 1); ?> guest<?php echo ($card['qty'] - 1) === 1 ? '' : 's'; ?>)</span>
                                <?php endif; ?>
                                <?php if ($card['cancelled'] && $card['refund_state'] === 'full'): ?>
                                    <span class="verdict-chip chip-refunded">Refunded</span>
                                <?php elseif ($card['refund_state'] === 'part'): ?>
                                    <span class="verdict-chip chip-refund" title="Part of this booking was refunded in the shop">Part refunded<?php echo esc_html($card['refunded_amount'] >= 0.005 ? ' $' . number_format($card['refunded_amount'], 2) : ''); ?></span>
                                <?php elseif ($card['cancelled']): ?>
                                    <span class="verdict-chip chip-refund">Refund due</span>
                                <?php endif; ?>
                                <?php if (!$card['has_account']): ?>
                                    <span class="verdict-chip chip-guestorder" title="Ordered without a club account — no profile or emergency contact on file">No account</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="cac-meta">
                            <?php if ($booked_by): ?>
                                Booked by <?php echo esc_html($card['name']); ?> &middot;
                            <?php endif; ?>
                            <?php if ($card['email']): ?>
                                <a href="mailto:<?php echo esc_attr($card['email']); ?>"><?php echo esc_html($card['email']); ?></a>
                            <?php endif; ?>
                            <?php if ($card['mobile']): ?>
                                &middot; <a href="tel:<?php echo esc_attr(TeamOversight_Coach_Portal::phone_tel_href($card['mobile'])); ?>"><?php echo esc_html(TeamOversight_Coach_Portal::format_phone($card['mobile'])); ?></a>
                            <?php endif; ?>
                            &middot; <small>order #<?php echo intval($card['order_id']); ?></small>
                        </div>
                        <div class="cac-footer">
                            <span class="cac-expanders">
                                <?php echo TeamOversight_Coach_Portal::render_emergency_details($card['email'], $card['user_id'], false); ?>
                            </span>
                            <span class="cac-actions">
                                <?php if (!empty($card['marked_note'])): ?>
                                    <small class="murvc-marked-note"><?php echo esc_html($card['marked_note']); ?></small>
                                <?php endif; ?>
                                <form method="post" class="murvc-attendance-form" data-item="<?php echo intval($card['order_item_id']); ?>">
                                    <input type="hidden" name="murvc_attendance_action" value="mark">
                                    <input type="hidden" name="program_key" value="<?php echo esc_attr($key); ?>">
                                    <input type="hidden" name="order_item_id" value="<?php echo intval($card['order_item_id']); ?>">
                                    <input type="hidden" name="session_date" value="<?php echo esc_attr($selected_date); ?>">
                                    <input type="hidden" name="attended" value="<?php echo !empty($card['attended']) ? '0' : '1'; ?>">
                                    <?php wp_nonce_field('murvc_attendance', 'murvc_attendance_nonce'); ?>
                                    <?php if (!empty($card['attended'])): ?>
                                        <button type="submit" class="button murvc-untick">✔ Here — undo</button>
                                    <?php else: ?>
                                        <button type="submit" class="button button-primary murvc-tick">Mark here</button>
                                    <?php endif; ?>
                                </form>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var root = document.querySelector('.murvc-sessions.murvc-dated');
            if (!root) { return; }
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('murvc_attendance')); ?>;
            var programKey = <?php echo wp_json_encode($key); ?>;
            var sessionDate = <?php echo wp_json_encode($selected_date); ?>;

            var box = document.getElementById('murvc-attendee-search');
            var counter = root.querySelector('.murvc-search-count');
            if (box) {
                box.addEventListener('input', function () {
                    var term = box.value.toLowerCase().trim();
                    var shown = 0;
                    root.querySelectorAll('.coach-applicant-card').forEach(function (card) {
                        var match = term === '' || (card.dataset.search || '').indexOf(term) !== -1;
                        card.style.display = match ? '' : 'none';
                        if (match) { shown++; }
                    });
                    counter.textContent = term === '' ? '' : shown + ' match' + (shown === 1 ? '' : 'es');
                });
            }

            function applyState(card, attended, note) {
                var form = card.querySelector('.murvc-attendance-form');
                var button = form.querySelector('button');
                var noteEl = card.querySelector('.murvc-marked-note');
                card.classList.toggle('murvc-attended', attended);
                form.querySelector('input[name="attended"]').value = attended ? '0' : '1';
                button.textContent = attended ? '✔ Here — undo' : 'Mark here';
                button.className = attended ? 'button murvc-untick' : 'button button-primary murvc-tick';
                if (note) {
                    if (!noteEl) {
                        noteEl = document.createElement('small');
                        noteEl.className = 'murvc-marked-note';
                        form.parentNode.insertBefore(noteEl, form);
                    }
                    noteEl.textContent = note;
                } else if (noteEl) {
                    noteEl.remove();
                }
                if (attended) { card.parentNode.appendChild(card); }
            }

            function refreshCount() {
                var el = root.querySelector('.murvc-checked-count');
                if (!el) { return; }
                var count = 0;
                root.querySelectorAll('.coach-applicant-card.murvc-attended').forEach(function (card) {
                    count += parseInt(card.dataset.qty, 10) || 0;
                });
                el.textContent = count;
            }

            root.addEventListener('submit', function (event) {
                var form = event.target.closest('.murvc-attendance-form');
                if (!form) { return; }
                event.preventDefault();
                var button = form.querySelector('button');
                var card = form.closest('.coach-applicant-card');
                var wanted = form.querySelector('input[name="attended"]').value === '1';
                button.disabled = true;
                var original = button.textContent;
                button.textContent = 'Saving…';
                fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({
                    action: 'murvc_mark_attendance',
                    nonce: nonce,
                    program_key: programKey,
                    order_item_id: form.dataset.item,
                    session_date: sessionDate,
                    attended: wanted ? '1' : '0'
                })})
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        button.disabled = false;
                        if (!res || !res.success) {
                            button.textContent = original;
                            alert(res && res.data ? res.data : 'Could not save — please try again.');
                            return;
                        }
                        applyState(card, res.data.attended, res.data.note);
                        refreshCount();
                    })
                    .catch(function () {
                        button.disabled = false;
                        button.textContent = original;
                        alert('Network problem — please try again.');
                    });
            });

            var lastHash = '';
            function poll() {
                if (document.hidden) { return; }
                fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({
                    action: 'murvc_attendance_state',
                    nonce: nonce,
                    program_key: programKey,
                    order_item_id: '0',
                    session_date: sessionDate,
                    since: lastHash
                })})
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { return; }
                        lastHash = res.data.hash || '';
                        if (res.data.unchanged || !res.data.marked) { return; }
                        root.querySelectorAll('.coach-applicant-card').forEach(function (card) {
                            var form = card.querySelector('.murvc-attendance-form');
                            if (!form) { return; }
                            var state = res.data.marked[form.dataset.item];
                            var attended = !!(state && state.attended);
                            if (attended !== card.classList.contains('murvc-attended')) {
                                applyState(card, attended, state ? state.note : '');
                            }
                        });
                        refreshCount();
                    })
                    .catch(function () {});
            }
            setInterval(poll, 20000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) { poll(); }
            });
        })();
        </script>
        <?php echo self::render_styles(); ?>
        <style>
        .murvc-dated .chip-slot { background: #eef2f8; color: #1d3d6e; border: 1px solid #9fb3d1; }
        .murvc-dated .chip-refunded { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
        .murvc-dated .murvc-slot-filter { margin: 8px 0 0; font-size: 13px; }
        .murvc-dated .murvc-cancelled-banner { background: #fdf2f2; border-left: 4px solid #b32d2e; color: #8a1f11; font-weight: 600; padding: 8px 12px; border-radius: 4px; }
        .murvc-dated .murvc-closed-note { background: #fff7f0; border-left: 4px solid #e07b00; padding: 6px 12px; border-radius: 4px; }
        .murvc-dated .murvc-earlier, .murvc-earlier-head { font-size: 13px; }
        </style>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Portal pages: never cached, never indexed (1.84.0, design 8)
    // ------------------------------------------------------------------

    /**
     * The roll shows names, mobiles and emergency details, so a page that
     * draws it is never cached or indexed: DONOTCACHEPAGE, the no-cache
     * headers and X-Robots-Tag (when headers can still be sent), and the
     * robots meta through the offer page's own filters (one copy of the
     * rule). Idempotent.
     */
    public static function no_cache() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow', true);
        }
        if (class_exists('TeamOversight_Offers_Player')) {
            if (method_exists('TeamOversight_Offers_Player', 'robots_noindex')) {
                add_filter('wp_robots', array('TeamOversight_Offers_Player', 'robots_noindex'), 100);
            }
            if (method_exists('TeamOversight_Offers_Player', 'seo_noindex')) {
                add_filter('the_seo_framework_robots_meta_array', array('TeamOversight_Offers_Player', 'seo_noindex'), 100);
            }
        }
    }

    /** Whether some content holds a portal shortcode ([session_attendance] or [mixed_dev_attendance]). */
    public static function content_has_portal($content) {
        if (!is_string($content) || $content === '') {
            return false;
        }
        foreach (self::PORTAL_SHORTCODES as $tag) {
            if (has_shortcode($content, $tag)) {
                return true;
            }
        }
        return false;
    }

    /**
     * template_redirect, priority 9: before any output, so the headers go
     * out. Reads only the page's own content: a portal shortcode inside a
     * synced pattern, template part or widget gets DONOTCACHEPAGE when it
     * renders but no early headers, which is why the shortcode goes on the
     * page itself.
     */
    public static function maybe_no_cache() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $post = get_queried_object();
        if ($post instanceof WP_Post && self::content_has_portal((string) $post->post_content)) {
            self::no_cache();
        }
    }

    /**
     * The program [mixed_dev_attendance] shows (design 5.2, a permanent
     * alias): the program whose setup names this page as its portal page,
     * else key mixed-dev, else (as before) the last program whose name
     * contains "mixed". '' when none.
     */
    public static function alias_program_key($page_id = 0) {
        $page_id = intval($page_id);
        if ($page_id <= 0) {
            $page_id = intval(get_the_ID());
        }
        if ($page_id <= 0) {
            $page_id = intval(get_queried_object_id());
        }
        $programs = self::get_programs();
        if ($page_id > 0 && class_exists('TeamOversight_Program_Setup')) {
            foreach ($programs as $program_key => $program) {
                if (!TeamOversight_Program_Setup::has_entry($program_key)) {
                    continue;
                }
                $setup = TeamOversight_Program_Setup::get($program_key);
                if (intval($setup['portal_page']) === $page_id) {
                    return (string) $program_key;
                }
            }
        }
        if (isset($programs['mixed-dev'])) {
            return 'mixed-dev';
        }
        $key = '';
        foreach ($programs as $program_key => $program) {
            if (stripos($program['name'], 'mixed') !== false) {
                $key = (string) $program_key;
            }
        }
        return $key;
    }

    // ------------------------------------------------------------------
    // Front end: [session_attendance]
    // ------------------------------------------------------------------

    public function render_shortcode($atts) {
        // Before anything else: also covers a page that carries the shortcode
        // somewhere template_redirect can't see (a block, a widget).
        self::no_cache();
        $atts = shortcode_atts(array('program' => ''), $atts, 'session_attendance');

        if (!is_user_logged_in()) {
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            return '<div class="coach-portal-notice"><p><strong>Please log in to view session attendance.</strong></p>'
                . '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p></div>';
        }

        if (!self::user_can_view()) {
            return '<div class="coach-portal-notice"><p>This page is for club program supervisors. If you should have access, ask the committee to add you in Club Programs → Settings.</p></div>';
        }

        // A shortcode that names a program is authoritative: if the viewer
        // doesn't supervise that one, refuse rather than quietly showing
        // them a different program's roster.
        if ($atts['program'] !== '') {
            $program = self::get_program($atts['program']);
            if (!$program) {
                return '<p>That club program isn\'t configured.</p>';
            }
            return $this->render_program_for_supervisor($program, $atts['program']);
        }

        // Otherwise: only programs this supervisor actually runs.
        $programs = self::get_viewable_programs();
        if (empty($programs)) {
            return '<p>No club programs are configured yet.</p>';
        }

        $key = '';
        if (isset($_GET['program']) && isset($programs[sanitize_key($_GET['program'])])) {
            $key = sanitize_key($_GET['program']);
        }
        if ($key === '') {
            $key = key($programs);
        }

        $selected = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
        $base_url = remove_query_arg('session');

        $output = '';
        // Program switcher only when there's a choice.
        if (count($programs) > 1) {
            $output .= '<p class="murvc-program-switcher">';
            foreach ($programs as $program_key => $program) {
                $output .= ($program_key === $key)
                    ? '<strong>' . esc_html($program['name']) . '</strong> '
                    : '<a href="' . esc_url(add_query_arg('program', $program_key, remove_query_arg('session'))) . '">' . esc_html($program['name']) . '</a> ';
            }
            $output .= '</p>';
        }

        return $output . $this->render_program_body($programs[$key], $key, $selected, add_query_arg('program', $key, $base_url), array('program_key' => $key));
    }

    /**
     * 1.85.0 (design 5.2, B28): a portal's body. While the program has no
     * Sessions tab (no dated current product, nothing prepared) it is
     * exactly the roll as before. Otherwise two plain links, Roll and
     * Sessions, then the roll or the Sessions tab.
     */
    private function render_program_body($program, $key, $selected, $base_url, $options) {
        if (!class_exists('TeamOversight_Program_Sessions') || !TeamOversight_Program_Sessions::tab_available($key)) {
            return self::render_roster($program, $selected, $base_url, 'session', $options);
        }
        $tab = (isset($_GET['tab']) && $_GET['tab'] === 'sessions') ? 'sessions' : 'roll';
        $roll_url = remove_query_arg(array('tab', 'step', 'confirm', 'v', 'show_past', 'season'), $base_url);
        $sessions_url = add_query_arg('tab', 'sessions', remove_query_arg(array('session', 'slot', 'past', 'step', 'confirm', 'v', 'show_past', 'season'), $base_url));
        $bar = '<p class="murvc-portal-tabs"><strong>' . esc_html(isset($program['name']) ? (string) $program['name'] : $key) . '</strong> '
            . '<a href="' . esc_url($roll_url) . '"' . ($tab === 'roll' ? ' aria-current="page"' : '') . '>Roll</a> &middot; '
            . '<a href="' . esc_url($sessions_url) . '"' . ($tab === 'sessions' ? ' aria-current="page"' : '') . '>Sessions</a></p>';
        if ($tab === 'sessions') {
            return $bar . TeamOversight_Program_Sessions::render_tab($key, 'portal', $sessions_url);
        }
        return $bar . self::render_roster($program, $selected, $roll_url, 'session', $options);
    }

    /**
     * [mixed_dev_attendance] — Mixed Development's own shortcode. Locks to
     * that program and is the place to add program-specific behaviour
     * (first-timer highlighting lives here by default).
     * Attributes: history="0" hides the first-timer / session-count chips.
     * 1.84.0: a permanent alias that finds its program by the page first
     * (alias_program_key()), and is never cached.
     */
    public function render_mixed_dev_shortcode($atts) {
        self::no_cache();
        $atts = shortcode_atts(array('history' => '1'), $atts, 'mixed_dev_attendance');

        $programs = self::get_programs();
        $key = self::alias_program_key();
        if ($key === '' || !isset($programs[$key])) {
            return '<p>Mixed Development isn\'t configured yet — add it in Club Programs → Settings.</p>';
        }

        return $this->render_program_for_supervisor($programs[$key], $key, array(
            'show_history' => $atts['history'] !== '0',
        ));
    }

    /** Access gate + roster for one program, shared by both shortcodes. */
    private function render_program_for_supervisor($program, $key, $options = array()) {
        if (!is_user_logged_in()) {
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            return '<div class="coach-portal-notice"><p><strong>Please log in to view session attendance.</strong></p>'
                . '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p></div>';
        }
        if (!self::user_can_view($key)) {
            return '<div class="coach-portal-notice"><p>This page is for supervisors of this program. If you should have access, ask the committee to add you in Club Programs → Settings.</p></div>';
        }

        $selected = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
        $base_url = add_query_arg('program', $key, remove_query_arg('session'));
        $options['program_key'] = $key;
        return $this->render_program_body($program, $key, $selected, $base_url, $options);
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function render_admin_page($program_key) {
        // template_redirect doesn't run in wp-admin, so ticks are handled
        // here. Explicit state means a refresh resubmit is harmless.
        // 1.84.0: a tick is saved only for this page's own program.
        $tick_notice = '';
        if (isset($_POST['murvc_attendance_action']) && $_POST['murvc_attendance_action'] === 'mark'
            && isset($_POST['murvc_attendance_nonce']) && wp_verify_nonce($_POST['murvc_attendance_nonce'], 'murvc_attendance')
            && current_user_can('manage_options')
            && isset($_POST['program_key'])) {
            if (sanitize_key(wp_unslash($_POST['program_key'])) !== sanitize_key((string) $program_key)) {
                $tick_notice = '<div class="notice notice-error"><p>That tick was for another program\'s page, so nothing was saved. Reload and try again.</p></div>';
            } elseif (isset($_POST['order_item_id'], $_POST['session_date'])) {
                // 1.85.0: a dated roll's tick, checked against the line, the session and a paid order.
                $done = self::mark_line(get_current_user_id(), $program_key, absint($_POST['order_item_id']), sanitize_text_field(wp_unslash($_POST['session_date'])), !empty($_POST['attended']));
                if (!$done['ok']) {
                    $tick_notice = '<div class="notice notice-error"><p>' . esc_html($done['message']) . '</p></div>';
                }
            } elseif (isset($_POST['session_label'], $_POST['order_id'])) {
                self::set_attendance(
                    $program_key,
                    sanitize_text_field(wp_unslash($_POST['session_label'])),
                    intval($_POST['order_id']),
                    !empty($_POST['attended']),
                    isset($_POST['attendee_user_id']) ? intval($_POST['attendee_user_id']) : 0
                );
            }
        }

        $program = self::get_program($program_key);
        // 1.85.0 (design 6): the program's tabs. Roll and stats is the default
        // (and the answer to an unknown or unavailable tab).
        $tabs = array('roll' => 'Roll and stats');
        if ($program && class_exists('TeamOversight_Program_Sessions')) {
            if (TeamOversight_Program_Sessions::tab_available($program_key)) {
                $tabs['sessions'] = 'Sessions';
            }
            $tabs['setup'] = 'Setup';
            foreach (self::program_products($program_key) as $product) {
                if ($product['kind'] === 'dated') {
                    $tabs['refund'] = 'To refund';
                }
            }
        }
        $tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if (!isset($tabs[$tab])) {
            $tab = 'roll';
        }
        $tab_base = admin_url('admin.php?page=club-programs-' . $program_key);
        ?>
        <div class="wrap">
            <h1><?php echo $program ? esc_html($program['name']) : 'Program'; ?> — Attendance</h1>
            <?php echo $tick_notice; ?>
            <?php if (!$program): ?>
                <p>This program is no longer configured. Add it in <a href="<?php echo admin_url('admin.php?page=club-programs-settings'); ?>">Settings</a>.</p>
            <?php else: ?>
                <?php if (count($tabs) > 1): ?>
                    <h2 class="nav-tab-wrapper">
                        <?php foreach ($tabs as $tab_key => $tab_label): ?>
                            <a href="<?php echo esc_url($tab_key === 'roll' ? $tab_base : add_query_arg('tab', $tab_key, $tab_base)); ?>" class="nav-tab<?php echo $tab === $tab_key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($tab_label); ?></a>
                        <?php endforeach; ?>
                    </h2>
                <?php endif; ?>
                <?php if ($tab === 'sessions'): ?>
                    <?php echo TeamOversight_Program_Sessions::render_tab($program_key, 'admin', add_query_arg('tab', 'sessions', $tab_base)); ?>
                <?php elseif ($tab === 'setup'): ?>
                    <?php echo TeamOversight_Program_Setup::render_tab($program_key, add_query_arg('tab', 'setup', $tab_base)); ?>
                <?php elseif ($tab === 'refund'): ?>
                    <?php echo TeamOversight_Program_Sessions::render_refund_tab($program_key); ?>
                <?php else: ?>
                <p class="description">Who has paid for each session, read live from orders. Supervisors can see this on the front end via the <code>[session_attendance]</code> shortcode — grant access in <a href="<?php echo admin_url('admin.php?page=club-programs-settings'); ?>">Settings</a>.</p>
                <?php
                $page_url = admin_url('admin.php?page=club-programs-' . $program_key);
                echo self::render_stats($program, $program_key, $page_url);

                // Keep the chosen range when the session picker reloads.
                foreach (array('from', 'to') as $range_arg) {
                    if (isset($_GET[$range_arg])) {
                        $page_url = add_query_arg($range_arg, self::sanitise_date(wp_unslash($_GET[$range_arg])), $page_url);
                    }
                }

                $selected = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
                echo self::render_roster($program, $selected, $page_url, 'session', array('program_key' => $program_key));
                ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 1.85.0 (design 6, B16): the Settings form posts to admin-post. The
     * save's own notice is kept in a flash for the page it redirects to
     * (Post/Redirect/Get). save_settings() keeps its checks and output.
     */
    public static function handle_settings_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        $self = new self();
        ob_start();
        $self->save_settings();
        $notice = ob_get_clean();
        set_transient(self::SETTINGS_FLASH . get_current_user_id(), (string) $notice, 300);
        wp_safe_redirect(admin_url('admin.php?page=club-programs-settings'));
        exit;
    }

    public function render_settings_page() {
        // 1.85.0: the page no longer saves anything itself (the form posts to
        // admin-post). A page opened before the update still posts here.
        if (isset($_POST['action']) && $_POST['action'] === 'save_program_settings') {
            echo '<div class="notice notice-error"><p>This page was opened before an update, so nothing was saved. Make the change again.</p></div>';
        }
        $flash_name = self::SETTINGS_FLASH . get_current_user_id();
        $flash = get_transient($flash_name);
        if (is_string($flash) && $flash !== '') {
            delete_transient($flash_name);
            echo wp_kses_post($flash);
        }

        $programs = self::get_programs();
        $legacy = self::get_supervisors();
        ?>
        <div class="wrap">
            <h1>Club Programs — Settings</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; max-width: 720px;">
                <h2 style="margin-top: 0;">Programs</h2>
                <p class="description">One line per program: <code>Name | product ID | weekday</code>. The product's date variations become the sessions; each program gets its own admin sub-page (reload after saving). <strong>Weekday is optional but important</strong> when the variation labels have no year (e.g. "13-May") — it pins each session to the right year, so last season's dates sort and filter correctly. Use a day name or 1–7 (Mon–Sun).</p>
                <?php
                $any_setup = false;
                if (class_exists('TeamOversight_Program_Setup')) {
                    foreach (array_keys($programs) as $setup_key) {
                        if (TeamOversight_Program_Setup::has_entry($setup_key)) {
                            $any_setup = true;
                        }
                    }
                }
                ?>
                <?php if ($any_setup): ?>
                    <p class="description">A program set up for online booking keeps its product here: change it on its Setup tab.</p>
                <?php endif; ?>
                <textarea name="programs" rows="5" style="width: 100%; font-family: monospace;"><?php
                    $lines = array();
                    foreach ($programs as $program) {
                        $weekday = self::get_program_weekday($program);
                        $lines[] = $program['name'] . ' | ' . $program['product_id']
                            . ($weekday ? ' | ' . date('l', strtotime('Sunday +' . $weekday . ' days')) : '');
                    }
                    echo esc_textarea(implode("\n", $lines));
                ?></textarea>

                <h2>Supervisors</h2>
                <p class="description">Per program: the accounts that can see that program's attendance and tick people off. Search by name or email, click to add. Administrators always have access to everything.</p>
                <?php foreach ($programs as $key => $program): ?>
                    <?php
                    $own = (!empty($program['supervisors']) && is_array($program['supervisors'])) ? $program['supervisors'] : array();
                    ?>
                    <div class="murvc-sup-group" data-program="<?php echo esc_attr($key); ?>" style="margin-bottom: 18px;">
                        <p style="margin-bottom: 4px;"><strong><?php echo esc_html($program['name']); ?></strong>
                            <?php if (class_exists('TeamOversight_Program_Setup')): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=club-programs-' . $key . '&tab=setup')); ?>" style="margin-left: 8px; font-size: 12px;">Setup tab</a>
                            <?php endif; ?>
                        </p>
                        <div class="murvc-sup-list" style="margin-bottom: 6px;">
                            <?php foreach ($own as $user_id): ?>
                                <?php $user = get_userdata(intval($user_id)); ?>
                                <?php if (!$user) { continue; } ?>
                                <span class="murvc-sup-chip" style="display: inline-flex; align-items: center; gap: 6px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 12px; padding: 3px 6px 3px 12px; margin: 0 6px 6px 0;">
                                    <?php echo esc_html($user->display_name); ?> <small style="color: #646970;"><?php echo esc_html($user->user_email); ?></small>
                                    <input type="hidden" name="supervisors[<?php echo esc_attr($key); ?>][]" value="<?php echo esc_attr($user->user_email); ?>">
                                    <button type="button" class="button-link murvc-sup-remove" title="Remove" style="color: #a00; text-decoration: none; font-size: 16px; line-height: 1;">&times;</button>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <input type="text" class="murvc-sup-search" autocomplete="off" placeholder="Search members by name or email…" style="width: 320px;">
                        <div class="murvc-sup-results" style="display: none; position: absolute; z-index: 1000; background: #fff; border: 1px solid #ccc; max-height: 200px; overflow-y: auto; width: 320px;"></div>
                    </div>
                <?php endforeach; ?>

                <script>
                (function () {
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('search_users')); ?>;

                    document.querySelectorAll('.murvc-sup-group').forEach(function (group) {
                        var key = group.dataset.program;
                        var input = group.querySelector('.murvc-sup-search');
                        var results = group.querySelector('.murvc-sup-results');
                        var list = group.querySelector('.murvc-sup-list');
                        var timer = null;

                        function addSupervisor(name, email) {
                            if (list.querySelector('input[value="' + email.replace(/"/g, '\\"') + '"]')) { return; }
                            var chip = document.createElement('span');
                            chip.className = 'murvc-sup-chip';
                            chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:12px;padding:3px 6px 3px 12px;margin:0 6px 6px 0;';
                            chip.innerHTML = '<span></span> <small style="color:#646970;"></small>'
                                + '<input type="hidden" name="supervisors[' + key + '][]">'
                                + '<button type="button" class="button-link murvc-sup-remove" title="Remove" style="color:#a00;text-decoration:none;font-size:16px;line-height:1;">&times;</button>';
                            chip.querySelector('span').textContent = name;
                            chip.querySelector('small').textContent = email;
                            chip.querySelector('input').value = email;
                            list.appendChild(chip);
                        }

                        input.addEventListener('input', function () {
                            clearTimeout(timer);
                            var query = input.value.trim();
                            if (query.length < 2) { results.style.display = 'none'; return; }
                            timer = setTimeout(function () {
                                fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                    body: 'action=search_users&search_type=both&nonce=' + nonce + '&query=' + encodeURIComponent(query)
                                })
                                    .then(function (r) { return r.json(); })
                                    .then(function (res) {
                                        results.innerHTML = '';
                                        if (!res.success || !res.data || !res.data.length) { results.style.display = 'none'; return; }
                                        res.data.forEach(function (user) {
                                            var row = document.createElement('div');
                                            row.style.cssText = 'padding:6px 10px;cursor:pointer;';
                                            row.textContent = user.name + ' — ' + user.email;
                                            row.addEventListener('mouseenter', function () { row.style.background = '#f0f0f1'; });
                                            row.addEventListener('mouseleave', function () { row.style.background = '#fff'; });
                                            row.addEventListener('click', function () {
                                                addSupervisor(user.name, user.email);
                                                input.value = '';
                                                results.style.display = 'none';
                                            });
                                            results.appendChild(row);
                                        });
                                        results.style.display = 'block';
                                    });
                            }, 250);
                        });

                        list.addEventListener('click', function (event) {
                            if (event.target.classList.contains('murvc-sup-remove')) {
                                event.target.closest('.murvc-sup-chip').remove();
                            }
                        });
                        document.addEventListener('click', function (event) {
                            if (!group.contains(event.target)) { results.style.display = 'none'; }
                        });
                    });
                })();
                </script>

                <?php if (!empty($legacy)): ?>
                    <p class="description"><strong>Note:</strong> <?php echo count($legacy); ?> club-wide supervisor(s) from the previous setting still have access to every program. Move them into the per-program boxes above, then clear this list.
                        <label style="display: block; margin-top: 4px;"><input type="checkbox" name="clear_legacy" value="1"> Clear the old club-wide supervisor list</label>
                    </p>
                <?php endif; ?>

                <p>
                    <input type="submit" class="button button-primary" value="Save Program Settings">
                    <input type="hidden" name="action" value="murvc_program_settings">
                    <?php wp_nonce_field('save_program_settings', 'program_settings_nonce'); ?>
                </p>
            </form>

            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; max-width: 720px; margin-top: 20px;">
                <h2 style="margin-top: 0;">Front-end page</h2>
                <p>Put <code>[session_attendance]</code> on a page for supervisors. Lock it to one program with <code>[session_attendance program="mixed-dev"]</code>.</p>
                <p>These pages are never cached, and are marked noindex for search engines (sent automatically). Tick the page's noindex box in the SEO plugin too, so it also leaves the sitemap. Put the shortcode on the page itself, not in a synced pattern or widget.</p>
                <p>The Mixed Dev Portal's <code>[mixed_dev_attendance]</code> finds its program by the page, then the key <code>mixed-dev</code>, then by the name.</p>
            </div>
        </div>
        <?php
    }

    private function save_settings() {
        if (!isset($_POST['program_settings_nonce']) || !wp_verify_nonce($_POST['program_settings_nonce'], 'save_program_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        // 1.85.0 (B16): under the setup lock, so a save can't race a Switch.
        // NULL (no named locks) runs unlocked, as the setup's own saves do.
        global $wpdb;
        $has_setup = class_exists('TeamOversight_Program_Setup');
        $lock = $has_setup ? TeamOversight_Program_Setup::lock_name() : '';
        $got = null;
        if ($lock !== '') {
            $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
            if ($got !== null && intval($got) !== 1) {
                echo '<div class="notice notice-error"><p>Someone else is saving a program\'s setup. Try again in a minute.</p></div>';
                return;
            }
        }
        try {
            $this->save_settings_locked($has_setup);
        } finally {
            if ($lock !== '' && $got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            self::flush_program_caches();
            if (class_exists('TeamOversight_Program_Sessions')) {
                TeamOversight_Program_Sessions::flush_cache();
            }
        }
    }

    /** save_settings()' steps, inside the setup lock (1.85.0). */
    private function save_settings_locked($has_setup) {
        $submitted_supervisors = (isset($_POST['supervisors']) && is_array($_POST['supervisors'])) ? $_POST['supervisors'] : array();
        $unknown = array();
        $supervisor_total = 0;

        // Keep each program's existing key across saves. The form posts
        // supervisors under the key that was rendered, and attendance rows
        // are stored against it, so regenerating keys from the name would
        // drop the supervisors being saved and orphan attendance history.
        // 1.85.0 (B16): both options read straight from the database inside
        // the lock (an autoloaded copy can be older than the row, and a
        // Settings page opened before a Switch would write the old product
        // back); the product-id lock then refuses that page's old id.
        $setup_all = array();
        if ($has_setup) {
            $existing = TeamOversight_Program_Setup::read_option_fresh(self::PROGRAMS_OPTION);
            $setup_all = TeamOversight_Program_Setup::read_option_fresh(TeamOversight_Program_Setup::OPTION);
            if ($existing === null || $setup_all === null) {
                echo '<div class="notice notice-error"><p>The settings couldn\'t be read, so nothing was saved.</p></div>';
                return;
            }
            if (empty($existing)) {
                // Nothing stored yet: the seeded list, as before.
                $existing = self::get_programs();
            }
            TeamOversight_Program_Setup::flush_cache();
            wp_cache_delete(TeamOversight_Program_Setup::OPTION, 'options');
        } else {
            $existing = self::get_programs();
        }
        $has_entry = function ($program_key) use ($setup_all) {
            return isset($setup_all[$program_key]) && is_array($setup_all[$program_key]);
        };
        $refusals = array();
        $key_by_name = array();
        $key_by_product = array();
        foreach ($existing as $existing_key => $existing_program) {
            $key_by_name[strtolower($existing_program['name'])] = $existing_key;
            $key_by_product[intval($existing_program['product_id'])] = $existing_key;
        }

        $programs = array();
        foreach (explode("\n", sanitize_textarea_field(wp_unslash($_POST['programs']))) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $name = isset($parts[0]) ? $parts[0] : '';
            $product_id = isset($parts[1]) ? intval($parts[1]) : 0;
            $weekday = isset($parts[2]) ? self::get_program_weekday(array('weekday' => $parts[2])) : 0;
            if ($name === '' || !$product_id) {
                continue;
            }
            // Same name (or same product, so renames keep their history)
            // reuses the existing key; genuinely new programs get one.
            if (isset($key_by_name[strtolower($name)])) {
                $key = $key_by_name[strtolower($name)];
            } elseif (isset($key_by_product[$product_id])) {
                $key = $key_by_product[$product_id];
            } else {
                $key = sanitize_title($name);
            }

            // Supervisor emails resolve to accounts; unknown ones are
            // reported rather than silently dropped.
            $ids = array();
            if (isset($submitted_supervisors[$key])) {
                $entries = is_array($submitted_supervisors[$key])
                    ? $submitted_supervisors[$key]
                    : explode("\n", (string) $submitted_supervisors[$key]);
                foreach ($entries as $entry) {
                    $email = sanitize_email(trim(wp_unslash($entry)));
                    if ($email === '') {
                        continue;
                    }
                    $user = get_user_by('email', $email);
                    if ($user) {
                        $ids[] = intval($user->ID);
                    } else {
                        $unknown[] = $email;
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            $supervisor_total += count($ids);

            // 1.84.0: a program set up for online booking keeps its product
            // here: it changes only on its Setup tab (otherwise a typed id
            // would bypass the switch).
            if ($has_setup && $has_entry($key) && isset($existing[$key]) && is_array($existing[$key])
                && intval(isset($existing[$key]['product_id']) ? $existing[$key]['product_id'] : 0) !== $product_id) {
                $refusals[] = esc_html($name) . ': change the product on the program\'s Setup tab.';
            }
            // 1.85.0 (plan 1.84.0 A17): never another program's prepared or earlier product.
            if ($has_setup) {
                $owner = TeamOversight_Program_Setup::owner_of_product($product_id, $key, false);
                if ($owner !== '') {
                    $other = isset($existing[$owner]['name']) && is_scalar($existing[$owner]['name']) ? (string) $existing[$owner]['name'] : $owner;
                    $refusals[] = esc_html($name) . ': product #' . intval($product_id) . ' already belongs to ' . esc_html($other) . ' (its prepared or an earlier product).';
                }
            }

            // 1.84.0: start from the stored entry and set only the four
            // fields this screen owns, so a field it doesn't know survives.
            $entry = (isset($existing[$key]) && is_array($existing[$key])) ? $existing[$key] : array();
            $entry['name'] = $name;
            $entry['product_id'] = $product_id;
            $entry['weekday'] = $weekday;
            $entry['supervisors'] = $ids;
            $programs[$key] = $entry;
        }

        // 1.84.0: nor can a set-up program be left out (deleted, or renamed
        // with a new product in one save, which would make a new key).
        if ($has_setup) {
            foreach ($existing as $existing_key => $existing_program) {
                if (!isset($programs[$existing_key]) && $has_entry($existing_key)) {
                    $existing_name = (is_array($existing_program) && isset($existing_program['name'])) ? (string) $existing_program['name'] : (string) $existing_key;
                    $refusals[] = esc_html($existing_name) . ' is set up for online booking, so it can\'t be removed or given a new product here.';
                }
            }
        }
        if (!empty($refusals)) {
            echo '<div class="notice notice-error"><p><strong>Nothing was saved.</strong></p><ul style="list-style: disc; margin-left: 20px;"><li>'
                . implode('</li><li>', $refusals) . '</li></ul></div>';
            return;
        }

        // update_option() compares with the row, not an older autoloaded copy.
        wp_cache_delete('alloptions', 'options');
        update_option(self::PROGRAMS_OPTION, $programs);
        self::flush_program_caches();
        if (class_exists('TeamOversight_Program_Sessions')) {
            TeamOversight_Program_Sessions::flush_cache();
        }

        if (!empty($_POST['clear_legacy'])) {
            delete_option(self::SUPERVISORS_OPTION);
        }

        echo '<div class="notice notice-success"><p>Saved: ' . count($programs) . ' program(s), ' . intval($supervisor_total) . ' supervisor assignment(s).'
            . (!empty($unknown) ? ' <strong>No account found for:</strong> ' . esc_html(implode(', ', array_unique($unknown))) : '')
            . '</p></div>';
    }
}
