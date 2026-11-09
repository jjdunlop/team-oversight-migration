<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club documents (1.90.0): the committee's screens, under their own
 * top-level menu (position 34, after Coach bios) and the capability
 * TeamOversight_Club_Documents::CAP, which administrators hold through a
 * filter and the WordPress editor role holds directly.
 *
 * Screens: Documents (club-documents: the To do panel, then the documents
 * by group, then How to), Add New / Edit (club-documents-edit: the form
 * with the four boxes and the live sentence, Upload a new version,
 * Versions, Old copies, History; the Withdraw and Remove the public copy
 * confirm screens; Copy a PDF from the Media Library on Add New for
 * administrators) and Groups and settings (club-documents-settings:
 * #groups, #managers, #storage, #settings, #pages).
 *
 * Rules (design 6.7):
 *  - ONE admin-post action, murvc_documents, with the button's verb in do.
 *    Each button has its own nonce bound to its target (nonce_action());
 *    CAP is checked before anything runs, POST only, and the
 *    administrator-only verbs (ADMIN_ACTIONS) also need manage_options,
 *    which their writers check again. The upload forms carry the action in
 *    their address too: a body bigger than post_max_size arrives with
 *    $_POST empty, and handle() then says so (post_too_big()) instead of a
 *    blank page. Post/Redirect/Get with a flash summary.
 *  - Every change goes through a TeamOversight_Club_Documents writer, which
 *    validates, compare-and-sets, records the history and logs. This class
 *    runs no SQL and writes only the flash and draft transients. A page
 *    render writes nothing: load_page() takes the flash and the draft, and
 *    reads To do, the old-link list and the pages list (which keep their
 *    own transients), before anything is printed.
 *  - Who can open a document is read only through the registry helpers:
 *    the boxes come from audiences(), the live sentence from
 *    sentence_table(), the list column from each entry's short word. No
 *    audience key is written in this file.
 *  - Every value is escaped where it is printed (esc_html, esc_attr,
 *    esc_url); display names are decoded once and escaped once. The script
 *    sets values, attributes and textContent only, never markup. Greyed
 *    boxes use aria-disabled, never the disabled attribute, so their ticks
 *    are still posted and kept.
 */
class TeamOversight_Club_Documents_Admin {

    const ACTION = 'murvc_documents';
    /** The screens a button may send someone back to. */
    const PAGES = array('club-documents', 'club-documents-edit', 'club-documents-settings');
    /** Every button (the posted do). */
    const DO_ACTIONS = array('save', 'upload', 'make_current', 'set_date', 'delete_file', 'withdraw', 'move', 'groups', 'settings', 'setup_storage', 'check_now', 'refresh', 'check_links', 'import', 'add_copy', 'drop_copy', 'remove_copy');
    /** The buttons only an administrator may press (manage_options as well as CAP). */
    const ADMIN_ACTIONS = array('delete_file', 'settings', 'setup_storage', 'check_now', 'import', 'add_copy', 'drop_copy', 'remove_copy');
    /** The buttons that act on one version (posted as version). */
    const VERSION_ACTIONS = array('make_current', 'set_date', 'delete_file');
    /** The sections a button may return to. */
    const ANCHORS = array('todo', 'versions', 'upload', 'old-copies', 'history', 'import', 'groups', 'managers', 'storage', 'settings', 'pages');
    /** The flash summary a button leaves for its presser (300 s). */
    const FLASH_PREFIX = 'murvc_documents_flash_';
    /** The To do panel shows at most this many rows. */
    const TODO_MAX = 25;

    /** The document fields History names. */
    const FIELD_LABELS = array(
        'title' => 'Title',
        'description' => 'Description',
        'group_key' => 'Group',
        'doc_year' => 'AGM year',
        'slug' => 'Address',
        'audiences' => 'Who can open it',
    );

    /** What the page is showing; every form posts it back as return_*. */
    private static $view = array('page' => 'club-documents', 'document' => 0, 'anchor' => '');

    /** Read in load_page(), before anything is printed. */
    private static $flash = null;
    private static $flash_taken = false;
    private static $drafts = array();
    private static $todo = null;
    private static $links_text = null;

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        if (is_admin()) {
            // After TeamOversight_Admin's menus (priority 10); Coach bios is 33.
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 20);
            add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 10, 2);
        }
    }

    // ------------------------------------------------------------------
    // The menu
    // ------------------------------------------------------------------

    /** Club documents, a top-level menu under CAP: Documents, Add New, Groups and settings. Each screen gets load_page(). */
    public static function add_menu() {
        $cap = TeamOversight_Club_Documents::CAP;
        $list = TeamOversight_Club_Documents::PAGE_LIST;
        add_menu_page('Club documents', 'Club documents', $cap, $list, array(__CLASS__, 'render_list_page'), 'dashicons-media-document', 34);
        $suffixes = array(
            add_submenu_page($list, 'Club documents', 'Documents', $cap, $list, array(__CLASS__, 'render_list_page')),
            add_submenu_page($list, 'Club document', 'Add New', $cap, TeamOversight_Club_Documents::PAGE_EDIT, array(__CLASS__, 'render_edit_page')),
            add_submenu_page($list, 'Club documents: groups and settings', 'Groups and settings', $cap, TeamOversight_Club_Documents::PAGE_SETTINGS, array(__CLASS__, 'render_settings_page')),
        );
        foreach ($suffixes as $suffix) {
            if (is_string($suffix) && $suffix !== '') {
                add_action('load-' . $suffix, array(__CLASS__, 'load_page'));
            }
        }
    }

    /** Keep Documents highlighted while an existing document is open (Add New stays for a new one). */
    public static function submenu_file($submenu_file, $parent_file = '') {
        if (self::current_page() === TeamOversight_Club_Documents::PAGE_EDIT && self::get_id('document') > 0) {
            return TeamOversight_Club_Documents::PAGE_LIST;
        }
        return $submenu_file;
    }

    /**
     * load-<page> for every Club documents screen, before anything is
     * printed: take the flash and (on the editor) the draft, and read what
     * keeps its own transient (the pages list; on Documents the To do rows
     * and, when asked, the old-link list; on Groups and settings the storage
     * check), so the render itself writes nothing.
     */
    public static function load_page() {
        self::take_flash();
        // An admin request: note whether the page refresh reaches the site cache from here, where it runs.
        TeamOversight_Club_Documents::note_purge_listener();
        TeamOversight_Club_Documents::pages();
        $page = self::current_page();
        if ($page === TeamOversight_Club_Documents::PAGE_LIST) {
            self::$todo = TeamOversight_Club_Documents::todo();
            if (self::get_flag('links')) {
                self::$links_text = TeamOversight_Club_Documents::old_links_text(false);
            }
        } elseif ($page === TeamOversight_Club_Documents::PAGE_EDIT) {
            self::take_draft(self::get_id('document'));
        } elseif ($page === TeamOversight_Club_Documents::PAGE_SETTINGS) {
            // An administrator gets the check fresh (a fix made since shows at once); others the last result.
            if (current_user_can('manage_options')) {
                TeamOversight_Club_Documents::storage_status(true);
            } else {
                TeamOversight_Club_Documents::storage_summary();
            }
        }
    }

    // ------------------------------------------------------------------
    // The flash summary and the draft (Post/Redirect/Get)
    // ------------------------------------------------------------------

    private static function result($ok, $message, array $lines = array(), $type = '', $id = 0) {
        return array(
            'ok' => (bool) $ok,
            'type' => $type !== '' ? $type : ($ok ? 'success' : 'error'),
            'message' => (string) $message,
            'lines' => array_values($lines),
            'id' => (int) $id,
        );
    }

    /** A writer's result with every documented key present (and the takeover confirm, when Set up storage asks for one). */
    private static function normalise($r, $id = 0) {
        if (!is_array($r)) {
            return self::result(false, 'Nothing was changed: reload the page and try again.', array(), 'error', $id);
        }
        $ok = !empty($r['ok']);
        $type = isset($r['type']) && in_array($r['type'], array('success', 'warning', 'error'), true) ? $r['type'] : ($ok ? 'success' : 'error');
        $lines = array();
        foreach (isset($r['lines']) && is_array($r['lines']) ? $r['lines'] : array() as $line) {
            if (is_scalar($line)) {
                $lines[] = (string) $line;
            }
        }
        $message = isset($r['message']) && is_scalar($r['message']) ? (string) $r['message'] : '';
        if ($message === '') {
            $message = $ok ? 'Done.' : 'Nothing was changed: reload the page and try again.';
        }
        $out = self::result($ok, $message, $lines, $type, isset($r['id']) && intval($r['id']) > 0 ? intval($r['id']) : $id);
        if (!empty($r['stale'])) {
            $out['stale'] = true;
        }
        if (!empty($r['number'])) {
            $out['number'] = (int) $r['number'];
        }
        if (!empty($r['needs_confirm'])) {
            $out['takeover'] = array(
                'old' => isset($r['old_home']) ? (string) $r['old_home'] : '',
                'new' => isset($r['new_home']) ? (string) $r['new_home'] : '',
            );
        }
        return $out;
    }

    /** The summary the last button left for this person (shown once), or null. */
    public static function take_flash() {
        if (!self::$flash_taken) {
            self::$flash_taken = true;
            $key = self::FLASH_PREFIX . get_current_user_id();
            $flash = get_transient($key);
            if (is_array($flash)) {
                delete_transient($key);
                self::$flash = $flash;
            }
        }
        return self::$flash;
    }

    private static function render_flash() {
        $flash = self::take_flash();
        if (!is_array($flash)) {
            return;
        }
        $type = isset($flash['type']) && in_array($flash['type'], array('success', 'warning', 'error'), true) ? $flash['type'] : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . self::h(isset($flash['message']) ? $flash['message'] : '') . '</p>';
        if (!empty($flash['lines']) && is_array($flash['lines'])) {
            echo '<ul class="mc-lines">';
            foreach ($flash['lines'] as $line) {
                if (is_scalar($line)) {
                    echo '<li>' . self::h($line) . '</li>';
                }
            }
            echo '</ul>';
        }
        // Set up storage found another site's marker: show both addresses and ask before taking the folder over.
        if (!empty($flash['takeover']) && is_array($flash['takeover']) && current_user_can('manage_options')) {
            $old = isset($flash['takeover']['old']) ? (string) $flash['takeover']['old'] : '';
            $new = isset($flash['takeover']['new']) ? (string) $flash['takeover']['new'] : '';
            echo '<p>' . self::h('The folder was set up for ' . ($old !== '' ? $old : 'another address') . '. This site is ' . $new . '.') . '</p>';
            self::$view['anchor'] = 'storage';
            echo self::form_open('setup_storage', array('takeover' => 1))
                . '<button type="submit" class="button button-primary">Take the folder over for this site</button>'
                . ' <span class="description">Only after a change of web address. The files stay where they are.</span></form>';
        }
        echo '</div>';
    }

    /** What this person typed into a document whose save stored nothing (shown once), or null. */
    private static function take_draft($id) {
        $id = (int) $id;
        if (!array_key_exists($id, self::$drafts)) {
            $key = TeamOversight_Club_Documents::DRAFT_PREFIX . get_current_user_id() . '_' . $id;
            $draft = get_transient($key);
            if (is_array($draft)) {
                delete_transient($key);
            }
            self::$drafts[$id] = is_array($draft) ? $draft : null;
        }
        return self::$drafts[$id];
    }

    private static function keep_draft($actor, $id, array $input) {
        set_transient(TeamOversight_Club_Documents::DRAFT_PREFIX . (int) $actor . '_' . (int) $id, $input, 1800);
    }

    // ------------------------------------------------------------------
    // Forms and their nonces
    // ------------------------------------------------------------------

    /**
     * The nonce action a posted button must carry, bound to what it acts
     * on and built from validated values only ('' for an unknown do or a
     * bad id). Remove the public copy uses the core's own action, bound to
     * the attachment, the document and its row_version.
     */
    public static function nonce_action($do, array $data) {
        if (!is_string($do) || !in_array($do, self::DO_ACTIONS, true)) {
            return '';
        }
        if (in_array($do, self::VERSION_ACTIONS, true)) {
            $version = self::posted_id($data, 'version');
            return $version ? 'murvc_documents_' . $do . '_' . $version : '';
        }
        $doc = self::posted_id($data, 'document');
        switch ($do) {
            case 'save':
                // 0 is a new document.
                return $doc === null ? '' : 'murvc_documents_save_' . $doc;
            case 'upload':
            case 'withdraw':
                return $doc ? 'murvc_documents_' . $do . '_' . $doc : '';
            case 'move':
                $dir = isset($data['direction']) && is_string($data['direction']) && in_array($data['direction'], array('up', 'down'), true) ? $data['direction'] : '';
                return ($doc && $dir !== '') ? 'murvc_documents_move_' . $doc . '_' . $dir : '';
            case 'add_copy':
                $att = self::posted_id($data, 'attachment');
                return ($doc && $att) ? 'murvc_documents_add_copy_' . $doc . '_' . $att : '';
            case 'drop_copy':
                // Bound to the document and the old path itself (the Media Library entry may be gone).
                $path = isset($data['path']) && is_string($data['path']) ? $data['path'] : '';
                return ($doc && $path !== '') ? 'murvc_documents_drop_copy_' . $doc . '_' . md5($path) : '';
            case 'remove_copy':
                $att = self::posted_id($data, 'attachment');
                $rv = self::posted_id($data, 'row_version');
                return ($doc && $att && $rv !== null) ? TeamOversight_Club_Documents::remove_copy_nonce_action($att, $doc, $rv) : '';
            case 'setup_storage':
                return 'murvc_documents_setup_storage_' . (empty($data['takeover']) ? 0 : 1);
        }
        return 'murvc_documents_' . $do . '_0';
    }

    /** The admin-post address, with the action (and where to come back to) in the address as well as the body. */
    private static function post_url() {
        $v = self::$view;
        $args = array('action' => self::ACTION, 'return_page' => $v['page']);
        if ($v['document'] > 0) {
            $args['return_document'] = (int) $v['document'];
        }
        if ($v['anchor'] !== '') {
            $args['return_anchor'] = $v['anchor'];
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    /** The hidden fields that bring the person back to the same screen after a button. */
    private static function return_fields() {
        $v = self::$view;
        return '<input type="hidden" name="return_page" value="' . esc_attr($v['page']) . '">'
            . '<input type="hidden" name="return_document" value="' . (int) $v['document'] . '">'
            . '<input type="hidden" name="return_anchor" value="' . esc_attr($v['anchor']) . '">';
    }

    /**
     * Open a form for one button: action, do, the values it acts on, its
     * own nonce and the return fields. $extra: class names, e.g. mc-once.
     * $confirm: static text for a confirm box (never a name or a title).
     * $multipart for a file. The caller closes the form.
     */
    private static function form_open($do, array $data = array(), $extra = '', $confirm = '', $multipart = false, $with_nonce = true) {
        $html = '<form method="post" action="' . esc_url(self::post_url()) . '"'
            . ($multipart ? ' enctype="multipart/form-data"' : '')
            . ($extra !== '' ? ' class="' . esc_attr($extra) . '"' : '')
            . ($confirm !== '' ? ' data-mc-confirm="' . esc_attr($confirm) . '"' : '') . '>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($data as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        if ($with_nonce) {
            $html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_action($do, $data))) . '">';
        }
        return $html . self::return_fields();
    }

    // ------------------------------------------------------------------
    // The handler
    // ------------------------------------------------------------------

    /**
     * admin-post murvc_documents: every Club documents button. First the
     * too-big check (a body over post_max_size arrives empty, so it is
     * answered with a message and a redirect, not a blank page; nothing is
     * written, so no nonce is needed for it); then CAP, POST only, the
     * button's own nonce; process(); the flash; the redirect back.
     */
    public static function handle() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (string) $_SERVER['CONTENT_LENGTH'] : '0';
        $too_big = TeamOversight_Club_Documents::post_too_big($method, is_array($_POST) ? $_POST : array(), $length, (string) ini_get('post_max_size'));
        if ($too_big !== '') {
            if (!current_user_can(TeamOversight_Club_Documents::CAP)) {
                wp_die(esc_html('You do not have permission to do that.'), '', array('response' => 403));
            }
            $result = self::result(false, $too_big, array(), 'error');
            set_transient(self::FLASH_PREFIX . get_current_user_id(), $result, 300);
            wp_safe_redirect(self::return_url(wp_unslash(is_array($_GET) ? $_GET : array()), $result));
            exit;
        }
        if (!current_user_can(TeamOversight_Club_Documents::CAP)) {
            wp_die(esc_html('You do not have permission to do that.'), '', array('response' => 403));
        }
        if ($method !== 'POST') {
            wp_die(esc_html('Use the buttons on the Club documents screens.'), '', array('response' => 405));
        }
        $data = wp_unslash($_POST);
        $do = isset($data['do']) && is_string($data['do']) ? sanitize_key($data['do']) : '';
        $nonce_action = self::nonce_action($do, $data);
        $nonce = isset($data['_wpnonce']) && is_string($data['_wpnonce']) ? $data['_wpnonce'] : '';
        if ($nonce_action === '' || $nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) {
            wp_die(esc_html('This page has expired. Go back, reload it and try again.'), '', array('response' => 403));
        }
        $files = isset($_FILES) && is_array($_FILES) ? $_FILES : array();
        $result = self::process($do, $data, get_current_user_id(), $files, $method);
        set_transient(self::FLASH_PREFIX . get_current_user_id(), $result, 300);
        wp_safe_redirect(self::return_url($data, $result));
        exit;
    }

    /**
     * Run one button. $data: the unslashed POST; $files: $_FILES (the PDF
     * is $files['pdf']); $method: the request's method (null reads it).
     * No nonce here (handle() checked it; Remove the public copy checks its
     * own again in the writer); the actor must hold CAP, and manage_options
     * for ADMIN_ACTIONS. Returns array(ok, type (success|warning|error),
     * message, lines, id).
     */
    public static function process($do, array $data, $actor, array $files = array(), $method = null) {
        $actor = (int) $actor;
        $do = is_string($do) ? sanitize_key($do) : '';
        if ($method === null) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        }
        if (!in_array($do, self::DO_ACTIONS, true)) {
            return self::result(false, 'That button is not recognised: reload the page.');
        }
        if ($actor <= 0 || !user_can($actor, TeamOversight_Club_Documents::CAP)) {
            return self::result(false, 'You do not have permission to do that.');
        }
        if (in_array($do, self::ADMIN_ACTIONS, true) && !user_can($actor, 'manage_options')) {
            return self::result(false, 'Only an administrator can do that.');
        }
        $D = 'TeamOversight_Club_Documents';
        $file = isset($files['pdf']) && is_array($files['pdf']) ? $files['pdf'] : null;
        switch ($do) {
            case 'save':
                return self::do_save($data, $file, $actor);
            case 'upload':
                $id = self::posted_id($data, 'document');
                if (!$id) {
                    return self::result(false, 'No document came with the button: reload the page.');
                }
                return self::normalise($D::add_version($id, $file, self::text($data, 'dated'), self::text($data, 'note'), self::row_version($data), $actor), $id);
            case 'make_current':
                $version = $D::get_version(self::posted_id($data, 'version'));
                if (!$version) {
                    return self::result(false, 'That version was not found. Reload the page.');
                }
                return self::normalise($D::make_current((int) $version->document_id, (int) $version->id, self::row_version($data), $actor), (int) $version->document_id);
            case 'set_date':
                return self::normalise($D::set_version_date(self::posted_id($data, 'version'), self::text($data, 'dated'), self::text($data, 'note'), self::row_version($data), $actor));
            case 'delete_file':
                return self::normalise($D::delete_version_file(self::posted_id($data, 'version'), self::text($data, 'reason'), $actor));
            case 'withdraw':
                $id = self::posted_id($data, 'document');
                return self::normalise($D::set_status((int) $id, 'retired', self::row_version($data), $actor), (int) $id);
            case 'move':
                $id = self::posted_id($data, 'document');
                $dir = isset($data['direction']) && is_string($data['direction']) ? $data['direction'] : '';
                return self::normalise($D::move((int) $id, $dir, self::row_version($data), $actor), (int) $id);
            case 'groups':
                return self::do_groups($data, $actor);
            case 'settings':
                $fields = array();
                foreach (array('max_mb', 'join_page', 'contact_email') as $key) {
                    if (array_key_exists($key, $data)) {
                        $fields[$key] = is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
                    }
                }
                return self::normalise($D::save_settings($fields, $actor));
            case 'setup_storage':
                return self::normalise($D::setup_storage($actor, !empty($data['takeover'])));
            case 'check_now':
                return self::do_check_now($actor);
            case 'refresh':
                return self::normalise($D::refresh_pages($actor));
            case 'check_links':
                return self::do_check_links();
            case 'import':
                return self::do_import($data, $actor);
            case 'add_copy':
                $id = (int) self::posted_id($data, 'document');
                return self::normalise($D::add_legacy_path($id, (int) self::posted_id($data, 'attachment'), self::row_version($data), $actor), $id);
            case 'drop_copy':
                $id = (int) self::posted_id($data, 'document');
                return self::normalise($D::remove_legacy_path($id, self::text($data, 'path'), self::row_version($data), $actor), $id);
            case 'remove_copy':
                $id = (int) self::posted_id($data, 'document');
                return self::normalise($D::remove_public_copy((int) self::posted_id($data, 'attachment'), $actor, array(
                    'method' => $method,
                    'document' => $id,
                    'row_version' => self::row_version($data),
                    'nonce' => isset($data['_wpnonce']) && is_string($data['_wpnonce']) ? $data['_wpnonce'] : '',
                )), $id);
        }
        return self::result(false, 'That button is not recognised: reload the page.');
    }

    /** The posted row_version (0 when missing, which no document has, so a save from a broken form is stale). */
    private static function row_version(array $data) {
        $rv = self::posted_id($data, 'row_version');
        return $rv === null ? 0 : $rv;
    }

    /** One posted text value as a string ('' when missing or not text). The writers clean it. */
    private static function text(array $data, $key) {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }

    /** The document fields as posted, for the writers and for the draft. audiences is always a list (the form posts a blank first). */
    private static function posted_fields(array $data) {
        $ticks = array();
        if (isset($data['audiences']) && is_array($data['audiences'])) {
            foreach ($data['audiences'] as $key) {
                if (is_scalar($key) && (string) $key !== '') {
                    $ticks[] = (string) $key;
                }
            }
        }
        return array(
            'title' => self::text($data, 'title'),
            'description' => self::text($data, 'description'),
            'group_key' => self::text($data, 'group_key'),
            'doc_year' => self::text($data, 'doc_year'),
            'slug' => self::text($data, 'slug'),
            'audiences' => $ticks,
        );
    }

    /** Whether a PDF came with the form (an empty file box posts UPLOAD_ERR_NO_FILE). */
    private static function has_file($file) {
        return is_array($file) && isset($file['error']) && intval($file['error']) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Save (Add New and Edit): create or update (the update is held to the
     * posted row_version), then the PDF when one came with the form (a
     * document with no file yet), then Publish when asked. Whenever the
     * fields were not stored, what was typed is kept for the editor to
     * refill once.
     */
    private static function do_save(array $data, $file, $actor) {
        $D = 'TeamOversight_Club_Documents';
        $id = self::posted_id($data, 'document');
        if ($id === null) {
            return self::result(false, 'No document came with the button: reload the page.');
        }
        $before = null;
        if ($id > 0) {
            $before = $D::get_document($id);
            if (!$before) {
                return self::result(false, 'That document no longer exists: reload the page.');
            }
        }
        $intent = isset($data['intent']) && is_string($data['intent']) && in_array($data['intent'], array('draft', 'publish', 'update'), true) ? $data['intent'] : 'draft';
        $input = self::posted_fields($data);
        $draft = $input + array('dated' => self::text($data, 'dated'), 'note' => self::text($data, 'note'));
        $lines = array();
        $type = 'success';
        $number = 0;

        if ($id === 0) {
            $r = self::normalise($D::create_document($input, $actor));
            if (!$r['ok'] || $r['id'] <= 0) {
                self::keep_draft($actor, 0, $draft);
                return self::result(false, 'Nothing was saved. What you typed is still in the form: fix ' . (count($r['lines']) === 1 ? 'this' : 'these') . ' and save again.', $r['lines'] ? $r['lines'] : array($r['message']), 'error');
            }
            $id = $r['id'];
            $message = 'Saved as a draft.';
        } else {
            $rv = self::row_version($data);
            $r = self::normalise($D::update_document($id, $input, $rv, $actor), $id);
            if (!$r['ok']) {
                // A stale save's typed fields were kept by the writer; any other refusal keeps them here.
                if (empty($r['stale'])) {
                    self::keep_draft($actor, $id, $draft);
                    return self::result(false, 'Nothing was saved. What you typed is still in the form: fix ' . (count($r['lines']) === 1 ? 'this' : 'these') . ' and save again.', $r['lines'] ? $r['lines'] : array($r['message']), 'error', $id);
                }
                return $r;
            }
            $message = $r['message'];
            // A writer's warning (a public file that may still be in the site cache) is kept.
            $lines = $r['lines'];
            $type = $r['type'] === 'warning' ? 'warning' : 'success';
        }

        if (self::has_file($file)) {
            $doc = $D::get_document($id);
            $v = self::normalise($D::add_version($id, $file, self::text($data, 'dated'), self::text($data, 'note'), $doc ? (int) $doc->row_version : 0, $actor), $id);
            if (!$v['ok']) {
                // The writer's "Nothing was uploaded." is said here once; its reasons follow as lines.
                $why = $v['lines'];
                $rest = (string) preg_replace('/^Nothing was uploaded[.:]\s*/', '', $v['message']);
                if ($rest !== '') {
                    array_unshift($why, ucfirst($rest));
                }
                return self::result(true, ($before ? 'Saved' : 'Saved as a draft') . ', but the PDF wasn\'t uploaded.', $why, 'warning', $id);
            }
            $lines[] = $v['message'];
            $lines = array_merge($lines, $v['lines']);
            $number = isset($v['number']) ? (int) $v['number'] : 0;
            $type = $v['type'] === 'warning' ? 'warning' : $type;
        }

        $doc = $D::get_document($id);
        if ($intent === 'publish' && $doc && (string) $doc->status !== 'published') {
            $p = self::normalise($D::set_status($id, 'published', (int) $doc->row_version, $actor), $id);
            if ($p['ok']) {
                // Published in the same press: the upload's "opens once published" line would be out of date.
                if ($number > 0) {
                    $lines = array('Version ' . $number . ' uploaded.');
                }
                return self::result(true, $p['message'], $lines, 'success', $id);
            }
            $was = (string) $doc->status === 'retired' ? (empty($doc->published_at) ? 'Saved, still set aside: ' : 'Saved, still withdrawn: ') : 'Saved as a draft, not published: ';
            return self::result(true, $was . lcfirst($p['message']), $lines, 'warning', $id);
        }
        return self::result(true, $message, $lines, $type, $id);
    }

    /**
     * Save groups: the posted rows keyed by group key (label, order, intro,
     * by_year, remove), and a new group under the key _new, which can never
     * be a group key. Held to the hash of the list the screen was drawn from.
     */
    private static function do_groups(array $data, $actor) {
        $rows = array();
        if (isset($data['rows']) && is_array($data['rows'])) {
            foreach ($data['rows'] as $key => $row) {
                $key = (string) $key;
                if (preg_match(TeamOversight_Club_Documents::GROUP_KEY_PATTERN, $key) !== 1 || !is_array($row)) {
                    continue;
                }
                $rows[$key] = array(
                    'label' => self::text($row, 'label'),
                    'order' => self::text($row, 'order'),
                    'intro' => self::text($row, 'intro'),
                    'by_year' => empty($row['by_year']) ? 0 : 1,
                    'remove' => empty($row['remove']) ? 0 : 1,
                );
            }
        }
        $new = trim(self::text($data, 'new_label'));
        if ($new !== '') {
            $rows['_new'] = array('label' => $new, 'by_year' => empty($data['new_by_year']) ? 0 : 1);
        }
        if (empty($rows)) {
            return self::result(false, 'No groups came with the button: reload the page.');
        }
        return self::normalise(TeamOversight_Club_Documents::save_groups($rows, self::text($data, 'groups_hash'), $actor));
    }

    /** Check now: the folder check, then the web check, in one summary. */
    private static function do_check_now($actor) {
        $r = TeamOversight_Club_Documents::check_now($actor);
        $storage = isset($r['storage']) && is_array($r['storage']) ? $r['storage'] : array('ok' => false, 'problem' => '');
        $web = isset($r['web']) && is_array($r['web']) ? $r['web'] : array('state' => 'skipped', 'message' => '');
        if (empty($storage['ok'])) {
            return self::result(false, 'The folder check failed: ' . (string) $storage['problem']);
        }
        $line = isset($web['message']) ? (string) $web['message'] : '';
        if (isset($web['state']) && $web['state'] === 'fail') {
            return self::result(false, 'The folder check passed, but the web check failed.', $line !== '' ? array($line) : array());
        }
        return self::result(true, 'The folder check passed.', $line !== '' ? array($line) : array(), isset($web['state']) && $web['state'] === 'unknown' ? 'warning' : 'success');
    }

    /** Check again: the old-link check, read fresh. */
    private static function do_check_links() {
        $n = 0;
        foreach (TeamOversight_Club_Documents::old_links(true) as $entry) {
            foreach (isset($entry['old']) && is_array($entry['old']) ? $entry['old'] : array() as $hits) {
                $n += count((array) $hits);
            }
            $n += isset($entry['withdrawn']) ? count((array) $entry['withdrawn']) : 0;
        }
        if ($n === 0) {
            return self::result(true, 'Checked again: nothing links an old file or a withdrawn document.');
        }
        return self::result(true, 'Checked again: ' . self::plural($n, 'link needs', 'links need') . ' changing. To do lists them.', array(), 'warning');
    }

    /** Copy a PDF from the Media Library into a new draft (administrators). */
    private static function do_import(array $data, $actor) {
        $att = self::posted_id($data, 'attachment');
        if (!$att) {
            return self::result(false, 'Choose a PDF from the list.');
        }
        $fields = array(
            'dated' => self::text($data, 'dated'),
            'note' => self::text($data, 'note'),
            'title' => self::text($data, 'title'),
            'description' => self::text($data, 'description'),
            'group_key' => self::text($data, 'group_key'),
            'doc_year' => self::text($data, 'doc_year'),
            'slug' => self::text($data, 'slug'),
        );
        return self::normalise(TeamOversight_Club_Documents::import_attachment($att, $fields, $actor));
    }

    /**
     * Where to send the person after a button: the screen they were on,
     * every return_* re-validated. A new document (saved or copied) opens in
     * its own editor.
     */
    private static function return_url(array $data, array $result) {
        $page = isset($data['return_page']) && is_string($data['return_page']) && in_array($data['return_page'], self::PAGES, true) ? $data['return_page'] : TeamOversight_Club_Documents::PAGE_LIST;
        $args = array();
        $do = isset($data['do']) && is_string($data['do']) ? $data['do'] : '';
        if (in_array($do, array('save', 'import'), true) && !empty($result['id'])) {
            $page = TeamOversight_Club_Documents::PAGE_EDIT;
        }
        if ($page === TeamOversight_Club_Documents::PAGE_EDIT) {
            $doc = self::posted_id($data, 'return_document');
            if (in_array($do, array('save', 'import'), true) && !empty($result['id'])) {
                $doc = (int) $result['id'];
            }
            if ($doc) {
                $args['document'] = $doc;
            }
        }
        $url = self::page_url($page, $args);
        $anchor = isset($data['return_anchor']) && is_string($data['return_anchor']) && in_array($data['return_anchor'], self::ANCHORS, true) ? $data['return_anchor'] : '';
        if ($do === 'import' && !empty($result['id'])) {
            // A new copy opens at the top of its form, where the message says what to check before publishing.
            $anchor = '';
        }
        return $anchor !== '' ? $url . '#' . $anchor : $url;
    }

    // ------------------------------------------------------------------
    // Documents (club-documents, the landing page)
    // ------------------------------------------------------------------

    public static function render_list_page() {
        if (!current_user_can(TeamOversight_Club_Documents::CAP)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        $D = 'TeamOversight_Club_Documents';
        self::$view = array('page' => $D::PAGE_LIST, 'document' => 0, 'anchor' => '');

        echo '<div class="wrap murvc-docs-admin">';
        self::styles();
        echo '<h1 class="wp-heading-inline">Club documents</h1> <a href="' . esc_url(self::page_url($D::PAGE_EDIT)) . '" class="page-title-action">Add New</a>';
        echo '<hr class="wp-header-end">';
        self::render_flash();
        echo '<p class="mc-text">The club\'s policies and AGM papers. Each document has one permanent address that always opens its current file, so a link in a menu or an email keeps working when a new version goes up. The Policies and documents page lists them by group.</p>';

        self::render_todo(self::$todo !== null ? self::$todo : $D::todo());

        $docs = $D::list_documents();
        $current = array();
        foreach ($docs as $doc) {
            $current[] = (int) $doc->current_version_id;
        }
        $versions = $D::versions_by_id($current);
        $groups = $D::groups();
        $by_group = array();
        foreach ($docs as $doc) {
            $key = isset($groups[(string) $doc->group_key]) ? (string) $doc->group_key : '';
            $by_group[$key][] = $doc;
        }
        if (empty($docs)) {
            echo '<h2 class="mc-heading">Documents</h2><p class="mc-text">No documents yet. <a href="' . esc_url(self::page_url($D::PAGE_EDIT)) . '">Add the first one</a>.</p>';
        }
        foreach ($groups as $key => $group) {
            if (!empty($by_group[$key])) {
                self::render_group_table($group['label'] . (!empty($group['by_year']) ? ' (by year)' : ''), $by_group[$key], $versions);
            }
        }
        if (!empty($by_group[''])) {
            self::render_group_table('Not in a group', $by_group[''], $versions);
        }
        self::render_howto();
        self::script();
        echo '</div>';
    }

    /** One group's documents: title, who can open it, status, Updated, address with Copy, the arrows, Edit and Open. */
    private static function render_group_table($label, array $docs, array $versions) {
        $D = 'TeamOversight_Club_Documents';
        echo '<h2 class="mc-heading">' . self::h($label) . '</h2>';
        echo '<div class="mc-scroll"><table class="wp-list-table widefat striped mc-list"><thead><tr>'
            . '<th scope="col" class="manage-column column-primary">Title</th><th scope="col">Who can open it</th><th scope="col">Status</th>'
            . '<th scope="col">Updated</th><th scope="col">Address</th><th scope="col">Order</th>'
            . '</tr></thead><tbody>';
        $years = array();
        $statuses = array();
        foreach (array_values($docs) as $doc) {
            $years[] = $doc->doc_year === null ? null : (int) $doc->doc_year;
            $statuses[] = (string) $doc->status;
        }
        foreach (array_values($docs) as $i => $doc) {
            $id = (int) $doc->id;
            $title = $D::title_with_year($doc);
            $v = isset($versions[(int) $doc->current_version_id]) ? $versions[(int) $doc->current_version_id] : null;
            $actions = '<span class="edit"><a href="' . esc_url(self::edit_url($id)) . '">Edit</a></span>';
            if ($v && $D::version_file_ok($v)) {
                $actions .= ' | <span><a href="' . esc_url(TeamOversight_Club_Documents_Route::admin_file_url((int) $v->id)) . '" target="_blank" rel="noopener">Open<span class="screen-reader-text"> ' . self::h($title) . ' (opens in a new tab)</span></a></span>';
            }
            $address = $D::document_url((string) $doc->slug);
            $field = 'md-addr-' . $id;
            $arrows = '';
            self::$view['anchor'] = '';
            // An arrow only where a move can happen: the same rule as move() (a published document past the next published one).
            foreach (array('up' => array($D::move_target($years, $statuses, $i, 'up') !== false, '&uarr;', 'Move up'), 'down' => array($D::move_target($years, $statuses, $i, 'down') !== false, '&darr;', 'Move down')) as $dir => $spec) {
                if ($spec[0]) {
                    $arrows .= self::form_open('move', array('document' => $id, 'direction' => $dir, 'row_version' => (int) $doc->row_version), 'mc-inline')
                        . '<button type="submit" class="button button-small"><span aria-hidden="true">' . $spec[1] . '</span><span class="screen-reader-text">' . self::h($spec[2] . ': ' . $title) . '</span></button></form>';
                } else {
                    $arrows .= '<span class="mc-arrow-gap" aria-hidden="true"></span>';
                }
            }
            echo '<tr>'
                . '<td class="column-primary has-row-actions" data-colname="Title"><strong><a class="row-title" href="' . esc_url(self::edit_url($id)) . '">' . self::h($title) . '</a></strong>'
                . '<div class="row-actions">' . $actions . '</div>'
                . '<button type="button" class="toggle-row"><span class="screen-reader-text">Show more details</span></button></td>'
                . '<td data-colname="Who can open it">' . self::h(self::who_short($doc)) . '</td>'
                . '<td data-colname="Status">' . self::h(self::status_word($doc)) . '</td>'
                . '<td data-colname="Updated" class="mc-nowrap">' . self::h($v ? $D::nice_date($v->dated, 'j M Y') : 'No file yet') . '</td>'
                . '<td data-colname="Address"><div class="mc-copyrow"><label class="screen-reader-text" for="' . esc_attr($field) . '">' . self::h('Address of ' . $title) . '</label>'
                . '<input type="text" class="mc-copy" id="' . esc_attr($field) . '" value="' . esc_attr($address) . '" readonly> '
                . '<button type="button" class="button button-small" data-mc-copy="' . esc_attr($field) . '">Copy</button></div>'
                . ((string) $doc->status === 'draft' ? '<span class="mc-muted mc-hint">Not live until published.</span>' : '') . '</td>'
                . '<td data-colname="Order" class="mc-nowrap">' . $arrows . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** The list column: each ticked box's short word joined with " + " (only Everyone's while it is ticked), or Not chosen. */
    private static function who_short($doc) {
        $reg = TeamOversight_Club_Documents::audiences();
        $keys = TeamOversight_Club_Documents::audiences_of($doc);
        if (empty($keys)) {
            return 'Not chosen';
        }
        $words = array();
        $public = TeamOversight_Club_Documents::is_public($keys);
        foreach ($keys as $key) {
            if (!$public || !empty($reg[$key]['public'])) {
                $words[] = (string) $reg[$key]['short'];
            }
        }
        return implode(' + ', $words) . ($public && count($keys) > count($words) ? ' (other ticks kept)' : '');
    }

    private static function status_word($doc) {
        $D = 'TeamOversight_Club_Documents';
        $status = (string) $doc->status;
        if ($status === 'published' && !$D::is_listed($doc)) {
            return 'On the website, not listed';
        }
        if ($status === 'retired' && empty($doc->published_at)) {
            return 'Set aside';
        }
        $words = array('draft' => 'Draft', 'published' => 'On the website', 'retired' => 'Withdrawn');
        return isset($words[$status]) ? $words[$status] : $status;
    }

    /**
     * The To do panel: TeamOversight_Club_Documents::todo()'s rows as Fix /
     * Check pills, each with its link and the places it names, then Check
     * again and Show the list (the old-link check as text).
     */
    private static function render_todo($rows) {
        $rows = is_array($rows) ? array_values($rows) : array();
        $labels = array('warn' => 'Check', 'bad' => 'Fix');
        self::$view['anchor'] = 'todo';
        echo '<div class="mc-box mc-todo" id="todo"><h2>To do</h2>';
        echo '<p class="description">Only what needs doing. It clears itself when you fix something, and never blocks anything.</p>';
        if (empty($rows)) {
            echo '<p>Nothing to do right now.</p>';
        } else {
            echo '<div class="mc-scroll"><table class="widefat striped"><tbody>';
            foreach (array_slice($rows, 0, self::TODO_MAX) as $n => $row) {
                $state = isset($row['state'], $labels[$row['state']]) ? $row['state'] : 'warn';
                $url = isset($row['url']) ? (string) $row['url'] : '';
                $label = isset($row['label']) ? (string) $row['label'] : '';
                $links = '';
                foreach (isset($row['links']) && is_array($row['links']) ? $row['links'] : array() as $link) {
                    $title = isset($link['title']) ? (string) $link['title'] : '';
                    $href = isset($link['url']) ? (string) $link['url'] : '';
                    $links .= '<li>' . self::h($title) . ($href !== '' ? ' <a href="' . esc_url($href) . '">Edit</a>' : '') . '</li>';
                }
                // An address to paste in place of the old link, with a Copy button.
                $copy = isset($row['copy']) && is_string($row['copy']) ? $row['copy'] : '';
                $copy_html = '';
                if ($copy !== '') {
                    $fid = 'md-todo-copy-' . (int) $n;
                    $copy_html = '<div class="mc-copyrow"><label class="screen-reader-text" for="' . esc_attr($fid) . '">The address to use</label>'
                        . '<input type="text" class="mc-copy" id="' . esc_attr($fid) . '" value="' . esc_attr($copy) . '" readonly> '
                        . '<button type="button" class="button button-small" data-mc-copy="' . esc_attr($fid) . '">Copy</button></div>';
                }
                echo '<tr class="' . esc_attr('mc-todo-' . sanitize_html_class(isset($row['key']) ? (string) $row['key'] : '')) . '">'
                    . '<td class="mc-pill-cell"><span class="mc-pill mc-pill-' . esc_attr($state) . '">' . self::h($labels[$state]) . '</span></td>'
                    . '<td>' . self::h(isset($row['text']) ? $row['text'] : '') . $copy_html . ($links !== '' ? '<ul class="mc-lines">' . $links . '</ul>' : '') . '</td>'
                    . '<td class="mc-nowrap">' . ($url !== '' ? '<a href="' . esc_url($url) . '">' . self::h($label !== '' ? $label : 'Open') . '</a>' : '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
            if (count($rows) > self::TODO_MAX) {
                echo '<p class="mc-muted">' . self::h('and ' . (count($rows) - self::TODO_MAX) . ' more.') . '</p>';
            }
        }
        echo '<div class="mc-bar">' . self::form_open('check_links', array(), 'mc-inline mc-once') . '<button type="submit" class="button">Check again</button></form> ';
        if (self::$links_text === null) {
            echo '<a class="button" href="' . esc_url(self::page_url(TeamOversight_Club_Documents::PAGE_LIST, array('links' => '1')) . '#todo') . '">Show the list</a>';
        }
        echo '<span class="description">The old-link check runs once an hour. Check again reads it fresh.</span></div>';
        if (self::$links_text !== null) {
            echo '<h3>Every old link, as text</h3>';
            if (self::$links_text === '') {
                echo '<p>Nothing links an old file or a withdrawn document.</p>';
            } else {
                echo '<p class="description">Save this before you change the links: it is the list to put back if the release is ever rolled back.</p>'
                    . '<label class="screen-reader-text" for="md-links-text">Every old link</label>'
                    . '<textarea id="md-links-text" class="large-text code" rows="8" readonly>' . esc_textarea(self::$links_text) . '</textarea>';
            }
        }
        echo '</div>';
        self::$view['anchor'] = '';
    }

    /** How to: the day-to-day steps. */
    private static function render_howto() {
        $D = 'TeamOversight_Club_Documents';
        $add = esc_url(self::page_url($D::PAGE_EDIT));
        $settings = esc_url(self::page_url($D::PAGE_SETTINGS));
        ?>
        <details class="mc-box mc-howto" id="how-to">
            <summary>How to</summary>
            <div class="mc-text">
                <h3>Add a document</h3>
                <ol>
                    <li>Click <a href="<?php echo $add; ?>">Add New</a>, type the title and a one-line description, and pick the group.</li>
                    <li>Tick who can open it. A new document starts with nothing ticked, so the choice is always made on purpose. The sentence under the boxes says who can open it and whether the page lists it.</li>
                    <li>In AGM papers, set the AGM year: the year of the meeting, e.g. 2027 for the May 2027 AGM.</li>
                    <li>Choose the PDF and set the Updated date: today, or the date an older document was approved.</li>
                    <li>Check the address. It follows the title until you type your own, and is fixed for good once the document is published.</li>
                    <li>Click Publish. Save draft keeps it off the website.</li>
                </ol>
                <h3>Put up a new version</h3>
                <p>Open the document and use Upload a new version. The address stays the same and opens the new file straight away; the old file stays under Versions. If the upload was a mistake, use Go back to version N on the one before it.</p>
                <h3>Replacing a policy? Don't withdraw it</h3>
                <p>Withdraw only a document that is gone for good. The checkout page links the refund policy's address, so withdrawing it breaks checkout. Upload a new version instead.</p>
                <h3>AGM papers each year</h3>
                <p>Add each paper as its own document in AGM papers with the year of the meeting, and tick Associate members and Full members. After the AGM, upload the signed minutes as a new version of the same document. Then an administrator updates who holds the Editor role on Users: that is who the Committee box means.</p>
                <h3>Order</h3>
                <p>Groups are ordered on <a href="<?php echo $settings; ?>#groups">Groups and settings</a>. Inside a group, use the arrows. AGM papers sort by year, newest first, then by the arrows.</p>
            </div>
        </details>
        <?php
    }

    // ------------------------------------------------------------------
    // Add New / Edit (club-documents-edit: &document=<id>, or none for a new one)
    // ------------------------------------------------------------------

    public static function render_edit_page() {
        if (!current_user_can(TeamOversight_Club_Documents::CAP)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        $D = 'TeamOversight_Club_Documents';
        $id = self::get_id('document');
        $doc = $id > 0 ? $D::get_document($id) : null;
        self::$view = array('page' => $D::PAGE_EDIT, 'document' => $id, 'anchor' => '');
        $back = '<a href="' . esc_url(self::page_url($D::PAGE_LIST)) . '">Back to Club documents</a>';

        echo '<div class="wrap murvc-docs-admin">';
        self::styles();
        if ($id > 0 && !$doc) {
            echo '<h1>Club document</h1><hr class="wp-header-end">';
            self::render_flash();
            echo '<div class="notice notice-error inline"><p>That document doesn\'t exist. ' . $back . '.</p></div></div>';
            return;
        }
        $confirm = isset($_GET['confirm']) && is_string($_GET['confirm']) ? sanitize_key(wp_unslash($_GET['confirm'])) : '';
        if ($doc && $confirm === 'withdraw' && (string) $doc->status === 'published') {
            self::render_withdraw_confirm($doc);
            self::script();
            echo '</div>';
            return;
        }
        if ($doc && $confirm === 'remove_copy' && current_user_can('manage_options')) {
            self::render_remove_confirm($doc, self::get_id('attachment'));
            self::script();
            echo '</div>';
            return;
        }

        $draft = self::take_draft($id);
        $v = self::form_values($doc, $draft);
        $current = $doc ? $D::current_version($doc) : null;
        echo '<h1 class="wp-heading-inline">' . self::h($doc ? 'Edit document' : 'Add a document') . '</h1>';
        if ($doc) {
            echo ' <a href="' . esc_url(self::page_url($D::PAGE_EDIT)) . '" class="page-title-action">Add New</a>';
        }
        echo '<hr class="wp-header-end">';
        self::render_flash();
        echo '<p class="mc-text">' . ($doc ? '<strong>' . self::h('Document #' . $id . '.') . '</strong> ' : '')
            . 'Nothing shows on the website until the document is published. ' . $back . '.</p>';
        if (is_array($draft)) {
            echo '<div class="notice notice-warning inline"><p>The form below holds what you typed, not what is saved. Check it and save again.</p></div>';
        }
        self::render_status($doc, $current);

        // mc-once: the script disables the buttons once the form is sent, so a double click saves once.
        echo self::form_open('save', array('document' => $id, 'row_version' => $doc ? (int) $doc->row_version : 0), 'mc-form mc-once', '', true);
        self::render_fields($doc, $v, !$current);
        echo '<p class="mc-actions">';
        $status = $doc ? (string) $doc->status : 'draft';
        if ($status === 'published') {
            echo '<button type="submit" name="intent" value="update" class="button button-primary">Update</button> '
                . '<a class="button-link mc-danger" href="' . esc_url(self::page_url($D::PAGE_EDIT, array('document' => $id, 'confirm' => 'withdraw'))) . '">Withdraw from the website</a>';
        } elseif ($status === 'retired') {
            echo '<button type="submit" name="intent" value="update" class="button">Save</button> '
                . '<button type="submit" name="intent" value="publish" class="button button-primary">' . self::h(empty($doc->published_at) ? 'Publish' : 'Publish again') . '</button>';
        } else {
            echo '<button type="submit" name="intent" value="draft" class="button">Save draft</button> '
                . '<button type="submit" name="intent" value="publish" class="button button-primary">Publish</button>';
        }
        echo '</p></form>';
        if ($doc && $status === 'draft' && empty($doc->published_at)) {
            // A draft that isn't wanted (a file copied into the wrong document, say) can be set aside: it never goes up.
            self::$view['anchor'] = '';
            echo self::form_open('withdraw', array('document' => $id, 'row_version' => (int) $doc->row_version), 'mc-once',
                'Set this draft aside? It stays off the website, marked Set aside, until someone publishes it.')
                . '<p><button type="submit" class="button-link mc-danger">Set this draft aside</button>'
                . ' <span class="description">For a draft that isn\'t wanted. Nothing is deleted.</span></p></form>';
        }

        if ($doc) {
            if ($current) {
                self::render_upload_box($doc);
            }
            self::render_versions($doc);
            if (!empty($D::legacy_paths_of($doc))) {
                self::render_old_copies($doc);
            }
            self::render_history($D::history($id, 100));
        } elseif (current_user_can('manage_options')) {
            self::render_import_box();
        }
        self::script();
        echo '</div>';
    }

    /**
     * The values the form is drawn with: the saved row, or what was typed
     * when a save stored nothing (the draft), else the defaults for a new
     * document. The draft is posted_fields()' shape (audiences a list), or
     * clean_fields()' when the writer kept it for a stale save (a csv);
     * audiences_of() reads either.
     */
    private static function form_values($doc, $draft) {
        $D = 'TeamOversight_Club_Documents';
        $keys = array_keys($D::groups());
        $v = array(
            'title' => '', 'description' => '', 'group_key' => isset($keys[0]) ? (string) $keys[0] : '', 'doc_year' => '', 'slug' => '',
            'audiences' => array(), 'dated' => current_time('Y-m-d'), 'note' => '',
        );
        if ($doc) {
            foreach (array('title', 'description', 'group_key', 'slug') as $key) {
                $v[$key] = (string) $doc->$key;
            }
            $v['doc_year'] = $doc->doc_year === null ? '' : (string) intval($doc->doc_year);
            $v['audiences'] = $D::audiences_of($doc);
        }
        if (is_array($draft)) {
            foreach (array('title', 'description', 'group_key', 'slug', 'dated', 'note') as $key) {
                if (isset($draft[$key]) && is_scalar($draft[$key])) {
                    $v[$key] = (string) $draft[$key];
                }
            }
            if (array_key_exists('doc_year', $draft)) {
                $v['doc_year'] = $draft['doc_year'] === null ? '' : (string) $draft['doc_year'];
            }
            $v['audiences'] = $D::audiences_of(isset($draft['audiences']) ? $draft['audiences'] : '');
        }
        return $v;
    }

    /** The status line, read from the saved row. */
    private static function render_status($doc, $current) {
        $D = 'TeamOversight_Club_Documents';
        if (!$doc) {
            echo '<p class="mc-status">Not saved yet.</p>';
            return;
        }
        $status = (string) $doc->status;
        if ($status === 'published') {
            $url = $D::document_url((string) $doc->slug);
            $listed = $D::is_listed($doc);
            echo '<p class="mc-status mc-good-text">' . self::h('On the website' . (!empty($doc->published_at) ? ', first published ' . $D::nice_date($doc->published_at, 'j M Y') : '') . '.'
                . ($listed ? '' : ' Not listed on the page. ' . $D::who_sentence($doc))) . '</p>'
                . '<p class="mc-text">Its address: <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . self::h($url) . '<span class="screen-reader-text"> (opens in a new tab)</span></a></p>';
        } elseif ($status === 'retired' && empty($doc->published_at)) {
            $problem = $D::publish_problem($doc, $current);
            echo '<p class="mc-status">' . self::h('Set aside: never on the website, and its address shows page not found. ' . ($problem === '' ? 'Publish puts it up.' : 'Before it can be published: ' . lcfirst($problem))) . '</p>';
        } elseif ($status === 'retired') {
            echo '<p class="mc-status mc-bad-text">' . self::h('Withdrawn from the website on ' . $D::nice_date($doc->retired_at, 'j M Y') . '. Its address says so. Publish again brings it back at the same address.') . '</p>';
        } else {
            $problem = $D::publish_problem($doc, $current);
            echo '<p class="mc-status' . ($problem !== '' ? ' mc-bad-text' : '') . '">' . self::h('Draft: not on the website. ' . ($problem === '' ? 'Ready to publish.' : 'Can\'t be published yet: ' . lcfirst($problem))) . '</p>';
        }
        if ($current && $D::version_file_ok($current)) {
            echo '<p class="mc-text"><a href="' . esc_url(TeamOversight_Club_Documents_Route::admin_file_url((int) $current->id)) . '" target="_blank" rel="noopener">Open the current file<span class="screen-reader-text"> (opens in a new tab)</span></a></p>';
        }
    }

    /** One form-table row with its hint. $control is HTML built (and escaped) by the caller. */
    private static function field_row($label, $for, $control, $hint, $hint_id = '') {
        echo '<tr><th scope="row">' . ($for !== '' ? '<label for="' . esc_attr($for) . '">' . self::h($label) . '</label>' : self::h($label)) . '</th>'
            . '<td>' . $control . ($hint !== '' ? '<span class="description mc-hint"' . ($hint_id !== '' ? ' id="' . esc_attr($hint_id) . '"' : '') . '>' . self::h($hint) . '</span>' : '') . '</td></tr>';
    }

    /** The document's fields (design 3.2): title, description, group, AGM year, the boxes, the address, and the PDF while there is no file yet. */
    private static function render_fields($doc, array $v, $with_file) {
        $D = 'TeamOversight_Club_Documents';
        $groups = $D::groups();
        $desc_n = mb_strlen($v['description'], 'UTF-8');
        echo '<table class="form-table" role="presentation"><tbody>';

        self::field_row('Title', 'md-title',
            '<input type="text" class="regular-text" id="md-title" name="title" value="' . esc_attr($v['title']) . '" required>',
            'As the page shows it, e.g. Refund policy. In a group shown by year, leave the year out: it shows as a heading.');

        self::field_row('Description', 'md-description',
            '<textarea class="large-text" rows="2" id="md-description" name="description" data-max="' . (int) $D::DESCRIPTION_MAX . '" aria-describedby="md-description-count">' . esc_textarea($v['description']) . '</textarea>'
                . '<span class="mc-count' . ($desc_n > $D::DESCRIPTION_MAX ? ' mc-over' : '') . '" id="md-description-count" aria-live="polite">' . (int) $desc_n . ' / ' . (int) $D::DESCRIPTION_MAX . '</span>',
            'One or two lines under the title on the page, e.g. Returns and refunds for club uniforms and merchandise.');

        $options = '';
        foreach ($groups as $key => $group) {
            $options .= '<option value="' . esc_attr($key) . '"' . selected($v['group_key'], (string) $key, false) . '>' . self::h($group['label'] . (!empty($group['by_year']) ? ' (by year)' : '')) . '</option>';
        }
        if ($v['group_key'] !== '' && !isset($groups[$v['group_key']])) {
            $options = '<option value="" selected>' . self::h('Choose a group') . '</option>' . $options;
        }
        self::field_row('Group', 'md-group', '<select id="md-group" name="group_key">' . $options . '</select>',
            'The heading it sits under on the page. Groups and their order are set on Groups and settings.');

        self::field_row('AGM year', 'md-year',
            '<input type="text" class="small-text" id="md-year" name="doc_year" value="' . esc_attr($v['doc_year']) . '" inputmode="numeric" pattern="[0-9]{4}">',
            'Only in a group shown by year, such as AGM papers: the year of the meeting, e.g. 2027 for the May 2027 AGM. The 2026 annual report goes under 2027.');

        self::render_boxes($v['audiences']);

        if ($doc && !empty($doc->published_at)) {
            $url = $D::document_url((string) $doc->slug);
            self::field_row('Address', 'md-slug-fixed',
                '<div class="mc-copyrow"><input type="text" class="mc-copy" id="md-slug-fixed" value="' . esc_attr($url) . '" readonly> <button type="button" class="button" data-mc-copy="md-slug-fixed">Copy</button></div>',
                'Fixed for good, so a link in an old email still works.');
        } else {
            $prefix = home_url('/documents/');
            if (!$doc) {
                $hint = 'Leave blank and it is made from the title, e.g. agm-2027-minutes-2026 in AGM papers. Small letters, numbers and hyphens. Fixed once the document is published.';
            } else {
                // While the saved address is the one suggested for the saved details, it follows them on each save.
                $suggested = $D::suggest_for_row($doc);
                $hint = (string) $doc->slug === $suggested
                    ? 'Made from the title, and it follows the title, group, year and ticks when you save, until you type your own. Fixed once the document is published.'
                    : 'Typed by hand, so it stays as it is. Suggested for these details: ' . $suggested . '. Fixed once the document is published.';
            }
            self::field_row('Address', 'md-slug',
                '<span class="mc-prefix">' . self::h($prefix) . '</span><input type="text" class="regular-text" id="md-slug" name="slug" value="' . esc_attr($v['slug']) . '" pattern="[a-z0-9][a-z0-9\-]{1,58}[a-z0-9]">/',
                $hint);
        }

        if ($with_file) {
            self::render_file_rows($v['dated'], $v['note']);
        }
        echo '</tbody></table>';
    }

    /** The PDF, the Updated date and the note: on the form while there is no file yet, and in Upload a new version. */
    private static function render_file_rows($dated, $note, $prefix = 'md') {
        $D = 'TeamOversight_Club_Documents';
        self::field_row('PDF', $prefix . '-pdf',
            '<input type="file" id="' . esc_attr($prefix . '-pdf') . '" name="pdf" accept=".pdf,application/pdf">',
            'PDF only, up to ' . $D::mb_words($D::cap_bytes()) . '. It is kept in the club\'s private folder, never in the Media Library.');
        self::field_row('Updated date', $prefix . '-dated',
            '<input type="date" id="' . esc_attr($prefix . '-dated') . '" name="dated" value="' . esc_attr($dated) . '">',
            'The date the page shows as Updated. Today by default; for an older document, the date it was approved.');
        self::field_row('Note', $prefix . '-note',
            '<input type="text" class="regular-text" id="' . esc_attr($prefix . '-note') . '" name="note" value="' . esc_attr($note) . '">',
            'Optional, for the committee only, e.g. Approved at the 12 May committee meeting.');
    }

    /**
     * Who can open it: one checkbox per registry entry, each with its help
     * line, the rule 78 hint, and the live sentence. Every combination's two
     * lines are printed as JSON in data-sentences (sentence_table()); the
     * script picks one with textContent. A blank audiences[] goes first, so
     * unticking every box is still posted.
     */
    private static function render_boxes(array $ticked) {
        $D = 'TeamOversight_Club_Documents';
        $have = array_fill_keys($ticked, true);
        $boxes = '<input type="hidden" name="audiences[]" value="">';
        foreach ($D::audiences() as $key => $entry) {
            $id = 'md-aud-' . sanitize_html_class($key);
            $boxes .= '<div class="md-box"><label><input type="checkbox" name="audiences[]" value="' . esc_attr($key) . '" id="' . esc_attr($id) . '" aria-describedby="' . esc_attr($id . '-help') . '"'
                . (!empty($entry['public']) ? ' data-public="1"' : '') . (isset($have[$key]) ? ' checked' : '') . '> ' . self::h($entry['label']) . '</label>'
                . '<span class="description md-box-help" id="' . esc_attr($id . '-help') . '">' . self::h($entry['help']) . '</span></div>';
        }
        $who = $D::who_sentence($ticked);
        $page = $D::page_line($ticked);
        $control = '<fieldset id="md-boxes" class="md-boxes" data-sentences="' . esc_attr((string) wp_json_encode($D::sentence_table())) . '">'
            . '<legend class="screen-reader-text">Who can open it</legend>'
            . '<p class="md-rule78">Under the constitution (rule 78) every member, associates included, can see the AGM minutes and accounts. For AGM papers, tick Associate members and Full members.</p>'
            . $boxes
            . '<p class="md-greyed description" id="md-greyed" hidden>The greyed boxes keep their ticks, which count again once Everyone is unticked.</p>'
            . '<div class="md-sentence" aria-live="polite"><p id="md-who">' . self::h($who) . '</p><p id="md-page"' . ($page === '' ? ' hidden' : '') . '>' . self::h($page) . '</p></div>'
            . '</fieldset>';
        self::field_row('Who can open it', '', $control, 'A person can open it when they match any ticked box.');
    }

    /** Upload a new version (design 3.3): the address stays (and opens the new file once published); the old one stays under Versions. */
    private static function render_upload_box($doc) {
        $status = (string) $doc->status;
        if ($status === 'published') {
            $what = 'The address stays the same and opens the new file straight away.';
        } elseif ($status === 'retired' && !empty($doc->published_at)) {
            $what = 'The address stays the same and still says the document was withdrawn until you publish it again.';
        } else {
            $what = 'The new file opens at the address once the document is published.';
        }
        self::$view['anchor'] = 'upload';
        echo '<div class="mc-box" id="upload"><h2>Upload a new version</h2>'
            . '<p class="description">' . self::h($what . ' The old file stays under Versions.') . '</p>';
        echo self::form_open('upload', array('document' => (int) $doc->id, 'row_version' => (int) $doc->row_version), 'mc-form mc-once', '', true);
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_file_rows(current_time('Y-m-d'), '', 'md-up');
        echo '</tbody></table><p><button type="submit" class="button button-primary">Upload and make current</button></p></form></div>';
        self::$view['anchor'] = '';
    }

    /**
     * Versions: number, Updated, who uploaded it and when, size, note, and
     * Open, Make current, Change date or note, and Delete file for
     * administrators (never the current version's).
     */
    private static function render_versions($doc) {
        $D = 'TeamOversight_Club_Documents';
        $rows = $D::versions((int) $doc->id);
        $admin = current_user_can('manage_options');
        self::$view['anchor'] = 'versions';
        echo '<h2 id="versions">Versions</h2>';
        if (empty($rows)) {
            echo '<p class="mc-muted">No file yet.</p>';
            self::$view['anchor'] = '';
            return;
        }
        echo '<p class="mc-text mc-muted">Every file uploaded for this document, newest first. The address only ever opens the current one; the committee opens the others from here.</p>';
        $ids = array();
        foreach ($rows as $r) {
            foreach (array('uploaded_by', 'file_deleted_by') as $col) {
                if ((int) $r->$col > 0) {
                    $ids[(int) $r->$col] = (int) $r->$col;
                }
            }
        }
        if (!empty($ids)) {
            cache_users(array_values($ids));
        }
        $current_number = 0;
        foreach ($rows as $r) {
            if ((int) $r->id === (int) $doc->current_version_id) {
                $current_number = (int) $r->number;
            }
        }
        // Whether an old public copy is still in the Media Library: an imported version is then the club's copy of it.
        $public_copy = false;
        if ($admin) {
            foreach ($D::legacy_copies($doc) as $copy) {
                $public_copy = $public_copy || ($copy['exists'] && $copy['attachment'] > 0);
            }
        }
        echo '<div class="mc-scroll"><table class="widefat striped mc-versions"><thead><tr><th scope="col">Version</th><th scope="col">Updated</th><th scope="col">Uploaded</th><th scope="col">Size</th><th scope="col">Note</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $vid = (int) $r->id;
            $num = (int) $r->number;
            $is_current = (int) $doc->current_version_id === $vid;
            $source = (string) $r->source === 'import'
                ? 'Copied from the Media Library (' . (string) $r->imported_from . ')'
                : 'Uploaded as ' . (string) $r->original_name;
            $uploaded = mysql2date('j M Y', (string) $r->uploaded_at) . ' by ' . self::who($r->uploaded_by);
            $actions = array();
            if (!empty($r->file_deleted_at)) {
                $actions[] = '<span class="mc-muted">' . self::h('File deleted ' . mysql2date('j M Y', (string) $r->file_deleted_at) . ' by ' . self::who($r->file_deleted_by) . ': ' . (string) $r->file_deleted_reason) . '</span>';
            } elseif (!$D::version_file_ok($r)) {
                $actions[] = '<span class="mc-bad-text">File missing from storage.</span>';
            } else {
                $actions[] = '<a href="' . esc_url(TeamOversight_Club_Documents_Route::admin_file_url($vid)) . '" target="_blank" rel="noopener">Open<span class="screen-reader-text"> ' . self::h('version ' . $num) . ' (opens in a new tab)</span></a>';
                if (!$is_current) {
                    $actions[] = self::form_open('make_current', array('version' => $vid, 'row_version' => (int) $doc->row_version), 'mc-inline mc-once')
                        . '<button type="submit" class="button button-small">' . self::h($num < $current_number ? 'Go back to version ' . $num : 'Make current') . '</button></form>';
                }
            }
            $actions[] = '<details class="mc-more"><summary>Change date or note</summary>'
                . self::form_open('set_date', array('version' => $vid, 'row_version' => (int) $doc->row_version), 'mc-once')
                . '<p><label for="' . esc_attr('md-vd-' . $vid) . '">Updated date</label> <input type="date" id="' . esc_attr('md-vd-' . $vid) . '" name="dated" value="' . esc_attr((string) $r->dated) . '"></p>'
                . '<p><label for="' . esc_attr('md-vn-' . $vid) . '">Note</label> <input type="text" class="regular-text" id="' . esc_attr('md-vn-' . $vid) . '" name="note" value="' . esc_attr((string) $r->note) . '"></p>'
                . '<p><button type="submit" class="button button-small">Save</button> <span class="description">Recorded in History.</span></p></form></details>';
            if ($admin && !$is_current && empty($r->file_deleted_at)) {
                $actions[] = '<details class="mc-more"><summary class="mc-danger">Delete file</summary>'
                    . self::form_open('delete_file', array('version' => $vid), 'mc-once', 'Delete this version\'s file for good? Its row stays in Versions.')
                    . '<p class="description">Only for a wrong upload. The file is deleted for good; this row stays, with your reason.'
                    . ($public_copy && (string) $r->source === 'import' ? ' This is the club\'s copy of the Media Library file: without it, the public copy can\'t be removed.' : '') . '</p>'
                    . '<p><label for="' . esc_attr('md-vr-' . $vid) . '">Why</label> <input type="text" class="regular-text" id="' . esc_attr('md-vr-' . $vid) . '" name="reason" required></p>'
                    . '<p><button type="submit" class="button button-small mc-danger-button">Delete this file</button></p></form></details>';
            }
            echo '<tr' . ($is_current ? ' class="mc-current"' : '') . '>'
                . '<td class="mc-nowrap">' . self::h($num . ($is_current ? ' (current)' : '')) . '</td>'
                . '<td class="mc-nowrap">' . self::h($D::nice_date($r->dated, 'j M Y')) . '</td>'
                . '<td>' . self::h($uploaded) . '<span class="mc-muted mc-hint">' . self::h($source) . '</span></td>'
                . '<td class="mc-nowrap">' . self::h(self::size_words((int) $r->size_bytes)) . '</td>'
                . '<td>' . self::h((string) $r->note) . '</td>'
                . '<td>' . implode(' ', $actions) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::$view['anchor'] = '';
    }

    /**
     * Old copies (design 3.11): each Media Library path that redirects here,
     * whether its public copy is still there, and Remove the public copy for
     * administrators (one file per confirm). For administrators, any
     * identical copy elsewhere in the Media Library, with Add to this
     * document.
     */
    private static function render_old_copies($doc) {
        $D = 'TeamOversight_Club_Documents';
        $admin = current_user_can('manage_options');
        $copies = $D::legacy_copies($doc);
        self::$view['anchor'] = 'old-copies';
        echo '<h2 id="old-copies">Old copies</h2>';
        echo '<p class="mc-text mc-muted">The Media Library file this document was copied from, and any identical copy added since. While a public copy is there, anyone with its old link can open it. Once it is removed, its old address sends people to this document\'s address.</p>';
        echo '<div class="mc-scroll"><table class="widefat striped"><thead><tr><th scope="col">Old file</th><th scope="col">Public copy</th><th scope="col"></th></tr></thead><tbody>';
        $live = $D::address_live($doc);
        $never = empty($doc->published_at);
        foreach ($copies as $path => $copy) {
            $action = '';
            if ($copy['exists'] && $copy['attachment'] > 0) {
                $state = 'Still in the Media Library';
                if ($admin && $live) {
                    $action = '<a class="mc-danger" href="' . esc_url(self::page_url($D::PAGE_EDIT, array('document' => (int) $doc->id, 'confirm' => 'remove_copy', 'attachment' => (int) $copy['attachment']))) . '">Remove the public copy</a>';
                } elseif ($admin) {
                    $action = '<span class="mc-muted">Publish this document first. Until then its address shows page not found, so the old link would break.</span>';
                }
            } elseif ($copy['exists']) {
                $state = 'The Media Library entry is gone, but the file is still on the server, so its old link still opens it. Delete it on the server.';
            } else {
                $state = $live ? 'Removed. Its old address sends people to this document.' : 'Not on the server.';
            }
            if ($admin && $never) {
                // A file copied into the wrong draft: take it off, so the right document can copy it.
                self::$view['anchor'] = 'old-copies';
                $action .= ($action !== '' ? '<br>' : '') . self::form_open('drop_copy', array('document' => (int) $doc->id, 'path' => $path, 'row_version' => (int) $doc->row_version), 'mc-inline mc-once',
                    'Take this old copy off the document? The Media Library file stays as it is.')
                    . '<button type="submit" class="button button-small">Not this document\'s file</button></form>';
            }
            echo '<tr><td><a href="' . esc_url($copy['url']) . '" target="_blank" rel="noopener"><code>' . self::h($path) . '</code><span class="screen-reader-text"> (opens in a new tab)</span></a></td>'
                . '<td>' . self::h($state) . '</td>'
                . '<td>' . $action . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (!$admin) {
            echo '<p class="mc-muted">Removing a public copy is an administrator\'s step.</p>';
        } else {
            $first = null;
            foreach ($D::versions((int) $doc->id) as $r) {
                if ((int) $r->number === 1) {
                    $first = $r;
                }
            }
            $held = array_fill_keys(array_keys($copies), true);
            $same = $first ? $D::identical_attachments((string) $first->sha256, (int) $first->size_bytes, 0) : array();
            $offer = array();
            foreach ($same as $att => $path) {
                if (!isset($held[$path])) {
                    $offer[$att] = $path;
                }
            }
            if (!empty($offer)) {
                echo '<h3>The same file elsewhere in the Media Library</h3>'
                    . '<p class="mc-text">Each of these holds exactly version 1\'s file. Add it, so its old links are checked and, once its copy is removed, redirected here too.</p><ul class="mc-lines">';
                foreach ($offer as $att => $path) {
                    echo '<li><code>' . self::h($path) . '</code> '
                        . self::form_open('add_copy', array('document' => (int) $doc->id, 'attachment' => (int) $att, 'row_version' => (int) $doc->row_version), 'mc-inline mc-once')
                        . '<button type="submit" class="button button-small">Add to this document</button></form></li>';
                }
                echo '</ul>';
            }
        }
        self::$view['anchor'] = '';
    }

    /** History: the newest 100 changes, each with the date, who and a plain sentence. */
    private static function render_history(array $rows) {
        echo '<h2 id="history">History</h2>';
        if (empty($rows)) {
            echo '<p class="mc-muted">No changes recorded yet.</p>';
            return;
        }
        $ids = array();
        foreach ($rows as $row) {
            if ((int) $row->recorded_by > 0) {
                $ids[(int) $row->recorded_by] = (int) $row->recorded_by;
            }
        }
        if (!empty($ids)) {
            cache_users(array_values($ids));
        }
        echo '<div class="mc-scroll"><table class="widefat striped mc-history"><thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td class="mc-nowrap">' . self::h(mysql2date('j M Y g:ia', (string) $row->recorded_at)) . '</td>'
                . '<td>' . self::h(self::who($row->recorded_by)) . '</td>'
                . '<td>' . self::history_html($row) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** One history row as escaped HTML. Every value decoded from the JSON is escaped. */
    private static function history_html($row) {
        $D = 'TeamOversight_Club_Documents';
        $event = (string) $row->event;
        $d = self::decode($row->detail);
        $num = isset($d['number']) && is_scalar($d['number']) ? (int) $d['number'] : 0;
        $path = isset($d['path']) && is_scalar($d['path']) ? (string) $d['path'] : '';
        switch ($event) {
            case 'created':
                return self::h('Created as a draft.');
            case 'updated':
                return self::diff_html($d);
            case 'version_added':
                $dated = isset($d['dated']) && is_scalar($d['dated']) ? $D::nice_date((string) $d['dated'], 'j M Y') : '';
                $size = isset($d['size']) ? self::size_words((int) $d['size']) : '';
                return self::h('Version ' . $num . ' uploaded (Updated ' . $dated . ', ' . $size . ')' . (!empty($d['current']) ? ' and made current.' : ', not made current: the document changed meanwhile.'));
            case 'made_current':
                $pair = isset($d['number']) && is_array($d['number']) ? $d['number'] : array(null, null);
                return self::h('Version ' . (int) (isset($pair[1]) ? $pair[1] : 0) . ' made current' . (isset($pair[0]) && $pair[0] !== null ? ' (was version ' . (int) $pair[0] . ')' : '') . '.');
            case 'version_dated':
                $parts = array();
                $side = function ($pair, $i) {
                    return isset($pair[$i]) && is_scalar($pair[$i]) ? (string) $pair[$i] : '';
                };
                if (isset($d['dated']) && is_array($d['dated'])) {
                    $parts[] = 'Updated date ' . $D::nice_date($side($d['dated'], 0), 'j M Y') . ' → ' . $D::nice_date($side($d['dated'], 1), 'j M Y');
                }
                if (isset($d['note']) && is_array($d['note'])) {
                    $parts[] = 'note "' . $side($d['note'], 0) . '" → "' . $side($d['note'], 1) . '"';
                }
                return self::h('Version ' . $num . ': ' . implode('; ', $parts) . '.');
            case 'published':
                return self::h('Published.');
            case 'republished':
                return self::h('Published again.');
            case 'retired':
                return self::h('Withdrawn from the website.');
            case 'set_aside':
                return self::h('Set aside (never on the website).');
            case 'unpublished':
                return self::h('Taken back to a draft.');
            case 'moved':
                return self::h(isset($d['direction']) && $d['direction'] === 'up' ? 'Moved up.' : 'Moved down.');
            case 'file_deleted':
                return self::h('Version ' . $num . '\'s file deleted: ' . (isset($d['reason']) && is_scalar($d['reason']) ? (string) $d['reason'] : '') . '.');
            case 'imported':
                $dated = isset($d['dated']) && is_scalar($d['dated']) ? $D::nice_date((string) $d['dated'], 'j M Y') : '';
                return self::h('Copied from the Media Library (' . $path . ') as a draft, Updated ' . $dated . '.');
            case 'legacy_path_added':
                return self::h('Identical old copy added: ' . $path . '.');
            case 'legacy_path_removed':
                return self::h('Old copy taken off (not this document\'s file): ' . $path . '.');
            case 'public_copy_removed':
                return self::h('Old public copy deleted from the Media Library: ' . $path . '.');
            case 'public_copy_failed':
                return self::h(!empty($d['file_kept'])
                    ? 'Old public copy deleted from the Media Library, but its file stayed on the server: ' . $path . '.'
                    : 'Deleting the old public copy failed: ' . $path . '.');
        }
        return self::h(ucfirst(str_replace('_', ' ', $event)) . '.');
    }

    /** An updated row's field => array(old, new), one line a field; the ticks in the boxes' short words. */
    private static function diff_html(array $detail) {
        $parts = array();
        foreach ($detail as $field => $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $field = (string) $field;
            $old = self::value_text($field, array_key_exists(0, $pair) ? $pair[0] : '');
            $new = self::value_text($field, array_key_exists(1, $pair) ? $pair[1] : '');
            $label = isset(self::FIELD_LABELS[$field]) ? self::FIELD_LABELS[$field] : ucfirst(str_replace('_', ' ', $field));
            $parts[] = self::h($label . ': ' . ($old !== '' ? $old : '(blank)') . ' → ' . ($new !== '' ? $new : '(blank)'));
        }
        return empty($parts) ? self::h('Saved, with no field changed.') : self::h('Changed:') . '<br>' . implode('<br>', $parts);
    }

    /** A stored value in words: the ticks as the boxes' short words, a group key as its name. Plain text. */
    private static function value_text($field, $value) {
        if ($value === null) {
            return '';
        }
        if ($field === 'audiences') {
            $reg = TeamOversight_Club_Documents::audiences();
            $words = array();
            foreach (TeamOversight_Club_Documents::audiences_of(is_scalar($value) ? (string) $value : '') as $key) {
                $words[] = (string) $reg[$key]['short'];
            }
            return empty($words) ? 'Not chosen' : implode(' + ', $words);
        }
        if ($field === 'group_key') {
            $groups = TeamOversight_Club_Documents::groups();
            return isset($groups[(string) $value]) ? (string) $groups[(string) $value]['label'] : (string) $value;
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /** The Withdraw confirm (design 3.6): don't withdraw to replace; every place linking its address; then the button. */
    private static function render_withdraw_confirm($doc) {
        $D = 'TeamOversight_Club_Documents';
        $id = (int) $doc->id;
        $title = $D::title_with_year($doc);
        $links = $D::links_to_address($doc);
        echo '<h1>Withdraw from the website</h1><hr class="wp-header-end">';
        echo '<div class="mc-box mc-warn-box"><p><strong>Replacing a policy? Don\'t withdraw it.</strong> Use Upload a new version on the document instead: the address stays the same, so links to it keep working. The checkout page links the refund policy\'s address, so withdrawing that policy breaks checkout.</p></div>';
        echo '<p class="mc-text">' . self::h('Withdrawing takes "' . $title . '" off the page. Its address then says the club withdrew it on ' . $D::nice_date(current_time('Y-m-d')) . ', with a link to the documents page. The committee can still open every version, and Publish again brings it back at the same address.') . '</p>';
        echo '<h2>Where its address is linked</h2>';
        self::render_link_list($links, 'Nothing we know of links its address.');
        self::$view['anchor'] = '';
        echo self::form_open('withdraw', array('document' => $id, 'row_version' => (int) $doc->row_version), 'mc-once')
            . '<p class="mc-actions"><button type="submit" class="button button-primary mc-danger-button">Withdraw from the website</button> '
            . '<a href="' . esc_url(self::edit_url($id)) . '">Cancel</a></p></form>';
    }

    /** The Remove the public copy confirm (design 3.11, step 1 of 2): the file, every place still linking it, what happens next. */
    private static function render_remove_confirm($doc, $attachment_id) {
        $D = 'TeamOversight_Club_Documents';
        $id = (int) $doc->id;
        $facts = $D::remove_copy_facts($attachment_id, $id);
        echo '<h1>Remove the public copy</h1><hr class="wp-header-end">';
        if (empty($facts['ok'])) {
            echo '<div class="notice notice-error inline"><p>' . self::h($facts['problem']) . ' <a href="' . esc_url(self::edit_url($id) . '#old-copies') . '">Back to the document</a>.</p></div>';
            return;
        }
        echo '<p class="mc-text">' . self::h('Document: ' . $D::title_with_year($doc) . '.') . '</p>';
        echo '<p class="mc-text">File: <code>' . self::h($facts['path']) . '</code></p>'
            . '<p class="mc-text">Its old address: <a href="' . esc_url($facts['url']) . '" target="_blank" rel="noopener">' . self::h($facts['url']) . '<span class="screen-reader-text"> (opens in a new tab)</span></a></p>';
        echo '<div class="mc-box mc-warn-box"><p>' . self::h('This deletes the file from the Media Library for good. The club\'s copy is version ' . (int) $facts['version'] . ' of this document, and the old address will send people to the new one.') . '</p></div>';
        echo '<h2>Still linking it</h2>';
        self::render_link_list($facts['links'], 'Nothing we know of links it.');
        if (!empty($facts['links'])) {
            echo '<p class="mc-text">Repoint these first. Once the file is gone they still work, through a redirect.</p>';
        }
        self::$view['anchor'] = 'old-copies';
        echo self::form_open('remove_copy', array('document' => $id, 'attachment' => (int) $facts['attachment'], 'row_version' => (int) $doc->row_version), 'mc-once', '', false, false);
        wp_nonce_field($facts['nonce_action'], '_wpnonce', false);
        echo '<p class="mc-actions"><button type="submit" class="button button-primary mc-danger-button">Delete the public copy</button> '
            . '<a href="' . esc_url(self::edit_url($id) . '#old-copies') . '">Cancel</a></p></form>';
        self::$view['anchor'] = '';
    }

    /** A list of old-link check hits (where, title, edit link), or $none. */
    private static function render_link_list($links, $none) {
        if (empty($links) || !is_array($links)) {
            echo '<p class="mc-text">' . self::h($none) . '</p>';
            return;
        }
        echo '<ul class="mc-lines">';
        foreach ($links as $hit) {
            $where = isset($hit['where']) ? ucfirst((string) $hit['where']) : '';
            $title = isset($hit['title']) ? (string) $hit['title'] : '';
            $url = isset($hit['edit_url']) ? (string) $hit['edit_url'] : '';
            echo '<li>' . self::h($where . ': ' . $title) . ($url !== '' ? ' <a href="' . esc_url($url) . '">Edit</a>' : '') . '</li>';
        }
        echo '</ul>';
    }

    /**
     * Copy a PDF from the Media Library (administrators, on Add New): the
     * PDF, the date approved (required, empty to start), and optionally
     * the title, group, AGM year and a note. The file is copied, never
     * moved; the Media Library file is left as it was.
     */
    private static function render_import_box() {
        $D = 'TeamOversight_Club_Documents';
        self::$view['anchor'] = 'import';
        echo '<div class="mc-box" id="import"><h2>Copy a PDF from the Media Library</h2>';
        echo '<p class="description">For a document already on the website as a Media Library file. The file is copied into the private folder as version 1 of a new draft. The Media Library file is left exactly as it is: its old link keeps working until an administrator removes the public copy, and from then on it sends people to the new address.</p>';
        $files = $D::pdf_attachments();
        if (empty($files)) {
            echo '<p>There are no PDFs in the Media Library.</p></div>';
            self::$view['anchor'] = '';
            return;
        }
        $options = '<option value="">' . self::h('Choose a PDF') . '</option>';
        foreach ($files as $f) {
            $label = $f['title'] . ' (' . $f['path'] . ', uploaded ' . $D::nice_date($f['uploaded'], 'j M Y') . ')';
            if (!empty($f['document'])) {
                $options .= '<option value="" disabled>' . self::h($label . ': already copied') . '</option>';
            } else {
                $options .= '<option value="' . (int) $f['id'] . '">' . self::h($label) . '</option>';
            }
        }
        $groups = '';
        foreach ($D::groups() as $key => $group) {
            $groups .= '<option value="' . esc_attr($key) . '">' . self::h($group['label']) . '</option>';
        }
        echo self::form_open('import', array(), 'mc-form mc-once');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::field_row('PDF', 'md-imp-file', '<select id="md-imp-file" name="attachment" required>' . $options . '</select>', 'Each file shows the date it was uploaded to the website.');
        self::field_row('Date approved', 'md-imp-dated', '<input type="date" id="md-imp-dated" name="dated" value="" required>',
            'The date the committee approved this version, not the upload date. The page shows it as Updated.');
        self::field_row('Title', 'md-imp-title', '<input type="text" class="regular-text" id="md-imp-title" name="title" value="">', 'As the page shows it, e.g. Refund policy. Blank: the file\'s title in the Media Library. The address is made from the title.');
        self::field_row('Group', 'md-imp-group', '<select id="md-imp-group" name="group_key">' . $groups . '</select>', '');
        self::field_row('AGM year', 'md-imp-year', '<input type="text" class="small-text" id="md-imp-year" name="doc_year" value="" inputmode="numeric" pattern="[0-9]{4}">', 'Only in a group shown by year, such as AGM papers.');
        self::field_row('Address', 'md-imp-slug',
            '<span class="mc-prefix">' . self::h(home_url('/documents/')) . '</span><input type="text" class="regular-text" id="md-imp-slug" name="slug" value="" pattern="[a-z0-9][a-z0-9\-]{1,58}[a-z0-9]">/',
            'Optional, e.g. refund-policy. Blank: made from the title. Fixed once the document is published.');
        self::field_row('Note', 'md-imp-note', '<input type="text" class="regular-text" id="md-imp-note" name="note" value="">', 'Optional, for the committee only.');
        echo '</tbody></table><p><button type="submit" class="button">Copy as a draft</button> <span class="description">It opens as a draft, to finish before publishing.</span></p></form></div>';
        self::$view['anchor'] = '';
    }

    // ------------------------------------------------------------------
    // Groups and settings (club-documents-settings)
    // ------------------------------------------------------------------

    public static function render_settings_page() {
        if (!current_user_can(TeamOversight_Club_Documents::CAP)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        $D = 'TeamOversight_Club_Documents';
        self::$view = array('page' => $D::PAGE_SETTINGS, 'document' => 0, 'anchor' => '');
        echo '<div class="wrap murvc-docs-admin">';
        self::styles();
        echo '<h1>Club documents: groups and settings</h1><hr class="wp-header-end">';
        self::render_flash();
        echo '<p class="mc-text"><a href="#groups">Groups</a> · <a href="#managers">Who can manage documents</a> · <a href="#storage">File storage</a> · <a href="#settings">Settings</a> · <a href="#pages">The documents page</a></p>';
        self::render_groups_section();
        self::render_managers_section();
        self::render_storage_section();
        self::render_settings_section();
        self::render_pages_section();
        self::script();
        echo '</div>';
    }

    /** #groups: one row per group, Add a group, one Save groups button held to the hash of what was drawn. */
    private static function render_groups_section() {
        $D = 'TeamOversight_Club_Documents';
        self::$view['anchor'] = 'groups';
        $counts = array();
        foreach ($D::list_documents() as $doc) {
            $counts[(string) $doc->group_key] = (isset($counts[(string) $doc->group_key]) ? $counts[(string) $doc->group_key] : 0) + 1;
        }
        echo '<h2 id="groups">Groups</h2>';
        echo '<p class="mc-text">Each group is a heading on the page, in this order (lowest first). Shown by year puts a year heading over its documents, newest first, as AGM papers does. The intro shows under the heading. For a link, type <code>&lt;a href="/privacy-policy/"&gt;Privacy Policy&lt;/a&gt;</code>; for bold, <code>&lt;strong&gt;words&lt;/strong&gt;</code>. Other tags are taken out, apart from em, p and br. A group that still holds documents can\'t be removed.</p>';
        echo self::form_open('groups', array('groups_hash' => $D::groups_hash()), 'mc-once');
        echo '<div class="mc-scroll"><table class="widefat striped mc-groups"><thead><tr><th scope="col">Name</th><th scope="col">Order</th><th scope="col">Shown by year</th><th scope="col">Intro</th><th scope="col">Documents</th><th scope="col">Remove</th></tr></thead><tbody>';
        foreach ($D::groups() as $key => $group) {
            $base = 'rows[' . $key . ']';
            $fid = 'md-g-' . sanitize_html_class($key);
            $n = isset($counts[$key]) ? $counts[$key] : 0;
            echo '<tr>'
                . '<td><label class="screen-reader-text" for="' . esc_attr($fid . '-label') . '">Name</label><input type="text" id="' . esc_attr($fid . '-label') . '" name="' . esc_attr($base . '[label]') . '" value="' . esc_attr($group['label']) . '"><span class="mc-muted mc-hint">' . self::h('Key: ' . $key) . '</span></td>'
                . '<td><label class="screen-reader-text" for="' . esc_attr($fid . '-order') . '">' . self::h('Order of ' . $group['label']) . '</label><input type="text" class="small-text" inputmode="numeric" id="' . esc_attr($fid . '-order') . '" name="' . esc_attr($base . '[order]') . '" value="' . (int) $group['order'] . '"></td>'
                . '<td><input type="hidden" name="' . esc_attr($base . '[by_year]') . '" value="0"><label><input type="checkbox" name="' . esc_attr($base . '[by_year]') . '" value="1"' . checked(!empty($group['by_year']), true, false) . '><span class="screen-reader-text">' . self::h('Show ' . $group['label'] . ' by year') . '</span></label></td>'
                . '<td><label class="screen-reader-text" for="' . esc_attr($fid . '-intro') . '">' . self::h('Intro of ' . $group['label']) . '</label><textarea class="large-text" rows="3" id="' . esc_attr($fid . '-intro') . '" name="' . esc_attr($base . '[intro]') . '">' . esc_textarea($group['intro']) . '</textarea></td>'
                . '<td>' . (int) $n . '</td>'
                . '<td>' . ($n > 0 ? '<span class="mc-muted">Holds documents</span>' : '<label><input type="checkbox" name="' . esc_attr($base . '[remove]') . '" value="1"><span class="screen-reader-text">' . self::h('Remove ' . $group['label']) . '</span></label>') . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="mc-bar"><label for="md-new-group">Add a group</label> <input type="text" id="md-new-group" name="new_label" value="" placeholder="Name"> '
            . '<label><input type="checkbox" name="new_by_year" value="1"> Shown by year</label></p>';
        echo '<p><button type="submit" class="button button-primary">Save groups</button></p></form>';
        self::$view['anchor'] = '';
    }

    /** #managers: who can manage documents today (CAP holders), read-only, with a link to Users for administrators. */
    private static function render_managers_section() {
        $D = 'TeamOversight_Club_Documents';
        echo '<h2 id="managers">Who can manage documents</h2>';
        echo '<p class="mc-text">Administrators and anyone with the WordPress Editor role. The Committee box means these same accounts. Editor also lets them change every page and post on the site, so keep it to the people who look after documents.</p>';
        $people = $D::managers();
        if (empty($people)) {
            echo '<p class="mc-text">Nobody yet.</p>';
        } else {
            echo '<ul class="mc-lines">';
            $role_names = function_exists('wp_roles') ? wp_roles()->get_names() : array();
            foreach ($people as $uid => $name) {
                if (user_can((int) $uid, 'manage_options')) {
                    $how = 'administrator';
                } else {
                    // Named by role, so an account given the capability another way shows how it holds it.
                    $user = get_userdata((int) $uid);
                    $roles = array();
                    foreach ($user && isset($user->roles) ? (array) $user->roles : array() as $role) {
                        $roles[] = isset($role_names[$role]) ? translate_user_role($role_names[$role]) : (string) $role;
                    }
                    $how = empty($roles) ? 'given the capability directly' : implode(', ', $roles);
                }
                echo '<li>' . self::h($name) . ' <span class="mc-muted">' . self::h('(user #' . (int) $uid . ', ' . $how . ')') . '</span></li>';
            }
            echo '</ul>';
        }
        if (current_user_can('manage_options') && current_user_can('list_users')) {
            echo '<p class="mc-text">To give someone access, or take it away after an AGM, change their role on <a href="' . esc_url(admin_url('users.php')) . '">Users</a>.</p>';
        } else {
            echo '<p class="mc-text mc-muted">An administrator gives or takes away access on Users.</p>';
        }
    }

    /** #storage: the last check's result; Set up storage and Check now for administrators. */
    private static function render_storage_section() {
        $D = 'TeamOversight_Club_Documents';
        $admin = current_user_can('manage_options');
        $s = $D::storage_summary();
        self::$view['anchor'] = 'storage';
        echo '<h2 id="storage">File storage</h2>';
        echo '<p class="mc-text">Files are kept in a private folder outside the website, named in the site\'s wp-config.php. Uploads are refused until its check passes.</p>';
        $checked = !empty($s['checked_at']) ? mysql2date('j M Y g:ia', (string) $s['checked_at']) : '';
        if (!empty($s['ok'])) {
            echo '<p class="mc-text mc-good-text">' . self::h('The folder check passed (' . $checked . ').') . '</p>';
        } else {
            echo '<p class="mc-text mc-bad-text">' . self::h((string) $s['problem'] . ($checked !== '' ? ' Last checked ' . $checked . '.' : '')) . '</p>';
        }
        $web_failed = $D::web_check_failed();
        if ($web_failed !== '') {
            echo '<p class="mc-text mc-bad-text">' . self::h('The web check failed on ' . mysql2date('j M Y g:ia', $web_failed) . ': a test file in the documents folder could be fetched over the web, so the files are not private. '
                . ($admin ? 'Ask the host to fix this, and don\'t upload members-only papers until Check now passes.' : 'Ask the website maintainer.')) . '</p>';
        }
        if (!$admin) {
            echo '<p class="mc-text mc-muted">An administrator sets up and checks the storage.' . (empty($s['ok']) ? ' Ask the website maintainer.' : '') . '</p>';
            self::$view['anchor'] = '';
            return;
        }
        if (!empty($s['dir'])) {
            echo '<p class="mc-text">Folder: <code>' . self::h((string) $s['dir']) . '</code></p>';
        }
        echo '<div class="mc-bar">'
            . self::form_open('setup_storage', array('takeover' => 0), 'mc-inline mc-once') . '<button type="submit" class="button">Set up storage</button></form> '
            . self::form_open('check_now', array(), 'mc-inline mc-once') . '<button type="submit" class="button">Check now</button></form></div>';
        echo '<p class="description">Set up storage makes the folder and marks it as this site\'s; run it here in the browser, never from WP-CLI. Check now proves the folder is private and belongs to this site, then tries to fetch a test file over the web and expects to fail. Run it after any hosting change.</p>';
        self::$view['anchor'] = '';
    }

    /** #settings: the size limit, the Ways to join page and the contact email (administrators change them). */
    private static function render_settings_section() {
        $D = 'TeamOversight_Club_Documents';
        $s = $D::settings();
        $server = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        $join = (int) $s['join_page'];
        echo '<h2 id="settings">Settings</h2>';
        if (!current_user_can('manage_options')) {
            echo '<ul class="mc-lines">'
                . '<li>' . self::h('Size limit: ' . (int) $s['max_mb'] . ' MB.') . '</li>'
                . '<li>' . self::h('Ways to join page: ' . ($join > 0 ? get_the_title($join) : 'none') . '.') . '</li>'
                . '<li>' . self::h('The missing-file page gives: ' . $D::contact_email() . '.') . '</li></ul>'
                . '<p class="mc-text mc-muted">An administrator changes these.</p>';
            return;
        }
        self::$view['anchor'] = 'settings';
        echo self::form_open('settings', array(), 'mc-form mc-once');
        echo '<table class="form-table" role="presentation"><tbody>';
        self::field_row('Size limit', 'md-max-mb',
            '<input type="text" class="small-text" inputmode="numeric" id="md-max-mb" name="max_mb" value="' . (int) $s['max_mb'] . '"> MB',
            'The largest PDF accepted.' . ($server > 0 ? ' The server\'s own limit is ' . $D::mb_words($server) . ', and the smaller of the two applies.' : ''));
        $dropdown = wp_dropdown_pages(array(
            'name' => 'join_page', 'id' => 'md-join-page', 'selected' => $join, 'echo' => 0,
            'show_option_none' => 'None: no Ways to join link', 'option_none_value' => '0', 'post_status' => 'publish',
        ));
        self::field_row('Ways to join page', 'md-join-page', is_string($dropdown) && $dropdown !== '' ? $dropdown : '<input type="hidden" name="join_page" value="0">' . self::h('There are no published pages.'),
            'Where the members-only page sends a logged-in account with no current membership.');
        self::field_row('Contact email', 'md-contact',
            '<input type="email" class="regular-text" id="md-contact" name="contact_email" value="' . esc_attr($s['contact_email']) . '">',
            'The missing-file page tells people to email this address for a copy. Blank: the site\'s admin email is shown. Shown now: ' . $D::contact_email() . '.');
        echo '</tbody></table><p><button type="submit" class="button button-primary">Save settings</button></p></form>';
        self::$view['anchor'] = '';
    }

    /** #pages: the pages showing documents, the code lines, the page-refresh warning and Refresh the documents page now. */
    private static function render_pages_section() {
        $D = 'TeamOversight_Club_Documents';
        $pages = $D::pages();
        self::$view['anchor'] = 'pages';
        echo '<h2 id="pages">The documents page</h2>';
        if (empty($pages)) {
            echo '<p class="mc-text">No published page shows the documents yet. Put the first code line below in a Shortcode block on the Policies and documents page.</p>';
        } else {
            echo '<ul class="mc-lines">';
            foreach ($pages as $page_id => $page) {
                $edit = (string) get_edit_post_link((int) $page_id, 'raw');
                echo '<li><a href="' . esc_url((string) $page['url']) . '" target="_blank" rel="noopener">' . self::h((string) $page['title']) . '<span class="screen-reader-text"> (opens in a new tab)</span></a>'
                    . ($edit !== '' ? ' <a href="' . esc_url($edit) . '">Edit page</a>' : '') . '</li>';
            }
            echo '</ul>';
        }
        echo '<h3>Code lines</h3>'
            . '<p class="mc-text">The first line goes on the Policies and documents page: every group, each under its heading. Each other line shows one group without its heading, for another page, e.g. Child safety on the Junior Volleyball page.</p>'
            . '<div class="mc-scroll"><table class="widefat striped mc-code"><tbody>';
        $lines = array('[' . $D::SHORTCODE . ']' => 'Every group');
        foreach ($D::groups() as $key => $group) {
            $lines['[' . $D::SHORTCODE . ' group="' . $key . '" heading="no"]'] = $group['label'];
        }
        $i = 0;
        foreach ($lines as $code => $words) {
            $fid = 'md-code-' . (++$i);
            echo '<tr><td><div class="mc-copyrow"><label class="screen-reader-text" for="' . esc_attr($fid) . '">Code line</label><input type="text" class="mc-copy mc-code-line" id="' . esc_attr($fid) . '" value="' . esc_attr($code) . '" readonly> '
                . '<button type="button" class="button button-small" data-mc-copy="' . esc_attr($fid) . '">Copy</button></div></td><td>' . self::h($words) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (!$D::has_purge_listener()) {
            echo '<p class="mc-text mc-bad-text">' . self::h('Nothing connects the site\'s page cache to Club documents in the dashboard, where changes are made, so logged-out visitors can see an old documents page for up to ' . $D::CACHE_DAYS . ' days and public files are sent uncached. An administrator adds the page-refresh snippet, which listens for the murvc_documents_changed action, set to run everywhere (the dashboard included).') . '</p>';
        }
        echo self::form_open('refresh', array(), 'mc-bar mc-once') . '<button type="submit" class="button">Refresh the documents page now</button>'
            . '<span class="description">Use this if the page still shows an old list a few minutes after a change.</span></form>';
        self::$view['anchor'] = '';
    }

    // ------------------------------------------------------------------
    // Styles and the script
    // ------------------------------------------------------------------

    private static function styles() {
        ?>
        <style>
            .murvc-docs-admin [hidden] { display: none !important; }
            .murvc-docs-admin .mc-text, .murvc-docs-admin .mc-box, .murvc-docs-admin .mc-form, .murvc-docs-admin p.description { max-width: 900px; }
            .murvc-docs-admin .mc-scroll { overflow-x: auto; }
            .murvc-docs-admin table.widefat td, .murvc-docs-admin table.widefat th { vertical-align: top; }
            .murvc-docs-admin .mc-box { background: #fff; border: 1px solid #c3c4c7; padding: 10px 14px; margin: 14px 0; box-sizing: border-box; }
            .murvc-docs-admin .mc-box h2 { margin: 4px 0 8px; font-size: 1.15em; }
            .murvc-docs-admin .mc-warn-box { border-left: 4px solid #dba617; }
            .murvc-docs-admin details.mc-box > summary { cursor: pointer; font-weight: 600; }
            .murvc-docs-admin .mc-howto h3 { margin: 16px 0 4px; font-size: 1em; }
            .murvc-docs-admin .mc-heading { margin: 22px 0 6px; }
            .murvc-docs-admin .mc-pill-cell { width: 62px; }
            .murvc-docs-admin .mc-pill { display: inline-block; min-width: 46px; text-align: center; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; }
            .murvc-docs-admin .mc-pill-warn { background: #8a6100; }
            .murvc-docs-admin .mc-pill-bad { background: #d63638; }
            .murvc-docs-admin .mc-muted { color: #50575e; }
            .murvc-docs-admin .mc-bad-text { color: #b32d2e; }
            .murvc-docs-admin .mc-good-text { color: #007017; }
            .murvc-docs-admin .mc-nowrap { white-space: nowrap; }
            .murvc-docs-admin .mc-lines { list-style: disc; margin: 4px 0 8px 20px; }
            .murvc-docs-admin .mc-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0; }
            .murvc-docs-admin form.mc-inline { display: inline; margin: 0; }
            .murvc-docs-admin .mc-list .column-primary { min-width: 180px; }
            .murvc-docs-admin tr:focus-within .row-actions { position: static; left: auto; }
            .murvc-docs-admin .mc-danger, .murvc-docs-admin .button-link.mc-danger { color: #b32d2e; }
            .murvc-docs-admin .button.mc-danger-button { background: #b32d2e; border-color: #b32d2e; color: #fff; }
            .murvc-docs-admin .mc-hint { display: block; margin-top: 4px; }
            .murvc-docs-admin .mc-count { display: block; margin-top: 2px; font-size: 12px; color: #50575e; }
            .murvc-docs-admin .mc-count.mc-over { color: #b32d2e; font-weight: 600; }
            .murvc-docs-admin .mc-status { margin: 14px 0 2px; font-weight: 600; }
            .murvc-docs-admin .mc-actions .button { margin: 0 6px 6px 0; }
            .murvc-docs-admin .mc-copyrow { display: flex; gap: 6px; align-items: center; }
            .murvc-docs-admin input.mc-copy { width: 100%; max-width: 420px; min-width: 12em; }
            .murvc-docs-admin input.mc-code-line { width: 30em; font-family: Consolas, Monaco, monospace; }
            .murvc-docs-admin .mc-prefix { color: #50575e; margin-right: 2px; }
            .murvc-docs-admin .mc-arrow-gap { display: inline-block; width: 30px; }
            .murvc-docs-admin .mc-list form.mc-inline .button { min-width: 30px; }
            .murvc-docs-admin .mc-versions tr.mc-current td { background: #f0f6fc; }
            .murvc-docs-admin details.mc-more { margin: 4px 0; }
            .murvc-docs-admin details.mc-more summary { cursor: pointer; color: #2271b1; }
            .murvc-docs-admin .md-boxes { max-width: 640px; }
            .murvc-docs-admin .md-rule78 { margin: 0 0 10px; padding: 6px 10px; background: #f0f6fc; border-left: 4px solid #2271b1; }
            .murvc-docs-admin .md-box { margin: 0 0 8px; }
            .murvc-docs-admin .md-box label { font-weight: 600; }
            .murvc-docs-admin .md-box-help { display: block; margin: 2px 0 0 24px; }
            .murvc-docs-admin .md-box.md-grey label, .murvc-docs-admin .md-box.md-grey .md-box-help { opacity: .55; }
            .murvc-docs-admin .md-box.md-grey input { cursor: not-allowed; }
            .murvc-docs-admin .md-sentence { margin-top: 10px; padding: 8px 10px; background: #f6f7f7; border: 1px solid #dcdcde; }
            .murvc-docs-admin .md-sentence p { margin: 2px 0; }
            .murvc-docs-admin .md-sentence p:first-child { font-weight: 600; }
            .murvc-docs-admin .mc-groups textarea { min-width: 220px; }
            @media (max-width: 782px) {
                .murvc-docs-admin .mc-copyrow { flex-wrap: wrap; }
                .murvc-docs-admin input.mc-code-line { width: 100%; }
            }
        </style>
        <?php
    }

    /**
     * The script, for every Club documents screen: a confirm box before a
     * form marked data-mc-confirm; one send per form marked mc-once; the
     * Copy buttons; the description counter; and the boxes' live sentence
     * and greying. Values, attributes and textContent only.
     */
    private static function script() {
        ?>
        <script>
            (function () {
                function byId(id) { return document.getElementById(id); }
                function chars(s) { return Array.from ? Array.from(s).length : s.length; }

                // A confirm box first, where a form asks for one; then a form marked mc-once is sent once: its
                // buttons are disabled straight after the submit (in a timeout, so the pressed button's value is posted).
                document.addEventListener('submit', function (e) {
                    var form = e.target;
                    if (!form || !form.getAttribute) { return; }
                    var ask = form.getAttribute('data-mc-confirm');
                    if (ask && !window.confirm(ask)) { e.preventDefault(); return; }
                    if (e.defaultPrevented || !form.classList.contains('mc-once')) { return; }
                    var buttons = form.querySelectorAll('button[type=submit]');
                    setTimeout(function () { for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = true; } }, 0);
                });

                Array.prototype.forEach.call(document.querySelectorAll('.murvc-docs-admin [data-mc-copy]'), function (button) {
                    button.addEventListener('click', function () {
                        var input = byId(button.getAttribute('data-mc-copy'));
                        if (!input) { return; }
                        var done = function () {
                            button.textContent = 'Copied';
                            setTimeout(function () { button.textContent = 'Copy'; }, 1500);
                        };
                        var pick = function () { input.focus(); input.select(); };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(input.value).then(done, pick);
                        } else {
                            pick();
                        }
                    });
                });

                var desc = byId('md-description'), count = byId('md-description-count');
                if (desc && count) {
                    var max = parseInt(desc.getAttribute('data-max'), 10) || 0;
                    var showCount = function () {
                        var n = chars(desc.value);
                        count.textContent = n + ' / ' + max;
                        count.classList.toggle('mc-over', max > 0 && n > max);
                    };
                    desc.addEventListener('input', showCount);
                    showCount();
                }

                // The boxes. The two lines for every set of ticks come from the server (data-sentences, keyed by
                // the ticked keys in the order the boxes are drawn); this only picks one. While a box marked
                // data-public is ticked, the others are greyed with aria-disabled (never disabled, so their
                // ticks are still posted and kept) and clicks on them do nothing.
                var box = byId('md-boxes');
                if (box) {
                    var table = {};
                    try { table = JSON.parse(box.getAttribute('data-sentences') || '{}') || {}; } catch (err) { table = {}; }
                    var inputs = box.querySelectorAll('input[type=checkbox]');
                    var who = byId('md-who'), page = byId('md-page'), note = byId('md-greyed');
                    var update = function () {
                        var on = [], open = false;
                        Array.prototype.forEach.call(inputs, function (i) {
                            if (i.checked) {
                                on.push(i.value);
                                if (i.hasAttribute('data-public')) { open = true; }
                            }
                        });
                        Array.prototype.forEach.call(inputs, function (i) {
                            if (i.hasAttribute('data-public')) { return; }
                            var wrap = i.closest ? i.closest('.md-box') : null;
                            if (open) { i.setAttribute('aria-disabled', 'true'); } else { i.removeAttribute('aria-disabled'); }
                            if (wrap) { wrap.classList.toggle('md-grey', open); }
                        });
                        if (note) { note.hidden = !open; }
                        var lines = table[on.join(',')];
                        if (lines && who && page) {
                            who.textContent = String(lines[0]);
                            page.textContent = String(lines[1]);
                            page.hidden = String(lines[1]) === '';
                        }
                    };
                    box.addEventListener('click', function (e) {
                        var t = e.target, input = null;
                        if (t && t.matches && t.matches('input[type=checkbox]')) {
                            input = t;
                        } else if (t && t.closest && t.closest('label')) {
                            input = t.closest('label').querySelector('input[type=checkbox]');
                        }
                        if (input && input.getAttribute('aria-disabled') === 'true') { e.preventDefault(); }
                    });
                    box.addEventListener('change', update);
                    update();
                }
            })();
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private static function h($text) {
        return esc_html((string) $text);
    }

    /** Who made a change or an upload, as plain text (a display name decoded once; the caller escapes once). */
    private static function who($user_id, $none = 'No signed-in account') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return $none;
        }
        $user = get_userdata($user_id);
        return $user ? wp_specialchars_decode((string) $user->display_name, ENT_QUOTES) : 'an account that no longer exists';
    }

    /** A JSON column (or an already decoded value) as an array; anything else is array(). */
    private static function decode($value) {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return array();
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function plural($n, $one, $many) {
        return (int) $n . ' ' . ((int) $n === 1 ? $one : $many);
    }

    /** A file size as the page shows it (the public class's one rule): "240 KB", "2.4 MB". */
    private static function size_words($bytes) {
        return TeamOversight_Club_Documents_Public::size_words($bytes);
    }

    /** A posted id ('0' allowed) checked with /^\d{1,10}$/D, or null. */
    private static function posted_id(array $data, $key) {
        if (!isset($data[$key]) || !is_scalar($data[$key])) {
            return null;
        }
        $raw = (string) $data[$key];
        return preg_match('/^\d{1,10}$/D', $raw) === 1 ? (int) $raw : null;
    }

    private static function current_page() {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return in_array($page, self::PAGES, true) ? $page : '';
    }

    private static function get_id($key) {
        $raw = isset($_GET[$key]) && is_string($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
        return preg_match('/^\d{1,10}$/D', $raw) === 1 ? (int) $raw : 0;
    }

    private static function get_flag($key) {
        return isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] === '1';
    }

    private static function page_url($slug, array $args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . $slug));
    }

    private static function edit_url($id) {
        return self::page_url(TeamOversight_Club_Documents::PAGE_EDIT, array('document' => (int) $id));
    }
}
