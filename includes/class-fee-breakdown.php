<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where your fees go (1.89.0, club decision 6 Nov 2026): a small block in
 * the fees step of the Player Checklist ([ready_to_play]) showing players
 * what share of the money goes where (and, when the committee fills it in,
 * where it comes from), so they can see the club makes no money from fees
 * and that Volleyball Victoria gets paid.
 *
 * The committee types PERCENTAGES per season on VVL Oversight ->
 * Configuration (the treasurer works them out from the budget offline).
 * No dollar figure is stored or shown anywhere: save() refuses one in any
 * text, and render() draws percentages only.
 *
 * One option, not autoloaded (OPTION), keyed by season (four digits). Each
 * entry: show (bool), source (plain text, at most SOURCE_MAX), note (plain
 * text, at most NOTE_MAX), go (at most MAX_GO rows of label (plain text, at
 * most LABEL_MAX) and pct (a whole number from 1 to 100)) and from (at most
 * MAX_FROM rows, the same). No account id is stored anywhere, so there is
 * nothing for the account merge. A save writes one season_config log line.
 *
 * Readers: get(), for_checklist(), checklist_block(). The one writer:
 * save(). The block: render() (styles printed once per request). The admin
 * box: render_admin_box() on Configuration, posting to admin-post
 * (handle_post(), process_post()) with Post/Redirect/Get.
 */
class TeamOversight_Fee_Breakdown {

    const OPTION = 'team_oversight_fee_breakdown';
    /** admin-post action of the Configuration box. */
    const ACTION = 'murvc_fee_breakdown';
    /** Nonce field; the nonce action is NONCE_ACTION plus the season. */
    const NONCE_FIELD = 'murvc_fee_breakdown_nonce';
    const NONCE_ACTION = 'murvc_fee_breakdown_';
    /** The notice (and, after a refused save, what was typed) for one redisplay: + user id. */
    const FLASH_PREFIX = 'murvc_fee_breakdown_flash_';
    const FLASH_SECONDS = 300;
    /** An existing log type (vvl channel): the season's configuration. */
    const LOG_TYPE = 'season_config';

    const MAX_GO = 6;
    const MAX_FROM = 4;
    const LABEL_MAX = 40;
    const SOURCE_MAX = 80;
    const NOTE_MAX = 240;
    /** A segment carries its percentage inside it only from this size up. */
    const INSIDE_LABEL_MIN = 12;

    /** Fixed order, never cycled (a bar has at most as many rows as colours). */
    const GO_COLOURS = array('#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#6250d6');
    const FROM_COLOURS = array('#008300', '#8a5cf6', '#898781', '#3a3a38');
    /** Text inside a segment is white, except on these colours. */
    const DARK_TEXT = array('#eda100' => '#3a2e00');

    /** What players read. */
    const HEADING = 'Where your fees go';
    const FROM_HEADING = 'Where the money comes from';

    private static $styles_printed = false;

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_post'));
    }

    /** The two bars: key => the name the admin box (and its refusals) gives it, the most rows, its colours. */
    private static function bars() {
        return array(
            'go' => array('name' => 'Where the money goes', 'max' => self::MAX_GO, 'colours' => self::GO_COLOURS),
            'from' => array('name' => 'Where it comes from', 'max' => self::MAX_FROM, 'colours' => self::FROM_COLOURS),
        );
    }

    public static function defaults() {
        return array('show' => false, 'source' => '', 'note' => '', 'go' => array(), 'from' => array());
    }

    public static function valid_season($season) {
        return (is_string($season) || is_int($season)) && preg_match('/^\d{4}$/D', (string) $season) === 1;
    }

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    /** The season's entry, normalised (defaults when there is none, tolerant of missing keys and junk). */
    public static function get($season) {
        if (!self::valid_season($season)) {
            return self::defaults();
        }
        $all = get_option(self::OPTION, array());
        $season = (string) $season;
        if (!is_array($all) || !isset($all[$season]) || !is_array($all[$season])) {
            return self::defaults();
        }
        return self::normalise($all[$season]);
    }

    /**
     * The structural reading of an entry: never refuses, drops what isn't
     * right (a row without a label or a whole percentage from 1 to 100,
     * rows past a bar's limit), and fills what is missing with defaults.
     */
    public static function normalise($entry) {
        $out = self::defaults();
        if (!is_array($entry)) {
            return $out;
        }
        $out['show'] = !empty($entry['show']);
        foreach (array('source', 'note') as $key) {
            $out[$key] = (isset($entry[$key]) && is_string($entry[$key])) ? trim($entry[$key]) : '';
        }
        foreach (self::bars() as $bar => $def) {
            $rows = array();
            $raw = (isset($entry[$bar]) && is_array($entry[$bar])) ? $entry[$bar] : array();
            foreach ($raw as $row) {
                if (count($rows) >= $def['max']) {
                    break;
                }
                if (!is_array($row)) {
                    continue;
                }
                $label = (isset($row['label']) && is_string($row['label'])) ? trim($row['label']) : '';
                $pct = isset($row['pct']) ? self::whole_pct($row['pct']) : null;
                if ($label === '' || $pct === null) {
                    continue;
                }
                $rows[] = array('label' => $label, 'pct' => $pct);
            }
            $out[$bar] = $rows;
        }
        return $out;
    }

    /**
     * What the Player Checklist shows for the season, or null: null unless
     * Show is on and Where the money goes has at least one row (and adds up
     * to 100, which every save makes sure of). Where it comes from is left
     * out if it does not add up to 100.
     */
    public static function for_checklist($season) {
        $entry = self::get($season);
        if (empty($entry['show']) || empty($entry['go']) || self::total($entry['go']) !== 100) {
            return null;
        }
        if (!empty($entry['from']) && self::total($entry['from']) !== 100) {
            $entry['from'] = array();
        }
        return $entry;
    }

    /** The block for the season's Player Checklist ('' when for_checklist() is null). Never throws. */
    public static function checklist_block($season) {
        try {
            $entry = self::for_checklist($season);
            return $entry === null ? '' : self::render($season, $entry);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function total(array $rows) {
        $sum = 0;
        foreach ($rows as $row) {
            $sum += intval($row['pct']);
        }
        return $sum;
    }

    /** A whole percentage from 1 to 100 (an int, or digits as text), else null. */
    private static function whole_pct($value) {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && preg_match('/^\d{1,3}$/D', trim($value))) {
            $n = intval(trim($value));
        } elseif (is_float($value) && is_finite($value) && floor($value) == $value) {
            $n = intval($value);
        } else {
            return null;
        }
        return ($n >= 1 && $n <= 100) ? $n : null;
    }

    // ------------------------------------------------------------------
    // The writer
    // ------------------------------------------------------------------

    /**
     * Save the season's entry. $fields: show, source, note, go and from
     * (each a list of array(label, pct)); text is expected plain already
     * (the handler runs sanitize_text_field() after wp_unslash()), and
     * anything else is refused, never cleaned or clipped. Blank rows are
     * dropped. Refused, with nothing written: a row with a label and no
     * percentage or the other way round, a percentage that is not a whole
     * number from 1 to 100, a bar with rows that does not add up to 100, too
     * many rows, text that is too long, not plain or holds a dollar figure,
     * Show on with no Where the money goes rows, an actor who is not an
     * administrator. Compare-before-write; one log line per save that
     * changes something. Returns array(ok, changed, message, errors (plain
     * sentences), entry (the normalised entry as saved, or as stored when
     * refused)).
     */
    public static function save($season, $fields, $actor) {
        if (!self::valid_season($season)) {
            return self::result(false, false, 'Choose a season first. Nothing was saved.', array(), self::defaults());
        }
        $season = (string) $season;
        if (intval($actor) <= 0 || !user_can(intval($actor), 'manage_options')) {
            return self::result(false, false, 'Only an administrator can change Where your fees go. Nothing was saved.', array(), self::get($season));
        }
        $fields = is_array($fields) ? $fields : array();
        $errors = array();
        $entry = self::defaults();
        $entry['show'] = !empty($fields['show']);
        $entry['source'] = self::check_text(isset($fields['source']) ? $fields['source'] : '', 'Figures from', self::SOURCE_MAX, $errors);
        $entry['note'] = self::check_text(isset($fields['note']) ? $fields['note'] : '', 'The note', self::NOTE_MAX, $errors);
        $bars_ok = array();
        foreach (self::bars() as $bar => $def) {
            list($rows, $bars_ok[$bar]) = self::check_rows(isset($fields[$bar]) ? $fields[$bar] : array(), $def, $errors);
            $entry[$bar] = $rows;
        }
        if ($entry['show'] && empty($entry['go']) && $bars_ok['go']) {
            $errors[] = 'To show it on the Player Checklist, fill in at least one row of Where the money goes.';
        }
        if (!empty($errors)) {
            return self::result(false, false, 'Nothing was saved.', $errors, self::get($season));
        }

        global $wpdb;
        $lock = 'murvc_fee_breakdown_' . md5($wpdb->prefix);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 5)) !== '1') {
            return self::result(false, false, 'Another save of Where your fees go is running. Try again in a moment. Nothing was saved.', array(), self::get($season));
        }
        try {
            wp_cache_delete(self::OPTION, 'options');
            $all = get_option(self::OPTION, array());
            $all = is_array($all) ? $all : array();
            $before = (isset($all[$season]) && is_array($all[$season])) ? self::normalise($all[$season]) : self::defaults();
            if ($before === $entry) {
                return self::result(true, false, 'Nothing changed for ' . $season . '.', array(), $before);
            }
            $all[$season] = $entry;
            update_option(self::OPTION, $all, false);
            wp_cache_delete(self::OPTION, 'options');
            $check = get_option(self::OPTION, array());
            if (!is_array($check) || !isset($check[$season]) || self::normalise($check[$season]) !== $entry) {
                return self::result(false, false, 'The database did not take the change. Nothing was saved: try again.', array(), $before);
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }

        TeamOversight_Log::add(self::LOG_TYPE, self::log_line($season, $before, $entry));
        $message = $entry['show']
            ? 'Saved for ' . $season . '. It shows in the fees step of the ' . $season . ' Player Checklist.'
            : 'Saved for ' . $season . '. It is not on the Player Checklist: tick Show on the Player Checklist to show it.';
        return self::result(true, true, $message, array(), $entry);
    }

    private static function result($ok, $changed, $message, array $errors, array $entry) {
        return array('ok' => (bool) $ok, 'changed' => (bool) $changed, 'message' => (string) $message, 'errors' => array_values($errors), 'entry' => $entry);
    }

    /** Plain text of at most $max characters with no dollar figure, else '' and a sentence in $errors. */
    private static function check_text($value, $name, $max, array &$errors) {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $errors[] = $name . ' must be plain text.';
            return '';
        }
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (sanitize_text_field($text) !== $text) {
            $errors[] = $name . ' must be plain text, with no HTML and no line breaks.';
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $max) {
            $errors[] = $name . ' can be at most ' . $max . ' characters; it has ' . $length . '.';
            return '';
        }
        if (self::has_money($text)) {
            $errors[] = $name . ' can\'t hold a dollar figure: this block shows percentages only.';
            return '';
        }
        return $text;
    }

    /** A dollar sign, or an amount with cents (12.50). */
    private static function has_money($text) {
        return strpos((string) $text, '$') !== false || preg_match('/\b\d[\d,]*\.\d{2}\b/', (string) $text) === 1;
    }

    /** One bar's rows: array(rows (blank ones dropped), ok). Refusals go in $errors, naming the row as typed. */
    private static function check_rows($raw, array $def, array &$errors) {
        $name = $def['name'];
        if ($raw === null || $raw === '') {
            return array(array(), true);
        }
        if (!is_array($raw)) {
            $errors[] = $name . ' could not be read.';
            return array(array(), false);
        }
        $rows = array();
        $ok = true;
        $filled = 0;
        foreach (array_values($raw) as $i => $row) {
            $num = $i + 1;
            if (!is_array($row)) {
                $errors[] = 'Row ' . $num . ' of ' . $name . ' could not be read.';
                $ok = false;
                continue;
            }
            $label = isset($row['label']) ? $row['label'] : '';
            $pct = isset($row['pct']) ? $row['pct'] : '';
            $label_blank = is_scalar($label) && trim((string) $label) === '';
            $pct_blank = $pct === null || (is_string($pct) && trim($pct) === '');
            if ($label_blank && $pct_blank) {
                continue;
            }
            $filled++;
            if ($pct_blank) {
                $errors[] = 'Row ' . $num . ' of ' . $name . ' has a label but no percentage.';
                $ok = false;
                continue;
            }
            if ($label_blank) {
                $errors[] = 'Row ' . $num . ' of ' . $name . ' has a percentage but no label.';
                $ok = false;
                continue;
            }
            $count = count($errors);
            $text = self::check_text($label, 'The label in row ' . $num . ' of ' . $name, self::LABEL_MAX, $errors);
            $whole = self::whole_pct($pct);
            if ($whole === null) {
                $errors[] = 'Row ' . $num . ' of ' . $name . ' needs a whole number from 1 to 100 as its percentage.';
            }
            if (count($errors) > $count) {
                $ok = false;
                continue;
            }
            $rows[] = array('label' => $text, 'pct' => $whole);
        }
        if ($filled > $def['max']) {
            $errors[] = $name . ' takes at most ' . $def['max'] . ' rows; it has ' . $filled . '.';
            $ok = false;
        }
        if ($ok && !empty($rows)) {
            $total = self::total($rows);
            if ($total !== 100) {
                $errors[] = $name . ' adds up to ' . $total . '%. It needs to add up to 100%.';
                $ok = false;
            }
        }
        return array($ok ? $rows : array(), $ok);
    }

    /** The log line: the season and what changed, under the log's 500 bytes. */
    private static function log_line($season, array $before, array $after) {
        $rows_text = function (array $rows) {
            $parts = array();
            foreach ($rows as $row) {
                $parts[] = $row['label'] . ' ' . $row['pct'] . '%';
            }
            return implode(', ', $parts);
        };
        $build = function ($short) use ($season, $before, $after, $rows_text) {
            $parts = array();
            if ($before['show'] !== $after['show']) {
                $parts[] = $after['show'] ? 'shown on the Player Checklist' : 'taken off the Player Checklist';
            }
            if ($before['source'] !== $after['source']) {
                $parts[] = $after['source'] === '' ? 'figures from cleared' : ($short > 1 ? 'figures from changed' : 'figures from: ' . $after['source']);
            }
            if ($before['note'] !== $after['note']) {
                $parts[] = $after['note'] === '' ? 'note cleared' : 'note changed';
            }
            foreach (self::bars() as $bar => $def) {
                if ($before[$bar] === $after[$bar]) {
                    continue;
                }
                $name = strtolower(substr($def['name'], 0, 1)) . substr($def['name'], 1);
                if (empty($after[$bar])) {
                    $parts[] = $name . ' cleared';
                } elseif ($short > 0) {
                    $parts[] = $name . ' changed (' . count($after[$bar]) . ' rows)';
                } else {
                    $parts[] = $name . ': ' . $rows_text($after[$bar]);
                }
            }
            return $season . ' Where your fees go (Player Checklist) saved: ' . implode('; ', $parts) . '.';
        };
        foreach (array(0, 1, 2) as $short) {
            $line = $build($short);
            if (strlen($line) <= 500) {
                return $line;
            }
        }
        return $season . ' Where your fees go (Player Checklist) saved.';
    }

    // ------------------------------------------------------------------
    // The block
    // ------------------------------------------------------------------

    /**
     * The block as HTML for the season's $entry (normalised here; '' when
     * Where the money goes has no rows). Show is not checked: the admin
     * preview draws a hidden entry too (for_checklist() decides what the
     * checklist shows). Everything escaped, percentages only, no script,
     * no title attribute. The styles come with the first block of the
     * request.
     */
    public static function render($season, $entry) {
        $entry = self::normalise($entry);
        if (empty($entry['go'])) {
            return '';
        }
        $html = self::styles();
        $html .= '<div class="murvc-feego"' . (self::valid_season($season) ? ' data-season="' . esc_attr((string) $season) . '"' : '') . '>';
        $html .= '<h4 class="murvc-feego-title">' . esc_html(self::HEADING) . '</h4>';
        if ($entry['note'] !== '') {
            $html .= '<p class="murvc-feego-note">' . esc_html($entry['note']) . '</p>';
        }
        $html .= self::bar_html(self::HEADING, $entry['go'], self::GO_COLOURS);
        if (!empty($entry['from'])) {
            $html .= '<h5 class="murvc-feego-sub">' . esc_html(self::FROM_HEADING) . '</h5>';
            $html .= self::bar_html(self::FROM_HEADING, $entry['from'], self::FROM_COLOURS);
        }
        $source = rtrim($entry['source'], ". \t");
        if ($source !== '') {
            $html .= '<p class="murvc-feego-source">' . esc_html('Figures from ' . $source . '.') . '</p>';
        }
        $html .= '</div>';
        return $html;
    }

    /** One stacked bar (role img, its aria-label listing every segment) and its legend. */
    private static function bar_html($name, array $rows, array $colours) {
        $spoken = array();
        $segments = '';
        $legend = '';
        foreach (array_values($rows) as $i => $row) {
            if (!isset($colours[$i])) {
                break;
            }
            $colour = $colours[$i];
            $pct = intval($row['pct']);
            $dark = self::DARK_TEXT;
            $text_colour = isset($dark[$colour]) ? $dark[$colour] : '#ffffff';
            $spoken[] = $row['label'] . ' ' . $pct . '%';
            $segments .= '<span class="murvc-feego-seg" style="flex-grow:' . $pct . ';background-color:' . $colour . ';color:' . $text_colour . ';">'
                . ($pct >= self::INSIDE_LABEL_MIN ? $pct . '%' : '') . '</span>';
            $legend .= '<li><span class="murvc-feego-key" style="background-color:' . $colour . ';" aria-hidden="true"></span>'
                . '<span class="murvc-feego-label">' . esc_html($row['label']) . ' <span class="murvc-feego-pct">' . $pct . '%</span></span></li>';
        }
        return '<div class="murvc-feego-bar" role="img" aria-label="' . esc_attr($name . ': ' . implode(', ', $spoken) . '.') . '">' . $segments . '</div>'
            . '<ul class="murvc-feego-legend">' . $legend . '</ul>';
    }

    /**
     * The block's styles, once per request ('' after the first call). Mobile
     * first: nothing fixed-width, the legend wraps, no sideways scroll. The
     * 2px gaps are margins (older Safari has no flex gap), so the page
     * shows through them.
     */
    public static function styles() {
        if (self::$styles_printed) {
            return '';
        }
        self::$styles_printed = true;
        return '<style>
        .murvc-feego{margin:14px 0 6px;max-width:100%;min-width:0;box-sizing:border-box;color:inherit}
        .murvc-feego .murvc-feego-title{margin:0 0 4px;padding:0;font-size:15px;line-height:1.3;font-weight:600;text-transform:none;letter-spacing:normal;color:inherit}
        .murvc-feego .murvc-feego-note{margin:0 0 8px;padding:0}
        .murvc-feego .murvc-feego-sub{margin:14px 0 4px;padding:0;font-size:14px;line-height:1.3;font-weight:600;text-transform:none;letter-spacing:normal;color:inherit}
        .murvc-feego .murvc-feego-bar{display:flex;width:100%;height:24px;margin:6px 0;padding:0;border-radius:4px;overflow:hidden;background:transparent}
        .murvc-feego .murvc-feego-seg{display:block;flex-shrink:1;flex-basis:0;min-width:0;height:24px;overflow:hidden;white-space:nowrap;text-align:center;font-size:12px;font-weight:700;line-height:24px}
        .murvc-feego .murvc-feego-seg+.murvc-feego-seg{margin-left:2px}
        .murvc-feego .murvc-feego-legend{display:flex;flex-wrap:wrap;margin:4px 0 0;padding:0;list-style:none;font-size:13px;line-height:1.4}
        .murvc-feego .murvc-feego-legend li{display:flex;align-items:center;min-width:0;margin:0 14px 4px 0;padding:0;list-style:none;overflow-wrap:anywhere;color:inherit}
        .murvc-feego .murvc-feego-legend li::before{content:none}
        .murvc-feego .murvc-feego-key{flex:0 0 10px;width:10px;height:10px;margin:0 6px 0 0;border-radius:2px}
        .murvc-feego .murvc-feego-pct{font-weight:600}
        .murvc-feego .murvc-feego-source{margin:10px 0 0;padding:0;font-size:12px;color:#666}
        </style>';
    }

    // ------------------------------------------------------------------
    // Configuration: the admin box and its handler
    // ------------------------------------------------------------------

    /**
     * admin-post murvc_fee_breakdown: administrators only, the season's own
     * nonce, then process_post() and back to the box (Post/Redirect/Get).
     */
    public static function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        $season = (isset($_POST['season']) && is_string($_POST['season']) && self::valid_season($_POST['season'])) ? (string) $_POST['season'] : '';
        check_admin_referer(self::NONCE_ACTION . $season, self::NONCE_FIELD);
        self::process_post($season, $_POST, get_current_user_id());
        wp_safe_redirect(self::config_url($season) . '#fee-breakdown');
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
        set_transient(self::FLASH_PREFIX . intval($user_id), array(
            'season' => self::valid_season($season) ? (string) $season : '',
            'ok' => $result['ok'],
            'changed' => $result['changed'],
            'message' => $result['message'],
            'errors' => $result['errors'],
            'typed' => $result['ok'] ? null : $fields,
        ), self::FLASH_SECONDS);
        return $result;
    }

    /** The box's fields from a slashed post: every typed text through wp_unslash() then sanitize_text_field(). */
    public static function fields_from_post(array $post) {
        $text = function ($value) {
            return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
        };
        $fields = array(
            'show' => !empty($post['show']),
            'source' => isset($post['source']) ? $text($post['source']) : '',
            'note' => isset($post['note']) ? $text($post['note']) : '',
        );
        foreach (self::bars() as $bar => $def) {
            $labels = (isset($post[$bar . '_label']) && is_array($post[$bar . '_label'])) ? $post[$bar . '_label'] : array();
            $pcts = (isset($post[$bar . '_pct']) && is_array($post[$bar . '_pct'])) ? $post[$bar . '_pct'] : array();
            $count = min(20, max(count($labels), count($pcts)));
            $rows = array();
            for ($i = 0; $i < $count; $i++) {
                $rows[] = array(
                    'label' => isset($labels[$i]) ? $text($labels[$i]) : '',
                    'pct' => isset($pcts[$i]) ? trim($text($pcts[$i])) : '',
                );
            }
            $fields[$bar] = $rows;
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

    /** Text values for the form: every row of both bars, as strings, from a stored entry or what was typed. */
    private static function form_values($fields) {
        $fields = is_array($fields) ? $fields : array();
        $string = function ($value) {
            return is_scalar($value) ? (string) $value : '';
        };
        $out = array(
            'show' => !empty($fields['show']),
            'source' => isset($fields['source']) ? $string($fields['source']) : '',
            'note' => isset($fields['note']) ? $string($fields['note']) : '',
        );
        foreach (self::bars() as $bar => $def) {
            $raw = (isset($fields[$bar]) && is_array($fields[$bar])) ? array_values($fields[$bar]) : array();
            $rows = array();
            for ($i = 0; $i < $def['max']; $i++) {
                $row = (isset($raw[$i]) && is_array($raw[$i])) ? $raw[$i] : array();
                $rows[] = array(
                    'label' => isset($row['label']) ? $string($row['label']) : '',
                    'pct' => isset($row['pct']) ? $string($row['pct']) : '',
                );
            }
            $out[$bar] = $rows;
        }
        return $out;
    }

    /**
     * Configuration (VVL Oversight), the selected season: the Where your fees
     * go box (anchor fee-breakdown), its form, and a preview drawn by
     * render() from the stored values. ECHOES. Administrators only.
     */
    public static function render_admin_box($season) {
        if (!current_user_can('manage_options') || !self::valid_season($season)) {
            return;
        }
        $season = (string) $season;
        $flash = self::take_flash();
        $stored = self::get($season);
        $typed = ($flash !== null && isset($flash['season']) && $flash['season'] === $season && isset($flash['typed']) && is_array($flash['typed'])) ? $flash['typed'] : null;
        $form = self::form_values($typed !== null ? $typed : $stored);
        $shown = self::for_checklist($season) !== null;
        $bars = self::bars();
        ?>
        <div id="fee-breakdown" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
            <h2 style="margin-top: 0;">Where your fees go (Player Checklist)</h2>
            <p class="description">Players see this in the fees step of their <?php echo esc_html($season); ?> Player Checklist: a bar showing where the money goes and, if you fill it in, a bar showing where it comes from. Type whole percentages; the treasurer works them out from the budget. No dollar amount is stored or shown.</p>
            <?php if ($flash !== null): ?>
                <?php $type = empty($flash['ok']) ? 'error' : (!empty($flash['changed']) ? 'success' : 'info'); ?>
                <div class="notice notice-<?php echo esc_attr($type); ?> inline">
                    <p><?php echo esc_html(isset($flash['message']) && is_scalar($flash['message']) ? (string) $flash['message'] : ''); ?></p>
                    <?php if (!empty($flash['errors']) && is_array($flash['errors'])): ?>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <?php foreach ($flash['errors'] as $error): ?>
                                <?php if (is_scalar($error)): ?>
                                    <li><?php echo esc_html((string) $error); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($typed !== null): ?>
                        <p>The form below still holds what you typed.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION . $season, self::NONCE_FIELD); ?>
                <p style="margin: 8px 0;">
                    <label><input type="checkbox" name="show" value="1" <?php checked($form['show']); ?>> Show on the Player Checklist</label>
                </p>
                <p style="margin: 8px 0;">
                    <label for="fee-breakdown-source">Figures from</label><br>
                    <input type="text" id="fee-breakdown-source" name="source" class="regular-text" value="<?php echo esc_attr($form['source']); ?>" aria-describedby="fee-breakdown-source-hint">
                    <span class="description" id="fee-breakdown-source-hint">Up to <?php echo intval(self::SOURCE_MAX); ?> characters. Type <em>the club's 2026 budget</em> and players read: Figures from the club's 2026 budget.</span>
                </p>
                <p style="margin: 8px 0;">
                    <label for="fee-breakdown-note">Note</label><br>
                    <input type="text" id="fee-breakdown-note" name="note" class="large-text" value="<?php echo esc_attr($form['note']); ?>" aria-describedby="fee-breakdown-note-hint">
                    <span class="description" id="fee-breakdown-note-hint">A sentence under the heading, up to <?php echo intval(self::NOTE_MAX); ?> characters, e.g. <em>The club doesn't make money from fees.</em></span>
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 16px 30px; align-items: flex-start; margin: 12px 0 4px;">
                    <?php foreach ($bars as $bar => $def): ?>
                        <table class="widefat striped" style="width: auto; min-width: 0; max-width: 100%;">
                            <caption style="text-align: left; font-weight: 600; padding: 0 0 6px;"><?php echo esc_html($def['name']); ?></caption>
                            <thead>
                                <tr><th scope="col" style="width: 3em;">Row</th><th scope="col">Label</th><th scope="col">%</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($form[$bar] as $i => $row): ?>
                                    <tr>
                                        <td><?php echo intval($i + 1); ?></td>
                                        <td><input type="text" name="<?php echo esc_attr($bar); ?>_label[<?php echo intval($i); ?>]" value="<?php echo esc_attr($row['label']); ?>" aria-label="<?php echo esc_attr($def['name'] . ', row ' . ($i + 1) . ', label'); ?>" style="width: 16em; max-width: 100%;"></td>
                                        <td><input type="number" name="<?php echo esc_attr($bar); ?>_pct[<?php echo intval($i); ?>]" value="<?php echo esc_attr($row['pct']); ?>" min="1" max="100" step="1" inputmode="numeric" aria-label="<?php echo esc_attr($def['name'] . ', row ' . ($i + 1) . ', percentage'); ?>" style="width: 5em;"></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>
                </div>
                <p class="description" style="margin: 4px 0 10px;">Leave a row blank to skip it. Each part that has rows must add up to 100%. Labels are up to <?php echo intval(self::LABEL_MAX); ?> characters; Where it comes from can stay empty.</p>
                <input type="submit" class="button button-primary" value="Save Where your fees go">
            </form>
            <p style="margin: 10px 0 0;">
                <?php if ($shown): ?>
                    <strong>Shown</strong> on the <?php echo esc_html($season); ?> Player Checklist, in the fees step.
                <?php else: ?>
                    <strong>Not shown</strong> on the <?php echo esc_html($season); ?> Player Checklist.
                <?php endif; ?>
            </p>
            <h3 style="margin: 18px 0 4px;">Preview</h3>
            <p class="description" style="margin-top: 0;">From the saved figures, drawn the way players see it.</p>
            <?php $preview = self::render($season, $stored); ?>
            <?php if ($preview !== ''): ?>
                <div style="max-width: 600px; border: 1px dashed #c3c4c7; border-radius: 6px; padding: 4px 16px 12px; background: #fff;"><?php echo $preview; ?></div>
            <?php else: ?>
                <p style="color: #996800;">Nothing to preview yet: fill in Where the money goes and save.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
