<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club documents (1.90.0): the club's policies and AGM papers as PDFs, each
 * with one permanent address, kept in a private folder outside the website.
 * This class holds the tables, the storage, the audience registry and the
 * access rules, the writers, the history, the old-link check and the page
 * refresh. TeamOversight_Club_Documents_Route answers the addresses; the
 * admin screens and the [club_documents] shortcode call into this class.
 *
 * The rules, in one place:
 *  - Who can open a document is a set of ticked boxes, stored as a list of
 *    audience keys in the documents table's audiences column. audiences()
 *    (the registry) is the ONLY place a box is defined, and the helpers
 *    below (audiences_of(), clean_audiences(), can_open(), is_listed(),
 *    public_label(), who_sentence(), page_line(), refusal_words()) are the
 *    only readers of that column. A person may open a document when they
 *    match ANY ticked box; administrators always can. A key the registry
 *    doesn't know is dropped on read, so it matches nobody and lists
 *    nothing.
 *  - Files live only in the folder named by the MURVC_PRIVATE_FILES_DIR
 *    wp-config constant (its club-documents subfolder), under random names.
 *    There is no default and no admin setting. storage_status() must pass
 *    before any file is written. Files never go through the Media Library.
 *  - Every writer validates first, writes with a compare-and-set on the
 *    document's row_version where it changes a document, records one
 *    history row (team_club_document_changes, never pruned) and one log
 *    line, and queues a refresh of the page and the address when the
 *    website's answer changed (queue_purge()). Nothing deletes a document,
 *    a version row or a history row. The only code that deletes a Media
 *    Library attachment is remove_public_copy(), an administrator's own
 *    confirmed action; import copies and never deletes.
 *  - The capability CAP is held by the WordPress editor role (added once by
 *    ensure_caps(), never removed) and by administrators through
 *    grant_admins(); the Committee box means CAP holders.
 *
 * Tables (InnoDB, registered with the account merge's id_columns()):
 * team_club_documents, team_club_document_versions,
 * team_club_document_changes. Contract: design/club-documents-plan.md.
 */
class TeamOversight_Club_Documents {

    /** The capability every Club documents screen, the handler and the admin file opener check. */
    const CAP = 'murvc_manage_documents';

    /** Options. Groups and settings are not autoloaded; the rewrite flag is (one cheap read per request). */
    const GROUPS_OPTION = 'team_oversight_document_groups';
    const SETTINGS_OPTION = 'team_oversight_documents_settings';
    const REWRITE_OPTION = 'team_oversight_documents_rewrite';
    /**
     * Whether the page refresh reaches the site cache from the admin side
     * (autoloaded, read by the route for every public file): 'on' while an
     * admin request (a Club documents screen, a writer's refresh) last saw a
     * listener on PURGE_ACTION, else the time it was last seen ('0' never).
     * Written only when that changes (note_purge_listener()).
     */
    const PURGE_OPTION = 'team_oversight_documents_purge';
    /** The last failed web check (Check now): array(state, at); deleted when a later web check passes. Not autoloaded. */
    const WEB_CHECK_OPTION = 'team_oversight_documents_web_check';
    /** How long the site's page cache can keep an answer nobody refreshes, in days. */
    const CACHE_DAYS = 30;
    /** Raise when the address rule changes; a different stored value flushes the rules once. */
    const RULE_VERSION = '1.90.0';

    const QUERY_VAR = 'murvc_document';
    /** The address rule (WordPress stores it under this exact key). It can never end in .pdf. */
    const RULE_REGEX = '^documents/([a-z0-9-]+)/?$';
    const SHORTCODE = 'club_documents';
    /** do_action(PURGE_ACTION, $page_ids, $urls, $reasons): the one way Club documents reaches the site's page cache. */
    const PURGE_ACTION = 'murvc_documents_changed';

    /** The two log types (membership channel). */
    const LOG_DOCUMENT = 'club_document';
    const LOG_PROBLEM = 'club_document_problem';

    /** The admin pages (drawn by TeamOversight_Club_Documents_Admin). */
    const PAGE_LIST = 'club-documents';
    const PAGE_EDIT = 'club-documents-edit';
    const PAGE_SETTINGS = 'club-documents-settings';

    /** Storage: the wp-config constant, the subfolder, the site marker. */
    const STORAGE_CONSTANT = 'MURVC_PRIVATE_FILES_DIR';
    const SUBFOLDER = 'club-documents';
    const MARKER_FILE = '.murvc-site.json';

    /** An address name: small letters, numbers and hyphens, 3 to 60, starting and ending with a letter or number. */
    const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/D';
    /** The only file name on disk. */
    const STORED_PATTERN = '/^[a-f0-9]{32}\.pdf$/D';
    const GROUP_KEY_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D';

    /** Field limits, in characters (mb_strlen). A longer value is refused, never cut short. */
    const TITLE_MAX = 120;
    const DESCRIPTION_MAX = 300;
    const NOTE_MAX = 255;
    const REASON_MAX = 255;
    const ORIGINAL_MAX = 190;
    const GROUP_LABEL_MAX = 60;
    const INTRO_MAX = 2000;

    /** The size cap's default and highest setting, in MB. */
    const MAX_MB_DEFAULT = 20;
    const MAX_MB_LIMIT = 100;
    /** How long browsers and the page cache may keep a public file, in seconds (only while something listens on PURGE_ACTION). */
    const PUBLIC_MAX_AGE = 600;

    const STATUSES = array('draft', 'published', 'retired');

    /** Transients: the pages holding the shortcode (12 h), the storage check (1 h), the old-link check (1 h), a missing file's daily log line, a stale save's typed fields (1800 s). */
    const PAGES_TRANSIENT = 'murvc_documents_pages';
    const STORAGE_TRANSIENT = 'murvc_documents_storage';
    const LINKS_TRANSIENT = 'murvc_documents_links';
    const MISSING_PREFIX = 'murvc_documents_missing_';
    const DRAFT_PREFIX = 'murvc_documents_draft_';
    const PAGES_MAX = 50;

    /** The groups while GROUPS_OPTION has never been saved. key => label, order, intro, by_year. */
    const DEFAULT_GROUPS = array(
        'child-safety' => array('label' => 'Child safety', 'order' => 10, 'intro' => '', 'by_year' => 0),
        'club-rules' => array('label' => 'Club rules and membership', 'order' => 20, 'intro' => '', 'by_year' => 0),
        'buying' => array('label' => 'Buying from the club', 'order' => 30, 'intro' => '', 'by_year' => 0),
        'agm-papers' => array('label' => 'AGM papers', 'order' => 40, 'intro' => '', 'by_year' => 1),
    );

    /**
     * Sets of boxes named together (part of the registry): when every key of
     * a set is ticked, the label uses its tag and the sentences its words;
     * when only some are, the live sentence says the others can't open it.
     */
    const COMBINED = array(
        array('keys' => array('associate', 'full'), 'tag' => 'club members', 'words' => 'associate, full and life members'),
    );

    /** A group intro's allowed HTML. */
    const INTRO_TAGS = array(
        'a' => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
        'strong' => array(),
        'em' => array(),
        'p' => array(),
        'br' => array(),
    );

    /** Tests only, set by Reflection: a storage root used in place of the wp-config constant. */
    private static $storage_override = null;
    /** Tests only, set by Reflection: key => entry, used in place of the registry. */
    private static $audiences_override = null;
    /** Tests only, set by Reflection: accept files that were not uploaded in this request (copy, never move). */
    private static $test_files = false;

    /** Request caches. */
    private static $tier_cache = array();
    private static $pages = null;
    private static $groups = null;
    /** The page refresh queued this request: reason => true, canonical URL => true. */
    private static $purge_reasons = array();
    private static $purge_urls = array();

    /**
     * Add the hooks, once per request (team_oversight_init() calls it): the
     * capability on the editor role and for administrators, Ultimate
     * Member's wp-admin gate, and forgetting the pages list when a page or
     * post changes. Static and guarded, never from a constructor.
     */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('init', array(__CLASS__, 'ensure_caps'));
        add_filter('user_has_cap', array(__CLASS__, 'grant_admins'), 10, 1);
        add_filter('um_user_permissions_filter', array(__CLASS__, 'um_permissions'), 10, 2);
        add_action('save_post_page', array(__CLASS__, 'forget_pages'), 10, 2);
        add_action('save_post_post', array(__CLASS__, 'forget_pages'), 10, 2);
        add_action('deleted_post', array(__CLASS__, 'forget_pages'), 10, 2);
        add_action('trashed_post', array(__CLASS__, 'forget_pages'), 10, 2);
    }

    /** Forget everything cached this request (tests; the writers do it too). A queued page refresh is kept. */
    public static function flush_cache() {
        self::$tier_cache = array();
        self::$pages = null;
        self::$groups = null;
    }

    // ------------------------------------------------------------------
    // Tables (one definition for migrate_database(), create_tables() and
    // team_oversight_create_tables())
    // ------------------------------------------------------------------

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'team_club_documents';
    }

    public static function versions_table() {
        global $wpdb;
        return $wpdb->prefix . 'team_club_document_versions';
    }

    public static function changes_table() {
        global $wpdb;
        return $wpdb->prefix . 'team_club_document_changes';
    }

    /**
     * Short name => CREATE statement. migrate_database() runs each behind
     * its own SHOW TABLES check, so a normal load issues no CREATE.
     * Activation requires this file before plugins_loaded, so nothing at
     * file scope needs another class.
     */
    public static function tables_sql() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        return array(
            // One row per document, never deleted. audiences: the ticked
            // boxes, a comma-separated list of registry keys in registry
            // order ('' = none ticked yet, drafts only). legacy_paths: JSON
            // list of uploads-relative paths that redirect here.
            'team_club_documents' => "CREATE TABLE {$p}team_club_documents (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                slug varchar(60) NOT NULL,
                title varchar(120) NOT NULL,
                description varchar(300) NOT NULL DEFAULT '',
                group_key varchar(40) NOT NULL,
                doc_year smallint(5) unsigned DEFAULT NULL,
                position int(11) NOT NULL DEFAULT 0,
                audiences varchar(255) NOT NULL DEFAULT '',
                status varchar(20) NOT NULL DEFAULT 'draft',
                current_version_id bigint(20) unsigned DEFAULT NULL,
                legacy_paths text DEFAULT NULL,
                published_at datetime DEFAULT NULL,
                retired_at datetime DEFAULT NULL,
                created_by bigint(20) unsigned DEFAULT NULL,
                updated_by bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                row_version int(10) unsigned NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug),
                KEY group_status (group_key, status)
            ) ENGINE=InnoDB $c;",
            // One row per uploaded file, never deleted (a wrong upload's
            // file can be deleted by an administrator; the row stays).
            'team_club_document_versions' => "CREATE TABLE {$p}team_club_document_versions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                document_id bigint(20) unsigned NOT NULL,
                number smallint(5) unsigned NOT NULL,
                stored_name char(36) NOT NULL,
                original_name varchar(190) NOT NULL,
                size_bytes int(10) unsigned NOT NULL,
                sha256 char(64) NOT NULL,
                dated date NOT NULL,
                note varchar(255) NOT NULL DEFAULT '',
                source varchar(10) NOT NULL,
                imported_from varchar(255) DEFAULT NULL,
                uploaded_by bigint(20) unsigned DEFAULT NULL,
                uploaded_at datetime NOT NULL,
                file_deleted_at datetime DEFAULT NULL,
                file_deleted_by bigint(20) unsigned DEFAULT NULL,
                file_deleted_reason varchar(255) DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY document_number (document_id, number),
                KEY sha256 (sha256)
            ) ENGINE=InnoDB $c;",
            // The history, never pruned. document_id 0 = groups, settings
            // and storage events. recorded_by NULL = no signed-in account.
            'team_club_document_changes' => "CREATE TABLE {$p}team_club_document_changes (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                document_id bigint(20) unsigned NOT NULL DEFAULT 0,
                event varchar(40) NOT NULL,
                detail longtext DEFAULT NULL,
                recorded_by bigint(20) unsigned DEFAULT NULL,
                recorded_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY document_id (document_id, id)
            ) ENGINE=InnoDB $c;",
        );
    }

    // ------------------------------------------------------------------
    // The capability and the wp-admin gate
    // ------------------------------------------------------------------

    /** init: give the editor role CAP once (a has_cap() check first, so the roles option is written once). Never removed. */
    public static function ensure_caps() {
        $role = get_role('editor');
        if ($role && !$role->has_cap(self::CAP)) {
            $role->add_cap(self::CAP, true);
        }
    }

    /** user_has_cap: anyone with manage_options holds CAP too. Nothing is written. */
    public static function grant_admins($allcaps) {
        if (is_array($allcaps) && !empty($allcaps['manage_options'])) {
            $allcaps[self::CAP] = true;
        }
        return $allcaps;
    }

    /** um_user_permissions_filter: a CAP holder may enter wp-admin past Ultimate Member's block (an editor whose UM role is a member role). */
    public static function um_permissions($perms, $user_id = 0) {
        if (is_array($perms) && intval($user_id) > 0 && user_can(intval($user_id), self::CAP)) {
            $perms['can_access_wpadmin'] = 1;
        }
        return $perms;
    }

    // ------------------------------------------------------------------
    // The audience registry and the access rules (design 6.7)
    // ------------------------------------------------------------------

    /**
     * The boxes, in the order they are shown, as key => entry. The only
     * place an audience is defined. Each entry: key (stored, never renamed
     * or reused), label, help (the line under the box), short (the list
     * column), words (how sentences name it), tag (how the page label names
     * it; '' when not listed), listed (a published document ticking it is
     * listed on the page), public (Everyone: no login, the other ticks count
     * for nothing), tiers (the membership tiers it takes, for the refusal
     * page's membership sentence; empty for a box that isn't about
     * membership) and matches (callable(int $user_id): bool, called only for
     * a signed-in account and never for a public entry).
     *
     * To add a box (Coaches is expected): add one entry here, a COMBINED set
     * if it pairs with another, and its test cases. Nothing else changes.
     */
    public static function audiences() {
        if (is_array(self::$audiences_override)) {
            return self::$audiences_override;
        }
        $m = class_exists('TeamOversight_Memberships');
        $associate = $m ? TeamOversight_Memberships::TIER_ASSOCIATE : 'associate-member';
        $full = $m ? TeamOversight_Memberships::TIER_FULL : 'full-member';
        $life = $m ? TeamOversight_Memberships::TIER_LIFE : 'life-member';
        return array(
            'public' => array(
                'key' => 'public',
                'label' => 'Everyone (no login needed)',
                'help' => 'Anyone, logged in or not. While it is ticked the other boxes are greyed out and don\'t count.',
                'short' => 'Everyone',
                'words' => 'anyone, no login needed',
                'tag' => '',
                'listed' => true,
                'public' => true,
                'tiers' => array(),
                'matches' => null,
            ),
            'associate' => array(
                'key' => 'associate',
                'label' => 'Associate members',
                'help' => 'A current associate membership, such as a trial fee, social comp or program sessions.',
                'short' => 'Associate',
                'words' => 'associate members',
                'tag' => 'associate members',
                'listed' => true,
                'public' => false,
                'tiers' => array($associate),
                'matches' => function ($user_id) use ($associate) {
                    return TeamOversight_Club_Documents::tier_today($user_id) === $associate;
                },
            ),
            'full' => array(
                'key' => 'full',
                'label' => 'Full members',
                'help' => 'A current full or life membership. Life members always count as full.',
                'short' => 'Full',
                'words' => 'full and life members',
                'tag' => 'full members',
                'listed' => true,
                'public' => false,
                'tiers' => array($full, $life),
                'matches' => function ($user_id) use ($full, $life) {
                    $tier = TeamOversight_Club_Documents::tier_today($user_id);
                    return $tier === $full || $tier === $life;
                },
            ),
            'committee' => array(
                'key' => 'committee',
                'label' => 'Committee',
                'help' => 'Administrators and editors on this site. A document with only this box ticked isn\'t listed on the page.',
                'short' => 'Committee',
                'words' => 'the committee',
                'tag' => '',
                'listed' => false,
                'public' => false,
                'tiers' => array(),
                'matches' => function ($user_id) {
                    return user_can(intval($user_id), TeamOversight_Club_Documents::CAP);
                },
            ),
        );
    }

    /** The COMBINED sets whose keys are all in the registry. */
    private static function combined() {
        $reg = self::audiences();
        $out = array();
        foreach (self::COMBINED as $set) {
            $ok = !empty($set['keys']);
            foreach ($set['keys'] as $key) {
                if (!isset($reg[$key])) {
                    $ok = false;
                }
            }
            if ($ok) {
                $out[] = $set;
            }
        }
        return $out;
    }

    /**
     * The membership tier an account holds today (the ledger's highest
     * current grant: life beats full beats associate), or null. Cached per
     * account for the request, so the Associate and Full checks cost one
     * query between them. Never reads WordPress roles.
     */
    public static function tier_today($user_id) {
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return null;
        }
        if (!array_key_exists($user_id, self::$tier_cache)) {
            $tier = null;
            if (class_exists('TeamOversight_Memberships')) {
                $engine = new TeamOversight_Memberships();
                $tier = $engine->get_active_tier($user_id);
            }
            self::$tier_cache[$user_id] = $tier;
        }
        return self::$tier_cache[$user_id];
    }

    /**
     * A document's ticked keys the registry knows, in registry order. $doc
     * is a row (object or array) or the stored string. An unknown key (a
     * rollback from a version with more boxes) is dropped.
     */
    public static function audiences_of($doc) {
        if (is_object($doc)) {
            $raw = isset($doc->audiences) ? (string) $doc->audiences : '';
        } elseif (is_array($doc) && array_key_exists('audiences', $doc)) {
            $raw = (string) $doc['audiences'];
        } elseif (is_array($doc)) {
            // A plain list of keys.
            $raw = implode(',', array_map('strval', array_filter($doc, 'is_scalar')));
        } else {
            $raw = (string) $doc;
        }
        $have = array();
        foreach (explode(',', $raw) as $key) {
            $key = trim($key);
            if ($key !== '') {
                $have[$key] = true;
            }
        }
        $out = array();
        foreach (array_keys(self::audiences()) as $key) {
            if (isset($have[$key])) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * The form's ticks as they are stored: array($csv, $error). $posted is
     * a list of keys (unslashed by the handler), or null/'' for none.
     * Deduplicated, in registry order, joined with commas. A posted key the
     * registry doesn't know refuses the save ($error set, $csv null). The
     * boxes Everyone greys out are posted ticked or not and kept.
     */
    public static function clean_audiences($posted) {
        if ($posted === null || $posted === '') {
            return array('', '');
        }
        if (!is_array($posted)) {
            $posted = explode(',', (string) $posted);
        }
        $reg = self::audiences();
        $have = array();
        foreach ($posted as $key) {
            if (!is_scalar($key)) {
                return array(null, 'Those boxes weren\'t recognised. Reload the page and try again.');
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if (!isset($reg[$key])) {
                return array(null, 'Those boxes weren\'t recognised. Reload the page and try again.');
            }
            $have[$key] = true;
        }
        $out = array();
        foreach (array_keys($reg) as $key) {
            if (isset($have[$key])) {
                $out[] = $key;
            }
        }
        return array(implode(',', $out), '');
    }

    /** Whether Everyone is among the ticked keys (any public entry). $keys a list, a stored string or a document. */
    public static function is_public($keys) {
        $keys = self::audiences_of($keys);
        $reg = self::audiences();
        foreach ($keys as $key) {
            if (isset($reg[$key]) && !empty($reg[$key]['public'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether $user_id may open $doc: Everyone ticked → yes; nobody signed
     * in → no; administrators → yes, whatever is ticked; else any ticked
     * box whose matches() is true. A document with no known key opens for
     * administrators only.
     */
    public static function can_open($doc, $user_id) {
        $keys = self::audiences_of($doc);
        if (self::is_public($keys)) {
            return true;
        }
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $reg = self::audiences();
        foreach ($keys as $key) {
            $entry = $reg[$key];
            if (empty($entry['public']) && isset($entry['matches']) && is_callable($entry['matches']) && call_user_func($entry['matches'], $user_id)) {
                return true;
            }
        }
        return false;
    }

    /** Whether a published document is listed on the public page: any ticked box the registry lists. */
    public static function is_listed($doc) {
        $reg = self::audiences();
        foreach (self::audiences_of($doc) as $key) {
            if (!empty($reg[$key]['listed'])) {
                return true;
            }
        }
        return false;
    }

    /** "a", "a and b", "a, b and c". */
    private static function word_list(array $parts) {
        $parts = array_values(array_filter($parts, 'strlen'));
        $n = count($parts);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $parts[0];
        }
        return implode(', ', array_slice($parts, 0, $n - 1)) . ' and ' . $parts[$n - 1];
    }

    /**
     * The ticked keys split for the sentences: listed parts (a COMBINED set
     * whose keys are all ticked counts once, at its first key, by its own
     * $set_field; any other listed key by its entry's $field), the
     * non-listed parts (by words), and the "can't" sentences for each set
     * ticked only in part.
     */
    private static function parts(array $keys, $field, $set_field) {
        $reg = self::audiences();
        $ticked = array_fill_keys($keys, true);
        $sets = self::combined();
        $done = array();
        $listed = array();
        $others = array();
        $cant = array();
        foreach ($keys as $key) {
            if (isset($done[$key]) || !isset($reg[$key]) || !empty($reg[$key]['public'])) {
                continue;
            }
            if (empty($reg[$key]['listed'])) {
                $others[] = (string) $reg[$key]['words'];
                $done[$key] = true;
                continue;
            }
            $whole = null;
            foreach ($sets as $set) {
                if (in_array($key, $set['keys'], true)) {
                    $all = true;
                    foreach ($set['keys'] as $k) {
                        if (!isset($ticked[$k])) {
                            $all = false;
                        }
                    }
                    if ($all) {
                        $whole = $set;
                        break;
                    }
                }
            }
            if ($whole !== null) {
                $listed[] = (string) $whole[$set_field];
                foreach ($whole['keys'] as $k) {
                    $done[$k] = true;
                }
                continue;
            }
            $listed[] = (string) $reg[$key][$field];
            $done[$key] = true;
        }
        foreach ($sets as $set) {
            $in = array();
            $out = array();
            foreach ($set['keys'] as $k) {
                if (isset($ticked[$k])) {
                    $in[] = $k;
                } else {
                    $out[] = (string) $reg[$k]['words'];
                }
            }
            if (!empty($in) && !empty($out)) {
                $cant[] = ucfirst(self::word_list($out)) . ' can\'t.';
            }
        }
        return array($listed, $others, $cant);
    }

    /** Normalise a list of keys (or a stored string / document) to known keys in registry order. */
    private static function keys_of($keys) {
        return self::audiences_of($keys);
    }

    /**
     * The page label for a set of ticks: '' when Everyone is ticked or no
     * listed box is; else "For " and the tags ("For club members", "For
     * full members", "For associate members").
     */
    public static function public_label($keys) {
        $keys = self::keys_of($keys);
        if (empty($keys) || self::is_public($keys)) {
            return '';
        }
        list($listed) = self::parts($keys, 'tag', 'tag');
        $text = self::word_list($listed);
        return $text === '' ? '' : 'For ' . $text;
    }

    /** The live sentence's first line: who can open it. */
    public static function who_sentence($keys) {
        $keys = self::keys_of($keys);
        if (empty($keys)) {
            return 'Tick at least one box to publish. A draft can be saved without.';
        }
        $reg = self::audiences();
        foreach ($keys as $key) {
            if (!empty($reg[$key]['public'])) {
                return 'Who can open it: ' . $reg[$key]['words'] . '.';
            }
        }
        list($listed, $others, $cant) = self::parts($keys, 'words', 'words');
        $names = self::word_list($listed);
        if (!empty($others)) {
            $names = $names === '' ? self::word_list($others) : $names . ', and ' . self::word_list($others);
        }
        $out = 'Who can open it: ' . $names . '.';
        if (!empty($cant)) {
            $out .= ' ' . implode(' ', $cant);
        }
        return $out . ' Administrators can always open it.';
    }

    /** The live sentence's second line: what the page does with it ('' with nothing ticked). */
    public static function page_line($keys) {
        $keys = self::keys_of($keys);
        if (empty($keys)) {
            return '';
        }
        if (self::is_public($keys)) {
            return 'On the page: listed.';
        }
        if (self::is_listed(implode(',', $keys))) {
            return 'On the page: listed, marked ' . self::public_label($keys) . '.';
        }
        return 'On the page: not listed. Its address shows page not found to everyone else.';
    }

    /**
     * Both lines for every combination of the registered boxes (16 today),
     * csv of keys => array(who, page). The edit screen prints this in a data
     * attribute, so the rule lives once, here.
     */
    public static function sentence_table() {
        $keys = array_keys(self::audiences());
        $n = count($keys);
        $out = array();
        for ($mask = 0; $mask < (1 << $n); $mask++) {
            $set = array();
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $set[] = $keys[$i];
                }
            }
            $out[implode(',', $set)] = array(self::who_sentence($set), self::page_line($set));
        }
        return $out;
    }

    /**
     * The 403 page's heading and first sentence: array(heading, sentence).
     * Heading: the label without "For ", capitalised, plus "only" ("Club
     * members only"). Sentence: "Only current club members can open
     * "TITLE"." with "and the committee" when a box the page doesn't list
     * is ticked too. Plain text; the caller escapes.
     */
    public static function refusal_words($keys, $title) {
        $keys = self::keys_of($keys);
        list($tags) = self::parts($keys, 'tag', 'tag');
        list($listed, $others) = self::parts($keys, 'words', 'tag');
        $label = self::word_list($tags);
        $heading = $label !== '' ? ucfirst($label) . ' only' : (!empty($others) ? ucfirst(self::word_list($others)) . ' only' : 'Not available');
        $who = self::word_list($listed);
        if ($who !== '') {
            $sentence = 'Only current ' . $who . (!empty($others) ? ' and ' . self::word_list($others) : '');
        } else {
            $sentence = 'Only ' . (!empty($others) ? self::word_list($others) : 'the club');
        }
        return array($heading, $sentence . ' can open "' . (string) $title . '".');
    }

    /** The membership tiers the ticked boxes take (empty when no ticked box is about membership). */
    public static function ticked_tiers($keys) {
        $reg = self::audiences();
        $tiers = array();
        foreach (self::keys_of($keys) as $key) {
            if (!empty($reg[$key]['tiers']) && is_array($reg[$key]['tiers'])) {
                foreach ($reg[$key]['tiers'] as $tier) {
                    $tiers[(string) $tier] = true;
                }
            }
        }
        return array_keys($tiers);
    }

    /**
     * What the refusal page needs to know about an account's membership:
     * array(tier (today's, or null), until (Y-m-d, '' for life or none),
     * last_end (the latest ended grant's end date, or '')). Read-only.
     */
    public static function membership_facts($user_id) {
        global $wpdb;
        $user_id = intval($user_id);
        $tier = self::tier_today($user_id);
        $until = '';
        $last = '';
        $life = class_exists('TeamOversight_Memberships') ? TeamOversight_Memberships::TIER_LIFE : 'life-member';
        if ($tier !== null && $tier !== $life && class_exists('TeamOversight_Memberships')) {
            $until = (string) TeamOversight_Memberships::covered_until($user_id, array($tier));
        }
        if ($tier === null && $user_id > 0) {
            $last = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(end_date) FROM {$wpdb->prefix}team_memberships WHERE user_id = %d AND end_date < %s",
                $user_id, current_time('Y-m-d')
            ));
        }
        return array('tier' => $tier, 'until' => $until, 'last_end' => $last);
    }

    /**
     * The refusal page's membership sentence for a document whose ticks
     * take $tiers: the account's tier and end date, else when its last one
     * ended, else that it has none today. '' when no ticked box is about
     * membership. Plain text.
     */
    public static function membership_sentence(array $facts, array $tiers) {
        if (empty($tiers)) {
            return '';
        }
        $m = class_exists('TeamOversight_Memberships');
        $names = array(
            ($m ? TeamOversight_Memberships::TIER_LIFE : 'life-member') => 'a life membership',
            ($m ? TeamOversight_Memberships::TIER_FULL : 'full-member') => 'a full membership',
            ($m ? TeamOversight_Memberships::TIER_ASSOCIATE : 'associate-member') => 'an associate membership',
        );
        $tier = isset($facts['tier']) ? $facts['tier'] : null;
        if ($tier !== null && isset($names[$tier])) {
            $until = isset($facts['until']) ? (string) $facts['until'] : '';
            if ($until === '' || $until >= '2099-01-01') {
                return 'This account has ' . $names[$tier] . '.';
            }
            return 'This account has ' . $names[$tier] . ' until ' . self::nice_date($until) . '.';
        }
        $last = isset($facts['last_end']) ? (string) $facts['last_end'] : '';
        if ($last !== '') {
            return 'This account\'s last membership ended on ' . self::nice_date($last) . '.';
        }
        return 'This account has no membership on today\'s date.';
    }

    /** A Y-m-d date as "4 April 2027" (the date as written, no time zone shift). */
    public static function nice_date($ymd, $format = 'j F Y') {
        $ymd = substr((string) $ymd, 0, 10);
        $dt = DateTime::createFromFormat('!Y-m-d', $ymd);
        return $dt ? $dt->format($format) : $ymd;
    }

    // ------------------------------------------------------------------
    // Groups and settings
    // ------------------------------------------------------------------

    /** An option as stored, read straight from the database (null when the read failed). */
    private static function fresh_option($option) {
        if (class_exists('TeamOversight_Program_Setup') && method_exists('TeamOversight_Program_Setup', 'read_option_fresh')) {
            return TeamOversight_Program_Setup::read_option_fresh($option);
        }
        $value = get_option($option, array());
        return is_array($value) ? $value : array();
    }

    /** One stored group entry made safe to use, or null. */
    private static function normalise_group($key, $g) {
        if (!is_string($key) || preg_match(self::GROUP_KEY_PATTERN, $key) !== 1 || !is_array($g)) {
            return null;
        }
        $label = isset($g['label']) && is_scalar($g['label']) ? trim((string) $g['label']) : '';
        if ($label === '') {
            return null;
        }
        return array(
            'label' => $label,
            'order' => isset($g['order']) ? max(0, min(999, intval($g['order']))) : 0,
            'intro' => isset($g['intro']) && is_string($g['intro']) ? $g['intro'] : '',
            'by_year' => empty($g['by_year']) ? 0 : 1,
        );
    }

    /** The groups in their order (order, then label), key => label, order, intro, by_year. DEFAULT_GROUPS while never saved. */
    public static function groups($stored = null) {
        if ($stored === null && self::$groups !== null) {
            return self::$groups;
        }
        $src = $stored !== null ? $stored : get_option(self::GROUPS_OPTION, array());
        if (!is_array($src) || empty($src)) {
            $src = self::DEFAULT_GROUPS;
        }
        $out = array();
        foreach ($src as $key => $g) {
            $clean = self::normalise_group((string) $key, $g);
            if ($clean !== null) {
                $out[(string) $key] = $clean;
            }
        }
        if (empty($out)) {
            foreach (self::DEFAULT_GROUPS as $key => $g) {
                $out[$key] = $g;
            }
        }
        uksort($out, function ($a, $b) use ($out) {
            if ($out[$a]['order'] !== $out[$b]['order']) {
                return $out[$a]['order'] < $out[$b]['order'] ? -1 : 1;
            }
            return strnatcasecmp($out[$a]['label'], $out[$b]['label']);
        });
        if ($stored === null) {
            self::$groups = $out;
        }
        return $out;
    }

    /** The hash the groups screen draws and save_groups() checks: md5 of the option as stored, read fresh. */
    public static function groups_hash() {
        $fresh = self::fresh_option(self::GROUPS_OPTION);
        return md5(serialize(is_array($fresh) ? $fresh : array()));
    }

    /** The settings: max_mb, join_page, contact_email (defaults for anything missing). */
    public static function settings() {
        $stored = get_option(self::SETTINGS_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $max = isset($stored['max_mb']) ? intval($stored['max_mb']) : self::MAX_MB_DEFAULT;
        return array(
            'max_mb' => ($max >= 1 && $max <= self::MAX_MB_LIMIT) ? $max : self::MAX_MB_DEFAULT,
            'join_page' => isset($stored['join_page']) ? max(0, intval($stored['join_page'])) : 0,
            'contact_email' => isset($stored['contact_email']) && is_string($stored['contact_email']) ? trim($stored['contact_email']) : '',
        );
    }

    /** The address the missing-file page gives: the setting, or the site's admin email while it is blank. */
    public static function contact_email() {
        $s = self::settings();
        if ($s['contact_email'] !== '' && is_email($s['contact_email'])) {
            return $s['contact_email'];
        }
        return (string) get_option('admin_email');
    }

    /** The "ways to join" page's address, or '' (no link) when unset or not published. */
    public static function join_url() {
        $id = self::settings()['join_page'];
        if ($id < 1 || get_post_status($id) !== 'publish') {
            return '';
        }
        return (string) get_permalink($id);
    }

    /** The largest file accepted, in bytes: the smaller of the setting and what the server accepts. */
    public static function cap_bytes() {
        $cap = self::settings()['max_mb'] * 1048576;
        $server = function_exists('wp_max_upload_size') ? intval(wp_max_upload_size()) : 0;
        return $server > 0 ? min($cap, $server) : $cap;
    }

    // ------------------------------------------------------------------
    // Addresses
    // ------------------------------------------------------------------

    /** The home URL's path without a trailing slash ('' at the domain root). */
    private static function home_path() {
        $path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        return rtrim($path, '/');
    }

    /** The canonical path of a document's address: /documents/<slug>/ (under the home path). */
    public static function canonical_path($slug) {
        return self::home_path() . '/documents/' . $slug . '/';
    }

    /** The canonical (pretty) address. Purges and redirects always use this one. */
    public static function canonical_url($slug) {
        return home_url('/documents/' . $slug . '/');
    }

    /** Whether the stored rewrite rules hold the documents rule (pretty links on and flushed). */
    public static function rule_present() {
        if ((string) get_option('permalink_structure') === '') {
            return false;
        }
        $rules = get_option('rewrite_rules');
        return is_array($rules) && isset($rules[self::RULE_REGEX]);
    }

    /** The address Club Manager prints: the pretty one once the rule exists, else the query form (which needs no rule). */
    public static function document_url($slug) {
        if (self::rule_present()) {
            return self::canonical_url($slug);
        }
        return add_query_arg(self::QUERY_VAR, $slug, home_url('/'));
    }

    /** The page that shows the documents (the first in pages()), or the home page. Writes nothing. */
    public static function documents_page_url() {
        foreach (self::pages(false, false) as $page) {
            if (!empty($page['url'])) {
                return (string) $page['url'];
            }
        }
        return home_url('/');
    }

    // ------------------------------------------------------------------
    // Storage (design 6.3)
    // ------------------------------------------------------------------

    /** The storage root: the wp-config constant's value ('' when unset). Tests may override it. */
    public static function storage_root() {
        if (is_string(self::$storage_override) && self::$storage_override !== '') {
            return self::$storage_override;
        }
        if (defined(self::STORAGE_CONSTANT)) {
            $value = constant(self::STORAGE_CONSTANT);
            return is_string($value) ? trim($value) : '';
        }
        return '';
    }

    /** The folder files are kept in: <root>/club-documents ('' when no root). */
    public static function storage_dir() {
        $root = self::storage_root();
        return $root === '' ? '' : rtrim($root, '/\\') . '/' . self::SUBFOLDER;
    }

    /** The full path for a stored name, or '' when the name isn't one of ours (no request can name a path). */
    public static function file_path($stored_name) {
        $stored_name = (string) $stored_name;
        $dir = self::storage_dir();
        if ($dir === '' || preg_match(self::STORED_PATTERN, $stored_name) !== 1) {
            return '';
        }
        return $dir . '/' . $stored_name;
    }

    /** Whether a version's file is there to serve: not deleted, a stored name of ours, a readable file. */
    public static function version_file_ok($version) {
        if (!$version || !empty($version->file_deleted_at)) {
            return false;
        }
        $path = self::file_path($version->stored_name);
        return $path !== '' && is_file($path) && is_readable($path);
    }

    /** Is $path (a real path) the same as or inside $base (a real path)? */
    private static function inside($path, $base) {
        if ($path === '' || $base === '' || $base === false) {
            return false;
        }
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        return strpos($path, $base) === 0;
    }

    /** A storage check result. */
    private static function storage_result($ok, $step, $code, $problem, array $extra = array()) {
        return array_merge(array(
            'ok' => (bool) $ok,
            'step' => intval($step),
            'code' => (string) $code,
            'problem' => (string) $problem,
            'dir' => self::storage_dir(),
            'checked_at' => current_time('mysql'),
        ), $extra);
    }

    /** The site marker this site would write: array(hash, home). */
    private static function site_marker() {
        $home = untrailingslashit(home_url());
        return array('hash' => hash('sha256', $home), 'home' => $home);
    }

    /** The marker file's contents, or null when there is none or it can't be read. */
    private static function read_marker() {
        $dir = self::storage_dir();
        $file = $dir . '/' . self::MARKER_FILE;
        if ($dir === '' || !is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) && isset($data['hash']) ? $data : null;
    }

    /**
     * The self-check (design 6.3), steps 1 to 5: the constant is an
     * absolute path; its real path is outside ABSPATH, WP_CONTENT_DIR and
     * the document root; the subfolder exists and gives "other" no access,
     * nor does any file in it; the site marker is this site's; a probe file
     * can be written, read back and deleted ($write false skips step 5).
     * It never creates the folder or the marker (setup_storage() does).
     * Returns array(ok, step, code, problem, dir, checked_at, marker_home).
     * The result is kept for an hour for the To do panel (not while tests
     * override the folder). A failure is logged once per problem.
     */
    public static function storage_status($write = true) {
        $result = self::storage_check($write);
        if (self::$storage_override === null) {
            $before = get_transient(self::STORAGE_TRANSIENT);
            if (!$result['ok'] && (!is_array($before) || !empty($before['ok']) || (isset($before['code']) && $before['code'] !== $result['code']))) {
                self::log(self::LOG_PROBLEM, 'Club documents storage check failed at step ' . $result['step'] . ' (' . $result['code'] . ').');
            }
            set_transient(self::STORAGE_TRANSIENT, $result, HOUR_IN_SECONDS);
        }
        return $result;
    }

    /** The last storage check (an hour at most), or a fresh one. For the To do panel. */
    public static function storage_summary() {
        $cached = self::$storage_override === null ? get_transient(self::STORAGE_TRANSIENT) : false;
        return is_array($cached) && isset($cached['ok']) ? $cached : self::storage_status(true);
    }

    private static function storage_check($write) {
        $root = self::storage_root();
        if ($root === '') {
            return self::storage_result(false, 1, 'unset', 'File storage isn\'t set up: the site\'s wp-config.php needs MURVC_PRIVATE_FILES_DIR naming a folder outside the website. Uploads are refused until it is.');
        }
        if (!path_is_absolute($root)) {
            return self::storage_result(false, 1, 'relative', 'MURVC_PRIVATE_FILES_DIR in wp-config.php must be a full path, starting from the top of the server.');
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            return self::storage_result(false, 2, 'missing', 'The folder named by MURVC_PRIVATE_FILES_DIR doesn\'t exist on the server.');
        }
        $web = array(realpath(ABSPATH), defined('WP_CONTENT_DIR') ? realpath(WP_CONTENT_DIR) : false);
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $web[] = realpath((string) $_SERVER['DOCUMENT_ROOT']);
        }
        foreach ($web as $base) {
            if ($base !== false && self::inside($real, $base)) {
                return self::storage_result(false, 2, 'inside', 'The folder named by MURVC_PRIVATE_FILES_DIR is inside the website, where anyone could open its files. Name a folder outside it.');
            }
        }
        $dir = self::storage_dir();
        if (!is_dir($dir)) {
            return self::storage_result(false, 3, 'no_folder', 'Storage isn\'t set up yet: an administrator clicks Set up storage on Groups and settings.');
        }
        $real_dir = realpath($dir);
        foreach ($web as $base) {
            if ($real_dir === false || ($base !== false && self::inside($real_dir, $base))) {
                return self::storage_result(false, 3, 'inside', 'The documents folder is inside the website, where anyone could open its files.');
            }
        }
        clearstatcache();
        if ((fileperms($dir) & 0007) !== 0) {
            return self::storage_result(false, 3, 'open', 'The documents folder lets other accounts on the server read it. Click Set up storage to fix its permissions.');
        }
        $names = scandir($dir);
        foreach (is_array($names) ? $names : array() as $name) {
            // The checks' own probe files live for a moment and are deleted at once.
            if ($name === '.' || $name === '..' || strpos($name, '.probe-') === 0 || strpos($name, 'webcheck-') === 0) {
                continue;
            }
            if ((fileperms($dir . '/' . $name) & 0007) !== 0) {
                return self::storage_result(false, 3, 'open_file', 'A file in the documents folder lets other accounts on the server read it. Click Set up storage to fix its permissions.');
            }
        }
        $marker = self::read_marker();
        $mine = self::site_marker();
        if ($marker === null) {
            return self::storage_result(false, 4, 'no_marker', 'Storage isn\'t set up yet: an administrator clicks Set up storage on Groups and settings.');
        }
        if (!hash_equals($mine['hash'], (string) $marker['hash'])) {
            return self::storage_result(false, 4, 'other_site', 'This folder belongs to another site\'s address. An administrator runs Set up storage in the browser to take it over.', array('marker_home' => isset($marker['home']) ? (string) $marker['home'] : ''));
        }
        if ($write) {
            $probe = $dir . '/.probe-' . bin2hex(random_bytes(8));
            $content = bin2hex(random_bytes(16));
            $wrote = file_put_contents($probe, $content);
            if ($wrote !== false) {
                chmod($probe, 0640);
            }
            $back = $wrote === strlen($content) ? file_get_contents($probe) : false;
            if (is_file($probe)) {
                unlink($probe);
            }
            if ($back !== $content || is_file($probe)) {
                return self::storage_result(false, 5, 'unwritable', 'The website can\'t write to the documents folder. An administrator runs Set up storage in the browser. If that doesn\'t fix it, the folder belongs to another server account: ask the host to give it to the website\'s account, or remove it so Set up storage can make it.');
            }
        }
        return self::storage_result(true, 5, 'ok', '');
    }

    /**
     * Set up storage (administrators, in the browser): make the subfolder
     * at 0770 with a backstop index.php and deny-all .htaccess, take away
     * "other" access from anything in it, and write the site marker. A
     * marker from another site's address is rewritten only with $takeover
     * true; without it the result asks to confirm, with both addresses
     * (needs_confirm, old_home, new_home). Refused from WP-CLI (the folder
     * must belong to the website's own account) unless a test has set its
     * own folder. One history row and one log line.
     */
    public static function setup_storage($actor, $takeover = false) {
        $actor = intval($actor);
        if (!user_can($actor, 'manage_options')) {
            return self::result(false, 'error', 'Only an administrator can set up storage.');
        }
        if (defined('WP_CLI') && WP_CLI && self::$storage_override === null) {
            return self::result(false, 'error', 'Set up storage in the browser, so the folder belongs to the website\'s own account.');
        }
        $check = self::storage_check(false);
        if (!$check['ok'] && $check['step'] <= 2) {
            return self::result(false, 'error', $check['problem']);
        }
        $dir = self::storage_dir();
        if (!is_dir($dir) && !mkdir($dir, 0770)) {
            return self::result(false, 'error', 'The documents folder could not be made. Check that the website can write to the folder named in wp-config.php.');
        }
        chmod($dir, 0770);
        $backstops = array(
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        );
        foreach ($backstops as $name => $content) {
            if (!is_file($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $content);
            }
        }
        clearstatcache();
        $names = scandir($dir);
        foreach (is_array($names) ? $names : array() as $name) {
            $path = $dir . '/' . $name;
            if ($name !== '.' && $name !== '..' && is_file($path) && (fileperms($path) & 0007) !== 0) {
                chmod($path, fileperms($path) & 0770);
            }
        }
        $mine = self::site_marker();
        $marker = self::read_marker();
        $took = false;
        if ($marker !== null && !hash_equals($mine['hash'], (string) $marker['hash'])) {
            if (!$takeover) {
                $out = self::result(false, 'warning', 'This folder belongs to another site\'s address. Confirm to take it over for this site.');
                $out['needs_confirm'] = true;
                $out['old_home'] = isset($marker['home']) ? (string) $marker['home'] : '';
                $out['new_home'] = $mine['home'];
                return $out;
            }
            $took = true;
        }
        if ($marker === null || $took) {
            $data = array('hash' => $mine['hash'], 'home' => $mine['home'], 'at' => current_time('mysql'), 'by' => $actor);
            $file = $dir . '/' . self::MARKER_FILE;
            if (file_put_contents($file, wp_json_encode($data)) === false) {
                return self::result(false, 'error', 'The site marker could not be written. Check that the website can write to the documents folder.');
            }
            chmod($file, 0640);
        }
        $status = self::storage_status(true);
        self::record_change(0, 'storage_set_up', array('takeover' => $took, 'ok' => $status['ok']), $actor);
        self::log(self::LOG_DOCUMENT, 'Club documents storage set up' . ($took ? ' (taken over from another site address)' : '') . '.');
        if (!$status['ok']) {
            return self::result(false, 'error', $status['problem']);
        }
        return self::result(true, 'success', 'Storage is set up and its check passed.');
    }

    /**
     * Check now (administrators): the folder check (steps 1 to 5) and then
     * the web check. Returns array(storage => storage_status(), web =>
     * web_check()). A failed web check is logged and kept in
     * WEB_CHECK_OPTION until a later web check passes (one that couldn't
     * run keeps it), so To do and File storage go on showing it.
     */
    public static function check_now($actor) {
        if (!user_can(intval($actor), 'manage_options')) {
            return array('storage' => self::storage_result(false, 0, 'denied', 'Only an administrator can check the storage.'), 'web' => array('state' => 'skipped', 'message' => ''));
        }
        $status = self::storage_status(true);
        $web = $status['ok'] ? self::web_check() : array('state' => 'skipped', 'message' => 'The web check runs once the folder check passes.');
        if ($web['state'] === 'fail') {
            self::log(self::LOG_PROBLEM, 'Club documents web check failed: a test file in the documents folder could be fetched over the web.');
            update_option(self::WEB_CHECK_OPTION, array('state' => 'fail', 'at' => current_time('mysql')), false);
        } elseif ($web['state'] === 'pass') {
            delete_option(self::WEB_CHECK_OPTION);
        }
        return array('storage' => $status, 'web' => $web);
    }

    /** When the last web check failed (site time), or '' when none has failed since one passed. */
    public static function web_check_failed() {
        $last = get_option(self::WEB_CHECK_OPTION, array());
        return (is_array($last) && isset($last['state'], $last['at']) && $last['state'] === 'fail') ? (string) $last['at'] : '';
    }

    /**
     * The web check (Check now only): put a probe file with random content
     * in the documents folder, ask for it at the addresses a host could map
     * (the folder's name, and the subfolder's name, under the site root),
     * and pass when no answer holds the content. A 401 (a password on the
     * whole site) means it couldn't be checked from here. The probe is
     * always deleted.
     */
    public static function web_check() {
        $dir = self::storage_dir();
        $real = realpath(self::storage_root());
        if ($dir === '' || $real === false) {
            return array('state' => 'skipped', 'message' => 'There is no folder to check.');
        }
        $name = 'webcheck-' . bin2hex(random_bytes(8)) . '.txt';
        $content = bin2hex(random_bytes(16));
        $file = $dir . '/' . $name;
        if (file_put_contents($file, $content) === false) {
            return array('state' => 'unknown', 'message' => 'The test file could not be written.');
        }
        chmod($file, 0640);
        $base = trailingslashit(home_url());
        $urls = array(
            $base . rawurlencode(basename($real)) . '/' . self::SUBFOLDER . '/' . $name,
            $base . self::SUBFOLDER . '/' . $name,
        );
        $state = 'pass';
        try {
            foreach ($urls as $url) {
                $response = wp_remote_get($url, array('timeout' => 5, 'redirection' => 0, 'sslverify' => apply_filters('https_local_ssl_verify', false)));
                if (is_wp_error($response)) {
                    if ($state === 'pass') {
                        $state = 'unknown';
                    }
                    continue;
                }
                if (strpos((string) wp_remote_retrieve_body($response), $content) !== false) {
                    $state = 'fail';
                    break;
                }
                if (intval(wp_remote_retrieve_response_code($response)) === 401 && $state === 'pass') {
                    $state = 'unknown';
                }
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $words = array(
            'pass' => 'The test file couldn\'t be fetched over the web, as it should be.',
            'fail' => 'The test file could be fetched over the web, so the files in the documents folder are not private. Ask the host to fix this, and don\'t upload members-only papers until Check now passes.',
            'unknown' => 'Couldn\'t check over the web from here.',
        );
        return array('state' => $state, 'message' => $words[$state]);
    }

    // ------------------------------------------------------------------
    // Uploads (design 6.6)
    // ------------------------------------------------------------------

    /**
     * A POST whose body was bigger than post_max_size arrives with $_POST
     * and $_FILES empty. The handler calls this first: the message to show
     * (with the limit the form gives, cap_bytes(), which is never more than
     * post_max_size), or '' when the request is not such a post. Nothing is
     * written, so no nonce is needed for this one message.
     */
    public static function post_too_big($method, array $post, $content_length, $post_max_size) {
        $limit = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) $post_max_size) : intval($post_max_size);
        if (strtoupper((string) $method) !== 'POST' || !empty($post) || $limit < 1 || intval($content_length) <= $limit) {
            return '';
        }
        return 'The file is bigger than the site accepts, so nothing was saved. The limit is ' . self::mb_words(self::cap_bytes()) . '.';
    }

    /** A byte count as whole MB ("32 MB"), or KB below 1 MB. */
    public static function mb_words($bytes) {
        $bytes = max(0, intval($bytes));
        if ($bytes >= 1048576) {
            $mb = $bytes / 1048576;
            return (floor($mb) == $mb ? (string) intval($mb) : number_format($mb, 1)) . ' MB';
        }
        return max(1, intval(round($bytes / 1024))) . ' KB';
    }

    /** Whether the first bytes hold %PDF- and the last ones %%EOF (pure; the byte checks stand alone without fileinfo). */
    public static function pdf_bytes_ok($head, $tail) {
        return strpos((string) $head, '%PDF-') !== false && strpos((string) $tail, '%%EOF') !== false;
    }

    /** Read a file's first 1,024 and last 2,048 bytes and check them. */
    private static function pdf_file_ok($path) {
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false || $size === false) {
            return false;
        }
        $head = (string) fread($fh, 1024);
        $tail = '';
        if (fseek($fh, max(0, $size - 2048)) === 0) {
            $tail = (string) fread($fh, 2048);
        }
        fclose($fh);
        return self::pdf_bytes_ok($head, $tail);
    }

    /**
     * Check an upload ($file is one $_FILES entry: name, tmp_name, size,
     * error). Returns array($errors, $info), $info = array(path,
     * original_name, size, sha256). The checks: PHP's upload error,
     * is_uploaded_file(), size (empty, over the cap), a .pdf name, the
     * %PDF- and %%EOF bytes, fileinfo when the extension exists.
     */
    public static function check_upload($file) {
        if (!is_array($file) || !isset($file['tmp_name'], $file['name'])) {
            return array(array('Choose a PDF to upload.'), null);
        }
        $error = isset($file['error']) ? intval($file['error']) : UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                return array(array('The file is bigger than the site accepts. The limit is ' . self::mb_words(self::cap_bytes()) . '.'), null);
            }
            if ($error === UPLOAD_ERR_NO_FILE) {
                return array(array('Choose a PDF to upload.'), null);
            }
            if ($error === UPLOAD_ERR_PARTIAL) {
                return array(array('Only part of the file arrived. Try again.'), null);
            }
            return array(array('The server couldn\'t take the file (error ' . $error . '). Try again, or tell the website maintainer.'), null);
        }
        $path = (string) $file['tmp_name'];
        if ($path === '' || !is_file($path) || (!self::$test_files && !is_uploaded_file($path))) {
            return array(array('The file didn\'t arrive. Try again.'), null);
        }
        return self::check_pdf($path, (string) $file['name']);
    }

    /** The checks after the upload itself (also for an import's copy): size, name, bytes, fileinfo; then the sha256. */
    private static function check_pdf($path, $name) {
        clearstatcache();
        $size = intval(filesize($path));
        $errors = array();
        if ($size < 1) {
            return array(array('The file is empty.'), null);
        }
        $cap = self::cap_bytes();
        if ($size > $cap) {
            $errors[] = 'The file is ' . self::mb_words($size) . '; the limit is ' . self::mb_words($cap) . '.';
        }
        if (!preg_match('/\.pdf$/iD', $name)) {
            $errors[] = 'Only PDF files can be uploaded.';
        }
        if (!self::pdf_file_ok($path)) {
            $errors[] = 'This file isn\'t a complete PDF. Save it as a PDF again and upload that.';
        } elseif (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($path);
            if ($mime !== 'application/pdf' && $mime !== 'application/x-pdf') {
                $errors[] = 'This file isn\'t a PDF. Save it as a PDF and upload that.';
            }
        }
        if (!empty($errors)) {
            return array($errors, null);
        }
        $original = sanitize_file_name(wp_basename($name));
        if (mb_strlen($original, 'UTF-8') > self::ORIGINAL_MAX) {
            $original = mb_substr($original, 0, self::ORIGINAL_MAX - 4, 'UTF-8') . '.pdf';
        }
        return array(array(), array(
            'path' => $path,
            'original_name' => $original,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $path),
        ));
    }

    /**
     * Put a checked file into storage under a new random name at 0640:
     * move_uploaded_file() for an upload, copy() for an import (never a
     * move). Returns the stored name, or '' when it could not be written.
     */
    private static function store_file($source, $is_upload) {
        $dir = self::storage_dir();
        if ($dir === '') {
            return '';
        }
        for ($try = 0; $try < 3; $try++) {
            $name = bin2hex(random_bytes(16)) . '.pdf';
            $dest = $dir . '/' . $name;
            if (file_exists($dest)) {
                continue;
            }
            $ok = ($is_upload && !self::$test_files) ? move_uploaded_file($source, $dest) : copy($source, $dest);
            if (!$ok) {
                return '';
            }
            chmod($dest, 0640);
            return $name;
        }
        return '';
    }

    /** Remove a stored file this request just wrote (a failed insert). */
    private static function discard_file($stored_name) {
        $path = self::file_path($stored_name);
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    public static function get_document($id) {
        global $wpdb;
        $id = intval($id);
        return $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id)) : null;
    }

    public static function get_by_slug($slug) {
        global $wpdb;
        $slug = (string) $slug;
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug));
    }

    public static function get_version($id) {
        global $wpdb;
        $id = intval($id);
        return $id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::versions_table() . ' WHERE id = %d', $id)) : null;
    }

    /** A document's versions, newest number first. */
    public static function versions($document_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::versions_table() . ' WHERE document_id = %d ORDER BY number DESC', intval($document_id)));
        return is_array($rows) ? $rows : array();
    }

    /** A document's current version row, or null. */
    public static function current_version($doc) {
        if (!$doc || empty($doc->current_version_id)) {
            return null;
        }
        $v = self::get_version($doc->current_version_id);
        return ($v && intval($v->document_id) === intval($doc->id)) ? $v : null;
    }

    /** Every document (or those with $status), by group, year (newest first), position, id. For the admin list. */
    public static function list_documents($status = '') {
        global $wpdb;
        $where = in_array($status, self::STATUSES, true) ? $wpdb->prepare(' WHERE status = %s', $status) : '';
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . $where . ' ORDER BY group_key, (doc_year IS NULL), doc_year DESC, position, id');
        return is_array($rows) ? $rows : array();
    }

    /** The published documents (one query), in list order. The shortcode drops those is_listed() rejects before reading versions. */
    public static function published_documents() {
        return self::list_documents('published');
    }

    /** Version rows by id for a list of ids (one query): id => row. */
    public static function versions_by_id(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return array();
        }
        $rows = $wpdb->get_results('SELECT * FROM ' . self::versions_table() . ' WHERE id IN (' . implode(',', $ids) . ')');
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $out[intval($row->id)] = $row;
        }
        return $out;
    }

    /** A document's legacy paths (uploads-relative), as stored. */
    public static function legacy_paths_of($doc) {
        $raw = is_object($doc) && isset($doc->legacy_paths) ? (string) $doc->legacy_paths : '';
        $list = $raw !== '' ? json_decode($raw, true) : array();
        $out = array();
        foreach (is_array($list) ? $list : array() as $path) {
            if (is_string($path) && $path !== '') {
                $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    /** A document's history, newest first. Values are as stored: the caller escapes every one. */
    public static function history($document_id, $limit = 100) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::changes_table() . ' WHERE document_id = %d ORDER BY id DESC LIMIT %d',
            intval($document_id), max(1, intval($limit))
        ));
        return is_array($rows) ? $rows : array();
    }

    /**
     * The accounts that can manage documents today, which is also who the
     * Committee box means: id => display name decoded once. Read from the
     * capability itself (any role holding CAP, CAP given to one account, and
     * manage_options, which holds CAP through grant_admins()), then each
     * account checked with user_can().
     */
    public static function managers() {
        $users = get_users(array('capability__in' => array(self::CAP, 'manage_options'), 'orderby' => 'display_name', 'fields' => array('ID', 'display_name')));
        $out = array();
        foreach (is_array($users) ? $users : array() as $u) {
            if (user_can(intval($u->ID), self::CAP)) {
                $out[intval($u->ID)] = wp_specialchars_decode((string) $u->display_name, ENT_QUOTES);
            }
        }
        return $out;
    }

    /** A title with its AGM year, for messages: "Notice and agenda (2026)". Plain text. */
    public static function title_with_year($doc) {
        $title = is_object($doc) ? (string) $doc->title : '';
        return (is_object($doc) && !empty($doc->doc_year)) ? $title . ' (' . intval($doc->doc_year) . ')' : $title;
    }

    // ------------------------------------------------------------------
    // Field rules
    // ------------------------------------------------------------------

    /** A date as Y-m-d (from Y-m-d or d/m/Y), or ''. */
    public static function parse_date($value) {
        $value = trim((string) $value);
        foreach (array('!Y-m-d', '!d/m/Y', '!j/n/Y') as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            $errors = DateTime::getLastErrors();
            if ($dt && (!is_array($errors) || (empty($errors['warning_count']) && empty($errors['error_count'])))) {
                $year = intval($dt->format('Y'));
                if ($year >= 1900 && $year <= 2100) {
                    return $dt->format('Y-m-d');
                }
            }
        }
        return '';
    }

    /** Who else holds an address name: that document's title (with its year), or ''. */
    public static function slug_owner($slug, $except_id = 0) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, title, doc_year FROM ' . self::table() . ' WHERE slug = %s AND id <> %d', (string) $slug, intval($except_id)));
        return $row ? self::title_with_year($row) : '';
    }

    /**
     * The address name suggested for a new document: committee-<number>
     * when only boxes the page doesn't list are ticked (so the address gives
     * nothing away), agm-<year>-<title> in a group shown by year, else the
     * title; made unique with -2, -3.
     */
    public static function suggest_slug($title, $group_key, $doc_year, $audiences, $except_id = 0) {
        global $wpdb;
        $keys = self::keys_of($audiences);
        if (!empty($keys) && !self::is_listed(implode(',', $keys))) {
            $next = intval($wpdb->get_var('SELECT MAX(id) FROM ' . self::table())) + 1;
            $base = 'committee-' . max(1, $except_id > 0 ? intval($except_id) : $next);
        } else {
            $base = sanitize_title((string) $title);
            $base = preg_replace('/[^a-z0-9-]+/', '-', strtolower($base));
            $groups = self::groups();
            if (isset($groups[$group_key]) && !empty($groups[$group_key]['by_year']) && intval($doc_year) > 0) {
                $base = 'agm-' . intval($doc_year) . ($base !== '' ? '-' . $base : '');
            }
        }
        $base = trim(preg_replace('/-+/', '-', (string) $base), '-');
        if (strlen($base) > 55) {
            $base = rtrim(substr($base, 0, 55), '-');
        }
        if (strlen($base) < 3) {
            $base = 'document' . ($base !== '' ? '-' . $base : '');
        }
        $slug = $base;
        for ($n = 2; $n < 100 && self::slug_owner($slug, $except_id) !== ''; $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** The address suggest_slug() gives a saved document for its saved title, group, year and ticks. */
    public static function suggest_for_row($doc) {
        if (!is_object($doc)) {
            return '';
        }
        return self::suggest_slug((string) $doc->title, (string) $doc->group_key, $doc->doc_year === null ? 0 : intval($doc->doc_year), implode(',', self::audiences_of($doc)), intval($doc->id));
    }

    /**
     * Clean what an editor typed (unslashed by the handler) against the old
     * row (null for a new document): array($clean, $errors). title (1 to
     * 120), description (to 300), group_key (a group that exists), doc_year
     * (a year, only for a group shown by year; else null), slug (the
     * pattern; blank on a new document = suggested; fixed once published),
     * audiences (through clean_audiences(); absent keeps the old ticks).
     */
    public static function clean_fields(array $fields, $old = null) {
        $errors = array();
        $clean = array();
        $text = function ($key) use ($fields) {
            return isset($fields[$key]) && is_scalar($fields[$key]) ? sanitize_text_field((string) $fields[$key]) : '';
        };
        $clean['title'] = $text('title');
        if ($clean['title'] === '') {
            $errors[] = 'Type a title.';
        } elseif (mb_strlen($clean['title'], 'UTF-8') > self::TITLE_MAX) {
            $errors[] = 'The title is ' . mb_strlen($clean['title'], 'UTF-8') . ' characters; the limit is ' . self::TITLE_MAX . '.';
        }
        $clean['description'] = $text('description');
        if (mb_strlen($clean['description'], 'UTF-8') > self::DESCRIPTION_MAX) {
            $errors[] = 'The description is ' . mb_strlen($clean['description'], 'UTF-8') . ' characters; the limit is ' . self::DESCRIPTION_MAX . '.';
        }
        $groups = self::groups();
        $clean['group_key'] = $text('group_key');
        if (!isset($groups[$clean['group_key']])) {
            $errors[] = 'Choose a group.';
        }
        $clean['doc_year'] = null;
        if (isset($groups[$clean['group_key']]) && !empty($groups[$clean['group_key']]['by_year'])) {
            $year = trim($text('doc_year'));
            if ($year !== '') {
                if (preg_match('/^\d{4}$/D', $year) !== 1 || intval($year) < 1990 || intval($year) > 2100) {
                    $errors[] = 'The AGM year must be a year, such as 2027.';
                } else {
                    $clean['doc_year'] = intval($year);
                }
            }
        }
        if (array_key_exists('audiences', $fields)) {
            list($csv, $error) = self::clean_audiences($fields['audiences']);
            if ($error !== '') {
                $errors[] = $error;
                $csv = $old ? (string) $old->audiences : '';
            }
            $clean['audiences'] = $csv;
        } else {
            $clean['audiences'] = $old ? implode(',', self::audiences_of($old)) : '';
        }
        $slug = strtolower(trim($text('slug')));
        if ($old && !empty($old->published_at)) {
            if ($slug !== '' && $slug !== (string) $old->slug) {
                $errors[] = 'The address is fixed once the document has been published.';
            }
            $slug = (string) $old->slug;
        } else {
            $suggested = self::suggest_slug($clean['title'], $clean['group_key'], $clean['doc_year'], $clean['audiences'], $old ? intval($old->id) : 0);
            if (!$old) {
                $slug = $slug === '' ? $suggested : $slug;
            } elseif ($slug === '' || $slug === (string) $old->slug) {
                // Until it is published, an address that is still the one suggested for the saved details
                // follows the title, group, year and ticks; one somebody typed stays as typed.
                $slug = (string) $old->slug === self::suggest_for_row($old) ? $suggested : (string) $old->slug;
            }
        }
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $errors[] = 'The address can use small letters, numbers and hyphens only, 3 to 60 of them, starting and ending with a letter or number.';
        } else {
            $owner = self::slug_owner($slug, $old ? intval($old->id) : 0);
            if ($owner !== '') {
                $errors[] = 'That address is already used by ' . $owner . '. Pick another.';
            }
        }
        $clean['slug'] = $slug;
        return array($clean, $errors);
    }

    /** What a published document needs: '' when it can be published, else the sentence that says what's missing. */
    public static function publish_problem($doc, $version = null) {
        if (!$doc) {
            return 'That document was not found. Reload the page.';
        }
        $version = $version !== null ? $version : self::current_version($doc);
        if (!$version) {
            return 'Upload the PDF before publishing.';
        }
        if (!self::version_file_ok($version)) {
            return 'The current file is missing from storage. Upload it again before publishing.';
        }
        $groups = self::groups();
        if (!isset($groups[(string) $doc->group_key])) {
            return 'Choose a group before publishing.';
        }
        if (!empty($groups[(string) $doc->group_key]['by_year']) && empty($doc->doc_year)) {
            return 'Set the AGM year before publishing.';
        }
        if (empty(self::audiences_of($doc))) {
            return 'Tick at least one box to publish.';
        }
        return '';
    }

    /**
     * Whether a document's address answers for it now: published, or
     * withdrawn after being published (its address says so). A draft, and a
     * draft set aside (withdrawn, never published), answer page not found.
     */
    public static function address_live($doc) {
        if (!is_object($doc)) {
            return false;
        }
        $status = (string) $doc->status;
        return $status === 'published' || ($status === 'retired' && !empty($doc->published_at));
    }

    /**
     * What a version's file now does at the address, after an upload or
     * Make current: one plain sentence for the document's status.
     */
    public static function opens_words($doc, $now = true) {
        $status = is_object($doc) ? (string) $doc->status : '';
        if ($status === 'published') {
            return $now ? 'The address now opens it.' : 'The address opens it.';
        }
        if ($status === 'retired' && !empty($doc->published_at)) {
            return 'The address still says it was withdrawn until you publish it again.';
        }
        return 'It opens once the document is published.';
    }

    // ------------------------------------------------------------------
    // Writers: documents
    // ------------------------------------------------------------------

    /** A writer's result. */
    private static function result($ok, $type, $message, array $lines = array(), $id = 0) {
        return array(
            'ok' => (bool) $ok,
            'type' => (string) $type,
            'message' => (string) $message,
            'lines' => array_values($lines),
            'id' => intval($id),
        );
    }

    /** The field values a history row compares. */
    private static function row_fields($row) {
        return array(
            'title' => (string) $row->title,
            'description' => (string) $row->description,
            'group_key' => (string) $row->group_key,
            'doc_year' => $row->doc_year === null || $row->doc_year === '' ? null : intval($row->doc_year),
            'slug' => (string) $row->slug,
            'audiences' => implode(',', self::audiences_of($row)),
        );
    }

    /** field => array(old, new) for each field that differs. */
    private static function diff(array $old, array $new) {
        $out = array();
        foreach ($new as $key => $value) {
            $was = array_key_exists($key, $old) ? $old[$key] : null;
            if ($was !== $value) {
                $out[$key] = array($was, $value);
            }
        }
        return $out;
    }

    /** Make a new draft document from what an editor typed. Records created, logs. Nothing on the website changes. */
    public static function create_document(array $fields, $actor) {
        global $wpdb;
        list($clean, $errors) = self::clean_fields($fields, null);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was saved.', $errors);
        }
        $now = current_time('mysql');
        $position = 10 + intval($wpdb->get_var($wpdb->prepare('SELECT MAX(position) FROM ' . self::table() . ' WHERE group_key = %s', $clean['group_key'])));
        $done = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (slug, title, description, group_key, doc_year, position, audiences, status, created_by, updated_by, created_at, updated_at, row_version)
             VALUES (%s, %s, %s, %s, NULLIF(%d, 0), %d, %s, \'draft\', NULLIF(%d, 0), NULLIF(%d, 0), %s, %s, 1)',
            $clean['slug'], $clean['title'], $clean['description'], $clean['group_key'], intval($clean['doc_year']), $position,
            $clean['audiences'], intval($actor), intval($actor), $now, $now
        ));
        $id = $done ? intval($wpdb->insert_id) : 0;
        if ($id < 1) {
            $owner = self::slug_owner($clean['slug']);
            return self::result(false, 'error', $owner !== '' ? 'That address is already used by ' . $owner . '. Pick another.' : 'The document could not be saved. Try again.');
        }
        self::record_change($id, 'created', self::diff(array(), $clean), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': created (draft).');
        return self::result(true, 'success', 'Saved as a draft.', array(), $id);
    }

    /**
     * A save drawn at an old row_version: keep what was typed for this
     * editor (DRAFT_PREFIX, 1800 s) and say who changed it since, and when.
     */
    private static function stale($id, array $clean, $actor) {
        $fresh = self::get_document($id);
        if (!$fresh) {
            return self::result(false, 'error', 'That document was not found. Reload the page.', array(), $id);
        }
        if (intval($actor) > 0 && !empty($clean)) {
            set_transient(self::DRAFT_PREFIX . intval($actor) . '_' . intval($id), $clean, 1800);
        }
        $who = self::person_name($fresh->updated_by);
        $when = mysql2date('j M Y \a\t g:i a', $fresh->updated_at);
        // Only this class's own fallbacks are capitalised; a person's name is shown as they wrote it.
        $who = $who === 'someone' ? 'Someone' : (strpos($who, 'user #') === 0 ? 'User #' . substr($who, 6) : $who);
        $said = $who . ' changed this document on ' . $when . ' after you opened it.';
        // A save keeps what was typed (the edit screen refills the form once); a button with nothing typed keeps nothing.
        $said .= !empty($clean) && intval($actor) > 0 ? ' Your changes are in the form below: check the History, then save again.' : ' Reload it, check the History and try again.';
        $out = self::result(false, 'error', $said, array(), $id);
        $out['stale'] = true;
        return $out;
    }

    /** A display name for messages (decoded once; the caller escapes), or "someone". */
    private static function person_name($user_id) {
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return 'someone';
        }
        $user = get_userdata($user_id);
        return $user ? wp_specialchars_decode((string) $user->display_name, ENT_QUOTES) : 'user #' . $user_id;
    }

    /**
     * Save an editor's changes to a document drawn at $expect_row_version.
     * One UPDATE ... WHERE id AND row_version. A change of ticks records the
     * old and new key lists. A published or withdrawn document can't be
     * left with no box ticked, and a published one in a group shown by year
     * needs its year. Refreshes the page when a published document changed,
     * and its address too when the ticks changed.
     */
    public static function update_document($id, array $fields, $expect_row_version, $actor) {
        global $wpdb;
        $id = intval($id);
        $old = self::get_document($id);
        if (!$old) {
            return self::result(false, 'error', 'That document was not found. Reload the page.', array(), $id);
        }
        list($clean, $errors) = self::clean_fields($fields, $old);
        if (self::address_live($old) && $clean['audiences'] === '') {
            $errors[] = 'A document on the website needs at least one box ticked. To take it off the website, withdraw it.';
        }
        if ((string) $old->status === 'published' && empty($errors)) {
            $groups = self::groups();
            if (!empty($groups[$clean['group_key']]['by_year']) && empty($clean['doc_year'])) {
                $errors[] = 'Set the AGM year: the document is on the website.';
            }
        }
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was saved.', $errors, $id);
        }
        if (intval($old->row_version) !== intval($expect_row_version)) {
            return self::stale($id, $clean, $actor);
        }
        $diff = self::diff(self::row_fields($old), $clean);
        if (empty($diff)) {
            return self::result(true, 'success', 'Nothing changed.', array(), $id);
        }
        $now = current_time('mysql');
        $position = (string) $old->group_key === $clean['group_key'] ? intval($old->position)
            : 10 + intval($wpdb->get_var($wpdb->prepare('SELECT MAX(position) FROM ' . self::table() . ' WHERE group_key = %s', $clean['group_key'])));
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET slug = %s, title = %s, description = %s, group_key = %s, doc_year = NULLIF(%d, 0), position = %d, audiences = %s,
                row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0)
             WHERE id = %d AND row_version = %d',
            $clean['slug'], $clean['title'], $clean['description'], $clean['group_key'], intval($clean['doc_year']), $position, $clean['audiences'],
            $now, intval($actor), $id, intval($expect_row_version)
        ));
        if ($done === false) {
            $owner = self::slug_owner($clean['slug'], $id);
            return self::result(false, 'error', $owner !== '' ? 'That address is already used by ' . $owner . '. Pick another.' : 'The document could not be saved. Try again.', array(), $id);
        }
        if ($done !== 1) {
            return self::stale($id, $clean, $actor);
        }
        self::record_change($id, 'updated', $diff, $actor, $now);
        $words = array();
        $names = array('title' => 'title', 'description' => 'description', 'group_key' => 'group', 'doc_year' => 'AGM year', 'slug' => 'address', 'audiences' => 'who can open it');
        foreach (array_keys($diff) as $key) {
            $words[] = $names[$key];
        }
        $message = 'Club document #' . $id . ': saved (' . implode(', ', $words) . ').';
        if (isset($diff['audiences'])) {
            $message .= ' Ticks ' . ($diff['audiences'][0] === '' ? 'none' : $diff['audiences'][0]) . ' -> ' . ($diff['audiences'][1] === '' ? 'none' : $diff['audiences'][1]) . '.';
        }
        self::log(self::LOG_DOCUMENT, $message);
        if ((string) $old->status !== 'draft') {
            self::queue_purge(isset($diff['audiences']) ? 'audiences' : 'document', isset($diff['audiences']) ? (string) $old->slug : '');
        }
        // Ticks moved off Everyone: the file that was public may still be in the site cache.
        $lines = (string) $old->status === 'published' && self::is_public($old) && !self::is_public($clean['audiences'])
            ? self::cache_warning($id, false) : array();
        return self::result(true, empty($lines) ? 'success' : 'warning', (string) $old->status === 'published' ? 'Document updated.' : 'Saved.', $lines, $id);
    }

    /** The next version number for a document. */
    private static function next_number($document_id) {
        global $wpdb;
        return 1 + intval($wpdb->get_var($wpdb->prepare('SELECT MAX(number) FROM ' . self::versions_table() . ' WHERE document_id = %d', intval($document_id))));
    }

    /**
     * Insert a version row for a stored file (retried once on a number
     * clash). Returns array(id, number) or null; the caller removes the
     * file when it is null.
     */
    private static function insert_version($document_id, $stored, array $info, $dated, $note, $source, $imported_from, $actor, $now) {
        global $wpdb;
        for ($try = 0; $try < 2; $try++) {
            $number = self::next_number($document_id);
            $done = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . self::versions_table() . ' (document_id, number, stored_name, original_name, size_bytes, sha256, dated, note, source, imported_from, uploaded_by, uploaded_at)
                 VALUES (%d, %d, %s, %s, %d, %s, %s, %s, %s, NULLIF(%s, \'\'), NULLIF(%d, 0), %s)',
                intval($document_id), $number, $stored, $info['original_name'], intval($info['size']), $info['sha256'], $dated, $note, $source,
                (string) $imported_from, intval($actor), $now
            ));
            if ($done === 1) {
                return array(intval($wpdb->insert_id), $number);
            }
        }
        return null;
    }

    /** Check the Updated date and note: array($dated, $note, $errors). */
    private static function clean_date_note($dated, $note) {
        $errors = array();
        $d = self::parse_date($dated);
        if ($d === '') {
            $errors[] = 'Set the Updated date, such as 12/03/2026.';
        }
        $note = sanitize_text_field(is_scalar($note) ? (string) $note : '');
        if (mb_strlen($note, 'UTF-8') > self::NOTE_MAX) {
            $errors[] = 'The note is ' . mb_strlen($note, 'UTF-8') . ' characters; the limit is ' . self::NOTE_MAX . '.';
        }
        return array($d, $note, $errors);
    }

    /**
     * Upload a new version and make it current ($file: one $_FILES entry,
     * checked by check_upload()). The storage check must pass. The same
     * file as the current version changes nothing. The file is stored, its
     * row inserted (the file is removed if the insert fails), then the
     * document is pointed at it with a compare-and-set on
     * $expect_row_version: when someone else changed the document
     * meanwhile, the new version is kept but not made current, and the
     * result says so (stale, version_id). Records version_added, logs, and
     * refreshes the page and the address when the document is published.
     */
    public static function add_version($id, $file, $dated, $note, $expect_row_version, $actor) {
        global $wpdb;
        $id = intval($id);
        $doc = self::get_document($id);
        if (!$doc) {
            return self::result(false, 'error', 'That document was not found. Reload the page.', array(), $id);
        }
        list($dated, $note, $errors) = self::clean_date_note($dated, $note);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was uploaded.', $errors, $id);
        }
        $storage = self::storage_status(true);
        if (!$storage['ok']) {
            return self::result(false, 'error', 'Nothing was uploaded: ' . $storage['problem'], array(), $id);
        }
        list($errors, $info) = self::check_upload($file);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was uploaded.', $errors, $id);
        }
        $current = self::current_version($doc);
        // The same file as the current version changes nothing, unless that version's file is missing from
        // storage: then this upload brings it back (as a new version, made current like any other).
        $restoring = $current && hash_equals((string) $current->sha256, $info['sha256']);
        if ($restoring && self::version_file_ok($current)) {
            return self::result(false, 'warning', 'This is the same file as version ' . intval($current->number) . '. Nothing changed.', array(), $id);
        }
        $stored = self::store_file($info['path'], true);
        if ($stored === '') {
            return self::result(false, 'error', 'The file could not be saved to storage. Try again, or ask an administrator to check the storage.', array(), $id);
        }
        $now = current_time('mysql');
        $inserted = self::insert_version($id, $stored, $info, $dated, $note, 'upload', '', $actor, $now);
        if ($inserted === null) {
            self::discard_file($stored);
            return self::result(false, 'error', 'The new version could not be saved. Try again.', array(), $id);
        }
        list($version_id, $number) = $inserted;
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET current_version_id = %d, row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0) WHERE id = %d AND row_version = %d',
            $version_id, $now, intval($actor), $id, intval($expect_row_version)
        ));
        $made_current = $done === 1;
        self::record_change($id, 'version_added', array('number' => $number, 'dated' => $dated, 'size' => intval($info['size']), 'current' => $made_current), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': version ' . $number . ' uploaded' . ($made_current ? ' and made current.' : ', not made current (the document changed meanwhile).'));
        if (!$made_current) {
            $out = self::result(false, 'warning', 'Someone else changed this document while you had it open. Your file is saved as version ' . $number . ' but isn\'t current: check the document, then use Make current on that version.', array(), $id);
            $out['stale'] = true;
            $out['version_id'] = $version_id;
            return $out;
        }
        $lines = array();
        if ((string) $doc->status === 'published') {
            self::queue_purge('version', (string) $doc->slug);
            $lines = self::is_public($doc) && !$restoring ? self::cache_warning($id, false) : array();
        }
        if ($restoring) {
            $message = 'The missing file is back, as version ' . $number . '.' . ((string) $doc->status === 'published' ? ' The address opens it again.' : '');
        } else {
            $message = 'Version ' . $number . ' uploaded. ' . self::opens_words($doc);
        }
        $out = self::result(true, empty($lines) ? 'success' : 'warning', $message, $lines, $id);
        $out['version_id'] = $version_id;
        $out['number'] = $number;
        return $out;
    }

    /** Make an existing version current (its file must be there). Compare-and-set; records, logs, refreshes when published. */
    public static function make_current($id, $version_id, $expect_row_version, $actor) {
        global $wpdb;
        $id = intval($id);
        $doc = self::get_document($id);
        $version = self::get_version($version_id);
        if (!$doc || !$version || intval($version->document_id) !== $id) {
            return self::result(false, 'error', 'That version was not found. Reload the page.', array(), $id);
        }
        if (intval($doc->current_version_id) === intval($version->id)) {
            return self::result(true, 'success', 'Version ' . intval($version->number) . ' is already current.', array(), $id);
        }
        if (!self::version_file_ok($version)) {
            return self::result(false, 'error', 'Version ' . intval($version->number) . '\'s file is not in storage, so it can\'t be made current.', array(), $id);
        }
        $now = current_time('mysql');
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET current_version_id = %d, row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0) WHERE id = %d AND row_version = %d',
            intval($version->id), $now, intval($actor), $id, intval($expect_row_version)
        ));
        if ($done !== 1) {
            return self::stale($id, array(), $actor);
        }
        $was = self::get_version($doc->current_version_id);
        self::record_change($id, 'made_current', array('number' => array($was ? intval($was->number) : null, intval($version->number))), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': version ' . intval($version->number) . ' made current.');
        $lines = array();
        if ((string) $doc->status === 'published') {
            self::queue_purge('version', (string) $doc->slug);
            $lines = self::is_public($doc) ? self::cache_warning($id, false) : array();
        }
        return self::result(true, empty($lines) ? 'success' : 'warning', 'Version ' . intval($version->number) . ' is now current. ' . self::opens_words($doc, false), $lines, $id);
    }

    /**
     * Change date or note, on any version: the only writer of a version's
     * dated and note. Compare-and-set on the document's row_version (it is
     * raised), then the version row. Records version_dated with old and
     * new, logs, and refreshes the page and the address when it is the
     * current version of a published document and the date changed.
     */
    public static function set_version_date($version_id, $dated, $note, $expect_row_version, $actor) {
        global $wpdb;
        $version = self::get_version($version_id);
        $doc = $version ? self::get_document($version->document_id) : null;
        if (!$version || !$doc) {
            return self::result(false, 'error', 'That version was not found. Reload the page.');
        }
        $id = intval($doc->id);
        list($dated, $note, $errors) = self::clean_date_note($dated, $note);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was saved.', $errors, $id);
        }
        if ($dated === (string) $version->dated && $note === (string) $version->note) {
            return self::result(true, 'success', 'Nothing changed.', array(), $id);
        }
        $now = current_time('mysql');
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0) WHERE id = %d AND row_version = %d',
            $now, intval($actor), $id, intval($expect_row_version)
        ));
        if ($done !== 1) {
            return self::stale($id, array(), $actor);
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . self::versions_table() . ' SET dated = %s, note = %s WHERE id = %d', $dated, $note, intval($version->id)));
        $detail = array('number' => intval($version->number));
        if ($dated !== (string) $version->dated) {
            $detail['dated'] = array((string) $version->dated, $dated);
        }
        if ($note !== (string) $version->note) {
            $detail['note'] = array((string) $version->note, $note);
        }
        self::record_change($id, 'version_dated', $detail, $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': version ' . intval($version->number) . ' ' . (isset($detail['dated']) ? 'date' : '') . (isset($detail['dated'], $detail['note']) ? ' and ' : '') . (isset($detail['note']) ? 'note' : '') . ' changed.');
        if ((string) $doc->status === 'published' && intval($doc->current_version_id) === intval($version->id) && isset($detail['dated'])) {
            self::queue_purge('version_date', (string) $doc->slug);
        }
        return self::result(true, 'success', 'Version ' . intval($version->number) . ' saved.', array(), $id);
    }

    /**
     * Publish ('published'), withdraw ('retired') or take back to a draft
     * ('draft'). Publish needs publish_problem() to be '' (a current file
     * that exists, a group that exists, the year in a group shown by year,
     * at least one box the registry knows); the first publish fixes the
     * address name. Withdraw is from published, or from a draft, which
     * sets the draft aside: it stays off the website and its address keeps
     * answering page not found (address_live()). Compare-and-set on
     * $expect_row_version. Records, logs and refreshes the page and the
     * address (not for setting a draft aside, which changes neither).
     */
    public static function set_status($id, $status, $expect_row_version, $actor) {
        global $wpdb;
        $id = intval($id);
        $doc = self::get_document($id);
        if (!$doc) {
            return self::result(false, 'error', 'That document was not found. Reload the page.', array(), $id);
        }
        if (!in_array($status, self::STATUSES, true)) {
            return self::result(false, 'error', 'Choose Publish, Withdraw or Save draft.', array(), $id);
        }
        $from = (string) $doc->status;
        if ($from === $status) {
            $words = array('published' => 'It is already on the website.', 'retired' => 'It is already withdrawn.', 'draft' => 'It is already a draft.');
            return self::result(true, 'success', $words[$status], array(), $id);
        }
        if ($status === 'published') {
            $problem = self::publish_problem($doc);
            if ($problem !== '') {
                return self::result(false, 'error', $problem, array(), $id);
            }
        }
        if ($status === 'retired' && $from !== 'published' && $from !== 'draft') {
            return self::result(false, 'error', 'Only a document on the website, or a draft, can be withdrawn.', array(), $id);
        }
        $now = current_time('mysql');
        $set = 'status = %s, row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0)';
        if ($status === 'published') {
            $set .= ', published_at = COALESCE(published_at, updated_at), retired_at = NULL';
        } elseif ($status === 'retired') {
            $set .= ', retired_at = updated_at';
        }
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET ' . $set . ' WHERE id = %d AND row_version = %d AND status = %s',
            $status, $now, intval($actor), $id, intval($expect_row_version), $from
        ));
        if ($done !== 1) {
            return self::stale($id, array(), $actor);
        }
        if ($status === 'published') {
            $event = ($from === 'retired' && !empty($doc->published_at)) ? 'republished' : 'published';
        } elseif ($status === 'retired') {
            // A draft that was never on the website is set aside; one that was is withdrawn like any other.
            $event = ($from === 'draft' && empty($doc->published_at)) ? 'set_aside' : 'retired';
        } else {
            $event = 'unpublished';
        }
        self::record_change($id, $event, array('from' => $from), $actor, $now);
        $words = array('published' => 'published', 'republished' => 'published again', 'retired' => 'withdrawn from the website', 'set_aside' => 'set aside (a draft, never on the website)', 'unpublished' => 'taken back to a draft');
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': ' . $words[$event] . '.');
        delete_transient(self::LINKS_TRANSIENT);
        if ($event !== 'set_aside') {
            self::queue_purge($event, (string) $doc->slug);
        }
        if ($status === 'published') {
            if (self::is_listed($doc)) {
                $message = ($event === 'republished' ? 'Published again at the same address.' : 'Published.')
                    . (self::has_purge_listener() ? ' It\'s listed on the Policies and documents page within a few minutes.'
                        : ' It\'s listed on the Policies and documents page, but logged-out visitors may see the old page until the site cache is emptied.');
            } else {
                $message = ($event === 'republished' ? 'Published again. ' : 'Published. ') . 'It isn\'t listed on the page. ' . self::who_sentence($doc);
            }
        } else {
            $messages = array(
                'retired' => 'Withdrawn. Its address now says the club withdrew it.',
                'set_aside' => 'Set aside. It stays off the website and its address shows page not found. Publish puts it up.',
                'unpublished' => 'Taken back to a draft. Its address now shows page not found.',
            );
            $message = $messages[$event];
        }
        // A public file taken off: it may still be in the site cache when nothing could refresh it.
        $lines = ($from === 'published' && $status !== 'published' && self::is_public($doc)) ? self::cache_warning($id, false) : array();
        return self::result(true, empty($lines) ? 'success' : 'warning', $message, $lines, $id);
    }

    /**
     * Where a move goes in a group's list (pure): the index to swap with,
     * or false. Only past a document of the same year; a published document
     * swaps with the nearest published one that way, skipping drafts and
     * withdrawn ones (which the page doesn't show), so every move of a
     * published document changes the page. Any other document swaps with
     * its neighbour. $years and $statuses are in the list's order.
     */
    public static function move_target(array $years, array $statuses, $at, $direction) {
        $step = $direction === 'up' ? -1 : 1;
        $published = isset($statuses[$at]) && $statuses[$at] === 'published';
        for ($to = $at + $step; $to >= 0 && $to < count($years); $to += $step) {
            if ($years[$to] !== $years[$at]) {
                return false;
            }
            if (!$published || $statuses[$to] === 'published') {
                return $to;
            }
        }
        return false;
    }

    /**
     * Move a document up or down inside its group (in a group shown by
     * year, only past a document of the same year; a published document
     * past the next published one, move_target()). The group is
     * renumbered 10, 20, 30. Compare-and-set on the moved document; the
     * others' positions change without raising their row_version (no form
     * holds a position). Records, logs, refreshes the page when published.
     */
    public static function move($id, $direction, $expect_row_version, $actor) {
        global $wpdb;
        $id = intval($id);
        $doc = self::get_document($id);
        if (!$doc) {
            return self::result(false, 'error', 'That document was not found. Reload the page.', array(), $id);
        }
        if ($direction !== 'up' && $direction !== 'down') {
            return self::result(false, 'error', 'Choose up or down.', array(), $id);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, doc_year, status FROM ' . self::table() . ' WHERE group_key = %s ORDER BY (doc_year IS NULL), doc_year DESC, position, id',
            (string) $doc->group_key
        ));
        $ids = array();
        $years = array();
        $statuses = array();
        foreach (is_array($rows) ? $rows : array() as $r) {
            $ids[] = intval($r->id);
            $years[] = $r->doc_year === null ? null : intval($r->doc_year);
            $statuses[] = (string) $r->status;
        }
        $at = array_search($id, $ids, true);
        $to = $at === false ? false : self::move_target($years, $statuses, $at, $direction);
        if ($to === false) {
            return self::result(true, 'success', $direction === 'up' ? 'It is already at the top.' : 'It is already at the bottom.', array(), $id);
        }
        $other = $ids[$to];
        $ids[$to] = $id;
        $ids[$at] = $other;
        $now = current_time('mysql');
        $mine = 10 * (array_search($id, $ids, true) + 1);
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET position = %d, row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0) WHERE id = %d AND row_version = %d',
            $mine, $now, intval($actor), $id, intval($expect_row_version)
        ));
        if ($done !== 1) {
            return self::stale($id, array(), $actor);
        }
        foreach ($ids as $i => $other_id) {
            if ($other_id !== $id) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET position = %d WHERE id = %d', 10 * ($i + 1), $other_id));
            }
        }
        self::record_change($id, 'moved', array('direction' => $direction), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': moved ' . $direction . '.');
        if ((string) $doc->status === 'published') {
            self::queue_purge('order');
        }
        return self::result(true, 'success', 'Moved ' . $direction . '.', array(), $id);
    }

    /**
     * Delete a wrong upload's file (administrators, never the current
     * version, a reason required). The version row stays, marked with who,
     * when and why. Records, logs. Nothing public changes.
     */
    public static function delete_version_file($version_id, $reason, $actor) {
        global $wpdb;
        $actor = intval($actor);
        if (!user_can($actor, 'manage_options')) {
            return self::result(false, 'error', 'Only an administrator can delete a version\'s file.');
        }
        $version = self::get_version($version_id);
        $doc = $version ? self::get_document($version->document_id) : null;
        if (!$version || !$doc) {
            return self::result(false, 'error', 'That version was not found. Reload the page.');
        }
        $id = intval($doc->id);
        if (intval($doc->current_version_id) === intval($version->id)) {
            return self::result(false, 'error', 'The current version\'s file can\'t be deleted. Make another version current first.', array(), $id);
        }
        if (!empty($version->file_deleted_at)) {
            return self::result(true, 'success', 'That file was already deleted.', array(), $id);
        }
        $reason = sanitize_text_field(is_scalar($reason) ? (string) $reason : '');
        if ($reason === '') {
            return self::result(false, 'error', 'Say why the file is being deleted.', array(), $id);
        }
        if (mb_strlen($reason, 'UTF-8') > self::REASON_MAX) {
            return self::result(false, 'error', 'The reason is ' . mb_strlen($reason, 'UTF-8') . ' characters; the limit is ' . self::REASON_MAX . '.', array(), $id);
        }
        $now = current_time('mysql');
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::versions_table() . ' SET file_deleted_at = %s, file_deleted_by = %d, file_deleted_reason = %s WHERE id = %d AND file_deleted_at IS NULL',
            $now, $actor, $reason, intval($version->id)
        ));
        if ($done !== 1) {
            return self::result(true, 'success', 'That file was already deleted.', array(), $id);
        }
        $path = self::file_path($version->stored_name);
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
        self::record_change($id, 'file_deleted', array('number' => intval($version->number), 'reason' => $reason), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': version ' . intval($version->number) . '\'s file deleted by an administrator.');
        return self::result(true, 'success', 'Version ' . intval($version->number) . '\'s file was deleted. Its row stays in Versions.', array(), $id);
    }

    // ------------------------------------------------------------------
    // Writers: groups and settings
    // ------------------------------------------------------------------

    /** How many documents (any status) each group holds: key => n. */
    private static function group_counts() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT group_key, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY group_key');
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $r) {
            $out[(string) $r->group_key] = intval($r->n);
        }
        return $out;
    }

    /**
     * Save the groups (CAP). $rows: key => array(label, order, intro,
     * by_year, remove), plus a new group under any key not in the list
     * (made from its label). Held to the hash of the list the screen was
     * drawn from, read fresh. A group that still holds documents can't be
     * removed; at least one group stays. Intros keep only a, strong, em, p
     * and br. Records groups_saved (each added, removed and changed entry),
     * logs, refreshes the page.
     */
    public static function save_groups(array $rows, $expect_hash, $actor) {
        $fresh = self::fresh_option(self::GROUPS_OPTION);
        if ($fresh === null) {
            return self::result(false, 'error', 'The groups could not be read, so nothing was saved. Try again.');
        }
        if ((string) $expect_hash !== md5(serialize($fresh))) {
            return self::result(false, 'error', 'The groups were changed after this page loaded, so nothing was saved. Reload the page and try again.');
        }
        $current = self::groups($fresh);
        $counts = self::group_counts();
        $errors = array();
        $next = array();
        $added = array();
        $removed = array();
        $changed = array();
        $read = function ($row, $base, $name) use (&$errors) {
            $label = isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field((string) $row['label']) : $base['label'];
            if ($label === '') {
                $errors[] = 'Every group needs a name.';
            } elseif (mb_strlen($label, 'UTF-8') > self::GROUP_LABEL_MAX) {
                $errors[] = 'The name "' . $name . '" is ' . mb_strlen($label, 'UTF-8') . ' characters; the limit is ' . self::GROUP_LABEL_MAX . '.';
            }
            $order = $base['order'];
            if (isset($row['order'])) {
                $typed = is_scalar($row['order']) ? trim((string) $row['order']) : '';
                if (preg_match('/^\d{1,3}$/D', $typed) === 1) {
                    $order = intval($typed);
                } else {
                    $errors[] = 'The order for ' . $name . ' must be a whole number from 0 to 999.';
                }
            }
            $intro = $base['intro'];
            if (isset($row['intro']) && is_scalar($row['intro'])) {
                $intro = trim(wp_kses((string) $row['intro'], self::INTRO_TAGS));
                if (mb_strlen($intro, 'UTF-8') > self::INTRO_MAX) {
                    $errors[] = 'The intro for ' . $name . ' is ' . mb_strlen($intro, 'UTF-8') . ' characters; the limit is ' . self::INTRO_MAX . '.';
                }
            }
            $by_year = array_key_exists('by_year', $row) ? (empty($row['by_year']) ? 0 : 1) : $base['by_year'];
            return array('label' => $label, 'order' => $order, 'intro' => $intro, 'by_year' => $by_year);
        };
        foreach ($current as $key => $group) {
            $row = isset($rows[$key]) && is_array($rows[$key]) ? $rows[$key] : null;
            if ($row === null) {
                $next[$key] = $group;
                continue;
            }
            if (!empty($row['remove'])) {
                if (!empty($counts[$key])) {
                    $errors[] = $group['label'] . ' still holds ' . ($counts[$key] === 1 ? '1 document' : $counts[$key] . ' documents') . '. Move ' . ($counts[$key] === 1 ? 'it' : 'them') . ' to another group first.';
                    $next[$key] = $group;
                } else {
                    $removed[] = $key;
                }
                continue;
            }
            $next[$key] = $read($row, $group, $group['label']);
            if ($next[$key] !== $group) {
                $changed[] = $key;
            }
        }
        $last = 0;
        foreach ($next as $g) {
            $last = max($last, $g['order']);
        }
        foreach ($rows as $key => $row) {
            if (isset($current[(string) $key]) || !is_array($row)) {
                continue;
            }
            $label = isset($row['label']) && is_scalar($row['label']) ? sanitize_text_field((string) $row['label']) : '';
            if ($label === '') {
                continue;
            }
            $group = $read($row, array('label' => $label, 'order' => min(999, $last + 10), 'intro' => '', 'by_year' => 0), $label);
            $base = substr(trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower(sanitize_title($label))), '-'), 0, 36);
            $base = $base !== '' ? trim($base, '-') : 'group';
            $new_key = $base;
            for ($n = 2; isset($next[$new_key]) || isset($current[$new_key]); $n++) {
                $new_key = $base . '-' . $n;
            }
            $next[$new_key] = $group;
            $added[] = $new_key;
            $last = max($last, $group['order']);
        }
        if (empty($next)) {
            $errors[] = 'Keep at least one group.';
        }
        if (!empty($errors)) {
            return self::result(false, 'error', 'The groups were not saved.', array_values(array_unique($errors)));
        }
        if (empty($added) && empty($removed) && empty($changed) && !empty($fresh)) {
            return self::result(true, 'success', 'Nothing changed.');
        }
        update_option(self::GROUPS_OPTION, $next, false);
        self::$groups = null;
        $detail = array('added' => array(), 'removed' => array(), 'changed' => array());
        foreach ($added as $key) {
            $detail['added'][$key] = $next[$key];
        }
        foreach ($removed as $key) {
            $detail['removed'][$key] = $current[$key];
        }
        foreach ($changed as $key) {
            $detail['changed'][$key] = array($current[$key], $next[$key]);
        }
        self::record_change(0, 'groups_saved', $detail, $actor);
        $parts = array();
        foreach (array('added' => $added, 'removed' => $removed, 'changed' => $changed) as $word => $keys) {
            if (!empty($keys)) {
                $parts[] = $word . ' ' . implode(', ', $keys);
            }
        }
        self::log(self::LOG_DOCUMENT, 'Club document groups saved' . (empty($parts) ? '' : ': ' . implode('; ', $parts)) . '.');
        self::queue_purge('groups');
        return self::result(true, 'success', 'Groups saved.');
    }

    /**
     * Save the settings (administrators): max_mb (1 to 100), join_page (0
     * or a published page), contact_email (blank, or an email address).
     * Records which settings changed (never the address itself), logs.
     */
    public static function save_settings(array $fields, $actor) {
        $actor = intval($actor);
        if (!user_can($actor, 'manage_options')) {
            return self::result(false, 'error', 'Only an administrator can change these settings.');
        }
        $old = self::settings();
        $next = $old;
        $errors = array();
        if (array_key_exists('max_mb', $fields)) {
            $typed = is_scalar($fields['max_mb']) ? trim((string) $fields['max_mb']) : '';
            if (preg_match('/^\d{1,3}$/D', $typed) !== 1 || intval($typed) < 1 || intval($typed) > self::MAX_MB_LIMIT) {
                $errors[] = 'The size limit must be a whole number of MB from 1 to ' . self::MAX_MB_LIMIT . '.';
            } else {
                $next['max_mb'] = intval($typed);
            }
        }
        if (array_key_exists('join_page', $fields)) {
            $page = is_scalar($fields['join_page']) ? intval($fields['join_page']) : -1;
            if ($page < 0 || ($page > 0 && (get_post_type($page) !== 'page' || get_post_status($page) !== 'publish'))) {
                $errors[] = 'Choose a published page for Ways to join, or none.';
            } else {
                $next['join_page'] = $page;
            }
        }
        if (array_key_exists('contact_email', $fields)) {
            $email = is_scalar($fields['contact_email']) ? trim((string) $fields['contact_email']) : '';
            if ($email !== '' && !is_email($email)) {
                $errors[] = 'The contact email isn\'t an email address.';
            } else {
                $next['contact_email'] = $email === '' ? '' : sanitize_email($email);
            }
        }
        if (!empty($errors)) {
            return self::result(false, 'error', 'The settings were not saved.', $errors);
        }
        $changed = array();
        foreach ($next as $key => $value) {
            if ($old[$key] !== $value) {
                $changed[$key] = $key === 'contact_email' ? 'changed' : array($old[$key], $value);
            }
        }
        if (empty($changed)) {
            return self::result(true, 'success', 'Nothing changed.');
        }
        update_option(self::SETTINGS_OPTION, $next, false);
        self::record_change(0, 'settings_saved', $changed, $actor);
        $names = array('max_mb' => 'size limit', 'join_page' => 'ways to join page', 'contact_email' => 'contact email');
        $words = array();
        foreach (array_keys($changed) as $key) {
            $words[] = $names[$key];
        }
        self::log(self::LOG_DOCUMENT, 'Club documents settings saved: ' . implode(', ', $words) . ' changed.');
        return self::result(true, 'success', 'Settings saved.');
    }

    // ------------------------------------------------------------------
    // Import from the Media Library, and removing a public copy (6.6)
    // ------------------------------------------------------------------

    /** Every document's legacy paths: path => document id. */
    private static function legacy_index() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT id, legacy_paths FROM ' . self::table() . ' WHERE legacy_paths IS NOT NULL AND legacy_paths <> \'\'');
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            foreach (self::legacy_paths_of($row) as $path) {
                $out[$path] = intval($row->id);
            }
        }
        return $out;
    }

    /** The document whose legacy paths hold this uploads-relative path, or null (fewer than 100 rows are read). */
    public static function document_for_legacy_path($relative) {
        $index = self::legacy_index();
        $relative = ltrim((string) $relative, '/');
        return isset($index[$relative]) ? self::get_document($index[$relative]) : null;
    }

    /** An attachment's uploads-relative path (its _wp_attached_file), or ''. */
    private static function attachment_path($attachment_id) {
        $path = get_post_meta(intval($attachment_id), '_wp_attached_file', true);
        return is_string($path) ? ltrim($path, '/') : '';
    }

    /** The full path of an uploads-relative path, or '' when it leaves the uploads folder. */
    private static function uploads_file($relative) {
        $relative = ltrim((string) $relative, '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            return '';
        }
        $uploads = wp_get_upload_dir();
        return rtrim((string) $uploads['basedir'], '/\\') . '/' . $relative;
    }

    /**
     * The PDF attachments in the Media Library, for the import list: a list
     * of array(id, title, path, uploaded (Y-m-d), size, document (the id
     * of the document whose legacy paths hold it, or 0)).
     */
    public static function pdf_attachments() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date, m.meta_value AS path FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' ORDER BY p.post_date DESC, p.ID DESC"
        );
        $legacy = self::legacy_index();
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $path = ltrim((string) $row->path, '/');
            $file = self::uploads_file($path);
            $out[] = array(
                'id' => intval($row->ID),
                'title' => wp_specialchars_decode((string) $row->post_title, ENT_QUOTES),
                'path' => $path,
                'uploaded' => substr((string) $row->post_date, 0, 10),
                'size' => ($file !== '' && is_file($file)) ? intval(filesize($file)) : 0,
                'document' => isset($legacy[$path]) ? $legacy[$path] : 0,
            );
        }
        return $out;
    }

    /**
     * Other PDF attachments holding exactly the same file (same size, then
     * the same sha256): attachment id => path. Only same-size files are
     * hashed.
     */
    public static function identical_attachments($sha256, $size, $except_id = 0) {
        $out = array();
        foreach (self::pdf_attachments() as $a) {
            if ($a['id'] === intval($except_id) || $a['size'] !== intval($size) || $a['size'] < 1) {
                continue;
            }
            $file = self::uploads_file($a['path']);
            if ($file !== '' && is_file($file) && hash_equals((string) $sha256, (string) hash_file('sha256', $file))) {
                $out[$a['id']] = $a['path'];
            }
        }
        return $out;
    }

    /**
     * Copy a PDF from the Media Library into a new draft document
     * (administrators). $fields: dated (required: the date approved), note,
     * and optionally title, description, group_key, doc_year, slug (the
     * attachment's title and the first group by default). The attachment's
     * file is copied, never moved, and checked by sha256; version 1 records
     * source import and imported_from; legacy_paths holds the attachment's
     * path; audiences stays '' so someone must tick before publishing. The
     * result lists identical copies elsewhere in the Media Library
     * (duplicates: id => path), to be added with add_legacy_path(). The
     * attachment, its file and its metadata are left exactly as they were.
     */
    public static function import_attachment($attachment_id, array $fields, $actor) {
        global $wpdb;
        $actor = intval($actor);
        $attachment_id = intval($attachment_id);
        if (!user_can($actor, 'manage_options')) {
            return self::result(false, 'error', 'Only an administrator can copy a file from the Media Library.');
        }
        if (get_post_type($attachment_id) !== 'attachment' || get_post_mime_type($attachment_id) !== 'application/pdf') {
            return self::result(false, 'error', 'That isn\'t a PDF in the Media Library.');
        }
        $path = self::attachment_path($attachment_id);
        $source = self::uploads_file($path);
        if ($path === '' || $source === '' || !is_file($source)) {
            return self::result(false, 'error', 'That attachment\'s file is not on the server.');
        }
        $legacy = self::legacy_index();
        if (isset($legacy[$path])) {
            $owner = self::get_document($legacy[$path]);
            return self::result(false, 'error', 'That file was already copied into ' . ($owner ? self::title_with_year($owner) : 'another document') . '.', array(), $legacy[$path]);
        }
        list($dated, $note, $errors) = self::clean_date_note(isset($fields['dated']) ? $fields['dated'] : '', isset($fields['note']) ? $fields['note'] : '');
        if ($dated === '') {
            $errors = array('Set the date approved: the date the committee approved this version, not the upload date.');
        }
        $title = get_the_title($attachment_id);
        $groups = self::groups();
        $doc_fields = array(
            'title' => isset($fields['title']) && is_scalar($fields['title']) && trim((string) $fields['title']) !== '' ? (string) $fields['title'] : wp_specialchars_decode((string) $title, ENT_QUOTES),
            'description' => isset($fields['description']) ? $fields['description'] : '',
            'group_key' => isset($fields['group_key']) && is_scalar($fields['group_key']) && (string) $fields['group_key'] !== '' ? (string) $fields['group_key'] : (string) current(array_keys($groups)),
            'doc_year' => isset($fields['doc_year']) ? $fields['doc_year'] : '',
            'slug' => isset($fields['slug']) ? $fields['slug'] : '',
        );
        list($clean, $field_errors) = self::clean_fields($doc_fields, null);
        $errors = array_merge($errors, $field_errors);
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was copied.', $errors);
        }
        $storage = self::storage_status(true);
        if (!$storage['ok']) {
            return self::result(false, 'error', 'Nothing was copied: ' . $storage['problem']);
        }
        list($errors, $info) = self::check_pdf($source, wp_basename($path));
        if (!empty($errors)) {
            return self::result(false, 'error', 'Nothing was copied.', $errors);
        }
        $stored = self::store_file($source, false);
        if ($stored === '' || !hash_equals($info['sha256'], (string) hash_file('sha256', self::file_path($stored)))) {
            if ($stored !== '') {
                self::discard_file($stored);
            }
            return self::result(false, 'error', 'The copy didn\'t match the original, so nothing was saved. Try again.');
        }
        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        $position = 10 + intval($wpdb->get_var($wpdb->prepare('SELECT MAX(position) FROM ' . self::table() . ' WHERE group_key = %s', $clean['group_key'])));
        $done = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (slug, title, description, group_key, doc_year, position, audiences, status, legacy_paths, created_by, updated_by, created_at, updated_at, row_version)
             VALUES (%s, %s, %s, %s, NULLIF(%d, 0), %d, \'\', \'draft\', %s, NULLIF(%d, 0), NULLIF(%d, 0), %s, %s, 1)',
            $clean['slug'], $clean['title'], $clean['description'], $clean['group_key'], intval($clean['doc_year']), $position,
            wp_json_encode(array($path), JSON_UNESCAPED_SLASHES), $actor, $actor, $now, $now
        ));
        $id = $done ? intval($wpdb->insert_id) : 0;
        $inserted = $id > 0 ? self::insert_version($id, $stored, $info, $dated, $note, 'import', $path, $actor, $now) : null;
        $linked = $inserted !== null ? $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET current_version_id = %d WHERE id = %d', $inserted[0], $id)) : false;
        if ($id < 1 || $inserted === null || $linked !== 1) {
            $wpdb->query('ROLLBACK');
            self::discard_file($stored);
            return self::result(false, 'error', 'The document could not be saved, so nothing was copied. Try again.');
        }
        $wpdb->query('COMMIT');
        self::record_change($id, 'imported', array('attachment' => $attachment_id, 'path' => $path, 'dated' => $dated, 'fields' => self::diff(array(), $clean)), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': copied from Media Library attachment #' . $attachment_id . ' (draft).');
        delete_transient(self::LINKS_TRANSIENT);
        $duplicates = self::identical_attachments($info['sha256'], $info['size'], $attachment_id);
        $lines = array();
        foreach ($duplicates as $path_found) {
            $lines[] = 'The same file is also at ' . $path_found . '. Add it to this document, so its old links are checked and redirected too.';
        }
        $out = self::result(true, empty($lines) ? 'success' : 'warning', 'Copied as a draft; the Media Library file is unchanged. Check the title, description and address (it can\'t change once published), tick who can open it, then publish.', $lines, $id);
        $out['duplicates'] = $duplicates;
        return $out;
    }

    /**
     * Add an identical Media Library copy's path to a document's legacy
     * paths (administrators): the attachment's file must hold exactly
     * version 1's file (sha256), and no document may hold the path yet.
     * Compare-and-set; records, logs. The attachment is not touched.
     */
    public static function add_legacy_path($document_id, $attachment_id, $expect_row_version, $actor) {
        global $wpdb;
        $actor = intval($actor);
        if (!user_can($actor, 'manage_options')) {
            return self::result(false, 'error', 'Only an administrator can add an old copy.');
        }
        $doc = self::get_document($document_id);
        if (!$doc) {
            return self::result(false, 'error', 'That document was not found. Reload the page.');
        }
        $id = intval($doc->id);
        $path = self::attachment_path($attachment_id);
        $file = self::uploads_file($path);
        $first = self::version_number($id, 1);
        if ($path === '' || $file === '' || !is_file($file) || !$first || !hash_equals((string) $first->sha256, (string) hash_file('sha256', $file))) {
            return self::result(false, 'error', 'That file isn\'t the same as this document\'s version 1, so it wasn\'t added.', array(), $id);
        }
        $legacy = self::legacy_index();
        if (isset($legacy[$path])) {
            return self::result($legacy[$path] === $id, $legacy[$path] === $id ? 'success' : 'error', $legacy[$path] === $id ? 'It is already on this document.' : 'Another document already holds that file.', array(), $id);
        }
        $paths = self::legacy_paths_of($doc);
        $paths[] = $path;
        $now = current_time('mysql');
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET legacy_paths = %s, row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0) WHERE id = %d AND row_version = %d',
            wp_json_encode(array_values($paths), JSON_UNESCAPED_SLASHES), $now, $actor, $id, intval($expect_row_version)
        ));
        if ($done !== 1) {
            return self::stale($id, array(), $actor);
        }
        self::record_change($id, 'legacy_path_added', array('attachment' => intval($attachment_id), 'path' => $path), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': identical old copy, attachment #' . intval($attachment_id) . ', added to its old addresses.');
        delete_transient(self::LINKS_TRANSIENT);
        return self::result(true, 'success', 'Added. Its old links are checked too, and it can be removed like the first copy.', array(), $id);
    }

    /**
     * Take an old copy's path off a document that has never been on the
     * website (administrators): "Not this document's file", for a file
     * copied into the wrong draft. Its old link then no longer belongs to
     * this document, and the Media Library file (never touched) can be
     * copied into the right one. The draft's versions stay as they are.
     * Compare-and-set; records, logs.
     */
    public static function remove_legacy_path($document_id, $path, $expect_row_version, $actor) {
        global $wpdb;
        $actor = intval($actor);
        if (!user_can($actor, 'manage_options')) {
            return self::result(false, 'error', 'Only an administrator can take an old copy off a document.');
        }
        $doc = self::get_document($document_id);
        if (!$doc) {
            return self::result(false, 'error', 'That document was not found. Reload the page.');
        }
        $id = intval($doc->id);
        if (!empty($doc->published_at)) {
            return self::result(false, 'error', 'This document has been on the website, so its old copies stay with it.', array(), $id);
        }
        $paths = self::legacy_paths_of($doc);
        $path = is_scalar($path) ? (string) $path : '';
        if ($path === '' || !in_array($path, $paths, true)) {
            return self::result(false, 'error', 'That file isn\'t an old copy of this document. Reload the page.', array(), $id);
        }
        $paths = array_values(array_diff($paths, array($path)));
        $now = current_time('mysql');
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET legacy_paths = NULLIF(%s, \'\'), row_version = row_version + 1, updated_at = %s, updated_by = NULLIF(%d, 0) WHERE id = %d AND row_version = %d',
            empty($paths) ? '' : wp_json_encode($paths, JSON_UNESCAPED_SLASHES), $now, $actor, $id, intval($expect_row_version)
        ));
        if ($done !== 1) {
            return self::stale($id, array(), $actor);
        }
        self::record_change($id, 'legacy_path_removed', array('path' => $path), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $id . ': an old copy\'s path taken off (not this document\'s file).');
        delete_transient(self::LINKS_TRANSIENT);
        return self::result(true, 'success', 'Taken off. The Media Library file is unchanged and can be copied into the right document.', array(), $id);
    }

    /** A document's version with that number, or null. */
    private static function version_number($document_id, $number) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::versions_table() . ' WHERE document_id = %d AND number = %d', intval($document_id), intval($number)));
    }

    /**
     * Each of a document's legacy paths and whether its public copy is
     * still there: path => array(exists, attachment (id or 0), url). One
     * query for the attachments.
     */
    public static function legacy_copies($doc) {
        global $wpdb;
        $paths = self::legacy_paths_of($doc);
        if (empty($paths)) {
            return array();
        }
        $in = implode(',', array_fill(0, count($paths), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($in)",
            $paths
        ));
        $ids = array();
        foreach (is_array($rows) ? $rows : array() as $r) {
            $ids[(string) $r->meta_value] = intval($r->post_id);
        }
        $uploads = wp_get_upload_dir();
        $out = array();
        foreach ($paths as $path) {
            $file = self::uploads_file($path);
            $out[$path] = array(
                'exists' => $file !== '' && is_file($file),
                'attachment' => isset($ids[$path]) ? $ids[$path] : 0,
                'url' => rtrim((string) $uploads['baseurl'], '/') . '/' . $path,
            );
        }
        return $out;
    }

    /** The nonce action for removing one public copy: bound to the attachment, the document and its row_version. */
    public static function remove_copy_nonce_action($attachment_id, $document_id, $row_version) {
        return 'murvc_documents_remove_copy_' . intval($attachment_id) . '_' . intval($document_id) . '_' . intval($row_version);
    }

    /**
     * Why an old public copy can't be removed now, or '' when it can. The
     * document's address must answer (address_live(): not a draft, whose
     * address is page not found, so the old link would break); the file must
     * still be on the server, and its folder writable by the website (or the
     * Media Library entry would go and the file stay); and the file must be
     * exactly a version the club holds: a version of this document with the
     * same sha256, not deleted, whose stored file is there and still has
     * that sha256 (so a file replaced in the Media Library since the copy
     * is never deleted). Reads only.
     */
    public static function copy_problem($doc, $path) {
        list($problem) = self::copy_check($doc, $path);
        return $problem;
    }

    /** copy_problem()'s checks: array(problem ('' when it can be removed), the number of the version the club holds of it, or 0). */
    private static function copy_check($doc, $path) {
        if (!is_object($doc) || !self::address_live($doc)) {
            return array('Publish the document first: until then its address shows page not found, so the old link would break.', 0);
        }
        $file = self::uploads_file($path);
        if ($file === '' || !is_file($file)) {
            return array('That file is no longer on the server, so there is no public copy to remove.', 0);
        }
        $hash = (string) hash_file('sha256', $file);
        $same = array();
        foreach (self::versions(intval($doc->id)) as $v) {
            if (hash_equals((string) $v->sha256, $hash)) {
                $same[] = $v;
            }
        }
        if (empty($same)) {
            return array('The file in the Media Library has changed since it was copied, so it stays. Upload it as a new version first.', 0);
        }
        $held = 0;
        foreach ($same as $v) {
            $kept = empty($v->file_deleted_at) ? self::file_path($v->stored_name) : '';
            if ($kept !== '' && is_file($kept) && hash_equals((string) $v->sha256, (string) hash_file('sha256', $kept))) {
                $held = intval($v->number);
                break;
            }
        }
        if ($held < 1) {
            return array('The club\'s copy (version ' . intval($same[0]->number) . ') is missing or doesn\'t match, so the public copy stays. Upload the Media Library file as a new version first.', 0);
        }
        if (!wp_is_writable(dirname($file))) {
            return array('The website can\'t delete that file, because its folder belongs to another server account. Ask the host to give the uploads folder back to the website\'s account, then try again.', $held);
        }
        return array('', $held);
    }

    /**
     * What the Remove the public copy confirm screen shows (step 1, a GET):
     * array(ok, problem, document, attachment, path, url, links (the
     * old-link check's hits for this path), nonce_action, version (the
     * number of the version the club holds of that file)). Writes nothing.
     * With a problem (copy_problem()) it offers no button.
     */
    public static function remove_copy_facts($attachment_id, $document_id) {
        $doc = self::get_document($document_id);
        $path = self::attachment_path($attachment_id);
        $out = array('ok' => false, 'problem' => '', 'document' => $doc, 'attachment' => intval($attachment_id), 'path' => $path, 'url' => '', 'links' => array(), 'nonce_action' => '', 'version' => 0);
        if (!$doc || $path === '' || !in_array($path, self::legacy_paths_of($doc), true)) {
            $out['problem'] = 'That file isn\'t an old copy of this document.';
            return $out;
        }
        $uploads = wp_get_upload_dir();
        $out['url'] = rtrim((string) $uploads['baseurl'], '/') . '/' . $path;
        list($out['problem'], $out['version']) = self::copy_check($doc, $path);
        if ($out['problem'] !== '') {
            return $out;
        }
        $out['links'] = self::links_to_path($path);
        $out['nonce_action'] = self::remove_copy_nonce_action($attachment_id, $doc->id, $doc->row_version);
        $out['ok'] = true;
        return $out;
    }

    /**
     * Remove one old public copy from the Media Library (administrators;
     * step 2 of its confirm, never automatic). $confirm: method (must be
     * POST), document (id), row_version (as the confirm screen drew it) and
     * nonce (for remove_copy_nonce_action()). Checks again that the
     * attachment's path is in that document's legacy paths, then
     * copy_problem() (the document's address answers, the file is exactly a
     * version the club holds in storage, and the website can delete it),
     * then deletes the attachment for good (with WordPress's preview images
     * of it) and checks the file really went (when it didn't, it says so and
     * records public_copy_failed). The old address then redirects to the
     * document's address. One history row, one log line, and the old
     * address is refreshed in the page cache. This is the only code in Club
     * Manager that deletes an attachment; on the live site it runs only with
     * the club's go.
     */
    public static function remove_public_copy($attachment_id, $actor, array $confirm) {
        global $wpdb;
        $actor = intval($actor);
        $attachment_id = intval($attachment_id);
        if ($actor < 1 || !user_can($actor, 'manage_options') || $actor !== get_current_user_id()) {
            return self::result(false, 'error', 'Only an administrator can remove a public copy.');
        }
        if (!isset($confirm['method']) || strtoupper((string) $confirm['method']) !== 'POST') {
            return self::result(false, 'error', 'Confirm on the Remove the public copy screen.');
        }
        $document_id = isset($confirm['document']) ? intval($confirm['document']) : 0;
        $row_version = isset($confirm['row_version']) ? intval($confirm['row_version']) : 0;
        $nonce = isset($confirm['nonce']) && is_string($confirm['nonce']) ? $confirm['nonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::remove_copy_nonce_action($attachment_id, $document_id, $row_version))) {
            return self::result(false, 'error', 'That confirm has expired or the document changed since. Open Remove the public copy again.');
        }
        $doc = self::get_document($document_id);
        if (!$doc || intval($doc->row_version) !== $row_version) {
            return self::result(false, 'error', 'The document changed after the confirm screen was drawn. Open Remove the public copy again.');
        }
        $path = self::attachment_path($attachment_id);
        if (get_post_type($attachment_id) !== 'attachment' || $path === '' || !in_array($path, self::legacy_paths_of($doc), true)) {
            return self::result(false, 'error', 'That file isn\'t an old copy of this document, so nothing was deleted.', array(), $document_id);
        }
        $problem = self::copy_problem($doc, $path);
        if ($problem !== '') {
            return self::result(false, 'error', $problem, array(), $document_id);
        }
        $file = self::uploads_file($path);
        $now = current_time('mysql');
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET row_version = row_version + 1, updated_at = %s, updated_by = %d WHERE id = %d AND row_version = %d',
            $now, $actor, $document_id, $row_version
        ));
        if ($claimed !== 1) {
            return self::result(false, 'error', 'The document changed after the confirm screen was drawn. Open Remove the public copy again.', array(), $document_id);
        }
        $uploads = wp_get_upload_dir();
        $old_url = rtrim((string) $uploads['baseurl'], '/') . '/' . $path;
        $deleted = wp_delete_attachment($attachment_id, true);
        if (!$deleted) {
            self::record_change($document_id, 'public_copy_failed', array('attachment' => $attachment_id, 'path' => $path), $actor, $now);
            self::log(self::LOG_PROBLEM, 'Club document #' . $document_id . ': removing attachment #' . $attachment_id . ' failed.');
            return self::result(false, 'error', 'WordPress couldn\'t delete that file. Nothing else changed.', array(), $document_id);
        }
        // WordPress deletes the Media Library entry first and ignores a file it couldn't delete: check the file is gone.
        clearstatcache();
        if ($file !== '' && is_file($file)) {
            self::record_change($document_id, 'public_copy_failed', array('attachment' => $attachment_id, 'path' => $path, 'file_kept' => true), $actor, $now);
            self::log(self::LOG_PROBLEM, 'Club document #' . $document_id . ': attachment #' . $attachment_id . ' deleted from the Media Library, but its file is still on the server.');
            delete_transient(self::LINKS_TRANSIENT);
            return self::result(false, 'error', 'The Media Library entry was deleted, but the file is still on the server, so its old address still opens it. Delete it on the server: ' . $path, array(), $document_id);
        }
        self::record_change($document_id, 'public_copy_removed', array('attachment' => $attachment_id, 'path' => $path), $actor, $now);
        self::log(self::LOG_DOCUMENT, 'Club document #' . $document_id . ': old public copy, attachment #' . $attachment_id . ', deleted from the Media Library.');
        delete_transient(self::LINKS_TRANSIENT);
        self::queue_purge('public_copy_removed', '', $old_url);
        $lines = self::cache_warning($document_id, true);
        return self::result(true, empty($lines) ? 'success' : 'warning', 'The public copy was deleted. Its old address now sends people to the document\'s address.', $lines, $document_id);
    }

    // ------------------------------------------------------------------
    // The old-link check (design 6.6)
    // ------------------------------------------------------------------

    /** The address patterns of one document name: /documents/<slug> and murvc_document=<slug>, then no further name character. */
    private static function address_regex($slug) {
        return '#(?:/documents/|' . self::QUERY_VAR . '=)' . preg_quote((string) $slug, '#') . '(?![a-z0-9-])#';
    }

    /**
     * Search where links are typed for each needle: array(key => array(type
     * 'path'|'slug', value)). Looks at the content of published posts,
     * pages and products, the _menu_item_url and _um_custom_fields metas,
     * and the um_fields option (never a full scan of postmeta). Returns key
     * => list of hits array(where, id, title, edit_url). Titles are plain
     * text; the caller escapes.
     */
    public static function search_links(array $needles) {
        global $wpdb;
        $out = array();
        if (empty($needles)) {
            return $out;
        }
        $likes = array();
        $args = array();
        foreach ($needles as $key => $needle) {
            $out[$key] = array();
            $value = (string) $needle[1];
            $variants = $needle[0] === 'path' ? array($value) : array('/documents/' . $value, self::QUERY_VAR . '=' . $value);
            foreach ($variants as $v) {
                foreach (array($v, str_replace('/', '\\/', $v)) as $form) {
                    $likes[] = '%s';
                    $args[] = '%' . $wpdb->esc_like($form) . '%';
                }
            }
        }
        $match = function ($text, $needle) {
            $text = str_replace('\\/', '/', (string) $text);
            if ($needle[0] === 'path') {
                return strpos($text, (string) $needle[1]) !== false;
            }
            return preg_match(self::address_regex($needle[1]), $text) === 1;
        };
        $content_where = implode(' OR ', array_map(function ($p) {
            return 'post_content LIKE ' . $p;
        }, $likes));
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page', 'product') AND ($content_where)",
            $args
        ));
        foreach (is_array($posts) ? $posts : array() as $row) {
            foreach ($needles as $key => $needle) {
                if ($match($row->post_content, $needle)) {
                    $out[$key][] = array(
                        'where' => (string) $row->post_type,
                        'id' => intval($row->ID),
                        'title' => self::plain_title($row->post_title, ucfirst((string) $row->post_type) . ' #' . intval($row->ID)),
                        'edit_url' => admin_url('post.php?post=' . intval($row->ID) . '&action=edit'),
                    );
                }
            }
        }
        $meta_where = implode(' OR ', array_map(function ($p) {
            return 'm.meta_value LIKE ' . $p;
        }, $likes));
        $metas = $wpdb->get_results($wpdb->prepare(
            "SELECT m.post_id, m.meta_key, m.meta_value, p.post_title FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key IN ('_menu_item_url', '_um_custom_fields') AND ($meta_where)",
            $args
        ));
        foreach (is_array($metas) ? $metas : array() as $row) {
            foreach ($needles as $key => $needle) {
                if (!$match($row->meta_value, $needle)) {
                    continue;
                }
                $id = intval($row->post_id);
                if ((string) $row->meta_key === '_menu_item_url') {
                    $menus = wp_get_object_terms($id, 'nav_menu', array('fields' => 'all'));
                    $menu = (!is_wp_error($menus) && !empty($menus)) ? $menus[0] : null;
                    $out[$key][] = array(
                        'where' => 'menu item',
                        'id' => $id,
                        'title' => self::plain_title($row->post_title, 'Menu item #' . $id) . ($menu ? ' (menu ' . wp_specialchars_decode((string) $menu->name, ENT_QUOTES) . ')' : ''),
                        'edit_url' => $menu ? admin_url('nav-menus.php?action=edit&menu=' . intval($menu->term_id)) : admin_url('nav-menus.php'),
                    );
                } else {
                    $out[$key][] = array(
                        'where' => 'Ultimate Member form',
                        'id' => $id,
                        'title' => self::plain_title($row->post_title, 'Form #' . $id),
                        'edit_url' => admin_url('post.php?post=' . $id . '&action=edit'),
                    );
                }
            }
        }
        $fields = get_option('um_fields');
        if (!empty($fields)) {
            $text = is_string($fields) ? $fields : serialize($fields);
            foreach ($needles as $key => $needle) {
                if ($match($text, $needle)) {
                    $out[$key][] = array('where' => 'Ultimate Member field list', 'id' => 0, 'title' => 'Ultimate Member\'s shared fields', 'edit_url' => admin_url('edit.php?post_type=um_form'));
                }
            }
        }
        return $out;
    }

    /** A post title as plain text (decoded once; the caller escapes), or $untitled. */
    private static function plain_title($title, $untitled) {
        $title = trim(wp_specialchars_decode((string) $title, ENT_QUOTES));
        return $title !== '' ? $title : $untitled;
    }

    /** Where an old uploads path is still linked (the Remove the public copy confirm). */
    public static function links_to_path($path) {
        $hits = self::search_links(array('p' => array('path', (string) $path)));
        return $hits['p'];
    }

    /** Where a document's own address is linked (the Withdraw confirm). */
    public static function links_to_address($doc) {
        if (!$doc) {
            return array();
        }
        $hits = self::search_links(array('a' => array('slug', (string) $doc->slug)));
        return $hits['a'];
    }

    /**
     * The old-link check for the To do panel, kept for an hour (Check again
     * passes $fresh): document id => array(old (path => hits: still opens
     * the old file), withdrawn (hits linking a withdrawn document's own
     * address)). Only documents with something found are listed.
     */
    public static function old_links($fresh = false) {
        if (!$fresh) {
            $cached = get_transient(self::LINKS_TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }
        }
        global $wpdb;
        $rows = $wpdb->get_results('SELECT id, slug, status, legacy_paths FROM ' . self::table());
        $needles = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            foreach (self::legacy_paths_of($row) as $i => $path) {
                $needles['p' . intval($row->id) . '_' . $i] = array('path', $path);
            }
            if ((string) $row->status === 'retired') {
                $needles['a' . intval($row->id)] = array('slug', (string) $row->slug);
            }
        }
        $hits = self::search_links($needles);
        $out = array();
        foreach ($hits as $key => $list) {
            if (empty($list)) {
                continue;
            }
            if ($key[0] === 'a') {
                $id = intval(substr($key, 1));
                $out[$id]['withdrawn'] = $list;
            } else {
                list($id) = explode('_', substr($key, 1));
                $out[intval($id)]['old'][$needles[$key][1]] = $list;
            }
        }
        foreach ($out as $id => $entry) {
            $out[$id] += array('old' => array(), 'withdrawn' => array());
        }
        set_transient(self::LINKS_TRANSIENT, $out, HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Show the list: every hit of the old-link check as plain text, one line
     * each, to save before the links are repointed (the rollback list).
     */
    public static function old_links_text($fresh = false) {
        $lines = array();
        foreach (self::old_links($fresh) as $id => $entry) {
            $doc = self::get_document($id);
            $name = $doc ? self::title_with_year($doc) : 'Document #' . $id;
            foreach ($entry['old'] as $path => $hits) {
                foreach ($hits as $hit) {
                    $lines[] = $name . ': ' . $hit['where'] . ' "' . $hit['title'] . '" (#' . $hit['id'] . ') still opens the old file ' . $path;
                }
            }
            foreach ($entry['withdrawn'] as $hit) {
                $lines[] = $name . ': ' . $hit['where'] . ' "' . $hit['title'] . '" (#' . $hit['id'] . ') links its address, and it was withdrawn';
            }
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // The To do panel (design 3.1)
    // ------------------------------------------------------------------

    private static function admin_url_for($page, array $args = array(), $anchor = '') {
        $url = admin_url('admin.php?page=' . $page);
        foreach ($args as $key => $value) {
            $url .= '&' . $key . '=' . rawurlencode((string) $value);
        }
        return $url . ($anchor !== '' ? '#' . $anchor : '');
    }

    private static function todo_row($key, $state, $text, $url = '', $label = '', array $links = array(), $copy = '') {
        return array('key' => $key, 'state' => $state, 'text' => $text, 'url' => $url, 'label' => $label, 'links' => $links, 'copy' => $copy);
    }

    /**
     * The To do panel's rows: a list of array(key, state ('warn' | 'bad'),
     * text, url, label, links (list of array(title, url)), copy (an address
     * to show with a Copy button, or '')), 'bad' first. Only what needs
     * doing; it never blocks anything. Text is plain (the caller escapes
     * it). A few queries, plus one per document that has old copies; the
     * storage check and the old-link check are each kept for an hour.
     */
    public static function todo() {
        $rows = array();
        $storage = self::storage_summary();
        $admin = current_user_can('manage_options');
        if (!$storage['ok']) {
            $rows[] = self::todo_row('storage', 'bad', $storage['problem'] . ($admin ? '' : ' Ask the website maintainer.'), self::admin_url_for(self::PAGE_SETTINGS, array(), 'storage'), 'File storage');
        }
        $web_failed = self::web_check_failed();
        if ($web_failed !== '') {
            $rows[] = self::todo_row('web_check', 'bad', 'On ' . mysql2date('j M Y', $web_failed) . ' Check now could fetch a test file from the documents folder over the web, so the files are not private. ' . ($admin ? 'Ask the host to fix it, then run Check now again.' : 'Ask the website maintainer.'), self::admin_url_for(self::PAGE_SETTINGS, array(), 'storage'), 'File storage');
        }
        $docs = self::list_documents();
        $versions = array();
        foreach ($docs as $doc) {
            $versions[] = intval($doc->current_version_id);
        }
        $versions = self::versions_by_id($versions);
        foreach ($docs as $doc) {
            if ((string) $doc->status !== 'published') {
                continue;
            }
            $edit = self::admin_url_for(self::PAGE_EDIT, array('document' => intval($doc->id)));
            $v = isset($versions[intval($doc->current_version_id)]) ? $versions[intval($doc->current_version_id)] : null;
            if (!$v || ($storage['ok'] && !self::version_file_ok($v))) {
                $rows[] = self::todo_row('missing_' . $doc->id, 'bad', 'The file for ' . self::title_with_year($doc) . ' is missing from storage, so its address can\'t open it. Upload it again.', $edit, 'Open it');
            }
            if (trim((string) $doc->description) === '') {
                $rows[] = self::todo_row('description_' . $doc->id, 'warn', self::title_with_year($doc) . ' has no description.', $edit, 'Add one');
            }
        }
        $by_id = array();
        foreach ($docs as $doc) {
            $by_id[intval($doc->id)] = $doc;
        }
        $copies = array();
        foreach ($docs as $doc) {
            $copies[intval($doc->id)] = empty($doc->legacy_paths) ? array() : self::legacy_copies($doc);
        }
        foreach (self::old_links() as $id => $entry) {
            if (!isset($by_id[$id])) {
                continue;
            }
            $doc = $by_id[$id];
            $name = self::title_with_year($doc);
            // Links to an old file, split by whether that file is still there (it opens) or gone (a redirect).
            $split = array('open' => array(), 'redirect' => array());
            foreach ($entry['old'] as $path => $hits) {
                $gone = isset($copies[$id][$path]) && !$copies[$id][$path]['exists'];
                foreach ($hits as $hit) {
                    $split[$gone ? 'redirect' : 'open'][] = array('title' => $hit['where'] . ' ' . $hit['title'], 'url' => $hit['edit_url']);
                }
            }
            $address = self::document_url((string) $doc->slug);
            foreach ($split as $kind => $links) {
                $n = count($links);
                if ($n === 0) {
                    continue;
                }
                $them = $n === 1 ? 'it' : 'them';
                $text = $kind === 'redirect'
                    ? ($n === 1 ? '1 link to the old ' . $name . ' file goes' : $n . ' links to the old ' . $name . ' file go') . ' through a redirect to its address.'
                    : ($n === 1 ? '1 link still opens' : $n . ' links still open') . ' the old ' . $name . ' file.';
                if ((string) $doc->status === 'published') {
                    $text .= ($kind === 'redirect' ? ' Point ' : ' Change ') . $them . ($kind === 'redirect' ? ' at' : ' to') . ' its address:';
                } elseif (self::address_live($doc)) {
                    $text .= ' It was withdrawn on ' . self::nice_date($doc->retired_at, 'j M Y') . ', so take ' . $them . ' out.';
                } else {
                    $text .= ' Publish ' . $name . ' first, then change ' . $them . ' to its address.';
                }
                $copy = (string) $doc->status === 'published' ? $address : '';
                $rows[] = self::todo_row(($kind === 'redirect' ? 'redirect_' : 'old_') . $id, 'warn', $text, '', '', $links, $copy);
            }
            foreach ($entry['withdrawn'] as $hit) {
                $rows[] = self::todo_row('withdrawn_' . $id . '_' . $hit['id'], 'warn', ucfirst($hit['where']) . ' ' . $hit['title'] . ' links ' . $name . ', which was withdrawn on ' . self::nice_date($doc->retired_at, 'j M Y') . '.', $hit['edit_url'], 'Edit');
            }
        }
        foreach ($docs as $doc) {
            foreach ($copies[intval($doc->id)] as $path => $copy) {
                if (!$copy['exists']) {
                    continue;
                }
                $old_copies = self::admin_url_for(self::PAGE_EDIT, array('document' => intval($doc->id)), 'old-copies');
                if ($copy['attachment'] < 1) {
                    // The Media Library entry went but the file stayed (WordPress couldn't delete it): only the server can fix it.
                    $rows[] = self::todo_row('kept_' . $doc->id . '_' . md5($path), 'bad', 'The Media Library entry for the old copy of ' . self::title_with_year($doc) . ' is gone, but the file is still on the server at ' . $path . ', so its old link still opens it. Delete it on the server.' . ($admin ? '' : ' Ask the website maintainer.'), $old_copies, 'Old copies');
                } elseif (self::address_live($doc)) {
                    $rows[] = self::todo_row('copy_' . $doc->id . '_' . md5($path), 'warn', 'The old public copy of ' . self::title_with_year($doc) . ' is still in the Media Library (' . $path . '), so anyone with its old link can open it. Removing it is an administrator\'s step, under Old copies, once the links to it are changed.', $old_copies, 'Old copies');
                }
            }
        }
        if (empty(self::pages())) {
            $rows[] = self::todo_row('no_page', 'warn', 'No page shows the documents yet. Put [' . self::SHORTCODE . '] on the Policies and documents page.');
        }
        if (self::settings()['contact_email'] === '') {
            $rows[] = self::todo_row('contact', 'warn', 'No contact email is set, so the missing-file page gives the site\'s admin email.', self::admin_url_for(self::PAGE_SETTINGS, array(), 'settings'), 'Settings');
        }
        if (!self::has_purge_listener()) {
            // Public files were sent cacheable while the admin side last saw a listener; until CACHE_DAYS after that, one can still be kept.
            $seen = (string) get_option(self::PURGE_OPTION, '0');
            $files = $seen === 'on' || intval($seen) > time() - self::CACHE_DAYS * DAY_IN_SECONDS;
            $rows[] = self::todo_row('page_refresh', 'warn', 'Nothing refreshes the site cache when a document changes, so for up to ' . self::CACHE_DAYS . ' days logged-out visitors can see an old documents page'
                . ($files ? ', and a file that was public can still open after its ticks change' : '') . '. Until the page-refresh snippet is added, empty the whole site cache after each change.');
        }
        usort($rows, function ($a, $b) {
            return ($a['state'] === 'bad' ? 0 : 1) - ($b['state'] === 'bad' ? 0 : 1);
        });
        return $rows;
    }

    // ------------------------------------------------------------------
    // The pages that show documents, and the page refresh (design 6.10)
    // ------------------------------------------------------------------

    /**
     * The published pages and posts holding [club_documents]: id =>
     * array(title (plain), url), pages first, at most PAGES_MAX. Kept in
     * PAGES_TRANSIENT for 12 hours and forgotten when a page or post
     * changes. $store false never writes the transient (a visitor's
     * request). Cached for the request.
     */
    public static function pages($fresh = false, $store = true) {
        if (!$fresh && self::$pages !== null) {
            return self::$pages;
        }
        $found = $fresh ? false : ($store ? get_transient(self::PAGES_TRANSIENT) : self::stored_pages());
        if (!is_array($found)) {
            $found = self::read_pages();
            if ($store) {
                set_transient(self::PAGES_TRANSIENT, $found, 12 * HOUR_IN_SECONDS);
            }
        }
        self::$pages = $found;
        return $found;
    }

    /**
     * The pages list's transient for a request that writes nothing: what
     * get_transient() would return, or false when missing or expired, but
     * never deleting an expired one (core's get_transient() does, as it
     * reads, on a site without a persistent object cache).
     */
    private static function stored_pages() {
        if (wp_using_ext_object_cache()) {
            return get_transient(self::PAGES_TRANSIENT);
        }
        $timeout = get_option('_transient_timeout_' . self::PAGES_TRANSIENT);
        if ($timeout !== false && intval($timeout) < time()) {
            return false;
        }
        return get_option('_transient_' . self::PAGES_TRANSIENT);
    }

    private static function read_pages() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page', 'post') AND post_content LIKE %s ORDER BY (post_type = 'page') DESC, ID LIMIT %d",
            '%' . $wpdb->esc_like('[' . self::SHORTCODE) . '%', self::PAGES_MAX
        ));
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if (!has_shortcode((string) $row->post_content, self::SHORTCODE)) {
                continue;
            }
            $out[intval($row->ID)] = array(
                'title' => self::plain_title($row->post_title, 'Page #' . intval($row->ID)),
                'url' => (string) get_permalink(intval($row->ID)),
            );
        }
        return $out;
    }

    /**
     * save_post_page, save_post_post, deleted_post, trashed_post: forget the
     * pages list (other post types are left alone).
     */
    public static function forget_pages($post_id = 0, $post = null) {
        $post = $post instanceof WP_Post ? $post : get_post(intval($post_id));
        if ($post instanceof WP_Post && $post->post_type !== 'page' && $post->post_type !== 'post') {
            return;
        }
        delete_transient(self::PAGES_TRANSIENT);
        self::$pages = null;
    }

    /** Whether something (the site's page-cache snippet) listens on PURGE_ACTION in this request. */
    public static function has_purge_listener() {
        return (bool) has_action(self::PURGE_ACTION);
    }

    /**
     * Note whether this admin-side request (a Club documents screen, or the
     * end of a writer's request) has a listener, in PURGE_OPTION, written
     * only when that changes: 'on', or when it was last seen. The purge runs
     * in the writer's request, so a listener only the visitor's request has
     * (a snippet set to run on the front end only) never empties the cache.
     * Returns the value stored before.
     */
    public static function note_purge_listener() {
        $stored = get_option(self::PURGE_OPTION, '0');
        $stored = is_scalar($stored) ? (string) $stored : '0';
        $now = self::has_purge_listener();
        if ($now && $stored !== 'on') {
            update_option(self::PURGE_OPTION, 'on', true);
        } elseif (!$now && $stored === 'on') {
            update_option(self::PURGE_OPTION, (string) time(), true);
        }
        return $stored;
    }

    /** Whether the admin side last saw a listener (read by the route before a public file is sent cacheable). */
    public static function purge_confirmed() {
        return (string) get_option(self::PURGE_OPTION, '0') === 'on';
    }

    /**
     * A change to what a public document's address answers, made in a
     * request that can't refresh the site cache: when public files may have
     * been kept in the cache in the last CACHE_DAYS days (the admin side saw
     * a listener then), the line telling the editor to empty it, and one
     * problem log line. array() otherwise. $any: the change is to an old
     * upload address, which the server's cache may hold whatever was seen.
     */
    private static function cache_warning($document_id, $any) {
        if (self::has_purge_listener()) {
            return array();
        }
        $stored = self::note_purge_listener();
        $recent = $stored === 'on' || intval($stored) > time() - self::CACHE_DAYS * DAY_IN_SECONDS;
        if (!$any && !$recent) {
            return array();
        }
        self::log(self::LOG_PROBLEM, 'Club document #' . intval($document_id) . ': changed while nothing could refresh the site cache.');
        return array($any
            ? 'The old file may still be in the site cache at its old address for logged-out visitors: empty the whole site cache now.'
            : 'The old public file may still be in the site cache for logged-out visitors: empty the whole site cache now.');
    }

    /**
     * Ask for the documents pages, and the given addresses, to be refreshed
     * at the end of this request. $slug adds that document's canonical
     * pretty address (never the query form); $url adds another address
     * whose answer changed (an old upload address once its copy is gone).
     * However many writers queue one in a request, it runs once.
     */
    public static function queue_purge($reason, $slug = '', $url = '') {
        $reason = is_scalar($reason) ? sanitize_key((string) $reason) : '';
        if (empty(self::$purge_reasons)) {
            add_action('shutdown', array(__CLASS__, 'run_purge'), 5);
        }
        self::$purge_reasons[$reason !== '' ? $reason : 'change'] = true;
        if ($slug !== '' && preg_match(self::SLUG_PATTERN, (string) $slug) === 1) {
            self::$purge_urls[self::canonical_url($slug)] = true;
        }
        if ($url !== '') {
            self::$purge_urls[(string) $url] = true;
        }
    }

    /**
     * shutdown, priority 5 (and tests): clean_post_cache() on every page in
     * pages(true), then do_action(PURGE_ACTION, $page_ids, $urls, $reasons).
     * That action is the one way Club documents reaches the site's page
     * cache; the purge itself is a site snippet outside this plugin. Runs
     * only when something was queued, once per queue. Returns array(page
     * ids, urls, reasons).
     */
    public static function run_purge() {
        if (empty(self::$purge_reasons)) {
            return array(array(), array(), array());
        }
        $reasons = array_keys(self::$purge_reasons);
        $urls = array_keys(self::$purge_urls);
        self::$purge_reasons = array();
        self::$purge_urls = array();
        self::note_purge_listener();
        $ids = array_keys(self::pages(true));
        foreach ($ids as $id) {
            clean_post_cache($id);
        }
        do_action(self::PURGE_ACTION, $ids, $urls, $reasons);
        return array($ids, $urls, $reasons);
    }

    /** Refresh the documents page now (CAP): queue a refresh and log. */
    public static function refresh_pages($actor) {
        if (!user_can(intval($actor), self::CAP)) {
            return self::result(false, 'error', 'You can\'t do that.');
        }
        $n = count(self::pages(true));
        if ($n === 0) {
            return self::result(false, 'warning', 'No published page shows the documents yet, so there was nothing to refresh.');
        }
        self::queue_purge('manual');
        self::log(self::LOG_DOCUMENT, 'Club documents page refreshed by hand.');
        if (!self::has_purge_listener()) {
            return self::result(true, 'warning', 'Asked for the page to be refreshed, but nothing connects the site\'s page cache to Club documents yet, so visitors can see an old copy until it expires.');
        }
        return self::result(true, 'success', 'Asked for the page to be refreshed. Visitors see the change within a few minutes.');
    }

    // ------------------------------------------------------------------
    // History and the activity log
    // ------------------------------------------------------------------

    /**
     * Add one history row: detail as JSON (NULL when empty), $actor 0 stored
     * as NULL. Every writer calls this, so it also drops the request caches.
     */
    private static function record_change($document_id, $event, array $detail, $actor, $at = '') {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::changes_table() . " (document_id, event, detail, recorded_by, recorded_at) VALUES (%d, %s, NULLIF(%s, ''), NULLIF(%d, 0), %s)",
            intval($document_id), (string) $event, empty($detail) ? '' : (string) wp_json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), intval($actor), $at !== '' ? $at : current_time('mysql')
        ));
        self::flush_cache();
    }

    /**
     * The address was asked for a document whose current file is missing
     * from storage (the route's 503): one problem line per document per
     * day.
     */
    public static function note_missing_file($document_id) {
        $document_id = intval($document_id);
        if ($document_id < 1 || get_transient(self::MISSING_PREFIX . $document_id)) {
            return;
        }
        set_transient(self::MISSING_PREFIX . $document_id, 1, DAY_IN_SECONDS);
        self::log(self::LOG_PROBLEM, 'Club document #' . $document_id . ': its address was opened but the current file is missing from storage.');
    }

    /**
     * The one way Club documents writes to the activity log: $type must be
     * LOG_DOCUMENT or LOG_PROBLEM. Messages name documents and accounts by
     * #id only, never a name or an email, and are clipped to 500 characters.
     */
    private static function log($type, $message, $user_id = 0) {
        if (!in_array($type, array(self::LOG_DOCUMENT, self::LOG_PROBLEM), true) || !class_exists('TeamOversight_Log')) {
            return;
        }
        TeamOversight_Log::add($type, mb_substr((string) $message, 0, 500), $user_id ? array('user_id' => intval($user_id)) : array());
    }
}
