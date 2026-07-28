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
        add_shortcode('session_attendance', array($this, 'render_shortcode'));
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
            'mixed-dev' => array('name' => 'Mixed Development', 'product_id' => intval($product_id)),
        );
    }

    public static function get_program($key) {
        $programs = self::get_programs();
        return isset($programs[$key]) ? $programs[$key] : null;
    }

    public static function get_supervisors() {
        $ids = get_option(self::SUPERVISORS_OPTION, array());
        return is_array($ids) ? array_map('intval', $ids) : array();
    }

    /** Admins always; configured supervisors otherwise. */
    public static function user_can_view($user_id = null) {
        $user_id = $user_id ? intval($user_id) : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return in_array($user_id, self::get_supervisors(), true);
    }

    // ------------------------------------------------------------------
    // Sessions and attendance (read straight from paid orders)
    // ------------------------------------------------------------------

    /**
     * Sessions for a program: every published variation's date attribute,
     * parsed to a real date so they sort and "today" can be found.
     * Returns array of array(label, date) sorted chronologically.
     */
    public static function get_sessions($product_id) {
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
            $date = self::parse_session_date($label, $year);
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
     */
    public static function parse_session_date($label, $year) {
        $label = trim($label);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $label)) {
            return $label;
        }
        $normalised = str_replace(array('-', '.', '/'), ' ', $label);
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

    // ------------------------------------------------------------------
    // Shared rendering (front end + admin use the same roster)
    // ------------------------------------------------------------------

    /**
     * Session roster: picker + attendee cards. $base_url decides where the
     * picker links (admin page or the front-end page hosting the shortcode).
     */
    public static function render_roster($program, $selected_label, $base_url, $query_arg = 'session') {
        $sessions = self::get_sessions($program['product_id']);

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

        ob_start();
        ?>
        <div class="murvc-sessions coach-portal">
            <div class="murvc-session-picker">
                <label for="murvc-session-select"><strong>Session:</strong></label>
                <select id="murvc-session-select" onchange="location.href=this.value;">
                    <?php foreach ($sessions as $label => $session): ?>
                        <option value="<?php echo esc_url(add_query_arg($query_arg, rawurlencode($label), $base_url)); ?>" <?php selected($selected_label, $label); ?>>
                            <?php echo esc_html($label); ?><?php echo ($session['date'] !== '' && $session['date'] === current_time('Y-m-d')) ? ' — today' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="murvc-session-summary">
                    <strong><?php echo intval($spots); ?></strong> booked
                    <?php if ($guests > 0): ?>(<?php echo intval($people); ?> accounts + <?php echo intval($guests); ?> guest<?php echo $guests === 1 ? '' : 's'; ?>)<?php endif; ?>
                    <?php if ($is_today): ?><span class="murvc-today-flag">TODAY</span><?php endif; ?>
                </span>
            </div>

            <?php if (empty($attendees)): ?>
                <p>Nobody has booked this session yet.</p>
            <?php else: ?>
                <?php foreach ($attendees as $attendee): ?>
                    <div class="coach-applicant-card">
                        <div class="cac-header">
                            <span class="cac-name"><?php echo esc_html($attendee['name']); ?></span>
                            <span class="cac-chips">
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
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
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

        $programs = self::get_programs();
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

        return $output . self::render_roster($programs[$key], $selected, add_query_arg('program', $key, $base_url));
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function render_admin_page($program_key) {
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
                echo self::render_roster($program, $selected, admin_url('admin.php?page=club-programs-' . $program_key));
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
        $supervisors = self::get_supervisors();
        $emails = array();
        foreach ($supervisors as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $emails[] = $user->user_email;
            }
        }
        ?>
        <div class="wrap">
            <h1>Club Programs — Settings</h1>

            <form method="post" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; max-width: 720px;">
                <h2 style="margin-top: 0;">Programs</h2>
                <p class="description">One line per program: <code>Name | product ID</code>. The product's date variations become the sessions. Each program gets its own admin sub-page (reload after saving).</p>
                <textarea name="programs" rows="5" style="width: 100%; font-family: monospace;"><?php
                    $lines = array();
                    foreach ($programs as $program) {
                        $lines[] = $program['name'] . ' | ' . $program['product_id'];
                    }
                    echo esc_textarea(implode("\n", $lines));
                ?></textarea>

                <h2>Supervisor access</h2>
                <p class="description">Email addresses (one per line) of the people who can view attendance on the front-end page. Administrators always have access.</p>
                <textarea name="supervisors" rows="5" style="width: 100%; font-family: monospace;"><?php echo esc_textarea(implode("\n", $emails)); ?></textarea>

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

        $programs = array();
        foreach (explode("\n", sanitize_textarea_field(wp_unslash($_POST['programs']))) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            list($name, $product_id) = array_map('trim', explode('|', $line, 2));
            $product_id = intval($product_id);
            if ($name === '' || !$product_id) {
                continue;
            }
            $key = sanitize_title($name);
            $programs[$key] = array('name' => $name, 'product_id' => $product_id);
        }
        update_option(self::PROGRAMS_OPTION, $programs);

        $supervisors = array();
        $unknown = array();
        foreach (explode("\n", sanitize_textarea_field(wp_unslash($_POST['supervisors']))) as $line) {
            $email = sanitize_email(trim($line));
            if ($email === '') {
                continue;
            }
            $user = get_user_by('email', $email);
            if ($user) {
                $supervisors[] = intval($user->ID);
            } else {
                $unknown[] = $email;
            }
        }
        update_option(self::SUPERVISORS_OPTION, array_values(array_unique($supervisors)));

        echo '<div class="notice notice-success"><p>Saved: ' . count($programs) . ' program(s), ' . count($supervisors) . ' supervisor(s).'
            . (!empty($unknown) ? ' <strong>No account found for:</strong> ' . esc_html(implode(', ', $unknown)) : '')
            . '</p></div>';
    }
}
