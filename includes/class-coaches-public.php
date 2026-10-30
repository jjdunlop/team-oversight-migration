<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coaches on the website (1.82.0): the [club_coaches] shortcode, which draws
 * every public coach list from the coach profiles, the card renderer, the
 * style block and the Our coaches filter script. The Coaches editor's card
 * preview builds and draws its card with the same card_from_row() and
 * render_card(), so the preview and the site never differ.
 *
 * The rules, in one place:
 *  - Only published profiles that pass TeamOversight_Coaches::guard_sql()
 *    are read (Query A, once per request), and a card is a whitelist built
 *    by card_from_row(): no consent, age, guardian, account or email field
 *    is ever copied in, so nothing here can print one. A Name only card
 *    carries the name and the roles, nothing else.
 *  - The output never depends on the viewer, writes nothing, sets no nonce
 *    and never asks for the page not to be cached or indexed: the coach
 *    pages are public, kept by the site's page cache (TeamOversight_Coaches
 *    asks it to refresh when coaches change) and indexed. Any problem and
 *    any empty list is '', so a shortcode placed early shows nothing; the
 *    To do panel names the problem. normalise_atts() is the one reading of
 *    the attributes, shared with TeamOversight_Coaches::pages().
 *  - A photo shows only while it is the cleared photo and an image in the
 *    Media Library, else the initials. The attachment caches are primed
 *    once, before any card is built, so a grid costs the same queries
 *    however many photos it shows.
 *  - Every text node goes through esc_html(), every attribute through
 *    esc_attr() and every URL through esc_url(); card ids and filter keys
 *    come from integers and validated keys. The filter script uses
 *    textContent only.
 *  - Program strips and the Our coaches grid come in 1.82.0. Team coaching
 *    (group and team strips, the Trials page's Coach line, team parts in
 *    the role lines, team groups among the filter buttons) needs the ticks
 *    and the season switch of 1.83.0: until then those uses show nothing.
 *
 * Contract: design/coach-bios-tech-design.md sections 5.3 to 5.5 and 9.
 */
class TeamOversight_Coaches_Public {

    const SHORTCODE = 'club_coaches';
    /** The shortcode's attributes and their defaults (shortcode_atts()). */
    const ATTS = array('program' => '', 'team' => '', 'group' => '', 'season' => '', 'mode' => '');
    const MODES = array('strip', 'grid', 'line');
    /** Role => rank in every ordering: Lead coach first, then Coach or Head coach, then assistants. */
    const RANKS = array('lead' => 0, 'coach' => 1, 'assistant' => 2, 'assistant_coach' => 2);

    /** Request caches; flush_cache() resets them (tests). */
    private static $rows = null;
    private static $data = array();
    private static $programs = null;
    private static $grid_url = null;
    /** What has been printed this request (styles, script): once each. flush_cache() leaves it alone. */
    private static $printed = array();

    /** Add the shortcode on init, once per request (team_oversight_init() calls this). Static and guarded, never from a constructor. */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (did_action('init')) {
            self::register_shortcode();
        } else {
            add_action('init', array(__CLASS__, 'register_shortcode'));
        }
    }

    public static function register_shortcode() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));
    }

    /** Forget everything read this request (tests, and 1.83.0's line fingerprint). The print-once flags are kept. */
    public static function flush_cache() {
        self::$rows = null;
        self::$data = array();
        self::$programs = null;
        self::$grid_url = null;
    }

    // ------------------------------------------------------------------
    // The shortcode
    // ------------------------------------------------------------------

    /**
     * [club_coaches]: a program strip ([club_coaches program="academy"]) or
     * the Our coaches grid ([club_coaches], filter buttons included). Returns
     * the HTML and never echoes; '' for a render inside wp_head (so it can't
     * use up the print-once styles), for any attribute problem and for an
     * empty list. Group, team and line uses are '' until 1.83.0. Writes
     * nothing, and nothing in it depends on who is looking.
     */
    public static function render_shortcode($atts = array()) {
        if (doing_action('wp_head')) {
            return '';
        }
        $use = self::normalise_atts(shortcode_atts(self::ATTS, is_array($atts) ? $atts : array(), self::SHORTCODE));
        if (!$use['ok']) {
            return '';
        }
        // Team coaching (group and team strips, the Trials line) arrives with the ticks and the season switch in 1.83.0.
        if ($use['mode'] === 'line' || $use['group'] !== '' || !empty($use['teams'])) {
            return '';
        }
        $data = self::data($use['season']);
        $cards = array();
        foreach ($data['cards'] as $card) {
            if (self::card_in_use($card, $use)) {
                $cards[] = $card;
            }
        }
        if (empty($cards)) {
            return '';
        }
        $cards = self::sort_cards($cards, array('mode' => $use['mode'], 'program' => $use['program']));
        return $use['mode'] === 'strip' ? self::render_strip($cards, $use) : self::render_grid($cards, $use);
    }

    /**
     * The one reading of a [club_coaches] use: shortcode_atts()' output, or
     * shortcode_parse_atts()' (a missing attribute is '', an unknown one is
     * ignored). Returns array(ok, problem, mode, program, teams (a list of
     * codes), group, season). ok is false with one plain sentence in
     * problem (no page title in it; the To do panel adds that) when:
     * more than one of program, team and group is given; season isn't four
     * digits; program isn't a key of programs(); group isn't a key of
     * TEAM_GROUPS; a team code isn't /^[A-Za-z0-9_-]{1,50}$/D or isn't one
     * of the season's teams (checked only while there is a season: one
     * given, else the website season); mode isn't strip, grid or line; a
     * line has no team; a strip has nothing to show (no program, group or
     * team). An empty season is the website season ('' while none is
     * announced); an empty mode is strip with a program, group or team,
     * else grid (a grid with one of them has no filter buttons). Program,
     * group and mode are read case-insensitively; team codes as typed. A
     * failed use still carries what was typed, for the Pages list.
     */
    public static function normalise_atts(array $atts) {
        $read = function ($key) use ($atts) {
            return isset($atts[$key]) && is_scalar($atts[$key]) ? trim((string) $atts[$key]) : '';
        };
        $program = strtolower($read('program'));
        $group = strtolower($read('group'));
        $season = $read('season');
        $mode = strtolower($read('mode'));
        $teams = array();
        foreach (explode(',', $read('team')) as $code) {
            $code = trim($code);
            if ($code !== '' && !in_array($code, $teams, true)) {
                $teams[] = $code;
            }
        }
        $out = array('ok' => false, 'problem' => '', 'mode' => $mode, 'program' => $program, 'teams' => $teams, 'group' => $group, 'season' => $season);
        $fail = function ($problem) use (&$out) {
            $out['problem'] = $problem;
            return $out;
        };

        $selectors = ($program !== '' ? 1 : 0) + (empty($teams) ? 0 : 1) + ($group !== '' ? 1 : 0);
        if ($selectors > 1) {
            return $fail('It names more than one of program, team and group: use one.');
        }
        if ($season !== '' && !TeamOversight_Coaches::valid_season($season)) {
            return $fail('Its season ' . self::quoted($season) . ' isn\'t a four-digit year.');
        }
        if ($season === '') {
            $season = (string) TeamOversight_Coaches::website_season();
            $out['season'] = $season;
        }
        $programs = self::programs();
        if ($program !== '' && !isset($programs[$program])) {
            return $fail('There is no program ' . self::quoted($program) . ': copy the code line from Coaches → Programs and settings.');
        }
        if ($group !== '' && !array_key_exists($group, TeamOversight_Coaches::TEAM_GROUPS)) {
            return $fail('There is no team group ' . self::quoted($group) . ': use ' . implode(', ', array_keys(TeamOversight_Coaches::TEAM_GROUPS)) . '.');
        }
        if (!empty($teams)) {
            $config = $season !== '' ? TeamOversight_Offers::teams_config($season) : null;
            foreach ($teams as $code) {
                if (preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $code) !== 1) {
                    return $fail('Team code ' . self::quoted($code) . ' isn\'t a team code.');
                }
                if (is_array($config) && !isset($config[$code])) {
                    return $fail('Team code ' . $code . ' isn\'t in ' . $season . '\'s teams.');
                }
            }
        }
        if ($mode === '') {
            $mode = $selectors > 0 ? 'strip' : 'grid';
            $out['mode'] = $mode;
        }
        if (!in_array($mode, self::MODES, true)) {
            return $fail('Its mode ' . self::quoted($mode) . ' isn\'t strip, grid or line.');
        }
        if ($mode === 'line' && empty($teams)) {
            return $fail('A line (mode="line") needs team="CODE".');
        }
        if ($mode === 'strip' && $selectors === 0) {
            return $fail('A strip (mode="strip") needs a program, group or team.');
        }
        $out['ok'] = true;
        return $out;
    }

    /** A typed value inside a problem sentence: quoted, at most 40 characters. */
    private static function quoted($value) {
        $value = (string) $value;
        return '"' . (mb_strlen($value, 'UTF-8') > 40 ? mb_substr($value, 0, 40, 'UTF-8') . '…' : $value) . '"';
    }

    /**
     * Does this use list this card? (A normalise_atts() result that is ok,
     * and a card from card_from_row().) A program use: a role on that
     * program. No selector (the grid): any public role, a program role or a
     * shown team. Group and team uses list nobody until 1.83.0. The
     * shortcode selects with this, and the editor's Appears on can too.
     */
    public static function card_in_use(array $card, array $use) {
        if (empty($use['ok'])) {
            return false;
        }
        $programs = isset($card['programs']) && is_array($card['programs']) ? $card['programs'] : array();
        $teams = isset($card['teams']) && is_array($card['teams']) ? $card['teams'] : array();
        if (isset($use['program']) && $use['program'] !== '') {
            return isset($programs[$use['program']]);
        }
        if ((isset($use['group']) && $use['group'] !== '') || !empty($use['teams'])) {
            return false;
        }
        return !empty($programs) || !empty($teams);
    }

    // ------------------------------------------------------------------
    // The data: one read per request, never one per card
    // ------------------------------------------------------------------

    /**
     * What every [club_coaches] on the page draws from, cached for the
     * request under (season, assume_announced), so the editor's preview and
     * a public render in one request never hand each other their result:
     * array(cards => profile id => card (card_from_row()), teams_with_place
     * => code => true, shown => profile id => list of (team, role)). Query A
     * (the published, guarded profiles) runs once per request whatever the
     * key, with the cleared photos primed before any card is built. The
     * places (Query B), teams_with_place and shown come with team coaching
     * in 1.83.0; until then both are empty.
     */
    public static function data($season, $assume_announced = false) {
        $season = is_scalar($season) && TeamOversight_Coaches::valid_season((string) $season) ? (string) $season : '';
        $key = $season . '|' . ($assume_announced ? 1 : 0);
        if (isset(self::$data[$key])) {
            return self::$data[$key];
        }
        $cards = array();
        foreach (self::published_rows() as $row) {
            $cards[intval($row->id)] = self::card_from_row($row, array(), $season, $assume_announced);
        }
        self::$data[$key] = array('cards' => $cards, 'teams_with_place' => array(), 'shown' => array());
        return self::$data[$key];
    }

    /**
     * Query A, once per request: every published profile that passes
     * guard_sql(), with the linked account's id and email (used only to
     * judge ticks, never printed). Consent columns appear in the WHERE
     * clause only and are never selected. Then one _prime_post_caches() over
     * the cleared photos of Full profiles, because building a card asks
     * wp_attachment_is_image() (the post and its file meta).
     */
    private static function published_rows() {
        global $wpdb;
        if (self::$rows !== null) {
            return self::$rows;
        }
        $rows = $wpdb->get_results(
            'SELECT p.id, p.user_id, p.display_mode, p.public_name, p.intro, p.bio, p.accreditation, p.accreditation_other,
                    p.highlights, p.playing_background, p.program_roles, p.photo_id, p.photo_cleared_id,
                    u.ID AS account_id, u.user_email AS account_email
             FROM ' . TeamOversight_Coaches::profiles_table() . " p
             LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
             WHERE p.status = 'published' AND " . TeamOversight_Coaches::guard_sql('p') . '
             ORDER BY p.id LIMIT ' . intval(TeamOversight_Coaches::LIST_MAX)
        );
        $rows = is_array($rows) ? $rows : array();
        $photos = array();
        foreach ($rows as $row) {
            $photo = intval($row->photo_id);
            if ($photo > 0 && intval($row->photo_cleared_id) === $photo && (string) $row->display_mode === 'full') {
                $photos[$photo] = $photo;
            }
        }
        if (!empty($photos)) {
            _prime_post_caches(array_values($photos), false, true);
        }
        self::$rows = $rows;
        return $rows;
    }

    /**
     * The one function that turns a profile row into a card, so the
     * editor's preview and the site never differ. $row carries Query A's
     * columns (a full profile row from get_profile() does too, plus
     * account_id / account_email). It checks neither status nor consent:
     * data() passes only published, guarded rows, and the preview
     * deliberately draws a draft. The card is a whitelist:
     *   id, name, display (full | name_only),
     *   intro, bio, chip (the accreditation chip's text, '' when not set),
     *   chip_title, highlights (a list), background,
     *   photo_id (0 unless it is the cleared photo and an image),
     *   programs (program key => lead | coach | assistant, programs that
     *   exist only), teams (a list of array(code, role, name, level, group)).
     * A Name only card keeps only id, name, display and the roles: its
     * other fields are blank, so no renderer can print them. $tick_rows
     * ($season, $assume_announced) give a card its shown teams from 1.83.0,
     * when ticks exist; until then teams is empty and they are not read.
     */
    public static function card_from_row($row, array $tick_rows, $season, $assume_announced) {
        $row = (object) $row;
        $full = isset($row->display_mode) && (string) $row->display_mode === 'full';
        $photo = intval(isset($row->photo_id) ? $row->photo_id : 0);
        $cleared = intval(isset($row->photo_cleared_id) ? $row->photo_cleared_id : 0);
        $chip = '';
        $chip_title = '';
        $acc = isset($row->accreditation) ? (string) $row->accreditation : '';
        if ($acc === 'other') {
            $chip = trim(isset($row->accreditation_other) ? (string) $row->accreditation_other : '');
            $chip_title = 'Coaching accreditation';
        } elseif ($acc !== '' && array_key_exists($acc, TeamOversight_Coaches::ACCREDITATIONS)) {
            $chip = 'VA ' . TeamOversight_Coaches::ACCREDITATIONS[$acc];
            $chip_title = 'Volleyball Australia coaching accreditation';
        }
        return array(
            'id' => intval(isset($row->id) ? $row->id : 0),
            'name' => trim(isset($row->public_name) ? (string) $row->public_name : ''),
            'display' => $full ? 'full' : 'name_only',
            'intro' => $full ? trim(isset($row->intro) ? (string) $row->intro : '') : '',
            'bio' => $full ? trim(isset($row->bio) ? (string) $row->bio : '') : '',
            'chip' => $full ? $chip : '',
            'chip_title' => $full && $chip !== '' ? $chip_title : '',
            'highlights' => $full ? TeamOversight_Coaches::highlights_of($row) : array(),
            'background' => $full ? trim(isset($row->playing_background) ? (string) $row->playing_background : '') : '',
            'photo_id' => ($full && $photo > 0 && $cleared === $photo && wp_attachment_is_image($photo)) ? $photo : 0,
            'programs' => TeamOversight_Coaches::program_roles_of($row),
            'teams' => array(),
        );
    }

    /** programs(), read once per request. */
    private static function programs() {
        if (self::$programs === null) {
            self::$programs = TeamOversight_Coaches::programs();
        }
        return self::$programs;
    }

    /** The Our coaches page's address ('' when no page has a grid), read once per request. */
    private static function grid_url() {
        if (self::$grid_url === null) {
            self::$grid_url = (string) TeamOversight_Coaches::grid_url();
        }
        return self::$grid_url;
    }

    // ------------------------------------------------------------------
    // Ordering and role lines (no order field: make someone Lead coach to put them first)
    // ------------------------------------------------------------------

    private static function rank($role) {
        return array_key_exists((string) $role, self::RANKS) ? self::RANKS[(string) $role] : 3;
    }

    /**
     * The cards in display order: with $ctx['program'] (a program strip, or
     * a grid for one program) by their rank on that program, else (the
     * grid) by their best rank across every public role; then by name,
     * compared with accents folded (Élodie among the E's) and naturally
     * (strnatcasecmp), then by profile id. Returns a list.
     */
    public static function sort_cards(array $cards, array $ctx) {
        $program = isset($ctx['program']) && is_string($ctx['program']) ? $ctx['program'] : '';
        $list = array();
        foreach ($cards as $card) {
            $programs = isset($card['programs']) && is_array($card['programs']) ? $card['programs'] : array();
            if ($program !== '' && isset($programs[$program])) {
                $rank = self::rank($programs[$program]);
            } else {
                $rank = 3;
                foreach ($programs as $role) {
                    $rank = min($rank, self::rank($role));
                }
                foreach (isset($card['teams']) && is_array($card['teams']) ? $card['teams'] : array() as $team) {
                    $rank = min($rank, self::rank(isset($team['role']) ? $team['role'] : ''));
                }
            }
            $list[] = array($rank, remove_accents(isset($card['name']) ? (string) $card['name'] : ''), intval(isset($card['id']) ? $card['id'] : 0), $card);
        }
        usort($list, function ($a, $b) {
            if ($a[0] !== $b[0]) {
                return $a[0] < $b[0] ? -1 : 1;
            }
            $by_name = strnatcasecmp($a[1], $b[1]);
            if ($by_name !== 0) {
                return $by_name;
            }
            return $a[2] === $b[2] ? 0 : ($a[2] < $b[2] ? -1 : 1);
        });
        $out = array();
        foreach ($list as $item) {
            $out[] = $item[3];
        }
        return $out;
    }

    /**
     * A card's roles as parts, each array(text, accent): its program roles
     * in programs() order ("Lead coach, Junior Academy"), then its shown
     * teams ("Head coach, State League 2 Women", from 1.83.0), with the
     * $first program's part moved to the front.
     */
    private static function role_parts(array $card, $first = '') {
        $parts = array();
        $roles = isset($card['programs']) && is_array($card['programs']) ? $card['programs'] : array();
        foreach (self::programs() as $key => $program) {
            if (isset($roles[$key]) && array_key_exists($roles[$key], TeamOversight_Coaches::PROGRAM_ROLES)) {
                $parts[$key] = array('text' => TeamOversight_Coaches::PROGRAM_ROLES[$roles[$key]] . ', ' . $program['label'], 'accent' => $program['accent']);
            }
        }
        if ($first !== '' && isset($parts[$first])) {
            $parts = array($first => $parts[$first]) + $parts;
        }
        $parts = array_values($parts);
        foreach (isset($card['teams']) && is_array($card['teams']) ? $card['teams'] : array() as $team) {
            $role = isset($team['role']) ? (string) $team['role'] : '';
            $group = isset($team['group']) ? (string) $team['group'] : '';
            if (array_key_exists($role, TeamOversight_Coaches::TEAM_ROLES)) {
                $parts[] = array(
                    'text' => TeamOversight_Coaches::TEAM_ROLES[$role] . ', ' . (isset($team['name']) ? (string) $team['name'] : ''),
                    'accent' => array_key_exists($group, TeamOversight_Coaches::TEAM_GROUPS) ? TeamOversight_Coaches::TEAM_GROUPS[$group][2] : 'blue',
                );
            }
        }
        return $parts;
    }

    /** The grid filter keys a card answers to: its program keys and (1.83.0) its teams' groups. Never 'all', which is the All button. */
    private static function filter_keys(array $card) {
        $keys = array();
        foreach (array_keys(isset($card['programs']) && is_array($card['programs']) ? $card['programs'] : array()) as $key) {
            $key = (string) $key;
            if ($key !== 'all' && preg_match('/^[a-z0-9_]{1,30}$/D', $key) === 1) {
                $keys[$key] = true;
            }
        }
        foreach (isset($card['teams']) && is_array($card['teams']) ? $card['teams'] : array() as $team) {
            $group = isset($team['group']) ? (string) $team['group'] : '';
            if (array_key_exists($group, TeamOversight_Coaches::TEAM_GROUPS)) {
                $keys[$group] = true;
            }
        }
        return array_keys($keys);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /** A program strip: the cards in a row that swipes sideways, then Meet all our coaches when there is a grid page. */
    private static function render_strip(array $cards, array $use) {
        $programs = self::programs();
        $key = $use['program'];
        $label = isset($programs[$key]) ? $programs[$key]['label'] : '';
        $items = '';
        foreach (array_values($cards) as $i => $card) {
            $items .= self::render_card($card, array('program' => $key, 'index' => $i), 'strip');
        }
        $html = '<div class="murvc-coaches murvc-coaches--strip">'
            . '<div class="murvc-coach-strip" role="region" aria-label="' . esc_attr(trim($label . ' coaches')) . '" tabindex="0">'
            . '<ul class="murvc-coach-list">' . $items . '</ul></div>';
        $grid = self::grid_url();
        if ($grid !== '') {
            $html .= '<p class="murvc-coach-all"><a href="' . esc_url($grid . '#' . $key) . '">' . esc_html('Meet all our coaches') . '</a></p>';
        }
        return self::styles() . $html . '</div>';
    }

    /**
     * The grid (Our coaches): the filter bar and the count line (only with
     * no selector, and only when a filter button would match a card), then
     * every card with Read more. The filter script follows the bar, once.
     */
    private static function render_grid(array $cards, array $use) {
        $items = '';
        $have = array();
        foreach (array_values($cards) as $i => $card) {
            $items .= self::render_card($card, array('program' => $use['program'], 'index' => $i), 'grid');
            foreach (self::filter_keys($card) as $key) {
                $have[$key] = true;
            }
        }
        $bar = ($use['program'] === '' && $use['group'] === '' && empty($use['teams'])) ? self::filter_bar($have) : '';
        $html = '<div class="murvc-coaches murvc-coaches--grid">' . $bar . '<div class="murvc-coach-grid">' . $items . '</div></div>';
        return self::styles() . $html . ($bar !== '' ? self::filter_script() : '');
    }

    /**
     * All, then one button per program with its filter on and (from 1.83.0)
     * per team group, in their order, leaving out any that would match no
     * card ($have: filter key => true); '' when only All would be left. The
     * bar stays hidden until the script shows it, so without JavaScript
     * every card shows and nobody sees buttons that do nothing.
     */
    private static function filter_bar(array $have) {
        $buttons = array();
        foreach (self::programs() as $key => $program) {
            if (!empty($program['filter']) && isset($have[$key])) {
                $buttons[$key] = array(intval($program['order']), $key, $program['label']);
            }
        }
        foreach (TeamOversight_Coaches::TEAM_GROUPS as $key => $group) {
            if (isset($have[$key]) && !isset($buttons[$key])) {
                $buttons[$key] = array(intval($group[1]), $key, $group[0]);
            }
        }
        if (empty($buttons)) {
            return '';
        }
        usort($buttons, function ($a, $b) {
            if ($a[0] !== $b[0]) {
                return $a[0] < $b[0] ? -1 : 1;
            }
            return strcmp($a[1], $b[1]);
        });
        $html = '<div class="murvc-coach-filters" role="group" aria-label="Show coaches for" hidden>'
            . '<button type="button" data-filter="all" aria-pressed="true">' . esc_html('All') . '</button>';
        foreach ($buttons as $button) {
            $html .= '<button type="button" data-filter="' . esc_attr($button[1]) . '" aria-pressed="false">' . esc_html($button[2]) . '</button>';
        }
        return $html . '</div><p class="murvc-coach-count" aria-live="polite"></p>';
    }

    /**
     * One card's HTML: $mode 'strip' (an <li>, for a .murvc-coach-list) or
     * 'grid' (an <article id="coach-<id>">, for a .murvc-coach-grid); ''
     * for any other mode. $ctx: program (the strip's or the selected grid's
     * program: its role comes first, and a strip shows only that role;
     * with none, or a card without it, the card's first role is used),
     * index (the card's place in the list: the first three grid photos load
     * at once) and grid_url (optional; the Our coaches address the strip
     * card's name links to, else grid_url()).
     *
     * A strip card is the face, the role, the name (linked to its grid card
     * for a Full profile when there is a grid page), the chip when set and
     * the intro; a grid card has every role on its role line and, for a Full
     * profile with a bio, highlights or background, Read more. A Name only
     * card (--min) is the role and the name only. --red when the first role
     * shown is a red program or a junior team. The editor's preview calls
     * this with card_from_row()'s card and wraps it as the site does: a
     * strip card inside .murvc-coaches.murvc-coaches--strip >
     * .murvc-coach-strip > ul.murvc-coach-list, a grid card inside
     * .murvc-coaches.murvc-coaches--grid > .murvc-coach-grid, after styles().
     */
    public static function render_card(array $card, array $ctx, $mode) {
        if ($mode !== 'strip' && $mode !== 'grid') {
            return '';
        }
        $grid = $mode === 'grid';
        $id = intval(isset($card['id']) ? $card['id'] : 0);
        $full = isset($card['display']) && $card['display'] === 'full';
        $program = isset($ctx['program']) && is_string($ctx['program']) ? $ctx['program'] : '';
        $index = isset($ctx['index']) ? max(0, intval($ctx['index'])) : 0;
        $parts = self::role_parts($card, $program);
        if (!$grid) {
            $parts = array_slice($parts, 0, 1);
        }

        $classes = 'murvc-coach-card';
        if (!empty($parts) && $parts[0]['accent'] === 'red') {
            $classes .= ' murvc-coach-card--red';
        }
        if (!$full) {
            $classes .= ' murvc-coach-card--min';
        }

        $body = '';
        if (!empty($parts)) {
            $texts = array();
            foreach ($parts as $part) {
                $texts[] = $part['text'];
            }
            $body .= '<p class="murvc-coach-role">' . esc_html(implode(' · ', $texts)) . '</p>';
        }
        $name = esc_html(isset($card['name']) ? (string) $card['name'] : '');
        if (!$grid && $full && $id > 0) {
            $url = array_key_exists('grid_url', $ctx) ? (string) $ctx['grid_url'] : self::grid_url();
            if ($url !== '') {
                $name = '<a href="' . esc_url($url . '#coach-' . $id) . '">' . $name . '</a>';
            }
        }
        $body .= '<h3 class="murvc-coach-name">' . $name . '</h3>';
        if ($full) {
            if (!empty($card['chip'])) {
                $title = isset($card['chip_title']) ? (string) $card['chip_title'] : '';
                $body .= '<p class="murvc-coach-chip"' . ($title !== '' ? ' title="' . esc_attr($title) . '"' : '') . '>' . esc_html((string) $card['chip']) . '</p>';
            }
            if (!empty($card['intro'])) {
                $body .= '<p class="murvc-coach-intro">' . esc_html((string) $card['intro']) . '</p>';
            }
            if ($grid) {
                $body .= self::more($card);
            }
        }
        $inner = ($full ? self::face($card, $grid, $index) : '') . '<div class="murvc-coach-body">' . $body . '</div>';

        if ($grid) {
            return '<article class="' . esc_attr($classes) . '" id="coach-' . $id . '" data-filters="' . esc_attr(implode(' ', self::filter_keys($card))) . '">' . $inner . '</article>';
        }
        return '<li class="' . esc_attr($classes) . '">' . $inner . '</li>';
    }

    /**
     * The photo, only when card_from_row() kept it (the cleared photo, an
     * image), else the initials. alt is empty because the name is printed
     * beside it. wp_get_attachment_image(), never the post-thumbnail
     * functions, so the site's featured-image filters never run on it; its
     * output is not escaped again.
     */
    private static function face(array $card, $grid, $index) {
        $photo = intval(isset($card['photo_id']) ? $card['photo_id'] : 0);
        $img = '';
        if ($photo > 0) {
            $img = (string) wp_get_attachment_image($photo, 'medium', false, array(
                'class' => 'murvc-coach-img',
                'alt' => '',
                'loading' => ($grid && $index < 3) ? 'eager' : 'lazy',
                'decoding' => 'async',
                'sizes' => $grid ? '88px' : '64px',
            ));
        }
        if ($img === '') {
            $img = '<span class="murvc-coach-initials" aria-hidden="true">' . esc_html(self::initials(isset($card['name']) ? $card['name'] : '')) . '</span>';
        }
        return '<div class="murvc-coach-face">' . $img . '</div>';
    }

    /** Read more (grid, Full profile): the bio's paragraphs (a blank line starts one), the highlights and the playing background; '' when there are none. */
    private static function more(array $card) {
        $html = '';
        $bio = str_replace(array("\r\n", "\r"), "\n", isset($card['bio']) ? (string) $card['bio'] : '');
        foreach (preg_split('/\n[ \t]*\n/', $bio) as $para) {
            $para = trim($para);
            if ($para !== '') {
                $html .= '<p>' . nl2br(esc_html($para), false) . '</p>';
            }
        }
        $highlights = isset($card['highlights']) && is_array($card['highlights']) ? $card['highlights'] : array();
        if (!empty($highlights)) {
            $html .= '<ul class="murvc-coach-highlights">';
            foreach ($highlights as $item) {
                $html .= '<li>' . esc_html((string) $item) . '</li>';
            }
            $html .= '</ul>';
        }
        if (!empty($card['background'])) {
            $html .= '<p class="murvc-coach-played">' . esc_html('Played: ' . $card['background']) . '</p>';
        }
        return $html === '' ? '' : '<details class="murvc-coach-more"><summary>' . esc_html('Read more') . '</summary>' . $html . '</details>';
    }

    /** "JD" for "Jo Dee Doe": the first letter of the first and last words, upper-cased (a word's first letter or digit, skipping a leading quote or bracket). */
    public static function initials($name) {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || empty($words)) {
            return '';
        }
        $picked = count($words) > 1 ? array($words[0], $words[count($words) - 1]) : array($words[0]);
        $out = '';
        foreach ($picked as $word) {
            $out .= preg_match('/[\p{L}\p{N}]/u', $word, $m) === 1 ? $m[0] : mb_substr($word, 0, 1, 'UTF-8');
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($out, 'UTF-8') : strtoupper($out);
    }

    // ------------------------------------------------------------------
    // Style and script (each printed once per request)
    // ------------------------------------------------------------------

    /**
     * The coach lists' <style> block, returned (never echoed) the first
     * time it is asked for in a request and '' after that; the shortcode
     * asks only from a render that outputs something, and the editor's
     * preview echoes it before its cards. Everything is scoped under
     * .murvc-coaches with no .button (the theme restyles it), using the
     * theme's colour tokens with the site's colours as fallbacks. The
     * [hidden] rules are what let the filter hide a card. A strip shows
     * 2.3 cards across from 600px (the content column is about 840px wide),
     * one compact card with the next cut off on a phone.
     */
    public static function styles() {
        if (!empty(self::$printed['styles'])) {
            return '';
        }
        self::$printed['styles'] = true;
        return '<style>
        .murvc-coaches{--cc-blue:var(--wp--preset--color--accent-2,#1f4e9c);--cc-red:var(--wp--preset--color--accent-1,#c8102e);--cc-navy:var(--wp--preset--color--accent-3,#0f2a52);--cc-muted:var(--wp--preset--color--accent-4,#5b6474);--cc-bg:var(--wp--preset--color--accent-5,#f3f5f8);--cc-border:var(--wp--preset--color--accent-6,#d9dee6);--cc-tint-red:#fbe9ec;--cc-tint-blue:#e8eef8;margin:16px 0}
        .murvc-coaches ul{list-style:none;margin:0;padding:0}
        .murvc-coach-strip{overflow-x:auto;scroll-snap-type:x mandatory;overscroll-behavior-x:contain;padding-bottom:6px}
        .murvc-coach-strip:focus-visible{outline:2px solid var(--cc-blue);outline-offset:2px}
        .murvc-coach-list{display:flex;gap:12px}
        .murvc-coaches--strip .murvc-coach-card{flex:0 0 78%;scroll-snap-align:start}
        @media (min-width:600px){.murvc-coaches--strip .murvc-coach-card{flex-basis:calc((100% - 12px)/2.3)}}
        .murvc-coach-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(min(19rem,100%),1fr))}
        .murvc-coach-card{display:flex;gap:12px;align-items:flex-start;min-width:0;box-sizing:border-box;background:var(--cc-bg);border-left:5px solid var(--cc-blue);border-radius:8px;padding:14px 16px}
        .murvc-coach-card[hidden]{display:none}
        .murvc-coach-card--red{border-left-color:var(--cc-red)}
        .murvc-coach-card--min{padding:10px 14px}
        .murvc-coach-face{flex:0 0 64px}
        .murvc-coach-body{flex:1 1 auto;min-width:0;overflow-wrap:break-word}
        .murvc-coach-img,.murvc-coach-initials{width:64px;height:64px;border-radius:50%;object-fit:cover;object-position:50% 25%;display:block}
        .murvc-coach-initials{display:flex;align-items:center;justify-content:center;font-weight:700;background:var(--cc-tint-blue);color:var(--cc-blue)}
        .murvc-coach-card--red .murvc-coach-initials{background:var(--cc-tint-red);color:var(--cc-red)}
        .murvc-coach-role{margin:0 0 4px;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--cc-blue)}
        .murvc-coach-card--red .murvc-coach-role{color:var(--cc-red)}
        .murvc-coach-name{margin:0 0 4px;font-size:1.1em;line-height:1.25;color:var(--cc-navy)}
        .murvc-coach-name a{color:inherit;text-decoration:none}
        .murvc-coach-intro{margin:0 0 4px;font-size:14px;color:var(--cc-muted)}
        .murvc-coach-chip{display:inline-block;margin:2px 0 6px;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:#fff;border:1px solid var(--cc-border);color:var(--cc-navy)}
        .murvc-coaches--grid .murvc-coach-face{flex-basis:88px}
        .murvc-coaches--grid .murvc-coach-img,.murvc-coaches--grid .murvc-coach-initials{width:88px;height:88px}
        .murvc-coach-filters{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
        .murvc-coach-filters[hidden]{display:none}
        .murvc-coach-filters button{font:inherit;font-size:14px;padding:6px 14px;border-radius:20px;border:2px solid var(--cc-blue);background:#fff;color:var(--cc-blue);cursor:pointer;text-transform:none}
        .murvc-coach-filters button[aria-pressed=true]{background:var(--cc-blue);color:#fff}
        .murvc-coach-more summary{cursor:pointer;color:var(--cc-blue);font-weight:600}
        .murvc-coach-more p{margin:6px 0}
        .murvc-coaches .murvc-coach-highlights{list-style:disc;margin:6px 0;padding-left:1.2em}
        .murvc-coach-played{color:var(--cc-muted)}
        .murvc-coach-all a{display:inline-block;padding:8px 16px;border:2px solid var(--cc-blue);border-radius:6px;color:var(--cc-blue);font-weight:700;text-decoration:none}
        .murvc-coach-line a{color:inherit;text-decoration:underline}
        </style>';
    }

    /**
     * The grid's filter script, once per request, after the first grid
     * with a filter bar: it shows the bar, filters cards with the hidden
     * attribute and says how many show, and pre-selects a filter named by
     * the address's #key (Meet all our coaches links to #academy). Filters
     * are never query arguments, which would split the page cache; a
     * #coach-12 link simply scrolls, because the page loads on All.
     */
    private static function filter_script() {
        if (!empty(self::$printed['script'])) {
            return '';
        }
        self::$printed['script'] = true;
        return '<script>
        (function () {
            function init() {
                document.querySelectorAll(".murvc-coaches--grid").forEach(function (g) {
                    var bar = g.querySelector(".murvc-coach-filters");
                    if (!bar || bar.getAttribute("data-ready")) { return; }
                    bar.setAttribute("data-ready", "1");
                    bar.hidden = false;
                    var cards = g.querySelectorAll(".murvc-coach-card"), count = g.querySelector(".murvc-coach-count");
                    function pick(k) {
                        var n = 0;
                        bar.querySelectorAll("button[data-filter]").forEach(function (b) { b.setAttribute("aria-pressed", b.getAttribute("data-filter") === k ? "true" : "false"); });
                        cards.forEach(function (c) {
                            var on = k === "all" || (" " + c.getAttribute("data-filters") + " ").indexOf(" " + k + " ") > -1;
                            c.hidden = !on;
                            if (on) { n++; }
                        });
                        if (count) { count.textContent = "Showing " + n + (n === 1 ? " coach" : " coaches"); }
                    }
                    bar.addEventListener("click", function (e) {
                        var b = e.target.closest("button[data-filter]");
                        if (b) { pick(b.getAttribute("data-filter")); }
                    });
                    var h = location.hash.slice(1);
                    if (/^[a-z0-9_]{1,30}$/.test(h) && bar.querySelector("button[data-filter=\"" + h + "\"]")) { pick(h); }
                });
            }
            if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", init); } else { init(); }
        })();
        </script>';
    }
}
