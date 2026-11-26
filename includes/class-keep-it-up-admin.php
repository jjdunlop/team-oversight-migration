<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Membership -> Keep it up (1.98.0, club request 25 Nov 2026): the
 * game on or off, the page that holds it (for the footer link), and the two
 * boards (top 50 this year and all time, hidden players marked; this
 * month until 1.98.3) with
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
    const ACTIONS = array('settings', 'remove', 'keep_off', 'put_back');
    /** 1.98.4: under the boards, what the Check column means. */
    const CHECK_WORDS = 'Check (from 1.98.4): the game ignores scripted taps and key presses, and each saved score keeps a few facts about how it was played. A score is marked when its timing was very steady or every tap landed dead centre, which a script does and a person doesn\'t. A mark is a reason to look, not proof: Remove or Keep off are yours to decide. Scores saved before 1.98.4 have no facts.';

    /** By the on/off switch: administrators still see the game while it's off. */
    const PREVIEW_WORDS = 'Administrators still see the game on its page while it\'s off, so it can be checked first (a draft page works through Preview).';

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

    /** One nonce per button: Remove by score id, Keep off and Put back (1.98.4) by account id. */
    public static function nonce_action($do, $id = 0) {
        return 'murvc_keep_it_up_' . $do . (in_array($do, array('remove', 'keep_off', 'put_back'), true) ? '_' . intval($id) : '');
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
        $field = in_array($do, array('keep_off', 'put_back'), true) ? 'user' : 'score';
        $id = isset($p[$field]) && is_scalar($p[$field]) && ctype_digit((string) $p[$field]) ? intval($p[$field]) : 0;
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
        if ($do === 'keep_off' || $do === 'put_back') {
            $r = TeamOversight_Keep_It_Up::set_kept_off($id, $do === 'keep_off', $user_id);
            $out['redirect'] = self::page_url() . '#kiu-boards';
            return $done($r['ok'], $r['ok'] ? 'success' : 'error', $r['message'], array(), $r['ok'] ? '' : 'engine');
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
        $year = TeamOversight_Keep_It_Up::year_key();
        echo '<div id="kiu-boards">';
        self::render_board('This year (' . $year . ')', TeamOversight_Keep_It_Up::admin_board($year), 'No scores yet this year.');
        self::render_board('All time', TeamOversight_Keep_It_Up::admin_board(null), 'No scores yet.');
        self::render_kept_off();
        echo '</div>';
        echo '<p class="description">Remove takes a score off the boards and keeps it on record; the player\'s next best shows in its place. Keep off takes a player off the leaderboard altogether (their scores are kept and still saved, and they can\'t put themselves back); Put back undoes it. Each removal and each change above is logged on Club Membership &rarr; Logs as <em>Keep it up</em>.</p>';
        echo '<p class="description">' . esc_html(self::CHECK_WORDS) . '</p>';
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
.murvc-kiu-admin .kiu-kept-off { background: #fcf0f1; color: #8a2424; }
.murvc-kiu-admin .kiu-flag { display: inline-block; padding: 1px 8px; border-radius: 10px; background: #fcf9e8; border: 1px solid #dba617; color: #6b4f00; font-size: 12px; margin: 0 4px 2px 0; }
.murvc-kiu-admin .kiu-facts { display: block; font-size: 12px; color: #646970; }
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
            . '<p class="description">Off hides the footer link and shows nothing where the game\'s shortcode is. Scores are kept either way.</p>'
            . '<p class="description">' . esc_html(self::PREVIEW_WORDS) . '</p></td></tr>';
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
        echo '<p>For the 404 page, add <code>[club_keep_it_up compact="1"]</code>: a smaller card with Play, this year\'s top 3 and a link to the game page.</p>';
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
        echo '<table class="widefat striped"><thead><tr><th>Place</th><th>Name on the board</th><th>Account</th><th>Score</th><th>Got it</th><th>Shown</th><th>Check</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $user = get_userdata($r['user_id']);
            $account = $user ? TeamOversight_Email::decode((string) $user->display_name) : '#' . $r['user_id'];
            $ts = strtotime((string) $r['achieved_gmt'] . ' UTC');
            $confirm = 'Remove this score of ' . number_format($r['score']) . '? It stays on record but leaves the boards, and their next best shows.';
            $kept_off = !empty($r['kept_off']);
            $shown = $kept_off ? '<span class="kiu-hidden kiu-kept-off">Kept off</span>' : ($r['hidden'] ? '<span class="kiu-hidden">Hidden</span>' : 'Yes');
            echo '<tr>'
                . '<td data-sort="' . ($r['place'] > 0 ? intval($r['place']) : 9999) . '">' . ($r['place'] > 0 ? intval($r['place']) : '&mdash;') . '</td>'
                . '<td>' . esc_html($r['name']) . '</td>'
                . '<td><a href="' . esc_url(admin_url('admin.php?page=club-membership-lookup&user=' . intval($r['user_id']))) . '">' . esc_html($account) . '</a></td>'
                . '<td>' . esc_html(number_format($r['score'])) . '</td>'
                . '<td data-sort="' . esc_attr((string) $r['achieved_gmt']) . '">' . esc_html($ts ? wp_date('j M Y, g:ia', $ts) : '') . '</td>'
                . '<td>' . $shown . '</td>'
                . '<td>' . self::check_cell(isset($r['stats']) ? $r['stats'] : null) . '</td>'
                . '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="kiu-inline" onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
                . '<input type="hidden" name="do" value="remove">'
                . '<input type="hidden" name="score" value="' . intval($r['id']) . '">'
                . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action('remove', $r['id']))) . '">'
                . '<button type="submit" class="button button-small">Remove</button></form> '
                . self::keep_off_form($r['user_id'], !$kept_off, $r['name'])
                . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    /** 1.98.4: a score's Check cell: its flags in words, then its facts small, or a dash for a score saved before 1.98.4. */
    private static function check_cell($stats) {
        if (!is_array($stats)) {
            return '<span class="description">&mdash;</span>';
        }
        $html = '';
        foreach ($stats['f'] as $flag) {
            $html .= '<span class="kiu-flag">' . esc_html(TeamOversight_Keep_It_Up::FLAG_WORDS[$flag]) . '</span> ';
        }
        $words = TeamOversight_Keep_It_Up::stats_words($stats);
        if ($words !== '') {
            $html .= '<span class="kiu-facts">' . esc_html($words) . '</span>';
        }
        return $html !== '' ? $html : '<span class="description">&mdash;</span>';
    }

    /** 1.98.4: the Keep off (or Put back) button for one account. */
    private static function keep_off_form($user_id, $keep_off, $name) {
        $do = $keep_off ? 'keep_off' : 'put_back';
        $confirm = $keep_off
            ? 'Keep ' . $name . ' off the leaderboard? Their scores are kept and still saved, they can\'t put themselves back, and Put back undoes it.'
            : 'Put ' . $name . ' back on the leaderboard?';
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="kiu-inline" onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">'
            . '<input type="hidden" name="user" value="' . intval($user_id) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action($do, $user_id))) . '">'
            . '<button type="submit" class="button button-small">' . ($keep_off ? 'Keep off' : 'Put back') . '</button></form>';
    }

    /** 1.98.4: the players the club keeps off the leaderboard, with who did it, when, and Put back. */
    private static function render_kept_off() {
        $list = TeamOversight_Keep_It_Up::kept_off_list();
        echo '<h2>Kept off the leaderboard</h2>';
        if (!$list) {
            echo '<p>Nobody is kept off.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Name on the board</th><th>Account</th><th>Kept off by</th><th>When</th><th></th></tr></thead><tbody>';
        foreach ($list as $uid => $facts) {
            $user = get_userdata($uid);
            if (!$user) {
                continue;
            }
            $by = $facts['by'] > 0 ? get_userdata($facts['by']) : false;
            $ts = $facts['at'] !== '' ? strtotime($facts['at'] . ' UTC') : false;
            $name = TeamOversight_Keep_It_Up::board_name($uid);
            echo '<tr>'
                . '<td>' . esc_html($name) . '</td>'
                . '<td><a href="' . esc_url(admin_url('admin.php?page=club-membership-lookup&user=' . intval($uid))) . '">' . esc_html(TeamOversight_Email::decode((string) $user->display_name)) . '</a></td>'
                . '<td>' . esc_html($by ? TeamOversight_Email::decode((string) $by->display_name) : '') . '</td>'
                . '<td data-sort="' . esc_attr($facts['at']) . '">' . esc_html($ts ? wp_date('j M Y, g:ia', $ts) : '') . '</td>'
                . '<td>' . self::keep_off_form($uid, false, $name) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }
}
