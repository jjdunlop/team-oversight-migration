<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Membership -> Mailouts (1.97.0, club request 25 Nov 2026): the screens
 * of Club mailouts. The engine (who gets it, the drafts, Send, the paced
 * sender, the unsubscribe) is TeamOversight_Mailouts; this class draws its
 * views and posts to it, and holds no rule of its own.
 *
 * Views of ?page=club-membership-mailouts:
 *  - no mailout: the list (the pace note, the gate warning, the table, the
 *    unsubscribed count);
 *  - &mailout=new, or a draft's id: the edit view (Kind, Who gets it, The
 *    email, Check it: Who will get this, Preview, Save and send me a test;
 *    Send, greyed with its reasons until a test of exactly this version was
 *    sent; Delete draft);
 *  - a draft with &view=confirm&version=N: Send's confirm view (the count,
 *    the finish estimate, Confirm send);
 *  - a mailout that was sent: the read-only view (progress, Pause / Resume /
 *    Cancel the rest, the email as sent, the recipients and their CSV, Copy
 *    as new draft).
 *
 * Every button posts to admin-post murvc_mailout (handle_post() ->
 * process_post()) with a nonce per action and mailout
 * (murvc_mailout_<action>_<id>, id 0 for a new draft) and the version (or,
 * for Pause, Resume and Cancel the rest, the state) the page was drawn from,
 * so a stale page changes nothing and says so. Then Post/Redirect/Get: the
 * notice lives for one redisplay in the transient FLASH_PREFIX + user id,
 * taken on the page's load-{hook}. A refused save keeps what was typed in
 * the flash and draws it again. The CSVs are admin-post murvc_mailout_csv
 * (read-only, nonce murvc_mailout_csv_<id>, no-cache headers, every cell
 * through TeamOversight_Exports::csv_cell()). Administrators only
 * (manage_options) everywhere, and every engine writer checks it again.
 */
class TeamOversight_Mailouts_Admin {

    const PAGE_SLUG = 'club-membership-mailouts';
    const ACTION = 'murvc_mailout';
    const CSV_ACTION = 'murvc_mailout_csv';
    const FLASH_PREFIX = 'murvc_mailout_flash_';
    const FLASH_SECONDS = 300;
    /** What a button can ask for (do=...). Save carries then=count or then=test for its two other buttons. */
    const ACTIONS = array('save', 'send', 'pause', 'resume', 'cancel', 'copy', 'delete');
    /** At most this many people are listed on a page (the CSV has everyone). */
    const LIST_MAX = 1000;
    /** The engine's reason for a draft with no test of this version (send_problems()). */
    const TEST_REASON = 'Send yourself a test of this version first.';

    private static $hooks_registered = false;
    /** The page's hook suffix, for load-{hook} and the media picker. */
    private static $hook = '';
    /** The flash taken for this request (load_page(), else render_page()). */
    private static $flash = null;
    private static $flash_taken = false;
    private static $names = array();
    private static $program_names = null;

    // ------------------------------------------------------------------
    // Hooks and the menu
    // ------------------------------------------------------------------

    /** Added once, from team_oversight_init(). */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_post'));
        add_action('admin_post_' . self::CSV_ACTION, array(__CLASS__, 'handle_csv'));
        if (is_admin()) {
            // After TeamOversight_Admin::add_admin_menu() (priority 10) has made Club Membership.
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 20);
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        }
    }

    public static function add_menu() {
        $hook = add_submenu_page('club-membership', 'Club mailouts', 'Mailouts', 'manage_options', self::PAGE_SLUG, array(__CLASS__, 'render_page'));
        if (is_string($hook) && $hook !== '') {
            self::$hook = $hook;
            add_action('load-' . $hook, array(__CLASS__, 'load_page'));
        }
    }

    /**
     * load-{hook}: take this administrator's flash before anything is printed,
     * and put the sender's five-minute event back if a mailout is sending
     * without one (TeamOversight_Mailouts::heal_schedule()).
     */
    public static function load_page() {
        if (current_user_can('manage_options')) {
            self::$flash = self::take_flash(get_current_user_id());
            self::$flash_taken = true;
            TeamOversight_Mailouts::heal_schedule(true);
            TeamOversight_Mailouts::ensure_secret();
        }
    }

    /** WordPress's media picker, on the edit view only. */
    public static function enqueue($hook) {
        if (self::$hook === '' || $hook !== self::$hook || !current_user_can('manage_options')) {
            return;
        }
        $raw = self::get_param('mailout');
        if ($raw === 'new') {
            wp_enqueue_media();
            return;
        }
        $row = ctype_digit($raw) ? TeamOversight_Mailouts::get(intval($raw)) : null;
        if ($row && $row->state === 'draft' && self::get_param('view') !== 'confirm') {
            wp_enqueue_media();
        }
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    public static function page_url($args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_SLUG));
    }

    /** One mailout's page ($id 'new' for a new draft), with an optional #anchor. */
    public static function mailout_url($id, $args = array(), $anchor = '') {
        return self::page_url(array_merge(array('mailout' => $id === 'new' ? 'new' : intval($id)), $args)) . ($anchor !== '' ? '#' . $anchor : '');
    }

    private static function get_param($key) {
        return isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_key(wp_unslash((string) $_GET[$key])) : '';
    }

    public static function nonce_action($do, $id) {
        return 'murvc_mailout_' . $do . '_' . intval($id);
    }

    private static function h($text) {
        return esc_html(TeamOversight_Email::decode((string) $text));
    }

    private static function people($n) {
        $n = intval($n);
        return number_format($n) . ' ' . ($n === 1 ? 'person' : 'people');
    }

    private static function d($mysql) {
        $ts = $mysql ? strtotime((string) $mysql) : false;
        return $ts ? date('j M Y', $ts) : '';
    }

    private static function dt($mysql) {
        $ts = $mysql ? strtotime((string) $mysql) : false;
        return $ts ? date('j M Y, g:ia', $ts) : '';
    }

    /** A timestamp as "4:30pm today", "4:30pm tomorrow" or "4:30pm Friday 27 November" (site time). */
    private static function when_words($ts) {
        $ts = intval($ts);
        $day = wp_date('Y-m-d', $ts);
        if ($day === wp_date('Y-m-d')) {
            return wp_date('g:ia', $ts) . ' today';
        }
        if ($day === wp_date('Y-m-d', time() + DAY_IN_SECONDS)) {
            return wp_date('g:ia', $ts) . ' tomorrow';
        }
        return wp_date('g:ia l j F', $ts);
    }

    /** An account's name for the By columns (decoded; 'user #ID' when it's gone). */
    private static function name_of($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return '';
        }
        if (!isset(self::$names[$user_id])) {
            $user = get_userdata($user_id);
            self::$names[$user_id] = $user ? TeamOversight_Email::decode((string) $user->display_name) : 'user #' . $user_id;
        }
        return self::$names[$user_id];
    }

    private static function kind_label($kind) {
        return $kind === 'news' ? 'News' : ($kind === 'notice' ? 'Notice' : '');
    }

    private static function kind_words($kind) {
        return $kind === 'news' ? 'Club news' : ($kind === 'notice' ? 'Club notice' : 'Kind not picked yet');
    }

    /** A mailout's state in words: Draft, Sending, Paused, Sent, Cancelled. */
    public static function state_label($state) {
        $labels = array('draft' => 'Draft', 'sending' => 'Sending', 'paused' => 'Paused', 'done' => 'Sent', 'cancelled' => 'Cancelled');
        return isset($labels[$state]) ? $labels[$state] : (string) $state;
    }

    /** A recipient row's groups as words, naming the Club Program when the row records it. */
    public static function groups_words($groups) {
        list($keys, $program) = TeamOversight_Mailouts::parse_groups($groups);
        $labels = TeamOversight_Mailouts::group_types();
        $out = array();
        foreach ($keys as $key) {
            $words = $labels[$key];
            if ($key === 'programs' && $program !== '') {
                if (self::$program_names === null) {
                    self::$program_names = TeamOversight_Mailouts::program_choices();
                }
                if (isset(self::$program_names[$program])) {
                    $words .= ' (' . self::$program_names[$program] . ')';
                }
            }
            $out[] = $words;
        }
        return implode(', ', $out);
    }

    /** The groups the preview and the sent view write the why line for: the first included group (its first ticked program). */
    private static function preview_groups($audience) {
        $a = TeamOversight_Mailouts::normalise_audience($audience);
        foreach (TeamOversight_Mailouts::GROUPS as $g) {
            if (!empty($a[$g]['on'])) {
                $program = ($g === 'programs' && !$a['programs']['all'] && $a['programs']['programs']) ? $a['programs']['programs'][0] : '';
                return TeamOversight_Mailouts::group_string(array($g), $program);
            }
        }
        return '';
    }

    /** A post form's opening tag and its hidden fields (action, do, mailout, the nonce, $hidden). */
    private static function form_open($do, $id, array $hidden = array(), $confirm = '', $class = 'mo-inline') {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="' . esc_attr($class) . '"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '') . '>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">'
            . '<input type="hidden" name="mailout" value="' . intval($id) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action($do, $id))) . '">';
        foreach ($hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        return $html;
    }

    private static function csv_url($id, $what) {
        return add_query_arg(array(
            'action' => self::CSV_ACTION,
            'mailout' => intval($id),
            'what' => $what,
            '_wpnonce' => wp_create_nonce('murvc_mailout_csv_' . intval($id)),
        ), admin_url('admin-post.php'));
    }

    // ------------------------------------------------------------------
    // The flash (Post/Redirect/Get)
    // ------------------------------------------------------------------

    /**
     * $flash: type (success, warning, error), message, lines (sentences), id
     * (the mailout it is about, 0 for a new draft), typed (a refused save's
     * fields, drawn again) and stale_typed (a save refused because the draft
     * changed meanwhile: what was typed, shown to copy, never drawn into the
     * form).
     */
    private static function set_flash($user_id, array $flash) {
        set_transient(self::FLASH_PREFIX . intval($user_id), array_merge(array('type' => 'error', 'message' => '', 'lines' => array(), 'id' => 0, 'typed' => null, 'stale_typed' => null), $flash), self::FLASH_SECONDS);
    }

    /** The notice the last button left for this administrator (once), or null. */
    public static function take_flash($user_id) {
        $key = self::FLASH_PREFIX . intval($user_id);
        $flash = get_transient($key);
        if (!is_array($flash)) {
            return null;
        }
        delete_transient($key);
        return $flash;
    }

    private static function flash() {
        if (!self::$flash_taken) {
            self::$flash = self::take_flash(get_current_user_id());
            self::$flash_taken = true;
        }
        return self::$flash;
    }

    private static function render_flash() {
        $f = self::flash();
        if (!is_array($f) || (string) $f['message'] === '') {
            return;
        }
        $type = in_array($f['type'], array('success', 'warning', 'error'), true) ? $f['type'] : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($f['message']) . '</p>';
        if (!empty($f['lines']) && is_array($f['lines'])) {
            echo '<ul class="mo-lines">';
            foreach ($f['lines'] as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }
            echo '</ul>';
        }
        if (is_array($f['stale_typed'])) {
            $t = $f['stale_typed'];
            echo '<details><summary>What you typed (not saved)</summary>'
                . '<p><strong>Subject:</strong> ' . esc_html(isset($t['subject']) ? (string) $t['subject'] : '') . '</p>'
                . '<textarea readonly rows="8" class="large-text">' . esc_textarea(isset($t['body']) ? (string) $t['body'] : '') . '</textarea></details>';
        }
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // The handlers
    // ------------------------------------------------------------------

    /** admin-post murvc_mailout: every button. manage_options, then process_post(), then back to the page. */
    public static function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Only administrators can change mailouts.'), '', array('response' => 403));
        }
        $out = self::process_post($_POST, get_current_user_id());
        wp_safe_redirect($out['redirect']);
        exit;
    }

    /**
     * The draft's fields from a slashed post, in TeamOversight_Mailouts::
     * clean_fields()' shape (which cleans and checks them): kind, subject,
     * heading, preheader, body, button_label, button_url, image_id,
     * image_alt, reply_to, audience (the form's array: group => options, as
     * normalise_audience() reads it) and version (the one the form was drawn
     * from).
     */
    public static function fields_from_post($post) {
        $p = is_array($post) ? wp_unslash($post) : array();
        $text = function ($key) use ($p) {
            return isset($p[$key]) && is_string($p[$key]) ? $p[$key] : '';
        };
        return array(
            'kind' => $text('kind'),
            'subject' => $text('subject'),
            'heading' => $text('heading'),
            'preheader' => $text('preheader'),
            'body' => $text('body'),
            'button_label' => $text('button_label'),
            'button_url' => $text('button_url'),
            'image_id' => isset($p['image_id']) && is_scalar($p['image_id']) ? absint($p['image_id']) : 0,
            'image_alt' => $text('image_alt'),
            'reply_to' => $text('reply_to'),
            'audience' => isset($p['audience']) && is_array($p['audience']) ? $p['audience'] : array(),
            'version' => isset($p['version']) && is_scalar($p['version']) ? intval($p['version']) : 0,
        );
    }

    /**
     * One button, without the redirect (the tests call it). $post: the
     * slashed request. Refuses anyone without manage_options and a nonce
     * that isn't this action's and this mailout's; a page drawn from another
     * version (or, for Pause, Resume and Cancel the rest, another state)
     * changes nothing. Leaves the flash (not for a refused non-administrator)
     * and returns array(ok, type, message, lines, redirect, id, refused
     * ('' or why: cap, action, nonce, missing, sent, stale, engine)).
     */
    public static function process_post($post, $user_id) {
        $raw = is_array($post) ? $post : array();
        $p = wp_unslash($raw);
        $user_id = intval($user_id);
        $do = isset($p['do']) && is_string($p['do']) ? sanitize_key($p['do']) : '';
        $raw_id = isset($p['mailout']) && is_scalar($p['mailout']) ? trim((string) $p['mailout']) : '';
        $id = ctype_digit($raw_id) ? intval($raw_id) : 0;
        $out = array('ok' => false, 'type' => 'error', 'message' => '', 'lines' => array(), 'redirect' => self::page_url(), 'id' => $id, 'refused' => '');

        if ($user_id <= 0 || !user_can($user_id, 'manage_options')) {
            $out['message'] = 'Only administrators can change mailouts.';
            $out['refused'] = 'cap';
            return $out;
        }
        $done = function ($ok, $message, $redirect, array $extra = array()) use (&$out, $user_id, $id) {
            $out['ok'] = (bool) $ok;
            $out['type'] = isset($extra['type']) ? $extra['type'] : ($ok ? 'success' : 'error');
            $out['message'] = $message;
            $out['lines'] = isset($extra['lines']) ? array_values($extra['lines']) : array();
            $out['redirect'] = $redirect;
            $out['refused'] = $ok ? '' : (isset($extra['refused']) ? $extra['refused'] : 'engine');
            if (isset($extra['id'])) {
                $out['id'] = intval($extra['id']);
            }
            self::set_flash($user_id, array(
                'type' => $out['type'],
                'message' => $message,
                'lines' => $out['lines'],
                'id' => isset($extra['id']) ? intval($extra['id']) : $id,
                'typed' => isset($extra['typed']) ? $extra['typed'] : null,
                'stale_typed' => isset($extra['stale_typed']) ? $extra['stale_typed'] : null,
            ));
            return $out;
        };
        $back = $id > 0 ? self::mailout_url($id) : self::page_url();

        if (!in_array($do, self::ACTIONS, true)) {
            return $done(false, 'That button isn\'t recognised. Reload the page and try again.', $back, array('refused' => 'action'));
        }
        $nonce = isset($p['_wpnonce']) && is_string($p['_wpnonce']) ? $p['_wpnonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::nonce_action($do, $id))) {
            return $done(false, 'This page has expired, so nothing was changed. Reload it and try again.', $back, array('refused' => 'nonce'));
        }
        if (!TeamOversight_Mailouts::tables_exist()) {
            return $done(false, 'Mailouts aren\'t set up on this site yet. Reload the page.', self::page_url(), array('refused' => 'missing'));
        }
        $version = isset($p['version']) && is_scalar($p['version']) ? intval($p['version']) : -1;

        if ($do === 'save') {
            return self::process_save($raw, $p, $id, $user_id, $done);
        }

        $row = $id > 0 ? TeamOversight_Mailouts::get($id) : null;
        if (!$row) {
            return $done(false, 'That mailout doesn\'t exist any more.', self::page_url(), array('refused' => 'missing'));
        }
        $view = self::mailout_url($id);
        $stale_draft = function ($what) use ($row, $version) {
            return $row->state === 'draft' && intval($row->version) !== $version
                ? 'This draft was changed in another tab, or by another administrator, since you opened it, so ' . $what . '. Look at it again.'
                : '';
        };

        if ($do === 'send') {
            if ($row->state !== 'draft') {
                return $done(false, 'This mailout has already been sent.', $view, array('refused' => 'sent'));
            }
            $stale = $stale_draft('nothing was sent');
            if ($stale !== '') {
                return $done(false, $stale, $view, array('refused' => 'stale'));
            }
            $shown = isset($p['shown_total']) && is_scalar($p['shown_total']) ? intval($p['shown_total']) : -1;
            $r = TeamOversight_Mailouts::send($id, $version, $shown, $user_id);
            if (!$r['ok']) {
                $fresh = TeamOversight_Mailouts::get($id);
                $to = ($fresh && $fresh->state === 'draft') ? self::mailout_url($id, array('view' => 'confirm', 'version' => intval($fresh->version))) : $view;
                return $done(false, 'Nothing was sent.', $to, array('lines' => $r['errors']));
            }
            $live = class_exists('TeamOversight_Offers') && TeamOversight_Offers::emails_live();
            return $done(true, 'Sending to ' . self::people($r['total']) . ' has started.'
                . ($live ? ' The first emails go within a few minutes.' : ' Emails are held on this copy of the site, so nothing will go until it is the live site and offer emails are allowed.'), $view);
        }

        if (in_array($do, array('pause', 'resume', 'cancel'), true)) {
            $shown_state = isset($p['shown_state']) && is_string($p['shown_state']) ? sanitize_key($p['shown_state']) : '';
            if ($shown_state !== (string) $row->state) {
                return $done(false, 'This mailout changed since the page loaded (it is now ' . strtolower(self::state_label($row->state)) . '), so nothing was changed. Look at it again.', $view, array('refused' => 'stale'));
            }
            if ($do === 'pause') {
                $r = TeamOversight_Mailouts::pause($id, $user_id);
                return $r['ok'] ? $done(true, 'Paused. Nothing more goes until you resume it.', $view) : $done(false, 'It wasn\'t paused.', $view, array('lines' => $r['errors']));
            }
            if ($do === 'resume') {
                $r = TeamOversight_Mailouts::resume($id, $user_id);
                return $r['ok'] ? $done(true, 'Resumed. The rest go at the usual pace.', $view) : $done(false, 'It wasn\'t resumed.', $view, array('lines' => $r['errors']));
            }
            $r = TeamOversight_Mailouts::cancel_rest($id, $user_id);
            return $r['ok']
                ? $done(true, 'Cancelled the rest: ' . self::people($r['cancelled']) . ' won\'t get it.', $view)
                : $done(false, 'Nothing was cancelled.', $view, array('lines' => $r['errors']));
        }

        if ($do === 'copy') {
            $stale = $stale_draft('it wasn\'t copied');
            if ($stale !== '') {
                return $done(false, $stale, $view, array('refused' => 'stale'));
            }
            $r = TeamOversight_Mailouts::copy_as_draft($id, $user_id);
            return $r['ok']
                ? $done(true, 'Copied as a new draft. Change what you need, send yourself a test, then send it.', self::mailout_url($r['id']), array('id' => $r['id']))
                : $done(false, 'It wasn\'t copied.', $view, array('lines' => $r['errors']));
        }

        // delete
        if ($row->state !== 'draft') {
            return $done(false, 'A mailout that was sent is never deleted.', $view, array('refused' => 'sent'));
        }
        $stale = $stale_draft('it wasn\'t deleted');
        if ($stale !== '') {
            return $done(false, $stale, $view, array('refused' => 'stale'));
        }
        return TeamOversight_Mailouts::delete_draft($id, $user_id)
            ? $done(true, 'Draft deleted.', self::page_url())
            : $done(false, 'The draft wasn\'t deleted. Reload the page and try again.', $view);
    }

    /** Save draft, and its two other buttons: Save and count again (then=count), Save and send me a test (then=test). */
    private static function process_save($raw, $p, $id, $user_id, $done) {
        $fields = self::fields_from_post($raw);
        $then = isset($p['then']) && is_string($p['then']) ? sanitize_key($p['then']) : '';
        if ($id > 0) {
            $row = TeamOversight_Mailouts::get($id);
            if (!$row) {
                return $done(false, 'That mailout doesn\'t exist any more.', self::page_url(), array('refused' => 'missing'));
            }
            if ($row->state !== 'draft') {
                return $done(false, 'This mailout has been sent, so it can\'t be changed. Copy it as a new draft to send a changed version.', self::mailout_url($id), array('refused' => 'sent'));
            }
            if (intval($row->version) !== $fields['version']) {
                return $done(false, 'This draft was changed in another tab, or by another administrator, since you opened it, so your changes weren\'t saved. The latest version is shown; what you typed is below, to copy.', self::mailout_url($id), array('refused' => 'stale', 'stale_typed' => $fields));
            }
        }
        $r = TeamOversight_Mailouts::save_draft($id, $fields, $user_id);
        if (!$r['ok']) {
            if ($id > 0) {
                $fresh = TeamOversight_Mailouts::get($id);
                if ($fresh && intval($fresh->version) !== $fields['version']) {
                    return $done(false, 'This draft was changed in another tab, or by another administrator, since you opened it, so your changes weren\'t saved. The latest version is shown; what you typed is below, to copy.', self::mailout_url($id), array('refused' => 'stale', 'stale_typed' => $fields));
                }
            }
            return $done(false, 'The draft wasn\'t saved:', $id > 0 ? self::mailout_url($id) : self::mailout_url('new'), array('lines' => $r['errors'], 'typed' => $fields));
        }
        $new_id = intval($r['id']);
        if ($then === 'test') {
            $t = TeamOversight_Mailouts::send_test($new_id, $user_id);
            return $t['ok']
                ? $done(true, 'Saved, and a test went to ' . $t['to'] . '. Check it in your inbox, then send.', self::mailout_url($new_id, array(), 'check'), array('id' => $new_id))
                : $done(false, 'Saved, but no test was sent:', self::mailout_url($new_id, array(), 'check'), array('id' => $new_id, 'type' => 'warning', 'lines' => $t['errors']));
        }
        if ($then === 'count') {
            return $done(true, 'Saved. Who will get it is worked out below.', self::mailout_url($new_id, array(), 'who'), array('id' => $new_id));
        }
        return $done(true, 'Draft saved.', self::mailout_url($new_id), array('id' => $new_id));
    }

    // ------------------------------------------------------------------
    // The CSVs
    // ------------------------------------------------------------------

    /** admin-post murvc_mailout_csv: Who will get this (a draft) or the recipients (a sent mailout). Read-only. */
    public static function handle_csv() {
        $payload = self::csv_payload(wp_unslash($_GET), get_current_user_id());
        if (!$payload['ok']) {
            wp_die(esc_html($payload['message']), '', array('response' => $payload['status']));
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $payload['filename'] . '"');
        header('X-Content-Type-Options: nosniff');
        echo $payload['csv'];
        exit;
    }

    /**
     * The CSV for a request (unslashed): mailout, what (audience: the people
     * a draft would reach now, Name, Email, Groups; recipients: a sent
     * mailout's rows, with Status), _wpnonce (murvc_mailout_csv_<id>).
     * Returns array(ok, status, message, filename, csv).
     */
    public static function csv_payload(array $request, $user_id) {
        $fail = function ($status, $message) {
            return array('ok' => false, 'status' => $status, 'message' => $message, 'filename' => '', 'csv' => '');
        };
        if (intval($user_id) <= 0 || !user_can(intval($user_id), 'manage_options')) {
            return $fail(403, 'Only administrators can download this.');
        }
        $id = isset($request['mailout']) && is_scalar($request['mailout']) ? intval($request['mailout']) : 0;
        $nonce = isset($request['_wpnonce']) && is_string($request['_wpnonce']) ? $request['_wpnonce'] : '';
        if ($id <= 0 || $nonce === '' || !wp_verify_nonce($nonce, 'murvc_mailout_csv_' . $id)) {
            return $fail(403, 'This link has expired. Go back, reload the page and try again.');
        }
        $row = TeamOversight_Mailouts::get($id);
        if (!$row) {
            return $fail(404, 'That mailout doesn\'t exist any more.');
        }
        $rows = array();
        if ($row->state === 'draft') {
            $header = array('Name', 'Email', 'Groups');
            $resolved = TeamOversight_Mailouts::resolve(TeamOversight_Mailouts::audience_of($row), $row->kind === 'news' ? 'news' : 'notice');
            foreach ($resolved['people'] as $person) {
                $rows[] = array(
                    TeamOversight_Email::decode($person['name']),
                    $person['email'],
                    self::groups_words(TeamOversight_Mailouts::group_string($person['groups'], $person['program'])),
                );
            }
            $what = 'list';
        } else {
            $header = array('Name', 'Email', 'Groups', 'Status');
            foreach (TeamOversight_Mailouts::recipients($id) as $r) {
                $rows[] = array(
                    TeamOversight_Email::decode((string) $r->name),
                    (string) $r->email,
                    self::groups_words((string) $r->group_keys),
                    TeamOversight_Mailouts::status_words($r),
                );
            }
            $what = 'recipients';
        }
        return array(
            'ok' => true,
            'status' => 200,
            'message' => '',
            'filename' => 'mailout-' . $id . '-' . $what . '.csv',
            'csv' => self::csv_text($header, $rows),
        );
    }

    /** A CSV with the UTF-8 mark Excel needs, every cell through TeamOversight_Exports::csv_cell() (a leading = + - @ gets an apostrophe). */
    public static function csv_text(array $header, array $rows) {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map(array('TeamOversight_Exports', 'csv_cell'), array_map('strval', (array) $row)));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string) $csv;
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        echo '<div class="wrap murvc-mailouts">';
        self::styles();
        if (!TeamOversight_Mailouts::tables_exist()) {
            echo '<h1>Mailouts</h1><div class="notice notice-error"><p>Mailouts aren\'t set up on this site yet. Reload the page.</p></div></div>';
            return;
        }
        $raw = self::get_param('mailout');
        if ($raw === '') {
            self::render_list();
        } elseif ($raw === 'new') {
            self::render_edit(null);
        } else {
            $row = ctype_digit($raw) ? TeamOversight_Mailouts::get(intval($raw)) : null;
            if (!$row) {
                echo '<div class="notice notice-error"><p>That mailout doesn\'t exist any more.</p></div>';
                self::render_list();
            } elseif ($row->state === 'draft') {
                if (self::get_param('view') === 'confirm') {
                    self::render_confirm($row);
                } else {
                    self::render_edit($row);
                }
            } else {
                self::render_sent($row);
            }
        }
        echo '</div>';
    }

    private static function styles() {
        echo '<style>
.murvc-mailouts .mo-held { color: #b32d2e; font-weight: 600; }
.murvc-mailouts .mo-section { background: #fff; border: 1px solid #dcdcde; padding: 2px 18px 14px; margin: 0 0 16px; max-width: 1000px; }
.murvc-mailouts .mo-section h2 { margin-top: 14px; }
.murvc-mailouts .mo-group { border: 1px solid #dcdcde; border-radius: 4px; padding: 6px 14px 8px; margin: 0 0 12px; }
.murvc-mailouts .mo-group legend { font-weight: 600; font-size: 14px; padding: 0 6px; }
.murvc-mailouts .mo-group.is-off .mo-options { opacity: .55; }
.murvc-mailouts .mo-group p { margin: 6px 0; }
.murvc-mailouts .mo-label { display: inline-block; min-width: 70px; font-weight: 600; }
.murvc-mailouts .mo-ticks label { display: inline-block; margin: 0 14px 4px 0; }
.murvc-mailouts .mo-kind { display: block; margin: 10px 0 2px; font-size: 14px; }
.murvc-mailouts .mo-kind + .description { margin: 0 0 4px 24px; }
.murvc-mailouts .mo-chip { display: inline-block; padding: 1px 8px; border-radius: 10px; background: #f0f0f1; font-size: 12px; }
.murvc-mailouts .mo-chip.is-sending { background: #d1e4f6; }
.murvc-mailouts .mo-chip.is-paused { background: #fcf0d1; }
.murvc-mailouts .mo-chip.is-done { background: #d7f0dc; }
.murvc-mailouts .mo-bar { height: 10px; background: #e8e8e8; border-radius: 5px; overflow: hidden; max-width: 520px; margin: 6px 0 10px; }
.murvc-mailouts .mo-bar span { display: block; height: 100%; background: #46b450; }
.murvc-mailouts .mo-warn { color: #8a5a00; }
.murvc-mailouts .mo-danger { color: #b32d2e; }
.murvc-mailouts .mo-inline { display: inline-block; margin: 0 6px 6px 0; }
.murvc-mailouts .mo-actions { margin: 10px 0; }
.murvc-mailouts .mo-reasons, .murvc-mailouts .mo-lines { margin: 4px 0 8px 18px; list-style: disc; }
.murvc-mailouts #mo-image-img { display: block; max-width: 320px; height: auto; margin: 0 0 8px; border: 1px solid #dcdcde; }
.murvc-mailouts #mo-image-img[hidden], .murvc-mailouts [hidden] { display: none; }
.murvc-mailouts .mo-total { font-size: 15px; }
.murvc-mailouts table.widefat { max-width: 1000px; }
.murvc-mailouts .mo-meta { color: #50575e; }
</style>';
    }

    /**
     * The red line shown while emails can't leave this site, worded by why:
     * offer emails paused on this site, permission given to another address
     * (a copy of the site, or the site's address changed), or never given.
     * '' when they can go.
     */
    public static function held_line() {
        if (!class_exists('TeamOversight_Offers')) {
            return '<p class="mo-held">Emails are held on this copy of the site. Nothing will be sent until it is the live site and offer emails are allowed.</p>';
        }
        if (TeamOversight_Offers::emails_live()) {
            return '';
        }
        $gate = TeamOversight_Offers::email_gate();
        $host = TeamOversight_Offers::site_host();
        $given_to = !empty($gate['host']) ? (string) $gate['host'] : (!empty($gate['was_host']) ? (string) $gate['was_host'] : '');
        $where = ' <a href="' . esc_url(admin_url('admin.php?page=team-oversight-emails&tab=offers#offer-gate')) . '">The email permission</a> is on VVL Oversight &rarr; Emails, Offers and selection.';
        if ($given_to !== '' && $given_to === $host) {
            return '<p class="mo-held">Emails are held: offer emails are paused on this site. Nothing will be sent until an administrator allows them again.' . $where
                . ' A mailout held for more than a day is then paused for you to resume or cancel.</p>';
        }
        if ($given_to !== '') {
            return '<p class="mo-held">Emails are held: permission to send was given to ' . esc_html($given_to) . ', not to this address (' . esc_html($host)
                . '), so nothing is sent from here. If this is the live site and its address has changed, allow offer emails again.' . $where . '</p>';
        }
        return '<p class="mo-held">Emails are held on this copy of the site. Nothing will be sent until it is the live site and offer emails are allowed.</p>';
    }

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    private static function render_list() {
        echo '<h1 class="wp-heading-inline">Mailouts</h1> <a href="' . esc_url(self::mailout_url('new')) . '" class="page-title-action">Add mailout</a><hr class="wp-header-end">';
        self::render_flash();
        echo '<p>Mailouts go out at up to ' . number_format(TeamOversight_Mailouts::HOUR_CAP) . ' an hour and at most ' . number_format(TeamOversight_Mailouts::DAY_CAP)
            . ' a day, so the site\'s other emails keep working. Sent in the last 24 hours: ' . number_format(TeamOversight_Mailouts::sent_in_window(DAY_IN_SECONDS)) . '.</p>';
        echo self::held_line();
        $rows = TeamOversight_Mailouts::all();
        if (!$rows) {
            echo '<p>No mailouts yet. Press Add mailout to write the first one.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Subject</th><th>Kind</th><th>Status</th><th>Recipients</th><th>Sent</th><th>Problems</th><th>Last change</th><th>By</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $draft = $r->state === 'draft';
                $live_counts = in_array($r->state, array('sending', 'paused'), true) ? TeamOversight_Mailouts::counts(intval($r->id)) : null;
                $sent = $draft ? '' : ($live_counts ? $live_counts['sent'] : intval($r->sent_total));
                $problems = $draft ? '' : ($live_counts ? $live_counts['problems'] : intval($r->problem_total));
                $last = max((string) $r->updated_at, (string) $r->started_at, (string) $r->finished_at);
                $by = $draft ? self::name_of($r->updated_by) : self::name_of($r->sent_by);
                $subject = trim((string) $r->subject) !== '' ? (string) $r->subject : '(no subject yet)';
                $status = self::state_label($r->state) . ($r->state === 'sending' && !empty($r->held_since) ? ' (held)' : '');
                echo '<tr>'
                    . '<td><a href="' . esc_url(self::mailout_url(intval($r->id))) . '"><strong>' . self::h($subject) . '</strong></a></td>'
                    . '<td>' . esc_html(self::kind_label($r->kind)) . '</td>'
                    . '<td><span class="mo-chip is-' . esc_attr($r->state) . '">' . esc_html($status) . '</span></td>'
                    . '<td>' . ($draft ? '&mdash;' : esc_html(number_format(intval($r->total)))) . '</td>'
                    . '<td>' . ($draft ? '&mdash;' : esc_html(number_format(intval($sent)))) . '</td>'
                    . '<td>' . ($draft ? '&mdash;' : esc_html(number_format(intval($problems)))) . '</td>'
                    . '<td data-sort="' . esc_attr($last) . '">' . esc_html(self::d($last)) . '</td>'
                    . '<td>' . esc_html($by) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        $n = TeamOversight_Mailouts::optout_count();
        echo '<p>' . esc_html(self::people($n) . ' ' . ($n === 1 ? 'has' : 'have') . ' unsubscribed from club news.') . '</p>';
    }

    // ------------------------------------------------------------------
    // A draft: the edit view
    // ------------------------------------------------------------------

    /** The values the form shows: the saved draft, or (after a refused save) what was typed. */
    private static function form_values($row) {
        $v = array(
            'kind' => '', 'subject' => '', 'heading' => '', 'preheader' => '', 'body' => '', 'button_label' => '', 'button_url' => '',
            'image_id' => 0, 'image_alt' => '', 'reply_to' => '', 'audience' => TeamOversight_Mailouts::default_audience(), 'version' => 0,
        );
        if ($row) {
            foreach (array('kind', 'subject', 'heading', 'preheader', 'body', 'button_label', 'button_url', 'image_alt', 'reply_to') as $key) {
                $v[$key] = (string) $row->$key;
            }
            $v['image_id'] = intval($row->image_id);
            $v['audience'] = TeamOversight_Mailouts::audience_of($row);
            $v['version'] = intval($row->version);
        }
        $f = self::flash();
        $id = $row ? intval($row->id) : 0;
        if (is_array($f) && is_array($f['typed']) && intval($f['id']) === $id) {
            $t = $f['typed'];
            foreach (array('kind', 'subject', 'heading', 'preheader', 'body', 'button_label', 'button_url', 'image_alt', 'reply_to') as $key) {
                $v[$key] = isset($t[$key]) && is_scalar($t[$key]) ? (string) $t[$key] : '';
            }
            $v['image_id'] = isset($t['image_id']) ? intval($t['image_id']) : 0;
            $v['audience'] = TeamOversight_Mailouts::normalise_audience(isset($t['audience']) ? $t['audience'] : array());
        }
        return $v;
    }

    private static function render_edit($row) {
        $id = $row ? intval($row->id) : 0;
        $v = self::form_values($row);
        echo '<p><a href="' . esc_url(self::page_url()) . '">&larr; All mailouts</a></p>';
        echo '<h1>' . ($row ? 'Draft: ' . self::h(trim((string) $row->subject) !== '' ? $row->subject : '(no subject yet)') : 'New mailout') . '</h1>';
        self::render_flash();
        if ($row) {
            echo '<p class="mo-meta">Version ' . intval($row->version) . ', last saved ' . esc_html(self::dt($row->updated_at)) . ' by ' . esc_html(self::name_of($row->updated_by)) . '.</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="mo-form">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="save">'
            . '<input type="hidden" name="mailout" value="' . $id . '">'
            . '<input type="hidden" name="version" value="' . intval($v['version']) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action('save', $id))) . '">';

        // 1. Kind
        echo '<div class="mo-section"><h2>1. Kind</h2>';
        echo '<label class="mo-kind"><input type="radio" name="kind" value="notice"' . checked($v['kind'], 'notice', false) . '> <strong>Club notice</strong></label>'
            . '<p class="description">Facts members need to know: an AGM notice, a rule or venue change. Goes to everyone you pick. Don\'t promote anything in a notice.</p>';
        echo '<label class="mo-kind"><input type="radio" name="kind" value="news"' . checked($v['kind'], 'news', false) . '> <strong>Club news</strong></label>'
            . '<p class="description">Anything that promotes something: programs, events, merchandise, sponsors. It carries an unsubscribe link, and anyone who has unsubscribed is left out.</p>';
        echo '</div>';

        // 2. Who gets it
        echo '<div class="mo-section"><h2>2. Who gets it</h2><p class="description">Tick Include on each group you want. Anyone in any included group gets it once. People are found by their accounts, and the email goes to each account\'s address when it is sent.</p>';
        self::render_groups($v['audience']);
        echo '</div>';

        // 3. The email
        self::render_email_fields($v);
        echo '</form>';

        if (!$row) {
            echo '<div class="mo-section"><h2>4. Check it</h2><p>Save the draft to see who will get it, preview it and send yourself a test.</p></div>';
            self::render_edit_script();
            return;
        }

        // 4. Check it
        $resolved = TeamOversight_Mailouts::resolve(TeamOversight_Mailouts::audience_of($row), $row->kind === 'news' ? 'news' : 'notice');
        echo '<div class="mo-section" id="check"><h2>4. Check it</h2>';
        echo '<h3 id="who">Who will get this</h3>';
        echo '<p class="description">Worked out from the saved draft, live each time until it is sent. Save to count your changes.</p>';
        self::render_counts($row, $resolved, true);
        echo '<p><button type="submit" form="mo-form" name="then" value="count" class="button">Save and count again</button></p>';

        echo '<h3>Preview</h3><p class="description">The saved draft as you would get it. Save to see your changes here.</p>';
        self::render_preview($row);

        echo '<h3>Send me a test</h3>';
        $me = wp_get_current_user();
        echo '<p>Saves the draft, then sends it to you only (' . esc_html($me->user_email) . '), with [TEST] in the subject' . ($row->kind === 'news' ? ' and the unsubscribe line in the footer' : '') . '. Send needs a test of exactly what the draft says now.</p>';
        echo '<p><button type="submit" form="mo-form" name="then" value="test" class="button">Save and send me a test</button></p>';
        if ((string) $row->tested_hash === '') {
            echo '<p class="mo-meta">No test sent yet.</p>';
        } elseif (TeamOversight_Mailouts::is_tested($row)) {
            echo '<p class="mo-meta">A test of this version went ' . esc_html(self::dt($row->tested_at)) . ' (' . esc_html(self::name_of($row->tested_by)) . ').</p>';
        } else {
            echo '<p class="mo-meta">The last test (' . esc_html(self::dt($row->tested_at)) . ') was of an earlier version.</p>';
        }
        echo '</div>';

        // 5. Send
        echo '<div class="mo-section"><h2>5. Send</h2>';
        $reasons = self::send_blockers($row, $resolved);
        if ($reasons) {
            echo '<p><button type="button" class="button button-primary" disabled>Send</button></p><ul class="mo-reasons">';
            foreach ($reasons as $reason) {
                echo '<li>' . esc_html($reason) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p><a class="button button-primary" id="mo-send-link" data-unsaved="' . esc_attr('You have changes on this page that aren\'t saved. Send uses the saved draft. Go on without them?') . '" href="' . esc_url(self::mailout_url($id, array('view' => 'confirm', 'version' => intval($row->version)))) . '">Send</a></p>'
                . '<p class="description">Shows the count and asks you to confirm. Nothing goes yet.</p>';
        }
        echo '</div>';

        echo '<div class="mo-actions">' . self::form_open('delete', $id, array('version' => intval($row->version)), 'Delete this draft? This can\'t be undone.')
            . '<button type="submit" class="button-link mo-danger">Delete draft</button></form></div>';
        self::render_edit_script();
    }

    /**
     * Why Send is greyed out (sentences; empty = it can be pressed): the
     * kind, subject and message, the audience's problems, nobody to send to,
     * and no test of exactly this version.
     */
    public static function send_blockers($row, $resolved = null) {
        if (!$row) {
            return array('Save the draft first.');
        }
        if ($row->state !== 'draft') {
            return array('This mailout has already been sent.');
        }
        $out = array();
        foreach (TeamOversight_Mailouts::send_problems($row) as $reason) {
            if ($reason !== self::TEST_REASON) {
                $out[] = $reason;
            }
        }
        $audience = TeamOversight_Mailouts::audience_of($row);
        $problems = TeamOversight_Mailouts::audience_problems($audience);
        $out = array_merge($out, $problems);
        if (!$problems) {
            if ($resolved === null) {
                $resolved = TeamOversight_Mailouts::resolve($audience, $row->kind === 'news' ? 'news' : 'notice');
            }
            if (intval($resolved['total']) === 0) {
                $out[] = 'Nobody would get this mailout. Pick who gets it first.';
            }
        }
        if (!TeamOversight_Mailouts::is_tested($row)) {
            $out[] = self::TEST_REASON;
        }
        return $out;
    }

    private static function checkbox($name, $value, $label, $checked) {
        return '<label><input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($checked ? ' checked' : '') . '> ' . esc_html($label) . '</label>';
    }

    private static function radio($name, $value, $label, $current) {
        return '<label><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . checked($current, $value, false) . '> ' . esc_html($label) . '</label>';
    }

    /** A season dropdown: the offered seasons, plus the saved one when it is no longer offered. */
    private static function season_select($name, array $seasons, $current, $class = '') {
        if ($current !== '' && !in_array($current, $seasons, true)) {
            $seasons[] = $current;
        }
        $html = '<select name="' . esc_attr($name) . '"' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . '>';
        foreach ($seasons as $season) {
            $html .= '<option value="' . esc_attr($season) . '"' . selected($current, $season, false) . '>' . esc_html($season) . '</option>';
        }
        return $html . '</select>';
    }

    private static function include_tick($group, $on) {
        return '<p class="mo-include">' . self::checkbox('audience[' . $group . '][on]', '1', 'Include', $on) . '</p>';
    }

    /** The five group fieldsets (Include tick and options each). */
    private static function render_groups(array $a) {
        // Members
        $m = $a['members'];
        echo '<fieldset class="mo-group"><legend>Members</legend>' . self::include_tick('members', $m['on']) . '<div class="mo-options" data-nosort>';
        echo '<p class="mo-ticks"><span class="mo-label">Tiers</span> ';
        foreach (TeamOversight_Mailouts::tier_choices() as $key => $label) {
            echo self::checkbox('audience[members][tiers][]', $key, $label, in_array($key, $m['tiers'], true));
        }
        echo '</p><p><span class="mo-label">When</span><br>'
            . self::radio('audience[members][when]', 'today', 'Members today', $m['when']) . '<br>'
            . self::radio('audience[members][when]', 'range', 'Members at any time between the two dates below', $m['when']) . '<br>'
            . self::radio('audience[members][when]', 'lapsed', 'Lapsed: members between the two dates below who aren\'t members now', $m['when']) . '</p>';
        echo '<p><label>From <input type="date" name="audience[members][from]" value="' . esc_attr($m['from']) . '"></label> '
            . '<label>to <input type="date" name="audience[members][to]" value="' . esc_attr($m['to']) . '"></label></p>'
            . '<p class="description">Read the way Membership History reads memberships. For lapsed members the second date can\'t be after today.</p>';
        echo '</div></fieldset>';

        // VVL teams
        $t = $a['teams'];
        $seasons = TeamOversight_Mailouts::team_seasons();
        echo '<fieldset class="mo-group"><legend>VVL teams</legend>';
        if (!$seasons && $t['season'] === '') {
            echo '<p>No season has team places on the site yet. Teams before ' . esc_html(TeamOversight_Mailouts::FIRST_TEAM_SEASON) . ' were never recorded here.</p></fieldset>';
        } else {
            echo self::include_tick('teams', $t['on']) . '<div class="mo-options" data-nosort>';
            echo '<p><label><span class="mo-label">Season</span> ' . self::season_select('audience[teams][season]', $seasons, $t['season'], 'mo-team-season') . '</label> '
                . '<span class="description">Teams before ' . esc_html(TeamOversight_Mailouts::FIRST_TEAM_SEASON) . ' were never recorded on the site, so earlier seasons aren\'t listed.</span></p>';
            echo '<p><span class="mo-label">Teams</span> ' . self::radio('audience[teams][scope]', 'all', 'All teams', $t['all'] ? 'all' : 'some') . ' &nbsp; '
                . self::radio('audience[teams][scope]', 'some', 'These teams:', $t['all'] ? 'all' : 'some') . '</p>';
            $shown = $seasons;
            if ($t['season'] !== '' && !in_array($t['season'], $shown, true)) {
                $shown[] = $t['season'];
            }
            foreach ($shown as $season) {
                $choices = TeamOversight_Mailouts::team_choices($season);
                echo '<p class="mo-ticks mo-team-ticks" data-season="' . esc_attr($season) . '">';
                if (count($shown) > 1) {
                    echo '<span class="mo-label">' . esc_html($season) . '</span> ';
                }
                if (!$choices) {
                    echo '<span class="description">No teams are set up for ' . esc_html($season) . '.</span>';
                }
                foreach ($choices as $code => $label) {
                    echo self::checkbox('audience[teams][teams][]', $code, $label, $season === $t['season'] && in_array((string) $code, $t['teams'], true));
                }
                echo '</p>';
            }
            echo '<p class="mo-ticks"><span class="mo-label">Roles</span> ';
            foreach (TeamOversight_Mailouts::role_choices() as $key => $label) {
                echo self::checkbox('audience[teams][roles][]', $key, $label, in_array($key, $t['roles'], true));
            }
            echo '</p><p class="description">Active places only. Players are playing members and training only players; coaches include assistant coaches.</p>';
            echo '</div></fieldset>';
        }

        // Trial applicants
        $tr = $a['trials'];
        $tseasons = TeamOversight_Mailouts::trial_seasons();
        echo '<fieldset class="mo-group"><legend>Trial applicants</legend>';
        if (!$tseasons && $tr['season'] === '') {
            echo '<p>There are no trial applications on the site yet.</p></fieldset>';
        } else {
            echo self::include_tick('trials', $tr['on']) . '<div class="mo-options" data-nosort>';
            echo '<p><label><span class="mo-label">Season</span> ' . self::season_select('audience[trials][season]', $tseasons, $tr['season']) . '</label></p>';
            echo '<p class="mo-ticks"><span class="mo-label">Status</span> ';
            foreach (TeamOversight_Mailouts::trial_status_choices() as $key => $label) {
                echo self::checkbox('audience[trials][statuses][]', $key, $label, in_array($key, $tr['statuses'], true));
            }
            echo '</p></div></fieldset>';
        }

        // Club Programs
        $pr = $a['programs'];
        $programs = TeamOversight_Mailouts::program_choices();
        echo '<fieldset class="mo-group"><legend>Club Programs</legend>';
        if (!$programs) {
            echo '<p>No Club Programs are set up on this site.</p></fieldset>';
        } else {
            echo self::include_tick('programs', $pr['on']) . '<div class="mo-options" data-nosort>';
            echo '<p><span class="mo-label">Program</span> ' . self::radio('audience[programs][scope]', 'any', 'Any program', $pr['all'] ? 'any' : 'some') . ' &nbsp; '
                . self::radio('audience[programs][scope]', 'some', 'These programs:', $pr['all'] ? 'any' : 'some') . '</p><p class="mo-ticks">';
            foreach ($programs as $key => $label) {
                echo self::checkbox('audience[programs][programs][]', $key, $label, in_array((string) $key, $pr['programs'], true));
            }
            echo '</p><p><label><span class="mo-label">Year</span> ' . self::season_select('audience[programs][year]', TeamOversight_Mailouts::program_years(), $pr['year']) . '</label></p>'
                . '<p class="description">Everyone with a paid booking in that year (the session\'s date, else the day they paid). Fully refunded bookings are left out.</p>';
            echo '</div></fieldset>';
        }

        // Reds Social League
        $l = $a['league'];
        echo '<fieldset class="mo-group"><legend>Reds Social League</legend>';
        if (!TeamOversight_Mailouts::league_available()) {
            echo '<p>The Reds Social League isn\'t installed on this site.</p></fieldset>';
        } else {
            $lseasons = TeamOversight_Mailouts::league_seasons();
            echo self::include_tick('league', $l['on']) . '<div class="mo-options" data-nosort>';
            echo '<p class="mo-ticks"><span class="mo-label">Seasons</span> ';
            if (!$lseasons) {
                echo '<span class="description">No league season has teams yet.</span>';
            }
            foreach ($lseasons as $key => $label) {
                echo self::checkbox('audience[league][seasons][]', $key, $label, in_array((string) $key, $l['seasons'], true));
            }
            echo '</p><p class="mo-ticks"><span class="mo-label">Who</span> ';
            foreach (TeamOversight_Mailouts::league_who_choices() as $key => $label) {
                echo self::checkbox('audience[league][who][]', $key, $label, in_array($key, $l['who'], true));
            }
            echo '</p><p class="description">On a team: members of an active team who haven\'t left it. Free agents: people looking for a team who haven\'t withdrawn.</p>';
            echo '</div></fieldset>';
        }
    }

    /** Section 3: the email's fields, the check_text() warnings, the picture picker, Reply-To and Save draft. */
    private static function render_email_fields(array $v) {
        $row_th = function ($for, $label) {
            return '<th scope="row"><label for="' . esc_attr($for) . '">' . esc_html($label) . '</label></th>';
        };
        echo '<div class="mo-section"><h2>3. The email</h2><table class="form-table" role="presentation" data-nosort><tbody>';
        echo '<tr>' . $row_th('mo-subject', 'Subject') . '<td><input type="text" id="mo-subject" name="subject" class="large-text" maxlength="' . intval(TeamOversight_Mailouts::SUBJECT_MAX) . '" value="' . esc_attr($v['subject']) . '">'
            . '<p class="description">At most ' . intval(TeamOversight_Mailouts::SUBJECT_MAX) . ' characters. <code>{first_name}</code> works here too.</p></td></tr>';
        echo '<tr>' . $row_th('mo-heading', 'Heading') . '<td><input type="text" id="mo-heading" name="heading" class="large-text" maxlength="' . intval(TeamOversight_Mailouts::TEXT_MAX) . '" value="' . esc_attr($v['heading']) . '">'
            . '<p class="description">The big line at the top of the email. Blank = the subject.</p></td></tr>';
        echo '<tr>' . $row_th('mo-preheader', 'Preview line') . '<td><input type="text" id="mo-preheader" name="preheader" class="large-text" maxlength="' . intval(TeamOversight_Mailouts::TEXT_MAX) . '" value="' . esc_attr($v['preheader']) . '">'
            . '<p class="description">Optional. What inboxes show after the subject.</p></td></tr>';
        echo '<tr>' . $row_th('mo-body', 'Message') . '<td><textarea id="mo-body" name="body" rows="14" class="large-text">' . esc_textarea($v['body']) . '</textarea>'
            . '<p class="description">' . TeamOversight_Email::format_help() . ' For more buttons, each with its own link, put a line like <code>{button:Register a team https://...}</code> wherever each one should go: the words, then the web address. Write <code>{first_name}</code> for the person\'s first name.</p>';
        $plain_body = TeamOversight_Mailouts::without_link_buttons($v['body']);
        $warnings = $v['body'] !== '' ? array_merge(TeamOversight_Email::check_text($plain_body, array('{first_name}'), array('button')), TeamOversight_Mailouts::link_button_problems($v['body'])) : array();
        if ($v['body'] !== '' && $v['button_url'] === '') {
            foreach (array('{button}', '{button:') as $token) {
                if (stripos($plain_body, $token) !== false) {
                    $warnings[] = 'The message has a {button} line but no button link is set, so no button will show.';
                    break;
                }
            }
        }
        if ($warnings) {
            echo '<ul class="mo-reasons mo-warn">';
            foreach ($warnings as $warning) {
                echo '<li>' . esc_html($warning) . '</li>';
            }
            echo '</ul>';
        }
        echo '</td></tr>';
        echo '<tr>' . $row_th('mo-button-label', 'Button') . '<td><label>Words <input type="text" id="mo-button-label" name="button_label" class="regular-text" maxlength="' . intval(TeamOversight_Mailouts::BUTTON_LABEL_MAX) . '" value="' . esc_attr($v['button_label']) . '" placeholder="' . esc_attr(TeamOversight_Mailouts::DEFAULT_BUTTON) . '"></label><br>'
            . '<label>Link <input type="url" name="button_url" class="large-text" maxlength="' . intval(TeamOversight_Mailouts::BUTTON_URL_MAX) . '" value="' . esc_attr($v['button_url']) . '" placeholder="https://"></label>'
            . '<p class="description">Optional. A web address starting with https://. With a link the email has a button (blank words say ' . esc_html(TeamOversight_Mailouts::DEFAULT_BUTTON) . '); it goes where the message has <code>{button}</code> on a line of its own, else after the last paragraph.</p></td></tr>';

        $image_id = intval($v['image_id']);
        $url = $image_id > 0 ? wp_get_attachment_image_url($image_id, 'medium') : false;
        echo '<tr>' . $row_th('mo-image-alt', 'Picture') . '<td>'
            . '<img id="mo-image-img" alt=""' . ($url ? ' src="' . esc_url($url) . '"' : ' hidden') . '>'
            . ($image_id > 0 && !$url ? '<p id="mo-image-gone" class="mo-danger">Picture #' . $image_id . ' is no longer in the Media Library. Choose another, or remove it.</p>' : '')
            . '<input type="hidden" id="mo-image-id" name="image_id" value="' . $image_id . '">'
            . '<p><button type="button" class="button" id="mo-image-choose">Choose a picture</button> '
            . '<button type="button" class="button-link mo-danger" id="mo-image-remove"' . ($image_id > 0 ? '' : ' hidden') . '>Remove the picture</button></p>'
            . '<p><label>Picture description <input type="text" id="mo-image-alt" name="image_alt" class="regular-text" maxlength="' . intval(TeamOversight_Mailouts::TEXT_MAX) . '" value="' . esc_attr($v['image_alt']) . '"></label></p>'
            . '<p class="description">Optional. A picture from the Media Library, shown full width above the message and linked to the button\'s link when there is one. With a picture, describe it in a few words: email programs read the description out, or show it when pictures are off.</p>'
            . '<noscript><p class="mo-danger">Choosing a picture needs JavaScript.</p></noscript></td></tr>';

        $usual = sanitize_email((string) get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION));
        echo '<tr>' . $row_th('mo-reply-to', 'Reply-To') . '<td><input type="email" id="mo-reply-to" name="reply_to" class="regular-text" maxlength="' . intval(TeamOversight_Mailouts::TEXT_MAX) . '" value="' . esc_attr($v['reply_to']) . '"' . ($usual !== '' ? ' placeholder="' . esc_attr($usual) . '"' : '') . '>'
            . '<p class="description">Where replies go. Blank = the Reply-To in the Sender settings (VVL Oversight, Emails, Overview)' . ($usual !== '' ? ', ' . esc_html($usual) : '') . '.</p></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Save draft</button></p></div>';
    }

    /** Who will get this: the total, the groups, who is left out and why, and the list with its CSV. */
    private static function render_counts($row, array $resolved, $with_list) {
        $kind = $row->kind === 'news' ? 'news' : 'notice';
        $problems = TeamOversight_Mailouts::audience_problems(TeamOversight_Mailouts::audience_of($row));
        if ($problems) {
            echo '<ul class="mo-reasons mo-danger">';
            foreach ($problems as $problem) {
                echo '<li>' . esc_html($problem) . '</li>';
            }
            echo '</ul>';
        }
        if (!$resolved['groups']) {
            echo '<p class="mo-total">Nobody yet. Tick Include on a group above and save.</p>';
            return;
        }
        $total = intval($resolved['total']);
        echo '<p class="mo-total">' . ($total > 0 ? '<strong>' . esc_html(self::people($total)) . '</strong> will get this.' : '<strong>Nobody</strong> would get this with these options.') . '</p>';
        if ($row->kind === '') {
            echo '<p class="description">Counted as a club notice. Pick Club news and anyone who has unsubscribed from club news is left out.</p>';
        }
        $labels = TeamOversight_Mailouts::group_types();
        echo '<table class="widefat striped"><thead><tr><th>Group</th><th>Getting it</th><th>Only in this group</th><th>Left out</th><th>Not on an account</th></tr></thead><tbody>';
        foreach ($resolved['groups'] as $g => $c) {
            echo '<tr><td>' . esc_html($labels[$g]) . '</td><td>' . esc_html(number_format(intval($c['people']))) . '</td><td>' . esc_html(number_format(intval($c['only'])))
                . '</td><td>' . esc_html(number_format(intval($c['left']))) . '</td><td>' . esc_html(number_format(intval($c['missing']))) . '</td></tr>';
        }
        echo '</tbody></table>';
        $lo = $resolved['left_out'];
        $lines = array();
        if ($kind === 'news' && $lo['unsubscribed'] > 0) {
            $lines[] = self::people($lo['unsubscribed']) . ' unsubscribed from club news';
        }
        if ($lo['no_email'] > 0) {
            $lines[] = self::people($lo['no_email']) . ' with no valid email address on their account';
        }
        if ($lo['no_account'] > 0) {
            $lines[] = number_format($lo['no_account']) . ' not on an account (a row with no account, or an account that is gone)';
        }
        if ($lo['undated'] > 0) {
            $lines[] = number_format($lo['undated']) . ' Club Programs ' . ($lo['undated'] === 1 ? 'booking' : 'bookings') . ' with no date, which can\'t be placed in a year';
        }
        if ($lines) {
            echo '<p><strong>Left out:</strong></p><ul class="mo-reasons">';
            foreach ($lines as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }
        if (!$with_list) {
            return;
        }
        if ($total > 0) {
            echo '<p><a class="button" href="' . esc_url(self::csv_url(intval($row->id), 'audience')) . '">Download CSV</a> <span class="description">The saved draft\'s list: name, email and groups.</span></p>';
            self::render_people_table(array_values($resolved['people']), false);
        }
        if ($resolved['left']) {
            echo '<details><summary>' . esc_html(self::people(count($resolved['left'])) . ' in these groups won\'t get it') . '</summary>';
            self::render_people_table(array_values($resolved['left']), true);
            echo '</details>';
        }
    }

    /** People from resolve(): Name (to Member Lookup), Email, Groups, and Why when $left. */
    private static function render_people_table(array $people, $left) {
        $shown = array_slice($people, 0, self::LIST_MAX);
        $reasons = array('unsubscribed' => 'Unsubscribed from club news', 'no_email' => 'No valid email address');
        echo '<table class="widefat striped"' . (count($people) > self::LIST_MAX ? ' data-sort-paged' : '') . '><thead><tr><th>Name</th><th>Email</th><th>Groups</th>' . ($left ? '<th>Why</th>' : '') . '</tr></thead><tbody>';
        foreach ($shown as $p) {
            $profile = class_exists('TeamOversight_Member_Lookup') ? TeamOversight_Member_Lookup::profile_url(intval($p['user_id'])) : '';
            echo '<tr><td>' . ($profile !== '' ? '<a href="' . esc_url($profile) . '">' . self::h($p['name']) . '</a>' : self::h($p['name'])) . '</td>'
                . '<td>' . esc_html($p['email']) . '</td>'
                . '<td>' . esc_html(self::groups_words(TeamOversight_Mailouts::group_string($p['groups'], $p['program']))) . '</td>'
                . ($left ? '<td>' . esc_html(isset($reasons[$p['reason']]) ? $reasons[$p['reason']] : (string) $p['reason']) . '</td>' : '')
                . '</tr>';
        }
        echo '</tbody></table>';
        if (count($people) > self::LIST_MAX) {
            echo '<p class="description">The first ' . number_format(self::LIST_MAX) . ' of ' . number_format(count($people)) . ' are listed. The CSV has everyone.</p>';
        }
    }

    /** The saved mailout as the administrator would get it, in TeamOversight_Email::preview_frame(). */
    private static function render_preview($row) {
        if (trim((string) $row->subject) === '' || trim((string) $row->body) === '') {
            echo '<p>Add a subject and a message, then save, to see the preview.</p>';
            return;
        }
        $me = wp_get_current_user();
        list($subject, $email) = TeamOversight_Mailouts::build_email($row, intval($me->ID), (string) $me->display_name, (string) $me->user_email, self::preview_groups(TeamOversight_Mailouts::audience_of($row)));
        echo '<p><strong>Subject:</strong> ' . self::h($subject) . '</p>';
        echo TeamOversight_Email::preview_frame($email['html']);
    }

    /** The edit view's script: unsaved changes before Send, dimmed groups, the season's team ticks, the picture picker. */
    private static function render_edit_script() {
        ?>
        <script>
            (function () {
                var form = document.getElementById('mo-form');
                if (!form) { return; }
                var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };
                var dirty = false;
                form.addEventListener('input', function () { dirty = true; });
                form.addEventListener('change', function () { dirty = true; });
                form.addEventListener('submit', function () { dirty = false; });
                var send = document.getElementById('mo-send-link');
                if (send) {
                    send.addEventListener('click', function (e) {
                        if (dirty && !window.confirm(send.getAttribute('data-unsaved'))) { e.preventDefault(); }
                    });
                }
                each(form.querySelectorAll('.mo-group'), function (group) {
                    var tick = group.querySelector('.mo-include input');
                    if (!tick) { return; }
                    var sync = function () { group.classList.toggle('is-off', !tick.checked); };
                    tick.addEventListener('change', sync);
                    sync();
                });
                var season = form.querySelector('.mo-team-season');
                var syncTeams = function () {
                    var chosen = season ? season.value : '';
                    each(form.querySelectorAll('.mo-team-ticks'), function (box) {
                        var on = box.getAttribute('data-season') === chosen;
                        box.hidden = !on;
                        each(box.querySelectorAll('input'), function (input) { input.disabled = !on; });
                    });
                };
                if (season) { season.addEventListener('change', syncTeams); syncTeams(); }
                var choose = document.getElementById('mo-image-choose');
                var remove = document.getElementById('mo-image-remove');
                var field = document.getElementById('mo-image-id');
                var img = document.getElementById('mo-image-img');
                var frame = null;
                var setImage = function (id, url) {
                    field.value = id ? String(id) : '0';
                    if (id && url) { img.src = url; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
                    remove.hidden = !id;
                    var gone = document.getElementById('mo-image-gone');
                    if (gone) { gone.hidden = true; }
                    dirty = true;
                };
                if (choose && field && img && remove) {
                    choose.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (!window.wp || !window.wp.media) { return; }
                        if (!frame) {
                            frame = window.wp.media({ title: 'Choose a picture', library: { type: 'image' }, multiple: false, button: { text: 'Use this picture' } });
                            frame.on('select', function () {
                                var picked = frame.state().get('selection').first();
                                if (!picked) { return; }
                                var a = picked.toJSON();
                                var url = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
                                setImage(parseInt(a.id, 10) || 0, url);
                                var alt = document.getElementById('mo-image-alt');
                                if (alt && !alt.value && a.alt) { alt.value = a.alt; }
                            });
                        }
                        frame.open();
                    });
                    remove.addEventListener('click', function (e) { e.preventDefault(); setImage(0, ''); });
                }
            })();
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    // Send's confirm view
    // ------------------------------------------------------------------

    private static function render_confirm($row) {
        $id = intval($row->id);
        echo '<p><a href="' . esc_url(self::mailout_url($id)) . '">&larr; Back to the draft</a></p>';
        echo '<h1>Send this mailout?</h1>';
        self::render_flash();
        echo self::held_line();
        $shown_version = isset($_GET['version']) && is_scalar($_GET['version']) ? intval($_GET['version']) : -1;
        if ($shown_version !== intval($row->version)) {
            echo '<div class="notice notice-warning"><p>This draft changed since you opened it. <a href="' . esc_url(self::mailout_url($id)) . '">Look at it again</a>, then send.</p></div>';
            return;
        }
        $kind = $row->kind === 'news' ? 'news' : 'notice';
        $resolved = TeamOversight_Mailouts::resolve(TeamOversight_Mailouts::audience_of($row), $kind);
        $reasons = self::send_blockers($row, $resolved);
        if ($reasons) {
            echo '<div class="notice notice-error"><p>It can\'t be sent yet:</p><ul class="mo-reasons">';
            foreach ($reasons as $reason) {
                echo '<li>' . esc_html($reason) . '</li>';
            }
            echo '</ul><p><a href="' . esc_url(self::mailout_url($id)) . '">Back to the draft</a></p></div>';
            return;
        }
        $total = intval($resolved['total']);
        $estimate = TeamOversight_Mailouts::queue_estimate($total);
        echo '<div class="mo-section">';
        echo '<p class="mo-total">Send "' . self::h($row->subject) . '" to ' . esc_html(self::people($total)) . '? At up to ' . number_format(TeamOversight_Mailouts::HOUR_CAP)
            . ' an hour it should finish around ' . esc_html(self::when_words($estimate['at'])) . ' (later if the site is quiet overnight). You can pause it or cancel the rest.</p>';
        if ($estimate['ahead'] > 0) {
            echo '<p>It goes after the ' . esc_html(number_format($estimate['ahead'])) . ' ' . ($estimate['ahead'] === 1 ? 'email' : 'emails') . ' still waiting in mailouts already sending.</p>';
        }
        echo '<p><strong>' . esc_html(self::kind_words($row->kind)) . '.</strong> ' . esc_html($row->kind === 'news' ? 'It carries an unsubscribe link.' : 'No unsubscribe link: it must not promote anything.') . '</p>';
        echo '<p><strong>Who:</strong> ' . esc_html(TeamOversight_Mailouts::audience_words(TeamOversight_Mailouts::audience_of($row))) . '</p>';
        self::render_counts($row, $resolved, false);
        echo self::form_open('send', $id, array('version' => intval($row->version), 'shown_total' => $total), '', 'mo-confirm')
            . '<p><button type="submit" class="button button-primary">Confirm send</button> <a class="button" href="' . esc_url(self::mailout_url($id)) . '">Back to the draft</a></p></form>';
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // A sent mailout (read-only)
    // ------------------------------------------------------------------

    private static function render_sent($row) {
        $id = intval($row->id);
        $c = TeamOversight_Mailouts::counts($id);
        echo '<p><a href="' . esc_url(self::page_url()) . '">&larr; All mailouts</a></p>';
        echo '<h1>' . self::h($row->subject) . ' <span class="mo-chip is-' . esc_attr($row->state) . '">' . esc_html(self::state_label($row->state)) . '</span></h1>';
        self::render_flash();
        $meta = self::kind_words($row->kind) . '. Sent by ' . (self::name_of($row->sent_by) !== '' ? self::name_of($row->sent_by) : 'unknown') . ' on ' . self::dt($row->started_at) . '.';
        if (!empty($row->finished_at)) {
            $meta .= ' ' . ($row->state === 'cancelled' ? 'Cancelled' : 'Finished') . ' ' . self::dt($row->finished_at) . '.';
        }
        echo '<p class="mo-meta">' . esc_html($meta) . '</p>';
        if (in_array($row->state, array('sending', 'paused'), true)) {
            echo self::held_line();
            if ($row->state === 'sending' && !empty($row->held_since)) {
                echo '<p class="mo-held">Held since ' . esc_html(self::dt($row->held_since)) . '.</p>';
            }
        }

        $total = intval($row->total);
        $done_n = $c['sent'];
        $pct = $total > 0 ? min(100, (int) round($done_n * 100 / $total)) : 0;
        $parts = array(
            'Sent ' . number_format($c['sent']) . ' of ' . number_format($total),
            'Waiting ' . number_format($c['waiting']),
            'Problems ' . number_format($c['problems']),
            'Left out ' . number_format($c['left_out']),
        );
        if ($c['cancelled'] > 0) {
            $parts[] = 'Cancelled ' . number_format($c['cancelled']);
        }
        echo '<div class="mo-section"><h2>Progress</h2><p class="mo-total">' . implode(' &middot; ', array_map('esc_html', $parts)) . '</p>'
            . '<div class="mo-bar" role="progressbar" aria-label="Sent" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '"><span style="width:' . $pct . '%"></span></div>';
        if ($row->state === 'sending' && $c['waiting'] > 0) {
            $estimate = TeamOversight_Mailouts::queue_estimate($c['waiting'], $id);
            echo '<p>At this pace the rest should go by around ' . esc_html(self::when_words($estimate['at'])) . ' (later if the site is quiet overnight)'
                . ($estimate['ahead'] > 0 ? ', after the ' . esc_html(number_format($estimate['ahead'])) . ' still waiting in mailouts sent before it' : '') . '.</p>';
        } elseif ($row->state === 'paused' && !empty($row->held_since)) {
            echo '<p class="mo-held">Paused by the site: emails were held from ' . esc_html(self::dt($row->held_since)) . ' for more than a day. Resume it if it should still go, or cancel the rest.</p>';
        } elseif ($row->state === 'paused') {
            echo '<p>Paused. Nothing more goes until you resume it.</p>';
        }
        echo '<div class="mo-actions">';
        if ($row->state === 'sending') {
            echo self::form_open('pause', $id, array('shown_state' => 'sending')) . '<button type="submit" class="button">Pause</button></form>';
        } elseif ($row->state === 'paused') {
            echo self::form_open('resume', $id, array('shown_state' => 'paused')) . '<button type="submit" class="button button-primary">Resume</button></form>';
        }
        if (in_array($row->state, array('sending', 'paused'), true)) {
            echo self::form_open('cancel', $id, array('shown_state' => $row->state), 'Cancel the rest? The ' . number_format($c['waiting']) . ' waiting won\'t get it. This can\'t be undone.')
                . '<button type="submit" class="button mo-danger">Cancel the rest</button></form>';
        }
        echo self::form_open('copy', $id, array('version' => intval($row->version))) . '<button type="submit" class="button">Copy as new draft</button></form>';
        echo '</div></div>';

        echo '<div class="mo-section"><h2>Who it went to</h2><p>' . esc_html((string) $row->audience_words !== '' ? (string) $row->audience_words : TeamOversight_Mailouts::audience_words(TeamOversight_Mailouts::audience_of($row))) . '</p>';
        if ($c['skipped_reasons']) {
            $words = array('unsubscribed' => 'unsubscribed from club news', 'no_email' => 'no valid email address', 'no_account' => 'account gone', 'duplicate' => 'merged into an account already on the list');
            $lines = array();
            foreach ($c['skipped_reasons'] as $reason => $n) {
                $lines[] = number_format($n) . ' ' . (isset($words[$reason]) ? $words[$reason] : $reason);
            }
            echo '<p>Left out: ' . esc_html(implode(', ', $lines)) . '.</p>';
        }
        echo '</div>';

        echo '<div class="mo-section"><h2>The email as sent</h2><p class="description">Shown with your name; each person got their own name and why line.</p>';
        $me = wp_get_current_user();
        list($subject, $email) = TeamOversight_Mailouts::build_email($row, intval($me->ID), (string) $me->display_name, (string) $me->user_email, self::preview_groups(TeamOversight_Mailouts::audience_of($row)));
        echo '<p><strong>Subject:</strong> ' . self::h($subject) . '</p>' . TeamOversight_Email::preview_frame($email['html']) . '</div>';

        echo '<div class="mo-section"><h2>Recipients</h2>';
        if ($c['rows'] === 0) {
            echo '<p>The list of who got it is kept for ' . intval(TeamOversight_Mailouts::KEEP_DAYS) . ' days after a mailout finishes, so it has been cleared. The totals above stay.</p></div>';
            return;
        }
        echo '<p><a class="button" href="' . esc_url(self::csv_url($id, 'recipients')) . '">Download CSV</a></p>';
        $rows = TeamOversight_Mailouts::recipients($id, array('limit' => self::LIST_MAX));
        echo '<table class="widefat striped"' . ($c['rows'] > self::LIST_MAX ? ' data-sort-paged' : '') . '><thead><tr><th>Name</th><th>Email</th><th>Group(s)</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $uid = intval($r->user_id);
            $name = $uid > 0 && class_exists('TeamOversight_Member_Lookup')
                ? '<a href="' . esc_url(TeamOversight_Member_Lookup::profile_url($uid)) . '">' . self::h($r->name) . '</a>'
                : self::h($r->name);
            echo '<tr><td>' . $name . '</td><td>' . esc_html((string) $r->email) . '</td><td>' . esc_html(self::groups_words((string) $r->group_keys)) . '</td>'
                . '<td>' . esc_html(TeamOversight_Mailouts::status_words($r)) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($c['rows'] > self::LIST_MAX) {
            echo '<p class="description">The first ' . number_format(self::LIST_MAX) . ' of ' . number_format($c['rows']) . ' are listed. The CSV has everyone.</p>';
        }
        echo '</div>';
    }
}
