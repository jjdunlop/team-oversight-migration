<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * VVL Oversight → How it works (1.70.2, admins only: manage_options).
 *
 * A plain-English guide for the committee checking that the plugin behaves
 * as agreed, and for whoever maintains it next. Three parts are live, never
 * typed in:
 *  - section 2 reads the selected season's settings (season dates, payment
 *    window, late-join cutoff, fee release, fee matrix);
 *  - section 6's worked examples are priced by the real engine from those
 *    settings: hypothetical team places go through
 *    TeamOversight_Fees::fee_breakdown_for_class() (the same
 *    timeline_stretches() / price_stretches() as every invoice) and
 *    TeamOversight_Fee_Timeline::schedule() (the due schedule every 2027+
 *    screen uses), the standing through
 *    TeamOversight_Payments::standing_from_invoices();
 *  - section 7's calculator asks an admin AJAX endpoint (ajax_calculator(),
 *    nonce + manage_options) that prices the same way.
 * Everything is computed in memory: this page writes nothing. Keep it that
 * way, and keep its examples and calculator engine-computed: never put a
 * hard-coded amount or date in them.
 *
 * Sections 1, 3, 4, 5, 8 and 9 are prose about what the code does; when a
 * rule changes, change the wording here in the same commit.
 */
class TeamOversight_Help_Page {

    /** The admin page slug (VVL Oversight → How it works). */
    const PAGE_SLUG = 'team-oversight-help';

    /** The calculator's admin AJAX action and its nonce action. */
    const AJAX_ACTION = 'murvc_help_calculator';
    const NONCE_ACTION = 'murvc_help_calculator';

    /** The roles the calculator offers (the roles a team place can have; supporter is a matrix column, not a place). */
    const CALC_ROLES = array('playing_member', 'training_only', 'coach', 'assistant_coach', 'team_manager');

    /** The largest amount paid the calculator accepts. */
    const MAX_PAID = 100000;

    private static $hooks_registered = false;

    /** One TeamOversight_Fees per request (each new one re-adds its own hooks); its rate cache is reset per render. */
    private static $fees = null;

    /** Add the calculator's AJAX hook, once per request (team_oversight_init() calls this). The menu entry lives in TeamOversight_Admin::add_admin_menu(). */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'ajax_calculator'));
    }

    /** The shared TeamOversight_Fees, with a fresh matrix cache (so a matrix saved since the last call is read). */
    private static function fees() {
        if (!(self::$fees instanceof TeamOversight_Fees)) {
            self::$fees = TeamOversight_Fees::shared();
        }
        self::$fees->enable_rate_cache();
        return self::$fees;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private static function money($amount) {
        return '$' . number_format((float) $amount, 2);
    }

    /** "1 Mar 2027". */
    private static function d($date) {
        return TeamOversight_Fee_Timeline::format_date($date);
    }

    /** "1 Mar" (chart labels). */
    private static function short_date($date) {
        $valid = TeamOversight_Fee_Timeline::parse_date($date);
        return $valid === null ? (string) $date : DateTime::createFromFormat('!Y-m-d', $valid, new DateTimeZone('UTC'))->format('j M');
    }

    private static function role_text($role) {
        return strtolower(TeamOversight_Fees::role_label($role));
    }

    /** A link to an admin page of the plugin. */
    private static function admin_link($page, $label, $args = array()) {
        $url = admin_url('admin.php?page=' . rawurlencode($page));
        foreach ($args as $key => $value) {
            $url .= '&' . rawurlencode($key) . '=' . rawurlencode((string) $value);
        }
        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private static function fail($message) {
        return array('ok' => false, 'error' => (string) $message);
    }

    // ------------------------------------------------------------------
    // Seasons
    // ------------------------------------------------------------------

    /**
     * The seasons the picker offers, newest first: every season with
     * dates, every season created on Configuration, this year and next.
     */
    public static function seasons() {
        $found = array();
        $dates = get_option('team_oversight_season_dates', array());
        foreach (is_array($dates) ? array_keys($dates) : array() as $season) {
            $season = (string) $season;
            if (preg_match('/^\d{4}$/D', $season) === 1 && TeamOversight_Fees::get_season_dates($season)) {
                $found[] = $season;
            }
        }
        $created = get_option('team_oversight_created_seasons', array());
        foreach (is_array($created) ? $created : array() as $season) {
            if (is_scalar($season) && preg_match('/^\d{4}$/D', (string) $season) === 1) {
                $found[] = (string) $season;
            }
        }
        $year = intval(current_time('Y'));
        $found[] = (string) $year;
        $found[] = (string) ($year + 1);
        $found = array_values(array_unique($found));
        rsort($found, SORT_STRING);
        return $found;
    }

    /** Fee-timeline seasons (2027+) with season dates, oldest first: what the examples and the calculator can price. */
    public static function priceable_seasons() {
        $out = array();
        foreach (self::seasons() as $season) {
            if (TeamOversight_Fees::timeline_applies($season) && TeamOversight_Fees::get_payment_window($season)) {
                $out[] = $season;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * The season the page opens on: the current or next fee-timeline
     * season with dates (the first whose end is after today), else the
     * latest one with dates, else this year or 2027, whichever is later.
     */
    public static function default_season() {
        $today = current_time('Y-m-d');
        $seasons = self::priceable_seasons();
        foreach ($seasons as $season) {
            $dates = TeamOversight_Fees::get_season_dates($season);
            if ((string) $dates['end'] > $today) {
                return $season;
            }
        }
        if ($seasons) {
            return $seasons[count($seasons) - 1];
        }
        return (string) max(intval(current_time('Y')), TeamOversight_Fees::FEE_TIMELINE_FROM);
    }

    /** ?season= when it is a four-digit season, else default_season(). */
    private static function requested_season() {
        $season = (isset($_GET['season']) && is_string($_GET['season'])) ? sanitize_text_field(wp_unslash($_GET['season'])) : '';
        return preg_match('/^\d{4}$/D', $season) === 1 ? $season : self::default_season();
    }

    /** The season's teams for the examples and the calculator: code => name (bare codes). */
    private static function teams($season) {
        $database = new TeamOversight_Database();
        $teams = $database->get_teams($season);
        return is_array($teams) ? $teams : array();
    }

    // ------------------------------------------------------------------
    // The engine, in memory
    // ------------------------------------------------------------------

    /**
     * Hypothetical team places as the fee engine reads them. $places: list
     * of array(team, role, start, stop (null or '' = still on)).
     */
    private static function rows($season, $places) {
        $rows = array();
        $i = 0;
        foreach ((array) $places as $place) {
            $i++;
            $stop = isset($place['stop']) && $place['stop'] !== '' ? (string) $place['stop'] : null;
            $rows[] = (object) array(
                'id' => -$i, 'user_id' => 0, 'email' => '', 'season' => (string) $season,
                'team' => (string) $place['team'], 'role' => (string) $place['role'], 'registration_status' => 'active',
                'start_date' => (string) $place['start'], 'start_missing' => false, 'end_date' => $stop,
                'is_active' => 1, 'created_ts' => 0,
            );
        }
        return $rows;
    }

    /**
     * A hypothetical invoice for $fee with $paid paid, as the database
     * would hold it (outstanding = fee - paid, never below $0, so money
     * paid above the fee is credit). No id, account or email, so the
     * engine looks nothing up for it; created_ts 0 and no fee_raised_at, so
     * its behind clock assumes the fee and every change were recorded on
     * time (only the payment window's own floor applies).
     */
    private static function invoice($season, $fee, $paid) {
        // An object in memory, never saved: built property by property so it
        // doesn't read like a database write of invoice_amount.
        $invoice = new stdClass();
        $invoice->id = 0;
        $invoice->user_id = 0;
        $invoice->email = '';
        $invoice->season = (string) $season;
        $invoice->invoice_amount = round($fee, 2);
        $invoice->outstanding_amount = max(0.0, round($fee - $paid, 2));
        $invoice->created_ts = 0;
        $invoice->fee_raised_at = null;
        $invoice->payment_arrangement = 0;
        $invoice->payment_arrangement_at = null;
        $invoice->payment_arrangement_note = null;
        $invoice->payment_arrangement_by = null;
        return $invoice;
    }

    /**
     * Price hypothetical team places for a person of fee class $class in
     * $season, and schedule what they owe, through the real engine,
     * writing nothing. $today: the day to judge what is due and overdue
     * (default the site's today). Returns ok/error, and with ok: fee,
     * paid, credit, outstanding, breakdown (stretches as plain rows),
     * missing (a role had no matrix amount), schedule (schedule() on
     * $today), due (the due-by dates), standing / status / pill (the fee
     * standing and the coaches' pill, as of $today), chart (the due curve's
     * geometry) and notes.
     */
    public static function compute($season, $class, $places, $paid = 0.0, $today = null) {
        $season = (string) $season;
        $today = (is_string($today) && TeamOversight_Fee_Timeline::parse_date($today) !== null) ? $today : current_time('Y-m-d');
        // An example worked out as at another day (the backdated stop) must
        // not call that day "today".
        $real_today = ($today === current_time('Y-m-d'));
        if (!TeamOversight_Fees::timeline_applies($season)) {
            return self::fail(sprintf('The worked examples and the calculator follow the fee timeline, which starts with the %d season.', TeamOversight_Fees::FEE_TIMELINE_FROM));
        }
        $dates = TeamOversight_Fees::get_season_dates($season);
        $window = TeamOversight_Fees::get_payment_window($season);
        if (!$dates || !$window || (string) $dates['end'] <= (string) $dates['start']) {
            return self::fail(sprintf('The %s season has no dates yet, so no fee or due date can be worked out. Set them on Configuration.', $season));
        }
        $rows = self::rows($season, $places);
        $breakdown = self::fees()->fee_breakdown_for_class($season, $rows, $class);
        $priced = false;
        foreach ($breakdown['stretches'] as $s) {
            if ($s['top']) {
                $priced = true;
            }
        }
        if (!empty($breakdown['stretches']) && !$priced) {
            // Name the cells the engine actually looked up: the class per
            // team (JPL/YSL for a junior on a junior-league team) and the
            // column each role is charged at (coaches at supporter).
            $cells = array();
            foreach ($breakdown['stretches'] as $s) {
                foreach ((array) (isset($s['buckets']) ? $s['buckets'] : array()) as $b) {
                    if (!array_key_exists('rate', $b) || $b['rate'] !== null) {
                        continue;
                    }
                    $charged = isset($b['charged_as']) ? (string) $b['charged_as'] : (string) $b['role'];
                    $cells[] = $b['class'] . ' / ' . self::role_text($charged)
                        . ($charged !== (string) $b['role'] ? ' (' . self::role_text($b['role']) . ' is charged at that amount)' : '');
                }
            }
            $cells = array_values(array_unique($cells));
            return self::fail(sprintf('The %s fee matrix has no amount for %s, so no fee can be worked out. Fill in the blank cell%s on Configuration.', $season, $cells ? implode(' or ', $cells) : $class, count($cells) === 1 ? '' : 's'));
        }

        $fee = round(floatval($breakdown['total']), 2);
        $paid = round(max(0.0, floatval($paid)), 2);
        $invoice = self::invoice($season, $fee, $paid);
        $schedule = TeamOversight_Fee_Timeline::schedule($invoice, $rows, $today);
        $invoice->schedule = $schedule;
        $standing = TeamOversight_Payments::standing_from_invoices(array('user_id' => 0, 'emails' => array('')), array($invoice), $season, null, strtotime($today));

        // The due-by dates: their window, halfway, paid in full by, the stop, today.
        $marks = array();
        $add = function ($date, $label) use (&$marks) {
            if ($date === null || $date === '') {
                return;
            }
            $marks[$date] = isset($marks[$date]) ? $marks[$date] . '; ' . $label : $label;
        };
        $w0 = $schedule['window_from'];
        $w1 = $schedule['window_to'];
        $stop = $schedule['stopped_on'];
        if ($w0 !== null) {
            if ($w1 <= $w0) {
                $add($w0, 'their first start, on or after paid in full by: all of it falls due that day');
            } else {
                $add($w0, $w0 > $window['from'] ? 'their window opens (their first start)' : 'their window opens (fees due from)');
            }
            if ($w1 > $w0) {
                $add(TeamOversight_Fee_Timeline::add_days($w0, intdiv(TeamOversight_Fee_Timeline::days_between($w0, $w1), 2)), 'halfway through their window');
                $add($w1, 'paid in full by');
            }
        }
        if ($stop !== null) {
            $add($stop, 'their last place stops: the rest falls due');
        }
        $add($today, $real_today ? 'today' : 'the day these figures are worked out for');
        ksort($marks, SORT_STRING);
        $due = array();
        foreach ($marks as $date => $label) {
            $at = TeamOversight_Fee_Timeline::schedule($invoice, $rows, $date);
            $due[] = array(
                'date' => $date, 'label' => ucfirst($label), 'amount' => $at['due_today'],
                'pct' => $at['fraction'] === null ? 0 : (int) round(floatval($at['fraction']) * 100),
                'fraction' => $at['fraction'] === null ? 0.0 : floatval($at['fraction']),
            );
        }

        $notes = array();
        if ($breakdown['missing']) {
            $notes[] = 'A role here has no amount in the ' . $season . ' fee matrix, so it was left out of the "highest place" pick and the fee may be too low. Fill in the blank cells on Configuration.';
        }
        if (empty($breakdown['stretches'])) {
            $notes[] = 'No place falls inside the season dates, so there is nothing to charge.';
        }
        // Judged on the day the figures are for, like fees_released() is on today.
        $release = (string) TeamOversight_Fees::get_fee_release_date($season);
        if ($release !== '' && $release > $today) {
            $notes[] = $season . ' fees are not released until ' . self::d($release) . ': nothing is billed before then, and this is what would be billed from then.';
        }

        return array(
            'ok' => true, 'error' => '', 'season' => $season, 'class' => (string) $class, 'today' => $today,
            'places' => $places, 'rows' => $rows,
            'fee' => $fee, 'paid' => $paid, 'credit' => max(0.0, round($paid - $fee, 2)), 'outstanding' => $invoice->outstanding_amount,
            'breakdown' => self::breakdown_rows($breakdown), 'missing' => (bool) $breakdown['missing'],
            'schedule' => $schedule, 'due' => $due,
            'standing' => $standing, 'status' => TeamOversight_Payments::describe_standing($standing),
            'pill' => TeamOversight_Payments::standing_pill_parts($standing, $season),
            'chart' => self::chart($season, $invoice, $rows, $schedule, $today, $due, $real_today),
            'notes' => $notes,
        );
    }

    /** fee_breakdown()'s stretches as plain rows (the Member Lookup card's columns), for the page and the calculator. */
    private static function breakdown_rows($breakdown) {
        $rows = array();
        foreach ($breakdown['stretches'] as $s) {
            $places = array();
            foreach ($s['buckets'] as $b) {
                $text = trim($b['team'] . ' ' . self::role_text($b['role'])) . ' (' . $b['class'] . ')'
                    . ($b['charged_as'] !== $b['role'] ? ', charged as ' . self::role_text($b['charged_as']) : '')
                    . ': ' . ($b['rate'] === null ? 'no amount in the fee matrix' : self::money($b['rate']));
                $top = $s['top'] && count($s['buckets']) > 1 && $b['team'] === $s['top']['team'] && $b['role'] === $s['top']['role'];
                $places[] = array('text' => $text, 'top' => $top);
            }
            $dated = $breakdown['has_dates'] && !empty($s['to']);
            $rows[] = array(
                'from' => self::d($s['from']),
                'last' => $dated ? self::d(TeamOversight_Fee_Timeline::add_days($s['to'], -1)) : '—',
                'days' => $breakdown['has_dates'] ? (string) intval($s['days']) : '—',
                'places' => $places,
                'rate' => $s['top'] ? self::money($s['rate']) : 'none',
                'amount' => self::money($s['amount']),
            );
        }
        return array(
            'rows' => $rows,
            'total' => self::money($breakdown['total']),
            'foot' => $breakdown['has_dates']
                ? 'Each stretch is its rate × its days ÷ ' . intval($breakdown['season_days']) . ' season days, added up and rounded once'
                : 'No season dates, so the latest place\'s full rate (no pro-rata)',
        );
    }

    /**
     * The due curve's geometry (shared by the page's SVG and the
     * calculator's script, so both draw the same thing): the share of the
     * fee schedule() has due on each day across the season dates, sampled
     * weekly and on each side of every bend (their window, the stop,
     * today), with the season's payment window shaded, a line for today
     * when it falls inside the season, and a dot at each due-by date.
     */
    private static function chart($season, $invoice, $rows, $schedule, $today, $due, $real_today = true) {
        $TL = 'TeamOversight_Fee_Timeline';
        $dates = TeamOversight_Fees::get_season_dates($season);
        $window = TeamOversight_Fees::get_payment_window($season);
        $start = $dates['start'];
        $end = $dates['end'];
        $total = max(1, $TL::days_between($start, $end));
        $w = 640;
        $h = 210;
        $l = 46;
        $r = 16;
        $t = 24;
        $b = 40;
        $pw = $w - $l - $r;
        $ph = $h - $t - $b;
        $x = function ($date) use ($TL, $start, $total, $l, $pw) {
            $days = max(0, min($total, $TL::days_between($start, $date)));
            return round($l + $pw * $days / $total, 1);
        };
        $y = function ($fraction) use ($t, $ph) {
            return round($t + $ph * (1 - max(0.0, min(1.0, floatval($fraction)))), 1);
        };

        $samples = array($start => true, $end => true);
        for ($day = 7; $day < $total; $day += 7) {
            $samples[$TL::add_days($start, $day)] = true;
        }
        foreach (array($schedule['window_from'], $schedule['window_to'], $schedule['stopped_on'], $today) as $bend) {
            if ($bend === null) {
                continue;
            }
            foreach (array(-1, 0, 1) as $offset) {
                $date = $TL::add_days($bend, $offset);
                if ($date >= $start && $date <= $end) {
                    $samples[$date] = true;
                }
            }
        }
        ksort($samples, SORT_STRING);
        $points = array();
        foreach (array_keys($samples) as $date) {
            $at = $TL::schedule($invoice, $rows, $date);
            $points[] = $x($date) . ',' . $y($at['fraction'] === null ? 0.0 : $at['fraction']);
        }

        $markers = array();
        foreach ($due as $mark) {
            if ($mark['date'] >= $start && $mark['date'] <= $end) {
                $markers[] = array(
                    'x' => $x($mark['date']), 'y' => $y($mark['fraction']),
                    'title' => self::d($mark['date']) . ' (' . strtolower($mark['label']) . '): ' . self::money($mark['amount']) . ' due (' . $mark['pct'] . '%)',
                );
            }
        }

        $ticks = array(array('x' => $x($start), 'label' => self::short_date($start), 'anchor' => 'start'));
        if ($window['from'] !== $start && $x($window['from']) - $x($start) >= 56) {
            $ticks[] = array('x' => $x($window['from']), 'label' => self::short_date($window['from']), 'anchor' => 'middle');
        }
        if ($window['to'] !== $end && $x($end) - $x($window['to']) >= 56) {
            $ticks[] = array('x' => $x($window['to']), 'label' => self::short_date($window['to']), 'anchor' => 'middle');
        }
        $ticks[] = array('x' => $x($end), 'label' => self::short_date($end), 'anchor' => 'end');

        $today_mark = null;
        if ($today >= $start && $today <= $end) {
            $tx = $x($today);
            $today_mark = array('x' => $tx, 'label' => $real_today ? 'Today' : 'As at', 'anchor' => $tx > $l + $pw - 40 ? 'end' : ($tx < $l + 40 ? 'start' : 'middle'));
        }

        return array(
            'w' => $w, 'h' => $h,
            'plot' => array('x' => $l, 'y' => $t, 'w' => $pw, 'h' => $ph),
            'window' => array('x' => $x($window['from']), 'w' => max(1.0, $x($window['to']) - $x($window['from'])), 'label' => 'Payment window'),
            'today' => $today_mark,
            'grid' => array(
                array('y' => $y(1.0), 'label' => '100%'),
                array('y' => $y(0.5), 'label' => '50%'),
                array('y' => $y(0.0), 'label' => '0%'),
            ),
            'ticks' => $ticks,
            'points' => implode(' ', $points),
            'markers' => $markers,
            'title' => 'Share of the ' . self::money($invoice->invoice_amount) . ' fee due by each day of the ' . $season . ' season',
            'caption' => 'Season ' . self::d($start) . ' – ' . self::d($end) . '; payment window (shaded) ' . self::d($window['from']) . ' – ' . self::d($window['to'])
                . ($real_today
                    ? ($today_mark ? '; the dashed line is today.' : '; today (' . self::d($today) . ') is outside the season.')
                    : ($today_mark ? '; the dashed line is ' . self::d($today) . ', the day these figures are worked out for.' : '; the day these figures are worked out for (' . self::d($today) . ') is outside the season.')),
        );
    }

    // ------------------------------------------------------------------
    // The worked examples (section 6)
    // ------------------------------------------------------------------

    /**
     * The worked examples for a season, every figure from compute(): ok
     * (false with a reason when the season can't be priced), class (the
     * fee class used: the first person class with a playing member amount
     * in the season's matrix), and items, each with key, title, story,
     * places, paid and result (compute()), or for the late joiner the
     * refusal and the latest start accepted. The dates come from the
     * season's own settings: S/E the season dates, P0/P1 the payment
     * window, N the season's days.
     */
    public static function examples($season) {
        $season = (string) $season;
        $TL = 'TeamOversight_Fee_Timeline';
        if (!TeamOversight_Fees::timeline_applies($season)) {
            return array('ok' => false, 'error' => sprintf('The worked examples follow the fee timeline, which starts with the %d season. %s fees are worked out by the earlier rules (see the note at the end of section 3): pick a season from %d above.', TeamOversight_Fees::FEE_TIMELINE_FROM, $season, TeamOversight_Fees::FEE_TIMELINE_FROM), 'items' => array());
        }
        $dates = TeamOversight_Fees::get_season_dates($season);
        $window = TeamOversight_Fees::get_payment_window($season);
        if (!$dates || !$window || (string) $dates['end'] <= (string) $dates['start']) {
            return array('ok' => false, 'error' => sprintf('The %s season has no dates yet, so there is nothing to work the examples out against. Set them on Configuration.', $season), 'items' => array());
        }
        $fees = self::fees();
        $grid = $fees->get_fee_grid($season);
        $class = null;
        foreach (TeamOversight_Fees::person_fee_classes() as $candidate) {
            if (isset($grid['cells'][$candidate]['playing_member'])) {
                $class = $candidate;
                break;
            }
        }
        if ($class === null) {
            return array('ok' => false, 'error' => sprintf('The %s fee matrix has no playing member amounts yet, so the examples can\'t be worked out. Fill it in on Configuration.', $season), 'items' => array());
        }

        // Two senior teams of the season, the same gender where there are
        // (a junior-league team would change a junior's class).
        $database = new TeamOversight_Database();
        $config = $database->get_teams_config($season);
        $codes = array();
        foreach (array_keys(self::teams($season)) as $code) {
            if (!TeamOversight_Fees::is_junior_league_team((string) $code, $season)) {
                $codes[] = (string) $code;
            }
        }
        $t1 = isset($codes[0]) ? $codes[0] : 'Team A';
        $t2 = isset($codes[1]) ? $codes[1] : 'Team B';
        $gender = isset($config[$t1]['gender']) ? $config[$t1]['gender'] : null;
        foreach (array_slice($codes, 1) as $code) {
            if ($gender !== null && isset($config[$code]['gender']) && $config[$code]['gender'] === $gender) {
                $t2 = $code;
                break;
            }
        }

        $s = $dates['start'];
        $e = $dates['end'];
        $n = $TL::days_between($s, $e);
        $p0 = $window['from'];
        $p1 = $window['to'];
        $joiner = $TL::add_days($p0, intdiv($TL::days_between($p0, $p1), 2));
        $mid = $TL::add_days($s, intdiv($n, 2));
        $second = $TL::add_days($s, intdiv($n, 4));
        if ($second > $joiner) {
            $second = $joiner;
        }
        $late_stop = $TL::add_days($s, intdiv(2 * $n, 3));
        $recorded = $TL::add_days($late_stop, 14);

        $items = array();
        $full = self::compute($season, $class, array(array('team' => $t1, 'role' => 'playing_member', 'start' => $s, 'stop' => null)));
        $items[] = array(
            'key' => 'full', 'title' => 'A full-season player',
            'story' => sprintf('Placed on %s as a playing member from the season start (%s) and still on. They are charged every day of the season, and the fee falls due evenly from fees due from to paid in full by.', $t1, self::d($s)),
            'places' => $full['ok'] ? $full['places'] : array(), 'paid' => 0.0, 'result' => $full,
        );

        $joined = self::compute($season, $class, array(array('team' => $t1, 'role' => 'playing_member', 'start' => $joiner, 'stop' => null)));
        $items[] = array(
            'key' => 'joiner', 'title' => 'A mid-season joiner',
            'story' => sprintf('Joins %s as a playing member on %s, halfway through the payment window. They pay only for the days from their start, and their own schedule starts that day: nothing is due before it, then it falls due evenly up to paid in full by.', $t1, self::d($joiner)),
            'places' => $joined['ok'] ? $joined['places'] : array(), 'paid' => 0.0, 'result' => $joined,
        );

        $stopped = self::compute($season, $class, array(array('team' => $t1, 'role' => 'playing_member', 'start' => $s, 'stop' => $mid)));
        $items[] = array(
            'key' => 'stopped', 'title' => 'Someone who stops early',
            'story' => sprintf('Playing member on %s from %s; an admin records Stopped playing on %s (the first day off the team). They pay up to %s, and once every place has stopped everything for the time played falls due on the stop date.', $t1, self::d($s), self::d($mid), self::d($TL::add_days($mid, -1))),
            'places' => $stopped['ok'] ? $stopped['places'] : array(), 'paid' => 0.0, 'result' => $stopped,
        );

        $two = self::compute($season, $class, array(
            array('team' => $t1, 'role' => 'training_only', 'start' => $s, 'stop' => null),
            array('team' => $t2, 'role' => 'playing_member', 'start' => $second, 'stop' => null),
        ));
        $items[] = array(
            'key' => 'two', 'title' => 'Two teams at once',
            'story' => sprintf('Training only on %s from %s, then also a playing member on %s from %s. Up to %s only the training only place counts; from %s both are held and each day is charged at the higher of the two (in bold in the table). The two never add up.', $t1, self::d($s), $t2, self::d($second), self::d($TL::add_days($second, -1)), self::d($second)),
            'places' => $two['ok'] ? $two['places'] : array(), 'paid' => 0.0, 'result' => $two,
        );

        $full_fee = $full['ok'] ? $full['fee'] : 0.0;
        $backdated = self::compute($season, $class, array(array('team' => $t1, 'role' => 'playing_member', 'start' => $s, 'stop' => $late_stop)), $full_fee, $recorded);
        $items[] = array(
            'key' => 'backdated', 'title' => 'A backdated stop, with credit',
            'story' => sprintf('A full-season player on %s paid their whole fee (%s) early. On %s an admin records that they stopped playing on %s, two weeks earlier. The fee re-prices to the real stop date straight away, the payment is never changed, and what they paid above the new fee shows as credit. The figures below are as at %s.', $t1, self::money($full_fee), self::d($recorded), self::d($late_stop), self::d($recorded)),
            'places' => $backdated['ok'] ? $backdated['places'] : array(), 'paid' => $full_fee, 'result' => $backdated,
            'before_fee' => $full_fee,
        );

        // The late joiner: refused the day after the cutoff; the latest start accepted.
        $late = $TL::add_days($p1, 1);
        $refusal = $TL::validate_start($season, $late);
        $latest = null;
        foreach (array($p1, $TL::add_days($p1, -1)) as $candidate) {
            if ($TL::validate_start($season, $candidate) === null) {
                $latest = $candidate;
                break;
            }
        }
        $latest_result = $latest !== null
            ? self::compute($season, $class, array(array('team' => $t1, 'role' => 'playing_member', 'start' => $latest, 'stop' => null)))
            : null;
        $items[] = array(
            'key' => 'late', 'title' => 'A late joiner, refused at the cutoff',
            'story' => sprintf('The late-join cutoff for %s is %s (paid in full by). Finalise, Accept, bulk accept and Add Assignment refuse a start after it, and so does moving to another team on the Fee timeline card. Here is what they say for a start on %s, and what the latest start they accept costs.', $season, self::d($p1), self::d($late)),
            'places' => $latest !== null ? array(array('team' => $t1, 'role' => 'playing_member', 'start' => $latest, 'stop' => null)) : array(),
            'paid' => 0.0, 'result' => $latest_result, 'late_start' => $late, 'refusal' => $refusal, 'latest' => $latest,
        );

        return array('ok' => true, 'error' => '', 'class' => $class, 'teams' => array($t1, $t2), 'items' => $items);
    }

    // ------------------------------------------------------------------
    // The calculator's endpoint (section 7)
    // ------------------------------------------------------------------

    /**
     * admin-ajax.php?action=murvc_help_calculator: price the posted places
     * in memory through compute() and reply with the figures as text (the
     * page inserts them with textContent). Needs the page's nonce and
     * manage_options; every input is unslashed, sanitised and checked
     * strictly (a fee-timeline season with dates, a person fee class, one
     * or two places on that season's teams in a team-place role, start
     * dates checked by validate_start() as the placing screens do, stop
     * dates after the start, the overlap rule, amount paid 0 to MAX_PAID
     * with at most two decimals). Writes nothing.
     */
    public static function ajax_calculator() {
        $nonce = (isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'Security check failed: reload the page and try again.'), 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }
        $input = self::read_calculator_input();
        if (!$input['ok']) {
            wp_send_json_error(array('message' => $input['error']), 400);
        }
        $result = self::compute($input['season'], $input['class'], $input['places'], $input['paid']);
        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['error']), 400);
        }
        wp_send_json_success(self::result_payload($result));
    }

    /** The calculator's posted fields, checked: ok + season, class, places, paid, or ok false + error. */
    private static function read_calculator_input() {
        $text = function ($key) {
            return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim(sanitize_text_field(wp_unslash($_POST[$key]))) : '';
        };
        $TL = 'TeamOversight_Fee_Timeline';
        $season = $text('season');
        if (preg_match('/^\d{4}$/D', $season) !== 1 || !TeamOversight_Fees::timeline_applies($season)) {
            return self::fail(sprintf('Pick a season from %d (the fee timeline).', TeamOversight_Fees::FEE_TIMELINE_FROM));
        }
        if (!TeamOversight_Fees::get_payment_window($season)) {
            return self::fail(sprintf('The %s season has no dates yet, so no fee or due date can be worked out. Set them on Configuration.', $season));
        }
        $class = $text('fee_class');
        if (!in_array($class, TeamOversight_Fees::person_fee_classes(), true)) {
            return self::fail('Pick a fee class.');
        }
        $teams = self::teams($season);
        $places = array();
        $rows = array();
        foreach (array(1, 2) as $i) {
            $team = $text('team_' . $i);
            if ($i === 2 && $team === '') {
                continue;
            }
            if ($team === '' || !array_key_exists($team, $teams)) {
                return self::fail(sprintf('Place %d: pick a %s team.', $i, $season));
            }
            $role = $text('role_' . $i);
            if (!in_array($role, self::CALC_ROLES, true)) {
                return self::fail(sprintf('Place %d: pick a role.', $i));
            }
            $start = $text('start_' . $i);
            $invalid = $TL::validate_start($season, $start);
            if ($invalid !== null) {
                return self::fail(sprintf('Place %d: %s', $i, $invalid));
            }
            $stop = $text('stop_' . $i);
            if ($stop !== '') {
                if ($TL::parse_date($stop) === null) {
                    return self::fail(sprintf('Place %d: pick a valid stop date, or leave it blank while they are still on.', $i));
                }
                if ($stop <= $start) {
                    return self::fail(sprintf('Place %d: the stop date must be after the start (%s). A place that never happened is a Remove (added by mistake).', $i, self::d($start)));
                }
            }
            $place = array('team' => $team, 'role' => $role, 'start' => $start, 'stop' => $stop === '' ? null : $stop);
            $other = $TL::clash($rows, $team, $role, $start, $place['stop']);
            if ($other) {
                return self::fail(sprintf('Place %d: %s', $i, $TL::clash_message($team, $other)));
            }
            $places[] = $place;
            $rows = self::rows($season, $places);
        }
        $paid_text = $text('paid');
        if ($paid_text === '') {
            $paid = 0.0;
        } elseif (preg_match('/^\d{1,6}(\.\d{1,2})?$/D', $paid_text) !== 1 || floatval($paid_text) > self::MAX_PAID) {
            return self::fail(sprintf('Amount paid: a number of dollars from 0 to %s, with at most two decimals (no $ sign).', number_format(self::MAX_PAID)));
        } else {
            $paid = round(floatval($paid_text), 2);
        }
        return array('ok' => true, 'season' => $season, 'class' => $class, 'places' => $places, 'paid' => $paid);
    }

    /** compute()'s answer as the calculator's script reads it: text it inserts with textContent, plus the raw figures. */
    private static function result_payload($result) {
        $places = array();
        foreach ($result['places'] as $place) {
            $places[] = self::place_text($place);
        }
        return array(
            'headline' => 'Fee ' . self::money($result['fee']) . ' for ' . $result['season'] . ' (' . $result['class'] . ')',
            'places' => $places,
            'notes' => $result['notes'],
            'stretches' => $result['breakdown'],
            'due' => self::due_rows($result),
            'today' => self::today_lines($result),
            'status' => $result['status'],
            'pill' => $result['pill'],
            'chart' => $result['chart'],
            'fee' => $result['fee'],
            'paid' => $result['paid'],
            'credit' => $result['credit'],
            'due_today' => $result['schedule']['due_today'],
            'overdue' => $result['schedule']['overdue'],
            'next_due' => $result['schedule']['next_due'],
            'behind_since' => $result['schedule']['behind_since'],
            'window_from' => $result['schedule']['window_from'],
            'window_to' => $result['schedule']['window_to'],
            'stopped_on' => $result['schedule']['stopped_on'],
        );
    }

    /** "SL2M playing member from 1 Mar 2027 · still on" / "· stopped 1 Jul 2027 (charged up to 30 Jun 2027)". */
    private static function place_text($place) {
        $text = $place['team'] . ' ' . self::role_text($place['role']) . ' from ' . self::d($place['start']);
        if (empty($place['stop'])) {
            return $text . ' · still on';
        }
        return $text . ' · stop ' . self::d($place['stop']) . ' (charged up to ' . self::d(TeamOversight_Fee_Timeline::add_days($place['stop'], -1)) . ')';
    }

    /** The due-by table's rows: date, what it is, the amount due by then and its share of the fee. */
    private static function due_rows($result) {
        $rows = array();
        foreach ($result['due'] as $mark) {
            $rows[] = array('date' => self::d($mark['date']), 'label' => $mark['label'], 'amount' => self::money($mark['amount']), 'pct' => $mark['pct'] . '%');
        }
        return $rows;
    }

    /** What the person owes as of the day judged: due, paid, overdue or credit, the next payment or behind since. */
    private static function today_lines($result) {
        $s = $result['schedule'];
        $on = self::d($result['today']);
        $lines = array();
        $lines[] = 'On ' . $on . ': ' . self::money($s['due_today']) . ' due by then, ' . self::money(min($result['paid'], $result['fee'])) . ' paid towards the fee'
            . ($result['credit'] >= 0.01 ? ' and ' . self::money($result['credit']) . ' credit (paid above the fee)' : '') . '.';
        if ($s['overdue'] > 0) {
            $lines[] = 'Overdue: ' . self::money($s['overdue']) . ($s['behind_since'] ? ', behind since ' . self::d($s['behind_since']) : '') . '.';
        } elseif ($result['outstanding'] > 0 && $s['next_due']) {
            $lines[] = 'Nothing overdue: their payments keep them on schedule until ' . self::d($s['next_due']) . ' (the next payment is due then).';
        } elseif ($result['outstanding'] > 0) {
            $lines[] = 'Nothing overdue.';
        } else {
            $lines[] = 'Nothing outstanding.';
        }
        return $lines;
    }

    // ------------------------------------------------------------------
    // The page
    // ------------------------------------------------------------------

    /** VVL Oversight → How it works. Admins only; reads only. */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Sorry, you are not allowed to access this page.'), 'How it works', array('response' => 403));
        }
        self::fees();
        $season = self::requested_season();
        echo '<div class="wrap murvc-help">';
        echo '<style>' . self::styles() . '</style>';
        echo '<h1>How it works</h1>';
        echo '<p class="mh-lead">A plain-English guide to the MURVC Club Manager plugin: what it runs, how fees are worked out and fall due, and what to know before changing it. It is written for the committee checking that it behaves as agreed, and for whoever maintains it next. Sections 2, 6, 7 and 9 read the live settings and work their figures out with the same code that bills members, so they are never out of date. Section 9 is the committee\'s guide to running offers and deposits through the selection period. Nothing on this page changes anything.</p>';
        self::render_toc();
        self::section_glance();
        self::section_settings($season);
        self::section_fees($season);
        self::section_timeline($season);
        self::section_due($season);
        self::section_examples($season);
        self::section_calculator($season);
        self::section_trials();
        self::section_offers($season);
        self::section_maintainers();
        echo '</div>';
        self::render_script();
    }

    private static function sections() {
        return array(
            'mh-glance' => 'At a glance',
            'mh-settings' => 'This season\'s settings',
            'mh-fees' => 'How a fee is worked out',
            'mh-timeline' => 'The player timeline',
            'mh-due' => 'When money is due',
            'mh-examples' => 'Worked examples',
            'mh-calculator' => 'Fee calculator',
            'mh-trials' => 'Trials, payments, emails and privacy',
            'mh-offers' => 'Offers and deposits',
            'mh-maintainers' => 'For whoever maintains this next',
        );
    }

    private static function render_toc() {
        echo '<nav class="mh-toc" aria-label="On this page"><ol>';
        foreach (self::sections() as $id => $title) {
            echo '<li><a href="#' . esc_attr($id) . '">' . esc_html($title) . '</a></li>';
        }
        echo '</ol></nav>';
    }

    private static function open_section($id) {
        $sections = self::sections();
        $number = array_search($id, array_keys($sections), true) + 1;
        echo '<section class="mh-section" id="' . esc_attr($id) . '">';
        echo '<h2><span class="mh-num">' . intval($number) . '</span> ' . esc_html($sections[$id]) . '</h2>';
    }

    private static function close_section() {
        echo '<p class="mh-top"><a href="#wpbody-content">Back to top</a></p></section>';
    }

    /** A list of plain-text items (each escaped). */
    private static function ul($items) {
        echo '<ul class="mh-list">';
        foreach ($items as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul>';
    }

    // 1. At a glance -----------------------------------------------------

    private static function section_glance() {
        $programs = class_exists('TeamOversight_Programs') ? TeamOversight_Programs::get_programs() : array();
        reset($programs);
        $first = !empty($programs) ? (string) key($programs) : '';
        $programs_slug = $first !== '' ? 'club-programs-' . $first : 'club-programs-settings';
        $rows = array(
            array('Memberships', 'Time-bound membership grants (Life, Full and Associate) from shop purchases, admin grants and team places (a place makes someone a Full Member until 1 April of the year after its season); the member list, membership history, the MUS and VV reports, stats and the membership log.',
                array(self::admin_link('club-membership', 'Members'), self::admin_link('club-membership-lookup', 'Member Lookup'), self::admin_link('club-membership-history', 'Membership History'), self::admin_link('club-membership-stats', 'Stats'), self::admin_link('club-membership-logs', 'Logs'))),
            array('VVL trials and selections', 'The trial application form and trial fee, coach verdicts, and (from 2027) offers: a coach asks for one, an administrator approves it, the player accepts and pays a deposit and is then put on the team automatically (section 9). Finalise Coach Selections places people for 2026 and earlier, and retries a 2027 placement that failed.',
                array(self::admin_link('team-oversight-trials', 'Trial Applications'), self::admin_link(TeamOversight_Offers_Admin::SLUG, 'Offers'), self::admin_link('team-oversight-committee', 'Committee Access'))),
            array('Team places', 'Who is on which team, in which role, from and to which dates. From 2027 each place has its own start and stop date and the fee follows them; they are changed on the person\'s Fee timeline card.',
                array(self::admin_link('team-oversight-assignments', 'Team Assignments'), self::admin_link('club-membership-lookup', 'Member Lookup (Fee timeline card)'))),
            array('Fees and payments', 'The fee matrix and season settings, each person\'s season fee, when it falls due, online pay-any-amount payments, the payments ledger, payment arrangements and overdue reminder emails.',
                array(self::admin_link('team-oversight-invoices', 'Payment Management'), self::admin_link('team-oversight-fees', 'Configuration'), self::admin_link('team-oversight-emails', 'Emails'), self::admin_link('team-oversight-logs', 'Logs'))),
            array('Ready to Play', 'The pre-season checklist players see on the website ([ready_to_play]): VV registration, shirt, shorts and socks, fees with the pay box. Admins see everyone\'s progress.',
                array(self::admin_link('team-oversight-readiness', 'Player Readiness'))),
            array('Coach portal', 'The coaches\' page on the website ([team_coach_portal]): the selection board, verdicts and notes, attendance, the team list with each player\'s fee standing, the roster export and the trial book.',
                array(self::admin_link('team-oversight-committee', 'Committee Access'))),
            array('Club Programs', 'Attendance for casual programs (Mixed Development and the like), read live from paid shop orders, with supervisors per program.',
                array(self::admin_link($programs_slug, 'Club Programs'))),
        );
        self::open_section('mh-glance');
        echo '<p>The plugin runs seven areas. The VVL Oversight ' . self::admin_link('team-oversight', 'Dashboard') . ' (and the WordPress dashboard\'s club overview) shows the headline numbers. Daily jobs (membership sync, fee releases, stops that take effect, reminder emails) run on WordPress\'s scheduler, which needs some site traffic to fire.</p>';
        echo '<div class="mh-scroll"><table class="widefat striped mh-table"><thead><tr><th scope="col">Area</th><th scope="col">What it does</th><th scope="col">Where</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . esc_html($row[1]) . '</td><td>' . implode('<br>', $row[2]) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::close_section();
    }

    // 2. This season's settings -----------------------------------------

    private static function season_picker($season) {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="mh-picker">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<label for="mh-season">Season</label> <select id="mh-season" name="season">';
        $seasons = self::seasons();
        if (!in_array($season, $seasons, true)) {
            $seasons[] = $season;
            rsort($seasons, SORT_STRING);
        }
        foreach ($seasons as $s) {
            $label = $s . (TeamOversight_Fees::get_season_dates($s) ? '' : ' (no dates)');
            echo '<option value="' . esc_attr($s) . '"' . selected($s, $season, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <button type="submit" class="button">Show</button>';
        echo '<span class="description">Sections 2 to 6 and 9 follow this season. The calculator has its own season list.</span>';
        echo '</form>';
    }

    private static function release_state($season) {
        $release = TeamOversight_Fees::get_fee_release_date($season);
        if ($release === '') {
            return 'No release date: fees are charged as people are placed.';
        }
        if (!TeamOversight_Fees::fees_released($season)) {
            return 'Not released until ' . self::d($release) . '. Until then places and changes are saved and nobody is billed; on that date the daily job (or Release fees now on Configuration) invoices everyone with a place at their real dates.';
        }
        $applied = get_option(TeamOversight_Fees::APPLIED_OPTION, array());
        if (is_array($applied) && !empty($applied[$season])) {
            return 'Released on ' . self::d($release) . ' (applied ' . self::d($applied[$season]) . ').';
        }
        return 'Release date ' . self::d($release) . ' has passed: invoices go out on the next daily run, or with Release fees now.';
    }

    /** Counts only (no member data): the season's invoices, those at $0 holding credit until release, and those set with Edit Fee. */
    private static function invoice_counts($season) {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) AS invoices,
                COALESCE(SUM(fee_overridden = 1), 0) AS manual,
                COALESCE(SUM(fee_overridden = 0 AND invoice_amount <= 0 AND EXISTS (SELECT 1 FROM {$p}team_invoice_payments pay WHERE pay.invoice_id = i.id)), 0) AS credit
            FROM {$p}team_invoices i WHERE season = %s
        ", $season));
        return array(
            'invoices' => $row ? intval($row->invoices) : 0,
            'manual' => $row ? intval($row->manual) : 0,
            'credit' => $row ? intval($row->credit) : 0,
        );
    }

    private static function section_settings($season) {
        $dates = TeamOversight_Fees::get_season_dates($season);
        $timeline = TeamOversight_Fees::timeline_applies($season);
        $window = TeamOversight_Fees::get_payment_window($season);
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        $config_link = self::admin_link('team-oversight-fees', 'Configuration', array('season' => $season));
        self::open_section('mh-settings');
        self::season_picker($season);
        echo '<p>Read live for <strong>' . esc_html($season) . '</strong>. Change any of them on ' . $config_link . ' (season dates' . ($timeline ? ' and payment window' : '') . ', fee release, fee matrix' . ($timeline ? ', upper limits, the offer settings and team division levels' : '') . ').</p>';

        $items = array();
        if ($dates && (string) $dates['end'] > (string) $dates['start']) {
            $items[] = array('Season dates (what fees cover)', sprintf('%s – %s: %d days are charged (the end date itself isn\'t).', self::d($dates['start']), self::d($dates['end']), TeamOversight_Fee_Timeline::days_between($dates['start'], $dates['end'])));
        } else {
            $items[] = array('Season dates (what fees cover)', 'Not set. Without them there is no pro-rata (a full fee is charged), nothing falls due on a schedule and nothing is overdue' . ($timeline ? ', and there is no late-join cutoff' : '') . '.');
        }
        if ($timeline) {
            if ($window) {
                if (!$window['from_set'] && !$window['to_set']) {
                    $text = sprintf('No payment window saved, so it is the season dates: fees due from %s, paid in full by %s.', self::d($window['from']), self::d($window['to']));
                } else {
                    $text = sprintf('Fees due from %s%s; paid in full by %s%s.', self::d($window['from']), $window['from_set'] ? '' : ' (blank, so the season start)', self::d($window['to']), $window['to_set'] ? '' : ' (blank, so the season end)');
                }
                $items[] = array('Payment window', $text . ' Each player\'s fee falls due inside it (section 5).');
            } else {
                $items[] = array('Payment window', 'Needs the season dates first.');
            }
            if ($cutoff !== null) {
                $text = sprintf('Nobody starts on a %s team after %s (the effective paid in full by).', $season, self::d($cutoff));
                if ($dates && $cutoff >= $dates['end']) {
                    $text = sprintf('Nobody starts on a %s team after %s (the effective paid in full by), and a start on the season end itself is refused because that day isn\'t charged, so the latest start accepted is %s.', $season, self::d($cutoff), self::d(TeamOversight_Fee_Timeline::add_days($dates['end'], -1)));
                }
                $items[] = array('Late-join cutoff', $text);
            } else {
                $items[] = array('Late-join cutoff', 'None: the season has no dates.');
            }
        } else {
            $items[] = array('Payment window', sprintf('Not used: seasons before %d fall due evenly across the season dates.', TeamOversight_Fees::FEE_TIMELINE_FROM));
        }
        $counts = self::invoice_counts($season);
        $extra = array();
        if ($counts['credit'] > 0) {
            $extra[] = $counts['credit'] . ' at $0 holding payments as credit';
        }
        if ($counts['manual'] > 0) {
            $extra[] = $counts['manual'] . ' set with Edit Fee';
        }
        $items[] = array('Fee release', self::release_state($season));
        $items[] = array('Invoices so far', sprintf('%d for %s', $counts['invoices'], $season) . ($extra ? ' (' . implode(', ', $extra) . ')' : '') . '.');
        // 1.76.0: the season's offer and deposit settings and the state of its upper limits, read live (counts and settings only).
        if ($timeline && class_exists('TeamOversight_Offers')) {
            $offer_settings = TeamOversight_Offers::settings($season);
            $percent = rtrim(rtrim(number_format((float) $offer_settings['percent'], 2, '.', ''), '0'), '.');
            $items[] = array('Offers and deposits', (TeamOversight_Offers::emails_live() ? 'Offer emails may be sent from this website.' : 'Offer emails are not being sent from this website yet (they wait until an administrator gives the permission, on the live website only).')
                . sprintf(' The deposit is %s%% of the quoted fee. It is due on the later of %s and %d days after the committee approves the offer, and the player has %d days to reply.', $percent, self::d($offer_settings['deposit_date']), intval($offer_settings['deposit_days']), intval($offer_settings['reply_days'])));
            $report = self::fees()->matrix_report($season);
            $limit_text = sprintf('%d offerable fee cell(s) have no upper limit yet, so an offer for them can\'t be sent before the fees are released; %d have no fee yet; %d have a fee above their limit.', count($report['no_limit']), count($report['no_fee']), count($report['above']));
            $items[] = array('Upper limits', $limit_text . ' A player whose place came from an offer quoted on an upper limit is never invoiced above the quoted amount.');
        }

        echo '<dl class="mh-facts">';
        foreach ($items as $item) {
            echo '<div><dt>' . esc_html($item[0]) . '</dt><dd>' . esc_html($item[1]) . '</dd></div>';
        }
        echo '</dl>';

        // The fee matrix.
        $grid = self::fees()->get_fee_grid($season);
        echo '<h3>Fee matrix for ' . esc_html($season) . '</h3>';
        $any = false;
        foreach ($grid['cells'] as $by_role) {
            foreach ($grid['roles'] as $role) {
                if (isset($by_role[$role])) {
                    $any = true;
                }
            }
        }
        if (!$any) {
            echo '<p class="mh-warn">The ' . esc_html($season) . ' fee matrix is empty, so nobody can be charged yet. Fill it in on ' . $config_link . ' (it can copy another season\'s amounts as a start).</p>';
        }
        $limits = $timeline ? TeamOversight_Fees::get_limits_for_season($season) : array();
        echo '<div class="mh-scroll"><table class="widefat striped mh-table mh-matrix"><thead><tr><th scope="col">Fee class</th>';
        foreach ($grid['roles'] as $role) {
            echo '<th scope="col">' . esc_html(TeamOversight_Fees::role_label($role)) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($grid['classes'] as $class) {
            echo '<tr><th scope="row">' . esc_html($class) . '</th>';
            foreach ($grid['roles'] as $role) {
                $cell = isset($grid['cells'][$class][$role]) ? $grid['cells'][$class][$role] : null;
                $limit_text = '';
                if ($timeline) {
                    $limit_text = isset($limits[$class][$role]) ? '<br><span class="description">upper limit ' . esc_html(self::money($limits[$class][$role])) . '</span>' : '<br><span class="description">no upper limit</span>';
                }
                echo $cell ? '<td>' . esc_html(self::money($cell->fee_amount)) . $limit_text . '</td>' : '<td class="mh-blank">blank' . $limit_text . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if ($timeline) {
            echo '<p class="description">From ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' each cell has a fee (blank until it is known) and an upper limit (the most that fee can be: offers sent before the fees are released are quoted on it).</p>';
        }
        $charged = array();
        foreach (TeamOversight_Fees::CHARGED_AS as $role => $as) {
            $charged[] = TeamOversight_Fees::role_label($role) . ' is charged the ' . self::role_text($as) . ' amount';
        }
        echo '<p class="description">' . esc_html(implode('; ', $charged) . '. The matrix has only these three columns, so there is no separate coaching rate (and no coaching discount). JPL/YSL is the row for a junior on a junior-league team; nobody is put in Unknown. A blank cell has no amount: someone who falls into it is charged the highest of their other places that has one; with none at all they get no new invoice, and an invoice they already have keeps its amount.') . '</p>';
        self::close_section();
    }

    // 3. How a fee is worked out ----------------------------------------

    private static function section_fees($season) {
        $class_season = TeamOversight_Fees::timeline_applies($season) ? $season : (string) TeamOversight_Fees::FEE_TIMELINE_FROM;
        $cutoff = TeamOversight_Database::get_dob_cutoff('u19', $class_season);
        self::open_section('mh-fees');
        echo '<p>From the ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' season each team place has its own dates and the fee follows them. The rules below are for ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' and later; seasons before that are covered in a short note at the end.</p>';

        echo '<h3>Fee classes</h3>';
        echo '<p>Every person is in one fee class for the season, which picks their row of the fee matrix. It is worked out from their profile, in this order:</p>';
        self::ul(array(
            sprintf('Junior U/19 (VVL): born on or after Volleyball Victoria\'s U19 cutoff for the season, 1 January of the season year minus 18 (for %s: born on or after %s), so nobody turns 19 during the season. On a junior-league team (JPL or YSL, by its code or an age rule on the team) the same junior is charged the JPL/YSL row for that place.', $class_season, self::d($cutoff)),
            'Full Adult: alumni (their degree end date is before the moment their fee was first recorded for the season), staff (degree type Staff), anyone not at a university, and anyone with no account.',
            'Melb Uni Student: institution Melbourne University, and not alumni or staff.',
            'Other Student: institution Other University, and not alumni or staff.',
        ));
        echo '<p>The moment a class is judged at is fixed for the season, so time passing never moves anyone between classes: junior follows the season\'s cutoff, and alumni is judged at the moment their fee was first recorded for the season (when Finalise, Accept or Add Assignment first put them on a team). A backdated start doesn\'t move that moment; removing every place they hold that season as added by mistake starts it again. The profile itself is read whenever the fee is worked out, so a corrected profile (a new institution, say) changes the class from the day a re-price picks it up, and earlier days are never re-priced.</p>';

        echo '<h3>Every place is priced on its own</h3>';
        echo '<p>Each team place is a bucket: team, role and the fee class for that team. Its rate comes from the matrix: playing member, training only and supporter at their own amounts, coach and assistant coach at the supporter amount, team manager at the playing member amount.</p>';

        echo '<h3>The highest place each day, never added up</h3>';
        echo '<p>For each day inside the season dates the person is charged the highest rate among the places they hold that day. Places never add up, so a coach who also plays pays the higher of the two, and a training-only player who joins a second team as a playing member pays the playing rate from that day only.</p>';
        echo self::diagram_highest();

        echo '<h3>Pro-rata over the season</h3>';
        self::ul(array(
            'Fee = for each stretch of days with the same places, its rate × its days ÷ the season\'s days; the stretches are added up and rounded once, to the cent.',
            'A start date is the first day charged. A stop date is the first day off the team, so it isn\'t charged (they pay up to the day before). Blank = still on.',
            'Days before the season start and after the season end aren\'t charged: a place that starts before the season is charged from the season start, and the season\'s end date itself isn\'t charged.',
            'With no season dates there is no pro-rata: the latest stretch\'s full rate applies.',
        ));

        echo '<h3>Fees set by hand</h3>';
        echo '<p><strong>Edit Fee</strong> on Payment Management sets a fee by hand, and it sticks: no automatic re-price changes it (date changes and removals on the Fee timeline, Finalise, Accept, Undo Assignment, a release, a season-date change). When a re-price would have set a different amount, Logs records it as Manual fee kept. <strong>Use calculated fee</strong> puts it back to automatic and re-prices it straight away. A manual fee falls due on the same schedule as any other (section 5).</p>';

        echo '<h3>Fee releases</h3>';
        echo '<p>People can be placed on next season\'s teams before its fees are set. Until the season\'s fee release date nobody is invoiced for it: places, changes and fee classes are still recorded. On the release date the daily job, or <strong>Release fees now</strong> on Configuration, invoices everyone with a place that season at their real dates, players who already stopped included (someone whose places all fall outside the season dates has nothing to pay). Invoices issued before a release date was set can be taken back with <strong>Remove fees until release</strong>: unpaid ones are deleted, and ones with payments drop to $0 so the money shows as credit (taken back until release). On release they are re-priced to the real fee and the credit comes off it. Fees set with Edit Fee stay as they are through both. Section 2 shows the selected season\'s release and how many invoices are holding credit.</p>';
        echo '<p>Changing a season\'s start or end date re-prices every automatic invoice for the season straight away (once released). Changing only the payment window re-prices nothing, but moves what everyone has due.</p>';

        echo '<h3>Seasons before ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . '</h3>';
        echo '<p>Those seasons keep their original rules, so their numbers never change: each team change records a dated stretch (a fee segment) and the fee is the same rate × share of the season, priced by the same code; the fee class is judged on the day the fee is worked out (age and degree end then); team places are edited on Team Assignments; and the fee falls due evenly across the season dates. Once a season\'s year is over, anything still owing is fully overdue.</p>';
        self::close_section();
    }

    /** Section 3's diagram: two places, and the higher one charged each day. */
    private static function diagram_highest() {
        $svg = '<figure class="mh-figure"><svg class="mh-diagram" viewBox="0 0 640 176" role="img" aria-labelledby="mh-d1-title mh-d1-desc">'
            . '<title id="mh-d1-title">Two places, charged at the higher rate each day</title>'
            . '<desc id="mh-d1-desc">Team A training only runs all season. Team B playing member starts a quarter of the way in. The charged row shows the training only rate until Team B starts, then the playing member rate: never both.</desc>'
            . '<text class="mh-d-label" x="0" y="30">Team A · training only</text>'
            . '<rect class="mh-d-train" x="190" y="16" width="440" height="22" rx="4"></rect>'
            . '<text class="mh-d-label" x="0" y="72">Team B · playing member</text>'
            . '<rect class="mh-d-play" x="300" y="58" width="330" height="22" rx="4"></rect>'
            . '<line class="mh-d-rule" x1="190" y1="98" x2="630" y2="98"></line>'
            . '<text class="mh-d-label mh-d-strong" x="0" y="124">Charged each day</text>'
            . '<rect class="mh-d-train" x="190" y="110" width="108" height="22" rx="4"></rect>'
            . '<rect class="mh-d-play" x="300" y="110" width="330" height="22" rx="4"></rect>'
            . '<text class="mh-d-note" x="244" y="152" text-anchor="middle">training only rate</text>'
            . '<text class="mh-d-note" x="465" y="152" text-anchor="middle">playing member rate (the higher one)</text>'
            . '<text class="mh-d-axis" x="190" y="172">season start</text>'
            . '<text class="mh-d-axis" x="630" y="172" text-anchor="end">season end</text>'
            . '</svg><figcaption>Each day is charged at the higher of the places held that day. The two places never add up.</figcaption></figure>';
        return $svg;
    }

    // 4. The player timeline --------------------------------------------

    private static function section_timeline($season) {
        $cutoff = TeamOversight_Fees::timeline_applies($season) ? TeamOversight_Fees::latest_start_date($season) : null;
        self::open_section('mh-timeline');
        echo '<p>From ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' every team place (one person, one team, one role) has a start date and a stop date, and the fee and the due dates follow them. Late news is normal: backdate a start or a stop and the fee re-prices to the real dates straight away (once fees are released). Anything already paid stays paid; any overpayment shows as credit.</p>';
        echo self::diagram_place();

        echo '<h3>Placing people</h3>';
        self::ul(array(
            'Finalise Coach Selections (one start date per run), Accept, bulk accept and Add Assignment each take a start date, defaulting to today.',
            'A start after the late-join cutoff is refused, with the reason, and nothing is written. The cutoff is the season\'s paid in full by date'
                . ($cutoff !== null ? ' (for ' . $season . ': ' . self::d($cutoff) . ')' : '') . '. With no season dates there is no cutoff.',
            'A start before 1 January of the year before the season is refused as a typo in the year.',
            'A team the person already holds is left as it is, so running Finalise again never adds a place twice. One person can\'t hold two overlapping playing places on the same team (playing member and training only count as one place), or the same staff role twice; different teams can overlap, and so can coach and player on one team.',
            'A future start puts them on the roster straight away and charges from that start.',
        ));

        echo '<h3>Changing a place: the Fee timeline card</h3>';
        echo '<p>Places are changed only on the person\'s <strong>Fee timeline</strong> card on ' . self::admin_link('club-membership-lookup', 'Member Lookup') . ' (Payment Management\'s names and Team Assignments\' Fee timeline buttons open it). ' . self::admin_link('team-oversight-assignments', 'Team Assignments') . ' shows ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . '+ places read-only, and the server refuses edits there. Each form has a <strong>Preview</strong> that shows the fee before and after (and, once they have an invoice, what falls due by today before and after), without saving.</p>';
        self::ul(array(
            'Start and stop dates, with Save: correct either date. Backdating is normal.',
            'Stopped playing on (Stopped on for staff): for someone who played. It keeps the place and its history and charges up to the day before. A future date keeps them on the roster until that day, when the daily job takes them off. A playing place stopped today or earlier drops that team\'s Selected / Training Only verdict back to Tentative, so Finalise doesn\'t put them back.',
            'Clear the stop: they are back on, charged again from the old stop date.',
            'Change role or team from [date]: stops the place on that date and starts the new one the same day. A date on or before the place\'s start corrects the place instead. Moving to another team after the late-join cutoff is refused; a role change on the same team isn\'t.',
            'Remove (added by mistake): for a place that should never have existed. It needs a tick box, deletes that one place (the change history keeps a copy) and charges nothing for it. Their other places are untouched. With no place left that season, an invoice with nothing paid is deleted; one with payments stays, with the money as credit; a fee set with Edit Fee is never changed.',
            'Undo Assignment (Trial Applications) removes every playing place on the application\'s teams the same way, as added by mistake, and puts the application back to pending. If a place can\'t be removed (someone changed it meanwhile) the application stays accepted and the notice says so. For a player who did play, use Stopped playing on instead.',
        ));

        echo '<h3>The change history</h3>';
        echo '<p>Every change is recorded before it is saved: which place, what changed (old and new dates), who did it (or the daily job), when, and the fee and amount due before and after. The card lists it, and VVL Oversight → Logs has a Team dates changed line for each. The history is never pruned, because it is the only record of a removed place and it feeds the behind clock (section 5). A save that loses to someone else\'s change, or comes from a page left open, changes nothing and says to reload.</p>';
        self::close_section();
    }

    /** Section 4's diagram: a place's start and stop. */
    private static function diagram_place() {
        return '<figure class="mh-figure"><svg class="mh-diagram" viewBox="0 0 640 120" role="img" aria-labelledby="mh-d2-title mh-d2-desc">'
            . '<title id="mh-d2-title">A team place\'s start and stop dates</title>'
            . '<desc id="mh-d2-desc">The bar runs from the start date, the first day charged, to the stop date, the first day off the team, which is not charged.</desc>'
            . '<rect class="mh-d-play" x="120" y="40" width="380" height="26" rx="4"></rect>'
            . '<line class="mh-d-mark" x1="120" y1="28" x2="120" y2="78"></line>'
            . '<line class="mh-d-mark mh-d-dash" x1="500" y1="28" x2="500" y2="78"></line>'
            . '<text class="mh-d-label mh-d-strong" x="120" y="20" text-anchor="middle">Start</text>'
            . '<text class="mh-d-label mh-d-strong" x="500" y="20" text-anchor="middle">Stop</text>'
            . '<text class="mh-d-note" x="120" y="98" text-anchor="middle">first day charged</text>'
            . '<text class="mh-d-note" x="500" y="98" text-anchor="middle">first day off the team (not charged)</text>'
            . '<text class="mh-d-note" x="310" y="58" text-anchor="middle">charged every day</text>'
            . '<text class="mh-d-axis" x="500" y="116" text-anchor="middle">blank stop = still on</text>'
            . '</svg><figcaption>Start counts, stop doesn\'t: a player who stops on 1 July is charged up to 30 June.</figcaption></figure>';
    }

    // 5. When money is due ----------------------------------------------

    private static function section_due($season) {
        $window = TeamOversight_Fees::timeline_applies($season) ? TeamOversight_Fees::get_payment_window($season) : null;
        $enabled = (bool) get_option(TeamOversight_Payments::REMINDERS_ENABLED_OPTION);
        $interval = max(1, intval(get_option(TeamOversight_Payments::REMINDER_DAYS_OPTION, 7)));
        $grace = TeamOversight_Payments::get_reminder_grace_days();
        $minimum = TeamOversight_Payments::get_reminder_minimum();
        $hour = TeamOversight_Payments::get_reminder_hour();
        $zone = TeamOversight_Payments::get_reminder_timezone()->getName();
        self::open_section('mh-due');
        echo '<p>A fee isn\'t due all at once: from ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' each player\'s fee falls due on their own schedule inside the season\'s payment window'
            . ($window ? ' (for ' . esc_html($season) . ': ' . esc_html(self::d($window['from'])) . ' to ' . esc_html(self::d($window['to'])) . ')' : '') . '. Every screen that shows or charges an amount due uses the same schedule, so the amount a member sees is the amount the pay box charges.</p>';
        self::ul(array(
            'Nothing is due before Fees due from, or before the player\'s first start if that is later. That day is the start of their window.',
            'From then the fee falls due evenly, a little each day (in whole days, at midnight site time), until Paid in full by, when all of it is due. A player whose first start is on or after Paid in full by owes it all on their start day.',
            'A joiner gets their own schedule: their pro-rated fee falls due evenly from their own start to Paid in full by.',
            'Stopping: once every place has a stop date, everything for the time played falls due on the last stop date, even if that is before the window opens.',
            'A season from an earlier calendar year still owing is all due. A season with no dates has no schedule, so nothing is overdue.',
            'Overdue = due by today minus paid, never more than what is outstanding. Money paid above the fee is credit: it shows on Payment Management (in credit) and Member Lookup, and the club offsets or refunds it.',
            'When nothing is overdue, the next payment date is the day the schedule catches up with what they have paid.',
        ));

        echo '<h3>The behind clock</h3>';
        echo '<p>How far someone is behind is measured in time: from the day their payments last covered the schedule to today, across all their seasons (an earlier season\'s leftover counts from that season\'s end). Behind means at least $1 overdue in total. The clock never starts before the latest of:</p>';
        self::ul(array(
            'the day the fee was billed (the invoice created);',
            'the day the fee was last raised (by a re-price or Edit Fee);',
            'the day an admin recorded the latest change that put more money due that day (a backdated start, a stop recorded late, a role upgrade): never before an admin recorded the change;',
            'the day the payment window was last moved earlier on Configuration.',
        ));
        echo '<p>A change that lowers what is due never moves the clock. Where it can\'t be exact it errs towards not flagging someone.</p>';
        echo '<h3>The no-training flag and payment arrangements</h3>';
        echo '<p>Someone ' . intval(TeamOversight_Payments::STANDING_FLAG_DAYS) . ' days or more behind is flagged as not to train, on the coach portal\'s team list and on Payment Management, until it is sorted with the club. An admin can record a <strong>payment arrangement</strong> on Payment Management: it covers everything the person owes at the time, every season. Those invoices still count as behind but never towards the flag, and coaches see payment arrangement instead. A fee raised after the arrangement counts again until it is extended.</p>';

        echo '<h3>Reminder emails</h3>';
        echo '<p>Overdue reminders are <strong>' . ($enabled ? 'switched on' : 'switched off') . '</strong> (' . self::admin_link('team-oversight-emails', 'Emails') . '). When on, a daily run at ' . esc_html(sprintf('%d:00', $hour)) . ' (' . esc_html($zone) . ') emails each person with an account who is at least ' . esc_html(self::money($minimum)) . ' overdue, has been behind for at least ' . intval($grace) . ' day' . ($grace === 1 ? '' : 's') . ' (the grace period, counted from the earliest day any of their invoices fell behind: for ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . '+ fees that day is held back as in the behind clock above, but unlike the coaches\' clock a few cents left on an older invoice count too, and an earlier season\'s date isn\'t held back to when it was billed) and wasn\'t emailed in the last ' . intval($interval) . ' day' . ($interval === 1 ? '' : 's') . '. Arrangements don\'t change the emails.</p>';

        echo '<h3>What coaches see</h3>';
        echo '<p>Coaches see each confirmed player\'s fee standing as <strong>percentages only, never a dollar amount</strong> (fees differ by class, and some are set by hand): for example <em>Fees: 40% paid · on track</em> or <em>Fees: 28% paid · 2 weeks behind</em>, with the player\'s own window in the tooltip. Amounts stay on admin pages. The calculator below shows the pill a coach would see.</p>';
        self::close_section();
    }

    // 6. Worked examples ------------------------------------------------

    private static function section_examples($season) {
        self::open_section('mh-examples');
        $examples = self::examples($season);
        if (!$examples['ok']) {
            echo '<p class="mh-warn">' . esc_html($examples['error']) . '</p>';
            self::close_section();
            return;
        }
        echo '<p>Worked out just now by the real fee engine from ' . esc_html($season) . '\'s settings above, for a <strong>' . esc_html($examples['class']) . '</strong> player (the first fee class with a playing member amount), on ' . esc_html($season) . ' teams. Nothing is saved. The due curves show the share of the fee due on each day; the shaded band is the season\'s payment window. Behind dates assume the fee and every change were recorded on time.</p>';
        echo '<div class="mh-examples">';
        foreach ($examples['items'] as $item) {
            echo '<article class="mh-card mh-example" id="' . esc_attr('mh-example-' . $item['key']) . '" data-example="' . esc_attr($item['key']) . '">';
            echo '<h3>' . esc_html($item['title']) . '</h3>';
            echo '<p>' . esc_html($item['story']) . '</p>';
            if ($item['key'] === 'late') {
                if ($item['refusal'] !== null) {
                    echo '<p class="mh-refusal"><strong>Start ' . esc_html(self::d($item['late_start'])) . ':</strong> <span class="mh-refusal-text">' . esc_html($item['refusal']) . '</span></p>';
                } else {
                    echo '<p class="mh-warn">A start on ' . esc_html(self::d($item['late_start'])) . ' is accepted: this season has no late-join cutoff.</p>';
                }
                if ($item['latest'] === null) {
                    echo '<p class="mh-muted">No later start is accepted near the cutoff.</p>';
                    echo '</article>';
                    continue;
                }
                echo '<p><strong>Latest start accepted: ' . esc_html(self::d($item['latest'])) . '.</strong></p>';
            }
            self::render_result($item['result'], $item);
            echo '</article>';
        }
        echo '</div>';
        self::close_section();
    }

    /** One compute() result, as the page shows it (the calculator's script builds the same with textContent). */
    private static function render_result($result, $item = null) {
        if (!$result || empty($result['ok'])) {
            echo '<p class="mh-warn">' . esc_html($result ? $result['error'] : 'Not worked out.') . '</p>';
            return;
        }
        echo '<ul class="mh-inputs">';
        echo '<li><span>Fee class</span> ' . esc_html($result['class']) . '</li>';
        foreach ($result['places'] as $place) {
            echo '<li><span>Place</span> ' . esc_html(self::place_text($place)) . '</li>';
        }
        if ($result['paid'] > 0) {
            echo '<li><span>Paid</span> ' . esc_html(self::money($result['paid'])) . '</li>';
        }
        echo '</ul>';
        echo '<p class="mh-fee">Fee <strong data-figure="fee">' . esc_html(self::money($result['fee'])) . '</strong>';
        if ($item && isset($item['before_fee'])) {
            echo ' <span class="mh-muted">(was ' . esc_html(self::money($item['before_fee'])) . ' before the stop)</span>';
        }
        echo '</p>';
        foreach ($result['notes'] as $note) {
            echo '<p class="mh-note">' . esc_html($note) . '</p>';
        }
        self::render_stretches($result['breakdown']);
        echo self::svg($result['chart']);
        self::render_due_table(self::due_rows($result));
        echo '<ul class="mh-today">';
        foreach (self::today_lines($result) as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
    }

    private static function render_stretches($table) {
        if (empty($table['rows'])) {
            echo '<p class="mh-muted">No stretch to charge.</p>';
            return;
        }
        echo '<div class="mh-scroll"><table class="widefat striped mh-table mh-stretches"><thead><tr>'
            . '<th scope="col">From</th><th scope="col">Last day charged</th><th scope="col">Days</th><th scope="col">Places held</th><th scope="col">Rate</th><th scope="col">Amount</th>'
            . '</tr></thead><tbody>';
        foreach ($table['rows'] as $row) {
            $places = array();
            foreach ($row['places'] as $place) {
                $places[] = $place['top'] ? '<strong>' . esc_html($place['text']) . '</strong>' : esc_html($place['text']);
            }
            echo '<tr><td>' . esc_html($row['from']) . '</td><td>' . esc_html($row['last']) . '</td><td>' . esc_html($row['days']) . '</td><td>' . implode('<br>', $places) . '</td><td>' . esc_html($row['rate']) . '</td><td>' . esc_html($row['amount']) . '</td></tr>';
        }
        echo '</tbody><tfoot><tr><th colspan="5" scope="row">' . esc_html($table['foot']) . '</th><th>' . esc_html($table['total']) . '</th></tr></tfoot></table></div>';
    }

    private static function render_due_table($rows) {
        echo '<div class="mh-scroll"><table class="widefat mh-table mh-due"><thead><tr><th scope="col">On</th><th scope="col">What</th><th scope="col">Due by then</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['date']) . '</td><td>' . esc_html($row['label']) . '</td><td>' . esc_html($row['amount'] . ' (' . $row['pct'] . ')') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** The due curve as inline SVG, from chart()'s geometry (escaped; the script draws the same with createElementNS). */
    private static function svg($g) {
        $f = function ($n) {
            return esc_attr((string) $n);
        };
        $plot = $g['plot'];
        $out = '<figure class="mh-figure"><svg class="mh-chart" viewBox="0 0 ' . intval($g['w']) . ' ' . intval($g['h']) . '" role="img" aria-label="' . esc_attr($g['title']) . '">';
        $out .= '<title>' . esc_html($g['title']) . '</title>';
        $out .= '<rect class="mh-c-plot" x="' . $f($plot['x']) . '" y="' . $f($plot['y']) . '" width="' . $f($plot['w']) . '" height="' . $f($plot['h']) . '"></rect>';
        $out .= '<rect class="mh-c-window" x="' . $f($g['window']['x']) . '" y="' . $f($plot['y']) . '" width="' . $f($g['window']['w']) . '" height="' . $f($plot['h']) . '"></rect>';
        $out .= '<text class="mh-c-label" x="' . $f($g['window']['x'] + 4) . '" y="' . $f($plot['y'] + 13) . '">' . esc_html($g['window']['label']) . '</text>';
        foreach ($g['grid'] as $line) {
            $out .= '<line class="mh-c-grid" x1="' . $f($plot['x']) . '" y1="' . $f($line['y']) . '" x2="' . $f($plot['x'] + $plot['w']) . '" y2="' . $f($line['y']) . '"></line>';
            $out .= '<text class="mh-c-axis" x="' . $f($plot['x'] - 6) . '" y="' . $f($line['y'] + 4) . '" text-anchor="end">' . esc_html($line['label']) . '</text>';
        }
        foreach ($g['ticks'] as $tick) {
            $bottom = $plot['y'] + $plot['h'];
            $out .= '<line class="mh-c-tick" x1="' . $f($tick['x']) . '" y1="' . $f($bottom) . '" x2="' . $f($tick['x']) . '" y2="' . $f($bottom + 5) . '"></line>';
            $out .= '<text class="mh-c-axis" x="' . $f($tick['x']) . '" y="' . $f($bottom + 18) . '" text-anchor="' . esc_attr($tick['anchor']) . '">' . esc_html($tick['label']) . '</text>';
        }
        if ($g['today']) {
            $out .= '<line class="mh-c-today" x1="' . $f($g['today']['x']) . '" y1="' . $f($plot['y'] - 4) . '" x2="' . $f($g['today']['x']) . '" y2="' . $f($plot['y'] + $plot['h']) . '"></line>';
            $out .= '<text class="mh-c-axis" x="' . $f($g['today']['x']) . '" y="' . $f($plot['y'] - 8) . '" text-anchor="' . esc_attr($g['today']['anchor']) . '">' . esc_html($g['today']['label']) . '</text>';
        }
        $out .= '<polyline class="mh-c-line" points="' . esc_attr($g['points']) . '"></polyline>';
        foreach ($g['markers'] as $marker) {
            $out .= '<circle class="mh-c-dot" cx="' . $f($marker['x']) . '" cy="' . $f($marker['y']) . '" r="4"><title>' . esc_html($marker['title']) . '</title></circle>';
        }
        $out .= '</svg><figcaption>' . esc_html($g['caption']) . '</figcaption></figure>';
        return $out;
    }

    // 7. Fee calculator -------------------------------------------------

    private static function section_calculator($season) {
        self::open_section('mh-calculator');
        $seasons = self::priceable_seasons();
        if (empty($seasons)) {
            echo '<p class="mh-warn">No season from ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' has dates yet, so there is nothing to calculate against. Set them on ' . self::admin_link('team-oversight-fees', 'Configuration') . '.</p>';
            self::close_section();
            return;
        }
        $calc_season = in_array($season, $seasons, true) ? $season : $seasons[0];
        $teams = array();
        $starts = array();
        foreach ($seasons as $s) {
            $teams[$s] = array_map('strval', array_keys(self::teams($s)));
            $window = TeamOversight_Fees::get_payment_window($s);
            $dates = TeamOversight_Fees::get_season_dates($s);
            $starts[$s] = array('start' => $dates['start'], 'min' => TeamOversight_Fee_Timeline::typo_guard($s), 'cutoff' => $window['to']);
        }
        $config = array(
            'ajax' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'teams' => $teams,
            'starts' => $starts,
        );
        echo '<p>Try any case: pick the season, a fee class, one or two team places and an amount paid. The answer comes from the same engine as the worked examples, works nothing out by hand and saves nothing. As in the examples, behind dates assume everything was recorded on time.</p>';
        echo '<noscript><p class="mh-warn">The calculator needs JavaScript. The worked examples above are computed the same way.</p></noscript>';
        echo '<div class="mh-card mh-calc" id="murvc-help-calc" hidden data-config="' . esc_attr(wp_json_encode($config)) . '">';
        echo '<form class="mh-calc-form" novalidate>';
        echo '<div class="mh-row">';
        echo '<label>Season <select name="season" class="mh-calc-season">';
        foreach ($seasons as $s) {
            echo '<option value="' . esc_attr($s) . '"' . selected($s, $calc_season, false) . '>' . esc_html($s) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Fee class <select name="fee_class">';
        foreach (TeamOversight_Fees::person_fee_classes() as $class) {
            echo '<option value="' . esc_attr($class) . '">' . esc_html($class) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Amount paid ($) <input type="number" name="paid" min="0" max="' . intval(self::MAX_PAID) . '" step="0.01" value="0" inputmode="decimal"></label>';
        echo '</div>';
        foreach (array(1, 2) as $i) {
            echo '<fieldset class="mh-place"><legend>' . ($i === 1 ? 'Place 1' : 'Place 2 (optional)') . '</legend><div class="mh-row">';
            echo '<label>Team <select name="team_' . $i . '" class="mh-calc-team" data-optional="' . ($i === 2 ? '1' : '0') . '">';
            if ($i === 2) {
                echo '<option value="">none</option>';
            }
            foreach ($teams[$calc_season] as $code) {
                echo '<option value="' . esc_attr($code) . '">' . esc_html($code) . '</option>';
            }
            echo '</select></label>';
            echo '<label>Role <select name="role_' . $i . '">';
            foreach (self::CALC_ROLES as $role) {
                echo '<option value="' . esc_attr($role) . '"' . selected($role, $i === 1 ? 'playing_member' : 'training_only', false) . '>' . esc_html(TeamOversight_Fees::role_label($role)) . '</option>';
            }
            echo '</select></label>';
            echo '<label>Start <input type="date" name="start_' . $i . '" class="mh-calc-start" value="' . esc_attr($starts[$calc_season]['start']) . '"></label>';
            echo '<label>Stop (optional) <input type="date" name="stop_' . $i . '" value=""></label>';
            echo '</div></fieldset>';
        }
        echo '<p><button type="submit" class="button button-primary">Work it out</button> <span class="description">Stop is the first day off the team. The late-join cutoff applies to starts, as on the placing screens.</span></p>';
        echo '</form>';
        echo '<div class="mh-calc-out" aria-live="polite"></div>';
        echo '</div>';
        echo '<style>' . TeamOversight_Payments::standing_pill_css('.murvc-help') . '</style>';
        self::close_section();
    }

    // 8. Trials, payments, emails and privacy ---------------------------

    private static function section_trials() {
        self::open_section('mh-trials');
        echo '<h3>Trials and selections</h3>';
        echo '<ol class="mh-steps">'
            . '<li><strong>Apply</strong> on the trial form ([team_trial_form]), logged in. Each applicant gets a trial number for the season.</li>'
            . '<li><strong>Payment pending</strong> until the trial fee is paid (or waived, or marked paid). Coaches can see, note and reject them, but can\'t select them yet.</li>'
            . '<li><strong>Verdicts</strong>: each team\'s coaches mark Tentative, Selected, Training Only or Rejected. A verdict is not a team place.</li>'
            . '<li><strong>Send offer</strong> (from ' . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . '): a coach presses Send offer on a Selected or Training Only player (or Send offers to all); the committee approves it on the Offers page; the site emails the player, who accepts or declines on the website and locks in their place by paying the deposit in one payment. An offer sent before the season\'s fees are released is an <strong>Early Offer</strong>: it is quoted on the fee matrix upper limit, the fee is set later and is never more than that limit. Changing the verdict of an offer that has been sent means withdrawing it first.</li>'
            . '<li><strong>Locked in</strong>: once the deposit is complete the player is placed on their team automatically, from that day, and is invoiced once fees are released. <strong>Finalise Coach Selections</strong> (Trial Applications) is the retry for a placement that failed. Offers not yet locked in are left alone.</li>'
            . '<li><strong>Not selected</strong> (from ' . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . '): a Rejected player is told nothing until someone presses <em>Send &quot;not selected&quot; email</em> on their card (the team\'s coaches) or Send on the Offers page, Not selected tab (administrators). The email is about that one team only.</li>'
            . '</ol>';
        // Two statements, one paragraph: which selection notices are emailed, then what players learn from where.
        echo '<p>Offers and a few selection notices are emailed, from the Offer emails section of Emails: the offer when the committee approves it (and its reminder), a withdrawal, the coaches\' accepted / declined / deadline-not-met notices, the player\'s &quot;you\'re locked in&quot; note, and the &quot;not selected&quot; email for one team when a coach or an administrator presses Send. Each is checked again just before it is sent, so nothing goes out about an offer (or a verdict) that has changed. Nothing leaves a copy of the site: the live site has to be opened for sending first. Accepting, rejecting, a verdict change and Finalise themselves email nobody. ';
        echo 'Players learn they are on a team from Ready to Play once they have a team place (from a locked-in offer, Finalise, Accept or Add Assignment). Before that they learn about their selection only from an offer that has been sent to them (their offer page, and a banner on Ready to Play), never from a verdict on its own.</p>';

        echo '<h3>Payments and the ledger</h3>';
        self::ul(array(
            'The payments ledger is the only record of money received: paid is the sum of an invoice\'s ledger rows, and outstanding is always fee minus paid. Nobody edits outstanding directly, and re-pricing a fee never touches a payment.',
            'Members pay any amount online, any time ([member_fees] and the Ready to Play fees step): what is overdue, the full balance or an amount they choose. Payments go to the oldest season first, except an offer deposit, which goes only to its own season; anything above the balance is recorded as credit.',
            'Record Payment on Payment Management adds cash, bank transfers and corrections, stamped with who recorded it.',
            'Refunds are manual: a refund or cancellation in WooCommerce never changes the ledger by itself. It is logged and listed at the top of Payment Management for 30 days, and the treasurer enters it as a negative Record Payment (with the order number in the note).',
        ));

        echo '<h3>Reminder emails</h3>';
        echo '<p>Optional and off by default, set on ' . self::admin_link('team-oversight-emails', 'Emails') . ': the wording, From and Reply-To, the interval and grace period, a preview of who would be emailed, and Send reminders now. Section 5 shows the current settings. The same page holds the trial applicant emails (received, payment needed, payment received, changes saved).</p>';
        echo '<p>Every email shares one club look: the logo and club name, the program it\'s about above the heading (<em>Club fees · 2026</em>, <em>Trials · 2027</em>), the message, and the same footer. The wording stays editable as plain text; the plugin draws the parts that hold figures. The reminder\'s fee cards show what the Player Checklist shows (season fee, paid so far, remaining, overdue now or the next payment date, and the progress bar), each worked out the same way as the checklist and the pay box, as at the day it\'s sent. They never name a team.</p>';

        echo '<h3>Coach portal privacy</h3>';
        self::ul(array(
            'Coaches see only the teams they coach, enforced on the server. Coaching a season opens it until 1 April of the following year, then it closes.',
            'Committee members can be given view-only or edit access to every team on ' . 'Committee Access' . '; admins always have edit access.',
            'Fees appear on the team list as percentages only, never dollar amounts.',
            'Emergency contacts are looked up by the member\'s account. Exact dates of birth are shown on purpose: coaches need them for Volleyball Victoria age cutoffs and exemptions.',
        ));
        self::close_section();
    }

    // 9. Offers and deposits --------------------------------------------

    /**
     * The committee's guide to running offers (1.78.0). Written in plain
     * English; every figure, date and setting is read live (never typed in).
     */
    private static function section_offers($season) {
        $applies = TeamOversight_Offers::applies($season);
        $setup = TeamOversight_Offers_Admin::page_url(array('tab' => 'setup', 'season' => $season));
        $link = function ($url, $label) {
            return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        };
        self::open_section('mh-offers');
        if (!$applies) {
            echo '<p>Offers apply from the ' . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . ' season. Pick a later season above to see this section\'s live figures; the guide below still applies.</p>';
        } else {
            $settings = TeamOversight_Offers::settings($season);
            $counts = TeamOversight_Offers::counts_by_state($season);
            $tally = TeamOversight_Offers_Setup::tally(TeamOversight_Offers_Setup::checks($season));
            echo '<p><strong>Right now for ' . esc_html($season) . ':</strong> offer emails ' . (TeamOversight_Offers::emails_live() ? 'may be sent from this website' : 'are not being sent from this website yet') . '; '
                . intval($counts['awaiting_approval']) . ' awaiting approval, ' . intval($counts['sent'] + $counts['accepted']) . ' waiting on players, ' . intval($counts['locked_in']) . ' locked in. '
                . 'The setup checklist has ' . intval($tally['bad']) . ' thing(s) to fix and ' . intval($tally['warn']) . ' to check (' . $link($setup, 'open the checklist') . ').</p>';
        }

        echo '<h3>In plain English</h3>';
        echo '<p>An <strong>offer</strong> is the club asking a player to join a team, sent from the website so the club can see where every offer is up to. The coach still makes the selection call (their verdict). The offer is what happens next: an administrator approves it, the player is emailed and accepts or declines on the website, and they <strong>lock in</strong> their place by paying a <strong>deposit</strong>, a small share of the season fee, in one payment. The moment the deposit is complete they are put on the team automatically and the coach sees them under Confirmed. Nobody has to key anything in by hand.</p>';
        echo '<p>Offers can go out before the season\'s fees are set. Such an offer is an <strong>Early Offer</strong>: it is quoted on the fee matrix\'s <strong>upper limit</strong> (the most the fee can be), the real fee is set later and is never more than that limit, and the deposit comes off the final fee.</p>';
        echo '<p><strong>' . esc_html(TeamOversight_Offers::controls_sentence()) . '</strong> Seasons before ' . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . ' have no offers at all: Finalise Coach Selections places their players as it always has.</p>';

        echo '<h3>The flow, step by step</h3>';
        echo '<ol class="mh-steps">'
            . '<li><strong>Coach selects.</strong> The verdict is Selected or Training Only (Tentative and Rejected can\'t be offered, and neither can a payment-pending applicant). A player a <em>higher-division</em> team is still deciding about (Tentative or Selected there) can\'t be offered by the team below: that coach decides first.</li>'
            . '<li><strong>Coach presses Send offer</strong> on a card, or Send offers to all. Nothing is emailed. The offer is now <em>awaiting committee approval</em> and the coach can cancel it.</li>'
            . '<li><strong>An administrator approves</strong> on the ' . TeamOversight_Offers_Setup::offers_link('Offers') . ' page, To approve tab (singly or in bulk). Approving works out and fixes the quote, the deposit and the two dates (they start that day), makes the offer live on the player\'s own pages, and emails the player and copies the team\'s coaches. The email waits while this website may not send offer emails; the offer and its dates do not, so give that permission before approving. Declining shows the coach <em>Not approved</em> and tells the player nothing.</li>'
            . '<li><strong>The player opens their offer page</strong> from the email (they must be logged in as the person it was sent to) and chooses Accept or Decline. They can\'t pay anything before accepting.</li>'
            . '<li><strong>Accepted:</strong> the coaches get an email, and the player pays the deposit in one payment from the offer page or the Player Checklist. From the moment they accept, their Player Checklist counts the team (VV registration, shirt, shorts and socks, with the usual ticks), says they have accepted a place and must pay the deposit to lock it in, and its fees step points to the deposit box. It never says they are on the team before the deposit is paid.</li>'
            . '<li><strong>Locked in:</strong> when the deposit is complete the player is put on the team from that day, the application is marked accepted, the coach portal lists them under Confirmed, and the player gets a "you\'re locked in" email. From then on they pay the rest of the fee like any other player.</li>'
            . '<li><strong>Missed dates:</strong> an offer with no reply by the reply-by date, or no deposit by the deposit date, is flagged <em>Overdue</em> on the coach portal and the coaches get one email. Nothing is released automatically: the coach (or an administrator) chooses Remind, +7 days or Move to declined.</li>'
            . '<li><strong>Rejected players:</strong> nothing is sent to them automatically. The card reads <em>Rejected - not told yet</em> and has a <em>Send &quot;not selected&quot; email</em> button (and the board a <em>Send &quot;not selected&quot; emails</em> panel for all of them at once); anyone with edit access to that team can press it, and it asks first. The email is about that one team: if the player applied for more than one team it says so, says this message is only about that team and that their other applications are decided separately, never mentions another team\'s decision, and suggests lower divisions to trial for (below that team, by age and gender, leaving out teams they already applied for or were turned down by), or else the Mixed Training Program and the Reds Social League (the Junior Academy, as extra training, for juniors). It is addressed like the offer emails: it goes to the player from the club\'s sender, replies go to the coach who pressed Send when they coach that team (when the committee or an administrator presses it, to the team\'s head coach; with no coach on file, to the trial applications address), every coach and assistant coach of the team is copied (including the one replies go to), and it is signed by the coach replies go to (or by the team\'s coaching team when no coach is on file). Coaches of the player\'s other teams are never copied. A coach can send it while other teams are still deciding about the player. It goes once per player and team, the card then says when and who sent it (or that it is waiting for the permission to send offer emails), and the flow chart counts the team\'s Rejected players not told yet. Administrators see every rejection nobody has told, with how long ago, on the Offers page, Not selected tab (Send, Send all, or Don\'t send).</li>'
            . '</ol>';

        echo '<h3>The settings, and where each is changed</h3>';
        if ($applies) {
            $pct = rtrim(rtrim(number_format((float) $settings['percent'], 2, '.', ''), '0'), '.');
            echo '<p>This season\'s numbers, read live: reply within <strong>' . intval($settings['reply_days']) . ' days</strong>; deposit <strong>' . esc_html($pct === '' ? '0' : $pct) . '%</strong> of the quoted fee; the deposit is due on the later of <strong>' . esc_html(self::d($settings['deposit_date'])) . '</strong> and <strong>' . intval($settings['deposit_days']) . ' days</strong> after the offer is sent (never after the late-join cutoff). ' . esc_html(TeamOversight_Offers_Setup::settings_example($season)) . '</p>';
        }
        $where = array(
            array('Permission to send offer emails', 'The safeguard (there is no offers on/off switch): offer emails leave only from the website the permission was given to, so a test copy of the site can never email players, and pausing it holds every offer email. If the website\'s address changes the permission is lost and every offer email waits until you give it again.', 'Emails, Offers and selection (also on Configuration and the Offers page)', TeamOversight_Offers_Setup::emails_url('offers', 'offer-gate')),
            array('Reply days, deposit days, fixed deposit date, deposit percent', 'The two dates and the deposit on every offer sent from now on. An offer keeps the figures it was sent with.', 'Configuration, Offers and deposits, offer settings', TeamOversight_Offers_Setup::config_url($season, 'offer-settings')),
            array('Upper limits and fees', 'Every fee cell holds a fee (blank until known) and an upper limit. Before the fees are released an offer can\'t be sent for a player whose cell has no limit; after the release, for one whose cell has no fee.', 'Configuration, Fee Matrix', TeamOversight_Offers_Setup::config_url($season, 'fee-matrix')),
            array('Fee release date', 'Until it, offers are Early Offers quoted on the upper limit; from it, offers quote the real fee.', 'Configuration, Fee release', TeamOversight_Offers_Setup::config_url($season, 'fee-release')),
            array('Division levels', 'The order of the divisions (1 is the highest). They decide the higher-team rule and the lower divisions the "not selected" email suggests. A team with level 0 is never judged.', 'Configuration, Team Management', TeamOversight_Offers_Setup::config_url($season, 'team-management')),
            array('The words', 'Every offer email, the commitments and deposit policy, the Early Offer explanation, the lines on the offer page, the coach portal\'s flow chart. Each has the built-in text beside it and a way to put it back.', 'Emails, Offers and selection', TeamOversight_Offers_Setup::emails_url('offers')),
            array('Pages the offers link to', 'The offer page, the Player Checklist, the trial form, the coach portal, the Trials & Team Info page and the Academy / Social League pages.', 'Emails, Offers and selection, Pages and links', TeamOversight_Offers_Setup::emails_url('offers', 'offer-pages')),
            array('The deposit payment product', 'The member payment product deposits are paid through. Without it a player sees "Online payment isn\'t available yet".', 'Payment Management, Payment settings', admin_url('admin.php?page=team-oversight-invoices')),
        );
        echo '<div class="mh-scroll"><table class="widefat striped mh-table"><thead><tr><th scope="col">Setting</th><th scope="col">What it does</th><th scope="col">Where</th></tr></thead><tbody>';
        foreach ($where as $row) {
            echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . esc_html($row[1]) . '</td><td>' . $link($row[3], $row[2]) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p>The Offers page has a <strong>Setup</strong> tab that checks all of this live (and so does Configuration): every row is ready, worth a look, or needs fixing, with a link to the screen that fixes it. It never blocks anything.</p>';

        echo '<h3>What to do, week by week</h3>';
        echo '<ol class="mh-steps">'
            . '<li><strong>Before internal trials.</strong> Open the setup checklist and clear every Fix: season dates, the fee release date, an upper limit in every cell, a division level on every team, the deposit payment product, the offer page. Press Save on the offer settings once, so the numbers are yours. Read the offer wording once and change anything that doesn\'t sound like the club. Test-send the emails to yourself.</li>'
            . '<li><strong>Before you approve the first offer.</strong> Give the permission to send offer emails (only on the live website). Coaches can send offers before then, and the requests simply wait on To approve. Approving is what starts an offer: it fixes the reply-by and deposit dates from that day and shows the offer on the player\'s own pages, and only the email waits for the permission. An offer approved while emails can\'t be sent loses days from its reply-by date before the player hears about it, and if the permission comes more than a day later its email is held for you to send or discard. Check at least one offer from start to finish with a real coach before the rest go.</li>'
            . '<li><strong>Every week or so during selection.</strong> Open the Offers page, Not selected tab: every player a team has turned down and nobody has told, with how long ago (players rejected before offers began are there too). Nothing is sent by itself. Remind the coach, or press Send (or Send all), or Don\'t send for a player who should not get one. The status strip flags rejections more than a week old.</li>'
            . '<li><strong>Every day while offers are going out.</strong> Open the Offers page. Approve what is waiting (check the quote and the "Also holds" and "Earlier balance" columns first). Clear anything under Needs attention. Look at Waiting on players, overdue ones first: Remind, +7 days, or leave them for the coach. The status strip says whether the daily check last ran.</li>'
            . '<li><strong>Around each reply-by and deposit date.</strong> The coaches get one "deadline not met" email per missed date. They decide; you can do the same from the Offers page.</li>'
            . '<li><strong>When fees are released (January).</strong> Offers quoted on the limit drop to the real fee automatically if it is lower (a deposit can only go down). Anyone locked in is charged at most what they were quoted. Look at Locked in for anyone Not placed, and at Held deposits for money to settle.</li>'
            . '<li><strong>During public trials and the season.</strong> Offers carry on the same way, now quoting the real fee. The treasurer works through Held deposits: refunds the credit the club owes back, and decides the few rows waiting for a decision (Refund owed, or Club keeps it). Declines and moves to Declined are kept by the site itself (the Forfeited list).</li>'
            . '<li><strong>Next season.</strong> Create the season on Configuration, copy the fee matrix (limits are copied, fees left blank), and start the checklist again.</li>'
            . '</ol>';

        echo '<h3>What each coach portal state means</h3>';
        $coach = array(
            array('Selected, offer not sent', 'The coach has made the call and hasn\'t asked for an offer yet.', 'Send offer.'),
            array('Awaiting committee approval', 'The request is with the administrators. The coach can cancel it.', 'An administrator approves or declines it.'),
            array('Not approved', 'An administrator declined the request. The player was told nothing.', 'The coach can ask again (Send offer again).'),
            array('Offer sent, reply by a date', 'Approved and emailed. Waiting for the player.', 'The player accepts or declines. The coach can Withdraw.'),
            array('Accepted, deposit N% paid', 'The player said yes. The coach sees the percentage of the deposit paid, never dollars.', 'The player pays; at 100% they lock in.'),
            array('Overdue', 'A reply or deposit date has passed.', 'Remind, +7 days, or Move to declined.'),
            array('Declined', 'The player said no, or the coach moved them to Declined. They are in their own Declined list.', 'Offer again (goes back through approval), or change the verdict.'),
            array('Locked in', 'The deposit is complete. The player is being, or has been, put on the team.', 'They move to Confirmed once placed.'),
            array('Early Offer ...', 'The same states for an offer quoted on the upper limit; the wording just says Early Offer.', 'Nothing different to do.'),
            array('Rejected - not told yet', 'The team turned the player down and nobody has sent them the "not selected" email. Nothing is sent by itself.', 'A coach presses Send "not selected" email (it asks first), or an administrator sends it (or chooses Don\'t send) on the Not selected tab.'),
            array('Not selected email sent (date, by whom)', 'The player was told they weren\'t selected for this team only. If the email is still waiting for the permission to send offer emails, the card says so.', 'Nothing: it is sent once per player and team. If the verdict changes later the card keeps it as history.'),
        );
        echo '<div class="mh-scroll"><table class="widefat striped mh-table"><thead><tr><th scope="col">The card says</th><th scope="col">What it means</th><th scope="col">What happens next</th></tr></thead><tbody>';
        foreach ($coach as $row) {
            echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p>The words behind each state (as an administrator sees them on the Offers page) are listed under <em>What the states mean</em> at the foot of every Offers tab.</p>';
        echo '<dl class="mh-facts">';
        $states = TeamOversight_Offers::states();
        foreach (TeamOversight_Offers_Setup::glossary() as $state => $sentence) {
            echo '<div><dt>' . esc_html(isset($states[$state]) ? $states[$state] : $state) . '</dt><dd>' . esc_html($sentence) . '</dd></div>';
        }
        echo '</dl>';

        echo '<h3>When something changes after the offer is out</h3>';
        self::ul(array(
            'A player pulls out after they are locked in: on the Locked in tab press Player pulled out and choose who broke the commitment. The place comes off the team first, then the offer ends. Nobody is emailed: speak to the player yourself. If the player broke it, the deposit is kept (Club keeps it); if the club did, it stays as credit to refund (Refund owed).',
            'The club withdraws an offer (or a locked-in player): press Withdraw. The player gets a short email and the coach can then change the verdict. A deposit paid is the player\'s to have back, so it goes on the Held deposits list as credit. A coach can withdraw an offer nothing has been paid on; once money is held only an administrator can.',
            'The coach picked the wrong team or player: if nothing has been paid, the coach withdraws the offer and sends a new one. If a deposit has been paid, an administrator withdraws it and the money is refunded from Held deposits.',
            'A player pays and nothing seems to happen: look at the Locked in tab for Not placed and a reason, and press Retry placement (it takes a start date). The daily check also retries.',
            'A deposit was paid by mistake, or twice: money above what the offer needs shows on Held deposits. The treasurer marks it Refund owed and refunds it from Payment Management with a negative Record Payment.',
            'The player says they never got the email: the Offers page status strip lists emails that failed or may not have gone out, each with Resend. Check the player\'s account email is right, and that the permission to send is on.',
        ));

        echo '<h3>How to hold things back</h3>';
        echo '<p>There is no switch to turn offers off: from ' . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . ' they are how selection works. Two controls remain. <strong>Approval:</strong> no offer reaches a player until an administrator approves it, so leaving requests on the To approve tab holds them. <strong>Pause offer emails:</strong> nothing is sent until you allow the website to send them again, and queued messages wait. When you allow it again, offer emails more than a day old (a "not selected" email pressed during the pause included) are held for you to send or discard rather than mass-sent. Two things are not held back by a pause: an offer approved during it is live at once (its dates run and the player can see it on their own pages; only the email waits), and the coaches\' "deadline not met" email goes out with the next daily check for each offer that is overdue then. Offers already out can still be answered and paid while emails are paused. <strong>Switching an email off</strong> (the tick beside it under Emails, Offers and selection) is not a pause: while it is off, every one of those emails that falls due is skipped for good and is not sent when you switch it back on.</p>';

        echo '<h3>Who can do what</h3>';
        $roles = array(
            array('Coach', 'Selects, sends offers (one or all), cancels a request before approval, reminds, gives 7 more days, moves to declined, withdraws an offer nothing has been paid on, and sends a Rejected player the "not selected" email for their own team (one or all). Sees percentages only, never dollars.'),
            array('Administrator (a WordPress Administrator account)', 'Approves or declines offers, resends failed emails, withdraws any offer, records a pull-out, retries a placement, sends (or chooses Don\'t send for) the "not selected" emails nobody has sent, and changes every setting. Committee Access "Edit" lets someone act like a coach on every team; it does not let them approve.'),
            array('Treasurer', 'Works the Held deposits list: refunds the credit the club owes back by hand from Payment Management, decides the few deposits waiting for a decision (Refund owed, Club keeps it), and can Reverse a forfeit the site made. Needs an Administrator account.'),
            array('Player', 'Opens their own offer page, accepts or declines, pays the deposit once. Sees only their own offers.'),
        );
        echo '<dl class="mh-facts">';
        foreach ($roles as $role) {
            echo '<div><dt>' . esc_html($role[0]) . '</dt><dd>' . esc_html($role[1]) . '</dd></div>';
        }
        echo '</dl>';
        if (class_exists('TeamOversight_Offers_Setup')) {
            $who = TeamOversight_Offers_Setup::approvers();
            echo '<p>Right now these people can approve: <strong>' . esc_html(empty($who) ? 'nobody' : implode(', ', $who)) . '</strong>. If they are all away, approvals wait. A developer can widen this with the <code>murvc_offers_can_approve</code> filter.</p>';
        }

        echo '<h3>The money, in one place</h3>';
        self::ul(array(
            'The deposit is a fixed share (' . ($applies ? esc_html(rtrim(rtrim(number_format((float) $settings['percent'], 2, '.', ''), '0'), '.')) . '%' : 'a percentage') . ') of the quoted fee, paid in one payment. If the player also holds another accepted or locked-in place, the fee (and so the deposit) is worked out across both places, so a second offer may only need a top-up.',
            'Deposit money goes to its own season only; it is never used up on an older balance.',
            'If the club breaks its commitment (the fee comes in above the quoted maximum, the club withdraws the offer or drops the player), the deposit is the player\'s to have back: the site holds it on their account as credit the moment the offer is withdrawn, and the treasurer refunds it by hand.',
            'If the player breaks theirs (declines after paying, misses the deposit date and is moved to Declined, or pulls out), the club keeps it: the site writes a negative "forfeit" line to the ledger the moment the offer ends, so the books balance, and the Forfeited list on the Held deposits tab shows what was kept (Reverse forfeit gives it back). Nobody has to decide these by hand.',
            'Money above the final fee stays on the account as credit.',
            'The upper limit is a promise: a player whose place came from an offer quoted on the limit is never invoiced more than they were quoted. Where the cap applied it is logged.',
            'Wording for all of this is editable: Emails, Offers and selection, Offer wording, Money policy.',
        ));

        echo '<h3>Rules that are fixed in the code</h3>';
        echo '<p>Not everything is a setting. These numbers are built in; changing one needs a developer.</p>';
        $fixed = array(
            array('Extra time when you press +7 days', TeamOversight_Offers::EXTEND_DAYS . ' days, counted from today', 'TeamOversight_Offers (the coach_overdue email also says 7)'),
            array('When a "not selected" email is sent', 'Only when someone presses Send, never by itself; about one team; once per player and team. Rejections nobody has told for ' . TeamOversight_Not_Selected::UNTOLD_FLAG_DAYS . ' days are flagged on the Offers page', 'TeamOversight_Not_Selected'),
            array('Who a "not selected" email is from', 'The club\'s sender. Reply-To the coach who pressed Send when they coach that team, else its head coach, else (no coach on file) the trial applications address; every coach and assistant coach of that team copied, including the Reply-To coach, read from the roster when it is sent; signed by the Reply-To coach', 'TeamOversight_Offer_Emails'),
            array('Reminders to one player', 'At most one in 24 hours', 'TeamOversight_Offers (do_remind)'),
            array('Email tries', TeamOversight_Offer_Emails::MAX_TRIES . ' tries, ' . TeamOversight_Offer_Emails::RETRY_MINUTES . ' minutes apart; a message stuck ' . TeamOversight_Offer_Emails::STUCK_MINUTES . ' minutes is "not sure if sent" and never retried automatically', 'TeamOversight_Offer_Emails'),
            array('Teams the higher-team rule ignores', 'Teams whose age rule is ' . implode(' or ', array_map('strtoupper', TeamOversight_Offers::BLOCK_EXEMPT_AGE_RULES)) . ' (JPL and U17), whatever their level', 'TeamOversight_Offers'),
            array('Who gets the extra training line, and who the extra games line', 'Under ' . TeamOversight_Offers::EXTRAS_ADULT_AGE . ' at the season start gets the Junior Academy line; ' . TeamOversight_Offers::EXTRAS_ADULT_AGE . ' and over the Social League line; no date of birth counts as an adult', 'TeamOversight_Offers'),
            array('Who counts as a junior in the "not selected" email', 'Born on or after the U19 date-of-birth cut-off for the season, so an 18-year-old can read the Social League line in an offer email and the Junior Academy suggestion in a "not selected" email', 'TeamOversight_Not_Selected'),
        );
        echo '<div class="mh-scroll"><table class="widefat striped mh-table"><thead><tr><th scope="col">Rule</th><th scope="col">Value</th><th scope="col">Where it lives</th></tr></thead><tbody>';
        foreach ($fixed as $row) {
            echo '<tr><th scope="row">' . esc_html($row[0]) . '</th><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<h3>The daily check and the website\'s scheduler</h3>';
        list($daily_text, $daily_state) = TeamOversight_Offers_Setup::daily_words();
        echo '<p>Once a day the site checks for missed dates (and emails the coaches), retries any placement or pricing that failed and tidies the email queue (it never sends a "not selected" email by itself: it only re-queues one someone pressed whose message went missing). It runs on WordPress\'s scheduler, which only fires when someone visits the website, so a quiet weekend can delay it. <strong>' . esc_html($daily_text) . '</strong> The Offers page shows this too and has a Run the daily check now button.</p>';

        echo '<h3>When something looks wrong</h3>';
        $trouble = array(
            array('"Needs attention" on the Offers page', 'Each line says what and links to the tab. Locked in but not placed: Retry placement. Could not be priced: check the fee matrix cell for that player. Account that no longer exists: see Member Lookup.'),
            array('A coach says Send offer is greyed out', 'Hover over it. Before the fees are released, the player\'s fee cell has no upper limit (add one); after they are released, the cell has no fee (add the fee: an offer is then quoted on the real fee). The Setup checklist lists every such cell. Or a higher team is still deciding about them (their coach decides first).'),
            array('Nothing is being emailed', 'Check the Offers page status strip: "waiting to send" with a red permission box means the website has no permission to send (give it, on the live site only). Failed or "not sure if sent" messages have a Resend button. A message older than a day is held for you to send or discard.'),
            array('The player sees "Online payment isn\'t available yet"', 'No member payment product is set: Payment Management, Payment settings.'),
            array('The offer email button goes to the wrong page', 'Setup checklist, pages: the page was guessed. Create or choose the right page under Emails, Offers and selection, Pages and links.'),
            array('The daily check says it has not run', 'It needs website visits. Press Run the daily check now; if a step failed, Logs has the detail.'),
            array('A held deposit is on the list and nobody knows why', 'Open the player in Member Lookup: the offers list says how the offer ended. A row marked Credit is a withdrawal: refund it. A row waiting for a decision: the club broke it, Refund owed; the player broke it, Club keeps it. A deposit the site kept is on the Forfeited list instead (Reverse forfeit if that was wrong).'),
            array('The fee came out higher than the offer said', 'It can\'t, for a place quoted on the upper limit: the cap holds it, and Logs shows when it applied. For an offer quoted on the real fee, the quote is the fee.'),
        );
        echo '<dl class="mh-facts">';
        foreach ($trouble as $row) {
            echo '<div><dt>' . esc_html($row[0]) . '</dt><dd>' . esc_html($row[1]) . '</dd></div>';
        }
        echo '</dl>';
        self::close_section();
    }

    // 10. For whoever maintains this next --------------------------------

    private static function section_maintainers() {
        self::open_section('mh-maintainers');
        echo '<dl class="mh-facts">';
        $facts = array(
            array('Version', 'MURVC Club Manager ' . TEAM_OVERSIGHT_VERSION . ' is running on this site.'),
            array('Where the code lives', 'The plugin is custom code in the public GitHub repository jjdunlop/team-oversight-migration. Because it is public, never put member data, credentials or hosting details in it: no database exports, screenshots of admin pages, real names or emails, passwords or keys.'),
            array('How it goes live', 'The live site pulls the repository\'s main branch from GitHub: a push doesn\'t deploy by itself, but the Deployer for Git plugin on the site pulls within about a minute of one (after the planned hosting move, the new host\'s own Git deployment will pull it instead). So treat every push to main as going live.'),
            array('Read first', 'README.md explains what the plugin does, area by area. CLAUDE.md is the maintainer\'s rulebook: the hard-won rules, the money invariants and the privacy rules, written so that an AI assistant or a developer can carry on safely.'),
            array('Test before pushing', 'Test every change on a local copy of the site first (it holds real member data, so it stays on the maintainer\'s machine and never goes in the repository). Never edit the plugin\'s files on the live site: the next pull overwrites them.'),
            array('Outside the plugin', 'Some behaviour lives in WPCode snippets on the live site, outside this plugin (the annual profile-update wall and some admin tools). If something on live isn\'t explained by the code, check WPCode.'),
            array('Safe by design', 'Deleting the plugin never deletes data (there is no uninstall routine). This page writes nothing; its examples and calculator must stay worked out by the engine, never typed in.'),
        );
        foreach ($facts as $fact) {
            echo '<div><dt>' . esc_html($fact[0]) . '</dt><dd>' . esc_html($fact[1]) . '</dd></div>';
        }
        echo '</dl>';
        echo '<p><a href="https://github.com/jjdunlop/team-oversight-migration" target="_blank" rel="noopener noreferrer">Open the repository on GitHub</a></p>';
        self::close_section();
    }

    // ------------------------------------------------------------------
    // Styles and script
    // ------------------------------------------------------------------

    private static function styles() {
        return '
.murvc-help { max-width: 1100px; }
.murvc-help .mh-lead { font-size: 14px; max-width: 760px; }
.murvc-help .mh-toc { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px 16px; margin: 12px 0 8px; }
.murvc-help .mh-toc ol { display: flex; flex-wrap: wrap; gap: 4px 20px; margin: 0; padding-left: 18px; }
.murvc-help .mh-section { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 4px 20px 8px; margin: 16px 0; box-sizing: border-box; }
.murvc-help .mh-section h2 { display: flex; align-items: center; gap: 10px; font-size: 1.3em; margin: 16px 0 8px; }
.murvc-help .mh-num { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: #2271b1; color: #fff; font-size: 13px; flex: none; }
.murvc-help h3 { font-size: 1.05em; margin: 18px 0 6px; }
.murvc-help p, .murvc-help li, .murvc-help dd { max-width: 820px; }
.murvc-help .mh-list { list-style: disc; padding-left: 20px; }
.murvc-help .mh-list li { margin-bottom: 6px; }
.murvc-help .mh-top { text-align: right; font-size: 12px; margin: 8px 0 4px; }
.murvc-help .mh-scroll { overflow-x: auto; max-width: 100%; margin: 8px 0; }
.murvc-help .mh-table { border-collapse: collapse; }
.murvc-help .mh-table th[scope=row] { font-weight: 600; }
.murvc-help .mh-matrix { width: auto; min-width: 420px; }
.murvc-help .mh-blank { color: #996800; font-style: italic; }
.murvc-help .mh-facts { margin: 8px 0; }
.murvc-help .mh-facts > div { display: grid; grid-template-columns: minmax(140px, 220px) 1fr; gap: 4px 16px; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
.murvc-help .mh-facts dt { font-weight: 600; }
.murvc-help .mh-facts dd { margin: 0; }
.murvc-help .mh-picker { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 8px 0 12px; }
.murvc-help .mh-picker .description { flex-basis: 100%; }
.murvc-help .mh-warn { background: #fcf9e8; border-left: 4px solid #dba617; padding: 8px 12px; }
.murvc-help .mh-note { color: #8a6100; }
.murvc-help .mh-muted { color: #646970; }
.murvc-help .mh-figure { margin: 12px 0; max-width: 680px; }
.murvc-help .mh-figure figcaption { color: #50575e; font-size: 12px; margin-top: 4px; }
.murvc-help .mh-diagram, .murvc-help .mh-chart { display: block; width: 100%; height: auto; }
.murvc-help .mh-d-label { font-size: 13px; fill: #1d2327; }
.murvc-help .mh-d-strong { font-weight: 600; }
.murvc-help .mh-d-note { font-size: 12px; fill: #50575e; }
.murvc-help .mh-d-axis { font-size: 11px; fill: #646970; }
.murvc-help .mh-d-train { fill: #9ec2e6; }
.murvc-help .mh-d-play { fill: #2271b1; }
.murvc-help .mh-d-rule { stroke: #c3c4c7; stroke-width: 1; }
.murvc-help .mh-d-mark { stroke: #1d2327; stroke-width: 2; }
.murvc-help .mh-d-dash { stroke-dasharray: 4 3; }
.murvc-help .mh-c-plot { fill: #fff; stroke: #dcdcde; }
.murvc-help .mh-c-window { fill: #e7f0f9; }
.murvc-help .mh-c-label { font-size: 11px; fill: #2c5d8f; }
.murvc-help .mh-c-grid { stroke: #dcdcde; stroke-width: 1; }
.murvc-help .mh-c-tick { stroke: #8c8f94; stroke-width: 1; }
.murvc-help .mh-c-axis { font-size: 11px; fill: #50575e; }
.murvc-help .mh-c-today { stroke: #50575e; stroke-width: 1.5; stroke-dasharray: 4 3; }
.murvc-help .mh-c-line { fill: none; stroke: #2271b1; stroke-width: 2; stroke-linejoin: round; }
.murvc-help .mh-c-dot { fill: #fff; stroke: #2271b1; stroke-width: 2; }
.murvc-help .mh-examples { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 440px), 1fr)); gap: 16px; }
.murvc-help .mh-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; box-sizing: border-box; min-width: 0; }
.murvc-help .mh-example h3 { margin-top: 4px; }
.murvc-help .mh-inputs { margin: 6px 0; padding: 0; list-style: none; }
.murvc-help .mh-inputs li { display: grid; grid-template-columns: 72px 1fr; gap: 8px; margin: 2px 0; }
.murvc-help .mh-inputs span { color: #646970; }
.murvc-help .mh-stretches { min-width: 560px; }
.murvc-help .mh-fee { font-size: 15px; margin: 8px 0; }
.murvc-help .mh-today { margin: 6px 0; padding-left: 18px; list-style: disc; }
.murvc-help .mh-refusal { background: #fcf0f1; border-left: 4px solid #d63638; padding: 8px 12px; }
.murvc-help .mh-steps { padding-left: 20px; }
.murvc-help .mh-steps li { margin-bottom: 6px; }
.murvc-help .mh-calc { margin: 12px 0; }
.murvc-help .mh-row { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: flex-end; }
.murvc-help .mh-row label { display: flex; flex-direction: column; gap: 2px; font-weight: 600; }
.murvc-help .mh-row select, .murvc-help .mh-row input { max-width: 100%; }
.murvc-help .mh-place { border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 12px 12px; margin: 12px 0 0; min-width: 0; }
.murvc-help .mh-place legend { padding: 0 4px; font-weight: 600; }
.murvc-help .mh-calc-out { margin-top: 12px; }
.murvc-help .mh-calc-out .mh-error { color: #b32d2e; font-weight: 600; }
.murvc-help .mh-status { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
@media (max-width: 782px) {
    .murvc-help .mh-section { padding: 4px 12px 8px; }
    .murvc-help .mh-facts > div { grid-template-columns: 1fr; }
    .murvc-help .mh-matrix { min-width: 0; }
}
';
    }

    /**
     * The calculator: posts its form to ajax_calculator() and builds the
     * answer with createElement / createElementNS and textContent only
     * (never markup), listeners attached with addEventListener. Without
     * JavaScript the calculator stays hidden and the page is unchanged.
     */
    private static function render_script() {
        ?>
        <script>
        (function () {
            var root = document.getElementById('murvc-help-calc');
            if (!root || !window.fetch || !window.FormData || !window.JSON) {
                return;
            }
            var config;
            try { config = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
            var form = root.querySelector('form');
            var out = root.querySelector('.mh-calc-out');
            var seasonSelect = form.querySelector('.mh-calc-season');
            var SVG = 'http://www.w3.org/2000/svg';
            root.hidden = false;

            function el(tag, cls, text) {
                var node = document.createElement(tag);
                if (cls) { node.className = cls; }
                if (text !== undefined && text !== null) { node.textContent = String(text); }
                return node;
            }
            function svgEl(tag, attrs, text) {
                var node = document.createElementNS(SVG, tag);
                Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, String(attrs[key])); });
                if (text !== undefined && text !== null) { node.textContent = String(text); }
                return node;
            }
            function clear(node) {
                while (node.firstChild) { node.removeChild(node.firstChild); }
            }
            function setSeason(season) {
                var teams = (config.teams && config.teams[season]) || [];
                var starts = (config.starts && config.starts[season]) || {};
                Array.prototype.forEach.call(form.querySelectorAll('.mh-calc-team'), function (select) {
                    var optional = select.getAttribute('data-optional') === '1';
                    clear(select);
                    if (optional) {
                        var none = el('option', '', 'none');
                        none.value = '';
                        select.appendChild(none);
                    }
                    teams.forEach(function (code) {
                        var option = el('option', '', code);
                        option.value = code;
                        select.appendChild(option);
                    });
                });
                Array.prototype.forEach.call(form.querySelectorAll('.mh-calc-start'), function (input) {
                    if (starts.start) { input.value = starts.start; }
                    if (starts.min) { input.min = starts.min; }
                    if (starts.cutoff) { input.max = starts.cutoff; }
                });
            }
            function drawChart(g) {
                var figure = el('figure', 'mh-figure');
                var svg = svgEl('svg', { 'class': 'mh-chart', viewBox: '0 0 ' + g.w + ' ' + g.h, role: 'img', 'aria-label': g.title });
                var p = g.plot;
                svg.appendChild(svgEl('title', {}, g.title));
                svg.appendChild(svgEl('rect', { 'class': 'mh-c-plot', x: p.x, y: p.y, width: p.w, height: p.h }));
                svg.appendChild(svgEl('rect', { 'class': 'mh-c-window', x: g.window.x, y: p.y, width: g.window.w, height: p.h }));
                svg.appendChild(svgEl('text', { 'class': 'mh-c-label', x: g.window.x + 4, y: p.y + 13 }, g.window.label));
                (g.grid || []).forEach(function (line) {
                    svg.appendChild(svgEl('line', { 'class': 'mh-c-grid', x1: p.x, y1: line.y, x2: p.x + p.w, y2: line.y }));
                    svg.appendChild(svgEl('text', { 'class': 'mh-c-axis', x: p.x - 6, y: line.y + 4, 'text-anchor': 'end' }, line.label));
                });
                var bottom = p.y + p.h;
                (g.ticks || []).forEach(function (tick) {
                    svg.appendChild(svgEl('line', { 'class': 'mh-c-tick', x1: tick.x, y1: bottom, x2: tick.x, y2: bottom + 5 }));
                    svg.appendChild(svgEl('text', { 'class': 'mh-c-axis', x: tick.x, y: bottom + 18, 'text-anchor': tick.anchor }, tick.label));
                });
                if (g.today) {
                    svg.appendChild(svgEl('line', { 'class': 'mh-c-today', x1: g.today.x, y1: p.y - 4, x2: g.today.x, y2: bottom }));
                    svg.appendChild(svgEl('text', { 'class': 'mh-c-axis', x: g.today.x, y: p.y - 8, 'text-anchor': g.today.anchor }, g.today.label));
                }
                svg.appendChild(svgEl('polyline', { 'class': 'mh-c-line', points: g.points }));
                (g.markers || []).forEach(function (marker) {
                    var dot = svgEl('circle', { 'class': 'mh-c-dot', cx: marker.x, cy: marker.y, r: 4 });
                    dot.appendChild(svgEl('title', {}, marker.title));
                    svg.appendChild(dot);
                });
                figure.appendChild(svg);
                figure.appendChild(el('figcaption', '', g.caption));
                return figure;
            }
            function table(cls, heads, rows, foot) {
                var wrap = el('div', 'mh-scroll');
                var t = el('table', 'widefat mh-table ' + cls);
                var thead = el('thead');
                var tr = el('tr');
                heads.forEach(function (head) { var th = el('th', '', head); th.setAttribute('scope', 'col'); tr.appendChild(th); });
                thead.appendChild(tr);
                t.appendChild(thead);
                var tbody = el('tbody');
                rows.forEach(function (cells) {
                    var row = el('tr');
                    cells.forEach(function (cell) { row.appendChild(cell); });
                    tbody.appendChild(row);
                });
                t.appendChild(tbody);
                if (foot) { t.appendChild(foot); }
                wrap.appendChild(t);
                return wrap;
            }
            function render(data) {
                clear(out);
                out.appendChild(el('p', 'mh-fee', data.headline));
                var inputs = el('ul', 'mh-inputs');
                (data.places || []).forEach(function (text) {
                    var li = el('li');
                    li.appendChild(el('span', '', 'Place'));
                    li.appendChild(document.createTextNode(' ' + text));
                    inputs.appendChild(li);
                });
                out.appendChild(inputs);
                (data.notes || []).forEach(function (note) { out.appendChild(el('p', 'mh-note', note)); });
                var st = data.stretches || { rows: [] };
                if (st.rows && st.rows.length) {
                    var rows = st.rows.map(function (r) {
                        var places = el('td');
                        r.places.forEach(function (place, i) {
                            if (i > 0) { places.appendChild(el('br')); }
                            places.appendChild(place.top ? el('strong', '', place.text) : document.createTextNode(place.text));
                        });
                        return [el('td', '', r.from), el('td', '', r.last), el('td', '', r.days), places, el('td', '', r.rate), el('td', '', r.amount)];
                    });
                    var tfoot = el('tfoot');
                    var ftr = el('tr');
                    var fth = el('th', '', st.foot);
                    fth.setAttribute('colspan', '5');
                    ftr.appendChild(fth);
                    ftr.appendChild(el('th', '', st.total));
                    tfoot.appendChild(ftr);
                    out.appendChild(table('striped mh-stretches', ['From', 'Last day charged', 'Days', 'Places held', 'Rate', 'Amount'], rows, tfoot));
                } else {
                    out.appendChild(el('p', 'mh-muted', 'No stretch to charge.'));
                }
                if (data.chart) { out.appendChild(drawChart(data.chart)); }
                out.appendChild(table('mh-due', ['On', 'What', 'Due by then'], (data.due || []).map(function (r) {
                    return [el('td', '', r.date), el('td', '', r.label), el('td', '', r.amount + ' (' + r.pct + ')')];
                })));
                var today = el('ul', 'mh-today');
                (data.today || []).forEach(function (line) { today.appendChild(el('li', '', line)); });
                out.appendChild(today);
                var status = el('div', 'mh-status');
                status.appendChild(el('strong', '', 'Status: '));
                status.appendChild(document.createTextNode(data.status || ''));
                out.appendChild(status);
                if (data.pill) {
                    var coach = el('div', 'mh-status');
                    coach.appendChild(el('strong', '', 'Coaches see: '));
                    var pill = el('span', 'cac-fee cac-fee-' + String(data.pill.state).replace(/[^a-z]/g, ''), (data.pill.flag ? '⚠ ' : '') + data.pill.text);
                    pill.title = data.pill.title || '';
                    coach.appendChild(pill);
                    out.appendChild(coach);
                }
            }
            function fail(message) {
                clear(out);
                out.appendChild(el('p', 'mh-error', message));
            }
            seasonSelect.addEventListener('change', function () { setSeason(seasonSelect.value); });
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var data = new FormData(form);
                data.set('action', config.action);
                data.set('_wpnonce', config.nonce);
                clear(out);
                out.appendChild(el('p', 'mh-muted', 'Working it out…'));
                fetch(config.ajax, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (response) { return response.text(); })
                    .then(function (body) {
                        var json = null;
                        try { json = JSON.parse(body); } catch (e) { json = null; }
                        if (!json || typeof json !== 'object') {
                            fail('The calculator is unavailable: your login may have expired. Reload the page.');
                            return;
                        }
                        if (!json.success) {
                            fail((json.data && json.data.message) || 'That could not be worked out.');
                            return;
                        }
                        render(json.data || {});
                    })
                    .catch(function () { fail('The calculator is unavailable: check your connection and try again.'); });
            });
        })();
        </script>
        <?php
    }
}
