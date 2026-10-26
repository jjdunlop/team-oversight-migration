<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Membership → Duplicate accounts (1.71.0): the suspected duplicates
 * (TeamOversight_Duplicates), a preview of each merge, the merge itself
 * and its undo (TeamOversight_Account_Merge), and the history.
 * Every POST is admin-post with a nonce and manage_options, then
 * Post/Redirect/Get with a flash message.
 */
class TeamOversight_Duplicates_Page {

    const PAGE_SLUG = 'club-membership-duplicates';
    const PER_PAGE = 20;

    private static $hooks_added = false;

    /** What was typed in the search box; every link in the lists keeps it. */
    private static $find = '';

    public static function register_hooks() {
        if (self::$hooks_added) {
            return;
        }
        self::$hooks_added = true;
        add_action('admin_post_murvc_account_merge', array(__CLASS__, 'handle_merge'));
        add_action('admin_post_murvc_account_merge_undo', array(__CLASS__, 'handle_undo'));
        add_action('admin_post_murvc_dup_dismiss', array(__CLASS__, 'handle_dismiss'));
        add_action('admin_post_murvc_dup_undismiss', array(__CLASS__, 'handle_undismiss'));
    }

    public static function menu_title() {
        $n = class_exists('TeamOversight_Duplicates') ? TeamOversight_Duplicates::new_count() : 0;
        return 'Duplicate accounts' . ($n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '');
    }

    private static function url($args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_SLUG));
    }

    private static function list_url($args = array()) {
        if (self::$find !== '') {
            $args['find'] = rawurlencode(self::$find);
        }
        return self::url($args);
    }

    private static function find_from($raw) {
        return substr(sanitize_text_field(wp_unslash((string) $raw)), 0, 100);
    }

    /** The groups (each with 'ids') that have at least one account matching the search. */
    private static function matching($groups, $q) {
        $ids = array();
        foreach ($groups as $g) {
            $ids = array_merge($ids, $g['ids']);
        }
        $entries = TeamOversight_Duplicates::search_entries($ids);
        return array_values(array_filter($groups, function ($g) use ($entries, $q) {
            foreach ($g['ids'] as $id) {
                if (isset($entries[$id]) && TeamOversight_Duplicates::search_matches($entries[$id], $q)) {
                    return true;
                }
            }
            return false;
        }));
    }

    private static function flash($type, $message) {
        set_transient('murvc_dup_flash_' . get_current_user_id(), array($type, $message), 5 * MINUTE_IN_SECONDS);
    }

    private static function show_flash() {
        $key = 'murvc_dup_flash_' . get_current_user_id();
        $f = get_transient($key);
        if (is_array($f)) {
            delete_transient($key);
            echo '<div class="notice notice-' . esc_attr($f[0]) . ' is-dismissible"><p>' . esc_html($f[1]) . '</p></div>';
        }
    }

    private static function ids_from($raw) {
        if (!is_array($raw)) {
            $raw = preg_split('/[\s,#]+/', (string) $raw);
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    public static function handle_merge() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $keep = isset($_POST['keep']) ? (int) $_POST['keep'] : 0;
        $merge = self::ids_from(isset($_POST['merge']) ? wp_unslash($_POST['merge']) : array());
        check_admin_referer('murvc_account_merge_' . $keep . '_' . implode('-', $merge));
        $confirm = isset($_POST['confirm']) ? array_map('sanitize_key', (array) wp_unslash($_POST['confirm'])) : array();
        $hash = isset($_POST['hash']) ? sanitize_key(wp_unslash($_POST['hash'])) : '';
        $result = TeamOversight_Account_Merge::apply($keep, $merge, $confirm, $hash);
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
            wp_safe_redirect(self::url(array('view' => 'preview', 'keep' => $keep, 'merge' => implode(',', $merge))));
            exit;
        }
        self::flash('success', 'Merged. Account(s) #' . implode(', #', $merge) . " are now part of #$keep.");
        wp_safe_redirect(self::url(array('view' => 'merge', 'id' => $result['id'])));
        exit;
    }

    public static function handle_undo() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('murvc_account_merge_undo_' . $id);
        $result = TeamOversight_Account_Merge::undo($id);
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
        } else {
            self::flash('success', sprintf('Undone: %d change(s) put back%s.', $result['restored'],
                $result['skipped'] ? '; ' . count($result['skipped']) . ' had changed since and were left as they are (listed below)' : ''));
        }
        wp_safe_redirect(self::url(array('view' => 'merge', 'id' => $id)));
        exit;
    }

    public static function handle_dismiss() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('murvc_dup_dismiss');
        $ids = self::ids_from(isset($_POST['ids']) ? wp_unslash($_POST['ids']) : array());
        $reason = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : 'other';
        if (count($ids) >= 2) {
            TeamOversight_Duplicates::dismiss($ids, $reason);
            self::flash('success', 'Marked #' . implode(', #', $ids) . ' as different people. They won\'t be suggested again.');
        }
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'strong';
        self::$find = isset($_POST['find']) ? self::find_from($_POST['find']) : '';
        wp_safe_redirect(self::list_url(array('tab' => $tab)));
        exit;
    }

    public static function handle_undismiss() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('murvc_dup_undismiss');
        $ids = self::ids_from(isset($_POST['ids']) ? wp_unslash($_POST['ids']) : array());
        TeamOversight_Duplicates::undismiss($ids);
        self::flash('success', 'Accounts #' . implode(', #', $ids) . ' can be suggested again.');
        self::$find = isset($_POST['find']) ? self::find_from($_POST['find']) : '';
        wp_safe_redirect(self::list_url(array('tab' => 'dismissed')));
        exit;
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        echo '<div class="wrap murvc-dups">';
        self::styles();
        if ($view === 'preview') {
            self::render_preview();
        } elseif ($view === 'merge') {
            self::render_merge();
        } else {
            self::render_tabs();
        }
        echo '</div>';
    }

    private static function styles() {
        ?>
        <style>
            .murvc-dups .dup-group { background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; margin: 0 0 16px; padding: 12px 14px; }
            .murvc-dups .dup-group.tier-likely { border-left-color: #dba617; }
            .murvc-dups .dup-group.tier-possible { border-left-color: #8c8f94; }
            .murvc-dups .dup-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
            .murvc-dups .chip { display: inline-block; padding: 1px 8px; border-radius: 10px; background: #f0f0f1; font-size: 12px; }
            .murvc-dups .chip.new { background: #d63638; color: #fff; }
            .murvc-dups .chip.flag { background: #fcf0d1; }
            .murvc-dups .chip.bad { background: #facfd2; }
            .murvc-dups table.dup-table { width: 100%; border-collapse: collapse; }
            .murvc-dups table.dup-table th, .murvc-dups table.dup-table td { text-align: left; padding: 5px 8px; border-top: 1px solid #f0f0f1; vertical-align: top; font-size: 13px; }
            .murvc-dups table.dup-table tr.suggested td { background: #f0f6fc; }
            .murvc-dups .muted { color: #646970; }
            .murvc-dups .dup-actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
            .murvc-dups .box { background: #fff; border: 1px solid #dcdcde; padding: 12px 16px; margin: 0 0 14px; }
            .murvc-dups .box h3 { margin-top: 0; }
            .murvc-dups .blocker { border-left: 4px solid #d63638; }
            .murvc-dups .confirm { border-left: 4px solid #dba617; }
            .murvc-dups ul.tight { margin: 4px 0 0 18px; list-style: disc; }
            .murvc-dups .dup-search { margin: 12px 0 4px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
            .murvc-dups .dup-search input[type=search] { width: 320px; max-width: 100%; }
        </style>
        <?php
    }

    private static function render_tabs() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'strong';
        $tabs = array('strong' => 'Strong', 'likely' => 'Likely', 'possible' => 'Possible', 'dismissed' => 'Not duplicates', 'history' => 'Merge history', 'manual' => 'Merge by ID');
        if (!isset($tabs[$tab])) {
            $tab = 'strong';
        }
        self::$find = isset($_GET['find']) ? self::find_from($_GET['find']) : '';
        $q = TeamOversight_Duplicates::parse_search(self::$find);
        $scan = in_array($tab, array('strong', 'likely', 'possible'), true) ? TeamOversight_Duplicates::scan(!empty($_GET['rescan'])) : null;
        $all = $scan ? $scan['groups'] : array();
        if ($scan && $q) {
            $all = self::matching($all, $q);
        }
        echo '<h1>Duplicate accounts</h1>';
        self::show_flash();
        echo '<p class="muted">People who seem to have more than one account. Pick the account that lives on and preview the merge: nothing changes until you press Merge, and every merge can be undone from Merge history.</p>';

        $form_tab = $tab === 'manual' ? 'strong' : $tab;
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="dup-search" role="search">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="hidden" name="tab" value="' . esc_attr($form_tab) . '">';
        echo '<label for="dup-find" class="screen-reader-text">Search accounts</label>';
        echo '<input type="search" id="dup-find" name="find" value="' . esc_attr(self::$find) . '" placeholder="Name, email, mobile or account number">';
        echo '<button class="button">Search</button>';
        if (self::$find !== '') {
            echo '<a href="' . esc_url(self::url(array('tab' => $form_tab))) . '">Clear search</a>';
        }
        echo '</form>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            $count = '';
            if ($scan && in_array($k, array('strong', 'likely', 'possible'), true)) {
                $count = ' (' . count(array_filter($all, function ($g) use ($k) {
                    return $g['tier'] === $k;
                })) . ')';
            }
            $href = $k === 'manual' ? self::url(array('tab' => $k)) : self::list_url(array('tab' => $k));
            echo '<a class="nav-tab' . ($k === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url($href) . '">' . esc_html($label . $count) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'dismissed') {
            self::render_dismissed();
            return;
        }
        if ($tab === 'history') {
            self::render_history();
            return;
        }
        if ($tab === 'manual') {
            self::render_manual();
            return;
        }

        $explain = array(
            'strong' => 'Same name and date of birth, or same name and a shared phone, email or student number.',
            'likely' => 'Near names (Sam and Samuel, a typo, swapped order) with the same birth date, or the same name with a near birth date and something shared. Check these.',
            'possible' => 'Only the name matches. Many will be different people with a common name.',
        );
        echo '<p>' . esc_html($explain[$tab]) . ' <a href="' . esc_url(self::list_url(array('tab' => $tab, 'rescan' => 1))) . '">Look again now</a> <span class="muted">(checked ' . esc_html(human_time_diff($scan['scanned_at'])) . ' ago, ' . (int) $scan['accounts'] . ' accounts)</span></p>';

        $groups = array_values(array_filter($all, function ($g) use ($tab) {
            return $g['tier'] === $tab;
        }));
        $total = count($groups);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min($pages, max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1));
        $groups = array_slice($groups, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        if (!$groups) {
            if ($q) {
                echo '<p><strong>No ' . esc_html(strtolower($tabs[$tab])) . ' group has an account matching &ldquo;' . esc_html(self::$find) . '&rdquo;.</strong> The tab counts show where the matches are. If the person has accounts the list never suggested, use <a href="' . esc_url(self::url(array('tab' => 'manual'))) . '">Merge by ID</a>.</p>';
            } else {
                echo '<p><strong>Nothing to look at here.</strong></p>';
            }
            return;
        }
        if ($q) {
            echo '<p><strong>' . (int) $total . ' ' . ($total === 1 ? 'group has' : 'groups have') . ' an account matching &ldquo;' . esc_html(self::$find) . '&rdquo;.</strong></p>';
        }
        $ids = array();
        foreach ($groups as $g) {
            $ids = array_merge($ids, $g['ids']);
        }
        $details = TeamOversight_Duplicates::details($ids);
        $since = time() - TeamOversight_Duplicates::NEW_DAYS * DAY_IN_SECONDS;
        foreach ($groups as $g) {
            self::render_group($g, $details, $tab, $g['first_seen'] >= $since);
        }
        if ($pages > 1) {
            echo '<p>';
            for ($p = 1; $p <= $pages; $p++) {
                echo $p === $paged ? '<strong>' . $p . '</strong> ' : '<a href="' . esc_url(self::list_url(array('tab' => $tab, 'paged' => $p))) . '">' . $p . '</a> ';
            }
            echo '</p>';
        }
    }

    private static function date_or($ts, $none = 'never') {
        return $ts ? wp_date('j M Y', $ts) : $none;
    }

    private static function render_group($g, $details, $tab, $is_new) {
        $statuses = array('awaiting_email_confirmation' => 'never confirmed email', 'awaiting_admin_review' => 'awaiting review', 'inactive' => 'deactivated', 'rejected' => 'rejected', 'checkmail' => 'never confirmed email');
        echo '<div class="dup-group tier-' . esc_attr($g['tier']) . '">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="hidden" name="view" value="preview">';
        echo '<div class="dup-head"><strong>' . esc_html(ucfirst($g['tier'])) . '</strong>';
        if ($is_new) {
            echo '<span class="chip new">New</span>';
        }
        foreach ($g['reasons'] as $r) {
            echo '<span class="chip">' . esc_html($r) . '</span>';
        }
        foreach ($g['flags'] as $f) {
            echo '<span class="chip flag">' . esc_html($f) . '</span>';
        }
        echo '</div>';
        echo '<table class="dup-table"><thead><tr><th>Keep</th><th>Include</th><th>Account</th><th>Born / mobile</th><th>Logs in with</th><th>Joined / last seen</th><th>Orders</th><th>Club</th></tr></thead><tbody>';
        foreach ($g['ids'] as $id) {
            if (!isset($details[$id])) {
                continue;
            }
            $d = $details[$id];
            $u = $d['user'];
            $suggested = (int) $g['suggested'] === (int) $id;
            echo '<tr' . ($suggested ? ' class="suggested"' : '') . '>';
            echo '<td><input type="radio" name="keep" value="' . (int) $id . '"' . checked($suggested, true, false) . ' aria-label="Keep #' . (int) $id . '"></td>';
            echo '<td><input type="checkbox" name="merge[]" value="' . (int) $id . '" checked aria-label="Include #' . (int) $id . '"></td>';
            $lookup = class_exists('TeamOversight_Member_Lookup') ? TeamOversight_Member_Lookup::profile_url($id) : get_edit_user_link($id);
            echo '<td><a href="' . esc_url($lookup) . '">#' . (int) $id . ' ' . esc_html($u->display_name) . '</a>';
            if ($suggested) {
                echo '<br><span class="chip">suggested: most recently used</span>';
            }
            if (isset($statuses[$d['status']])) {
                echo '<br><span class="chip bad">' . esc_html($statuses[$d['status']]) . '</span>';
            }
            $staff = array_intersect((array) $u->roles, array('administrator', 'editor', 'shop_manager', 'author', 'contributor'));
            if ($staff) {
                echo '<br><span class="chip bad">staff: ' . esc_html(implode(', ', $staff)) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($d['dob'] !== '' ? $d['dob'] : '-') . '<br><span class="muted">' . esc_html($d['mobile'] !== '' ? $d['mobile'] : '-') . '</span></td>';
            echo '<td>' . esc_html($u->user_email);
            if (strcasecmp($u->user_login, $u->user_email) !== 0) {
                echo '<br><span class="muted">or ' . esc_html($u->user_login) . '</span>';
            }
            if ($d['billing_email'] !== '' && strcasecmp($d['billing_email'], $u->user_email) !== 0) {
                echo '<br><span class="muted">billing ' . esc_html($d['billing_email']) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html(wp_date('j M Y', strtotime($u->user_registered . ' UTC'))) . '<br><span class="muted">seen ' . esc_html(self::date_or($d['last_seen'])) . '</span></td>';
            echo '<td>' . (int) $d['orders'] . ($d['last_order'] ? '<br><span class="muted">last ' . esc_html(mysql2date('j M Y', $d['last_order'])) . '</span>' : '') . '</td>';
            echo '<td>' . esc_html(implode('; ', $d['club']) ?: '-') . ($d['tier'] ? '<br><span class="muted">' . esc_html(str_replace('-', ' ', $d['tier'])) . '</span>' : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<div class="dup-actions"><button class="button button-primary">Preview merge</button> <span class="muted">Untick an account to leave it out.</span></div></form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dup-actions" onsubmit="return confirm(\'Mark these accounts as different people? They will not be suggested again.\');">';
        wp_nonce_field('murvc_dup_dismiss');
        echo '<input type="hidden" name="action" value="murvc_dup_dismiss"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="find" value="' . esc_attr(self::$find) . '">';
        foreach ($g['ids'] as $id) {
            echo '<input type="hidden" name="ids[]" value="' . (int) $id . '">';
        }
        echo '<select name="reason"><option value="same_name">Different people, same name</option><option value="twins_siblings">Twins or siblings</option><option value="parent_child">Parent and child</option><option value="other">Other</option></select> ';
        echo '<button class="button">Not the same person</button></form>';
        echo '</div>';
    }

    private static function render_preview() {
        $keep = isset($_GET['keep']) ? (int) $_GET['keep'] : 0;
        $merge = self::ids_from(isset($_GET['merge']) ? wp_unslash($_GET['merge']) : array());
        $merge = array_values(array_diff($merge, array($keep)));
        echo '<h1>Merge preview</h1>';
        self::show_flash();
        echo '<p><a href="' . esc_url(self::url()) . '">&laquo; Back to duplicate accounts</a></p>';
        if (!$keep) {
            echo '<div class="notice notice-error"><p>Choose the account to keep (the Keep button).</p></div>';
            return;
        }
        $plan = TeamOversight_Account_Merge::plan($keep, $merge);
        if (is_wp_error($plan)) {
            echo '<div class="notice notice-error"><p>' . esc_html($plan->get_error_message()) . '</p></div>';
            return;
        }
        $k = $plan['keep'];
        echo '<div class="box"><h3>The account that lives on</h3><p><strong>#' . (int) $k['id'] . ' ' . esc_html($k['name']) . '</strong>: they log in with <strong>' . esc_html($k['email']) . '</strong>';
        if (strcasecmp($k['login'], $k['email']) !== 0) {
            echo ' (or <strong>' . esc_html($k['login']) . '</strong>)';
        }
        echo ' and its current password.</p><p>Merged into it and then deleted: ';
        $names = array();
        foreach ($plan['merge'] as $m) {
            $names[] = '#' . (int) $m['id'] . ' ' . esc_html($m['name']) . ' (' . esc_html($m['email']) . ')';
        }
        echo implode(', ', $names) . '. Those logins stop working.</p></div>';

        if ($plan['blockers']) {
            echo '<div class="box blocker"><h3>Can\'t merge yet</h3><ul class="tight">';
            foreach ($plan['blockers'] as $b) {
                echo '<li>' . esc_html($b) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<div class="box"><h3>What moves to #' . (int) $k['id'] . '</h3>';
        if ($plan['moves']) {
            echo '<ul class="tight">';
            foreach ($plan['moves'] as $label => $n) {
                echo '<li>' . esc_html($label) . ': ' . (int) $n . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="muted">No orders or club records on the other account(s).</p>';
        }
        echo '<p class="muted">Fees and payments are moved as they are: nothing is re-priced.</p></div>';

        $p = $plan['profile'];
        echo '<div class="box"><h3>Profile</h3>';
        if ($p['copied'] || $p['photo']) {
            echo '<p>Filled in where the kept account was empty:</p><ul class="tight">';
            foreach ($p['copied'] as $key => $v) {
                echo '<li>' . esc_html($key) . ': ' . esc_html($v) . '</li>';
            }
            if ($p['photo']) {
                echo '<li>' . esc_html($p['photo']) . '</li>';
            }
            echo '</ul>';
        }
        if ($p['merged']) {
            echo '<p>Combined:</p><ul class="tight">';
            foreach ($p['merged'] as $key => $v) {
                echo '<li>' . esc_html($key) . ': ' . esc_html($v) . '</li>';
            }
            echo '</ul>';
        }
        if ($p['conflicts']) {
            echo '<p>Different on the other account; the kept account\'s stays (edit the profile afterwards if the other is right):</p><ul class="tight">';
            foreach ($p['conflicts'] as $key => $pair) {
                echo '<li>' . esc_html($key) . ': <strong>' . esc_html($pair[0]) . '</strong> (kept), ' . esc_html($pair[1]) . '</li>';
            }
            echo '</ul>';
        }
        if (!$p['copied'] && !$p['merged'] && !$p['conflicts'] && !$p['photo']) {
            echo '<p class="muted">Nothing to copy: the kept account already has it all.</p>';
        }
        if ($p['not_copied']) {
            echo '<p class="muted">Other fields left behind (old forms; kept in the backup): ' . esc_html(implode(', ', $p['not_copied'])) . '</p>';
        }
        echo '<p>Roles: ' . esc_html(implode(', ', $plan['roles_before'])) . ' &rarr; <strong>' . esc_html(implode(', ', $plan['roles_after'])) . '</strong></p></div>';

        if ($plan['warnings']) {
            echo '<div class="box"><h3>Good to know</h3><ul class="tight">';
            foreach ($plan['warnings'] as $w) {
                echo '<li>' . esc_html($w) . '</li>';
            }
            echo '</ul></div>';
        }

        if ($plan['blockers']) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="box' . ($plan['confirm'] ? ' confirm' : '') . '">';
        wp_nonce_field('murvc_account_merge_' . (int) $k['id'] . '_' . implode('-', array_map(function ($m) {
            return (int) $m['id'];
        }, $plan['merge'])));
        echo '<input type="hidden" name="action" value="murvc_account_merge"><input type="hidden" name="keep" value="' . (int) $k['id'] . '"><input type="hidden" name="hash" value="' . esc_attr($plan['hash']) . '">';
        foreach ($plan['merge'] as $m) {
            echo '<input type="hidden" name="merge[]" value="' . (int) $m['id'] . '">';
        }
        foreach ($plan['confirm'] as $code => $text) {
            echo '<p><label><input type="checkbox" name="confirm[]" value="' . esc_attr($code) . '" required> ' . esc_html($text) . '</label></p>';
        }
        echo '<p><button class="button button-primary button-hero">Merge into #' . (int) $k['id'] . '</button> <span class="muted">A full backup is kept, and Merge history can undo it.</span></p></form>';
    }

    private static function render_merge() {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $m = TeamOversight_Account_Merge::get($id);
        echo '<h1>Merge record ' . (int) $id . '</h1>';
        self::show_flash();
        echo '<p><a href="' . esc_url(self::url(array('tab' => 'history'))) . '">&laquo; Merge history</a></p>';
        if (!$m) {
            echo '<p>Not found.</p>';
            return;
        }
        $s = is_array($m['summary']) ? $m['summary'] : array();
        $by = get_userdata((int) $m['created_by']);
        $kept = get_userdata((int) $m['kept_user_id']);
        echo '<div class="box"><p>' . esc_html(mysql2date('j M Y g:ia', $m['created_at'])) . ' by ' . esc_html($by ? $by->display_name : '#' . $m['created_by']) . ': accounts #' . esc_html(str_replace(',', ', #', $m['merged_user_ids'])) . ' merged into ';
        $lookup = class_exists('TeamOversight_Member_Lookup') ? TeamOversight_Member_Lookup::profile_url((int) $m['kept_user_id']) : '';
        echo '<a href="' . esc_url($lookup) . '">#' . (int) $m['kept_user_id'] . ($kept ? ' ' . esc_html($kept->display_name) : '') . '</a>.</p>';
        if ($m['status'] === 'undone') {
            $ub = get_userdata((int) $m['undone_by']);
            echo '<p><strong>Undone</strong> ' . esc_html(mysql2date('j M Y g:ia', $m['undone_at'])) . ' by ' . esc_html($ub ? $ub->display_name : '#' . $m['undone_by']) . '.</p>';
            if (!empty($s['undo_skipped'])) {
                echo '<p>Left as they were, because they had changed since the merge (check these by hand):</p><ul class="tight">';
                foreach ($s['undo_skipped'] as $item) {
                    echo '<li>' . esc_html($item) . '</li>';
                }
                echo '</ul>';
            }
        }
        if (!empty($s['moves'])) {
            echo '<ul class="tight">';
            foreach ($s['moves'] as $label => $n) {
                echo '<li>' . esc_html($label) . ': ' . (int) $n . '</li>';
            }
            echo '</ul>';
        }
        if (!empty($s['notes'])) {
            echo '<p>Notes:</p><ul class="tight">';
            foreach ($s['notes'] as $note) {
                echo '<li>' . esc_html($note) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        if ($m['status'] === 'done') {
            $why = TeamOversight_Account_Merge::undo_blocker($m);
            if ($why !== '') {
                echo '<div class="box"><p><strong>Undo isn\'t available now.</strong> ' . esc_html($why) . '</p></div>';
                return;
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="box" onsubmit="return confirm(\'Undo this merge? The deleted accounts come back with their orders and records.\');">';
            wp_nonce_field('murvc_account_merge_undo_' . (int) $id);
            echo '<input type="hidden" name="action" value="murvc_account_merge_undo"><input type="hidden" name="id" value="' . (int) $id . '">';
            echo '<p><button class="button">Undo this merge</button> <span class="muted">Brings the deleted accounts back and moves their records back. On the kept account, the details the merge filled in go back to how they were, except any changed since (those are left and listed).</span></p></form>';
        }
    }

    private static function render_history() {
        $q = TeamOversight_Duplicates::parse_search(self::$find);
        $limit = $q ? 2000 : 100;
        $rows = TeamOversight_Account_Merge::recent($limit);
        if (!$rows) {
            echo '<p>No merges yet.</p>';
            return;
        }
        $shown_all = count($rows) < $limit;
        if ($q) {
            // The kept account as it is now, and every account as it was at the merge.
            $now = TeamOversight_Duplicates::search_entries(wp_list_pluck($rows, 'kept_user_id'));
            $rows = array_values(array_filter($rows, function ($r) use ($q, $now) {
                $entries = isset($now[(int) $r['kept_user_id']]) ? array($now[(int) $r['kept_user_id']]) : array();
                foreach (explode(',', $r['kept_user_id'] . ',' . $r['merged_user_ids']) as $id) {
                    $p = isset($r['people'][(int) $id]) ? $r['people'][(int) $id] : array();
                    $entries[] = TeamOversight_Duplicates::search_entry($id, array(
                        isset($p['name']) ? $p['name'] : '', isset($p['email']) ? $p['email'] : '', isset($p['login']) ? $p['login'] : '',
                    ));
                }
                foreach ($entries as $e) {
                    if (TeamOversight_Duplicates::search_matches($e, $q)) {
                        return true;
                    }
                }
                return false;
            }));
            if (!$rows) {
                echo '<p><strong>No merge involves an account matching &ldquo;' . esc_html(self::$find) . '&rdquo;.</strong></p>';
                return;
            }
        }
        $name = function ($id, $r) {
            $u = get_userdata((int) $id);
            if ($u) {
                return '#' . (int) $id . ' ' . $u->display_name;
            }
            return '#' . (int) $id . (isset($r['people'][(int) $id]['name']) ? ' ' . $r['people'][(int) $id]['name'] : '');
        };
        echo '<table class="widefat striped"><thead><tr><th>Record</th><th>When</th><th>Kept</th><th>Merged in</th><th>By</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $by = get_userdata((int) $r['created_by']);
            $merged = array();
            foreach (explode(',', $r['merged_user_ids']) as $id) {
                $merged[] = $name($id, $r);
            }
            echo '<tr><td><a href="' . esc_url(self::url(array('view' => 'merge', 'id' => $r['id']))) . '">' . (int) $r['id'] . '</a></td>';
            echo '<td>' . esc_html(mysql2date('j M Y g:ia', $r['created_at'])) . '</td>';
            echo '<td>' . esc_html($name($r['kept_user_id'], $r)) . '</td>';
            echo '<td>' . esc_html(implode(', ', $merged)) . '</td>';
            echo '<td>' . esc_html($by ? $by->display_name : '#' . $r['created_by']) . '</td>';
            echo '<td>' . esc_html($r['status'] === 'undone' ? 'undone' : 'merged') . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!$shown_all) {
            echo '<p class="muted">' . ($q ? 'Searched the latest ' . (int) $limit . ' merges.' : 'Showing the latest ' . (int) $limit . ' merges. Search to find older ones.') . '</p>';
        }
    }

    private static function render_dismissed() {
        $groups = TeamOversight_Duplicates::dismissed_groups();
        if (!$groups) {
            echo '<p>Nothing marked yet. "Not the same person" on a group puts it here.</p>';
            return;
        }
        $q = TeamOversight_Duplicates::parse_search(self::$find);
        if ($q) {
            $groups = self::matching($groups, $q);
            if (!$groups) {
                echo '<p><strong>Nothing marked has an account matching &ldquo;' . esc_html(self::$find) . '&rdquo;.</strong></p>';
                return;
            }
        }
        $labels = array('same_name' => 'different people, same name', 'twins_siblings' => 'twins or siblings', 'parent_child' => 'parent and child', 'other' => 'other');
        echo '<table class="widefat striped"><thead><tr><th>Accounts</th><th>Why</th><th>When</th><th></th></tr></thead><tbody>';
        foreach ($groups as $g) {
            $names = array();
            foreach ($g['ids'] as $id) {
                $u = get_userdata($id);
                $names[] = '#' . (int) $id . ($u ? ' ' . esc_html($u->display_name) : '');
            }
            echo '<tr><td>' . implode(', ', $names) . '</td><td>' . esc_html(isset($labels[$g['reason']]) ? $labels[$g['reason']] : $g['reason']) . '</td><td>' . esc_html(mysql2date('j M Y', $g['at'])) . '</td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('murvc_dup_undismiss');
            echo '<input type="hidden" name="action" value="murvc_dup_undismiss"><input type="hidden" name="find" value="' . esc_attr(self::$find) . '">';
            foreach ($g['ids'] as $id) {
                echo '<input type="hidden" name="ids[]" value="' . (int) $id . '">';
            }
            echo '<button class="button-link">Suggest again</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_manual() {
        echo '<p>For duplicates the list didn\'t find. Account numbers are on Member Lookup and Users.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="box">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="hidden" name="view" value="preview">';
        echo '<p><label>Account to keep (number) <input type="number" name="keep" min="1" required></label></p>';
        echo '<p><label>Accounts to merge into it (numbers, separated by commas) <input type="text" name="merge" required pattern="[0-9 ,#]+"></label></p>';
        echo '<p><button class="button button-primary">Preview merge</button></p></form>';
    }
}
