<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep it up (1.98.0, club request 25 Nov 2026): a volleyball mini-game over
 * the whole browser window, with a members' leaderboard. This class is the
 * engine: the scores table, the setting, the leaderboard names, the boards,
 * the runs (start and finish) and their checks, removal, the opt-out, the
 * deleted-account cleanup and the REST routes. The shortcode and the footer
 * link are TeamOversight_Keep_It_Up_Public; the admin screen is
 * TeamOversight_Keep_It_Up_Admin; the game itself is assets/keep-it-up.js.
 *
 * The rules:
 *  - Only logged-in members save scores. A run starts with start() (a
 *    one-time token tied to the account and the server's start time; one
 *    pending run per account, a new start replaces it) and ends with
 *    finish() (the token, the score and the touch times). finish() checks
 *    the token, the count, the order and gaps of the touches, the touches
 *    against the server's clock, the run's length and the score's size; a
 *    failed check saves nothing and says why in plain words.
 *  - Only a score that beats the player's best this MONTH is stored, so the
 *    table holds each player's improvements month by month. A month is the
 *    calendar month in the site's timezone at the moment the game ended.
 *  - The boards are this YEAR and all time (1.98.3, club request 26 Nov
 *    2026; this month and all time in 1.98.0 to 1.98.2). A year is the
 *    calendar year in the site's time, and its board is each player's best
 *    over that year's months. Saving stays monthly on purpose (1.98.3
 *    review): when an administrator removes a doubtful score, the player's
 *    best of every other month is still there to show, so Remove never
 *    costs them more than that month's lower games. The game says Saved.
 *    only for a new best this year.
 *  - A board is each player's best (this year, or all time): higher score
 *    first, then whoever got it first (achieved_gmt, then id). Removed rows
 *    and hidden players are left out; a hidden player still gets their own
 *    best and the place they would have.
 *  - Names on a board are board_name() only (first name and last initial),
 *    never a username, an email or an account id.
 *  - Rows are never deleted except when their account is deleted. An
 *    administrator's Remove marks a row removed (kept for the record).
 */
class TeamOversight_Keep_It_Up {

    const TABLE = 'team_keep_it_up_scores';

    /**
     * The setting: array(on => 0|1, page_id => the game page's id or 0).
     * Autoloaded, because the footer link reads it on every page. No account
     * id, so nothing for the account merge.
     */
    const OPTION = 'murvc_keep_it_up';
    /** '1' while the player chose to stay off the leaderboard (deleted when shown). In the merge's copy_if_empty(). */
    const HIDDEN_META = 'murvc_keep_it_up_hidden';
    /** The pending run: "TOKEN_HASH|STARTED_MS|p" (p pending, u used). Per account; the merge never copies it. */
    const RUN_META = 'murvc_keep_it_up_run';
    /** Starts: "LAST_START_MS|Y-m-d|COUNT" (the site's day). Per account; the merge never copies it. */
    const STARTS_META = 'murvc_keep_it_up_starts';

    const REST_NS = 'murvc/v1';
    const REST_BASE = '/keep-it-up';
    const LOG_TYPE = 'keep_it_up';

    /** A second start within this many milliseconds is refused (429). */
    const START_GAP_MS = 1500;
    /** More starts than this in one day (the site's day) are refused (429). */
    const DAY_CAP = 400;
    /** Touches closer together than this are refused. */
    const MIN_GAP_MS = 120;
    /** The last touch may be this much later than the server's elapsed time (network delay). */
    const NETWORK_SLACK_MS = 2000;
    /** A run longer than this is refused. */
    const MAX_RUN_MS = 7200000;
    const MAX_SCORE = 2000;
    /** The public boards' size, the compact board's, and the admin boards'. */
    const BOARD_SIZE = 10;
    const COMPACT_SIZE = 3;
    const ADMIN_BOARD_SIZE = 50;
    /** The name shown when an account has nothing usable. */
    const PLAYER = 'Player';
    /**
     * The public lists (hidden players left out) of this year and all time
     * are kept between requests this long (a transient), because anyone can
     * read the board and the HTTP answer is never cached. Every write drops
     * them (flush_cache()), so a board is never older than the last write;
     * a read racing a write can keep the old list for at most this long.
     */
    const SHARED_SECONDS = 30;
    const SHARED_KEY = 'murvc_kiu_ranked_';

    private static $hooks_registered = false;
    private static $tables_exist = null;
    /** Request cache of ranked boards, key year|hidden. */
    private static $ranked = array();
    /** Request cache of hidden_ids(). */
    private static $hidden_ids = null;

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    /** Added once, from team_oversight_init(). */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('deleted_user', array(__CLASS__, 'forget_user'), 10, 1);
        // A merge re-points scores and copies the hidden flag; its undo puts them back. Neither goes through our writers.
        add_action('murvc_account_merged', array(__CLASS__, 'flush_cache'), 10, 0);
        add_action('murvc_account_merge_undone', array(__CLASS__, 'flush_cache'), 10, 0);
    }

    /**
     * Forget the boards read so far: this request's and the shared public
     * lists of this year and all time (every writer calls it, and the tests).
     */
    public static function flush_cache() {
        self::$ranked = array();
        self::$hidden_ids = null;
        delete_transient(self::shared_key(null));
        delete_transient(self::shared_key(self::year_key()));
    }

    /** The transient that holds a shared public list ($year null: all time). */
    public static function shared_key($year) {
        return self::SHARED_KEY . ($year === null ? 'all' : (string) $year);
    }

    // ------------------------------------------------------------------
    // The table
    // ------------------------------------------------------------------

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * The scores table, short name => CREATE statement: one definition shared
     * by TeamOversight_Database::migrate_database() (behind a SHOW TABLES
     * check), create_tables() and team_oversight_create_tables(). One row per
     * improvement: user_id, the site month it was got in, the score, how long
     * the run took, when it was got (UTC, so a tie is decided right across a
     * daylight-saving change) and the removal marks. No unique key on
     * user_id: an account merge re-points ids, so the kept account can hold
     * two rows for a month, and every reader takes the best per account.
     */
    public static function tables_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return array(
            self::TABLE => "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                month char(7) NOT NULL DEFAULT '',
                score int(10) unsigned NOT NULL DEFAULT 0,
                duration_ms int(10) unsigned NOT NULL DEFAULT 0,
                achieved_gmt datetime NOT NULL,
                removed tinyint(1) NOT NULL DEFAULT 0,
                removed_by bigint(20) unsigned DEFAULT NULL,
                removed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY user_month (user_id, month),
                KEY month_score (month, removed, score),
                KEY removed_score (removed, score)
            ) ENGINE=InnoDB $charset_collate;",
        );
    }

    /** The table exists (the migration runs on init; a request before it can't use the game). */
    public static function tables_exist($fresh = false) {
        global $wpdb;
        if ($fresh || self::$tables_exist === null) {
            self::$tables_exist = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table())));
        }
        return self::$tables_exist;
    }

    // ------------------------------------------------------------------
    // The setting
    // ------------------------------------------------------------------

    /** array(on => bool, page_id => int). */
    public static function settings() {
        $raw = get_option(self::OPTION, array());
        $raw = is_array($raw) ? $raw : array();
        return array(
            'on' => !empty($raw['on']),
            'page_id' => isset($raw['page_id']) && is_scalar($raw['page_id']) ? max(0, intval($raw['page_id'])) : 0,
        );
    }

    /** The game is switched on (the table is checked where it is used). */
    public static function is_on() {
        $s = self::settings();
        return $s['on'];
    }

    /**
     * The game answers this account: the table exists and the game is on,
     * or the account is an administrator (club decision 25 Nov 2026: the
     * committee checks the page before launch, so an administrator gets the
     * board and saves scores like anyone while the game is off). A guest or
     * any other member while off gets the refusals (board 404, POSTs 403).
     */
    public static function is_open_for($user_id) {
        if (!self::tables_exist()) {
            return false;
        }
        if (self::is_on()) {
            return true;
        }
        $user_id = intval($user_id);
        return $user_id > 0 && user_can($user_id, 'manage_options');
    }

    /** The game page's address while the page is set and published, else ''. */
    public static function page_url() {
        $s = self::settings();
        if ($s['page_id'] <= 0 || get_post_status($s['page_id']) !== 'publish') {
            return '';
        }
        $url = get_permalink($s['page_id']);
        return is_string($url) ? $url : '';
    }

    /**
     * The one writer of the setting. $fields: on (bool), page_id (0 or a
     * page that exists and isn't binned). Administrators only. Every problem
     * is listed and nothing is written; no change writes nothing; each change
     * is one log line. Returns array(ok, errors, changed, message).
     */
    public static function save_settings(array $fields, $actor) {
        $actor = intval($actor);
        $out = array('ok' => false, 'errors' => array(), 'changed' => array(), 'message' => '');
        if ($actor <= 0 || !user_can($actor, 'manage_options')) {
            $out['errors'][] = 'Only administrators can change Keep it up.';
            return $out;
        }
        $on = !empty($fields['on']);
        $page_id = isset($fields['page_id']) && is_scalar($fields['page_id']) && preg_match('/^\d{1,20}$/', trim((string) $fields['page_id'])) ? intval($fields['page_id']) : -1;
        if ($page_id < 0) {
            $out['errors'][] = 'Choose the game page from the list.';
        } elseif ($page_id > 0) {
            $post = get_post($page_id);
            if (!$post || $post->post_type !== 'page' || in_array($post->post_status, array('trash', 'auto-draft', 'inherit'), true)) {
                $out['errors'][] = 'That page doesn\'t exist any more. Choose another.';
            }
        }
        if ($out['errors']) {
            return $out;
        }
        $old = self::settings();
        $new = array('on' => $on ? 1 : 0, 'page_id' => $page_id);
        if ((bool) $old['on'] === $on && $old['page_id'] === $page_id) {
            $out['ok'] = true;
            $out['message'] = 'No change.';
            return $out;
        }
        update_option(self::OPTION, $new, true);
        if ((bool) $old['on'] !== $on) {
            $out['changed'][] = $on ? 'Keep it up switched on' : 'Keep it up switched off';
        }
        if ($old['page_id'] !== $page_id) {
            $out['changed'][] = $page_id > 0
                ? 'Keep it up game page set to ' . self::page_words($page_id)
                : 'Keep it up game page cleared (was ' . self::page_words($old['page_id']) . ')';
        }
        foreach ($out['changed'] as $line) {
            TeamOversight_Log::add(self::LOG_TYPE, TeamOversight_Log::cut_bytes($line, 500));
        }
        $out['ok'] = true;
        $out['message'] = 'Saved.';
        return $out;
    }

    private static function page_words($page_id) {
        $page_id = intval($page_id);
        if ($page_id <= 0) {
            return 'none';
        }
        $title = trim(wp_strip_all_tags(TeamOversight_Email::decode(get_the_title($page_id))));
        return ($title !== '' ? $title . ' ' : '') . '(page #' . $page_id . ')';
    }

    // ------------------------------------------------------------------
    // Names and the opt-out
    // ------------------------------------------------------------------

    /**
     * The name on a board (club decision 25 Nov 2026): the first word of the
     * account's preferred name (TeamOversight_Email::preferred_given(), the
     * source the 1.94.1 greetings use), else of its first name when that is a
     * plain name, then a space, the first letter of the last name and a full
     * stop ("Sam K."). No usable last name: the first name alone. Nothing
     * usable: "Player". Never a username, a display name or an email. Both
     * names go through board_clean() first, here only (the email greetings
     * read the names as before).
     */
    public static function board_name($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return self::PLAYER;
        }
        $given = self::board_clean(TeamOversight_Email::preferred_given($user_id));
        if ($given === '') {
            $given = self::board_clean(TeamOversight_Email::plain_name(get_user_meta($user_id, 'first_name', true)));
        }
        $first = self::first_word($given);
        if ($first === '') {
            return self::PLAYER;
        }
        $last = self::board_clean(TeamOversight_Email::plain_name(get_user_meta($user_id, 'last_name', true), 60));
        if ($last !== '' && preg_match('/\p{L}/u', $last, $m)) {
            $initial = function_exists('mb_strtoupper') ? mb_strtoupper($m[0], 'UTF-8') : strtoupper($m[0]);
            return $first . ' ' . $initial . '.';
        }
        return $first;
    }

    /**
     * A plain name made safe for a public board (1.98.0 review): the letters
     * that draw nothing (the Hangul fillers U+115F, U+1160, U+3164, U+FFA0
     * and the Khmer U+17B4, U+17B5) are taken out, and a run of more than
     * two combining marks is cut to two, so a name can't be blank or pile
     * marks over the other rows. Spaces collapsed; '' when nothing is left.
     */
    private static function board_clean($text) {
        $text = (string) preg_replace('/[\x{115F}\x{1160}\x{3164}\x{FFA0}\x{17B4}\x{17B5}]/u', '', (string) $text);
        $text = (string) preg_replace('/(\p{M}{2})\p{M}+/u', '$1', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The first word of a plain name, without trailing dots, hyphens or apostrophes ('' if none starts with a letter). */
    private static function first_word($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $text);
        $word = (string) preg_replace('/[.\-\'\x{2019}]+\z/u', '', (string) $parts[0]);
        return preg_match('/^\p{L}/u', $word) === 1 ? $word : '';
    }

    /** The player chose to stay off the leaderboard. */
    public static function is_hidden($user_id) {
        $user_id = intval($user_id);
        return $user_id > 0 && (string) get_user_meta($user_id, self::HIDDEN_META, true) === '1';
    }

    /** Show or hide a player on the leaderboard (their scores are kept either way). */
    public static function set_shown($user_id, $show) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return false;
        }
        if ($show) {
            delete_user_meta($user_id, self::HIDDEN_META);
        } else {
            update_user_meta($user_id, self::HIDDEN_META, '1');
        }
        self::flush_cache();
        return true;
    }

    // ------------------------------------------------------------------
    // Years, months and the clock
    // ------------------------------------------------------------------

    /**
     * The site year ('Y', e.g. '2026') at a unix time (seconds, a float is
     * fine): the boards' period since 1.98.3. Worked out from the same clock
     * reading as month_key(), so a row's month always falls in its year.
     */
    public static function year_key($now = null) {
        $now = $now === null ? microtime(true) : (float) $now;
        return wp_date('Y', (int) floor($now));
    }

    /** $year is a board's period: four digits ('2026'). */
    private static function is_year($year) {
        return is_string($year) && preg_match('/^\d{4}$/D', $year) === 1;
    }

    /** The site month ('Y-m') at a unix time (seconds, a float is fine): the month a row was got in. */
    public static function month_key($now = null) {
        $now = $now === null ? microtime(true) : (float) $now;
        return wp_date('Y-m', (int) floor($now));
    }

    /** "November 2026" for '2026-11'. */
    public static function month_label($month) {
        if (!is_string($month) || !preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            return '';
        }
        try {
            $d = new DateTimeImmutable($m[1] . '-' . $m[2] . '-15 12:00:00', wp_timezone());
        } catch (\Exception $e) {
            return '';
        }
        return wp_date('F Y', $d->getTimestamp());
    }

    private static function ms($now) {
        return (int) round((float) $now * 1000);
    }

    // ------------------------------------------------------------------
    // The boards
    // ------------------------------------------------------------------

    /**
     * Each account's best row, best first: higher score, then the earlier
     * achieved_gmt, then the lower id. $year a site year ('2026', from
     * year_key(): the rows whose month falls in it) or null for all time;
     * anything else is an empty board. Removed rows never count; hidden
     * players only with $include_hidden; rows of an account that no longer
     * exists never. Objects: id, user_id, month, score, achieved_gmt,
     * duration_ms.
     *
     * The reads grow with the rows, never with their square (1.98.0
     * review): a GROUP BY finds each account's top score, the join keeps
     * only rows with it, and the first of those per account in board order
     * is its best (a correlated "no better row" check per row cost an
     * account's rows squared, which a scripted account could inflate). The
     * public list is that list less the hidden accounts (one query for them,
     * hidden_ids()). The public lists of this year and all time are shared
     * between requests for SHARED_SECONDS (see flush_cache()); the
     * improvement check in process_finish() and the admin boards include
     * hidden players, so they never read the shared copy.
     */
    public static function ranked($year = null, $include_hidden = false) {
        global $wpdb;
        if (!self::tables_exist() || ($year !== null && !self::is_year($year))) {
            return array();
        }
        $key = ($year === null ? '*' : $year) . '|' . ($include_hidden ? 1 : 0);
        if (isset(self::$ranked[$key])) {
            return self::$ranked[$key];
        }
        if (!$include_hidden) {
            $shared = $year === null || $year === self::year_key();
            if ($shared) {
                $hit = get_transient(self::shared_key($year));
                if (is_array($hit) && isset($hit['rows']) && is_array($hit['rows'])) {
                    self::$ranked[$key] = $hit['rows'];
                    return $hit['rows'];
                }
            }
            $hidden = self::hidden_ids();
            $rows = array();
            foreach (self::ranked($year, true) as $r) {
                if (!isset($hidden[$r->user_id])) {
                    $rows[] = $r;
                }
            }
            self::$ranked[$key] = $rows;
            if ($shared) {
                set_transient(self::shared_key($year), array('rows' => $rows), self::SHARED_SECONDS);
            }
            return $rows;
        }
        $t = self::table();
        // A year's rows are its twelve months ('2026-01' to '2026-12'): a range on the month column, so the month_score key serves it.
        $sm = $year === null ? '' : $wpdb->prepare(' AND s.month BETWEEN %s AND %s', $year . '-01', $year . '-12');
        $bm = $year === null ? '' : $wpdb->prepare(' AND month BETWEEN %s AND %s', $year . '-01', $year . '-12');
        $found = $wpdb->get_results("SELECT s.id, s.user_id, s.month, s.score, s.achieved_gmt, s.duration_ms FROM $t s
            JOIN (SELECT user_id, MAX(score) AS top FROM $t WHERE removed = 0$bm GROUP BY user_id) b ON b.user_id = s.user_id AND b.top = s.score
            JOIN {$wpdb->users} u ON u.ID = s.user_id
            WHERE s.removed = 0$sm
            ORDER BY s.score DESC, s.achieved_gmt ASC, s.id ASC");
        $rows = array();
        $seen = array();
        foreach (is_array($found) ? $found : array() as $r) {
            $r->id = intval($r->id);
            $r->user_id = intval($r->user_id);
            $r->score = intval($r->score);
            $r->duration_ms = intval($r->duration_ms);
            // An account with its top score in more than one row (a year or all time: one row a month, or two rows after a merge): the first is its best.
            if (isset($seen[$r->user_id])) {
                continue;
            }
            $seen[$r->user_id] = true;
            $rows[] = $r;
        }
        self::$ranked[$key] = $rows;
        return $rows;
    }

    /** The accounts that chose to stay off the leaderboard, as user id => true (one query a request). */
    private static function hidden_ids() {
        global $wpdb;
        if (self::$hidden_ids === null) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'", self::HIDDEN_META));
            self::$hidden_ids = array();
            foreach (is_array($ids) ? $ids : array() as $id) {
                self::$hidden_ids[intval($id)] = true;
            }
        }
        return self::$hidden_ids;
    }

    /** $a ranks ahead of $b (higher score, then got it first). */
    private static function ahead($a, $b) {
        if ($a->score !== $b->score) {
            return $a->score > $b->score;
        }
        if ($a->achieved_gmt !== $b->achieved_gmt) {
            return strcmp((string) $a->achieved_gmt, (string) $b->achieved_gmt) < 0;
        }
        return $a->id < $b->id;
    }

    /** An account's best row for a year (or all time), hidden or not; null with none. */
    public static function best_row($user_id, $year = null) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return null;
        }
        foreach (self::ranked($year, true) as $r) {
            if ($r->user_id === $user_id) {
                return $r;
            }
        }
        return null;
    }

    /** An account's best score for a year (or all time), 0 with none. */
    public static function best_score($user_id, $year = null) {
        $r = self::best_row($user_id, $year);
        return $r ? $r->score : 0;
    }

    /**
     * An account's best score in one site month ('Y-m'), removed rows left
     * out, 0 with none: the saving rule's bar (process_finish()). One
     * indexed read (user_month); not cached, since it is read once a game.
     */
    public static function best_month($user_id, $month) {
        global $wpdb;
        $user_id = intval($user_id);
        if ($user_id <= 0 || !is_string($month) || preg_match('/^\d{4}-\d{2}$/D', $month) !== 1 || !self::tables_exist()) {
            return 0;
        }
        return intval($wpdb->get_var($wpdb->prepare('SELECT MAX(score) FROM ' . self::table() . ' WHERE user_id = %d AND month = %s AND removed = 0', $user_id, $month)));
    }

    /**
     * An account's place on a board: 1 + the shown players ranked ahead of
     * its best (so a hidden player gets the place they would have). 0 with
     * no score.
     */
    public static function place($user_id, $year = null) {
        $mine = self::best_row($user_id, $year);
        if (!$mine) {
            return 0;
        }
        $place = 1;
        foreach (self::ranked($year, false) as $r) {
            if ($r->user_id !== $mine->user_id && self::ahead($r, $mine)) {
                $place++;
            }
        }
        return $place;
    }

    /** A player's standing at $now: shown, bests and places this year and all time. */
    public static function standing($user_id, $now = null) {
        $year = self::year_key($now);
        return array(
            'shown' => !self::is_hidden($user_id),
            'year' => $year,
            'best_year' => self::best_score($user_id, $year),
            'best_all' => self::best_score($user_id, null),
            'place_year' => self::place($user_id, $year),
            'place_all' => self::place($user_id, null),
        );
    }

    /**
     * One public board: the top $limit shown players as rows of place, name,
     * score and me (true on the viewer's own row). No account id, username or
     * email ever goes in it.
     */
    public static function board($year, $limit, $viewer_id = 0) {
        $viewer_id = intval($viewer_id);
        $top = array_slice(self::ranked($year, false), 0, max(1, intval($limit)));
        $ids = array();
        foreach ($top as $r) {
            $ids[] = $r->user_id;
        }
        if ($ids) {
            update_meta_cache('user', $ids);
        }
        $rows = array();
        foreach ($top as $i => $r) {
            $rows[] = array(
                'place' => $i + 1,
                'name' => self::board_name($r->user_id),
                'score' => $r->score,
                'me' => $viewer_id > 0 && $r->user_id === $viewer_id,
            );
        }
        return $rows;
    }

    /**
     * The admin's board: the top $limit players, hidden ones included and
     * marked, each with the id of its best row (for Remove). Rows of
     * array(id, user_id, place, name, score, achieved_gmt, month (the month
     * the score was got in), hidden). $year a site year or null for all time.
     */
    public static function admin_board($year, $limit = self::ADMIN_BOARD_SIZE) {
        $top = array_slice(self::ranked($year, true), 0, max(1, intval($limit)));
        $ids = array();
        foreach ($top as $r) {
            $ids[] = $r->user_id;
        }
        if ($ids) {
            update_meta_cache('user', $ids);
        }
        $out = array();
        $shown_place = 0;
        foreach ($top as $r) {
            $hidden = self::is_hidden($r->user_id);
            $out[] = array(
                'id' => $r->id,
                'user_id' => $r->user_id,
                'place' => $hidden ? 0 : ++$shown_place,
                'name' => self::board_name($r->user_id),
                'score' => $r->score,
                'achieved_gmt' => $r->achieved_gmt,
                'month' => (string) $r->month,
                'hidden' => $hidden,
            );
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The requests (each returns array(status, body); the routes and the tests call them)
    // ------------------------------------------------------------------

    private static function out($status, array $body) {
        return array('status' => intval($status), 'body' => $body);
    }

    private static function refuse($status, $code, $reason) {
        return self::out($status, array('ok' => false, 'code' => $code, 'reason' => $reason));
    }

    /** The viewer is the logged-in account making the request, with its wp_rest nonce. null when fine, else the refusal. */
    private static function member_check($user_id, $nonce) {
        $user_id = intval($user_id);
        if ($user_id <= 0 || $user_id !== get_current_user_id() || !get_userdata($user_id)) {
            return self::refuse(401, 'guest', 'Log in to save your score.');
        }
        if (!is_string($nonce) || $nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::refuse(403, 'nonce', 'Your login has timed out. Reload the page to save scores.');
        }
        if (!self::is_open_for($user_id)) {
            return self::refuse(403, 'off', 'The game is switched off.');
        }
        return null;
    }

    /**
     * The leaderboard for anyone: this year's and all time's top $limit
     * (10, or 3 for the compact board), the year ('2026'), and for a
     * logged-in viewer their own standing (bests and places, also when
     * hidden). 404 while the game is off, except for an administrator
     * (is_open_for()).
     */
    public static function process_board($viewer_id, $limit = self::BOARD_SIZE, $now = null) {
        if (!self::is_open_for($viewer_id)) {
            return self::refuse(404, 'off', 'The game is switched off.');
        }
        $limit = max(1, min(self::BOARD_SIZE, intval($limit)));
        // One reading of the clock, so the board's year and the viewer's standing agree across midnight on New Year's Eve.
        $now = $now === null ? microtime(true) : (float) $now;
        $year = self::year_key($now);
        $viewer_id = intval($viewer_id);
        $viewer = null;
        if ($viewer_id > 0 && get_userdata($viewer_id)) {
            $viewer = self::standing($viewer_id, $now);
            unset($viewer['year']);
        }
        return self::out(200, array(
            'ok' => true,
            'year' => $year,
            'limit' => $limit,
            'boards' => array(
                'year' => self::board($year, $limit, $viewer_id),
                'all' => self::board(null, $limit, $viewer_id),
            ),
            'viewer' => $viewer,
        ));
    }

    /**
     * Start a run (logged-in members, with the wp_rest nonce): refused (429)
     * within START_GAP_MS of the last start or past DAY_CAP starts in the
     * site's day; else a new one-time token replaces any pending run.
     */
    public static function process_start($user_id, $nonce, $now = null) {
        $refused = self::member_check($user_id, $nonce);
        if ($refused) {
            return $refused;
        }
        $user_id = intval($user_id);
        $now = $now === null ? microtime(true) : (float) $now;
        $now_ms = self::ms($now);
        $day = wp_date('Y-m-d', (int) floor($now));
        $starts = self::read_starts($user_id);
        if ($starts['last'] > 0 && $now_ms >= $starts['last'] && $now_ms - $starts['last'] < self::START_GAP_MS) {
            return self::refuse(429, 'throttle', 'Games are starting too fast, so this one won\'t be saved.');
        }
        $count = $starts['day'] === $day ? $starts['count'] : 0;
        if ($count >= self::DAY_CAP) {
            return self::refuse(429, 'daily', 'That\'s ' . number_format(self::DAY_CAP) . ' games today. Scores save again tomorrow.');
        }
        $token = wp_generate_password(32, false, false);
        update_user_meta($user_id, self::RUN_META, hash('sha256', $token) . '|' . $now_ms . '|p');
        update_user_meta($user_id, self::STARTS_META, $now_ms . '|' . $day . '|' . ($count + 1));
        return self::out(200, array('ok' => true, 'token' => $token, 'started' => $now_ms));
    }

    /** array(last ms, day, count) from STARTS_META. */
    private static function read_starts($user_id) {
        $raw = (string) get_user_meta(intval($user_id), self::STARTS_META, true);
        $p = explode('|', $raw);
        return array(
            'last' => isset($p[0]) && ctype_digit($p[0]) ? intval($p[0]) : 0,
            'day' => isset($p[1]) ? (string) $p[1] : '',
            'count' => isset($p[2]) && ctype_digit($p[2]) ? intval($p[2]) : 0,
        );
    }

    /**
     * Finish a run (logged-in members, with the wp_rest nonce). $params:
     * token, score, touches (milliseconds from the start, one per point).
     * The token must be the account's pending run, unused (it is used up by
     * this call whatever follows); the touch count equals the score; the
     * times go up by at least MIN_GAP_MS; the last touch is no later than the
     * server's elapsed time plus NETWORK_SLACK_MS; the run is at most
     * MAX_RUN_MS; the score at most MAX_SCORE. A failed check stores nothing
     * and answers 200 with saved false and a plain reason (fails quietly).
     * A score that beats the player's best this month is stored (kept for
     * the record, so a removal falls back to the other months' bests); only
     * one that beats their best this year is Saved. for the player (code
     * saved, saved true). The answer carries the player's standing either
     * way.
     */
    public static function process_finish($user_id, $nonce, $params, $now = null) {
        global $wpdb;
        $refused = self::member_check($user_id, $nonce);
        if ($refused) {
            return $refused;
        }
        $user_id = intval($user_id);
        $now = $now === null ? microtime(true) : (float) $now;
        $now_ms = self::ms($now);
        $params = is_array($params) ? $params : array();
        $quiet = function ($code, $reason) use ($user_id, $now) {
            return self::out(200, array_merge(array(
                'ok' => true, 'saved' => false, 'new_best' => false, 'code' => $code, 'reason' => $reason,
                'line' => 'Not saved. ' . $reason,
            ), self::standing($user_id, $now)));
        };

        $token = isset($params['token']) && is_string($params['token']) ? $params['token'] : '';
        $run = self::read_run($user_id);
        if ($token === '' || !preg_match('/^[A-Za-z0-9]{1,64}$/', $token) || !$run || !hash_equals($run['hash'], hash('sha256', $token))) {
            return $quiet('token', 'A newer game was started, or this one didn\'t start properly.');
        }
        if ($run['state'] !== 'p') {
            return $quiet('used', 'This game was sent already.');
        }
        // Use the token up (compare-and-set on the stored value), so it can never finish twice.
        $pending = $run['hash'] . '|' . $run['started'] . '|p';
        if (!update_user_meta($user_id, self::RUN_META, $run['hash'] . '|' . $run['started'] . '|u', $pending)) {
            return $quiet('used', 'This game was sent already.');
        }

        $score = isset($params['score']) && (is_int($params['score']) || (is_string($params['score']) && ctype_digit($params['score'])) || (is_float($params['score']) && floor($params['score']) === $params['score']))
            ? intval($params['score']) : -1;
        $touches = isset($params['touches']) && is_array($params['touches']) ? array_values($params['touches']) : null;
        if ($score < 0 || $touches === null) {
            return $quiet('bad_input', 'The game sent something the site couldn\'t read.');
        }
        if ($score > self::MAX_SCORE || count($touches) > self::MAX_SCORE) {
            return $quiet('too_high', 'Scores over ' . number_format(self::MAX_SCORE) . ' can\'t be saved.');
        }
        if (count($touches) !== $score) {
            return $quiet('count', 'The touches don\'t add up to the score.');
        }
        $prev = -1;
        foreach ($touches as $t) {
            if (!(is_int($t) || (is_float($t) && floor($t) === $t) || (is_string($t) && ctype_digit($t))) || intval($t) < 0) {
                return $quiet('bad_input', 'The game sent something the site couldn\'t read.');
            }
            $t = intval($t);
            if ($prev >= 0 && $t <= $prev) {
                return $quiet('order', 'The touch times are out of order.');
            }
            if ($prev >= 0 && $t - $prev < self::MIN_GAP_MS) {
                return $quiet('gap', 'Two touches were too close together.');
            }
            $prev = $t;
        }
        $elapsed = $now_ms - $run['started'];
        if ($elapsed > self::MAX_RUN_MS) {
            return $quiet('too_long', 'Games longer than two hours can\'t be saved.');
        }
        if ($prev > $elapsed + self::NETWORK_SLACK_MS) {
            return $quiet('elapsed', 'The game\'s timing doesn\'t match the site\'s clock.');
        }

        // The same clock reading for both, so the row's month always falls in the year it is judged against.
        $month = self::month_key($now);
        $year = self::year_key($now);
        $best_month = self::best_month($user_id, $month);
        $best_year = self::best_score($user_id, $year);
        $best_all = self::best_score($user_id, null);
        $stored = false;
        if ($score > $best_month) {
            $stored = (bool) $wpdb->insert(self::table(), array(
                'user_id' => $user_id,
                'month' => $month,
                'score' => $score,
                'duration_ms' => max(0, min(4294967295, $elapsed)),
                'achieved_gmt' => gmdate('Y-m-d H:i:s', (int) floor($now)),
                'removed' => 0,
            ), array('%d', '%s', '%d', '%d', '%s', '%d'));
            self::flush_cache();
        }
        if (!$stored && $score > $best_month) {
            return $quiet('store', 'The site couldn\'t save it just now.');
        }
        // For the player, Saved. means it counts on the boards: a new best this year (a month's record alone is kept quietly).
        $saved = $stored && $score > $best_year;
        return self::out(200, array_merge(array(
            'ok' => true,
            'saved' => $saved,
            'new_best' => $saved && $score > $best_all,
            'code' => $saved ? 'saved' : 'not_better',
            'reason' => $saved ? '' : 'Your best this year is ' . number_format($best_year) . '.',
            'line' => $saved ? 'Saved.' : 'Not a new best. Your best this year is ' . number_format($best_year) . '.',
        ), self::standing($user_id, $now)));
    }

    /** array(hash, started ms, state 'p'|'u') from RUN_META, or null. */
    private static function read_run($user_id) {
        $raw = (string) get_user_meta(intval($user_id), self::RUN_META, true);
        $p = explode('|', $raw);
        if (count($p) !== 3 || !preg_match('/^[0-9a-f]{64}$/', $p[0]) || !ctype_digit($p[1]) || !in_array($p[2], array('p', 'u'), true)) {
            return null;
        }
        return array('hash' => $p[0], 'started' => intval($p[1]), 'state' => $p[2]);
    }

    /** "Show me on the leaderboard" (logged-in members, with the wp_rest nonce). */
    public static function process_visibility($user_id, $nonce, $show) {
        $refused = self::member_check($user_id, $nonce);
        if ($refused) {
            return $refused;
        }
        $show = filter_var($show, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($show === null) {
            return self::refuse(400, 'bad_input', 'Tick or untick the box again.');
        }
        self::set_shown($user_id, $show);
        return self::out(200, array('ok' => true, 'shown' => !self::is_hidden($user_id)));
    }

    // ------------------------------------------------------------------
    // Removal and deleted accounts
    // ------------------------------------------------------------------

    /**
     * Remove one score from the boards (administrators only): the row is
     * marked removed (kept for the record), so the player's next best shows.
     * Returns array(ok, message).
     */
    public static function remove_score($score_id, $actor) {
        global $wpdb;
        $score_id = intval($score_id);
        $actor = intval($actor);
        if ($actor <= 0 || !user_can($actor, 'manage_options')) {
            return array('ok' => false, 'message' => 'Only administrators can remove scores.');
        }
        if (!self::tables_exist()) {
            return array('ok' => false, 'message' => 'Keep it up isn\'t set up on this site yet. Reload the page.');
        }
        $row = $score_id > 0 ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $score_id)) : null;
        if (!$row) {
            return array('ok' => false, 'message' => 'That score doesn\'t exist any more.');
        }
        if (intval($row->removed) === 1) {
            return array('ok' => false, 'message' => 'That score was already removed.');
        }
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET removed = 1, removed_by = %d, removed_at = %s WHERE id = %d AND removed = 0', $actor, current_time('mysql'), $score_id));
        if (!$n) {
            return array('ok' => false, 'message' => 'That score was already removed.');
        }
        self::flush_cache();
        $label = self::month_label((string) $row->month);
        TeamOversight_Log::add(self::LOG_TYPE, TeamOversight_Log::cut_bytes('Keep it up score removed: ' . number_format(intval($row->score)) . ($label !== '' ? ' in ' . $label : '') . ' (kept for the record)', 500), array('user_id' => intval($row->user_id)));
        return array('ok' => true, 'message' => 'Removed. Their next best shows in its place.');
    }

    /** deleted_user: a deleted account's scores go too (a merge re-points them before it deletes an account). */
    public static function forget_user($user_id) {
        global $wpdb;
        $user_id = intval($user_id);
        if ($user_id <= 0 || !self::tables_exist()) {
            return;
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE user_id = %d', $user_id));
        self::flush_cache();
    }

    // ------------------------------------------------------------------
    // REST
    // ------------------------------------------------------------------

    /** The full address of one route (board, start, finish, settings). */
    public static function route_url($name) {
        return rest_url(self::REST_NS . self::REST_BASE . '/' . $name);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NS, self::REST_BASE . '/board', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_board'),
            'permission_callback' => '__return_true',
        ));
        foreach (array('start' => 'rest_start', 'finish' => 'rest_finish', 'settings' => 'rest_settings') as $name => $callback) {
            register_rest_route(self::REST_NS, self::REST_BASE . '/' . $name, array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, $callback),
                'permission_callback' => array(__CLASS__, 'rest_member'),
            ));
        }
    }

    /** Saving needs a logged-in account (a cookie request without the wp_rest nonce reaches here as a guest). */
    public static function rest_member() {
        if (is_user_logged_in()) {
            return true;
        }
        return new WP_Error('murvc_keep_it_up_guest', 'Log in to save your score.', array('status' => 401));
    }

    private static function nonce_of($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || $nonce === '') {
            $nonce = $request->get_param('_wpnonce');
        }
        return is_string($nonce) ? $nonce : '';
    }

    public static function rest_board($request) {
        $limit = $request->get_param('limit');
        $limit = is_scalar($limit) && ctype_digit((string) $limit) ? intval($limit) : self::BOARD_SIZE;
        return self::respond(self::process_board(get_current_user_id(), $limit));
    }

    public static function rest_start($request) {
        return self::respond(self::process_start(get_current_user_id(), self::nonce_of($request)));
    }

    public static function rest_finish($request) {
        return self::respond(self::process_finish(get_current_user_id(), self::nonce_of($request), array(
            'token' => $request->get_param('token'),
            'score' => $request->get_param('score'),
            'touches' => $request->get_param('touches'),
        )));
    }

    public static function rest_settings($request) {
        return self::respond(self::process_visibility(get_current_user_id(), self::nonce_of($request), $request->get_param('show')));
    }

    /** A request's answer with headers that keep every cache off it (the page cache, a proxy, the browser). */
    private static function respond(array $out) {
        $response = new WP_REST_Response($out['body'], $out['status']);
        $response->set_headers(self::no_cache_headers());
        return $response;
    }

    /** WordPress's no-cache headers (an Expires in the past included). */
    public static function no_cache_headers() {
        $base = function_exists('wp_get_nocache_headers') ? wp_get_nocache_headers() : array();
        $out = array();
        foreach (is_array($base) ? $base : array() as $name => $value) {
            if ($value !== false && $value !== '' && $value !== null) {
                $out[$name] = (string) $value;
            }
        }
        $out['Cache-Control'] = 'no-cache, must-revalidate, max-age=0, no-store, private';
        if (!isset($out['Expires'])) {
            $out['Expires'] = 'Wed, 11 Jan 1984 05:00:00 GMT';
        }
        return $out;
    }
}
