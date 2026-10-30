<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coaches (1.81.0): the committee's screens for coach profiles, under their
 * own top-level menu (position 33, after Club Programs) and their own
 * capability, TeamOversight_Coaches::CAP, which administrators hold through
 * a filter and the Coach bios editor role holds directly.
 *
 * Screens: Profiles (club-coaches: To do, the list, How to, glossary), Add
 * New / Edit (club-coaches-edit) and Programs and settings
 * (club-coaches-settings: #programs, and #editors for administrators).
 * This season (club-coaches-season), the editor's Teams box, Linked
 * account, card preview and Appears on arrive with the public pages
 * (1.82.0) and team coaching (1.83.0).
 *
 * Rules (design section 8):
 *  - ONE admin-post action, murvc_coaches, with the button's verb in do.
 *    Each button has its own nonce (nonce_action()); CAP is checked before
 *    anything runs, and the Editors box also needs manage_options and
 *    promote_users. Post/Redirect/Get with a flash summary.
 *  - Every change goes through a TeamOversight_Coaches writer, which
 *    validates, compare-and-sets, records the history and logs. This class
 *    writes no Coaches table or option (only the flash and draft
 *    transients), runs no SQL, and a page render writes nothing:
 *    load_page() takes the one-shot flash and draft before the page is
 *    drawn.
 *  - Each editor save posts the version it was drawn with; a stale save
 *    stores nothing and keeps what was typed, so the editor refills once.
 *  - Display names are member-controlled text: decoded once and escaped
 *    once, never put into a script. Profile text is the committee's, escaped
 *    on the page. The scripts set values and textContent only.
 */
class TeamOversight_Coaches_Admin {

    const PAGE_PROFILES = 'club-coaches';
    const PAGE_EDIT = 'club-coaches-edit';
    const PAGE_SETTINGS = 'club-coaches-settings';
    /** The screens a button may send someone back to (1.83.0 adds club-coaches-season). */
    const PAGES = array(self::PAGE_PROFILES, self::PAGE_EDIT, self::PAGE_SETTINGS);
    const ACTION = 'murvc_coaches';
    /** The buttons of 1.81.0 (the posted do). */
    const DO_ACTIONS = array('save', 'hide', 'bulk', 'programs', 'editor_add', 'editor_remove', 'editor_readd', 'editor_lost_ok');
    /** The Editors box: administrators only (manage_options and promote_users). */
    const EDITOR_ACTIONS = array('editor_add', 'editor_remove', 'editor_readd', 'editor_lost_ok');
    /** The buttons that act on one account (posted as user). */
    const USER_ACTIONS = array('editor_remove', 'editor_readd', 'editor_lost_ok');
    /** filter => link label above the list ('' = All). */
    const FILTERS = array('' => 'All', 'shown' => 'Shown', 'hidden' => 'Hidden', 'check' => 'Needs checking', 'consent' => 'No consent', 'photo' => 'Photo waiting');
    /** The Programs and settings sections a button may return to. */
    const ANCHORS = array('programs', 'editors');
    /** The To do panel shows at most this many rows. */
    const TODO_MAX = 20;

    /** profile column => the name History gives it. */
    const FIELD_LABELS = array(
        'public_name' => 'Public name',
        'intro' => 'One-line intro',
        'bio' => 'Short bio',
        'accreditation' => 'Accreditation',
        'accreditation_other' => 'Accreditation (Other)',
        'highlights' => 'Highlights',
        'playing_background' => 'Playing background',
        'program_roles' => 'What they coach',
        'display_mode' => 'Show as',
        'photo_id' => 'Photo',
        'photo_cleared_id' => 'Photo cleared',
        'consent_date' => 'Date they answered',
        'consent_method' => 'How they agreed',
        'consent_scope' => 'What they agreed to',
        'is_minor' => 'Under 18',
        'guardian_date' => 'Date a parent or guardian agreed',
        'status' => 'Website',
        'user_id' => 'Linked account',
    );

    /** slug => the hook suffix WordPress returned for it (never hard-coded). */
    private static $hooks = array();

    /** Per-button <form>s, printed after the table they belong to (a button points at its form with form="", so forms never nest). */
    private static $forms = array();

    /** What the page is showing; every form posts it back as return_*. */
    private static $view = array('page' => self::PAGE_PROFILES, 'profile' => 0, 'filter' => '', 's' => '', 'anchor' => '');

    /** The flash and the drafts, each taken once per request. */
    private static $flash = null;
    private static $flash_taken = false;
    private static $drafts = array();

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        if (is_admin()) {
            // After TeamOversight_Admin::add_admin_menu() (priority 10) has made Club Programs (position 32).
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 20);
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
            add_action('load-index.php', array(__CLASS__, 'dashboard_redirect'));
            add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 10, 2);
        }
    }

    // ------------------------------------------------------------------
    // The menu
    // ------------------------------------------------------------------

    /**
     * Coaches, a top-level menu under TeamOversight_Coaches::CAP: Profiles
     * (repeating the parent slug, as Club Membership does), Add New and
     * Programs and settings. Each hook suffix WordPress returns is kept for
     * enqueue() and gets load_page().
     */
    public static function add_menu() {
        $cap = TeamOversight_Coaches::CAP;
        add_menu_page('Coaches', 'Coaches', $cap, self::PAGE_PROFILES, array(__CLASS__, 'render_profiles_page'), 'dashicons-businessperson', 33);
        $suffixes = array(
            self::PAGE_PROFILES => add_submenu_page(self::PAGE_PROFILES, 'Coaches', 'Profiles', $cap, self::PAGE_PROFILES, array(__CLASS__, 'render_profiles_page')),
            self::PAGE_EDIT => add_submenu_page(self::PAGE_PROFILES, 'Coach profile', 'Add New', $cap, self::PAGE_EDIT, array(__CLASS__, 'render_edit_page')),
            self::PAGE_SETTINGS => add_submenu_page(self::PAGE_PROFILES, 'Coaches: programs and settings', 'Programs and settings', $cap, self::PAGE_SETTINGS, array(__CLASS__, 'render_settings_page')),
        );
        foreach ($suffixes as $slug => $suffix) {
            if (is_string($suffix) && $suffix !== '') {
                self::$hooks[$slug] = $suffix;
                add_action('load-' . $suffix, array(__CLASS__, 'load_page'));
            }
        }
    }

    /** Keep Profiles highlighted in the menu while an existing profile is open (Add New stays for a new one). */
    public static function submenu_file($submenu_file, $parent_file = '') {
        if (self::current_page() === self::PAGE_EDIT && self::get_id('profile') > 0) {
            return self::PAGE_PROFILES;
        }
        return $submenu_file;
    }

    /** The media picker, on the profile editor only. Nothing else is enqueued (the VVL pages' admin.css and admin.js never load here). */
    public static function enqueue($hook) {
        if (isset(self::$hooks[self::PAGE_EDIT]) && $hook === self::$hooks[self::PAGE_EDIT]) {
            wp_enqueue_media();
        }
    }

    /** The Dashboard sends a Coach bios editor with no other staff access straight to Coaches. Other staff are never redirected. */
    public static function dashboard_redirect() {
        if (!current_user_can(TeamOversight_Coaches::CAP) || current_user_can('manage_options') || current_user_can('edit_posts')) {
            return;
        }
        wp_safe_redirect(self::page_url(self::PAGE_PROFILES));
        exit;
    }

    /**
     * load-<page> for every Coaches screen, before anything is printed: take
     * the flash and (on the editor) the draft, so the render itself writes
     * nothing. 1.83.0 runs the daily check here too.
     */
    public static function load_page() {
        self::take_flash();
        if (self::current_page() === self::PAGE_EDIT) {
            self::take_draft(self::get_id('profile'));
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

    /** A writer's result with every documented key present. */
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
        return self::result($ok, $message, $lines, $type, isset($r['id']) ? intval($r['id']) : $id);
    }

    /** The summary the last button left for this person (shown once), or null. */
    public static function take_flash() {
        if (!self::$flash_taken) {
            self::$flash_taken = true;
            $key = TeamOversight_Coaches::FLASH_PREFIX . get_current_user_id();
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
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . self::h(isset($flash['message']) ? $flash['message'] : '') . '</p>';
        if (!empty($flash['lines']) && is_array($flash['lines'])) {
            echo '<ul class="mc-lines">';
            foreach ($flash['lines'] as $line) {
                if (is_scalar($line)) {
                    echo '<li>' . self::h($line) . '</li>';
                }
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** What this person typed into a profile whose save stored nothing (shown once), or null. */
    private static function take_draft($id) {
        $id = (int) $id;
        if (!array_key_exists($id, self::$drafts)) {
            $key = TeamOversight_Coaches::DRAFT_PREFIX . get_current_user_id() . '_' . $id;
            $draft = get_transient($key);
            if (is_array($draft)) {
                delete_transient($key);
            }
            self::$drafts[$id] = is_array($draft) ? $draft : null;
        }
        return self::$drafts[$id];
    }

    private static function keep_draft($actor, $id, array $input) {
        set_transient(TeamOversight_Coaches::DRAFT_PREFIX . (int) $actor . '_' . (int) $id, $input, 1800);
    }

    // ------------------------------------------------------------------
    // Per-button forms
    // ------------------------------------------------------------------

    /** The posted field that names what a button acts on ('' = nothing: the button's nonce is its own). */
    private static function id_field($do) {
        if (in_array($do, self::USER_ACTIONS, true)) {
            return 'user';
        }
        return ($do === 'save' || $do === 'hide') ? 'id' : '';
    }

    /** The nonce action of one button (section 8.3), from a validated id. '' = not a button. */
    private static function nonce_for($do, $id) {
        $id = (int) $id;
        switch ($do) {
            case 'bulk':
                return 'murvc_coaches_bulk';
            case 'programs':
            case 'editor_add':
                return 'murvc_coaches_' . $do . '_0';
            case 'save':
                // 0 is a new profile.
                return $id >= 0 ? 'murvc_coaches_save_' . $id : '';
            case 'hide':
            case 'editor_remove':
            case 'editor_readd':
            case 'editor_lost_ok':
                return $id > 0 ? 'murvc_coaches_' . $do . '_' . $id : '';
        }
        return '';
    }

    /** The nonce action a posted button must carry, built from validated ids only ('' for an unknown do or a bad id). */
    public static function nonce_action($do, array $data) {
        if (!is_string($do) || !in_array($do, self::DO_ACTIONS, true)) {
            return '';
        }
        $field = self::id_field($do);
        if ($field === '') {
            return self::nonce_for($do, 0);
        }
        $id = self::posted_id($data, $field);
        return $id === null ? '' : self::nonce_for($do, $id);
    }

    /** The hidden fields that bring the person back to the same screen after a button. */
    private static function return_fields() {
        $v = self::$view;
        return '<input type="hidden" name="return_page" value="' . esc_attr($v['page']) . '">'
            . '<input type="hidden" name="return_profile" value="' . intval($v['profile']) . '">'
            . '<input type="hidden" name="return_filter" value="' . esc_attr($v['filter']) . '">'
            . '<input type="hidden" name="return_s" value="' . esc_attr($v['s']) . '">'
            . '<input type="hidden" name="return_anchor" value="' . esc_attr($v['anchor']) . '">';
    }

    /** action, do, the target id, the button's nonce and the return fields. */
    private static function hidden_fields($do, $id = 0) {
        $field = self::id_field($do);
        return '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">'
            . ($field !== '' ? '<input type="hidden" name="' . esc_attr($field) . '" value="' . intval($id) . '">' : '')
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::nonce_for($do, $id))) . '">'
            . self::return_fields();
    }

    /**
     * Queue one single-button form (printed by flush_forms() after the
     * table) and return its id for the button's form="" attribute.
     * $hidden: name => value. $confirm: static text for a confirm box (never a name).
     */
    private static function row_form($do, $id, array $hidden = array(), $confirm = '') {
        $fid = 'mc-f-' . $do . '-' . intval($id) . '-' . count(self::$forms);
        $html = '<form id="' . esc_attr($fid) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '') . '>'
            . self::hidden_fields($do, $id);
        foreach ($hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        self::$forms[] = $html . '</form>';
        return $fid;
    }

    private static function flush_forms() {
        if (empty(self::$forms)) {
            return;
        }
        echo '<div class="mc-forms">' . implode('', self::$forms) . '</div>';
        self::$forms = array();
    }

    private static function button($label, $fid, $class = 'button', $disabled = false, $title = '') {
        return '<button type="submit" form="' . esc_attr($fid) . '" class="' . esc_attr($class) . '"' . ($disabled ? ' disabled' : '') . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '') . '>' . esc_html($label) . '</button>';
    }

    // ------------------------------------------------------------------
    // The handler
    // ------------------------------------------------------------------

    /**
     * admin-post murvc_coaches: every Coaches button. Checks CAP, POST only,
     * then the button's own nonce, runs it (process()), leaves the flash and
     * redirects back. There is no handler for logged-out visitors.
     */
    public static function handle() {
        if (!current_user_can(TeamOversight_Coaches::CAP)) {
            wp_die('You do not have permission to do that.', '', array('response' => 403));
        }
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
            wp_die('Use the buttons on the Coaches screens.', '', array('response' => 405));
        }
        $data = wp_unslash($_POST);
        $do = isset($data['do']) && is_string($data['do']) ? sanitize_key($data['do']) : '';
        $nonce_action = self::nonce_action($do, $data);
        $nonce = isset($data['_wpnonce']) && is_string($data['_wpnonce']) ? $data['_wpnonce'] : '';
        if ($nonce_action === '' || $nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) {
            wp_die('This page has expired. Go back, reload it and try again.', '', array('response' => 403));
        }
        $result = self::process($do, $data, get_current_user_id());
        set_transient(TeamOversight_Coaches::FLASH_PREFIX . get_current_user_id(), $result, 300);
        wp_safe_redirect(self::return_url($data, $result));
        exit;
    }

    /**
     * Run one button. $data: the unslashed POST. No nonce here (handle()
     * checked it); the actor must hold CAP, and manage_options plus
     * promote_users for the Editors box. Returns array(ok, type
     * (success|warning|error), message, lines, id).
     */
    public static function process($do, array $data, $actor) {
        $actor = (int) $actor;
        $do = is_string($do) ? sanitize_key($do) : '';
        if (!in_array($do, self::DO_ACTIONS, true)) {
            return self::result(false, 'That button is not recognised: reload the page.');
        }
        if ($actor <= 0 || !user_can($actor, TeamOversight_Coaches::CAP)) {
            return self::result(false, 'You do not have permission to do that.');
        }
        if (in_array($do, self::EDITOR_ACTIONS, true) && !self::can_manage_editors($actor)) {
            return self::result(false, 'Only an administrator can give or remove the Coach bios editor role.');
        }
        switch ($do) {
            case 'save':
                return self::do_save($data, $actor);
            case 'hide':
                $id = self::posted_id($data, 'id');
                if (!$id) {
                    return self::result(false, 'No profile was given: reload the page.');
                }
                $source = isset($data['return_page']) && $data['return_page'] === self::PAGE_EDIT ? 'editor' : 'list';
                return self::normalise(TeamOversight_Coaches::set_status($id, 'draft', $actor, $source), $id);
            case 'bulk':
                return self::do_bulk($data, $actor);
            case 'programs':
                return self::do_programs($data, $actor);
        }
        return self::do_editor($do, $data, $actor);
    }

    /**
     * Save (section 8.3): clean the fields, create or update (the update is
     * held to the posted version), then Publish when asked and the profile
     * was a draft. Whenever nothing was stored, what was typed is kept for
     * the editor to refill once.
     */
    private static function do_save(array $data, $actor) {
        $id = self::posted_id($data, 'id');
        if ($id === null) {
            return self::result(false, 'No profile was given: reload the page.');
        }
        $before = null;
        if ($id > 0) {
            $before = TeamOversight_Coaches::get_profile($id);
            if (!$before) {
                return self::result(false, 'That profile no longer exists: reload the page.');
            }
        }
        $intent = isset($data['intent']) && $data['intent'] === 'publish' ? 'publish' : 'draft';
        $input = self::posted_fields($data);

        $clean = TeamOversight_Coaches::clean_fields($input);
        $fields = is_array($clean) && isset($clean[0]) && is_array($clean[0]) ? $clean[0] : array();
        $errors = is_array($clean) && isset($clean[1]) && is_array($clean[1]) ? array_values($clean[1]) : array();
        // A newly chosen photo must be an image in the Media Library. The saved one is let through even if
        // its file has gone since (the card shows initials and To do says so), so other fixes still save.
        $photo = (int) $input['photo_id'];
        if ($photo > 0 && $photo !== ($before ? (int) $before->photo_id : 0) && !wp_attachment_is_image($photo)) {
            $errors[] = 'The chosen photo is not an image in the Media Library: choose it again, or remove it.';
        }
        if (!empty($errors)) {
            self::keep_draft($actor, $id, $input);
            return self::result(false, 'Nothing was saved. What you typed is still in the form: fix ' . (count($errors) === 1 ? 'this' : 'these') . ' and save again.', $errors, 'error', $id);
        }

        // The writers word the save (Saved as a draft. / Profile updated. / Saved and hidden from the
        // website: ...) and add the same-name and old-photo lines; a stale update keeps the draft itself.
        if ($id === 0) {
            $r = self::normalise(TeamOversight_Coaches::create_profile($fields, 0, $actor));
            if (!$r['ok'] || $r['id'] <= 0) {
                self::keep_draft($actor, 0, $input);
                return self::result(false, $r['message'], $r['lines'], 'error');
            }
            $id = $r['id'];
        } else {
            $version = self::posted_id($data, 'version');
            $version = $version === null ? 0 : $version;
            $r = self::normalise(TeamOversight_Coaches::update_profile($id, $fields, $version, $actor), $id);
            if (!$r['ok']) {
                $now = TeamOversight_Coaches::get_profile($id);
                if (!$now || (int) $now->version === $version) {
                    self::keep_draft($actor, $id, $input);
                }
                return self::result(false, $r['message'], $r['lines'], 'error', $id);
            }
        }
        if (($before && $before->status !== 'draft') || $intent !== 'publish') {
            return $r;
        }
        $p = self::normalise(TeamOversight_Coaches::set_status($id, 'published', $actor, 'editor'), $id);
        if ($p['ok']) {
            $lines = array_merge($r['lines'], $p['lines']);
            return self::result(true, 'Profile published. Pages showing it refresh within a few minutes.', $lines, empty($lines) ? 'success' : 'warning', $id);
        }
        // set_status() refuses with publish_problem()'s bare sentence.
        return self::result(true, 'Saved as a draft, not published: ' . $p['message'], $r['lines'], 'warning', $id);
    }

    /**
     * The editor's fields as posted, keyed by profile column, for
     * clean_fields() and for the draft: free text through the sanitisers
     * (never clipped), keys through sanitize_key(), highlights a list with
     * blanks dropped (at most HIGHLIGHTS), program_roles key => role (blank
     * left out), photo_id and the Photo cleared box's photo_cleared.
     */
    private static function posted_fields(array $data) {
        $text = function ($name) use ($data) {
            return isset($data[$name]) && is_scalar($data[$name]) ? sanitize_text_field((string) $data[$name]) : '';
        };
        $key = function ($name) use ($data) {
            return isset($data[$name]) && is_string($data[$name]) ? sanitize_key($data[$name]) : '';
        };
        $highlights = array();
        if (isset($data['highlights']) && is_array($data['highlights'])) {
            foreach ($data['highlights'] as $line) {
                $line = is_scalar($line) ? sanitize_text_field((string) $line) : '';
                if ($line !== '') {
                    $highlights[] = $line;
                }
            }
        }
        $roles = array();
        if (isset($data['program_roles']) && is_array($data['program_roles'])) {
            foreach ($data['program_roles'] as $program => $role) {
                $program = (string) $program;
                if (preg_match('/^[a-z0-9_]{1,30}$/D', $program) === 1 && is_string($role) && $role !== '') {
                    $roles[$program] = sanitize_key($role);
                }
            }
        }
        $photo = self::posted_id($data, 'photo_id');
        $photo = $photo === null ? 0 : $photo;
        $cleared = self::posted_id($data, 'photo_cleared');
        $cleared = $cleared === null ? 0 : $cleared;
        return array(
            'public_name' => $text('public_name'),
            'intro' => $text('intro'),
            'bio' => isset($data['bio']) && is_string($data['bio']) ? sanitize_textarea_field($data['bio']) : '',
            'accreditation' => $key('accreditation'),
            'accreditation_other' => $text('accreditation_other'),
            'highlights' => array_slice($highlights, 0, TeamOversight_Coaches::HIGHLIGHTS),
            'playing_background' => $text('playing_background'),
            'program_roles' => $roles,
            'display_mode' => $key('display_mode'),
            'photo_id' => $photo,
            // The photo id the box was drawn for: clean_fields() clears the photo only when it equals photo_id (8.5).
            'photo_cleared' => $cleared,
            'consent_date' => $text('consent_date'),
            'consent_method' => $key('consent_method'),
            'consent_scope' => $key('consent_scope'),
            'is_minor' => !empty($data['is_minor']) ? 1 : 0,
            'guardian_date' => $text('guardian_date'),
        );
    }

    /** Bulk (Hide from website, Still correct) over the ticked rows, at most BULK_MAX. */
    private static function do_bulk(array $data, $actor) {
        $act = isset($data['bulk_action']) && is_string($data['bulk_action']) ? sanitize_key($data['bulk_action']) : '';
        if (!in_array($act, array('hide', 'checked'), true)) {
            return self::result(false, 'Choose Hide from website or Still correct, then press Apply.');
        }
        $ids = self::id_list(isset($data['ids']) ? $data['ids'] : array());
        if (empty($ids)) {
            return self::result(false, 'Tick at least one profile first.');
        }
        if (count($ids) > TeamOversight_Coaches::BULK_MAX) {
            return self::result(false, 'At most ' . TeamOversight_Coaches::BULK_MAX . ' profiles at once: tick fewer and try again.');
        }
        if ($act === 'checked') {
            return self::normalise(TeamOversight_Coaches::mark_checked($ids, $actor));
        }
        $hidden = 0;
        $lines = array();
        foreach ($ids as $id) {
            $r = self::normalise(TeamOversight_Coaches::set_status($id, 'draft', $actor, 'bulk'), $id);
            if ($r['ok']) {
                $hidden++;
            } else {
                $lines[] = 'Profile #' . $id . ': ' . $r['message'];
            }
        }
        // Hiding a profile that was already hidden changes nothing and still counts: it is hidden now.
        $type = empty($lines) ? 'success' : ($hidden > 0 ? 'warning' : 'error');
        return self::result($hidden > 0, self::plural($hidden, 'profile is', 'profiles are') . ' now hidden from the website.', $lines, $type);
    }

    /**
     * Save programs. save_programs() gets the posted rows keyed by program
     * key (label, accent, filter 0|1, order, remove 0|1) and, when a name was
     * typed under Add a program, the new one under the key '' (never a real
     * key: the writer makes its key from the label).
     */
    private static function do_programs(array $data, $actor) {
        $rows = array();
        $last = 0;
        if (isset($data['rows']) && is_array($data['rows'])) {
            foreach ($data['rows'] as $key => $row) {
                $key = (string) $key;
                if (preg_match('/^[a-z0-9_]{1,30}$/D', $key) !== 1 || !is_array($row)) {
                    continue;
                }
                $order = isset($row['order']) && is_scalar($row['order']) && is_numeric(trim((string) $row['order'])) ? (int) $row['order'] : -1;
                $rows[$key] = array(
                    'label' => isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field((string) $row['label']) : '',
                    'accent' => isset($row['accent']) && is_string($row['accent']) ? sanitize_key($row['accent']) : '',
                    'filter' => !empty($row['filter']) ? 1 : 0,
                    'order' => $order,
                    'remove' => !empty($row['remove']) ? 1 : 0,
                );
                $last = max($last, $order);
            }
        }
        $new = isset($data['new_label']) && is_scalar($data['new_label']) ? sanitize_text_field((string) $data['new_label']) : '';
        if ($new !== '') {
            $rows[''] = array(
                'label' => $new,
                'accent' => isset($data['new_accent']) && is_string($data['new_accent']) ? sanitize_key($data['new_accent']) : '',
                'filter' => 1,
                'order' => min(999, $last + 10),
                'remove' => 0,
            );
        }
        if (empty($rows)) {
            return self::result(false, 'No programs came with the button: reload the page.');
        }
        $hash = isset($data['shown_hash']) && is_string($data['shown_hash']) ? sanitize_key($data['shown_hash']) : '';
        return self::normalise(TeamOversight_Coaches::save_programs($rows, $hash, $actor));
    }

    /** The Editors box's buttons (process() has checked manage_options and promote_users). */
    private static function do_editor($do, array $data, $actor) {
        if ($do === 'editor_add') {
            $who = isset($data['who']) && is_scalar($data['who']) ? trim(sanitize_text_field((string) $data['who'])) : '';
            if ($who === '') {
                return self::result(false, 'Type their email address or login, then press Add.');
            }
            return self::normalise(TeamOversight_Coaches::grant_editor($who, $actor));
        }
        $user_id = self::posted_id($data, 'user');
        if (!$user_id) {
            return self::result(false, 'No account came with the button: reload the page.');
        }
        if ($do === 'editor_remove') {
            return self::normalise(TeamOversight_Coaches::revoke_editor($user_id, $actor));
        }
        if ($do === 'editor_readd') {
            return self::normalise(TeamOversight_Coaches::grant_editor_by_id($user_id, $actor));
        }
        return self::normalise(TeamOversight_Coaches::leave_editor_off($user_id, $actor));
    }

    /**
     * Where to send the person after a button: the screen they were on, every
     * return_* re-validated. A newly created profile opens in its own editor.
     */
    private static function return_url(array $data, array $result) {
        $page = isset($data['return_page']) && is_string($data['return_page']) && in_array($data['return_page'], self::PAGES, true) ? $data['return_page'] : self::PAGE_PROFILES;
        $args = array();
        if ($page === self::PAGE_EDIT) {
            $profile = self::posted_id($data, 'return_profile');
            if (isset($data['do']) && $data['do'] === 'save' && !empty($result['id'])) {
                $profile = (int) $result['id'];
            }
            if ($profile) {
                $args['profile'] = $profile;
            }
        } elseif ($page === self::PAGE_PROFILES) {
            $filter = isset($data['return_filter']) && is_string($data['return_filter']) ? $data['return_filter'] : '';
            if ($filter !== '' && isset(self::FILTERS[$filter])) {
                $args['filter'] = $filter;
            }
            $s = isset($data['return_s']) && is_scalar($data['return_s']) ? sanitize_text_field((string) $data['return_s']) : '';
            if ($s !== '') {
                $args['s'] = rawurlencode($s);
            }
        }
        $url = self::page_url($page, $args);
        $anchor = isset($data['return_anchor']) && is_string($data['return_anchor']) && in_array($data['return_anchor'], self::ANCHORS, true) ? $data['return_anchor'] : '';
        return $anchor !== '' ? $url . '#' . $anchor : $url;
    }

    // ------------------------------------------------------------------
    // Profiles (club-coaches, the landing page)
    // ------------------------------------------------------------------

    public static function render_profiles_page() {
        if (!current_user_can(TeamOversight_Coaches::CAP)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        self::$forms = array();
        $filter = self::get_filter();
        $search = self::get_search();
        self::$view = array('page' => self::PAGE_PROFILES, 'profile' => 0, 'filter' => $filter, 's' => $search, 'anchor' => '');
        $season = self::focus_season();

        echo '<div class="wrap murvc-coaches-admin">';
        self::styles();
        echo '<h1 class="wp-heading-inline">Coaches</h1> <a href="' . esc_url(self::page_url(self::PAGE_EDIT)) . '" class="page-title-action">Add New</a>';
        echo '<hr class="wp-header-end">';
        self::render_flash();
        echo '<p class="mc-text">Each coach has one profile; every page builds its coach list from these. <strong>What to do here:</strong> work through To do, then open a profile to change it.</p>';

        self::render_todo(TeamOversight_Coaches::todo($season));

        echo '<h2 class="mc-heading">Profiles</h2>';
        echo '<div class="mc-listbar">';
        self::render_filter_links($filter, TeamOversight_Coaches::profile_counts());
        self::render_search_box($filter, $search);
        echo '</div>';
        if ($search !== '') {
            echo '<p class="mc-text">Names containing &ldquo;' . self::h($search) . '&rdquo;. <a href="' . esc_url(self::page_url(self::PAGE_PROFILES, $filter !== '' ? array('filter' => $filter) : array())) . '">Show all</a></p>';
        }
        self::render_list((array) TeamOversight_Coaches::list_profiles($filter, $search), $season, $filter !== '' || $search !== '');

        self::render_howto();
        self::render_glossary();
        self::flush_forms();
        echo '</div>';
    }

    /** The filter links with their counts (profile_counts(): one grouped query, the same conditions as list_profiles()'s filters). */
    private static function render_filter_links($filter, $counts) {
        $counts = is_array($counts) ? $counts : array();
        $items = array();
        foreach (self::FILTERS as $key => $label) {
            $count_key = $key === '' ? 'all' : $key;
            $url = self::page_url(self::PAGE_PROFILES, $key !== '' ? array('filter' => $key) : array());
            $items[] = '<a href="' . esc_url($url) . '"' . ($key === $filter ? ' class="current" aria-current="page"' : '') . '>'
                . esc_html($label) . ' <span class="count">(' . (isset($counts[$count_key]) ? (int) $counts[$count_key] : 0) . ')</span></a>';
        }
        $last = count($items) - 1;
        echo '<ul class="subsubsub">';
        foreach ($items as $i => $item) {
            echo '<li>' . $item . ($i < $last ? ' |' : '') . '</li>';
        }
        echo '</ul>';
    }

    private static function render_search_box($filter, $search) {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="mc-search" role="search">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_PROFILES) . '">'
            . ($filter !== '' ? '<input type="hidden" name="filter" value="' . esc_attr($filter) . '">' : '')
            . '<label class="screen-reader-text" for="mc-search">Search by public name</label>'
            . '<input type="search" id="mc-search" name="s" value="' . esc_attr($search) . '"> '
            . '<button type="submit" class="button">Search by name</button></form>';
    }

    /**
     * The list (at most LIST_MAX rows) inside the bulk form. The role lines
     * come from one list_role_lines() call for the whole page; nothing is
     * read per row. Hide from website is its own queued form.
     */
    private static function render_list(array $rows, $season, $narrowed) {
        if (empty($rows)) {
            echo '<p class="mc-text">' . ($narrowed
                ? 'No profile matches.'
                : 'No coach profiles yet. <a href="' . esc_url(self::page_url(self::PAGE_EDIT)) . '">Add the first coach</a>.') . '</p>';
            return;
        }
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) self::v($row, 'id', 0);
        }
        $lines = TeamOversight_Coaches::list_role_lines($ids, $season);
        $lines = is_array($lines) ? $lines : array();
        $lookup = current_user_can('manage_options');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="mc-bulk-form">';
        echo self::hidden_fields('bulk');
        echo '<div class="mc-scroll"><table class="wp-list-table widefat striped mc-list"><thead><tr>'
            . '<td class="manage-column column-cb check-column"><label class="screen-reader-text" for="mc-select-all">Select all</label><input type="checkbox" id="mc-select-all"></td>'
            . '<th scope="col" class="manage-column column-primary">Name</th><th scope="col">Website</th><th scope="col">Display</th><th scope="col">What they coach</th>'
            . '<th scope="col">Consent</th><th scope="col">Photo</th><th scope="col">Checked</th><th scope="col">Account</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) self::v($row, 'id', 0);
            $name = (string) self::v($row, 'public_name');
            $actions = '<span class="edit"><a href="' . esc_url(self::edit_url($id)) . '">Edit</a></span>';
            if (self::v($row, 'status') !== 'draft') {
                $actions .= ' | <span class="mc-hide">' . self::button('Hide from website', self::row_form('hide', $id), 'button-link mc-danger') . '</span>';
            }
            $role_line = isset($lines[$id]) ? (string) $lines[$id] : '';
            $checked = (string) self::v($row, 'checked_at');
            echo '<tr>'
                . '<th scope="row" class="check-column"><label class="screen-reader-text" for="mc-sel-' . $id . '">Select ' . self::h($name) . '</label><input type="checkbox" id="mc-sel-' . $id . '" name="ids[]" value="' . $id . '"></th>'
                . '<td class="column-primary has-row-actions" data-colname="Name"><strong><a class="row-title" href="' . esc_url(self::edit_url($id)) . '">' . self::h($name !== '' ? $name : 'Profile #' . $id) . '</a></strong>'
                . '<div class="row-actions">' . $actions . '</div>'
                . '<button type="button" class="toggle-row"><span class="screen-reader-text">Show more details</span></button></td>'
                . '<td data-colname="Website">' . self::h(self::website_label($row)) . '</td>'
                . '<td data-colname="Display">' . self::h(self::v($row, 'display_mode') === 'name_only' ? 'Name only' : 'Full profile') . '</td>'
                . '<td data-colname="What they coach">' . ($role_line !== '' ? self::h($role_line) : '<span class="mc-muted">No role yet</span>') . '</td>'
                . '<td data-colname="Consent">' . self::h(self::consent_label($row)) . '</td>'
                . '<td data-colname="Photo">' . self::h(self::photo_label($row)) . '</td>'
                . '<td data-colname="Checked" class="mc-nowrap">' . self::h($checked !== '' ? mysql2date('j M Y', $checked) : 'Never') . '</td>'
                . '<td data-colname="Account">' . self::account_cell($row, $lookup) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        if (count($rows) >= TeamOversight_Coaches::LIST_MAX) {
            echo '<p class="mc-text mc-muted">Showing the first ' . (int) TeamOversight_Coaches::LIST_MAX . '. Search by name to find the others.</p>';
        }
        echo '<div class="mc-bar"><label class="screen-reader-text" for="mc-bulk-action">With the ticked profiles</label>'
            . '<select name="bulk_action" id="mc-bulk-action"><option value="">With the ticked profiles&hellip;</option><option value="hide">Hide from website</option><option value="checked">Still correct</option></select>'
            . '<button type="submit" class="button">Apply</button>'
            . '<span class="description">Still correct marks a shown profile as checked today, so it leaves Needs checking.</span></div>';
        echo '</form>';
    }

    /** Website column: Shown / Hidden / Hidden: no consent / Hidden: asked not to be shown. */
    private static function website_label($row) {
        if (self::v($row, 'status') !== 'draft') {
            return 'Shown';
        }
        if (self::v($row, 'consent_method') === 'declined') {
            return 'Hidden: asked not to be shown';
        }
        return TeamOversight_Coaches::publish_problem($row) !== '' ? 'Hidden: no consent' : 'Hidden';
    }

    /** Consent column, with no date: Recorded / Missing / Parent missing / Asked not to be shown (the No consent filter's conditions). */
    private static function consent_label($row) {
        if (self::v($row, 'consent_method') === 'declined') {
            return 'Asked not to be shown';
        }
        if ((string) self::v($row, 'consent_date') === ''
            || !in_array((string) self::v($row, 'consent_method'), TeamOversight_Coaches::CONSENT_GIVEN, true)
            || !array_key_exists((string) self::v($row, 'consent_scope'), TeamOversight_Coaches::CONSENT_SCOPES)) {
            return 'Missing';
        }
        if ((int) self::v($row, 'is_minor', 0) === 1 && (string) self::v($row, 'guardian_date') === '') {
            return 'Parent missing';
        }
        return 'Recorded';
    }

    /** Photo column: Cleared / Waiting / None. */
    private static function photo_label($row) {
        $photo = (int) self::v($row, 'photo_id', 0);
        if ($photo <= 0) {
            return 'None';
        }
        return (int) self::v($row, 'photo_cleared_id', 0) === $photo ? 'Cleared' : 'Waiting';
    }

    /** Account column: Linked / No account; administrators get the Member Lookup link. The account email is never printed. */
    private static function account_cell($row, $lookup) {
        $user_id = (int) self::v($row, 'user_id', 0);
        if ($user_id <= 0) {
            return self::h('No account');
        }
        if ((int) self::v($row, 'account_id', 0) <= 0) {
            return '<span class="mc-bad-text">' . self::h('Linked, account gone') . '</span>';
        }
        return $lookup ? '<a href="' . esc_url(TeamOversight_Member_Lookup::profile_url($user_id)) . '">Linked</a>' : self::h('Linked');
    }

    // ------------------------------------------------------------------
    // To do, How to and the glossary (on Profiles)
    // ------------------------------------------------------------------

    /**
     * The To do panel (8.7): TeamOversight_Coaches::todo()'s rows as Ready /
     * Check / Fix pills, at most TODO_MAX. The links about the editor role
     * show for administrators only.
     */
    private static function render_todo($rows) {
        $rows = is_array($rows) ? array_values($rows) : array();
        $labels = array('ok' => 'Ready', 'warn' => 'Check', 'bad' => 'Fix');
        $editors = self::can_manage_editors();
        echo '<div class="mc-box mc-todo" id="todo"><h2>To do</h2>';
        echo '<p class="description">This list is read live, so it clears itself when you fix something. It never blocks anything.</p>';
        if (empty($rows)) {
            echo '<p>Nothing to do right now.</p></div>';
            return;
        }
        echo '<div class="mc-scroll"><table class="widefat striped"><tbody>';
        foreach (array_slice($rows, 0, self::TODO_MAX) as $row) {
            $state = self::v($row, 'state');
            $state = isset($labels[$state]) ? $state : 'warn';
            $key = (string) self::v($row, 'key');
            $url = (string) self::v($row, 'url');
            $label = (string) self::v($row, 'label');
            // todo() gives the Editors and Media links to administrators only; the Editors check is repeated here.
            if (in_array($key, array('editors', 'editor_lost'), true) && !$editors) {
                $url = '';
            }
            $media = (string) self::v($row, 'media_url');
            echo '<tr class="' . esc_attr('mc-todo-' . sanitize_html_class($key)) . '">'
                . '<td class="mc-pill-cell"><span class="mc-pill mc-pill-' . esc_attr($state) . '">' . self::h($labels[$state]) . '</span></td>'
                . '<td>' . self::h(self::v($row, 'text')) . '</td>'
                . '<td class="mc-nowrap">' . ($url !== '' ? '<a href="' . esc_url($url) . '">' . self::h($label !== '' ? $label : 'Open') . '</a>' : '')
                . ($media !== '' ? ($url !== '' ? '<br>' : '') . '<a href="' . esc_url($media) . '">Open in Media</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (count($rows) > self::TODO_MAX) {
            echo '<p class="mc-muted">and ' . (int) (count($rows) - self::TODO_MAX) . ' more.</p>';
        }
        echo '</div>';
    }

    /** How to (8.4 item 7): the day-to-day steps, each linking its screen. Team coaching and the website pages come in later updates. */
    private static function render_howto() {
        $add = esc_url(self::page_url(self::PAGE_EDIT));
        $check = esc_url(self::page_url(self::PAGE_PROFILES, array('filter' => 'check')));
        $programs = esc_url(self::page_url(self::PAGE_SETTINGS) . '#programs');
        $editors = esc_url(self::page_url(self::PAGE_SETTINGS) . '#editors');
        $bookmark = admin_url('admin.php?page=' . self::PAGE_PROFILES);
        ?>
        <details class="mc-box mc-howto" id="how-to">
            <summary>How to</summary>
            <div class="mc-text">
                <h3>Add a coach</h3>
                <ol>
                    <li>Click <a href="<?php echo $add; ?>">Add New</a> and type their public name: how the website names them. Nothing is taken from their account.</li>
                    <li>Fill in the one-line intro, the short bio, the highlights and the playing background from what they sent. Every box has a hint under it.</li>
                    <li>Under What they coach, set their role on each program they coach.</li>
                    <li>Choose their photo, and tick Photo cleared once it has been checked. Until then the card shows their initials.</li>
                    <li>Record consent: the date they answered, how they agreed and what they agreed to (Full profile, or Name and role only). For a coach under 18, a parent or guardian must agree too.</li>
                    <li>Choose Full profile or Name only, then click Publish. Publish is refused until consent is recorded; Save draft keeps the profile hidden.</li>
                </ol>
                <h3>A coach who has a team place</h3>
                <p>Add them with <a href="<?php echo $add; ?>">Add New</a> the same way, with their program roles. Showing team coaching (State League and the junior teams) comes in a later update, which links each profile to the coach places Club Manager already holds. Never make a second profile for the same person.</p>
                <h3>A coach on several programs</h3>
                <p>Keep one profile. In What they coach, set their role for each program: blank, Lead coach, Coach or Assistant coach.</p>
                <h3>Update a bio or photo</h3>
                <p>Open the profile from the list, change it and click Update. The History under the form shows who changed what, with the old text, so a mistake can be copied back.</p>
                <h3>Retire or hide a coach</h3>
                <p>For a program coach, set that program back to blank. To hide someone straight away, point at their name in the list (or Tab to it) and click Hide from website, or use Hide from website on their profile. Never delete a profile: there is no delete. To get rid of a photo file, ask an administrator to delete it in Media: Remove photo only takes it off the profile, and the file stays.</p>
                <h3>Reorder</h3>
                <p>You don't need to. Lead coaches come first, then head coaches before assistants, then A to Z. To put someone first, make them Lead coach.</p>
                <h3>New season</h3>
                <p>Work through <a href="<?php echo $check; ?>">Needs checking</a>. Nothing is pre-ticked, so each confirmation is a deliberate click: tick several and choose Still correct, or open a profile and click Update.</p>
                <h3>New program</h3>
                <p>On <a href="<?php echo $programs; ?>">Programs and settings</a>, type its name under Add a program and click Save programs. Its key is made from the name and never changes.</p>
                <h3>Give someone access</h3>
                <p>An administrator adds them on <a href="<?php echo $editors; ?>">Programs and settings, Coach bios editors</a>. Never use the Role dropdown on the Users screen: it replaces the person's member roles. Keep at least three people holding the role.</p>
                <h3>The bookmark</h3>
                <p>Editors don't see the admin bar on the website, so bookmark <code><?php echo esc_html($bookmark); ?></code>.</p>
                <h3>Photos</h3>
                <p>Upload only photos you've already vetted. Every file uploaded to the site goes into its Media Library, which the site lists publicly, and anyone with a file's link can open it from the moment it's uploaded. The site keeps coach photos that no card shows out of its public list, but the link still works until an administrator deletes the file.</p>
            </div>
        </details>
        <?php
    }

    /** The glossary (8.4 item 8). */
    private static function render_glossary() {
        $months = (int) round(TeamOversight_Coaches::CHECK_AFTER_DAYS / 30);
        $terms = array(
            'Shown' => 'Published: the profile appears on every page that lists it.',
            'Hidden' => 'A draft: it appears nowhere on the website. Hidden profiles are kept, never deleted.',
            'Name only' => 'The website shows just their name and role: no photo, intro or bio.',
            'Ticked (waiting for the season\'s Show button)' => 'A team place someone chose to show, in a season whose team coaching isn\'t on the website yet. (Team coaching comes in a later update.)',
            'Not ticked' => 'A coach place Club Manager holds that nobody has chosen to show. Nothing is ticked by default. (Team coaching comes in a later update.)',
            'No profile' => 'A coach place whose person has no coach profile yet. (Team coaching comes in a later update.)',
            'Photo waiting' => 'A photo has been chosen but Photo cleared isn\'t ticked, so the card shows their initials.',
            'Needs checking' => 'A shown profile nobody has confirmed for ' . $months . ' months. Open it and click Update, or tick it in the list and choose Still correct.',
            'Asked not to be shown' => 'They answered that they don\'t want to be on the website. The profile is kept, so nobody asks them again, and it can\'t be published.',
        );
        echo '<details class="mc-box" id="glossary"><summary>What the words mean</summary><dl class="mc-text">';
        foreach ($terms as $term => $sentence) {
            echo '<dt>' . self::h($term) . '</dt><dd>' . self::h($sentence) . '</dd>';
        }
        echo '</dl></details>';
    }

    // ------------------------------------------------------------------
    // Add New / Edit (club-coaches-edit: &profile=<id>, or none for a new one)
    // ------------------------------------------------------------------

    public static function render_edit_page() {
        if (!current_user_can(TeamOversight_Coaches::CAP)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        self::$forms = array();
        $id = self::get_id('profile');
        $profile = $id > 0 ? TeamOversight_Coaches::get_profile($id) : null;
        self::$view = array('page' => self::PAGE_EDIT, 'profile' => $id, 'filter' => '', 's' => '', 'anchor' => '');
        $back = '<a href="' . esc_url(self::page_url(self::PAGE_PROFILES)) . '">Back to Coaches</a>';

        echo '<div class="wrap murvc-coaches-admin">';
        self::styles();
        if ($id > 0 && !$profile) {
            echo '<h1>Coach profile</h1><hr class="wp-header-end">';
            self::render_flash();
            echo '<div class="notice notice-error inline"><p>That profile doesn\'t exist. ' . $back . '.</p></div></div>';
            return;
        }
        $draft = self::take_draft($id);
        $v = self::form_values($profile, $draft);
        $history = $profile ? TeamOversight_Coaches::history($id, 50) : array();
        $history = is_array($history) ? array_values($history) : array();
        $published = $profile && $profile->status !== 'draft';

        echo '<h1 class="wp-heading-inline">' . self::h($profile ? 'Edit coach profile' : 'Add a coach') . '</h1>';
        if ($profile) {
            echo ' <a href="' . esc_url(self::page_url(self::PAGE_EDIT)) . '" class="page-title-action">Add New</a>';
        }
        echo '<hr class="wp-header-end">';
        self::render_flash();
        echo '<p class="mc-text">' . ($profile ? '<strong>Profile #' . (int) $id . '.</strong> ' : '')
            . 'Everything here is typed by the committee: nothing is read from the coach\'s account. Nothing shows on the website until the profile is published, and Publish is refused until consent is recorded. ' . $back . '.</p>';
        if (is_array($draft)) {
            echo '<div class="notice notice-warning inline"><p>The form below holds what you typed, not what is saved. Check it and save again.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="mc-profile-form" class="mc-form">';
        echo self::hidden_fields('save', $id);
        echo '<input type="hidden" name="version" value="' . ($profile ? (int) $profile->version : 0) . '">';
        self::render_fields($v);
        self::render_consent_box($v);
        self::render_status($profile, $history);
        echo '<p class="mc-actions">';
        if ($published) {
            echo '<button type="submit" name="intent" value="publish" class="button button-primary">Update</button> '
                . self::button('Hide from website', self::row_form('hide', $id));
        } else {
            echo '<button type="submit" name="intent" value="draft" class="button">Save draft</button> '
                . '<button type="submit" name="intent" value="publish" class="button button-primary">Publish</button>';
        }
        echo '</p></form>';
        self::flush_forms();

        if ($profile) {
            self::render_history($history);
        }
        self::edit_script();
        echo '</div>';
    }

    /**
     * The values the form is drawn with: the saved row, or what was typed
     * when a save stored nothing (the draft), else the defaults for a new
     * profile. The draft has posted_fields()'s shape, or clean_fields()'
     * when update_profile() kept it for a stale save.
     */
    private static function form_values($profile, $draft) {
        $v = array(
            'public_name' => '', 'intro' => '', 'bio' => '', 'accreditation' => '', 'accreditation_other' => '',
            'highlights' => array(), 'playing_background' => '', 'program_roles' => array(), 'display_mode' => 'full',
            'photo_id' => 0, 'photo_cleared' => false,
            'consent_date' => '', 'consent_method' => '', 'consent_scope' => '', 'is_minor' => 0, 'guardian_date' => '',
        );
        $text = array('public_name', 'intro', 'bio', 'accreditation', 'accreditation_other', 'playing_background', 'display_mode', 'consent_date', 'consent_method', 'consent_scope', 'guardian_date');
        if ($profile) {
            foreach ($text as $key) {
                $v[$key] = isset($profile->$key) ? (string) $profile->$key : '';
            }
            $v['highlights'] = TeamOversight_Coaches::highlights_of($profile);
            $v['program_roles'] = TeamOversight_Coaches::program_roles_of($profile);
            $v['photo_id'] = (int) $profile->photo_id;
            $v['photo_cleared'] = $v['photo_id'] > 0 && (int) $profile->photo_cleared_id === $v['photo_id'];
            $v['is_minor'] = (int) $profile->is_minor;
        }
        if (is_array($draft)) {
            foreach ($text as $key) {
                if (isset($draft[$key]) && is_scalar($draft[$key])) {
                    $v[$key] = (string) $draft[$key];
                }
            }
            $v['highlights'] = isset($draft['highlights']) && is_array($draft['highlights']) ? array_values(array_map('strval', $draft['highlights'])) : array();
            $v['program_roles'] = isset($draft['program_roles']) && is_array($draft['program_roles']) ? $draft['program_roles'] : array();
            $v['photo_id'] = isset($draft['photo_id']) ? (int) $draft['photo_id'] : 0;
            // posted_fields() keeps the posted photo_cleared; a stale update's draft (the writer's cleaned fields) has photo_cleared_id.
            $cleared = isset($draft['photo_cleared']) ? $draft['photo_cleared'] : (isset($draft['photo_cleared_id']) ? $draft['photo_cleared_id'] : 0);
            $v['photo_cleared'] = $v['photo_id'] > 0 && is_scalar($cleared) && (int) $cleared === $v['photo_id'];
            $v['is_minor'] = !empty($draft['is_minor']) ? 1 : 0;
        }
        if (!in_array($v['display_mode'], array('full', 'name_only'), true)) {
            $v['display_mode'] = 'full';
        }
        $v['highlights'] = array_pad(array_slice($v['highlights'], 0, TeamOversight_Coaches::HIGHLIGHTS), TeamOversight_Coaches::HIGHLIGHTS, '');
        return $v;
    }

    /** One form-table row with its hint. $control is HTML built (and escaped) by the caller. */
    private static function field_row($label, $for, $control, $hint) {
        echo '<tr><th scope="row">' . ($for !== '' ? '<label for="' . esc_attr($for) . '">' . self::h($label) . '</label>' : self::h($label)) . '</th>'
            . '<td>' . $control . '<span class="description mc-hint">' . self::h($hint) . '</span></td></tr>';
    }

    private static function options(array $choices, $current, $blank = null) {
        $html = $blank !== null ? '<option value="">' . self::h($blank) . '</option>' : '';
        foreach ($choices as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected((string) $current, (string) $value, false) . '>' . self::h($label) . '</option>';
        }
        return $html;
    }

    /** The profile's fields (8.5 items 1 to 10, without the 1.83.0 Teams box). */
    private static function render_fields(array $v) {
        $intro_n = mb_strlen($v['intro']);
        $words = preg_split('/\s+/u', trim($v['bio']), -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? count($words) : 0;
        echo '<table class="form-table" role="presentation"><tbody>';

        self::field_row('Public name', 'mc-public-name',
            '<input type="text" class="regular-text" id="mc-public-name" name="public_name" value="' . esc_attr($v['public_name']) . '" required>',
            'How the website names them. Typed by you, never taken from their account.');

        self::field_row('One-line intro', 'mc-intro',
            '<input type="text" class="large-text" id="mc-intro" name="intro" value="' . esc_attr($v['intro']) . '" data-max="' . (int) TeamOversight_Coaches::INTRO_MAX . '" aria-describedby="mc-intro-count">'
                . '<span class="mc-count' . ($intro_n > TeamOversight_Coaches::INTRO_MAX ? ' mc-over' : '') . '" id="mc-intro-count" aria-live="polite">' . (int) $intro_n . ' / ' . (int) TeamOversight_Coaches::INTRO_MAX . '</span>',
            'One line under their name on every card, at most ' . TeamOversight_Coaches::INTRO_MAX . ' characters, e.g. Patient, technical coach who loves first-time players.');

        self::field_row('Short bio', 'mc-bio',
            '<textarea class="large-text" rows="6" id="mc-bio" name="bio" data-aim="' . (int) TeamOversight_Coaches::BIO_AIM_WORDS . '" aria-describedby="mc-bio-count">' . esc_textarea($v['bio']) . '</textarea>'
                . '<span class="mc-count" id="mc-bio-count" aria-live="polite">' . self::plural($words, 'word', 'words') . ' (aim for about ' . (int) TeamOversight_Coaches::BIO_AIM_WORDS . ')</span>',
            '2 to 4 sentences: facts and what players can expect. Plain text; a blank line starts a new paragraph. No contact details.');

        self::field_row('Accreditation', 'mc-accreditation',
            '<select id="mc-accreditation" name="accreditation">' . self::options(TeamOversight_Coaches::ACCREDITATIONS, $v['accreditation'], 'Not set (no chip shows)') . '</select>'
                . ' <span id="mc-accreditation-other"><label for="mc-accreditation-other-text">Other:</label> <input type="text" class="regular-text" id="mc-accreditation-other-text" name="accreditation_other" value="' . esc_attr($v['accreditation_other']) . '"></span>',
            'Optional. The card simply has no chip when it\'s not set. If they answered none, leave it as Not set: Other\'s text is printed on the chip as typed.');

        $highlights = '';
        foreach ($v['highlights'] as $i => $line) {
            $highlights .= '<input type="text" class="large-text mc-highlight" id="mc-highlight-' . ($i + 1) . '" name="highlights[]" value="' . esc_attr($line) . '" aria-label="' . esc_attr('Highlight ' . ($i + 1)) . '">';
        }
        self::field_row('Highlights', 'mc-highlight-1', $highlights,
            'Up to ' . TeamOversight_Coaches::HIGHLIGHTS . '. Facts or numbers, not adjectives, e.g. Coached State League for 4 seasons.');

        self::field_row('Playing background', 'mc-background',
            '<input type="text" class="large-text" id="mc-background" name="playing_background" value="' . esc_attr($v['playing_background']) . '">',
            'One line about where they played, e.g. Played State League for the club for six seasons.');

        $programs = TeamOversight_Coaches::programs();
        $roles = '';
        foreach (is_array($programs) ? $programs : array() as $key => $program) {
            $key = (string) $key;
            $label = is_array($program) && isset($program['label']) ? (string) $program['label'] : $key;
            $current = isset($v['program_roles'][$key]) && is_string($v['program_roles'][$key]) ? $v['program_roles'][$key] : '';
            $roles .= '<div class="mc-program"><label for="' . esc_attr('mc-role-' . $key) . '">' . self::h($label) . '</label> '
                . '<select id="' . esc_attr('mc-role-' . $key) . '" name="' . esc_attr('program_roles[' . $key . ']') . '">' . self::options(TeamOversight_Coaches::PROGRAM_ROLES, $current, '(blank)') . '</select></div>';
        }
        if ($roles === '') {
            $roles = '<p>No programs yet: add one on <a href="' . esc_url(self::page_url(self::PAGE_SETTINGS) . '#programs') . '">Programs and settings</a>.</p>';
        }
        self::field_row('What they coach', '', $roles,
            'Lead coaches come first on every page. Teams will come from Club Manager\'s coach places in a later update, so never make a second profile.');

        self::field_row('Show as', '',
            '<fieldset><legend class="screen-reader-text">Show as</legend>'
                . '<label><input type="radio" name="display_mode" value="full"' . checked($v['display_mode'], 'full', false) . '> Full profile</label><br>'
                . '<label><input type="radio" name="display_mode" value="name_only"' . checked($v['display_mode'], 'name_only', false) . '> Name only</label></fieldset>',
            'Name only shows the name and role: no photo, intro or bio.');

        self::field_row('Photo', '', self::photo_control($v),
            'Tick Photo cleared only once this photo has been checked. Choosing another photo un-ticks it. Upload only photos you\'ve already vetted: every uploaded file goes into the site\'s Media Library, which the site lists publicly, and its link works from the moment it\'s uploaded, whatever this box says. Remove photo takes it off this profile but leaves the file in Media.');

        echo '</tbody></table>';
    }

    /** The photo: preview, Choose photo (WordPress's media picker), Remove photo and Photo cleared. */
    private static function photo_control(array $v) {
        $photo = (int) $v['photo_id'];
        $url = $photo > 0 ? wp_get_attachment_image_url($photo, 'thumbnail') : false;
        $gone = $photo > 0 && !$url;
        return '<div class="mc-photo">'
            . '<img id="mc-photo-img" alt=""' . ($url ? ' src="' . esc_url($url) . '"' : ' hidden') . '>'
            . '<p id="mc-photo-none"' . ($photo > 0 ? ' hidden' : '') . '>No photo: the card shows their initials.</p>'
            . ($gone ? '<p id="mc-photo-gone" class="mc-bad-text">Photo #' . $photo . ' is no longer in the Media Library, so the card shows initials. Choose another, or remove it.</p>' : '')
            . '<input type="hidden" id="mc-photo-id" name="photo_id" value="' . $photo . '">'
            . '<p><button type="button" class="button" id="mc-photo-choose">Choose photo</button> '
            . '<button type="button" class="button-link mc-danger" id="mc-photo-remove"' . ($photo > 0 ? '' : ' hidden') . '>Remove photo</button></p>'
            . '<p><label><input type="checkbox" id="mc-photo-cleared" name="photo_cleared" value="' . $photo . '"' . ($v['photo_cleared'] ? ' checked' : '') . ($photo > 0 ? '' : ' disabled') . '> Photo cleared</label></p>'
            . '<noscript><p class="mc-bad-text">Choosing a photo needs JavaScript.</p></noscript>'
            . '</div>';
    }

    /** Consent (8.5 item 11): committee only, never on the website. */
    private static function render_consent_box(array $v) {
        $today = current_time('Y-m-d');
        echo '<div class="mc-box mc-consent"><h2>Consent</h2><p class="description">Committee only. Never shown on the website. Publish is refused until this is recorded. For a coach under 18, the coach and a parent or guardian must both agree.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::field_row('Date they answered', 'mc-consent-date',
            '<input type="date" id="mc-consent-date" name="consent_date" value="' . esc_attr($v['consent_date']) . '" max="' . esc_attr($today) . '">',
            'The day they replied. It can\'t be in the future.');
        self::field_row('How they agreed', 'mc-consent-method',
            '<select id="mc-consent-method" name="consent_method">' . self::options(TeamOversight_Coaches::CONSENT_METHODS, $v['consent_method'], 'Not recorded yet') . '</select>',
            'Asked not to be shown keeps the profile hidden, so nobody asks them again.');
        self::field_row('What they agreed to', 'mc-consent-scope',
            '<select id="mc-consent-scope" name="consent_scope">' . self::options(TeamOversight_Coaches::CONSENT_SCOPES, $v['consent_scope'], 'Not recorded yet') . '</select>',
            'Show as Full profile needs a Full profile answer; Name and role only allows Name only.');
        self::field_row('Coach is under 18', 'mc-minor',
            '<input type="checkbox" id="mc-minor" name="is_minor" value="1"' . checked((int) $v['is_minor'], 1, false) . '>',
            'Under 18, a parent or guardian must agree as well as the coach.');
        self::field_row('Date a parent or guardian agreed', 'mc-guardian-date',
            '<input type="date" id="mc-guardian-date" name="guardian_date" value="' . esc_attr($v['guardian_date']) . '" max="' . esc_attr($today) . '">',
            'Only for a coach under 18.');
        echo '</tbody></table></div>';
    }

    /** The status line (8.5 item 13), read from the saved row, and who last checked it. */
    private static function render_status($profile, array $history) {
        if (!$profile) {
            echo '<p class="mc-status">Not saved yet. Save a draft at any time: nothing shows on the website until it is published.</p>';
            return;
        }
        if ($profile->status !== 'draft') {
            echo '<p class="mc-status mc-good-text">Shown on the website.</p>';
        } else {
            $problem = TeamOversight_Coaches::publish_problem($profile);
            echo '<p class="mc-status' . ($problem !== '' ? ' mc-bad-text' : '') . '">' . self::h($problem === '' ? 'Ready to publish.' : 'Can\'t be published yet: ' . $problem) . '</p>';
        }
        // Publish, Update while shown and Still correct stamp checked_at and their history row with one
        // time, so the newest row at that time says who checked it.
        $checked = isset($profile->checked_at) ? (string) $profile->checked_at : '';
        $line = '';
        if ($checked !== '') {
            $by = '';
            foreach ($history as $row) {
                if ((string) self::v($row, 'recorded_at') === $checked) {
                    $by = self::who(self::v($row, 'recorded_by', 0), 'the daily check');
                    break;
                }
            }
            $line = 'Last checked ' . mysql2date('j M Y', $checked) . ($by !== '' ? ' by ' . $by : '') . '.';
        } elseif ($profile->status !== 'draft') {
            $line = 'Never checked.';
        }
        if ($line !== '') {
            echo '<p class="mc-muted">' . self::h($line) . '</p>';
        }
    }

    /**
     * History (8.5): the newest 50 changes, each with the date, who
     * ("Daily check" for the daily job) and a plain sentence. Long old values
     * sit in <details>, so an old bio can be copied back.
     */
    private static function render_history(array $rows) {
        echo '<h2 id="history">History</h2>';
        if (empty($rows)) {
            echo '<p class="mc-muted">No changes recorded yet.</p>';
            return;
        }
        $ids = array();
        foreach ($rows as $row) {
            $by = (int) self::v($row, 'recorded_by', 0);
            if ($by > 0) {
                $ids[$by] = $by;
            }
        }
        if (!empty($ids)) {
            cache_users(array_values($ids));
        }
        echo '<div class="mc-scroll"><table class="widefat striped mc-history"><thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $at = (string) self::v($row, 'recorded_at');
            echo '<tr><td class="mc-nowrap">' . self::h($at !== '' ? mysql2date('j M Y g:ia', $at) : '') . '</td>'
                . '<td>' . self::h(self::who(self::v($row, 'recorded_by', 0))) . '</td>'
                . '<td>' . self::history_html($row) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** One history row as escaped HTML. */
    private static function history_html($row) {
        $event = (string) self::v($row, 'event');
        $detail = self::decode(self::v($row, 'detail'));
        switch ($event) {
            case 'created':
                return self::h('Created as a draft.');
            case 'updated':
                return self::diff_html($detail);
            case 'published':
                return self::h('Published: shown on the website.');
            case 'hidden':
                return self::h('Hidden from the website.');
            case 'hidden_by_guard':
                return self::h('Saved and hidden from the website: its consent no longer covered it.');
            case 'checked':
                return self::h('Marked Still correct.');
            case 'photo_changed':
                return self::h('Photo changed from ' . self::photo_text(isset($detail['old']) ? $detail['old'] : 0) . ' to ' . self::photo_text(isset($detail['new']) ? $detail['new'] : 0) . '.');
            case 'photo_cleared':
                return self::h('Photo cleared.');
            case 'photo_uncleared':
                return self::h('Photo no longer cleared: the card shows initials.');
        }
        return self::h(ucfirst(str_replace('_', ' ', $event)) . '.');
    }

    /** An updated row's field => array(old, new), one line a field; long values in <details>. */
    private static function diff_html(array $detail) {
        $parts = array();
        foreach ($detail as $field => $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $field = (string) $field;
            $old = self::value_text($field, array_key_exists(0, $pair) ? $pair[0] : (isset($pair['old']) ? $pair['old'] : ''));
            $new = self::value_text($field, array_key_exists(1, $pair) ? $pair[1] : (isset($pair['new']) ? $pair['new'] : ''));
            $label = isset(self::FIELD_LABELS[$field]) ? self::FIELD_LABELS[$field] : ucfirst(str_replace('_', ' ', $field));
            if (mb_strlen($old) + mb_strlen($new) > 100 || strpos($old . $new, "\n") !== false) {
                $parts[] = '<details><summary>' . self::h($label) . ' changed</summary>'
                    . '<div class="mc-old"><strong>Before:</strong> ' . nl2br(self::h($old !== '' ? $old : '(blank)'), false) . '</div>'
                    . '<div class="mc-old"><strong>After:</strong> ' . nl2br(self::h($new !== '' ? $new : '(blank)'), false) . '</div></details>';
            } else {
                $parts[] = self::h($label . ': ' . ($old !== '' ? $old : '(blank)') . ' → ' . ($new !== '' ? $new : '(blank)'));
            }
        }
        return empty($parts) ? self::h('Saved, with no field changed.') : self::h('Changed:') . '<br>' . implode('<br>', $parts);
    }

    /** A stored value in words (keys as their labels, lists joined). */
    private static function value_text($field, $value) {
        if (is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (is_array($value)) {
            if ($field === 'program_roles') {
                $programs = TeamOversight_Coaches::programs();
                $out = array();
                foreach ($value as $key => $role) {
                    if (!is_scalar($role)) {
                        continue;
                    }
                    $role = (string) $role;
                    $label = isset($programs[$key]['label']) ? (string) $programs[$key]['label'] : (string) $key;
                    $out[] = $label . ': ' . (isset(TeamOversight_Coaches::PROGRAM_ROLES[$role]) ? TeamOversight_Coaches::PROGRAM_ROLES[$role] : $role);
                }
                return implode(', ', $out);
            }
            return implode(' | ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        $value = $value === null ? '' : (string) $value;
        $maps = array(
            'accreditation' => TeamOversight_Coaches::ACCREDITATIONS,
            'consent_method' => TeamOversight_Coaches::CONSENT_METHODS,
            'consent_scope' => TeamOversight_Coaches::CONSENT_SCOPES,
            'display_mode' => array('full' => 'Full profile', 'name_only' => 'Name only'),
            'status' => array('draft' => 'Hidden', 'published' => 'Shown'),
        );
        if (isset($maps[$field][$value])) {
            return $maps[$field][$value];
        }
        if ($field === 'is_minor') {
            return $value === '1' ? 'Yes' : 'No';
        }
        if ($field === 'photo_id' || $field === 'photo_cleared_id' || $field === 'user_id') {
            return self::photo_text($value);
        }
        if (($field === 'consent_date' || $field === 'guardian_date') && $value !== '') {
            return TeamOversight_Fee_Timeline::format_date($value);
        }
        return $value;
    }

    /** An attachment or account id as "#12", or "none". */
    private static function photo_text($id) {
        return (int) $id > 0 ? '#' . (int) $id : 'none';
    }

    // ------------------------------------------------------------------
    // Programs and settings (club-coaches-settings)
    // ------------------------------------------------------------------

    public static function render_settings_page() {
        if (!current_user_can(TeamOversight_Coaches::CAP)) {
            wp_die(esc_html('You do not have permission to view this page.'), '', array('response' => 403));
        }
        self::$forms = array();
        self::$view = array('page' => self::PAGE_SETTINGS, 'profile' => 0, 'filter' => '', 's' => '', 'anchor' => '');
        $editors = self::can_manage_editors();

        echo '<div class="wrap murvc-coaches-admin">';
        self::styles();
        echo '<h1>Coaches: programs and settings</h1><hr class="wp-header-end">';
        self::render_flash();
        echo '<p class="mc-text">The programs a coach can have a role on' . ($editors ? ', and who may edit coach profiles' : '') . '. '
            . '<a href="#programs">Programs</a>' . ($editors ? ' · <a href="#editors">Coach bios editors</a>' : '') . '</p>';
        self::render_programs_section();
        if ($editors) {
            self::render_editors_section();
        } else {
            echo '<p class="mc-text mc-muted">Ask an administrator to give someone the Coach bios editor role.</p>';
        }
        self::flush_forms();
        self::settings_script();
        echo '</div>';
    }

    /** #programs: one row per program, Add a program, one Save programs button (held to the hash of what was drawn). */
    private static function render_programs_section() {
        self::$view['anchor'] = 'programs';
        $programs = TeamOversight_Coaches::programs();
        $programs = is_array($programs) ? $programs : array();
        $defaults = TeamOversight_Coaches::DEFAULT_PROGRAMS;
        $counts = self::program_counts();
        $accents = array('blue' => 'Blue', 'red' => 'Red');
        $groups = array();
        foreach (TeamOversight_Coaches::TEAM_GROUPS as $group) {
            $groups[] = $group[0] . ' ' . (int) $group[1];
        }

        echo '<h2 id="programs">Programs</h2>';
        echo '<p class="mc-text">Each program gets a role select on every profile (Lead coach, Coach, Assistant coach). Accent is the colour of its cards; Filter button gives it a button on the Our coaches page; Order sets where it sits (the team groups use ' . self::h(implode(', ', $groups)) . ').</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo self::hidden_fields('programs');
        echo '<input type="hidden" name="shown_hash" value="' . esc_attr(TeamOversight_Coaches::programs_hash()) . '">';
        echo '<div class="mc-scroll"><table class="widefat striped mc-programs"><thead><tr><th scope="col">Program</th><th scope="col">Accent</th><th scope="col">Filter button</th><th scope="col">Order</th><th scope="col">Profiles with a role</th><th scope="col">Remove</th></tr></thead><tbody>';
        foreach ($programs as $key => $program) {
            $key = (string) $key;
            $program = is_array($program) ? $program : array();
            $label = isset($program['label']) ? (string) $program['label'] : $key;
            $accent = isset($program['accent']) ? (string) $program['accent'] : 'blue';
            $filter = !empty($program['filter']);
            $order = isset($program['order']) ? (int) $program['order'] : 0;
            $n = isset($counts[$key]) ? (int) $counts[$key] : 0;
            $name = 'rows[' . $key . ']';
            if (isset($defaults[$key]) && is_array($defaults[$key])) {
                $d = $defaults[$key];
                $builtin = 'Built-in: ' . (isset($d['label']) ? $d['label'] : $key) . ', ' . (isset($d['accent']) ? $d['accent'] : 'blue')
                    . ', filter button ' . (!empty($d['filter']) ? 'on' : 'off') . ', order ' . (isset($d['order']) ? (int) $d['order'] : 0) . '.';
            } else {
                $builtin = 'Added on this site.';
            }
            $remove = $n > 0
                ? '<label title="' . esc_attr(self::plural($n, 'profile still has', 'profiles still have') . ' a role on it: set them to blank first.') . '"><input type="checkbox" disabled> Remove</label>'
                : '<label><input type="checkbox" name="' . esc_attr($name . '[remove]') . '" value="1"> Remove</label>';
            echo '<tr>'
                . '<td><label class="screen-reader-text" for="' . esc_attr('mc-prog-' . $key) . '">Program name</label><input type="text" class="regular-text" id="' . esc_attr('mc-prog-' . $key) . '" name="' . esc_attr($name . '[label]') . '" value="' . esc_attr($label) . '" required>'
                . '<span class="description mc-hint">Key ' . self::h($key) . '. ' . self::h($builtin) . '</span></td>'
                . '<td><select name="' . esc_attr($name . '[accent]') . '" aria-label="Accent">' . self::options($accents, $accent) . '</select></td>'
                . '<td><select name="' . esc_attr($name . '[filter]') . '" aria-label="Filter button">' . self::options(array('1' => 'Yes', '0' => 'No'), $filter ? '1' : '0') . '</select></td>'
                . '<td><input type="number" class="small-text" min="0" max="999" name="' . esc_attr($name . '[order]') . '" value="' . $order . '" aria-label="Order"></td>'
                . '<td>' . $n . '</td>'
                . '<td>' . $remove . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<h3>Add a program</h3>';
        echo '<p class="mc-bar"><label for="mc-new-label">Name</label> <input type="text" class="regular-text" id="mc-new-label" name="new_label" value=""> '
            . '<label for="mc-new-accent">Accent</label> <select id="mc-new-accent" name="new_accent">' . self::options($accents, 'blue') . '</select></p>';
        echo '<p class="description">Its key is made from the name and never changes. A program can be removed only while no profile has a role on it.</p>';
        echo '<p><button type="submit" class="button button-primary">Save programs</button></p>';
        echo '</form>';
    }

    /**
     * Profiles with a role per program key, for the Remove column, from one
     * list_profiles() read (the first LIST_MAX profiles; the writer counts
     * every profile when it refuses a removal).
     */
    private static function program_counts() {
        $counts = array();
        foreach ((array) TeamOversight_Coaches::list_profiles('', '') as $row) {
            foreach (TeamOversight_Coaches::program_roles_of($row) as $key => $role) {
                $key = (string) $key;
                $counts[$key] = (isset($counts[$key]) ? $counts[$key] : 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * #editors (administrators only): who holds the Coach bios editor role,
     * Add by email or login, the accounts that lost the role outside this box
     * (Re-add or Leave off; nothing is re-added without a click) and the
     * bookmark. Names are decoded once and escaped once.
     */
    private static function render_editors_section() {
        self::$view['anchor'] = 'editors';
        $holders = get_users(array('role' => TeamOversight_Coaches::ROLE, 'fields' => array('ID', 'display_name'), 'orderby' => 'display_name'));
        $holders = is_array($holders) ? $holders : array();
        $lost = TeamOversight_Coaches::lost_editor_dates();
        $lost = is_array($lost) ? $lost : array();
        $bookmark = admin_url('admin.php?page=' . self::PAGE_PROFILES);

        echo '<h2 id="editors">Coach bios editors</h2>';
        echo '<p class="mc-text">People who hold the Coach bios editor role can add, change, publish and hide coach profiles, upload photos and use these settings. Administrators can always. Keep at least three people holding it.</p>';
        if (empty($holders)) {
            echo '<p class="mc-text"><strong>Nobody holds the role yet.</strong></p>';
        } else {
            echo '<div class="mc-scroll"><table class="widefat striped mc-editors"><tbody>';
            foreach ($holders as $holder) {
                $uid = (int) $holder->ID;
                $fid = self::row_form('editor_remove', $uid, array(), 'Remove the Coach bios editor role from this account? Its other roles stay as they are.');
                echo '<tr><td>' . self::h(self::plain_name($holder->display_name)) . ' <span class="mc-muted">(user #' . $uid . ')</span></td>'
                    . '<td class="mc-nowrap">' . self::button('Remove', $fid) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mc-bar">';
        echo self::hidden_fields('editor_add');
        echo '<label for="mc-editor-who">Add by email or login</label> <input type="text" class="regular-text" id="mc-editor-who" name="who" value="" autocomplete="off"> '
            . '<button type="submit" class="button">Add</button></form>';
        echo '<p class="description">Don\'t use the Role dropdown on the Users screen: it replaces the person\'s member roles. Saving a holder\'s Users → Edit screen for any other reason (fixing an email) drops this role too; it then shows below.</p>';

        if (!empty($lost)) {
            echo '<h3>Lost the role outside this box</h3>';
            echo '<p class="mc-text">These accounts held the role and lost it some other way. Re-add gives it back; Leave off records that it was meant and stops To do asking.</p>';
            echo '<div class="mc-scroll"><table class="widefat striped"><tbody>';
            // lost_editor_dates(): account id => its newest editor history row's time (the accounts are cached).
            foreach ($lost as $uid => $at) {
                $uid = (int) $uid;
                $user = $uid > 0 ? get_userdata($uid) : false;
                if (!$user) {
                    continue;
                }
                $readd = self::row_form('editor_readd', $uid);
                $off = self::row_form('editor_lost_ok', $uid, array(), 'Leave the Coach bios editor role off this account? Nothing changes, and To do stops asking.');
                echo '<tr><td>' . self::h(self::plain_name($user->display_name)) . ' <span class="mc-muted">(user #' . $uid . ')</span></td>'
                    . '<td class="mc-nowrap">' . self::h(is_string($at) && $at !== '' ? mysql2date('j M Y', $at) : '') . '</td>'
                    . '<td class="mc-nowrap">' . self::button('Re-add', $readd, 'button button-primary') . ' ' . self::button('Leave off', $off) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<h3>The bookmark to send them</h3>';
        echo '<p class="mc-bar"><label class="screen-reader-text" for="mc-bookmark">Bookmark</label><input type="text" class="mc-copy" id="mc-bookmark" value="' . esc_attr($bookmark) . '" readonly> '
            . '<button type="button" class="button" data-mc-copy="mc-bookmark">Copy</button></p>';
        echo '<p class="description">Editors don\'t see the admin bar on the website, so this is how they get here. An account holding this role can\'t be merged away until the role is removed.</p>';
    }

    // ------------------------------------------------------------------
    // Styles and scripts
    // ------------------------------------------------------------------

    private static function styles() {
        ?>
        <style>
            .murvc-coaches-admin [hidden] { display: none !important; }
            .murvc-coaches-admin .mc-text, .murvc-coaches-admin .mc-box, .murvc-coaches-admin .mc-form, .murvc-coaches-admin p.description { max-width: 900px; }
            .murvc-coaches-admin .mc-scroll { overflow-x: auto; }
            .murvc-coaches-admin table.widefat td, .murvc-coaches-admin table.widefat th { vertical-align: top; }
            .murvc-coaches-admin .mc-box { background: #fff; border: 1px solid #c3c4c7; padding: 10px 14px; margin: 14px 0; box-sizing: border-box; }
            .murvc-coaches-admin .mc-box h2 { margin: 4px 0 8px; font-size: 1.15em; }
            .murvc-coaches-admin details.mc-box > summary { cursor: pointer; font-weight: 600; }
            .murvc-coaches-admin .mc-howto h3 { margin: 16px 0 4px; font-size: 1em; }
            .murvc-coaches-admin dl.mc-text dt { font-weight: 600; margin-top: 8px; }
            .murvc-coaches-admin dl.mc-text dd { margin: 0 0 0 16px; }
            .murvc-coaches-admin .mc-heading { margin: 22px 0 0; }
            .murvc-coaches-admin .mc-consent { border-left: 4px solid #2271b1; }
            .murvc-coaches-admin .mc-pill-cell { width: 62px; }
            .murvc-coaches-admin .mc-pill { display: inline-block; min-width: 46px; text-align: center; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; }
            .murvc-coaches-admin .mc-pill-ok { background: #00a32a; }
            .murvc-coaches-admin .mc-pill-warn { background: #dba617; }
            .murvc-coaches-admin .mc-pill-bad { background: #d63638; }
            .murvc-coaches-admin .mc-muted { color: #50575e; }
            .murvc-coaches-admin .mc-bad-text { color: #d63638; }
            .murvc-coaches-admin .mc-good-text { color: #00a32a; }
            .murvc-coaches-admin .mc-nowrap { white-space: nowrap; }
            .murvc-coaches-admin .mc-forms form { display: inline; margin: 0; }
            .murvc-coaches-admin .mc-lines { list-style: disc; margin: 0 0 8px 20px; }
            .murvc-coaches-admin .mc-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0; }
            .murvc-coaches-admin .mc-listbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; margin: 8px 0; }
            .murvc-coaches-admin .mc-listbar .subsubsub { float: none; margin: 0; }
            .murvc-coaches-admin .mc-list .column-primary { min-width: 160px; }
            .murvc-coaches-admin tr:focus-within .row-actions { position: static; left: auto; }
            .murvc-coaches-admin .button-link.mc-danger { color: #d63638; }
            .murvc-coaches-admin .mc-hint { display: block; margin-top: 4px; }
            .murvc-coaches-admin .mc-count { display: block; margin-top: 2px; font-size: 12px; color: #50575e; }
            .murvc-coaches-admin .mc-count.mc-over { color: #d63638; font-weight: 600; }
            .murvc-coaches-admin .mc-highlight { display: block; margin-bottom: 6px; }
            .murvc-coaches-admin .mc-program { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 6px; }
            .murvc-coaches-admin .mc-program label { min-width: 180px; }
            .murvc-coaches-admin .mc-photo img { width: 96px; height: 96px; object-fit: cover; border-radius: 50%; display: block; background: #f0f0f1; }
            .murvc-coaches-admin .mc-status { margin: 14px 0 2px; font-weight: 600; }
            .murvc-coaches-admin .mc-actions .button { margin: 0 6px 6px 0; }
            .murvc-coaches-admin .mc-history .mc-old { white-space: pre-wrap; margin: 4px 0; }
            .murvc-coaches-admin input.mc-copy { width: 100%; max-width: 520px; }
        </style>
        <?php
    }

    /** The editor's script: the counters, Other's text box, and the media picker. Values and textContent only. */
    private static function edit_script() {
        ?>
        <script>
            (function () {
                function byId(id) { return document.getElementById(id); }
                function chars(s) { return Array.from ? Array.from(s).length : s.length; }

                var intro = byId('mc-intro'), introCount = byId('mc-intro-count');
                if (intro && introCount) {
                    var max = parseInt(intro.getAttribute('data-max'), 10) || 0;
                    var showIntro = function () {
                        var n = chars(intro.value);
                        introCount.textContent = n + ' / ' + max;
                        introCount.classList.toggle('mc-over', max > 0 && n > max);
                    };
                    intro.addEventListener('input', showIntro);
                    showIntro();
                }

                var bio = byId('mc-bio'), bioCount = byId('mc-bio-count');
                if (bio && bioCount) {
                    var aim = parseInt(bio.getAttribute('data-aim'), 10) || 0;
                    var showBio = function () {
                        var text = bio.value.trim();
                        var n = text === '' ? 0 : text.split(/\s+/).length;
                        bioCount.textContent = n + (n === 1 ? ' word' : ' words') + ' (aim for about ' + aim + ')';
                    };
                    bio.addEventListener('input', showBio);
                    showBio();
                }

                var acc = byId('mc-accreditation'), other = byId('mc-accreditation-other');
                if (acc && other) {
                    var showOther = function () { other.hidden = acc.value !== 'other'; };
                    acc.addEventListener('change', showOther);
                    showOther();
                }

                var choose = byId('mc-photo-choose'), remove = byId('mc-photo-remove'), field = byId('mc-photo-id'),
                    img = byId('mc-photo-img'), none = byId('mc-photo-none'), cleared = byId('mc-photo-cleared'), frame = null;
                if (!choose || !field) { return; }
                // Another photo un-ticks Photo cleared; the box's value is always the photo it would clear.
                var setPhoto = function (id, url) {
                    if (String(id) !== field.value && cleared) { cleared.checked = false; }
                    field.value = String(id);
                    if (cleared) { cleared.value = String(id); cleared.disabled = !id; if (!id) { cleared.checked = false; } }
                    if (img) {
                        if (id && url) { img.src = url; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
                    }
                    if (none) { none.hidden = !!id; }
                    if (remove) { remove.hidden = !id; }
                    var gone = byId('mc-photo-gone');
                    if (gone) { gone.hidden = true; }
                };
                choose.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (!window.wp || !window.wp.media) { return; }
                    if (!frame) {
                        frame = window.wp.media({ title: 'Choose photo', library: { type: 'image' }, multiple: false });
                        frame.on('select', function () {
                            var picked = frame.state().get('selection').first();
                            if (!picked) { return; }
                            var a = picked.toJSON();
                            var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
                            setPhoto(parseInt(a.id, 10) || 0, url);
                        });
                    }
                    frame.open();
                });
                if (remove) {
                    remove.addEventListener('click', function (e) { e.preventDefault(); setPhoto(0, ''); });
                }
            })();
        </script>
        <?php
    }

    /** The settings script: the Copy buttons (navigator.clipboard, falling back to selecting the text). */
    private static function settings_script() {
        ?>
        <script>
            (function () {
                var buttons = document.querySelectorAll('.murvc-coaches-admin [data-mc-copy]');
                Array.prototype.forEach.call(buttons, function (button) {
                    button.addEventListener('click', function () {
                        var input = document.getElementById(button.getAttribute('data-mc-copy'));
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

    /** A display name as plain text. Names are stored entity-encoded (& as &amp;): decode once, then the caller escapes once. */
    private static function plain_name($text) {
        return wp_specialchars_decode((string) $text, ENT_QUOTES);
    }

    /** Who made a change, as plain text: $none for the daily job (no account). */
    private static function who($user_id, $none = 'Daily check') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return $none;
        }
        $user = get_userdata($user_id);
        return $user ? self::plain_name($user->display_name) : 'an account that no longer exists';
    }

    /** One column of a row the core class returned (an object or an array). */
    private static function v($row, $key, $default = '') {
        if (is_array($row)) {
            return isset($row[$key]) ? $row[$key] : $default;
        }
        if (is_object($row)) {
            return isset($row->$key) ? $row->$key : $default;
        }
        return $default;
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

    /** May this person use the Editors box? (manage_options and promote_users; the current user by default.) */
    private static function can_manage_editors($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        return $user_id > 0 && user_can($user_id, 'manage_options') && user_can($user_id, 'promote_users');
    }

    /** The season the To do panel and the role lines look at: the website season, else this year. */
    private static function focus_season() {
        $season = (string) TeamOversight_Coaches::website_season();
        return TeamOversight_Coaches::valid_season($season) ? $season : current_time('Y');
    }

    /** A posted id ('0' allowed) checked with /^\d{1,10}$/D, or null. */
    private static function posted_id(array $data, $key) {
        if (!isset($data[$key]) || !is_scalar($data[$key])) {
            return null;
        }
        $raw = (string) $data[$key];
        return preg_match('/^\d{1,10}$/D', $raw) === 1 ? (int) $raw : null;
    }

    /** Distinct positive ids from a posted list. */
    private static function id_list($raw) {
        $ids = array();
        foreach (is_array($raw) ? $raw : array() as $value) {
            if (is_scalar($value) && preg_match('/^\d{1,10}$/D', (string) $value) === 1 && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }
        return array_values($ids);
    }

    private static function current_page() {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return in_array($page, self::PAGES, true) ? $page : '';
    }

    private static function get_id($key) {
        $raw = isset($_GET[$key]) && is_string($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
        return preg_match('/^\d{1,10}$/D', $raw) === 1 ? (int) $raw : 0;
    }

    private static function get_filter() {
        $filter = isset($_GET['filter']) && is_string($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : '';
        return isset(self::FILTERS[$filter]) ? $filter : '';
    }

    private static function get_search() {
        return isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    }

    private static function page_url($slug, array $args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . $slug));
    }

    private static function edit_url($id) {
        return self::page_url(self::PAGE_EDIT, array('profile' => (int) $id));
    }
}
