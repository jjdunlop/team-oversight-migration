<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Programs booking (1.86.0, program bookings design 3.1): the Book
 * page ([program_booking]), the range add, the cart rules, the tier
 * discount, the order item meta and saving row, the order-pay rule, the
 * product page forward, the membership grant window and the committee's
 * refund helper.
 *
 * Everything here acts only on lines of a program's DATED current product
 * (line_role() 'book') and on prepared, past and orphan session products
 * ('stop': never on sale). A legacy or month current product (a program
 * still selling its old product) reads '' and every hook hands back what
 * it was given, so nothing changes for it until its Switch.
 *
 * Its existence is what TeamOversight_Program_Sessions::selling() checks:
 * from 1.86.0 the Bookings open tick alone decides.
 *
 * Money is whole cents inside (TeamOversight_Program_Setup::money_cents()
 * and cents_text() at the edges); tier_for() is the ONE place the tier
 * rule is read. No price, total, tier, GST figure or product id is read
 * from a post. A name never goes in a URL, a log line or error_log.
 *
 * 1.86.1 (the club's decision of 4 Nov 2026): a booking is ALWAYS for the
 * account holder. There is no per-booking player name: the Book page says
 * who it is for (holder_name(): the account's first and last name), the
 * Add form carries a required tick (HOLDER_FIELD) confirming it, and an
 * account with no first or last name is asked to add one before booking.
 * The already-booked and in-cart checks key on the account (one line per
 * session in a cart; where a booking is one place, never a session the
 * account already holds). The cart line, the order line (Player, as at
 * checkout) and the roll show the holder's name. Order lines from 1.86.0
 * that carry a typed player name (Player, _murvc_player_key) are still read.
 * The program setup's player_name field is never read as anything but off
 * (TeamOversight_Program_Setup::get()).
 *
 * Never return false from woocommerce_order_needs_payment: WooCommerce's
 * checkout and pay handlers then call payment_complete() on the order.
 * The order-pay rule hides the Pay action, empties the gateways and stops
 * the pay post instead (4.10).
 */
class TeamOversight_Program_Booking {

    /** Design 4.15: sessions per booking post, and lines of one program in a cart. */
    const MAX_PER_ADD = 60;
    const MAX_IN_CART = 100;
    /** Design 4.15, D23: the grant starts 7 days before the first session and ends 28 days after the last. */
    const GRANT_LEAD_DAYS = 7;
    const GRANT_TAIL_DAYS = 28;
    /** Design 7.1.1: "N left" at 10 or fewer. */
    const SHOW_LEFT_AT = 10;
    /** Club 2: a listed session that has started (still open until its cut-off, never ticked by a shortcut). */
    const STARTED_WORDS = 'Today, already started';
    /** 1.86.1: the Add form's required tick (these sessions are for the account holder). */
    const HOLDER_FIELD = 'murvc_book_for';
    /** 1.86.1: the murvc_booking data version of a line booked for the account holder (1.86.0's lines are 1). */
    const BOOKING_V = 2;
    /** Design 4.7.2: after the fee payment rule at 20. */
    const TOTALS_PRIORITY = 25;

    const SHORTCODE = 'program_booking';
    const ACTION_FIELD = 'murvc_book_action';
    const NONCE_FIELD = 'murvc_book_nonce';
    const NONCE_PREFIX = 'murvc_book_';
    const REMOVE_NONCE_PREFIX = 'murvc_book_remove_';

    /** Cart item data (design 3.1). */
    const CART_KEY = 'murvc_booking';
    const TIER_KEY = 'murvc_tier';

    /**
     * Order item meta (design 3.8): two visible, the rest hidden. Player is
     * the account holder's name as it was at checkout (1.86.1);
     * _murvc_player_key is only on 1.86.0's lines (a typed name), read and
     * never written.
     */
    const META_PLAYER = 'Player';
    const META_DISCOUNT = 'Multi-session discount';
    const META_PROGRAM = '_murvc_program';
    const META_DATE = '_murvc_session_date';
    const META_SLOT = '_murvc_session_slot';
    const META_END = '_murvc_session_end';
    const META_STATE = '_murvc_session_state';
    const META_PLAYER_KEY = '_murvc_player_key';
    const META_FULL = '_murvc_full_price';
    const META_PCT = '_murvc_tier_pct';
    const META_COUNT = '_murvc_tier_count';
    /** Order meta: program key => the tiers in force at checkout (the refund helper's rule, 4.9.3). */
    const ORDER_TIERS_META = '_murvc_program_tiers';
    /** Product meta set by Prepare (design 3.1). */
    const GRANT_META = '_murvc_membership_grant';
    /**
     * Design 4.9.3: how to make a withdrawal refund. WooCommerce fills in
     * what the withdrawn lines paid, which is more than a withdrawal owes
     * (the kept lines move to a dearer tier). Shared by the refund box, the
     * To refund tab and the README.
     */
    const REFUND_HOW = 'Refund on the order screen: put the withdrawn sessions\' quantities, then change the refund amounts so they add up to the last column. WooCommerce fills in the full price those lines paid, which is more. When the last column is $0.00, nothing is owed back.';

    /**
     * Design 4.5, D17: true only around Club Manager's own add_to_cart()
     * calls. The validation filter refuses every other add of a program's
     * session product while it is false.
     */
    public static $adding = false;
    /** C27: the 40-adds speed-up (the add hook's totals and the persistent cart held back during the loop). */
    public static $fast_add = true;

    private static $registered = false;
    private static $box_done = false;
    /** Request caches (flush_cache()): account_lines() per key and user, pay_problem() per order. */
    private static $lines_cache = array();
    private static $pay_cache = array();
    /** Program lines the cart loader dropped this request (session_remove()), for their notices. */
    private static $session_removed = array();

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    public static function register_hooks() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        // The Book page (7.1), never cached (8), its posts before output (4.6, 9).
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));
        add_action('template_redirect', array(__CLASS__, 'maybe_no_cache'), 9);
        add_action('template_redirect', array(__CLASS__, 'handle_post'), 10);
        add_action('template_redirect', array(__CLASS__, 'product_redirect'), 11);
        // Every way in goes through the Book page (4.5).
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_add'), 10, 6);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart_items'), 0);
        add_filter('woocommerce_pre_remove_cart_item_from_session', array(__CLASS__, 'session_remove'), 10, 4);
        add_action('woocommerce_cart_loaded_from_session', array(__CLASS__, 'session_removed_notices'), 20);
        add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'validate_update'), 10, 4);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'quantity_html'), 10, 3);
        // The discount (4.7) and how it is shown (4.7.5, D2, 7.2).
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_tiers'), self::TOTALS_PRIORITY);
        add_filter('woocommerce_cart_item_price', array(__CLASS__, 'price_html'), 10, 3);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'item_data'), 10, 2);
        add_action('woocommerce_cart_totals_after_order_total', array(__CLASS__, 'saving_row'));
        add_action('woocommerce_review_order_after_order_total', array(__CLASS__, 'saving_row'));
        add_action('woocommerce_before_cart', array(__CLASS__, 'print_bookings_box'), 5);
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'print_bookings_box'), 5);
        add_filter('woocommerce_coupon_is_valid_for_product', array(__CLASS__, 'coupon_valid'), 10, 4);
        // The order (3.8, 4.7.5).
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_line_meta'), 20, 4);
        add_filter('woocommerce_get_order_item_totals', array(__CLASS__, 'order_totals_row'), 10, 3);
        // Paying an unpaid order later (4.10). Never woocommerce_order_needs_payment (see the class comment).
        add_filter('woocommerce_my_account_my_orders_actions', array(__CLASS__, 'account_actions'), 10, 2);
        add_filter('woocommerce_pay_order_button_html', array(__CLASS__, 'pay_button_html'), 10, 1);
        add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'pay_gateways'), 10, 1);
        add_action('woocommerce_before_pay_action', array(__CLASS__, 'before_pay'), 1);
        add_action('before_woocommerce_pay', array(__CLASS__, 'pay_page_notice'));
        add_action('woocommerce_checkout_validate_order_before_payment', array(__CLASS__, 'validate_before_payment'), 10, 2);
        // An order that changes drops the request caches.
        foreach (array('woocommerce_new_order', 'woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_checkout_order_created') as $hook) {
            add_action($hook, array(__CLASS__, 'flush_cache'), 1, 0);
        }
        // The committee's refund helper (4.9.3).
        add_action('add_meta_boxes', array(__CLASS__, 'order_meta_box'), 30, 2);
    }

    /** Drop the request caches (account_lines(), pay_problem()). Tests call it after changing an order or a session. */
    public static function flush_cache() {
        self::$lines_cache = array();
        self::$pay_cache = array();
    }

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    /** The product a product or variation id belongs to (the variation's parent), or 0. */
    private static function parent_of($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return 0;
        }
        $post = get_post($product_id);
        if (!$post) {
            return 0;
        }
        if ($post->post_type === 'product_variation') {
            return intval($post->post_parent);
        }
        return $post->post_type === 'product' ? $product_id : 0;
    }

    /**
     * C1: 'book' for a program's DATED current product (or one of its
     * variations); 'stop' for a prepared or past program product, or a
     * session product of no program; '' for everything else, a legacy or
     * month current product included, so it is never touched.
     */
    public static function line_role($product_id) {
        if (!class_exists('TeamOversight_Program_Sessions')) {
            return '';
        }
        $parent = self::parent_of($product_id);
        if ($parent <= 0) {
            return '';
        }
        $entry = TeamOversight_Program_Sessions::program_of_product($parent);
        if ($entry !== null) {
            if ($entry['role'] === 'current') {
                return $entry['kind'] === 'dated' ? 'book' : '';
            }
            return 'stop';
        }
        return (string) get_post_meta($parent, '_murvc_session_product', true) === '1' ? 'stop' : '';
    }

    /** The program key of a product or variation id ('' for none). */
    private static function key_of_product($product_id) {
        $parent = self::parent_of($product_id);
        $entry = $parent > 0 && class_exists('TeamOversight_Program_Sessions') ? TeamOversight_Program_Sessions::program_of_product($parent) : null;
        return $entry !== null ? (string) $entry['key'] : '';
    }

    /** Design 3.2: a program is bookable when its current product is dated. */
    public static function bookable($key) {
        return TeamOversight_Program_Sessions::target_product((string) $key, 'current') > 0;
    }

    /**
     * A cart line's murvc_booking data, cleaned (program, player, player_key,
     * v), or null. player is the name the line was added with: the account
     * holder's from 1.86.1 (v 2, no player_key), a typed name on 1.86.0's
     * lines (v 1, player_key from the name).
     */
    private static function booking_data($item) {
        if (!is_array($item) || !isset($item[self::CART_KEY]) || !is_array($item[self::CART_KEY])) {
            return null;
        }
        $b = $item[self::CART_KEY];
        $v = isset($b['v']) && is_scalar($b['v']) ? intval($b['v']) : 1;
        $player = isset($b['player']) && is_scalar($b['player']) ? trim((string) $b['player']) : '';
        $key = '';
        if ($v < self::BOOKING_V) {
            $key = isset($b['player_key']) && is_scalar($b['player_key']) ? (string) $b['player_key'] : '';
            if ($key === '' && $player !== '') {
                $key = self::player_key($player);
            }
        }
        return array(
            'program' => isset($b['program']) && is_scalar($b['program']) ? (string) $b['program'] : '',
            'player' => $player,
            'player_key' => $key,
            'v' => $v,
        );
    }

    /**
     * The program key of a cart line when it is a booked line of a dated
     * current product (line_role() 'book') carrying that program's
     * murvc_booking data, else ''.
     */
    public static function program_key_of_line(array $cart_item) {
        $pid = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
        if (self::line_role($pid) !== 'book') {
            return '';
        }
        $key = self::key_of_product($pid);
        $booking = self::booking_data($cart_item);
        if ($key === '' || $booking === null || $booking['program'] !== $key) {
            return '';
        }
        return $key;
    }

    /** Design 4.7: the ONE place the tier rule is read: the percent off for $count sessions of a program. */
    public static function tier_for($key, $count) {
        return TeamOversight_Program_Setup::tier_pct(TeamOversight_Program_Setup::get((string) $key)['tiers'], intval($count));
    }

    /** The program's name for members. */
    private static function program_name($key) {
        $program = TeamOversight_Programs::get_program((string) $key);
        return (is_array($program) && isset($program['name']) && is_scalar($program['name']) && (string) $program['name'] !== '') ? (string) $program['name'] : (string) $key;
    }

    /** ISO weekday (1 Monday to 7 Sunday) of a Y-m-d, 0 when it isn't one. */
    private static function weekday_of($ymd) {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) || !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return 0;
        }
        return intval(gmdate('N', gmmktime(0, 0, 0, intval($m[2]), intval($m[3]), intval($m[1]))));
    }

    /** A Y-m-d moved by whole days ('' for anything else). */
    private static function add_days($ymd, $days) {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m)) {
            return '';
        }
        return gmdate('Y-m-d', gmmktime(0, 0, 0, intval($m[2]), intval($m[3]) + intval($days), intval($m[1])));
    }

    /** "24 Jan" from a Y-m-d. */
    private static function day_month($ymd) {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m)) {
            return '';
        }
        return gmdate('j M', gmmktime(0, 0, 0, intval($m[2]), intval($m[3]), intval($m[1])));
    }

    /**
     * Club 4: array('Sunday', 'Sundays') when every listed session of the
     * current product (else every session of the current, else of the
     * prepared product) falls on one weekday, else array('session', 'sessions').
     */
    public static function unit_words($key) {
        $key = (string) $key;
        $dates = array();
        foreach (self::listed_sessions($key) as $row) {
            $dates[] = $row['date'];
        }
        if (empty($dates)) {
            foreach (array('current', 'prepared') as $role) {
                $pid = TeamOversight_Program_Sessions::target_product($key, $role);
                if ($pid <= 0) {
                    continue;
                }
                foreach (TeamOversight_Program_Sessions::children($pid) as $row) {
                    if ($row['date'] !== '' && $row['status'] !== 'trash') {
                        $dates[] = $row['date'];
                    }
                }
                if (!empty($dates)) {
                    break;
                }
            }
        }
        $days = array();
        foreach ($dates as $date) {
            $days[self::weekday_of($date)] = true;
        }
        if (count($days) === 1) {
            $day = intval(key($days));
            if ($day >= 1) {
                return array(TeamOversight_Program_Sessions::day_name($day), TeamOversight_Program_Sessions::day_name($day, true));
            }
        }
        return array('session', 'sessions');
    }

    /** Design 3.8: a name trimmed, spaces squeezed, lowercased (how 1.86.0's lines keyed a typed player). */
    public static function player_key($name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /**
     * 1.86.1: the name a booking of this account is for: the account's first
     * and last name ("Sam Lee"), cleaned (entities decoded, tags and extra
     * spaces dropped), falling back to the display name when they clean to
     * nothing. '' when the account has no first or last name: such an
     * account is asked to add them before booking.
     */
    public static function holder_name($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return '';
        }
        $clean = function ($text) {
            $text = html_entity_decode(is_scalar($text) ? (string) $text : '', ENT_QUOTES, 'UTF-8');
            return trim(preg_replace('/\s+/u', ' ', sanitize_text_field($text)));
        };
        $raw_first = get_user_meta($user_id, 'first_name', true);
        $raw_last = get_user_meta($user_id, 'last_name', true);
        if (!is_scalar($raw_first) || !is_scalar($raw_last) || trim((string) $raw_first) === '' || trim((string) $raw_last) === '') {
            return '';
        }
        $first = $clean($raw_first);
        $last = $clean($raw_last);
        if ($first !== '' && $last !== '') {
            return $first . ' ' . $last;
        }
        $user = get_userdata($user_id);
        return $user ? $clean($user->display_name) : '';
    }

    /**
     * 1.86.1: where a member changes the name on their account: Ultimate
     * Member's Account page only when members can edit their first and last
     * name there (um_names_editable()), else WooCommerce's Account details,
     * which always has both name fields ('' when neither is there).
     */
    public static function account_details_url() {
        if (function_exists('um_get_core_page') && self::um_names_editable()) {
            $url = um_get_core_page('account');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        if (function_exists('wc_get_account_endpoint_url')) {
            $url = wc_get_account_endpoint_url('edit-account');
            return is_string($url) ? $url : '';
        }
        return '';
    }

    /**
     * 1.86.1: whether Ultimate Member's Account page lets a member change
     * their first and last name: its Display First & Last name fields
     * setting (account_name) on and its Disable First & Last name field
     * editing setting (account_name_disable) off. With editing disabled UM
     * posts no name fields, so the page can't change a name.
     */
    private static function um_names_editable() {
        if (!function_exists('UM')) {
            return false;
        }
        $um = UM();
        if (!is_object($um) || !method_exists($um, 'options')) {
            return false;
        }
        $options = $um->options();
        if (!is_object($options) || !method_exists($options, 'get')) {
            return false;
        }
        return !empty($options->get('account_name')) && empty($options->get('account_name_disable'));
    }

    /** The ids of a program's dated products (current, prepared and earlier). */
    private static function dated_product_ids($key) {
        $ids = array();
        foreach (TeamOversight_Programs::program_products((string) $key) as $product) {
            if ($product['kind'] === 'dated' && intval($product['product_id']) > 0) {
                $ids[] = intval($product['product_id']);
            }
        }
        return array_values(array_unique($ids));
    }

    /** The session date of an order line: its own _murvc_session_date, else its variation's; '' when neither is a real date. */
    public static function line_session_date($item) {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return '';
        }
        $date = trim((string) $item->get_meta(self::META_DATE, true));
        if ($date === '' && method_exists($item, 'get_variation_id') && intval($item->get_variation_id()) > 0) {
            $date = trim((string) get_post_meta(intval($item->get_variation_id()), self::META_DATE, true));
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) || !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return '';
        }
        return $date;
    }

    /**
     * Design 4.6 step 3, D21: this account's own lines of the program's
     * dated products, read ONCE through WooCommerce (never the analytics
     * tables, which lag): orders processing, completed, on-hold or pending
     * created since the earliest of those products. Each line: item_id,
     * order_id, product_id, variation_id, date, player, player_key (the
     * line's Player and, on 1.86.0's lines, its key: shown, never checked),
     * qty (places after refunds), state (paid / awaiting) and created. Fully
     * refunded lines are left out (4.9.1). Request cached per key and user.
     */
    public static function account_lines($key, $user_id) {
        $key = (string) $key;
        $user_id = intval($user_id);
        $cache_key = $key . '|' . $user_id;
        if (isset(self::$lines_cache[$cache_key])) {
            return self::$lines_cache[$cache_key];
        }
        $out = array();
        $ids = self::dated_product_ids($key);
        if ($user_id <= 0 || empty($ids) || !function_exists('wc_get_orders')) {
            self::$lines_cache[$cache_key] = $out;
            return $out;
        }
        $earliest = null;
        foreach ($ids as $pid) {
            $gmt = (string) get_post_field('post_date_gmt', $pid, 'raw');
            $ts = ($gmt !== '' && $gmt !== '0000-00-00 00:00:00') ? strtotime($gmt . ' UTC') : false;
            if ($ts && ($earliest === null || $ts < $earliest)) {
                $earliest = $ts;
            }
        }
        $args = array(
            'type' => 'shop_order',
            'customer_id' => $user_id,
            'status' => array('processing', 'completed', 'on-hold', 'pending'),
            'limit' => -1,
            'return' => 'objects',
        );
        if ($earliest !== null) {
            $args['date_created'] = '>=' . gmdate('Y-m-d', $earliest - 2 * DAY_IN_SECONDS);
        }
        $orders = wc_get_orders($args);
        $id_set = array_flip($ids);
        $raw = array();
        foreach ((array) $orders as $order) {
            if (!($order instanceof WC_Order)) {
                continue;
            }
            $state = in_array($order->get_status(), array('processing', 'completed'), true) ? 'paid' : 'awaiting';
            $created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            foreach ($order->get_items() as $item_id => $item) {
                if (!($item instanceof WC_Order_Item_Product) || !isset($id_set[intval($item->get_product_id())])) {
                    continue;
                }
                $player = trim((string) $item->get_meta(self::META_PLAYER, true));
                $player_key = (string) $item->get_meta(self::META_PLAYER_KEY, true);
                $raw[] = array(
                    'item_id' => intval($item_id),
                    'order_id' => intval($order->get_id()),
                    'product_id' => intval($item->get_product_id()),
                    'variation_id' => intval($item->get_variation_id()),
                    'date' => self::line_session_date($item),
                    'player' => $player,
                    'player_key' => $player_key,
                    'qty' => max(1, intval($item->get_quantity())),
                    'total' => (float) $item->get_total() + (float) $item->get_total_tax(),
                    'state' => $state,
                    'created' => $created,
                );
            }
        }
        $refunds = TeamOversight_Programs::refunds_for_items(wp_list_pluck($raw, 'item_id'));
        foreach ($raw as $line) {
            $refund = isset($refunds[$line['item_id']]) ? $refunds[$line['item_id']] : null;
            $state = TeamOversight_Programs::refund_state($line['qty'], $line['total'], $refund);
            if ($state === 'full') {
                continue;
            }
            $line['qty'] = TeamOversight_Programs::places_after_refunds($line['qty'], $state, $refund);
            unset($line['total']);
            if ($line['qty'] > 0) {
                $out[] = $line;
            }
        }
        self::$lines_cache[$cache_key] = $out;
        return $out;
    }

    /** WooCommerce's cart, loaded when it isn't yet (front end, AJAX and WP-CLI only), else null. */
    private static function cart() {
        if (!function_exists('WC')) {
            return null;
        }
        $wc = WC();
        if (!$wc) {
            return null;
        }
        if ($wc->cart === null && function_exists('wc_load_cart') && (!is_admin() || wp_doing_ajax())) {
            wc_load_cart();
        }
        return ($wc->cart instanceof WC_Cart) ? $wc->cart : null;
    }

    /** The order a declined card is being retried on (WooCommerce's own exclusion for held stock), or 0. */
    private static function awaiting_order_id() {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return 0;
        }
        return absint(WC()->session->get('order_awaiting_payment'));
    }

    /** A session variation's end (HH:MM): its _murvc_session_end, else read from its time label. */
    private static function product_end($product) {
        if (!($product instanceof WC_Product)) {
            return '';
        }
        return TeamOversight_Program_Sessions::session_end(array(
            'end' => (string) $product->get_meta(self::META_END, true),
            'time_label' => (string) $product->get_attribute('time'),
        ));
    }

    /**
     * The program's lines in the cart ($key '' = every program): cart item
     * key => program, variation_id, date, slot, end, player (the name the
     * line was added with), player_key (1.86.0's lines only), qty and
     * full_cents.
     */
    public static function cart_lines($key = '') {
        $out = array();
        $cart = self::cart();
        if (!$cart) {
            return $out;
        }
        foreach ($cart->get_cart() as $cart_key => $item) {
            $line_key = self::program_key_of_line($item);
            if ($line_key === '' || ((string) $key !== '' && $line_key !== (string) $key)) {
                continue;
            }
            $booking = self::booking_data($item);
            $vid = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
            $product = (isset($item['data']) && $item['data'] instanceof WC_Product) ? $item['data'] : wc_get_product($vid);
            $out[$cart_key] = array(
                'program' => $line_key,
                'variation_id' => $vid,
                'date' => $product ? trim((string) $product->get_meta(self::META_DATE, true)) : '',
                'slot' => $product ? (string) $product->get_meta(self::META_SLOT, true) : '',
                'end' => self::product_end($product),
                'player' => $booking['player'],
                'player_key' => $booking['player_key'],
                'qty' => max(1, isset($item['quantity']) ? intval($item['quantity']) : 1),
                'full_cents' => $product ? self::full_cents($product) : 0,
            );
        }
        return $out;
    }

    /** "5:00pm to 7:00pm" from a start and an end. */
    private static function group_label($start, $end) {
        $a = TeamOversight_Program_Sessions::time_label((string) $start);
        $b = TeamOversight_Program_Sessions::time_label((string) $end);
        return ($a !== '' && $b !== '' && $b !== $a) ? $a . ' to ' . $b : $a;
    }

    /**
     * Design 7.1, club 2, club 4: the current product's sessions a member
     * can see: a session date, not in the Bin, today or later and before
     * its booking cut-off ("a session stays listed until its cut-off").
     * Each children() row gains end, group ("17:00-19:00"), group_label
     * ("5:00pm to 7:00pm"), started (its start has passed: today's session
     * kept open by an end or end-of-day cut-off) and full_cents. Sorted by
     * date then start.
     */
    public static function listed_sessions($key) {
        $key = (string) $key;
        $pid = TeamOversight_Program_Sessions::target_product($key, 'current');
        if ($pid <= 0) {
            return array();
        }
        $today = current_time('Y-m-d');
        $now = time();
        $rows = array();
        foreach (TeamOversight_Program_Sessions::children($pid) as $row) {
            if ($row['date'] === '' || $row['status'] === 'trash' || $row['date'] < $today
                || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $row['slot'])) {
                continue;
            }
            if (!TeamOversight_Program_Sessions::before_cutoff($key, $row, $now)) {
                continue;
            }
            $end = TeamOversight_Program_Sessions::session_end($row);
            if ($end === '' || strcmp($end, $row['slot']) <= 0) {
                $end = $row['slot'];
            }
            $row['end'] = $end;
            $row['group'] = $row['slot'] . '-' . $end;
            $row['group_label'] = self::group_label($row['slot'], $end);
            $start = TeamOversight_Program_Sessions::start_ts($row['date'], $row['slot']);
            $row['started'] = $start > 0 && $start <= $now;
            $rows[] = $row;
        }
        $ids = wp_list_pluck($rows, 'id');
        if (!empty($ids)) {
            if (function_exists('_prime_post_caches')) {
                _prime_post_caches($ids, false, true);
            } else {
                update_meta_cache('post', $ids);
            }
        }
        foreach ($rows as $i => $row) {
            $variation = wc_get_product($row['id']);
            $rows[$i]['full_cents'] = $variation ? self::full_cents($variation) : 0;
        }
        usort($rows, function ($a, $b) {
            $c = strcmp($a['date'], $b['date']);
            if ($c !== 0) {
                return $c;
            }
            $c = strcmp($a['slot'], $b['slot']);
            return $c !== 0 ? $c : strcmp($a['end'], $b['end']);
        });
        return $rows;
    }

    /** Club 4: the time groups among the listed sessions, group => label, by start then end. */
    public static function time_groups($key, $rows = null) {
        if (!is_array($rows)) {
            $rows = self::listed_sessions($key);
        }
        $groups = array();
        foreach ($rows as $row) {
            $groups[$row['group']] = $row['group_label'];
        }
        ksort($groups);
        return $groups;
    }

    /**
     * Places a booker can still take on a session: its stock, less the
     * places held by checkouts in progress (the retried order excluded,
     * as WooCommerce's own check), less every line of it already in this
     * cart. 0 at least.
     */
    public static function free_places($variation) {
        $product = $variation instanceof WC_Product ? $variation : wc_get_product(intval($variation));
        if (!$product) {
            return 0;
        }
        if (!$product->managing_stock()) {
            return 9999;
        }
        $stock = intval($product->get_stock_quantity());
        $held = function_exists('wc_get_held_stock_quantity') ? intval(wc_get_held_stock_quantity($product, self::awaiting_order_id())) : 0;
        $in_cart = 0;
        $cart = self::cart();
        if ($cart) {
            foreach ($cart->get_cart() as $item) {
                if (isset($item['variation_id']) && intval($item['variation_id']) === intval($product->get_id())) {
                    $in_cart += max(0, intval($item['quantity']));
                }
            }
        }
        return max(0, $stock - $held - $in_cart);
    }

    /** A stored price as cents: the setup's reading, else any number (0 when unreadable). */
    private static function price_cents($text) {
        $text = is_scalar($text) ? trim((string) $text) : '';
        $cents = TeamOversight_Program_Setup::money_cents($text);
        if ($cents === null && is_numeric($text)) {
            $cents = intval(round((float) $text * 100));
        }
        return $cents === null ? 0 : max(0, $cents);
    }

    /**
     * Design 4.7.2: the full price of one place as STORED (the sale price
     * while the variation is on sale, else the regular price), never the
     * price the cart last set, so totals never compound.
     */
    public static function full_cents($variation) {
        if (!($variation instanceof WC_Product)) {
            return 0;
        }
        $text = $variation->is_on_sale('edit') ? $variation->get_sale_price('edit') : $variation->get_regular_price('edit');
        return self::price_cents($text);
    }

    // ------------------------------------------------------------------
    // Money (pure; the tests call these)
    // ------------------------------------------------------------------

    /**
     * Design 4.7: every line at the tier $count reaches (default: the
     * number of lines). Returns count, pct, each (per line, in the given
     * order), total, full_total and saving, in cents.
     */
    public static function totals(array $full_cents, array $tiers, $count = null) {
        $count = $count === null ? count($full_cents) : intval($count);
        $pct = TeamOversight_Program_Setup::tier_pct($tiers, $count);
        $each = array();
        $total = 0;
        $full_total = 0;
        foreach ($full_cents as $i => $cents) {
            $discounted = TeamOversight_Program_Setup::discounted_cents(intval($cents), $pct);
            $each[$i] = $discounted;
            $total += $discounted;
            $full_total += intval($cents);
        }
        return array('count' => $count, 'pct' => $pct, 'each' => $each, 'total' => $total, 'full_total' => $full_total, 'saving' => $full_total - $total);
    }

    /**
     * Club 1, C3: the hint under a running total. $line_cents are the full
     * prices of every line the total covers (the program's cart lines and
     * the ticked dates); n = $count or their number. Only the next tier's
     * start can lower the total, so it compares the total at n with the
     * total at that start, the added sessions priced at the highest full
     * price among the lines. $available (finding 8): the sessions that
     * could still be added; '' when fewer than needed. '' when n < 1 or no
     * tier starts above n. $weights (same keys as $line_cents; 1 when
     * missing) are each line's places and $add_places the places of each
     * added session: money counts places, the tier never does (4.7.1).
     */
    public static function nudge(array $tiers, array $line_cents, array $unit, $count = null, $available = null, array $weights = array(), $add_places = 1) {
        $n = $count === null ? count($line_cents) : intval($count);
        if ($n < 1 || empty($line_cents)) {
            return '';
        }
        $add_places = max(1, intval($add_places));
        $next = null;
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !isset($tier['from'])) {
                continue;
            }
            $from = intval($tier['from']);
            if ($from > $n && ($next === null || $from < $next)) {
                $next = $from;
            }
        }
        if ($next === null) {
            return '';
        }
        $k = $next - $n;
        if ($available !== null && intval($available) < $k) {
            return '';
        }
        $add = 0;
        foreach ($line_cents as $cents) {
            $add = max($add, intval($cents));
        }
        $pct_now = TeamOversight_Program_Setup::tier_pct($tiers, $n);
        $pct_then = TeamOversight_Program_Setup::tier_pct($tiers, $next);
        $now = 0;
        $then = 0;
        foreach ($line_cents as $i => $cents) {
            $places = isset($weights[$i]) ? max(1, intval($weights[$i])) : 1;
            $now += TeamOversight_Program_Setup::discounted_cents(intval($cents), $pct_now) * $places;
            $then += TeamOversight_Program_Setup::discounted_cents(intval($cents), $pct_then) * $places;
        }
        $each_then = TeamOversight_Program_Setup::discounted_cents($add, $pct_then);
        $then += $k * $each_then * $add_places;
        $words = isset($unit[0], $unit[1]) ? ($k === 1 ? (string) $unit[0] : (string) $unit[1]) : ($k === 1 ? 'session' : 'sessions');
        if ($then < $now) {
            return 'Add ' . $k . ' more ' . $words . ' and pay ' . self::money_text($now - $then) . ' less in total.';
        }
        if ($then === $now) {
            return 'Add ' . $k . ' more ' . $words . ' and pay the same in total.';
        }
        return 'Book ' . $k . ' more and pay ' . self::money_text($each_then) . ($add_places > 1 ? ' a place.' : ' each.');
    }

    /** "$1,057.80" (member-facing text). */
    public static function money_text($cents) {
        return '$' . number_format(intval($cents) / 100, 2);
    }

    /** "$30" for whole dollars, else "$16.50". */
    private static function short_money($cents) {
        $cents = intval($cents);
        return $cents % 100 === 0 ? '$' . number_format($cents / 100) : self::money_text($cents);
    }

    /**
     * Design 7.1.1: the tier ladder in plain words, one string a row
     * ("1 to 3 Sundays: $30.00 each", "4 to 9: $27.90 each (7% off)", ...,
     * "30 or more: $21.60 each (28% off)"). Empty without tiers.
     */
    public static function ladder(array $tiers, $price_cents, array $unit) {
        $rows = array();
        foreach ($tiers as $tier) {
            if (is_array($tier) && isset($tier['from'], $tier['pct'])) {
                $rows[intval($tier['from'])] = intval($tier['pct']);
            }
        }
        if (empty($rows) || intval($price_cents) <= 0) {
            return array();
        }
        ksort($rows);
        $plural = isset($unit[1]) ? (string) $unit[1] : 'sessions';
        $singular = isset($unit[0]) ? (string) $unit[0] : 'session';
        $starts = array_keys($rows);
        $out = array();
        $first = $starts[0];
        $out[] = ($first - 1 === 1 ? '1 ' . $singular : '1 to ' . ($first - 1) . ' ' . $plural) . ': ' . self::money_text($price_cents) . ' each';
        foreach ($starts as $i => $from) {
            $pct = $rows[$from];
            $each = self::money_text(TeamOversight_Program_Setup::discounted_cents(intval($price_cents), $pct));
            $span = isset($starts[$i + 1]) ? ($starts[$i + 1] - 1 === $from ? (string) $from : $from . ' to ' . ($starts[$i + 1] - 1)) : $from . ' or more';
            $out[] = $span . ': ' . $each . ' each (' . $pct . '% off)';
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The Book page (design 7.1, 7.1.3, 8)
    // ------------------------------------------------------------------

    /** template_redirect 9: a page holding [program_booking] is never cached or indexed (8). */
    public static function maybe_no_cache() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $post = get_queried_object();
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, self::SHORTCODE)) {
            TeamOversight_Programs::no_cache();
        }
    }

    /** The page being drawn (no query string of ours), else the site's home. */
    public static function page_url() {
        $id = function_exists('get_queried_object_id') ? intval(get_queried_object_id()) : 0;
        $url = $id > 0 ? get_permalink($id) : '';
        return (is_string($url) && $url !== '') ? $url : home_url('/');
    }

    /** Hidden fields for the query string a permalink itself carries (plain permalinks), so a GET form keeps it. */
    private static function url_hidden_fields($url) {
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }
        parse_str($query, $args);
        $out = '';
        foreach ($args as $name => $value) {
            if (is_scalar($value)) {
                $out .= '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">';
            }
        }
        return $out;
    }

    /**
     * [program_booking program="KEY"]. Returns HTML, never echoes, writes
     * nothing (it prints this page's WooCommerce notices into its output,
     * as the cart page does). An unknown program is '' for visitors and a
     * sentence for the committee.
     */
    public static function render_shortcode($atts) {
        $atts = shortcode_atts(array('program' => ''), is_array($atts) ? $atts : array(), self::SHORTCODE);
        $key = sanitize_key(is_scalar($atts['program']) ? (string) $atts['program'] : '');
        $program = ($key !== '' && class_exists('TeamOversight_Programs')) ? TeamOversight_Programs::get_program($key) : null;
        if (!is_array($program)) {
            return current_user_can('manage_options') ? '<p>' . esc_html('No such program: ' . ($key !== '' ? $key : '(none given)')) . '</p>' : '';
        }
        TeamOversight_Programs::no_cache();
        if (!function_exists('WC') || !class_exists('TeamOversight_Program_Sessions')) {
            return '';
        }
        return self::render_page($key);
    }

    /** The most common full price among the listed sessions, else the standard price (cents; 0 when none). */
    private static function common_cents(array $rows, array $setup) {
        $counts = array();
        foreach ($rows as $row) {
            if ($row['full_cents'] > 0) {
                $counts[$row['full_cents']] = (isset($counts[$row['full_cents']]) ? $counts[$row['full_cents']] : 0) + 1;
            }
        }
        if (!empty($counts)) {
            arsort($counts);
            return intval(key($counts));
        }
        $standard = !empty($setup['prices']) ? TeamOversight_Program_Setup::money_cents($setup['prices'][0]) : null;
        return $standard === null ? 0 : $standard;
    }

    /** The season year of the current dated product, else the prepared product's (0 when none). */
    private static function season_year($key) {
        foreach (array('current', 'prepared') as $role) {
            $pid = TeamOversight_Program_Sessions::target_product((string) $key, $role);
            if ($pid > 0) {
                $year = intval(get_post_meta($pid, '_murvc_program_year', true));
                if ($year > 0) {
                    return $year;
                }
            }
        }
        return 0;
    }

    private static function render_page($key) {
        $setup = TeamOversight_Program_Setup::get($key);
        $name = self::program_name($key);
        $current = TeamOversight_Program_Sessions::target_product($key, 'current');
        $rows = $current > 0 ? self::listed_sessions($key) : array();
        $groups = self::time_groups($key, $rows);
        $unit = self::unit_words($key);
        $tiers = $setup['tiers'];
        $selling = $current > 0 && TeamOversight_Program_Sessions::selling($key);

        $out = self::styles() . '<div class="murvc-book" data-program="' . esc_attr($key) . '">';
        $out .= '<h2>' . esc_html('Book ' . $name . ' ' . $unit[1]) . '</h2>';
        $price = self::common_cents($rows, $setup);
        if ($price > 0) {
            $out .= '<p class="murvc-book-price">' . esc_html(self::short_money($price) . ' a ' . $unit[0] . (empty($tiers) ? '.' : ', less when you book more.')) . '</p>';
        }
        $out .= '<p class="murvc-book-cutoff">' . esc_html(TeamOversight_Program_Sessions::cutoff_words($setup)) . '</p>';
        $ladder = self::ladder($tiers, $price, $unit);
        if (!empty($ladder)) {
            $out .= '<details class="murvc-book-ladder"><summary>Prices when you book more</summary><ul>';
            foreach ($ladder as $line) {
                $out .= '<li>' . esc_html($line) . '</li>';
            }
            $out .= '</ul></details>';
        }

        if (!$selling) {
            $year = self::season_year($key);
            $out .= '<p class="murvc-book-closed"><strong>' . esc_html('Bookings' . ($year > 0 ? ' for ' . $year : '') . ' aren\'t open yet.') . '</strong></p>';
            if ($current > 0 && !empty($rows)) {
                $out .= self::render_public_list($rows, $groups, $unit);
            }
            return $out . '</div>';
        }
        if (!is_user_logged_in()) {
            $out .= self::render_public_list($rows, $groups, $unit);
            $out .= '<p class="murvc-book-login"><a class="button" href="' . esc_url(wp_login_url(self::page_url())) . '">Log in to book</a>';
            if (get_option('users_can_register')) {
                $out .= ' <a class="button murvc-book-secondary" href="' . esc_url(wp_registration_url()) . '">Create an account</a>';
            }
            return $out . '</p></div>';
        }
        return $out . self::render_member($key, $setup, $rows, $groups, $unit) . '</div>';
    }

    /** The chosen time group from the GET (whitelisted), else the first. */
    private static function chosen_time(array $groups) {
        $time = isset($_GET['time']) && is_scalar($_GET['time']) ? trim(wp_unslash((string) $_GET['time'])) : '';
        if (preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/D', $time) && isset($groups[$time])) {
            return $time;
        }
        $keys = array_keys($groups);
        return empty($keys) ? '' : (string) $keys[0];
    }

    /** Logged out, or bookings not open: the time groups and the coming sessions of one, no names, no form (7.1.1). */
    private static function render_public_list(array $rows, array $groups, array $unit) {
        $time = self::chosen_time($groups);
        $out = '';
        if (count($groups) > 1) {
            $out .= '<p class="murvc-book-times">';
            foreach ($groups as $group => $label) {
                $out .= $group === $time
                    ? '<strong>' . esc_html($label) . '</strong> '
                    : '<a href="' . esc_url(add_query_arg('time', $group, self::page_url())) . '">' . esc_html($label) . '</a> ';
            }
            $out .= '</p>';
        } elseif ($time !== '') {
            $out .= '<p class="murvc-book-times">' . esc_html($groups[$time]) . '</p>';
        }
        $out .= '<h3>' . esc_html('Coming ' . $unit[1]) . '</h3><ul class="murvc-book-list">';
        $any = false;
        foreach ($rows as $row) {
            if ($row['group'] !== $time) {
                continue;
            }
            $any = true;
            if ($row['state'] === 'cancelled') {
                $state = 'Cancelled by the club';
            } elseif ($row['state'] === 'closed' || $row['status'] !== 'publish') {
                $state = 'Bookings closed';
            } else {
                $free = self::free_places($row['id']);
                $state = $free <= 0 ? 'Full' : ($free <= self::SHOW_LEFT_AT ? $free . ' left' : '');
                if (!empty($row['started'])) {
                    $state = self::STARTED_WORDS . ($state !== '' ? ' (' . $state . ')' : '');
                }
            }
            $out .= '<li class="murvc-book-row"><span class="murvc-book-date">' . esc_html(TeamOversight_Program_Sessions::date_label($row['date'])) . '</span>'
                . ($state !== '' ? ' <span class="murvc-book-state">' . esc_html($state) . '</span>' : '') . '</li>';
        }
        if (!$any) {
            $out .= '<li class="murvc-book-row">' . esc_html('No ' . $unit[1] . ' to book right now.') . '</li>';
        }
        return $out . '</ul>';
    }

    /**
     * The GET contract (7.1.3), whitelisted: time, places, range, from, to.
     * Nothing about who is booking (1.86.1: always the account holder); a
     * p left in an older link is ignored.
     */
    private static function view_args(array $groups, $max_places, array $blocks) {
        $get = function ($name) {
            return isset($_GET[$name]) && is_scalar($_GET[$name]) ? trim(wp_unslash((string) $_GET[$name])) : '';
        };
        $view = array('time' => self::chosen_time($groups), 'places' => 1, 'range' => '', 'from' => '', 'to' => '');
        $places = $get('places');
        $view['places'] = ($places !== '' && ctype_digit($places)) ? max(1, min(intval($max_places), intval($places))) : 1;
        $range = $get('range');
        if (in_array($range, array('next', 'month', 'nextmonth', 'season', 'dates'), true)
            || (preg_match('/^b(\d{1,2})$/D', $range, $m) && isset($blocks[intval($m[1])]))) {
            $view['range'] = $range;
        }
        $view['from'] = TeamOversight_Program_Sessions::parse_input_date($get('from'));
        $view['to'] = TeamOversight_Program_Sessions::parse_input_date($get('to'));
        return $view;
    }

    /** The view's GET arguments, for links and form actions (never a name). */
    private static function view_query(array $view, array $override = array()) {
        $args = array();
        foreach (array('time', 'places', 'range', 'from', 'to') as $name) {
            $value = array_key_exists($name, $override) ? $override[$name] : (isset($view[$name]) ? $view[$name] : '');
            if ($name === 'places' && intval($value) <= 1) {
                continue;
            }
            if ((string) $value !== '') {
                $args[$name] = (string) $value;
            }
        }
        return $args;
    }

    /**
     * The dates a shortcut ticks (7.1.2, C28), from rows a member can tick:
     * next, month (today to the month's end), nextmonth, b<i> (a block),
     * season, dates (from to to). array(the earliest 60 dates, more than 60).
     */
    private static function shortcut_dates(array $view, array $blocks, array $rows) {
        $range = $view['range'];
        if ($range === '' || empty($rows)) {
            return array(array(), false);
        }
        $today = current_time('Y-m-d');
        $y = intval(substr($today, 0, 4));
        $m = intval(substr($today, 5, 2));
        $from = '';
        $to = '';
        if ($range === 'month') {
            $from = $today;
            $to = gmdate('Y-m-t', gmmktime(0, 0, 0, $m, 1, $y));
        } elseif ($range === 'nextmonth') {
            $from = gmdate('Y-m-d', gmmktime(0, 0, 0, $m + 1, 1, $y));
            $to = gmdate('Y-m-t', gmmktime(0, 0, 0, $m + 1, 1, $y));
        } elseif ($range === 'dates') {
            if ($view['from'] === '' || $view['to'] === '') {
                return array(array(), false);
            }
            $from = min($view['from'], $view['to']);
            $to = max($view['from'], $view['to']);
        } elseif (preg_match('/^b(\d{1,2})$/D', $range, $mm) && isset($blocks[intval($mm[1])])) {
            $from = $blocks[intval($mm[1])]['from'];
            $to = $blocks[intval($mm[1])]['to'];
        }
        $dates = array();
        foreach ($rows as $row) {
            if ($from !== '' && ($row['date'] < $from || $row['date'] > $to)) {
                continue;
            }
            $dates[] = $row['date'];
            if ($range === 'next') {
                break;
            }
        }
        $more = count($dates) > self::MAX_PER_ADD;
        return array(array_slice($dates, 0, self::MAX_PER_ADD), $more);
    }

    /**
     * "24 Sundays at $23.10 = $554.40 (23% off, you save $165.60)" for the
     * ticked dates ('' with none; a $who before it, which the Book page no
     * longer passes, reads "Sam: 24 ..."); with $places above 1 "10 Tuesdays,
     * 2 places each, at $14.85 a place = $297.00 (...)": the money counts
     * every place, the tier only the sessions (4.7.1), as the cart does.
     */
    public static function total_text($who, array $ticked_cents, array $cart_cents, array $tiers, array $unit, $places = 1) {
        $n = count($ticked_cents);
        if ($n < 1) {
            return '';
        }
        $places = max(1, intval($places));
        $t = self::totals(array_values($ticked_cents), $tiers, $n + count($cart_cents));
        $same = count(array_unique($t['each'])) === 1;
        $text = ($who !== '' ? $who . ': ' : '') . $n . ' ' . ($n === 1 ? $unit[0] : $unit[1])
            . ($places > 1 ? ', ' . $places . ' places each' . ($same ? ',' : '') : '')
            . ($same ? ' at ' . self::money_text(reset($t['each'])) . ($places > 1 ? ' a place' : '') : '')
            . ' = ' . self::money_text($t['total'] * $places);
        if ($t['pct'] > 0) {
            $text .= ' (' . $t['pct'] . '% off' . (count($cart_cents) > 0 ? ' with the ' . count($cart_cents) . ' already in your cart' : '') . ', you save ' . self::money_text($t['saving'] * $places) . ')';
        }
        return $text;
    }

    /**
     * The logged-in Book page (7.1.2, 7.1.4). 1.86.1: every booking is for
     * the account holder, so the page is drawn for the account: above the
     * Add button it says who the booking is for, with a required tick and a
     * link to change the account's name; an account with no first or last
     * name is asked to add them and gets no Add form.
     */
    private static function render_member($key, array $setup, array $rows, array $groups, array $unit) {
        $user_id = get_current_user_id();
        $name = self::program_name($key);
        $max_places = max(1, intval($setup['max_places']));
        // One place per booking: a session the account already holds is booked; above 1, a single date adds places.
        $one_place = $max_places === 1;
        $current = TeamOversight_Program_Sessions::target_product($key, 'current');
        $holder = self::holder_name($user_id);
        $account_url = self::account_details_url();
        $blocks = array_values($setup['blocks']);
        $view = self::view_args($groups, $max_places, $blocks);
        $url = self::page_url();
        $out = '';
        if (function_exists('wc_print_notices') && WC()->session) {
            $out .= (string) wc_print_notices(true);
        }

        // This account's own lines (one player per account: keyed on the session).
        $lines = self::account_lines($key, $user_id);
        $paid = array();
        $awaiting = array();
        $mine = array('paid' => array(), 'awaiting' => 0);
        foreach ($lines as $line) {
            if ($line['product_id'] !== $current) {
                continue;
            }
            $index = (string) $line['variation_id'];
            if ($line['state'] === 'paid') {
                $paid[$index] = (isset($paid[$index]) ? $paid[$index] : 0) + $line['qty'];
                $mine['paid'][] = $line;
            } else {
                $awaiting[$index] = (isset($awaiting[$index]) ? $awaiting[$index] : 0) + $line['qty'];
                $mine['awaiting']++;
            }
        }
        $cart_lines = self::cart_lines($key);
        $in_cart = array();
        $cart_cents = array();
        $cart_qty = array();
        foreach ($cart_lines as $line) {
            $in_cart[(string) $line['variation_id']] = true;
            $cart_cents[] = $line['full_cents'];
            $cart_qty[] = $line['qty'];
        }

        // Each listed session's state for this account (all time groups: the nudge counts them all).
        $info = array();
        $available = 0;
        foreach ($rows as $row) {
            $vid = $row['id'];
            $index = (string) $vid;
            $free = self::free_places($vid);
            $have = (!$one_place && isset($paid[$index])) ? intval($paid[$index]) : 0;
            if ($row['state'] === 'cancelled') {
                $state = 'cancelled';
            } elseif ($row['state'] === 'closed' || $row['status'] !== 'publish') {
                $state = 'closed';
            } elseif ($one_place && isset($paid[$index])) {
                $state = 'booked';
            } elseif (isset($in_cart[$index])) {
                $state = 'cart';
            } elseif (isset($awaiting[$index])) {
                $state = 'awaiting';
            } elseif ($free < $view['places']) {
                $state = 'full';
            } elseif ($have > 0) {
                $state = 'have';
            } else {
                $state = 'open';
            }
            $info[$vid] = array('state' => $state, 'free' => $free, 'have' => $have);
            if ($state === 'open' || $state === 'have') {
                $available++;
            }
        }
        // An account with no first or last name can see the sessions but not tick them.
        $can_tick = $holder !== '';

        // Choose: time and places (a GET form; a name never goes in it).
        $out .= '<form method="get" action="' . esc_url($url) . '" class="murvc-book-choose" id="murvc-book-choose">' . self::url_hidden_fields($url);
        if (count($groups) > 1) {
            $out .= '<fieldset><legend>Time</legend>';
            foreach ($groups as $group => $label) {
                $out .= '<label class="murvc-book-pick"><input type="radio" name="time" value="' . esc_attr($group) . '"' . checked($view['time'], $group, false) . '> ' . esc_html($label) . '</label> ';
            }
            $out .= '</fieldset>';
        } elseif ($view['time'] !== '') {
            $out .= '<input type="hidden" name="time" value="' . esc_attr($view['time']) . '"><p class="murvc-book-times">' . esc_html($groups[$view['time']]) . '</p>';
        }
        if ($max_places > 1) {
            $out .= '<p><label>Places <input type="number" name="places" min="1" max="' . $max_places . '" value="' . intval($view['places']) . '" style="width: 70px;"></label> '
                . '<span class="murvc-book-note">' . esc_html('(you plus up to ' . ($max_places - 1) . ' guest' . ($max_places - 1 === 1 ? '' : 's') . ')') . '</span></p>';
        }
        foreach (array('range', 'from', 'to') as $carry) {
            if ($view[$carry] !== '') {
                $out .= '<input type="hidden" name="' . $carry . '" value="' . esc_attr($view[$carry]) . '">';
            }
        }
        $out .= '<p><button type="submit" class="button murvc-book-secondary">Show ' . esc_html($unit[1]) . '</button></p></form>';

        // Already booked, awaiting payment, in the cart.
        if (!empty($mine['paid'])) {
            usort($mine['paid'], function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });
            $n = count($mine['paid']);
            $first = $mine['paid'][0]['date'];
            $last = $mine['paid'][$n - 1]['date'];
            $span = $first === $last ? self::day_month($first) : self::day_month($first) . ' to ' . self::day_month($last);
            $out .= '<details class="murvc-book-booked"><summary>' . esc_html('Already booked: ' . $n . ' ' . ($n === 1 ? $unit[0] : $unit[1]) . ', ' . $span) . '</summary><ul>';
            foreach ($mine['paid'] as $line) {
                $out .= '<li>' . esc_html(TeamOversight_Program_Sessions::date_label($line['date']) . ($line['qty'] > 1 ? ' (' . $line['qty'] . ' places)' : '')) . '</li>';
            }
            $out .= '</ul></details>';
        }
        if ($mine['awaiting'] > 0) {
            $out .= '<p class="murvc-book-note">' . esc_html($mine['awaiting'] . ' awaiting payment: pay or cancel ' . ($mine['awaiting'] === 1 ? 'it' : 'them') . ' in ')
                . '<a href="' . esc_url(wc_get_account_endpoint_url('orders')) . '">My account</a>.</p>';
        }
        if (!empty($cart_lines)) {
            $n = count($cart_lines);
            $out .= '<p class="murvc-book-incart">' . esc_html('In your cart: ' . $n . ' ' . ($n === 1 ? $unit[0] : $unit[1]) . ' of ' . $name . '.')
                . ' <a href="' . esc_url(wc_get_cart_url()) . '">View cart</a></p>';
        }

        if (empty($rows)) {
            return $out . '<p>' . esc_html('No ' . $unit[1] . ' to book right now.') . '</p>' . self::script();
        }

        // The chosen group's rows and what a shortcut may tick: never a session that has
        // started (club 2: it stays open to tick by hand, to pay for today, until its cut-off).
        $group_rows = array();
        $tickable = array();
        foreach ($rows as $row) {
            if ($row['group'] !== $view['time']) {
                continue;
            }
            $group_rows[] = $row;
            if ($can_tick && $info[$row['id']]['state'] === 'open' && empty($row['started'])) {
                $tickable[] = $row;
            }
        }
        list($ticked, $more) = self::shortcut_dates($view, $blocks, $tickable);
        $ticked = array_flip($ticked);

        // Shortcuts (GET links), or the account's name first.
        if (!$can_tick) {
            $add_name = 'Add your first and last name to your account';
            $out .= '<p class="murvc-book-noname"><strong>'
                . ($account_url !== '' ? '<a href="' . esc_url($account_url) . '">' . esc_html($add_name) . '</a>' : esc_html($add_name))
                . ' before booking.</strong></p>';
        } else {
            $links = array('next' => 'Next ' . $unit[0], 'month' => 'Rest of this month', 'nextmonth' => 'Next month');
            $today = current_time('Y-m-d');
            foreach ($blocks as $i => $block) {
                foreach ($tickable as $row) {
                    if ($row['date'] >= $block['from'] && $row['date'] <= $block['to'] && $row['date'] >= $today) {
                        $links['b' . $i] = $block['name'];
                        break;
                    }
                }
            }
            $links['season'] = 'Rest of the season';
            $out .= '<p class="murvc-book-shortcuts">';
            foreach ($links as $range => $label) {
                $href = add_query_arg(self::view_query($view, array('range' => $range, 'from' => '', 'to' => '')), $url);
                $out .= '<a class="button murvc-book-secondary' . ($view['range'] === $range ? ' is-current' : '') . '" href="' . esc_url($href) . '">' . esc_html($label) . '</a> ';
            }
            $out .= '</p><form method="get" action="' . esc_url($url) . '" class="murvc-book-dates">' . self::url_hidden_fields($url);
            foreach (self::view_query($view, array('range' => 'dates', 'from' => '', 'to' => '')) as $arg => $value) {
                $out .= '<input type="hidden" name="' . esc_attr($arg) . '" value="' . esc_attr($value) . '">';
            }
            $out .= '<label>From <input type="date" name="from" value="' . esc_attr($view['from']) . '"></label> '
                . '<label>to <input type="date" name="to" value="' . esc_attr($view['to']) . '"></label> '
                . '<button type="submit" class="button murvc-book-secondary">Choose dates</button></form>';
            if ($more) {
                $out .= '<p class="murvc-book-note">' . esc_html('Add at most ' . self::MAX_PER_ADD . ' at a time: the first ' . self::MAX_PER_ADD . ' are ticked.') . '</p>';
            }
        }

        // The list, inside the Add form when something can be ticked.
        $list = '<ul class="murvc-book-list">';
        $previous = '';
        $ticked_cents = array();
        foreach ($group_rows as $row) {
            if ($previous !== '' && self::weekday_of($previous) === self::weekday_of($row['date'])) {
                for ($gap = self::add_days($previous, 7); $gap !== '' && $gap < $row['date']; $gap = self::add_days($gap, 7)) {
                    $list .= '<li class="murvc-book-row is-none"><span class="murvc-book-date">' . esc_html(TeamOversight_Program_Sessions::date_label($gap)) . '</span> <span class="murvc-book-state">No session</span></li>';
                }
            }
            $previous = $row['date'];
            $state = $info[$row['id']]['state'];
            $free = $info[$row['id']]['free'];
            $label = TeamOversight_Program_Sessions::date_label($row['date']);
            $words = array(
                'cancelled' => 'Cancelled by the club',
                'closed' => 'Bookings closed',
                'booked' => 'Booked',
                'cart' => 'In your cart',
                'awaiting' => 'Awaiting payment',
                'full' => ($free > 0 ? 'Only ' . $free . ' left' : 'Full'),
            );
            if (($state === 'open' || $state === 'have') && $can_tick) {
                $is_ticked = isset($ticked[$row['date']]);
                if ($is_ticked) {
                    $ticked_cents[] = $row['full_cents'];
                }
                $note = $state === 'have'
                    ? 'You have ' . $info[$row['id']]['have'] . ' place' . ($info[$row['id']]['have'] === 1 ? '' : 's')
                    : ($free <= self::SHOW_LEFT_AT ? $free . ' left' : '');
                if (!empty($row['started'])) {
                    $note = self::STARTED_WORDS . ': tick it only to pay for today' . ($note !== '' ? ' (' . $note . ')' : '');
                }
                $list .= '<li class="murvc-book-row is-' . $state . '"><label><input type="checkbox" name="dates[]" value="' . esc_attr($row['date']) . '" data-cents="' . intval($row['full_cents']) . '"' . ($is_ticked ? ' checked' : '') . '> '
                    . '<span class="murvc-book-date">' . esc_html($label) . '</span></label>'
                    . ($note !== '' ? ' <span class="murvc-book-state">' . esc_html($note) . '</span>' : '') . '</li>';
            } else {
                $text = isset($words[$state]) ? $words[$state] : ($free <= self::SHOW_LEFT_AT ? $free . ' left' : '');
                $list .= '<li class="murvc-book-row is-' . esc_attr($state) . '"><span class="murvc-book-date">' . esc_html($label) . '</span>'
                    . ($text !== '' ? ' <span class="murvc-book-state">' . esc_html($text) . '</span>' : '') . '</li>';
            }
        }
        $list .= '</ul>';
        if (!$can_tick) {
            return $out . $list . self::script();
        }

        $tier_attr = array();
        foreach ($setup['tiers'] as $tier) {
            $tier_attr[] = intval($tier['from']) . ':' . intval($tier['pct']);
        }
        // Money counts places (the ticked dates' and each cart line's own), the tier only sessions.
        $places = max(1, intval($view['places']));
        $total = self::total_text('', $ticked_cents, $cart_cents, $setup['tiers'], $unit, $places);
        $weights = array_merge($cart_qty, empty($ticked_cents) ? array() : array_fill(0, count($ticked_cents), $places));
        $nudge = self::nudge($setup['tiers'], array_merge($cart_cents, $ticked_cents), $unit, null, max(0, $available - count($ticked_cents)), $weights, $places);
        $n = count($ticked_cents);
        $button = $n > 0 ? 'Add ' . $n . ' ' . ($n === 1 ? $unit[0] : $unit[1]) . ' to cart' : 'Add the ticked ' . $unit[1] . ' to cart';
        $cart_attr = array();
        foreach ($cart_cents as $i => $cents) {
            $cart_attr[] = intval($cents) . ':' . max(1, intval($cart_qty[$i]));
        }

        $action = add_query_arg(self::view_query($view), $url);
        $out .= '<form method="post" action="' . esc_url($action) . '" id="murvc-book-add" class="murvc-book-add"'
            . ' data-tiers="' . esc_attr(implode(',', $tier_attr)) . '"'
            . ' data-in-cart="' . esc_attr(implode(',', $cart_attr)) . '"'
            . ' data-places="' . $places . '"'
            . ' data-unit="' . esc_attr($unit[0] . '|' . $unit[1]) . '"'
            . ' data-open="' . intval($available) . '">'
            . '<input type="hidden" name="' . self::ACTION_FIELD . '" value="add">'
            . '<input type="hidden" name="program" value="' . esc_attr($key) . '">'
            . '<input type="hidden" name="time" value="' . esc_attr($view['time']) . '">'
            . '<input type="hidden" name="places" value="' . intval($view['places']) . '">'
            . ($view['range'] !== '' ? '<input type="hidden" name="range" value="' . esc_attr($view['range']) . '">' : '')
            . ($view['from'] !== '' ? '<input type="hidden" name="from" value="' . esc_attr($view['from']) . '">' : '')
            . ($view['to'] !== '' ? '<input type="hidden" name="to" value="' . esc_attr($view['to']) . '">' : '')
            . wp_nonce_field(self::NONCE_PREFIX . $key, self::NONCE_FIELD, false, false);
        // 1.86.1: who the booking is for (the account holder), a required tick, and where to change the name.
        $guests = $max_places - 1;
        $confirm = 'These sessions are for ' . $holder . ', the name on this account'
            . ($guests > 0 ? ', and up to ' . $guests . ' guest' . ($guests === 1 ? '' : 's') : '') . '.';
        $change = 'Change the name on your account';
        $out .= $list
            . '<p class="murvc-book-total" aria-live="polite">' . esc_html($total) . '</p>'
            . '<p class="murvc-book-nudge">' . esc_html($nudge) . '</p>'
            . '<div class="murvc-book-for">'
            . '<p class="murvc-book-holder"><strong>' . esc_html('Booking for ' . $holder) . '</strong></p>'
            . '<p><label><input type="checkbox" name="' . self::HOLDER_FIELD . '" value="1" required> ' . esc_html($confirm) . '</label></p>'
            . '<p class="murvc-book-note">Not the player? '
            . ($account_url !== '' ? '<a href="' . esc_url($account_url) . '">' . esc_html($change) . '</a>' : esc_html($change))
            . ' first.</p></div>'
            . '<p><button type="submit" class="button murvc-book-submit">' . esc_html($button) . '</button></p></form>';
        return $out . self::script();
    }

    private static function styles() {
        return '<style>
        .murvc-book { --mb-text: var(--wp--preset--color--contrast, #1d2327); --mb-muted: #646970; --mb-line: #dcdcde; --mb-tint: #f6f7f7; --mb-good: #1a7a2e; --mb-warn: #8a4b00; --mb-bad: #8a1f11; color: var(--mb-text); max-width: 760px; }
        .murvc-book fieldset { border: 0; padding: 0; margin: 0 0 10px; }
        .murvc-book legend { font-weight: 600; margin-bottom: 4px; }
        .murvc-book .murvc-book-pick { display: inline-block; margin: 0 14px 6px 0; }
        .murvc-book .murvc-book-note, .murvc-book .murvc-book-cutoff { color: var(--mb-muted); }
        .murvc-book .murvc-book-shortcuts .button { margin: 0 6px 6px 0; }
        .murvc-book .murvc-book-shortcuts .is-current { font-weight: 700; }
        .murvc-book .murvc-book-list { list-style: none; margin: 10px 0; padding: 0; border-top: 1px solid var(--mb-line); }
        .murvc-book .murvc-book-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 7px 4px; border-bottom: 1px solid var(--mb-line); margin: 0; }
        .murvc-book .murvc-book-row label { display: flex; gap: 8px; align-items: center; margin: 0; cursor: pointer; }
        .murvc-book .murvc-book-state { color: var(--mb-muted); font-size: 0.92em; }
        .murvc-book .is-none, .murvc-book .is-closed, .murvc-book .is-cancelled { background: var(--mb-tint); }
        .murvc-book .is-cancelled .murvc-book-state { color: var(--mb-bad); }
        .murvc-book .is-full .murvc-book-state, .murvc-book .is-closed .murvc-book-state { color: var(--mb-warn); }
        .murvc-book .is-booked .murvc-book-state, .murvc-book .is-cart .murvc-book-state { color: var(--mb-good); font-weight: 600; }
        .murvc-book .murvc-book-total { font-weight: 600; margin-top: 12px; }
        .murvc-book .murvc-book-nudge { color: var(--mb-good); font-weight: 600; }
        .murvc-book .murvc-book-dates { margin: 0 0 10px; }
        .murvc-book .murvc-book-dates label { margin-right: 8px; }
        .murvc-book .murvc-book-for { border-top: 1px solid var(--mb-line); margin-top: 12px; padding-top: 8px; }
        .murvc-book .murvc-book-for p { margin: 0 0 6px; }
        .murvc-book-box { border: 1px solid #dcdcde; border-radius: 6px; padding: 12px 16px; margin: 0 0 18px; }
        .murvc-book-box h3 { margin-top: 0; }
        .murvc-book-box ul { list-style: none; margin: 0 0 8px; padding: 0; }
        .murvc-book-box li { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
        .murvc-book-box form { margin: 0; }
        .murvc-book-box .murvc-book-nudge { color: #1a7a2e; font-weight: 600; }
        </style>';
    }

    /**
     * The Book page's script, once: submits the Choose form on change, and
     * redraws the total, the nudge and the button as dates are ticked with
     * the same formula as totals() and nudge() (textContent only).
     */
    private static function script() {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<script>(function () {
            var choose = document.getElementById("murvc-book-choose");
            if (choose) {
                choose.addEventListener("change", function (e) {
                    if (e.target && (e.target.type === "radio" || e.target.type === "number")) { choose.submit(); }
                });
            }
            var form = document.getElementById("murvc-book-add");
            if (!form) { return; }
            function ints(text, sep) { return (text || "").split(sep).filter(function (s) { return s !== ""; }); }
            var tiers = ints(form.getAttribute("data-tiers"), ",").map(function (t) { var p = t.split(":"); return [parseInt(p[0], 10), parseInt(p[1], 10)]; });
            var inCart = ints(form.getAttribute("data-in-cart"), ",").map(function (c) { var p = c.split(":"); return [parseInt(p[0], 10) || 0, Math.max(1, parseInt(p[1] || "1", 10) || 1)]; });
            var places = Math.max(1, parseInt(form.getAttribute("data-places") || "1", 10) || 1);
            var unit = (form.getAttribute("data-unit") || "session|sessions").split("|");
            var open = parseInt(form.getAttribute("data-open") || "0", 10) || 0;
            var totalEl = form.querySelector(".murvc-book-total");
            var nudgeEl = form.querySelector(".murvc-book-nudge");
            var button = form.querySelector(".murvc-book-submit");
            function pct(n) { var best = null, p = 0; tiers.forEach(function (t) { if (t[0] <= n && (best === null || t[0] > best)) { best = t[0]; p = t[1]; } }); return p; }
            function disc(c, p) { return Math.floor((c * (100 - p) + 50) / 100); }
            function money(c) {
                var d = String(Math.floor(c / 100)), out = "";
                while (d.length > 3) { out = "," + d.slice(-3) + out; d = d.slice(0, -3); }
                return "$" + d + out + "." + ("0" + (c % 100)).slice(-2);
            }
            function word(n) { return n === 1 ? unit[0] : unit[1]; }
            function nudge(lines, available) {
                var n = lines.length, next = null, i;
                if (n < 1) { return ""; }
                tiers.forEach(function (t) { if (t[0] > n && (next === null || t[0] < next)) { next = t[0]; } });
                if (next === null) { return ""; }
                var k = next - n;
                if (available < k) { return ""; }
                var add = 0, now = 0, then = 0, pn = pct(n), pt = pct(next);
                for (i = 0; i < n; i++) { add = Math.max(add, lines[i][0]); now += disc(lines[i][0], pn) * lines[i][1]; then += disc(lines[i][0], pt) * lines[i][1]; }
                var each = disc(add, pt);
                then += k * each * places;
                if (then < now) { return "Add " + k + " more " + word(k) + " and pay " + money(now - then) + " less in total."; }
                if (then === now) { return "Add " + k + " more " + word(k) + " and pay the same in total."; }
                return "Book " + k + " more and pay " + money(each) + (places > 1 ? " a place." : " each.");
            }
            function redraw() {
                var boxes = form.querySelectorAll("input[name=\'dates[]\']"), ticked = [], i;
                for (i = 0; i < boxes.length; i++) { if (boxes[i].checked) { ticked.push(parseInt(boxes[i].getAttribute("data-cents"), 10) || 0); } }
                var n = ticked.length, text = "";
                if (n > 0) {
                    var p = pct(n + inCart.length), each = [], total = 0, full = 0, same = true;
                    for (i = 0; i < n; i++) { each.push(disc(ticked[i], p)); total += each[i] * places; full += ticked[i] * places; if (each[i] !== each[0]) { same = false; } }
                    text = n + " " + word(n)
                        + (places > 1 ? ", " + places + " places each" + (same ? "," : "") : "")
                        + (same ? " at " + money(each[0]) + (places > 1 ? " a place" : "") : "") + " = " + money(total);
                    if (p > 0) { text += " (" + p + "% off" + (inCart.length > 0 ? " with the " + inCart.length + " already in your cart" : "") + ", you save " + money(full - total) + ")"; }
                }
                if (totalEl) { totalEl.textContent = text; }
                if (nudgeEl) { nudgeEl.textContent = nudge(inCart.concat(ticked.map(function (c) { return [c, places]; })), Math.max(0, open - n)); }
                if (button) { button.textContent = n > 0 ? "Add " + n + " " + word(n) + " to cart" : "Add the ticked " + unit[1] + " to cart"; }
            }
            form.addEventListener("change", redraw);
            form.addEventListener("input", redraw);
        })();</script>';
    }

    // ------------------------------------------------------------------
    // Posts: the range add and Remove all (design 4.6, 7.2, 9)
    // ------------------------------------------------------------------

    /** template_redirect 10: a Book page or Your bookings post, then a redirect (Post/Redirect/Get). */
    public static function handle_post() {
        if (!isset($_POST[self::ACTION_FIELD]) || !is_user_logged_in()) {
            return;
        }
        $result = self::process_post($_POST, get_current_user_id());
        if (!empty($result['handled'])) {
            wp_safe_redirect($result['redirect'] !== '' ? $result['redirect'] : self::page_url());
            exit;
        }
    }

    /**
     * One booking post ($post slashed, as WordPress gives it). Returns
     * array(handled, ok, added, skipped (date => reason), message,
     * redirect); what happened goes to WooCommerce notices, which the next
     * page prints. Callable from tests.
     */
    public static function process_post(array $post, $user_id) {
        $out = array('handled' => false, 'ok' => false, 'added' => 0, 'skipped' => array(), 'message' => '', 'redirect' => '');
        $action = isset($post[self::ACTION_FIELD]) && is_scalar($post[self::ACTION_FIELD]) ? sanitize_key((string) $post[self::ACTION_FIELD]) : '';
        if ($action !== 'add' && $action !== 'remove') {
            return $out;
        }
        $out['handled'] = true;
        $out['redirect'] = self::page_url();
        $key = isset($post['program']) && is_scalar($post['program']) ? sanitize_key((string) $post['program']) : '';
        $user_id = intval($user_id);
        $cart = self::cart();
        $refuse = function ($message) use ($out, $cart) {
            $out['message'] = $message;
            if ($cart && function_exists('wc_add_notice')) {
                wc_add_notice(esc_html($message), 'error');
            }
            return $out;
        };
        if ($key === '' || !is_array(TeamOversight_Programs::get_program($key))) {
            return $refuse('That program isn\'t on sale.');
        }
        if ($user_id <= 0 || get_current_user_id() !== $user_id) {
            return $refuse('Log in to book.');
        }
        if (!$cart) {
            return $refuse('The shop isn\'t available. Try again in a minute.');
        }
        $result = $action === 'add' ? self::add_range($key, $post, $user_id) : self::remove_all($key, $post, $user_id);
        return array_merge($out, $result);
    }

    /** A posted scalar, unslashed and trimmed ('' when missing). */
    private static function posted(array $post, $name) {
        return isset($post[$name]) && is_scalar($post[$name]) ? trim((string) wp_unslash((string) $post[$name])) : '';
    }

    /**
     * Design 4.6's steps, exactly; 1.86.1: the booking is for the account
     * holder (an account with no first or last name is refused, and so is
     * a post without the tick), and every check keys on the account.
     */
    private static function add_range($key, array $post, $user_id) {
        $name = self::program_name($key);
        $setup = TeamOversight_Program_Setup::get($key);
        $max_places = max(1, intval($setup['max_places']));
        $one_place = $max_places === 1;
        $result = array('ok' => false, 'added' => 0, 'skipped' => array(), 'message' => '', 'redirect' => self::page_url());
        $refuse = function ($message) use (&$result) {
            $result['message'] = $message;
            wc_add_notice(esc_html($message), 'error');
            return $result;
        };

        // 1. The checks, in order.
        if (!wp_verify_nonce(self::posted($post, self::NONCE_FIELD), self::NONCE_PREFIX . $key)) {
            return $refuse('This page has expired. Reload it and try again.');
        }
        if (!self::bookable($key)) {
            return $refuse($name . ' isn\'t taking bookings yet.');
        }
        if (!TeamOversight_Program_Sessions::selling($key)) {
            return $refuse('Bookings for ' . $name . ' aren\'t open yet.');
        }
        $groups = self::time_groups($key);
        $time = self::posted($post, 'time');
        if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/D', $time, $tm) || !isset($groups[$time])) {
            return $refuse('Choose a time.');
        }
        $start = $tm[1];
        $end = $tm[2];
        if (!isset($post['dates']) || !is_array($post['dates']) || empty($post['dates'])) {
            return $refuse('Tick at least one date.');
        }
        $dates = array();
        foreach ($post['dates'] as $raw) {
            $date = is_scalar($raw) ? TeamOversight_Program_Sessions::parse_input_date(trim((string) wp_unslash((string) $raw))) : '';
            if ($date === '') {
                return $refuse('One of the dates couldn\'t be read. Reload the page and try again.');
            }
            $dates[$date] = true;
        }
        $dates = array_keys($dates);
        sort($dates);
        if (count($dates) > self::MAX_PER_ADD) {
            return $refuse('Add at most ' . self::MAX_PER_ADD . ' at a time.');
        }
        $places_text = self::posted($post, 'places');
        $places = ($places_text !== '' && ctype_digit($places_text)) ? intval($places_text) : 0;
        if ($places < 1 || $places > $max_places) {
            return $refuse($max_places === 1 ? 'Choose 1 place.' : 'Choose 1 to ' . $max_places . ' places.');
        }
        $result['redirect'] = add_query_arg(self::view_query(array(
            'time' => $time,
            'places' => $places,
            'range' => preg_match('/^(next|month|nextmonth|season|dates|b\d{1,2})$/D', self::posted($post, 'range')) ? self::posted($post, 'range') : '',
            'from' => TeamOversight_Program_Sessions::parse_input_date(self::posted($post, 'from')),
            'to' => TeamOversight_Program_Sessions::parse_input_date(self::posted($post, 'to')),
        )), self::page_url());
        // 1.86.1: the booking is the account holder's, by the name on the account, ticked.
        $holder = self::holder_name($user_id);
        if ($holder === '') {
            return $refuse('Add your first and last name to your account before booking.');
        }
        if (self::posted($post, self::HOLDER_FIELD) !== '1') {
            return $refuse('Tick the box to confirm these sessions are for ' . $holder . ', the name on this account.');
        }

        // 2. The cart's limit for one program.
        $cart_lines = self::cart_lines($key);
        if (count($cart_lines) + count($dates) > self::MAX_IN_CART) {
            return $refuse('Your cart can hold at most ' . self::MAX_IN_CART . ' sessions of ' . $name . '. Check out the ones in it first.');
        }

        // 3. This account's own lines, read once (one player per account: keyed on the session).
        $paid = array();
        $awaiting = array();
        $current = TeamOversight_Program_Sessions::target_product($key, 'current');
        foreach (self::account_lines($key, $user_id) as $line) {
            if ($line['product_id'] !== $current) {
                continue;
            }
            $index = (string) $line['variation_id'];
            if ($line['state'] === 'paid') {
                $paid[$index] = (isset($paid[$index]) ? $paid[$index] : 0) + $line['qty'];
            } else {
                $awaiting[$index] = true;
            }
        }
        $in_cart = array();
        foreach ($cart_lines as $line) {
            $in_cart[(string) $line['variation_id']] = true;
        }

        // 4. Each date in order: the first rule that applies is the reason.
        $by_pair = TeamOversight_Program_Sessions::by_pair(TeamOversight_Program_Sessions::children($current));
        $added = array();
        $skipped = array();
        $now_have = array();
        $cart = self::cart();
        $fast = self::$fast_add;
        $restore = false;
        self::$adding = true;
        if ($fast) {
            $priority = has_action('woocommerce_add_to_cart', array($cart, 'calculate_totals'));
            if ($priority !== false) {
                remove_action('woocommerce_add_to_cart', array($cart, 'calculate_totals'), $priority);
                $restore = $priority;
            }
            add_filter('woocommerce_persistent_cart_enabled', array(__CLASS__, 'no_persistent_cart'), 99);
        }
        try {
            foreach ($dates as $date) {
                $row = isset($by_pair[TeamOversight_Program_Sessions::pair_key($date, $start)]) ? $by_pair[TeamOversight_Program_Sessions::pair_key($date, $start)] : null;
                if ($row === null || $row['status'] === 'trash' || TeamOversight_Program_Sessions::session_end($row) !== $end) {
                    $skipped[$date] = 'no session at that time';
                    continue;
                }
                if ($row['state'] === 'closed') {
                    $skipped[$date] = 'closed';
                    continue;
                }
                if ($row['state'] === 'cancelled') {
                    $skipped[$date] = 'cancelled by the club';
                    continue;
                }
                $variation = wc_get_product($row['id']);
                if (!$variation || !$variation->is_purchasable()) {
                    $skipped[$date] = 'bookings have closed';
                    continue;
                }
                $vid = intval($row['id']);
                $index = (string) $vid;
                // Never two lines of one session in a cart; one place per booking: never a session already held.
                if (isset($in_cart[$index])) {
                    $skipped[$date] = $one_place ? 'already in your cart' : 'already in your cart: change its places in the cart';
                    continue;
                }
                if (isset($paid[$index]) && ($one_place || count($dates) > 1)) {
                    $skipped[$date] = 'already booked';
                    continue;
                }
                if (isset($awaiting[$index])) {
                    $skipped[$date] = 'awaiting payment: pay or cancel it in My account';
                    continue;
                }
                $free = self::free_places($variation);
                if ($free < $places) {
                    $skipped[$date] = $free <= 0 ? 'full' : 'only ' . $free . ' place' . ($free === 1 ? '' : 's') . ' left';
                    continue;
                }
                if (!self::add_line($key, $variation, $places, $holder)) {
                    $skipped[$date] = 'couldn\'t be added';
                    continue;
                }
                $added[] = $date;
                $in_cart[$index] = true;
                if (!$one_place && isset($paid[$index])) {
                    $now_have[$date] = intval($paid[$index]) + $places;
                }
            }
        } finally {
            self::$adding = false;
            if ($fast) {
                if ($restore !== false) {
                    add_action('woocommerce_add_to_cart', array($cart, 'calculate_totals'), $restore, 0);
                }
                remove_filter('woocommerce_persistent_cart_enabled', array(__CLASS__, 'no_persistent_cart'), 99);
            }
            if (!empty($added)) {
                $cart->calculate_totals();
                if ($fast && get_current_user_id() && apply_filters('woocommerce_persistent_cart_enabled', true) && method_exists($cart, 'get_cart_for_session')) {
                    update_user_meta(get_current_user_id(), '_woocommerce_persistent_cart_' . get_current_blog_id(), array('cart' => $cart->get_cart_for_session()));
                }
            }
        }

        // 5. What happened, every date in full.
        $unit = self::unit_words($key);
        $n = count($added);
        if ($n > 0) {
            $first = TeamOversight_Program_Sessions::date_label(reset($added));
            $last = TeamOversight_Program_Sessions::date_label(end($added));
            if ($one_place) {
                $text = $n === 1 ? 'Added ' . $first . ' for ' . $holder . '.' : 'Added ' . $n . ' ' . $unit[1] . ' for ' . $holder . ': ' . $first . ' to ' . $last . '.';
            } else {
                $time_words = self::group_label($start, $end);
                $place_words = $places . ' place' . ($places === 1 ? '' : 's');
                $text = $n === 1
                    ? 'Added ' . $first . ', ' . $time_words . ', ' . $place_words . '.'
                    : 'Added ' . $n . ' ' . $unit[1] . ', ' . $time_words . ', ' . $place_words . ' each: ' . $first . ' to ' . $last . '.';
            }
            foreach ($now_have as $date => $have) {
                $text .= ' You now have ' . $have . ' places on ' . TeamOversight_Program_Sessions::date_label($date) . '.';
            }
            wc_add_notice(esc_html($text), 'success');
            $result['message'] = $text;
        }
        if (!empty($skipped)) {
            $parts = array();
            foreach ($skipped as $date => $reason) {
                $parts[] = TeamOversight_Program_Sessions::date_label($date) . ' (' . $reason . ')';
            }
            wc_add_notice(esc_html('Not added: ' . implode('; ', $parts) . '.'), 'notice');
        }

        // 6. Something added: the cart; nothing: back to the Book page, which prints the notices.
        $result['ok'] = $n > 0;
        $result['added'] = $n;
        $result['skipped'] = $skipped;
        if ($n > 0) {
            $result['redirect'] = wc_get_cart_url();
        }
        return $result;
    }

    /** The persistent cart held back while a range is added (C27). */
    public static function no_persistent_cart() {
        return false;
    }

    /**
     * One line per session (4.6 "Adding a line"): add_to_cart() runs no add
     * validation (D17) and checks stock against the cart. 1.86.1: the line
     * carries the account holder's name as added (v 2, no player key); the
     * caller never adds a session already in the cart, so one session is
     * one line.
     */
    private static function add_line($key, $variation, $places, $holder) {
        $cart = self::cart();
        if (!$cart || !($variation instanceof WC_Product_Variation)) {
            return false;
        }
        try {
            $cart_key = $cart->add_to_cart(
                $variation->get_parent_id(),
                intval($places),
                $variation->get_id(),
                $variation->get_variation_attributes(),
                array(self::CART_KEY => array('program' => (string) $key, 'player' => (string) $holder, 'v' => self::BOOKING_V))
            );
        } catch (Exception $e) {
            return false;
        }
        return is_string($cart_key) && $cart_key !== '';
    }

    /**
     * Design 7.2: Remove all, every line of one program in the cart (1.86.1:
     * one player per account, so no player reference; a Remove all form
     * from 1.86.0 carries the old nonce and reads as expired).
     */
    private static function remove_all($key, array $post, $user_id) {
        $result = array('ok' => false, 'added' => 0, 'skipped' => array(), 'message' => '', 'redirect' => wc_get_cart_url());
        $referer = wp_get_referer();
        if (is_string($referer) && $referer !== '') {
            $path = (string) wp_parse_url($referer, PHP_URL_PATH);
            foreach (array(wc_get_cart_url(), wc_get_checkout_url()) as $allowed) {
                if ($path !== '' && untrailingslashit($path) === untrailingslashit((string) wp_parse_url($allowed, PHP_URL_PATH))) {
                    $result['redirect'] = $allowed;
                }
            }
        }
        if (!wp_verify_nonce(self::posted($post, self::NONCE_FIELD), self::REMOVE_NONCE_PREFIX . $key)) {
            $result['message'] = 'This page has expired. Reload it and try again.';
            wc_add_notice(esc_html($result['message']), 'error');
            return $result;
        }
        $cart = self::cart();
        $remove = array_keys(self::cart_lines($key));
        if (empty($remove)) {
            $result['message'] = 'Nothing to remove.';
            wc_add_notice(esc_html($result['message']), 'notice');
            return $result;
        }
        foreach ($remove as $cart_key) {
            $cart->remove_cart_item($cart_key);
        }
        $unit = self::unit_words($key);
        $n = count($remove);
        $result['ok'] = true;
        $result['message'] = 'Removed ' . $n . ' ' . ($n === 1 ? $unit[0] : $unit[1]) . ' of ' . self::program_name($key) . '.';
        wc_add_notice(esc_html($result['message']), 'success');
        return $result;
    }

    // ------------------------------------------------------------------
    // Cart guards (design 4.5)
    // ------------------------------------------------------------------

    /** The Book page's address when the program's Book page is published, else ''. */
    private static function book_url($key) {
        $page = intval(TeamOversight_Program_Setup::get((string) $key)['booking_page']);
        return ($page > 0 && get_post_status($page) === 'publish') ? (string) get_permalink($page) : '';
    }

    /**
     * woocommerce_add_to_cart_validation: any add of a program's session
     * product (book or stop) is refused, with a notice naming the Book
     * page, unless Club Manager itself is adding ($adding). Covers the
     * product form, AJAX adds, ?add-to-cart=, the Store API and Order
     * again. Every other product passes untouched.
     */
    public static function validate_add($passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array()) {
        if (!$passed || self::$adding) {
            return $passed;
        }
        $id = intval($variation_id) > 0 ? intval($variation_id) : intval($product_id);
        $role = self::line_role($id);
        if ($role !== 'book' && $role !== 'stop') {
            return $passed;
        }
        $key = self::key_of_product($id);
        if ($key === '') {
            wc_add_notice(esc_html('These sessions aren\'t on sale.'), 'error');
            return false;
        }
        $url = self::book_url($key);
        $message = 'Book ' . esc_html(self::program_name($key)) . ' sessions on the '
            . ($url !== '' ? '<a href="' . esc_url($url) . '">Book page</a>' : 'Book page') . '.';
        wc_add_notice($message, 'error');
        return false;
    }

    /** A session's date label for notices ("Sun 14 Mar 2027"). */
    private static function product_label($product) {
        if (!($product instanceof WC_Product)) {
            return 'A session';
        }
        $date = TeamOversight_Program_Sessions::date_label(trim((string) $product->get_meta(self::META_DATE, true)));
        if ($date !== '') {
            return $date;
        }
        $label = trim((string) $product->get_attribute('date'));
        return $label !== '' ? $label : $product->get_name();
    }

    /** program key => the number of its counted lines (4.7.1: distinct sessions; 1.86.1: one player per account). */
    private static function program_counts(array $items) {
        $pairs = array();
        foreach ($items as $item) {
            $key = self::program_key_of_line($item);
            if ($key === '') {
                continue;
            }
            $pairs[$key][intval($item['variation_id'])] = true;
        }
        $counts = array();
        foreach ($pairs as $key => $set) {
            $counts[$key] = count($set);
        }
        return $counts;
    }

    /** One "Your X discount is now N%." per program whose tier moved. */
    private static function tier_notices(array $before, array $after, array $touched) {
        foreach (array_keys($touched) as $key) {
            $was = self::tier_for($key, isset($before[$key]) ? $before[$key] : 0);
            $now = self::tier_for($key, isset($after[$key]) ? $after[$key] : 0);
            if ($was !== $now) {
                wc_add_notice(esc_html('Your ' . self::program_name($key) . ' discount is now ' . $now . '%.'), 'notice');
            }
        }
    }

    /**
     * woocommerce_check_cart_items, priority 0 (before WooCommerce's own
     * checks): each program line that lost its booking data, is no longer
     * on sale, closed, was cancelled, passed its cut-off, sits twice in the
     * cart (1.86.1: one line per session for the account) or, where a
     * booking is one place, is already in another order of this account
     * (finding 2) is taken out with a notice naming it; places above the program's
     * places per booking are set back; a session short of places loses
     * lines from the last added until it fits. Then, per program touched,
     * the new discount. Without this one full session would block the
     * whole checkout.
     */
    public static function check_cart_items() {
        $cart = self::cart();
        if (!$cart) {
            return;
        }
        $items = $cart->get_cart();
        if (empty($items)) {
            return;
        }
        $before = self::program_counts($items);
        $user_id = get_current_user_id();
        $retry = self::awaiting_order_id();
        $touched = array();
        $lines_by_key = array();
        $seen = array();
        foreach ($items as $cart_key => $item) {
            $pid = isset($item['product_id']) ? intval($item['product_id']) : 0;
            $vid = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
            $role = self::line_role($vid > 0 ? $vid : $pid);
            if ($role !== 'book' && $role !== 'stop') {
                continue;
            }
            $key = self::key_of_product($pid);
            $product = (isset($item['data']) && $item['data'] instanceof WC_Product) ? $item['data'] : wc_get_product($vid > 0 ? $vid : $pid);
            $label = self::product_label($product);
            $booking = self::booking_data($item);
            $reason = '';
            if ($booking === null || $key === '' || $booking['program'] !== $key) {
                $reason = 'book sessions on the Book page';
            } elseif ($role === 'stop') {
                $reason = 'no longer on sale';
            } else {
                $state = $product ? (string) $product->get_meta(self::META_STATE, true) : '';
                if ($state === 'closed') {
                    $reason = 'closed';
                } elseif ($state === 'cancelled') {
                    $reason = 'cancelled by the club';
                } elseif (!$product || !$product->is_purchasable()) {
                    $reason = 'bookings have closed';
                }
            }
            // 1.86.1: one player per account. A second line of one session goes; where a booking is
            // one place, so does a session this account already holds in another order.
            if ($reason === '') {
                if (isset($seen[$vid])) {
                    $reason = 'already in your cart';
                } else {
                    $seen[$vid] = true;
                    if ($user_id > 0 && intval(TeamOversight_Program_Setup::get($key)['max_places']) === 1) {
                        if (!isset($lines_by_key[$key])) {
                            $lines_by_key[$key] = self::account_lines($key, $user_id);
                        }
                        foreach ($lines_by_key[$key] as $line) {
                            if ($line['variation_id'] !== $vid || $line['order_id'] === $retry) {
                                continue;
                            }
                            $reason = $line['state'] === 'paid' ? 'already booked' : 'already in an unpaid order: pay or cancel it in My account';
                            if ($line['state'] === 'paid') {
                                break;
                            }
                        }
                    }
                }
            }
            if ($reason !== '') {
                $cart->remove_cart_item($cart_key);
                wc_add_notice(esc_html('Removed ' . $label . ': ' . $reason . '.'), 'error');
                if ($key !== '') {
                    $touched[$key] = true;
                }
                continue;
            }
            $max = max(1, intval(TeamOversight_Program_Setup::get($key)['max_places']));
            if (intval($item['quantity']) > $max) {
                $cart->set_quantity($cart_key, $max, false);
                wc_add_notice(esc_html($label . ': at most ' . $max . ' place' . ($max === 1 ? '' : 's') . ' per booking.'), 'error');
            }
        }

        // Places: lines of one session beyond what is free go, the last added first.
        $by_variation = array();
        foreach ($cart->get_cart() as $cart_key => $item) {
            if (self::program_key_of_line($item) !== '') {
                $by_variation[intval($item['variation_id'])][] = $cart_key;
            }
        }
        foreach ($by_variation as $vid => $keys) {
            $product = wc_get_product($vid);
            if (!$product || !$product->managing_stock()) {
                continue;
            }
            $held = function_exists('wc_get_held_stock_quantity') ? intval(wc_get_held_stock_quantity($product, $retry)) : 0;
            $limit = intval($product->get_stock_quantity()) - $held;
            $sum = 0;
            foreach ($keys as $cart_key) {
                $line = $cart->get_cart_item($cart_key);
                $sum += isset($line['quantity']) ? intval($line['quantity']) : 0;
            }
            while ($sum > $limit && !empty($keys)) {
                $cart_key = array_pop($keys);
                $line = $cart->get_cart_item($cart_key);
                $sum -= isset($line['quantity']) ? intval($line['quantity']) : 0;
                $cart->remove_cart_item($cart_key);
                wc_add_notice(esc_html('Removed ' . self::product_label($product) . ': now full.'), 'error');
                $touched[self::key_of_product($vid)] = true;
            }
        }
        if (!empty($touched)) {
            self::tier_notices($before, self::program_counts($cart->get_cart()), $touched);
        }
    }

    /**
     * woocommerce_pre_remove_cart_item_from_session (finding 9): the cart
     * loader drops a program line that can no longer be bought before
     * check_cart_items() ever sees it, with WooCommerce's generic notice.
     * Take such a line out here instead (true), quietly, and remember why;
     * session_removed_notices() names it.
     */
    public static function session_remove($remove, $cart_key, $values, $product = null) {
        if ($remove || !is_array($values)) {
            return $remove;
        }
        $pid = isset($values['product_id']) ? intval($values['product_id']) : 0;
        $vid = isset($values['variation_id']) ? intval($values['variation_id']) : 0;
        $role = self::line_role($vid > 0 ? $vid : $pid);
        if ($role !== 'book' && $role !== 'stop') {
            return $remove;
        }
        if (!($product instanceof WC_Product)) {
            $product = wc_get_product($vid > 0 ? $vid : $pid);
        }
        if ($role === 'book' && $product && $product->is_purchasable()) {
            return $remove;
        }
        $state = $product ? (string) $product->get_meta(self::META_STATE, true) : '';
        if ($role === 'stop') {
            $reason = 'no longer on sale';
        } elseif ($state === 'closed') {
            $reason = 'closed';
        } elseif ($state === 'cancelled') {
            $reason = 'cancelled by the club';
        } else {
            $reason = 'bookings have closed';
        }
        $booking = self::booking_data($values);
        $key = self::key_of_product($pid);
        self::$session_removed[] = array(
            'key' => $key,
            'text' => 'Removed ' . self::product_label($product) . ': ' . $reason . '.',
            'pair' => (string) $vid,
            'booked' => $booking !== null && $booking['program'] === $key,
        );
        return true;
    }

    /** woocommerce_cart_loaded_from_session: the named notices for session_remove(), and each program's new discount. */
    public static function session_removed_notices($cart = null) {
        if (empty(self::$session_removed)) {
            return;
        }
        $removed = self::$session_removed;
        self::$session_removed = array();
        if (!function_exists('wc_add_notice') || !WC()->session) {
            return;
        }
        $cart = $cart instanceof WC_Cart ? $cart : self::cart();
        $after = $cart ? self::program_counts($cart->get_cart()) : array();
        $before = $after;
        $extra = array();
        $touched = array();
        foreach ($removed as $entry) {
            wc_add_notice(esc_html($entry['text']), 'error');
            if ($entry['key'] !== '' && $entry['booked']) {
                $touched[$entry['key']] = true;
                $extra[$entry['key']][$entry['pair']] = true;
            }
        }
        foreach ($extra as $key => $pairs) {
            $before[$key] = (isset($before[$key]) ? $before[$key] : 0) + count($pairs);
        }
        self::tier_notices($before, $after, $touched);
    }

    /** woocommerce_update_cart_validation: a program line's places stay within places per booking. */
    public static function validate_update($passed, $cart_item_key, $values, $quantity) {
        if (!$passed || !is_array($values)) {
            return $passed;
        }
        $key = self::program_key_of_line($values);
        if ($key === '') {
            return $passed;
        }
        $max = max(1, intval(TeamOversight_Program_Setup::get($key)['max_places']));
        if (intval($quantity) > $max) {
            wc_add_notice(esc_html('At most ' . $max . ' place' . ($max === 1 ? '' : 's') . ' per booking.'), 'error');
            return false;
        }
        return $passed;
    }

    /** woocommerce_cart_item_quantity: one place per booking shows "1" with no box (WooCommerce's sold-individually markup). */
    public static function quantity_html($html, $cart_item_key, $cart_item) {
        $key = is_array($cart_item) ? self::program_key_of_line($cart_item) : '';
        if ($key === '' || intval(TeamOversight_Program_Setup::get($key)['max_places']) !== 1) {
            return $html;
        }
        return '1 <input type="hidden" name="cart[' . esc_attr($cart_item_key) . '][qty]" value="1" />';
    }

    // ------------------------------------------------------------------
    // The discount (design 4.7, D1, D2, D3) and how it is shown (4.7.5, 7.2)
    // ------------------------------------------------------------------

    /**
     * woocommerce_before_calculate_totals, priority 25 (after the fee
     * payment rule at 20): each program's lines in the cart are counted
     * (4.7.1) and every one is priced at its STORED full price less the
     * tier, in whole cents. Runs on every totals calculation, so two
     * calculations never compound and removing lines drops the tier at
     * once. Never on an admin screen (C32): Recalculate on the order
     * screen cannot change a price (D1).
     */
    public static function apply_tiers($cart) {
        if (!($cart instanceof WC_Cart) || (is_admin() && !wp_doing_ajax())) {
            return;
        }
        $groups = array();
        foreach ($cart->get_cart() as $cart_key => $item) {
            $key = self::program_key_of_line($item);
            if ($key === '') {
                if (isset($cart->cart_contents[$cart_key][self::TIER_KEY])) {
                    unset($cart->cart_contents[$cart_key][self::TIER_KEY]);
                }
                continue;
            }
            $groups[$key][] = $cart_key;
        }
        $counts = self::program_counts($cart->get_cart());
        foreach ($groups as $key => $cart_keys) {
            $count = isset($counts[$key]) ? $counts[$key] : 0;
            $pct = self::tier_for($key, $count);
            foreach ($cart_keys as $cart_key) {
                $item = $cart->cart_contents[$cart_key];
                if (!isset($item['data']) || !($item['data'] instanceof WC_Product)) {
                    continue;
                }
                $full = self::full_cents($item['data']);
                $each = TeamOversight_Program_Setup::discounted_cents($full, $pct);
                $item['data']->set_price(TeamOversight_Program_Setup::cents_text($each));
                $cart->cart_contents[$cart_key][self::TIER_KEY] = array('count' => $count, 'pct' => $pct, 'full' => TeamOversight_Program_Setup::cents_text($full));
            }
        }
    }

    /** A cart line's tier data (count, pct, full_cents), or null. */
    private static function line_tier(array $item) {
        if (!isset($item[self::TIER_KEY]) || !is_array($item[self::TIER_KEY])) {
            return null;
        }
        $tier = $item[self::TIER_KEY];
        return array(
            'count' => isset($tier['count']) ? intval($tier['count']) : 0,
            'pct' => isset($tier['pct']) ? intval($tier['pct']) : 0,
            'full_cents' => self::price_cents(isset($tier['full']) ? $tier['full'] : ''),
        );
    }

    /**
     * woocommerce_cart_item_price: the full price struck through and the
     * line's price, both from the line's tier (finding 10: never the
     * product's current price, which a request that didn't recalculate,
     * such as the header's mini cart, still holds at full).
     */
    public static function price_html($html, $cart_item, $cart_item_key) {
        if (!is_array($cart_item) || self::program_key_of_line($cart_item) === '') {
            return $html;
        }
        $tier = self::line_tier($cart_item);
        if ($tier === null || $tier['pct'] <= 0 || $tier['full_cents'] <= 0) {
            return $html;
        }
        $cart = self::cart();
        if ($cart && method_exists($cart, 'display_prices_including_tax') && !$cart->display_prices_including_tax()) {
            return $html;
        }
        $each = TeamOversight_Program_Setup::discounted_cents($tier['full_cents'], $tier['pct']);
        return '<del>' . wc_price($tier['full_cents'] / 100) . '</del> <ins>' . wc_price($each / 100) . '</ins>';
    }

    /**
     * woocommerce_get_item_data: "Player" and "Multi-session discount: 28%
     * off (34 sessions)" under a line (C30). 1.86.1: Player is the account
     * holder's name as it is now (what checkout records), else the name
     * the line was added with.
     */
    public static function item_data($data, $cart_item) {
        if (!is_array($cart_item) || !isset($cart_item[self::CART_KEY])) {
            return $data;
        }
        $data = is_array($data) ? $data : array();
        $booking = self::booking_data($cart_item);
        if ($booking !== null) {
            $who = self::holder_name(get_current_user_id());
            if ($who === '') {
                $who = $booking['player'];
            }
            if ($who !== '') {
                $data[] = array('key' => self::META_PLAYER, 'value' => esc_html($who));
            }
        }
        $tier = self::line_tier($cart_item);
        if ($tier !== null && $tier['pct'] > 0 && self::program_key_of_line($cart_item) !== '') {
            $data[] = array('key' => self::META_DISCOUNT, 'value' => esc_html($tier['pct'] . '% off (' . $tier['count'] . ' session' . ($tier['count'] === 1 ? '' : 's') . ')'));
        }
        return $data;
    }

    /** The cart's multi-session saving in cents: per line (full less the tier price) times places. */
    public static function cart_saving_cents() {
        $cart = self::cart();
        $total = 0;
        if (!$cart) {
            return 0;
        }
        foreach ($cart->get_cart() as $item) {
            if (self::program_key_of_line($item) === '') {
                continue;
            }
            $tier = self::line_tier($item);
            if ($tier === null || $tier['pct'] <= 0) {
                continue;
            }
            $each = TeamOversight_Program_Setup::discounted_cents($tier['full_cents'], $tier['pct']);
            $total += ($tier['full_cents'] - $each) * max(1, intval($item['quantity']));
        }
        return $total;
    }

    /** D2: after the cart's and the checkout's total, "Includes a multi-session saving of $X" (no minus sign; it changes no total). */
    public static function saving_row() {
        $cents = self::cart_saving_cents();
        if ($cents <= 0) {
            return;
        }
        echo '<tr class="murvc-saving"><td colspan="2">' . esc_html('Includes a multi-session saving of ' . self::money_text($cents)) . '</td></tr>';
    }

    /** woocommerce_before_cart / woocommerce_before_checkout_form: the Your bookings box, once a page (C31). */
    public static function print_bookings_box() {
        if (self::$box_done) {
            return;
        }
        $html = self::bookings_box();
        if ($html !== '') {
            self::$box_done = true;
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped by bookings_box().
        }
    }

    /**
     * Design 7.2: the Your bookings box as HTML ('' when the cart holds no
     * program line): per program one row, "You" (1.86.1: every booking is
     * the account holder's), with the count, first and last dates, the
     * time when the program has more than one, the discount and a Remove
     * all form (no name in it); then the program's nudge and a Book more
     * link.
     */
    public static function bookings_box() {
        $lines = self::cart_lines();
        if (empty($lines)) {
            return '';
        }
        $by_program = array();
        foreach ($lines as $cart_key => $line) {
            $by_program[$line['program']][$cart_key] = $line;
        }
        $out = self::styles() . '<div class="murvc-book-box"><h3>Your bookings</h3>';
        foreach ($by_program as $key => $plines) {
            $setup = TeamOversight_Program_Setup::get($key);
            $unit = self::unit_words($key);
            $year = self::season_year($key);
            $counts = self::program_counts(self::cart_items_of($plines));
            $count = isset($counts[$key]) ? $counts[$key] : count($plines);
            $pct = self::tier_for($key, $count);
            $times = array();
            foreach ($plines as $line) {
                $times[$line['slot'] . '-' . $line['end']] = true;
            }
            $multi = count($times) > 1;
            $plist = array_values($plines);
            usort($plist, function ($a, $b) {
                $c = strcmp($a['date'], $b['date']);
                return $c !== 0 ? $c : strcmp($a['slot'], $b['slot']);
            });
            $n = count($plist);
            $first = $plist[0];
            $last = $plist[$n - 1];
            $dates = TeamOversight_Program_Sessions::short_date($first['date']) . ($first['date'] !== $last['date'] ? ' to ' . TeamOversight_Program_Sessions::short_date($last['date']) : '');
            $time = '';
            if ($multi) {
                $own = array();
                foreach ($plist as $line) {
                    $own[self::group_label($line['slot'], $line['end'])] = true;
                }
                $time = implode(', ', array_keys($own));
            }
            $out .= '<h4>' . esc_html(self::program_name($key) . ($year > 0 ? ' ' . $year : '')) . '</h4><ul>'
                . '<li><strong>You</strong> <span>' . esc_html($n . ' ' . ($n === 1 ? $unit[0] : $unit[1])) . '</span>'
                . ($time !== '' ? ' <span>' . esc_html($time) . '</span>' : '')
                . ' <span>' . esc_html($dates) . '</span>'
                . ($pct > 0 ? ' <span>' . esc_html($pct . '% off (' . $count . ' ' . ($count === 1 ? $unit[0] : $unit[1]) . ' in the cart)') . '</span>' : '')
                . ' <form method="post" action="' . esc_url(wc_get_cart_url()) . '">'
                . '<input type="hidden" name="' . self::ACTION_FIELD . '" value="remove">'
                . '<input type="hidden" name="program" value="' . esc_attr($key) . '">'
                . wp_nonce_field(self::REMOVE_NONCE_PREFIX . $key, self::NONCE_FIELD, false, false)
                . '<button type="submit" class="button">Remove all</button></form></li></ul>';
            $cents = wp_list_pluck($plines, 'full_cents');
            $open = 0;
            foreach (self::listed_sessions($key) as $row) {
                if ($row['state'] === '' && $row['status'] === 'publish' && self::free_places($row['id']) > 0) {
                    $open++;
                }
            }
            // Each line's places count toward the money; an added session is priced at the most places booked on one.
            $qtys = array_values(wp_list_pluck($plines, 'qty'));
            $nudge = self::nudge($setup['tiers'], array_values($cents), $unit, null, max(0, $open - count($plines)), $qtys, empty($qtys) ? 1 : max($qtys));
            $book = self::book_url($key);
            if ($nudge !== '' || $book !== '') {
                $out .= '<p>' . ($nudge !== '' ? '<span class="murvc-book-nudge">' . esc_html($nudge) . '</span> ' : '')
                    . ($book !== '' ? '<a href="' . esc_url($book) . '">Book more</a>' : '') . '</p>';
            }
        }
        return $out . '</div>';
    }

    /** The cart items behind some cart_lines() rows (for program_counts()). */
    private static function cart_items_of(array $lines) {
        $cart = self::cart();
        $items = array();
        if ($cart) {
            foreach (array_keys($lines) as $cart_key) {
                $item = $cart->get_cart_item($cart_key);
                if (!empty($item)) {
                    $items[$cart_key] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * Design 4.7.4, D4: a percent or fixed-product coupon never applies to
     * a program session line unless it names that product (the parent or
     * the session). Fixed-cart coupons (credits) are never asked per
     * product, so they still apply to the whole cart.
     */
    public static function coupon_valid($valid, $product, $coupon, $values) {
        if (!$valid || !($product instanceof WC_Product) || !($coupon instanceof WC_Coupon)) {
            return $valid;
        }
        $is_program = (is_array($values) && isset($values[self::CART_KEY])) || self::line_role($product->get_id()) === 'book';
        if (!$is_program || !$coupon->is_type(array('percent', 'fixed_product'))) {
            return $valid;
        }
        $named = array_map('intval', (array) $coupon->get_product_ids());
        $parent = $product->is_type('variation') ? intval($product->get_parent_id()) : intval($product->get_id());
        if (in_array(intval($product->get_id()), $named, true) || in_array($parent, $named, true)) {
            return $valid;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Checkout and the order (design 3.8, 4.7.5, 7.3, 7.4)
    // ------------------------------------------------------------------

    /**
     * woocommerce_checkout_create_order_line_item: a session line gets its
     * session date, slot and end (D8: history survives a later change of
     * the variation); a booked line also gets its program, Player (1.86.1:
     * the account holder's name as it is at checkout; no player key, so
     * history and every check key on the account), full price and tier
     * (3.8) and, with a tier, the visible discount. The tiers in force are
     * kept on the order once per program (finding 5), so a withdrawal is
     * priced by the rule the family booked under.
     */
    public static function order_line_meta($item, $cart_item_key, $values, $order) {
        if (!($item instanceof WC_Order_Item_Product)) {
            return;
        }
        $vid = intval($item->get_variation_id());
        $date = $vid > 0 ? trim((string) get_post_meta($vid, self::META_DATE, true)) : '';
        if ($date !== '') {
            $item->add_meta_data(self::META_DATE, $date, true);
            $item->add_meta_data(self::META_SLOT, (string) get_post_meta($vid, self::META_SLOT, true), true);
            $end = TeamOversight_Program_Sessions::session_end(array(
                'end' => (string) get_post_meta($vid, self::META_END, true),
                'time_label' => (string) get_post_meta($vid, 'attribute_time', true),
            ));
            if ($end !== '') {
                $item->add_meta_data(self::META_END, $end, true);
            }
        }
        if (!is_array($values) || !isset($values[self::CART_KEY]) || !is_array($values[self::CART_KEY])) {
            return;
        }
        $booking = self::booking_data($values);
        $key = $booking['program'];
        $item->add_meta_data(self::META_PROGRAM, $key, true);
        $customer = ($order instanceof WC_Order) ? intval($order->get_customer_id()) : 0;
        $who = self::holder_name($customer > 0 ? $customer : get_current_user_id());
        if ($who === '') {
            $who = $booking['player'];
        }
        if ($who !== '') {
            $item->add_meta_data(self::META_PLAYER, $who, true);
        }
        $tier = self::line_tier($values);
        if ($tier === null || $tier['full_cents'] <= 0) {
            $product = (isset($values['data']) && $values['data'] instanceof WC_Product) ? $values['data'] : wc_get_product($vid);
            $counts = array();
            $cart = self::cart();
            if ($cart) {
                $counts = self::program_counts($cart->get_cart());
            }
            $count = isset($counts[$key]) ? $counts[$key] : 1;
            $tier = array('count' => $count, 'pct' => self::tier_for($key, $count), 'full_cents' => $product ? self::full_cents($product) : 0);
        }
        $item->add_meta_data(self::META_FULL, TeamOversight_Program_Setup::cents_text($tier['full_cents']), true);
        $item->add_meta_data(self::META_PCT, (string) intval($tier['pct']), true);
        $item->add_meta_data(self::META_COUNT, (string) intval($tier['count']), true);
        if ($tier['pct'] > 0) {
            $item->add_meta_data(self::META_DISCOUNT, $tier['pct'] . '% off ' . self::money_text($tier['full_cents']) . ' (' . $tier['count'] . ' session' . ($tier['count'] === 1 ? '' : 's') . ')', true);
        }
        if ($order instanceof WC_Order && $key !== '') {
            $stored = $order->get_meta(self::ORDER_TIERS_META, true);
            $stored = is_array($stored) ? $stored : array();
            if (!isset($stored[$key])) {
                $stored[$key] = TeamOversight_Program_Setup::get($key)['tiers'];
                $order->update_meta_data(self::ORDER_TIERS_META, $stored);
            }
        }
    }

    /**
     * C16: an order's multi-session saving in cents: per line with a tier,
     * the full price times places less what the line cost before coupons
     * (subtotal incl. GST), so a store credit isn't reported as a saving.
     */
    public static function order_saving_cents($order) {
        if (!($order instanceof WC_Order)) {
            return 0;
        }
        $total = 0;
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $full = (string) $item->get_meta(self::META_FULL, true);
            if ($full === '' || intval($item->get_meta(self::META_PCT, true)) <= 0) {
                continue;
            }
            $paid = intval(round(((float) $item->get_subtotal() + (float) $item->get_subtotal_tax()) * 100));
            $total += max(0, self::price_cents($full) * max(1, intval($item->get_quantity())) - $paid);
        }
        return $total;
    }

    /** woocommerce_get_order_item_totals: "Multi-session saving: $285.60 (already taken off the prices above)" after the total. */
    public static function order_totals_row($rows, $order, $tax_display = '') {
        if (!is_array($rows) || !($order instanceof WC_Order)) {
            return $rows;
        }
        $cents = self::order_saving_cents($order);
        if ($cents <= 0) {
            return $rows;
        }
        $row = array('label' => 'Multi-session saving:', 'value' => esc_html(self::money_text($cents) . ' (already taken off the prices above)'));
        $out = array();
        foreach ($rows as $name => $existing) {
            $out[$name] = $existing;
            if ($name === 'order_total') {
                $out['murvc_saving'] = $row;
            }
        }
        if (!isset($out['murvc_saving'])) {
            $out['murvc_saving'] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Paying a pending or failed order later (design 4.10)
    // ------------------------------------------------------------------

    /**
     * '' when an order's session lines may still be paid, else one
     * sentence naming the first that can't: a session no longer on sale,
     * closed, cancelled by the club, past its booking cut-off, or (where a
     * booking is one place; 1.86.1: one player per account) already in
     * another paid order of the same customer. Orders with no session line
     * are ''. Cached per order for the request (flush_cache()).
     */
    public static function pay_problem($order) {
        if (!($order instanceof WC_Order)) {
            return '';
        }
        $order_id = intval($order->get_id());
        if ($order_id > 0 && isset(self::$pay_cache[$order_id])) {
            return self::$pay_cache[$order_id];
        }
        $problem = '';
        $held_lines = array();
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $pid = intval($item->get_product_id());
            $vid = intval($item->get_variation_id());
            $role = self::line_role($vid > 0 ? $vid : $pid);
            if ($role !== 'book' && $role !== 'stop') {
                continue;
            }
            $date = self::line_session_date($item);
            $label = $date !== '' ? TeamOversight_Program_Sessions::date_label($date) : $item->get_name();
            // A 1.86.0 line naming a typed player (it carries a player key) says who; the holder's own lines don't.
            $player = trim((string) $item->get_meta(self::META_PLAYER, true));
            $named = $player !== '' && (string) $item->get_meta(self::META_PLAYER_KEY, true) !== '';
            $about = $label . ($named ? ' for ' . $player : '');
            $variation = $vid > 0 ? wc_get_product($vid) : null;
            $key = self::key_of_product($pid);
            $reason = '';
            if ($role === 'stop' || !$variation || $date === '' || $key === '') {
                $reason = 'is no longer on sale';
            } else {
                $state = (string) $variation->get_meta(self::META_STATE, true);
                if ($state === 'cancelled') {
                    $reason = 'is cancelled by the club';
                } elseif ($state === 'closed' || $variation->get_status('edit') !== 'publish') {
                    $reason = 'is closed';
                } elseif (!TeamOversight_Program_Sessions::selling($key)) {
                    $reason = 'is no longer on sale';
                } elseif (!TeamOversight_Program_Sessions::before_cutoff($key, array(
                    'date' => $date,
                    'slot' => (string) $variation->get_meta(self::META_SLOT, true),
                    'end' => (string) $variation->get_meta(self::META_END, true),
                    'time_label' => (string) $variation->get_attribute('time'),
                ))) {
                    $reason = 'is past its booking cut-off';
                }
            }
            if ($reason !== '') {
                $problem = 'This order can\'t be paid now: ' . $about . ' ' . $reason . '. Book again on the Book page.';
                break;
            }
            // 1.86.1: one player per account; where a booking is one place, a session held in another paid order can't be paid twice.
            if (intval(TeamOversight_Program_Setup::get($key)['max_places']) === 1) {
                $held_lines[] = array('key' => $key, 'vid' => $vid, 'about' => $about);
            }
        }
        $customer = intval($order->get_customer_id());
        if ($problem === '' && !empty($held_lines) && $customer > 0) {
            foreach ($held_lines as $mine) {
                foreach (self::account_lines($mine['key'], $customer) as $line) {
                    if ($line['state'] === 'paid' && $line['order_id'] !== $order_id && $line['variation_id'] === $mine['vid']) {
                        $problem = 'This order can\'t be paid now: ' . $mine['about'] . ' is already booked in another order. Book again on the Book page.';
                        break 2;
                    }
                }
            }
        }
        if ($order_id > 0) {
            self::$pay_cache[$order_id] = $problem;
        }
        return $problem;
    }

    /** The order the order-pay page is about (the endpoint's id), or null. */
    private static function pay_page_order() {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return null;
        }
        $order = wc_get_order(absint(get_query_var('order-pay')));
        return ($order instanceof WC_Order) ? $order : null;
    }

    /** woocommerce_my_account_my_orders_actions (also the view-order page): no Pay for an order that can't be paid. */
    public static function account_actions($actions, $order) {
        if (is_array($actions) && isset($actions['pay']) && $order instanceof WC_Order && self::pay_problem($order) !== '') {
            unset($actions['pay']);
        }
        return $actions;
    }

    /** woocommerce_pay_order_button_html: no Pay button on the order-pay page of an order that can't be paid. */
    public static function pay_button_html($html) {
        $order = self::pay_page_order();
        return ($order !== null && self::pay_problem($order) !== '') ? '' : $html;
    }

    /** woocommerce_available_payment_gateways: none on the order-pay page of an order that can't be paid. */
    public static function pay_gateways($gateways) {
        if (is_admin() && !wp_doing_ajax()) {
            return $gateways;
        }
        $order = self::pay_page_order();
        return ($order !== null && self::pay_problem($order) !== '') ? array() : $gateways;
    }

    /** Where a pay post of this order is sent instead (My account's Orders), or '' when it may be paid. */
    public static function pay_gate($order) {
        if (!($order instanceof WC_Order) || self::pay_problem($order) === '') {
            return '';
        }
        return wc_get_account_endpoint_url('orders');
    }

    /**
     * woocommerce_before_pay_action: runs inside WooCommerce's pay handler
     * after its first needs_payment() check, before any gateway. An order
     * that can't be paid gets the notice and goes to My account's Orders;
     * the handler never reaches a gateway or its no-payment branch.
     */
    public static function before_pay($order) {
        $url = self::pay_gate($order);
        if ($url === '') {
            return;
        }
        wc_add_notice(esc_html(self::pay_problem($order)), 'error');
        wp_safe_redirect($url);
        exit;
    }

    /** before_woocommerce_pay: the reason, on the order-pay page of the customer's own order. */
    public static function pay_page_notice() {
        $order = wc_get_order(absint(get_query_var('order-pay')));
        if (!($order instanceof WC_Order) || intval($order->get_customer_id()) !== get_current_user_id() || get_current_user_id() <= 0) {
            return;
        }
        $problem = self::pay_problem($order);
        if ($problem !== '' && function_exists('wc_print_notice')) {
            wc_print_notice(esc_html($problem), 'error');
        }
    }

    /** woocommerce_checkout_validate_order_before_payment (the Store API's checkout and pay routes, WooCommerce 9.9+). */
    public static function validate_before_payment($order, $errors) {
        if (!($order instanceof WC_Order) || !($errors instanceof WP_Error)) {
            return;
        }
        $problem = self::pay_problem($order);
        if ($problem !== '') {
            $errors->add('murvc_pay', $problem);
        }
    }

    // ------------------------------------------------------------------
    // The product page forward (design 4.5, D6, C21)
    // ------------------------------------------------------------------

    /** The Book page of a program's dated current product (the setup's booking_page), else 0. */
    public static function redirect_page_id($product_id) {
        if (self::line_role($product_id) !== 'book') {
            return 0;
        }
        $key = self::key_of_product($product_id);
        return $key === '' ? 0 : intval(TeamOversight_Program_Setup::get($key)['booking_page']);
    }

    /** Where the product page forwards: the Book page's address once it is published, else ''. */
    public static function redirect_target($product_id) {
        $page = self::redirect_page_id($product_id);
        if ($page <= 0 || get_post_status($page) !== 'publish') {
            return '';
        }
        $url = get_permalink($page);
        return is_string($url) ? $url : '';
    }

    /** template_redirect 11: the product page of a program's dated current product forwards (302, never 301) to its Book page. */
    public static function product_redirect() {
        if (is_admin() || !is_singular('product')) {
            return;
        }
        $url = self::redirect_target(get_queried_object_id());
        if ($url !== '') {
            wp_safe_redirect($url, 302);
            exit;
        }
    }

    // ------------------------------------------------------------------
    // Membership grants (design 4.11, D23, C14, C15)
    // ------------------------------------------------------------------

    /**
     * 'order' (one grant per order) only for a session product Prepare
     * marked so; 'line' (one per order line, today's rule) for every
     * other product.
     */
    public static function grant_mode($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return 'line';
        }
        return ((string) get_post_meta($product_id, self::GRANT_META, true) === 'order'
            && (string) get_post_meta($product_id, '_murvc_session_product', true) === '1') ? 'order' : 'line';
    }

    /**
     * D23: array(start, end) of an order's one grant: from GRANT_LEAD_DAYS
     * before the first session date to GRANT_TAIL_DAYS after the last,
     * whatever day it was paid. With no session date (a line of the
     * product without one), from $fallback_start for $months, as a line
     * grant runs.
     */
    public static function grant_window(array $dates, $fallback_start, $months) {
        $clean = array();
        foreach ($dates as $date) {
            if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) && checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
                $clean[] = $date;
            }
        }
        if (empty($clean)) {
            $start = (string) $fallback_start;
            return array($start, date('Y-m-d', strtotime($start . ' +' . intval($months) . ' months')));
        }
        sort($clean);
        return array(self::add_days(reset($clean), -self::GRANT_LEAD_DAYS), self::add_days(end($clean), self::GRANT_TAIL_DAYS));
    }

    // ------------------------------------------------------------------
    // The refund helper (design 4.9.3, D5, C17)
    // ------------------------------------------------------------------

    /** The program keys whose dated products have lines on this order. */
    public static function order_program_keys($order) {
        $keys = array();
        if (!($order instanceof WC_Order) || !class_exists('TeamOversight_Program_Sessions')) {
            return $keys;
        }
        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }
            $entry = TeamOversight_Program_Sessions::program_of_product(intval($item->get_product_id()));
            if ($entry !== null && $entry['kind'] === 'dated') {
                $keys[$entry['key']] = true;
            }
        }
        return array_keys($keys);
    }

    /** The tiers a withdrawal is priced by: those kept on the order at checkout, else the setup's (finding 5). */
    private static function order_tiers($order, $key) {
        $stored = $order->get_meta(self::ORDER_TIERS_META, true);
        if (is_array($stored) && isset($stored[$key]) && is_array($stored[$key])) {
            return $stored[$key];
        }
        return TeamOversight_Program_Setup::get((string) $key)['tiers'];
    }

    /**
     * Design 4.9.3: the refund for a family that withdraws the lines
     * $withdraw_item_ids of one program in an order, in cents: the money
     * the program's lines still hold (what every one of them paid, fully
     * refunded lines included, less the money already given back against
     * them: refunded_money()), less any refund tied to no line, less what
     * the kept lines cost at the tier their own number reaches (the tiers
     * kept on the order), never below 0. A fully refunded line is neither
     * kept nor withdrawn. Returns paid, kept_count, pct, kept_cost,
     * unlinked, coupons (what coupons took off the program's lines, cents)
     * and refund. Reads only.
     */
    public static function withdrawal($order, $key, array $withdraw_item_ids) {
        $out = array('paid' => 0, 'kept_count' => 0, 'pct' => 0, 'kept_cost' => 0, 'unlinked' => 0, 'coupons' => 0, 'refund' => 0);
        if (!($order instanceof WC_Order)) {
            return $out;
        }
        $key = (string) $key;
        $ids = array_flip(self::dated_product_ids($key));
        $withdraw = array_flip(array_map('intval', $withdraw_item_ids));
        $summary = TeamOversight_Programs::refund_summary_for_order($order->get_id());
        $lines = array();
        $kept = array();
        foreach ($order->get_items() as $item_id => $item) {
            if (!($item instanceof WC_Order_Item_Product) || !isset($ids[intval($item->get_product_id())])) {
                continue;
            }
            $full = (string) $item->get_meta(self::META_FULL, true);
            if ($full === '') {
                continue;
            }
            $lines[intval($item_id)] = true;
            $qty = max(1, intval($item->get_quantity()));
            $line_cents = intval(round(((float) $item->get_total() + (float) $item->get_total_tax()) * 100));
            $out['paid'] += $line_cents;
            $out['coupons'] += max(0, intval(round(((float) $item->get_subtotal() + (float) $item->get_subtotal_tax()) * 100)) - $line_cents);
            $refund = isset($summary['lines'][intval($item_id)]) ? $summary['lines'][intval($item_id)] : null;
            $state = TeamOversight_Programs::refund_state($qty, $line_cents / 100, $refund);
            if ($state === 'full') {
                continue;
            }
            if (!isset($withdraw[intval($item_id)])) {
                $kept[] = array('full' => self::price_cents($full), 'places' => TeamOversight_Programs::places_after_refunds($qty, $state, $refund));
            }
        }
        $money = self::refunded_money($order, $lines);
        $out['paid'] = max(0, $out['paid'] - $money['tied']);
        $out['kept_count'] = count($kept);
        $out['pct'] = TeamOversight_Program_Setup::tier_pct(self::order_tiers($order, $key), count($kept));
        foreach ($kept as $line) {
            $out['kept_cost'] += TeamOversight_Program_Setup::discounted_cents($line['full'], $out['pct']) * $line['places'];
        }
        $out['unlinked'] = $money['unlinked'];
        $out['refund'] = max(0, $out['paid'] - $out['unlinked'] - $out['kept_cost']);
        return $out;
    }

    /**
     * The money an order's refunds gave back, in cents: tied => against
     * the lines $line_ids (item id => true), unlinked => against no line.
     * Read refund by refund, so it is the money returned, never what the
     * refund's lines recorded: a refund whose lines add up to more than
     * its own amount (the committee lowered the total WooCommerce filled
     * in from the lines) shares its amount over its lines in proportion,
     * and a refund for more than its lines adds the rest to unlinked.
     */
    private static function refunded_money($order, array $line_ids) {
        $out = array('tied' => 0, 'unlinked' => 0);
        foreach ($order->get_refunds() as $refund) {
            $amount = max(0, intval(round((float) $refund->get_amount() * 100)));
            $all = 0;
            $ours = 0;
            foreach ($refund->get_items() as $item) {
                $line_id = intval($item->get_meta('_refunded_item_id'));
                if ($line_id <= 0) {
                    continue;
                }
                $cents = intval(round(-100 * ((float) $item->get_total() + (float) $item->get_total_tax())));
                $all += $cents;
                if (isset($line_ids[$line_id])) {
                    $ours += $cents;
                }
            }
            if ($all <= 0) {
                $out['unlinked'] += $amount;
            } elseif ($amount >= $all) {
                $out['tied'] += $ours;
                $out['unlinked'] += $amount - $all;
            } else {
                $out['tied'] += intval(round($amount * $ours / $all));
            }
        }
        return $out;
    }

    /** The order being edited behind an add_meta_boxes call, or null. */
    private static function screen_order($object) {
        if ($object instanceof WC_Order) {
            return $object;
        }
        if ($object instanceof WP_Post) {
            $order = wc_get_order($object->ID);
            return ($order instanceof WC_Order) ? $order : null;
        }
        return null;
    }

    /** add_meta_boxes: "Club Programs refunds" on the order screen (legacy and HPOS), for the committee, on orders with program lines only. */
    public static function order_meta_box($screen_id = '', $object = null) {
        if (!current_user_can('manage_options') || !function_exists('wc_get_order')) {
            return;
        }
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        if (!in_array($screen_id, $screens, true)) {
            return;
        }
        $order = self::screen_order($object);
        if ($order === null || empty(self::order_program_keys($order))) {
            return;
        }
        add_meta_box('murvc-program-refunds', 'Club Programs refunds', array(__CLASS__, 'render_refund_box'), $screen_id, 'normal', 'low');
    }

    /**
     * The refund helper box (read only): per program, what its lines cost
     * (the club-cancel figure) and, per name on the lines (the account
     * holder's, 1.86.1; a 1.86.0 line's typed player apart; the whole
     * program for lines with none), what to refund if they stop after each
     * of their dates (4.9.3). Names are the order's own (the committee's screen).
     */
    public static function render_refund_box($post_or_order) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $order = self::screen_order($post_or_order);
        if ($order === null) {
            return;
        }
        $keys = self::order_program_keys($order);
        if (empty($keys)) {
            echo '<p>No Club Programs sessions on this order.</p>';
            return;
        }
        echo '<div class="murvc-refund-box">';
        $summary = TeamOversight_Programs::refund_summary_for_order($order->get_id());
        foreach ($keys as $key) {
            $ids = array_flip(self::dated_product_ids($key));
            $base = self::withdrawal($order, $key, array());
            $people = array();
            foreach ($order->get_items() as $item_id => $item) {
                if (!($item instanceof WC_Order_Item_Product) || !isset($ids[intval($item->get_product_id())]) || (string) $item->get_meta(self::META_FULL, true) === '') {
                    continue;
                }
                // A line already refunded in full is neither kept nor withdrawn: no stop row of its own.
                $refund = isset($summary['lines'][intval($item_id)]) ? $summary['lines'][intval($item_id)] : null;
                if (TeamOversight_Programs::refund_state(max(1, intval($item->get_quantity())), (float) $item->get_total() + (float) $item->get_total_tax(), $refund) === 'full') {
                    continue;
                }
                $player = trim((string) $item->get_meta(self::META_PLAYER, true));
                $player_key = $player !== '' ? self::player_key($player) : '';
                if (!isset($people[$player_key])) {
                    $people[$player_key] = array('name' => $player, 'lines' => array());
                }
                $people[$player_key]['lines'][intval($item_id)] = self::line_session_date($item);
            }
            echo '<h4>' . esc_html(self::program_name($key)) . '</h4>';
            echo '<p>' . esc_html('Paid for these lines: ' . self::money_text($base['paid']) . ' (a session the club cancels is refunded at what its line paid).') . '</p>';
            foreach ($people as $person) {
                asort($person['lines']);
                $dates = array_values(array_unique(array_values($person['lines'])));
                $who = $person['name'] !== '' ? $person['name'] : 'they';
                echo '<details><summary>' . esc_html('If ' . $who . ' stop' . ($person['name'] !== '' ? 's' : '') . ' part way') . '</summary>';
                echo '<table class="widefat striped" style="margin: 6px 0;"><thead><tr><th>Stop after</th><th>Sessions kept on this order (all players)</th><th>What the kept sessions cost</th><th>Refund that keeps the rest at the right tier</th></tr></thead><tbody>';
                $stops = array_merge(array(''), $dates);
                foreach ($stops as $stop) {
                    $withdraw = array();
                    foreach ($person['lines'] as $item_id => $date) {
                        if ($stop === '' || $date === '' || $date > $stop) {
                            $withdraw[] = $item_id;
                        }
                    }
                    $w = self::withdrawal($order, $key, $withdraw);
                    echo '<tr><td>' . esc_html($stop === '' ? 'Before the first session' : TeamOversight_Program_Sessions::date_label($stop)) . '</td>'
                        . '<td>' . intval($w['kept_count']) . ($w['pct'] > 0 ? ' (' . intval($w['pct']) . '% off)' : '') . '</td>'
                        . '<td>' . esc_html(self::money_text($w['kept_cost'])) . '</td>'
                        . '<td><strong>' . esc_html(self::money_text($w['refund'])) . '</strong></td></tr>';
                }
                echo '</tbody></table>';
                if (count($people) > 1) {
                    echo '<p class="description">The other players\' sessions on this order count toward the tier too.</p>';
                }
                echo '</details>';
            }
            echo '<p class="description">' . esc_html(self::REFUND_HOW) . ' Nothing here changes the order.</p>';
            if ($base['unlinked'] > 0) {
                echo '<p class="description">' . esc_html('This order also has ' . self::money_text($base['unlinked']) . ' refunded that is not tied to a session; it is taken off the refunds above.') . '</p>';
            }
            if ($base['coupons'] > 0) {
                echo '<p class="description">' . esc_html('This order used ' . self::money_text($base['coupons']) . ' of coupons on these sessions. The refunds above count only the money paid, so that amount is treated as spent on the sessions kept; if it was store credit, decide whether to give it back as credit.') . '</p>';
            }
        }
        echo '</div>';
    }
}
