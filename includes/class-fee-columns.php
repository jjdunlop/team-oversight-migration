<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Team fee columns (1.95.0, club decision 13 Nov 2026): a named set of
 * fees for a team whose players pay a different fee (a team with a
 * specialist coach, say). The committee names a column on VVL Oversight ->
 * Configuration, types its amounts per fee class in the Fee Matrix, and
 * ticks the teams charged at it. Supporter, coach, assistant coach and
 * team manager places on those teams are charged as on any other team, and
 * a person whose playing fee the club covers (TeamOversight_Fee_Cover)
 * still pays the supporter column.
 *
 * 1.96.0, club decision 13 Nov 2026: a column is a PAIR of amounts, one
 * for the team's playing members and one for its Training only players,
 * each with a fee and an upper limit per fee class (a double column in the
 * Fee Matrix). A place whose raw role is playing_member is charged at the
 * playing amount, one whose raw role is training_only at the Training only
 * amount (COLUMN_ROLES, role_key()). Every column has both; there is no
 * switch per column.
 *
 * Fee-timeline seasons only (2027 on: applies()). For an earlier season
 * every reader returns the empty answer and nothing is drawn.
 *
 * The amounts are ordinary matrix data: fee_matrix and fee_matrix_limits
 * rows of the season. The playing amount's team_role is the column's key
 * (as stored by 1.95.x, so nothing was migrated); the Training only
 * amount's is the key plus TRAINING_SUFFIX (col_1_to). A blank cell has no
 * amount: it never falls back to the Playing member or Training only
 * amount. The name is admin-only. Players and coaches see amounts, never
 * the name, and a key (either form) is never printed.
 *
 * One option, not autoloaded (OPTION), keyed by four-digit season: columns
 * (key => label and retired; insertion order is display order) and teams
 * (team code => key). A key is KEY_PREFIX plus a number (col_1, col_2 ...),
 * never the name: a key sits in a priced bucket's charged_as and in a
 * quote's column, and the name must not reach a player or a coach through
 * one. A stored key never ends with TRAINING_SUFFIX (next_key() never makes
 * one, and normalise() drops one). It never changes on a rename and is
 * never reused (a retired column keeps its entry and its matrix rows). Not
 * team meta: update_team(), reset_default_teams() and seed_season_teams()
 * rewrite that wholesale. No account id is stored, so there is nothing for
 * the account merge. Nothing here is copied into another season.
 *
 * Readers: applies(), get(), live(), is_key(), is_live(), label(), teams(),
 * team_column(), teams_of(), in_use(), with a request cache dropped by
 * flush_cache() (also on murvc_offers_cache_flushed); is_live(), label()
 * and teams_of() take either form of a key. The key helpers role_key(),
 * base_key() and is_training_key() read nothing. The one writer: save() (a
 * team's column can only change before its playing or Training only offers
 * carry a quote; after a team change in a released season the season is
 * re-priced). The admin box: render_admin_box() on Configuration, posting
 * to admin-post (handle_post(), process_post()) with Post/Redirect/Get. The
 * engine's side is TeamOversight_Fees::team_fee_column() and
 * column_for_place().
 */
class TeamOversight_Fee_Columns {

    const OPTION = 'team_oversight_fee_columns';
    /** admin-post action of the Configuration box. */
    const ACTION = 'murvc_fee_columns';
    /** Nonce field; the nonce action is NONCE_ACTION plus the season. */
    const NONCE_FIELD = 'murvc_fee_columns_nonce';
    const NONCE_ACTION = 'murvc_fee_columns_';
    /** The notice (and, after a refused save, what was typed) for one redisplay: + user id. */
    const FLASH_PREFIX = 'murvc_fee_columns_flash_';
    const FLASH_SECONDS = 300;
    /** An existing log type (vvl channel): the season's configuration. */
    const LOG_TYPE = 'season_config';

    /** Every key starts with this; no team role does. */
    const KEY_PREFIX = 'col_';
    /**
     * 1.96.0, club decision 13 Nov 2026: the matrix team_role of a column's
     * Training only amount is its key plus this (col_1_to); the key itself
     * is the playing amount's. A stored key never ends with it.
     */
    const TRAINING_SUFFIX = '_to';
    /** 1.96.0: the raw roles charged at a column, in the order the Fee Matrix shows them (playing, then Training only). */
    const COLUMN_ROLES = array('playing_member', 'training_only');
    /** team_role is varchar(50) in fee_matrix and fee_matrix_limits. */
    const KEY_MAX = 50;
    /** The longest name a column can have, in characters. */
    const LABEL_MAX = 40;
    /** Live columns per season. */
    const MAX_COLUMNS = 4;
    /** Names a column can't take (compared in lower case): the standard columns. */
    const RESERVED_NAMES = array('playing member', 'training only', 'supporter');

    /** Normalised entries read this request: season => entry. */
    private static $cache = array();
    /** The whole option as refresh() read it from the database (null = read it with get_option()). */
    private static $fresh = null;

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_post'));
        // Every offers flush (each offer write, and the tests' overlays) drops this cache too.
        add_action('murvc_offers_cache_flushed', array(__CLASS__, 'flush_cache'));
    }

    /** Forget the entries read this request (save() calls it; so does every TeamOversight_Offers::flush_cache()). */
    public static function flush_cache() {
        self::$cache = array();
        self::$fresh = null;
    }

    /**
     * Read the stored columns straight from the database, for this request's
     * reads until the next flush_cache(). get_option() keeps a request's
     * first answer (or its "no such option" flag), so a long request, such
     * as a bulk approval, would otherwise go on quoting from a copy another
     * administrator has since changed. TeamOversight_Offers::approve() calls
     * it inside lock().
     */
    public static function refresh() {
        self::$cache = array();
        self::$fresh = self::read_stored();
    }

    /** The one named lock a change to the columns and a quote being fixed at approval both take. */
    public static function lock_name() {
        global $wpdb;
        return 'murvc_fee_columns_' . md5($wpdb->prefix);
    }

    /** Take the lock (true only when it was taken). */
    public static function lock($seconds = 5) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name(), intval($seconds))) === '1';
    }

    public static function unlock() {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
    }

    public static function valid_season($season) {
        return (is_string($season) || is_int($season)) && preg_match('/^\d{4}$/D', (string) $season) === 1;
    }

    /** Does the season have team fee columns at all? Fee-timeline seasons only (2027 on). */
    public static function applies($season) {
        return self::valid_season($season) && class_exists('TeamOversight_Fees') && TeamOversight_Fees::timeline_applies((string) $season);
    }

    /** A season with no columns. */
    public static function empty_entry() {
        return array('columns' => array(), 'teams' => array());
    }

    /** Is this a column key (a string starting with KEY_PREFIX)? Says nothing about whether a season has it. */
    public static function is_key($value) {
        return is_string($value) && strpos($value, self::KEY_PREFIX) === 0;
    }

    /**
     * A key as stored: KEY_PREFIX and more, at most KEY_MAX characters,
     * unchanged by sanitize_key(), no '|' and no ':'. 1.96.0: and not ending
     * with TRAINING_SUFFIX, which only the Training only amount's matrix role
     * carries. (next_key() makes col_ and a number, so a key plus the suffix
     * is far inside team_role's 50 characters.)
     */
    public static function valid_key($key) {
        return self::is_key($key) && strlen($key) > strlen(self::KEY_PREFIX) && strlen($key) <= self::KEY_MAX
            && sanitize_key($key) === $key && strpos($key, '|') === false && strpos($key, ':') === false
            && !self::ends_with_suffix($key);
    }

    /** Does a string end with TRAINING_SUFFIX (and hold more than it)? */
    private static function ends_with_suffix($key) {
        $length = strlen(self::TRAINING_SUFFIX);
        return strlen($key) > $length && substr($key, -$length) === self::TRAINING_SUFFIX;
    }

    /**
     * 1.96.0, club decision 13 Nov 2026: is this the matrix role of a
     * column's Training only amount? A column key that ends with
     * TRAINING_SUFFIX and holds a column key before it (col_1_to, never
     * col_to). Says nothing about whether a season has the column.
     */
    public static function is_training_key($key) {
        return self::is_key($key) && strlen($key) > strlen(self::KEY_PREFIX) + strlen(self::TRAINING_SUFFIX) && self::ends_with_suffix($key);
    }

    /**
     * 1.96.0: the column key behind a matrix role: col_1_to gives col_1,
     * col_1 gives col_1. Anything that isn't a column key gives ''.
     */
    public static function base_key($key) {
        if (!self::is_key($key)) {
            return '';
        }
        return self::is_training_key($key) ? substr($key, 0, -strlen(self::TRAINING_SUFFIX)) : $key;
    }

    /**
     * 1.96.0: the matrix role a place of a raw role is charged at on a team
     * charged at $column: the key itself for playing_member, the key plus
     * TRAINING_SUFFIX for training_only, '' for any other role or an empty
     * column. Reads nothing; never print what it returns.
     */
    public static function role_key($column, $role) {
        $column = is_string($column) ? $column : '';
        if ($column === '') {
            return '';
        }
        if ($role === 'playing_member') {
            return $column;
        }
        if ($role === 'training_only') {
            return $column . self::TRAINING_SUFFIX;
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    /** The season's entry, normalised: array(columns, teams). Empty for a season where applies() is false. */
    public static function get($season) {
        if (!self::applies($season)) {
            return self::empty_entry();
        }
        $season = (string) $season;
        if (!isset(self::$cache[$season])) {
            $all = is_array(self::$fresh) ? self::$fresh : get_option(self::OPTION, array());
            self::$cache[$season] = (is_array($all) && isset($all[$season])) ? self::normalise($all[$season]) : self::empty_entry();
        }
        return self::$cache[$season];
    }

    /**
     * The structural reading of an entry: never refuses. A column needs a
     * valid key and a name (trimmed); anything else is dropped (1.96.0: so
     * is a key ending with TRAINING_SUFFIX, valid_key()). A teams entry
     * naming a missing or retired column is dropped.
     */
    public static function normalise($entry) {
        $out = self::empty_entry();
        if (!is_array($entry)) {
            return $out;
        }
        $columns = (isset($entry['columns']) && is_array($entry['columns'])) ? $entry['columns'] : array();
        foreach ($columns as $key => $column) {
            if (!is_string($key) || !self::valid_key($key) || !is_array($column)) {
                continue;
            }
            $label = (isset($column['label']) && is_string($column['label'])) ? trim($column['label']) : '';
            if ($label === '') {
                continue;
            }
            $out['columns'][$key] = array('label' => $label, 'retired' => !empty($column['retired']));
        }
        $teams = (isset($entry['teams']) && is_array($entry['teams'])) ? $entry['teams'] : array();
        foreach ($teams as $team => $key) {
            $team = (string) $team;
            if ($team === '' || !is_string($key) || !isset($out['columns'][$key]) || $out['columns'][$key]['retired']) {
                continue;
            }
            $out['teams'][$team] = $key;
        }
        return $out;
    }

    /** The season's live (not retired) columns: key => name, in display order. */
    public static function live($season) {
        $out = array();
        $entry = self::get($season);
        foreach ($entry['columns'] as $key => $column) {
            if (empty($column['retired'])) {
                $out[$key] = $column['label'];
            }
        }
        return $out;
    }

    /** Is $key a live column of the season? 1.96.0: true for its Training only form (col_1_to) too. */
    public static function is_live($key, $season) {
        if (!is_string($key)) {
            return false;
        }
        $base = self::base_key($key);
        if ($base === '') {
            return false;
        }
        $live = self::live($season);
        return isset($live[$base]);
    }

    /**
     * The committee's name for a column of the season (a retired one too),
     * or ''. Admin-only text. 1.96.0: the same name for the Training only
     * form (the caller adds the role words).
     */
    public static function label($key, $season) {
        $base = is_string($key) ? self::base_key($key) : '';
        if ($base === '') {
            return '';
        }
        $entry = self::get($season);
        return isset($entry['columns'][$base]) ? $entry['columns'][$base]['label'] : '';
    }

    /**
     * Team code => key for the season: live columns only, and only teams
     * the season has (TeamOversight_Offers::teams_config()), in that order.
     */
    public static function teams($season) {
        $entry = self::get($season);
        if (empty($entry['teams'])) {
            return array();
        }
        $live = self::live($season);
        $out = array();
        foreach (self::teams_config($season) as $code => $team) {
            $code = (string) $code;
            if (isset($entry['teams'][$code]) && isset($live[$entry['teams'][$code]])) {
                $out[$code] = $entry['teams'][$code];
            }
        }
        return $out;
    }

    /**
     * The column a team's playing members are charged at in the season (a
     * key), or '' (the Playing member column). 1.96.0: its Training only
     * players are charged at the same column's Training only amount
     * (role_key()). The stored tick decides, not
     * teams(): pricing must not move because a team code was taken out of
     * Team Management (a stopped place still prices its stretch), so a team
     * with a column can't be deleted there either.
     */
    public static function team_column($team, $season) {
        $entry = self::get($season);
        $team = (string) $team;
        return isset($entry['teams'][$team]) ? (string) $entry['teams'][$team] : '';
    }

    /** What a form was drawn from: save() refuses a form drawn from an entry that has since changed. */
    public static function entry_hash(array $entry) {
        return md5((string) wp_json_encode($entry));
    }

    /** The codes of the teams charged at a column in the season. 1.96.0: the same teams for its Training only form (col_1_to). */
    public static function teams_of($key, $season) {
        $base = is_string($key) ? self::base_key($key) : '';
        $out = array();
        foreach (self::teams($season) as $code => $column) {
            if ($base !== '' && $column === $base) {
                $out[] = (string) $code;
            }
        }
        return $out;
    }

    /** The live columns at least one team is charged at: key => list of team codes, in display order. */
    public static function in_use($season) {
        $teams = self::teams($season);
        if (empty($teams)) {
            return array();
        }
        $out = array();
        foreach (self::live($season) as $key => $label) {
            $codes = array();
            foreach ($teams as $code => $column) {
                if ($column === $key) {
                    $codes[] = (string) $code;
                }
            }
            if (!empty($codes)) {
                $out[$key] = $codes;
            }
        }
        return $out;
    }

    /** The season's teams (code => entry): the offers' request-cached copy, else the database's. */
    private static function teams_config($season) {
        if (class_exists('TeamOversight_Offers')) {
            $config = TeamOversight_Offers::teams_config($season);
        } elseif (class_exists('TeamOversight_Database')) {
            $config = TeamOversight_Database::shared()->get_teams_config($season);
        } else {
            $config = array();
        }
        return is_array($config) ? $config : array();
    }

    // ------------------------------------------------------------------
    // The writer
    // ------------------------------------------------------------------

    /**
     * Save the season's columns. $fields: labels (key => name, for the
     * existing live columns), retire (list of keys), new_label (a name, ''
     * = none) and teams (team code => key or '', for every team shown; an
     * array of keys means the form ticked it under more than one column,
     * which is refused). Names are expected plain already (the handler runs
     * sanitize_text_field() after wp_unslash()); anything else is refused,
     * never cleaned or clipped. Everything is checked first and every
     * problem listed in errors, with nothing written. A team's column can
     * only change while it has no live quoted playing or (1.96.0) Training
     * only offer (TeamOversight_Offers::quoted_playing_offers()). Inside an
     * option-wide named lock the stored row is read straight from the
     * database (TeamOversight_Program_Setup::read_option_fresh()) and must
     * still be what was checked; then compare-before-write, write (autoload
     * off), read back. One season_config log line per change. After a team
     * change in a season whose fees are released, every invoice of the
     * season is re-priced (TeamOversight_Fees::reprice_season()). Returns array(ok,
     * errors (plain sentences), changed, notes (plain sentences for the
     * notice: what changed and what the re-price did)).
     */
    public static function save($season, array $fields, $actor) {
        if (!self::applies($season)) {
            return self::result(false, false, array('Team fee columns start with the ' . self::first_season() . ' season. Choose a season from ' . self::first_season() . ' on.'));
        }
        $season = (string) $season;
        if (intval($actor) <= 0 || !user_can(intval($actor), 'manage_options')) {
            return self::result(false, false, array('Only an administrator can change team fee columns.'));
        }
        $all = self::read_stored();
        if ($all === null) {
            return self::result(false, false, array('Team fee columns couldn\'t be read. Reload the page and try again.'));
        }
        $raw = isset($all[$season]) ? $all[$season] : array();
        $before = self::normalise($raw);
        // A page drawn before another save (a second tab, another administrator) would post the old names and ticks back.
        if (isset($fields['drawn']) && is_string($fields['drawn']) && $fields['drawn'] !== '' && !hash_equals(self::entry_hash($before), $fields['drawn'])) {
            return self::result(false, false, array('The ' . $season . ' team fee columns changed since this page was opened. Reload the page and try again.'));
        }
        $plan = self::plan($season, $before, $raw, $fields);
        if (!empty($plan['errors'])) {
            return self::result(false, false, $plan['errors']);
        }
        if ($plan['after'] === $before) {
            return self::result(true, false, array());
        }

        if (!self::lock()) {
            return self::result(false, false, array('Another save of team fee columns, or an offer approval, is running. Try again in a moment.'));
        }
        try {
            // Straight from the database, never get_option(): a stale
            // notoptions flag would read every season as missing, and the
            // write would keep only this one.
            wp_cache_delete(self::OPTION, 'options');
            $all = self::read_stored();
            if ($all === null) {
                return self::result(false, false, array('Team fee columns couldn\'t be read. Reload the page and try again.'));
            }
            $now = self::normalise(isset($all[$season]) ? $all[$season] : array());
            if ($now !== $before) {
                return self::result(false, false, array('The ' . $season . ' team fee columns changed while you were saving. Reload the page and try again.'));
            }
            // Counted again inside the lock: an approval fixes its quote inside the same lock, so none can land between this count and the write.
            foreach ($plan['team_codes'] as $code) {
                $quoted = self::quoted_offers($season, $code);
                if ($quoted > 0) {
                    return self::result(false, false, array(self::quoted_refusal($code, $quoted, self::teams_config($season))));
                }
            }
            $all[$season] = $plan['after'];
            update_option(self::OPTION, $all, false);
            wp_cache_delete(self::OPTION, 'options');
            $check = self::read_stored();
            if (!is_array($check) || !isset($check[$season]) || self::normalise($check[$season]) !== $plan['after']) {
                return self::result(false, false, array('The database did not take the change. Try again.'));
            }
        } finally {
            self::unlock();
        }

        // The engine reads the team's column through this cache and the offers' team config.
        self::flush_cache();
        if (class_exists('TeamOversight_Offers')) {
            TeamOversight_Offers::flush_cache();
        }
        $notes = array();
        foreach ($plan['changes'] as $line) {
            $line = self::cut($line);
            if (class_exists('TeamOversight_Log')) {
                TeamOversight_Log::add(self::LOG_TYPE, $line);
            }
            $notes[] = $line . '.';
        }
        if ($plan['teams_changed']) {
            $notes = array_merge($notes, self::reprice($season));
        }
        return self::result(true, true, array(), $notes);
    }

    /**
     * What save() would do, worked out from the stored entry (normalised
     * $before, $raw as stored): array(after (normalised), errors, changes
     * (the log lines, in order: added, renamed, teams, retired),
     * teams_changed). Reads the team config, the offers and, for a new
     * column, the keys the season's matrix already holds.
     */
    private static function plan($season, array $before, $raw, array $fields) {
        $errors = array();
        $config = self::teams_config($season);
        $live_before = array();
        foreach ($before['columns'] as $key => $column) {
            if (empty($column['retired'])) {
                $live_before[$key] = $column['label'];
            }
        }
        $stale = 'This page shows a team fee column that ' . $season . ' no longer has. Reload the page.';

        // Retire: only a live column.
        $retire = array();
        $raw_retire = isset($fields['retire']) ? $fields['retire'] : array();
        if (!is_array($raw_retire)) {
            $errors[] = 'The Retire ticks could not be read.';
            $raw_retire = array();
        }
        foreach ($raw_retire as $key) {
            if (!is_string($key) || !isset($live_before[$key])) {
                $errors[] = $stale;
                continue;
            }
            $retire[$key] = true;
        }

        // The names of the existing live columns (a column being retired keeps its name).
        $names = $live_before;
        $labels = isset($fields['labels']) ? $fields['labels'] : array();
        if (!is_array($labels)) {
            $errors[] = 'The column names could not be read.';
            $labels = array();
        }
        foreach ($labels as $key => $name) {
            $key = (string) $key;
            if (!isset($live_before[$key])) {
                $errors[] = $stale;
                continue;
            }
            if (isset($retire[$key])) {
                continue;
            }
            $checked = self::check_name($name, 'The name for ' . $live_before[$key], $errors);
            if ($checked !== null) {
                $names[$key] = $checked;
            }
        }
        foreach ($retire as $key => $yes) {
            unset($names[$key]);
        }

        // A new column (a blank name adds none).
        $new_key = '';
        $new_name = null;
        $new_label = isset($fields['new_label']) ? $fields['new_label'] : '';
        if ($new_label !== null && !(is_string($new_label) && trim($new_label) === '')) {
            $new_name = self::check_name($new_label, 'The new column\'s name', $errors);
            if ($new_name !== null) {
                $new_key = self::next_key(self::taken_keys($season, $raw));
            }
        }

        // The live columns after this save: at most MAX_COLUMNS, names unique.
        $live_after = $names;
        if ($new_key !== '') {
            $live_after[$new_key] = $new_name;
        }
        if (count($live_after) > self::MAX_COLUMNS) {
            $errors[] = $season . ' can have at most ' . self::MAX_COLUMNS . ' team fee columns. Retire one before adding another.';
        }
        $seen = array();
        foreach ($live_after as $key => $name) {
            $lower = self::lower($name);
            if (isset($seen[$lower])) {
                $errors[] = 'Two columns can\'t both be called ' . $name . '.';
            }
            $seen[$lower] = true;
        }

        // Teams: each posted team must be one of the season's, its key '' or a column live after this save.
        $teams_in = isset($fields['teams']) ? $fields['teams'] : array();
        if (!is_array($teams_in)) {
            $errors[] = 'The team ticks could not be read.';
            $teams_in = array();
        }
        $team_changes = array();
        foreach ($teams_in as $code => $key) {
            $code = (string) $code;
            if ($code === '' || !isset($config[$code])) {
                $errors[] = 'There is no team ' . ($code === '' ? '(blank)' : $code) . ' in ' . $season . '. Reload the page.';
                continue;
            }
            if (is_array($key)) {
                $errors[] = $code . ' is ticked under more than one column. A team can be charged at one column only.';
                continue;
            }
            if (!is_string($key)) {
                $errors[] = 'The ticks for ' . $code . ' could not be read.';
                continue;
            }
            if ($key !== '' && !isset($live_after[$key]) && !isset($retire[$key])) {
                $errors[] = $stale;
                continue;
            }
            $old = isset($before['teams'][$code]) ? $before['teams'][$code] : '';
            if ($old !== $key) {
                $team_changes[$code] = array($old, $key);
            }
        }

        // A column can be retired only when no team of the season is charged at it after this save.
        foreach ($retire as $key => $yes) {
            $users = array();
            foreach ($config as $code => $team) {
                $code = (string) $code;
                $after_key = isset($team_changes[$code]) ? $team_changes[$code][1] : (isset($before['teams'][$code]) ? $before['teams'][$code] : '');
                if ($after_key === $key) {
                    $users[] = $code;
                }
            }
            if (!empty($users)) {
                $errors[] = $live_before[$key] . ' can\'t be retired while ' . implode(', ', $users) . (count($users) === 1 ? ' is' : ' are') . ' ticked under it. Untick its teams first.';
            }
        }

        // A team's column can only change before its offers carry a quote.
        foreach ($team_changes as $code => $pair) {
            if ($pair[1] !== '' && isset($retire[$pair[1]])) {
                continue; // refused above
            }
            $quoted = self::quoted_offers($season, $code);
            if ($quoted > 0) {
                $errors[] = self::quoted_refusal($code, $quoted, $config);
            }
        }

        $out = array('after' => $before, 'errors' => array_values(array_unique($errors)), 'changes' => array(), 'teams_changed' => false, 'team_codes' => array());
        if (!empty($out['errors'])) {
            return $out;
        }

        // The entry after this save: every column in its place (retired ones too), the new one last.
        $columns = array();
        foreach ($before['columns'] as $key => $column) {
            if (isset($retire[$key])) {
                $columns[$key] = array('label' => $column['label'], 'retired' => true);
            } elseif (isset($names[$key])) {
                $columns[$key] = array('label' => $names[$key], 'retired' => false);
            } else {
                $columns[$key] = $column;
            }
        }
        if ($new_key !== '') {
            $columns[$new_key] = array('label' => $new_name, 'retired' => false);
        }
        $teams = $before['teams'];
        foreach ($team_changes as $code => $pair) {
            if ($pair[1] === '') {
                unset($teams[$code]);
            } else {
                $teams[$code] = $pair[1];
            }
        }
        $out['after'] = self::normalise(array('columns' => $columns, 'teams' => $teams));

        $changes = array();
        if ($new_key !== '') {
            $changes[] = $season . ' team fee column added: ' . $new_name;
        }
        foreach ($names as $key => $name) {
            if (isset($live_before[$key]) && $name !== $live_before[$key]) {
                $changes[] = $season . ' team fee column renamed: ' . $live_before[$key] . ' to ' . $name;
            }
        }
        foreach ($team_changes as $code => $pair) {
            // 1.96.0: the column holds a Training only amount too, so the line names both.
            $changes[] = $season . ' ' . $code . ' playing members and training only players charged at ' . self::column_words($pair[1], $live_after) . ' (was ' . self::column_words($pair[0], $live_before) . ')';
        }
        foreach ($retire as $key => $yes) {
            $changes[] = $season . ' team fee column retired: ' . $live_before[$key];
        }
        $out['changes'] = $changes;
        $out['teams_changed'] = !empty($team_changes);
        $out['team_codes'] = array_map('strval', array_keys($team_changes));
        return $out;
    }

    /** The refusal for a team whose column would change while it has quoted playing or (1.96.0) Training only offers. */
    private static function quoted_refusal($code, $quoted, array $config) {
        return self::team_words($code, $config) . ' has ' . intval($quoted) . ' offer' . (intval($quoted) === 1 ? '' : 's') . ' already sent with a quote. A team\'s fee column can only change before its offers are sent.';
    }

    /** "the standard columns" for '', else "the NAME column" (the name as it is after the save, or before it for the "was"). */
    private static function column_words($key, array $names) {
        if ($key === '') {
            return 'the standard columns';
        }
        $name = isset($names[$key]) ? (string) $names[$key] : 'retired';
        // TeamOversight_Fees::column_phrase()'s rule, kept here so the class loads without it.
        return 'the ' . $name . (preg_match('/\bcolumn$/i', $name) === 1 ? '' : ' column');
    }

    /** A team named for a refusal: its code, with its name in brackets when it has another. */
    private static function team_words($code, array $config) {
        $name = (isset($config[$code]) && is_array($config[$code]) && isset($config[$code]['name']) && is_string($config[$code]['name'])) ? trim($config[$code]['name']) : '';
        return $code . (($name !== '' && $name !== $code) ? ' (' . $name . ')' : '');
    }

    /**
     * How many of the season's offers for the team are live playing or
     * Training only offers carrying a quote (1.96.0, club decision 13 Nov
     * 2026: a Training only place is charged at the column too):
     * TeamOversight_Offers::quoted_playing_offers(), the only reader of
     * team_offers here. An offers class without it counts 0.
     */
    private static function quoted_offers($season, $team) {
        if (!class_exists('TeamOversight_Offers') || !method_exists('TeamOversight_Offers', 'quoted_playing_offers')) {
            return 0;
        }
        return intval(TeamOversight_Offers::quoted_playing_offers($season, $team));
    }

    /** A name checked as save() takes it: plain text, 1 to LABEL_MAX characters, no dollar sign, not a standard column. Null and a sentence in $errors when refused. */
    private static function check_name($value, $what, array &$errors) {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $errors[] = $what . ' must be plain text.';
            return null;
        }
        $name = trim((string) $value);
        if ($name === '') {
            $errors[] = $what . ' can\'t be blank. To stop using a column, tick Retire.';
            return null;
        }
        if (sanitize_text_field($name) !== $name) {
            $errors[] = $what . ' must be plain text, with no HTML and no line breaks.';
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length > self::LABEL_MAX) {
            $errors[] = $what . ' can be at most ' . self::LABEL_MAX . ' characters; it has ' . $length . '.';
            return null;
        }
        if (strpos($name, '$') !== false) {
            $errors[] = $what . ' can\'t hold a dollar sign.';
            return null;
        }
        if (in_array(self::lower($name), self::RESERVED_NAMES, true)) {
            $errors[] = $what . ' can\'t be ' . $name . ': that is a standard column.';
            return null;
        }
        return $name;
    }

    private static function lower($text) {
        return function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
    }

    /**
     * A new key: KEY_PREFIX plus the lowest number from 1 that is none of
     * $taken (key => true). Never made from the name, so a key printed by
     * mistake tells a player or a coach nothing, and never ending with
     * TRAINING_SUFFIX (1.96.0: a column's Training only role is its key
     * plus the suffix; taken_keys() lists such roles too, harmlessly).
     */
    public static function next_key(array $taken) {
        $n = 1;
        while (isset($taken[self::KEY_PREFIX . $n])) {
            $n++;
        }
        return self::KEY_PREFIX . $n;
    }

    /**
     * Every key the season ever had, so a key is never reused: the stored
     * entry's columns (retired ones, and any the structural pass drops) and
     * any column key in the season's fee_matrix or fee_matrix_limits rows.
     */
    private static function taken_keys($season, $raw) {
        global $wpdb;
        $taken = array();
        if (is_array($raw) && isset($raw['columns']) && is_array($raw['columns'])) {
            foreach (array_keys($raw['columns']) as $key) {
                $taken[(string) $key] = true;
            }
        }
        $like = $wpdb->esc_like(self::KEY_PREFIX) . '%';
        foreach (array('fee_matrix', 'fee_matrix_limits') as $short) {
            $roles = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT team_role FROM {$wpdb->prefix}{$short} WHERE season = %s AND team_role LIKE %s", (string) $season, $like));
            foreach ((array) $roles as $role) {
                $taken[strtolower((string) $role)] = true;
            }
        }
        return $taken;
    }

    /** After a team's column changed: the season re-priced when its fees are released. Plain sentences for the notice. */
    private static function reprice($season) {
        if (!class_exists('TeamOversight_Fees')) {
            return array();
        }
        if (!TeamOversight_Fees::fees_released($season)) {
            return array($season . ' fees aren\'t released yet, so no invoice changed.');
        }
        try {
            $r = TeamOversight_Fees::shared()->reprice_season($season, 'team fee column changed');
        } catch (\Throwable $e) {
            if (class_exists('TeamOversight_Log')) {
                TeamOversight_Log::add('offer_alert', $season . ' team fee column changed, but the invoices could not all be worked out again (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . '). Check Payment Management.');
            }
            return array('The ' . $season . ' invoices could not all be worked out again. Check Payment Management.');
        }
        $plural = function ($n, $one, $many) {
            return intval($n) . ' ' . (intval($n) === 1 ? $one : $many);
        };
        $words = array('Every ' . $season . ' invoice was worked out again: ' . intval($r['updated']) . ' changed, ' . intval($r['unchanged']) . ' unchanged.');
        if (!empty($r['manual'])) {
            $words[] = $plural(count($r['manual']), 'fee set with Edit Fee was', 'fees set with Edit Fee were') . ' kept.';
        }
        if (!empty($r['unpriced'])) {
            $words[] = $plural(count($r['unpriced']), 'invoice couldn\'t be priced and was', 'invoices couldn\'t be priced and were') . ' kept as before. Fill in the blank cells in the Fee Matrix.';
        }
        return $words;
    }

    /** A log line kept under the log's 500 bytes. */
    private static function cut($line) {
        if (strlen($line) <= 500) {
            return $line;
        }
        return (class_exists('TeamOversight_Log') && method_exists('TeamOversight_Log', 'cut_bytes')) ? TeamOversight_Log::cut_bytes($line, 500) : substr($line, 0, 500);
    }

    private static function first_season() {
        return class_exists('TeamOversight_Fees') ? intval(TeamOversight_Fees::FEE_TIMELINE_FROM) : 2027;
    }

    /**
     * The option as stored, for save(): an array (array() when there is
     * none), or null when it couldn't be read, so nothing is written over
     * what wasn't read. The plugin's one fresh reader, which keeps the
     * pre_option filters and drops a stale notoptions flag.
     */
    private static function read_stored() {
        if (!class_exists('TeamOversight_Program_Setup') || !method_exists('TeamOversight_Program_Setup', 'read_option_fresh')) {
            return null;
        }
        $all = TeamOversight_Program_Setup::read_option_fresh(self::OPTION);
        return is_array($all) ? $all : null;
    }

    private static function result($ok, $changed, array $errors, array $notes = array()) {
        return array('ok' => (bool) $ok, 'errors' => array_values($errors), 'changed' => (bool) $changed, 'notes' => array_values($notes));
    }

    // ------------------------------------------------------------------
    // Configuration: the admin box and its handler
    // ------------------------------------------------------------------

    /**
     * admin-post murvc_fee_columns: administrators only, the season's own
     * nonce, then process_post() and back to the box (Post/Redirect/Get).
     */
    public static function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        $season = (isset($_POST['season']) && is_string($_POST['season']) && self::valid_season($_POST['season'])) ? (string) $_POST['season'] : '';
        check_admin_referer(self::NONCE_ACTION . $season, self::NONCE_FIELD);
        self::process_post($season, $_POST, get_current_user_id());
        wp_safe_redirect(self::config_url($season) . '#team-fee-columns');
        exit;
    }

    /**
     * The save behind the handler (the nonce already checked): fields from
     * the slashed $post, save(), and the notice left for the next page,
     * with what was typed when nothing was saved. Returns save()'s result.
     */
    public static function process_post($season, $post, $user_id) {
        $fields = self::fields_from_post(is_array($post) ? $post : array());
        $result = self::save($season, $fields, $user_id);
        $shown = self::valid_season($season) ? (string) $season : '';
        if ($result['ok'] && $result['changed']) {
            $message = 'Team fee columns saved for ' . $shown . '.';
        } elseif ($result['ok']) {
            $message = 'Nothing changed for ' . $shown . '.';
        } else {
            $message = 'Nothing was saved.';
        }
        set_transient(self::FLASH_PREFIX . intval($user_id), array(
            'season' => $shown,
            'ok' => $result['ok'],
            'changed' => $result['changed'],
            'message' => $message,
            'errors' => $result['errors'],
            'notes' => $result['notes'],
            'typed' => $result['ok'] ? null : $fields,
        ), self::FLASH_SECONDS);
        return $result;
    }

    /**
     * The box's fields from a slashed post. Names and team codes go
     * through wp_unslash() then sanitize_text_field(), keys through
     * sanitize_key(). The form posts labels[key], retire[], new_label,
     * col_teams[key][] (the team codes ticked under each column) and
     * shown_teams[] (every team drawn), so a team ticked under no column
     * becomes '' and one ticked under two becomes the list of both keys.
     */
    public static function fields_from_post(array $post) {
        $text = function ($value) {
            return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
        };
        $key = function ($value) {
            return (is_string($value) || is_int($value)) ? sanitize_key(wp_unslash((string) $value)) : '';
        };
        $fields = array('labels' => array(), 'retire' => array(), 'new_label' => '', 'teams' => array());
        if (isset($post['labels']) && is_array($post['labels'])) {
            foreach (array_slice($post['labels'], 0, 20, true) as $k => $name) {
                $k = $key($k);
                if ($k !== '') {
                    $fields['labels'][$k] = $text($name);
                }
            }
        }
        if (isset($post['retire']) && is_array($post['retire'])) {
            foreach (array_slice($post['retire'], 0, 20) as $k) {
                $k = $key($k);
                if ($k !== '') {
                    $fields['retire'][] = $k;
                }
            }
            $fields['retire'] = array_values(array_unique($fields['retire']));
        }
        $fields['new_label'] = isset($post['new_label']) ? $text($post['new_label']) : '';
        if (isset($post['drawn']) && is_string($post['drawn'])) {
            $fields['drawn'] = $key($post['drawn']);
        }
        if (isset($post['shown_teams']) && is_array($post['shown_teams'])) {
            foreach (array_slice($post['shown_teams'], 0, 200) as $code) {
                $code = $text($code);
                if ($code !== '') {
                    $fields['teams'][$code] = '';
                }
            }
        }
        $ticked = array();
        if (isset($post['col_teams']) && is_array($post['col_teams'])) {
            foreach (array_slice($post['col_teams'], 0, 20, true) as $k => $codes) {
                $k = $key($k);
                if ($k === '' || !is_array($codes)) {
                    continue;
                }
                foreach (array_slice($codes, 0, 200) as $code) {
                    $code = $text($code);
                    if ($code !== '') {
                        $ticked[$code][] = $k;
                    }
                }
            }
        }
        foreach ($ticked as $code => $keys) {
            $keys = array_values(array_unique($keys));
            $fields['teams'][(string) $code] = count($keys) === 1 ? $keys[0] : $keys;
        }
        return $fields;
    }

    private static function config_url($season) {
        return admin_url('admin.php?page=team-oversight-fees' . (self::valid_season($season) ? '&season=' . $season : ''));
    }

    /** The notice the last save left for this administrator (taken once), or null. */
    private static function take_flash() {
        $key = self::FLASH_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        if ($flash !== false) {
            delete_transient($key);
        }
        return is_array($flash) ? $flash : null;
    }

    /**
     * The form's values: labels (key => name), teams (code => list of keys
     * ticked), retire (key => true), new_label. From the stored columns, or
     * from what was typed when a save was refused.
     */
    private static function form_values($typed, array $live, array $assigned) {
        $out = array('labels' => $live, 'teams' => array(), 'retire' => array(), 'new_label' => '');
        foreach ($assigned as $code => $key) {
            $out['teams'][(string) $code] = array($key);
        }
        if (!is_array($typed)) {
            return $out;
        }
        if (isset($typed['labels']) && is_array($typed['labels'])) {
            foreach ($typed['labels'] as $key => $name) {
                if (isset($out['labels'][$key]) && is_scalar($name)) {
                    $out['labels'][$key] = (string) $name;
                }
            }
        }
        if (isset($typed['teams']) && is_array($typed['teams'])) {
            $out['teams'] = array();
            foreach ($typed['teams'] as $code => $keys) {
                $keys = is_array($keys) ? $keys : array($keys);
                $out['teams'][(string) $code] = array_values(array_filter($keys, function ($k) {
                    return is_string($k) && $k !== '';
                }));
            }
        }
        if (isset($typed['retire']) && is_array($typed['retire'])) {
            foreach ($typed['retire'] as $key) {
                if (is_string($key)) {
                    $out['retire'][$key] = true;
                }
            }
        }
        if (isset($typed['new_label']) && is_scalar($typed['new_label'])) {
            $out['new_label'] = (string) $typed['new_label'];
        }
        return $out;
    }

    /**
     * Configuration (VVL Oversight), the selected season: the Team fee
     * columns box (anchor team-fee-columns) and its form. ECHOES.
     * Administrators only; nothing at all for a season before 2027. No
     * dollar figure anywhere in it.
     */
    public static function render_admin_box($season) {
        if (!current_user_can('manage_options') || !self::applies($season)) {
            return;
        }
        $season = (string) $season;
        $flash = self::take_flash();
        $entry = self::get($season);
        $live = self::live($season);
        $config = self::teams_config($season);
        $typed = ($flash !== null && isset($flash['season']) && $flash['season'] === $season && isset($flash['typed']) && is_array($flash['typed'])) ? $flash['typed'] : null;
        $form = self::form_values($typed, $live, self::teams($season));
        $retired = array();
        foreach ($entry['columns'] as $column) {
            if (!empty($column['retired'])) {
                $retired[] = $column['label'];
            }
        }
        ?>
        <div id="team-fee-columns" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
            <h2 style="margin-top: 0;">Team fee columns</h2>
            <p class="description">A team fee column adds two columns to the <?php echo esc_html($season); ?> Fee Matrix: one for playing members and one for Training only players. Playing members of the teams ticked under it are charged at its playing amount. Training only players of those teams are charged at its Training only amount. Coaches, assistant coaches and team managers on those teams are charged as usual. Type the fees and upper limits in the Fee Matrix below. A blank cell has no amount: it never falls back to the Playing member or Training only amount. Players and coaches never see a column's name. A team's column can only change before its offers are sent.</p>
            <?php if ($flash !== null): ?>
                <?php $type = empty($flash['ok']) ? 'error' : (!empty($flash['changed']) ? 'success' : 'info'); ?>
                <div class="notice notice-<?php echo esc_attr($type); ?> inline">
                    <p><?php echo esc_html(isset($flash['message']) && is_scalar($flash['message']) ? (string) $flash['message'] : ''); ?></p>
                    <?php foreach (array('errors', 'notes') as $list): ?>
                        <?php if (!empty($flash[$list]) && is_array($flash[$list])): ?>
                            <ul style="list-style: disc; margin-left: 20px;">
                                <?php foreach ($flash[$list] as $line): ?>
                                    <?php if (is_scalar($line)): ?>
                                        <li><?php echo esc_html((string) $line); ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($typed !== null): ?>
                        <p>The form below still holds what you typed.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION . $season, self::NONCE_FIELD); ?>
                <input type="hidden" name="drawn" value="<?php echo esc_attr(self::entry_hash($entry)); ?>">
                <?php if (empty($live)): ?>
                    <p>No team fee columns for <?php echo esc_html($season); ?> yet.</p>
                <?php else: ?>
                    <table class="widefat striped" data-nosort style="width: auto; max-width: 100%; margin: 8px 0 6px;">
                        <thead>
                            <tr><th scope="col">Name</th><th scope="col">Teams charged at it</th><th scope="col">Retire</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($live as $key => $label): ?>
                                <tr>
                                    <td><input type="text" name="labels[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($form['labels'][$key]); ?>" maxlength="<?php echo intval(self::LABEL_MAX); ?>" aria-label="<?php echo esc_attr('Name of the ' . $label . ' column'); ?>" style="width: 16em; max-width: 100%;"></td>
                                    <td>
                                        <?php if (empty($config)): ?>
                                            No <?php echo esc_html($season); ?> teams yet.
                                        <?php else: ?>
                                            <fieldset>
                                                <legend class="screen-reader-text"><?php echo esc_html('Teams whose playing members and training only players are charged at ' . $label); ?></legend>
                                                <?php foreach ($config as $code => $team): ?>
                                                    <?php $code = (string) $code; ?>
                                                    <?php $name = (is_array($team) && isset($team['name']) && is_scalar($team['name'])) ? (string) $team['name'] : $code; ?>
                                                    <label title="<?php echo esc_attr($name); ?>" style="display: inline-block; margin: 0 12px 4px 0; white-space: nowrap;"><input type="checkbox" name="col_teams[<?php echo esc_attr($key); ?>][]" value="<?php echo esc_attr($code); ?>" <?php checked(isset($form['teams'][$code]) && in_array($key, $form['teams'][$code], true)); ?>> <?php echo esc_html($code); ?></label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                        <?php endif; ?>
                                    </td>
                                    <td><label><input type="checkbox" name="retire[]" value="<?php echo esc_attr($key); ?>" <?php checked(isset($form['retire'][$key])); ?>> Retire</label></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php foreach ($config as $code => $team): ?>
                        <input type="hidden" name="shown_teams[]" value="<?php echo esc_attr((string) $code); ?>">
                    <?php endforeach; ?>
                    <p class="description" style="margin: 0 0 10px;">Tick a team under one column at most. To retire a column, untick its teams first: its amounts stay in the database, but it is no longer shown or charged.</p>
                <?php endif; ?>
                <p style="margin: 8px 0;">
                    <label for="team-fee-columns-new"><strong>Add a column</strong></label><br>
                    <input type="text" id="team-fee-columns-new" name="new_label" class="regular-text" maxlength="<?php echo intval(self::LABEL_MAX); ?>" value="<?php echo esc_attr($form['new_label']); ?>" aria-describedby="team-fee-columns-new-hint">
                    <span class="description" id="team-fee-columns-new-hint">Its name, up to <?php echo intval(self::LABEL_MAX); ?> characters, e.g. <em>Specialist coach</em>. A season can have up to <?php echo intval(self::MAX_COLUMNS); ?> columns. After saving, tick its teams here and type its amounts in the Fee Matrix.</span>
                </p>
                <input type="submit" class="button button-primary" value="Save team fee columns">
            </form>
            <?php if (!empty($retired)): ?>
                <p class="description" style="margin: 10px 0 0;">Retired in <?php echo esc_html($season); ?>: <?php echo esc_html(implode(', ', $retired)); ?>.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
