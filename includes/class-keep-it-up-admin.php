<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Membership -> Keep it up (1.98.0, club request 25 Nov 2026): the
 * game on or off, the page that holds it (for the footer link), and the two
 * boards (top 50 this month and all time, hidden players marked) with
 * Remove beside each score. Administrators only (manage_options); every
 * rule is TeamOversight_Keep_It_Up's.
 *
 * Every button posts to admin-post murvc_keep_it_up (handle_post() ->
 * process_post()) with a nonce per action (murvc_keep_it_up_settings, and
 * murvc_keep_it_up_remove_<score id>), then Post/Redirect/Get with the
 * notice in the transient FLASH_PREFIX + user id.
 */
class TeamOversight_Keep_It_Up_Admin {

    const PAGE_SLUG = 'club-membership-keep-it-up';
    const ACTION = 'murvc_keep_it_up';
    const FLASH_PREFIX = 'murvc_keep_it_up_flash_';
    const FLASH_SECONDS = 300;
    const ACTIONS = array('settings', 'remove');

    private static $hooks_registered = false;
    private static $flash = null;
    private static $flash_taken = false;

    /** Added once, from team_oversight_init(). */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_post'));
        if (is_admin()) {
            // After TeamOversight_Admin::add_admin_menu() (priority 10) has made Club Membership.
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 20);
        }
    }

    public static function add_menu() {
        $hook = add_submenu_page('club-membership', 'Keep it up', 'Keep it up', 'manage_options', self::PAGE_SLUG, array(__CLASS__, 'render_page'));
        if (is_string($hook) && $hook !== '') {
            add_action('load-' . $hook, array(__CLASS__, 'load_page'));
        }
    }

    /** load-{hook}: take this administrator's flash before anything is printed. */
    public static function load_page() {
        if (current_user_can('manage_options')) {
            self::$flash = self::take_flash(get_current_user_id());
            self::$flash_taken = true;
        }
    }

    public static function page_url($args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_SLUG));
    }

    public static function nonce_action($do, $id = 0) {
        return 'murvc_keep_it_up_' . $do . ($do === 'remove' ? '_' . intval($id) : '');
    }

    // ------------------------------------------------------------------
    // The flash
    // ------------------------------------------------------------------

    private static function set_flash($user_id, $type, $message, array $lines = array()) {
        set_transient(self::FLASH_PREFIX . intval($user_id), array('type' => $type, 'message' => $message, 'lines' => array_values($lines)), self::FLASH_SECONDS);
    }

    public static function take_flash($user_id) {
        $key = self::FLASH_PREFIX . intval($user_id);
        $flash = get_transient($key);
        if (!is_array($flash)) {
            return null;
        }
        delete_transient($key);
        return $flash;
    }

    private static function render_flash() {
        if (!self::$flash_taken) {
            self::$flash = self::take_flash(get_current_user_id());
            self::$flash_taken = true;
        }
        $f = self::$flash;
        if (!is_array($f) || empty($f['message'])) {
            return;
        }
        $type = isset($f['type']) && in_array($f['type'], array('success', 'warning', 'error'), true) ? $f['type'] : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html((string) $f['message']) . '</p>';
        if (!empty($f['lines']) && is_array($f['lines'])) {
            echo '<ul class="kiu-lines">';
            foreach ($f['lines'] as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // The handlers
    // ------------------------------------------------------------------

    /** admin-post murvc_keep_it_up: manage_options, then process_post(), then back to the page. */
    public static function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Only administrators can change Keep it up.'), '', array('response' => 403));
        }
        $out = self::process_post($_POST, get_current_user_id());
        wp_safe_redirect($out['redirect']);
        exit;
    }

    /**
     * One button, without the redirect (the tests call it). $post: the
     * slashed request. Returns array(ok, type, message, lines, redirect,
     * refused ('' or why: cap, action, nonce, engine)). Leaves the flash,
     * except for a refused non-administrator.
     */
    public static function process_post($post, $user_id) {
        $p = is_array($post) ? wp_unslash($post) : array();
        $user_id = intval($user_id);
        $do = isset($p['do']) && is_string($p['do']) ? sanitize_key($p['do']) : '';
        $id = isset($p['score']) && is_scalar($p['score']) && ctype_digit((string) $p['score']) ? intval($p['score']) : 0;
        $out = array('ok' => false, 'type' => 'error', 'message' => '', 'lines' => array(), 'redirect' => self::page_url(), 'refused' => '');
        if ($user_id <= 0 || !user_can($user_id, 'manage_options')) {
            $out['message'] = 'Only administrators can change Keep it up.';
            $out['refused'] = 'cap';
            return $out;
        }
        $done = function ($ok, $type, $message, array $lines, $refused) use (&$out, $user_id) {
            $out['ok'] = (bool) $ok;
            $out['type'] = $type;
            $out['message'] = $message;
            $out['lines'] = $lines;
            $out['refused'] = $refused;
            self::set_flash($user_id, $type, $message, $lines);
            return $out;
        };
        if (!in_array($do, self::ACTIONS, true)) {
            return $done(false, 'error', 'That button isn\'t recognised. Reload the page and try again.', array(), 'action');
        }
        $nonce = isset($p['_wpnonce']) && is_string($p['_wpnonce']) ? $p['_wpnonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::nonce_action($do, $id))) {
            return $done(false, 'error', 'This page has expired, so nothing was changed. Reload it and try again.', array(), 'nonce');
        }
        if ($do === 'settings') {
            $r = TeamOversight_Keep_It_Up::save_settings(array(
                'on' => !empty($p['on']),
                'page_id' => isset($p['page_id']) && is_scalar($p['page_id']) ? (string) $p['page_id'] : '',
            ), $user_id);
            if (!$r['ok']) {
                return $done(false, 'error', 'Nothing was saved.', $r['errors'], 'engine');
            }
            return $done(true, 'success', $r['message'], $r['changed'], '');
        }
        $r = TeamOversight_Keep_It_Up::remove_score($id, $user_id);
        $out['redirect'] = self::page_url() . '#kiu-boards';
        return $done($r['ok'], $r['ok'] ? 'success' : 'error', $r['message'], array(), $r['ok'] ? '' : 'engine');
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        echo '<div class="wrap murvc-kiu-admin">';
        self::styles();
        echo '<h1>Keep it up</h1>';
        self::render_flash();
        if (!TeamOversight_Keep_It_Up::tables_exist()) {
            echo '<div class="notice notice-error"><p>Keep it up isn\'t set up on this site yet. Reload the page.</p></div></div>';
            return;
        }
        echo '<p>A volleyball game members can play on the website, with a leaderboard for logged-in members. Names show as first name and last initial; members can untick <em>Show me on the leaderboard</em> to stay off it.</p>';
        self::render_settings();
        $month = TeamOversight_Keep_It_Up::month_key();
        echo '<div id="kiu-boards">';
        self::render_board('This month (' . TeamOversight_Keep_It_Up::month_label($month) . ')', TeamOversight_Keep_It_Up::admin_board($month), 'No scores yet this month.');
        self::render_board('All time', TeamOversight_Keep_It_Up::admin_board(null), 'No scores yet.');
        echo '</div>';
        echo '<p class="description">Remove takes a score off the boards and keeps it on record; the player\'s next best shows in its place. Each removal and each change above is logged on Club Membership &rarr; Logs as <em>Keep it up</em>.</p>';
        echo '</div>';
    }

    private static function styles() {
        echo '<style>
.murvc-kiu-admin .kiu-section { background: #fff; border: 1px solid #dcdcde; padding: 2px 18px 14px; margin: 0 0 16px; max-width: 900px; }
.murvc-kiu-admin .kiu-section h2 { margin-top: 14px; }
.murvc-kiu-admin .kiu-warn { color: #8a5a00; }
.murvc-kiu-admin .kiu-lines { margin: 4px 0 8px 18px; list-style: disc; }
.murvc-kiu-admin table.widefat { max-width: 900px; margin-bottom: 18px; }
.murvc-kiu-admin .kiu-hidden { display: inline-block; padding: 1px 8px; border-radius: 10px; background: #f0f0f1; font-size: 12px; }
.murvc-kiu-admin form.kiu-inline { display: inline; margin: 0; }
.murvc-kiu-admin code { user-select: all; }
</style>';
    }

    private static function render_settings() {
        $s = TeamOversight_Keep_It_Up::settings();
        echo '<div class="kiu-section"><h2>Settings</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="settings">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action('settings'))) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">The game</th><td><label><input type="checkbox" name="on" value="1"' . ($s['on'] ? ' checked' : '') . '> Switch the game on</label>'
            . '<p class="description">Off hides the footer link and shows nothing where the game\'s shortcode is. Scores are kept either way.</p></td></tr>';
        $dropdown = wp_dropdown_pages(array(
            'name' => 'page_id',
            'id' => 'kiu-page',
            'selected' => $s['page_id'],
            'echo' => 0,
            'show_option_none' => '(none)',
            'option_none_value' => '0',
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
        ));
        echo '<tr><th scope="row"><label for="kiu-page">Game page</label></th><td>' . ($dropdown !== '' ? $dropdown : '<input type="hidden" name="page_id" value="0"><em>No pages yet.</em>');
        echo '<p class="description">The page with <code>[club_keep_it_up]</code> on it. The footer link goes there, and shows on every page only while the game is on and this page is published.</p>';
        foreach (self::page_warnings($s) as $w) {
            echo '<p class="kiu-warn">' . esc_html($w) . '</p>';
        }
        echo '</td></tr></tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">Save</button></p></form>';
        echo '<p>For the 404 page, add <code>[club_keep_it_up compact="1"]</code>: a smaller card with Play, this month\'s top 3 and a link to the game page.</p>';
        echo '<p class="description">Pages the site\'s page cache already holds show the footer link (or stop showing it) once that cache is cleared.</p>';
        echo '</div>';
    }

    /** What stops the footer link or the game showing, in plain words. */
    public static function page_warnings(array $s) {
        $out = array();
        if ($s['page_id'] <= 0) {
            if ($s['on']) {
                $out[] = 'No game page is set, so there is no footer link.';
            }
            return $out;
        }
        $post = get_post($s['page_id']);
        if (!$post) {
            $out[] = 'The page set here doesn\'t exist any more, so there is no footer link.';
            return $out;
        }
        if ($post->post_status !== 'publish') {
            $out[] = 'This page isn\'t published, so there is no footer link yet.';
        }
        if (!has_shortcode((string) $post->post_content, TeamOversight_Keep_It_Up_Public::SHORTCODE)) {
            $out[] = 'This page doesn\'t have [club_keep_it_up] on it yet.';
        }
        return $out;
    }

    private static function render_board($title, array $rows, $empty) {
        echo '<h2>' . esc_html($title) . '</h2>';
        if (!$rows) {
            echo '<p>' . esc_html($empty) . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Place</th><th>Name on the board</th><th>Account</th><th>Score</th><th>Got it</th><th>Shown</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $user = get_userdata($r['user_id']);
            $account = $user ? TeamOversight_Email::decode((string) $user->display_name) : '#' . $r['user_id'];
            $ts = strtotime((string) $r['achieved_gmt'] . ' UTC');
            $confirm = 'Remove this score of ' . number_format($r['score']) . '? It stays on record but leaves the boards, and their next best shows.';
            echo '<tr>'
                . '<td data-sort="' . ($r['place'] > 0 ? intval($r['place']) : 9999) . '">' . ($r['place'] > 0 ? intval($r['place']) : '&mdash;') . '</td>'
                . '<td>' . esc_html($r['name']) . '</td>'
                . '<td><a href="' . esc_url(admin_url('admin.php?page=club-membership-lookup&user=' . intval($r['user_id']))) . '">' . esc_html($account) . '</a></td>'
                . '<td>' . esc_html(number_format($r['score'])) . '</td>'
                . '<td data-sort="' . esc_attr((string) $r['achieved_gmt']) . '">' . esc_html($ts ? wp_date('j M Y, g:ia', $ts) : '') . '</td>'
                . '<td>' . ($r['hidden'] ? '<span class="kiu-hidden">Hidden</span>' : 'Yes') . '</td>'
                . '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="kiu-inline" onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
                . '<input type="hidden" name="do" value="remove">'
                . '<input type="hidden" name="score" value="' . intval($r['id']) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action('remove', $r['id']))) . '">'
                . '<button type="submit" class="button button-small">Remove</button></form></td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }
}
