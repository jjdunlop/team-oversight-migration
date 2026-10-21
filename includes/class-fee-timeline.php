<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The fee timeline (1.70.1, from the 2027 season: TeamOversight_Fees::
 * timeline_applies()). Each team place (one team_assignments row) has its
 * own start date (inclusive: charged from that day) and stop date
 * (exclusive: the first day off the team; blank = still on), and the fee
 * follows those dates: for each day inside the season dates the person is
 * charged the highest rate among the places they hold that day.
 *
 * This class holds the readers the fee engine prices from: a person's
 * places for a season (rows(), every row, active or stopped), their fee
 * class history (class_history() / record_class()), the request caches that
 * let a page read many people with a fixed number of queries
 * (preload_people() / preload_for_invoices()), and the pure due schedule
 * (schedule(), with behind_floor()). Since 1.70.2 every 2027+ amount due
 * follows schedule(), through TeamOversight_Payments::invoice_schedule()
 * and its wrappers (member fees, the pay box, Ready to Play, Member Lookup,
 * Payment Management, the dashboard, the fee standing and the reminder
 * emails); it also fills the change rows' due_before / due_after, which
 * the behind clock's floor reads. Seasons before 2027 keep the 1.69
 * schedule.
 *
 * The price itself is worked out in TeamOversight_Fees::fee_breakdown(),
 * which builds the stretches from rows() (timeline_stretches()) and prices
 * them through price_stretches(), the same code the fee-segment seasons use.
 *
 * It also holds the writers, the only code that writes 2027+ team places:
 * add_stretches() (Finalise, Accept, bulk accept, Add Assignment),
 * set_start(), stop(), clear_stop(), remove_mistake() (also Undo
 * Assignment), change_role(), preview() (the same edit priced in memory)
 * and apply_due_stops() (daily: a future stop takes effect). Each writes a
 * change row first, writes with compare-and-set, keeps the trial verdicts
 * in step and re-prices once, under the person's account email. Callers
 * check the nonce and manage_options.
 *
 * And the Member Lookup card: render_person_card() (the person's places
 * drawn across the season, the forms that change them, the stretches, the
 * money and the change history), handle_post() (admin-post: saves one
 * form through the writers, then Post/Redirect/Get back to the card) and
 * ajax_preview() (prices the same change without saving). All three need
 * manage_options; the two endpoints also need the place's own nonce.
 */
class TeamOversight_Fee_Timeline {

    /** Has register_hooks() run this request? Hooks are added once, statically, never from a constructor. */
    private static $hooks_registered = false;

    /**
     * Request caches filled by preload_people() / preload_for_invoices():
     * season => row id => row (team places), and season => list of
     * array(user_id, email, recorded_at) (the latest change that put more
     * money due, per identity). *_covered says which identities a season's
     * cache holds completely ('u' => user ids, 'e' => lower-case emails),
     * so a reader only uses the cache for a person it fully covers.
     * Anything that writes team places or change rows must call
     * flush_cache().
     */
    private static $row_cache = array();
    private static $row_covered = array();
    private static $floor_cache = array();
    private static $floor_covered = array();

    /**
     * Add the fee timeline's hooks, once per request (team_oversight_init()
     * calls it). Each hook is added only when its handler exists in this
     * class: the admin form handler, the Preview endpoint and the daily
     * stop job belong to the timeline's writers and card.
     */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        $hooks = array(
            'admin_post_murvc_fee_timeline' => 'handle_post',
            'wp_ajax_murvc_fee_timeline_preview' => 'ajax_preview',
            TeamOversight_Memberships::CRON_HOOK => 'apply_due_stops',
        );
        foreach ($hooks as $hook => $method) {
            if (method_exists(__CLASS__, $method)) {
                add_action($hook, array(__CLASS__, $method), 10, 0);
            }
        }
    }

    /** Forget everything preloaded this request (call after writing team places or change rows). */
    public static function flush_cache() {
        self::$row_cache = array();
        self::$row_covered = array();
        self::$floor_cache = array();
        self::$floor_covered = array();
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    /**
     * A 'YYYY-MM-DD' string that is a real calendar date, else null
     * (DateTime::createFromFormat plus a round trip, which rejects
     * 2027-02-30 and the zero date; PHP 7.4 safe).
     */
    public static function parse_date($value) {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return ($date && $date->format('Y-m-d') === $value) ? $value : null;
    }

    /** Whole days from $from to $to (both valid 'YYYY-MM-DD'); negative when $to is earlier. */
    public static function days_between($from, $to) {
        $a = DateTime::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
        $b = DateTime::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / DAY_IN_SECONDS);
    }

    /** $date ('YYYY-MM-DD') moved by $days whole days. */
    public static function add_days($date, $days) {
        $d = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $days = intval($days);
        $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $d->format('Y-m-d');
    }

    // ------------------------------------------------------------------
    // Team places
    // ------------------------------------------------------------------

    /** The columns every reader selects, so a preloaded row and a queried one look the same. */
    private static function row_columns() {
        return 'id, user_id, email, season, team, role, registration_status, start_date, end_date, is_active, UNIX_TIMESTAMP(created_date) AS created_ts';
    }

    /**
     * Normalise a team_assignments row as the timeline reads it: ints for
     * the ids and flags, end_date a valid date or null (still on), and
     * start_date always a date. A row saved with no start (a legacy or
     * imported row) reads as the site date of its created_date (database
     * clock, so read through UNIX_TIMESTAMP and wp_date()) and is flagged
     * with start_missing, so the card can ask an admin to set it.
     */
    private static function normalise_row($row) {
        $row = (object) $row;
        $start = self::parse_date(isset($row->start_date) ? $row->start_date : null);
        $row->start_missing = $start === null;
        if ($start === null) {
            $ts = isset($row->created_ts) ? intval($row->created_ts) : 0;
            $start = $ts > 0 ? wp_date('Y-m-d', $ts) : null;
        }
        $row->start_date = $start;
        $row->end_date = self::parse_date(isset($row->end_date) ? $row->end_date : null);
        $row->id = isset($row->id) ? intval($row->id) : 0;
        $row->user_id = isset($row->user_id) ? intval($row->user_id) : 0;
        $row->is_active = isset($row->is_active) ? intval($row->is_active) : 0;
        $row->created_ts = isset($row->created_ts) ? intval($row->created_ts) : 0;
        return $row;
    }

    /** Does a season's cache hold everything for this account id (<= 0: none) and these lower-case emails? */
    private static function covers($covered, $season, $user_id, $emails) {
        if (!isset($covered[$season])) {
            return false;
        }
        if ($user_id > 0 && !isset($covered[$season]['u'][$user_id])) {
            return false;
        }
        foreach ($emails as $email) {
            if (!isset($covered[$season]['e'][$email])) {
                return false;
            }
        }
        return true;
    }

    /** The cached rows matching "(user_id = $user_id OR email IN $emails)", as copies (a caller may edit them in memory). */
    private static function cached_rows($season, $user_id, $emails) {
        $out = array();
        foreach (self::$row_cache[$season] as $row) {
            if (($user_id > 0 && $row->user_id === $user_id) || in_array(strtolower((string) $row->email), $emails, true)) {
                $out[] = clone $row;
            }
        }
        return $out;
    }

    /**
     * Every team place the person holds in the season, active or stopped
     * (stopped places are kept and still count for the time they ran),
     * matched by "(user_id = resolve_user_id($email) OR email = $email)" so
     * an email change never splits a person. Served from the preload cache
     * when it covers them. Returns normalised row objects, in id order:
     * id, user_id, email, season, team, role, registration_status,
     * start_date (always a date; see normalise_row()), start_missing,
     * end_date (null = still on), is_active, created_ts.
     */
    public static function rows($email, $season) {
        global $wpdb;
        $season = (string) $season;
        $email = (string) $email;
        $user_id = TeamOversight_Fees::resolve_user_id($email);
        $emails = array(strtolower(trim($email)));
        if (self::covers(self::$row_covered, $season, $user_id, $emails)) {
            return self::cached_rows($season, $user_id, $emails);
        }
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT " . self::row_columns() . " FROM {$wpdb->prefix}team_assignments
            WHERE (user_id = %d OR email = %s) AND season = %s
            ORDER BY id
        ", $user_id, $email, $season));
        return array_map(array(__CLASS__, 'normalise_row'), $rows ? $rows : array());
    }

    /**
     * The identity an invoice belongs to: its account id (0 when none)
     * and the lower-case emails to match, the invoice's own email plus its
     * account's current email.
     */
    private static function invoice_identity($invoice) {
        $user_id = isset($invoice->user_id) ? intval($invoice->user_id) : 0;
        $emails = array();
        if (isset($invoice->email) && (string) $invoice->email !== '') {
            $emails[] = strtolower(trim((string) $invoice->email));
        }
        if ($user_id > 0) {
            $account = get_userdata($user_id);
            if ($account && $account->user_email) {
                $emails[] = strtolower($account->user_email);
            }
        }
        return array($user_id, array_values(array_unique($emails)));
    }

    /**
     * The team places behind an invoice, for its schedule: the season's
     * rows matching "user_id = invoice user_id OR email IN (invoice email,
     * account email)". Served from the preload cache when it covers them.
     */
    public static function owner_rows($invoice) {
        global $wpdb;
        $invoice = (object) $invoice;
        $season = (string) $invoice->season;
        list($user_id, $emails) = self::invoice_identity($invoice);
        if (self::covers(self::$row_covered, $season, $user_id, $emails)) {
            return self::cached_rows($season, $user_id, $emails);
        }
        if (!$emails && $user_id <= 0) {
            return array();
        }
        $params = array($season, $user_id > 0 ? $user_id : -1);
        $email_sql = '';
        if ($emails) {
            $email_sql = ' OR email IN (' . implode(',', array_fill(0, count($emails), '%s')) . ')';
            $params = array_merge($params, $emails);
        }
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT " . self::row_columns() . " FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND (user_id = %d" . $email_sql . ")
            ORDER BY id
        ", $params));
        return array_map(array(__CLASS__, 'normalise_row'), $rows ? $rows : array());
    }

    // ------------------------------------------------------------------
    // Preloading (one query for the places, one for the floors)
    // ------------------------------------------------------------------

    /**
     * Load the team places and the behind-clock floors (rule 3: the latest
     * change that put more money due on the day it was recorded) of many
     * people for the given seasons in two queries, cached for the request
     * by user id and by email. $people: a list of array(user_id, email)
     * (arrays or objects); a person with no account id is resolved from
     * their email, and an account's current email is included. Seasons
     * before the fee timeline are ignored.
     */
    public static function preload_people($people, $seasons) {
        global $wpdb;
        $seasons = array_values(array_unique(array_filter(array_map('strval', (array) $seasons), array('TeamOversight_Fees', 'timeline_applies'))));
        if (empty($seasons) || empty($people)) {
            return;
        }
        $user_ids = array();
        $emails = array();
        foreach ($people as $person) {
            $person = (array) $person;
            $user_id = isset($person['user_id']) ? intval($person['user_id']) : 0;
            $email = isset($person['email']) ? strtolower(trim((string) $person['email'])) : '';
            if ($user_id <= 0 && $email !== '') {
                $user_id = max(0, intval(TeamOversight_Fees::resolve_user_id($email)));
            }
            if ($email !== '') {
                $emails[] = $email;
            }
            if ($user_id > 0) {
                $user_ids[] = $user_id;
            }
        }
        // Every account in one go (users and their meta: two queries, not
        // two per person), then their current emails.
        $user_ids = array_values(array_unique($user_ids));
        if ($user_ids) {
            cache_users($user_ids);
            foreach ($user_ids as $user_id) {
                $account = get_userdata($user_id);
                if ($account && $account->user_email) {
                    $emails[] = strtolower($account->user_email);
                }
            }
        }
        $user_ids = array_values(array_unique($user_ids));
        $emails = array_values(array_unique($emails));
        if (!$user_ids && !$emails) {
            return;
        }
        // Already loaded this request (and nothing written since: writers
        // flush the cache)? Then there's nothing to query.
        $loaded = true;
        foreach ($seasons as $season) {
            foreach ($user_ids as $user_id) {
                if (!isset(self::$row_covered[$season]['u'][$user_id], self::$floor_covered[$season]['u'][$user_id])) {
                    $loaded = false;
                    break 2;
                }
            }
            foreach ($emails as $email) {
                if (!isset(self::$row_covered[$season]['e'][$email], self::$floor_covered[$season]['e'][$email])) {
                    $loaded = false;
                    break 2;
                }
            }
        }
        if ($loaded) {
            return;
        }

        $who = array();
        $who_params = array();
        if ($user_ids) {
            $who[] = 'user_id IN (' . implode(',', array_fill(0, count($user_ids), '%d')) . ')';
            $who_params = array_merge($who_params, $user_ids);
        }
        if ($emails) {
            $who[] = 'email IN (' . implode(',', array_fill(0, count($emails), '%s')) . ')';
            $who_params = array_merge($who_params, $emails);
        }
        $season_in = implode(',', array_fill(0, count($seasons), '%s'));
        $params = array_merge($seasons, $who_params);

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT " . self::row_columns() . " FROM {$wpdb->prefix}team_assignments
            WHERE season IN ($season_in) AND (" . implode(' OR ', $who) . ")
            ORDER BY id
        ", $params));
        $floors = $wpdb->get_results($wpdb->prepare("
            SELECT season, user_id, email, MAX(recorded_at) AS recorded_at FROM {$wpdb->prefix}team_assignment_changes
            WHERE season IN ($season_in) AND (" . implode(' OR ', $who) . ")
                AND due_after IS NOT NULL AND due_before IS NOT NULL AND due_after - due_before >= 0.005
            GROUP BY season, user_id, email
        ", $params));

        foreach ($seasons as $season) {
            if (!isset(self::$row_cache[$season])) {
                self::$row_cache[$season] = array();
                self::$floor_cache[$season] = array();
            }
            foreach ($user_ids as $user_id) {
                self::$row_covered[$season]['u'][$user_id] = true;
                self::$floor_covered[$season]['u'][$user_id] = true;
            }
            foreach ($emails as $email) {
                self::$row_covered[$season]['e'][$email] = true;
                self::$floor_covered[$season]['e'][$email] = true;
            }
        }
        foreach ($rows ? $rows : array() as $row) {
            $row = self::normalise_row($row);
            self::$row_cache[(string) $row->season][$row->id] = $row;
        }
        foreach ($floors ? $floors : array() as $floor) {
            self::$floor_cache[(string) $floor->season][] = array(
                'user_id' => intval($floor->user_id),
                'email' => strtolower((string) $floor->email),
                'recorded_at' => (string) $floor->recorded_at,
            );
        }
    }

    /**
     * preload_people() for a list of invoice rows (each with user_id,
     * email and season), over the fee-timeline seasons among them.
     */
    public static function preload_for_invoices($invoices) {
        $people = array();
        $seasons = array();
        foreach ((array) $invoices as $invoice) {
            $invoice = (object) $invoice;
            $people[] = array('user_id' => isset($invoice->user_id) ? intval($invoice->user_id) : 0, 'email' => isset($invoice->email) ? (string) $invoice->email : '');
            $seasons[] = isset($invoice->season) ? (string) $invoice->season : '';
        }
        self::preload_people($people, $seasons);
    }

    // ------------------------------------------------------------------
    // Fee class history
    // ------------------------------------------------------------------

    /**
     * The person's class_recorded and history_reset change rows for the
     * season, oldest first (by id, the order they were written).
     */
    private static function class_rows($email, $season) {
        global $wpdb;
        $user_id = TeamOversight_Fees::resolve_user_id($email);
        return $wpdb->get_results($wpdb->prepare("
            SELECT id, action, new_class, recorded_at FROM {$wpdb->prefix}team_assignment_changes
            WHERE (user_id = %d OR email = %s) AND season = %s AND action IN ('class_recorded', 'history_reset')
            ORDER BY id
        ", $user_id, (string) $email, (string) $season));
    }

    /**
     * The person's fee class history for the season: every class_recorded
     * row written after their latest history_reset (a Remove that left them
     * with no place restarts the history, so a re-add is judged as if the
     * first add never happened), ordered by when it was recorded. Each is
     * array(id, date (site date it applies from), recorded_at (site time),
     * class). Empty when nothing is recorded.
     */
    public static function class_history($email, $season) {
        $kept = array();
        foreach (self::class_rows($email, $season) as $row) {
            if ($row->action === 'history_reset') {
                $kept = array();
                continue;
            }
            $kept[] = array(
                'id' => intval($row->id),
                'date' => substr((string) $row->recorded_at, 0, 10),
                'recorded_at' => (string) $row->recorded_at,
                'class' => (string) $row->new_class,
            );
        }
        usort($kept, function ($a, $b) {
            $by_time = strcmp($a['recorded_at'], $b['recorded_at']);
            return $by_time !== 0 ? $by_time : $a['id'] - $b['id'];
        });
        return $kept;
    }

    /**
     * When the person's fee was first recorded for the season (site time,
     * 'Y-m-d H:i:s'), the moment a 2027+ alumni class is judged at: the
     * earliest class_recorded row after their latest history_reset. A
     * season fee recorded before 1.70.1 lives in team_fee_segments (their
     * first segment's created_date, the moment 1.70.0 judged them at), so
     * while no history_reset has been written that moment counts too and
     * the earlier of the two wins: the first class row 1.70.1 writes for
     * someone placed before it never moves their reference moment. Null
     * when nothing is recorded yet (the caller uses now).
     */
    public static function first_record_time($email, $season) {
        global $wpdb;
        $reset = false;
        $first = null;
        foreach (self::class_rows($email, $season) as $row) {
            if ($row->action === 'history_reset') {
                $reset = true;
                $first = null;
                continue;
            }
            if ($first === null || (string) $row->recorded_at < $first) {
                $first = (string) $row->recorded_at;
            }
        }
        if (!$reset) {
            $user_id = TeamOversight_Fees::resolve_user_id($email);
            $segment = $wpdb->get_var($wpdb->prepare("
                SELECT MIN(created_date) FROM {$wpdb->prefix}team_fee_segments
                WHERE (user_id = %d OR email = %s) AND season = %s
            ", $user_id, (string) $email, (string) $season));
            if ($segment && ($first === null || (string) $segment < $first)) {
                $first = (string) $segment;
            }
        }
        return $first;
    }

    /**
     * Record the person's fee class for the season (a class_recorded change
     * row), when they hold a place in it and the class as judged now
     * (determine_person_fee_class(), the person's class ignoring teams)
     * differs from the last one recorded, or none is. The fee engine calls
     * this wherever 2026 and earlier checkpoint fee segments, so the first
     * record is the moment the person's fee was first recorded and a
     * profile correction counts from the day a re-price picks it up.
     * $source: what caused it (finalise, accept, timeline, ...; '' from a
     * re-price). $fees: the caller's TeamOversight_Fees, if it has one.
     * Returns true when a row was written.
     */
    public static function record_class($email, $season, $source = '', $fees = null) {
        global $wpdb;
        if (!TeamOversight_Fees::timeline_applies($season)) {
            return false;
        }
        $rows = self::rows($email, $season);
        if (empty($rows)) {
            return false;
        }
        if (!($fees instanceof TeamOversight_Fees)) {
            $fees = new TeamOversight_Fees();
        }
        $class = $fees->determine_person_fee_class($email, $season);
        $history = self::class_history($email, $season);
        $last = empty($history) ? null : $history[count($history) - 1]['class'];
        if ($last === $class) {
            return false;
        }
        $user_id = TeamOversight_Fees::resolve_user_id($email);
        if ($user_id <= 0) {
            foreach ($rows as $row) {
                if ($row->user_id > 0) {
                    $user_id = $row->user_id;
                    break;
                }
            }
        }
        $data = array(
            'email' => (string) $email,
            'season' => (string) $season,
            'team' => '',
            'role' => '',
            'action' => 'class_recorded',
            'source' => substr((string) $source, 0, 20),
            'new_class' => substr((string) $class, 0, 100),
            'note' => $last === null ? 'fee class first recorded' : 'fee class changed',
            'recorded_at' => current_time('mysql'),
            'recorded_by' => get_current_user_id(),
        );
        $formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d');
        if ($user_id > 0) {
            $data['user_id'] = $user_id;
            $formats[] = '%d';
        }
        if ($last !== null) {
            $data['old_class'] = substr((string) $last, 0, 100);
            $formats[] = '%s';
        }
        return (bool) $wpdb->insert($wpdb->prefix . 'team_assignment_changes', $data, $formats);
    }

    // ------------------------------------------------------------------
    // The due schedule (pure: reads, never writes)
    // ------------------------------------------------------------------

    /**
     * What an invoice has falling due on a day, on the fee timeline's
     * schedule: every 2027+ season's due amounts (1.70.2, read through
     * TeamOversight_Payments::invoice_schedule() and its wrappers), and the
     * change rows' due_before / due_after, which the behind clock's floor
     * reads. Seasons before 2027 keep the 1.69 schedule.
     *
     * $invoice: an invoice row (season, invoice_amount, outstanding_amount,
     * user_id, email; for the floor also id, created_ts, fee_raised_at).
     * $rows: the person's team places (default owner_rows($invoice), or an
     * edit priced in memory). $today: the day ('YYYY-MM-DD', default the
     * site's today). $amount: a fee to schedule instead of invoice_amount
     * (what was paid stays the same; an optional $invoice->paid overrides
     * invoice_amount - outstanding_amount, which hides any credit).
     *
     * The window runs from W0 = the later of "Fees due from" (P0) and their
     * earliest start (P0 with no places) to W1 = "Paid in full by" (P1).
     * Once every place has a stop date, everything falls due on the last
     * stop. Otherwise the fee falls due evenly, in whole site days, from W0
     * to W1 (all on W0 when a legacy start falls on or after P1). A season
     * from an earlier calendar year is fully due, and a season with no
     * dates is unscheduled and never overdue, as in 1.69.
     *
     * Returns fee, paid, outstanding, scheduled, fraction, due_today,
     * due_pct, overdue, paid_through, next_due (when not overdue),
     * fell_behind and behind_since (when overdue; behind_since is never
     * before behind_floor()), window_from (W0), window_to (W1), stopped_on.
     */
    public static function schedule($invoice, $rows = null, $today = null, $amount = null) {
        $invoice = (object) $invoice;
        $season = (string) $invoice->season;
        $today = self::parse_date($today) !== null ? $today : current_time('Y-m-d');
        $fee = round(floatval($amount !== null ? $amount : $invoice->invoice_amount), 2);
        $paid = isset($invoice->paid)
            ? round(floatval($invoice->paid), 2)
            : round(floatval($invoice->invoice_amount) - floatval($invoice->outstanding_amount), 2);
        $outstanding = ($amount !== null || isset($invoice->paid))
            ? max(0.0, round($fee - $paid, 2))
            : max(0.0, round(floatval($invoice->outstanding_amount), 2));
        $result = array(
            'fee' => $fee,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'scheduled' => false,
            'fraction' => null,
            'due_today' => 0.0,
            'due_pct' => null,
            'overdue' => 0.0,
            'paid_through' => null,
            'next_due' => null,
            'fell_behind' => null,
            'behind_since' => null,
            'window_from' => null,
            'window_to' => null,
            'stopped_on' => null,
        );

        // An earlier calendar year's season still owing is fully due (it
        // rolls forward, season dates or not), behind from its end.
        if (intval($season) > 0 && intval($season) < intval(substr($today, 0, 4))) {
            $dates = TeamOversight_Fees::get_season_dates($season);
            $result['scheduled'] = true;
            $result['fraction'] = 1.0;
            $result['due_today'] = $fee;
            $result['due_pct'] = 100;
            $result['overdue'] = round($outstanding, 2);
            if ($result['overdue'] > 0) {
                $result['fell_behind'] = $dates ? $dates['end'] : intval($season) . '-12-31';
                $result['behind_since'] = self::later_date($result['fell_behind'], self::behind_floor($invoice));
            }
            return $result;
        }

        $window = TeamOversight_Fees::get_payment_window($season);
        if (!$window) {
            return $result; // no season dates: unscheduled, never overdue
        }
        if ($rows === null) {
            $rows = self::owner_rows($invoice);
        }
        $earliest = null;
        $stop = null;
        $all_stopped = !empty($rows);
        foreach ((array) $rows as $row) {
            $row = (object) $row;
            $start = self::parse_date(isset($row->start_date) ? $row->start_date : null);
            $end = self::parse_date(isset($row->end_date) ? $row->end_date : null);
            if ($start !== null && ($earliest === null || $start < $earliest)) {
                $earliest = $start;
            }
            if ($end === null) {
                $all_stopped = false;
            } elseif ($stop === null || $end > $stop) {
                $stop = $end;
            }
        }
        if (!$all_stopped) {
            $stop = null;
        }
        $w0 = ($earliest !== null && $earliest > $window['from']) ? $earliest : $window['from'];
        $w1 = $window['to'];

        if ($stop !== null && $today >= $stop) {
            $fraction = 1.0;
        } elseif ($w0 >= $w1) {
            $fraction = $today >= $w0 ? 1.0 : 0.0;
        } else {
            $fraction = max(0.0, min(1.0, self::days_between($w0, $today) / self::days_between($w0, $w1)));
        }
        $due = round($fee * $fraction, 2);

        $result['scheduled'] = true;
        $result['fraction'] = $fraction;
        $result['due_today'] = $due;
        $result['due_pct'] = (int) round($fraction * 100);
        $result['overdue'] = round(max(0.0, min($due - $paid, $outstanding)), 2);
        $result['window_from'] = $w0;
        $result['window_to'] = $w1;
        $result['stopped_on'] = $stop;

        if ($fee > 0) {
            if ($w0 >= $w1) {
                $paid_through = $w0;
            } else {
                $share = max(0.0, min(1.0, $paid / $fee));
                $paid_through = self::add_days($w0, (int) round($share * self::days_between($w0, $w1)));
            }
            if ($stop !== null && $outstanding > 0 && $paid_through > $stop) {
                $paid_through = $stop; // stopped: the rest fell due on the stop date
            }
            $result['paid_through'] = $paid_through;
        }
        if ($result['overdue'] > 0) {
            $result['fell_behind'] = $result['paid_through'];
            $result['behind_since'] = self::later_date($result['fell_behind'], self::behind_floor($invoice));
        } elseif ($outstanding > 0) {
            $result['next_due'] = $result['paid_through'];
        }
        return $result;
    }

    /** The later of two 'YYYY-MM-DD' dates, either of which may be null. */
    private static function later_date($a, $b) {
        if ($a === null || $a === '') {
            return ($b === null || $b === '') ? null : $b;
        }
        if ($b === null || $b === '') {
            return $a;
        }
        return $b > $a ? $b : $a;
    }

    /**
     * The earliest day an invoice can count as behind from (the floor of
     * the behind clock): the latest of (1) the day it was billed (its
     * created_date, on the database clock, read as created_ts and turned
     * into the site's date), (2) the day its fee was last raised
     * (fee_raised_at), (3) the day of the latest recorded change for that
     * person and season that put more money due on the day it was recorded
     * (due_after - due_before >= $0.005), and (4) the day a configuration
     * save last moved the season's payment window earlier (raised_at).
     * Null when none applies. One lookup when the invoice row carries no
     * created_ts / fee_raised_at.
     */
    public static function behind_floor($invoice) {
        global $wpdb;
        $invoice = (object) $invoice;
        $season = (string) $invoice->season;
        if ((!isset($invoice->created_ts) || !property_exists($invoice, 'fee_raised_at')) && !empty($invoice->id)) {
            $looked_up = $wpdb->get_row($wpdb->prepare("
                SELECT UNIX_TIMESTAMP(created_date) AS created_ts, fee_raised_at FROM {$wpdb->prefix}team_invoices WHERE id = %d
            ", intval($invoice->id)));
            if ($looked_up) {
                if (!isset($invoice->created_ts)) {
                    $invoice->created_ts = $looked_up->created_ts;
                }
                if (!property_exists($invoice, 'fee_raised_at')) {
                    $invoice->fee_raised_at = $looked_up->fee_raised_at;
                }
            }
        }
        $floor = null;
        $created_ts = isset($invoice->created_ts) ? intval($invoice->created_ts) : 0;
        if ($created_ts > 0) {
            $floor = self::later_date($floor, wp_date('Y-m-d', $created_ts));
        }
        $raised = isset($invoice->fee_raised_at) ? self::parse_date(substr((string) $invoice->fee_raised_at, 0, 10)) : null;
        $floor = self::later_date($floor, $raised);
        $change = self::latest_raising_change($invoice);
        $floor = self::later_date($floor, $change ? self::parse_date(substr($change, 0, 10)) : null);
        $window = TeamOversight_Fees::get_payment_window($season);
        if ($window && !empty($window['raised_at'])) {
            $floor = self::later_date($floor, self::parse_date(substr((string) $window['raised_at'], 0, 10)));
        }
        return $floor;
    }

    /**
     * recorded_at (site time) of the latest change row for the invoice's
     * person and season that put more money due on the day it was recorded,
     * or null. From the preload cache when it covers them.
     */
    private static function latest_raising_change($invoice) {
        global $wpdb;
        $season = (string) $invoice->season;
        list($user_id, $emails) = self::invoice_identity($invoice);
        if (self::covers(self::$floor_covered, $season, $user_id, $emails)) {
            $latest = null;
            foreach (self::$floor_cache[$season] as $floor) {
                if (($user_id > 0 && $floor['user_id'] === $user_id) || in_array($floor['email'], $emails, true)) {
                    if ($latest === null || $floor['recorded_at'] > $latest) {
                        $latest = $floor['recorded_at'];
                    }
                }
            }
            return $latest;
        }
        if (!$emails && $user_id <= 0) {
            return null;
        }
        $params = array($season, $user_id > 0 ? $user_id : -1);
        $email_sql = '';
        if ($emails) {
            $email_sql = ' OR email IN (' . implode(',', array_fill(0, count($emails), '%s')) . ')';
            $params = array_merge($params, $emails);
        }
        $latest = $wpdb->get_var($wpdb->prepare("
            SELECT MAX(recorded_at) FROM {$wpdb->prefix}team_assignment_changes
            WHERE season = %s AND (user_id = %d" . $email_sql . ")
                AND due_after IS NOT NULL AND due_before IS NOT NULL AND due_after - due_before >= 0.005
        ", $params));
        return $latest ? (string) $latest : null;
    }

    // ------------------------------------------------------------------
    // Writers (1.70.1). From 2027 team places are written only here, so
    // every change leaves a change row (who, when, old and new dates, fee
    // and amount due before and after), is checked against what the
    // admin saw (compare-and-set), keeps the trial verdicts in step and
    // re-prices once. Every writer follows the same steps:
    //   1. load the row and refuse unless it is a 2027+ place;
    //   2. validate the dates and the overlap rules;
    //   3. work out the account email before writing anything;
    //   4. snapshot the fee and (with an invoice) the amount due;
    //   5. insert the change row with the row as it was;
    //   6. write with compare-and-set (an explicit IS NULL branch, because
    //      prepare() turns null into ''); nothing changed = someone else
    //      changed it: delete the change row and say reload;
    //   7. is_active / registration_status follow the dates;
    //   8. a playing place leaving its team today or earlier drops that
    //      team's Selected / Training Only verdict to Tentative;
    //   9. re-price under the account email through generate_invoice(),
    //      adds and edits alike (an edit can bring someone with no invoice
    //      back inside a released season); before the release that bills
    //      nothing, which is not a failure;
    //  10. fee and amount due after on the change row, one team_dates line;
    //  11. Remove only: history_reset when no place is left, then
    //      TeamOversight_Fees::delete_invoice_if_empty().
    // Callers check the nonce and manage_options. The payments ledger is
    // never touched, and a fee set with Edit Fee is never changed
    // (generate_invoice() keeps it).
    // ------------------------------------------------------------------

    /** Playing roles count as one place per team (a player is playing member OR training only on a team at a time). */
    const PLAYING_ROLES = array('playing_member', 'training_only');

    /** The message every writer gives when the row changed since the admin loaded it. */
    const RELOAD_MESSAGE = 'Changed by someone else — reload the page and check it before trying again.';

    /** The TeamOversight_Fees the writers price and re-price with (one per request: each new one re-adds its hooks). */
    private static $fees = null;

    private static function fees() {
        if (!(self::$fees instanceof TeamOversight_Fees)) {
            self::$fees = new TeamOversight_Fees();
        }
        return self::$fees;
    }

    /** "1 Jul 2027" for a 'YYYY-MM-DD' date (anything else as given). */
    public static function format_date($date) {
        $valid = self::parse_date($date);
        if ($valid === null) {
            return (string) $date;
        }
        return DateTime::createFromFormat('!Y-m-d', $valid, new DateTimeZone('UTC'))->format('j M Y');
    }

    private static function money($amount) {
        return '$' . number_format((float) $amount, 2);
    }

    /** "SL2M playing member". */
    private static function place_label($team, $role) {
        return $team . ' ' . strtolower(TeamOversight_Fees::role_label($role));
    }

    /**
     * The earliest start a season's place may have: 1 January of the year
     * before the season starts (the season's own year without dates). A
     * typo guard, so 2062 for 2026 is caught.
     */
    public static function typo_guard($season) {
        $dates = TeamOversight_Fees::get_season_dates($season);
        $year = ($dates && self::parse_date($dates['start']) !== null) ? intval(substr($dates['start'], 0, 4)) : intval($season);
        return sprintf('%04d-01-01', $year - 1);
    }

    /** The refusal for a start after the late-join cutoff (the effective "Paid in full by"). */
    public static function cutoff_message($season, $start, $cutoff) {
        return sprintf(
            'Start %s is after the %s late-join cutoff (fees paid in full by %s). Nobody starts on a team after that; post-season training is a separate product in the shop.',
            self::format_date($start), $season, self::format_date($cutoff)
        );
    }

    /**
     * One line for the screens that take a start date: the season's
     * late-join cutoff, or that it has none (no season dates).
     */
    public static function cutoff_note($season) {
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        if ($cutoff === null) {
            return sprintf('The %s season has no dates yet, so there is no late-join cutoff.', $season);
        }
        return sprintf('Late-join cutoff: nobody starts on a %s team after %s (fees paid in full by).', $season, self::format_date($cutoff));
    }

    /**
     * Is $start a start date a place in $season may have? Null when it is,
     * else the reason to show. A real date, not before typo_guard(), and
     * (unless $check_cutoff is false) on or before the late-join cutoff,
     * TeamOversight_Fees::latest_start_date() (none without season dates).
     */
    public static function validate_start($season, $start, $check_cutoff = true) {
        $start = is_string($start) ? trim($start) : '';
        if (self::parse_date($start) === null) {
            return 'Pick a valid start date.';
        }
        $guard = self::typo_guard($season);
        if ($start < $guard) {
            return sprintf('Start %s is before %s — check the year: a %s team place can\'t start before then.', self::format_date($start), self::format_date($guard), $season);
        }
        if ($check_cutoff) {
            $cutoff = TeamOversight_Fees::latest_start_date($season);
            if ($cutoff !== null && $start > $cutoff) {
                return self::cutoff_message($season, $start, $cutoff);
            }
            // The season end isn't charged (end dates are exclusive), so a
            // place starting on it would never count. With no payment window
            // saved the cutoff falls back to the season end, which lets that
            // date through above.
            $dates = TeamOversight_Fees::get_season_dates($season);
            if ($dates && self::parse_date($dates['end']) !== null && $start >= $dates['end']) {
                return sprintf('Start %s is on or after the %s season end (%s), which isn\'t charged, so the place would never count. Pick an earlier start.', self::format_date($start), $season, self::format_date($dates['end']));
            }
        }
        return null;
    }

    /** A team_assignments row by id as stored (dates raw: null when NULL), or null when it no longer exists. */
    private static function load_row($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT " . self::row_columns() . " FROM {$wpdb->prefix}team_assignments WHERE id = %d
        ", intval($id)));
        return $row ? $row : null;
    }

    /**
     * The person behind a place or a placement, worked out before anything
     * is written: their account (the row's user_id, else resolve_user_id()
     * of the email), and the email the fees engine prices them under (the
     * account's current email, else the email given). 'emails' lists the
     * lower-case emails to match them by.
     */
    private static function account($user_id, $email) {
        $uid = intval($user_id);
        if ($uid <= 0) {
            $uid = intval(TeamOversight_Fees::resolve_user_id((string) $email));
        }
        $user = $uid > 0 ? get_userdata($uid) : false;
        $account_email = ($user && $user->user_email) ? (string) $user->user_email : (string) $email;
        $emails = array_values(array_unique(array_filter(array(strtolower(trim((string) $email)), strtolower(trim($account_email))))));
        return array('user_id' => $uid > 0 ? $uid : 0, 'email' => $account_email, 'emails' => $emails);
    }

    /** Every place the person holds in the season: by account id, the email given and the account's email. */
    private static function person_rows($account, $email, $season) {
        return self::owner_rows((object) array('season' => (string) $season, 'user_id' => $account['user_id'], 'email' => (string) $email));
    }

    /** Do two roles share one place on a team? Playing member and training only do; a staff role only with itself. */
    private static function same_place($role_a, $role_b) {
        if (in_array($role_a, self::PLAYING_ROLES, true) && in_array($role_b, self::PLAYING_ROLES, true)) {
            return true;
        }
        return $role_a === $role_b;
    }

    /** Do [from_a, to_a) and [from_b, to_b) overlap? A null end runs on for ever. */
    private static function overlaps($from_a, $to_a, $from_b, $to_b) {
        return ($to_b === null || $from_a < $to_b) && ($to_a === null || $from_b < $to_a);
    }

    /**
     * The first of $rows (other than $exclude_id) that a place on $team in
     * $role from $from to $to (null = still on) may not overlap: the same
     * team and the same place (playing roles as one, a staff role with
     * itself). Different teams, and coach plus player, may overlap. Null
     * when none clashes. Public so the How it works calculator refuses the
     * same overlaps the writers do.
     */
    public static function clash($rows, $team, $role, $from, $to, $exclude_id = 0) {
        foreach ((array) $rows as $other) {
            if (intval($other->id) === intval($exclude_id) || (string) $other->team !== (string) $team || !self::same_place($other->role, $role)) {
                continue;
            }
            if ($other->start_date === null || ($other->end_date !== null && $other->end_date <= $other->start_date)) {
                continue; // no start, or zero length: never on the team
            }
            if (self::overlaps($from, $to, $other->start_date, $other->end_date)) {
                return $other;
            }
        }
        return null;
    }

    public static function clash_message($team, $other) {
        return sprintf(
            'That overlaps their other %s place (%s from %s%s). One person holds one playing place per team at a time, and each staff role once: change that place\'s dates first.',
            $team, strtolower(TeamOversight_Fees::role_label($other->role)), self::format_date($other->start_date),
            $other->end_date !== null ? ' to ' . self::format_date($other->end_date) : ', still on'
        );
    }

    /** The person's invoice for the season, the one generate_invoice() re-prices under $account['email'], or null. */
    private static function find_invoice($account, $season) {
        global $wpdb;
        $uid = TeamOversight_Fees::resolve_user_id($account['email']);
        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, user_id, email, season, invoice_amount, outstanding_amount, fee_overridden, fee_raised_at, UNIX_TIMESTAMP(created_date) AS created_ts
            FROM {$wpdb->prefix}team_invoices WHERE (user_id = %d OR email = %s) AND season = %s
        ", $uid, $account['email'], (string) $season));
        return $invoice ? $invoice : null;
    }

    /**
     * The person's fee and amount due as things stand (or with $rows, an
     * edit priced in memory): fee = the fee_breakdown() total, due = what
     * schedule() has due by today on their invoice (null with no invoice).
     */
    private static function snapshot($account, $season, $rows = null) {
        if ($rows === null) {
            $rows = self::rows($account['email'], $season);
        }
        $breakdown = self::fees()->fee_breakdown($account['email'], $season, $rows);
        $invoice = self::find_invoice($account, $season);
        $due = null;
        if ($invoice) {
            $schedule = self::schedule($invoice, $rows);
            $due = $schedule['due_today'];
        }
        return array('fee' => round(floatval($breakdown['total']), 2), 'breakdown' => $breakdown, 'invoice' => $invoice, 'due' => $due, 'rows' => $rows);
    }

    /**
     * Insert a change row. Nulls (and a user_id of 0) are left out, so the
     * column stays NULL. recorded_at defaults to the site's time,
     * recorded_by to the current user (0 = cron). Returns its id, 0 when it
     * couldn't be written.
     */
    private static function insert_change($fields) {
        global $wpdb;
        $columns = array(
            'assignment_id' => array('%d', 0), 'user_id' => array('%d', 0), 'email' => array('%s', 255), 'season' => array('%s', 10),
            'team' => array('%s', 50), 'role' => array('%s', 50), 'action' => array('%s', 20), 'source' => array('%s', 20),
            'old_start' => array('%s', 10), 'new_start' => array('%s', 10), 'old_end' => array('%s', 10), 'new_end' => array('%s', 10),
            'old_class' => array('%s', 100), 'new_class' => array('%s', 100),
            'fee_before' => array('%f', 0), 'fee_after' => array('%f', 0), 'due_before' => array('%f', 0), 'due_after' => array('%f', 0),
            'note' => array('%s', 255), 'recorded_at' => array('%s', 19), 'recorded_by' => array('%d', 0),
        );
        $fields = array_merge(array('recorded_at' => current_time('mysql'), 'recorded_by' => get_current_user_id()), $fields);
        $data = array();
        $formats = array();
        foreach ($columns as $column => $spec) {
            if (!array_key_exists($column, $fields) || $fields[$column] === null) {
                continue;
            }
            $value = $fields[$column];
            if (($column === 'user_id' || $column === 'assignment_id') && intval($value) <= 0) {
                continue;
            }
            if ($spec[0] === '%s') {
                $value = substr((string) $value, 0, $spec[1]);
            } elseif ($spec[0] === '%d') {
                $value = max(0, intval($value));
            } else {
                $value = round(floatval($value), 2);
            }
            $data[$column] = $value;
            $formats[] = $spec[0];
        }
        return $wpdb->insert($wpdb->prefix . 'team_assignment_changes', $data, $formats) ? intval($wpdb->insert_id) : 0;
    }

    /** Step 10: the fee and amount due after the change, on its change row(s). */
    private static function finish_change($change_ids, $after) {
        global $wpdb;
        foreach ((array) $change_ids as $change_id) {
            if (intval($change_id) <= 0) {
                continue;
            }
            $data = array('fee_after' => $after['fee']);
            $formats = array('%f');
            if ($after['due'] !== null) {
                $data['due_after'] = $after['due'];
                $formats[] = '%f';
            }
            $wpdb->update($wpdb->prefix . 'team_assignment_changes', $data, array('id' => intval($change_id)), $formats, array('%d'));
        }
    }

    /** Take back a change row whose write didn't happen (compare-and-set lost, or the write failed). */
    private static function drop_change($change_id) {
        global $wpdb;
        if (intval($change_id) > 0) {
            $wpdb->delete($wpdb->prefix . 'team_assignment_changes', array('id' => intval($change_id)), array('%d'));
        }
    }

    /**
     * Step 6's WHERE: the row only as it was loaded (id, team, role, start
     * and end), with an explicit IS NULL for a blank date, since prepare()
     * turns PHP null into '' and NULL = '' never matches.
     */
    private static function cas_where($row) {
        global $wpdb;
        $where = $wpdb->prepare('id = %d AND team = %s AND role = %s', intval($row->id), (string) $row->team, (string) $row->role);
        $where .= $row->start_date === null ? ' AND start_date IS NULL' : $wpdb->prepare(' AND start_date = %s', (string) $row->start_date);
        $where .= $row->end_date === null ? ' AND end_date IS NULL' : $wpdb->prepare(' AND end_date = %s', (string) $row->end_date);
        return $where;
    }

    /** Step 7: is_active and registration_status from the stop date (active while it is blank or after today). */
    private static function active_sql($end, $today) {
        global $wpdb;
        $active = ($end === null || $end > $today) ? 1 : 0;
        return $wpdb->prepare('is_active = %d, registration_status = %s', $active, $active ? 'active' : 'inactive');
    }

    /**
     * Do the caller's expected values (keys start, end, team, role; a key
     * left out isn't checked, '' or null means blank) match the row as
     * stored? A stale page fails this and is told to reload.
     */
    private static function expect_matches($row, $expect) {
        if (!is_array($expect)) {
            return true;
        }
        $norm = function ($value) {
            $value = is_string($value) ? trim($value) : $value;
            return ($value === null || $value === '' || $value === false) ? null : (string) $value;
        };
        if (array_key_exists('start', $expect) && $norm($expect['start']) !== self::parse_date($row->start_date)) {
            return false;
        }
        if (array_key_exists('end', $expect) && $norm($expect['end']) !== self::parse_date($row->end_date)) {
            return false;
        }
        if (array_key_exists('team', $expect) && $norm($expect['team']) !== (string) $row->team) {
            return false;
        }
        if (array_key_exists('role', $expect) && $norm($expect['role']) !== (string) $row->role) {
            return false;
        }
        return true;
    }

    /**
     * Step 8: a playing place left its team, so drop that team's Selected /
     * Training Only verdict to Tentative (and take the team off an accepted
     * application), unless the person still holds a playing place on that
     * team on or after $today (a role change on the same team, or another
     * place). Returns true when it synced.
     */
    private static function sync_if_left($account, $email, $season, $team, $today) {
        self::flush_cache();
        foreach (self::person_rows($account, $email, $season) as $other) {
            if ((string) $other->team === (string) $team && in_array($other->role, self::PLAYING_ROLES, true)
                && ($other->end_date === null || $other->end_date > $today)) {
                return false;
            }
        }
        TeamOversight_Admin::sync_trial_after_assignment_removal($email, $season, $team, $account['user_id']);
        return true;
    }

    /** Step 10's activity-log line: "2027 SL2M playing member: stopped 1 Jul 2027 (recorded 21 Oct) — fee $1,000.00 → $496.69". */
    private static function log_change($account, $season, $what, $before, $after, $source) {
        $sources = array('finalise' => 'Finalise', 'accept' => 'Accept', 'bulk_accept' => 'bulk accept', 'add_assignment' => 'Add Assignment', 'undo' => 'Undo Assignment', 'cron' => 'daily job');
        $line = sprintf('%s %s (recorded %s%s) — fee %s → %s', $season, $what, current_time('j M'),
            isset($sources[$source]) ? ', ' . $sources[$source] : '', self::money($before['fee']), self::money($after['fee']));
        if ($after['invoice'] && intval($after['invoice']->fee_overridden) === 1) {
            $line .= '; fee set with Edit Fee kept at ' . self::money($after['invoice']->invoice_amount);
        }
        if (!TeamOversight_Fees::fees_released($season)) {
            $line .= '; not billed until ' . $season . ' fees are released';
        }
        TeamOversight_Log::add('team_dates', $line, $account['user_id'] > 0 ? array('user_id' => $account['user_id']) : array());
    }

    private static function refusal($message, $reload = false) {
        return array('ok' => false, 'error' => (string) $message, 'reload' => (bool) $reload);
    }

    /**
     * Place a person on one or more teams from $start (Finalise, Accept,
     * bulk accept, Add Assignment). $person: array(user_id, email) (or an
     * email); $teams: list of array(team, role); $source: finalise, accept,
     * bulk_accept or add_assignment. The start is checked first (a real
     * date, the typo guard, the late-join cutoff) and nothing is written
     * when it is refused.
     *
     * A team the person already holds in that place (an open place, or one
     * covering $start) is "already on the team" and skipped, so Finalise
     * and bulk accept stay idempotent; one that would overlap a later
     * place on that team is refused (listed in 'conflicts'). Each new
     * place gets an 'added' change row, then one re-price for the person
     * under their account email, and only when something was added or
     * they have no invoice yet (as Finalise always did): generate_invoice()
     * creates or re-prices (a fee set with Edit Fee is kept; before the
     * release it bills nothing).
     *
     * Returns ok (something was added or already held), error, added,
     * row_ids, already, skipped (array(team, role, existing_role,
     * existing_id) per team already held), conflicts (array(team, role,
     * message)), had_invoice, invoiced (new invoice), repriced (existing
     * invoice re-priced), manual_kept, not_priced (released, no invoice
     * and no fee could be worked out), released, fee_before, fee_after,
     * due_before, due_after.
     */
    public static function add_stretches($person, $season, $teams, $start, $source) {
        global $wpdb;
        $season = (string) $season;
        $result = array(
            'ok' => false, 'error' => '', 'reload' => false, 'added' => 0, 'row_ids' => array(), 'already' => 0, 'skipped' => array(), 'conflicts' => array(),
            'had_invoice' => false, 'invoiced' => false, 'repriced' => false, 'manual_kept' => false, 'not_priced' => false,
            'released' => TeamOversight_Fees::fees_released($season),
            'fee_before' => null, 'fee_after' => null, 'due_before' => null, 'due_after' => null,
        );
        if (!TeamOversight_Fees::timeline_applies($season)) {
            $result['error'] = 'Fee timelines start with the ' . TeamOversight_Fees::FEE_TIMELINE_FROM . ' season.';
            return $result;
        }
        $start = is_string($start) ? trim($start) : '';
        $invalid = self::validate_start($season, $start);
        if ($invalid !== null) {
            $result['error'] = $invalid;
            return $result;
        }

        if (!is_array($person) && !is_object($person)) {
            $person = array('user_id' => 0, 'email' => (string) $person);
        }
        $person = (array) $person;
        $email = isset($person['email']) ? trim((string) $person['email']) : '';
        $given_uid = isset($person['user_id']) ? intval($person['user_id']) : 0;
        if ($email === '' && $given_uid > 0) {
            $user = get_userdata($given_uid);
            $email = $user ? (string) $user->user_email : '';
        }
        if ($email === '') {
            $result['error'] = 'No person to place.';
            return $result;
        }

        $database = new TeamOversight_Database();
        $roles = array_keys($database->get_roles());
        $wanted = array();
        foreach ((array) $teams as $entry) {
            $entry = (array) $entry;
            $team = isset($entry['team']) ? trim((string) $entry['team']) : '';
            $role = isset($entry['role']) ? (string) $entry['role'] : '';
            if ($team === '' || !in_array($role, $roles, true)) {
                $result['error'] = 'Pick a team and a role.';
                return $result;
            }
            foreach ($wanted as $w) {
                if ($w['team'] === $team && self::same_place($w['role'], $role)) {
                    continue 2; // the same place twice in one request
                }
            }
            $wanted[] = array('team' => $team, 'role' => $role);
        }
        if (empty($wanted)) {
            $result['error'] = 'Pick a team and a role.';
            return $result;
        }

        // 3. The account first, then what they hold already. A per-person
        // named lock covers the check and the insert, so a double-clicked
        // Add Assignment or Accept (or one racing a Finalise) can't place
        // the same person twice. Finalise calls this while holding its own
        // season lock: one session holding several named locks needs
        // MariaDB 10.0.2+ / MySQL 5.7+. Without named locks it runs unlocked.
        $account = self::account($given_uid, $email);
        $lock = 'murvc_place_' . md5($wpdb->prefix . $season . '|' . ($account['user_id'] > 0 ? 'u' . $account['user_id'] : strtolower($account['email'])));
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ($got !== null && intval($got) !== 1) {
            $result['error'] = 'Another change for this person is still being saved. Reload and check before trying again.';
            $result['reload'] = true;
            return $result;
        }
        self::flush_cache();
        $existing = self::person_rows($account, $email, $season);
        $to_add = array();
        foreach ($wanted as $w) {
            $holding = null;
            $clash = null;
            foreach ($existing as $other) {
                if ((string) $other->team !== $w['team'] || !self::same_place($other->role, $w['role'])) {
                    continue;
                }
                if ($other->start_date === null || ($other->end_date !== null && $other->end_date <= $other->start_date)) {
                    continue;
                }
                if ($other->end_date === null || ($other->start_date <= $start && $other->end_date > $start)) {
                    $holding = $other;
                    break;
                }
                if ($other->start_date > $start) {
                    $clash = $other; // a later, stopped place: an open place from $start would overlap it
                }
            }
            if ($holding) {
                $result['already']++;
                $result['skipped'][] = array('team' => $w['team'], 'role' => $w['role'], 'existing_role' => (string) $holding->role, 'existing_id' => intval($holding->id));
            } elseif ($clash) {
                $result['conflicts'][] = array('team' => $w['team'], 'role' => $w['role'], 'message' => self::clash_message($w['team'], $clash));
            } else {
                $to_add[] = $w;
            }
        }

        // 4. Before: fee and amount due.
        $before = self::snapshot($account, $season);
        $result['had_invoice'] = (bool) $before['invoice'];
        $result['fee_before'] = $before['fee'];
        $result['due_before'] = $before['due'];

        // 5-7. A change row, then the place (active: a new place has no stop).
        $change_ids = array();
        $added_labels = array();
        foreach ($to_add as $w) {
            $change_id = self::insert_change(array(
                'user_id' => $account['user_id'], 'email' => $account['email'], 'season' => $season,
                'team' => $w['team'], 'role' => $w['role'], 'action' => 'added', 'source' => $source,
                'new_start' => $start, 'fee_before' => $before['fee'], 'due_before' => $before['due'],
                'note' => 'placed from ' . self::format_date($start),
            ));
            if ($change_id <= 0) {
                $result['conflicts'][] = array('team' => $w['team'], 'role' => $w['role'], 'message' => 'The change could not be recorded, so this place was not saved.');
                continue;
            }
            $inserted = $wpdb->insert(
                $wpdb->prefix . 'team_assignments',
                array(
                    'user_id' => $account['user_id'],
                    'email' => $email,
                    'season' => $season,
                    'team' => $w['team'],
                    'role' => $w['role'],
                    'registration_status' => 'active',
                    'start_date' => $start,
                    'is_active' => 1,
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
            );
            if (!$inserted) {
                self::drop_change($change_id);
                $result['conflicts'][] = array('team' => $w['team'], 'role' => $w['role'], 'message' => 'The place could not be saved.');
                continue;
            }
            $row_id = intval($wpdb->insert_id);
            $wpdb->update($wpdb->prefix . 'team_assignment_changes', array('assignment_id' => $row_id), array('id' => $change_id), array('%d'), array('%d'));
            $change_ids[] = $change_id;
            $result['row_ids'][] = $row_id;
            $result['added']++;
            $added_labels[] = self::place_label($w['team'], $w['role']);
        }
        if ($got !== null) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        self::flush_cache();

        // 9. One re-price, only when something was added or there's no
        // invoice yet (anyone else is unchanged since the last run).
        if ($result['added'] > 0 || !$result['had_invoice']) {
            $fees = self::fees();
            self::record_class($account['email'], $season, $source, $fees);
            if ($fees->generate_invoice($account['email'], $season)) {
                if ($result['had_invoice'] && $fees->last_kept_manual()) {
                    $result['manual_kept'] = true;
                } elseif ($result['had_invoice']) {
                    $result['repriced'] = true;
                } else {
                    $result['invoiced'] = true;
                }
            } elseif ($result['released'] && !$result['had_invoice']) {
                // Released, on a team, and still no fee: the matrix has no
                // amount for their class and role. (No place inside the
                // season dates at all is nothing to bill, not a gap.)
                $breakdown = $fees->fee_breakdown($account['email'], $season);
                $result['not_priced'] = !empty($breakdown['stretches']);
            }
            self::flush_cache();
        }

        // 10. After, and one log line for the person.
        if ($result['added'] > 0) {
            $after = self::snapshot($account, $season);
            $result['fee_after'] = $after['fee'];
            $result['due_after'] = $after['due'];
            self::finish_change($change_ids, $after);
            self::log_change($account, $season, implode(', ', $added_labels) . ': added from ' . self::format_date($start), $before, $after, $source);
        } else {
            $result['fee_after'] = $before['fee'];
            $result['due_after'] = $before['due'];
        }

        $result['ok'] = $result['added'] > 0 || $result['already'] > 0;
        if (!$result['ok']) {
            $messages = array();
            foreach ($result['conflicts'] as $conflict) {
                $messages[] = $conflict['team'] . ': ' . $conflict['message'];
            }
            $result['error'] = $messages ? implode(' ', $messages) : 'Nothing was placed.';
        }
        return $result;
    }

    /**
     * Steps 1-2 for an edit of one place, writing nothing: load the row,
     * refuse unless it is a 2027+ place still as the caller saw it
     * ($args['expect']), validate the dates and the overlap rules, and say
     * what the write would be. $op: set_start (start), stop (date),
     * clear_stop, remove, change_role (team, role, from). Returns a
     * refusal() or array(ok => true, ...) for execute() and preview().
     */
    private static function plan($op, $args) {
        $row = self::load_row(isset($args['id']) ? $args['id'] : 0);
        if (!$row) {
            return self::refusal('That team place no longer exists — reload the page.', true);
        }
        $season = (string) $row->season;
        if (!TeamOversight_Fees::timeline_applies($season)) {
            return self::refusal('Fee timelines start with the ' . TeamOversight_Fees::FEE_TIMELINE_FROM . ' season; this place is in ' . $season . '.');
        }
        if (!self::expect_matches($row, isset($args['expect']) ? $args['expect'] : null)) {
            return self::refusal(self::RELOAD_MESSAGE, true);
        }

        $current = self::normalise_row(clone $row); // start always a date (created_date for a blank one)
        $recorded_start = self::parse_date($row->start_date);
        $start = $current->start_date;
        $end = $current->end_date;
        $account = self::account($row->user_id, $row->email);
        self::flush_cache();
        $others = self::person_rows($account, $row->email, $season);
        $today = current_time('Y-m-d');
        $playing = in_array($row->role, self::PLAYING_ROLES, true);
        $place = self::place_label($row->team, $row->role);

        $plan = array(
            'ok' => true, 'op' => $op, 'row' => $row, 'season' => $season, 'account' => $account, 'today' => $today,
            'unchanged' => false, 'set' => array(), 'delete' => false, 'insert' => null, 'leaves_team' => false,
            'action' => '', 'note' => '', 'what' => '',
            'old_start' => $recorded_start, 'new_start' => $recorded_start, 'old_end' => $end, 'new_end' => $end,
            'after' => array('start' => $start, 'end' => $end, 'team' => (string) $row->team, 'role' => (string) $row->role),
        );

        if ($op === 'set_start') {
            $new = isset($args['start']) && is_string($args['start']) ? trim($args['start']) : '';
            $invalid = self::validate_start($season, $new);
            if ($invalid !== null) {
                return self::refusal($invalid);
            }
            if ($end !== null && $new >= $end) {
                return self::refusal(sprintf('The start must be before the stop date (%s). A place that never happened is a Remove (added by mistake).', self::format_date($end)));
            }
            if ($recorded_start !== null && $new === $recorded_start) {
                $plan['unchanged'] = true;
                return $plan;
            }
            $other = self::clash($others, $row->team, $row->role, $new, $end, $row->id);
            if ($other) {
                return self::refusal(self::clash_message($row->team, $other));
            }
            $plan['set'] = array('start_date' => $new);
            $plan['action'] = 'start_changed';
            $plan['new_start'] = $new;
            $plan['after']['start'] = $new;
            $plan['note'] = $recorded_start === null ? 'start recorded (was blank)' : 'start moved';
            $plan['what'] = sprintf('%s: start %s → %s', $place, $recorded_start === null ? 'not recorded' : self::format_date($recorded_start), self::format_date($new));
        } elseif ($op === 'stop') {
            $date = isset($args['date']) && is_string($args['date']) ? trim($args['date']) : '';
            if (self::parse_date($date) === null) {
                return self::refusal('Pick a valid stop date.');
            }
            if ($date <= $start) {
                return self::refusal(sprintf('The stop date must be after the start (%s). A place that never happened is a Remove (added by mistake).', self::format_date($start)));
            }
            if ($end !== null && $date === $end) {
                $plan['unchanged'] = true;
                return $plan;
            }
            $other = self::clash($others, $row->team, $row->role, $start, $date, $row->id);
            if ($other) {
                return self::refusal(self::clash_message($row->team, $other));
            }
            $plan['set'] = array('end_date' => $date);
            $plan['action'] = $end === null ? 'stopped' : 'stop_changed';
            $plan['new_end'] = $date;
            $plan['after']['end'] = $date;
            $plan['leaves_team'] = $playing && $date <= $today;
            $charged_to = self::format_date(self::add_days($date, -1));
            $plan['note'] = 'charged up to ' . $charged_to;
            $plan['what'] = $end === null
                ? sprintf('%s: stopped %s (charged up to %s)', $place, self::format_date($date), $charged_to)
                : sprintf('%s: stop %s → %s (charged up to %s)', $place, self::format_date($end), self::format_date($date), $charged_to);
        } elseif ($op === 'clear_stop') {
            if ($end === null) {
                return self::refusal('This place has no stop date.');
            }
            $other = self::clash($others, $row->team, $row->role, $start, null, $row->id);
            if ($other) {
                return self::refusal(self::clash_message($row->team, $other));
            }
            $plan['set'] = array('end_date' => null);
            $plan['action'] = 'stop_cleared';
            $plan['new_end'] = null;
            $plan['after']['end'] = null;
            $plan['note'] = 'still on the team';
            $plan['what'] = sprintf('%s: stop %s cleared (still on the team)', $place, self::format_date($end));
        } elseif ($op === 'remove') {
            $plan['delete'] = true;
            $plan['action'] = 'removed_mistake';
            $plan['new_start'] = null;
            $plan['new_end'] = null;
            $plan['after'] = null;
            $plan['leaves_team'] = $playing;
            $plan['note'] = 'added by mistake: never on the team, charged nothing';
            $plan['what'] = sprintf('%s: removed (added by mistake, never on the team)', $place);
        } elseif ($op === 'change_role') {
            $team = isset($args['team']) && is_string($args['team']) ? trim($args['team']) : '';
            $role = isset($args['role']) && is_string($args['role']) ? trim($args['role']) : '';
            $from = isset($args['from']) && is_string($args['from']) ? trim($args['from']) : '';
            $database = new TeamOversight_Database();
            if ($team === '' || !in_array($role, array_keys($database->get_roles()), true)) {
                return self::refusal('Pick a team and a role.');
            }
            if ($team !== (string) $row->team && !array_key_exists($team, $database->get_teams($season))) {
                return self::refusal(sprintf('%s is not a %s team.', $team, $season));
            }
            if ($team === (string) $row->team && $role === (string) $row->role) {
                return self::refusal('That is the place\'s team and role already.');
            }
            if (self::parse_date($from) === null) {
                return self::refusal('Pick a valid date for the change.');
            }
            if ($end !== null && $from >= $end) {
                return self::refusal(sprintf('This place stopped on %s; a change must start before then.', self::format_date($end)));
            }
            $in_place = $from <= $start;
            $new_from = $in_place ? $start : $from;
            // Joining another team is joining: the late-join cutoff applies.
            // A role change on a team they hold is allowed after it.
            if ($team !== (string) $row->team) {
                $cutoff = TeamOversight_Fees::latest_start_date($season);
                if ($cutoff !== null && $new_from > $cutoff) {
                    return self::refusal(self::cutoff_message($season, $new_from, $cutoff));
                }
            }
            $other = self::clash($others, $team, $role, $new_from, $end, $row->id);
            if ($other) {
                return self::refusal(self::clash_message($team, $other));
            }
            $plan['action'] = 'role_changed';
            $plan['leaves_team'] = $playing && ($team !== (string) $row->team || !in_array($role, self::PLAYING_ROLES, true))
                && ($in_place || $from <= $today);
            $new_place = self::place_label($team, $role);
            if ($in_place) {
                $plan['set'] = array('team' => $team, 'role' => $role);
                $plan['after']['team'] = $team;
                $plan['after']['role'] = $role;
                $plan['note'] = 'corrected in place: now ' . $new_place;
                $plan['what'] = sprintf('%s → %s (corrected in place, from the start)', $place, $new_place);
            } else {
                $plan['set'] = array('end_date' => $from);
                $plan['new_end'] = $from;
                $plan['after']['end'] = $from;
                $plan['insert'] = array('team' => $team, 'role' => $role, 'start' => $from, 'end' => $end);
                $plan['note'] = 'now ' . $new_place . ' from ' . self::format_date($from);
                $plan['what'] = sprintf('%s → %s from %s', $place, $new_place, self::format_date($from));
            }
        } elseif ($op === 'set_dates') {
            // The card's Save with a start and a stop, checked together
            // (start: '' = keep it; end: '' = no stop). Preview prices it;
            // handle_post() writes it as separate set_start / stop /
            // clear_stop changes, each with its own change row, so it is
            // never executed as it is.
            $new = isset($args['start']) && is_string($args['start']) ? trim($args['start']) : '';
            $new_end = isset($args['end']) && is_string($args['end']) ? trim($args['end']) : '';
            $start_changes = $new !== '' && $new !== $recorded_start;
            $new_start = $start_changes ? $new : $start;
            if ($start_changes) {
                $invalid = self::validate_start($season, $new);
                if ($invalid !== null) {
                    return self::refusal($invalid);
                }
            }
            if ($new_end === '') {
                $new_end = null;
            } elseif (self::parse_date($new_end) === null) {
                return self::refusal('Pick a valid stop date.');
            }
            if ($new_end !== null && $new_end <= $new_start) {
                return self::refusal(sprintf('The stop date must be after the start (%s). A place that never happened is a Remove (added by mistake).', self::format_date($new_start)));
            }
            if (!$start_changes && $new_end === $end) {
                $plan['unchanged'] = true;
                return $plan;
            }
            $other = self::clash($others, $row->team, $row->role, $new_start, $new_end, $row->id);
            if ($other) {
                return self::refusal(self::clash_message($row->team, $other));
            }
            $plan['preview_only'] = true;
            $plan['new_start'] = $start_changes ? $new : $recorded_start;
            $plan['new_end'] = $new_end;
            $plan['after']['start'] = $new_start;
            $plan['after']['end'] = $new_end;
            $plan['leaves_team'] = $playing && $new_end !== null && $new_end <= $today;
            $plan['what'] = sprintf('%s: from %s%s', $place, self::format_date($new_start), $new_end === null ? ', still on' : ' to ' . self::format_date($new_end));
        } else {
            return self::refusal('Unknown change.');
        }
        return $plan;
    }

    /** The person's places as they would be after a plan (copies; the saved rows are untouched), for pricing. */
    private static function rows_after($plan) {
        $out = array();
        foreach (self::rows($plan['account']['email'], $plan['season']) as $r) {
            if (intval($r->id) === intval($plan['row']->id)) {
                if ($plan['after'] === null) {
                    continue; // removed
                }
                $r->start_date = $plan['after']['start'];
                $r->end_date = $plan['after']['end'];
                $r->team = $plan['after']['team'];
                $r->role = $plan['after']['role'];
            }
            $out[] = $r;
        }
        if ($plan['insert']) {
            $out[] = (object) array(
                'id' => 0, 'user_id' => $plan['account']['user_id'], 'email' => (string) $plan['row']->email, 'season' => $plan['season'],
                'team' => $plan['insert']['team'], 'role' => $plan['insert']['role'], 'registration_status' => 'active',
                'start_date' => $plan['insert']['start'], 'start_missing' => false, 'end_date' => $plan['insert']['end'],
                'is_active' => 1, 'created_ts' => 0,
            );
        }
        return $out;
    }

    /** Steps 3-11 for a plan(): write it, re-price, record and log. */
    private static function execute($plan, $source, $log = array()) {
        global $wpdb;
        if (empty($plan['ok'])) {
            return $plan;
        }
        if (!empty($plan['preview_only'])) {
            return self::refusal('That change is checked and previewed only; it is saved as separate start and stop changes.');
        }
        if ($plan['unchanged']) {
            return array('ok' => true, 'unchanged' => true, 'error' => '', 'reload' => false, 'message' => 'Nothing to change.');
        }
        $row = $plan['row'];
        $season = $plan['season'];
        $account = $plan['account'];
        $today = $plan['today'];
        $table = $wpdb->prefix . 'team_assignments';

        // 4. Before.
        $before = self::snapshot($account, $season);

        // 5. The change row, with the row as it was.
        $change_id = self::insert_change(array(
            'assignment_id' => $row->id, 'user_id' => $account['user_id'], 'email' => $account['email'], 'season' => $season,
            'team' => $row->team, 'role' => $row->role, 'action' => $plan['action'], 'source' => $source,
            'old_start' => $plan['old_start'], 'new_start' => $plan['new_start'], 'old_end' => $plan['old_end'], 'new_end' => $plan['new_end'],
            'fee_before' => $before['fee'], 'due_before' => $before['due'], 'note' => $plan['note'],
        ));
        if ($change_id <= 0) {
            return self::refusal('The change could not be recorded, so nothing was saved.');
        }

        // 6-7. Compare-and-set (the dates decide is_active).
        if ($plan['delete']) {
            $done = $wpdb->query("DELETE FROM {$table} WHERE " . self::cas_where($row));
        } else {
            $sets = array();
            foreach ($plan['set'] as $column => $value) {
                $sets[] = $value === null ? "`{$column}` = NULL" : $wpdb->prepare("`{$column}` = %s", $value);
            }
            $sets[] = self::active_sql(array_key_exists('end_date', $plan['set']) ? $plan['set']['end_date'] : $plan['old_end'], $today);
            $done = $wpdb->query("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE " . self::cas_where($row));
        }
        if (intval($done) !== 1) {
            self::drop_change($change_id);
            self::flush_cache();
            return self::refusal(self::RELOAD_MESSAGE, true);
        }

        // A change from a date on: the new place, with its own change row.
        $new_row_id = 0;
        if ($plan['insert']) {
            $insert = $plan['insert'];
            $added_id = self::insert_change(array(
                'user_id' => $account['user_id'], 'email' => $account['email'], 'season' => $season,
                'team' => $insert['team'], 'role' => $insert['role'], 'action' => 'added', 'source' => $source,
                'new_start' => $insert['start'], 'new_end' => $insert['end'],
                'note' => 'from place #' . intval($row->id) . ' (role or team change)',
            ));
            $new_active = ($insert['end'] === null || $insert['end'] > $today) ? 1 : 0;
            $new_data = array(
                'user_id' => intval($row->user_id) > 0 ? intval($row->user_id) : $account['user_id'],
                'email' => (string) $row->email,
                'season' => $season,
                'team' => $insert['team'],
                'role' => $insert['role'],
                'registration_status' => $new_active ? 'active' : 'inactive',
                'start_date' => $insert['start'],
                'is_active' => $new_active,
            );
            $new_formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d');
            if ($insert['end'] !== null) {
                $new_data['end_date'] = $insert['end'];
                $new_formats[] = '%s';
            }
            $inserted = $added_id > 0 && $wpdb->insert($table, $new_data, $new_formats);
            if (!$inserted) {
                // Put the old place back as it was (only if still as we left it).
                $moved = clone $row;
                $moved->end_date = $insert['start'];
                $restore = ($plan['old_end'] === null ? '`end_date` = NULL' : $wpdb->prepare('`end_date` = %s', $plan['old_end']))
                    . ', ' . self::active_sql($plan['old_end'], $today);
                $wpdb->query("UPDATE {$table} SET {$restore} WHERE " . self::cas_where($moved));
                self::drop_change($added_id);
                self::drop_change($change_id);
                self::flush_cache();
                return self::refusal('The new place could not be saved, so nothing was changed.');
            }
            $new_row_id = intval($wpdb->insert_id);
            $wpdb->update($wpdb->prefix . 'team_assignment_changes', array('assignment_id' => $new_row_id), array('id' => $added_id), array('%d'), array('%d'));
        }
        self::flush_cache();

        // 8. Trial verdicts, when a playing place left its team today or earlier.
        $synced = false;
        if ($plan['leaves_team']) {
            $synced = self::sync_if_left($account, (string) $row->email, $season, (string) $row->team, $today);
        }

        // 9. Re-price under the account email, through generate_invoice()
        // as adds do: it re-prices an existing invoice (keeping a manual
        // fee), bills nothing before the release, and once released creates
        // the invoice for someone an edit brings back inside the season
        // (a cleared pre-season stop) — the daily release has already run.
        self::fees()->generate_invoice($account['email'], $season);
        self::flush_cache();

        // 10. After.
        $after = self::snapshot($account, $season);
        self::finish_change(array($change_id), $after);
        self::log_change($account, $season, $plan['what'], $before, $after, $source);

        // 11. Remove: restart the class history when no place is left, then
        // the invoice goes only if it is a clean slate.
        $guard = null;
        $reset = false;
        if ($plan['delete']) {
            self::flush_cache();
            if (!self::person_rows($account, (string) $row->email, $season)) {
                $reset = self::insert_change(array(
                    'user_id' => $account['user_id'], 'email' => $account['email'], 'season' => $season,
                    'action' => 'history_reset', 'source' => $source,
                    'note' => 'no ' . $season . ' place left: the fee class history starts again',
                )) > 0;
            }
            if ($before['invoice']) {
                $guard = self::fees()->delete_invoice_if_empty($account['email'], $season, intval($before['invoice']->id), array(
                    'amount' => $before['invoice']->invoice_amount,
                    'reason' => isset($log['reason']) ? $log['reason'] : 'removed by mistake, nothing paid',
                    'user_id' => $account['user_id'],
                ));
            }
        }

        return array(
            'ok' => true, 'unchanged' => false, 'error' => '', 'reload' => false,
            'message' => ucfirst($plan['what']) . '. Fee ' . self::money($before['fee']) . ' → ' . self::money($after['fee']) . '.',
            'change_id' => $change_id, 'row_id' => intval($row->id), 'new_row_id' => $new_row_id,
            'fee_before' => $before['fee'], 'fee_after' => $after['fee'], 'due_before' => $before['due'], 'due_after' => $after['due'],
            'synced' => $synced, 'history_reset' => $reset, 'invoice' => $guard,
        );
    }

    /**
     * Correct a place's start date (backdating is normal: the fee re-prices
     * to the real date). $expect_start: the start the admin saw ('' for
     * blank; false skips the check). Returns ok, error, reload (changed by
     * someone else), unchanged, message, fee_before / fee_after,
     * due_before / due_after, change_id.
     */
    public static function set_start($id, $new, $expect_start, $source = 'timeline') {
        self::flush_cache();
        $expect = $expect_start === false ? null : array('start' => $expect_start);
        return self::execute(self::plan('set_start', array('id' => $id, 'start' => $new, 'expect' => $expect)), $source);
    }

    /**
     * "Stopped playing on [date]": $date is the first day off the team (it
     * isn't charged). The place keeps its history and the fee re-prices to
     * that date. A future date leaves them on the roster until the daily
     * job runs on it. $expect_end: the stop the admin saw ('' for none;
     * false skips the check). Same return as set_start().
     */
    public static function stop($id, $date, $expect_end, $source = 'timeline') {
        self::flush_cache();
        $expect = $expect_end === false ? null : array('end' => $expect_end);
        return self::execute(self::plan('stop', array('id' => $id, 'date' => $date, 'expect' => $expect)), $source);
    }

    /** Take a place's stop date away (still on the team). Same return as set_start(). */
    public static function clear_stop($id, $expect_end, $source = 'timeline') {
        self::flush_cache();
        $expect = $expect_end === false ? null : array('end' => $expect_end);
        return self::execute(self::plan('clear_stop', array('id' => $id, 'expect' => $expect)), $source);
    }

    /**
     * "Remove (added by mistake)": they were never on the team, so the
     * place is charged nothing. Deletes this one row by id, whatever its
     * role (the row as it was is in the change row first); a player's
     * other places, and staff places, are untouched. With no place left in
     * the season the class history restarts, and the invoice is deleted
     * only when delete_invoice_if_empty() allows (no payments, not a
     * manual fee, nothing accrued); otherwise it drops to what remains and
     * payments show as credit. $expect: array(start, end, team, role) as
     * the admin saw them (null skips the check); $source: timeline or
     * undo; $log['reason']: the invoice-removal log reason. Returns as
     * set_start() plus history_reset and invoice (the guard's answer, null
     * with no invoice).
     */
    public static function remove_mistake($id, $expect = null, $source = 'timeline', $log = array()) {
        self::flush_cache();
        return self::execute(self::plan('remove', array('id' => $id, 'expect' => $expect)), $source, $log);
    }

    /**
     * "Change role or team from [date]": stops the place on $from and
     * starts the new one that day (a new row with its own 'added' change
     * row). A date on or before the place's start is a correction made in
     * place. Joining another team after the late-join cutoff is refused; a
     * role change on the same team is not. Same return as set_start() plus
     * new_row_id.
     */
    public static function change_role($id, $team, $role, $from, $expect = null, $source = 'timeline') {
        self::flush_cache();
        return self::execute(self::plan('change_role', array('id' => $id, 'team' => $team, 'role' => $role, 'from' => $from, 'expect' => $expect)), $source);
    }

    /**
     * Price a change without writing anything, through the same validation
     * and the same fee_breakdown() as the writer. $op: set_start (id,
     * start), stop (id, date), clear_stop (id), remove (id), change_role
     * (id, team, role, from), set_dates (id, start, end: the card's Save
     * of both dates at once; start '' keeps it, end '' = no stop), or add
     * (person, season, teams, start); each may carry expect. Returns ok, error, reload, unchanged, fee_before,
     * fee_after, due_before / due_after (with an invoice, null otherwise;
     * the amount due on the timeline schedule), manual (a fee set with
     * Edit Fee, which re-pricing keeps), released, and text ("Fee $X →
     * $Y · due now $A → $B", the due part with an invoice; plain text, so
     * insert it with textContent).
     */
    public static function preview($op, $args) {
        self::flush_cache();
        $args = (array) $args;
        if ($op === 'add') {
            $season = isset($args['season']) ? (string) $args['season'] : '';
            if (!TeamOversight_Fees::timeline_applies($season)) {
                return self::refusal('Fee timelines start with the ' . TeamOversight_Fees::FEE_TIMELINE_FROM . ' season.');
            }
            $start = isset($args['start']) && is_string($args['start']) ? trim($args['start']) : '';
            $invalid = self::validate_start($season, $start);
            if ($invalid !== null) {
                return self::refusal($invalid);
            }
            $person = isset($args['person']) ? (array) $args['person'] : array();
            $account = self::account(isset($person['user_id']) ? $person['user_id'] : 0, isset($person['email']) ? $person['email'] : '');
            $rows = self::rows($account['email'], $season);
            foreach (isset($args['teams']) ? (array) $args['teams'] : array() as $entry) {
                $entry = (array) $entry;
                $rows[] = (object) array('id' => 0, 'user_id' => $account['user_id'], 'email' => $account['email'], 'season' => $season,
                    'team' => isset($entry['team']) ? (string) $entry['team'] : '', 'role' => isset($entry['role']) ? (string) $entry['role'] : '',
                    'registration_status' => 'active', 'start_date' => $start, 'start_missing' => false, 'end_date' => null, 'is_active' => 1, 'created_ts' => 0);
            }
            $plan = array('ok' => true, 'unchanged' => false, 'season' => $season, 'account' => $account);
        } else {
            $plan = self::plan($op, $args);
            if (empty($plan['ok'])) {
                return $plan;
            }
            $season = $plan['season'];
            $account = $plan['account'];
            $rows = $plan['unchanged'] ? null : self::rows_after($plan);
        }

        $before = self::snapshot($account, $season);
        $after_breakdown = $rows === null ? $before['breakdown'] : self::fees()->fee_breakdown($account['email'], $season, $rows);
        $fee_after = round(floatval($after_breakdown['total']), 2);
        $released = TeamOversight_Fees::fees_released($season);
        $manual = $before['invoice'] && intval($before['invoice']->fee_overridden) === 1;
        $due_after = null;
        if ($before['invoice']) {
            // What the invoice would be: kept when manual or before the
            // release, $0 with no stretch left, kept when nothing priced.
            $amount = floatval($before['invoice']->invoice_amount);
            if (!$manual && $released) {
                $priced = false;
                foreach ($after_breakdown['stretches'] as $s) {
                    if ($s['top']) {
                        $priced = true;
                    }
                }
                if (empty($after_breakdown['stretches'])) {
                    $amount = 0.0;
                } elseif ($priced) {
                    $amount = $fee_after;
                }
            }
            $schedule = self::schedule($before['invoice'], $rows === null ? $before['rows'] : $rows, null, $amount);
            $due_after = $schedule['due_today'];
        }
        $text = ($manual ? 'Calculated fee ' : 'Fee ') . self::money($before['fee']) . ' → ' . self::money($fee_after);
        if ($manual) {
            $text .= ' (fee set with Edit Fee stays at ' . self::money($before['invoice']->invoice_amount) . ')';
        } elseif (!$released) {
            $text .= ' (not billed until ' . $season . ' fees are released)';
        }
        // What falls due by today on their own schedule, before and after
        // (1.70.2: the amount they would see and be asked to pay).
        if ($before['due'] !== null && $due_after !== null) {
            $text .= ' · due now ' . self::money($before['due']) . ' → ' . self::money($due_after);
        }
        return array(
            'ok' => true, 'error' => '', 'reload' => false, 'unchanged' => !empty($plan['unchanged']),
            'fee_before' => $before['fee'], 'fee_after' => $fee_after, 'due_before' => $before['due'], 'due_after' => $due_after,
            'manual' => $manual, 'released' => $released, 'text' => $text,
        );
    }

    /**
     * Daily (the membership cron): a stop date that has arrived takes the
     * place off the roster. Every 2027+ place still active whose stop is
     * today or earlier is set inactive (compare-and-set on the stop, so an
     * edit at the same moment wins), with a stop_took_effect change row
     * (recorded_by 0, the job), and a playing place drops its team's
     * Selected / Training Only verdict to Tentative, as an immediate stop
     * would. No re-price: the fee and the due date already use the stop
     * date. $today: the day to apply ('YYYY-MM-DD', default the site's
     * today); $season: only that season (default every 2027+ season).
     * Returns how many places it took off.
     */
    public static function apply_due_stops($today = null, $season = null) {
        global $wpdb;
        $today = (is_string($today) && self::parse_date($today) !== null) ? $today : current_time('Y-m-d');
        $only = ($season !== null && TeamOversight_Fees::timeline_applies($season)) ? $wpdb->prepare(' AND season = %s', (string) $season) : '';
        $due = $wpdb->get_results($wpdb->prepare("
            SELECT id, user_id, email, season, team, role, start_date, end_date FROM {$wpdb->prefix}team_assignments
            WHERE CAST(season AS UNSIGNED) >= %d AND is_active = 1 AND end_date IS NOT NULL AND end_date <= %s" . $only . "
            ORDER BY id
        ", TeamOversight_Fees::FEE_TIMELINE_FROM, $today));
        $flipped = 0;
        foreach ($due ? $due : array() as $row) {
            if (!TeamOversight_Fees::timeline_applies($row->season) || self::parse_date($row->end_date) === null) {
                continue;
            }
            $done = $wpdb->query($wpdb->prepare("
                UPDATE {$wpdb->prefix}team_assignments SET is_active = 0, registration_status = 'inactive'
                WHERE id = %d AND is_active = 1 AND end_date = %s
            ", $row->id, $row->end_date));
            if (intval($done) !== 1) {
                continue;
            }
            $flipped++;
            self::flush_cache();
            $account = self::account($row->user_id, $row->email);
            self::insert_change(array(
                'assignment_id' => $row->id, 'user_id' => $account['user_id'], 'email' => $account['email'], 'season' => (string) $row->season,
                'team' => $row->team, 'role' => $row->role, 'action' => 'stop_took_effect', 'source' => 'cron',
                'old_start' => self::parse_date($row->start_date), 'new_start' => self::parse_date($row->start_date),
                'old_end' => $row->end_date, 'new_end' => $row->end_date,
                'note' => 'stop date reached: off the roster', 'recorded_by' => 0,
            ));
            if (in_array($row->role, self::PLAYING_ROLES, true)) {
                self::sync_if_left($account, (string) $row->email, (string) $row->season, (string) $row->team, $today);
            }
        }
        if ($flipped) {
            self::flush_cache();
        }
        return $flipped;
    }

    // ------------------------------------------------------------------
    // The Member Lookup card (1.70.1). Server-rendered HTML and CSS (the
    // styles live in TeamOversight_Member_Lookup::render_styles()), no
    // library. Every form posts to admin-post.php and saves through the
    // writers above, so saving works without JavaScript; the only script
    // is the Preview button, which writes its answer with textContent.
    // ------------------------------------------------------------------

    /** The admin-post action the card's forms post to, and the Preview AJAX action. */
    const POST_ACTION = 'murvc_fee_timeline';
    const PREVIEW_ACTION = 'murvc_fee_timeline_preview';

    /** Transient, plus the admin's user id, holding the last save's notice for the page it redirects to. */
    const NOTICE_TRANSIENT = 'murvc_fee_timeline_notice_';

    /** What the card's forms can ask for. */
    const CARD_OPS = array('save_dates', 'stop', 'clear_stop', 'remove', 'change_role');

    /** The order places are listed in on the card (by team, then role). */
    const ROLE_ORDER = array('playing_member', 'training_only', 'team_manager', 'coach', 'assistant_coach', 'supporter');

    /** The roles "Change role or team" offers. */
    const CARD_ROLES = array('playing_member', 'training_only', 'coach', 'assistant_coach', 'team_manager');

    /** The nonce action for one place's forms and its Preview. */
    public static function nonce_action($assignment_id) {
        return 'murvc_fee_timeline_' . intval($assignment_id);
    }

    /**
     * The fee-timeline seasons (2027+) an account has a team place or an
     * invoice in, newest first: the card shows the first unless the page
     * asks for another. Places match the account id or its email (as
     * rows() does); invoices follow get_user_invoices()'s rule.
     */
    public static function person_seasons($user) {
        global $wpdb;
        if (!($user instanceof WP_User) || intval($user->ID) <= 0) {
            return array();
        }
        $found = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments WHERE user_id = %d OR email = %s
            UNION
            SELECT DISTINCT season FROM {$wpdb->prefix}team_invoices WHERE user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s)
        ", intval($user->ID), (string) $user->user_email, intval($user->ID), (string) $user->user_email));
        $seasons = array();
        foreach ((array) $found as $season) {
            if (TeamOversight_Fees::timeline_applies($season)) {
                $seasons[] = (string) $season;
            }
        }
        $seasons = array_values(array_unique($seasons));
        rsort($seasons, SORT_STRING);
        return $seasons;
    }

    /**
     * The Fee timeline card for one account and season (Member Lookup,
     * admins only; prints nothing for anyone else). For a season before
     * FEE_TIMELINE_FROM it only says timelines start in 2027. Otherwise:
     * the season dates, payment window, late-join cutoff and release
     * state; warnings; one bar lane per team and role across the season
     * (payment window shaded, a line for today, stop markers, the part
     * still to come hatched, "start not recorded" badges); each place's
     * forms (dates with Save, Stopped playing on, Change role or team,
     * Remove with its confirm box) and Preview; the fee_breakdown()
     * stretches; the money (fee, paid from the ledger, credit, due by
     * today on the player's own schedule, overdue, next payment or behind
     * since, status and the coaches' pill); and the change history.
     */
    public static function render_person_card($user, $season) {
        if (!current_user_can('manage_options') || !($user instanceof WP_User) || intval($user->ID) <= 0) {
            return;
        }
        $season = is_scalar($season) ? (string) $season : '';
        if (preg_match('/^\d{4}$/D', $season) !== 1) {
            return;
        }
        echo '<div class="murvc-tl" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '">';
        self::render_card_notice();
        self::render_season_links($user, $season);
        if (!TeamOversight_Fees::timeline_applies($season)) {
            echo '<p class="murvc-tl-intro">Fee timelines start with the ' . intval(TeamOversight_Fees::FEE_TIMELINE_FROM) . ' season. '
                . esc_html($season) . ' fees are worked out as they always were: see Fees above, and Payment Management.</p>';
            echo '</div>';
            return;
        }

        $today = current_time('Y-m-d');
        self::flush_cache();
        $rows = self::rows($user->user_email, $season);
        $invoice = self::card_invoice($user, $season);
        self::render_card_header($season);
        if (empty($rows) && !$invoice) {
            echo '<p class="murvc-muted">No ' . esc_html($season) . ' team places or fee for this account. Places are added by Finalise Coach Selections, Accept and Add Assignment.</p>';
            echo '</div>';
            return;
        }
        usort($rows, array(__CLASS__, 'compare_places'));
        $flags = self::place_flags($user, $season, $rows);
        $breakdown = self::fees()->fee_breakdown($user->user_email, $season, $rows);

        self::render_card_warnings($season, $rows, $flags);
        echo '<h4 class="murvc-tl-sub">Team places</h4>';
        if (empty($rows)) {
            echo '<p class="murvc-muted">No ' . esc_html($season) . ' team places left.</p>';
        } else {
            self::render_bars($rows, $season, $today);
            self::render_places($user, $season, $rows, $today, $flags);
        }
        echo '<h4 class="murvc-tl-sub">How the fee is worked out</h4>';
        self::render_stretches($breakdown);
        echo '<h4 class="murvc-tl-sub">Money</h4>';
        self::render_money($user, $season, $invoice, $breakdown);
        echo '<h4 class="murvc-tl-sub">Change history</h4>';
        self::render_history($user, $season);
        self::render_card_script();
        echo '</div>';
    }

    /** usort: places by team, then role (ROLE_ORDER), then start, then id. */
    private static function compare_places($a, $b) {
        $by_team = strcmp((string) $a->team, (string) $b->team);
        if ($by_team !== 0) {
            return $by_team;
        }
        $rank_a = array_search($a->role, self::ROLE_ORDER, true);
        $rank_b = array_search($b->role, self::ROLE_ORDER, true);
        $rank_a = $rank_a === false ? 99 : $rank_a;
        $rank_b = $rank_b === false ? 99 : $rank_b;
        if ($rank_a !== $rank_b) {
            return $rank_a - $rank_b;
        }
        $by_start = strcmp((string) $a->start_date, (string) $b->start_date);
        return $by_start !== 0 ? $by_start : intval($a->id) - intval($b->id);
    }

    /** The account's invoice for the season, with created_ts (get_user_invoices()'s identity rule), or null. */
    private static function card_invoice($user, $season) {
        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT *, UNIX_TIMESTAMP(created_date) AS created_ts FROM {$wpdb->prefix}team_invoices
            WHERE (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s)) AND season = %s
            ORDER BY id LIMIT 1
        ", intval($user->ID), (string) $user->user_email, (string) $season));
        return $invoice ? $invoice : null;
    }

    /** The notice the last save left for this admin (shown once). */
    private static function render_card_notice() {
        $key = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient($key);
        $type = (isset($notice['type']) && in_array($notice['type'], array('success', 'error', 'warning', 'info'), true)) ? $notice['type'] : 'info';
        // "inline" keeps WordPress from moving it to the top of the page.
        echo '<div class="notice notice-' . esc_attr($type) . ' inline murvc-tl-notice"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }

    /** Links to the account's other fee-timeline seasons, when there are any. */
    private static function render_season_links($user, $season) {
        $seasons = self::person_seasons($user);
        if (empty(array_diff($seasons, array($season)))) {
            return;
        }
        $links = array();
        foreach ($seasons as $s) {
            $links[] = $s === $season
                ? '<strong>' . esc_html($s) . '</strong>'
                : '<a href="' . esc_url(TeamOversight_Member_Lookup::profile_url($user->ID, '', $s)) . '">' . esc_html($s) . '</a>';
        }
        echo '<p class="murvc-tl-seasons">Seasons: ' . implode(' &middot; ', $links) . '</p>';
    }

    /** Season dates (what fees cover), payment window ("season dates" when blank), late-join cutoff, release state. */
    private static function render_card_header($season) {
        $dates = TeamOversight_Fees::get_season_dates($season);
        $start = $dates ? self::parse_date($dates['start']) : null;
        $end = $dates ? self::parse_date($dates['end']) : null;
        $window = TeamOversight_Fees::get_payment_window($season);
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        $items = array();
        if ($start !== null && $end !== null && $end > $start) {
            $items[] = array('Season dates', sprintf('%s – %s: fees cover %d days (the end date itself isn\'t charged)', self::format_date($start), self::format_date($end), self::days_between($start, $end)));
        } else {
            $items[] = array('Season dates', 'not set: no pro-rata (the latest place\'s full rate applies) and no late-join cutoff');
        }
        if ($window) {
            if (!$window['from_set'] && !$window['to_set']) {
                $text = 'season dates (' . self::format_date($window['from']) . ' – ' . self::format_date($window['to']) . ')';
            } else {
                $text = 'fees due from ' . self::format_date($window['from']) . ($window['from_set'] ? '' : ' (season start)')
                    . ' to paid in full by ' . self::format_date($window['to']) . ($window['to_set'] ? '' : ' (season end)');
            }
            $items[] = array('Payment window', $text . '. Each player\'s fee falls due evenly from their own first start (not before fees due from) to paid in full by, and all at once when every place has stopped. Paid in full by is also the late-join cutoff.');
        }
        $items[] = array('Late-join cutoff', $cutoff === null ? 'none (no season dates)' : 'nobody starts on a ' . $season . ' team after ' . self::format_date($cutoff));
        $release = TeamOversight_Fees::get_fee_release_date($season);
        if ($release === '') {
            $state = 'being charged (no release date)';
        } elseif (!TeamOversight_Fees::fees_released($season)) {
            $state = 'not released until ' . self::format_date($release) . ': places and changes are saved, and nothing is billed until then';
        } else {
            $applied = get_option(TeamOversight_Fees::APPLIED_OPTION, array());
            $state = 'released ' . self::format_date($release) . ((is_array($applied) && !empty($applied[$season])) ? '' : ' (invoices go out on the next daily run, or Release fees now)');
        }
        $items[] = array('Fees', $state);
        echo '<dl class="murvc-tl-head">';
        foreach ($items as $item) {
            echo '<div><dt>' . esc_html($item[0]) . '</dt><dd>' . esc_html($item[1]) . '</dd></div>';
        }
        echo '</dl>';
    }

    /**
     * Per place: 'outside' (written after the timeline with no 'added'
     * change row, so by something other than the timeline's writers, or
     * its team or role was changed directly), 'before' (placed before
     * 1.70.1: no 'added' row, but the old fee segments hold its team and
     * role) and 'late' (a recorded start after the late-join cutoff).
     * id => array of those flags.
     */
    private static function place_flags($user, $season, $rows) {
        global $wpdb;
        $flags = array();
        if (empty($rows)) {
            return $flags;
        }
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = intval($row->id);
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        $added = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT assignment_id FROM {$wpdb->prefix}team_assignment_changes WHERE action = 'added' AND assignment_id IN ($in)
        ", $ids)));
        // Before 1.70.1 every place change checkpointed a fee segment
        // holding each active team and role, so an old place shows there.
        $segment_places = array();
        $segments = $wpdb->get_col($wpdb->prepare("
            SELECT buckets FROM {$wpdb->prefix}team_fee_segments
            WHERE (user_id = %d OR email = %s) AND season = %s AND buckets IS NOT NULL
        ", intval($user->ID), (string) $user->user_email, (string) $season));
        foreach ((array) $segments as $json) {
            $buckets = json_decode((string) $json, true);
            foreach (is_array($buckets) ? $buckets : array() as $bucket) {
                if (is_array($bucket) && isset($bucket['team'], $bucket['role'])) {
                    $segment_places[$bucket['team'] . '|' . $bucket['role']] = true;
                }
            }
        }
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        foreach ($rows as $row) {
            $has_added = in_array(intval($row->id), $added, true);
            $old = isset($segment_places[$row->team . '|' . $row->role]);
            $flags[intval($row->id)] = array(
                'outside' => !$has_added && !$old,
                'before' => !$has_added && $old,
                'late' => $cutoff !== null && !$row->start_missing && $row->start_date !== null && $row->start_date > $cutoff,
            );
        }
        return $flags;
    }

    /** The card's warnings, from place_flags() and the missing starts. */
    private static function render_card_warnings($season, $rows, $flags) {
        $lists = array('outside' => array(), 'missing' => array(), 'late' => array(), 'before' => array());
        foreach ($rows as $row) {
            $label = self::place_label($row->team, $row->role) . ' (place #' . intval($row->id) . ')';
            $f = isset($flags[intval($row->id)]) ? $flags[intval($row->id)] : array();
            if (!empty($f['outside'])) {
                $lists['outside'][] = $label;
            }
            if (!empty($f['before'])) {
                $lists['before'][] = $label;
            }
            if (!empty($f['late'])) {
                $lists['late'][] = $label;
            }
            if ($row->start_missing) {
                $lists['missing'][] = $label;
            }
        }
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        $warnings = array();
        if ($lists['outside']) {
            $warnings[] = 'Recorded outside the timeline: ' . implode(', ', $lists['outside']) . '. These places have no "added" entry in the change history, so something other than these screens inserted them (or changed the team or role of a place made before the timeline). The fee follows their dates: check them.';
        }
        if ($lists['missing']) {
            $warnings[] = 'Start not recorded: ' . implode(', ', $lists['missing']) . '. Each is charged from the day it was added until a start date is saved below.';
        }
        if ($lists['late'] && $cutoff !== null) {
            $warnings[] = 'Starts after the late-join cutoff (' . self::format_date($cutoff) . '): ' . implode(', ', $lists['late']) . '. Nobody can be placed that late now: check the start.';
        }
        foreach ($warnings as $warning) {
            echo '<div class="notice notice-warning inline murvc-tl-warn"><p>' . esc_html($warning) . '</p></div>';
        }
        if ($lists['before']) {
            echo '<p class="murvc-muted murvc-tl-before">Placed before the fee timeline (1.70.1): ' . esc_html(implode(', ', $lists['before'])) . '. Their dates came from the old screens, so check them once.</p>';
        }
    }

    /**
     * A place's dates in words: "from 1 Mar 2027 · still on", "· stopped
     * 1 Jul 2027 (charged up to 30 Jun 2027)", "· stops ...". With
     * $season_start, a start before it says it is charged from the season.
     */
    private static function span_text($row, $today, $season_start = null) {
        if ($row->start_date === null) {
            $from = 'start not recorded';
        } elseif ($row->start_missing) {
            $from = 'start not recorded (charged from ' . self::format_date($row->start_date) . ', the day it was added)';
        } else {
            $from = 'from ' . self::format_date($row->start_date);
            if ($season_start !== null && $row->start_date < $season_start) {
                $from .= ' (before the season: charged from its start)';
            }
        }
        if ($row->end_date === null) {
            $to = 'still on';
        } else {
            $charged = ' (charged up to ' . self::format_date(self::add_days($row->end_date, -1)) . ')';
            $to = ($row->end_date <= $today ? 'stopped ' : 'stops ') . self::format_date($row->end_date) . $charged;
        }
        return $from . ' · ' . $to;
    }

    /**
     * The bars: one lane per team and role, each place positioned by
     * percentage across the season dates (start inclusive, stop
     * exclusive), the payment window shaded, a line for today, a marker
     * at each stop date, and the part still to come hatched. Nothing to
     * draw against without season dates.
     */
    private static function render_bars($rows, $season, $today) {
        $dates = TeamOversight_Fees::get_season_dates($season);
        $start = $dates ? self::parse_date($dates['start']) : null;
        $end = $dates ? self::parse_date($dates['end']) : null;
        if ($start === null || $end === null || $end <= $start) {
            echo '<p class="murvc-muted">' . esc_html($season) . ' has no season dates, so there is nothing to draw the places against. Set them on Configuration.</p>';
            return;
        }
        $days = self::days_between($start, $end);
        $pct = function ($date) use ($start, $days) {
            return max(0.0, min(100.0, self::days_between($start, $date) * 100.0 / $days));
        };
        $span = function ($from, $to) use ($pct) {
            $left = $pct($from);
            return sprintf('left:%.3F%%;width:%.3F%%;', $left, max(0.0, $pct($to) - $left));
        };
        $window = TeamOversight_Fees::get_payment_window($season);
        $show_today = $today >= $start && $today <= $end;

        $lanes = array();
        foreach ($rows as $row) {
            $lanes[$row->team . '|' . $row->role][] = $row;
        }
        echo '<div class="murvc-tl-bars">';
        foreach ($lanes as $lane) {
            $first = $lane[0];
            $kind = $first->role === 'training_only' ? 'training' : (in_array($first->role, self::PLAYING_ROLES, true) ? 'playing' : 'staff');
            echo '<div class="murvc-tl-lane">';
            echo '<div class="murvc-tl-label"><strong>' . esc_html($first->team) . '</strong> ' . esc_html(TeamOversight_Fees::role_label($first->role));
            foreach ($lane as $row) {
                $outside = $row->start_date !== null && (($row->end_date !== null && $row->end_date <= $start) || $row->start_date >= $end);
                echo '<span class="murvc-tl-span">' . esc_html(self::span_text($row, $today, $start) . ($outside ? ' · outside the season dates: not charged' : '')) . '</span>';
                if ($row->start_missing) {
                    echo '<span class="murvc-tl-badge">start not recorded</span>';
                }
            }
            echo '</div>';
            echo '<div class="murvc-tl-track">';
            if ($window) {
                echo '<div class="murvc-tl-window" style="' . esc_attr($span($window['from'], $window['to'])) . '" title="' . esc_attr('Payment window: ' . self::format_date($window['from']) . ' – ' . self::format_date($window['to'])) . '"></div>';
            }
            foreach ($lane as $row) {
                if ($row->start_date === null) {
                    continue;
                }
                $from = $row->start_date > $start ? $row->start_date : $start;
                $to = ($row->end_date !== null && $row->end_date < $end) ? $row->end_date : $end;
                $title = self::place_label($row->team, $row->role) . ': ' . self::span_text($row, $today);
                if ($to > $from) {
                    $cut = $today < $from ? $from : ($today > $to ? $to : $today);
                    if ($cut > $from) {
                        echo '<div class="murvc-tl-seg seg-' . esc_attr($kind) . ($row->start_missing ? ' is-unrecorded' : '') . '" style="' . esc_attr($span($from, $cut)) . '" title="' . esc_attr($title) . '"></div>';
                    }
                    if ($to > $cut) {
                        echo '<div class="murvc-tl-seg seg-' . esc_attr($kind) . ' is-future" style="' . esc_attr($span($cut, $to)) . '" title="' . esc_attr($title . ' (still to come)') . '"></div>';
                    }
                }
                if ($row->end_date !== null && $row->end_date >= $start && $row->end_date <= $end) {
                    echo '<div class="murvc-tl-stop" style="' . esc_attr(sprintf('left:%.3F%%;', $pct($row->end_date))) . '" title="' . esc_attr(($row->end_date <= $today ? 'Stopped ' : 'Stops ') . self::format_date($row->end_date)) . '"></div>';
                }
            }
            if ($show_today) {
                echo '<div class="murvc-tl-today" style="' . esc_attr(sprintf('left:%.3F%%;', $pct($today))) . '" title="' . esc_attr('Today, ' . self::format_date($today)) . '"></div>';
            }
            echo '</div>';
            echo '</div>';
        }

        // The axis: the season's months.
        $months = array();
        $month = DateTime::createFromFormat('!Y-m-d', substr($start, 0, 8) . '01', new DateTimeZone('UTC'));
        $month->modify('+1 month');
        while ($month->format('Y-m-d') < $end) {
            $months[] = $month->format('Y-m-d');
            $month->modify('+1 month');
        }
        $every = count($months) > 8 ? 2 : 1;
        echo '<div class="murvc-tl-lane murvc-tl-axis-lane"><div class="murvc-tl-label murvc-muted">' . esc_html(self::format_date($start) . ' → ' . self::format_date($end)) . '</div><div class="murvc-tl-axis">';
        foreach ($months as $i => $first_of_month) {
            $label = $i % $every === 0 ? DateTime::createFromFormat('!Y-m-d', $first_of_month, new DateTimeZone('UTC'))->format('M') : '';
            echo '<span class="murvc-tl-tick" style="' . esc_attr(sprintf('left:%.3F%%;', $pct($first_of_month))) . '">' . esc_html($label) . '</span>';
        }
        echo '</div></div>';
        echo '<p class="murvc-tl-legend"><span class="murvc-tl-key key-window"></span> payment window'
            . ($show_today ? ' <span class="murvc-tl-key key-today"></span> today (' . esc_html(self::format_date($today)) . ')' : '')
            . ' <span class="murvc-tl-key key-stop"></span> stop date <span class="murvc-tl-key key-future"></span> still to come</p>';
        echo '</div>';
    }

    /** Each place's forms: dates with Save, Stopped playing on (or Clear the stop), Change role or team, Remove; Preview. */
    private static function render_places($user, $season, $rows, $today, $flags) {
        $database = new TeamOversight_Database();
        $teams = $database->get_teams($season);
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        $guard = self::typo_guard($season);
        $post_url = admin_url('admin-post.php');
        echo '<div class="murvc-tl-places">';
        foreach ($rows as $row) {
            $id = intval($row->id);
            $nonce = wp_create_nonce(self::nonce_action($id));
            $raw_start = $row->start_missing ? '' : (string) $row->start_date;
            $raw_end = $row->end_date === null ? '' : (string) $row->end_date;
            $playing = in_array($row->role, self::PLAYING_ROLES, true);
            $f = isset($flags[$id]) ? $flags[$id] : array();
            $hidden = function ($op) use ($id, $nonce, $user, $season, $raw_start, $raw_end, $row) {
                $fields = array(
                    'action' => self::POST_ACTION, 'op' => $op, 'assignment_id' => $id, 'profile_user' => intval($user->ID), 'season' => $season,
                    '_wpnonce' => $nonce, 'expect_start' => $raw_start, 'expect_end' => $raw_end, 'expect_team' => (string) $row->team, 'expect_role' => (string) $row->role,
                );
                $out = '';
                foreach ($fields as $name => $value) {
                    $out .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
                }
                return $out;
            };
            $preview = '<button type="button" class="button murvc-tl-preview" hidden>Preview</button>';
            $form = '<form method="post" action="' . esc_url($post_url) . '" class="murvc-tl-form';

            echo '<div class="murvc-tl-place" id="fee-timeline-place-' . $id . '">';
            echo '<h5>' . esc_html($row->team) . ' &middot; ' . esc_html(TeamOversight_Fees::role_label($row->role)) . ' <span class="murvc-muted">place #' . $id . '</span>';
            if ($row->start_missing) {
                echo ' <span class="murvc-tl-badge">start not recorded</span>';
            }
            if (!empty($f['outside'])) {
                echo ' <span class="murvc-tl-badge badge-warn">recorded outside the timeline</span>';
            }
            echo '</h5>';
            echo '<p class="murvc-tl-place-dates">' . esc_html(self::span_text($row, $today) . ' · ' . ($row->is_active ? 'on the roster' : 'off the roster')) . '</p>';
            echo '<div class="murvc-tl-forms">';

            // Start and stop, saved together.
            $start_attrs = ' min="' . esc_attr($guard) . '"';
            if ($cutoff !== null && ($raw_start === '' || $raw_start <= $cutoff)) {
                $start_attrs .= ' max="' . esc_attr($cutoff) . '"';
            }
            if ($raw_start !== '') {
                $start_attrs .= ' required';
            }
            echo $form . ' murvc-tl-dates">' . $hidden('save_dates');
            echo '<label>Start <input type="date" name="start_date" value="' . esc_attr($raw_start) . '"' . $start_attrs . '></label>';
            echo '<label>Stop <input type="date" name="end_date" value="' . esc_attr($raw_end) . '"></label>';
            echo '<span class="murvc-tl-actions"><button type="submit" class="button button-primary">Save dates</button> ' . $preview . '</span>';
            echo '<p class="description">' . esc_html(($row->start_missing && $row->start_date !== null ? 'No start was recorded: it is charged from ' . self::format_date($row->start_date) . ' (the day it was added) until you save one. ' : '')
                . 'Start is the first day charged. Stop is the first day off the team (not charged); leave it blank while they are still on.') . '</p>';
            echo '</form>';

            // Stopped playing on (or take the stop away).
            if ($row->end_date === null) {
                $first_stop = $row->start_date !== null ? self::add_days($row->start_date, 1) : $guard;
                $default_stop = $today >= $first_stop ? $today : $first_stop;
                echo $form . ' murvc-tl-stop-form">' . $hidden('stop');
                echo '<label>' . ($playing ? 'Stopped playing on' : 'Stopped on') . ' <input type="date" name="stop_date" value="' . esc_attr($default_stop) . '" min="' . esc_attr($first_stop) . '" required></label>';
                echo '<span class="murvc-tl-actions"><button type="submit" class="button">Stop</button> ' . $preview . '</span>';
                echo '<p class="description">' . esc_html('The first day off the team: charged up to the day before, and the history is kept. A future date leaves them on the roster until then.'
                    . ($playing ? ' Today or earlier also drops their ' . $row->team . ' Selected / Training Only verdict to Tentative (an application left with no team goes back to pending).' : '')) . '</p>';
                echo '</form>';
            } else {
                echo $form . ' murvc-tl-clear-form">' . $hidden('clear_stop');
                echo '<span class="murvc-tl-actions"><button type="submit" class="button">Clear the stop (still on the team)</button> ' . $preview . '</span>';
                echo '<p class="description">' . esc_html('Takes the stop date away, so they are charged again from ' . self::format_date($row->end_date) . ' on.') . '</p>';
                echo '</form>';
            }

            // Change role or team from a date.
            echo $form . ' murvc-tl-role-form">' . $hidden('change_role');
            echo '<label>Change to <select name="new_team">';
            $team_codes = array_keys($teams);
            if (!in_array((string) $row->team, $team_codes, true)) {
                array_unshift($team_codes, (string) $row->team);
            }
            foreach ($team_codes as $code) {
                echo '<option value="' . esc_attr($code) . '"' . selected((string) $code, (string) $row->team, false) . '>' . esc_html($code) . '</option>';
            }
            echo '</select></label> <select name="new_role" aria-label="Role">';
            $roles = self::CARD_ROLES;
            if (!in_array($row->role, $roles, true)) {
                $roles[] = (string) $row->role;
            }
            foreach ($roles as $role) {
                echo '<option value="' . esc_attr($role) . '"' . selected($role, (string) $row->role, false) . '>' . esc_html(TeamOversight_Fees::role_label($role)) . '</option>';
            }
            echo '</select>';
            echo '<label>from <input type="date" name="change_from" value="' . esc_attr($today) . '" required></label>';
            echo '<span class="murvc-tl-actions"><button type="submit" class="button">Change role or team</button> ' . $preview . '</span>';
            echo '<p class="description">' . esc_html('Stops this place on that date and starts the new one the same day. A date on or before its start' . ($row->start_date !== null ? ' (' . self::format_date($row->start_date) . ')' : '') . ' corrects this place instead. Moving to another team after the late-join cutoff is refused; a role change on the same team is not.') . '</p>';
            echo '</form>';

            // Remove (added by mistake).
            echo $form . ' murvc-tl-remove">' . $hidden('remove');
            echo '<label class="murvc-tl-confirm"><input type="checkbox" name="confirm_remove" value="1" required> Yes, they were never on this team — charge nothing</label>';
            echo '<span class="murvc-tl-actions"><button type="submit" class="button murvc-tl-danger">Remove (added by mistake)</button> ' . $preview . '</span>';
            echo '<p class="description">' . esc_html('Deletes this one place (the change history keeps a copy) and charges nothing for it. Someone who played should be stopped instead. Their other places, coach and manager places included, are untouched.'
                . ($playing ? ' Their ' . $row->team . ' Selected / Training Only verdict drops to Tentative.' : '')
                . ' With no place left, an invoice with nothing paid is deleted; a paid one stays, with the payments as credit.') . '</p>';
            echo '</form>';

            echo '</div>';
            echo '<p class="murvc-tl-preview-out" aria-live="polite"></p>';
            echo '</div>';
        }
        echo '</div>';
    }

    /** The fee_breakdown() stretches: dates, places held with their rates, the rate charged and the amount. */
    private static function render_stretches($breakdown) {
        $stretches = $breakdown['stretches'];
        if (empty($stretches)) {
            echo '<p class="murvc-muted">Nothing to charge: no place falls inside the season dates.</p>';
            return;
        }
        echo '<div class="murvc-tl-scroll"><table class="widefat striped murvc-tl-stretches"><thead><tr>'
            . '<th>From</th><th>Last day charged</th><th>Days</th><th>Places held</th><th>Rate</th><th>Amount</th>'
            . '</tr></thead><tbody>';
        foreach ($stretches as $s) {
            $places = array();
            foreach ($s['buckets'] as $b) {
                $text = trim($b['team'] . ' ' . strtolower(TeamOversight_Fees::role_label($b['role']))) . ' (' . $b['class'] . ')'
                    . ($b['charged_as'] !== $b['role'] ? ', charged as ' . strtolower(TeamOversight_Fees::role_label($b['charged_as'])) : '')
                    . ': ' . ($b['rate'] === null ? 'no amount in the fee matrix' : self::money($b['rate']));
                $top = $s['top'] && count($s['buckets']) > 1 && $b['team'] === $s['top']['team'] && $b['role'] === $s['top']['role'];
                $places[] = $top ? '<strong>' . esc_html($text) . '</strong>' : esc_html($text);
            }
            $dated = $breakdown['has_dates'] && !empty($s['to']);
            echo '<tr>';
            echo '<td>' . esc_html(self::format_date($s['from'])) . '</td>';
            echo '<td>' . ($dated ? esc_html(self::format_date(self::add_days($s['to'], -1))) : '&mdash;') . '</td>';
            echo '<td>' . ($breakdown['has_dates'] ? intval($s['days']) : '&mdash;') . '</td>';
            echo '<td>' . implode('<br>', $places) . '</td>';
            echo '<td>' . ($s['top'] ? esc_html(self::money($s['rate'])) : '<em>none</em>') . '</td>';
            echo '<td>' . esc_html(self::money($s['amount'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody><tfoot><tr><th colspan="5">'
            . esc_html($breakdown['has_dates']
                ? 'Season fee: each stretch is its rate × its days ÷ ' . intval($breakdown['season_days']) . ' season days, added up and rounded once'
                : 'Season fee: no season dates, so the latest place\'s full rate (no pro-rata)')
            . '</th><th>' . esc_html(self::money($breakdown['total'])) . '</th></tr></tfoot></table></div>';
        echo '<p class="description">Where places overlap, each day is charged at the highest of them (in bold); fees never add up. Coaches and assistant coaches are charged at the supporter rate, team managers as playing members.</p>';
        if (!empty($breakdown['missing'])) {
            echo '<p class="murvc-owing">Some roles have no amount in the fee matrix, so they were left out. Fill in the blank cells on Configuration.</p>';
        }
    }

    /**
     * Fee (manual marker), paid from the ledger, credit, outstanding, due
     * by today on the player's own schedule (schedule(), the one members
     * see and pay by), overdue, the next payment or how long they've been
     * behind (with the floor explained when it held the clock back),
     * status and the coaches' pill.
     */
    private static function render_money($user, $season, $invoice, $breakdown) {
        global $wpdb;
        $calculated = round(floatval($breakdown['total']), 2);
        $released = TeamOversight_Fees::fees_released($season);
        $release = TeamOversight_Fees::get_fee_release_date($season);
        $lines = array();
        if (!$invoice) {
            $why = $released
                ? (empty($breakdown['stretches']) ? 'no place falls inside the season dates, so there is nothing to bill' : 'it is created on the next change, Finalise or release')
                : 'nothing is billed until fees are released on ' . self::format_date($release);
            $lines[] = array('Fee', esc_html('No ' . $season . ' invoice yet · calculated ' . self::money($calculated) . ' (' . $why . ')'));
        } else {
            $fee = round(floatval($invoice->invoice_amount), 2);
            $manual = intval($invoice->fee_overridden) === 1;
            $ledger = $wpdb->get_row($wpdb->prepare("
                SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM {$wpdb->prefix}team_invoice_payments WHERE invoice_id = %d
            ", intval($invoice->id)));
            $paid = $ledger ? round(floatval($ledger->total), 2) : 0.0;
            $count = $ledger ? intval($ledger->n) : 0;
            $credit = round($paid - $fee, 2);
            $outstanding = round(floatval($invoice->outstanding_amount), 2);

            $fee_html = esc_html(self::money($fee));
            if ($manual) {
                $setter = intval($invoice->fee_overridden_by) > 0 ? get_userdata(intval($invoice->fee_overridden_by)) : false;
                $title = 'Set by hand with Edit Fee' . ($setter ? ' by ' . $setter->display_name : '') . '. Date changes re-price nothing; Use calculated fee on Payment Management applies the calculation.';
                $fee_html .= '<br><small class="fee-manual" title="' . esc_attr($title) . '">Manual fee &middot; calculated ' . esc_html(self::money($calculated)) . '</small>';
            } elseif (!$released) {
                $fee_html .= '<br><small class="murvc-muted">' . esc_html('Fees are not released until ' . self::format_date($release) . ': calculated ' . self::money($calculated) . ', billed then less anything already paid.') . '</small>';
            } elseif (abs($fee - $calculated) >= 0.01) {
                $fee_html .= '<br><small class="murvc-owing">' . esc_html('Differs from the calculation (' . self::money($calculated) . '): it updates on the next change.') . '</small>';
            }
            $lines[] = array('Fee', $fee_html);
            $lines[] = array('Paid (payments ledger)', esc_html(self::money($paid) . ' (' . $count . ' payment' . ($count === 1 ? '' : 's') . ')'));
            $lines[] = array('Credit', $credit >= 0.01 ? '<strong>' . esc_html(self::money($credit)) . '</strong> <span class="murvc-muted">paid above the fee</span>' : '<span class="murvc-muted">&mdash;</span>');
            $lines[] = array('Outstanding', $outstanding > 0 ? '<span class="murvc-owing">' . esc_html(self::money($outstanding)) . '</span>' : esc_html(self::money(0)));

            $schedule = TeamOversight_Payments::invoice_schedule($invoice);
            if (empty($schedule['scheduled'])) {
                $due_html = '<span class="murvc-muted">nothing on a schedule: the season has no dates</span>';
            } else {
                $due_html = esc_html(self::money($schedule['due_today'])) . ' <span class="murvc-muted">' . esc_html('(' . self::describe_due($schedule) . ')') . '</span>';
            }
            $lines[] = array('Due by today', $due_html);
            $overdue = floatval($schedule['overdue']);
            $lines[] = array('Overdue', $overdue > 0 ? '<span class="murvc-owing">' . esc_html(self::money($overdue)) . '</span>' : '<span class="murvc-muted">&mdash;</span>');
            if ($overdue > 0 && !empty($schedule['behind_since'])) {
                $behind = 'since ' . self::format_date($schedule['behind_since']);
                if (!empty($schedule['fell_behind']) && $schedule['fell_behind'] < $schedule['behind_since']) {
                    $behind .= ' (their payments last covered ' . self::format_date($schedule['fell_behind']) . ', but the behind clock never starts before the day their fee, or the change that put more due, was recorded)';
                }
                $lines[] = array('Behind', esc_html($behind));
            } elseif ($outstanding > 0 && !empty($schedule['next_due'])) {
                $lines[] = array('Next payment due', esc_html('on schedule until ' . self::format_date($schedule['next_due'])));
            }
        }

        $standings = TeamOversight_Payments::get_fee_standings(array('me' => array('user_id' => intval($user->ID), 'email' => (string) $user->user_email)), $season);
        if (isset($standings['me'])) {
            $standing = $standings['me'];
            $status = TeamOversight_Payments::describe_standing($standing);
            if ($standing['overdue'] >= 1) {
                $status .= ' (counting all their fees: ' . TeamOversight_Payments::describe_behind($standing['days_behind']) . ')';
            }
            $lines[] = array('Status', esc_html($status));
            $lines[] = array('Coaches see', TeamOversight_Payments::render_standing_pill($standing, $season));
        }

        echo '<table class="murvc-kv murvc-tl-money">';
        foreach ($lines as $line) {
            echo '<tr><th>' . esc_html($line[0]) . '</th><td>' . $line[1] . '</td></tr>';
        }
        echo '</table>';
    }

    /** A schedule()'s due-by-today figure explained, for the card (admins). */
    private static function describe_due($schedule) {
        $today = current_time('Y-m-d');
        if (empty($schedule['window_from']) || empty($schedule['window_to'])) {
            return 'a season from an earlier year is all due';
        }
        if (!empty($schedule['stopped_on']) && $today >= $schedule['stopped_on']) {
            return 'every place has stopped, the last on ' . self::format_date($schedule['stopped_on']) . ', so everything for the time played is due';
        }
        if ($schedule['window_from'] >= $schedule['window_to']) {
            $text = 'their first start (' . self::format_date($schedule['window_from']) . ') is on or after paid in full by, so it all falls due that day';
        } else {
            $text = 'their own schedule: the fee falls due evenly from ' . self::format_date($schedule['window_from'])
                . ' (fees due from, or their first start if later) to ' . self::format_date($schedule['window_to'])
                . ' (paid in full by), and ' . intval($schedule['due_pct']) . '% is due now';
        }
        if (!empty($schedule['stopped_on'])) {
            $text .= '; their last place stops on ' . self::format_date($schedule['stopped_on']) . ', when the rest falls due';
        }
        return $text;
    }

    /** One change row in words. */
    private static function describe_change($c) {
        $d = function ($value) {
            return ($value === null || $value === '') ? 'not recorded' : self::format_date((string) $value);
        };
        // End dates are the first day off: say what was charged.
        $stopped = function ($end) use ($d) {
            $valid = self::parse_date((string) $end);
            return ', stopped ' . $d($end) . ($valid !== null ? ' (charged up to ' . self::format_date(self::add_days($valid, -1)) . ')' : '');
        };
        switch ((string) $c->action) {
            case 'added':
                $text = 'Added from ' . $d($c->new_start) . (!empty($c->new_end) ? $stopped($c->new_end) : '');
                break;
            case 'start_changed':
                $text = 'Start ' . $d($c->old_start) . ' → ' . $d($c->new_start);
                break;
            case 'stopped':
                $text = 'Stopped ' . $d($c->new_end);
                break;
            case 'stop_changed':
                $text = 'Stop ' . $d($c->old_end) . ' → ' . $d($c->new_end);
                break;
            case 'stop_cleared':
                $text = 'Stop ' . $d($c->old_end) . ' cleared: still on';
                break;
            case 'role_changed':
                $text = 'Role or team changed';
                break;
            case 'removed_mistake':
                $text = 'Removed (added by mistake); it ran from ' . $d($c->old_start) . (!empty($c->old_end) ? $stopped($c->old_end) : ', still on');
                break;
            case 'stop_took_effect':
                $text = 'Stop date reached: off the roster';
                break;
            case 'class_recorded':
                $text = !empty($c->old_class) ? 'Fee class ' . $c->old_class . ' → ' . $c->new_class : 'Fee class recorded: ' . $c->new_class;
                break;
            case 'history_reset':
                $text = 'No place left: the fee class history starts again';
                break;
            case 'reconciled':
                $text = 'Filled in from the old fee history';
                break;
            default:
                $text = (string) $c->action;
        }
        $sources = array('finalise' => 'Finalise', 'accept' => 'Accept', 'bulk_accept' => 'bulk accept', 'add_assignment' => 'Add Assignment', 'undo' => 'Undo Assignment', 'cron' => 'daily job', 'reconcile' => 'reconciliation', 'timeline' => 'Fee timeline');
        if (!empty($c->source) && isset($sources[$c->source])) {
            $text .= ' (' . $sources[$c->source] . ')';
        }
        return $text;
    }

    /** 'Y-m-d H:i:s' (site time) as "21 Oct 2026 14:05". */
    private static function format_datetime($value) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string) $value, new DateTimeZone('UTC'));
        return $dt ? $dt->format('j M Y H:i') : (string) $value;
    }

    /** The change history for the account and season, newest first: who, when, what, the fee before and after. */
    private static function render_history($user, $season) {
        global $wpdb;
        $changes = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_assignment_changes
            WHERE (user_id = %d OR email = %s) AND season = %s
            ORDER BY recorded_at DESC, id DESC
            LIMIT 200
        ", intval($user->ID), (string) $user->user_email, (string) $season));
        if (empty($changes)) {
            echo '<p class="murvc-muted">No changes recorded for ' . esc_html($season) . ' yet.</p>';
            return;
        }
        $names = array();
        echo '<div class="murvc-tl-scroll"><table class="widefat striped murvc-tl-history"><thead><tr>'
            . '<th>When</th><th>By</th><th>Place</th><th>Change</th><th>Fee</th>'
            . '</tr></thead><tbody>';
        foreach ($changes as $c) {
            $by_id = intval($c->recorded_by);
            if ($by_id > 0) {
                if (!isset($names[$by_id])) {
                    $by_user = get_userdata($by_id);
                    $names[$by_id] = $by_user ? (string) $by_user->display_name : 'user #' . $by_id;
                }
                $by = $names[$by_id];
            } else {
                $by = $c->source === 'cron' ? 'daily job' : 'the system';
            }
            $place = ((string) $c->team !== '' || (string) $c->role !== '') ? self::place_label((string) $c->team, (string) $c->role) : 'the person';
            if (intval($c->assignment_id) > 0) {
                $place .= ' (#' . intval($c->assignment_id) . ')';
            }
            $fee = ($c->fee_before !== null || $c->fee_after !== null)
                ? ($c->fee_before !== null ? self::money($c->fee_before) : '—') . ' → ' . ($c->fee_after !== null ? self::money($c->fee_after) : '—')
                : '';
            echo '<tr>';
            echo '<td>' . esc_html(self::format_datetime($c->recorded_at)) . '</td>';
            echo '<td>' . esc_html($by) . '</td>';
            echo '<td>' . esc_html($place) . '</td>';
            echo '<td>' . esc_html(self::describe_change($c)) . ((string) $c->note !== '' ? '<br><span class="murvc-muted">' . esc_html((string) $c->note) . '</span>' : '') . '</td>';
            echo '<td>' . ($fee !== '' ? esc_html($fee) : '<span class="murvc-muted">&mdash;</span>') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if (count($changes) === 200) {
            echo '<p class="description">Showing the latest 200 changes.</p>';
        }
    }

    /**
     * The Preview buttons: each posts its own form to the Preview endpoint
     * and writes the answer into the place's preview line with
     * textContent (never markup). Without JavaScript the buttons stay
     * hidden and the forms still save.
     */
    private static function render_card_script() {
        ?>
        <script>
        (function () {
            var cards = document.querySelectorAll('.murvc-tl');
            if (!cards.length || !window.fetch || !window.FormData) {
                return;
            }
            Array.prototype.forEach.call(cards, function (card) {
                var url = card.getAttribute('data-ajax-url');
                Array.prototype.forEach.call(card.querySelectorAll('form.murvc-tl-form'), function (form) {
                    var button = form.querySelector('.murvc-tl-preview');
                    var place = form.closest ? form.closest('.murvc-tl-place') : null;
                    var out = place ? place.querySelector('.murvc-tl-preview-out') : null;
                    if (!button || !out || !url) {
                        return;
                    }
                    button.hidden = false;
                    var show = function (text, failed) {
                        out.textContent = text;
                        out.classList.toggle('is-error', !!failed);
                    };
                    button.addEventListener('click', function () {
                        var data = new FormData(form);
                        data.set('action', '<?php echo esc_js(self::PREVIEW_ACTION); ?>');
                        show('Working it out…', false);
                        fetch(url, { method: 'POST', credentials: 'same-origin', body: data })
                            .then(function (response) { return response.text(); })
                            .then(function (body) {
                                var json = null;
                                try { json = JSON.parse(body); } catch (e) { json = null; }
                                if (!json || typeof json !== 'object') {
                                    show('Preview unavailable: your login may have expired. Reload the page.', true);
                                    return;
                                }
                                var data = json.data || {};
                                var text = data.text || data.message || 'Preview unavailable.';
                                show(text, !json.success);
                            })
                            .catch(function () {
                                show('Preview unavailable: check your connection and try again.', true);
                            });
                    });
                });
            });
        })();
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    // The card's endpoints
    // ------------------------------------------------------------------

    /** The card's posted fields, unslashed and sanitised (dates are validated by the writers). */
    private static function read_request() {
        $text = function ($key) {
            return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim(sanitize_text_field(wp_unslash($_POST[$key]))) : '';
        };
        $op = $text('op');
        $season = $text('season');
        return array(
            'op' => in_array($op, self::CARD_OPS, true) ? $op : '',
            'id' => isset($_POST['assignment_id']) && is_scalar($_POST['assignment_id']) ? intval($_POST['assignment_id']) : 0,
            'start' => $text('start_date'),
            'end' => $text('end_date'),
            'stop' => $text('stop_date'),
            'team' => $text('new_team'),
            'role' => sanitize_key($text('new_role')),
            'from' => $text('change_from'),
            'confirm' => $text('confirm_remove') === '1',
            'season' => preg_match('/^\d{4}$/D', $season) === 1 ? $season : '',
            'profile_user' => isset($_POST['profile_user']) && is_scalar($_POST['profile_user']) ? max(0, intval($_POST['profile_user'])) : 0,
            'expect' => array('start' => $text('expect_start'), 'end' => $text('expect_end'), 'team' => $text('expect_team'), 'role' => $text('expect_role')),
        );
    }

    /** '' when the current user may change this place (manage_options plus its nonce), else why not. */
    private static function request_refused($id) {
        if (!current_user_can('manage_options')) {
            return 'Insufficient permissions';
        }
        $nonce = (isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (intval($id) <= 0 || !wp_verify_nonce($nonce, self::nonce_action($id))) {
            return 'Security check failed: reload the page and try again.';
        }
        return '';
    }

    /** A writer op on one place, as the card saves it (source timeline, with everything the admin saw as expected). */
    private static function run($op, $args) {
        self::flush_cache();
        return self::execute(self::plan($op, $args), 'timeline');
    }

    /** "Nothing changed" as a writer would say it. */
    private static function unchanged_result($message) {
        return array('ok' => true, 'unchanged' => true, 'error' => '', 'reload' => false, 'message' => $message);
    }

    /**
     * The card's Save: a new start and/or stop (or no stop), checked
     * together first so a save is refused whole rather than half done,
     * then written as separate changes (set_start, stop / clear_stop),
     * each with its own change row, in the order that keeps the place
     * valid in between.
     */
    private static function save_dates($id, $start, $end, $expect) {
        if ($start === '' && $expect['start'] !== '') {
            return self::refusal('A team place needs a start date: pick one rather than blanking it. If they were never on the team, use Remove (added by mistake).');
        }
        $start_changes = $start !== '' && $start !== $expect['start'];
        $end_changes = $end !== $expect['end'];
        if (!$start_changes && !$end_changes) {
            return self::unchanged_result('Nothing changed: those are the dates already saved.');
        }
        self::flush_cache();
        $check = self::plan('set_dates', array('id' => $id, 'start' => $start_changes ? $start : '', 'end' => $end, 'expect' => $expect));
        if (empty($check['ok'])) {
            return $check;
        }
        if (!empty($check['unchanged'])) {
            return self::unchanged_result('Nothing changed: those are the dates already saved.');
        }
        $steps = array();
        if ($start_changes) {
            $steps[] = 'start';
        }
        if ($end_changes) {
            $steps[] = 'end';
        }
        // Stop first when it is being cleared, or when the start moves to
        // on or after the old stop (the place stays valid in between).
        if ($start_changes && $end_changes && ($end === '' || ($expect['end'] !== '' && $start >= $expect['end']))) {
            $steps = array('end', 'start');
        }
        $messages = array();
        $last = null;
        foreach ($steps as $step) {
            if ($step === 'start') {
                $result = self::run('set_start', array('id' => $id, 'start' => $start, 'expect' => $expect));
                if (!empty($result['ok'])) {
                    $expect['start'] = $start;
                }
            } else {
                $result = $end === ''
                    ? self::run('clear_stop', array('id' => $id, 'expect' => $expect))
                    : self::run('stop', array('id' => $id, 'date' => $end, 'expect' => $expect));
                if (!empty($result['ok'])) {
                    $expect['end'] = $end;
                }
            }
            if (empty($result['ok'])) {
                if ($messages) {
                    $result['error'] = implode(' ', $messages) . ' But the next change was not saved: ' . $result['error'];
                }
                return $result;
            }
            if (empty($result['unchanged'])) {
                $messages[] = $result['message'];
            }
            $last = $result;
        }
        $last['message'] = $messages ? implode(' ', $messages) : 'Nothing changed.';
        return $last;
    }

    /** Carry out one card form on its (loaded) place. */
    private static function save_request($request, $row) {
        $id = intval($row->id);
        $expect = $request['expect'];
        switch ($request['op']) {
            case 'save_dates':
                return self::save_dates($id, $request['start'], $request['end'], $expect);
            case 'stop':
                return self::run('stop', array('id' => $id, 'date' => $request['stop'], 'expect' => $expect));
            case 'clear_stop':
                return self::run('clear_stop', array('id' => $id, 'expect' => $expect));
            case 'remove':
                if (!$request['confirm']) {
                    return self::refusal('Nothing was removed: tick "Yes, they were never on this team — charge nothing" to confirm. If they played, use Stopped playing on instead.');
                }
                return self::run('remove', array('id' => $id, 'expect' => $expect));
            case 'change_role':
                return self::run('change_role', array('id' => $id, 'team' => $request['team'], 'role' => $request['role'], 'from' => $request['from'], 'expect' => $expect));
        }
        return self::refusal('Unknown change.');
    }

    /** The notice for the page the save redirects to, kept for this admin for five minutes. */
    private static function set_notice($result, $season) {
        if (!empty($result['ok'])) {
            $type = !empty($result['unchanged']) ? 'info' : 'success';
            $message = (isset($result['message']) && $result['message'] !== '') ? $result['message'] : 'Saved.';
            if (empty($result['unchanged'])) {
                $notes = array();
                if (!empty($result['synced'])) {
                    $notes[] = 'Their Selected / Training Only verdict for that team dropped to Tentative.';
                }
                if (isset($result['invoice']) && is_array($result['invoice'])) {
                    if (!empty($result['invoice']['removed'])) {
                        $notes[] = 'Their ' . $season . ' invoice had nothing paid and nothing to charge, so it was deleted.';
                    } elseif (!empty($result['invoice']['manual'])) {
                        $notes[] = 'Their fee was set with Edit Fee, so it stays as it is.';
                    } elseif (empty($result['invoice']['still_on_team'])) {
                        $notes[] = 'Their invoice stays (it has payments on it, or something was charged), with anything paid above the fee shown as credit.';
                    }
                }
                if ($season !== '' && !TeamOversight_Fees::fees_released($season)) {
                    $notes[] = 'Nothing is billed until ' . $season . ' fees are released.';
                }
                if ($notes) {
                    $message .= ' ' . implode(' ', $notes);
                }
            }
        } else {
            $type = 'error';
            $message = (isset($result['error']) && $result['error'] !== '') ? $result['error'] : 'Nothing was saved.';
        }
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), array('type' => $type, 'message' => $message), 5 * MINUTE_IN_SECONDS);
    }

    /**
     * admin-post.php?action=murvc_fee_timeline: one card form (op
     * save_dates, stop, clear_stop, remove or change_role, with the values
     * the admin saw in expect_*). manage_options and the place's nonce are
     * required; Remove also needs its tick box. Saves through the writers,
     * leaves a notice for this admin and redirects back to the person's
     * card (Post/Redirect/Get), so it works without JavaScript.
     */
    public static function handle_post() {
        $request = self::read_request();
        $refused = self::request_refused($request['id']);
        if ($refused !== '') {
            wp_die(esc_html($refused), 'Fee timeline', array('response' => 403, 'back_link' => true));
        }
        $row = self::load_row($request['id']);
        $season = $row ? (string) $row->season : $request['season'];
        $result = $row ? self::save_request($request, $row) : self::refusal('That team place no longer exists (it may have been removed): reload the page.', true);
        self::set_notice($result, $season);

        $user_id = $request['profile_user'];
        if ($user_id <= 0 && $row) {
            $account = self::account($row->user_id, $row->email);
            $user_id = $account['user_id'];
        }
        wp_safe_redirect($user_id > 0 ? TeamOversight_Member_Lookup::profile_url($user_id, '', $season) : admin_url('admin.php?page=club-membership-lookup'));
        exit;
    }

    /**
     * admin-ajax.php?action=murvc_fee_timeline_preview: what a card form
     * would do to the fee and to what is due by today, without saving
     * (preview()). manage_options and the place's nonce are required.
     * Replies { text, fee_before, fee_after, due_before, due_after } (text
     * is plain: the page inserts it with textContent) or { message } when
     * the change would be refused.
     */
    public static function ajax_preview() {
        $request = self::read_request();
        $refused = self::request_refused($request['id']);
        if ($refused !== '') {
            wp_send_json_error(array('message' => $refused), 403);
        }
        $id = $request['id'];
        $expect = $request['expect'];
        switch ($request['op']) {
            case 'save_dates':
                if ($request['start'] === '' && $expect['start'] !== '') {
                    $result = self::refusal('A team place needs a start date: pick one rather than blanking it.');
                } else {
                    $start = $request['start'] !== $expect['start'] ? $request['start'] : '';
                    $result = self::preview('set_dates', array('id' => $id, 'start' => $start, 'end' => $request['end'], 'expect' => $expect));
                }
                break;
            case 'stop':
                $result = self::preview('stop', array('id' => $id, 'date' => $request['stop'], 'expect' => $expect));
                break;
            case 'clear_stop':
                $result = self::preview('clear_stop', array('id' => $id, 'expect' => $expect));
                break;
            case 'remove':
                $result = self::preview('remove', array('id' => $id, 'expect' => $expect));
                break;
            case 'change_role':
                $result = self::preview('change_role', array('id' => $id, 'team' => $request['team'], 'role' => $request['role'], 'from' => $request['from'], 'expect' => $expect));
                break;
            default:
                $result = self::refusal('Unknown change.');
        }
        if (empty($result['ok'])) {
            wp_send_json_error(array('message' => (string) $result['error'], 'reload' => !empty($result['reload'])));
        }
        wp_send_json_success(array(
            'text' => !empty($result['unchanged']) ? 'Nothing would change.' : 'If saved: ' . $result['text'] . '.',
            'fee_before' => $result['fee_before'],
            'fee_after' => $result['fee_after'],
            'due_before' => $result['due_before'],
            'due_after' => $result['due_after'],
        ));
    }
}
