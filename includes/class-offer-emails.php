<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Offer emails (1.76.0): the offer and its reminder, the withdrawal, the
 * "you're locked in" note, the three coach emails and the automatic
 * "not selected" email, all sent through an OUTBOX (team_offer_emails): one
 * row per message, unique on its dedupe key, so a message is queued once and
 * sent once whatever retries the caller does. Nothing here is sent inline by
 * a transition: TeamOversight_Offers calls queue(), and a flush sends.
 *
 * The core (version 1.76.0) wrote queue(), live(), dedupe_key(), the
 * outbox readers and the live-site gate's hold on old mail; the EMAILS
 * BUILDER wrote the rest (wording, recipients, the flush, the retry and
 * unknown rules, the admin section, the test send). Keep those signatures.
 * See design/offer-api.md.
 *
 * Wording and rules: design/offer-deposit-tech-design.md section 9, and the
 * decisions at the end of design/offer-deposit-spec.md. Nine editable
 * wordings: the offer, its reminder (an unanswered offer) and its reminder
 * for an accepted offer (deposit still due), withdrawn, the player's
 * "you're locked in", the three coach notices and "not selected". Every send
 * goes through TeamOversight_Email::render() and ::send(); the player's
 * address is read from the account at send time (never the snapshot on the
 * offer); the coaches are read from the live roster at send time; the
 * live-site gate (TeamOversight_Offers::emails_live()) stops a copy of the
 * database from sending anything; and every message is re-checked just
 * before it is sent, so an offer that was withdrawn while its email waited
 * is never mailed as an offer.
 *
 * The Emails page calls render_settings_section() (inside the page) and
 * handle_settings_post() (before the page prints): see their docblocks.
 */
class TeamOversight_Offer_Emails {

    /** On/off switches for the coach emails, the "you're locked in" email and the not-selected email (the offer, reminder and withdrawal emails have none: they are the process). */
    const OPTION = 'team_oversight_offer_emails';

    /** Saved wording per email: subject, heading and body (blank = the built-in text). */
    const TEXT_OPTION = 'team_oversight_offer_email_texts';

    /** The single cron event that drains the outbox when a flush ran out of time. */
    const QUEUE_HOOK = 'murvc_offers_email_queue';

    /** A message may be tried this many times in all before a person has to look at it. */
    const MAX_TRIES = 3;

    /** A row left in 'sending' this long is 'unknown': it may or may not have been delivered, so it is never retried automatically. */
    const STUCK_MINUTES = 10;

    /** A failed message waits this long before the next flush tries it again. */
    const RETRY_MINUTES = 5;

    /**
     * The message types that can be queued: type => dedupe key shape.
     *  offer            offer:<offer id>
     *  reminder         reminder:<offer id>:<reminder number>      context: reminder_number
     *  withdrawn        withdrawn:<offer id>                       context: held_cents, by
     *  coach_accepted  coach_accepted:<offer id>
     *  coach_declined   coach_declined:<offer id>                  context: had_deposit
     *  coach_overdue    coach_overdue:<offer id>:<reply|deposit>   context: kind (reply|deposit), date, days_late
     *  not_selected     not_selected:<application id>:<team>      offer_id 0; context: application_id, team, by, via
     *                                                              (1.80.0: one per application and team, queued only
     *                                                              when someone presses Send; a key with no team is
     *                                                              the 1.79 per-application notice, never queued again)
     *  locked_in        locked_in:<offer id>                       the player's "you're locked in" note when the
     *                                                              deposit completes (queued by lock_in(); sent
     *                                                              only while the offer is locked_in)
     */
    public static function types() {
        return array('offer', 'reminder', 'withdrawn', 'coach_accepted', 'coach_declined', 'coach_overdue', 'not_selected', 'locked_in');
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'team_offer_emails';
    }

    /**
     * The outbox's unique key for a message, or '' when the type or its
     * context is unusable. One offer can have an offer email, several
     * reminders and a withdrawal in flight at once without overwriting each
     * other; the same key is never queued twice.
     */
    public static function dedupe_key($type, $offer_id, $context = array()) {
        $offer_id = intval($offer_id);
        $context = is_array($context) ? $context : array();
        switch ($type) {
            case 'offer':
            case 'withdrawn':
            case 'coach_accepted':
            case 'coach_declined':
            case 'locked_in':
                return $offer_id > 0 ? $type . ':' . $offer_id : '';
            case 'reminder':
                return ($offer_id > 0 && !empty($context['reminder_number'])) ? 'reminder:' . $offer_id . ':' . intval($context['reminder_number']) : '';
            case 'coach_overdue':
                return ($offer_id > 0 && isset($context['kind']) && in_array($context['kind'], array('reply', 'deposit'), true)) ? 'coach_overdue:' . $offer_id . ':' . $context['kind'] : '';
            case 'not_selected':
                $team = (isset($context['team']) && class_exists('TeamOversight_Not_Selected')) ? TeamOversight_Not_Selected::clean_team($context['team']) : '';
                return (!empty($context['application_id']) && $team !== '') ? TeamOversight_Not_Selected::dedupe_key($context['application_id'], $team) : '';
        }
        return '';
    }

    /**
     * Record a message in the outbox (INSERT IGNORE on the dedupe key): it
     * is queued once, whatever retries the caller does, and a message that
     * is already queued or sent is never queued again. Nothing is sent here.
     * Never throws. $context is stored as JSON for whoever sends it (keep
     * it small: ids, dates, counts; no names or addresses).
     * Returns array(queued (bool: this call made the row), id, dedupe_key,
     * status (the row's status), error).
     */
    public static function queue($type, $offer_id, $context = array()) {
        global $wpdb;
        $key = self::dedupe_key($type, $offer_id, $context);
        if ($key === '' || !in_array($type, self::types(), true)) {
            return array('queued' => false, 'id' => 0, 'dedupe_key' => $key, 'status' => '', 'error' => 'Unknown or incomplete message.');
        }
        $application_id = ($type === 'not_selected') ? intval($context['application_id']) : 0;
        if ($type !== 'not_selected' && class_exists('TeamOversight_Offers')) {
            $offer = TeamOversight_Offers::get($offer_id);
            $application_id = $offer ? intval($offer->application_id) : 0;
        }
        $json = wp_json_encode(is_array($context) ? $context : array());
        $table = self::table();
        $wpdb->suppress_errors(true);
        $added = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (dedupe_key, event, offer_id, application_id, status, tries, created_at, context) VALUES (%s, %s, %d, %d, 'pending', 0, %s, %s)",
            $key, $type, intval($offer_id), $application_id, current_time('mysql'), (string) $json
        ));
        $wpdb->suppress_errors(false);
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM $table WHERE dedupe_key = %s", $key));
        if ($added) {
            self::flush_after_request();
        }
        return array('queued' => (bool) $added, 'id' => $row ? intval($row->id) : 0, 'dedupe_key' => $key, 'status' => $row ? $row->status : '', 'error' => '');
    }

    /** Is an offer email allowed to leave this site? (The live-site gate; TeamOversight_Offers::emails_live().) */
    public static function live() {
        return class_exists('TeamOversight_Offers') ? TeamOversight_Offers::emails_live() : false;
    }

    // ---- old messages held at the gate, and the outbox readers (core) ----
    //
    // These are the only places other classes read or move outbox rows: the
    // Offers admin page's status strip (problem_rows(), status_counts()), the
    // coach card's "email sending" hint (status_for_offers()), the Not
    // selected tab (status_for_key()) and the live-site gate's hold on stale
    // mail (hold_stale(), release_held(), discard_held()). Nothing else runs
    // SQL on team_offer_emails.

    /** The moment before which a pending message counts as stale (24 hours ago, site time). */
    private static function stale_cutoff() {
        return date('Y-m-d H:i:s', strtotime(current_time('mysql')) - DAY_IN_SECONDS);
    }

    /** Pending messages queued more than 24 hours ago (what a pause or a long gap leaves behind). */
    public static function stale_count() {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending' AND created_at < %s", self::stale_cutoff())));
    }

    /**
     * Hold every pending message older than 24 hours ('pending' -> 'held'):
     * the flush claims only pending / failed rows, so a held message is never
     * sent until an administrator releases it. Opening the live-site gate
     * calls this first, so mail that sat through a pause is never mass-sent
     * by accident. The dedupe key stays, so nothing is queued twice.
     * Returns the number of rows held.
     */
    public static function hold_stale($actor_id = 0) {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET status = 'held', note = %s WHERE status = 'pending' AND created_at < %s", 'Held: older than a day when the emails were opened', self::stale_cutoff()));
        $n = $n === false ? 0 : intval($n);
        if ($n > 0 && class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add('email_offer', $n . ' offer email(s) older than a day are held for a decision instead of being sent.');
        }
        return $n;
    }

    /** How many messages are waiting for the send-or-discard decision. */
    public static function held_count() {
        global $wpdb;
        return intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'held'"));
    }

    /**
     * Send the held messages after all ('held' -> 'pending', flushed at the
     * end of the request). Only while the gate is open (live()): releasing
     * them into a closed gate would just let the next gate opening hold them
     * again. Returns array(ok, message, released).
     */
    public static function release_held($actor_id = 0) {
        global $wpdb;
        if (!self::live()) {
            return array('ok' => false, 'message' => 'Send offer emails from this site first, then send the held messages.', 'released' => 0);
        }
        $n = $wpdb->query('UPDATE ' . self::table() . " SET status = 'pending', note = NULL WHERE status = 'held'");
        $n = $n === false ? 0 : intval($n);
        if ($n > 0) {
            self::flush_after_request();
            if (class_exists('TeamOversight_Log')) {
                TeamOversight_Log::add('email_offer', $n . ' held offer email(s) released for sending.');
            }
        }
        return array('ok' => true, 'message' => $n . ' held message(s) will be sent now.', 'released' => $n);
    }

    /** The note discard_held() leaves on a message (TeamOversight_Not_Selected words a discarded "not selected" email from it). */
    const DISCARD_NOTE = 'Discarded by an administrator';

    /** The note a "not selected" email skipped because it is switched off on the Emails page carries. */
    const OFF_NOTE = 'This email is switched off on the Emails page.';

    /**
     * Never send the held messages ('held' -> 'skipped', with a note).
     * A discarded "not selected" email leaves its player not told: the Not
     * selected tab and the coach's card list them again, and Send works
     * again (TeamOversight_Not_Selected::send_for_team() re-opens the row
     * with rearm_skipped()). Returns array(ok, message, discarded).
     */
    public static function discard_held($actor_id = 0) {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET status = 'skipped', note = %s WHERE status = 'held'", self::DISCARD_NOTE));
        $n = $n === false ? 0 : intval($n);
        if ($n > 0 && class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add('email_offer', $n . ' held offer email(s) discarded by an administrator.');
        }
        return array('ok' => true, 'message' => $n . ' held message(s) were discarded and will never be sent.', 'discarded' => $n);
    }

    /**
     * Re-open a "not selected" email that was never sent ('skipped': it was
     * discarded, switched off, or the verdict had changed when it was due)
     * because someone pressed Send again (1.80.0 review): ONE compare-and-set
     * 'skipped' -> 'pending' on its dedupe key, with fresh tries, the new
     * context (who pressed it now) and created_at = now (it is a new press,
     * so the gate's day-old hold judges it from now). Only the press that
     * wins this UPDATE sends, so two tabs re-open it once. Only for
     * not_selected rows. Returns true when this call re-opened it.
     */
    public static function rearm_skipped($dedupe_key, array $context) {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'pending', tries = 0, claimed_at = NULL, sent_at = NULL, note = %s, created_at = %s, context = %s WHERE dedupe_key = %s AND event = 'not_selected' AND status = 'skipped'",
            'Pressed again after it was not sent', current_time('mysql'), (string) wp_json_encode($context), (string) $dedupe_key
        ));
        if ($n === 1) {
            self::flush_after_request();
            return true;
        }
        return false;
    }

    /**
     * One message's outbox row by its dedupe key (offer:12, not_selected:34:SL1M,
     * coach_overdue:12:reply ...): array(id, event, offer_id, application_id,
     * status, tries, note, created_at, sent_at) or null when it was never
     * queued. status: pending, sending, sent, failed, skipped, unknown, held.
     */
    public static function status_for_key($dedupe_key) {
        $rows = self::status_for_keys(array($dedupe_key));
        return isset($rows[(string) $dedupe_key]) ? $rows[(string) $dedupe_key] : null;
    }

    /** Several messages at once (one query): dedupe key => the row status_for_key() returns. Keys never queued are absent. */
    public static function status_for_keys(array $dedupe_keys) {
        global $wpdb;
        $keys = array_values(array_unique(array_filter(array_map('strval', $dedupe_keys))));
        $out = array();
        if (empty($keys)) {
            return $out;
        }
        $in = implode(',', array_map(function ($k) {
            return "'" . esc_sql($k) . "'";
        }, $keys));
        $rows = $wpdb->get_results('SELECT id, dedupe_key, event, offer_id, application_id, status, tries, note, created_at, sent_at FROM ' . self::table() . " WHERE dedupe_key IN ($in)", ARRAY_A);
        foreach ((array) $rows as $row) {
            $out[$row['dedupe_key']] = array(
                'id' => intval($row['id']), 'event' => $row['event'], 'offer_id' => intval($row['offer_id']), 'application_id' => intval($row['application_id']),
                'status' => $row['status'], 'tries' => intval($row['tries']), 'note' => (string) $row['note'], 'created_at' => $row['created_at'], 'sent_at' => (string) $row['sent_at'],
            );
        }
        return $out;
    }

    /**
     * The newest message of each kind for some offers (the coach card's
     * "Offer approved - email sending" hint): offer id => event => status.
     * One query. An offer with nothing queued is absent.
     */
    public static function status_for_offers(array $offer_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $offer_ids))));
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $rows = $wpdb->get_results('SELECT offer_id, event, status FROM ' . self::table() . ' WHERE offer_id IN (' . implode(',', $ids) . ') ORDER BY id');
        foreach ((array) $rows as $row) {
            $out[intval($row->offer_id)][$row->event] = $row->status;
        }
        return $out;
    }

    /**
     * The messages a person has to look at, newest first (the Offers admin
     * page's status strip, each with a Resend button that needs the row id):
     * status 'unknown' (a send that may or may not have gone out) and
     * 'failed' with its retries used up. A list of array(id, event,
     * offer_id, application_id, status, tries, note, created_at).
     */
    public static function problem_rows($limit = 50) {
        global $wpdb;
        $limit = max(1, min(500, intval($limit)));
        $rows = $wpdb->get_results('SELECT id, event, offer_id, application_id, status, tries, note, created_at FROM ' . self::table() . " WHERE status = 'unknown' OR (status = 'failed' AND tries >= 3) ORDER BY id DESC LIMIT $limit", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'id' => intval($row['id']), 'event' => $row['event'], 'offer_id' => intval($row['offer_id']), 'application_id' => intval($row['application_id']),
                'status' => $row['status'], 'tries' => intval($row['tries']), 'note' => (string) $row['note'], 'created_at' => $row['created_at'],
            );
        }
        return $out;
    }

    // ---- flushing ----

    private static $flush_hooked = false;

    /** Send what was queued once this request ends (so a handful of messages go straight out); the daily job is the backstop. */
    private static function flush_after_request() {
        if (self::$flush_hooked) {
            return;
        }
        self::$flush_hooked = true;
        add_action('shutdown', array(__CLASS__, 'flush_on_shutdown'));
    }

    public static function flush_on_shutdown() {
        try {
            // Whoever pressed the button gets their page first; the sending carries on behind it.
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request') && !(defined('DOING_CRON') && DOING_CRON)) {
                ignore_user_abort(true);
                @fastcgi_finish_request();
            }
            self::flush_email_queue();
        } catch (\Throwable $e) {
            // A send problem never breaks the request that queued the message.
        }
    }

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        // The single event that drains a long outbox after a flush ran out of time.
        add_action(self::QUEUE_HOOK, array(__CLASS__, 'flush_email_queue'));
        // Opening the live-site gate sends what waited at the closed gate (shortly, in its own request).
        add_action('update_option_' . TeamOversight_Offers::EMAILS_LIVE_OPTION, array(__CLASS__, 'gate_option_changed'), 10, 2);
        add_action('add_option_' . TeamOversight_Offers::EMAILS_LIVE_OPTION, array(__CLASS__, 'gate_option_added'), 10, 2);
    }

    /** update_option hook for the gate: when it now holds a fingerprint, drain the outbox soon. */
    public static function gate_option_changed($old_value, $value) {
        if (is_array($value) && !empty($value['fingerprint'])) {
            self::schedule_flush(10);
        }
    }

    /** add_option hook for the gate (the very first time it is opened). */
    public static function gate_option_added($option, $value) {
        self::gate_option_changed(null, $value);
    }

    /** Make sure a flush runs in about $seconds (one event, never a pile of them). */
    private static function schedule_flush($seconds) {
        if (!wp_next_scheduled(self::QUEUE_HOOK)) {
            wp_schedule_single_event(time() + max(1, intval($seconds)), self::QUEUE_HOOK);
        }
    }

    // ------------------------------------------------------------------
    // The wording: nine emails, each editable
    // ------------------------------------------------------------------

    /** The sign-off the player-facing emails share (the coach is the voice). */
    private static function sign_off() {
        return "Questions? Just reply to this email and it comes straight to me.\n\n"
            . "{coach_name}\n{team} coach";
    }

    /**
     * The sign-off of "not selected": the same coach voice as sign_off(), with
     * its sentences from the wording registry so it still reads right when
     * the team has no coach on file ({reply_line} and {coach_title} change,
     * {coach_name} becomes the team's coaching team and {coach_title} is
     * left out). With a coach named it reads exactly like sign_off().
     */
    private static function not_selected_sign_off() {
        return "{reply_line}\n\n{coach_name}\n{coach_title}";
    }

    /** The thank-you every player-facing offer email opens with. */
    private static function thanks() {
        return "Thanks for coming to trials. It's never an easy process with the numbers we get, and we appreciate the time and effort you put in.";
    }

    /** The line that points at the Trials & Team Info page. */
    private static function training_line() {
        return "Training times and the season calendar are on the {season} Trials & Team Info page: {training_link}";
    }

    /**
     * The emails with their built-in wording (TeamOversight_Email's format;
     * placeholders are the keys of vars_from_facts(), blocks {offer} {terms}
     * {button} and, for "not selected", {next_steps}). Keys are wording keys,
     * not outbox types: the reminder has two (one for an unanswered offer, one
     * for an accepted offer whose deposit is still due), picked at send time
     * from the offer's state. 'switch' = the email has an on/off switch (the
     * offer, its reminders and the withdrawal are the process, so they have
     * none; whether anything is SENT is the live-site gate's decision).
     */
    public static function get_events() {
        $offer_blocks = array('offer', 'terms', 'button');
        return array(
            'offer' => array(
                'label' => 'Offer',
                'type' => 'offer',
                'switch' => false,
                'when' => 'The committee approves an offer.',
                'who' => 'To the player\'s account email. Reply-To the head coach who asked for the offer, else the team\'s first head coach on the roster (else its first assistant coach; the trial applications address when the team has no coach on file). An assistant coach or committee member who asked is not the Reply-To. Cc every coach and assistant coach of the team, including the one replies go to.',
                'program' => 'Offers · {season}',
                'subject' => 'Your {offer_name} to join {team} for {season}',
                'heading' => 'Your {offer_name} to join {team}',
                'preheader' => 'Accept by {reply_by} and pay your deposit by {deposit_due} to lock in your place.',
                'button' => 'See your offer',
                'why' => 'You\'re getting this because the {team} coaches have offered you a place.',
                'blocks' => array('offer', 'terms', 'extras', 'button'),
                'required' => array('offer' => 'the box with your fee, deposit and dates would be missing.', 'terms' => 'the commitments and deposit policy would be missing.', 'button' => 'there would be no button to the offer page.'),
                'body' => "Hi {first_name},\n\n"
                    . self::thanks() . "\n\n"
                    . "The coaching panel would love you to join {team} for the {season} season.\n\n"
                    . "{offer}\n\n"
                    . "{early_note}\n\n"
                    . "{button:See your offer}\n\n"
                    . "Your offer page shows your fee and what you'll need to do before the season, including the uniform. You can accept or decline the offer there. " . self::training_line() . "\n\n"
                    . "{extras}\n\n"
                    . "## Locking in your place\n"
                    . "Accept by {reply_by}. Then pay your deposit of {deposit} by {deposit_due} to lock in your place. It's paid in one payment and comes off your season fee. If it isn't paid by then, your place may be offered to someone else. Apart from the deposit, your fee won't be due for a while, and you'll be able to pay it off gradually through the season.\n\n"
                    . "{terms}\n\n"
                    . self::sign_off(),
            ),
            'reminder' => array(
                'label' => 'Reminder (no reply yet)',
                'type' => 'reminder',
                'switch' => false,
                'when' => 'A coach or administrator presses Remind on an offer the player has not answered.',
                'who' => 'The same as the offer.',
                'program' => 'Offers · {season}',
                'subject' => 'Reminder: Your {offer_name} to join {team} for {season}',
                'heading' => 'A reminder about your {offer_name} to join {team}',
                'preheader' => '{reply_sentence}',
                'button' => 'See your offer',
                'why' => 'You\'re getting this because the {team} coaches have offered you a place.',
                'blocks' => $offer_blocks,
                'required' => array('offer' => 'the box with your fee, deposit and dates would be missing.', 'button' => 'there would be no button to the offer page.'),
                'body' => "Hi {first_name},\n\n"
                    . "This is a reminder about your {offer_name} to join {team} for the {season} season. We haven't heard from you yet, and we'd love to know either way.\n\n"
                    . "{offer}\n\n"
                    . "{early_note}\n\n"
                    . "{button:See your offer}\n\n"
                    . "You can accept or decline on your offer page. " . self::training_line() . "\n\n"
                    . "## Locking in your place\n"
                    . "{reply_sentence} {deposit_sentence} It's paid in one payment and comes off your season fee. If it isn't paid in time, your place may be offered to someone else. Apart from the deposit, your fee won't be due for a while, and you'll be able to pay it off gradually through the season.\n\n"
                    . "{terms}\n\n"
                    . self::sign_off(),
            ),
            'reminder_accepted' => array(
                'label' => 'Reminder (deposit still due)',
                'type' => 'reminder',
                'switch' => false,
                'when' => 'A coach or administrator presses Remind on an offer the player has accepted but not finished paying the deposit for.',
                'who' => 'The same as the offer.',
                'program' => 'Offers · {season}',
                'subject' => 'Reminder: Your deposit for {team} in {season}',
                'heading' => 'A reminder about your deposit',
                'preheader' => '{deposit_sentence}',
                'button' => 'See your offer',
                'why' => 'You\'re getting this because the {team} coaches have offered you a place.',
                'blocks' => $offer_blocks,
                'required' => array('offer' => 'the box with your fee, deposit and dates would be missing.', 'button' => 'there would be no button to the offer page.'),
                'body' => "Hi {first_name},\n\n"
                    . "This is a reminder about your place in {team} for the {season} season. You've accepted the {offer_name}, but your deposit isn't complete yet.\n\n"
                    . "{offer}\n\n"
                    . "{early_note}\n\n"
                    . "{button:See your offer}\n\n"
                    . "## Your deposit\n"
                    . "You've accepted this offer. {deposit_sentence} It's paid in one payment and comes off your season fee. If it isn't paid in time, your place may be offered to someone else. Apart from the deposit, your fee won't be due for a while, and you'll be able to pay it off gradually through the season.\n\n"
                    . "{terms}\n\n"
                    . self::sign_off(),
            ),
            'withdrawn' => array(
                'label' => 'Offer withdrawn',
                'type' => 'withdrawn',
                'switch' => false,
                'when' => 'A coach or administrator withdraws an offer that was sent.',
                'who' => 'To the player. Reply-To the team\'s coach. Cc all the team\'s coaches.',
                'program' => 'Offers · {season}',
                'subject' => 'Your {team} {offer_name} has been withdrawn',
                'heading' => 'Your {offer_name} has been withdrawn',
                'preheader' => 'The {season} {offer_name} to join {team} has been withdrawn.',
                'button' => 'See your offer',
                'why' => 'You\'re getting this because of your {team} offer.',
                'blocks' => array('button'),
                'required' => array(),
                'body' => "Hi {first_name},\n\n"
                    . "We're sorry to let you know that the {season} {offer_name} to join {team} has been withdrawn.\n\n"
                    . "{deposit_note}\n\n"
                    . "If you have any questions, just reply to this email and it comes to {coach_name}.\n\n"
                    . "{coach_name}\n{team} coach",
            ),
            'locked_in' => array(
                'label' => 'You\'re locked in',
                'type' => 'locked_in',
                'switch' => true,
                'when' => 'The player\'s deposit is complete and they are placed on the team.',
                'who' => 'To the player. Reply-To the team\'s coach. Cc all the team\'s coaches.',
                'program' => 'Offers · {season}',
                'subject' => 'You\'re locked in for {team}, {season}',
                'heading' => 'You\'re locked in',
                'preheader' => 'Your deposit is complete. Welcome to {team}.',
                'button' => 'See your offer',
                'why' => 'You\'re getting this because of your {team} offer.',
                'blocks' => array('offer', 'extras', 'button'),
                'required' => array(),
                'body' => "Hi {first_name},\n\n"
                    . "Your deposit is complete, so your place in {team} for the {season} season is locked in. Welcome to the team!\n\n"
                    . "{offer}\n\n"
                    . "{button:See your offer}\n\n"
                    . "Your deposit comes off your season fee, and the rest is paid as it falls due. You can see what's coming up on your Player Checklist. " . self::training_line() . "\n\n"
                    . "{extras}\n\n"
                    . self::sign_off(),
            ),
            'coach_accepted' => array(
                'label' => 'Coaches: player accepted',
                'type' => 'coach_accepted',
                'switch' => true,
                'when' => 'A player accepts an offer.',
                'who' => 'To the team\'s head coach and assistant coaches (read from the roster when it is sent). Not sent to the player. No Reply-To, no Cc.',
                'program' => 'Offers · {season}',
                'subject' => '{player_name} accepted the {team} {offer_name}',
                'heading' => '{player_name} accepted the {offer_name}',
                'preheader' => '{player_name} (trial #{trial_number}) accepted the {role} {offer_name} for {team}.',
                'button' => 'Open the coach portal',
                'why' => 'You\'re getting this because you coach {team}.',
                'blocks' => array('button'),
                'required' => array(),
                'body' => "{player_name} (trial #{trial_number}) has accepted the {role} {offer_name} for {team}. {deposit_status}\n\n"
                    . "{button:Open the coach portal}",
            ),
            'coach_declined' => array(
                'label' => 'Coaches: player declined',
                'type' => 'coach_declined',
                'switch' => true,
                'when' => 'A player declines an offer (their reason is included when they gave one).',
                'who' => 'To the team\'s head coach and assistant coaches. Not sent to the player. No Reply-To, no Cc.',
                'program' => 'Offers · {season}',
                'subject' => '{player_name} declined the {team} {offer_name}',
                'heading' => '{player_name} declined the {offer_name}',
                'preheader' => '{player_name} (trial #{trial_number}) declined the {role} {offer_name} for {team}.',
                'button' => 'Open the coach portal',
                'why' => 'You\'re getting this because you coach {team}.',
                'blocks' => array('button'),
                'required' => array(),
                'body' => "{player_name} (trial #{trial_number}) has declined the {role} {offer_name} for {team}.\n\n"
                    . "{reason_line}\n\n"
                    . "{deposit_line}\n\n"
                    . "{button:Open the coach portal}",
            ),
            'coach_overdue' => array(
                'label' => 'Coaches: deadline not met',
                'type' => 'coach_overdue',
                'switch' => true,
                'when' => 'The daily check finds an offer past its reply-by or deposit date. One email per missed deadline.',
                'who' => 'To the team\'s head coach and assistant coaches. Not sent to the player. No Reply-To, no Cc.',
                'program' => 'Offers · {season}',
                'subject' => 'Deadline not met: {player_name} ({team})',
                'heading' => 'Deadline not met',
                'preheader' => '{player_name} {overdue_what}.',
                'button' => 'Open the coach portal',
                'why' => 'You\'re getting this because you coach {team}.',
                'blocks' => array('button'),
                'required' => array(),
                'body' => "{player_name} (trial #{trial_number}) {overdue_what}. It was due {overdue_date}.\n\n"
                    . "Open the coach portal to send a reminder, give them 7 more days or move them to Declined. Nothing happens until you choose.\n\n"
                    . "{button:Open the coach portal}",
            ),
            'not_selected' => array(
                'label' => 'Not selected',
                'type' => 'not_selected',
                'switch' => true,
                // 1.80.0 (club decision, 28 Oct 2026): only when someone presses the button, about one team, addressed like the offer emails.
                'when' => 'Only when someone presses Send "not selected" email: a coach (or committee with edit access) on a Rejected player\'s card in the coach portal, or an administrator on the Offers page, Not selected tab. About ONE team; once per player and team. It does not wait for the player\'s other teams and never mentions their decisions. No committee approval.',
                'switch_note' => 'While this is unticked the Send "not selected" email buttons are greyed out (the coach portal says the club has switched these emails off), and an email pressed before it was unticked and still waiting is skipped: that player goes back to not told yet, and can be sent it once this is ticked again.',
                'who' => 'To the player\'s account email, from the club\'s sender like every offer email. Reply-To the coach who pressed Send when they are one of this team\'s coaches or assistant coaches; when someone else pressed it (the committee, or an administrator on the Offers page), the team\'s head coach; with no coach on file, the trial applications address. Cc every coach and assistant coach of this team, including the one replies go to (read from the roster when it is sent; never the coaches of the player\'s other teams). Signed by the Reply-To coach, or by the team\'s coaching team when no coach is on file.',
                'program' => 'Trials · {season}',
                'subject' => 'Your {season} MURVC trial: {team}',
                'heading' => 'Thanks for trialling',
                'preheader' => 'Thanks for coming to trials.',
                'button' => 'Edit my application',
                'why' => 'You\'re getting this because you applied to trial with the Renegades.',
                'blocks' => array('next_steps', 'button'),
                'required' => array('next_steps' => 'the suggestions for what to do next would be missing.'),
                'body' => "Hi {first_name},\n\n"
                    . self::thanks() . "\n\n"
                    . "We weren't able to offer you a place in {team} this season.\n\n"
                    . "{other_teams}\n\n"
                    . "{next_steps}\n\n"
                    . "We hope to see you around the club.\n\n"
                    . self::not_selected_sign_off(),
            ),
        );
    }

    /**
     * Saved settings merged over the defaults: every email on unless
     * switched off (only those with a switch can be); an empty subject,
     * heading or body means "use the built-in text", so improvements to the
     * built-in wording reach anyone who never customised it.
     */
    public static function get_settings() {
        $switches = get_option(self::OPTION, array());
        $switches = is_array($switches) ? $switches : array();
        $saved = get_option(self::TEXT_OPTION, array());
        $saved = is_array($saved) ? $saved : array();
        $settings = array();
        foreach (self::get_events() as $key => $event) {
            $switch = isset($switches[$key]) && is_array($switches[$key]) ? $switches[$key] : array();
            $mine = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();
            $setting = array(
                'enabled' => (!empty($event['switch']) && isset($switch['enabled'])) ? !empty($switch['enabled']) : true,
                'customised' => false,
            );
            foreach (array('subject', 'heading', 'body') as $field) {
                $text = isset($mine[$field]) ? trim((string) $mine[$field]) : '';
                $setting[$field] = $text !== '' ? $text : $event[$field];
                if ($text !== '') {
                    $setting['customised'] = true;
                }
            }
            $settings[$key] = $setting;
        }
        return $settings;
    }

    /**
     * Which wording an outbox message uses for an offer in its CURRENT
     * state: the outbox type, except a reminder, which has one wording for
     * an unanswered offer and one for an accepted offer. '' when the type
     * is unknown.
     */
    public static function wording_key($type, $offer = null) {
        if ($type === 'reminder') {
            return ($offer && $offer->state === 'accepted') ? 'reminder_accepted' : 'reminder';
        }
        return isset(self::get_events()[$type]) ? $type : '';
    }

    /** Things in an email's text that will not come out as meant (the placeholders this email can fill, its blocks, the blocks it needs). */
    public static function check_body($key, $body) {
        $events = self::get_events();
        if (!isset($events[$key])) {
            return array();
        }
        return TeamOversight_Email::check_text($body, array_keys(self::sample_vars($key)), $events[$key]['blocks'], $events[$key]['required']);
    }

    /**
     * Build one email from the saved wording. $vars: placeholder => text
     * ('{team}' => ...), $blocks: 'offer' / 'terms' / 'next_steps' =>
     * array(html, text). Returns array(subject, email) with email =
     * array(html, text, id). A paragraph that fills to nothing (a sentence
     * placeholder with no sentence) is dropped, so it leaves no gap.
     */
    public static function render_wording($key, array $vars, array $blocks = array()) {
        $events = self::get_events();
        $settings = self::get_settings();
        if (!isset($settings[$key])) {
            return array('', array('html' => '', 'text' => '', 'id' => ''));
        }
        $setting = $settings[$key];
        $event = $events[$key];
        $link = isset($vars['{link}']) ? (string) $vars['{link}'] : '';
        $email = TeamOversight_Email::render(array(
            'program' => TeamOversight_Email::fill($event['program'], $vars),
            'heading' => $setting['heading'],
            'body' => self::tidy_body($setting['body'], $vars),
            'vars' => $vars,
            'blocks' => $blocks,
            'button' => $link !== '' ? array('url' => $link, 'label' => $event['button']) : null,
            'preheader' => $event['preheader'],
            'why' => TeamOversight_Email::fill($event['why'], $vars),
        ));
        return array(TeamOversight_Email::fill($setting['subject'], $vars), $email);
    }

    /**
     * Tidy the wording before it is drawn: a "page: {training_link}" with no
     * address to link becomes a plain full stop, a paragraph that comes out
     * empty once its placeholders are filled is removed, and so is a line of
     * a longer paragraph that comes out empty (the sign-off's {coach_title}
     * when no coach can be named), so neither leaves a gap.
     */
    private static function tidy_body($body, array $vars) {
        $plain = array();
        foreach ($vars as $token => $value) {
            $plain[$token] = trim(preg_replace('/\s+/u', ' ', TeamOversight_Email::decode($value)));
        }
        if (isset($plain['{training_link}']) && $plain['{training_link}'] === '') {
            $body = preg_replace('/\s*:\s*\{training_link\}/i', '.', (string) $body);
        }
        $keep = array();
        foreach (TeamOversight_Email::paragraphs($body) as $paragraph) {
            if (trim(strtr($paragraph, $plain)) === '') {
                continue;
            }
            $lines = array();
            foreach (explode("\n", $paragraph) as $line) {
                if (trim($line) !== '' && trim(strtr($line, $plain)) === '') {
                    continue;
                }
                $lines[] = $line;
            }
            $keep[] = implode("\n", $lines);
        }
        return implode("\n\n", $keep);
    }

    // ------------------------------------------------------------------
    // Facts, placeholders and blocks
    // ------------------------------------------------------------------

    private static $db = null;

    private static function database() {
        if (!(self::$db instanceof TeamOversight_Database)) {
            self::$db = new TeamOversight_Database();
        }
        return self::$db;
    }

    /** "15 Nov 2026" from 'Y-m-d', '' for anything else. */
    private static function date_words($ymd) {
        $valid = TeamOversight_Fee_Timeline::parse_date((string) $ymd);
        return $valid === null ? '' : date('j M Y', strtotime($valid));
    }

    /** Money from cents. */
    private static function cents_words($cents) {
        return TeamOversight_Offers::money(intval($cents) / 100);
    }

    /**
     * The facts one offer email is written from, as plain strings and
     * numbers, whether they come from a real offer or from the sample.
     * Every placeholder is derived from these by vars_from_facts(), so a
     * preview and a real send fill exactly the same placeholders.
     */
    private static function blank_facts() {
        return array(
            'state' => 'sent', 'name' => '', 'first_name' => '', 'trial_number' => '', 'team' => '', 'team_code' => '', 'season' => '',
            'role' => 'playing_member', 'fee' => '', 'deposit' => '', 'reply_by' => '', 'deposit_due' => '', 'paid_cents' => 0, 'left_cents' => 0,
            'coach_name' => '', 'coach_email' => '', 'link' => '', 'training_url' => '', 'reason' => '', 'had_deposit' => false,
            'held_cents' => 0, 'kind' => '', 'overdue_date' => '', 'rejected_teams' => '', 'multi' => false, 'placed' => false,
            'early' => false, 'early_note' => '', 'fee_limit' => '',
            'extras' => array(),
        );
    }

    /** Every placeholder an email can use, from the facts. */
    public static function vars_from_facts(array $f) {
        $f = array_merge(self::blank_facts(), $f);
        $today = current_time('Y-m-d');
        $reply = self::date_words($f['reply_by']);
        $due = self::date_words($f['deposit_due']);
        $reply_late = $f['reply_by'] !== '' && $f['reply_by'] < $today;
        $due_late = $f['deposit_due'] !== '' && $f['deposit_due'] < $today;
        $deposit = $f['deposit'];

        // One sentence each, so the wording reads right whether the date is still ahead or already behind.
        // The sentences are the administrator's (Emails -> Offers and selection -> Offer wording).
        $w = function ($key, array $more = array()) use ($reply, $due, $deposit) {
            return TeamOversight_Offers::wording($key, array_merge(array('{reply_by}' => $reply, '{deposit_due}' => $due, '{deposit}' => $deposit), $more));
        };
        $reply_sentence = '';
        $deposit_sentence = '';
        if ($f['state'] === 'sent') {
            $reply_sentence = $reply === '' ? '' : ($reply_late ? $w('email_reply_late') : $w('email_reply_future'));
            $deposit_sentence = $due === '' ? '' : ($due_late ? $w('email_deposit_late') : $w('email_deposit_future'));
        } elseif ($f['state'] === 'accepted') {
            $deposit_sentence = $due === '' ? '' : ($due_late ? $w('email_accepted_late') : $w('email_accepted_future'));
        }

        // What the coaches are told about the deposit when the player accepts.
        $deposit_status = '';
        if ($f['state'] === 'locked_in') {
            $deposit_status = $w('email_status_locked');
        } elseif ($f['state'] === 'accepted' && $due !== '') {
            $deposit_status = $w('email_status_due');
        }

        $reason = trim((string) $f['reason']);
        $held = self::cents_words($f['held_cents']);
        // An Early Offer (quoted on the upper limit, sent before fees were out) is called one everywhere; TeamOversight_Offers::is_early() decides and the facts carry its answer.
        $offer_name = !empty($f['early']) ? 'Early Offer' : 'offer';

        return array(
            '{name}' => $f['name'],
            '{player_name}' => $f['name'],
            '{first_name}' => $f['first_name'],
            '{trial_number}' => $f['trial_number'],
            '{team}' => $f['team'],
            '{team_code}' => $f['team_code'],
            '{season}' => $f['season'],
            '{role}' => $f['role'] === 'training_only' ? 'Training Only' : 'playing',
            '{fee}' => $f['fee'],
            '{fee_limit}' => (string) $f['fee_limit'],
            '{offer_name}' => $offer_name,
            '{early_note}' => (string) $f['early_note'],
            '{deposit}' => $deposit,
            '{reply_by}' => $reply,
            '{deposit_due}' => $due,
            '{coach_name}' => $f['coach_name'],
            '{coach_email}' => $f['coach_email'],
            '{link}' => $f['link'],
            '{training_link}' => $f['training_url'],
            '{reply_sentence}' => $reply_sentence,
            '{deposit_sentence}' => $deposit_sentence,
            '{deposit_status}' => $deposit_status,
            '{reason}' => $reason,
            '{reason_line}' => $reason !== '' ? $w('email_reason_line', array('{reason}' => $reason)) : '',
            '{deposit_line}' => !empty($f['had_deposit']) ? TeamOversight_Offers::wording('deposit_kept_note') : '',
            '{held}' => $held,
            '{deposit_note}' => intval($f['held_cents']) > 0 ? TeamOversight_Offers::wording('deposit_back_note', array('{held}' => $held)) : '',
            '{overdue_what}' => TeamOversight_Offers::wording($f['kind'] === 'deposit' ? 'email_overdue_deposit' : 'email_overdue_reply', array('{team}' => $f['team'], '{offer_name}' => $offer_name)),
            '{overdue_date}' => self::date_words($f['overdue_date']),
        );
    }

    /**
     * The placeholders of "not selected" (it has its own, shorter set). Since
     * 1.80.0 it is about ONE team: {team} is that team, {rejected_teams} is
     * the same name (kept so wording saved before 1.80.0 still reads right),
     * and {other_teams} is the registry's ns_other_teams sentence when the
     * player applied for at least one other team, else empty (the paragraph
     * then drops out). Nothing here can name another team's decision.
     *
     * The sign-off (the coach is the voice, as in the offer emails):
     * {coach_name} is the Reply-To coach's name ($f['coach_name'], read from
     * the roster at send time); with no coach on file it is the registry's
     * ns_sign_team ("The <team> coaching team"), {coach_title} (ns_sign_title,
     * "<team> coach") is then empty so its line drops, and {reply_line} is
     * ns_sign_reply_team instead of ns_sign_reply.
     */
    private static function not_selected_vars(array $f) {
        $f = array_merge(self::blank_facts(), $f);
        $team_vars = array('{team}' => $f['team']);
        $named = trim((string) $f['coach_name']) !== '';
        return array(
            '{name}' => $f['name'],
            '{player_name}' => $f['name'],
            '{first_name}' => $f['first_name'],
            '{trial_number}' => $f['trial_number'],
            '{season}' => $f['season'],
            '{team}' => $f['team'],
            '{rejected_teams}' => $f['team'],
            // A player who already holds a place this season (an offer they accepted) isn't told they'll hear about their other applications.
            '{other_teams}' => !empty($f['multi']) ? TeamOversight_Offers::wording(!empty($f['placed']) ? 'ns_other_teams_placed' : 'ns_other_teams', $team_vars) : '',
            '{link}' => $f['link'],
            '{reply_line}' => TeamOversight_Offers::wording($named ? 'ns_sign_reply' : 'ns_sign_reply_team', $team_vars),
            '{coach_name}' => $named ? (string) $f['coach_name'] : TeamOversight_Offers::wording('ns_sign_team', $team_vars),
            '{coach_title}' => $named ? TeamOversight_Offers::wording('ns_sign_title', $team_vars) : '',
        );
    }

    /** The {offer} block: team, role, season, fee, deposit and the dates that still matter. */
    public static function offer_block(array $f) {
        $f = array_merge(self::blank_facts(), $f);
        $rows = array(
            array('Role', $f['role'] === 'training_only' ? 'Training Only' : 'Playing'),
            array('Season', $f['season']),
        );
        if ($f['fee'] !== '') {
            $rows[] = array('Fee', $f['fee']);
        }
        if ($f['state'] === 'locked_in') {
            $rows[] = array('Deposit', $f['deposit'] . ', paid');
        } else {
            $rows[] = array('Deposit', $f['deposit']);
            if ($f['state'] === 'accepted' && intval($f['paid_cents']) > 0) {
                $rows[] = array('Paid so far', self::cents_words($f['paid_cents']));
                $rows[] = array('Still to pay', self::cents_words($f['left_cents']));
            }
            if ($f['state'] === 'sent' && self::date_words($f['reply_by']) !== '') {
                $rows[] = array('Reply by', self::date_words($f['reply_by']));
            }
            if (self::date_words($f['deposit_due']) !== '') {
                $rows[] = array('Deposit due', self::date_words($f['deposit_due']));
            }
        }
        $title = !empty($f['early']) ? 'Your Early Offer' : 'Your offer';
        $text = array($title . ': ' . $f['team']);
        foreach ($rows as $row) {
            $text[] = $row[0] . ': ' . $row[1];
        }
        return array(
            'html' => TeamOversight_Email::details($rows, $title, $f['team']),
            'text' => implode("\n", $text),
        );
    }

    /**
     * The {extras} block: the one extra training / extra games line for this
     * player (TeamOversight_Offers::extras_line(), chosen by their age at the
     * season start), with the page's name linked. Empty, and so left out
     * of the email, when the page it points to isn't published.
     */
    public static function extras_block(array $f) {
        $line = (isset($f['extras']) && is_array($f['extras'])) ? $f['extras'] : array();
        if (empty($line['html']) || empty($line['text'])) {
            return array('html' => '', 'text' => '');
        }
        // The only tag in the line is the one link TeamOversight_Offers built; it gets the email's link look.
        $html = str_replace('<a href=', '<a style="color:' . TeamOversight_Email::BLUE . ';text-decoration:underline;" href=', (string) $line['html']);
        return array('html' => TeamOversight_Email::paragraph($html), 'text' => (string) $line['text']);
    }

    /** One bulleted list, drawn so every email program keeps the bullets and the hanging indent. */
    private static function bullets_html(array $items, $linked = false) {
        $font = TeamOversight_Email::FONT;
        $rows = '';
        foreach ($items as $item) {
            $cell = 'padding:3px 0;font-family:' . $font . ';font-size:15px;line-height:1.5;color:' . TeamOversight_Email::INK . ';vertical-align:top;';
            if ($linked && is_array($item) && !empty($item['url'])) {
                $inner = '<a href="' . esc_url($item['url']) . '" style="color:' . TeamOversight_Email::BLUE . ';text-decoration:underline;">' . esc_html($item['label']) . '</a>';
            } else {
                $inner = esc_html(is_array($item) ? $item['label'] : $item);
            }
            $rows .= '<tr><td width="18" style="' . $cell . 'padding-right:8px;">•</td><td style="' . $cell . 'word-break:break-word;">' . $inner . '</td></tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>';
    }

    /** A block with the standard space under it. */
    private static function spaced_html($html) {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:0 0 16px;">' . $html . '</td></tr></table>';
    }

    /** The {terms} block: the commitments and the deposit policy, from the one source the offer page uses too. */
    public static function terms_block() {
        $html = '';
        $text = array();
        foreach (TeamOversight_Offers::terms() as $group) {
            if (empty($group['items'])) {
                continue;
            }
            $html .= TeamOversight_Email::subheading(esc_html($group['title'])) . self::spaced_html(self::bullets_html($group['items']));
            $lines = array($group['title']);
            foreach ($group['items'] as $item) {
                $lines[] = '• ' . $item;
            }
            $text[] = implode("\n", $lines);
        }
        return array('html' => $html, 'text' => implode("\n\n", $text));
    }

    /**
     * The {next_steps} block of "not selected", from
     * TeamOversight_Not_Selected::notice_content() (one team): the divisions
     * below that team they can still trial for (with a button to edit the
     * application), else the pathway (adults: the Mixed Training Program and
     * the Reds Social League; juniors: the Junior Academy, as extra
     * training). Every sentence is the wording registry's (ns_lower_intro,
     * ns_lower_outro, ns_pathway_*). Empty when there is nothing to suggest.
     */
    public static function build_next_steps(array $notice) {
        $font = TeamOversight_Email::FONT;
        $suggestions = (isset($notice['suggestions']) && is_array($notice['suggestions'])) ? $notice['suggestions'] : array();
        $pathway = (isset($notice['pathway']) && is_array($notice['pathway'])) ? $notice['pathway'] : array();
        $team_name = isset($notice['team_name']) ? (string) $notice['team_name'] : (isset($notice['rejected_teams'][0]) ? (string) $notice['rejected_teams'][0] : '');
        $team_vars = array('{team}' => $team_name !== '' ? $team_name : 'this team');
        if (!empty($suggestions) && !empty($notice['can_edit'])) {
            $labels = array();
            foreach ($suggestions as $s) {
                $labels[] = is_array($s) ? (string) $s['label'] : (string) $s;
            }
            $intro = TeamOversight_Offers::wording('ns_lower_intro', $team_vars);
            $outro = TeamOversight_Offers::wording('ns_lower_outro', $team_vars);
            $url = isset($notice['edit_url']) ? (string) $notice['edit_url'] : '';
            $html = TeamOversight_Email::paragraph(esc_html($intro), 16, null, '0 0 8px') . self::spaced_html(self::bullets_html($labels))
                . TeamOversight_Email::paragraph(esc_html($outro), 16, null, '0 0 8px')
                . ($url !== '' ? TeamOversight_Email::button('Edit my application', $url) : '');
            $text = $intro . "\n";
            foreach ($labels as $label) {
                $text .= '• ' . $label . "\n";
            }
            $text .= $outro . ($url !== '' ? "\nEdit my application: " . $url : '');
            return array('html' => $html, 'text' => $text);
        }
        if (!empty($pathway)) {
            $junior = isset($notice['pathway_kind']) && $notice['pathway_kind'] === 'junior';
            if ($junior) {
                $intro = TeamOversight_Offers::wording('ns_pathway_junior', $team_vars);
            } else {
                $intro = TeamOversight_Offers::wording(count($pathway) > 1 ? 'ns_pathway_two' : 'ns_pathway_one', $team_vars);
            }
            $html = TeamOversight_Email::paragraph(esc_html($intro), 16, null, '0 0 8px') . self::spaced_html(self::bullets_html($pathway, true));
            $text = $intro . "\n";
            foreach ($pathway as $p) {
                $text .= '• ' . $p['label'] . ': ' . $p['url'] . "\n";
            }
            return array('html' => $html, 'text' => rtrim($text));
        }
        return array('html' => '', 'text' => '');
    }

    // ------------------------------------------------------------------
    // Samples (the Emails page's previews and the test send)
    // ------------------------------------------------------------------

    /**
     * The three offer emails that read differently for an Early Offer and for
     * an offer sent after the fees were released get a second preview and a
     * second test send (variant 'real').
     */
    private static function early_variants() {
        return array('offer', 'reminder', 'reminder_accepted');
    }

    /** The previews / test sends of one email: variant key => array(preview label, test-send suffix). */
    private static function variants_for($key) {
        if ($key === 'not_selected') {
            // 1.80.0: one team per email, so it reads differently for a player who applied for one team and one who applied for several.
            return array(
                'lower' => array('Preview: applied for one team, lower divisions to try', ' (one team, lower divisions to try)'),
                'multi' => array('Preview: applied for more than one team, lower divisions to try', ' (more than one team, lower divisions to try)'),
                'adult' => array('Preview: applied for one team, adult, nothing lower to try', ' (one team, adult, nothing lower)'),
                'multi_adult' => array('Preview: applied for more than one team, adult, nothing lower to try', ' (more than one team, adult, nothing lower)'),
                'junior' => array('Preview: applied for one team, junior, nothing lower to try', ' (one team, junior, nothing lower)'),
                'multi_junior' => array('Preview: applied for more than one team, junior, nothing lower to try', ' (more than one team, junior, nothing lower)'),
                // 1.80.0 review: a player who already holds a place this season (an offer they accepted) gets no other way in.
                'multi_placed' => array('Preview: applied for more than one team, already has a place this season', ' (more than one team, already has a place)'),
                // Signed by the coach (Sam Coach) in every shape above; this one shows a team with no coach on file.
                'no_coach' => array('Preview: applied for one team, no coach on file (signed by the coaching team)', ' (no coach on file)'),
            );
        }
        if (in_array($key, self::early_variants(), true)) {
            $variants = array(
                'lower' => array('Preview: Early Offer (sent before the season fee is set)', ' (Early Offer)'),
                'real' => array('Preview: offer sent after the fees are released', ' (after fees are released)'),
            );
            // The offer email also reads differently for a player under 18 (extra training, not extra games).
            if ($key === 'offer') {
                $variants['junior'] = array('Preview: Early Offer, player under 18 (the extra training line)', ' (under 18)');
            }
            return $variants;
        }
        if ($key === 'locked_in') {
            return array(
                'lower' => array('Preview: 18 and over (the extra games line)', ''),
                'junior' => array('Preview: under 18 (the extra training line)', ' (under 18)'),
            );
        }
        return array('lower' => array('Preview', ''));
    }

    private static function sample_facts($key, $variant = '') {
        $today = current_time('Y-m-d');
        $shift = function ($days) use ($today) {
            return date('Y-m-d', strtotime($today . ' ' . ($days >= 0 ? '+' : '-') . abs($days) . ' days'));
        };
        $late = in_array($key, array('reminder', 'reminder_accepted', 'coach_overdue'), true);
        $state = $key === 'reminder_accepted' ? 'accepted' : ($key === 'locked_in' ? 'locked_in' : (in_array($key, array('coach_accepted'), true) ? 'accepted' : 'sent'));
        $offer_url = TeamOversight_Offers::offer_page_url();
        $training = TeamOversight_Offers::training_url(strval(max(2027, intval(wp_date('Y')))));
        $facts = array_merge(self::blank_facts(), array(
            'state' => $state,
            'name' => 'Alex Example',
            'first_name' => 'Alex',
            'trial_number' => '42',
            'team' => 'State League 2 Men',
            'team_code' => 'SL2M',
            'season' => strval(max(2027, intval(wp_date('Y')))),
            'role' => 'playing_member',
            'fee' => 'up to $612.00, set in January',
            'deposit' => '$61.20',
            'reply_by' => $late ? $shift(-3) : $shift(7),
            'deposit_due' => $late ? ($key === 'reminder_accepted' || $key === 'coach_overdue' ? $shift(-2) : $shift(4)) : $shift(14),
            'coach_name' => 'Sam Coach',
            'coach_email' => 'coach@example.test',
            'link' => $offer_url !== '' ? add_query_arg('offer', 123, $offer_url) : home_url('/'),
            'training_url' => $training !== '' ? $training : home_url('/'),
            'reason' => 'I have a clash with training.',
            'had_deposit' => true,
            'held_cents' => 2000,
            'kind' => $key === 'coach_overdue' ? 'reply' : 'deposit',
            'overdue_date' => $shift(-3),
        ));
        if (strpos($key, 'coach_') === 0) {
            $facts['link'] = self::coach_portal_url();
        }
        if ($key === 'not_selected') {
            // The sample was turned down by one team; the multi variants applied for another team too (never named, never judged).
            $facts['team'] = 'State League 2 Men';
            $facts['rejected_teams'] = 'State League 2 Men';
            $facts['multi'] = strpos((string) $variant, 'multi') === 0;
            $facts['placed'] = $variant === 'multi_placed';
            $facts['link'] = TeamOversight_Trial_Emails::get_form_url();
            // Signed by the sample coach, except the shape for a team with no coach on file.
            $facts['coach_name'] = $variant === 'no_coach' ? '' : 'Sam Coach';
        }
        if ($key === 'coach_declined' || $key === 'coach_accepted') {
            $facts['deposit_due'] = $shift(14);
        }
        // The sample is an Early Offer (the usual case: sent before the fees are out) unless the 'real' variant asks for an offer quoted on the real fee.
        if ($key !== 'not_selected') {
            if ($variant === 'real') {
                $facts['early'] = false;
                $facts['fee'] = '$480.00';
                $facts['deposit'] = '$48.00';
            } else {
                $facts['early'] = true;
                $facts['fee_limit'] = '$612.00';
                $facts['early_note'] = TeamOversight_Offers::early_note_text(612, 10, null, false, true);
            }
            // The extra training / extra games line: 18 and over unless the 'junior' variant asks for a player under 18.
            $facts['extras'] = TeamOversight_Offers::extras_for_kind($variant === 'junior' ? 'junior' : 'adult', true);
        }
        return $facts;
    }

    /** The placeholders (with sample values) an email can fill: the preview's and the warnings' source. */
    public static function sample_vars($key, $variant = '') {
        $facts = self::sample_facts($key, $variant);
        return $key === 'not_selected' ? self::not_selected_vars($facts) : self::vars_from_facts($facts);
    }

    /** The blocks (with sample content) an email has. */
    private static function sample_blocks($key, $variant = 'lower') {
        $events = self::get_events();
        if (!isset($events[$key])) {
            return array();
        }
        $facts = self::sample_facts($key, $variant);
        $blocks = array();
        if (in_array('offer', $events[$key]['blocks'], true)) {
            $blocks['offer'] = self::offer_block($facts);
        }
        if (in_array('terms', $events[$key]['blocks'], true)) {
            $blocks['terms'] = self::terms_block();
        }
        if (in_array('extras', $events[$key]['blocks'], true)) {
            $blocks['extras'] = self::extras_block($facts);
        }
        if (in_array('next_steps', $events[$key]['blocks'], true)) {
            $blocks['next_steps'] = self::build_next_steps(self::sample_notice($variant));
        }
        return $blocks;
    }

    /**
     * A sample notice_content() result for the shapes of "not selected"
     * (1.80.0: one team each): lower divisions to try, the adult pathway, the
     * junior pathway, each for a player who applied for one team and for one
     * who applied for more than one ('multi...'), 'multi_placed' (a player
     * who already holds a place this season: no next steps at all), and
     * 'no_coach' (lower divisions; only its sign-off differs).
     */
    private static function sample_notice($variant) {
        $variant = (string) $variant;
        $multi = strpos($variant, 'multi') === 0;
        $shape = $multi ? (string) substr($variant, 6) : $variant;
        // The no-coach shape differs only in its sign-off: lower divisions to try, like the first.
        $shape = ($shape === '' || $shape === 'no_coach') ? 'lower' : $shape;
        $notice = array(
            'application_id' => 0,
            'team' => 'SL2M',
            'team_name' => 'State League 2 Men',
            'rejected_teams' => array('State League 2 Men'),
            'applied' => true,
            'multi' => $multi,
            'placed' => $shape === 'placed',
            'suggestions' => array(),
            'eligible' => array(),
            'can_edit' => true,
            'edit_url' => TeamOversight_Trial_Emails::get_form_url(),
            'pathway_kind' => 'adult',
            'pathway' => array(),
        );
        if ($shape === 'lower') {
            $notice['suggestions'] = array(
                array('label' => 'State League 3 Men (Red or White)', 'level' => 6, 'codes' => array()),
                array('label' => 'State League 3 Mixed', 'level' => 6, 'codes' => array()),
            );
            return $notice;
        }
        if ($shape === 'placed') {
            return $notice;
        }
        $kind = $shape === 'junior' ? 'junior' : 'adult';
        $notice['pathway_kind'] = $kind;
        $notice['pathway'] = (class_exists('TeamOversight_Not_Selected') && method_exists('TeamOversight_Not_Selected', 'pathway_links')) ? TeamOversight_Not_Selected::pathway_links($kind) : array();
        if (empty($notice['pathway'])) {
            $notice['pathway'] = $kind === 'junior'
                ? array(array('label' => 'Junior Academy', 'url' => home_url('/')))
                : array(array('label' => 'Mixed Training Program', 'url' => home_url('/')), array('label' => 'Reds Social League', 'url' => home_url('/')));
        }
        return $notice;
    }

    // ------------------------------------------------------------------
    // Who gets it
    // ------------------------------------------------------------------

    /** The shared From header (the club's), without the fee reminders' Reply-To: here the Reply-To is set per email. */
    private static function base_headers() {
        $headers = array();
        foreach (TeamOversight_Payments::get_email_headers() as $header) {
            if (stripos($header, 'Reply-To:') !== 0) {
                $headers[] = $header;
            }
        }
        return $headers;
    }

    /** Valid, de-duplicated addresses (any case), without $exclude. */
    private static function clean_addresses(array $list, array $exclude = array()) {
        $skip = array();
        foreach ($exclude as $e) {
            $skip[strtolower((string) $e)] = true;
        }
        $out = array();
        foreach ($list as $address) {
            $address = sanitize_email((string) $address);
            if ($address === '' || !is_email($address) || isset($skip[strtolower($address)])) {
                continue;
            }
            $skip[strtolower($address)] = true;
            $out[] = $address;
        }
        return $out;
    }

    /** The coaches copied on a player email: the primary coach (who replies go to) and every other coach and assistant of the team. */
    private static function copied_coaches(array $contacts) {
        $people = isset($contacts['others']) && is_array($contacts['others']) ? $contacts['others'] : array();
        if (!empty($contacts['primary']) && is_array($contacts['primary'])) {
            array_unshift($people, $contacts['primary']);
        }
        return $people;
    }

    /** Where a coach notice goes when the team has no coach on file: the trial applications address, else the fee reminders' Reply-To. */
    private static function fallback_address() {
        $found = array();
        // The club's contact address for offers; unset, it is the trial applications address, as before.
        $found[] = TeamOversight_Offers::club_contact();
        $found[] = get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION);
        $clean = self::clean_addresses($found);
        return empty($clean) ? '' : $clean[0];
    }

    /**
     * The Reply-To of a "not selected" email when the team has no coach on
     * file: the trial applications address, else the fee reminders' Reply-To
     * (the same choice TeamOversight_Trial_Emails::get_headers() makes, which
     * is what this email used before it was addressed like the offers); ''
     * when neither is set.
     */
    private static function trial_reply_to() {
        $clean = self::clean_addresses(array(TeamOversight_Trial_Emails::get_reply_to(), (string) get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION)));
        return empty($clean) ? '' : $clean[0];
    }

    /** The page carrying a shortcode, found by its text so a moved page never breaks a link; '' when there is none. */
    private static function page_url_for($shortcode) {
        global $wpdb;
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page', 'post') AND post_content LIKE %s ORDER BY (post_type = 'page') DESC, ID LIMIT 1",
            '%' . $wpdb->esc_like($shortcode) . '%'
        ));
        return $page_id ? (string) get_permalink($page_id) : '';
    }

    /** The coach portal, for the buttons in the coaches' emails. */
    public static function coach_portal_url() {
        $url = self::page_url_for('[team_coach_portal');
        return $url !== '' ? $url : home_url('/');
    }

    private static function teams_config($season) {
        $config = self::database()->get_teams_config((string) $season);
        return is_array($config) ? $config : array();
    }

    /** The facts for a real offer at the moment of sending. */
    private static function offer_facts($offer, $user, array $contacts, $type, array $ctx) {
        global $wpdb;
        $config = self::teams_config($offer->season);
        $team = isset($config[$offer->team]['name']) ? (string) $config[$offer->team]['name'] : (string) $offer->team;
        $name = $user ? (string) $user->display_name : (string) $offer->name;
        $trial = intval($offer->application_id) > 0
            ? $wpdb->get_var($wpdb->prepare("SELECT trial_number FROM {$wpdb->prefix}trial_applications WHERE id = %d", intval($offer->application_id)))
            : null;
        $deadline = TeamOversight_Offers::deadline_status($offer);
        $paid = 0;
        $left = 0;
        if ($offer->state === 'accepted') {
            $paid = intval(TeamOversight_Offers::paid_toward_deposit_cents($offer));
            $left = intval(TeamOversight_Offers::remaining_cents($offer));
        }
        $coach_side = strpos($type, 'coach_') === 0;
        $facts = array_merge(self::blank_facts(), array(
            'state' => (string) $offer->state,
            'name' => $name,
            'first_name' => TeamOversight_Email::first_name($user ? $user->ID : 0, $name),
            'trial_number' => $trial !== null && $trial !== '' ? (string) intval($trial) : '-',
            'team' => $team,
            'team_code' => (string) $offer->team,
            'season' => (string) $offer->season,
            'role' => (string) $offer->role,
            'fee' => TeamOversight_Offers::fee_words($offer),
            'deposit' => TeamOversight_Offers::money(TeamOversight_Offers::effective_target($offer)),
            'reply_by' => $offer->reply_by ? (string) $offer->reply_by : '',
            'deposit_due' => $offer->deposit_due ? (string) $offer->deposit_due : '',
            'paid_cents' => $paid,
            'left_cents' => $left,
            'coach_name' => (string) $contacts['coach_name'],
            'coach_email' => $contacts['primary'] ? (string) $contacts['primary']['email'] : '',
            'link' => $coach_side ? self::coach_portal_url() : TeamOversight_Offers::offer_url($offer),
            'training_url' => TeamOversight_Offers::training_url($offer->season),
            'reason' => (string) $offer->decline_reason,
            'had_deposit' => !empty($ctx['had_deposit']),
            'held_cents' => isset($ctx['held_cents']) ? max(0, intval($ctx['held_cents'])) : 0,
            'kind' => isset($ctx['kind']) ? (string) $ctx['kind'] : '',
            'overdue_date' => isset($ctx['date']) && $ctx['date'] ? (string) $ctx['date'] : (string) $deadline['date'],
            'early' => TeamOversight_Offers::is_early($offer),
            'early_note' => TeamOversight_Offers::early_note($offer),
            'fee_limit' => ($offer->quote_amount !== null && TeamOversight_Offers::is_early($offer)) ? TeamOversight_Offers::money($offer->quote_amount) : '',
            // The one extra training / extra games line (the player's own emails only: the offer and "you're locked in").
            'extras' => in_array($type, array('offer', 'locked_in'), true) ? TeamOversight_Offers::extras_line($offer->user_id, $offer->season) : array(),
        ));
        return $facts;
    }

    /**
     * Does a queued message still apply to the offer as it is NOW? An email
     * can be sent minutes (or, after a pause, days) after it was queued, so
     * the offer is judged again: an offer or reminder only while sent or
     * accepted, a withdrawal only while withdrawn,
     * "you're locked in" only while
     * locked_in, a coach notice only while it is still true. Returns
     * array(ok, note, key): key = the wording to use.
     */
    private static function still_applies($type, $offer, array $ctx) {
        $state = (string) $offer->state;
        $names = TeamOversight_Offers::states();
        $now = isset($names[$state]) ? $names[$state] : $state;
        $no = function ($why) {
            return array('ok' => false, 'note' => $why, 'key' => '');
        };
        switch ($type) {
            case 'offer':
                return in_array($state, array('sent', 'accepted'), true) ? array('ok' => true, 'note' => '', 'key' => 'offer') : $no('The offer is no longer open (' . $now . ').');
            case 'reminder':
                return in_array($state, array('sent', 'accepted'), true) ? array('ok' => true, 'note' => '', 'key' => self::wording_key('reminder', $offer)) : $no('The offer is no longer open (' . $now . ').');
            case 'withdrawn':
                return $state === 'withdrawn' ? array('ok' => true, 'note' => '', 'key' => 'withdrawn') : $no('The offer is not withdrawn (' . $now . ').');
            case 'locked_in':
                return $state === 'locked_in' ? array('ok' => true, 'note' => '', 'key' => 'locked_in') : $no('The player is not locked in (' . $now . ').');
            case 'coach_accepted':
                return in_array($state, array('accepted', 'locked_in'), true) ? array('ok' => true, 'note' => '', 'key' => 'coach_accepted') : $no('The offer is no longer accepted (' . $now . ').');
            case 'coach_declined':
                return $state === 'declined' ? array('ok' => true, 'note' => '', 'key' => 'coach_declined') : $no('The offer is not declined (' . $now . ').');
            case 'coach_overdue':
                $kind = isset($ctx['kind']) ? (string) $ctx['kind'] : '';
                $status = TeamOversight_Offers::deadline_status($offer);
                if (empty($status['overdue']) || $status['kind'] !== $kind) {
                    return $no('The deadline is not missed any more (' . $now . ').');
                }
                return array('ok' => true, 'note' => '', 'key' => 'coach_overdue');
        }
        return $no('Unknown message type.');
    }

    private static function skip($note) {
        return array('ok' => false, 'note' => TeamOversight_Offers::cut((string) $note, 250));
    }

    /**
     * Turn one outbox row into a message from the live data: re-check it
     * still applies, read the recipients now (the player's CURRENT account
     * email, the coaches from the current roster), fill the wording and
     * draw it. Returns array(ok => false, note) to skip, or array(ok => true,
     * to, subject, email, headers, key, label, user_id, what).
     */
    private static function prepare($row) {
        $ctx = json_decode((string) $row->context, true);
        $ctx = is_array($ctx) ? $ctx : array();
        $type = (string) $row->event;
        if ($type === 'not_selected') {
            return self::prepare_not_selected($row, $ctx);
        }
        if (!in_array($type, self::types(), true)) {
            return self::skip('Unknown message type.');
        }
        $offer = TeamOversight_Offers::get(intval($row->offer_id));
        if (!$offer) {
            return self::skip('The offer no longer exists.');
        }
        $check = self::still_applies($type, $offer, $ctx);
        if (!$check['ok']) {
            return self::skip($check['note']);
        }
        $key = $check['key'];
        $settings = self::get_settings();
        if (empty($settings[$key]['enabled'])) {
            return self::skip('This email is switched off on the Emails page.');
        }
        $player_side = in_array($type, array('offer', 'reminder', 'withdrawn', 'locked_in'), true);
        $user = intval($offer->user_id) > 0 ? get_userdata(intval($offer->user_id)) : false;
        if ($player_side && (!$user || !is_email($user->user_email))) {
            return self::skip('The player\'s account, or its email address, is gone.');
        }
        $contacts = TeamOversight_Offers::team_contacts($offer->team, $offer->season, $offer->requested_by, $offer->user_id);
        $headers = self::base_headers();
        if ($player_side) {
            $to = $user->user_email;
            $reply_to = self::clean_addresses(array($contacts['reply_to']));
            if (!empty($reply_to)) {
                $headers[] = 'Reply-To: ' . $reply_to[0];
            }
            // Every coach and assistant of the team is copied, the Reply-To coach
            // included, so the coach the player replies to also sees what was sent.
            $cc = self::clean_addresses(array_map(function ($p) {
                return $p['email'];
            }, self::copied_coaches($contacts)), array($to));
            if (!empty($cc)) {
                $headers[] = 'Cc: ' . implode(', ', $cc);
            }
        } else {
            $to = self::clean_addresses($contacts['all_emails']);
            if (empty($to)) {
                $fallback = self::fallback_address();
                if ($fallback === '') {
                    return self::skip('No coach is on file for ' . $offer->team . ' and no fallback address is set.');
                }
                $to = array($fallback);
            }
        }
        $facts = self::offer_facts($offer, $user, $contacts, $type, $ctx);
        $vars = self::vars_from_facts($facts);
        $events = self::get_events();
        $blocks = array();
        if (in_array('offer', $events[$key]['blocks'], true)) {
            $blocks['offer'] = self::offer_block($facts);
        }
        if (in_array('terms', $events[$key]['blocks'], true)) {
            $blocks['terms'] = self::terms_block();
        }
        if (in_array('extras', $events[$key]['blocks'], true)) {
            $blocks['extras'] = self::extras_block($facts);
        }
        list($subject, $email) = self::render_wording($key, $vars, $blocks);
        return array(
            'ok' => true,
            'to' => $to,
            'subject' => $subject,
            'email' => $email,
            'headers' => $headers,
            'key' => $key,
            'label' => $events[$key]['label'],
            'user_id' => intval($offer->user_id),
            'what' => 'offer #' . intval($offer->id) . ' (' . $offer->season . ' ' . $offer->team . ')',
        );
    }

    /**
     * "Not selected" (1.80.0: one team, queued only when someone pressed
     * Send): re-checked now (TeamOversight_Not_Selected::send_check(): the
     * application still open, that team's verdict still Rejected, a team the
     * player applied for), the
     * account email, the next steps for that one team. A message from before
     * 1.80.0 (one per application, no team) is never sent any more.
     *
     * Addressed like the offer emails (club decision, 28 Oct 2026), all read
     * from THIS team's roster now: From the club's sender (base_headers());
     * Reply-To the person who pressed Send (context 'by') when they hold a
     * coach or assistant coach place on this team, else the team's head coach
     * (else its first assistant coach), else, with no coach on file, the
     * trial applications address as before (trial_reply_to()); Cc every
     * coach and assistant coach of this team, the Reply-To coach included
     * (copied_coaches()), never the player. Coaches of the player's other teams are never read. The
     * sign-off names the Reply-To coach, or the team's coaching team when no
     * coach is on file.
     */
    private static function prepare_not_selected($row, array $ctx) {
        $app_id = intval($row->application_id) > 0 ? intval($row->application_id) : (isset($ctx['application_id']) ? intval($ctx['application_id']) : 0);
        if ($app_id <= 0 || !class_exists('TeamOversight_Not_Selected') || !method_exists('TeamOversight_Not_Selected', 'send_check') || !method_exists('TeamOversight_Not_Selected', 'notice_content')) {
            return self::skip('The application is unknown.');
        }
        $team = isset($ctx['team']) ? TeamOversight_Not_Selected::clean_team($ctx['team']) : '';
        if ($team === '') {
            return self::skip('A "not selected" email from before 1.80.0 (one per player, naming every team) is no longer sent. Send one per team from the coach portal or the Offers page, Not selected tab.');
        }
        $settings = self::get_settings();
        if (empty($settings['not_selected']['enabled'])) {
            return self::skip(self::OFF_NOTE);
        }
        $check = TeamOversight_Not_Selected::send_check($app_id, $team);
        if (empty($check['ok'])) {
            return self::skip($check['note']);
        }
        $app = $check['app'];
        $user = intval($app->user_id) > 0 ? get_userdata(intval($app->user_id)) : false;
        if (!$user || !is_email($user->user_email)) {
            return self::skip('The player\'s account, or its email address, is gone.');
        }
        $notice = TeamOversight_Not_Selected::notice_content($app, $team);
        if (empty($notice)) {
            return self::skip('There was nothing to say.');
        }
        $to = $user->user_email;
        // This team's coaches only, at send time; whoever pressed Send is the voice when they coach this team.
        $by = isset($ctx['by']) && is_scalar($ctx['by']) ? intval($ctx['by']) : 0;
        $contacts = TeamOversight_Offers::team_contacts($team, (string) $app->season, $by, intval($user->ID), array('coach', 'assistant_coach'));
        $headers = self::base_headers();
        $reply_to = self::clean_addresses(array($contacts['primary'] ? (string) $contacts['primary']['email'] : self::trial_reply_to()), array($to));
        if (!empty($reply_to)) {
            $headers[] = 'Reply-To: ' . $reply_to[0];
        }
        $cc_list = array_map(function ($p) {
            return $p['email'];
        }, self::copied_coaches($contacts));
        $cc = self::clean_addresses($cc_list, array($to));
        if (!empty($cc)) {
            $headers[] = 'Cc: ' . implode(', ', $cc);
        }
        $name = (string) $user->display_name;
        $facts = array_merge(self::blank_facts(), array(
            'name' => $name,
            'first_name' => TeamOversight_Email::first_name($user->ID, $name),
            'trial_number' => (string) intval($app->trial_number),
            'season' => (string) $app->season,
            'team' => (string) $notice['team_name'],
            'team_code' => $team,
            'rejected_teams' => (string) $notice['team_name'],
            'multi' => !empty($notice['multi']),
            'placed' => !empty($notice['placed']),
            'link' => isset($notice['edit_url']) ? (string) $notice['edit_url'] : TeamOversight_Trial_Emails::get_form_url(),
            // The sign-off: the Reply-To coach by name; '' (signed by the team's coaching team) when no coach is on file.
            'coach_name' => $contacts['primary'] ? (string) $contacts['primary']['name'] : '',
        ));
        list($subject, $email) = self::render_wording('not_selected', self::not_selected_vars($facts), array('next_steps' => self::build_next_steps($notice)));
        $events = self::get_events();
        return array(
            'ok' => true,
            'to' => $to,
            'subject' => $subject,
            'email' => $email,
            'headers' => $headers,
            'key' => 'not_selected',
            'label' => $events['not_selected']['label'],
            'user_id' => intval($user->ID),
            'what' => 'application #' . $app_id . ' (' . $app->season . ' ' . $team . ')',
        );
    }

    // ------------------------------------------------------------------
    // Sending: claim, check, send, record
    // ------------------------------------------------------------------

    /** The most time one flush spends sending (seconds). */
    const FLUSH_SECONDS = 20;

    /** The next message to send: the oldest pending one, or a failed one with tries left whose wait is over. */
    private static function next_row() {
        global $wpdb;
        $retry_before = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - self::RETRY_MINUTES * 60);
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE (status = 'pending' OR (status = 'failed' AND tries < %d AND (claimed_at IS NULL OR claimed_at <= %s))) ORDER BY id LIMIT 1",
            self::MAX_TRIES, $retry_before
        ));
    }

    /** pending => sending, tries + 1 (compare-and-set: two flushes never send one message). */
    private static function claim($row) {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'sending', tries = tries + 1, claimed_at = %s WHERE id = %d AND status IN ('pending', 'failed')",
            current_time('mysql'), intval($row->id)
        ));
        return $n === 1;
    }

    /** sending => sent | skipped | failed (compare-and-set on 'sending'). */
    private static function finish_row($row, $status, $note = '', $stamp_sent = false) {
        global $wpdb;
        $set = array('status = %s', 'note = %s');
        $args = array($status, $note === '' ? null : TeamOversight_Offers::cut($note, 250));
        if ($stamp_sent) {
            $set[] = 'sent_at = %s';
            $args[] = current_time('mysql');
        }
        $args[] = intval($row->id);
        $sql = 'UPDATE ' . self::table() . ' SET ' . implode(', ', $set) . " WHERE id = %d AND status = 'sending'";
        $n = $wpdb->query($wpdb->prepare($sql, $args));
        return $n === 1;
    }

    /** Messages that can be sent now, and failed ones waiting out their retry delay. */
    private static function waiting_counts() {
        global $wpdb;
        $retry_before = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - self::RETRY_MINUTES * 60);
        $ready = intval($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . " WHERE (status = 'pending' OR (status = 'failed' AND tries < %d AND (claimed_at IS NULL OR claimed_at <= %s)))",
            self::MAX_TRIES, $retry_before
        )));
        $backoff = intval($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'failed' AND tries < %d AND claimed_at > %s",
            self::MAX_TRIES, $retry_before
        )));
        return array($ready, $backoff);
    }

    /** One claimed row through to its final status for this attempt. Returns 'sent', 'skipped' or 'failed'. */
    private static function process($row) {
        $type = (string) $row->event;
        try {
            $prepared = self::prepare($row);
        } catch (\Throwable $e) {
            self::finish_row($row, 'failed', 'Could not build the message (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
            TeamOversight_Log::add('email_offer', 'The ' . $type . ' email for offer #' . intval($row->offer_id) . ' could not be built (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
            return 'failed';
        }
        if (empty($prepared['ok'])) {
            self::finish_row($row, 'skipped', $prepared['note']);
            TeamOversight_Log::add('email_offer', 'The ' . $type . ' email for ' . ($type === 'not_selected' ? 'application #' . intval($row->application_id) : 'offer #' . intval($row->offer_id)) . ' was not sent: ' . $prepared['note']);
            return 'skipped';
        }
        $failure = 'The mail system reported a failure. Check the site\'s mail setup.';
        $sent = false;
        try {
            $sent = TeamOversight_Email::send($prepared['to'], $prepared['subject'], $prepared['email'], $prepared['headers']);
        } catch (\Throwable $e) {
            $failure = 'Sending failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').';
        }
        $log_args = $prepared['user_id'] > 0 ? array('user_id' => $prepared['user_id']) : array();
        if ($sent) {
            self::finish_row($row, 'sent', '', true);
            TeamOversight_Log::add('email_offer', $prepared['label'] . ' email for ' . $prepared['what'] . ' sent.', $log_args);
            return 'sent';
        }
        self::finish_row($row, 'failed', $failure);
        TeamOversight_Log::add('email_offer', $prepared['label'] . ' email for ' . $prepared['what'] . ' NOT delivered, check the site\'s mail setup.', $log_args);
        return 'failed';
    }

    /**
     * Send queued messages, up to $budget in this call and a time limit:
     * claim a row (pending or failed -> sending; a 'held' row is never
     * claimed), check the message still applies, resolve the recipients at
     * send time, send through TeamOversight_Email::render() / send(), record
     * sent / failed / skipped. Sends nothing, stamps nothing, while the
     * live-site gate is closed (live() false), and then calls
     * TeamOversight_Offers::note_suppressed() (one log line a day). Failed
     * messages are tried again after RETRY_MINUTES, MAX_TRIES times in all.
     * If rows remain it schedules QUEUE_HOOK. Returns array(sent, skipped, failed,
     * pending (still waiting, including failed ones waiting to retry),
     * gate_closed).
     */
    public static function flush_email_queue($budget = 20) {
        $out = array('sent' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0, 'gate_closed' => false);
        if (!self::live()) {
            $counts = self::waiting_counts();
            $out['gate_closed'] = true;
            $out['pending'] = $counts[0] + $counts[1];
            TeamOversight_Offers::note_suppressed($out['pending'] > 0 ? $out['pending'] . ' offer email(s) are waiting.' : '');
            return $out;
        }
        $budget = max(1, intval($budget));
        $deadline = microtime(true) + self::FLUSH_SECONDS;
        $handled = 0;
        $guard = 0;
        while ($handled < $budget && $guard < $budget * 3) {
            $guard++;
            if (microtime(true) > $deadline) {
                break;
            }
            if (!self::live()) {
                $out['gate_closed'] = true;
                break;
            }
            $row = self::next_row();
            if (!$row) {
                break;
            }
            if (!self::claim($row)) {
                continue;
            }
            $handled++;
            $result = self::process($row);
            $out[$result]++;
        }
        $counts = self::waiting_counts();
        $out['pending'] = $counts[0] + $counts[1];
        if ($counts[0] > 0) {
            self::schedule_flush(30);
        } elseif ($counts[1] > 0) {
            self::schedule_flush(self::RETRY_MINUTES * 60 + 10);
        }
        return $out;
    }

    /**
     * The daily job's email step: rows stuck in 'sending' for ten minutes
     * become 'unknown' (they may or may not have been delivered, so they are
     * never retried automatically), then a flush, which also tries the
     * failed rows that have tries left. Returns array(stuck, flush).
     */
    public static function daily_upkeep() {
        global $wpdb;
        $before = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - self::STUCK_MINUTES * 60);
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'unknown', note = %s WHERE status = 'sending' AND (claimed_at IS NULL OR claimed_at < %s)",
            'Stopped part-way: it may or may not have been delivered. Check, then use Resend if it did not arrive.', $before
        ));
        $n = $n === false ? 0 : intval($n);
        if ($n > 0) {
            TeamOversight_Log::add('email_offer', $n . ' offer email(s) were left half-sent and are marked as not sure if delivered (they are not tried again unless an administrator uses Resend).');
        }
        return array('stuck' => $n, 'flush' => self::flush_email_queue());
    }

    /**
     * Outbox numbers for the Offers admin page's status strip: array(
     * pending, sending, sent, failed, unknown, skipped, held (waiting for a
     * send-or-discard decision, see hold_stale()), oldest_pending (the
     * created_at of the oldest pending row, or ''), gate_open (bool)). The
     * individual problem rows, each with the id its Resend button needs, are
     * problem_rows().
     */
    public static function status_counts() {
        global $wpdb;
        $out = array('pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0, 'held' => 0, 'oldest_pending' => '', 'last_sent' => '', 'gate_open' => self::live());
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        foreach ((array) $rows as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = intval($r['n']);
            }
        }
        $oldest = $wpdb->get_var('SELECT MIN(created_at) FROM ' . self::table() . " WHERE status = 'pending'");
        $out['oldest_pending'] = $oldest ? (string) $oldest : '';
        $last = $wpdb->get_var('SELECT MAX(sent_at) FROM ' . self::table() . " WHERE status = 'sent'");
        $out['last_sent'] = $last ? (string) $last : '';
        return $out;
    }

    /**
     * Messages that have been waiting to send for longer than $minutes:
     * array(count, oldest (a datetime or '')). The "emails are stuck behind
     * the closed permission" warning reads it; one cheap query.
     */
    public static function waiting_longer_than($minutes) {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 60 * max(1, intval($minutes)));
        $row = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM ' . self::table() . " WHERE status = 'pending' AND created_at < %s", $cutoff));
        return array($row ? intval($row->n) : 0, ($row && $row->oldest) ? (string) $row->oldest : '');
    }

    /**
     * Resend a message that is 'unknown' or an exhausted 'failed' (the page's
     * Resend button; its confirm says the player may get it twice): back to
     * 'pending' with fresh tries and flushed. The send check runs again, so
     * a message that no longer applies is skipped, not sent. Administrators
     * only. Returns array(ok, message).
     */
    public static function resend($row_id, $actor_id) {
        global $wpdb;
        if (!class_exists('TeamOversight_Offers') || !TeamOversight_Offers::can_approve($actor_id)) {
            return array('ok' => false, 'message' => 'Only administrators can send a message again.');
        }
        $row_id = intval($row_id);
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'pending', tries = 0, claimed_at = NULL, note = %s WHERE id = %d AND (status = 'unknown' OR (status = 'failed' AND tries >= %d))",
            'Sent again on request', $row_id, self::MAX_TRIES
        ));
        if ($n === 1) {
            self::flush_after_request();
            TeamOversight_Log::add('email_offer', 'Message #' . $row_id . ' queued to be sent again by an administrator.');
            return array('ok' => true, 'message' => self::live()
                ? 'The message will be sent again now.'
                : 'The message is queued again. It goes out once offer emails are opened for this site.');
        }
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . self::table() . ' WHERE id = %d', $row_id));
        if (in_array($status, array('pending', 'sending', 'sent'), true)) {
            return array('ok' => true, 'message' => 'That message is already on its way, or already sent again.');
        }
        return array('ok' => false, 'message' => $status === null ? 'No such message.' : 'That message is not waiting for a decision (it is ' . $status . ').');
    }

    // ------------------------------------------------------------------
    // The Emails page: wording, preview, test send, terms and links
    // ------------------------------------------------------------------
    //
    // The integrator calls handle_settings_post() once at the top of
    // emails_page() (every load: it also shows the result of the email
    // permission buttons) and render_settings_section() where the Offer emails box
    // should sit (after the trial emails). Both do their own capability and
    // nonce checks. The Offers page reaches the outbox only
    // through status_counts(), problem_rows() and resend().

    /** The one nonce every form of the section carries. */
    const SETTINGS_NONCE = 'offer_email_settings';
    const SETTINGS_NONCE_FIELD = 'offer_email_settings_nonce';

    /** The POST actions handle_settings_post() answers. */
    const SETTINGS_ACTIONS = array('save_offer_email_settings', 'send_test_offer_emails', 'save_offer_terms', 'save_offer_links', 'create_offer_page', 'save_offer_wording', 'reset_offer_wording');

    private static function notice($kind, $message) {
        return '<div class="notice notice-' . esc_attr($kind) . '"><p>' . implode('<br>', array_map('esc_html', (array) $message)) . '</p></div>';
    }

    /** Seasons the links box asks a training page for: the offers seasons we know of, newest first. */
    private static function offer_seasons() {
        $seasons = array();
        try {
            foreach ((array) TeamOversight_Offers::fees()->get_available_seasons() as $season) {
                $season = (string) $season;
                if (preg_match('/^\d{4}$/D', $season) === 1 && TeamOversight_Offers::applies($season)) {
                    $seasons[$season] = $season;
                }
            }
        } catch (\Throwable $e) {
            // fall through to the saved and default seasons
        }
        $saved = get_option(TeamOversight_Offers::TRAINING_URLS_OPTION, array());
        if (is_array($saved)) {
            foreach (array_keys($saved) as $season) {
                if (preg_match('/^\d{4}$/D', (string) $season) === 1) {
                    $seasons[(string) $season] = (string) $season;
                }
            }
        }
        if (empty($seasons)) {
            $year = intval(wp_date('Y'));
            foreach (array($year, $year + 1) as $season) {
                if (TeamOversight_Offers::applies((string) $season)) {
                    $seasons[(string) $season] = (string) $season;
                }
            }
        }
        krsort($seasons);
        return array_values($seasons);
    }

    /**
     * Handle this section's forms (call it at the top of emails_page(), on
     * every load) and print what happened. Also prints the result of the
     * email permission buttons, which redirect back here with a flash notice.
     * Returns true when it handled a POST. Administrators only, with the
     * section's nonce; anything else prints an error and changes nothing.
     */
    public static function handle_settings_post() {
        if (!class_exists('TeamOversight_Offers')) {
            return false;
        }
        echo TeamOversight_Offers::flash_notice();
        $action = (isset($_POST['action']) && is_string($_POST['action'])) ? sanitize_key(wp_unslash($_POST['action'])) : '';
        if (!in_array($action, self::SETTINGS_ACTIONS, true)) {
            return false;
        }
        $nonce = (isset($_POST[self::SETTINGS_NONCE_FIELD]) && is_string($_POST[self::SETTINGS_NONCE_FIELD])) ? sanitize_text_field(wp_unslash($_POST[self::SETTINGS_NONCE_FIELD])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::SETTINGS_NONCE)) {
            echo self::notice('error', 'Security check failed.');
            return true;
        }
        if (!current_user_can('manage_options')) {
            echo self::notice('error', 'Insufficient permissions.');
            return true;
        }
        switch ($action) {
            case 'save_offer_email_settings':
                echo self::save_wording();
                break;
            case 'send_test_offer_emails':
                echo self::test_notice(self::send_test_emails(get_current_user_id()));
                break;
            case 'save_offer_terms':
                echo self::save_terms();
                break;
            case 'save_offer_links':
                echo self::save_links();
                break;
            case 'create_offer_page':
                echo self::create_offer_page();
                break;
            case 'save_offer_wording':
                $posted = (isset($_POST['offer_wording']) && is_array($_POST['offer_wording'])) ? wp_unslash($_POST['offer_wording']) : array();
                $reset = (isset($_POST['offer_wording_reset']) && is_array($_POST['offer_wording_reset'])) ? array_map('sanitize_key', wp_unslash($_POST['offer_wording_reset'])) : array();
                echo TeamOversight_Offers_Setup::save_wording_post($posted, $reset, get_current_user_id());
                break;
            case 'reset_offer_wording':
                $n = TeamOversight_Offer_Wording::reset(array(), get_current_user_id());
                echo self::notice('success', 'All the offer wording is back to the built-in text (' . intval($n) . ' line' . ($n === 1 ? '' : 's') . ' had been changed).');
                break;
        }
        return true;
    }

    /** Save every email's switch and wording. Wording left identical to the built-in text is stored as "built in", so later improvements still reach it. */
    private static function save_wording() {
        $posted = (isset($_POST['offer_emails']) && is_array($_POST['offer_emails'])) ? wp_unslash($_POST['offer_emails']) : array();
        $switches = get_option(self::OPTION, array());
        $switches = is_array($switches) ? $switches : array();
        $texts = get_option(self::TEXT_OPTION, array());
        $texts = is_array($texts) ? $texts : array();
        $warnings = array();
        foreach (self::get_events() as $key => $event) {
            if (!isset($posted[$key]) || !is_array($posted[$key])) {
                continue; // a page opened before this email existed leaves it as it is
            }
            $mine = $posted[$key];
            if (!empty($event['switch'])) {
                $switches[$key] = array('enabled' => !empty($mine['enabled']) ? 1 : 0);
            }
            // Wording identical to the built-in text is stored as "built in" (one rule for every email: TeamOversight_Email::collect_wording()).
            list($subject, $heading, $body, $check) = TeamOversight_Email::collect_wording($mine, $event);
            $texts[$key] = array('subject' => $subject, 'heading' => $heading, 'body' => $body);
            foreach (self::check_body($key, $check) as $warning) {
                $warnings[] = $event['label'] . ': ' . $warning;
            }
        }
        update_option(self::OPTION, $switches, false);
        update_option(self::TEXT_OPTION, $texts, false);
        TeamOversight_Log::add('email_offer', 'The offer email wording or switches were saved.');
        $out = '';
        if ($warnings) {
            $out .= self::notice('warning', $warnings);
        }
        return $out . self::notice('success', 'Offer email settings saved.');
    }

    /** Save the three terms lists (one item per line; a list left as the built-in text, or blank, is stored as "built in"). */
    private static function save_terms() {
        $posted = (isset($_POST['offer_terms']) && is_array($_POST['offer_terms'])) ? wp_unslash($_POST['offer_terms']) : array();
        $clean = array();
        foreach (TeamOversight_Offers::terms_defaults() as $group => $def) {
            $text = (isset($posted[$group]) && is_string($posted[$group])) ? sanitize_textarea_field($posted[$group]) : '';
            $items = array();
            foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
                $line = trim(TeamOversight_Offers::cut(trim($line), 400));
                if ($line !== '') {
                    $items[] = $line;
                }
            }
            $items = array_slice($items, 0, 20);
            if (!empty($items) && $items !== array_values($def['items'])) {
                $clean[$group] = implode("\n", $items);
            }
        }
        if (empty($clean)) {
            delete_option(TeamOversight_Offers::TERMS_OPTION);
        } else {
            update_option(TeamOversight_Offers::TERMS_OPTION, $clean, false);
        }
        TeamOversight_Offers::flush_cache();
        TeamOversight_Log::add('email_offer', 'The offer terms (commitments and deposit policy) were saved.');
        return self::notice('success', 'Offer terms saved. The offer emails and the offer page both use them.');
    }

    /** Save the offer page address, the Trials & Team Info pages and the pages "not selected" points to. */
    private static function save_links() {
        $errors = array();
        $web = function ($raw) {
            $raw = trim((string) $raw);
            $url = esc_url_raw($raw);
            return ($url !== '' && preg_match('~^https?://~i', $url) === 1) ? $url : '';
        };

        $page_raw = (isset($_POST['offer_page_url']) && is_string($_POST['offer_page_url'])) ? trim(wp_unslash($_POST['offer_page_url'])) : '';
        if ($page_raw === '') {
            delete_option(TeamOversight_Offers::PAGE_URL_OPTION);
        } elseif ($web($page_raw) === '') {
            $errors[] = 'The offer page address is not a web address (it should start with https://).';
        } else {
            update_option(TeamOversight_Offers::PAGE_URL_OPTION, $web($page_raw), false);
        }

        $posted = (isset($_POST['training_urls']) && is_array($_POST['training_urls'])) ? wp_unslash($_POST['training_urls']) : array();
        $new_season = (isset($_POST['new_training_season']) && is_string($_POST['new_training_season'])) ? trim(wp_unslash($_POST['new_training_season'])) : '';
        $new_url = (isset($_POST['new_training_url']) && is_string($_POST['new_training_url'])) ? trim(wp_unslash($_POST['new_training_url'])) : '';
        if ($new_season !== '' || $new_url !== '') {
            $posted[$new_season] = $new_url;
        }
        $map = array();
        foreach ($posted as $season => $raw) {
            $season = (string) $season;
            if (preg_match('/^\d{4}$/D', $season) !== 1) {
                if ($raw !== '') {
                    $errors[] = 'Training page: "' . $season . '" is not a season year.';
                }
                continue;
            }
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            if ($web($raw) === '') {
                $errors[] = $season . ' training page: that is not a web address (it should start with https://).';
                continue;
            }
            $map[$season] = $web($raw);
        }
        if (empty($map)) {
            delete_option(TeamOversight_Offers::TRAINING_URLS_OPTION);
        } else {
            update_option(TeamOversight_Offers::TRAINING_URLS_OPTION, $map, false);
        }

        if (class_exists('TeamOversight_Not_Selected') && method_exists('TeamOversight_Not_Selected', 'save_pathway_pages') && isset($_POST['pathway']) && is_array($_POST['pathway'])) {
            $input = array();
            foreach (wp_unslash($_POST['pathway']) as $name => $value) {
                if (is_string($value)) {
                    $input[(string) $name] = $value;
                }
            }
            $result = TeamOversight_Not_Selected::save_pathway_pages($input, get_current_user_id());
            if (empty($result['ok'])) {
                foreach ((array) $result['errors'] as $error) {
                    $errors[] = $error;
                }
            }
        }
        // The extra training / extra games line: one wording per age group; blank or the built-in text is stored as "built in".
        $warnings = array();
        if (isset($_POST['offer_extras']) && is_array($_POST['offer_extras'])) {
            $posted_extras = wp_unslash($_POST['offer_extras']);
            $clean_extras = array();
            foreach (TeamOversight_Offers::extras_defaults() as $kind => $default) {
                $text = (isset($posted_extras[$kind]) && is_string($posted_extras[$kind])) ? trim(TeamOversight_Offers::cut(sanitize_text_field($posted_extras[$kind]), 300)) : '';
                if ($text === '' || $text === $default) {
                    continue;
                }
                if (strpos($text, '{page}') === false) {
                    $warnings[] = 'The ' . ($kind === 'junior' ? 'under 18' : '18 and over') . ' line has no {page} in it, so the page\'s name and link are added at the end of it.';
                }
                $clean_extras[$kind] = $text;
            }
            if (empty($clean_extras)) {
                delete_option(TeamOversight_Offers::EXTRAS_OPTION);
            } else {
                update_option(TeamOversight_Offers::EXTRAS_OPTION, $clean_extras, false);
            }
        }
        TeamOversight_Offers::flush_cache();
        TeamOversight_Log::add('email_offer', 'The offer links (offer page, training pages, not-selected pages, the extra training line) were saved.');
        $prefix = $warnings ? self::notice('warning', $warnings) : '';
        if ($errors) {
            return $prefix . self::notice('error', array_merge($errors, array('Everything else was saved.')));
        }
        return $prefix . self::notice('success', 'Offer links saved.');
    }

    /** The "Create the Your Team Offer page" button: a published page (slug team-offer) holding the [team_offer] shortcode, once. */
    private static function create_offer_page() {
        if (self::page_url_for('[team_offer') !== '') {
            return self::notice('info', 'The offer page already exists.');
        }
        $id = wp_insert_post(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Your Team Offer',
            'post_name' => 'team-offer',
            'post_content' => '[team_offer]',
            'post_author' => get_current_user_id(),
        ), true);
        if (is_wp_error($id) || !$id) {
            return self::notice('error', 'The page could not be created. Make a page with the shortcode [team_offer] by hand.');
        }
        TeamOversight_Offers::flush_cache();
        TeamOversight_Log::add('offer_flow', 'The Your Team Offer page was created (page #' . intval($id) . ').');
        return self::notice('success', 'The Your Team Offer page was created. Offer emails now link to it. Keep it out of the menus; it is already set not to be indexed by search engines.');
    }

    private static function test_notice(array $results) {
        if (empty($results)) {
            return self::notice('info', 'Nothing was sent: every switchable offer email is off, or your account has no valid address.');
        }
        $failed = 0;
        foreach ($results as $r) {
            if (empty($r['ok'])) {
                $failed++;
            }
        }
        $sent = count($results) - $failed;
        $admin = wp_get_current_user();
        if ($failed) {
            return self::notice('error', $failed . ' test email(s) failed to send. Check the site\'s email configuration.');
        }
        return self::notice('success', $sent . ' test email(s) sent to ' . $admin->user_email . '. They carry sample names, fees and dates and go to nobody else.');
    }

    /**
     * The "Offer emails" box of VVL Oversight > Emails: the live-site gate
     * (the email permission), the wording of all nine emails with a preview and a
     * test send, the commitments and deposit policy, and the links
     * (offer page, Trials & Team Info pages, the pages "not selected"
     * points to). Prints its own anchor (#offer-emails).
     */
    public static function render_settings_section() {
        if (!current_user_can('manage_options') || !class_exists('TeamOversight_Offers')) {
            return;
        }
        $events = self::get_events();
        $settings = self::get_settings();
        $page = admin_url('admin.php?page=team-oversight-emails&tab=offers');
        $nonce = wp_create_nonce(self::SETTINGS_NONCE);
        $hidden = function ($action) use ($nonce) {
            return '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="' . esc_attr(self::SETTINGS_NONCE_FIELD) . '" value="' . esc_attr($nonce) . '">';
        };
        $offer_page = self::page_url_for('[team_offer');
        $seasons = self::offer_seasons();
        $links_season = !empty($seasons) ? (string) $seasons[0] : (string) max(2027, intval(wp_date('Y')));
        $counts = self::status_counts();
        ?>
        <div id="offer-emails" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-top: 20px;">
            <h2 style="margin-top: 0;">Offers and selection</h2>
            <p style="margin: 0 0 8px;"><strong>On this tab:</strong>
                <a href="#offer-gate">Permission to send</a> &middot;
                <a href="#offer-emails-cards">The emails</a> &middot;
                <a href="#offer-terms">Commitments and deposit policy</a> &middot;
                <a href="#offer-wording">Offer wording</a> (Early Offer, money policy, the offer page, the coach portal) &middot;
                <a href="#offer-pages">Pages and links</a>
                &middot; <?php echo TeamOversight_Offers_Setup::offers_link('Setup checklist', array('tab' => 'setup')); ?>
            </p>
            <p class="description" style="max-width: 900px;">
                The offer and its reminder, a withdrawal, the &quot;you're locked in&quot; note, the coaches' notices and the &quot;not selected&quot; email (sent only when someone presses Send, about one team; Reply-To the coach who pressed it when they coach that team, else its head coach; every coach and assistant coach of the team copied, the Reply-To coach included).
                Each is queued when something happens and sent from here once the site has been opened for sending (the box just below). A message is checked again just before it goes, so an offer withdrawn while its email waited is never mailed as an offer; the player's address is read from their account at that moment, and the coaches from the team's current roster.
                An offer sent before the season's fees are released is an <strong>Early Offer</strong> (quoted on the upper limit): <code>{offer_name}</code> turns into &quot;Early Offer&quot; (else &quot;offer&quot;) in the subject and heading, and <code>{early_note}</code> is the plain-words paragraph that explains it (the fee isn't set yet, it won't be more than the upper limit, the deposit is 10% of that limit and comes off the final fee; empty for an ordinary offer). The offer and its two reminders are previewed and test-sent both ways.
                Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{team}</code> <code>{season}</code> <code>{role}</code> <code>{fee}</code> <code>{fee_limit}</code> <code>{deposit}</code> <code>{reply_by}</code> <code>{deposit_due}</code> <code>{offer_name}</code> <code>{early_note}</code> <code>{coach_name}</code> <code>{coach_email}</code> <code>{link}</code> <code>{training_link}</code> and, for the coaches, <code>{player_name}</code> <code>{trial_number}</code> <code>{reason_line}</code>; each email's box lists what it can fill.
                <?php echo TeamOversight_Email::format_help(true); ?>
                Clear a subject, heading or message and save to go back to the built-in wording.
            </p>

            <?php echo TeamOversight_Offers::render_gate_box($page . '#offer-gate'); ?>

            <p style="margin: 0 0 12px;">
                <strong>Outbox:</strong>
                <?php echo intval($counts['pending'] + $counts['sending']); ?> waiting,
                <?php echo intval($counts['sent']); ?> sent,
                <?php echo intval($counts['skipped']); ?> skipped<?php if ($counts['held']) { echo ', ' . intval($counts['held']) . ' held'; } ?><?php if ($counts['failed']) { echo ', <span style="color:#b32d2e;">' . intval($counts['failed']) . ' failed</span>'; } ?><?php if ($counts['unknown']) { echo ', <span style="color:#b32d2e;">' . intval($counts['unknown']) . ' not sure if delivered</span>'; } ?>.
                Failed messages and the ones that may or may not have gone out are listed, with a Resend button, on the <?php echo TeamOversight_Offers_Setup::offers_link('Offers'); ?> page (the Offer emails box at the top of every tab).
            </p>

            <?php if ($offer_page === ''): ?>
                <div class="notice notice-warning inline" style="margin: 0 0 12px;"><p>
                    <strong>There is no offer page yet,</strong> so the &quot;See your offer&quot; button would point to <?php echo esc_html(TeamOversight_Offers::offer_page_url()); ?>, which may not exist.
                </p>
                    <form method="post" style="margin: 0 0 8px;">
                        <?php echo $hidden('create_offer_page'); ?>
                        <input type="submit" class="button" value="Create the Your Team Offer page">
                        <span class="description">Makes a published page called Your Team Offer (address /team-offer/) that holds the [team_offer] shortcode. It is sent to search engines as not to be indexed; keep it out of the menus.</span>
                    </form>
                </div>
            <?php else: ?>
                <p class="description" style="margin: 0 0 12px;">The offer page is <a href="<?php echo esc_url($offer_page); ?>" target="_blank"><?php echo esc_html($offer_page); ?></a>.</p>
            <?php endif; ?>
            <?php foreach ($seasons as $season): if (TeamOversight_Offers::training_url($season) === ''): ?>
                <p style="color: #b32d2e; margin: 0 0 8px;">No Trials &amp; Team Info page is set for <?php echo esc_html($season); ?>, so the training line in its offer emails has no link. Set it under Pages and links below.</p>
            <?php endif; endforeach; ?>

            <h3 id="offer-emails-cards">The emails</h3>
            <form method="post">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 15px;">
                    <?php foreach ($events as $key => $event):
                        $s = $settings[$key];
                        $sample = self::sample_vars($key);
                        $variants = self::variants_for($key);
                        $name = 'offer_emails[' . $key . ']';
                        ?>
                        <div style="border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 14px; <?php echo $s['enabled'] ? '' : 'background: #f6f7f7;'; ?>">
                            <?php if (!empty($event['switch'])): ?>
                                <label style="font-size: 14px;">
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked($s['enabled']); ?>>
                                    <strong><?php echo esc_html($event['label']); ?></strong>
                                    <?php if ($s['customised']): ?><span style="color: #996800; font-size: 12px;"> &middot; customised</span><?php endif; ?>
                                </label>
                                <p class="description" style="margin: 2px 0 0;"><?php echo $s['enabled'] ? '' : '<strong style="color: #b32d2e;">Switched off.</strong> '; ?><?php echo !empty($event['switch_note']) ? esc_html($event['switch_note']) : 'Unticking this is not a pause: while it is off, every one of these emails that falls due is skipped for good, and none of them goes out when you tick it again.'; ?> To hold offer emails for a while, use Pause offer emails at the top of this tab.</p>
                            <?php else: ?>
                                <div style="font-size: 14px;">
                                    <strong><?php echo esc_html($event['label']); ?></strong>
                                    <span style="color: #646970; font-size: 12px;"> &middot; always on (it is the process)</span>
                                    <?php if ($s['customised']): ?><span style="color: #996800; font-size: 12px;"> &middot; customised</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <p class="description" style="margin: 4px 0 2px;"><?php echo esc_html($event['when']); ?></p>
                            <p class="description" style="margin: 0 0 8px;"><?php echo esc_html($event['who']); ?></p>
                            <p style="margin: 0 0 6px;">
                                <label>Subject<br>
                                    <input type="text" name="<?php echo esc_attr($name); ?>[subject]" value="<?php echo esc_attr($s['subject']); ?>" style="width: 100%;">
                                </label>
                            </p>
                            <p style="margin: 0 0 6px;">
                                <label>Heading<br>
                                    <input type="text" name="<?php echo esc_attr($name); ?>[heading]" value="<?php echo esc_attr($s['heading']); ?>" style="width: 100%;">
                                </label>
                            </p>
                            <label>Message<br>
                                <textarea name="<?php echo esc_attr($name); ?>[body]" rows="14" style="width: 100%; font-size: 13px;"><?php echo esc_textarea($s['body']); ?></textarea>
                            </label>
                            <p class="description" style="margin: 2px 0 4px;">Can fill: <?php echo esc_html(implode(' ', array_keys($sample))); ?></p>
                            <p class="description" style="margin: 0 0 4px;">Added around your words (fixed, so the emails keep one look): the label above the heading &ldquo;<?php echo esc_html(TeamOversight_Email::fill($event['program'], $sample)); ?>&rdquo;, the button &ldquo;<?php echo esc_html($event['button']); ?>&rdquo; (change what it says with <code>{button:Your words}</code> in the message), the preview line &ldquo;<?php echo esc_html(TeamOversight_Email::fill($event['preheader'], $sample)); ?>&rdquo; and the footer &ldquo;<?php echo esc_html(TeamOversight_Email::fill($event['why'], $sample)); ?>&rdquo;.</p>
                            <?php foreach (self::check_body($key, $s['body']) as $warning): ?>
                                <p style="color: #b32d2e; margin: 4px 0;">Check: <?php echo esc_html($warning); ?></p>
                            <?php endforeach; ?>
                            <?php echo TeamOversight_Email::wording_footer(array($name . '[subject]', $name . '[heading]', $name . '[body]'), array($event['subject'], $event['heading'], $event['body']), $s['customised']); ?>
                            <?php foreach ($variants as $variant => $variant_info):
                                list($p_subject, $p_email) = self::render_wording($key, self::sample_vars($key, $variant), self::sample_blocks($key, $variant)); ?>
                                <details style="margin-top: 6px;">
                                    <summary style="cursor: pointer;"><?php echo esc_html($variant_info[0]); ?></summary>
                                    <p style="background: #f0f0f1; padding: 6px 10px; border-radius: 4px; margin: 6px 0;"><strong>Subject:</strong> <?php echo esc_html($p_subject); ?></p>
                                    <?php echo TeamOversight_Email::preview_frame($p_email['html']); ?>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <input type="submit" class="button button-primary" value="Save offer emails">
                    <?php echo $hidden('save_offer_email_settings'); ?>
                </p>
            </form>
            <form method="post" style="margin-bottom: 18px;">
                <?php echo $hidden('send_test_offer_emails'); ?>
                <input type="submit" class="button" value="Send a test of each switched-on offer email to me">
                <span class="description">Goes only to your own address, with a [TEST] subject and sample details. Nobody is copied, and it works on any site.</span>
            </form>

            <h3 id="offer-terms">Commitments and deposit policy</h3>
            <p class="description" style="max-width: 900px;">One point per line. This text fills the <code>{terms}</code> block of the offer emails and is shown on the offer page, so it is edited once, here. Clear a box and save to go back to the built-in wording. The built-in lists quote the two money-policy sentences (what happens to a deposit when the club breaks its side, and when the player breaks theirs) from <a href="#offer-wording">Offer wording, Money policy</a>: edit those there and the lists, the withdrawal and decline notes and the finished-offer lines all follow. A list you change here no longer follows them.</p>
            <form method="post">
                <?php $terms = TeamOversight_Offers::terms(); foreach (TeamOversight_Offers::terms_defaults() as $group => $def): $terms_custom = array_values($terms[$group]['items']) !== array_values($def['items']); ?>
                    <p style="margin: 0 0 4px;">
                        <label><strong><?php echo esc_html($terms[$group]['title']); ?></strong><?php if ($terms_custom): ?><span style="color: #996800; font-size: 12px;"> &middot; changed</span><?php endif; ?><br>
                            <textarea name="offer_terms[<?php echo esc_attr($group); ?>]" rows="6" style="width: 100%; max-width: 900px; font-size: 13px;"><?php echo esc_textarea(implode("\n", $terms[$group]['items'])); ?></textarea>
                        </label>
                    </p>
                    <details style="margin: 0 0 4px;"><summary style="cursor: pointer;">Built-in wording<?php echo $terms_custom ? '' : ' (this is what is in use)'; ?></summary>
                        <pre style="background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; padding: 8px; white-space: pre-wrap; font-size: 12px; margin: 4px 0 0; max-width: 900px;"><?php echo esc_html(implode("\n", $def['items'])); ?></pre>
                    </details>
                    <?php if ($terms_custom) { echo TeamOversight_Email::use_builtin_button(array('offer_terms[' . $group . ']' => implode("\n", $def['items']))); } ?>
                    <div style="margin-bottom: 10px;"></div>
                <?php endforeach; ?>
                <p><input type="submit" class="button button-primary" value="Save terms"><?php echo $hidden('save_offer_terms'); ?></p>
            </form>

            <?php echo TeamOversight_Offers_Setup::render_wording_panel($hidden); ?>

            <h3 id="offer-pages">Pages and links</h3>
            <p class="description" style="max-width: 900px;">Where the buttons and links in the offer emails and on the offer page go, and how the site found each page. A page marked as a guess would send players somewhere that may not exist.</p>
            <?php echo TeamOversight_Offers_Setup::render_pages_table($links_season); ?>
            <form method="post" style="margin-top: 12px;">
                <p class="description" style="max-width: 900px;">Change them here. Leave a box blank to use the default.</p>
                <p>
                    <label><strong>Your offer page</strong><br>
                        <input type="url" name="offer_page_url" value="<?php echo esc_attr((string) get_option(TeamOversight_Offers::PAGE_URL_OPTION, '')); ?>" placeholder="<?php echo esc_attr($offer_page !== '' ? $offer_page : TeamOversight_Offers::offer_page_url()); ?>" style="width: 100%; max-width: 600px;">
                    </label>
                    <span class="description" style="display: block;">Only needed if no page holds the [team_offer] shortcode.</span>
                </p>
                <p style="margin-bottom: 4px;"><strong>Trials &amp; Team Info page, per season</strong> <span class="description">(the one place to set it for a season; a season with no page of its own uses the default Training info page URL on Trial Applications)</span></p>
                <?php $training_saved = get_option(TeamOversight_Offers::TRAINING_URLS_OPTION, array()); $training_saved = is_array($training_saved) ? $training_saved : array();
                foreach ($seasons as $season): ?>
                    <p style="margin: 0 0 6px;">
                        <label><?php echo esc_html($season); ?>
                            <input type="url" name="training_urls[<?php echo esc_attr($season); ?>]" value="<?php echo esc_attr(isset($training_saved[$season]) ? (string) $training_saved[$season] : ''); ?>" placeholder="<?php echo esc_attr(TeamOversight_Offers::training_url($season)); ?>" style="width: 100%; max-width: 560px;">
                        </label>
                    </p>
                <?php endforeach; ?>
                <p style="margin: 0 0 10px;">
                    <label>Another season <input type="text" name="new_training_season" value="" placeholder="2028" maxlength="4" style="width: 70px;"></label>
                    <input type="url" name="new_training_url" value="" placeholder="https://" style="width: 100%; max-width: 480px;">
                </p>
                <?php if (class_exists('TeamOversight_Not_Selected') && method_exists('TeamOversight_Not_Selected', 'pathway_pages')): $pages = TeamOversight_Not_Selected::pathway_pages(); $defaults = TeamOversight_Not_Selected::pathway_defaults(); ?>
                    <p style="margin-bottom: 4px;"><strong>Pages the &quot;not selected&quot; email and the extra training / extra games line point to</strong></p>
                    <p class="description" style="margin-top: 0;">The &quot;not selected&quot; email uses them when a player has no lower division left to try: adults get the first two, juniors the Junior Academy. The offer email, the offer page and the &quot;you're locked in&quot; email use two of them for one short line: a player under 18 at the season start gets the Junior Academy (extra training), anyone 18 or over the Reds Social League (extra games). Pick a page, or leave the built-in one.</p>
                    <p style="margin: 0 0 6px;">
                        <?php foreach (array('mixed_training' => 'Mixed Training Program', 'social_league' => 'Reds Social League', 'junior_academy' => 'Junior Academy') as $path_key => $path_label): ?>
                            <label style="margin-right: 14px; display: inline-block;"><?php echo esc_html($path_label); ?><br>
                                <?php echo wp_dropdown_pages(array(
                                    'name' => 'pathway[' . $path_key . ']',
                                    'selected' => $pages[$path_key] !== $defaults[$path_key] ? intval($pages[$path_key]) : 0,
                                    'show_option_none' => 'Built-in page (#' . intval($defaults[$path_key]) . ')',
                                    'option_none_value' => '',
                                    'echo' => 0,
                                )); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </label>
                        <?php endforeach; ?>
                        <label>Junior Academy section<br><input type="text" name="pathway[junior_anchor]" value="<?php echo esc_attr($pages['junior_anchor'] !== $defaults['junior_anchor'] ? $pages['junior_anchor'] : ''); ?>" placeholder="<?php echo esc_attr($defaults['junior_anchor']); ?>" style="width: 110px;"></label>
                    </p>
                <?php endif; ?>
                <?php $extras_saved = get_option(TeamOversight_Offers::EXTRAS_OPTION, array()); $extras_saved = is_array($extras_saved) ? $extras_saved : array(); $extras_defaults = TeamOversight_Offers::extras_defaults(); ?>
                <p style="margin-bottom: 4px;"><strong>The extra training / extra games line</strong></p>
                <p class="description" style="margin-top: 0; max-width: 900px;">One short line in the <code>{extras}</code> spot of the offer and &quot;you're locked in&quot; emails, and on the offer page. <code>{page}</code> is the page's name, as a link. Leave a box blank for the built-in wording. The Junior Academy line is about extra training. If the page isn't published, the line is left out.</p>
                <p style="margin: 0 0 6px;">
                    <label>Under 18 at the season start (Junior Academy)<br>
                        <input type="text" name="offer_extras[junior]" value="<?php echo esc_attr(isset($extras_saved['junior']) && is_string($extras_saved['junior']) ? $extras_saved['junior'] : ''); ?>" placeholder="<?php echo esc_attr($extras_defaults['junior']); ?>" maxlength="300" style="width: 100%; max-width: 700px;">
                    </label>
                </p>
                <p style="margin: 0 0 6px;">
                    <label>18 and over (Reds Social League)<br>
                        <input type="text" name="offer_extras[adult]" value="<?php echo esc_attr(isset($extras_saved['adult']) && is_string($extras_saved['adult']) ? $extras_saved['adult'] : ''); ?>" placeholder="<?php echo esc_attr($extras_defaults['adult']); ?>" maxlength="300" style="width: 100%; max-width: 700px;">
                    </label>
                </p>
                <p class="description" style="margin: 0 0 4px;">Built-in: under 18 &ldquo;<?php echo esc_html($extras_defaults['junior']); ?>&rdquo;; 18 and over &ldquo;<?php echo esc_html($extras_defaults['adult']); ?>&rdquo;. Clear a box and save to use the built-in line.</p>
                <?php if (!empty($extras_saved)) { echo TeamOversight_Email::use_builtin_button(array('offer_extras[junior]' => '', 'offer_extras[adult]' => ''), 'Use the built-in lines'); } ?>
                <p class="description" style="margin: 0 0 10px;">Reads now:
                    <?php foreach (array('junior' => 'under 18', 'adult' => '18 and over') as $extras_kind => $extras_label): $extras_now = TeamOversight_Offers::extras_for_kind($extras_kind); ?>
                        <br><em><?php echo esc_html($extras_label); ?>:</em> <?php echo !empty($extras_now['text']) ? esc_html($extras_now['text']) : '(left out: the page isn\'t published)'; ?>
                    <?php endforeach; ?>
                </p>
                <p><input type="submit" class="button button-primary" value="Save links"><?php echo $hidden('save_offer_links'); ?></p>
            </form>
        </div>
        <?php
    }

    /**
     * Send each switched-on email to the administrator only, with sample
     * names, fees and dates, the subject prefixed [TEST] and no real Cc or
     * Reply-To, so a test never reaches a player or a coach. Works on any
     * site (it is the one send the live-site gate does not hold back).
     * "Not selected" is sent in its eight shapes (one team or more than one
     * applied for; lower divisions, adult pathway, junior; a player who
     * already has a place this season; and a team with no coach on file,
     * signed by the coaching team). Returns a list of
     * array(key, label, ok).
     */
    public static function send_test_emails($admin_user_id) {
        $admin = get_userdata(intval($admin_user_id));
        $results = array();
        if (!$admin || !is_email($admin->user_email) || !user_can($admin, 'manage_options')) {
            return $results;
        }
        $settings = self::get_settings();
        foreach (self::get_events() as $key => $event) {
            if (empty($settings[$key]['enabled'])) {
                continue;
            }
            foreach (self::variants_for($key) as $variant => $variant_info) {
                $suffix = $variant_info[1];
                list($subject, $email) = self::render_wording($key, self::sample_vars($key, $variant), self::sample_blocks($key, $variant));
                $ok = TeamOversight_Email::send($admin->user_email, '[TEST] ' . $subject . $suffix, $email, self::base_headers());
                $results[] = array('key' => $key, 'label' => $event['label'] . $suffix, 'ok' => (bool) $ok);
            }
        }
        TeamOversight_Log::add('email_offer', count($results) . ' test offer email(s) sent to an administrator.', array('user_id' => $admin->ID));
        if (!empty($results)) {
            update_option(TeamOversight_Offers_Setup::TEST_SENT_OPTION, array('at' => current_time('mysql'), 'by' => intval($admin->ID), 'count' => count($results)), false);
        }
        return $results;
    }
}
