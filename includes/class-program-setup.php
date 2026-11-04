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
 *   shape, a published page; 1.86.0 dropped the used-slot lookup);
 * - merged_problems(), the only place for checks across fields or programs,
 *   called only by save();
 * - the one writer, save(): ONE option-wide named lock (lock_name()), the
 *   per-program rev compare-and-refuse, the stored row read and confirmed
 *   straight from the database (stored_row(), never a cached copy), stored
 *   fields this version doesn't know kept, one program_setup log line;
 * - the pure tier maths (tier_pct(), discounted_cents(), tier_dip()) that the
 *   booking code will call, so the rule lives in one place.
 *
 * 1.85.0 adds the Setup tab (render_tab(): Booking, Days and slots, Prices (both gone in 1.86.0),
 * Discount tiers with the price check, Blocks, Products; every box posts to
 * admin-post, handle_admin_post() / process_post(), Post/Redirect/Get) and
 * the product actions: prepare(), discard(), switch_over(), switch_back(),
 * add_past_product(), remove_past_product(), apply_sold_individually().
 * Every one runs inside locked() (the option-wide lock), reads the stored
 * row fresh and writes through save_locked(); one that also writes
 * sessions or products takes the products' session locks second
 * (TeamOversight_Program_Sessions::lock_name()), never first. Switch writes
 * team_oversight_programs first, then this option, and undoes every step
 * in reverse when a later one fails.
 *
 * 1.86.0 (the club's decisions of 4 Nov 2026): a booking cut-off chosen per
 * program (close_at: before / start / end / day, default day; close_hours
 * is the N of before); price dips are allowed (merged_problems() never
 * refuses one; save() returns dip_notes() for the committee); the Setup
 * tab opens with a six-step guide (steps()) and holds only program-wide
 * rules: Booking, Standard price (prices[0]), Discounts, Blocks, Products.
 * Days, times, places and a session's own price are given when sessions
 * are added (TeamOversight_Program_Sessions). The stored days, slots and
 * prices are kept and still validated, so a rollback finds them, but
 * nothing reads days or slots any more.
 *
 * 1.86.1 (the club's decision of 4 Nov 2026: a booking is always for the
 * account holder): the Setup tab no longer shows Who is coming. The stored
 * player_name field is kept and still validated, so a rollback finds it,
 * but get() reads every program as player name off whatever is stored, and
 * a Booking box post never writes it.
 *
 * No user id is ever stored in this option, so the account merge needs
 * nothing for it.
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
    /** 1.86.0 (club 2): when a session stops taking bookings. */
    const CLOSE_AT = array('before', 'start', 'end', 'day');
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
        'max_places', 'close_hours', 'close_at', 'tiers', 'blocks', 'booking_page', 'portal_page',
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
            // 1.86.0 (club 2, C4): a stored setup with no close_at reads as the end of the day.
            'close_at' => 'day',
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
     * 1.86.1: player_name always reads off (a booking is always for the
     * account holder); a stored required, which 1.86.0 could save and the
     * live Academy holds, is kept in the option for a rollback and ignored.
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
        $entry['player_name'] = 'off';
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
            case 'close_at':
                if (is_string($value) && in_array($value, self::CLOSE_AT, true)) {
                    return array(true, $value, array());
                }
                return array(false, null, array('Booking cut-off must be one of: hours before the start, at the start, at the end of the session, at the end of the day.'));
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
            'close_hours' => 'Hours before the start (for the first cut-off choice)',
            'close_at' => 'Booking cut-off',
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
            if ($weekday === null || $weekday < 0 || $weekday > 7) {
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
        // 1.86.0 (club 4): the used-slot rule went with the Days and slots box; each session keeps its own times.
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
            return array(false, null, array('Discounts must be a list.'));
        }
        $kept = array();
        $problems = array();
        if ($full && count($value) > self::MAX_TIERS) {
            $problems[] = 'Discounts: at most ' . self::MAX_TIERS . ' rows.';
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
                $problems[] = 'Discounts: both the number of sessions and the percent off must rise from row to row.';
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
        // 1.85.0 (B14): what Switch back restores, recorded by switch_over().
        $extra = array('old_status', 'old_visibility', 'retired_slug', 'new_slug');
        if ($full && count(array_diff(array_keys($value), array_merge(array('from', 'to', 'old_slug', 'at'), $extra))) > 0) {
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
        $clean = array('from' => $from, 'to' => $to, 'old_slug' => $slug, 'at' => $at);
        if (isset($value['old_status'])) {
            if (!is_string($value['old_status']) || !in_array($value['old_status'], array('publish', 'private', 'pending', 'draft'), true)) {
                return $bad;
            }
            $clean['old_status'] = $value['old_status'];
        }
        if (isset($value['old_visibility'])) {
            if (!is_string($value['old_visibility']) || !in_array($value['old_visibility'], array('visible', 'catalog', 'search', 'hidden'), true)) {
                return $bad;
            }
            $clean['old_visibility'] = $value['old_visibility'];
        }
        foreach (array('retired_slug', 'new_slug') as $field) {
            if (isset($value[$field])) {
                if (!is_string($value[$field]) || sanitize_title($value[$field]) !== $value[$field] || ($field === 'retired_slug' && $value[$field] === '')) {
                    return $bad;
                }
                $clean[$field] = $value[$field];
            }
        }
        return array(true, $clean, array());
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
        // 1.86.0 (club 1, C2): a price dip is allowed; save() returns it as a note (dip_notes()).
        return array_values(array_unique($problems));
    }

    /**
     * Club 1: one sentence for the committee per price dip (more sessions
     * costing less in total) at the standard price, from 1 to 100
     * sessions. Empty without tiers or a price. A dip is allowed: the Book
     * page and the cart tell families just below it, and a withdrawal
     * never refunds money because of it (4.9.3).
     */
    public static function dip_notes(array $tiers, $price) {
        $notes = array();
        $cents = self::money_cents(is_scalar($price) ? (string) $price : '');
        if (empty($tiers) || $cents === null) {
            return $notes;
        }
        $money = function ($c) {
            return '$' . number_format(intval($c) / 100, 2);
        };
        $previous = null;
        foreach (self::price_check_rows($tiers, self::cents_text($cents), 100) as $row) {
            if ($row['dip'] && $previous !== null) {
                $n = intval($row['n']);
                $notes[] = 'At ' . $money($cents) . ', ' . $n . ' sessions cost less than ' . ($n - 1) . ' (' . $money($row['total_cents']) . ' against ' . $money($previous['total_cents'])
                    . '): families booking ' . ($n - 1) . ' will usually add one to reach the cheaper total.';
            }
            $previous = $row;
        }
        return $notes;
    }

    /** The session counts where the standard price dips (for the log line). */
    private static function dip_points(array $tiers, $price) {
        $points = array();
        $cents = self::money_cents(is_scalar($price) ? (string) $price : '');
        if (empty($tiers) || $cents === null) {
            return $points;
        }
        foreach (self::price_check_rows($tiers, self::cents_text($cents), 100) as $row) {
            if ($row['dip']) {
                $points[] = intval($row['n']);
            }
        }
        return $points;
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
     * get(), so no check can recurse. $include_current false (1.85.0, the
     * Settings screen's check) counts only prepared and earlier products.
     */
    public static function owner_of_product($product_id, $except_key = '', $include_current = true) {
        $pid = intval($product_id);
        $except_key = (string) $except_key;
        if ($pid <= 0) {
            return '';
        }
        foreach ($include_current ? TeamOversight_Programs::get_programs() : array() as $key => $program) {
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
     * 4.7.3's dip test, kept pure (1.86.0, club 1: a dip is allowed, so
     * nothing refuses on this any more; dip_notes() words it for the
     * committee). At every tier boundary B (previous percent p_prev, 0
     * below the first tier) and every given price, B sessions cost at least
     * as much as B - 1. Returns null when the set has no dip (or there are
     * no tiers or prices), else the first: boundary, the largest whole
     * percent at most the tier's with no dip at every price, and a sentence.
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
     * Returns array('ok' => bool, 'errors' => array of sentences, 'rev' => int);
     * 1.86.0: a successful save that touched tiers or prices also returns
     * 'notes' (dip_notes(): a dip is saved, and noted for the committee).
     * 1.85.0: $message, when given, is the log line instead of the
     * generated one (the earlier-product actions write their own).
     */
    public static function save($key, array $fields, $expected_rev, $message = null) {
        $key = (string) $key;
        $program = $key !== '' ? TeamOversight_Programs::get_program($key) : null;
        if (!is_array($program)) {
            return self::refused(array('That program isn\'t configured, so nothing was saved.'));
        }
        $expected = self::whole($expected_rev);
        if ($expected === null || $expected < 0) {
            return self::refused(array('The setup\'s revision is missing, so nothing was saved. Reload and try again.'));
        }
        return self::locked(function () use ($key, $program, $fields, $expected, $message) {
            return self::save_locked($key, $program, $fields, $expected, $message);
        });
    }

    /**
     * Run $fn under the option-wide lock (1.85.0, the refactor of save()'s
     * own steps): 0 refuses; NULL (no named locks) runs unlocked, the
     * plugin's convention for this lock; every request cache that could
     * hold the old rows is dropped afterwards.
     */
    private static function locked($fn) {
        global $wpdb;
        $lock = self::lock_name();
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return self::refused(array('Someone else is saving a program\'s setup. Try again in a minute.'));
        }
        try {
            return call_user_func($fn);
        } finally {
            if ($got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            self::flush_cache();
            TeamOversight_Programs::flush_program_caches();
            if (class_exists('TeamOversight_Program_Sessions')) {
                TeamOversight_Program_Sessions::flush_cache();
            }
        }
    }

    private static function refused(array $errors, $rev = 0) {
        return array('ok' => false, 'errors' => $errors, 'rev' => intval($rev));
    }

    /**
     * An option as stored, read straight from the database (1.85.0: any
     * option; 1.84.0's stored_row() for this one). get_option() would trust
     * the object cache, and the site's is persistent: a request that loaded
     * the "no such option" flag (notoptions) before another request created
     * the option would read nothing and its write would drop that request's
     * entry, and an autoloaded option's copy in alloptions can be older than
     * the row. get_option()'s own pre_option filters still apply, so a
     * stand-in works here as it does for get_option(). A stale flag for a
     * row that exists is dropped, as add_option() does once a row exists.
     * An array (array() for none or for a non-array value), or null when the
     * read failed, so no caller writes over what it could not read.
     */
    public static function read_option_fresh($option) {
        global $wpdb;
        $option = (string) $option;
        $pre = apply_filters('pre_option_' . $option, false, $option, array());
        $pre = apply_filters('pre_option', $pre, $option, array());
        if ($pre !== false) {
            return is_array($pre) ? $pre : array();
        }
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option));
        if ($wpdb->last_error !== '') {
            return null;
        }
        if ($raw === null) {
            return array();
        }
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$option])) {
            unset($notoptions[$option]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
        $value = apply_filters('option_' . $option, maybe_unserialize($raw), $option);
        return is_array($value) ? $value : array();
    }

    /** This option as stored (read_option_fresh()), for the writers inside the lock. */
    private static function stored_row() {
        return self::read_option_fresh(self::OPTION);
    }

    /** save()'s steps a to f, inside the lock. */
    private static function save_locked($key, array $program, array $fields, $expected, $message = null) {
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

        // 1.86.0 (club 1, C2): a dip is saved, and noted for the committee.
        $notes = array();
        $dips = array();
        if (array_key_exists('tiers', $clean) || array_key_exists('prices', $clean)) {
            list($merged_clean) = self::validate($key, $merged, false);
            $now = array_merge(self::defaults($key), $merged_clean);
            $standard = !empty($now['prices']) ? $now['prices'][0] : '';
            $notes = self::dip_notes($now['tiers'], $standard);
            $dips = self::dip_points($now['tiers'], $standard);
        }

        // f. One log line: the program's name and each changed field, old -> new
        // (or the product action's own line, 1.85.0).
        if (class_exists('TeamOversight_Log')) {
            $line = (is_string($message) && $message !== '') ? $message : self::log_message($key, $program, $old, $clean);
            if (!empty($dips)) {
                $line .= ' Price dips noted at ' . implode(', ', $dips) . '.';
            }
            TeamOversight_Log::add(self::LOG_TYPE, $line);
        }
        return array('ok' => true, 'errors' => array(), 'rev' => $new_rev, 'notes' => $notes);
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
            if ($field === 'close_at') {
                return self::close_at_words((string) $value);
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

    /** 1.86.0: a booking cut-off choice in words ("at the end of the day"). */
    public static function close_at_words($value) {
        $words = array(
            'before' => 'hours before the start',
            'start' => 'at the start',
            'end' => 'at the end of the session',
            'day' => 'at the end of the day',
        );
        return isset($words[$value]) ? $words[$value] : 'at the end of the day';
    }

    // ------------------------------------------------------------------
    // 1.85.0: the Setup tab (design 6.1, 6.2) and its posts
    // ------------------------------------------------------------------

    /**
     * The boxes that save fields (1.86.0, club 4: Booking, Standard price,
     * Discounts, Blocks; days, times and places are given when sessions
     * are added), and the product actions (design 6.4, 6.5).
     */
    const BOXES = array('booking', 'price', 'tiers', 'blocks');
    /** A post from a box that left the tab in 1.86.0 (a page opened before it) is refused, C9. */
    const OLD_BOXES = array('slots', 'prices');
    const OLD_BOX_REFUSAL = 'Days and times are set on the Sessions tab now, and the standard price in the Standard price box. Nothing was saved.';
    const DO_ACTIONS = array('prepare', 'discard', 'switch', 'switch_back', 'add_past', 'remove_past');
    const FLASH_PREFIX = 'murvc_program_setup_flash_';
    const TITLE_MAX = 100;

    private static $registered = false;

    public static function register_hooks() {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_action('admin_post_murvc_program_setup', array(__CLASS__, 'handle_admin_post'));
    }

    private static function program_name($key, $program = null) {
        if (!is_array($program)) {
            $program = TeamOversight_Programs::get_program($key);
        }
        return (is_array($program) && isset($program['name']) && is_scalar($program['name']) && (string) $program['name'] !== '') ? (string) $program['name'] : (string) $key;
    }

    /** A product's title as stored (no display filters), for log lines and plain text. */
    public static function product_title($product_id) {
        $title = get_post_field('post_title', intval($product_id), 'raw');
        return is_string($title) && $title !== '' ? $title : '#' . intval($product_id);
    }

    /** Inside the lock: the stored row read fresh and put in the request cache. array($all or null, stored rev). */
    private static function fresh_rows($key) {
        wp_cache_delete(self::OPTION, 'options');
        self::flush_cache();
        $all = self::stored_row();
        if ($all === null) {
            return array(null, 0);
        }
        self::$all = $all;
        $rev = 0;
        if (isset($all[$key]) && is_array($all[$key]) && isset($all[$key]['rev'])) {
            $stored = self::whole($all[$key]['rev']);
            $rev = ($stored !== null && $stored >= 0) ? $stored : 0;
        }
        return array($all, $rev);
    }

    /** The program's entry in team_oversight_programs read fresh (the seeded list when the option is empty), or null. */
    private static function fresh_program($key) {
        $all = self::read_option_fresh(TeamOversight_Programs::PROGRAMS_OPTION);
        if ($all === null) {
            return null;
        }
        if (empty($all)) {
            $all = TeamOversight_Programs::get_programs();
        }
        return (isset($all[$key]) && is_array($all[$key])) ? $all[$key] : null;
    }

    /** The checks every product action shares before it takes the lock: array($program, $expected, $refusal or null). */
    private static function action_start($key, $expected_rev) {
        if (!current_user_can('manage_options')) {
            return array(null, 0, self::refused(array('Only the committee can change a program\'s products.')));
        }
        $program = TeamOversight_Programs::get_program((string) $key);
        if (!is_array($program)) {
            return array(null, 0, self::refused(array('That program isn\'t configured, so nothing was changed.')));
        }
        $expected = self::whole($expected_rev);
        if ($expected === null || $expected < 0) {
            return array(null, 0, self::refused(array('The setup\'s revision is missing, so nothing was changed. Reload and try again.')));
        }
        if (!class_exists('TeamOversight_Program_Sessions') || !function_exists('wc_get_product')) {
            return array(null, 0, self::refused(array('WooCommerce isn\'t available, so nothing was changed.')));
        }
        return array($program, $expected, null);
    }

    const REV_REFUSAL = 'Someone else saved this program since you opened it. Reload and try again.';
    const READ_REFUSAL = 'The setup couldn\'t be read, so nothing was changed. Reload and try again.';
    const ON_SALE_REFUSAL = 'This product is on sale: adding it as an earlier product stops it selling. Make it private first, then add it.';

    /** admin-post murvc_program_setup: manage_options, the program's nonce, then process_post() and back to the tab. */
    public static function handle_admin_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        $key = isset($_POST['key']) && is_scalar($_POST['key']) ? sanitize_key($_POST['key']) : '';
        if ($key === '' || !is_array(TeamOversight_Programs::get_program($key))) {
            wp_safe_redirect(admin_url('admin.php?page=club-programs-settings'));
            exit;
        }
        check_admin_referer('murvc_program_setup_' . $key);
        $post = wp_unslash($_POST);
        $result = self::process_post(is_array($post) ? $post : array(), get_current_user_id());
        set_transient(self::FLASH_PREFIX . get_current_user_id(), array(
            'key' => $key,
            'ok' => !empty($result['ok']),
            'messages' => isset($result['messages']) ? $result['messages'] : array(),
            'notes' => isset($result['notes']) ? $result['notes'] : array(),
            'links' => isset($result['links']) ? $result['links'] : array(),
        ), 300);
        $anchor = isset($result['anchor']) && $result['anchor'] !== '' ? '#' . $result['anchor'] : '';
        wp_safe_redirect(admin_url('admin.php?page=club-programs-' . $key . '&tab=setup') . $anchor);
        exit;
    }

    /**
     * One Setup post ($post unslashed): a box saves its own fields against
     * the posted rev; a product action runs with it. Returns array(ok,
     * messages, notes (1.86.0: the committee's dip notes, shown apart),
     * links, anchor). Callable from tests.
     */
    public static function process_post(array $post, $user_id) {
        $key = isset($post['key']) && is_scalar($post['key']) ? sanitize_key($post['key']) : '';
        $rev = isset($post['rev']) && is_scalar($post['rev']) ? (string) $post['rev'] : '';
        $out = array('ok' => false, 'messages' => array(), 'notes' => array(), 'links' => array(), 'anchor' => '');
        if (!user_can(intval($user_id), 'manage_options') || intval($user_id) !== get_current_user_id()) {
            $out['messages'][] = 'Only the committee can change a program\'s setup.';
            return $out;
        }
        $box = isset($post['box']) && is_scalar($post['box']) ? sanitize_key($post['box']) : '';
        $do = isset($post['do']) && is_scalar($post['do']) ? sanitize_key($post['do']) : '';
        if (in_array($box, self::OLD_BOXES, true)) {
            $out['anchor'] = 'price';
            $out['messages'][] = self::OLD_BOX_REFUSAL;
            return $out;
        }
        if (in_array($box, self::BOXES, true)) {
            $out['anchor'] = $box;
            if ($box === 'price') {
                $typed = isset($post['standard_price']) && is_scalar($post['standard_price']) ? ltrim(trim((string) $post['standard_price']), '$') : '';
                $cents = self::money_cents($typed);
                if ($cents === null || $cents < self::MIN_PRICE_CENTS || $cents > self::MAX_PRICE_CENTS) {
                    $out['messages'][] = 'The standard price must be from $' . self::cents_text(self::MIN_PRICE_CENTS) . ' to $' . self::cents_text(self::MAX_PRICE_CENTS) . ', with at most two decimals.';
                    return $out;
                }
            }
            $fields = self::fields_from_post($box, $post, $key);
            $result = self::save($key, $fields, $rev);
            $out['ok'] = !empty($result['ok']);
            $out['messages'] = $out['ok'] ? array('Saved.') : $result['errors'];
            $out['notes'] = ($out['ok'] && !empty($result['notes'])) ? $result['notes'] : array();
            // B17: sold individually follows max_places (checked on every Booking save,
            // written only where it differs, so a save that missed a lock heals).
            if ($out['ok'] && $box === 'booking') {
                $notes = self::locked(function () use ($key) {
                    return self::apply_sold_individually($key);
                });
                if (is_array($notes) && isset($notes['ok'])) {
                    $out['messages'][] = 'Sold individually wasn\'t updated on the program\'s products: someone else is saving. Save the box again.';
                } elseif (is_array($notes)) {
                    $out['messages'] = array_merge($out['messages'], $notes);
                }
            }
            return $out;
        }
        $out['anchor'] = 'products';
        $text = function ($name) use ($post) {
            return isset($post[$name]) && is_scalar($post[$name]) ? trim((string) $post[$name]) : '';
        };
        switch ($do) {
            case 'prepare':
                $result = self::prepare($key, $text('title'), $text('year'), $rev);
                break;
            case 'discard':
                $result = self::discard($key, $rev);
                break;
            case 'switch':
                $result = self::switch_over($key, $rev);
                break;
            case 'switch_back':
                $result = self::switch_back($key, $rev);
                break;
            case 'add_past':
                $years = array();
                foreach (preg_split('/[\s,\/]+/', $text('years')) as $year) {
                    if ($year !== '') {
                        $years[] = $year;
                    }
                }
                $result = self::add_past_product($key, absint($text('product_id')), $text('kind'), $text('weekday'), $years, $rev);
                break;
            case 'remove_past':
                $result = self::remove_past_product($key, absint($text('product_id')), $rev);
                break;
            default:
                $out['messages'][] = 'Nothing to do.';
                return $out;
        }
        $out['ok'] = !empty($result['ok']);
        $out['messages'] = $out['ok'] ? array(isset($result['message']) ? $result['message'] : 'Done.') : $result['errors'];
        if (!empty($result['links'])) {
            $out['links'] = $result['links'];
        }
        return $out;
    }

    /** A posted time as HH:MM (a browser may add seconds). */
    private static function posted_time($value) {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^(\d{2}:\d{2}):\d{2}$/D', $value, $m)) {
            return $m[1];
        }
        return $value;
    }

    /**
     * One box's fields from the unslashed post: tick boxes '1' / '0',
     * numbers as typed, empty rows dropped, dates read as YYYY-MM-DD or
     * d/m/yyyy when they parse (else as typed, so validate() names the row).
     * Every rule is validate()'s, on the server. 1.86.0: the Booking box
     * carries close_at only when posted (C5: a page opened before 1.86.0
     * keeps the stored choice); the Standard price box writes prices as the
     * typed price followed by the stored others (C9, kept for a rollback).
     * 1.86.1: the Booking box never carries player_name (a page opened
     * before 1.86.1 still posts it; it is ignored and the stored value kept).
     */
    private static function fields_from_post($box, array $post, $key = '') {
        $scalar = function ($name) use ($post) {
            return isset($post[$name]) && is_scalar($post[$name]) ? trim((string) $post[$name]) : '';
        };
        $rows = function ($name) use ($post) {
            return (isset($post[$name]) && is_array($post[$name])) ? array_values($post[$name]) : array();
        };
        $date = function ($value) {
            $value = is_scalar($value) ? trim((string) $value) : '';
            $parsed = class_exists('TeamOversight_Program_Sessions') ? TeamOversight_Program_Sessions::parse_input_date($value) : '';
            return $parsed !== '' ? $parsed : $value;
        };
        switch ($box) {
            case 'booking':
                $fields = array(
                    'bookings_open' => $scalar('bookings_open') === '1' ? '1' : '0',
                    'booking_page' => $scalar('booking_page'),
                    'portal_page' => $scalar('portal_page'),
                    'max_places' => $scalar('max_places'),
                    'close_hours' => $scalar('close_hours'),
                );
                if (isset($post['close_at'])) {
                    $fields['close_at'] = $scalar('close_at');
                }
                return $fields;
            case 'price':
                $typed = ltrim($scalar('standard_price'), '$');
                $typed_cents = self::money_cents($typed);
                // The stored prices follow (the old standard price included), so a rollback keeps its approved list.
                $prices = array($typed_cents !== null ? self::cents_text($typed_cents) : $typed);
                foreach (self::get((string) $key)['prices'] as $other) {
                    if (count($prices) < self::MAX_PRICES && self::money_cents($other) !== $typed_cents) {
                        $prices[] = $other;
                    }
                }
                return array('prices' => $prices);
            case 'slots':
                $days = array();
                foreach ($rows('days') as $day) {
                    if (is_scalar($day)) {
                        $days[] = trim((string) $day);
                    }
                }
                $slots = array();
                foreach ($rows('slots') as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $start = self::posted_time(isset($row['start']) ? $row['start'] : '');
                    $end = self::posted_time(isset($row['end']) ? $row['end'] : '');
                    $places = isset($row['places']) && is_scalar($row['places']) ? trim((string) $row['places']) : '';
                    if ($start === '' && $end === '' && $places === '') {
                        continue;
                    }
                    $hidden = isset($row['hidden']) && is_scalar($row['hidden']) && (string) $row['hidden'] === '1' ? '1' : '0';
                    $slots[] = array('start' => $start, 'end' => $end, 'places' => $places, 'hidden' => $hidden);
                }
                return array('days' => $days, 'slots' => $slots);
            case 'prices':
                $prices = array();
                foreach ($rows('prices') as $price) {
                    $price = is_scalar($price) ? ltrim(trim((string) $price), '$') : '';
                    if ($price !== '') {
                        $prices[] = $price;
                    }
                }
                return array('prices' => $prices);
            case 'tiers':
                $tiers = array();
                foreach ($rows('tiers') as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $from = isset($row['from']) && is_scalar($row['from']) ? trim((string) $row['from']) : '';
                    $pct = isset($row['pct']) && is_scalar($row['pct']) ? rtrim(trim((string) $row['pct']), '%') : '';
                    if ($from === '' && $pct === '') {
                        continue;
                    }
                    $tiers[] = array('from' => $from, 'pct' => $pct);
                }
                return array('tiers' => $tiers);
            case 'blocks':
                $blocks = array();
                foreach ($rows('blocks') as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $name = isset($row['name']) && is_scalar($row['name']) ? trim((string) $row['name']) : '';
                    $from = $date(isset($row['from']) ? $row['from'] : '');
                    $to = $date(isset($row['to']) ? $row['to'] : '');
                    if ($name === '' && $from === '' && $to === '') {
                        continue;
                    }
                    $blocks[] = array('name' => $name, 'from' => $from, 'to' => $to);
                }
                return array('blocks' => $blocks);
        }
        return array();
    }

    /**
     * Design 6.2: the price check, a row per number of sessions from 1 to
     * $max at one price: n, pct, each_cents, total_cents, boundary (a tier
     * starts at n) and dip (n costs less than n - 1). The same maths as
     * tier_pct() and discounted_cents(). A dip is allowed (1.86.0, club 1):
     * dip_notes() words it for the committee.
     */
    public static function price_check_rows(array $tiers, $price, $max = 45) {
        $cents = self::money_cents(is_string($price) ? $price : (string) $price);
        $rows = array();
        if ($cents === null) {
            return $rows;
        }
        $starts = array();
        foreach ($tiers as $tier) {
            if (is_array($tier) && isset($tier['from'])) {
                $starts[intval($tier['from'])] = true;
            }
        }
        $previous = null;
        for ($n = 1; $n <= intval($max); $n++) {
            $pct = self::tier_pct($tiers, $n);
            $each = self::discounted_cents($cents, $pct);
            $total = $n * $each;
            $rows[] = array(
                'n' => $n,
                'pct' => $pct,
                'each_cents' => $each,
                'total_cents' => $total,
                'boundary' => isset($starts[$n]),
                'dip' => $previous !== null && $total < $previous,
            );
            $previous = $total;
        }
        return $rows;
    }

    private static function tab_styles() {
        return '<style>
        .murvc-setup-tab { max-width: 980px; }
        .murvc-setup-box { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 12px 18px; margin: 15px 0 20px; }
        .murvc-setup-box h2 { margin-top: 4px; }
        .murvc-setup-box table.murvc-setup-rows td, .murvc-setup-box table.murvc-setup-rows th { padding: 4px 8px 4px 0; text-align: left; vertical-align: middle; }
        .murvc-setup-note { color: #646970; }
        .murvc-price-check { max-height: 320px; overflow: auto; max-width: 520px; margin-top: 8px; }
        .murvc-price-check tr.is-boundary td { font-weight: 600; }
        .murvc-price-check tr.is-dip td { color: #996800; background: #fcf9e8; }
        .murvc-setup-flash ul { margin: 0 0 0 18px; list-style: disc; }
        .murvc-setup-box p.description { margin: 2px 0 0; color: #646970; font-style: normal; }
        .murvc-setup-rows th { width: 200px; }
        .murvc-setup-steps { list-style: none; margin: 10px 0 0; padding: 0; counter-reset: none; }
        .murvc-setup-steps li { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; padding: 7px 0; border-bottom: 1px solid #f0f0f1; }
        .murvc-setup-steps .murvc-step-chip { display: inline-block; min-width: 46px; text-align: center; font-size: 12px; font-weight: 600; padding: 1px 8px; border-radius: 10px; }
        .murvc-setup-steps li.is-done .murvc-step-chip { background: #edfaef; color: #1a7a2e; }
        .murvc-setup-steps li.is-todo .murvc-step-chip { background: #fcf9e8; color: #996800; }
        .murvc-setup-steps .murvc-step-note { color: #646970; }
        .murvc-setup-confirm-lead { font-size: 14px; }
        </style>';
    }

    /**
     * Club 3: the six steps at the top of a program's Setup tab, each done
     * or to do from the setup's own state (read only). A list of n, title,
     * done, note (what to do while it isn't done) and link (an anchor, or
     * the Sessions tab).
     */
    public static function steps($key) {
        $key = (string) $key;
        $setup = self::get($key);
        $has_entry = self::has_entry($key);
        $sessions_class = class_exists('TeamOversight_Program_Sessions');
        $dated = $sessions_class ? TeamOversight_Program_Sessions::target_product($key, 'current') : 0;
        $prepared = $sessions_class ? TeamOversight_Program_Sessions::target_product($key, 'prepared') : 0;
        $session_product = $prepared > 0 ? $prepared : $dated;
        $sessions = 0;
        if ($session_product > 0) {
            foreach (TeamOversight_Program_Sessions::children($session_product) as $row) {
                if ($row['date'] !== '' && $row['status'] !== 'trash') {
                    $sessions++;
                }
            }
        }
        $published = function ($page) {
            $page = intval($page);
            return $page > 0 && get_post_type($page) === 'page' && get_post_status($page) === 'publish';
        };
        $switch_note = 'After steps 2 and 3.';
        if ($prepared > 0) {
            $program = TeamOversight_Programs::get_program($key);
            $current_id = (is_array($program) && isset($program['product_id']) && is_scalar($program['product_id'])) ? intval($program['product_id']) : 0;
            $weekday = is_array($program) ? intval(TeamOversight_Programs::get_program_weekday($program)) : 0;
            $last = $current_id > 0 ? self::last_session_date($current_id, TeamOversight_Programs::detect_kind($current_id), $weekday) : '';
            if ($last !== '' && $sessions_class) {
                $parts = explode('-', $last);
                $switch_note = 'From ' . TeamOversight_Program_Sessions::date_label(gmdate('Y-m-d', gmmktime(0, 0, 0, intval($parts[1]), intval($parts[2]) + 1, intval($parts[0])))) . ', the day after the current product\'s last session.';
            } else {
                $switch_note = 'When the current season has ended.';
            }
        }
        return array(
            array('n' => 1, 'title' => 'Set the standard price and the booking rules', 'done' => $has_entry && !empty($setup['prices']),
                'note' => 'Save the Standard price box.', 'link' => '#price'),
            array('n' => 2, 'title' => 'Prepare next season\'s product', 'done' => $prepared > 0 || $dated > 0,
                'note' => 'Prepare it in the Products box.', 'link' => '#products'),
            array('n' => 3, 'title' => 'Add sessions on the Sessions tab', 'done' => $sessions > 0,
                'note' => $session_product > 0 ? 'Supervisors or the committee add them: day, times, places and dates.' : 'After step 2.', 'link' => $session_product > 0 ? 'sessions' : ''),
            array('n' => 4, 'title' => 'Make the Book page and the portal page', 'done' => $published($setup['booking_page']) && $published($setup['portal_page']),
                'note' => 'Make two pages holding [program_booking program="' . $key . '"] and [session_attendance program="' . $key . '"], publish them, then choose them in the Booking box.', 'link' => '#booking'),
            array('n' => 5, 'title' => 'Switch when this season ends', 'done' => $dated > 0 && $prepared <= 0,
                'note' => $switch_note, 'link' => '#products'),
            array('n' => 6, 'title' => 'Tick Bookings open', 'done' => !empty($setup['bookings_open']) && $dated > 0,
                'note' => $dated > 0 ? 'Tick it in the Booking box when you are ready.' : 'After the switch.', 'link' => '#booking'),
        );
    }

    private static function render_steps($key, $base_url) {
        $out = '<div class="murvc-setup-box" id="steps"><h2>Getting ready to sell sessions online</h2><ol class="murvc-setup-steps">';
        foreach (self::steps($key) as $step) {
            $link = '';
            if ($step['link'] === 'sessions') {
                $link = add_query_arg('tab', 'sessions', $base_url);
            } elseif ($step['link'] !== '') {
                $link = $step['link'];
            }
            $out .= '<li class="' . ($step['done'] ? 'is-done' : 'is-todo') . '"><span class="murvc-step-chip">' . ($step['done'] ? 'Done' : 'To do') . '</span>'
                . '<strong>' . intval($step['n']) . '. ' . esc_html($step['title']) . '</strong>';
            if (!$step['done']) {
                $out .= ' <span class="murvc-step-note">' . esc_html($step['note']) . ($link !== '' ? ' <a href="' . esc_url($link) . '">Go there</a>' : '') . '</span>';
            }
            $out .= '</li>';
        }
        return $out . '</ol></div>';
    }

    /** A box's form: admin-post, the program key, the box, the rev and the program's nonce. */
    private static function form_open($key, $field, $value, $rev, $attrs = '') {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . $attrs . '>'
            . '<input type="hidden" name="action" value="murvc_program_setup">'
            . '<input type="hidden" name="key" value="' . esc_attr($key) . '">'
            . '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '">'
            . '<input type="hidden" name="rev" value="' . intval($rev) . '">'
            . wp_nonce_field('murvc_program_setup_' . $key, '_wpnonce', true, false);
    }

    private static function page_select($name, $selected) {
        $out = '<select name="' . esc_attr($name) . '"><option value="0">None</option>';
        foreach (get_pages(array('post_status' => 'publish', 'sort_column' => 'post_title', 'number' => 500)) as $page) {
            $title = $page->post_title !== '' ? $page->post_title : '#' . $page->ID;
            $out .= '<option value="' . intval($page->ID) . '"' . selected(intval($selected), intval($page->ID), false) . '>' . esc_html($title) . '</option>';
        }
        return $out . '</select>';
    }

    /**
     * The Setup tab (design 6.1; 1.86.0, club 3 and 4): the six-step guide,
     * then the Booking, Standard price, Discounts, Blocks and Products
     * boxes, each its own form posting to admin-post with the stored rev,
     * and the confirm views for Switch,
     * Switch back and Add an earlier product. manage_options only. It reads
     * and takes the user's flash; nothing else is written.
     */
    public static function render_tab($key, $base_url) {
        $key = (string) $key;
        if (!current_user_can('manage_options')) {
            return '';
        }
        $program = TeamOversight_Programs::get_program($key);
        if (!is_array($program)) {
            return '';
        }
        $user_id = get_current_user_id();
        $flash = get_transient(self::FLASH_PREFIX . $user_id);
        if (is_array($flash)) {
            delete_transient(self::FLASH_PREFIX . $user_id);
            if (!isset($flash['key']) || $flash['key'] !== $key) {
                $flash = null;
            }
        }
        $setup = self::get($key);
        $rev = intval($setup['rev']);
        $out = self::tab_styles() . '<div class="murvc-setup-tab">';
        if (is_array($flash) && !empty($flash['messages'])) {
            $out .= '<div class="notice ' . (!empty($flash['ok']) ? 'notice-success' : 'notice-error') . ' murvc-setup-flash"><ul>';
            foreach ((array) $flash['messages'] as $message) {
                $out .= '<li>' . esc_html((string) $message) . '</li>';
            }
            $out .= '</ul>';
            if (!empty($flash['notes']) && is_array($flash['notes'])) {
                // Club 1: a price dip is saved; the note stands out for the committee.
                $out .= '</div><div class="notice notice-warning murvc-setup-flash"><ul>';
                foreach ($flash['notes'] as $note) {
                    $out .= '<li>' . esc_html((string) $note) . '</li>';
                }
                $out .= '</ul>';
            }
            if (!empty($flash['links']) && is_array($flash['links'])) {
                $out .= '<p><strong>These pages and menu items link to the old product. Point them at the Book page.</strong></p><ul>';
                foreach ($flash['links'] as $link) {
                    $label = esc_html((string) $link['title']) . ' (' . esc_html((string) $link['type']) . ', ' . esc_html((string) $link['why']) . ')';
                    $out .= '<li>' . ($link['edit'] !== '' ? '<a href="' . esc_url($link['edit']) . '">' . $label . '</a>' : $label) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</div>';
        }
        if (!self::has_entry($key)) {
            $out .= '<p class="murvc-setup-note">This program isn\'t set up for online booking yet: saving any box sets it up.</p>';
        }
        $confirm = isset($_GET['confirm']) && is_scalar($_GET['confirm']) ? sanitize_key($_GET['confirm']) : '';
        if ($confirm === 'switch') {
            return $out . self::render_switch_confirm($key, $base_url, $rev) . '</div>';
        }
        if ($confirm === 'switch_back') {
            return $out . self::render_switch_back_confirm($key, $base_url, $rev) . '</div>';
        }
        if (isset($_GET['add_past']) && is_scalar($_GET['add_past'])) {
            $kind = isset($_GET['kind']) && is_scalar($_GET['kind']) ? sanitize_key($_GET['kind']) : '';
            return $out . self::render_add_past_confirm($key, $base_url, $rev, absint($_GET['add_past']), $kind) . '</div>';
        }
        $out .= self::render_steps($key, $base_url)
            . self::box_booking($key, $setup, $rev)
            . self::box_price($key, $setup, $rev)
            . self::box_tiers($key, $setup, $rev)
            . self::box_blocks($key, $setup, $rev)
            . self::box_products($key, $program, $setup, $rev, $base_url);
        return $out . '</div>';
    }

    /** A form row: the label, the field and a one-line hint under it (club 3). */
    private static function field_row($label, $field, $hint) {
        return '<tr><th scope="row">' . $label . '</th><td>' . $field . '<p class="description">' . esc_html($hint) . '</p></td></tr>';
    }

    /**
     * The Booking box (club 3: plainer labels and a hint on every field;
     * club 2: the booking cut-off).
     */
    private static function box_booking($key, array $setup, $rev) {
        $close_at = in_array($setup['close_at'], self::CLOSE_AT, true) ? $setup['close_at'] : 'day';
        $radio = function ($value, $label) use ($close_at) {
            return '<label style="display: block; margin: 2px 0;"><input type="radio" name="close_at" value="' . esc_attr($value) . '"' . checked($close_at, $value, false) . '> ' . $label . '</label>';
        };
        $cutoff = $radio('before', '<input type="number" name="close_hours" min="0" max="' . self::MAX_CLOSE_HOURS . '" value="' . intval($setup['close_hours']) . '" style="width: 64px;" aria-label="Hours before the start"> hours before the start')
            . $radio('start', 'At the start')
            . $radio('end', 'At the end of the session')
            . $radio('day', 'At the end of the day (11:59pm)');
        $out = '<div class="murvc-setup-box" id="booking"><h2>Booking</h2>' . self::form_open($key, 'box', 'booking', $rev)
            . '<table class="murvc-setup-rows"><tbody>'
            . self::field_row('Bookings open', '<input type="hidden" name="bookings_open" value="0"><label><input type="checkbox" name="bookings_open" value="1"' . checked(!empty($setup['bookings_open']), true, false) . '> Members can book</label>',
                'Members can book the sessions on sale. Untick to stop new bookings at once; anyone already booked stays booked.')
            . self::field_row('Book page', self::page_select('booking_page', $setup['booking_page']),
                'The page holding [program_booking program="' . $key . '"]. Members book there, and the product\'s own page forwards to it.')
            . self::field_row('Portal page', self::page_select('portal_page', $setup['portal_page']),
                'The page holding [session_attendance program="' . $key . '"], for this program\'s supervisors.')
            . self::field_row('Places per booking', '<input type="number" name="max_places" min="1" max="' . self::MAX_MAX_PLACES . '" value="' . intval($setup['max_places']) . '" style="width: 64px;">',
                'Every booking is for the account holder, by the name on their account. 1 means just them. Up to ' . self::MAX_MAX_PLACES . ' lets them bring guests on one booking. How many places each session has is set when sessions are added, on the Sessions tab.')
            . self::field_row('Booking cut-off', $cutoff,
                'When a session stops taking bookings. At the end of the day lets a late arrival pay on the spot or that evening.')
            . '</tbody></table><p><button type="submit" class="button button-primary">Save booking</button></p></form></div>';
        return $out;
    }

    /** The Standard price box (1.86.0, club 4): prices[0], the price every new session starts at. */
    private static function box_price($key, array $setup, $rev) {
        $value = !empty($setup['prices']) ? (string) $setup['prices'][0] : '';
        return '<div class="murvc-setup-box" id="price"><h2>Standard price</h2>' . self::form_open($key, 'box', 'price', $rev)
            . '<table class="murvc-setup-rows"><tbody>'
            . self::field_row('<label for="murvc-standard-price">Standard price (incl. GST)</label>', '$<input type="text" inputmode="decimal" name="standard_price" id="murvc-standard-price" value="' . esc_attr($value) . '" size="8">',
                'Every new session starts at this price. Only the committee can give a session a different price, on the Sessions tab.')
            . '</tbody></table><p><button type="submit" class="button button-primary">Save standard price</button></p></form></div>';
    }

    private static function box_tiers($key, array $setup, $rev) {
        $out = '<div class="murvc-setup-box" id="tiers"><h2>Discounts</h2>' . self::form_open($key, 'box', 'tiers', $rev, ' id="murvc-tiers-form"')
            . '<p class="description">Every session of this program in one cart gets the percent of the highest row it reaches; brothers and sisters count together. Both columns rise from row to row.</p>'
            . '<table class="murvc-setup-rows"><tbody>';
        for ($i = 0; $i < self::MAX_TIERS; $i++) {
            $tier = isset($setup['tiers'][$i]) ? $setup['tiers'][$i] : null;
            $out .= '<tr><td>from <input type="number" name="tiers[' . $i . '][from]" min="' . self::TIER_FROM_MIN . '" max="' . self::TIER_FROM_MAX . '" value="' . esc_attr($tier !== null ? (string) intval($tier['from']) : '') . '" style="width: 70px;"> sessions,</td>'
                . '<td><input type="number" name="tiers[' . $i . '][pct]" min="' . self::TIER_PCT_MIN . '" max="' . self::TIER_PCT_MAX . '" value="' . esc_attr($tier !== null ? (string) intval($tier['pct']) : '') . '" style="width: 70px;">% off</td></tr>';
        }
        $out .= '</tbody></table><p><button type="submit" class="button button-primary">Save discounts</button></p></form>';
        $price = !empty($setup['prices']) ? $setup['prices'][0] : '';
        $out .= '<h3>Price check</h3><p class="description">At the standard price, as the rows are typed. A dip (more sessions costing less in total) is allowed: the Book page and the cart then tell families just below it that one more costs less.</p>'
            . '<div class="murvc-price-check"><table class="widefat striped" id="murvc-price-check"><thead><tr><th>Sessions</th><th>% off</th><th>Each</th><th>Total</th><th></th></tr></thead><tbody>';
        foreach (self::price_check_rows($setup['tiers'], $price) as $row) {
            $class = $row['dip'] ? 'is-dip' : ($row['boundary'] ? 'is-boundary' : '');
            $out .= '<tr class="' . $class . '"><td>' . intval($row['n']) . '</td><td>' . intval($row['pct']) . '%</td><td>$' . esc_html(self::cents_text($row['each_cents'])) . '</td><td>$' . esc_html(self::cents_text($row['total_cents'])) . '</td><td>'
                . ($row['dip'] ? 'costs less than ' . (intval($row['n']) - 1) . ': families will add one' : ($row['boundary'] ? 'a tier starts' : '')) . '</td></tr>';
        }
        if ($price === '') {
            $out .= '<tr><td colspan="5">Save the standard price first.</td></tr>';
        }
        $out .= '</tbody></table></div></div>';
        // The same formula as discounted_cents(), redrawn as the rows are typed; cells written with textContent.
        $out .= '<script>(function () {
            var form = document.getElementById("murvc-tiers-form");
            var table = document.getElementById("murvc-price-check");
            if (!form || !table) { return; }
            function cents(text) {
                var m = /^\s*\$?(\d{1,3})(?:\.(\d{1,2}))?\s*$/.exec(text || "");
                if (!m) { return null; }
                return parseInt(m[1], 10) * 100 + (m[2] ? parseInt((m[2] + "0").slice(0, 2), 10) : 0);
            }
            function money(c) { return "$" + Math.floor(c / 100) + "." + ("0" + (c % 100)).slice(-2); }
            function redraw() {
                var priceInput = document.getElementById("murvc-standard-price");
                var c = cents(priceInput ? priceInput.value : "");
                var tiers = [];
                for (var i = 0; i < ' . self::MAX_TIERS . '; i++) {
                    var f = form.querySelector("input[name=\"tiers[" + i + "][from]\"]");
                    var p = form.querySelector("input[name=\"tiers[" + i + "][pct]\"]");
                    if (f && p && /^\d+$/.test(f.value) && /^\d+$/.test(p.value)) { tiers.push([parseInt(f.value, 10), parseInt(p.value, 10)]); }
                }
                var body = table.tBodies[0];
                while (body.firstChild) { body.removeChild(body.firstChild); }
                if (c === null) { return; }
                var previous = null;
                for (var n = 1; n <= 45; n++) {
                    var pct = 0, best = null, boundary = false;
                    tiers.forEach(function (t) {
                        if (t[0] <= n && (best === null || t[0] > best)) { best = t[0]; pct = t[1]; }
                        if (t[0] === n) { boundary = true; }
                    });
                    var each = Math.floor((c * (100 - pct) + 50) / 100);
                    var total = n * each;
                    var dip = previous !== null && total < previous;
                    var tr = document.createElement("tr");
                    tr.className = dip ? "is-dip" : (boundary ? "is-boundary" : "");
                    [String(n), pct + "%", money(each), money(total), dip ? "costs less than " + (n - 1) + ": families will add one" : (boundary ? "a tier starts" : "")].forEach(function (text) {
                        var td = document.createElement("td");
                        td.textContent = text;
                        tr.appendChild(td);
                    });
                    body.appendChild(tr);
                    previous = total;
                }
            }
            form.addEventListener("input", redraw);
            var priceInput = document.getElementById("murvc-standard-price");
            if (priceInput) { priceInput.addEventListener("input", redraw); }
        })();</script>';
        return $out;
    }

    private static function box_blocks($key, array $setup, $rev) {
        $out = '<div class="murvc-setup-box" id="blocks"><h2>Blocks</h2>' . self::form_open($key, 'box', 'blocks', $rev)
            . '<p class="description">Named periods the Book page offers as one-tap choices (the club\'s own periods, for example Term 1). Name 1 to ' . self::BLOCK_NAME_MAX . ' characters, at most ' . self::BLOCK_MAX_DAYS . ' days long.</p>'
            . '<table class="murvc-setup-rows"><thead><tr><th>Name</th><th>From</th><th>To</th></tr></thead><tbody>';
        $rows = array_values($setup['blocks']);
        $count = min(self::MAX_BLOCKS, count($rows) + 3);
        for ($i = 0; $i < $count; $i++) {
            $block = isset($rows[$i]) ? $rows[$i] : array('name' => '', 'from' => '', 'to' => '');
            $out .= '<tr><td><input type="text" name="blocks[' . $i . '][name]" maxlength="' . self::BLOCK_NAME_MAX . '" value="' . esc_attr($block['name']) . '"></td>'
                . '<td><input type="date" name="blocks[' . $i . '][from]" value="' . esc_attr($block['from']) . '"></td>'
                . '<td><input type="date" name="blocks[' . $i . '][to]" value="' . esc_attr($block['to']) . '"></td></tr>';
        }
        return $out . '</tbody></table><p><button type="submit" class="button button-primary">Save blocks</button></p></form></div>';
    }

    private static function kind_words($kind) {
        $words = array(
            'dated' => 'dated sessions (made by Club Manager)',
            'legacy_date' => 'date labels',
            'month' => 'months',
            'paid_date' => 'dated by payment',
        );
        return isset($words[$kind]) ? $words[$kind] : (string) $kind;
    }

    private static function box_products($key, array $program, array $setup, $rev, $base_url) {
        $out = '<div class="murvc-setup-box" id="products"><h2>Products</h2>';
        $current = isset($program['product_id']) && is_scalar($program['product_id']) ? intval($program['product_id']) : 0;
        $kind = TeamOversight_Programs::detect_kind($current);
        if ($current > 0 && get_post($current)) {
            $out .= '<p><strong>On sale now:</strong> #' . $current . ' ' . esc_html(self::product_title($current)) . ' (' . esc_html(self::kind_words($kind)) . ', ' . esc_html(get_post_status($current)) . ')'
                . ' <a href="' . esc_url(admin_url('post.php?post=' . $current . '&action=edit')) . '">Edit in WooCommerce</a></p>';
        } else {
            $out .= '<p><strong>On sale now:</strong> no product found (#' . $current . ').</p>';
        }

        $prepared = intval($setup['prepared_product']);
        if ($prepared > 0) {
            $sessions = 0;
            if (class_exists('TeamOversight_Program_Sessions')) {
                foreach (TeamOversight_Program_Sessions::children($prepared) as $row) {
                    if ($row['date'] !== '' && $row['status'] !== 'trash') {
                        $sessions++;
                    }
                }
            }
            $out .= '<p><strong>Being prepared:</strong> #' . $prepared . ' ' . esc_html(self::product_title($prepared)) . ' (' . esc_html((string) get_post_status($prepared)) . ', season ' . intval(get_post_meta($prepared, '_murvc_program_year', true)) . ', ' . $sessions . ' session' . ($sessions === 1 ? '' : 's') . ')</p>';
            $has_lines = class_exists('TeamOversight_Program_Sessions') && TeamOversight_Program_Sessions::has_order_lines($prepared);
            $out .= '<p><a class="button button-primary" href="' . esc_url(add_query_arg('confirm', 'switch', $base_url)) . '">Switch to the prepared product</a></p>';
            if ($has_lines) {
                $out .= '<p class="murvc-setup-note">It has order lines, so it can\'t be discarded.</p>';
            } else {
                $out .= self::form_open($key, 'do', 'discard', $rev) . '<p><button type="submit" class="button">Discard prepared product</button> <span class="murvc-setup-note">'
                    . esc_html('Moves it and its ' . $sessions . ' session' . ($sessions === 1 ? '' : 's') . ' to the Bin; nothing has been sold from it.') . '</span></p></form>';
            }
        } else {
            $this_year = intval(wp_date('Y'));
            // C13: Prepare needs only the standard price (step 1); days and times come with the sessions.
            $ready = !empty($setup['prices']);
            $out .= '<h3>Prepare the next product</h3>';
            if (!$ready) {
                $out .= '<p class="murvc-setup-note">Set the standard price first.</p>';
            } else {
                $out .= self::form_open($key, 'do', 'prepare', $rev)
                    . '<p class="description">Makes next season\'s product as a hidden draft; nothing goes on sale. Supervisors then add its sessions on the Sessions tab. It takes the current product\'s category, description and membership fields.</p>'
                    . '<p><label>Title <input type="text" name="title" maxlength="' . self::TITLE_MAX . '" size="40" value="' . esc_attr(self::program_name($key, $program) . ' ' . ($this_year + 1)) . '"></label> '
                    . '<label>Season <select name="year"><option value="' . $this_year . '">' . $this_year . '</option><option value="' . ($this_year + 1) . '" selected>' . ($this_year + 1) . '</option></select></label> '
                    . '<button type="submit" class="button button-primary">Prepare</button></p></form>';
            }
        }

        if (self::switch_back_open($key)) {
            $out .= '<p><a class="button" href="' . esc_url(add_query_arg('confirm', 'switch_back', $base_url)) . '">Switch back</a> <span class="murvc-setup-note">Possible until the new product\'s first order.</span></p>';
        }

        $out .= '<h3>Earlier products</h3>';
        if (empty($setup['past_products'])) {
            $out .= '<p class="murvc-setup-note">None.</p>';
        } else {
            $out .= '<table class="widefat striped" style="max-width: 760px;"><thead><tr><th>Product</th><th>Dates read as</th><th>Weekday</th><th>Years</th><th></th></tr></thead><tbody>';
            foreach ($setup['past_products'] as $past) {
                $pid = intval($past['product_id']);
                $out .= '<tr><td>#' . $pid . ' ' . esc_html(self::product_title($pid)) . '</td><td>' . esc_html(self::kind_words($past['kind'])) . '</td><td>'
                    . esc_html($past['weekday'] > 0 && class_exists('TeamOversight_Program_Sessions') ? TeamOversight_Program_Sessions::day_name($past['weekday']) : 'none') . '</td><td>' . esc_html(implode(', ', $past['years'])) . '</td><td>';
                if ($past['kind'] !== 'dated') {
                    $out .= self::form_open($key, 'do', 'remove_past', $rev) . '<input type="hidden" name="product_id" value="' . $pid . '"><button type="submit" class="button">Remove</button></form>';
                }
                $out .= '</td></tr>';
            }
            $out .= '</tbody></table>';
        }
        $out .= '<h3>Add an earlier product</h3><form method="get" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr('club-programs-' . $key) . '"><input type="hidden" name="tab" value="setup">'
            . '<p><label>Product id <input type="number" name="add_past" min="1" style="width: 110px;"></label> '
            . '<label>Its dates are <select name="kind"><option value="legacy_date">date labels (13-Oct)</option><option value="month">months (October 2026)</option><option value="paid_date">dated by payment (a one-off fee)</option></select></label> '
            . '<button type="submit" class="button">Check it</button></p>'
            . '<p class="murvc-setup-note">Its paid bookings then count as history for New here and the stats. Month and paid-date products never appear on a roll.</p></form>';
        return $out . '</div>';
    }

    // ------------------------------------------------------------------
    // 1.85.0: Prepare, Discard, Switch, Switch back (design 6.4, 3.2)
    // ------------------------------------------------------------------

    /**
     * Prepare the next product (design 6.4, 3.2): inside the lock, makes the
     * program's next season product as a hidden draft (variable, taxable,
     * sold individually when one place per booking, the current product's
     * category, description and membership fields, one grant per order
     * marked for 1.86.0, attributes Date and Time) and records it as the
     * prepared product. The current product is read, never written. Its
     * sessions are virtual: WooCommerce keeps that flag per variation (a
     * variable parent is never virtual), so the generator sets it on each.
     */
    public static function prepare($key, $title, $year, $expected_rev) {
        $key = (string) $key;
        list($program, $expected, $refusal) = self::action_start($key, $expected_rev);
        if ($refusal !== null) {
            return $refusal;
        }
        $title = sanitize_text_field((string) $title);
        $year = self::whole($year);
        return self::locked(function () use ($key, $program, $expected, $title, $year) {
            list($all, $rev) = self::fresh_rows($key);
            if ($all === null) {
                return self::refused(array(self::READ_REFUSAL));
            }
            if ($rev !== $expected) {
                return self::refused(array(self::REV_REFUSAL), $rev);
            }
            $setup = self::get($key);
            $errors = array();
            if (intval($setup['prepared_product']) > 0) {
                $errors[] = 'There\'s already a prepared product.';
            }
            if (empty($setup['prices'])) {
                $errors[] = 'Set the standard price first.';
            }
            $length = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
            if ($length < 1 || $length > self::TITLE_MAX) {
                $errors[] = 'The title must be 1 to ' . self::TITLE_MAX . ' characters.';
            }
            $this_year = intval(wp_date('Y'));
            if ($year === null || ($year !== $this_year && $year !== $this_year + 1)) {
                $errors[] = 'The season must be ' . $this_year . ' or ' . ($this_year + 1) . '.';
            }
            $fresh = self::fresh_program($key);
            $current_id = (is_array($fresh) && isset($fresh['product_id']) && is_scalar($fresh['product_id'])) ? intval($fresh['product_id']) : 0;
            $current = $current_id > 0 ? wc_get_product($current_id) : false;
            if (!$current) {
                $errors[] = 'The program\'s current product doesn\'t exist.';
            }
            if (!empty($errors)) {
                return self::refused($errors, $rev);
            }

            $product = new WC_Product_Variable();
            $product->set_name($title);
            $product->set_status('draft');
            $product->set_catalog_visibility('hidden');
            $product->set_tax_status('taxable');
            $product->set_tax_class('');
            $product->set_sold_individually(intval($setup['max_places']) === 1);
            $product->set_category_ids($current->get_category_ids('edit'));
            $product->set_description($current->get_description('edit'));
            $product->set_short_description($current->get_short_description('edit'));
            $tier_meta = class_exists('TeamOversight_Memberships') ? TeamOversight_Memberships::PRODUCT_TIER_META : '_murvc_membership_tier';
            $term_meta = class_exists('TeamOversight_Memberships') ? TeamOversight_Memberships::PRODUCT_TERM_META : '_murvc_membership_term_months';
            foreach (array($tier_meta, $term_meta) as $meta_key) {
                $value = get_post_meta($current_id, $meta_key, true);
                if ($value !== '' && $value !== false && $value !== null) {
                    $product->update_meta_data($meta_key, $value);
                }
            }
            $product->update_meta_data('_murvc_membership_grant', 'order');
            $product->update_meta_data('_murvc_session_product', '1');
            $product->update_meta_data('_murvc_program_key', $key);
            $product->update_meta_data('_murvc_program_year', (string) $year);
            $date_attr = new WC_Product_Attribute();
            $date_attr->set_id(0);
            $date_attr->set_name('Date');
            $date_attr->set_options(array());
            $date_attr->set_position(0);
            $date_attr->set_visible(true);
            $date_attr->set_variation(true);
            // C12: like Date, Time starts with no options; the first series fills both from its sessions.
            $time_attr = new WC_Product_Attribute();
            $time_attr->set_id(0);
            $time_attr->set_name('Time');
            $time_attr->set_options(array());
            $time_attr->set_position(1);
            $time_attr->set_visible(false);
            $time_attr->set_variation(true);
            $product->set_attributes(array('date' => $date_attr, 'time' => $time_attr));
            $pid = intval($product->save());
            if ($pid <= 0) {
                return self::refused(array('The product couldn\'t be made.'), $rev);
            }
            $message = self::program_name($key, $program) . ': prepared product #' . $pid . ' ' . $title . ' made (draft, hidden, season ' . $year . ').';
            $result = self::save_locked($key, $program, array('prepared_product' => $pid), $expected, $message);
            if (empty($result['ok'])) {
                // A fresh draft with no sessions and no lines, which the guard lets go.
                $product->delete(true);
                return $result;
            }
            $result['message'] = 'Prepared ' . $title . ' (#' . $pid . '), a hidden draft. Supervisors can now load its sessions on the Sessions tab.';
            return $result;
        });
    }

    /**
     * B17: sold individually follows max_places on the program's dated
     * current and prepared products, each under its session lock. Called
     * inside locked() after a Booking box save changes max_places. Returns
     * sentences for what it couldn't do.
     */
    public static function apply_sold_individually($key) {
        $notes = array();
        if (!class_exists('TeamOversight_Program_Sessions')) {
            return $notes;
        }
        $want = intval(self::get((string) $key)['max_places']) === 1;
        foreach (array('current', 'prepared') as $role) {
            $pid = TeamOversight_Program_Sessions::target_product((string) $key, $role);
            if ($pid <= 0) {
                continue;
            }
            if (!TeamOversight_Program_Sessions::take_lock($pid)) {
                $notes[] = 'Sold individually on #' . $pid . ' wasn\'t updated: someone else is changing its sessions. Save the box again.';
                continue;
            }
            try {
                clean_post_cache($pid);
                $product = wc_get_product($pid);
                if ($product && (bool) $product->get_sold_individually('edit') !== $want) {
                    $product->set_sold_individually($want);
                    $product->save();
                }
            } finally {
                TeamOversight_Program_Sessions::release_lock($pid);
            }
        }
        return $notes;
    }

    /**
     * Discard prepared product (design 6.4, 4.4): only while it has no order
     * lines at all. Bins it (WooCommerce bins its variations with it, which
     * the guard allows for this product alone) and clears prepared_product.
     * It stays in the Bin: the guard refuses deleting it for good.
     */
    public static function discard($key, $expected_rev) {
        $key = (string) $key;
        list($program, $expected, $refusal) = self::action_start($key, $expected_rev);
        if ($refusal !== null) {
            return $refusal;
        }
        return self::locked(function () use ($key, $program, $expected) {
            global $wpdb;
            list($all, $rev) = self::fresh_rows($key);
            if ($all === null) {
                return self::refused(array(self::READ_REFUSAL));
            }
            if ($rev !== $expected) {
                return self::refused(array(self::REV_REFUSAL), $rev);
            }
            $pid = intval(self::get($key)['prepared_product']);
            if ($pid <= 0) {
                return self::refused(array('There\'s no prepared product.'), $rev);
            }
            if (TeamOversight_Program_Sessions::has_order_lines($pid)) {
                return self::refused(array('It has order lines, so it can\'t be discarded.'), $rev);
            }
            if (!TeamOversight_Program_Sessions::take_lock($pid)) {
                return self::refused(array(TeamOversight_Program_Sessions::LOCK_BUSY), $rev);
            }
            try {
                $title = self::product_title($pid);
                TeamOversight_Program_Sessions::$discarding = $pid;
                try {
                    wp_trash_post($pid);
                } finally {
                    TeamOversight_Program_Sessions::$discarding = 0;
                }
                clean_post_cache($pid);
                $status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $pid));
                if ($status !== 'trash') {
                    return self::refused(array('The product couldn\'t be moved to the Bin, so nothing changed.'), $rev);
                }
                // The sessions really in the Bin with it.
                $binned = intval($wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_murvc_session_date' AND m.meta_value <> ''
                    WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'trash'
                ", $pid)));
                $sessions = $binned . ' session' . ($binned === 1 ? '' : 's');
                $message = self::program_name($key, $program) . ': prepared product #' . $pid . ' ' . $title . ' discarded (in the Bin with its ' . $sessions . ').';
                $result = self::save_locked($key, $program, array('prepared_product' => 0), $expected, $message);
                if (empty($result['ok'])) {
                    wp_untrash_post($pid);
                    return $result;
                }
                $result['message'] = 'Discarded ' . $title . ': it is in the Bin with its ' . $sessions . '.';
                return $result;
            } finally {
                TeamOversight_Program_Sessions::release_lock($pid);
            }
        });
    }

    /**
     * Every session date of a product, sorted and distinct: dated, its
     * _murvc_session_date values; legacy_date, its non-empty date labels read
     * with the current window and the weekday; month, the last day of each
     * "October 2026" label. Variations in the Bin and empty labels (the
     * "Any date" variations) are left out, and so is any label that reads as
     * no date.
     */
    public static function product_session_dates($product_id, $kind, $weekday) {
        global $wpdb;
        $pid = intval($product_id);
        $dates = array();
        if ($pid <= 0) {
            return $dates;
        }
        if ($kind === 'dated') {
            $values = $wpdb->get_col($wpdb->prepare("
                SELECT m.meta_value FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_murvc_session_date' AND m.meta_value <> ''
                WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status <> 'trash'
            ", $pid));
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if (self::ymd_parts($value) !== null) {
                    $dates[$value] = true;
                }
            }
        } elseif ($kind === 'month' || $kind === 'legacy_date') {
            $keys = $kind === 'month' ? "'attribute_month', 'attribute_pa_month'" : "'attribute_date', 'attribute_pa_date'";
            $labels = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT m.meta_value FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ({$keys}) AND m.meta_value <> ''
                WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status <> 'trash'
            ", $pid));
            $year = intval(wp_date('Y'));
            $months = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
            foreach ((array) $labels as $label) {
                $label = trim((string) $label);
                if ($label === '') {
                    continue;
                }
                if ($kind === 'month') {
                    if (!preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December) (\d{4})$/D', $label, $m)) {
                        continue;
                    }
                    $month = intval(array_search($m[1], $months, true)) + 1;
                    $dates[gmdate('Y-m-t', gmmktime(0, 0, 0, $month, 1, intval($m[2])))] = true;
                    continue;
                }
                $date = TeamOversight_Programs::parse_session_date($label, $year, intval($weekday));
                if (is_string($date) && self::ymd_parts($date) !== null) {
                    $dates[$date] = true;
                }
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        return $dates;
    }

    /**
     * Whether a product has any non-empty session date or date / month label
     * (variations in the Bin left out), read or not: tells labels that can't
     * be read from no labels at all.
     */
    private static function has_date_labels($product_id, $kind) {
        global $wpdb;
        $pid = intval($product_id);
        if ($pid <= 0) {
            return false;
        }
        if ($kind === 'dated') {
            $keys = "'_murvc_session_date'";
        } elseif ($kind === 'month') {
            $keys = "'attribute_month', 'attribute_pa_month'";
        } elseif ($kind === 'legacy_date') {
            $keys = "'attribute_date', 'attribute_pa_date'";
        } else {
            return false;
        }
        return intval($wpdb->get_var($wpdb->prepare("
            SELECT EXISTS (SELECT 1 FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ({$keys}) AND TRIM(m.meta_value) <> ''
                WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status <> 'trash')
        ", $pid))) === 1;
    }

    /** Design 6.4 step 1: a product's last session date ('' when none). */
    public static function last_session_date($product_id, $kind, $weekday) {
        $dates = self::product_session_dates($product_id, $kind, $weekday);
        return empty($dates) ? '' : end($dates);
    }

    /** B14: the distinct years of a product's session dates, the latest 5, else this year. */
    public static function product_years($product_id, $kind, $weekday) {
        $years = array();
        foreach (self::product_session_dates($product_id, $kind, $weekday) as $date) {
            $years[intval(substr($date, 0, 4))] = true;
        }
        $years = array_keys($years);
        sort($years);
        if (count($years) > self::MAX_YEARS) {
            $years = array_slice($years, -self::MAX_YEARS);
        }
        return empty($years) ? array(intval(wp_date('Y'))) : array_values($years);
    }

    /** A product's address and state straight from the database: array(slug, status), or null. */
    private static function post_state($product_id) {
        global $wpdb;
        clean_post_cache(intval($product_id));
        $row = $wpdb->get_row($wpdb->prepare("SELECT post_name, post_status, post_type FROM {$wpdb->posts} WHERE ID = %d", intval($product_id)));
        return ($row && $row->post_type === 'product') ? array('slug' => (string) $row->post_name, 'status' => (string) $row->post_status) : null;
    }

    /** Set a product's address, status and (when given) shop visibility through WooCommerce; its state after, or null. */
    private static function move_product($product_id, $slug, $status, $visibility) {
        clean_post_cache(intval($product_id));
        $product = wc_get_product(intval($product_id));
        if (!$product) {
            return null;
        }
        $product->set_slug($slug);
        $product->set_status($status);
        if ($visibility !== null) {
            $product->set_catalog_visibility($visibility);
        }
        $product->save();
        return self::post_state($product_id);
    }

    /** WordPress's old-address redirect must never point at a product that gave this address away. */
    private static function clean_old_slug(array $product_ids, $slug) {
        foreach ($product_ids as $pid) {
            delete_post_meta(intval($pid), '_wp_old_slug', (string) $slug);
        }
    }

    /** Design 6.4 step 1: why a Switch can't run now (none = it can). */
    private static function switch_problems($key, $old_id, $new_id, $weekday) {
        $problems = array();
        if ($new_id <= 0) {
            return array('There\'s no prepared product to switch to.');
        }
        $shape = self::product_shape_problem($new_id, 'dated', $key);
        if ($shape !== '') {
            $problems[] = $shape;
        }
        $sessions = 0;
        foreach (TeamOversight_Program_Sessions::children($new_id) as $row) {
            if ($row['date'] !== '' && $row['status'] !== 'trash') {
                $sessions++;
            }
        }
        if ($sessions === 0) {
            $problems[] = 'Load at least one session first.';
        }
        $old = $old_id > 0 ? self::post_state($old_id) : null;
        if ($old === null) {
            $problems[] = 'The program has no current product.';
            return $problems;
        }
        $old_kind = TeamOversight_Programs::detect_kind($old_id);
        $last = self::last_session_date($old_id, $old_kind, $weekday);
        if ($last !== '' && current_time('Y-m-d') <= $last) {
            $problems[] = 'The current product\'s last session is ' . TeamOversight_Program_Sessions::date_label($last) . '. Switch after that day.';
        }
        if ($last === '' && self::has_date_labels($old_id, $old_kind)) {
            // Labels that read as no date must not let a Switch run early: refuse rather than guess.
            $problems[] = 'The current product\'s session dates can\'t be read, so the Switch can\'t check they have passed.';
        }
        if ($old['slug'] === '') {
            $problems[] = 'The current product has no address, so it can\'t hand one over.';
        }
        if (!in_array($old['status'], array('publish', 'private', 'pending', 'draft'), true)) {
            $problems[] = 'The current product is ' . $old['status'] . ': publish it or make it private first.';
        }
        return $problems;
    }

    /** The facts the Switch confirm view lists (read only). */
    public static function switch_facts($key) {
        $key = (string) $key;
        $program = TeamOversight_Programs::get_program($key);
        $setup = self::get($key);
        $weekday = is_array($program) ? intval(TeamOversight_Programs::get_program_weekday($program)) : 0;
        $old_id = (is_array($program) && isset($program['product_id']) && is_scalar($program['product_id'])) ? intval($program['product_id']) : 0;
        $new_id = intval($setup['prepared_product']);
        $facts = array('old' => null, 'new' => null, 'years' => array(), 'weekday' => $weekday, 'problems' => array());
        $old = $old_id > 0 ? self::post_state($old_id) : null;
        if ($old !== null) {
            $kind = TeamOversight_Programs::detect_kind($old_id);
            $last = self::last_session_date($old_id, $kind, $weekday);
            $product = wc_get_product($old_id);
            $facts['old'] = array(
                'id' => $old_id,
                'title' => self::product_title($old_id),
                'kind' => $kind,
                'slug' => $old['slug'],
                'status' => $old['status'],
                'visibility' => $product ? $product->get_catalog_visibility('edit') : '',
                'last' => $last,
                'retired_slug' => $old['slug'] . '-' . ($last !== '' ? substr($last, 0, 4) : wp_date('Y')),
            );
            $facts['years'] = self::product_years($old_id, $kind, $weekday);
        }
        if ($new_id > 0) {
            $sessions = 0;
            foreach (TeamOversight_Program_Sessions::children($new_id) as $row) {
                if ($row['date'] !== '' && $row['status'] !== 'trash') {
                    $sessions++;
                }
            }
            $facts['new'] = array('id' => $new_id, 'title' => self::product_title($new_id), 'sessions' => $sessions, 'year' => intval(get_post_meta($new_id, '_murvc_program_year', true)));
        }
        $facts['problems'] = self::switch_problems($key, $old_id, $new_id, $weekday);
        return $facts;
    }

    /**
     * Switch to the prepared product (design 6.4, D6, D12, B14, B15). Inside
     * the setup lock, then both products' session locks: the old product
     * gets -<year> on its address and becomes private and hidden (nothing
     * else on it changes); the prepared product takes the old address and is
     * published, still hidden; team_oversight_programs first, then the setup
     * (the old product an earlier product, nothing prepared, the switch
     * recorded, bookings closed). Any failure undoes the earlier steps in
     * reverse. Returns the save's result plus message and links.
     */
    public static function switch_over($key, $expected_rev) {
        $key = (string) $key;
        list($program, $expected, $refusal) = self::action_start($key, $expected_rev);
        if ($refusal !== null) {
            return $refusal;
        }
        $result = self::locked(function () use ($key, $program, $expected) {
            list($all, $rev) = self::fresh_rows($key);
            if ($all === null) {
                return self::refused(array(self::READ_REFUSAL));
            }
            if ($rev !== $expected) {
                return self::refused(array(self::REV_REFUSAL), $rev);
            }
            $fresh = self::fresh_program($key);
            if (!is_array($fresh)) {
                return self::refused(array('The programs couldn\'t be read, so nothing changed.'), $rev);
            }
            $setup = self::get($key);
            $old_id = isset($fresh['product_id']) && is_scalar($fresh['product_id']) ? intval($fresh['product_id']) : 0;
            $new_id = intval($setup['prepared_product']);
            $weekday = intval(TeamOversight_Programs::get_program_weekday($fresh));
            $problems = self::switch_problems($key, $old_id, $new_id, $weekday);
            if (!empty($problems)) {
                return self::refused($problems, $rev);
            }
            $held = TeamOversight_Program_Sessions::take_locks(array($old_id, $new_id));
            if ($held === null) {
                return self::refused(array(TeamOversight_Program_Sessions::LOCK_BUSY), $rev);
            }
            try {
                // 3. The snapshot every undo restores.
                $old_state = self::post_state($old_id);
                $new_state = self::post_state($new_id);
                $old_product = wc_get_product($old_id);
                $new_product = wc_get_product($new_id);
                if ($old_state === null || $new_state === null || !$old_product || !$new_product) {
                    return self::refused(array('A product couldn\'t be read, so nothing changed.'), $rev);
                }
                $old_slug = $old_state['slug'];
                $old_vis = $old_product->get_catalog_visibility('edit');
                $new_vis = $new_product->get_catalog_visibility('edit');
                $kind = TeamOversight_Programs::detect_kind($old_id);
                $last = self::last_session_date($old_id, $kind, $weekday);
                $year = $last !== '' ? substr($last, 0, 4) : wp_date('Y');
                $undo_old = function () use ($old_id, $old_state, $old_vis, $old_slug, $new_id) {
                    self::move_product($old_id, $old_state['slug'], $old_state['status'], $old_vis);
                    self::clean_old_slug(array($old_id, $new_id), $old_slug);
                };
                $undo_new = function () use ($new_id, $new_state, $new_vis) {
                    self::move_product($new_id, $new_state['slug'], $new_state['status'], $new_vis);
                };

                // 4. The old product: a -year address, private, hidden. Nothing else.
                $after_old = self::move_product($old_id, $old_slug . '-' . $year, 'private', 'hidden');
                if ($after_old === null || $after_old['status'] !== 'private') {
                    $undo_old();
                    return self::refused(array('The current product couldn\'t be made private, so nothing changed.'), $rev);
                }
                $retired = $after_old['slug'];
                self::clean_old_slug(array($old_id), $old_slug);

                // 5. The new product takes the old address and is published (still hidden).
                $after_new = self::move_product($new_id, $old_slug, 'publish', null);
                self::clean_old_slug(array($old_id, $new_id), $old_slug);
                if ($after_new === null || $after_new['slug'] !== $old_slug || $after_new['status'] !== 'publish') {
                    $undo_new();
                    $undo_old();
                    return self::refused(array('The address couldn\'t be moved, so nothing changed.'), $rev);
                }

                // 6. team_oversight_programs first (B15).
                if (!TeamOversight_Programs::set_current_product($key, $new_id)) {
                    $undo_new();
                    $undo_old();
                    return self::refused(array('The program\'s product couldn\'t be changed, so nothing changed.'), $rev);
                }

                // 7. Then the setup.
                $past = $setup['past_products'];
                $past[] = array('product_id' => $old_id, 'kind' => $kind, 'weekday' => $weekday, 'years' => self::product_years($old_id, $kind, $weekday));
                $name = self::program_name($key, $program);
                $message = $name . ': switched from #' . $old_id . ' ' . self::product_title($old_id) . ' (now private at /product/' . $retired . '/) to #' . $new_id . ' '
                    . self::product_title($new_id) . ' (published at /product/' . $old_slug . '/). Bookings closed.';
                $saved = self::save_locked($key, $program, array(
                    'prepared_product' => 0,
                    'past_products' => $past,
                    'last_switch' => array(
                        'from' => $old_id,
                        'to' => $new_id,
                        'old_slug' => $old_slug,
                        'at' => current_time('mysql'),
                        'old_status' => $old_state['status'],
                        'old_visibility' => $old_vis,
                        'retired_slug' => $retired,
                        'new_slug' => $new_state['slug'],
                    ),
                    'bookings_open' => false,
                ), $expected, $message);
                if (empty($saved['ok'])) {
                    TeamOversight_Programs::set_current_product($key, $old_id);
                    $undo_new();
                    $undo_old();
                    return $saved;
                }
                $saved['message'] = 'Switched to ' . self::product_title($new_id) . '. ' . self::product_title($old_id) . ' is private at /product/' . $retired . '/ and listed as an earlier product. Bookings are closed: tick Bookings open when you are ready.';
                $saved['old_id'] = $old_id;
                $saved['old_slug'] = $old_slug;
                return $saved;
            } finally {
                TeamOversight_Program_Sessions::release_locks($held);
            }
        });
        if (!empty($result['ok'])) {
            $result['links'] = self::links_to_product($result['old_id'], $result['old_slug']);
        }
        return $result;
    }

    /** Whether Switch back is offered: a switch is recorded, its product is current, nothing is prepared and it has no order lines. */
    public static function switch_back_open($key) {
        $key = (string) $key;
        $setup = self::get($key);
        $last = $setup['last_switch'];
        if (empty($last) || intval($setup['prepared_product']) > 0 || !class_exists('TeamOversight_Program_Sessions')) {
            return false;
        }
        $program = TeamOversight_Programs::get_program($key);
        $current = (is_array($program) && isset($program['product_id']) && is_scalar($program['product_id'])) ? intval($program['product_id']) : 0;
        return $current === intval($last['to']) && !TeamOversight_Program_Sessions::has_order_lines($current);
    }

    /**
     * Switch back (design 6.4): while the new product has no order lines in
     * any order, reverses Switch's steps 2 to 4 exactly: the new product a
     * draft at its old address, the old product its address, status and shop
     * visibility back, the program's product the old one, the new one the
     * prepared product again, the switch forgotten. Bookings stay closed.
     */
    public static function switch_back($key, $expected_rev) {
        $key = (string) $key;
        list($program, $expected, $refusal) = self::action_start($key, $expected_rev);
        if ($refusal !== null) {
            return $refusal;
        }
        return self::locked(function () use ($key, $program, $expected) {
            list($all, $rev) = self::fresh_rows($key);
            if ($all === null) {
                return self::refused(array(self::READ_REFUSAL));
            }
            if ($rev !== $expected) {
                return self::refused(array(self::REV_REFUSAL), $rev);
            }
            $setup = self::get($key);
            $last = $setup['last_switch'];
            if (empty($last)) {
                return self::refused(array('There\'s no switch to undo.'), $rev);
            }
            $fresh = self::fresh_program($key);
            $current = (is_array($fresh) && isset($fresh['product_id']) && is_scalar($fresh['product_id'])) ? intval($fresh['product_id']) : 0;
            $from = intval($last['from']);
            $to = intval($last['to']);
            if ($current !== $to) {
                return self::refused(array('The program\'s product has changed since the switch, so it can\'t be switched back.'), $rev);
            }
            if (intval($setup['prepared_product']) > 0) {
                return self::refused(array('Discard the prepared product first.'), $rev);
            }
            if (self::post_state($from) === null) {
                return self::refused(array('The earlier product no longer exists.'), $rev);
            }
            if (TeamOversight_Program_Sessions::has_order_lines($to)) {
                return self::refused(array('The new product has orders, so the switch can\'t be undone.'), $rev);
            }
            $held = TeamOversight_Program_Sessions::take_locks(array($from, $to));
            if ($held === null) {
                return self::refused(array(TeamOversight_Program_Sessions::LOCK_BUSY), $rev);
            }
            try {
                $to_state = self::post_state($to);
                $from_state = self::post_state($from);
                $to_product = wc_get_product($to);
                $from_product = wc_get_product($from);
                if ($to_state === null || $from_state === null || !$to_product || !$from_product) {
                    return self::refused(array('A product couldn\'t be read, so nothing changed.'), $rev);
                }
                $to_vis = $to_product->get_catalog_visibility('edit');
                $from_vis = $from_product->get_catalog_visibility('edit');
                $old_slug = (string) $last['old_slug'];
                $old_status = isset($last['old_status']) ? $last['old_status'] : 'publish';
                $old_vis = isset($last['old_visibility']) ? $last['old_visibility'] : 'visible';
                $new_slug = isset($last['new_slug']) ? $last['new_slug'] : '';
                $undo_to = function () use ($to, $to_state, $to_vis) {
                    self::move_product($to, $to_state['slug'], $to_state['status'], $to_vis);
                };
                $undo_from = function () use ($from, $from_state, $from_vis, $to, $old_slug) {
                    self::move_product($from, $from_state['slug'], $from_state['status'], $from_vis);
                    self::clean_old_slug(array($from, $to), $old_slug);
                };

                $after_to = self::move_product($to, $new_slug, 'draft', null);
                if ($after_to === null || $after_to['status'] !== 'draft') {
                    $undo_to();
                    return self::refused(array('The new product couldn\'t be made a draft again, so nothing changed.'), $rev);
                }
                $after_from = self::move_product($from, $old_slug, $old_status, $old_vis);
                self::clean_old_slug(array($from, $to), $old_slug);
                if ($after_from === null || $after_from['slug'] !== $old_slug || $after_from['status'] !== $old_status) {
                    $undo_from();
                    $undo_to();
                    return self::refused(array('The address couldn\'t be moved back, so nothing changed.'), $rev);
                }
                if (!TeamOversight_Programs::set_current_product($key, $from)) {
                    $undo_from();
                    $undo_to();
                    return self::refused(array('The program\'s product couldn\'t be changed, so nothing changed.'), $rev);
                }
                $past = array();
                foreach ($setup['past_products'] as $entry) {
                    if (intval($entry['product_id']) !== $from) {
                        $past[] = $entry;
                    }
                }
                $name = self::program_name($key, $program);
                $message = $name . ': switched back to #' . $from . ' ' . self::product_title($from) . '; ' . self::product_title($to) . ' is the prepared product again.';
                $saved = self::save_locked($key, $program, array(
                    'prepared_product' => $to,
                    'past_products' => $past,
                    'last_switch' => array(),
                    'bookings_open' => false,
                ), $expected, $message);
                if (empty($saved['ok'])) {
                    TeamOversight_Programs::set_current_product($key, $to);
                    $undo_from();
                    $undo_to();
                    return $saved;
                }
                $saved['message'] = 'Switched back to ' . self::product_title($from) . '. ' . self::product_title($to) . ' is the prepared product again (a draft). Bookings stay closed.';
                return $saved;
            } finally {
                TeamOversight_Program_Sessions::release_locks($held);
            }
        });
    }

    /**
     * Design 6.4 step 6 (read only, at most 50 rows): published or private
     * pages, posts, patterns and templates whose content links to the old
     * product (its address or its id), and menu items pointing at it.
     */
    public static function links_to_product($product_id, $slug) {
        global $wpdb;
        $pid = intval($product_id);
        $slug = (string) $slug;
        if ($pid <= 0) {
            return array();
        }
        $rows = array();
        $likes = array(
            '%add-to-cart=' . $pid . '%',
            '%product_id="' . $pid . '"%',
            '%[product id="' . $pid . '"%',
            '%?p=' . $pid . '%',
        );
        if ($slug !== '') {
            $likes[] = '%/product/' . $wpdb->esc_like($slug) . '%';
        }
        $where = implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s'));
        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
            WHERE post_type IN ('page', 'post', 'wp_block', 'wp_template', 'wp_template_part') AND post_status IN ('publish', 'private')
                AND ({$where})
            ORDER BY ID LIMIT 50
        ", $likes));
        $id = preg_quote((string) $pid, '/');
        foreach ((array) $posts as $post) {
            $why = array();
            $content = (string) $post->post_content;
            if ($slug !== '' && preg_match('/\/product\/' . preg_quote($slug, '/') . '(?![a-z0-9\-])/i', $content)) {
                $why[] = 'its address';
            }
            if (preg_match('/add-to-cart=' . $id . '(?!\d)|product_id="' . $id . '"|\[product id="' . $id . '"|[?&]p=' . $id . '(?!\d)/', $content)) {
                $why[] = 'its id';
            }
            if (empty($why)) {
                continue;
            }
            $edit = get_edit_post_link(intval($post->ID), 'raw');
            $rows[] = array('id' => intval($post->ID), 'title' => (string) $post->post_title !== '' ? (string) $post->post_title : '#' . intval($post->ID), 'type' => (string) $post->post_type, 'why' => implode(' and ', $why), 'edit' => is_string($edit) ? $edit : '');
        }
        $menu = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_menu_item_object_id'
            LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_menu_item_object'
            LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_menu_item_url'
            WHERE p.post_type = 'nav_menu_item'
                AND ((o.meta_value = %s AND t.meta_value = 'product') OR (%s <> '' AND u.meta_value LIKE %s))
            ORDER BY p.ID LIMIT 50
        ", (string) $pid, $slug, '%/product/' . $wpdb->esc_like($slug) . '%'));
        foreach ((array) $menu as $item) {
            if (count($rows) >= 50) {
                break;
            }
            $rows[] = array('id' => intval($item->ID), 'title' => (string) $item->post_title !== '' ? (string) $item->post_title : 'Menu item #' . intval($item->ID), 'type' => 'menu item', 'why' => 'a link to the product', 'edit' => admin_url('nav-menus.php'));
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // 1.85.0: earlier products (design 6.5, 10.1 step 2)
    // ------------------------------------------------------------------

    /** The facts Add an earlier product shows before adding (read only). */
    public static function past_product_facts($product_id, $kind, $key) {
        global $wpdb;
        $pid = intval($product_id);
        $facts = array('id' => $pid, 'exists' => false, 'title' => '', 'kind' => (string) $kind, 'problem' => '', 'owner' => '', 'paid_lines' => 0, 'years' => array());
        $post = $pid > 0 ? get_post($pid) : null;
        if (!$post || $post->post_type !== 'product') {
            $facts['problem'] = 'Product #' . $pid . ' doesn\'t exist.';
            return $facts;
        }
        $facts['exists'] = true;
        $facts['title'] = self::product_title($pid);
        if (!in_array($kind, array('legacy_date', 'month', 'paid_date'), true)) {
            $facts['problem'] = 'Choose how its dates are read: date labels, months or dated by payment.';
        } else {
            $facts['problem'] = self::product_shape_problem($pid, $kind, $key);
        }
        $facts['owner'] = self::owner_of_product($pid, $key);
        $program = TeamOversight_Programs::get_program($key);
        $setup = self::get($key);
        if ((is_array($program) && isset($program['product_id']) && intval($program['product_id']) === $pid) || intval($setup['prepared_product']) === $pid) {
            $facts['problem'] = 'Product #' . $pid . ' is this program\'s current or prepared product.';
        }
        foreach ($setup['past_products'] as $past) {
            if (intval($past['product_id']) === $pid) {
                $facts['problem'] = 'Product #' . $pid . ' is already one of this program\'s earlier products.';
            }
        }
        if ($facts['problem'] === '' && $post->post_status === 'publish') {
            $facts['problem'] = self::ON_SALE_REFUSAL;
        }
        $paid = "FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta prod
                ON prod.order_item_id = oi.order_item_id AND prod.meta_key = '_product_id' AND prod.meta_value = %d
            JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = oi.order_id
            WHERE oi.order_item_type = 'line_item' AND os.parent_id = 0 AND os.status IN ('wc-processing', 'wc-completed')";
        $facts['paid_lines'] = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT oi.order_item_id) ' . $paid, $pid)));
        $years = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT YEAR(os.date_created) AS y ' . $paid . ' ORDER BY y DESC LIMIT ' . self::MAX_YEARS, $pid));
        $years = array_map('intval', (array) $years);
        sort($years);
        $facts['years'] = $years;
        return $facts;
    }

    /**
     * Add an earlier product (design 6.5): kind legacy_date, month or
     * paid_date (a dated earlier product comes only from Switch). save()
     * decides: validate() (the shape, the years, not twice) and
     * merged_problems() (not the current or prepared product, not another
     * program's). A published product is refused first: an earlier product
     * is never purchasable, so adding one that is on sale would stop it
     * selling without a word.
     */
    public static function add_past_product($key, $product_id, $kind, $weekday, array $years, $expected_rev) {
        $key = (string) $key;
        if (!current_user_can('manage_options')) {
            return self::refused(array('Only the committee can change a program\'s products.'));
        }
        $program = TeamOversight_Programs::get_program($key);
        if (!is_array($program)) {
            return self::refused(array('That program isn\'t configured, so nothing was changed.'));
        }
        if (!in_array($kind, array('legacy_date', 'month', 'paid_date'), true)) {
            return self::refused(array('Choose how its dates are read: date labels, months or dated by payment.'));
        }
        $pid = intval($product_id);
        if (get_post_type($pid) === 'product' && get_post_status($pid) === 'publish') {
            return self::refused(array(self::ON_SALE_REFUSAL));
        }
        $list = self::get($key)['past_products'];
        $list[] = array('product_id' => $pid, 'kind' => $kind, 'weekday' => $weekday, 'years' => array_values($years));
        $clean_years = array();
        foreach ($years as $year) {
            $clean_years[] = is_scalar($year) ? preg_replace('/\D/', '', (string) $year) : '';
        }
        $message = self::program_name($key, $program) . ': earlier product #' . $pid . ' (' . $kind . ', ' . implode('/', $clean_years) . ') added.';
        $result = self::save($key, array('past_products' => $list), $expected_rev, $message);
        if (!empty($result['ok'])) {
            $result['message'] = 'Added #' . $pid . ' ' . self::product_title($pid) . ' as an earlier product.';
        }
        return $result;
    }

    /**
     * Remove an earlier product (B19, the way back of 10.1 step 2). A dated
     * one (made for this program, retired by a Switch) stays in its history,
     * and the product the last switch retired stays while Switch back is
     * possible.
     */
    public static function remove_past_product($key, $product_id, $expected_rev) {
        $key = (string) $key;
        if (!current_user_can('manage_options')) {
            return self::refused(array('Only the committee can change a program\'s products.'));
        }
        $program = TeamOversight_Programs::get_program($key);
        if (!is_array($program)) {
            return self::refused(array('That program isn\'t configured, so nothing was changed.'));
        }
        $pid = intval($product_id);
        $setup = self::get($key);
        $list = array();
        $found = null;
        foreach ($setup['past_products'] as $past) {
            if (intval($past['product_id']) === $pid && $found === null) {
                $found = $past;
                continue;
            }
            $list[] = $past;
        }
        if ($found === null) {
            return self::refused(array('That product isn\'t one of this program\'s earlier products.'));
        }
        if ($found['kind'] === 'dated') {
            return self::refused(array('A session product made for this program stays in its history.'));
        }
        if (!empty($setup['last_switch']) && intval($setup['last_switch']['from']) === $pid) {
            $current = isset($program['product_id']) && is_scalar($program['product_id']) ? intval($program['product_id']) : 0;
            if ($current === intval($setup['last_switch']['to'])) {
                return self::refused(array('The last switch retired that product, so it stays while Switch back is possible.'));
            }
        }
        $message = self::program_name($key, $program) . ': earlier product #' . $pid . ' removed.';
        $result = self::save($key, array('past_products' => $list), $expected_rev, $message);
        if (!empty($result['ok'])) {
            $result['message'] = 'Removed #' . $pid . ' ' . self::product_title($pid) . ' from the earlier products.';
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // 1.85.0: the confirm views
    // ------------------------------------------------------------------

    private static function render_switch_confirm($key, $base_url, $rev) {
        $back = '<a class="button" href="' . esc_url(remove_query_arg('confirm', $base_url)) . '">Back</a>';
        $facts = self::switch_facts($key);
        $name = self::program_name($key);
        $out = '<div class="murvc-setup-box"><h2>Switch ' . esc_html($name) . ($facts['new'] !== null ? ' to ' . esc_html($facts['new']['title']) : '') . '?</h2>';
        if ($facts['old'] !== null && $facts['old']['last'] === '') {
            $out .= '<p class="murvc-setup-note">No session dates were found on the current product.</p>';
        }
        if (!empty($facts['problems'])) {
            $out .= '<div class="notice notice-error inline"><ul style="margin-left: 18px; list-style: disc;">';
            foreach ($facts['problems'] as $problem) {
                $out .= '<li>' . esc_html($problem) . '</li>';
            }
            return $out . '</ul></div><p>' . $back . '</p></div>';
        }
        $old = $facts['old'];
        $new = $facts['new'];
        $weekday = $facts['weekday'] > 0 ? TeamOversight_Program_Sessions::day_name($facts['weekday']) : 'no weekday';
        $out .= '<p class="murvc-setup-confirm-lead"><strong>' . esc_html('This makes ' . $new['title'] . ' the program\'s product at /product/' . $old['slug'] . '/ and retires ' . $old['title'] . '; nothing can be booked until you tick Bookings open.') . '</strong></p>'
            . '<ol>'
            . '<li>' . esc_html($old['title']) . ' (#' . intval($old['id']) . ') gets the address /product/' . esc_html($old['retired_slug']) . '/, becomes private and hidden from the shop. Nothing else on it changes: no attribute, label, price, stock, variation or membership field; its orders, refunds and reports are untouched.</li>'
            . '<li>' . esc_html($new['title']) . ' (#' . intval($new['id']) . ', ' . intval($new['sessions']) . ' session' . ($new['sessions'] === 1 ? '' : 's') . ') takes /product/' . esc_html($old['slug']) . '/, is published and stays hidden from the shop.</li>'
            . '<li>The program\'s product becomes #' . intval($new['id']) . ', and #' . intval($old['id']) . ' joins its earlier products as ' . esc_html(self::kind_words($old['kind'])) . ', ' . esc_html($weekday) . ', ' . esc_html(implode(', ', $facts['years'])) . '.</li>'
            . '<li>Bookings are closed. Tick Bookings open on the Booking box when you are ready.</li>'
            . '</ol>'
            . self::form_open($key, 'do', 'switch', $rev)
            . '<p><button type="submit" class="button button-primary">Switch now</button> ' . $back . '</p></form>'
            . '<p class="murvc-setup-note">Switch back stays possible until the new product\'s first order.</p>';
        return $out . '</div>';
    }

    private static function render_switch_back_confirm($key, $base_url, $rev) {
        $back = '<a class="button" href="' . esc_url(remove_query_arg('confirm', $base_url)) . '">Back</a>';
        $setup = self::get($key);
        $last = $setup['last_switch'];
        $out = '<div class="murvc-setup-box"><h2>Switch ' . esc_html(self::program_name($key)) . ' back?</h2>';
        if (!self::switch_back_open($key)) {
            return $out . '<p>Switch back isn\'t possible now: there is no switch to undo, a product is being prepared, or the new product has orders.</p><p>' . $back . '</p></div>';
        }
        $from = intval($last['from']);
        $to = intval($last['to']);
        $out .= '<p class="murvc-setup-confirm-lead"><strong>' . esc_html('This puts ' . self::product_title($from) . ' back on sale at /product/' . $last['old_slug'] . '/ and makes ' . self::product_title($to) . ' the prepared product again.') . '</strong></p>'
            . '<ol>'
            . '<li>' . esc_html(self::product_title($to)) . ' (#' . $to . ') goes back to being the prepared product, a draft.</li>'
            . '<li>' . esc_html(self::product_title($from)) . ' (#' . $from . ') gets /product/' . esc_html($last['old_slug']) . '/ back, ' . esc_html(isset($last['old_status']) ? $last['old_status'] : 'publish') . ' and ' . esc_html(isset($last['old_visibility']) ? $last['old_visibility'] : 'visible') . ' in the shop, as before the switch, and is the program\'s product again.</li>'
            . '<li>It leaves the earlier products. Bookings stay closed.</li>'
            . '</ol>'
            . self::form_open($key, 'do', 'switch_back', $rev)
            . '<p><button type="submit" class="button button-primary">Switch back now</button> ' . $back . '</p></form>';
        return $out . '</div>';
    }

    private static function render_add_past_confirm($key, $base_url, $rev, $product_id, $kind) {
        $back = '<a class="button" href="' . esc_url(remove_query_arg(array('add_past', 'kind'), $base_url)) . '">Back</a>';
        $facts = self::past_product_facts($product_id, $kind, $key);
        $out = '<div class="murvc-setup-box"><h2>Add an earlier product</h2>';
        if (!$facts['exists']) {
            return $out . '<p>' . esc_html($facts['problem']) . '</p><p>' . $back . '</p></div>';
        }
        $out .= '<p class="murvc-setup-confirm-lead"><strong>' . esc_html('This adds ' . $facts['title'] . '\'s paid bookings to the program\'s history (New here and the stats); nothing about the product changes.') . '</strong></p>'
            . '<p><strong>#' . intval($facts['id']) . ' ' . esc_html($facts['title']) . '</strong>, read as ' . esc_html(self::kind_words($kind)) . '. '
            . intval($facts['paid_lines']) . ' paid order line' . ($facts['paid_lines'] === 1 ? '' : 's') . '.</p>';
        if ($facts['owner'] !== '') {
            $other = TeamOversight_Programs::get_program($facts['owner']);
            $out .= '<p class="notice notice-error inline">It already belongs to ' . esc_html(is_array($other) && isset($other['name']) ? (string) $other['name'] : $facts['owner']) . '.</p>';
        }
        if ($facts['problem'] !== '') {
            return $out . '<p class="notice notice-error inline">' . esc_html($facts['problem']) . '</p><p>' . $back . '</p></div>';
        }
        $program = TeamOversight_Programs::get_program($key);
        $weekday = is_array($program) ? intval(TeamOversight_Programs::get_program_weekday($program)) : 0;
        $years = !empty($facts['years']) ? implode(', ', $facts['years']) : wp_date('Y');
        $out .= self::form_open($key, 'do', 'add_past', $rev)
            . '<input type="hidden" name="product_id" value="' . intval($facts['id']) . '"><input type="hidden" name="kind" value="' . esc_attr($kind) . '">'
            . '<p><label>Years its bookings belong to <input type="text" name="years" value="' . esc_attr($years) . '" size="20"></label> <span class="murvc-setup-note">Suggested from its paid lines; 1 to ' . self::MAX_YEARS . ' years.</span></p>'
            . '<p><label>Weekday <select name="weekday"><option value="0">None</option>';
        for ($d = 1; $d <= 7; $d++) {
            $out .= '<option value="' . $d . '"' . selected($weekday, $d, false) . '>' . esc_html(TeamOversight_Program_Sessions::day_name($d)) . '</option>';
        }
        $out .= '</select></label></p><p><button type="submit" class="button button-primary">Add it</button> ' . $back . '</p></form>';
        return $out . '</div>';
    }
}
