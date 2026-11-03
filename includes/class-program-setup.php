<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club Programs setup (1.84.0): each program's booking settings, kept in the
 * option team_oversight_program_setup (autoload off), keyed by program key.
 *
 * They live apart from team_oversight_programs on purpose: an older Club
 * Manager rebuilds that option from four fields on every Settings save, so a
 * rollback plus one save would wipe anything kept there. A program with no
 * entry here behaves exactly as before.
 *
 * In 1.84.0 this class holds:
 * - the reader, get(): defaults plus the STRUCTURAL pass of validate() (type,
 *   shape and range only). A scalar field that fails reads as its default; a
 *   list keeps its good entries and drops each bad one by itself. It never
 *   writes, never queries, never asks owner_of_product() and never calls
 *   another program's get(), so no check can recurse;
 * - validate(), the 3.7 table of the program bookings design, complete (the
 *   full pass adds the lookups that concern one field: the product and its
 *   shape, a published page, a used slot);
 * - merged_problems(), the only place for checks across fields or programs,
 *   called only by save();
 * - the one writer, save(): ONE option-wide named lock (lock_name()), the
 *   per-program rev compare-and-refuse, the stored row read and confirmed
 *   straight from the database (stored_row(), never a cached copy), stored
 *   fields this version doesn't know kept, one program_setup log line;
 * - the pure tier maths (tier_pct(), discounted_cents(), tier_dip()) that the
 *   booking code will call, so the rule lives in one place.
 *
 * No screen and no hooks yet (the Setup tab comes with 1.85.0), and nothing
 * in the plugin calls save() yet. No user id is ever stored in this option,
 * so the account merge needs nothing for it.
 */
class TeamOversight_Program_Setup {

    const OPTION = 'team_oversight_program_setup';
    /** + md5 of the table prefix: ONE lock for the whole option (lock_name()). */
    const LOCK_PREFIX = 'murvc_prog_setup_';
    /** Club Membership → Logs (the membership channel). */
    const LOG_TYPE = 'program_setup';

    /** How a product's order lines get their date (design 3.2). */
    const KINDS = array('dated', 'legacy_date', 'month', 'paid_date');
    const PLAYER_NAME = array('required', 'off');

    const MIN_YEAR = 2015;
    /** Years an earlier product may list. */
    const MAX_YEARS = 5;
    const MAX_DAYS = 7;
    const MAX_SLOTS = 4;
    const MAX_SLOT_PLACES = 500;
    const MAX_PRICES = 4;
    const MIN_PRICE_CENTS = 100;
    const MAX_PRICE_CENTS = 50000;
    const MAX_MAX_PLACES = 6;
    const MAX_CLOSE_HOURS = 72;
    const MAX_TIERS = 6;
    const TIER_FROM_MIN = 2;
    const TIER_FROM_MAX = 100;
    const TIER_PCT_MIN = 1;
    const TIER_PCT_MAX = 60;
    const MAX_BLOCKS = 12;
    const BLOCK_NAME_MAX = 40;
    const BLOCK_MAX_DAYS = 200;

    /**
     * The fields this version validates and writes. Any other field found in
     * a stored entry is kept as it is by save() (a later version's fields
     * survive a rollback and a save) and left out of get().
     */
    const FIELDS = array(
        'rev', 'prepared_product', 'past_products', 'days', 'slots', 'prices', 'player_name',
        'max_places', 'close_hours', 'tiers', 'blocks', 'booking_page', 'portal_page',
        'bookings_open', 'last_switch',
    );

    /** Request caches: the raw option, and get() per key. Dropped by flush_cache(). */
    private static $all = null;
    private static $entries = array();

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    /** The raw option as an array (anything else reads as an empty array). */
    public static function all() {
        if (self::$all === null) {
            $value = get_option(self::OPTION, array());
            self::$all = is_array($value) ? $value : array();
        }
        return self::$all;
    }

    /** Whether the option holds an entry (an array) for this program. */
    public static function has_entry($key) {
        $all = self::all();
        $key = (string) $key;
        return $key !== '' && isset($all[$key]) && is_array($all[$key]);
    }

    /**
     * Every field's value when nothing is stored. Empty lists mean "not set
     * up yet": valid here, refused only when someone saves that field.
     */
    public static function defaults($key) {
        $program = class_exists('TeamOversight_Programs') ? TeamOversight_Programs::get_program((string) $key) : null;
        $weekday = is_array($program) ? intval(TeamOversight_Programs::get_program_weekday($program)) : 0;
        return array(
            'rev' => 0,
            'prepared_product' => 0,
            'past_products' => array(),
            // Normally the program's own day.
            'days' => ($weekday >= 1 && $weekday <= 7) ? array($weekday) : array(),
            'slots' => array(),
            'prices' => array(),
            'player_name' => 'off',
            'max_places' => 1,
            'close_hours' => 0,
            'tiers' => array(),
            'blocks' => array(),
            'booking_page' => 0,
            'portal_page' => 0,
            'bookings_open' => false,
            'last_switch' => array(),
        );
    }

    /**
     * One program's setup: defaults(), overlaid with the stored entry's
     * structurally clean fields. Never writes, never queries beyond the two
     * option reads, never asks about other programs. Request cached per key.
     */
    public static function get($key) {
        $key = (string) $key;
        if (isset(self::$entries[$key])) {
            return self::$entries[$key];
        }
        $entry = self::defaults($key);
        $all = self::all();
        if ($key !== '' && isset($all[$key]) && is_array($all[$key])) {
            list($clean) = self::validate($key, $all[$key], false);
            foreach ($clean as $field => $value) {
                $entry[$field] = $value;
            }
        }
        self::$entries[$key] = $entry;
        return $entry;
    }

    /** Drop the request caches of all() and get(). */
    public static function flush_cache() {
        self::$all = null;
        self::$entries = array();
    }

    /**
     * The named lock every write of this option takes. Named locks are
     * server-wide, so the name carries the table prefix (49 characters,
     * under MySQL's 64).
     */
    public static function lock_name() {
        global $wpdb;
        return self::LOCK_PREFIX . md5((string) $wpdb->prefix);
    }

    // ------------------------------------------------------------------
    // Validation (design 3.7; 6.5 for earlier products)
    // ------------------------------------------------------------------

    /**
     * Check each given field on its own. Returns array($clean, $errors).
     *
     * $full = false is the structural pass get() uses: the field's own rule
     * only (type, shape, range), never a query. A scalar field that fails is
     * left out of $clean; a list keeps its good entries in order (a later
     * repeat, and a tier row that does not rise above the kept row before it,
     * are bad entries) and keeps at most its limit; unknown fields are left
     * out; nothing is an error.
     *
     * $full = true is save()'s: the own rule plus the lookups that concern
     * that one field. Any failure is an error (nothing is dropped quietly),
     * and so is an unknown field or a posted rev. Checks across fields or
     * programs are merged_problems()'s.
     */
    public static function validate($key, array $fields, $full = true) {
        $key = (string) $key;
        $clean = array();
        $errors = array();
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if (!in_array($field, self::FIELDS, true)) {
                if ($full) {
                    $errors[] = 'There is no setup field called ' . sanitize_key($field) . '.';
                }
                continue;
            }
            if ($field === 'rev') {
                if ($full) {
                    $errors[] = 'The revision is set by the save, never sent with it.';
                    continue;
                }
                $rev = self::whole($value);
                if ($rev !== null && $rev >= 0) {
                    $clean['rev'] = $rev;
                }
                continue;
            }
            list($has, $value_clean, $problems) = self::check_field($key, $field, $value, $full);
            if ($full) {
                if (!empty($problems) || !$has) {
                    $errors = array_merge($errors, !empty($problems) ? $problems : array(self::label($field) . ' is not valid.'));
                    continue;
                }
            }
            if ($has) {
                $clean[$field] = $value_clean;
            }
        }
        return array($clean, array_values(array_unique($errors)));
    }

    /** array($has_value, $clean_value, $problems) for one field. */
    private static function check_field($key, $field, $value, $full) {
        switch ($field) {
            case 'past_products':
                return self::check_past_products($key, $value, $full);
            case 'prepared_product':
                $result = self::check_whole($field, $value, 0, null);
                if ($full && $result[0] && $result[1] > 0 && !self::product_exists($result[1])) {
                    return array(false, null, array('Prepared product: product #' . intval($result[1]) . ' doesn\'t exist.'));
                }
                return $result;
            case 'days':
                return self::check_days($value, $full);
            case 'slots':
                return self::check_slots($key, $value, $full);
            case 'prices':
                return self::check_prices($value, $full);
            case 'player_name':
                if (is_string($value) && in_array($value, self::PLAYER_NAME, true)) {
                    return array(true, $value, array());
                }
                return array(false, null, array('Player name must be required or off.'));
            case 'max_places':
                return self::check_whole($field, $value, 1, self::MAX_MAX_PLACES);
            case 'close_hours':
                return self::check_whole($field, $value, 0, self::MAX_CLOSE_HOURS);
            case 'tiers':
                return self::check_tiers($value, $full);
            case 'blocks':
                return self::check_blocks($value, $full);
            case 'booking_page':
            case 'portal_page':
                $result = self::check_whole($field, $value, 0, null);
                if ($full && $result[0] && $result[1] > 0) {
                    $page = get_post($result[1]);
                    if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish') {
                        return array(false, null, array(self::label($field) . ': choose a published page.'));
                    }
                }
                return $result;
            case 'bookings_open':
                $bool = self::boolish($value);
                return $bool === null
                    ? array(false, null, array('Bookings open must be yes or no.'))
                    : array(true, $bool, array());
            case 'last_switch':
                return self::check_last_switch($value, $full);
        }
        return array(false, null, array('There is no setup field called ' . sanitize_key($field) . '.'));
    }

    private static function label($field) {
        $labels = array(
            'rev' => 'Revision',
            'prepared_product' => 'Prepared product',
            'past_products' => 'Earlier products',
            'days' => 'Days',
            'slots' => 'Time slots',
            'prices' => 'Prices',
            'player_name' => 'Player name',
            'max_places' => 'Places per booking',
            'close_hours' => 'Hours bookings close before the start',
            'tiers' => 'Discount tiers',
            'blocks' => 'Blocks',
            'booking_page' => 'Book page',
            'portal_page' => 'Portal page',
            'bookings_open' => 'Bookings open',
            'last_switch' => 'Last switch',
        );
        return isset($labels[$field]) ? $labels[$field] : $field;
    }

    /** A whole number given as an int or as digits; null for anything else. */
    private static function whole($value) {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,9}$/D', trim($value))) {
            return intval(trim($value));
        }
        return null;
    }

    /** true / false for a bool, 1 / 0 or '1' / '0'; null for anything else. */
    private static function boolish($value) {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        return null;
    }

    private static function check_whole($field, $value, $min, $max) {
        $n = self::whole($value);
        if ($n === null || $n < $min || ($max !== null && $n > $max)) {
            return array(false, null, array(self::label($field) . ' must be a whole number ' . ($max !== null ? 'from ' . $min . ' to ' . $max : 'of ' . $min . ' or more') . '.'));
        }
        return array(true, $n, array());
    }

    /** A real Y-m-d date as array(y, m, d), else null. */
    private static function ymd_parts($value) {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
            return null;
        }
        if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
            return null;
        }
        return array(intval($m[1]), intval($m[2]), intval($m[3]));
    }

    private static function product_exists($product_id) {
        $post = get_post(intval($product_id));
        return $post && $post->post_type === 'product';
    }

    private static function check_past_products($key, $value, $full) {
        if (!is_array($value)) {
            return array(false, null, array('Earlier products must be a list.'));
        }
        $max_year = intval(wp_date('Y')) + 1;
        $allowed = array('product_id', 'kind', 'weekday', 'years');
        $kept = array();
        $problems = array();
        $seen = array();
        foreach (array_values($value) as $i => $entry) {
            $n = $i + 1;
            if (!is_array($entry) || !isset($entry['product_id'], $entry['kind'], $entry['weekday'], $entry['years'])) {
                $problems[] = 'Earlier product ' . $n . ' needs a product, a kind, a weekday and its years.';
                continue;
            }
            $pid = self::whole($entry['product_id']);
            $weekday = self::whole($entry['weekday']);
            $years = self::clean_years($entry['years'], $max_year);
            $name = ($pid !== null && $pid > 0) ? 'Earlier product #' . $pid : 'Earlier product ' . $n;
            if ($full && count(array_diff(array_keys($entry), $allowed)) > 0) {
                $problems[] = $name . ' has fields other than its product, kind, weekday and years.';
                continue;
            }
            if ($pid === null || $pid < 1) {
                $problems[] = $name . ': the product must be a product id.';
                continue;
            }
            if (!is_string($entry['kind']) || !in_array($entry['kind'], self::KINDS, true)) {
                $problems[] = $name . ': the kind must be one of ' . implode(', ', self::KINDS) . '.';
                continue;
            }
            if ($weekday === null || $weekday > 7) {
                $problems[] = $name . ': the weekday must be 0 (none) or 1 (Monday) to 7 (Sunday).';
                continue;
            }
            if ($years === null) {
                $problems[] = $name . ': list 1 to ' . self::MAX_YEARS . ' different years, each from ' . self::MIN_YEAR . ' to ' . $max_year . '.';
                continue;
            }
            if (isset($seen[$pid])) {
                $problems[] = $name . ' is listed twice.';
                continue;
            }
            if ($full) {
                $shape = self::product_shape_problem($pid, $entry['kind'], $key);
                if ($shape !== '') {
                    $problems[] = $shape;
                    continue;
                }
            }
            $seen[$pid] = true;
            $kept[] = array('product_id' => $pid, 'kind' => $entry['kind'], 'weekday' => $weekday, 'years' => $years);
        }
        return array(true, $kept, $problems);
    }

    /** 1 to MAX_YEARS distinct whole years in range, sorted; null when any is wrong. */
    private static function clean_years($years, $max_year) {
        if (!is_array($years) || count($years) < 1 || count($years) > self::MAX_YEARS) {
            return null;
        }
        $out = array();
        foreach ($years as $year) {
            $n = self::whole($year);
            if ($n === null || $n < self::MIN_YEAR || $n > $max_year || in_array($n, $out, true)) {
                return null;
            }
            $out[] = $n;
        }
        sort($out);
        return $out;
    }

    private static function check_days($value, $full) {
        if (!is_array($value)) {
            return array(false, null, array('Days must be a list.'));
        }
        $kept = array();
        $problems = array();
        if ($full && count($value) > self::MAX_DAYS) {
            $problems[] = 'Days: at most ' . self::MAX_DAYS . '.';
        }
        foreach (array_values($value) as $day) {
            $d = self::whole($day);
            if ($d === null || $d < 1 || $d > 7) {
                $problems[] = 'Days: each day must be 1 (Monday) to 7 (Sunday).';
                continue;
            }
            if (in_array($d, $kept, true)) {
                $problems[] = 'Days: a day is listed twice.';
                continue;
            }
            $kept[] = $d;
        }
        if ($full && empty($kept) && empty($problems)) {
            $problems[] = 'Days: choose at least one day.';
        }
        sort($kept);
        return array(true, $kept, array_values(array_unique($problems)));
    }

    private static function check_slots($key, $value, $full) {
        if (!is_array($value)) {
            return array(false, null, array('Time slots must be a list.'));
        }
        $kept = array();
        $problems = array();
        if ($full && count($value) > self::MAX_SLOTS) {
            $problems[] = 'Time slots: at most ' . self::MAX_SLOTS . '.';
        }
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/D';
        foreach (array_values($value) as $i => $slot) {
            if (count($kept) >= self::MAX_SLOTS) {
                break;
            }
            $n = $i + 1;
            if (!is_array($slot) || !isset($slot['start'], $slot['end'], $slot['places'])) {
                $problems[] = 'Time slot ' . $n . ' needs a start, an end and its places.';
                continue;
            }
            $start = is_string($slot['start']) ? $slot['start'] : '';
            $end = is_string($slot['end']) ? $slot['end'] : '';
            $places = self::whole($slot['places']);
            $hidden = isset($slot['hidden']) ? self::boolish($slot['hidden']) : false;
            if (!preg_match($time, $start) || !preg_match($time, $end)) {
                $problems[] = 'Time slot ' . $n . ': the start and end must be times like 17:00.';
                continue;
            }
            if (strcmp($end, $start) <= 0) {
                $problems[] = 'Time slot ' . $start . ': the end must be after the start.';
                continue;
            }
            if ($places === null || $places < 1 || $places > self::MAX_SLOT_PLACES) {
                $problems[] = 'Time slot ' . $start . ': places must be from 1 to ' . self::MAX_SLOT_PLACES . '.';
                continue;
            }
            if ($hidden === null) {
                $problems[] = 'Time slot ' . $start . ': hidden must be yes or no.';
                continue;
            }
            foreach ($kept as $other) {
                if ($other['start'] === $start) {
                    $problems[] = 'Time slot ' . $start . ' is listed twice.';
                    continue 2;
                }
            }
            $kept[] = array('start' => $start, 'end' => $end, 'places' => $places, 'hidden' => $hidden);
        }
        usort($kept, function ($a, $b) {
            return strcmp($a['start'], $b['start']);
        });
        if ($full && empty($kept) && empty($problems)) {
            $problems[] = 'Time slots: add at least one slot.';
        }
        // A slot that sessions already use keeps its start and end: it can only be hidden.
        if ($full && empty($problems)) {
            $new = array();
            foreach ($kept as $slot) {
                $new[$slot['start']] = $slot;
            }
            $stored = self::get($key);
            foreach ($stored['slots'] as $old) {
                if (!self::slot_in_use($key, $old['start'])) {
                    continue;
                }
                if (!isset($new[$old['start']])) {
                    $problems[] = 'Bookings carry the ' . $old['start'] . ' slot, so it can\'t be removed: hide it instead.';
                } elseif ($new[$old['start']]['end'] !== $old['end']) {
                    $problems[] = 'Bookings carry the ' . $old['start'] . ' slot, so its times can\'t change: hide it and add a new slot instead.';
                }
            }
        }
        return array(true, $kept, array_values(array_unique($problems)));
    }

    private static function check_prices($value, $full) {
        if (!is_array($value)) {
            return array(false, null, array('Prices must be a list.'));
        }
        $kept = array();
        $cents_seen = array();
        $problems = array();
        if ($full && count($value) > self::MAX_PRICES) {
            $problems[] = 'Prices: at most ' . self::MAX_PRICES . '.';
        }
        foreach (array_values($value) as $price) {
            if (count($kept) >= self::MAX_PRICES) {
                break;
            }
            $cents = self::money_cents($price);
            if ($cents === null || $cents < self::MIN_PRICE_CENTS || $cents > self::MAX_PRICE_CENTS) {
                $problems[] = 'Prices: each price must be from $' . self::cents_text(self::MIN_PRICE_CENTS) . ' to $' . self::cents_text(self::MAX_PRICE_CENTS) . ', with at most two decimals.';
                continue;
            }
            if (in_array($cents, $cents_seen, true)) {
                $problems[] = 'Prices: a price is listed twice.';
                continue;
            }
            $cents_seen[] = $cents;
            $kept[] = self::cents_text($cents);
        }
        if ($full && empty($kept) && empty($problems)) {
            $problems[] = 'Prices: add at least one price.';
        }
        return array(true, $kept, array_values(array_unique($problems)));
    }

    private static function check_tiers($value, $full) {
        if (!is_array($value)) {
            return array(false, null, array('Discount tiers must be a list.'));
        }
        $kept = array();
        $problems = array();
        if ($full && count($value) > self::MAX_TIERS) {
            $problems[] = 'Discount tiers: at most ' . self::MAX_TIERS . ' rows.';
        }
        foreach (array_values($value) as $i => $row) {
            if (count($kept) >= self::MAX_TIERS) {
                break;
            }
            $n = $i + 1;
            if (!is_array($row) || !isset($row['from'], $row['pct'])) {
                $problems[] = 'Discount tier ' . $n . ' needs a number of sessions and a percent.';
                continue;
            }
            $from = self::whole($row['from']);
            $pct = self::whole($row['pct']);
            if ($from === null || $from < self::TIER_FROM_MIN || $from > self::TIER_FROM_MAX) {
                $problems[] = 'Discount tier ' . $n . ': the number of sessions must be from ' . self::TIER_FROM_MIN . ' to ' . self::TIER_FROM_MAX . '.';
                continue;
            }
            if ($pct === null || $pct < self::TIER_PCT_MIN || $pct > self::TIER_PCT_MAX) {
                $problems[] = 'Discount tier ' . $n . ': the percent off must be from ' . self::TIER_PCT_MIN . ' to ' . self::TIER_PCT_MAX . '.';
                continue;
            }
            $last = empty($kept) ? null : $kept[count($kept) - 1];
            if ($last !== null && ($from <= $last['from'] || $pct <= $last['pct'])) {
                $problems[] = 'Discount tiers: both the number of sessions and the percent off must rise from row to row.';
                continue;
            }
            $kept[] = array('from' => $from, 'pct' => $pct);
        }
        return array(true, $kept, array_values(array_unique($problems)));
    }

    private static function check_blocks($value, $full) {
        if (!is_array($value)) {
            return array(false, null, array('Blocks must be a list.'));
        }
        $kept = array();
        $problems = array();
        if ($full && count($value) > self::MAX_BLOCKS) {
            $problems[] = 'Blocks: at most ' . self::MAX_BLOCKS . '.';
        }
        foreach (array_values($value) as $i => $block) {
            if (count($kept) >= self::MAX_BLOCKS) {
                break;
            }
            $n = $i + 1;
            if (!is_array($block) || !isset($block['name'], $block['from'], $block['to']) || !is_string($block['name'])) {
                $problems[] = 'Block ' . $n . ' needs a name, a first date and a last date.';
                continue;
            }
            $name = sanitize_text_field($block['name']);
            $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($length < 1 || $length > self::BLOCK_NAME_MAX) {
                $problems[] = 'Block ' . $n . ': the name must be 1 to ' . self::BLOCK_NAME_MAX . ' characters.';
                continue;
            }
            $from = self::ymd_parts($block['from']);
            $to = self::ymd_parts($block['to']);
            if ($from === null || $to === null) {
                $problems[] = 'Block ' . $n . ': the dates must be real dates (YYYY-MM-DD).';
                continue;
            }
            $days = (gmmktime(0, 0, 0, $to[1], $to[2], $to[0]) - gmmktime(0, 0, 0, $from[1], $from[2], $from[0])) / 86400;
            if ($days <= 0) {
                $problems[] = 'Block ' . $n . ': the first date must be before the last.';
                continue;
            }
            if ($days > self::BLOCK_MAX_DAYS) {
                $problems[] = 'Block ' . $n . ': at most ' . self::BLOCK_MAX_DAYS . ' days from the first date to the last.';
                continue;
            }
            $kept[] = array('name' => $name, 'from' => $block['from'], 'to' => $block['to']);
        }
        return array(true, $kept, array_values(array_unique($problems)));
    }

    private static function check_last_switch($value, $full) {
        $bad = array(false, null, array('Last switch must be empty, or the two products, the old address and when.'));
        if (!is_array($value)) {
            return $bad;
        }
        if (empty($value)) {
            return array(true, array(), array());
        }
        if (!isset($value['from'], $value['to'], $value['old_slug'], $value['at'])) {
            return $bad;
        }
        if ($full && count(array_diff(array_keys($value), array('from', 'to', 'old_slug', 'at'))) > 0) {
            return $bad;
        }
        $from = self::whole($value['from']);
        $to = self::whole($value['to']);
        $slug = is_string($value['old_slug']) ? $value['old_slug'] : '';
        $at = is_string($value['at']) ? $value['at'] : '';
        if ($from === null || $from < 1 || $to === null || $to < 1 || $slug === '' || sanitize_title($slug) !== $slug
            || !preg_match('/^(\d{4}-\d{2}-\d{2}) ([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $at, $m) || self::ymd_parts($m[1]) === null) {
            return $bad;
        }
        return array(true, array('from' => $from, 'to' => $to, 'old_slug' => $slug, 'at' => $at), array());
    }

    /**
     * The checks across fields or programs, on the entry save() is about to
     * write ($merged: the stored entry with the posted clean fields over it;
     * $changed: the posted field names). save() is the only caller. Returns
     * a list of sentences.
     */
    public static function merged_problems($key, array $merged, array $changed) {
        $key = (string) $key;
        $problems = array();
        list($clean) = self::validate($key, $merged, false);
        $m = array_merge(self::defaults($key), $clean);

        if (in_array('past_products', $changed, true) || in_array('prepared_product', $changed, true)) {
            $program = TeamOversight_Programs::get_program($key);
            $current = is_array($program) && isset($program['product_id']) && is_scalar($program['product_id']) ? intval($program['product_id']) : 0;
            $prepared = intval($m['prepared_product']);
            if ($prepared > 0 && $prepared === $current) {
                $problems[] = 'Product #' . $prepared . ' is the program\'s current product, so it can\'t also be its prepared product.';
            }
            $ids = $prepared > 0 ? array($prepared) : array();
            foreach ($m['past_products'] as $past) {
                $pid = intval($past['product_id']);
                if ($pid === $current) {
                    $problems[] = 'Product #' . $pid . ' is the program\'s current product, so it can\'t also be an earlier product.';
                } elseif ($prepared > 0 && $pid === $prepared) {
                    $problems[] = 'Product #' . $pid . ' is the program\'s prepared product, so it can\'t also be an earlier product.';
                }
                $ids[] = $pid;
            }
            foreach (array_unique($ids) as $pid) {
                $owner = self::owner_of_product($pid, $key);
                if ($owner !== '') {
                    $other = TeamOversight_Programs::get_program($owner);
                    $other_name = is_array($other) && isset($other['name']) && is_scalar($other['name']) ? (string) $other['name'] : $owner;
                    $problems[] = 'Product #' . intval($pid) . ' already belongs to ' . $other_name . '.';
                }
            }
        }

        if (in_array('tiers', $changed, true) || in_array('prices', $changed, true)) {
            $dip = self::tier_dip($m['tiers'], $m['prices']);
            if ($dip !== null) {
                $problems[] = $dip['message'];
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Design 6.5: whether a product may join a program's history as this
     * kind ('' = yes, else one sentence). Attributes are read from the
     * product's _product_attributes post meta; paid lines from the order
     * item tables and wc_order_stats.
     */
    public static function product_shape_problem($product_id, $kind, $program_key = '') {
        global $wpdb;
        $pid = intval($product_id);
        if ($pid <= 0 || !self::product_exists($pid)) {
            return 'Product #' . $pid . ' doesn\'t exist.';
        }
        $attributes = get_post_meta($pid, '_product_attributes', true);
        $attributes = is_array($attributes) ? $attributes : array();
        $variable = has_term('variable', 'product_type', $pid);
        switch ($kind) {
            case 'legacy_date':
                if (!$variable) {
                    return 'Product #' . $pid . ' isn\'t a product with variations, so it can\'t be read as dated sessions.';
                }
                if (!isset($attributes['date']) && !isset($attributes['pa_date'])) {
                    return 'Product #' . $pid . ' has no date attribute, so it can\'t be read as dated sessions.';
                }
                return '';
            case 'month':
                if (!$variable) {
                    return 'Product #' . $pid . ' isn\'t a product with variations, so it can\'t be read as months.';
                }
                if (!isset($attributes['month']) && !isset($attributes['pa_month'])) {
                    return 'Product #' . $pid . ' has no month attribute, so it can\'t be read as months.';
                }
                return '';
            case 'paid_date':
                $paid = $wpdb->get_var($wpdb->prepare("
                    SELECT EXISTS (
                        SELECT 1
                        FROM {$wpdb->prefix}woocommerce_order_items oi
                        JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod
                            ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
                        JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = oi.order_id
                        WHERE oi.order_item_type = 'line_item' AND os.status IN ('wc-processing', 'wc-completed')
                            AND os.parent_id = 0
                    )
                ", $pid));
                return intval($paid) === 1 ? '' : 'Product #' . $pid . ' has no paid order lines.';
            case 'dated':
                if ((string) get_post_meta($pid, '_murvc_session_product', true) !== '1'
                    || (string) $program_key === ''
                    || (string) get_post_meta($pid, '_murvc_program_key', true) !== (string) $program_key) {
                    return 'Product #' . $pid . ' isn\'t a session product made for this program.';
                }
                return '';
        }
        return 'Product #' . $pid . ': the kind must be one of ' . implode(', ', self::KINDS) . '.';
    }

    /**
     * The first program key other than $except_key that holds this product
     * as its current product (team_oversight_programs) or as its prepared or
     * an earlier product (the raw option, read defensively), else ''. Never
     * get(), so no check can recurse.
     */
    public static function owner_of_product($product_id, $except_key = '') {
        $pid = intval($product_id);
        $except_key = (string) $except_key;
        if ($pid <= 0) {
            return '';
        }
        foreach (TeamOversight_Programs::get_programs() as $key => $program) {
            if ((string) $key === $except_key || !is_array($program)) {
                continue;
            }
            if (isset($program['product_id']) && is_scalar($program['product_id']) && intval($program['product_id']) === $pid) {
                return (string) $key;
            }
        }
        foreach (self::all() as $key => $entry) {
            if ((string) $key === $except_key || !is_array($entry)) {
                continue;
            }
            if (isset($entry['prepared_product']) && is_scalar($entry['prepared_product']) && intval($entry['prepared_product']) === $pid) {
                return (string) $key;
            }
            if (isset($entry['past_products']) && is_array($entry['past_products'])) {
                foreach ($entry['past_products'] as $past) {
                    if (is_array($past) && isset($past['product_id']) && is_scalar($past['product_id']) && intval($past['product_id']) === $pid) {
                        return (string) $key;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Whether a session (a variation, any status) of the program's current,
     * prepared or dated earlier products carries this slot start. One query.
     * True for nothing until 1.85.0 makes sessions.
     */
    public static function slot_in_use($key, $start) {
        global $wpdb;
        $key = (string) $key;
        $setup = self::get($key);
        $ids = array();
        $program = TeamOversight_Programs::get_program($key);
        if (is_array($program) && isset($program['product_id']) && is_scalar($program['product_id'])) {
            $ids[] = intval($program['product_id']);
        }
        $ids[] = intval($setup['prepared_product']);
        foreach ($setup['past_products'] as $past) {
            if ($past['kind'] === 'dated') {
                $ids[] = intval($past['product_id']);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids) || !is_string($start) || $start === '') {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $args = array_merge(array($start), $ids);
        $found = $wpdb->get_var($wpdb->prepare("
            SELECT 1
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_murvc_session_slot' AND m.meta_value = %s
            WHERE p.post_type = 'product_variation' AND p.post_parent IN ({$placeholders})
            LIMIT 1
        ", $args));
        return intval($found) === 1;
    }

    // ------------------------------------------------------------------
    // Tier maths (design 4.7): pure, so the booking code, the Setup tab's
    // price check and the tests all call the same rule.
    // ------------------------------------------------------------------

    /** 4.7.1: the percent of the highest tier whose `from` is at most $count, else 0. */
    public static function tier_pct(array $tiers, $count) {
        $count = intval($count);
        $best_from = null;
        $pct = 0;
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !isset($tier['from'], $tier['pct'])) {
                continue;
            }
            $from = intval($tier['from']);
            if ($from <= $count && ($best_from === null || $from > $best_from)) {
                $best_from = $from;
                $pct = intval($tier['pct']);
            }
        }
        return $pct;
    }

    /** 4.7.2: a price less a percent, in whole cents, half up (1650 at 5% is 1568). */
    public static function discounted_cents($full_cents, $pct) {
        return intdiv(intval($full_cents) * (100 - intval($pct)) + 50, 100);
    }

    /**
     * 4.7.3: no price dips. At every tier boundary B (previous percent p_prev,
     * 0 below the first tier) and every approved price, B sessions must cost
     * at least as much as B - 1. Returns null when the set passes (or there
     * are no tiers or prices), else the first failure: boundary, the largest
     * whole percent at most the tier's that passes at every price, and the
     * sentence to show.
     */
    public static function tier_dip(array $tiers, array $prices) {
        $cents = array();
        foreach ($prices as $price) {
            $c = self::money_cents($price);
            if ($c !== null && $c > 0) {
                $cents[] = $c;
            }
        }
        $rows = array();
        foreach ($tiers as $tier) {
            if (is_array($tier) && isset($tier['from'], $tier['pct'])) {
                $rows[] = array(intval($tier['from']), intval($tier['pct']));
            }
        }
        if (empty($rows) || empty($cents)) {
            return null;
        }
        usort($rows, function ($a, $b) {
            return $a[0] - $b[0];
        });
        $previous = 0;
        foreach ($rows as $row) {
            list($boundary, $pct) = $row;
            if ($boundary >= 2 && !self::passes_at($boundary, $pct, $previous, $cents)) {
                $max = $previous;
                for ($p = $pct - 1; $p > $previous; $p--) {
                    if (self::passes_at($boundary, $p, $previous, $cents)) {
                        $max = $p;
                        break;
                    }
                }
                return array(
                    'boundary' => $boundary,
                    'max_pct' => $max,
                    'message' => 'At ' . $boundary . ' sessions the most you can take off is ' . $max . '%, or ' . $boundary . ' would cost less than ' . ($boundary - 1) . '.',
                );
            }
            $previous = $pct;
        }
        return null;
    }

    private static function passes_at($boundary, $pct, $previous, array $cents) {
        foreach ($cents as $c) {
            if ($boundary * self::discounted_cents($c, $pct) < ($boundary - 1) * self::discounted_cents($c, $previous)) {
                return false;
            }
        }
        return true;
    }

    /** The one reading of a price: '30', '30.5' or '30.00' (or an int) as cents; null for anything else. */
    public static function money_cents($text) {
        if (is_int($text)) {
            $text = (string) $text;
        }
        if (!is_string($text) || !preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', trim($text), $m)) {
            return null;
        }
        $fraction = isset($m[2]) ? str_pad($m[2], 2, '0') : '00';
        return intval($m[1]) * 100 + intval($fraction);
    }

    /** Cents as a price string with two decimals ('30.00'). */
    public static function cents_text($cents) {
        $cents = intval($cents);
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // The writer
    // ------------------------------------------------------------------

    /**
     * Save some fields of one program's setup. $expected_rev is the rev the
     * screen was drawn with: a save drawn from another rev is refused.
     *
     * Runs under ONE named lock for the whole option (lock_name()), held
     * around read, merge and write: every program lives in one option row, so
     * per-program locks would let two saves of different programs both read
     * the row and the later write drop the earlier one. (This differs from
     * the design's per-program lock on purpose; 1.85.0's Prepare, Discard,
     * Switch, Switch back and Add an earlier product take this same lock and
     * go through the same steps.) A database that can't do named locks (NULL)
     * runs unlocked, as everywhere else in the plugin. Inside the lock the
     * stored row is read and confirmed straight from the database
     * (stored_row()), so a stale cached copy or "no such option" flag can't
     * drop another program's entry. Stored fields this version doesn't know
     * are kept. Writes one program_setup log line.
     *
     * Returns array('ok' => bool, 'errors' => array of sentences, 'rev' => int).
     */
    public static function save($key, array $fields, $expected_rev) {
        global $wpdb;
        $key = (string) $key;
        $program = $key !== '' ? TeamOversight_Programs::get_program($key) : null;
        if (!is_array($program)) {
            return self::refused(array('That program isn\'t configured, so nothing was saved.'));
        }
        $expected = self::whole($expected_rev);
        if ($expected === null || $expected < 0) {
            return self::refused(array('The setup\'s revision is missing, so nothing was saved. Reload and try again.'));
        }

        $lock = self::lock_name();
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return self::refused(array('Someone else is saving a program\'s setup. Try again in a minute.'));
        }
        try {
            $result = self::save_locked($key, $program, $fields, $expected);
        } finally {
            if ($got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            self::flush_cache();
            TeamOversight_Programs::flush_program_caches();
        }
        return $result;
    }

    private static function refused(array $errors, $rev = 0) {
        return array('ok' => false, 'errors' => $errors, 'rev' => intval($rev));
    }

    /**
     * The option as stored, read straight from the database, for save()
     * inside the lock. get_option() would trust the object cache, and the
     * site's is persistent: a request that loaded the "no such option" flag
     * (notoptions) before another request created the option would read
     * nothing and its write would drop that request's entry. get_option()'s
     * own pre_option filters still apply, so a stand-in works here as it does
     * for all(). A stale flag for a row that exists is dropped, as
     * add_option() does once a row exists. Null when the read failed, so a
     * save never writes over what it could not read.
     */
    private static function stored_row() {
        global $wpdb;
        $pre = apply_filters('pre_option_' . self::OPTION, false, self::OPTION, array());
        $pre = apply_filters('pre_option', $pre, self::OPTION, array());
        if ($pre !== false) {
            return is_array($pre) ? $pre : array();
        }
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION));
        if ($wpdb->last_error !== '') {
            return null;
        }
        if ($raw === null) {
            return array();
        }
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[self::OPTION])) {
            unset($notoptions[self::OPTION]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
        $value = apply_filters('option_' . self::OPTION, maybe_unserialize($raw), self::OPTION);
        return is_array($value) ? $value : array();
    }

    /** save()'s steps a to f, inside the lock. */
    private static function save_locked($key, array $program, array $fields, $expected) {
        // a. A fresh read of the stored row (stored_row(), never the cache),
        // which every check below reads too.
        wp_cache_delete(self::OPTION, 'options');
        self::flush_cache();
        $all = self::stored_row();
        if ($all === null) {
            return self::refused(array('The setup couldn\'t be read, so nothing was saved. Reload and try again.'));
        }
        self::$all = $all;
        $stored = (isset($all[$key]) && is_array($all[$key])) ? $all[$key] : null;
        $stored_rev = 0;
        if ($stored !== null && isset($stored['rev'])) {
            $rev = self::whole($stored['rev']);
            $stored_rev = ($rev !== null && $rev >= 0) ? $rev : 0;
        }

        // b. A save drawn from another rev is refused.
        if ($stored_rev !== $expected) {
            return self::refused(array('Someone else saved this program since you opened it. Reload and try again.'), $stored_rev);
        }

        // c. Each field on its own.
        list($clean, $errors) = self::validate($key, $fields, true);
        if (!empty($errors)) {
            return self::refused($errors, $stored_rev);
        }
        if (empty($clean)) {
            return self::refused(array('Nothing to save.'), $stored_rev);
        }

        // d. The merged entry: unknown stored fields are kept as they are.
        $base = $stored !== null ? $stored : self::defaults($key);
        list($old_clean) = self::validate($key, $base, false);
        $old = array_merge(self::defaults($key), $old_clean);
        $merged = $base;
        foreach ($clean as $field => $value) {
            $merged[$field] = $value;
        }
        $errors = self::merged_problems($key, $merged, array_keys($clean));
        if (!empty($errors)) {
            return self::refused($errors, $stored_rev);
        }

        // e. Write, then confirm by reading the stored row back (update_option()
        // also returns false for "unchanged", so its answer proves nothing).
        $new_rev = $stored_rev + 1;
        $merged['rev'] = $new_rev;
        $before = $all;
        $all[$key] = $merged;
        update_option(self::OPTION, $all, false);
        wp_cache_delete(self::OPTION, 'options');
        self::flush_cache();
        $after = self::stored_row();
        $ok = is_array($after) && isset($after[$key]) && is_array($after[$key]) && isset($after[$key]['rev']) && self::whole($after[$key]['rev']) === $new_rev;
        if ($ok) {
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $other) {
                if ((string) $other === $key) {
                    continue;
                }
                $was = isset($before[$other]) ? serialize($before[$other]) : null;
                $now = isset($after[$other]) ? serialize($after[$other]) : null;
                if ($was !== $now) {
                    $ok = false;
                    break;
                }
            }
        }
        if (!$ok) {
            return self::refused(array('The setup couldn\'t be saved. Reload and try again.'), $stored_rev);
        }

        // f. One log line: the program's name and each changed field, old -> new.
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::LOG_TYPE, self::log_message($key, $program, $old, $clean));
        }
        return array('ok' => true, 'errors' => array(), 'rev' => $new_rev);
    }

    /** "Name: setup saved: Field old -> new; ..." (lists shortened to counts past 500 characters). */
    private static function log_message($key, array $program, array $old, array $clean) {
        $name = (isset($program['name']) && is_scalar($program['name']) && (string) $program['name'] !== '') ? (string) $program['name'] : $key;
        $full = array();
        $short = array();
        foreach ($clean as $field => $value) {
            $was = array_key_exists($field, $old) ? $old[$field] : null;
            if ($was === $value) {
                continue;
            }
            $line = self::label($field) . ' ' . self::describe($field, $was) . ' -> ' . self::describe($field, $value);
            $full[] = $line;
            $short[] = is_array($value)
                ? self::label($field) . ' ' . (is_array($was) ? count($was) : 0) . ' -> ' . count($value) . ' entries'
                : $line;
        }
        if (empty($full)) {
            return $name . ': setup saved, nothing changed.';
        }
        $message = $name . ': setup saved: ' . implode('; ', $full) . '.';
        if (strlen($message) > 500) {
            $message = $name . ': setup saved: ' . implode('; ', $short) . '.';
        }
        return $message;
    }

    /** A field's value in words for the log (structurally clean values). */
    private static function describe($field, $value) {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (!is_array($value)) {
            if (in_array($field, array('prepared_product', 'booking_page', 'portal_page'), true)) {
                return intval($value) > 0 ? '#' . intval($value) : 'none';
            }
            return is_scalar($value) ? (string) $value : 'none';
        }
        if (empty($value)) {
            return 'none';
        }
        $parts = array();
        switch ($field) {
            case 'past_products':
                foreach ($value as $past) {
                    $parts[] = '#' . intval($past['product_id']) . ' ' . $past['kind'] . ' ' . implode('/', $past['years']);
                }
                break;
            case 'days':
                $names = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun');
                foreach ($value as $day) {
                    $parts[] = isset($names[$day]) ? $names[$day] : (string) $day;
                }
                break;
            case 'slots':
                foreach ($value as $slot) {
                    $parts[] = $slot['start'] . '-' . $slot['end'] . ' ' . intval($slot['places']) . ' places' . (!empty($slot['hidden']) ? ' (hidden)' : '');
                }
                break;
            case 'prices':
                foreach ($value as $price) {
                    $parts[] = '$' . $price;
                }
                break;
            case 'tiers':
                foreach ($value as $tier) {
                    $parts[] = intval($tier['from']) . ':' . intval($tier['pct']) . '%';
                }
                break;
            case 'blocks':
                foreach ($value as $block) {
                    $parts[] = $block['name'] . ' ' . $block['from'] . ' to ' . $block['to'];
                }
                break;
            case 'last_switch':
                return '#' . intval($value['from']) . ' to #' . intval($value['to']) . ' at ' . $value['at'];
            default:
                return count($value) . ' entries';
        }
        return implode(', ', $parts);
    }
}
