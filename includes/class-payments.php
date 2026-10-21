<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member fee payments.
 *
 * Fees are a balance against a season timeline, not an invoice event:
 *  - the season has manually-entered start/end dates (Configuration page)
 *  - 2026 and earlier: a player's fee accrues linearly across the season,
 *    so "overdue" = what the schedule says should be paid by today minus
 *    what has been paid
 *  - 2027 on (1.70.2): each player's fee falls due evenly across their own
 *    part of the season's payment window (TeamOversight_Fee_Timeline::
 *    schedule()); invoice_schedule() and its wrappers are the one door to
 *    either schedule
 *  - members pay any amount, any time: [member_fees] shows their balance
 *    and adds the configured payment product to the cart at the entered
 *    price; paid orders reduce outstanding and are recorded in the
 *    team_invoice_payments ledger.
 */
class TeamOversight_Payments {

    const PAYMENT_PRODUCT_OPTION = 'team_oversight_payment_product';

    // Overdue reminder emails: master switch (default off), days between
    // reminders per person, per-user last-sent meta, daily cron hook.
    const REMINDERS_ENABLED_OPTION = 'team_oversight_overdue_reminders_enabled';
    const REMINDER_DAYS_OPTION = 'team_oversight_overdue_reminder_days';
    const REMINDER_META = 'murvc_overdue_last_reminded';
    const REMINDER_CRON = 'team_oversight_overdue_reminders';
    const EMAIL_SUBJECT_OPTION = 'team_oversight_overdue_email_subject';
    const EMAIL_BODY_OPTION = 'team_oversight_overdue_email_body';
    const REMINDER_MIN_OPTION = 'team_oversight_reminder_minimum';
    const REMINDER_GRACE_OPTION = 'team_oversight_reminder_grace_days';
    const REMINDER_HOUR_OPTION = 'team_oversight_reminder_hour';
    const REMINDER_TZ_OPTION = 'team_oversight_reminder_timezone';
    const EMAIL_FROM_NAME_OPTION = 'team_oversight_email_from_name';
    const EMAIL_FROM_OPTION = 'team_oversight_email_from_address';
    const EMAIL_REPLYTO_OPTION = 'team_oversight_email_replyto';

    private static $hooks_registered = false;

    public function __construct() {
        // Other classes instantiate this for rendering (e.g. the readiness
        // fees fallback); only the first instance owns the hooks.
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        add_shortcode('member_fees', array($this, 'render_member_fees'));
        add_shortcode('player_fees', array($this, 'render_player_fees'));

        // Pay-any-amount flow: process the form before output, override the
        // cart line price, carry meta onto the order, apply on payment.
        add_action('template_redirect', array($this, 'maybe_start_payment'));
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_cart_payment_amount'), 20);
        add_filter('woocommerce_get_item_data', array($this, 'display_payment_in_cart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'attach_payment_to_order_item'), 10, 3);
        add_filter('woocommerce_is_sold_individually', array($this, 'payment_product_sold_individually'), 10, 2);
        add_action('woocommerce_order_status_processing', array($this, 'handle_payment_order'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_payment_order'));

        // A fee payment refunded or cancelled in WooCommerce never changes
        // the ledger by itself (a refund is a negative Record Payment): the
        // treasurer is alerted on Logs and Payment Management instead.
        add_action('woocommerce_order_refunded', array($this, 'handle_payment_refund'), 10, 2);
        add_action('woocommerce_order_status_refunded', array($this, 'handle_payment_refund'));
        add_action('woocommerce_order_status_cancelled', array($this, 'handle_payment_cancelled'));
        add_action('admin_notices', array($this, 'render_refund_notice'));

        // Overdue reminder emails (daily cron; only sends when enabled).
        add_action('init', array($this, 'maybe_schedule_reminder_cron'));
        add_action(self::REMINDER_CRON, array($this, 'run_reminder_cron'));
    }

    /** Club timezone reminders are scheduled against. */
    public static function get_reminder_timezone() {
        $tz = (string) get_option(self::REMINDER_TZ_OPTION, '');
        if ($tz === '') {
            $tz = 'Australia/Melbourne';
        }
        try {
            return new DateTimeZone($tz);
        } catch (Exception $e) {
            return new DateTimeZone('Australia/Melbourne');
        }
    }

    /** Hour of the day (0-23) reminders are sent. Default 6pm. */
    public static function get_reminder_hour() {
        $hour = get_option(self::REMINDER_HOUR_OPTION, null);
        return ($hour === null || $hour === '') ? 18 : max(0, min(23, intval($hour)));
    }

    /** UTC timestamp of the next send at the configured local time. */
    public static function next_reminder_timestamp() {
        $tz = self::get_reminder_timezone();
        $now = new DateTime('now', $tz);
        $next = clone $now;
        $next->setTime(self::get_reminder_hour(), 0, 0);
        if ($next <= $now) {
            $next->modify('+1 day');
        }
        return $next->getTimestamp();
    }

    /**
     * Keep the daily cron anchored to the configured local time,
     * rescheduling whenever the setting (or a DST shift) moves it.
     */
    public function maybe_schedule_reminder_cron() {
        $scheduled = wp_next_scheduled(self::REMINDER_CRON);

        if ($scheduled) {
            $when = new DateTime('@' . $scheduled);
            $when->setTimezone(self::get_reminder_timezone());
            if (intval($when->format('G')) === self::get_reminder_hour()) {
                return; // already lands on the right hour locally
            }
            wp_unschedule_event($scheduled, self::REMINDER_CRON);
        }

        wp_schedule_event(self::next_reminder_timestamp(), 'daily', self::REMINDER_CRON);
    }

    public function run_reminder_cron() {
        $this->send_overdue_reminders(false, false);
    }

    // ------------------------------------------------------------------
    // Payments ledger — the single source of truth for money received.
    // "Paid" is ALWAYS the sum of ledger rows; outstanding is ALWAYS
    // fee − paid. Nothing else may write outstanding_amount directly.
    // ------------------------------------------------------------------

    /** Total recorded payments for an invoice. */
    public static function get_ledger_paid($invoice_id) {
        global $wpdb;
        return round(floatval($wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}team_invoice_payments WHERE invoice_id = %d
        ", $invoice_id))), 2);
    }

    /** Resync outstanding = fee − ledger paid (never negative). */
    public static function recompute_invoice($invoice_id) {
        global $wpdb;
        $fee = $wpdb->get_var($wpdb->prepare("
            SELECT invoice_amount FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id));
        if ($fee === null) {
            return null;
        }
        $outstanding = max(0, round(floatval($fee) - self::get_ledger_paid($invoice_id), 2));
        $wpdb->update(
            $wpdb->prefix . 'team_invoices',
            array('outstanding_amount' => $outstanding),
            array('id' => $invoice_id),
            array('%f'),
            array('%d')
        );
        return $outstanding;
    }

    /**
     * Record a payment (or negative correction) against an invoice and
     * resync its outstanding. The only way money enters the books.
     * Returns false when nothing was recorded — including a duplicate
     * order_item_id, which means another order hook already applied it.
     */
    public static function record_payment($invoice_id, $amount, $source = 'manual', $note = '', $order_id = null, $order_item_id = null) {
        global $wpdb;

        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, user_id FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id));
        if (!$invoice || abs(floatval($amount)) < 0.005) {
            return false;
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'team_invoice_payments',
            array(
                'invoice_id' => intval($invoice_id),
                'user_id' => intval($invoice->user_id),
                'order_id' => $order_id ? intval($order_id) : null,
                'order_item_id' => $order_item_id ? intval($order_item_id) : null,
                'amount' => round(floatval($amount), 2),
                'source' => $source,
                'note' => $note,
            ),
            array('%d', '%d', '%d', '%d', '%f', '%s', '%s')
        );
        if ($inserted === false) {
            error_log('TeamOversight_Payments::record_payment: nothing recorded for invoice ' . intval($invoice_id)
                . ($order_item_id ? ' (order item ' . intval($order_item_id) . ')' : '') . ': ' . $wpdb->last_error);
            return false;
        }

        self::recompute_invoice($invoice_id);

        if (class_exists('TeamOversight_Log') && in_array($source, array('online', 'manual'), true)) {
            $season = $wpdb->get_var($wpdb->prepare("SELECT season FROM {$wpdb->prefix}team_invoices WHERE id = %d", $invoice_id));
            TeamOversight_Log::add(
                'payment_' . $source,
                'Payment applied to ' . $season . ' fees'
                    . ($order_id ? ' (order #' . intval($order_id) . ')' : '')
                    . ($note !== '' ? ' — ' . $note : ''),
                array('user_id' => intval($invoice->user_id), 'amount' => $amount)
            );
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Overdue reminder emails
    // ------------------------------------------------------------------

    /**
     * Everyone with money overdue (all seasons; past-season debt counts in
     * full), one entry per person, resolved to their WP account. Rows that
     * can't be matched to an account are returned with user_id 0.
     */
    /**
     * A token floor so cent-level rounding never triggers an email. The
     * real gate is time (see the grace period) rather than an arbitrary
     * dollar line, which would leave real amounts uncollected.
     */
    public static function get_reminder_minimum() {
        $minimum = get_option(self::REMINDER_MIN_OPTION, null);
        return ($minimum === null || $minimum === '') ? 1.0 : max(0.01, floatval($minimum));
    }

    /** Days someone must have been behind before they're emailed. */
    public static function get_reminder_grace_days() {
        $days = get_option(self::REMINDER_GRACE_OPTION, null);
        return ($days === null || $days === '') ? 7 : max(0, intval($days));
    }

    /**
     * The date an invoice fell behind: the point where the linear schedule
     * overtook what had been paid. Past seasons are behind from the day
     * the season ended (or that year's end when no dates were set).
     * Null when the invoice isn't behind at all.
     */
    public static function get_overdue_since($invoice) {
        if (self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season) <= 0) {
            return null;
        }

        if (intval($invoice->season) > 0 && intval($invoice->season) < intval(wp_date('Y'))) {
            $dates = TeamOversight_Fees::get_season_dates($invoice->season);
            return $dates ? $dates['end'] : intval($invoice->season) . '-12-31';
        }

        return self::get_paid_through_date($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
    }

    public static function get_overdue_people($minimum = null) {
        global $wpdb;

        $minimum = ($minimum === null) ? self::get_reminder_minimum() : floatval($minimum);

        $invoices = $wpdb->get_results("
            SELECT *, UNIX_TIMESTAMP(created_date) AS created_ts FROM {$wpdb->prefix}team_invoices WHERE outstanding_amount > 0
        ");
        // 2027+ invoices are scheduled from their places and floors: load
        // them all at once (a no-op when this request already has them,
        // as send_overdue_reminders() calls this three times).
        TeamOversight_Fee_Timeline::preload_for_invoices($invoices);

        $people = array();
        foreach ($invoices as $invoice) {
            $user_id = intval($invoice->user_id);
            if (!$user_id && $invoice->email) {
                $user = get_user_by('email', $invoice->email);
                $user_id = $user ? $user->ID : 0;
            }
            $key = $user_id ? 'u' . $user_id : 'e' . strtolower((string) $invoice->email);

            if (!isset($people[$key])) {
                $account = $user_id ? get_userdata($user_id) : null;
                $people[$key] = array(
                    'user_id' => $user_id,
                    'email' => $account ? $account->user_email : $invoice->email,
                    'name' => $account ? $account->display_name : $invoice->name,
                    'overdue' => 0.0,
                    'outstanding' => 0.0,
                    'overdue_since' => null,
                    'days_overdue' => 0,
                );
            }
            $people[$key]['outstanding'] += floatval($invoice->outstanding_amount);
            if (TeamOversight_Fees::timeline_applies($invoice->season)) {
                // 2027+: their own schedule, and a behind date that never
                // starts before the fee, or the change that put it due,
                // was recorded (behind_floor()), so reminder grace counts
                // from then.
                $schedule = TeamOversight_Fee_Timeline::schedule($invoice);
                $people[$key]['overdue'] += $schedule['overdue'];
                $since = $schedule['behind_since'];
            } else {
                $people[$key]['overdue'] += self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
                $since = self::get_overdue_since($invoice);
            }

            // Track the earliest date anything of theirs fell behind.
            if ($since && ($people[$key]['overdue_since'] === null || $since < $people[$key]['overdue_since'])) {
                $people[$key]['overdue_since'] = $since;
            }
        }

        $today = strtotime(current_time('Y-m-d'));
        foreach ($people as $key => $person) {
            $people[$key]['days_overdue'] = $person['overdue_since']
                ? max(0, (int) floor(($today - strtotime($person['overdue_since'])) / DAY_IN_SECONDS))
                : 0;
        }

        return array_values(array_filter($people, function ($p) use ($minimum) {
            return $p['overdue'] >= $minimum;
        }));
    }

    public static function get_default_reminder_subject() {
        return 'MURVC club fees overdue — ${overdue}';
    }

    public static function get_default_reminder_body() {
        return "Hi {first_name},\n\n"
            . "Your MURVC club fees have been behind the payment schedule for {time_overdue}.\n\n"
            . "  Overdue now:     \${overdue}\n"
            . "  Total remaining: \${outstanding}\n\n"
            . "You can pay any amount online — part of it or all of it, whatever suits — and it comes straight off your balance:\n"
            . "{link}\n\n"
            . "If you think this is a mistake, or you'd like to arrange a payment plan, just reply to this email.\n\n"
            . "Melbourne University Renegades Volleyball Club";
    }

    /** "a week" / "3 weeks" / "2 months" — readable, not "37 days". */
    public static function describe_duration($days) {
        $days = max(0, intval($days));
        if ($days < 7) {
            return $days === 1 ? 'a day' : $days . ' days';
        }
        if ($days < 14) {
            return 'a week';
        }
        if ($days < 60) {
            return floor($days / 7) . ' weeks';
        }
        return floor($days / 30) . ' months';
    }

    /**
     * Time behind on fees: months from STANDING_FLAG_DAYS, so the flag reads
     * "a month behind", not "4 weeks"; days and weeks below that.
     */
    public static function describe_behind($days) {
        $days = max(1, intval($days));
        if ($days < self::STANDING_FLAG_DAYS) {
            return self::describe_duration($days) . ' behind';
        }
        $months = (int) floor($days / 30);
        return ($months <= 1 ? 'a month' : $months . ' months') . ' behind';
    }

    /**
     * Mail headers for plugin emails: configured From (name + address on a
     * domain the server may send as) and Reply-To. Empty From = WordPress
     * default sender.
     */
    public static function get_email_headers() {
        $headers = array();
        $from = sanitize_email(get_option(self::EMAIL_FROM_OPTION));
        if ($from) {
            $name = trim((string) get_option(self::EMAIL_FROM_NAME_OPTION));
            $headers[] = 'From: ' . ($name !== '' ? $name . ' <' . $from . '>' : $from);
        }
        $replyto = sanitize_email(get_option(self::EMAIL_REPLYTO_OPTION));
        if ($replyto) {
            $headers[] = 'Reply-To: ' . $replyto;
        }
        return $headers;
    }

    /** The link reminders point at: configured fees page or the checklist. */
    public static function get_reminder_link() {
        $url = get_option('team_oversight_fees_page_url');
        return $url ? $url : home_url('/player-checklist/');
    }

    /**
     * Render the reminder subject+body from the saved templates (or the
     * defaults). Placeholders: {name} {first_name} {overdue} {outstanding}
     * {link}. Returns array(subject, body).
     */
    public static function render_reminder_email($name, $overdue, $outstanding, $link = null, $days_overdue = 0) {
        $subject_tpl = get_option(self::EMAIL_SUBJECT_OPTION);
        if (!is_string($subject_tpl) || trim($subject_tpl) === '') {
            $subject_tpl = self::get_default_reminder_subject();
        }
        $body_tpl = get_option(self::EMAIL_BODY_OPTION);
        if (!is_string($body_tpl) || trim($body_tpl) === '') {
            $body_tpl = self::get_default_reminder_body();
        }

        $first = trim((string) strtok((string) $name, ' '));
        $replacements = array(
            '{name}' => $name,
            '{first_name}' => $first !== '' ? $first : $name,
            '{overdue}' => number_format(floatval($overdue), 2),
            '{outstanding}' => number_format(floatval($outstanding), 2),
            '{link}' => $link !== null ? $link : self::get_reminder_link(),
            '{days_overdue}' => intval($days_overdue),
            '{time_overdue}' => self::describe_duration(intval($days_overdue)),
        );

        return array(strtr($subject_tpl, $replacements), strtr($body_tpl, $replacements));
    }

    /**
     * Email everyone whose fees are overdue, at most once per configured
     * interval per person. $force ignores the master switch (admin "send
     * now"); $dry_run reports who WOULD be emailed without sending.
     * Returns a report array.
     */
    public function send_overdue_reminders($force = false, $dry_run = false) {
        $report = array('enabled' => (bool) get_option(self::REMINDERS_ENABLED_OPTION), 'sent' => 0, 'skipped_recent' => 0, 'skipped_no_account' => 0, 'skipped_small' => 0, 'skipped_grace' => 0, 'minimum' => self::get_reminder_minimum(), 'grace_days' => self::get_reminder_grace_days(), 'recipients' => array());

        if (!$force && !$report['enabled']) {
            return $report;
        }

        $days = max(1, intval(get_option(self::REMINDER_DAYS_OPTION, 7)));
        $grace = self::get_reminder_grace_days();
        $checklist_url = self::get_reminder_link();

        // Sub-cent rounding is filtered by the token floor; the real gate
        // is the grace period, both counted so the policy is visible.
        $report['skipped_small'] = count(self::get_overdue_people(0.01)) - count(self::get_overdue_people());

        foreach (self::get_overdue_people() as $person) {
            if (!$person['user_id']) {
                $report['skipped_no_account']++;
                continue;
            }

            // Just tipped over? Leave them be until they've actually been
            // behind for a while — that's what makes the email meaningful.
            if ($person['days_overdue'] < $grace) {
                $report['skipped_grace']++;
                continue;
            }

            $last = intval(get_user_meta($person['user_id'], self::REMINDER_META, true));
            if ($last && (time() - $last) < $days * DAY_IN_SECONDS) {
                $report['skipped_recent']++;
                continue;
            }

            $report['recipients'][] = $person['name'] . ' <' . $person['email'] . '> $' . number_format($person['overdue'], 2)
                . ' (' . intval($person['days_overdue']) . ' days behind)';

            if ($dry_run) {
                $report['sent']++;
                continue;
            }

            list($subject, $message) = self::render_reminder_email($person['name'], $person['overdue'], $person['outstanding'], $checklist_url, $person['days_overdue']);

            if (wp_mail($person['email'], $subject, $message, self::get_email_headers())) {
                update_user_meta($person['user_id'], self::REMINDER_META, time());
                $report['sent']++;
                if (class_exists('TeamOversight_Log')) {
                    TeamOversight_Log::add(
                        'email_reminder',
                        'Overdue reminder sent to ' . $person['email'],
                        array('user_id' => $person['user_id'], 'amount' => $person['overdue'])
                    );
                }
            }
        }

        return $report;
    }

    // ------------------------------------------------------------------
    // Schedule maths
    // ------------------------------------------------------------------

    /**
     * Fraction of the season fee expected to be paid by today: 0 before the
     * start, 1 after the end, linear in between. Null when no season dates
     * are configured (nothing is ever "overdue" without a schedule).
     */
    public static function get_expected_factor($season) {
        $dates = TeamOversight_Fees::get_season_dates($season);
        if (!$dates) {
            return null;
        }

        $start = strtotime($dates['start']);
        $end = strtotime($dates['end']);
        $now = current_time('timestamp');

        if ($end <= $start) {
            return null;
        }
        if ($now <= $start) {
            return 0.0;
        }
        if ($now >= $end) {
            return 1.0;
        }
        return ($now - $start) / ($end - $start);
    }

    /**
     * Overdue amount for an invoice under the linear schedule. Debts left
     * over from past seasons roll forward: once the season year is over,
     * whatever is still outstanding is fully overdue, season dates or not.
     */
    public static function get_overdue($invoice_amount, $outstanding_amount, $season) {
        if (intval($season) > 0 && intval($season) < intval(wp_date('Y'))) {
            return round(max(0, floatval($outstanding_amount)), 2);
        }

        $factor = self::get_expected_factor($season);
        if ($factor === null) {
            return 0.0;
        }
        $paid = floatval($invoice_amount) - floatval($outstanding_amount);
        $expected = floatval($invoice_amount) * $factor;
        return round(max(0, min($expected - $paid, floatval($outstanding_amount))), 2);
    }

    /**
     * The date up to which current payments keep the member on schedule:
     * the point where the linear accrual catches up with what they've paid.
     * Null without season dates or a fee; capped to the season window.
     */
    public static function get_paid_through_date($invoice_amount, $outstanding_amount, $season) {
        $dates = TeamOversight_Fees::get_season_dates($season);
        $invoice_amount = floatval($invoice_amount);
        if (!$dates || $invoice_amount <= 0) {
            return null;
        }

        $start = strtotime($dates['start']);
        $end = strtotime($dates['end']);
        if ($end <= $start) {
            return null;
        }

        $paid_fraction = max(0, min(1, ($invoice_amount - floatval($outstanding_amount)) / $invoice_amount));
        return date('Y-m-d', $start + (int) round($paid_fraction * ($end - $start)));
    }

    // ------------------------------------------------------------------
    // An invoice's due schedule (1.70.2): the one door every screen that
    // shows or charges an amount due goes through, so the amount shown and
    // the amount charged always agree. A season before TeamOversight_Fees::
    // FEE_TIMELINE_FROM keeps the 1.69 schedule above, returned verbatim
    // (get_overdue(), get_paid_through_date(), get_overdue_since(),
    // render_fee_progress()); a 2027+ season uses the player's own
    // schedule, TeamOversight_Fee_Timeline::schedule(): nothing until the
    // payment window opens (or their start, if later), then evenly up to
    // "Paid in full by", and everything at once once every place has
    // stopped.
    //
    // Invoice rows passed in should carry id, user_id, email, season,
    // invoice_amount, outstanding_amount, fee_raised_at and
    // UNIX_TIMESTAMP(created_date) AS created_ts (behind_floor() looks the
    // last two up, one query, when they're missing). For a list, call
    // TeamOversight_Fee_Timeline::preload_for_invoices() first, so each
    // 2027+ schedule costs no query.
    // ------------------------------------------------------------------

    /**
     * Everything about an invoice's schedule in one array:
     *   engine        'season' (the 1.69 schedule) or 'timeline' (2027+)
     *   scheduled     it has a schedule (season dates, or an earlier
     *                 calendar year's season, which is all due)
     *   fee, paid, outstanding
     *   due_today     what the schedule has due by today (null unscheduled)
     *   due_pct       the same as a percentage of the fee (null unscheduled)
     *   overdue       due by today - paid, never more than outstanding
     *   paid_through  the day the schedule catches up with what's paid
     *   next_due      'season': paid_through (shown only when nothing is
     *                 overdue, as before); 'timeline': set only then
     *   behind_since  when it fell behind (null when not overdue); for
     *                 2027+ never before behind_floor()
     *   fell_behind   when the schedule overtook what was paid (2027+:
     *                 before the floor is applied)
     *   window_from, window_to, stopped_on   2027+ only: the player's own
     *                 window (W0, W1) and the stop everything falls due on
     *   progress_html the thin progress bar members see
     */
    public static function invoice_schedule($invoice) {
        $invoice = (object) $invoice;
        if (TeamOversight_Fees::timeline_applies($invoice->season)) {
            $schedule = TeamOversight_Fee_Timeline::schedule($invoice);
            $schedule['engine'] = 'timeline';
            $schedule['progress_html'] = self::timeline_progress_html($schedule);
            return $schedule;
        }
        $amount = $invoice->invoice_amount;
        $outstanding = $invoice->outstanding_amount;
        $season = $invoice->season;
        $past = intval($season) > 0 && intval($season) < intval(wp_date('Y'));
        $factor = $past ? 1.0 : self::get_expected_factor($season);
        $paid_through = self::get_paid_through_date($amount, $outstanding, $season);
        $behind_since = self::get_overdue_since($invoice);
        return array(
            'engine' => 'season',
            'scheduled' => $factor !== null,
            'fee' => round(floatval($amount), 2),
            'paid' => round(floatval($amount) - floatval($outstanding), 2),
            'outstanding' => round(max(0, floatval($outstanding)), 2),
            'fraction' => $factor,
            'due_today' => $factor === null ? null : round(floatval($amount) * $factor, 2),
            'due_pct' => $factor === null ? null : (int) round($factor * 100),
            'overdue' => self::get_overdue($amount, $outstanding, $season),
            'paid_through' => $paid_through,
            'next_due' => $paid_through,
            'behind_since' => $behind_since,
            'fell_behind' => $behind_since,
            'window_from' => null,
            'window_to' => null,
            'stopped_on' => null,
            'progress_html' => self::render_fee_progress($amount, $outstanding, $season),
        );
    }

    /** Overdue now on the invoice's schedule (get_overdue() verbatim before 2027). */
    public static function invoice_overdue($invoice) {
        $invoice = (object) $invoice;
        if (!TeamOversight_Fees::timeline_applies($invoice->season)) {
            return self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
        }
        $schedule = TeamOversight_Fee_Timeline::schedule($invoice);
        return $schedule['overdue'];
    }

    /**
     * The next payment date ('YYYY-MM-DD') or null. Before 2027 this is
     * get_paid_through_date() verbatim, which callers show only when
     * nothing is overdue and something is outstanding (as they always
     * have); for 2027+ it is null unless that holds.
     */
    public static function invoice_next_due($invoice) {
        $invoice = (object) $invoice;
        if (!TeamOversight_Fees::timeline_applies($invoice->season)) {
            return self::get_paid_through_date($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
        }
        $schedule = TeamOversight_Fee_Timeline::schedule($invoice);
        return $schedule['next_due'];
    }

    /** When the invoice fell behind, or null (get_overdue_since() verbatim before 2027; floored for 2027+). */
    public static function invoice_behind_since($invoice) {
        $invoice = (object) $invoice;
        if (!TeamOversight_Fees::timeline_applies($invoice->season)) {
            return self::get_overdue_since($invoice);
        }
        $schedule = TeamOversight_Fee_Timeline::schedule($invoice);
        return $schedule['behind_since'];
    }

    /** The members' progress bar for the invoice (render_fee_progress() verbatim before 2027). */
    public static function invoice_progress_html($invoice) {
        $invoice = (object) $invoice;
        if (!TeamOversight_Fees::timeline_applies($invoice->season)) {
            return self::render_fee_progress($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
        }
        return self::timeline_progress_html(TeamOversight_Fee_Timeline::schedule($invoice));
    }

    /**
     * The progress bar for a 2027+ schedule: fill = share of the fee paid
     * (red while anything is overdue), marker = the share due by today.
     * Empty without a schedule or a fee.
     */
    private static function timeline_progress_html($schedule) {
        $fee = floatval($schedule['fee']);
        if (empty($schedule['scheduled']) || $fee <= 0) {
            return '';
        }
        $paid_pct = (int) round(max(0, min(1, floatval($schedule['paid']) / $fee)) * 100);
        $due_pct = max(0, min(100, intval($schedule['due_pct'])));
        $state = floatval($schedule['overdue']) > 0 ? 'behind' : 'ontrack';
        return '<div class="fee-progress" title="' . esc_attr('Paid ' . $paid_pct . '% — ' . $due_pct . '% is due by today on your schedule') . '">'
            . '<div class="fee-progress-fill fee-progress-' . $state . '" style="width: ' . min(100, $paid_pct) . '%;"></div>'
            . '<div class="fee-progress-marker" style="left: ' . $due_pct . '%;"></div>'
            . '</div>'
            . '<p class="fee-progress-caption">' . esc_html('Paid ' . $paid_pct . '% · ' . $due_pct . '% due by today') . '</p>';
    }

    /**
     * A 2027+ schedule in a member's words, with no amounts: when their
     * fee falls due. '' when it isn't on a schedule.
     */
    public static function describe_member_schedule($schedule) {
        if (empty($schedule['scheduled'])) {
            return '';
        }
        $today = current_time('Y-m-d');
        $fmt = array('TeamOversight_Fee_Timeline', 'format_date');
        if (empty($schedule['window_from'])) {
            return 'That season is over, so anything still owing is due now.';
        }
        if (!empty($schedule['stopped_on']) && $today >= $schedule['stopped_on']) {
            return 'You stopped on ' . call_user_func($fmt, $schedule['stopped_on']) . ', so everything for the time you played is due now.';
        }
        if ($schedule['window_from'] >= $schedule['window_to']) {
            $started = $today >= $schedule['window_from'];
            return 'Your fee ' . ($started ? 'fell' : 'falls') . ' due in full on ' . call_user_func($fmt, $schedule['window_from']) . ', the day you ' . ($started ? 'started' : 'start') . '.';
        }
        $text = 'Your fee falls due evenly from ' . call_user_func($fmt, $schedule['window_from']) . ' to ' . call_user_func($fmt, $schedule['window_to'])
            . ($today < $schedule['window_from'] ? ', so nothing is due before ' . call_user_func($fmt, $schedule['window_from']) : '') . '.';
        if (!empty($schedule['stopped_on'])) {
            $text .= ' You stop on ' . call_user_func($fmt, $schedule['stopped_on']) . ': anything still owing falls due that day.';
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Fee standing (coach portal confirmed players, Payment Management)
    // ------------------------------------------------------------------

    /**
     * Days behind the schedule at which a player is flagged: they shouldn't
     * train until it's sorted with the club (club decision).
     */
    const STANDING_FLAG_DAYS = 30;

    /**
     * Where each person stands on fees: how much of $season's fee is paid,
     * and how long they've been behind the linear schedule across ALL their
     * invoices, so a debt left from an earlier season (fully overdue, as for
     * the reminder emails) counts too. Behind means at least $1 overdue.
     * Invoices marked with a payment arrangement still count as behind but
     * never towards the flag. The one place this is worked out: the coach
     * portal and Payment Management both use it.
     *
     * Two rules keep the clock honest. An invoice is never behind from
     * before its fee was billed: the later of its creation (created_date,
     * on the database clock, so converted to the site's date with that
     * day's offset) and its last rise (fee_raised_at, by a re-price or Edit
     * Fee; see TeamOversight_Fees::fee_rise_fields()). So a player joining
     * mid-season on a pro-rated fee, a late fee release or a fee raised
     * mid-season starts at that day, not the season start; a lower fee or a
     * re-save never restarts it. For seasons before 2027 the schedule
     * itself still runs from the season start, so from that day they must
     * catch up with it (the 1.69 stopgap, kept exactly). A 2027+ invoice
     * (1.70.2) uses the player's own schedule (TeamOversight_Fee_Timeline::
     * schedule()), whose behind date is already floored by behind_floor():
     * billed, raised, the latest recorded change that put more money due,
     * and the payment window moved earlier. Where it can't be exact it
     * errs towards not flagging (every rise restarts the clock). And only
     * an invoice at least $1 overdue sets the date (or names its season as
     * owing), so cents left on an old invoice can't age a fresh debt; when
     * only small amounts add up to $1, the latest of their dates is used
     * and their seasons are named.
     *
     * An arrangement covers an invoice only up to its fee when the
     * arrangement was recorded: a rise after it counts again (from the
     * rise) until the arrangement is recorded again.
     *
     * Invoices belong to their account: one with an account counts only for
     * that account, so nobody gets another account's invoices through an
     * email they now use. One with no account (an old row) still goes by its
     * email, and so does a person with no account.
     *
     * $people: array of array('user_id' => int, 'email' => string), keyed as
     * the caller likes; returns the same keys, each an array:
     *   has_invoice     there's a $season invoice (perhaps at $0)
     *   fee, paid       $season's fee and what's paid of it (0 with no invoice)
     *   paid_pct        0-100 of $season's fee, null with no fee
     *   scheduled       $season has season dates (without them it is never behind)
     *   overdue         $ overdue across all invoices
     *   days_behind     days since their debt fell behind
     *   flag_days       the same, leaving out invoices with an arrangement
     *   flagged         flag_days >= STANDING_FLAG_DAYS
     *   arrangement     an overdue invoice has a payment arrangement covering it
     *   arrangement_held  the person's arrangement, overdue or not: array(note,
     *                   at, by) from the most recently recorded, null if none
     *                   (admins only)
     *   other_seasons   other seasons at least $1 overdue (or, when only small
     *                   amounts add up to $1, every other season overdue), oldest first
     *   state           none | paid | ontrack | unscheduled | behind | arrangement | flagged
     *   due_pct, window_from, window_to, stopped_on   (1.70.2) a 2027+
     *                   $season invoice's own schedule: the share of the fee
     *                   due by today, their window (W0, W1) and the stop
     *                   everything falls due on; null otherwise.
     *                   Percentages and dates only (the coach portal shows them)
     * One invoice query for everyone, plus one for the 2027+ places and one
     * for their floors (preloaded), however many people.
     */
    public static function get_fee_standings($people, $season) {
        global $wpdb;

        $season = (string) $season;
        $result = array();
        if (empty($people)) {
            return $result;
        }

        $who = array();
        $user_ids = array();
        $emails = array();
        foreach ($people as $key => $person) {
            $email = strtolower(trim(isset($person['email']) ? (string) $person['email'] : ''));
            $user_id = isset($person['user_id']) ? intval($person['user_id']) : 0;
            if ($user_id <= 0 && $email !== '') {
                $user_id = max(0, intval(TeamOversight_Fees::resolve_user_id($email)));
            }
            $mine = $email !== '' ? array($email) : array();
            if ($user_id > 0) {
                $account = get_userdata($user_id);
                if ($account && $account->user_email) {
                    $mine[] = strtolower($account->user_email);
                }
                $user_ids[] = $user_id;
            }
            $who[$key] = array('user_id' => $user_id, 'emails' => array_values(array_unique($mine)));
            $emails = array_merge($emails, $mine);
        }
        $user_ids = array_values(array_unique($user_ids));
        $emails = array_values(array_unique($emails));

        $where = array();
        $params = array();
        if ($user_ids) {
            $where[] = 'user_id IN (' . implode(',', array_fill(0, count($user_ids), '%d')) . ')';
            $params = array_merge($params, $user_ids);
        }
        if ($emails) {
            $where[] = 'email IN (' . implode(',', array_fill(0, count($emails), '%s')) . ')';
            $params = array_merge($params, $emails);
        }
        $params[] = $season;
        $invoices = $where ? $wpdb->get_results($wpdb->prepare("
            SELECT id, user_id, email, season, invoice_amount, outstanding_amount, fee_raised_at,
                UNIX_TIMESTAMP(created_date) AS created_ts,
                payment_arrangement, payment_arrangement_at, payment_arrangement_note, payment_arrangement_by
            FROM {$wpdb->prefix}team_invoices
            WHERE (" . implode(' OR ', $where) . ") AND (season = %s OR outstanding_amount > 0 OR payment_arrangement = 1)
        ", $params)) : array();
        // created_date is the database's clock (CURRENT_TIMESTAMP, usually
        // UTC); UNIX_TIMESTAMP reads it in the database's zone and wp_date
        // gives the site's date for that moment, daylight saving included.
        TeamOversight_Fee_Timeline::preload_for_invoices($invoices);

        $scheduled = self::get_expected_factor($season) !== null;
        $today = strtotime(current_time('Y-m-d'));
        foreach ($who as $key => $person) {
            $result[$key] = self::standing_from_invoices($person, $invoices, $season, $scheduled, $today);
        }
        return $result;
    }

    /**
     * One person's fee standing (one get_fee_standings() entry, same keys)
     * from invoice rows already in hand: the second half of
     * get_fee_standings(), which runs it for each person over its one
     * invoice query. $person: array(user_id, emails (lower case)), the
     * identity get_fee_standings() works out; only the invoices that belong
     * to it count (the same identity rule). $invoices: invoice rows with the
     * columns get_fee_standings() selects. A 2027+ invoice may carry its
     * schedule already worked out in ->schedule (a
     * TeamOversight_Fee_Timeline::schedule() array): the How it works page
     * prices a hypothetical player in memory that way, with no invoice or
     * places in the database. $scheduled / $today_ts: the season has dates,
     * and the site's today as a timestamp (worked out here when null).
     * Reads only.
     */
    public static function standing_from_invoices($person, $invoices, $season, $scheduled = null, $today_ts = null) {
        $season = (string) $season;
        $person = array(
            'user_id' => isset($person['user_id']) ? intval($person['user_id']) : 0,
            'emails' => isset($person['emails']) ? array_values((array) $person['emails']) : array(),
        );
        if ($scheduled === null) {
            $scheduled = self::get_expected_factor($season) !== null;
        }
        $today = $today_ts !== null ? intval($today_ts) : strtotime(current_time('Y-m-d'));
        $days_since = function ($date) use ($today) {
            return $date ? max(0, (int) floor(($today - strtotime($date)) / DAY_IN_SECONDS)) : 0;
        };

        $has_invoice = false;
        $fee = 0.0;
        $paid = 0.0;
        $overdue = 0.0;
        $flag_overdue = 0.0;
        // Earliest date an invoice at least $1 overdue fell behind; and,
        // in case only small amounts add up to $1, the latest of them.
        $since = $small_since = null;
        $flag_since = $flag_small_since = null;
        $arrangement = false;
        $held = null;
        $other_seasons = array();
        $small_seasons = array();
        $season_schedule = null;
        foreach ((array) $invoices as $invoice) {
            $invoice_uid = intval($invoice->user_id);
            $is_mine = ($invoice_uid > 0 && $person['user_id'] > 0)
                ? $invoice_uid === $person['user_id']
                : in_array(strtolower((string) $invoice->email), $person['emails'], true);
            if (!$is_mine) {
                continue;
            }
            if (intval($invoice->payment_arrangement) === 1 && ($held === null || (string) $invoice->payment_arrangement_at > $held['at'])) {
                $held = array('note' => (string) $invoice->payment_arrangement_note, 'at' => (string) $invoice->payment_arrangement_at, 'by' => intval($invoice->payment_arrangement_by));
            }
            if ((string) $invoice->season === $season) {
                $has_invoice = true;
                $fee += floatval($invoice->invoice_amount);
                $paid += floatval($invoice->invoice_amount) - floatval($invoice->outstanding_amount);
            }
            if (TeamOversight_Fees::timeline_applies($invoice->season)) {
                // 2027+: the player's own schedule; its behind date is
                // already floored (behind_floor()).
                $schedule = (isset($invoice->schedule) && is_array($invoice->schedule)) ? $invoice->schedule : TeamOversight_Fee_Timeline::schedule($invoice);
                if ((string) $invoice->season === $season && $season_schedule === null) {
                    $season_schedule = $schedule;
                }
                $owed = $schedule['overdue'];
                if ($owed <= 0) {
                    continue;
                }
                $overdue += $owed;
                $fell_behind = $schedule['behind_since'];
            } else {
                $owed = self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
                if ($owed <= 0) {
                    continue;
                }
                $overdue += $owed;
                $fell_behind = self::get_overdue_since($invoice);
                $created = intval($invoice->created_ts) > 0 ? wp_date('Y-m-d', intval($invoice->created_ts)) : '';
                $billed = max($created, substr((string) $invoice->fee_raised_at, 0, 10));
                if ($fell_behind && $billed !== '' && $billed > $fell_behind) {
                    $fell_behind = $billed;
                }
            }
            if ($fell_behind) {
                if ($owed >= 1 && ($since === null || $fell_behind < $since)) {
                    $since = $fell_behind;
                }
                if ($small_since === null || $fell_behind > $small_since) {
                    $small_since = $fell_behind;
                }
            }
            if ((string) $invoice->season !== $season) {
                if ($owed >= 1) {
                    $other_seasons[] = (string) $invoice->season;
                } else {
                    $small_seasons[] = (string) $invoice->season;
                }
            }
            // Both site-time datetimes, so comparing the strings is safe.
            $covered = intval($invoice->payment_arrangement) === 1
                && ((string) $invoice->fee_raised_at === '' || (string) $invoice->fee_raised_at <= (string) $invoice->payment_arrangement_at);
            if ($covered) {
                $arrangement = true;
                continue;
            }
            $flag_overdue += $owed;
            if ($fell_behind) {
                if ($owed >= 1 && ($flag_since === null || $fell_behind < $flag_since)) {
                    $flag_since = $fell_behind;
                }
                if ($flag_small_since === null || $fell_behind > $flag_small_since) {
                    $flag_small_since = $fell_behind;
                }
            }
        }
        if ($since === null) {
            $since = $small_since;
            // No single invoice is $1 overdue: if they're behind, the
            // small ones are what make them so, so name their seasons.
            if ($overdue >= 1) {
                $other_seasons = array_merge($other_seasons, $small_seasons);
            }
        }
        if ($flag_since === null) {
            $flag_since = $flag_small_since;
        }

        $behind = $overdue >= 1;
        $days_behind = $behind ? $days_since($since) : 0;
        $flag_days = $flag_overdue >= 1 ? $days_since($flag_since) : 0;
        $flagged = $flag_overdue >= 1 && $flag_days >= self::STANDING_FLAG_DAYS;
        $other_seasons = array_values(array_unique($other_seasons));
        sort($other_seasons);

        if ($flagged) {
            $state = 'flagged';
        } elseif ($behind && $flag_overdue < 1) {
            $state = 'arrangement';
        } elseif ($behind) {
            $state = 'behind';
        } elseif ($fee < 0.01) {
            $state = 'none';
        } elseif ($fee - $paid < 0.01) {
            $state = 'paid';
        } else {
            $state = $scheduled ? 'ontrack' : 'unscheduled';
        }

        return array(
            'has_invoice' => $has_invoice,
            'fee' => round($fee, 2),
            'paid' => round($paid, 2),
            'paid_pct' => $fee >= 0.01 ? (int) floor(max(0, min(1, $paid / $fee)) * 100) : null,
            'scheduled' => $scheduled,
            'overdue' => round($overdue, 2),
            'days_behind' => $days_behind,
            'flag_days' => $flag_days,
            'flagged' => $flagged,
            'arrangement' => $arrangement,
            'arrangement_held' => $held,
            'other_seasons' => $other_seasons,
            'state' => $state,
            'due_pct' => $season_schedule ? $season_schedule['due_pct'] : null,
            'window_from' => $season_schedule ? $season_schedule['window_from'] : null,
            'window_to' => $season_schedule ? $season_schedule['window_to'] : null,
            'stopped_on' => $season_schedule ? $season_schedule['stopped_on'] : null,
        );
    }

    /**
     * Record or end a payment arrangement (Payment Management). It covers
     * the person's debt, not one season: recording marks the chosen invoice
     * and every other invoice of theirs with money still owing, so an
     * earlier season's leftover is covered too; ending clears every one of
     * theirs that has it. Those invoices still count as behind, but never
     * flag the player as not to train. Invoices belong to their account
     * (one with no account goes by its email). The note is for admins only.
     * Logged once. Returns false when the invoice doesn't exist.
     */
    public static function set_payment_arrangement($invoice_id, $on, $note = '') {
        global $wpdb;

        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, user_id, email, season FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", intval($invoice_id)));
        if (!$invoice) {
            return false;
        }

        $user_id = intval($invoice->user_id) > 0 ? intval($invoice->user_id) : max(0, intval(TeamOversight_Fees::resolve_user_id($invoice->email)));
        $emails = array(strtolower((string) $invoice->email));
        $account = $user_id ? get_userdata($user_id) : null;
        if ($account && $account->user_email) {
            $emails[] = strtolower($account->user_email);
        }
        $emails = array_values(array_unique($emails));
        // The same identity rule as get_fee_standings(): with an account,
        // its invoices plus no-account ones under its emails; without one,
        // everything under the email.
        $in = implode(',', array_fill(0, count($emails), '%s'));
        if ($user_id > 0) {
            $mine = "((user_id > 0 AND user_id = %d) OR ((user_id IS NULL OR user_id = 0) AND email IN ($in)))";
            $params = array_merge(array(intval($invoice->id), $user_id), $emails);
        } else {
            $mine = "email IN ($in)";
            $params = array_merge(array(intval($invoice->id)), $emails);
        }
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT id, season FROM {$wpdb->prefix}team_invoices
            WHERE id = %d
                OR ($mine AND " . ($on ? 'outstanding_amount > 0' : 'payment_arrangement = 1') . ")
            ORDER BY season, id
        ", $params));

        $note = mb_substr(trim((string) $note), 0, 255);
        $fields = $on
            ? array('payment_arrangement' => 1, 'payment_arrangement_note' => $note, 'payment_arrangement_at' => current_time('mysql'), 'payment_arrangement_by' => get_current_user_id())
            : array('payment_arrangement' => 0, 'payment_arrangement_note' => null, 'payment_arrangement_at' => null, 'payment_arrangement_by' => null);
        $seasons = array();
        $ids = array();
        foreach ($rows as $row) {
            $wpdb->update("{$wpdb->prefix}team_invoices", $fields, array('id' => $row->id));
            $seasons[] = $row->season;
            $ids[] = '#' . $row->id;
        }

        TeamOversight_Log::add(
            'payment_arrangement',
            ($on ? 'Payment arrangement recorded' : 'Payment arrangement ended') . ' for ' . implode(', ', array_unique($seasons)) . ' fees (invoice ' . implode(', ', $ids) . ')' . ($on && $note !== '' ? ': ' . $note : ''),
            array('user_id' => $user_id)
        );
        return true;
    }

    /**
     * One person's standing in words, without dollar amounts (the coach
     * portal shows percentages only): "40% paid · 3 weeks behind".
     */
    public static function describe_standing($standing) {
        if ($standing['state'] === 'none') {
            return !empty($standing['has_invoice']) ? 'no fee' : 'no fee yet';
        }
        if ($standing['state'] === 'paid') {
            return 'paid in full';
        }
        $lead = array();
        if ($standing['paid_pct'] !== null) {
            $lead[] = $standing['paid_pct'] . '% paid';
        }
        if ($standing['other_seasons']) {
            $lead[] = implode(', ', $standing['other_seasons']) . ' fees owing';
        }
        $parts = $lead ? array(implode(', ', $lead)) : array();
        // Time behind leaves out invoices with an arrangement (the flag's
        // measure).
        $behind = self::describe_behind($standing['flag_days']);
        if ($standing['state'] === 'flagged') {
            $parts[] = $behind . ' — no training';
        } elseif ($standing['state'] === 'behind') {
            $parts[] = $behind;
        } elseif ($standing['state'] === 'arrangement') {
            $parts[] = 'payment arrangement';
        } elseif ($standing['state'] === 'ontrack') {
            $parts[] = 'on track';
        }
        return implode(' · ', $parts);
    }

    /**
     * One person's fee standing as the pill coaches see on the team list
     * (and admins on Member Lookup's Fee timeline card): percentage paid
     * and how far behind, never an amount (fees differ by class, and some
     * are set by hand, so a reduced fee would show). More than a month
     * behind is flagged: they shouldn't train until it's sorted with the
     * club. The tooltip explains the schedule: for 2026 and earlier the
     * season-long one, as before; for 2027+ (1.70.2) the player's own
     * window, in percentages and dates only. $standing: one
     * get_fee_standings() entry. Style it with standing_pill_css().
     */
    public static function render_standing_pill($standing, $season) {
        $pill = self::standing_pill_parts($standing, $season);
        return '<span class="cac-fee cac-fee-' . esc_attr($pill['state']) . '" title="' . esc_attr($pill['title']) . '">'
            . ($pill['flag'] ? '&#9888; ' : '')
            . esc_html($pill['text']) . '</span>';
    }

    /**
     * What render_standing_pill() draws, as plain text: state (the
     * cac-fee-<state> class), title (the tooltip), text ("Fees: ...") and
     * flag (the warning sign in front). The How it works calculator sends
     * these to its script, which builds the same pill with textContent.
     * Percentages and dates only, never an amount.
     */
    public static function standing_pill_parts($standing, $season) {
        $season = (string) $season;
        // Before release a $0 invoice is a fee taken back until then (any
        // payment kept as credit), not an exemption.
        if ($standing['state'] === 'none' && !TeamOversight_Fees::fees_released($season)) {
            $standing['has_invoice'] = false;
        }
        if ($standing['state'] === 'none') {
            $title = !empty($standing['has_invoice']) ? 'They have no fee to pay for ' . $season . '.' : 'No ' . $season . ' fee has been issued for them yet.';
        } else {
            if (TeamOversight_Fees::timeline_applies($season)) {
                $title = self::standing_schedule_title($standing, $season);
            } else {
                $dates = TeamOversight_Fees::get_season_dates($season);
                $factor = self::get_expected_factor($season);
                $title = ($dates && $factor !== null)
                    ? 'Fees fall due evenly across the season (' . date('j M', strtotime($dates['start'])) . ' – ' . date('j M Y', strtotime($dates['end'])) . '), and ' . intval(round($factor * 100)) . '% of it has gone.'
                    : 'No season dates are set yet, so nothing falls due on a schedule.';
            }
            if ($standing['other_seasons']) {
                $title .= ' Includes fees still owing from ' . implode(', ', $standing['other_seasons']) . '.';
            }
            if ($standing['arrangement']) {
                $title .= ' The club has agreed a payment arrangement.';
            }
            $title .= ' A month or more behind means they shouldn\'t train until it\'s sorted with the club.';
        }
        return array(
            'state' => (string) $standing['state'],
            'title' => $title,
            'text' => 'Fees: ' . self::describe_standing($standing),
            'flag' => $standing['state'] === 'flagged',
        );
    }

    /**
     * The 2027+ pill tooltip's first sentence: the player's own schedule
     * from the standing's due_pct / window_from / window_to / stopped_on.
     * Percentages and dates only, never an amount.
     */
    private static function standing_schedule_title($standing, $season) {
        if (!TeamOversight_Fees::get_season_dates($season)) {
            return 'No season dates are set yet, so nothing falls due on a schedule.';
        }
        if (!isset($standing['due_pct']) || $standing['due_pct'] === null) {
            return 'No ' . $season . ' fee has been issued for them yet.';
        }
        $today = current_time('Y-m-d');
        $fmt = array('TeamOversight_Fee_Timeline', 'format_date');
        $stopped_on = !empty($standing['stopped_on']) ? (string) $standing['stopped_on'] : '';
        if ($stopped_on !== '' && $today >= $stopped_on) {
            return 'They stopped on ' . call_user_func($fmt, $stopped_on) . ', so everything for the time played is due now.';
        }
        if (empty($standing['window_from']) || empty($standing['window_to'])) {
            return 'The ' . $season . ' season is over, so everything still owing is due now.';
        }
        if ($standing['window_from'] >= $standing['window_to']) {
            $title = 'They started on or after the paid-in-full date, so all of it ' . ($today >= $standing['window_from'] ? 'fell' : 'falls') . ' due on ' . call_user_func($fmt, $standing['window_from']) . '.';
        } else {
            $pct = intval($standing['due_pct']);
            $title = 'Fees fall due evenly from ' . call_user_func($fmt, $standing['window_from']) . ' to ' . call_user_func($fmt, $standing['window_to']) . '; '
                . ($pct >= 100 ? 'all of it is due now' : ($pct <= 0 ? 'nothing is due yet' : $pct . '% is due now')) . '.';
        }
        if ($stopped_on !== '') {
            $title .= ' They stop on ' . call_user_func($fmt, $stopped_on) . ', when the rest for the time played falls due.';
        }
        return $title;
    }

    /**
     * The fee standing pill's CSS, every rule under $scope (a selector such
     * as '.coach-portal' or '.murvc-lookup'), shared by the coach portal and
     * Member Lookup. Sizes follow the coach portal's --cp-* tokens where the
     * page defines them.
     */
    public static function standing_pill_css($scope) {
        $scope = trim(preg_replace('/[^A-Za-z0-9_.#\- ]/', '', (string) $scope));
        $rules = array(
            '.cac-fee' => 'display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; min-height: var(--cp-control-h, 0); padding: 4px 12px; font-size: 13px; font-weight: 600; line-height: 1.25; text-align: center; border: 1px solid var(--cp-border, #c3c4c7); border-radius: var(--cp-radius, 4px); background: #f6f7f7; color: var(--cp-text, #1d2327); cursor: help;',
            '.cac-fee-paid, .cac-fee-ontrack' => 'background: #e8f5ea; border-color: #2e8540; color: #1e6b2a;',
            '.cac-fee-behind' => 'background: #fff4e0; border-color: #d18a00; color: #7a4a00;',
            '.cac-fee-flagged' => 'background: #fce8e8; border-color: #c62d2d; color: #a00;',
            '.cac-fee-arrangement' => 'background: #e8f0fb; border-color: #4f7cc0; color: #1d4f91;',
            '.cac-fee-none' => 'font-weight: 400; color: var(--cp-muted, #646970);',
        );
        $css = '';
        foreach ($rules as $selectors => $body) {
            $scoped = array();
            foreach (explode(',', $selectors) as $selector) {
                $scoped[] = trim($scope . ' ' . trim($selector));
            }
            $css .= implode(', ', $scoped) . ' { ' . $body . " }\n";
        }
        return $css;
    }

    /**
     * Thin progress bar: green/red fill = fraction paid, dark marker = where
     * the schedule says you should be today. Empty string without dates.
     */
    public static function render_fee_progress($invoice_amount, $outstanding_amount, $season) {
        $expected = self::get_expected_factor($season);
        $invoice_amount = floatval($invoice_amount);
        if ($expected === null || $invoice_amount <= 0) {
            return '';
        }

        $paid_pct = (int) round(max(0, min(1, ($invoice_amount - floatval($outstanding_amount)) / $invoice_amount)) * 100);
        $sched_pct = (int) round($expected * 100);
        $state = $paid_pct >= $sched_pct ? 'ontrack' : 'behind';

        return '<div class="fee-progress" title="Paid ' . $paid_pct . '% — schedule expects ' . $sched_pct . '%">'
            . '<div class="fee-progress-fill fee-progress-' . $state . '" style="width: ' . min(100, $paid_pct) . '%;"></div>'
            . '<div class="fee-progress-marker" style="left: ' . min(100, $sched_pct) . '%;"></div>'
            . '</div>'
            . '<p class="fee-progress-caption">Paid ' . $paid_pct . '% &middot; season ' . $sched_pct . '% elapsed</p>';
    }

    /**
     * The pay box, shared by [member_fees] and the Ready to Play fees
     * step. Members kept missing that the amount is theirs to choose —
     * a prefilled box reads as fixed — so the choice is now explicit
     * radio options and the button states exactly what it will charge.
     * The chosen option is resolved server-side, so it holds without JS.
     */
    public static function render_pay_box($outstanding, $overdue, $class = 'member-fees-pay-form') {
        $outstanding = round(floatval($outstanding), 2);
        $overdue = round(min(floatval($overdue), $outstanding), 2);
        $show_overdue = ($overdue >= 1 && $overdue < $outstanding);
        $uid = 'murvc-pay-' . wp_rand(1000, 9999);

        ob_start();
        ?>
        <form method="post" class="<?php echo esc_attr($class); ?> murvc-pay-box" id="<?php echo esc_attr($uid); ?>">
            <h4>Make a payment</h4>
            <p class="murvc-pay-intro">You can pay <strong>any amount, any time</strong> — pay it off gradually or all at once.</p>

            <?php if ($show_overdue): ?>
                <label class="murvc-pay-option">
                    <input type="radio" name="murvc_pay_choice" value="overdue" checked>
                    <span>Pay what's <strong>overdue now</strong> — $<?php echo number_format($overdue, 2); ?></span>
                </label>
            <?php endif; ?>

            <label class="murvc-pay-option">
                <input type="radio" name="murvc_pay_choice" value="full" <?php checked(!$show_overdue); ?>>
                <span>Pay the <strong>full remaining balance</strong> — $<?php echo number_format($outstanding, 2); ?></span>
            </label>

            <label class="murvc-pay-option">
                <input type="radio" name="murvc_pay_choice" value="other">
                <span>Pay a <strong>different amount</strong></span>
            </label>

            <p class="murvc-pay-custom">
                <label for="<?php echo esc_attr($uid); ?>-amount">Amount ($)</label>
                <input type="number" name="murvc_pay_amount" id="<?php echo esc_attr($uid); ?>-amount"
                       min="1" max="<?php echo esc_attr(number_format($outstanding, 2, '.', '')); ?>" step="0.01"
                       placeholder="e.g. 50.00">
                <small>Anything from $1 up to $<?php echo number_format($outstanding, 2); ?>.</small>
            </p>

            <input type="hidden" name="murvc_pay_action" value="pay_fees">
            <?php wp_nonce_field('murvc_pay_fees', 'murvc_pay_nonce'); ?>
            <button type="submit" class="button button-primary murvc-pay-submit">Continue to payment</button>
        </form>

        <script>
        (function () {
            var form = document.getElementById(<?php echo wp_json_encode($uid); ?>);
            if (!form) { return; }
            var custom = form.querySelector('.murvc-pay-custom');
            var amount = form.querySelector('input[name="murvc_pay_amount"]');
            var button = form.querySelector('.murvc-pay-submit');
            var amounts = {
                overdue: <?php echo wp_json_encode(number_format($overdue, 2, '.', '')); ?>,
                full: <?php echo wp_json_encode(number_format($outstanding, 2, '.', '')); ?>
            };

            function sync() {
                var choice = form.querySelector('input[name="murvc_pay_choice"]:checked').value;
                var isOther = choice === 'other';
                custom.style.display = isOther ? '' : 'none';
                amount.required = isOther;
                if (isOther) {
                    button.textContent = amount.value ? 'Pay $' + parseFloat(amount.value).toFixed(2) + ' now' : 'Continue to payment';
                } else {
                    button.textContent = 'Pay $' + parseFloat(amounts[choice]).toFixed(2) + ' now';
                }
            }

            form.querySelectorAll('input[name="murvc_pay_choice"]').forEach(function (radio) {
                radio.addEventListener('change', sync);
            });
            amount.addEventListener('input', sync);
            sync();
        })();
        </script>

        <style>
        .murvc-pay-box .murvc-pay-intro { font-size: 13px; color: #555; margin: 0 0 10px 0; }
        .murvc-pay-box .murvc-pay-option { display: block; margin: 0 0 6px 0; cursor: pointer; }
        .murvc-pay-box .murvc-pay-option input { margin-right: 8px; }
        .murvc-pay-box .murvc-pay-custom { margin: 8px 0 12px 26px; }
        .murvc-pay-box .murvc-pay-custom input { width: 130px; margin: 0 8px; }
        .murvc-pay-box .murvc-pay-custom small { display: block; color: #666; font-size: 12px; margin-top: 4px; }
        .murvc-pay-box .murvc-pay-submit { margin-top: 4px; }
        </style>
        <?php
        return ob_get_clean();
    }

    public static function get_payment_product() {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $product_id = intval(get_option(self::PAYMENT_PRODUCT_OPTION));
        if (!$product_id) {
            return null;
        }
        $product = wc_get_product($product_id);
        return $product ? $product : null;
    }

    /**
     * All invoices for a user (matched by user_id with email fallback),
     * newest season first, each with created_ts (what invoice_schedule()
     * floors the behind clock with).
     */
    public static function get_user_invoices($user) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT *, UNIX_TIMESTAMP(created_date) AS created_ts FROM {$wpdb->prefix}team_invoices
            WHERE user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s)
            ORDER BY season DESC, id
        ", $user->ID, $user->user_email));
    }

    // ------------------------------------------------------------------
    // Member-facing balance + payment form: [member_fees]
    // ------------------------------------------------------------------

    /**
     * [player_fees] — a compact overdue-fees flag. Completely invisible
     * (renders nothing at all) unless the logged-in viewer has overdue
     * fees; when they do, it shows the amount and sends them to the
     * Player Checklist page to pay. Safe to drop on any page.
     *
     * Attributes: url — where the button goes (default /player-checklist/).
     */
    public function render_player_fees($atts = array()) {
        if (!is_user_logged_in()) {
            return '';
        }

        $atts = shortcode_atts(array(
            'url' => home_url('/player-checklist/'),
        ), $atts, 'player_fees');

        $invoices = self::get_user_invoices(wp_get_current_user());
        if (empty($invoices)) {
            return '';
        }

        $overdue = 0;
        foreach ($invoices as $invoice) {
            $overdue += self::invoice_overdue($invoice);
        }
        if ($overdue <= 0) {
            return '';
        }

        return '<div class="murvc-fees-flag">'
            . '<p><strong>You have overdue club fees: $' . number_format($overdue, 2) . '</strong></p>'
            . '<p>Please make a payment to stay on court.</p>'
            . '<p><a class="button button-primary" href="' . esc_url($atts['url']) . '">Go to your Player Checklist</a></p>'
            . '<style>.murvc-fees-flag{border:2px solid #dc3232;background:#fdf0f0;border-radius:8px;padding:14px 18px;max-width:560px;margin:0 0 40px 0;clear:both;overflow:hidden;}.murvc-fees-flag p{margin:5px 0;}</style>'
            . '</div>';
    }

    public function render_member_fees() {
        if (!is_user_logged_in()) {
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            return '<div class="member-fees-panel"><p><strong>Please log in to view your club fees.</strong></p>'
                . '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p></div>';
        }

        $user = wp_get_current_user();
        $invoices = self::get_user_invoices($user);

        if (empty($invoices)) {
            // Deliberately silent. Saying "your fees would appear here"
            // implies the club has nothing on record for them, which isn't
            // safe while legacy fees exist outside this system — better to
            // show nothing than to imply they're square.
            return '';
        }

        $payment_product = self::get_payment_product();
        $total_outstanding = 0;
        $total_overdue = 0;
        // The same schedules maybe_start_payment() charges by (invoice_schedule()).
        TeamOversight_Fee_Timeline::preload_for_invoices($invoices);

        ob_start();
        ?>
        <div class="member-fees-panel">
            <h3>Your Club Fees</h3>

            <?php foreach ($invoices as $invoice): ?>
                <?php
                $paid = self::get_ledger_paid($invoice->id);
                $credit = round($paid - floatval($invoice->invoice_amount), 2);
                $schedule = self::invoice_schedule($invoice);
                $timeline = $schedule['engine'] === 'timeline';
                $overdue = $schedule['overdue'];
                $total_outstanding += floatval($invoice->outstanding_amount);
                $total_overdue += $overdue;
                $dates = TeamOversight_Fees::get_season_dates($invoice->season);
                ?>
                <div class="member-fees-season">
                    <h4><?php echo esc_html($invoice->season); ?> Season</h4>
                    <?php if ($dates && $timeline): ?>
                        <p class="member-fees-schedule"><?php echo esc_html('Fees cover ' . TeamOversight_Fee_Timeline::format_date($dates['start']) . ' – ' . TeamOversight_Fee_Timeline::format_date($dates['end']) . '. ' . self::describe_member_schedule($schedule)); ?> Pay any amount, as often as you like, as long as you keep ahead of your schedule.</p>
                    <?php elseif ($dates): ?>
                        <p class="member-fees-schedule">Season runs <?php echo esc_html(date('j M Y', strtotime($dates['start']))); ?> &ndash; <?php echo esc_html(date('j M Y', strtotime($dates['end']))); ?>. Fees fall due progressively across the season — pay any amount, as often as you like, as long as you keep ahead of the schedule.</p>
                    <?php endif; ?>
                    <table class="member-fees-table">
                        <tr><th>Season fee</th><td>$<?php echo number_format($invoice->invoice_amount, 2); ?></td></tr>
                        <tr><th>Paid so far</th><td>$<?php echo number_format(max(0, $paid), 2); ?></td></tr>
                        <tr class="member-fees-owing"><th>Remaining</th><td>$<?php echo number_format($invoice->outstanding_amount, 2); ?></td></tr>
                        <?php if ($timeline && $schedule['scheduled']): ?>
                            <tr><th>Due by today</th><td>$<?php echo number_format($schedule['due_today'], 2); ?></td></tr>
                        <?php endif; ?>
                        <tr class="<?php echo $overdue > 0 ? 'member-fees-overdue' : ''; ?>"><th>Overdue now</th><td>$<?php echo number_format($overdue, 2); ?></td></tr>
                        <?php if ($credit >= 0.01 && floatval($invoice->outstanding_amount) <= 0): ?>
                            <tr class="member-fees-uptodate"><th>In credit</th><td>$<?php echo number_format($credit, 2); ?> — you've paid more than this season's fee; the club will offset or refund it.</td></tr>
                        <?php endif; ?>
                        <?php $paid_through = $schedule['next_due']; ?>
                        <?php if ($overdue <= 0 && floatval($invoice->outstanding_amount) > 0 && $paid_through): ?>
                            <tr class="member-fees-uptodate"><th>Next payment due</th><td><?php echo esc_html(date('j M Y', strtotime($paid_through))); ?></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php echo $schedule['progress_html']; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($total_outstanding > 0 && $payment_product): ?>
                <?php echo self::render_pay_box($total_outstanding, $total_overdue); ?>
            <?php elseif ($total_outstanding > 0): ?>
                <p><em>Online payment isn't available yet — please contact the club to arrange payment.</em></p>
            <?php else: ?>
                <p><strong>You're all paid up — thank you!</strong></p>
            <?php endif; ?>
        </div>

        <style>
        .member-fees-panel {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #f9f9f9;
            max-width: 560px;
            /* Breathing room + float containment so the panel never
               collides with UM profile headers rendered below it. */
            margin: 0 0 40px 0;
            clear: both;
            overflow: hidden;
        }

        .member-fees-season {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 15px;
        }

        .member-fees-season h4 {
            margin: 0 0 6px 0;
        }

        .member-fees-schedule {
            font-size: 13px;
            color: #666;
        }

        .member-fees-table {
            border-collapse: collapse;
            width: 100%;
        }

        .member-fees-table th {
            text-align: left;
            font-weight: 600;
            color: #444;
            padding: 4px 0;
            width: 50%;
        }

        .member-fees-table td {
            text-align: right;
            padding: 4px 0;
        }

        .member-fees-owing th, .member-fees-owing td {
            border-top: 1px solid #ddd;
            font-weight: 700;
        }

        .member-fees-overdue th, .member-fees-overdue td {
            color: #a00;
            font-weight: 700;
        }

        .member-fees-uptodate th, .member-fees-uptodate td {
            color: #155724;
            font-weight: 600;
        }

        .fee-progress {
            position: relative;
            height: 10px;
            background: #e8e8e8;
            border-radius: 5px;
            margin: 10px 0 2px 0;
            overflow: visible;
        }

        .fee-progress-fill {
            height: 100%;
            border-radius: 5px;
        }

        .fee-progress-ontrack { background: #46b450; }
        .fee-progress-behind { background: #dc3232; }

        .fee-progress-marker {
            position: absolute;
            top: -3px;
            width: 2px;
            height: 16px;
            background: #333;
        }

        .fee-progress-caption {
            font-size: 12px;
            color: #666;
            margin: 2px 0 0 0;
        }

        .member-fees-pay-form input[type="number"] {
            width: 120px;
            padding: 6px;
            font-size: 16px;
        }
        </style>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Pay-any-amount flow
    // ------------------------------------------------------------------

    public function maybe_start_payment() {
        if (!isset($_POST['murvc_pay_action']) || $_POST['murvc_pay_action'] !== 'pay_fees') {
            return;
        }

        if (!is_user_logged_in()
            || !isset($_POST['murvc_pay_nonce'])
            || !wp_verify_nonce($_POST['murvc_pay_nonce'], 'murvc_pay_fees')) {
            return;
        }

        $product = self::get_payment_product();
        if (!$product || !function_exists('WC')) {
            return;
        }

        $user = wp_get_current_user();
        $invoices = self::get_user_invoices($user);
        $total_outstanding = 0;
        foreach ($invoices as $invoice) {
            $total_outstanding += floatval($invoice->outstanding_amount);
        }

        // Resolve the chosen option server-side so the amount charged is
        // always the one the button named, with or without JS.
        $choice = isset($_POST['murvc_pay_choice']) ? sanitize_text_field($_POST['murvc_pay_choice']) : 'other';
        if ($choice === 'full') {
            $amount = round($total_outstanding, 2);
        } elseif ($choice === 'overdue') {
            // The same schedules [member_fees] and Ready to Play show.
            $overdue = 0;
            foreach ($invoices as $invoice) {
                $overdue += self::invoice_overdue($invoice);
            }
            $amount = round(min($overdue, $total_outstanding), 2);
        } else {
            $amount = round(floatval(isset($_POST['murvc_pay_amount']) ? $_POST['murvc_pay_amount'] : 0), 2);
        }

        if ($amount < 1 || $total_outstanding <= 0) {
            return;
        }
        $amount = min($amount, round($total_outstanding, 2));

        if (WC()->cart === null && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (WC()->cart === null) {
            return;
        }

        // One fee payment line at a time.
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (!empty($item['murvc_fee_payment'])) {
                WC()->cart->remove_cart_item($key);
            }
        }

        $added = WC()->cart->add_to_cart($product->get_id(), 1, 0, array(), array(
            'murvc_fee_payment' => array(
                'user_id' => $user->ID,
                'amount' => $amount,
            ),
        ));

        if ($added) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    public function apply_cart_payment_amount($cart) {
        if (!is_object($cart)) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (!empty($item['murvc_fee_payment']['amount']) && isset($item['data'])) {
                $item['data']->set_price(floatval($item['murvc_fee_payment']['amount']));
            }
        }
    }

    public function display_payment_in_cart($item_data, $cart_item) {
        if (!empty($cart_item['murvc_fee_payment'])) {
            $item_data[] = array(
                'key' => 'Applies to',
                'value' => 'Outstanding club fees',
            );
        }
        return $item_data;
    }

    public function attach_payment_to_order_item($item, $cart_item_key, $values) {
        if (!empty($values['murvc_fee_payment'])) {
            $item->add_meta_data('_murvc_fee_payment_user', intval($values['murvc_fee_payment']['user_id']), true);
        }
    }

    /** The payment product is one line of the chosen amount: quantity 2 would charge it twice. */
    public function payment_product_sold_individually($sold_individually, $product) {
        $payment_product_id = intval(get_option(self::PAYMENT_PRODUCT_OPTION));
        if ($payment_product_id && is_object($product)
            && in_array($payment_product_id, array(intval($product->get_id()), intval($product->get_parent_id())), true)) {
            return true;
        }
        return $sold_individually;
    }

    /**
     * Paid order containing a fee payment: reduce the member's outstanding
     * balances (oldest season first) and record it in the ledger.
     *
     * The ledger's UNIQUE order_item_id allows one row per order item, so
     * only the first part of a split carries the item id — that insert is
     * the claim, and a hook that loses it writes nothing. Later parts
     * carry the order id with a note naming the item. Anything paid above
     * the balance goes on the newest invoice, where it shows as credit.
     * Money that can't go in the ledger is logged as an unapplied payment.
     */
    public function handle_payment_order($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        global $wpdb;

        foreach ($order->get_items() as $item_id => $item) {
            $payer_id = intval($item->get_meta('_murvc_fee_payment_user'));
            if (!$payer_id) {
                continue;
            }

            // Dedupe: each order item is applied once (or flagged once as
            // unapplied, below — the treasurer takes it from there).
            $already = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_invoice_payments WHERE order_item_id = %d
            ", $item_id));
            if ($already || $item->get_meta('_murvc_fee_payment_unapplied')) {
                continue;
            }

            $amount = round(floatval($item->get_total()) + floatval($item->get_total_tax()), 2);
            if ($amount <= 0) {
                continue;
            }

            $payer = get_userdata($payer_id);
            $invoices = $payer ? array_reverse(self::get_user_invoices($payer)) : array(); // oldest season first

            // Work out every part before writing any.
            $remaining = $amount;
            $parts = array();
            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }
                $outstanding = floatval($invoice->outstanding_amount);
                if ($outstanding <= 0) {
                    continue;
                }

                $applied = min($remaining, $outstanding);
                $parts[] = array('invoice_id' => $invoice->id, 'amount' => $applied, 'over' => false);
                $remaining = round($remaining - $applied, 2);
            }
            // Paid more than is owed (e.g. a cart left over from before a
            // cash payment or fee change): keep it on the newest invoice,
            // as credit for the treasurer to offset or refund.
            if ($remaining >= 0.005 && !empty($invoices)) {
                $newest = end($invoices);
                $parts[] = array('invoice_id' => $newest->id, 'amount' => $remaining, 'over' => true);
            }

            if (empty($parts)) {
                // No invoice to hold it at all: flag it once per item.
                if (class_exists('TeamOversight_Log') && wc_add_order_item_meta($item_id, '_murvc_fee_payment_unapplied', current_time('mysql'), true)) {
                    TeamOversight_Log::add(
                        'payment_unapplied',
                        'Online fee payment (order #' . intval($order_id) . ') not applied — '
                            . ($payer ? 'no fee invoice on record' : 'the member account no longer exists')
                            . '; offset or refund it',
                        array('user_id' => $payer_id, 'amount' => $amount)
                    );
                }
                continue;
            }

            $count = count($parts);
            foreach ($parts as $n => $part) {
                $label = ($count > 1 ? 'part ' . ($n + 1) . ' of ' . $count . ' of ' : '') . 'order item #' . intval($item_id);
                if ($part['over']) {
                    $note = 'Overpayment, ' . $label;
                } else {
                    $note = $count > 1 ? ucfirst($label) : '';
                }
                $recorded = self::record_payment($part['invoice_id'], $part['amount'], 'online', $note, $order_id, $n === 0 ? $item_id : null);
                if ($recorded) {
                    continue;
                }
                if ($n === 0) {
                    $claimed = $wpdb->get_var($wpdb->prepare("
                        SELECT id FROM {$wpdb->prefix}team_invoice_payments WHERE order_item_id = %d
                    ", $item_id));
                    // No claim either (its invoice went, or a DB error):
                    // flag it once so no later hook applies it behind the
                    // treasurer's back, and tell the treasurer.
                    if (!$claimed && class_exists('TeamOversight_Log') && wc_add_order_item_meta($item_id, '_murvc_fee_payment_unapplied', current_time('mysql'), true)) {
                        TeamOversight_Log::add(
                            'payment_unapplied',
                            'Online fee payment (order #' . intval($order_id) . ') could not be recorded — record it with Record Payment',
                            array('user_id' => $payer_id, 'amount' => $amount)
                        );
                    }
                    break; // otherwise another hook claimed this item first
                }
                // A later part failed (its invoice went, or a DB error). The
                // claim is in, so no hook retries it: the treasurer must.
                if (class_exists('TeamOversight_Log')) {
                    TeamOversight_Log::add(
                        'payment_unapplied',
                        'Online fee payment (order #' . intval($order_id) . '): ' . $label . ' could not be recorded — record it with Record Payment',
                        array('user_id' => $payer_id, 'amount' => $part['amount'])
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Refunds and cancellations: alert, never reverse
    // ------------------------------------------------------------------

    /**
     * The order's fee-payment items that reached the books: the item holds
     * the ledger claim, or was flagged unapplied (logged for the treasurer
     * to record). item id => array(item, payer_id). An order that never got
     * that far (unpaid, then cancelled by the stock-hold timeout) has none,
     * so it raises no alert.
     */
    private function recorded_fee_items($order) {
        global $wpdb;

        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $payer_id = intval($item->get_meta('_murvc_fee_payment_user'));
            if (!$payer_id) {
                continue;
            }
            $claimed = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_invoice_payments WHERE order_item_id = %d
            ", $item_id));
            if ($claimed || $item->get_meta('_murvc_fee_payment_unapplied')) {
                $items[$item_id] = array('item' => $item, 'payer_id' => $payer_id);
            }
        }
        return $items;
    }

    /** What an order put in the ledger, by season: "2026 fees $100.00, 2027 fees $50.00" ('' for nothing). */
    private static function describe_order_ledger($order_id) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT i.season, SUM(p.amount) AS amount
            FROM {$wpdb->prefix}team_invoice_payments p
            LEFT JOIN {$wpdb->prefix}team_invoices i ON i.id = p.invoice_id
            WHERE p.order_id = %d AND p.source = 'online'
            GROUP BY i.season
            ORDER BY i.season
        ", $order_id));

        $parts = array();
        foreach ($rows as $row) {
            $parts[] = ($row->season !== null ? $row->season . ' fees ' : '') . '$' . number_format(floatval($row->amount), 2);
        }
        return implode(', ', $parts);
    }

    /**
     * A fee-payment order refunded in WooCommerce, fully or partly (setting
     * the status to Refunded creates a refund too). Called with the refund,
     * or with just the order on the status change, when every refund on it
     * is checked. Each refund alerts once (order item meta); the ledger is
     * left alone for the treasurer to put right with Record Payment.
     */
    public function handle_payment_refund($order_id, $refund_id = 0) {
        if (!function_exists('wc_get_order') || !class_exists('TeamOversight_Log')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || !is_callable(array($order, 'get_refunds'))) {
            return;
        }
        $fee_items = $this->recorded_fee_items($order);
        if (empty($fee_items)) {
            return;
        }

        $other_products = false;
        foreach ($order->get_items() as $item) {
            if (!intval($item->get_meta('_murvc_fee_payment_user'))) {
                $other_products = true;
            }
        }
        reset($fee_items);
        $first_id = key($fee_items);
        $refunds = $refund_id ? array(wc_get_order($refund_id)) : $order->get_refunds();

        foreach ($refunds as $refund) {
            if (!$refund || !is_callable(array($refund, 'get_amount'))) {
                continue;
            }

            // How much of the refund was the fee payment. A refund with no
            // line items (just an amount typed in) can't say, so all of it
            // counts, flagged when the order held other products too.
            $refunded = 0.0;
            $itemised = false;
            foreach ($refund->get_items() as $refund_item) {
                $itemised = true;
                if (isset($fee_items[intval($refund_item->get_meta('_refunded_item_id'))])) {
                    $refunded += abs(floatval($refund_item->get_total()) + floatval($refund_item->get_total_tax()));
                }
            }
            if (!$itemised) {
                $refunded = abs(floatval($refund->get_amount()));
            }
            if ($refunded < 0.005) {
                continue; // it refunded other products only
            }

            if (!wc_add_order_item_meta($first_id, '_murvc_refund_alerted_' . intval($refund->get_id()), current_time('mysql'), true)) {
                continue; // already alerted
            }

            $recorded = self::describe_order_ledger($order_id);
            $message = 'Order #' . intval($order_id) . ': $' . number_format($refunded, 2) . ' refunded in WooCommerce'
                . (!$itemised && $other_products ? ' (not itemised, and the order has other products too: check how much was the fee payment)' : '');
            if ($recorded === '') {
                $message .= '. Its fee payment was logged as unapplied, so the plugin never recorded it: record only what the club kept, if anything. If you have since recorded it by hand, record a negative payment with Record Payment to match.';
            } else {
                $message .= ', but its fee payment still counts as paid (' . $recorded . '). Record a negative payment with Record Payment to match.';
            }
            TeamOversight_Log::add('payment_refunded', $message, array('user_id' => $fee_items[$first_id]['payer_id'], 'amount' => $refunded));
        }
    }

    /**
     * A fee-payment order cancelled after its payment reached the books.
     * Cancelling doesn't return the money by itself, so the treasurer is
     * asked to check, once per order; the ledger is left alone.
     */
    public function handle_payment_cancelled($order_id) {
        if (!function_exists('wc_get_order') || !class_exists('TeamOversight_Log')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $fee_items = $this->recorded_fee_items($order);
        if (empty($fee_items)) {
            return;
        }

        reset($fee_items);
        $first_id = key($fee_items);
        if (!wc_add_order_item_meta($first_id, '_murvc_refund_alerted_cancelled', current_time('mysql'), true)) {
            return; // already alerted
        }

        $amount = 0.0;
        foreach ($fee_items as $fee_item) {
            $amount += floatval($fee_item['item']->get_total()) + floatval($fee_item['item']->get_total_tax());
        }
        $recorded = self::describe_order_ledger($order_id);
        $message = 'Order #' . intval($order_id) . ' was cancelled in WooCommerce after its $' . number_format($amount, 2) . ' fee payment '
            . ($recorded === ''
                ? 'was logged as unapplied, so the plugin never recorded it: record it only if the club kept the money. If you have since recorded it by hand and the money went back, record a negative payment with Record Payment to match.'
                : 'was recorded (' . $recorded . '). It still counts as paid: if the money went back, record a negative payment with Record Payment.');
        TeamOversight_Log::add('payment_refunded', $message, array('user_id' => $fee_items[$first_id]['payer_id'], 'amount' => $amount));
    }

    /** Payment Management: recent refunds and cancellations to put right by hand. */
    public function render_refund_notice() {
        if (!class_exists('TeamOversight_Log') || !isset($_GET['page']) || $_GET['page'] !== 'team-oversight-invoices') {
            return;
        }
        TeamOversight_Log::render_alert_notice(
            array('payment_refunded'),
            'Fee payments refunded or cancelled in WooCommerce in the last 30 days. The ledger never changes by itself: put each one right with Record Payment (a negative amount), with the order number in the note, for example Refund order #123. That note shows on Logs and Member Lookup, so check there first: an entry stays on this list after it has been put right.'
        );
    }
}
