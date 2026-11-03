<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Programs sessions (1.85.0, program bookings design 3.1).
 *
 * A session is one variation of a program's dated product (made by Prepare
 * the next product on the Setup tab), carrying its date, slot, places and
 * state as post meta (design 3.4). This class holds:
 * - the labels (date_label(), slot_label(): the ONE spelling, English, from
 *   a stored date with gmdate(), never typed);
 * - children(): ONE direct query over a product's variations in any status,
 *   the Bin included (never get_children() or WooCommerce's children
 *   transient: the site's object cache is persistent, so a cached list can
 *   be stale);
 * - the series generator (check_series(), series_outcome(), fingerprint(),
 *   preview_series(), create_series()): the product's session lock, the
 *   fingerprint, nothing ever recreated, safe to run twice;
 * - the writers (close, reopen, cancel, undo cancel, change places, change
 *   price), each behind writer_target() and the product's session lock;
 * - the purchasable filters (a dated current product's booking rules;
 *   prepared, past and orphan session products never; a legacy or month
 *   current product untouched, so nothing changes until a switch) and the
 *   daily sweep;
 * - the deletion guard (sessions are never deleted; it starts at the switch
 *   for a program still selling a legacy product);
 * - the Sessions tab (portal and admin) and To refund (admin).
 *
 * Every session write takes lock_name($product_id). Setup actions take
 * TeamOversight_Program_Setup::lock_name() first and session locks second,
 * never the other way round. A session lock that isn't granted (0 or NULL)
 * refuses: two Creates must never run side by side.
 */
class TeamOversight_Program_Sessions {

    /** Design 4.15: sessions per series run (the series' pairs after the skips). */
    const MAX_PER_SERIES = 60;
    /** Design 4.15: dates to skip in one series. */
    const MAX_SKIPS = 30;
    /** Design 4.15: a series preview is kept 30 minutes. */
    const SERIES_TTL = 1800;
    /** Design 4.15: a session can be cancelled up to 14 days after it. */
    const CANCEL_LATE_DAYS = 14;
    /** Design 4.15: places per session. */
    const MAX_PLACES = 500;
    /** Design 4.2 step 5: the flash after a post. */
    const FLASH_TTL = 300;
    /** Design 4.3: the cancel reason's length. */
    const REASON_MIN = 3;
    const REASON_MAX = 120;

    /** + 12 characters of md5(table prefix) + '_' + product id: named locks are server-wide. */
    const LOCK_PREFIX = 'murvc_prog_sess_';
    /** Club Membership → Logs (design 4.14, D16). */
    const LOG_TYPE = 'program_sessions';
    const GUARD_LOG_TYPE = 'program_guard';

    /** Nonce action murvc_sessions_<key> (design 3.1) and the post fields. */
    const NONCE_PREFIX = 'murvc_sessions_';
    const ACTION_FIELD = 'murvc_sessions_action';
    const NONCE_FIELD = 'murvc_sessions_nonce';
    const ACTIONS = array('preview', 'create', 'close', 'reopen', 'cancel', 'undo_cancel', 'places', 'price');

    /** Variation meta (design 3.4). */
    const META_DATE = '_murvc_session_date';
    const META_SLOT = '_murvc_session_slot';
    const META_CAPACITY = '_murvc_session_capacity';
    const META_STATE = '_murvc_session_state';
    const META_CANCELLED_ON = '_murvc_session_cancelled_on';
    const META_CANCEL_REASON = '_murvc_session_cancel_reason';
    const META_SERIES = '_murvc_session_series';

    const LOCK_BUSY = 'Someone else is changing these sessions. Try again in a minute.';
    const NOT_OURS = 'That session isn\'t one of this program\'s.';

    /**
     * Discard's request-scoped flag (design 4.4): the id of the prepared
     * product it is binning. The guard lets that product, and its own
     * variations, go to the Bin (never deleted) while it is set.
     */
    public static $discarding = 0;

    private static $registered = false;
    /** product id => array(key, role, kind), request cached; dropped by flush_cache(). */
    private static $map = null;
    /** What the guard refused in this request (one log line at shutdown). */
    private static $guard_refusals = array();
    private static $guard_hooked = false;

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    public static function register_hooks() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        // Every Sessions action is a small POST handled before the page is drawn (design 5.3).
        add_action('template_redirect', array(__CLASS__, 'handle_post'), 10);
        add_action('admin_post_murvc_program_sessions', array(__CLASS__, 'handle_admin_post'));
        // Club Manager's second layer (design 4.1); WooCommerce's own states are the first.
        add_filter('woocommerce_variation_is_purchasable', array(__CLASS__, 'variation_purchasable'), 10, 2);
        add_filter('woocommerce_is_purchasable', array(__CLASS__, 'product_purchasable'), 10, 2);
        // The daily sweep makes past sessions private (design 4.1).
        if (class_exists('TeamOversight_Memberships')) {
            add_action(TeamOversight_Memberships::CRON_HOOK, array(__CLASS__, 'sweep'), 30);
        }
        // Sessions are never deleted (design 4.4).
        add_filter('pre_delete_post', array(__CLASS__, 'guard_delete'), 10, 3);
        add_filter('pre_trash_post', array(__CLASS__, 'guard_trash'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'guard_notice'));
    }

    // ------------------------------------------------------------------
    // Labels and small readers (design 3.3, 3.4)
    // ------------------------------------------------------------------

    /** A real Y-m-d as array(y, m, d), else null. */
    private static function ymd_parts($ymd) {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m)) {
            return null;
        }
        if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return null;
        }
        return array(intval($m[1]), intval($m[2]), intval($m[3]));
    }

    /** "Sun 24 Jan 2027" from "2027-01-24"; '' for anything not a real date. English always. */
    public static function date_label($ymd) {
        $parts = self::ymd_parts($ymd);
        if ($parts === null) {
            return '';
        }
        return gmdate('D j M Y', gmmktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }

    /** "Sun 28 Mar" (the preview's short dates). */
    public static function short_date($ymd) {
        $parts = self::ymd_parts($ymd);
        return $parts === null ? '' : gmdate('D j M', gmmktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }

    /** "5:00pm" from "17:00"; '' for anything else. */
    public static function time_label($hhmm) {
        if (!is_string($hhmm) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $hhmm, $m)) {
            return '';
        }
        return gmdate('g:ia', gmmktime(intval($m[1]), intval($m[2]), 0, 1, 1, 2000));
    }

    /** "5:00pm - 7:00pm" from a slot's start and end (design 3.3: never typed). */
    public static function slot_label(array $slot) {
        $start = isset($slot['start']) ? self::time_label((string) $slot['start']) : '';
        $end = isset($slot['end']) ? self::time_label((string) $slot['end']) : '';
        return ($start === '' || $end === '') ? '' : $start . ' - ' . $end;
    }

    /** A date typed as YYYY-MM-DD or d/m/yyyy (design 5.4) as Y-m-d, else ''. */
    public static function parse_input_date($text) {
        if (!is_string($text)) {
            return '';
        }
        $text = trim($text);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $text, $m)) {
            $y = intval($m[1]);
            $mo = intval($m[2]);
            $d = intval($m[3]);
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $text, $m)) {
            $d = intval($m[1]);
            $mo = intval($m[2]);
            $y = intval($m[3]);
        } else {
            return '';
        }
        if (!checkdate($mo, $d, $y)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** ISO weekday (1 Monday … 7 Sunday) of a Y-m-d, 0 when it isn't one. */
    private static function weekday_of($ymd) {
        $parts = self::ymd_parts($ymd);
        return $parts === null ? 0 : intval(gmdate('N', gmmktime(0, 0, 0, $parts[1], $parts[2], $parts[0])));
    }

    /** A Y-m-d moved by whole days. */
    private static function add_days($ymd, $days) {
        $parts = self::ymd_parts($ymd);
        if ($parts === null) {
            return '';
        }
        return gmdate('Y-m-d', gmmktime(0, 0, 0, $parts[1], $parts[2] + intval($days), $parts[0]));
    }

    public static function day_name($day, $plural = false) {
        $names = array(1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday');
        $day = intval($day);
        if (!isset($names[$day])) {
            return '';
        }
        return $names[$day] . ($plural ? 's' : '');
    }

    /** "$30.00". */
    private static function money($price) {
        $cents = TeamOversight_Program_Setup::money_cents(is_string($price) ? $price : (string) $price);
        return '$' . ($cents === null ? (string) $price : TeamOversight_Program_Setup::cents_text($cents));
    }

    /** The named lock of one product's sessions (B5: at most 46 characters). */
    public static function lock_name($product_id) {
        global $wpdb;
        return self::LOCK_PREFIX . substr(md5((string) $wpdb->prefix), 0, 12) . '_' . intval($product_id);
    }

    /**
     * Take one product's session lock, waiting up to 10 seconds. True only
     * when MySQL answers 1: a 0 (held elsewhere) or a NULL (no named locks)
     * refuses, because two series runs side by side would make every
     * session twice.
     */
    public static function take_lock($product_id) {
        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', self::lock_name($product_id)));
        return $got !== null && intval($got) === 1;
    }

    public static function release_lock($product_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($product_id)));
    }

    /**
     * Take several products' locks in id order (so two callers can never
     * wait on each other). Returns the ids held, or null with every lock
     * already taken released.
     */
    public static function take_locks(array $product_ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        sort($ids);
        $held = array();
        foreach ($ids as $id) {
            if (!self::take_lock($id)) {
                foreach (array_reverse($held) as $done) {
                    self::release_lock($done);
                }
                return null;
            }
            $held[] = $id;
        }
        return $held;
    }

    public static function release_locks(array $held) {
        foreach (array_reverse($held) as $id) {
            self::release_lock($id);
        }
    }

    /** Drop product_map()'s request cache. Every writer of either program option calls it. */
    public static function flush_cache() {
        self::$map = null;
    }

    /**
     * Every child of a product, sessions or not, in any status (the Bin
     * included), with its session meta: ONE direct query (design 4.2 step
     * 3a). Returns variation id => array(id, status, menu_order, date, slot,
     * capacity, state, cancelled_on, cancel_reason, series, stock (int or
     * null), price, date_label, time_label). A row with no session date is
     * kept: the generator ignores it, the guard does not.
     */
    public static function children($product_id) {
        global $wpdb;
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return array();
        }
        $keys = array(
            'session_date' => self::META_DATE,
            'session_slot' => self::META_SLOT,
            'capacity' => self::META_CAPACITY,
            'state' => self::META_STATE,
            'cancelled_on' => self::META_CANCELLED_ON,
            'cancel_reason' => self::META_CANCEL_REASON,
            'series' => self::META_SERIES,
            'stock' => '_stock',
            'price' => '_regular_price',
            'date_label' => 'attribute_date',
            'time_label' => 'attribute_time',
        );
        $columns = array();
        foreach ($keys as $alias => $meta_key) {
            $columns[] = $wpdb->prepare('MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS ', $meta_key) . $alias;
        }
        $in = implode(',', array_map(function ($k) use ($wpdb) {
            return $wpdb->prepare('%s', $k);
        }, array_values($keys)));
        // Every column outside the GROUP BY is aggregated (MariaDB's ONLY_FULL_GROUP_BY).
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID AS id, MAX(p.post_status) AS status, MAX(p.menu_order) AS sort_order,
                " . implode(",\n                ", $columns) . "
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ({$in})
            WHERE p.post_parent = %d AND p.post_type = 'product_variation'
            GROUP BY p.ID
            ORDER BY sort_order, p.ID
        ", $product_id));
        $out = array();
        foreach ((array) $rows as $row) {
            $date = $row->session_date !== null ? trim((string) $row->session_date) : '';
            $stock = ($row->stock === null || trim((string) $row->stock) === '') ? null : intval(round((float) $row->stock));
            $out[intval($row->id)] = array(
                'id' => intval($row->id),
                'status' => (string) $row->status,
                'menu_order' => intval($row->sort_order),
                'date' => self::ymd_parts($date) !== null ? $date : '',
                'slot' => $row->session_slot !== null ? (string) $row->session_slot : '',
                'capacity' => intval($row->capacity),
                'state' => in_array((string) $row->state, array('closed', 'cancelled'), true) ? (string) $row->state : '',
                'cancelled_on' => $row->cancelled_on !== null ? (string) $row->cancelled_on : '',
                'cancel_reason' => $row->cancel_reason !== null ? (string) $row->cancel_reason : '',
                'series' => $row->series !== null ? (string) $row->series : '',
                'stock' => $stock,
                'price' => $row->price !== null ? (string) $row->price : '',
                'date_label' => $row->date_label !== null ? (string) $row->date_label : '',
                'time_label' => $row->time_label !== null ? (string) $row->time_label : '',
            );
        }
        return $out;
    }

    /** "2027-01-24|17:00": a session is (product, date, slot) (design 3.4). */
    public static function pair_key($date, $slot) {
        return $date . '|' . $slot;
    }

    /** pair => row for the sessions among children() (rows with a date and a slot). */
    public static function by_pair(array $children) {
        $out = array();
        foreach ($children as $row) {
            if ($row['date'] !== '' && $row['slot'] !== '') {
                $key = self::pair_key($row['date'], $row['slot']);
                if (!isset($out[$key])) {
                    $out[$key] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * product id => array(key, role, kind) over every program's products
     * (TeamOversight_Programs::program_products(): current, prepared, past).
     * Request cached; flush_cache() drops it.
     */
    public static function product_map() {
        if (self::$map === null) {
            $map = array();
            if (class_exists('TeamOversight_Programs')) {
                foreach (TeamOversight_Programs::get_programs() as $key => $program) {
                    if (!is_array($program)) {
                        continue;
                    }
                    foreach (TeamOversight_Programs::program_products((string) $key) as $product) {
                        $pid = intval($product['product_id']);
                        if ($pid > 0 && !isset($map[$pid])) {
                            $map[$pid] = array('key' => (string) $key, 'role' => (string) $product['role'], 'kind' => (string) $product['kind']);
                        }
                    }
                }
            }
            self::$map = $map;
        }
        return self::$map;
    }

    /** The product_map() entry of a product, or null. */
    public static function program_of_product($product_id) {
        $map = self::product_map();
        $product_id = intval($product_id);
        return isset($map[$product_id]) ? $map[$product_id] : null;
    }

    /** Design 4.2 step 1: a variable session product made for this program, not in the Bin. */
    public static function product_ok($key, $product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return false;
        }
        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            return false;
        }
        if (!has_term('variable', 'product_type', $product_id)) {
            return false;
        }
        return (string) get_post_meta($product_id, '_murvc_session_product', true) === '1'
            && (string) get_post_meta($product_id, '_murvc_program_key', true) === (string) $key;
    }

    /**
     * The product a series or a writer may act on: 'current' (the program's
     * product when it is dated) or 'prepared' (the setup's prepared
     * product). Read from the options on the server, never posted. 0 when none.
     */
    public static function target_product($key, $role) {
        $key = (string) $key;
        $program = TeamOversight_Programs::get_program($key);
        if (!is_array($program)) {
            return 0;
        }
        if ($role === 'current') {
            $pid = isset($program['product_id']) && is_scalar($program['product_id']) ? intval($program['product_id']) : 0;
            return ($pid > 0 && TeamOversight_Programs::current_kind($key) === 'dated' && self::product_ok($key, $pid)) ? $pid : 0;
        }
        if ($role === 'prepared') {
            $setup = TeamOversight_Program_Setup::get($key);
            $pid = intval($setup['prepared_product']);
            return ($pid > 0 && self::product_ok($key, $pid)) ? $pid : 0;
        }
        return 0;
    }

    /** Design 5.2: the Sessions tab shows only for a bookable program or one with a prepared product. */
    public static function tab_available($key) {
        return self::target_product($key, 'current') > 0 || self::target_product($key, 'prepared') > 0;
    }

    /**
     * Whether a program's dated sessions may be sold (B3): Bookings open is
     * ticked AND the Book page code (1.86.0) is installed. Until then no
     * dated session is purchasable, whatever the tick says.
     */
    public static function selling($key) {
        $setup = TeamOversight_Program_Setup::get((string) $key);
        return !empty($setup['bookings_open']) && class_exists('TeamOversight_Program_Booking');
    }

    /** B9: a session is past once its date is before today (site clock). */
    public static function is_past(array $row) {
        return $row['date'] !== '' && $row['date'] < current_time('Y-m-d');
    }

    /** The start of a session in the site's time zone, as a timestamp (0 when unreadable). */
    public static function start_ts($date, $slot) {
        if (self::ymd_parts($date) === null) {
            return 0;
        }
        $slot = (is_string($slot) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $slot)) ? $slot : '00:00';
        try {
            $when = new DateTimeImmutable($date . ' ' . $slot . ':00', wp_timezone());
            return $when->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Every order line of a product that names a variation, from the order
     * item tables and wc_order_stats (storage-agnostic, the roll's own
     * source). Orders only (parent_id 0), any status unless $statuses is
     * given; $variation_ids narrows it. One query.
     */
    public static function session_lines($product_id, $variation_ids = null, $statuses = null) {
        global $wpdb;
        $p = $wpdb->prefix;
        $args = array(intval($product_id));
        $var_filter = '';
        if (is_array($variation_ids)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $variation_ids))));
            if (empty($ids)) {
                return array();
            }
            $var_filter = 'AND var.meta_value IN (' . implode(',', array_fill(0, count($ids), '%s')) . ')';
            foreach ($ids as $id) {
                $args[] = (string) $id;
            }
        }
        $status_filter = '';
        if (is_array($statuses) && !empty($statuses)) {
            $status_filter = 'AND os.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
            foreach ($statuses as $status) {
                $args[] = (string) $status;
            }
        }
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_item_id,
                MAX(oi.order_id) AS order_id,
                MAX(var.meta_value) AS variation_id,
                MAX(qty.meta_value) AS qty,
                MAX(lt.meta_value) AS line_total,
                MAX(ltx.meta_value) AS line_tax,
                MAX(os.status) AS status
            FROM {$p}woocommerce_order_items oi
            JOIN {$p}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            JOIN {$p}woocommerce_order_itemmeta var
                ON var.order_item_id = oi.order_item_id AND var.meta_key = '_variation_id' {$var_filter}
            LEFT JOIN {$p}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
            LEFT JOIN {$p}woocommerce_order_itemmeta lt
                ON lt.order_item_id = oi.order_item_id AND lt.meta_key = '_line_total'
            LEFT JOIN {$p}woocommerce_order_itemmeta ltx
                ON ltx.order_item_id = oi.order_item_id AND ltx.meta_key = '_line_tax'
            JOIN {$p}wc_order_stats os ON os.order_id = oi.order_id
            WHERE oi.order_item_type = 'line_item' AND os.parent_id = 0 {$status_filter}
            GROUP BY oi.order_item_id
            ORDER BY oi.order_item_id
        ", $args));
        return (array) $rows;
    }

    /**
     * Design 5.3, B22: per variation, booked (places on paid lines after
     * refunds), awaiting (places on pending and on-hold orders) and
     * any_refund (a refund on any of its lines, any order status: Undo
     * cancel's test). One query plus one refund query.
     */
    public static function line_counts($product_id) {
        $lines = self::session_lines($product_id);
        $refunds = TeamOversight_Programs::refunds_for_items(wp_list_pluck($lines, 'order_item_id'));
        $counts = array();
        foreach ($lines as $line) {
            $vid = intval($line->variation_id);
            if ($vid <= 0) {
                continue;
            }
            if (!isset($counts[$vid])) {
                $counts[$vid] = array('booked' => 0, 'awaiting' => 0, 'any_refund' => false);
            }
            $item_id = intval($line->order_item_id);
            $qty = max(1, intval($line->qty));
            $refund = isset($refunds[$item_id]) ? $refunds[$item_id] : null;
            if ($refund !== null) {
                $counts[$vid]['any_refund'] = true;
            }
            if (in_array($line->status, array('wc-processing', 'wc-completed'), true)) {
                $state = TeamOversight_Programs::refund_state($qty, (float) $line->line_total + (float) $line->line_tax, $refund);
                $counts[$vid]['booked'] += TeamOversight_Programs::places_after_refunds($qty, $state, $refund);
            } elseif (in_array($line->status, array('wc-pending', 'wc-on-hold'), true)) {
                $counts[$vid]['awaiting'] += $qty;
            }
        }
        return $counts;
    }

    /** Whether any order (any status) has a line of this product. One query. */
    public static function has_order_lines($product_id) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod
                    ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
                WHERE oi.order_item_type = 'line_item'
            )
        ", intval($product_id)));
        return intval($found) === 1;
    }

    /** Whether a product has a child (any status) carrying a session date. One query. */
    public static function has_session_children($product_id) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare("
            SELECT EXISTS (
                SELECT 1 FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value <> ''
                WHERE p.post_parent = %d AND p.post_type = 'product_variation'
            )
        ", self::META_DATE, intval($product_id)));
        return intval($found) === 1;
    }

    /** Whether a product has any variation, in any status. One query. */
    public static function has_variation_children($product_id) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare("
            SELECT EXISTS (
                SELECT 1 FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'
            )
        ", intval($product_id)));
        return intval($found) === 1;
    }

    /**
     * B25: free text that looks like contact details (an email address, a
     * phone number, a web address): '' or the sentence to show. The cancel
     * reason uses it now, 1.86.0's player names later.
     */
    public static function contact_problem($text) {
        $text = (string) $text;
        if (preg_match('/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', $text) === 1
            || preg_match('/\+?\(?\d(?:[\s.\-()]*\d){8,}/', $text) === 1
            || preg_match('/https?:\/\/|www\./i', $text) === 1) {
            return 'No names or contact details, please.';
        }
        return '';
    }

    /** The program's name for log lines and headings, with " (2027, being prepared)" for its prepared product. */
    private static function program_label($key, $product_id = 0) {
        $program = TeamOversight_Programs::get_program($key);
        $name = (is_array($program) && isset($program['name']) && is_scalar($program['name']) && (string) $program['name'] !== '') ? (string) $program['name'] : (string) $key;
        $product_id = intval($product_id);
        if ($product_id > 0) {
            $setup = TeamOversight_Program_Setup::get($key);
            if (intval($setup['prepared_product']) === $product_id) {
                $year = intval(get_post_meta($product_id, '_murvc_program_year', true));
                $name .= ' (' . ($year > 0 ? $year . ', ' : '') . 'being prepared)';
            }
        }
        return $name;
    }

    private static function log($message) {
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::LOG_TYPE, $message);
        }
    }

    /** "Sun 14 Mar 2027 5:00pm - 7:00pm" for a children() row. */
    private static function row_label(array $row, $key = '') {
        $time = $row['time_label'];
        if ($time === '' && $key !== '') {
            foreach (TeamOversight_Program_Setup::get($key)['slots'] as $slot) {
                if ($slot['start'] === $row['slot']) {
                    $time = self::slot_label($slot);
                }
            }
        }
        $date = $row['date'] !== '' ? self::date_label($row['date']) : $row['date_label'];
        return trim($date . ' ' . $time);
    }

    /** A row's state in words (the preview and the refusals). */
    private static function state_word(array $row) {
        if ($row['status'] === 'trash') {
            return 'in the Bin';
        }
        if ($row['state'] === 'cancelled') {
            return 'cancelled';
        }
        if ($row['state'] === 'closed') {
            return 'closed';
        }
        if (self::is_past($row)) {
            return 'past';
        }
        return 'open';
    }

    // ------------------------------------------------------------------
    // The series generator (design 4.2, 5.4)
    // ------------------------------------------------------------------

    /** The input fields a series reads, unslashed and reduced to scalars (the transient keeps them, B26). */
    private static function clean_input(array $input) {
        $text = function ($value, $max = 40) {
            return is_scalar($value) ? substr(trim((string) $value), 0, $max) : '';
        };
        $out = array(
            'season' => $text(isset($input['season']) ? $input['season'] : ''),
            'day' => $text(isset($input['day']) ? $input['day'] : ''),
            'slots' => array(),
            'first' => $text(isset($input['first']) ? $input['first'] : ''),
            'last' => $text(isset($input['last']) ? $input['last'] : ''),
            'skips' => is_scalar(isset($input['skips']) ? $input['skips'] : '') ? substr((string) (isset($input['skips']) ? $input['skips'] : ''), 0, 2000) : '',
            'price' => $text(isset($input['price']) ? $input['price'] : ''),
            'places' => array(),
        );
        if (isset($input['slots']) && is_array($input['slots'])) {
            foreach ($input['slots'] as $start) {
                if (is_scalar($start) && count($out['slots']) < 10) {
                    $out['slots'][] = $text($start, 5);
                }
            }
        }
        if (isset($input['places']) && is_array($input['places'])) {
            foreach ($input['places'] as $start => $places) {
                if (is_scalar($places) && count($out['places']) < 10) {
                    $out['places'][$text((string) $start, 5)] = $text($places, 6);
                }
            }
        }
        return $out;
    }

    /**
     * Design 4.2's input rules. $input is unslashed (the form's shape:
     * season, day, slots[], first, last, skips (a date a line), price,
     * places[<start>]). Returns array($series, $errors); $series is null
     * when there is any error.
     */
    public static function check_series($key, array $input, $user_id) {
        $key = (string) $key;
        $input = self::clean_input($input);
        $program = TeamOversight_Programs::get_program($key);
        if (!is_array($program)) {
            return array(null, array('That program isn\'t configured.'));
        }
        if (!TeamOversight_Programs::can_write_sessions($key, $user_id)) {
            return array(null, array('You can\'t change this program\'s sessions.'));
        }
        if (!self::tab_available($key)) {
            return array(null, array('This program has no product to add sessions to yet.'));
        }
        $season = $input['season'];
        if (!in_array($season, array('current', 'prepared'), true)) {
            return array(null, array('Choose the season.'));
        }
        $pid = self::target_product($key, $season);
        if ($pid <= 0) {
            return array(null, array($season === 'prepared' ? 'There\'s no product being prepared.' : 'The product on sale isn\'t a session product.'));
        }
        $year = intval(get_post_meta($pid, '_murvc_program_year', true));
        if ($year < 2000) {
            return array(null, array('That product has no season year, so no sessions can be added to it.'));
        }

        $setup = TeamOversight_Program_Setup::get($key);
        $errors = array();

        // Day (D10): one of the committee's days.
        $day = ctype_digit($input['day']) ? intval($input['day']) : 0;
        if (!in_array($day, $setup['days'], true)) {
            $names = array();
            foreach ($setup['days'] as $allowed) {
                $names[] = self::day_name($allowed, true);
            }
            $errors[] = 'Choose a day the committee allows: ' . (empty($names) ? 'none is set yet' : implode(' or ', $names)) . '.';
        }

        // Slots: the program's visible slots, by start time.
        $visible = array();
        foreach ($setup['slots'] as $slot) {
            if (empty($slot['hidden'])) {
                $visible[$slot['start']] = $slot;
            }
        }
        $slots = array();
        if (empty($input['slots'])) {
            $errors[] = 'Tick at least one time slot.';
        } else {
            foreach ($input['slots'] as $start) {
                if (!isset($visible[$start])) {
                    $errors[] = 'That time slot isn\'t one of this program\'s.';
                    continue;
                }
                $slots[$start] = true;
            }
            $slots = array_keys($slots);
            sort($slots);
        }

        // First and last date: from today, inside the product's season year (D15).
        $today = current_time('Y-m-d');
        $first = self::parse_input_date($input['first']);
        $last = self::parse_input_date($input['last']);
        if ($first === '') {
            $errors[] = 'Enter the first date as d/m/yyyy.';
        }
        if ($last === '') {
            $errors[] = 'Enter the last date as d/m/yyyy.';
        }
        if ($first !== '' && $first < $today) {
            $errors[] = 'The first date can\'t be before today.';
        }
        if ($first !== '' && $last !== '' && $last < $first) {
            $errors[] = 'The last date can\'t be before the first.';
        }
        if (($first !== '' && intval(substr($first, 0, 4)) !== $year) || ($last !== '' && intval(substr($last, 0, 4)) !== $year)) {
            $errors[] = 'Both dates must be in ' . $year . ', the product\'s season.';
        }

        // Dates to skip: a date a line, repeats removed, at most MAX_SKIPS.
        $skips = array();
        foreach (preg_split('/[\r\n,]+/', (string) $input['skips']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parsed = self::parse_input_date($line);
            if ($parsed === '') {
                $errors[] = 'Dates to skip: ' . sanitize_text_field(substr($line, 0, 20)) . ' isn\'t a date.';
                continue;
            }
            $skips[$parsed] = true;
        }
        $skips = array_keys($skips);
        sort($skips);
        if (count($skips) > self::MAX_SKIPS) {
            $errors[] = 'At most ' . self::MAX_SKIPS . ' dates to skip.';
        }

        // Price (D9): one of the committee's prices, kept as the approved string.
        $price = '';
        $cents = TeamOversight_Program_Setup::money_cents(ltrim($input['price'], '$'));
        foreach ($setup['prices'] as $approved) {
            if ($cents !== null && TeamOversight_Program_Setup::money_cents($approved) === $cents) {
                $price = $approved;
            }
        }
        if ($price === '') {
            $errors[] = 'Choose one of the committee\'s prices.';
        }

        // Places (B8): one number per ticked slot; blank = that slot's places.
        $places = array();
        foreach ($slots as $start) {
            $typed = isset($input['places'][$start]) ? trim((string) $input['places'][$start]) : '';
            $n = $typed === '' ? intval($visible[$start]['places']) : (ctype_digit($typed) ? intval($typed) : 0);
            if ($n < 1 || $n > self::MAX_PLACES) {
                $errors[] = 'Places must be a whole number from 1 to ' . self::MAX_PLACES . '.';
                continue;
            }
            $places[$start] = $n;
        }

        if (!empty($errors)) {
            return array(null, array_values(array_unique($errors)));
        }

        $series = array(
            'v' => 1,
            'key' => $key,
            'season' => $season,
            'product_id' => $pid,
            'year' => $year,
            'day' => $day,
            'slots' => $slots,
            'first' => $first,
            'last' => $last,
            'skips' => $skips,
            'price' => $price,
            'places' => $places,
            'made_at' => current_time('mysql'),
        );
        $outcome = self::series_outcome($series, array());
        if (empty($outcome['dates'])) {
            return array(null, array('No ' . self::day_name($day) . ' falls between those dates' . (!empty($outcome['skipped']) ? ' once the dates to skip are taken out' : '') . '.'));
        }
        // B7: the series' pairs after the skips.
        if ($outcome['pairs'] > self::MAX_PER_SERIES) {
            return array(null, array('That\'s ' . $outcome['pairs'] . ' sessions. Add at most ' . self::MAX_PER_SERIES . ' at a time: split the series by date.'));
        }
        return array($series, array());
    }

    /**
     * The preview's outcome, worked out fresh each time (design 4.2):
     * dates (on the day, first to last, the skips taken out), skipped (as
     * asked), ignored (date => why), rows (per date, per slot: create, or
     * exists with its state), create (the pairs to make, date then slot),
     * exists (pair keys already there, any status, the Bin included) and
     * pairs (the B7 count). Reads children() once when not given.
     */
    public static function series_outcome(array $series, $children = null) {
        if ($children === null) {
            $children = self::children($series['product_id']);
        }
        $by_pair = self::by_pair($children);
        $all = array();
        $day = intval($series['day']);
        $d = $series['first'];
        if ($d !== '' && $series['last'] !== '' && $day >= 1) {
            while ($d !== '' && $d <= $series['last'] && self::weekday_of($d) !== $day) {
                $d = self::add_days($d, 1);
            }
            while ($d !== '' && $d <= $series['last'] && count($all) < 400) {
                $all[] = $d;
                $d = self::add_days($d, 7);
            }
        }
        $skip_set = array_flip($series['skips']);
        $dates = array();
        $skipped = array();
        foreach ($all as $date) {
            if (isset($skip_set[$date])) {
                $skipped[] = $date;
            } else {
                $dates[] = $date;
            }
        }
        $ignored = array();
        foreach ($series['skips'] as $skip) {
            if (in_array($skip, $all, true)) {
                continue;
            }
            $ignored[$skip] = ($skip < $series['first'] || $skip > $series['last']) ? 'outside the dates' : 'not a ' . self::day_name($day);
        }
        $rows = array();
        $create = array();
        $exists = array();
        foreach ($dates as $date) {
            $row = array('date' => $date, 'slots' => array());
            foreach ($series['slots'] as $start) {
                $pair = self::pair_key($date, $start);
                if (isset($by_pair[$pair])) {
                    $exists[] = $pair;
                    $row['slots'][$start] = array('outcome' => 'exists', 'state' => self::state_word($by_pair[$pair]));
                } else {
                    $create[] = array($date, $start);
                    $row['slots'][$start] = array('outcome' => 'create', 'state' => '');
                }
            }
            $rows[] = $row;
        }
        return array(
            'dates' => $dates,
            'skipped' => $skipped,
            'ignored' => $ignored,
            'rows' => $rows,
            'create' => $create,
            'exists' => $exists,
            'pairs' => count($dates) * count($series['slots']),
        );
    }

    /** Design 4.2: a hash of (product id, the sorted pairs to create, the price, the places per slot). */
    public static function fingerprint($product_id, array $create, $price, array $places) {
        $pairs = array();
        foreach ($create as $pair) {
            $pairs[] = self::pair_key($pair[0], $pair[1]);
        }
        sort($pairs);
        ksort($places);
        $per_slot = array();
        foreach ($places as $start => $n) {
            $per_slot[] = array((string) $start, intval($n));
        }
        return md5((string) wp_json_encode(array(intval($product_id), $pairs, TeamOversight_Program_Setup::money_cents((string) $price), $per_slot)));
    }

    public static function series_transient($user_id, $key) {
        return 'murvc_series_' . intval($user_id) . '_' . $key;
    }

    /**
     * Design 4.2 Preview: checks the inputs and keeps the series (with the
     * input it came from, B26) in the user's transient for 30 minutes.
     * Writes nothing to products. Returns array(ok, message).
     */
    public static function preview_series($key, array $input, $user_id) {
        list($series, $errors) = self::check_series($key, $input, $user_id);
        if (!empty($errors)) {
            return array('ok' => false, 'message' => implode(' ', $errors));
        }
        $series['input'] = self::clean_input($input);
        set_transient(self::series_transient($user_id, $key), $series, self::SERIES_TTL);
        return array('ok' => true, 'message' => '');
    }

    /** The user's stored series (with its input), or null. */
    public static function stored_series($key, $user_id) {
        $stored = get_transient(self::series_transient($user_id, $key));
        return (is_array($stored) && isset($stored['input']) && is_array($stored['input'])) ? $stored : null;
    }

    /**
     * Design 4.2 Create, its steps exactly: the checks (the stored input
     * checked again, B26), the product's session lock, the children read
     * once inside it, the fingerprint, then one variation per new pair, the
     * parent's options rebuilt from what exists, one log line. Returns
     * array(ok, message, step).
     */
    public static function create_series($key, $posted_fingerprint, $user_id) {
        $key = (string) $key;
        $user_id = intval($user_id);
        if ($user_id <= 0 || get_current_user_id() !== $user_id) {
            return array('ok' => false, 'message' => 'Log in to change sessions.', 'step' => '');
        }
        if (!TeamOversight_Programs::can_write_sessions($key, $user_id)) {
            return array('ok' => false, 'message' => 'You can\'t change this program\'s sessions.', 'step' => '');
        }
        $stored = self::stored_series($key, $user_id);
        if ($stored === null) {
            return array('ok' => false, 'message' => 'Your preview has expired (previews last 30 minutes). Fill in the series again.', 'step' => 'form');
        }
        list($series, $errors) = self::check_series($key, $stored['input'], $user_id);
        if (!empty($errors)) {
            return array('ok' => false, 'message' => implode(' ', $errors), 'step' => 'preview');
        }
        $pid = intval($series['product_id']);
        if (!self::take_lock($pid)) {
            return array('ok' => false, 'message' => self::LOCK_BUSY, 'step' => 'preview');
        }
        $made = 0;
        $outcome = null;
        try {
            // A Discard holds this lock while it bins the product: one that finished while this
            // waited would leave published sessions under a binned parent. Check the product again,
            // fresh, now that nothing else can change it.
            clean_post_cache($pid);
            if (!self::product_ok($key, $pid)) {
                return array('ok' => false, 'message' => 'That product is no longer taking sessions.', 'step' => 'preview');
            }
            $children = self::children($pid);
            $outcome = self::series_outcome($series, $children);
            $create = $outcome['create'];
            if (empty($create)) {
                return array('ok' => false, 'message' => 'Nothing new to create: every session in this series is already there.', 'step' => 'preview');
            }
            $expected = self::fingerprint($pid, $create, $series['price'], $series['places']);
            if (!is_string($posted_fingerprint) || !hash_equals($expected, $posted_fingerprint)) {
                return array('ok' => false, 'message' => 'The sessions changed since your preview (someone else may have added some, or the price changed). Check the new preview.', 'step' => 'preview');
            }

            $setup = TeamOversight_Program_Setup::get($key);
            $index = array();
            $by_start = array();
            foreach (array_values($setup['slots']) as $i => $slot) {
                $index[$slot['start']] = $i;
                $by_start[$slot['start']] = $slot;
            }
            $token = wp_generate_password(12, false);
            foreach ($create as $pair) {
                list($ymd, $start) = $pair;
                try {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($pid);
                    $variation->set_attributes(array('date' => self::date_label($ymd), 'time' => self::slot_label($by_start[$start])));
                    $variation->set_regular_price($series['price']);
                    $variation->set_virtual(true);
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity($series['places'][$start]);
                    $variation->set_backorders('no');
                    $variation->set_status('publish');
                    $variation->set_menu_order(intval(str_replace('-', '', $ymd)) * 10 + (isset($index[$start]) ? $index[$start] : 0));
                    $variation->update_meta_data(self::META_DATE, $ymd);
                    $variation->update_meta_data(self::META_SLOT, $start);
                    $variation->update_meta_data(self::META_CAPACITY, intval($series['places'][$start]));
                    $variation->update_meta_data(self::META_STATE, '');
                    $variation->update_meta_data(self::META_SERIES, $token);
                    if (intval($variation->save()) <= 0) {
                        break;
                    }
                } catch (Exception $e) {
                    // What was made stays; running it again makes the rest.
                    break;
                }
                $made++;
            }
            if ($made > 0) {
                self::rebuild_parent_options($pid, $key);
                if (class_exists('WC_Product_Variable')) {
                    WC_Product_Variable::sync($pid);
                }
                wc_delete_product_transients($pid);
            }
        } finally {
            self::release_lock($pid);
        }

        $total = count($outcome['create']);
        $dates = array();
        foreach ($outcome['create'] as $pair) {
            $dates[] = $pair[0];
        }
        $labels = array();
        $place_parts = array();
        $distinct_places = array_unique(array_values($series['places']));
        foreach ($series['slots'] as $start) {
            $label = isset($by_start[$start]) ? self::slot_label($by_start[$start]) : $start;
            $labels[] = $label;
            $place_parts[] = $label . ' ' . intval($series['places'][$start]) . ' places';
        }
        $what = $made === $total
            ? 'added ' . $made . ' session' . ($made === 1 ? '' : 's')
            : 'added ' . $made . ' of ' . $total . ' sessions (stopped part way: run it again to add the rest)';
        $message = self::program_label($key, $pid) . ': ' . $what . ', '
            . self::date_label(min($dates)) . ' to ' . self::date_label(max($dates)) . ', '
            . implode(', ', $labels) . ', ' . self::money($series['price']) . ', '
            . (count($distinct_places) === 1 ? intval(reset($distinct_places)) . ' places each' : implode(', ', $place_parts))
            . '. Already there: ' . count($outcome['exists']) . '. Skipped as asked: ' . count($outcome['skipped']) . '.';
        if ($made > 0) {
            self::log($message);
        }
        if ($made === $total) {
            delete_transient(self::series_transient($user_id, $key));
            return array('ok' => true, 'message' => 'Added ' . $made . ' session' . ($made === 1 ? '' : 's') . '.', 'step' => '');
        }
        return array('ok' => false, 'message' => 'Added ' . $made . ' of ' . $total . ' sessions; the rest could not be saved. Run it again to add the rest.', 'step' => 'preview');
    }

    /**
     * Design 4.2 step e (inside the lock): the parent's "date" options are
     * every child's label with a session date (any status, the Bin
     * included, so never trimmed), by date then slot; "time" is the
     * program's slot labels (hidden ones included) plus any a child
     * carries, in start order. Any other attribute is kept.
     */
    private static function rebuild_parent_options($pid, $key) {
        clean_post_cache($pid);
        $parent = wc_get_product($pid);
        if (!$parent) {
            return;
        }
        $rows = array();
        foreach (self::children($pid) as $row) {
            if ($row['date'] !== '') {
                $rows[] = $row;
            }
        }
        usort($rows, function ($a, $b) {
            $c = strcmp($a['date'], $b['date']);
            return $c !== 0 ? $c : strcmp($a['slot'], $b['slot']);
        });
        $dates = array();
        foreach ($rows as $row) {
            $label = $row['date_label'] !== '' ? $row['date_label'] : self::date_label($row['date']);
            if ($label !== '' && !in_array($label, $dates, true)) {
                $dates[] = $label;
            }
        }
        $times = array();
        foreach (TeamOversight_Program_Setup::get($key)['slots'] as $slot) {
            $label = self::slot_label($slot);
            if ($label !== '') {
                $times[$slot['start']] = $label;
            }
        }
        foreach ($rows as $row) {
            if ($row['time_label'] !== '' && !in_array($row['time_label'], $times, true)) {
                $times[$row['slot'] !== '' ? $row['slot'] : 'zz' . count($times)] = $row['time_label'];
            }
        }
        ksort($times);

        $date_attr = new WC_Product_Attribute();
        $date_attr->set_id(0);
        $date_attr->set_name('Date');
        $date_attr->set_options($dates);
        $date_attr->set_position(0);
        $date_attr->set_visible(true);
        $date_attr->set_variation(true);
        $time_attr = new WC_Product_Attribute();
        $time_attr->set_id(0);
        $time_attr->set_name('Time');
        $time_attr->set_options(array_values($times));
        $time_attr->set_position(1);
        $time_attr->set_visible(false);
        $time_attr->set_variation(true);
        $attributes = array('date' => $date_attr, 'time' => $time_attr);
        foreach ($parent->get_attributes() as $name => $attribute) {
            if ($name !== 'date' && $name !== 'time') {
                $attributes[$name] = $attribute;
            }
        }
        $parent->set_attributes($attributes);
        $parent->save();
    }

    // ------------------------------------------------------------------
    // Closing, cancelling and the other writers (design 4.3)
    // ------------------------------------------------------------------

    /**
     * Design 4.3, B30: before anything else, the poster may write the
     * program's sessions and the variation is a session (it carries a
     * session date) of the program's DATED current product or its prepared
     * product. Returns array(parent id, problem).
     */
    private static function writer_target($key, $variation_id, $user_id) {
        if (!TeamOversight_Programs::can_write_sessions($key, $user_id)) {
            return array(0, 'You can\'t change this program\'s sessions.');
        }
        $variation_id = intval($variation_id);
        $post = $variation_id > 0 ? get_post($variation_id) : null;
        if (!$post || $post->post_type !== 'product_variation') {
            return array(0, self::NOT_OURS);
        }
        $parent = intval(wp_get_post_parent_id($variation_id));
        $targets = array_filter(array(self::target_product($key, 'current'), self::target_product($key, 'prepared')));
        if ($parent <= 0 || !in_array($parent, $targets, true)) {
            return array(0, self::NOT_OURS);
        }
        if ((string) get_post_meta($variation_id, self::META_DATE, true) === '') {
            return array(0, self::NOT_OURS);
        }
        return array($parent, '');
    }

    /**
     * The writers' frame: writer_target(), the parent's session lock, the
     * row read again inside it, the change ($fn returns array(ok, message,
     * log)), WooCommerce's sync, the lock released, one log line.
     */
    private static function with_session($key, $variation_id, $user_id, $fn) {
        $key = (string) $key;
        list($parent, $problem) = self::writer_target($key, $variation_id, $user_id);
        if ($problem !== '') {
            return array('ok' => false, 'message' => $problem);
        }
        if (!self::take_lock($parent)) {
            return array('ok' => false, 'message' => self::LOCK_BUSY);
        }
        $result = array('ok' => false, 'message' => self::NOT_OURS, 'log' => '');
        try {
            $children = self::children($parent);
            $vid = intval($variation_id);
            if (isset($children[$vid]) && $children[$vid]['date'] !== '') {
                $row = $children[$vid];
                if ($row['status'] === 'trash') {
                    $result = array('ok' => false, 'message' => 'That session is in the Bin.', 'log' => '');
                } else {
                    $result = call_user_func($fn, $row, $parent);
                }
                if (!empty($result['ok'])) {
                    if (class_exists('WC_Product_Variable')) {
                        WC_Product_Variable::sync($parent);
                    }
                    wc_delete_product_transients($parent);
                }
            }
        } finally {
            self::release_lock($parent);
        }
        if (!empty($result['ok']) && !empty($result['log'])) {
            self::log(self::program_label($key, $parent) . ': ' . $result['log']);
        }
        return array('ok' => !empty($result['ok']), 'message' => (string) $result['message']);
    }

    /** A fresh variation object (the post and meta caches dropped first). */
    private static function fresh_variation($variation_id) {
        clean_post_cache($variation_id);
        $variation = wc_get_product($variation_id);
        return ($variation && $variation->is_type('variation')) ? $variation : null;
    }

    /** Close (design 4.3, D7): private, state closed; bookings stay on the roll. */
    public static function close_session($key, $variation_id, $user_id) {
        return self::with_session($key, $variation_id, $user_id, function ($row) use ($key) {
            if (self::is_past($row)) {
                return array('ok' => false, 'message' => 'That session has already happened.');
            }
            if ($row['state'] === 'closed') {
                return array('ok' => false, 'message' => 'That session is already closed.');
            }
            if ($row['state'] === 'cancelled') {
                return array('ok' => false, 'message' => 'It\'s cancelled: undo the cancel first.');
            }
            $variation = self::fresh_variation($row['id']);
            if (!$variation) {
                return array('ok' => false, 'message' => self::NOT_OURS);
            }
            $variation->set_status('private');
            $variation->update_meta_data(self::META_STATE, 'closed');
            $variation->save();
            $label = self::row_label($row, $key);
            return array('ok' => true, 'message' => 'Closed ' . $label . '.', 'log' => 'closed ' . $label . '.');
        });
    }

    /** Reopen: published, state empty. */
    public static function reopen_session($key, $variation_id, $user_id) {
        return self::with_session($key, $variation_id, $user_id, function ($row) use ($key) {
            if (self::is_past($row)) {
                return array('ok' => false, 'message' => 'That session has already happened.');
            }
            if ($row['state'] === 'cancelled') {
                return array('ok' => false, 'message' => 'It\'s cancelled: undo the cancel first.');
            }
            if ($row['state'] !== 'closed') {
                return array('ok' => false, 'message' => 'It\'s already open.');
            }
            $variation = self::fresh_variation($row['id']);
            if (!$variation) {
                return array('ok' => false, 'message' => self::NOT_OURS);
            }
            $variation->set_status('publish');
            $variation->update_meta_data(self::META_STATE, '');
            $variation->save();
            $label = self::row_label($row, $key);
            return array('ok' => true, 'message' => 'Reopened ' . $label . '.', 'log' => 'reopened ' . $label . '.');
        });
    }

    /**
     * Cancel (design 4.3, D13): private, state cancelled, today's date and
     * the reason. $reason is unslashed text; it is sanitised here.
     */
    public static function cancel_session($key, $variation_id, $reason, $user_id) {
        $reason = sanitize_text_field((string) $reason);
        return self::with_session($key, $variation_id, $user_id, function ($row) use ($key, $reason) {
            if ($row['state'] === 'cancelled') {
                return array('ok' => false, 'message' => 'That session is already cancelled.');
            }
            if (current_time('Y-m-d') > self::add_days($row['date'], self::CANCEL_LATE_DAYS)) {
                return array('ok' => false, 'message' => 'It\'s more than ' . self::CANCEL_LATE_DAYS . ' days since that session, so it can\'t be cancelled now.');
            }
            $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
            if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
                return array('ok' => false, 'message' => 'Give a reason of ' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters.');
            }
            $contact = self::contact_problem($reason);
            if ($contact !== '') {
                return array('ok' => false, 'message' => $contact);
            }
            $variation = self::fresh_variation($row['id']);
            if (!$variation) {
                return array('ok' => false, 'message' => self::NOT_OURS);
            }
            $variation->set_status('private');
            $variation->update_meta_data(self::META_STATE, 'cancelled');
            $variation->update_meta_data(self::META_CANCELLED_ON, current_time('Y-m-d'));
            $variation->update_meta_data(self::META_CANCEL_REASON, $reason);
            $variation->save();
            $label = self::row_label($row, $key);
            return array('ok' => true, 'message' => 'Cancelled ' . $label . '. Its paid bookings are listed on To refund.', 'log' => 'cancelled ' . $label . ' (reason: ' . $reason . ').');
        });
    }

    /** Undo cancel (B10): state closed, the cancel date and reason deleted, stays private. */
    public static function undo_cancel($key, $variation_id, $user_id) {
        return self::with_session($key, $variation_id, $user_id, function ($row, $parent) use ($key) {
            if ($row['state'] !== 'cancelled') {
                return array('ok' => false, 'message' => 'That session isn\'t cancelled.');
            }
            $counts = self::line_counts($parent);
            if (!empty($counts[$row['id']]['any_refund'])) {
                return array('ok' => false, 'message' => 'A refund has been made on this session, so the cancel can\'t be undone.');
            }
            $variation = self::fresh_variation($row['id']);
            if (!$variation) {
                return array('ok' => false, 'message' => self::NOT_OURS);
            }
            $variation->set_status('private');
            $variation->update_meta_data(self::META_STATE, 'closed');
            $variation->delete_meta_data(self::META_CANCELLED_ON);
            $variation->delete_meta_data(self::META_CANCEL_REASON);
            $variation->save();
            $label = self::row_label($row, $key);
            return array('ok' => true, 'message' => 'Undid the cancel of ' . $label . '. It is closed: reopen it to sell it again.', 'log' => 'undid the cancel of ' . $label . ' (now closed).');
        });
    }

    /**
     * Change places (design 4.3): capacity C to N, the stock moved by the
     * difference through wc_update_product_stock(), never set. A decrease
     * is at most the stock less places held by checkouts in progress.
     */
    public static function change_places($key, $variation_id, $places, $user_id) {
        $places = is_int($places) ? $places : (is_scalar($places) && ctype_digit(trim((string) $places)) ? intval(trim((string) $places)) : 0);
        return self::with_session($key, $variation_id, $user_id, function ($row, $parent) use ($key, $places) {
            if ($places < 1 || $places > self::MAX_PLACES) {
                return array('ok' => false, 'message' => 'Places must be a whole number from 1 to ' . self::MAX_PLACES . '.');
            }
            if (self::is_past($row)) {
                return array('ok' => false, 'message' => 'That session has already happened.');
            }
            if ($row['state'] === 'cancelled') {
                return array('ok' => false, 'message' => 'It\'s cancelled, so its places can\'t change.');
            }
            $variation = self::fresh_variation($row['id']);
            if (!$variation) {
                return array('ok' => false, 'message' => self::NOT_OURS);
            }
            if ($variation->get_manage_stock('edit') !== true) {
                return array('ok' => false, 'message' => 'This session\'s places aren\'t managed in the shop.');
            }
            $capacity = intval($row['capacity']);
            if ($capacity < 1) {
                return array('ok' => false, 'message' => 'This session has no recorded places, so they can\'t be changed here.');
            }
            if ($places === $capacity) {
                return array('ok' => false, 'message' => 'It already has ' . $capacity . ' places.');
            }
            $counts = self::line_counts($parent);
            $paid = isset($counts[$row['id']]) ? intval($counts[$row['id']]['booked']) : 0;
            if ($places < $paid) {
                return array('ok' => false, 'message' => $paid . ' place' . ($paid === 1 ? ' is' : 's are') . ' paid for, so it can\'t go below ' . $paid . '.');
            }
            $too_far = function ($held, $free) {
                return array('ok' => false, 'message' => $held > 0
                    ? $held . ' place' . ($held === 1 ? ' is' : 's are') . ' held by checkouts in progress, so it can go down by at most ' . $free . ' now.'
                    : 'Only ' . $free . ' place' . ($free === 1 ? ' is' : 's are') . ' left, so it can go down by at most ' . $free . ' now.');
            };
            if ($places > $capacity) {
                wc_update_product_stock($variation, $places - $capacity, 'increase');
            } else {
                $down = $capacity - $places;
                $stock = intval($variation->get_stock_quantity());
                $held = function_exists('wc_get_held_stock_quantity') ? intval(wc_get_held_stock_quantity($variation)) : 0;
                $free = max(0, $stock - $held);
                if ($down > $free) {
                    return $too_far($held, $free);
                }
                wc_update_product_stock($variation, $down, 'decrease');
                // A checkout reserves and takes stock without this lock, so one that ran between the
                // check and the decrease could leave fewer places than it holds (or below 0): read
                // again and, if so, put the places back and refuse.
                $after = self::fresh_variation($row['id']);
                if ($after) {
                    $stock_now = intval($after->get_stock_quantity());
                    $held_now = function_exists('wc_get_held_stock_quantity') ? intval(wc_get_held_stock_quantity($after)) : 0;
                    if ($stock_now < max(0, $held_now)) {
                        wc_update_product_stock($after, $down, 'increase');
                        return $too_far($held_now, max(0, $stock_now + $down - $held_now));
                    }
                }
            }
            $variation = self::fresh_variation($row['id']);
            if ($variation) {
                $variation->update_meta_data(self::META_CAPACITY, $places);
                $variation->save();
            }
            $label = self::row_label($row, $key);
            return array('ok' => true, 'message' => 'Places for ' . $label . ': ' . $capacity . ' -> ' . $places . '.', 'log' => 'places for ' . $label . ' ' . $capacity . ' -> ' . $places . '.');
        });
    }

    /**
     * Change price (design 4.3, D9, B11): committee only. The regular
     * price of each picked open session from today on becomes one of the
     * approved prices; any other pick refuses the whole change. Paid lines
     * keep what they paid.
     */
    public static function change_price($key, array $variation_ids, $price, $user_id) {
        $key = (string) $key;
        if (!user_can(intval($user_id), 'manage_options')) {
            return array('ok' => false, 'message' => 'Only the committee can change prices.');
        }
        $setup = TeamOversight_Program_Setup::get($key);
        $cents = TeamOversight_Program_Setup::money_cents(is_scalar($price) ? ltrim(trim((string) $price), '$') : '');
        $approved = '';
        foreach ($setup['prices'] as $candidate) {
            if ($cents !== null && TeamOversight_Program_Setup::money_cents($candidate) === $cents) {
                $approved = $candidate;
            }
        }
        if ($approved === '') {
            return array('ok' => false, 'message' => 'Choose one of the committee\'s prices.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $variation_ids))));
        if (empty($ids)) {
            return array('ok' => false, 'message' => 'Tick at least one session.');
        }
        $by_parent = array();
        foreach ($ids as $vid) {
            list($parent, $problem) = self::writer_target($key, $vid, $user_id);
            if ($problem !== '') {
                return array('ok' => false, 'message' => $problem);
            }
            $by_parent[$parent][] = $vid;
        }
        $held = self::take_locks(array_keys($by_parent));
        if ($held === null) {
            return array('ok' => false, 'message' => self::LOCK_BUSY);
        }
        $changed = array();
        $old_prices = array();
        try {
            $rows = array();
            foreach ($by_parent as $parent => $vids) {
                $children = self::children($parent);
                foreach ($vids as $vid) {
                    if (!isset($children[$vid])) {
                        return array('ok' => false, 'message' => self::NOT_OURS);
                    }
                    $row = $children[$vid];
                    if ($row['state'] !== '' || $row['status'] !== 'publish' || self::is_past($row)) {
                        return array('ok' => false, 'message' => 'Only open sessions from today on can change price: ' . self::row_label($row, $key) . ' is ' . self::state_word($row) . '.');
                    }
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                $variation = self::fresh_variation($row['id']);
                if (!$variation) {
                    continue;
                }
                $old_prices[] = (string) $variation->get_regular_price('edit');
                $variation->set_regular_price($approved);
                $variation->save();
                $changed[] = $row['date'];
            }
            foreach (array_keys($by_parent) as $parent) {
                if (class_exists('WC_Product_Variable')) {
                    WC_Product_Variable::sync($parent);
                }
                wc_delete_product_transients($parent);
            }
        } finally {
            self::release_locks($held);
        }
        if (empty($changed)) {
            return array('ok' => false, 'message' => 'Nothing was changed.');
        }
        $old = array_count_values($old_prices);
        arsort($old);
        $old_text = count($old) === 1 ? self::money((string) key($old)) : 'various';
        $n = count($changed);
        self::log(self::program_label($key) . ': price of ' . $n . ' session' . ($n === 1 ? '' : 's') . ' ' . $old_text . ' -> ' . self::money($approved)
            . ' (' . self::date_label(min($changed)) . ($n > 1 ? ' to ' . self::date_label(max($changed)) : '') . ').');
        return array('ok' => true, 'message' => 'The price of ' . $n . ' session' . ($n === 1 ? ' is' : 's is') . ' now ' . self::money($approved) . '.');
    }

    // ------------------------------------------------------------------
    // Posts (design 5.3, 9)
    // ------------------------------------------------------------------

    public static function flash_name($user_id) {
        return 'murvc_sessions_flash_' . intval($user_id);
    }

    /**
     * Every Sessions action. $post is the request array as WordPress gives
     * it (slashed). Returns array(handled, ok, message, key, step) and sets
     * the user's flash. No price, total or product id is read from a post:
     * the only ids posted are variation ids, which writer_target() checks.
     */
    public static function process_post(array $post, $user_id) {
        $result = array('handled' => false, 'ok' => false, 'message' => '', 'key' => '', 'step' => '');
        $action = isset($post[self::ACTION_FIELD]) && is_scalar($post[self::ACTION_FIELD]) ? sanitize_key($post[self::ACTION_FIELD]) : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return $result;
        }
        $result['handled'] = true;
        $key = isset($post['program']) && is_scalar($post['program']) ? sanitize_key($post['program']) : '';
        $user_id = intval($user_id);
        $input = null;
        if ($key === '' || !is_array(TeamOversight_Programs::get_program($key))) {
            $result['message'] = 'That program isn\'t configured.';
        } elseif ($user_id <= 0 || get_current_user_id() !== $user_id) {
            $result['message'] = 'Log in to change sessions.';
        } elseif (!isset($post[self::NONCE_FIELD]) || !is_scalar($post[self::NONCE_FIELD])
            || !wp_verify_nonce((string) $post[self::NONCE_FIELD], self::NONCE_PREFIX . $key)) {
            $result['message'] = 'This page has expired. Reload it and try again.';
        } else {
            $result['key'] = $key;
            $vid = isset($post['v']) && is_scalar($post['v']) ? absint($post['v']) : 0;
            switch ($action) {
                case 'preview':
                    $input = wp_unslash($post);
                    $done = self::preview_series($key, is_array($input) ? $input : array(), $user_id);
                    $result['step'] = $done['ok'] ? 'preview' : 'form';
                    break;
                case 'create':
                    $fingerprint = isset($post['fingerprint']) && is_scalar($post['fingerprint']) ? sanitize_text_field(wp_unslash((string) $post['fingerprint'])) : '';
                    $done = self::create_series($key, $fingerprint, $user_id);
                    $result['step'] = $done['step'];
                    break;
                case 'close':
                    $done = self::close_session($key, $vid, $user_id);
                    break;
                case 'reopen':
                    $done = self::reopen_session($key, $vid, $user_id);
                    break;
                case 'undo_cancel':
                    $done = self::undo_cancel($key, $vid, $user_id);
                    break;
                case 'cancel':
                    $reason = isset($post['reason']) && is_scalar($post['reason']) ? wp_unslash((string) $post['reason']) : '';
                    $done = self::cancel_session($key, $vid, $reason, $user_id);
                    break;
                case 'places':
                    $places = isset($post['places']) && is_scalar($post['places']) ? trim((string) $post['places']) : '';
                    $done = self::change_places($key, $vid, $places, $user_id);
                    break;
                case 'price':
                    $ids = (isset($post['v']) && is_array($post['v'])) ? array_map('absint', array_filter($post['v'], 'is_scalar')) : array();
                    $price = isset($post['price']) && is_scalar($post['price']) ? wp_unslash((string) $post['price']) : '';
                    $done = self::change_price($key, $ids, $price, $user_id);
                    break;
                default:
                    $done = array('ok' => false, 'message' => '');
            }
            $result['ok'] = !empty($done['ok']);
            $result['message'] = (string) $done['message'];
        }
        if ($user_id > 0) {
            $flash = array('ok' => $result['ok'], 'message' => $result['message'], 'key' => $result['key']);
            if ($action === 'preview' && !$result['ok'] && is_array($input)) {
                // So the form comes back filled in after a refusal.
                $flash['input'] = self::clean_input($input);
            }
            set_transient(self::flash_name($user_id), $flash, self::FLASH_TTL);
        }
        return $result;
    }

    /** template_redirect: a portal page's Sessions post, then back to the page (Post/Redirect/Get). */
    public static function handle_post() {
        if (!isset($_POST[self::ACTION_FIELD]) || !is_user_logged_in() || !is_singular()) {
            return;
        }
        $result = self::process_post($_POST, get_current_user_id());
        if (!$result['handled']) {
            return;
        }
        $url = get_permalink(get_queried_object_id());
        if (!$url) {
            $url = home_url('/');
        }
        $args = array('tab' => 'sessions');
        if ($result['key'] !== '') {
            $args['program'] = $result['key'];
        }
        if ($result['step'] !== '') {
            $args['step'] = $result['step'];
        }
        wp_safe_redirect(add_query_arg($args, $url));
        exit;
    }

    /** admin-post murvc_program_sessions: the admin Sessions tab (the return address is built from the key). */
    public static function handle_admin_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        $result = self::process_post($_POST, get_current_user_id());
        $key = $result['key'];
        if ($key === '' && isset($_POST['program']) && is_scalar($_POST['program'])) {
            $candidate = sanitize_key($_POST['program']);
            $key = is_array(TeamOversight_Programs::get_program($candidate)) ? $candidate : '';
        }
        if ($key === '') {
            wp_safe_redirect(admin_url('admin.php?page=club-programs-settings'));
            exit;
        }
        $url = admin_url('admin.php?page=club-programs-' . $key . '&tab=sessions');
        if ($result['step'] !== '') {
            $url = add_query_arg('step', $result['step'], $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    // ------------------------------------------------------------------
    // Purchasable filters and the daily sweep (design 4.1, B2, B3)
    // ------------------------------------------------------------------

    /**
     * woocommerce_variation_is_purchasable. Acts only on (a) a program's
     * DATED current product (bookings open, the session's state, the
     * cutoff), (b) prepared and past products (never) and (c) a session
     * product or session that belongs to no program (never). A legacy or
     * month current product is returned untouched (B2), so nothing changes
     * until its switch.
     */
    public static function variation_purchasable($purchasable, $variation) {
        if (!$purchasable || !($variation instanceof WC_Product)) {
            return $purchasable;
        }
        $parent = intval($variation->get_parent_id());
        $entry = self::program_of_product($parent);
        if ($entry === null) {
            if ((string) get_post_meta($parent, '_murvc_session_product', true) === '1'
                || (string) $variation->get_meta(self::META_DATE, true) !== '') {
                return false;
            }
            return $purchasable;
        }
        if ($entry['role'] !== 'current') {
            return false;
        }
        if ($entry['kind'] !== 'dated') {
            return $purchasable;
        }
        if (!self::selling($entry['key'])) {
            return false;
        }
        $state = (string) $variation->get_meta(self::META_STATE, true);
        if ($state === 'closed' || $state === 'cancelled') {
            return false;
        }
        $date = (string) $variation->get_meta(self::META_DATE, true);
        if ($date === '') {
            return false;
        }
        $start = self::start_ts($date, (string) $variation->get_meta(self::META_SLOT, true));
        $setup = TeamOversight_Program_Setup::get($entry['key']);
        if ($start <= 0 || time() >= $start - intval($setup['close_hours']) * HOUR_IN_SECONDS) {
            return false;
        }
        return $purchasable;
    }

    /** woocommerce_is_purchasable: a past or prepared program product, or an orphan session product, never. */
    public static function product_purchasable($purchasable, $product) {
        if (!$purchasable || !($product instanceof WC_Product) || $product->is_type('variation')) {
            return $purchasable;
        }
        $entry = self::program_of_product($product->get_id());
        if ($entry === null) {
            return (string) $product->get_meta('_murvc_session_product', true) === '1' ? false : $purchasable;
        }
        return $entry['role'] === 'current' ? $purchasable : false;
    }

    /**
     * The daily sweep (design 4.1): sessions of a dated current product
     * whose date is before today become private, their state left alone (a
     * past session is not closed). A refused lock waits for tomorrow. No log
     * line (B20).
     */
    public static function sweep() {
        if (!function_exists('wc_get_product')) {
            return;
        }
        $today = current_time('Y-m-d');
        foreach (array_keys(TeamOversight_Programs::get_programs()) as $key) {
            $pid = self::target_product((string) $key, 'current');
            if ($pid <= 0 || !self::take_lock($pid)) {
                continue;
            }
            try {
                $changed = 0;
                foreach (self::children($pid) as $row) {
                    if ($row['status'] === 'publish' && $row['date'] !== '' && $row['date'] < $today) {
                        $variation = self::fresh_variation($row['id']);
                        if ($variation) {
                            $variation->set_status('private');
                            $variation->save();
                            $changed++;
                        }
                    }
                }
                if ($changed > 0) {
                    if (class_exists('WC_Product_Variable')) {
                        WC_Product_Variable::sync($pid);
                    }
                    wc_delete_product_transients($pid);
                }
            } finally {
                self::release_lock($pid);
            }
        }
    }

    // ------------------------------------------------------------------
    // The deletion guard (design 4.4, B4, B21)
    // ------------------------------------------------------------------

    /**
     * '' (allow) or what is refused. A session (a variation of a program
     * product, or one carrying a session date) is never binned or deleted;
     * a program product with order lines or variations is never binned or
     * deleted, nor is any product holding sessions. Discard may bin its
     * prepared product and that product's own variations (never delete
     * them). Dormant until the switch: a program whose current product is
     * not dated (the 2026 products) is left alone, apart from anything
     * carrying a session date.
     */
    private static function guard_reason($post, $permanent) {
        if (!($post instanceof WP_Post)) {
            return '';
        }
        if ($post->post_type === 'product_variation') {
            $parent = intval($post->post_parent);
            if (!$permanent && self::$discarding > 0 && $parent === intval(self::$discarding)) {
                return '';
            }
            $has_date = (string) get_post_meta($post->ID, self::META_DATE, true) !== '';
            $entry = self::program_of_product($parent);
            if ($entry !== null) {
                if ($entry['role'] === 'current' && $entry['kind'] !== 'dated' && !$has_date) {
                    return '';
                }
                return 'session';
            }
            return $has_date ? 'session' : '';
        }
        if ($post->post_type !== 'product') {
            return '';
        }
        $pid = intval($post->ID);
        if (!$permanent && self::$discarding > 0 && $pid === intval(self::$discarding)) {
            return '';
        }
        $entry = self::program_of_product($pid);
        if ($entry !== null && !($entry['role'] === 'current' && $entry['kind'] !== 'dated')) {
            if (self::has_order_lines($pid)) {
                return 'product_lines';
            }
            if (self::has_variation_children($pid)) {
                return 'product_children';
            }
        }
        return self::has_session_children($pid) ? 'product_sessions' : '';
    }

    /** pre_delete_post: a non-null $check is passed on; a refusal returns false (wp_delete_post() stops). */
    public static function guard_delete($check, $post, $force_delete = false) {
        if ($check !== null) {
            return $check;
        }
        // A product or variation is never trashed by wp_delete_post(): this is a real delete.
        $reason = self::guard_reason($post, true);
        if ($reason === '') {
            return $check;
        }
        self::refuse($post, $reason, true);
        return false;
    }

    /** pre_trash_post: the same for the Bin. */
    public static function guard_trash($check, $post) {
        if ($check !== null) {
            return $check;
        }
        $reason = self::guard_reason($post, false);
        if ($reason === '') {
            return $check;
        }
        self::refuse($post, $reason, false);
        return false;
    }

    /**
     * Record a refusal: the notice for the user and one program_guard line
     * at shutdown. WordPress's daily emptying of the Bin (wp_scheduled_delete)
     * is refused quietly, or a discarded product would log every day.
     */
    private static function refuse($post, $reason, $permanent) {
        if (function_exists('doing_action') && doing_action('wp_scheduled_delete')) {
            return;
        }
        self::$guard_refusals[] = array(
            'id' => intval($post->ID),
            'parent' => intval($post->post_parent),
            'type' => $reason,
            'permanent' => (bool) $permanent,
        );
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            set_transient('murvc_prog_guard_' . $user_id, $reason === 'session' ? 'session' : 'product', 300);
        }
        if (!self::$guard_hooked) {
            self::$guard_hooked = true;
            add_action('shutdown', array(__CLASS__, 'write_guard_log'));
        }
    }

    /**
     * B21: one program_guard line for what this request refused (a "Delete
     * all variations" is dozens of refusals), then the list is emptied.
     * Writes nothing when nothing was refused. Public so a test can write it
     * before its own clean-up.
     */
    public static function write_guard_log() {
        if (empty(self::$guard_refusals)) {
            return;
        }
        $refusals = self::$guard_refusals;
        self::$guard_refusals = array();
        $verb = 'binning';
        foreach ($refusals as $refusal) {
            if ($refusal['permanent']) {
                $verb = 'deleting';
            }
        }
        $sessions = array();
        $products = array();
        foreach ($refusals as $refusal) {
            if ($refusal['type'] === 'session') {
                $sessions[$refusal['parent']][$refusal['id']] = true;
            } else {
                $products[$refusal['id']] = $refusal['type'];
            }
        }
        $parts = array();
        foreach ($sessions as $parent => $ids) {
            $ids = array_keys($ids);
            $children = self::children($parent);
            $first = isset($children[$ids[0]]) ? self::row_label($children[$ids[0]]) : '#' . $ids[0];
            $title = $parent > 0 ? wp_strip_all_tags(get_the_title($parent)) : 'no product';
            $n = count($ids);
            $parts[] = 'Refused ' . $verb . ' ' . ($n === 1 ? 'a session' : $n . ' sessions') . ' of ' . $title
                . ' (' . $first . ($n > 1 ? ' and ' . ($n - 1) . ' more' : '') . '). Close ' . ($n === 1 ? 'it' : 'them') . ' instead.';
        }
        $why = array(
            'product_lines' => 'it has bookings',
            'product_children' => 'its sessions or variations stay with it',
            'product_sessions' => 'it holds sessions',
        );
        foreach ($products as $id => $type) {
            $parts[] = 'Refused ' . $verb . ' ' . wp_strip_all_tags(get_the_title($id)) . ', a Club Programs product (' . (isset($why[$type]) ? $why[$type] : 'kept') . ').';
        }
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::GUARD_LOG_TYPE, implode(' ', $parts));
        }
    }

    /** admin_notices: the guard's notice, once. */
    public static function guard_notice() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }
        $kind = get_transient('murvc_prog_guard_' . $user_id);
        if ($kind === false) {
            return;
        }
        delete_transient('murvc_prog_guard_' . $user_id);
        $text = $kind === 'session'
            ? 'This is a Club Programs session. Close it on the Sessions tab instead.'
            : 'This is a Club Programs product that holds sessions or bookings, so it stays. Close its sessions on the Sessions tab instead.';
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    // ------------------------------------------------------------------
    // The Sessions tab (design 5.2 to 5.4)
    // ------------------------------------------------------------------

    /** The tab's own look: the coach portal's control set (CLAUDE.md), scoped to the tab. */
    private static function styles() {
        return '<style>
        .murvc-sessions-tab.coach-portal { --cp-navy: #1d3d6e; --cp-navy-dark: #142c50; --cp-navy-tint: #eef2f8; --cp-border: #c3c8d0; --cp-text: #3c434a; --cp-muted: #6b7280; --cp-radius: 4px; --cp-control-h: 34px; max-width: 980px; color: var(--cp-text); }
        .murvc-sessions-tab .button { display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; min-height: var(--cp-control-h); margin: 0; padding: 6px 12px; font-family: inherit; font-size: 13px; font-weight: 600; line-height: 1.2; letter-spacing: normal; text-transform: none; text-decoration: none; border: 1px solid var(--cp-navy); border-radius: var(--cp-radius); box-shadow: none; cursor: pointer; background: var(--cp-navy); color: #fff; }
        .murvc-sessions-tab .button:hover, .murvc-sessions-tab .button:focus { background: var(--cp-navy-dark); border-color: var(--cp-navy-dark); color: #fff; }
        .murvc-sessions-tab .coach-btn-secondary { background: #fff; color: var(--cp-navy); }
        .murvc-sessions-tab .coach-btn-secondary:hover, .murvc-sessions-tab .coach-btn-secondary:focus { background: var(--cp-navy-tint); border-color: var(--cp-navy); color: var(--cp-navy); }
        .murvc-sessions-tab table.murvc-st { border-collapse: collapse; width: 100%; margin: 6px 0 14px; font-size: 13px; }
        .murvc-sessions-tab table.murvc-st th, .murvc-sessions-tab table.murvc-st td { border-bottom: 1px solid #e2e4e7; padding: 6px 8px; text-align: left; vertical-align: middle; }
        .murvc-sessions-tab table.murvc-st th { background: #f6f7f7; font-weight: 600; }
        .murvc-sessions-tab .murvc-st-actions form { display: inline-flex; gap: 4px; align-items: center; margin: 0 4px 4px 0; }
        .murvc-sessions-tab .murvc-st-actions input[type=number] { width: 64px; min-height: var(--cp-control-h); }
        .murvc-sessions-tab .murvc-st-note { color: var(--cp-muted); font-size: 12px; }
        .murvc-sessions-tab .murvc-st-flash { padding: 8px 12px; border-left: 4px solid #2b7a2f; background: #f5fbf5; margin: 8px 0 12px; }
        .murvc-sessions-tab .murvc-st-flash.is-error { border-left-color: #b32d2e; background: #fdf2f2; }
        .murvc-sessions-tab .murvc-st-box { border: 1px solid var(--cp-border); border-radius: 6px; padding: 12px 14px; margin: 0 0 16px; background: #fff; }
        .murvc-sessions-tab .murvc-st-field { margin: 0 0 12px; }
        .murvc-sessions-tab .murvc-st-field label.murvc-st-label { display: block; font-weight: 600; margin-bottom: 4px; }
        .murvc-sessions-tab .murvc-st-state-cancelled { color: #8a1f11; font-weight: 600; }
        .murvc-sessions-tab .murvc-st-state-closed { color: #8a4b00; font-weight: 600; }
        .murvc-sessions-tab .murvc-st-create { color: #1a7a2e; font-weight: 600; }
        .murvc-sessions-tab textarea { width: 100%; max-width: 320px; }
        </style>';
    }

    private static function nonce_field($key) {
        return wp_nonce_field(self::NONCE_PREFIX . $key, self::NONCE_FIELD, false, false);
    }

    /** A form's opening tags: the portal posts to its own page, the admin page to admin-post. */
    private static function form_open($key, $context, $base_url, $action, $attrs = '') {
        $target = $context === 'admin' ? admin_url('admin-post.php') : $base_url;
        $out = '<form method="post" action="' . esc_url($target) . '"' . $attrs . '>';
        if ($context === 'admin') {
            $out .= '<input type="hidden" name="action" value="murvc_program_sessions">';
        }
        $out .= '<input type="hidden" name="' . esc_attr(self::ACTION_FIELD) . '" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="program" value="' . esc_attr($key) . '">'
            . self::nonce_field($key);
        return $out;
    }

    /** The user's flash for this program, taken (read once). */
    private static function take_flash($key, $user_id) {
        if ($user_id <= 0) {
            return null;
        }
        $flash = get_transient(self::flash_name($user_id));
        if (!is_array($flash)) {
            return null;
        }
        delete_transient(self::flash_name($user_id));
        if (isset($flash['key']) && $flash['key'] !== '' && $flash['key'] !== $key) {
            return null;
        }
        return $flash;
    }

    /**
     * The Sessions tab. $context is 'portal' or 'admin'; $base_url is the
     * page with program and tab=sessions. The caller has checked the viewer
     * (the portal's user_can_view(), the admin page's manage_options). It
     * writes nothing except taking the user's flash.
     */
    public static function render_tab($key, $context, $base_url) {
        $key = (string) $key;
        $context = $context === 'admin' ? 'admin' : 'portal';
        $user_id = get_current_user_id();
        $program = TeamOversight_Programs::get_program($key);
        $flash = self::take_flash($key, $user_id);
        $writer = TeamOversight_Programs::can_write_sessions($key, $user_id);
        $step = isset($_GET['step']) && is_scalar($_GET['step']) ? sanitize_key($_GET['step']) : '';
        $confirm = isset($_GET['confirm']) && is_scalar($_GET['confirm']) ? sanitize_key($_GET['confirm']) : '';

        $out = self::styles() . '<div class="murvc-sessions-tab coach-portal">';
        if (is_array($flash) && isset($flash['message']) && $flash['message'] !== '') {
            $out .= '<div class="murvc-st-flash' . (empty($flash['ok']) ? ' is-error' : '') . '" role="status">' . esc_html($flash['message']) . '</div>';
        }
        if (!is_array($program) || !self::tab_available($key)) {
            return $out . '<p>This program has no session product yet. The committee prepares one on the Setup tab.</p></div>';
        }
        if (!$writer) {
            $out .= '<p class="murvc-st-note">Only this program\'s own supervisors can change sessions.</p>';
        }
        $list_url = remove_query_arg(array('step', 'confirm', 'v', 'season'), $base_url);
        if ($writer && $step === 'form') {
            $out .= self::render_series_form($key, $context, $list_url, $flash);
        } elseif ($writer && $step === 'preview') {
            $out .= self::render_preview($key, $context, $list_url, $flash);
        } elseif ($writer && in_array($confirm, array('close', 'cancel'), true)) {
            $vid = isset($_GET['v']) && is_scalar($_GET['v']) ? absint($_GET['v']) : 0;
            $out .= self::render_confirm($key, $context, $list_url, $confirm, $vid);
        } else {
            $out .= self::render_list($key, $context, $list_url, $writer);
        }
        return $out . '</div>';
    }

    /** The list (design 5.3): the product on sale and, while one exists, the one being prepared. */
    private static function render_list($key, $context, $base_url, $writer) {
        $current = self::target_product($key, 'current');
        $prepared = self::target_product($key, 'prepared');
        $out = '';
        $parts = array();
        if ($prepared > 0) {
            if ($current > 0) {
                $parts[] = array($current, 'On sale now (' . intval(get_post_meta($current, '_murvc_program_year', true)) . ')', '');
            } else {
                $program = TeamOversight_Programs::get_program($key);
                $pid = is_array($program) && isset($program['product_id']) ? intval($program['product_id']) : 0;
                $out .= '<h3>On sale now</h3><p>' . esc_html($pid > 0 ? get_the_title($pid) : 'The current product') . ', sold as before.</p>';
            }
            $parts[] = array($prepared, 'Being prepared for ' . intval(get_post_meta($prepared, '_murvc_program_year', true)), 'These go on sale when the committee switches over.');
        } else {
            $parts[] = array($current, 'On sale now (' . intval(get_post_meta($current, '_murvc_program_year', true)) . ')', '');
        }
        $show_past = !empty($_GET['show_past']);
        $admin = $context === 'admin' && current_user_can('manage_options');
        $any_price_box = false;
        foreach ($parts as $part) {
            list($pid, $heading, $note) = $part;
            $html = self::render_part($key, $context, $base_url, $writer, $pid, $heading, $note, $show_past, $admin);
            $out .= $html[0];
            $any_price_box = $any_price_box || $html[1];
        }
        if ($admin && $any_price_box) {
            $setup = TeamOversight_Program_Setup::get($key);
            $out .= self::form_open($key, $context, $base_url, 'price', ' id="murvc-price-form" class="murvc-st-box"')
                . '<p><strong>Change price</strong> (committee only): tick open sessions from today on above, then choose one of the approved prices. Paid bookings keep what they paid.</p>'
                . '<p><label>New price <select name="price">';
            foreach ($setup['prices'] as $price) {
                $out .= '<option value="' . esc_attr($price) . '">' . esc_html(self::money($price)) . '</option>';
            }
            $out .= '</select></label> <button type="submit" class="button">Change price of the ticked sessions</button></p></form>';
        }
        $hold = intval(get_option('woocommerce_hold_stock_minutes', 60));
        $out .= '<p class="murvc-st-note">Places held by checkouts in progress come back within ' . ($hold > 0 ? $hold : 60) . ' minutes if not paid. A new unpaid order can take a few minutes to show here.</p>';
        return $out;
    }

    /** One product's sessions: array(html, has open future rows the price box can tick). */
    private static function render_part($key, $context, $base_url, $writer, $pid, $heading, $note, $show_past, $admin) {
        $children = self::children($pid);
        $sessions = array();
        foreach ($children as $row) {
            if ($row['date'] !== '' && $row['status'] !== 'trash') {
                $sessions[] = $row;
            }
        }
        usort($sessions, function ($a, $b) {
            $c = strcmp($a['date'], $b['date']);
            return $c !== 0 ? $c : strcmp($a['slot'], $b['slot']);
        });
        $prices = array();
        foreach ($sessions as $row) {
            $prices[] = $row['price'];
        }
        $common = '';
        if (!empty($prices)) {
            $counted = array_count_values($prices);
            arsort($counted);
            $common = (string) key($counted);
        }
        $counts = self::line_counts($pid);
        $today = current_time('Y-m-d');
        $selling = self::selling($key) && self::target_product($key, 'current') === intval($pid);

        $out = '<h3>' . esc_html($heading) . ': ' . esc_html(get_the_title($pid)) . '</h3>';
        if ($note !== '') {
            $out .= '<p class="murvc-st-note">' . esc_html($note) . '</p>';
        }
        $out .= '<p>' . ($common !== '' ? 'Each session: <strong>' . esc_html(self::money($common)) . '</strong>. ' : '');
        if ($writer) {
            $add = add_query_arg(array('step' => 'form', 'season' => ($pid === self::target_product($key, 'prepared') ? 'prepared' : 'current')), $base_url);
            $out .= '<a class="button" href="' . esc_url($add) . '">Add sessions</a>';
        }
        $out .= '</p>';
        if (empty($sessions)) {
            return array($out . '<p>No sessions yet.</p>', false);
        }

        $upcoming = array();
        $past = array();
        foreach ($sessions as $row) {
            if ($row['date'] >= $today) {
                $upcoming[] = $row;
            } else {
                $past[] = $row;
            }
        }
        $any_price_row = false;
        $columns = '<tr>' . ($admin ? '<th><span class="screen-reader-text">Pick</span></th>' : '') . '<th>Date</th><th>Time</th><th>Places</th><th>Booked</th><th>Awaiting payment</th><th>Left</th><th>State</th>' . ($writer ? '<th></th>' : '') . '</tr>';
        $groups = array();
        foreach ($upcoming as $row) {
            $groups[substr($row['date'], 0, 7)][] = $row;
        }
        if ($show_past) {
            foreach ($past as $row) {
                $groups['past ' . substr($row['date'], 0, 7)][] = $row;
            }
        }
        foreach ($groups as $month => $rows) {
            $is_past_group = strpos($month, 'past ') === 0;
            $ym = $is_past_group ? substr($month, 5) : $month;
            $out .= '<h4>' . esc_html(gmdate('F Y', gmmktime(0, 0, 0, intval(substr($ym, 5, 2)), 1, intval(substr($ym, 0, 4))))) . ($is_past_group ? ' (past)' : '') . '</h4>';
            $out .= '<table class="murvc-st"><thead>' . $columns . '</thead><tbody>';
            foreach ($rows as $row) {
                list($row_html, $pickable) = self::render_row($key, $context, $base_url, $writer, $admin, $row, $counts, $common, $selling);
                $out .= $row_html;
                $any_price_row = $any_price_row || $pickable;
            }
            $out .= '</tbody></table>';
        }
        if (empty($upcoming)) {
            $out .= '<p>No sessions to come.</p>';
        }
        if (!$show_past && !empty($past)) {
            $out .= '<p><a href="' . esc_url(add_query_arg('show_past', '1', $base_url)) . '">Show past sessions (' . count($past) . ')</a></p>';
        }
        return array($out, $any_price_row);
    }

    /** One row of the list: array(html, pickable for the price box). */
    private static function render_row($key, $context, $base_url, $writer, $admin, array $row, array $counts, $common, $selling) {
        $past = self::is_past($row);
        $booked = isset($counts[$row['id']]) ? intval($counts[$row['id']]['booked']) : 0;
        $awaiting = isset($counts[$row['id']]) ? intval($counts[$row['id']]['awaiting']) : 0;
        $open = ($row['state'] === '' && $row['status'] === 'publish' && !$past);
        $left = '—';
        if ($open && $row['stock'] !== null) {
            $held = 0;
            if (function_exists('wc_get_held_stock_quantity')) {
                $variation = wc_get_product($row['id']);
                if ($variation) {
                    $held = intval(wc_get_held_stock_quantity($variation));
                }
            }
            $left = (string) max(0, intval($row['stock']) - $held);
        }
        if ($row['state'] === 'cancelled') {
            $state = '<span class="murvc-st-state-cancelled">Cancelled</span>';
        } elseif ($row['state'] === 'closed') {
            $state = '<span class="murvc-st-state-closed">Closed</span>';
        } elseif ($past) {
            $state = 'Past';
        } elseif ($row['stock'] !== null && intval($row['stock']) <= 0) {
            $state = 'Full';
        } else {
            $state = $selling ? 'On sale' : 'Not on sale yet';
        }
        $time = $row['time_label'] !== '' ? $row['time_label'] : self::time_label($row['slot']);
        $price_note = ($row['price'] !== '' && $row['price'] !== $common) ? ' <span class="murvc-st-note">' . esc_html(self::money($row['price'])) . '</span>' : '';
        $html = '<tr>';
        if ($admin) {
            $html .= '<td>' . ($open ? '<input type="checkbox" name="v[]" value="' . intval($row['id']) . '" form="murvc-price-form" aria-label="' . esc_attr('Pick ' . self::row_label($row)) . '">' : '') . '</td>';
        }
        $html .= '<td>' . esc_html(self::date_label($row['date'])) . $price_note . '</td>'
            . '<td>' . esc_html($time) . '</td>'
            . '<td>' . intval($row['capacity']) . '</td>'
            . '<td>' . $booked . '</td>'
            . '<td>' . $awaiting . '</td>'
            . '<td>' . esc_html($left) . '</td>'
            . '<td>' . $state . '</td>';
        if ($writer) {
            $html .= '<td class="murvc-st-actions">' . self::row_actions($key, $context, $base_url, $row) . '</td>';
        }
        return array($html . '</tr>', $open);
    }

    /** A row's actions (design 4.3's rules): small POST forms, Close and Cancel through a confirm screen. */
    private static function row_actions($key, $context, $base_url, array $row) {
        $past = self::is_past($row);
        $out = '';
        $places_form = function () use ($key, $context, $base_url, $row) {
            return self::form_open($key, $context, $base_url, 'places')
                . '<input type="hidden" name="v" value="' . intval($row['id']) . '">'
                . '<label><span class="screen-reader-text">Places</span><input type="number" name="places" min="1" max="' . self::MAX_PLACES . '" value="' . intval($row['capacity']) . '"></label>'
                . '<button type="submit" class="button coach-btn-secondary">Save places</button></form>';
        };
        $confirm_link = function ($what, $label) use ($base_url, $row) {
            return '<a class="button coach-btn-secondary" href="' . esc_url(add_query_arg(array('confirm' => $what, 'v' => intval($row['id'])), $base_url)) . '">' . esc_html($label) . '</a> ';
        };
        if ($row['state'] === 'cancelled') {
            $out .= self::form_open($key, $context, $base_url, 'undo_cancel')
                . '<input type="hidden" name="v" value="' . intval($row['id']) . '">'
                . '<button type="submit" class="button coach-btn-secondary">Undo cancel</button></form>';
        } elseif ($past) {
            if (current_time('Y-m-d') <= self::add_days($row['date'], self::CANCEL_LATE_DAYS)) {
                $out .= $confirm_link('cancel', 'Cancel');
            }
        } elseif ($row['state'] === 'closed') {
            $out .= $places_form()
                . self::form_open($key, $context, $base_url, 'reopen')
                . '<input type="hidden" name="v" value="' . intval($row['id']) . '">'
                . '<button type="submit" class="button coach-btn-secondary">Reopen</button></form>'
                . $confirm_link('cancel', 'Cancel');
        } else {
            $out .= $places_form() . $confirm_link('close', 'Close') . $confirm_link('cancel', 'Cancel');
        }
        return $out;
    }

    /** Close and Cancel ask first, on a second screen (design 5.3). */
    private static function render_confirm($key, $context, $base_url, $what, $variation_id) {
        $back = '<a class="button coach-btn-secondary" href="' . esc_url($base_url) . '">Back</a>';
        $row = null;
        foreach (array(self::target_product($key, 'current'), self::target_product($key, 'prepared')) as $pid) {
            if ($pid > 0) {
                $children = self::children($pid);
                if (isset($children[$variation_id]) && $children[$variation_id]['date'] !== '') {
                    $row = $children[$variation_id];
                }
            }
        }
        if ($row === null) {
            return '<p>' . esc_html(self::NOT_OURS) . '</p><p>' . $back . '</p>';
        }
        $label = self::row_label($row, $key);
        $out = '<div class="murvc-st-box">';
        if ($what === 'close') {
            $out .= '<p><strong>Close ' . esc_html($label) . '?</strong></p>'
                . '<p>Close this session? People already booked stay booked. To call it off, use Cancel instead.</p>'
                . self::form_open($key, $context, $base_url, 'close')
                . '<input type="hidden" name="v" value="' . intval($row['id']) . '">'
                . '<p><button type="submit" class="button">Close it</button> ' . $back . '</p></form>';
        } else {
            $counts = self::line_counts(wp_get_post_parent_id($row['id']));
            $booked = isset($counts[$row['id']]) ? intval($counts[$row['id']]['booked']) : 0;
            $out .= '<p><strong>Cancel ' . esc_html($label) . '?</strong></p>'
                . '<p>' . ($booked > 0 ? 'Its ' . $booked . ' paid booking' . ($booked === 1 ? '' : 's') . ' will need refunds, which the committee makes.' : 'Nobody has paid for it yet.') . '</p>'
                . self::form_open($key, $context, $base_url, 'cancel')
                . '<input type="hidden" name="v" value="' . intval($row['id']) . '">'
                . '<p class="murvc-st-field"><label class="murvc-st-label" for="murvc-st-reason">Reason</label>'
                . '<input type="text" id="murvc-st-reason" name="reason" required minlength="' . self::REASON_MIN . '" maxlength="' . self::REASON_MAX . '" size="50"> '
                . '<span class="murvc-st-note">' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters. No names, please.</span></p>'
                . '<p><button type="submit" class="button">Cancel the session</button> ' . $back . '</p></form>';
        }
        return $out . '</div>';
    }

    /** The series form (design 5.4), filled from a refused preview, else the stored series, else defaults. */
    private static function render_series_form($key, $context, $base_url, $flash) {
        $setup = TeamOversight_Program_Setup::get($key);
        $user_id = get_current_user_id();
        $current = self::target_product($key, 'current');
        $prepared = self::target_product($key, 'prepared');
        $visible = array();
        foreach ($setup['slots'] as $slot) {
            if (empty($slot['hidden'])) {
                $visible[$slot['start']] = $slot;
            }
        }
        $values = array(
            'season' => $prepared > 0 ? 'prepared' : 'current',
            'day' => !empty($setup['days']) ? (string) $setup['days'][0] : '',
            'slots' => array_keys($visible),
            'first' => '',
            'last' => '',
            'skips' => '',
            'price' => !empty($setup['prices']) ? $setup['prices'][0] : '',
            'places' => array(),
        );
        if (isset($_GET['season']) && in_array($_GET['season'], array('current', 'prepared'), true)) {
            $values['season'] = (string) $_GET['season'];
        }
        $prefill = null;
        if (is_array($flash) && isset($flash['input']) && is_array($flash['input'])) {
            $prefill = $flash['input'];
        } else {
            $stored = self::stored_series($key, $user_id);
            if ($stored !== null) {
                $prefill = $stored['input'];
            }
        }
        if ($prefill !== null) {
            foreach (array('season', 'day', 'first', 'last', 'skips', 'price') as $field) {
                if (isset($prefill[$field]) && is_string($prefill[$field]) && $prefill[$field] !== '') {
                    $values[$field] = $prefill[$field];
                }
            }
            if (isset($prefill['slots']) && is_array($prefill['slots']) && !empty($prefill['slots'])) {
                $values['slots'] = $prefill['slots'];
            }
            if (isset($prefill['places']) && is_array($prefill['places'])) {
                $values['places'] = $prefill['places'];
            }
        }
        foreach (array('first', 'last') as $field) {
            $parsed = self::parse_input_date($values[$field]);
            if ($parsed !== '') {
                $values[$field] = $parsed;
            }
        }

        $out = '<h3>Add sessions</h3>';
        if (empty($visible) || empty($setup['days']) || empty($setup['prices'])) {
            return $out . '<p>The committee hasn\'t set this program\'s days, time slots and prices yet (the Setup tab).</p><p><a class="button coach-btn-secondary" href="' . esc_url($base_url) . '">Back to the sessions</a></p>';
        }
        $out .= self::form_open($key, $context, $base_url, 'preview', ' class="murvc-st-box" id="murvc-series-form"');
        // Season: shown only when both products take sessions.
        if ($current > 0 && $prepared > 0) {
            $out .= '<div class="murvc-st-field"><span class="murvc-st-label">Season</span>'
                . '<label><input type="radio" name="season" value="prepared"' . checked($values['season'], 'prepared', false) . '> ' . intval(get_post_meta($prepared, '_murvc_program_year', true)) . ' (being prepared)</label> '
                . '<label><input type="radio" name="season" value="current"' . checked($values['season'], 'current', false) . '> ' . intval(get_post_meta($current, '_murvc_program_year', true)) . ' (on sale)</label></div>';
        } else {
            $out .= '<input type="hidden" name="season" value="' . ($prepared > 0 ? 'prepared' : 'current') . '">';
        }
        // Day: text for one allowed day, else a select.
        if (count($setup['days']) === 1) {
            $out .= '<div class="murvc-st-field"><span class="murvc-st-label">Day</span>' . esc_html(self::day_name($setup['days'][0], true))
                . '<input type="hidden" name="day" value="' . intval($setup['days'][0]) . '"></div>';
        } else {
            $out .= '<div class="murvc-st-field"><label class="murvc-st-label" for="murvc-st-day">Day</label><select id="murvc-st-day" name="day">';
            foreach ($setup['days'] as $day) {
                $out .= '<option value="' . intval($day) . '"' . selected((string) $values['day'], (string) $day, false) . '>' . esc_html(self::day_name($day, true)) . '</option>';
            }
            $out .= '</select></div>';
        }
        // Time slots and their places.
        $out .= '<div class="murvc-st-field"><span class="murvc-st-label">Time slots and places per session</span>';
        foreach ($visible as $start => $slot) {
            $places = isset($values['places'][$start]) && $values['places'][$start] !== '' ? $values['places'][$start] : (string) intval($slot['places']);
            $out .= '<p><label><input type="checkbox" name="slots[]" value="' . esc_attr($start) . '"' . (in_array($start, $values['slots'], true) ? ' checked' : '') . '> ' . esc_html(self::slot_label($slot)) . '</label> '
                . '<label>places <input type="number" name="places[' . esc_attr($start) . ']" min="1" max="' . self::MAX_PLACES . '" value="' . esc_attr($places) . '" style="width: 80px;"></label></p>';
        }
        $out .= '</div>';
        // Dates.
        $out .= '<div class="murvc-st-field"><label class="murvc-st-label" for="murvc-st-first">First date</label><input type="date" id="murvc-st-first" name="first" value="' . esc_attr($values['first']) . '"></div>'
            . '<div class="murvc-st-field"><label class="murvc-st-label" for="murvc-st-last">Last date</label><input type="date" id="murvc-st-last" name="last" value="' . esc_attr($values['last']) . '"></div>'
            . '<div class="murvc-st-field"><label class="murvc-st-label" for="murvc-st-skips">Dates to skip</label>'
            . '<textarea id="murvc-st-skips" name="skips" rows="4" placeholder="d/m/yyyy, one a line">' . esc_textarea($values['skips']) . '</textarea><br>'
            . '<input type="date" id="murvc-st-skip-pick" aria-label="Pick a date to skip"> <button type="button" class="button coach-btn-secondary" id="murvc-st-skip-add">Add</button>'
            . ' <span class="murvc-st-note">Up to ' . self::MAX_SKIPS . '. A date that isn\'t in the series is listed as ignored.</span></div>';
        // Price: text for one approved price, else a select.
        if (count($setup['prices']) === 1) {
            $out .= '<div class="murvc-st-field"><span class="murvc-st-label">Price</span>' . esc_html(self::money($setup['prices'][0]))
                . '<input type="hidden" name="price" value="' . esc_attr($setup['prices'][0]) . '"></div>';
        } else {
            $out .= '<div class="murvc-st-field"><label class="murvc-st-label" for="murvc-st-price">Price</label><select id="murvc-st-price" name="price">';
            foreach ($setup['prices'] as $price) {
                $out .= '<option value="' . esc_attr($price) . '"' . selected($values['price'], $price, false) . '>' . esc_html(self::money($price)) . '</option>';
            }
            $out .= '</select></div>';
        }
        $out .= '<p><button type="submit" class="button">Preview</button> <a class="button coach-btn-secondary" href="' . esc_url($base_url) . '">Back to the sessions</a></p></form>';
        $out .= '<script>(function () {
            var add = document.getElementById("murvc-st-skip-add");
            var pick = document.getElementById("murvc-st-skip-pick");
            var box = document.getElementById("murvc-st-skips");
            if (!add || !pick || !box) { return; }
            add.addEventListener("click", function () {
                var v = pick.value;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { return; }
                var parts = v.split("-");
                var text = parseInt(parts[2], 10) + "/" + parseInt(parts[1], 10) + "/" + parts[0];
                box.value = (box.value.replace(/\s+$/, "") + (box.value.trim() === "" ? "" : "\n") + text);
                pick.value = "";
            });
        })();</script>';
        return $out;
    }

    /** The preview (design 4.2, 5.4), worked out fresh from the stored input. */
    private static function render_preview($key, $context, $base_url, $flash) {
        $user_id = get_current_user_id();
        $form_url = add_query_arg('step', 'form', $base_url);
        $stored = self::stored_series($key, $user_id);
        if ($stored === null) {
            return '<p>Your preview has expired (previews last 30 minutes). Fill in the series again.</p>'
                . self::render_series_form($key, $context, $base_url, $flash);
        }
        list($series, $errors) = self::check_series($key, $stored['input'], $user_id);
        if (!empty($errors)) {
            $out = '<div class="murvc-st-flash is-error"><ul style="margin: 0 0 0 18px;">';
            foreach ($errors as $error) {
                $out .= '<li>' . esc_html($error) . '</li>';
            }
            return $out . '</ul></div><p><a class="button coach-btn-secondary" href="' . esc_url($form_url) . '">Change the series</a></p>';
        }
        $outcome = self::series_outcome($series);
        $fingerprint = self::fingerprint($series['product_id'], $outcome['create'], $series['price'], $series['places']);
        $setup = TeamOversight_Program_Setup::get($key);
        $by_start = array();
        foreach ($setup['slots'] as $slot) {
            $by_start[$slot['start']] = $slot;
        }
        $labels = array();
        $place_parts = array();
        foreach ($series['slots'] as $start) {
            $label = isset($by_start[$start]) ? self::slot_label($by_start[$start]) : $start;
            $labels[] = $label;
            $place_parts[] = $label . ' ' . intval($series['places'][$start]) . ' places';
        }
        $distinct = array_unique(array_values($series['places']));
        $days_in = count($outcome['dates']) + count($outcome['skipped']);
        $all = array_merge($outcome['dates'], $outcome['skipped']);
        sort($all);
        $headline = get_the_title($series['product_id']) . ': ' . $days_in . ' ' . self::day_name($series['day'], $days_in !== 1)
            . ' from ' . self::date_label(reset($all)) . ' to ' . self::date_label(end($all)) . ', ' . implode(', ', $labels) . ', '
            . (count($distinct) === 1 ? intval(reset($distinct)) . ' places' : implode(', ', $place_parts)) . ', ' . self::money($series['price']);

        $out = '<div class="murvc-st-box" id="murvc-series-preview" data-fingerprint="' . esc_attr($fingerprint) . '">';
        $out .= '<p><strong>' . esc_html($headline) . '</strong></p>';
        $skipped_short = array();
        foreach ($outcome['skipped'] as $date) {
            $skipped_short[] = self::short_date($date);
        }
        $out .= '<table class="murvc-st" style="max-width: 560px;"><tbody>'
            . '<tr><th>Will be created</th><td>' . count($outcome['create']) . '</td></tr>'
            . '<tr><th>Already there, left as they are</th><td>' . count($outcome['exists']) . '</td></tr>'
            . '<tr><th>Skipped as you asked</th><td>' . count($outcome['skipped']) . (!empty($skipped_short) ? ' (' . esc_html(implode(', ', $skipped_short)) . ')' : '') . '</td></tr>';
        if (empty($outcome['ignored'])) {
            $out .= '<tr><th>Ignored</th><td>0</td></tr>';
        } else {
            foreach ($outcome['ignored'] as $date => $why) {
                $out .= '<tr><th>Ignored: ' . esc_html($why) . '</th><td>' . esc_html(self::date_label($date)) . '</td></tr>';
            }
        }
        $out .= '</tbody></table>';

        $out .= '<table class="murvc-st"><thead><tr><th>Date</th>';
        foreach ($labels as $label) {
            $out .= '<th>' . esc_html($label) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($outcome['rows'] as $row) {
            $out .= '<tr><td>' . esc_html(self::date_label($row['date'])) . '</td>';
            foreach ($series['slots'] as $start) {
                $cell = $row['slots'][$start];
                $out .= $cell['outcome'] === 'create'
                    ? '<td class="murvc-st-create">Will be created</td>'
                    : '<td>Already there (' . esc_html($cell['state']) . ')</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';

        $n = count($outcome['create']);
        if ($n > 0) {
            $out .= self::form_open($key, $context, $base_url, 'create', ' class="murvc-series-create"')
                . '<input type="hidden" name="fingerprint" value="' . esc_attr($fingerprint) . '">'
                . '<p><button type="submit" class="button">Create ' . $n . ' session' . ($n === 1 ? '' : 's') . '</button> '
                . '<a class="button coach-btn-secondary" href="' . esc_url($form_url) . '">Change the series</a></p></form>';
        } else {
            $out .= '<p>Nothing new to create: every session in this series is already there.</p>'
                . '<p><a class="button coach-btn-secondary" href="' . esc_url($form_url) . '">Change the series</a> '
                . '<a class="button coach-btn-secondary" href="' . esc_url($base_url) . '">Back to the sessions</a></p>';
        }
        return $out . '</div>';
    }

    // ------------------------------------------------------------------
    // To refund (design 6.6; admin only)
    // ------------------------------------------------------------------

    /**
     * Each cancelled session of the program's dated products, and the
     * orders with paid lines on it not yet fully refunded: the amount still
     * to refund incl. GST (grouped per order, so one refund covers
     * siblings), part refunds, and any refund tied to no line. Worked out
     * live on every view. No names (the order screen has them).
     */
    public static function render_refund_tab($key) {
        $key = (string) $key;
        if (!current_user_can('manage_options')) {
            return '';
        }
        $out = self::styles() . '<div class="murvc-sessions-tab coach-portal">';
        $out .= '<p>Refund from the order screen, by quantity, never only in Square. Square allows at most 20 refunds on one payment and none after a year.</p>';
        $any = false;
        foreach (TeamOversight_Programs::program_products($key) as $product) {
            if ($product['kind'] !== 'dated') {
                continue;
            }
            $pid = intval($product['product_id']);
            $cancelled = array();
            foreach (self::children($pid) as $row) {
                if ($row['date'] !== '' && $row['state'] === 'cancelled') {
                    $cancelled[$row['id']] = $row;
                }
            }
            if (empty($cancelled)) {
                continue;
            }
            uasort($cancelled, function ($a, $b) {
                $c = strcmp($a['date'], $b['date']);
                return $c !== 0 ? $c : strcmp($a['slot'], $b['slot']);
            });
            $lines = self::session_lines($pid, array_keys($cancelled), array('wc-processing', 'wc-completed'));
            $refunds = TeamOversight_Programs::refunds_for_items(wp_list_pluck($lines, 'order_item_id'));
            $by_session = array();
            foreach ($lines as $line) {
                $item_id = intval($line->order_item_id);
                $qty = max(1, intval($line->qty));
                $total = (float) $line->line_total + (float) $line->line_tax;
                $refund = isset($refunds[$item_id]) ? $refunds[$item_id] : null;
                $state = TeamOversight_Programs::refund_state($qty, $total, $refund);
                if ($state === 'full') {
                    continue;
                }
                $refunded = is_array($refund) ? max(0.0, (float) $refund['amount']) : 0.0;
                $vid = intval($line->variation_id);
                $oid = intval($line->order_id);
                if (!isset($by_session[$vid][$oid])) {
                    $by_session[$vid][$oid] = array('lines' => 0, 'due' => 0.0, 'part' => 0.0);
                }
                $by_session[$vid][$oid]['lines']++;
                $by_session[$vid][$oid]['due'] += max(0.0, $total - $refunded);
                $by_session[$vid][$oid]['part'] += $refunded;
            }
            $out .= '<h3>' . esc_html(get_the_title($pid)) . '</h3>';
            foreach ($cancelled as $vid => $row) {
                $any = true;
                $out .= '<h4>' . esc_html(self::row_label($row, $key)) . ': cancelled'
                    . ($row['cancelled_on'] !== '' ? ' on ' . esc_html(self::date_label($row['cancelled_on'])) : '')
                    . ($row['cancel_reason'] !== '' ? ' (reason: ' . esc_html($row['cancel_reason']) . ')' : '') . '</h4>';
                if (empty($by_session[$vid])) {
                    $out .= '<p>Nothing left to refund.</p>';
                    continue;
                }
                $out .= '<table class="murvc-st"><thead><tr><th>Order</th><th>Lines</th><th>Still to refund (incl. GST)</th><th>Notes</th></tr></thead><tbody>';
                foreach ($by_session[$vid] as $oid => $facts) {
                    $order = wc_get_order($oid);
                    $link = $order ? '<a href="' . esc_url($order->get_edit_order_url()) . '">#' . intval($oid) . '</a>' : '#' . intval($oid);
                    $notes = array();
                    if ($facts['part'] >= 0.005) {
                        $notes[] = 'Part refunded $' . number_format($facts['part'], 2);
                    }
                    $summary = TeamOversight_Programs::refund_summary_for_order($oid);
                    if ($summary['unlinked'] >= 0.005) {
                        $notes[] = 'This order also has $' . number_format($summary['unlinked'], 2) . ' refunded that is not tied to a session';
                    }
                    $out .= '<tr><td>' . $link . '</td><td>' . intval($facts['lines']) . '</td><td>$' . esc_html(number_format($facts['due'], 2)) . '</td><td>' . esc_html(implode('. ', $notes)) . '</td></tr>';
                }
                $out .= '</tbody></table>';
            }
        }
        if (!$any) {
            $out .= '<p>No cancelled sessions.</p>';
        }
        return $out . '</div>';
    }
}
