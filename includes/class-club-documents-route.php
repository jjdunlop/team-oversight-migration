<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club documents (1.90.0): the permanent addresses /documents/<name>/, the
 * old upload addresses that redirect to them, the refusal pages, and the
 * streaming of the files.
 *
 * Every answer comes from delivery_plan() (pure apart from reading the
 * document, its current version and, for a refusal, the account's
 * membership), and every access answer from the registry helpers of
 * TeamOversight_Club_Documents (can_open(), is_listed(), is_public()):
 * this class never reads the audiences column itself. serve() does the
 * I/O. The plan, in order:
 *   1. unknown, a name that fails the pattern, or a draft: WordPress's own
 *      404;
 *   2. not listed (only boxes the page doesn't list are ticked, or none)
 *      and the viewer isn't let in: exactly step 1's answer, so the address
 *      never confirms that such a document exists;
 *   3. not the canonical address (the trailing slash, no query string at
 *      all): 301 to it (only once the rule exists);
 *   4. withdrawn: 410;
 *   5. not let in and logged out: 302 to the login page, back here after;
 *   6. not let in and logged in: 403 saying who it is for and the
 *      account's membership;
 *   7. the current file missing from storage: 503 naming the contact email;
 *   8. the file.
 * A public file is cacheable (10 minutes) only at the exact canonical
 * address and only while something listens on PURGE_ACTION; every answer
 * sends its own Expires; a restricted answer is never cacheable. Nothing
 * records who opened what.
 */
class TeamOversight_Club_Documents_Route {

    /** admin-post action for opening any version from the admin screens (CAP and a nonce per version). */
    const FILE_ACTION = 'murvc_document_file';
    /** Set for an hour when a flush didn't put the rule in, so a site that can't hold it isn't flushed on every request. */
    const FLUSH_TRIED = 'murvc_documents_flush_tried';
    const CHUNK = 1048576;
    const PAST = 'Wed, 11 Jan 1984 05:00:00 GMT';

    /** Add the hooks once (team_oversight_init() calls it). */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('init', array(__CLASS__, 'add_rule'), 10);
        add_action('init', array(__CLASS__, 'maybe_flush'), 99);
        add_action('parse_request', array(__CLASS__, 'handle_request'), 0);
        add_action('admin_post_' . self::FILE_ACTION, array(__CLASS__, 'admin_file'));
    }

    /** init: the query var and the rule /documents/<name>/. */
    public static function add_rule() {
        add_rewrite_tag('%' . TeamOversight_Club_Documents::QUERY_VAR . '%', '([a-z0-9-]+)');
        add_rewrite_rule(TeamOversight_Club_Documents::RULE_REGEX, 'index.php?' . TeamOversight_Club_Documents::QUERY_VAR . '=$matches[1]', 'top');
    }

    /**
     * init, priority 99: the deploy never runs the activation hook, so the
     * rules are flushed here once per RULE_VERSION, or whenever the rule's
     * key is missing from the stored rules (a rollback and a Permalinks
     * save), at most once an hour while a flush can't put it in. Nothing
     * while the site uses plain links (the query form works without a
     * rule).
     */
    public static function maybe_flush() {
        if ((string) get_option('permalink_structure') === '') {
            return;
        }
        $flag = get_option(TeamOversight_Club_Documents::REWRITE_OPTION);
        $present = TeamOversight_Club_Documents::rule_present();
        if ($present && $flag === TeamOversight_Club_Documents::RULE_VERSION) {
            return;
        }
        if (!$present && $flag === TeamOversight_Club_Documents::RULE_VERSION && get_transient(self::FLUSH_TRIED)) {
            return;
        }
        flush_rewrite_rules(false);
        update_option(TeamOversight_Club_Documents::REWRITE_OPTION, TeamOversight_Club_Documents::RULE_VERSION, true);
        if (!TeamOversight_Club_Documents::rule_present()) {
            set_transient(self::FLUSH_TRIED, 1, HOUR_IN_SECONDS);
        }
    }

    /**
     * parse_request, priority 0: before the main query and every
     * template_redirect hook (Ultimate Member's content restriction
     * included). Never in admin, AJAX, cron, REST or WP-CLI.
     */
    public static function handle_request($wp) {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!is_object($wp) || !isset($wp->query_vars) || !is_array($wp->query_vars) || !empty($wp->query_vars['rest_route'])) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $var = TeamOversight_Club_Documents::QUERY_VAR;
        $slug = isset($wp->query_vars[$var]) && is_scalar($wp->query_vars[$var]) ? (string) $wp->query_vars[$var] : '';
        if ($slug !== '') {
            $etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) wp_unslash($_SERVER['HTTP_IF_NONE_MATCH']) : '';
            self::serve(self::delivery_plan($slug, get_current_user_id(), $method, $uri, $etag), $wp);
            return;
        }
        if ($method === 'GET' || $method === 'HEAD') {
            $plan = self::legacy_plan($uri);
            if ($plan !== null) {
                self::serve($plan, $wp);
            }
        }
    }

    // ------------------------------------------------------------------
    // The plan
    // ------------------------------------------------------------------

    /**
     * The answer for /documents/<slug>/ (or the query form): array(action
     * (not_found | redirect | page | not_modified | file), status, headers,
     * and location, title and html (escaped), path and head, or
     * missing_document by action). $ctx overrides what the plan would read
     * for itself (tests): rule (bool), listener (bool), now (a timestamp),
     * file_ok (bool), size (bytes), membership (membership_facts()), join_url.
     */
    public static function delivery_plan($slug, $user_id, $method, $request_uri, $if_none_match, array $ctx = array()) {
        $doc = TeamOversight_Club_Documents::get_by_slug($slug);
        $version = $doc ? TeamOversight_Club_Documents::current_version($doc) : null;
        return self::plan_for($doc, $version, $user_id, $method, $request_uri, $if_none_match, $ctx);
    }

    /** delivery_plan() for a document row and its current version already in hand. Writes nothing and sends nothing. */
    public static function plan_for($doc, $version, $user_id, $method, $request_uri, $if_none_match, array $ctx = array()) {
        $user_id = intval($user_id);
        $method = strtoupper((string) $method);
        // 1. Unknown, or a draft: WordPress's own 404, as for an address that doesn't exist.
        if (!$doc || !in_array((string) $doc->status, array('published', 'retired'), true)
            || preg_match(TeamOversight_Club_Documents::SLUG_PATTERN, (string) $doc->slug) !== 1) {
            return self::not_found();
        }
        $listed = TeamOversight_Club_Documents::is_listed($doc);
        $can = TeamOversight_Club_Documents::can_open($doc, $user_id);
        // 2. Not listed and not let in: the very same answer at every step.
        if (!$listed && !$can) {
            return self::not_found();
        }
        $rule = array_key_exists('rule', $ctx) ? (bool) $ctx['rule'] : TeamOversight_Club_Documents::rule_present();
        $now = isset($ctx['now']) ? intval($ctx['now']) : time();
        // 3. Only the canonical address answers (once the rule exists): no query string at all, the slash.
        if ($rule && (string) $request_uri !== TeamOversight_Club_Documents::canonical_path($doc->slug)) {
            return array(
                'action' => 'redirect',
                'status' => 301,
                'location' => TeamOversight_Club_Documents::canonical_url($doc->slug),
                'headers' => $listed ? self::cacheable_headers($now, 86400) : self::restricted_headers(),
            );
        }
        // 4. Withdrawn.
        if ((string) $doc->status === 'retired') {
            return self::page(410, 'Withdrawn',
                '<p>The club withdrew this document on ' . esc_html(TeamOversight_Club_Documents::nice_date($doc->retired_at)) . '.</p>' . self::back_html());
        }
        if (!$can) {
            // 5. Logged out: the login page, then back here.
            if ($user_id < 1) {
                return array(
                    'action' => 'redirect',
                    'status' => 302,
                    'location' => self::login_url(TeamOversight_Club_Documents::canonical_url($doc->slug)),
                    'headers' => self::restricted_headers(),
                );
            }
            // 6. Logged in, matching no ticked box.
            return self::refusal($doc, $user_id, $ctx);
        }
        // 7. The file is missing from storage.
        $file_ok = array_key_exists('file_ok', $ctx) ? (bool) $ctx['file_ok'] : TeamOversight_Club_Documents::version_file_ok($version);
        if (!$version || !$file_ok) {
            $email = TeamOversight_Club_Documents::contact_email();
            $plan = self::page(503, 'File missing',
                '<p>This file is missing from the website. Email <a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a> for a copy.</p>' . self::back_html());
            $plan['missing_document'] = intval($doc->id);
            return $plan;
        }
        // 8. The file.
        $path = TeamOversight_Club_Documents::file_path($version->stored_name);
        $size = isset($ctx['size']) ? intval($ctx['size']) : (($path !== '' && is_file($path)) ? intval(filesize($path)) : intval($version->size_bytes));
        $headers = self::file_headers($doc->slug, $version, $size, false);
        if (TeamOversight_Club_Documents::is_public($doc)) {
            $etag = '"' . substr((string) $version->sha256, 0, 32) . '"';
            $listener = array_key_exists('listener', $ctx) ? (bool) $ctx['listener'] : TeamOversight_Club_Documents::has_purge_listener();
            // At this point the address is the canonical one whenever the rule exists (step 3).
            $cache = ($rule && $listener) ? self::cacheable_headers($now, TeamOversight_Club_Documents::PUBLIC_MAX_AGE) : self::uncached_headers();
            $headers = array_merge($headers, $cache, array('ETag' => $etag));
            if (self::etag_matches($if_none_match, $etag)) {
                return array(
                    'action' => 'not_modified',
                    'status' => 304,
                    'headers' => array_merge($cache, array('ETag' => $etag, 'X-Content-Type-Options' => 'nosniff')),
                );
            }
        } else {
            $headers = array_merge($headers, self::restricted_headers());
        }
        return array(
            'action' => 'file',
            'status' => 200,
            'headers' => $headers,
            'path' => $path,
            'head' => $method === 'HEAD',
        );
    }

    /** The plan for a 404: hand back to WordPress, unchanged. */
    private static function not_found() {
        return array('action' => 'not_found', 'status' => 404, 'headers' => array());
    }

    /** A wp_die() page: no-cache, noindex. $html is already escaped. */
    private static function page($status, $title, $html) {
        return array('action' => 'page', 'status' => intval($status), 'title' => (string) $title, 'html' => (string) $html, 'headers' => self::restricted_headers());
    }

    /** The 403 page: who the document is for, the account's membership, Ways to join (only with no current membership), back. */
    private static function refusal($doc, $user_id, array $ctx) {
        $keys = TeamOversight_Club_Documents::audiences_of($doc);
        list($heading, $sentence) = TeamOversight_Club_Documents::refusal_words($keys, $doc->title);
        $tiers = TeamOversight_Club_Documents::ticked_tiers($keys);
        $facts = array();
        if (!empty($tiers)) {
            $facts = isset($ctx['membership']) && is_array($ctx['membership']) ? $ctx['membership'] : TeamOversight_Club_Documents::membership_facts($user_id);
        }
        $line = TeamOversight_Club_Documents::membership_sentence($facts, $tiers);
        $html = '<h1>' . esc_html($heading) . '</h1><p>' . esc_html($sentence) . ($line !== '' ? ' ' . esc_html($line) : '') . '</p><p>';
        $join = array_key_exists('join_url', $ctx) ? (string) $ctx['join_url'] : TeamOversight_Club_Documents::join_url();
        if (!empty($tiers) && empty($facts['tier']) && $join !== '') {
            $html .= '<a href="' . esc_url($join) . '">Ways to join</a> &nbsp; ';
        }
        $html .= '<a href="' . esc_url(TeamOversight_Club_Documents::documents_page_url()) . '">Back to policies and documents</a></p>';
        return self::page(403, $heading, $html);
    }

    private static function back_html() {
        return '<p><a href="' . esc_url(TeamOversight_Club_Documents::documents_page_url()) . '">Back to policies and documents</a></p>';
    }

    /** The site's login page with redirect_to (Ultimate Member's when it has one, else WordPress's). */
    public static function login_url($back) {
        if (function_exists('um_get_core_page')) {
            $page = um_get_core_page('login');
            if (is_string($page) && $page !== '') {
                return add_query_arg('redirect_to', rawurlencode($back), $page);
            }
        }
        return wp_login_url($back);
    }

    /** WordPress's no-cache headers (an Expires in the past included), noindex and nosniff. */
    private static function restricted_headers() {
        $base = function_exists('wp_get_nocache_headers') ? wp_get_nocache_headers() : array();
        $out = array();
        foreach (is_array($base) ? $base : array() as $name => $value) {
            if ($value !== false && $value !== '' && $value !== null) {
                $out[$name] = (string) $value;
            }
        }
        if (!isset($out['Cache-Control'])) {
            $out['Cache-Control'] = 'no-cache, must-revalidate, max-age=0, no-store, private';
        }
        if (!isset($out['Expires'])) {
            $out['Expires'] = self::PAST;
        }
        $out['X-Robots-Tag'] = 'noindex, nofollow';
        $out['X-Content-Type-Options'] = 'nosniff';
        return $out;
    }

    /** Cacheable for $seconds, with a matching Expires (the server's own expiry rules leave an answer that has one alone). */
    private static function cacheable_headers($now, $seconds) {
        return array(
            'Cache-Control' => 'public, max-age=' . intval($seconds),
            'Expires' => self::http_date($now + intval($seconds)),
            'X-Content-Type-Options' => 'nosniff',
        );
    }

    /** A public file that mustn't be kept: no-cache and an Expires in the past. */
    private static function uncached_headers() {
        return array('Cache-Control' => 'no-cache', 'Expires' => self::PAST, 'X-Content-Type-Options' => 'nosniff');
    }

    /** The headers every file answer carries: type, an inline download name, length, last modified. */
    private static function file_headers($slug, $version, $size, $numbered) {
        $name = 'MURVC-' . $slug . '-' . substr((string) $version->dated, 0, 10) . ($numbered ? '-v' . intval($version->number) : '') . '.pdf';
        $modified = strtotime(get_gmt_from_date((string) $version->uploaded_at) . ' UTC');
        return array(
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Content-Length' => (string) intval($size),
            'Last-Modified' => self::http_date($modified ? $modified : time()),
            'X-Content-Type-Options' => 'nosniff',
        );
    }

    public static function http_date($ts) {
        return gmdate('D, d M Y H:i:s', intval($ts)) . ' GMT';
    }

    /** Whether an If-None-Match header names this ETag (weak or strong), or is *. */
    public static function etag_matches($header, $etag) {
        $header = trim((string) $header);
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if (strpos($tag, 'W/') === 0) {
                $tag = substr($tag, 2);
            }
            if ($tag === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * An old upload address: a request for a .pdf under the uploads address
     * that reached WordPress (the web server serves files that exist
     * itself). When its file really is gone and a document's legacy paths
     * hold it, a 301 to that document's address, whatever its status and
     * whoever asks (the target answers by viewer). Else null (WordPress's
     * own 404). A path that doesn't end in .pdf is never looked up.
     */
    public static function legacy_plan($request_uri) {
        $path = rawurldecode((string) wp_parse_url((string) $request_uri, PHP_URL_PATH));
        if ($path === '' || preg_match('/\.pdf$/iD', $path) !== 1) {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim((string) wp_parse_url((string) $uploads['baseurl'], PHP_URL_PATH), '/') . '/';
        if ($base === '/' || strpos($path, $base) !== 0) {
            return null;
        }
        $relative = substr($path, strlen($base));
        if ($relative === '' || strpos($relative, '..') !== false) {
            return null;
        }
        if (is_file(rtrim((string) $uploads['basedir'], '/\\') . '/' . $relative)) {
            return null;
        }
        $doc = TeamOversight_Club_Documents::document_for_legacy_path($relative);
        if (!$doc) {
            return null;
        }
        $rule = TeamOversight_Club_Documents::rule_present();
        return array(
            'action' => 'redirect',
            'status' => 301,
            'location' => TeamOversight_Club_Documents::document_url($doc->slug),
            'headers' => $rule ? self::cacheable_headers(time(), 86400) : self::restricted_headers(),
        );
    }

    // ------------------------------------------------------------------
    // Sending the answer
    // ------------------------------------------------------------------

    /** Carry out a plan. A 404 hands back to WordPress; everything else ends the request. */
    public static function serve(array $plan, $wp = null) {
        if ($plan['action'] === 'not_found') {
            if (is_object($wp)) {
                $wp->query_vars = array('error' => '404');
            }
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if ($plan['action'] === 'redirect') {
            self::send_headers($plan['headers']);
            wp_redirect($plan['location'], intval($plan['status']), 'Club Manager');
            exit;
        }
        if ($plan['action'] === 'page') {
            if (!empty($plan['missing_document'])) {
                TeamOversight_Club_Documents::note_missing_file($plan['missing_document']);
            }
            self::send_headers($plan['headers']);
            wp_die($plan['html'], esc_html($plan['title']), array('response' => intval($plan['status'])));
        }
        if ($plan['action'] === 'not_modified') {
            status_header(304);
            self::send_headers($plan['headers']);
            exit;
        }
        self::stream($plan);
    }

    private static function send_headers(array $headers) {
        if (headers_sent()) {
            return;
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /** Stream a file: every output buffer emptied, the headers, then 1 MB chunks (headers only for HEAD). Ends the request. */
    private static function stream(array $plan) {
        $fh = ($plan['path'] !== '' && is_readable($plan['path'])) ? fopen($plan['path'], 'rb') : false;
        if ($fh === false) {
            $email = TeamOversight_Club_Documents::contact_email();
            self::send_headers(self::restricted_headers());
            wp_die('<p>This file is missing from the website. Email <a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a> for a copy.</p>' . self::back_html(), 'File missing', array('response' => 503));
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header(intval($plan['status']));
        self::send_headers($plan['headers']);
        if (empty($plan['head'])) {
            while (!feof($fh)) {
                $chunk = fread($fh, self::CHUNK);
                if ($chunk === false) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- a PDF's bytes.
                flush();
            }
        }
        fclose($fh);
        exit;
    }

    // ------------------------------------------------------------------
    // Opening a file from the admin screens
    // ------------------------------------------------------------------

    /** The admin link that opens one version (drafts, old versions, any document): CAP and a nonce per version. */
    public static function admin_file_url($version_id) {
        $version_id = intval($version_id);
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::FILE_ACTION . '&version=' . $version_id), self::FILE_ACTION . '_' . $version_id);
    }

    /**
     * admin-post murvc_document_file: open any version for a CAP holder,
     * with the restricted headers. wp-admin addresses skip the page caches.
     */
    public static function admin_file() {
        if (!current_user_can(TeamOversight_Club_Documents::CAP)) {
            wp_die(esc_html('You can\'t open club documents.'), 'Not allowed', array('response' => 403));
        }
        $version_id = isset($_GET['version']) ? intval(wp_unslash($_GET['version'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? (string) wp_unslash($_GET['_wpnonce']) : '';
        if ($version_id < 1 || !wp_verify_nonce($nonce, self::FILE_ACTION . '_' . $version_id)) {
            wp_die(esc_html('This link has expired. Go back to Club documents and open it again.'), 'Link expired', array('response' => 403));
        }
        $version = TeamOversight_Club_Documents::get_version($version_id);
        $doc = $version ? TeamOversight_Club_Documents::get_document($version->document_id) : null;
        if (!$version || !$doc) {
            wp_die(esc_html('That version was not found.'), 'Not found', array('response' => 404));
        }
        if (!empty($version->file_deleted_at)) {
            wp_die(esc_html('An administrator deleted this version\'s file.'), 'File deleted', array('response' => 404));
        }
        if (!TeamOversight_Club_Documents::version_file_ok($version)) {
            wp_die(esc_html('This file is missing from storage.'), 'File missing', array('response' => 503));
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        $path = TeamOversight_Club_Documents::file_path($version->stored_name);
        $current = intval($doc->current_version_id) === intval($version->id);
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        self::stream(array(
            'status' => 200,
            'headers' => array_merge(self::file_headers($doc->slug, $version, intval(filesize($path)), !$current), self::restricted_headers()),
            'path' => $path,
            'head' => $method === 'HEAD',
        ));
    }
}
