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
 */
class TeamOversight_Programs {

    const PROGRAMS_OPTION = 'team_oversight_programs';
    const SUPERVISORS_OPTION = 'team_oversight_program_supervisors';

    public function __construct() {
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
        return array(
            'key' => $program_key,
            'program' => $program,
            'session' => sanitize_text_field(wp_unslash($_POST['session_label'])),
        );
    }

    /** Count the spots currently marked present for a session. */
    private function checked_in_count($program, $program_key, $session_label) {
        $marked = self::get_attendance($program_key, $session_label);
        $count = 0;
        foreach (self::get_attendees($program['product_id'], $session_label) as $attendee) {
            if (!empty($marked[$attendee['order_id']]['attended'])) {
                $count += $attendee['qty'];
            }
        }
        return $count;
    }

    public function ajax_mark_attendance() {
        $ctx = $this->ajax_context();

        self::set_attendance(
            $ctx['key'],
            $ctx['session'],
            intval($_POST['order_id']),
            !empty($_POST['attended']),
            intval($_POST['attendee_user_id'])
        );

        $marked = self::get_attendance($ctx['key'], $ctx['session']);
        $entry = isset($marked[intval($_POST['order_id'])]) ? $marked[intval($_POST['order_id'])] : null;

        wp_send_json_success(array(
            'attended' => (bool) ($entry && $entry['attended']),
            'note' => ($entry && $entry['attended'] && $entry['by'])
                ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                : '',
            'checked_in' => $this->checked_in_count($ctx['program'], $ctx['key'], $ctx['session']),
        ));
    }

    public function ajax_attendance_state() {
        $ctx = $this->ajax_context();

        $state = array();
        foreach (self::get_attendance($ctx['key'], $ctx['session']) as $order_id => $entry) {
            $state[$order_id] = array(
                'attended' => (bool) $entry['attended'],
                'note' => ($entry['attended'] && $entry['by'])
                    ? 'Marked by ' . $entry['by'] . ($entry['at'] ? ' at ' . $entry['at'] : '')
                    : '',
            );
        }

        wp_send_json_success(array(
            'marked' => $state,
            'checked_in' => $this->checked_in_count($ctx['program'], $ctx['key'], $ctx['session']),
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

        self::set_attendance(
            $program_key,
            sanitize_text_field(wp_unslash($_POST['session_label'])),
            intval($_POST['order_id']),
            !empty($_POST['attended']),
            intval($_POST['attendee_user_id'])
        );

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
     */
    public static function get_sessions($product_id, $weekday = 0) {
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
            $date = self::parse_session_date($label, $year, $weekday);
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
     */
    public static function parse_session_date($label, $year, $weekday = 0) {
        $label = trim($label);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)) {
            return $label;
        }
        $normalised = str_replace(array('-', '.', '/'), ' ', $label);

        if ($weekday >= 1 && $weekday <= 7) {
            $today = strtotime(current_time('Y-m-d'));
            $best = '';
            $best_distance = null;
            foreach (array($year - 2, $year - 1, $year, $year + 1) as $candidate) {
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

        $ts = strtotime($normalised . ' ' . $year);
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

    /**
     * Everyone booked into one session of a program, from paid orders.
     * Storage-agnostic (order item tables + analytics), so it keeps
     * working under HPOS. Quantity > 1 means they booked guests too.
     */
    public static function get_attendees($product_id, $session_label) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT oi.order_id,
                MAX(qty.meta_value) AS qty,
                MAX(cl.user_id) AS user_id,
                MAX(cl.email) AS email,
                MAX(cl.first_name) AS first_name,
                MAX(cl.last_name) AS last_name,
                MAX(os.date_created) AS ordered
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta sess
                ON sess.order_item_id = oi.order_item_id AND sess.meta_key IN ('date', 'pa_date') AND sess.meta_value = %s
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
            JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = oi.order_id
            LEFT JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = os.customer_id
            WHERE os.status IN ('wc-processing', 'wc-completed')
            GROUP BY oi.order_id
            ORDER BY MAX(cl.last_name), MAX(cl.first_name)
        ", intval($product_id), $session_label));

        $attendees = array();
        foreach ($rows as $row) {
            $user_id = intval($row->user_id);
            $account = $user_id ? get_userdata($user_id) : false;
            $email = $account ? $account->user_email : (string) $row->email;
            $name = trim(trim((string) $row->first_name) . ' ' . trim((string) $row->last_name));
            if ($name === '' && $account) {
                $name = $account->display_name;
            }

            $attendees[] = array(
                'order_id' => intval($row->order_id),
                'user_id' => $user_id,
                'name' => $name !== '' ? $name : ($email ?: 'Unknown'),
                'email' => $email,
                'mobile' => $user_id ? get_user_meta($user_id, 'mobile_number', true) : '',
                'qty' => max(1, intval($row->qty)),
                'ordered' => $row->ordered,
                'has_account' => (bool) $account,
            );
        }
        return $attendees;
    }

    /**
     * How many sessions of this program each person had already booked
     * BEFORE the given session — so someone who books three weeks ahead
     * still reads as a first-timer at their first one.
     * Returns array keyed by user id ('u123') or lowercased email.
     */
    public static function get_prior_session_counts($product_id, $before_date, $weekday = 0) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT sess.meta_value AS label,
                cl.user_id AS user_id,
                LOWER(cl.email) AS email
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta sess
                ON sess.order_item_id = oi.order_item_id AND sess.meta_key IN ('date', 'pa_date')
            JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = oi.order_id
            LEFT JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = os.customer_id
            WHERE os.status IN ('wc-processing', 'wc-completed')
        ", intval($product_id)));

        $year = intval(wp_date('Y'));
        $counts = array();
        foreach ($rows as $row) {
            $date = self::parse_session_date($row->label, $year, $weekday);
            // Undated labels can't be ordered, so they don't count as prior.
            if ($date === '' || ($before_date !== '' && $date >= $before_date)) {
                continue;
            }
            $keys = array();
            if (intval($row->user_id)) {
                $keys[] = 'u' . intval($row->user_id);
            }
            if ($row->email) {
                $keys[] = $row->email;
            }
            foreach ($keys as $key) {
                $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
            }
        }
        return $counts;
    }

    private static function prior_count_for($counts, $attendee) {
        if ($attendee['user_id'] && isset($counts['u' . $attendee['user_id']])) {
            return intval($counts['u' . $attendee['user_id']]);
        }
        $email = strtolower((string) $attendee['email']);
        return ($email !== '' && isset($counts[$email])) ? intval($counts[$email]) : 0;
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
     * Session roster: picker + attendee cards. $base_url decides where the
     * picker links (admin page or the front-end page hosting the shortcode).
     */
    public static function render_roster($program, $selected_label, $base_url, $query_arg = 'session', $options = array()) {
        $options = array_merge(array('show_history' => true, 'program_key' => ''), $options);
        $program_key = $options['program_key'];
        $weekday = self::get_program_weekday($program);
        $sessions = self::get_sessions($program['product_id'], $weekday);

        if (empty($sessions)) {
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

        // First-timers: nobody has "attended before" without prior sessions.
        $prior_counts = $options['show_history'] ? self::get_prior_session_counts($program['product_id'], $session_date, $weekday) : array();
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
                                <?php echo TeamOversight_Coach_Portal::render_emergency_details($attendee['email']); ?>
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

            function refreshCount(count) {
                var el = document.querySelector('.murvc-checked-count');
                if (el && typeof count === 'number') { el.textContent = count; }
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
                        refreshCount(res.data.checked_in);
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
            // upserts server-side, so nothing is lost either way.
            setInterval(function () {
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new URLSearchParams({
                        action: 'murvc_attendance_state',
                        nonce: nonce,
                        program_key: programKey,
                        session_label: sessionLabel
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) { return; }
                        root.querySelectorAll('.coach-applicant-card').forEach(function (card) {
                            var form = card.querySelector('.murvc-attendance-form');
                            if (!form) { return; }
                            var state = res.data.marked[form.dataset.order];
                            var attended = !!(state && state.attended);
                            if (attended !== card.classList.contains('murvc-attended')) {
                                applyState(card, attended, state ? state.note : '');
                            }
                        });
                        refreshCount(res.data.checked_in);
                    })
                    .catch(function () {});
            }, 20000);
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
    // Front end: [session_attendance]
    // ------------------------------------------------------------------

    public function render_shortcode($atts) {
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

        // Only programs this supervisor actually runs.
        $programs = self::get_viewable_programs();
        if (empty($programs)) {
            return '<p>No club programs are configured yet.</p>';
        }

        $key = $atts['program'] !== '' && isset($programs[$atts['program']]) ? $atts['program'] : '';
        if ($key === '' && isset($_GET['program']) && isset($programs[sanitize_key($_GET['program'])])) {
            $key = sanitize_key($_GET['program']);
        }
        if ($key === '') {
            $key = key($programs);
        }

        $selected = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
        $base_url = remove_query_arg('session');

        $output = '';
        // Program switcher only when there's a choice and the shortcode
        // isn't locked to one program.
        if (count($programs) > 1 && $atts['program'] === '') {
            $output .= '<p class="murvc-program-switcher">';
            foreach ($programs as $program_key => $program) {
                $output .= ($program_key === $key)
                    ? '<strong>' . esc_html($program['name']) . '</strong> '
                    : '<a href="' . esc_url(add_query_arg('program', $program_key, remove_query_arg('session'))) . '">' . esc_html($program['name']) . '</a> ';
            }
            $output .= '</p>';
        }

        return $output . self::render_roster($programs[$key], $selected, add_query_arg('program', $key, $base_url), 'session', array('program_key' => $key));
    }

    /**
     * [mixed_dev_attendance] — Mixed Development's own shortcode. Locks to
     * that program and is the place to add program-specific behaviour
     * (first-timer highlighting lives here by default).
     * Attributes: history="0" hides the first-timer / session-count chips.
     */
    public function render_mixed_dev_shortcode($atts) {
        $atts = shortcode_atts(array('history' => '1'), $atts, 'mixed_dev_attendance');

        $programs = self::get_programs();
        $key = isset($programs['mixed-dev']) ? 'mixed-dev' : '';
        if ($key === '') {
            foreach ($programs as $program_key => $program) {
                if (stripos($program['name'], 'mixed') !== false) {
                    $key = $program_key;
                }
            }
        }
        if ($key === '') {
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
        return self::render_roster($program, $selected, $base_url, 'session', $options);
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function render_admin_page($program_key) {
        // template_redirect doesn't run in wp-admin, so ticks are handled
        // here. Explicit state means a refresh resubmit is harmless.
        if (isset($_POST['murvc_attendance_action']) && $_POST['murvc_attendance_action'] === 'mark'
            && isset($_POST['murvc_attendance_nonce']) && wp_verify_nonce($_POST['murvc_attendance_nonce'], 'murvc_attendance')
            && current_user_can('manage_options')) {
            self::set_attendance(
                sanitize_key($_POST['program_key']),
                sanitize_text_field(wp_unslash($_POST['session_label'])),
                intval($_POST['order_id']),
                !empty($_POST['attended']),
                intval($_POST['attendee_user_id'])
            );
        }

        $program = self::get_program($program_key);
        ?>
        <div class="wrap">
            <h1><?php echo $program ? esc_html($program['name']) : 'Program'; ?> — Attendance</h1>
            <?php if (!$program): ?>
                <p>This program is no longer configured. Add it in <a href="<?php echo admin_url('admin.php?page=club-programs-settings'); ?>">Settings</a>.</p>
            <?php else: ?>
                <p class="description">Who has paid for each session, read live from orders. Supervisors can see this on the front end via the <code>[session_attendance]</code> shortcode — grant access in <a href="<?php echo admin_url('admin.php?page=club-programs-settings'); ?>">Settings</a>.</p>
                <?php
                $selected = isset($_GET['session']) ? sanitize_text_field(wp_unslash($_GET['session'])) : '';
                echo self::render_roster($program, $selected, admin_url('admin.php?page=club-programs-' . $program_key), 'session', array('program_key' => $program_key));
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_settings_page() {
        if (isset($_POST['action']) && $_POST['action'] === 'save_program_settings') {
            $this->save_settings();
        }

        $programs = self::get_programs();
        $legacy = self::get_supervisors();
        ?>
        <div class="wrap">
            <h1>Club Programs — Settings</h1>

            <form method="post" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; max-width: 720px;">
                <h2 style="margin-top: 0;">Programs</h2>
                <p class="description">One line per program: <code>Name | product ID | weekday</code>. The product's date variations become the sessions; each program gets its own admin sub-page (reload after saving). <strong>Weekday is optional but important</strong> when the variation labels have no year (e.g. "13-May") — it pins each session to the right year, so last season's dates sort and filter correctly. Use a day name or 1–7 (Mon–Sun).</p>
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
                        <p style="margin-bottom: 4px;"><strong><?php echo esc_html($program['name']); ?></strong></p>
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
                    <input type="hidden" name="action" value="save_program_settings">
                    <?php wp_nonce_field('save_program_settings', 'program_settings_nonce'); ?>
                </p>
            </form>

            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; max-width: 720px; margin-top: 20px;">
                <h2 style="margin-top: 0;">Front-end page</h2>
                <p>Put <code>[session_attendance]</code> on a page for supervisors. Lock it to one program with <code>[session_attendance program="mixed-dev"]</code>.</p>
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

        $submitted_supervisors = (isset($_POST['supervisors']) && is_array($_POST['supervisors'])) ? $_POST['supervisors'] : array();
        $unknown = array();
        $supervisor_total = 0;

        // Keep each program's existing key across saves. The form posts
        // supervisors under the key that was rendered, and attendance rows
        // are stored against it, so regenerating keys from the name would
        // drop the supervisors being saved and orphan attendance history.
        $existing = self::get_programs();
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

            $programs[$key] = array('name' => $name, 'product_id' => $product_id, 'weekday' => $weekday, 'supervisors' => $ids);
        }
        update_option(self::PROGRAMS_OPTION, $programs);

        if (!empty($_POST['clear_legacy'])) {
            delete_option(self::SUPERVISORS_OPTION);
        }

        echo '<div class="notice notice-success"><p>Saved: ' . count($programs) . ' program(s), ' . intval($supervisor_total) . ' supervisor assignment(s).'
            . (!empty($unknown) ? ' <strong>No account found for:</strong> ' . esc_html(implode(', ', array_unique($unknown))) : '')
            . '</p></div>';
    }
}
