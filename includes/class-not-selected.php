<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "not selected" email (1.80.0, club decision, 28 Oct 2026): sent ONLY
 * when someone presses a button, and about ONE team, so a coach always knows
 * when something is being sent. It is addressed like the offer emails
 * (TeamOversight_Offer_Emails::prepare_not_selected()): the coach who
 * pressed Send (when they coach that team, else its head coach) is the
 * Reply-To and the voice, and the team's other coaches are copied.
 *
 * The rules, in one place:
 *  - Nothing is scheduled or sent automatically, ever. There is no hold, no
 *    condition over the whole application and no daily sending. The notice
 *    goes when a coach (anyone with edit access to THAT team in the coach
 *    portal, committee edit included) presses Send "not selected" email on
 *    the player's card, or Send "not selected" emails for the board, or when
 *    an administrator presses Send / Send all on the Offers page's Not
 *    selected tab (the committee's safety net).
 *  - One notice per (application, team), ever: team_not_selected has a
 *    unique key on (application_id, team) and the press claims the row with
 *    ONE INSERT IGNORE (or a compare-and-set from 'failed'); only the winner
 *    queues the outbox row not_selected:<application id>:<team>
 *    (TeamOversight_Offer_Emails::queue(), whose own dedupe key is a second
 *    lock). Two tabs, a double press, or a coach and the committee at once
 *    send it once.
 *  - It does NOT wait for the player's other teams: a coach can tell a player
 *    they weren't selected for their team while other teams are still
 *    deciding, or have selected them. The email names the one team and, when
 *    the player applied for more than one, says plainly that this message is
 *    only about that team and that their other applications are decided
 *    separately. It never says anything about another team's verdict or
 *    offer.
 *  - Send works while THIS team's verdict is Rejected and the application is
 *    live: 'pending' or 'accepted' (a Payment-pending application can't be
 *    told until the fee is paid; a rejected, expired or deleted one never),
 *    the account still exists and the email is switched on (Emails, Offers
 *    and selection). block_reason() is the one rule; the cards, the bulk
 *    panel, the Offers page and the server all ask it.
 *  - The email permission (TeamOversight_Offers::emails_live()) still holds
 *    every message: a notice pressed while it is closed waits in the outbox
 *    (the card says so), and the outbox's own hold catches anything more
 *    than a day old when it is opened. At send time the outbox re-checks
 *    the notice (send_check(): still Rejected for that team, application
 *    still live, account still there).
 *  - Don't send (administrators, on the Not selected tab) records a final
 *    'stopped' row: kept as a record, never sent.
 *  - If the verdict later changes from Rejected, the row stays and the card
 *    shows it as history; it is never sent twice.
 *
 * States of a per-team row: 'queued' (sent: the button was pressed and the
 * email queued; final), 'stopped' (Don't send; final) and 'failed' (the
 * outbox refused it; Send works again). A row from before 1.80.0 has team ''
 * (one per application, from the old automatic notice: scheduled, held,
 * queued, cancelled, stopped) and is kept as history; migrate_legacy_rows()
 * mapped its 'queued' / 'stopped' rows once to each team that had rejected
 * the player (source 'legacy'), and every other rejection is simply "not
 * told yet" (there is no row until someone presses a button).
 *
 * Division levels (TeamOversight_Database::get_teams_config() 'level': lower
 * number = higher division, equal numbers = the same division, 0 = not set)
 * decide which lower divisions the email suggests. The pathway pages it
 * falls back to are the option team_oversight_pathway_pages.
 *
 * Contract: design/offer-api.md sections 3.4, 7.3 and 20.
 */
class TeamOversight_Not_Selected {

    /** Saved pathway page ids: mixed_training, social_league, junior_academy (page ids) and junior_anchor (a fragment). Blank or missing = the built-in default. */
    const PATHWAY_OPTION = 'team_oversight_pathway_pages';

    /** The one-off flag of migrate_legacy_rows() (autoloaded, so the check costs no query). */
    const MIGRATE_OPTION = 'team_oversight_ns_per_team';

    /** A per-team row: the email was queued (final). */
    const SENT = 'queued';

    /** A per-team row an administrator marked Don't send (final). Also the internal state of an old per-application row an administrator cancelled. */
    const STOPPED = 'stopped';

    /** A per-team row whose email the outbox refused: Send works again. */
    const FAILED = 'failed';

    /** The 1.79.x state of an old per-application notice that waited for an administrator (history only since 1.80.0). */
    const HELD = 'held';

    /** Applications that can be told (a Payment-pending one waits for its fee; any other status is no longer open). */
    const LIVE_STATUSES = array('pending', 'accepted');

    /** Rejections not told for this many days are listed on the Offers page's status strip and the setup checklist. */
    const UNTOLD_FLAG_DAYS = 7;

    /** The most applications one bulk send takes. */
    const BULK_MAX = 200;

    private static $db = null;
    private static $last_sweep = array();
    private static $switched_on = null;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'team_not_selected';
    }

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        // Nothing to hook: the coach portal and the Offers page call send_for_team() / admin_send() / admin_stop() directly.
    }

    // ------------------------------------------------------------------
    // Small readers
    // ------------------------------------------------------------------

    /** One shared TeamOversight_Database. */
    private static function database() {
        if (self::$db === null) {
            self::$db = new TeamOversight_Database();
        }
        return self::$db;
    }

    private static function teams_config($season) {
        return self::database()->get_teams_config((string) $season);
    }

    /** A team code from a request or a row ('' when it isn't one). */
    public static function clean_team($team) {
        $team = is_scalar($team) ? trim((string) $team) : '';
        return preg_match('/^[A-Za-z0-9_-]{1,50}$/D', $team) === 1 ? $team : '';
    }

    /** A team's name for a season (its code when the season's list doesn't have it). */
    public static function team_name($team, $season, $config = null) {
        $config = is_array($config) ? $config : self::teams_config($season);
        return isset($config[$team]['name']) ? (string) $config[$team]['name'] : (string) $team;
    }

    /** The application row, from an id or an object that carries one; null when there is none. */
    private static function load_application($application) {
        global $wpdb;
        $id = 0;
        if (is_object($application) && isset($application->id)) {
            $id = intval($application->id);
        } elseif (is_scalar($application)) {
            $id = intval($application);
        }
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'trial_applications WHERE id = %d', $id));
        return $row ? $row : null;
    }

    /** The team codes an application asked for, in the order they were stored. */
    private static function applied_codes($app) {
        $codes = json_decode((string) $app->interested_teams, true);
        $out = array();
        foreach ((array) $codes as $code) {
            if (is_string($code) && $code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /** team code => verdict status for one application. */
    private static function verdict_map($application_id) {
        global $wpdb;
        $map = array();
        $rows = $wpdb->get_results($wpdb->prepare('SELECT team, status FROM ' . $wpdb->prefix . 'team_trial_selections WHERE application_id = %d', intval($application_id)));
        foreach ((array) $rows as $row) {
            $map[(string) $row->team] = (string) $row->status;
        }
        return $map;
    }

    /** The verdict row of one application for one team (id, status, dates), or null. */
    private static function verdict_row($application_id, $team) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, application_id, season, team, status, created_date, updated_date FROM ' . $wpdb->prefix . 'team_trial_selections WHERE application_id = %d AND team = %s', intval($application_id), (string) $team));
        return $row ? $row : null;
    }

    /** The per-team notice row of one application for one team, or null. */
    private static function row_for($application_id, $team) {
        global $wpdb;
        if ($team === '') {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE application_id = %d AND team = %s', intval($application_id), (string) $team));
        return $row ? $row : null;
    }

    private static function now() {
        return current_time('mysql');
    }

    /** When the verdict was given (its last change, else when it was made; site time where known). */
    private static function verdict_at($verdict) {
        if (!$verdict) {
            return '';
        }
        $at = !empty($verdict->updated_date) ? (string) $verdict->updated_date : (string) $verdict->created_date;
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $at) === 1 ? $at : '';
    }

    /** Whole days from a datetime to now (0 for today or an unknown date). */
    public static function days_since($datetime) {
        $ts = $datetime !== '' ? strtotime((string) $datetime) : false;
        if (!$ts) {
            return 0;
        }
        return max(0, intval(floor((strtotime(self::now()) - $ts) / DAY_IN_SECONDS)));
    }

    private static function log($text, $app = null) {
        if (!class_exists('TeamOversight_Log')) {
            return;
        }
        $args = array();
        if ($app && !empty($app->user_id)) {
            $args['user_id'] = intval($app->user_id);
        }
        TeamOversight_Log::add('offer_flow', $text, $args);
    }

    private static function alert($text) {
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add('offer_alert', $text);
        }
    }

    /** A line from the offer wording registry. */
    private static function w($key, array $vars = array()) {
        return class_exists('TeamOversight_Offers') ? TeamOversight_Offers::wording($key, $vars) : '';
    }

    /** Is the "not selected" email switched on (Emails, Offers and selection)? Remembered for the request. */
    public static function email_switched_on() {
        if (self::$switched_on === null) {
            $settings = class_exists('TeamOversight_Offer_Emails') ? TeamOversight_Offer_Emails::get_settings() : array();
            self::$switched_on = !empty($settings['not_selected']['enabled']);
        }
        return self::$switched_on;
    }

    /** Forget what this request remembered (tests, and after the Emails page saves). */
    public static function flush_cache() {
        self::$switched_on = null;
    }

    /** The outbox key of one notice: not_selected:<application id>:<team>. */
    public static function dedupe_key($application_id, $team) {
        return 'not_selected:' . intval($application_id) . ':' . (string) $team;
    }

    // ------------------------------------------------------------------
    // The one rule: may this (application, team) be told now?
    // ------------------------------------------------------------------

    /**
     * Why this player can't be sent the "not selected" email for a team
     * right now, or null when Send may go ahead. The one rule the coach's
     * card, the bulk panel, the Offers page and the server all ask. Reads
     * only. $application_status / $user_id: the application's; $verdict: the
     * team's verdict status ('' = none); $info: the team's notice
     * (for_applications() shape) or null; $team_name for the wording.
     * Returns array(code, text) with code: sent (already sent: text is the
     * status line), stopped (Don't send was chosen), verdict (not Rejected),
     * unpaid, closed, account, off. Every text is coach-safe.
     */
    public static function block_reason($application_status, $user_id, $verdict, $info, $team_name) {
        $vars = array('{team}' => (string) $team_name);
        if (is_array($info) && isset($info['state'])) {
            if ($info['state'] === 'sent') {
                return array('code' => 'sent', 'text' => self::status_text($info));
            }
            if ($info['state'] === 'stopped') {
                return array('code' => 'stopped', 'text' => self::w('ns_stopped', $vars));
            }
        }
        if ((string) $verdict !== 'rejected') {
            return array('code' => 'verdict', 'text' => self::w('ns_reason_verdict', $vars));
        }
        if ((string) $application_status === 'awaiting_payment') {
            return array('code' => 'unpaid', 'text' => self::w('ns_reason_unpaid', $vars));
        }
        if (!in_array((string) $application_status, self::LIVE_STATUSES, true)) {
            return array('code' => 'closed', 'text' => self::w('ns_reason_closed', $vars));
        }
        $user = intval($user_id) > 0 ? get_userdata(intval($user_id)) : false;
        if (!$user || !is_email($user->user_email)) {
            return array('code' => 'account', 'text' => self::w('ns_reason_account', $vars));
        }
        if (!self::email_switched_on()) {
            return array('code' => 'off', 'text' => self::w('ns_reason_off', $vars));
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Sending (the buttons)
    // ------------------------------------------------------------------

    private static function result($ok, $changed, $code, $message) {
        return array('ok' => (bool) $ok, 'changed' => (bool) $changed, 'code' => (string) $code, 'message' => (string) $message);
    }

    /**
     * Send the "not selected" email for ONE team. The coach portal's button
     * and its bulk send call this after checking the nonce and that the
     * person may edit $team (get_editable_team_codes(), committee edit
     * included); the Offers page goes through admin_send(), which checks
     * can_approve(). Everything else is checked here, on the server: an
     * offers season, an application of that season, block_reason() (this
     * team's verdict Rejected, a live application, an account, the email
     * switched on, never sent or stopped before). The (application, team)
     * row is claimed with ONE INSERT IGNORE (or a compare-and-set from
     * 'failed'); only the winner queues the outbox row, so a double press,
     * a second tab, or a coach and the committee at once send it once.
     * $via: 'coach' | 'admin' (the row's source). Never throws. Returns
     * array(ok, changed, code (sent | already | stopped | season | team |
     * application | verdict | unpaid | closed | account | off | outbox |
     * error), message).
     */
    public static function send_for_team($application_id, $team, $season, $actor_id, $via = 'coach') {
        try {
            return self::send_now($application_id, $team, $season, $actor_id, $via);
        } catch (\Throwable $e) {
            self::alert('The "not selected" email for application ' . intval($application_id) . ' could not be sent (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
            return self::result(false, false, 'error', 'Something went wrong, so nothing was sent. Reload the page and try again.');
        }
    }

    private static function send_now($application_id, $team, $season, $actor_id, $via) {
        global $wpdb;
        $team = self::clean_team($team);
        $season = is_scalar($season) ? (string) $season : '';
        $via = $via === 'admin' ? 'admin' : 'coach';
        $actor_id = intval($actor_id);
        if (!class_exists('TeamOversight_Offers') || !TeamOversight_Offers::applies($season)) {
            return self::result(false, false, 'season', 'Not selected emails are sent from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.');
        }
        if ($team === '') {
            return self::result(false, false, 'team', 'No team was given. Reload the page.');
        }
        $app = self::load_application($application_id);
        if (!$app || (string) $app->season !== $season) {
            return self::result(false, false, 'application', 'That trial application was not found. Reload the page.');
        }
        $config = self::teams_config($season);
        $team_name = self::team_name($team, $season, $config);
        $verdict = self::verdict_row($app->id, $team);
        $row = self::row_for($app->id, $team);
        $info = $row ? self::info_from_row($row, self::email_rows_for(array($row)), self::names_for(array($row))) : null;
        $block = self::block_reason((string) $app->application_status, intval($app->user_id), $verdict ? (string) $verdict->status : '', $info, $team_name);
        if ($block !== null) {
            if ($block['code'] === 'sent') {
                return self::result(true, false, 'already', self::w('ns_already', array('{team}' => $team_name, '{status}' => $block['text'])));
            }
            return self::result(false, false, $block['code'], $block['text']);
        }

        $now = self::now();
        $since = self::verdict_at($verdict);
        $since = $since !== '' ? $since : $now;
        $table = self::table();
        if ($row && $row->state === self::FAILED) {
            $won = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET state = %s, queued_at = %s, send_after = %s, source = %s, acted_by = NULLIF(%d, 0), acted_at = %s WHERE id = %d AND state = %s",
                self::SENT, $now, $now, $via, $actor_id, $now, intval($row->id), self::FAILED
            ));
            $row_id = intval($row->id);
        } else {
            $won = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (application_id, team, season, state, since, send_after, queued_at, source, acted_by, acted_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, NULLIF(%d, 0), %s)",
                intval($app->id), $team, $season, self::SENT, $since, $now, $now, $via, $actor_id, $now
            ));
            $row_id = $won === 1 ? intval($wpdb->insert_id) : 0;
        }
        if ($won !== 1) {
            // Someone got there first (another tab, the committee): say what is there now.
            $fresh = self::row_for($app->id, $team);
            $fresh_info = $fresh ? self::info_from_row($fresh, self::email_rows_for(array($fresh)), self::names_for(array($fresh))) : null;
            if ($fresh_info && $fresh_info['state'] === 'sent') {
                return self::result(true, false, 'already', self::w('ns_already', array('{team}' => $team_name, '{status}' => self::status_text($fresh_info))));
            }
            if ($fresh_info && $fresh_info['state'] === 'stopped') {
                return self::result(false, false, 'stopped', self::w('ns_stopped', array('{team}' => $team_name)));
            }
            return self::result(false, false, 'error', 'That changed while you were looking at it, so nothing was sent. Reload the page.');
        }

        $queued = class_exists('TeamOversight_Offer_Emails')
            ? TeamOversight_Offer_Emails::queue('not_selected', 0, array('application_id' => intval($app->id), 'team' => $team, 'by' => $actor_id, 'via' => $via))
            : array('id' => 0, 'error' => 'The email outbox is not available.');
        if (empty($queued['id'])) {
            // The outbox refused it: the row says so and Send works again.
            $wpdb->query($wpdb->prepare("UPDATE $table SET state = %s WHERE id = %d AND state = %s AND queued_at = %s", self::FAILED, $row_id, self::SENT, $now));
            self::alert('The "not selected" email for application ' . intval($app->id) . ' (' . $season . ' ' . $team . ') could not be queued' . (!empty($queued['error']) ? ' (' . $queued['error'] . ')' : '') . '; nothing was sent and Send works again.');
            return self::result(false, false, 'outbox', 'The email could not be queued, so nothing was sent. Try again in a moment; if it keeps happening, tell the club.');
        }
        self::log('Not-selected email for application ' . intval($app->id) . ' (' . $season . ' ' . $team . ') queued by user #' . $actor_id . ' (' . ($via === 'admin' ? 'Offers page' : 'coach portal') . ').', $app);
        $live = TeamOversight_Offers::emails_live();
        return self::result(true, true, 'sent', self::w($live ? 'ns_done' : 'ns_done_waiting', array('{team}' => $team_name)));
    }

    /**
     * Send for several applications on one team (the coach portal's Send
     * "not selected" emails, which has already checked the nonce and edit
     * access to $team). Each id goes through send_for_team(), so every
     * rule is re-checked one by one. Returns array(sent (ids), already
     * (ids), skipped (id => reason), message).
     */
    public static function send_many($team, $season, array $application_ids, $actor_id) {
        $team = self::clean_team($team);
        $ids = array();
        foreach ($application_ids as $id) {
            if (is_scalar($id) && intval($id) > 0) {
                $ids[] = intval($id);
            }
        }
        $ids = array_slice(array_values(array_unique($ids)), 0, self::BULK_MAX);
        $out = array('sent' => array(), 'already' => array(), 'skipped' => array(), 'message' => '');
        foreach ($ids as $id) {
            $r = self::send_for_team($id, $team, $season, $actor_id, 'coach');
            if (!empty($r['ok']) && !empty($r['changed'])) {
                $out['sent'][] = $id;
            } elseif (!empty($r['ok'])) {
                $out['already'][] = $id;
            } else {
                $out['skipped'][$id] = $r['message'];
            }
        }
        $team_name = $team !== '' ? self::team_name($team, $season) : '';
        $live = class_exists('TeamOversight_Offers') && TeamOversight_Offers::emails_live();
        $out['message'] = self::w($live ? 'ns_bulk_done' : 'ns_bulk_done_waiting', array('{n}' => (string) count($out['sent']), '{team}' => $team_name))
            . (count($out['already']) ? ' ' . count($out['already']) . ' had already been sent theirs.' : '')
            . (count($out['skipped']) ? ' ' . count($out['skipped']) . ' could not be sent:' : '');
        return $out;
    }

    /** The verdict a row of the Offers page's Not selected tab was drawn from (by its id), or null. */
    private static function selection($selection_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, application_id, season, team, status, created_date, updated_date FROM ' . $wpdb->prefix . 'team_trial_selections WHERE id = %d', intval($selection_id)));
        return $row ? $row : null;
    }

    /**
     * The Offers page's Send (and Send all): administrators only
     * (TeamOversight_Offers::can_approve()). $selection_id is the verdict
     * row the page showed (one per application and team), so a verdict
     * cleared or changed since is refused. Then send_for_team(). Returns
     * its answer.
     */
    public static function admin_send($selection_id, $actor_id) {
        if (!class_exists('TeamOversight_Offers') || !TeamOversight_Offers::can_approve($actor_id)) {
            return self::result(false, false, 'denied', 'Only administrators can do this.');
        }
        $sel = self::selection($selection_id);
        if (!$sel) {
            return self::result(false, false, 'verdict', 'That verdict no longer exists (the coach changed it). Reload the page.');
        }
        return self::send_for_team(intval($sel->application_id), (string) $sel->team, (string) $sel->season, $actor_id, 'admin');
    }

    /**
     * The Offers page's Don't send: administrators only. Records a final
     * 'stopped' row for the verdict's (application, team): never sent, kept
     * as a record, and the coach's card says the club decided not to send
     * it. Only for a Rejected verdict that hasn't been told (a sent one
     * can't be taken back). One INSERT IGNORE (or a compare-and-set from
     * 'failed'). Returns array(ok, changed, code, message).
     */
    public static function admin_stop($selection_id, $actor_id) {
        global $wpdb;
        if (!class_exists('TeamOversight_Offers') || !TeamOversight_Offers::can_approve($actor_id)) {
            return self::result(false, false, 'denied', 'Only administrators can do this.');
        }
        $sel = self::selection($selection_id);
        if (!$sel || (string) $sel->status !== 'rejected') {
            return self::result(false, false, 'verdict', 'That player is no longer Rejected for that team. Reload the page.');
        }
        $team = self::clean_team($sel->team);
        if ($team === '' || !TeamOversight_Offers::applies((string) $sel->season)) {
            return self::result(false, false, 'season', 'Not selected emails are sent from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.');
        }
        $team_name = self::team_name($team, $sel->season);
        $row = self::row_for($sel->application_id, $team);
        $now = self::now();
        $table = self::table();
        if ($row && $row->state === self::SENT) {
            return self::result(false, false, 'sent', 'That email has already been sent and can\'t be taken back.');
        }
        if ($row && $row->state === self::STOPPED) {
            return self::result(true, false, 'already', 'Nothing to do: it was already marked Don\'t send.');
        }
        if ($row && $row->state === self::FAILED) {
            $won = $wpdb->query($wpdb->prepare("UPDATE $table SET state = %s, acted_by = NULLIF(%d, 0), acted_at = %s, source = 'admin' WHERE id = %d AND state = %s", self::STOPPED, intval($actor_id), $now, intval($row->id), self::FAILED));
        } else {
            $since = self::verdict_at($sel);
            $won = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (application_id, team, season, state, since, send_after, queued_at, source, acted_by, acted_at) VALUES (%d, %s, %s, %s, %s, %s, NULL, 'admin', NULLIF(%d, 0), %s)",
                intval($sel->application_id), $team, (string) $sel->season, self::STOPPED, $since !== '' ? $since : $now, $now, intval($actor_id), $now
            ));
        }
        if ($won !== 1) {
            return self::result(false, false, 'error', 'That changed while you were looking at it. Reload the page.');
        }
        self::log('Not-selected email for application ' . intval($sel->application_id) . ' (' . $sel->season . ' ' . $team . ') marked Don\'t send by user #' . intval($actor_id) . '.', self::load_application($sel->application_id));
        return self::result(true, true, 'stopped', 'Marked Don\'t send: they won\'t be emailed about ' . $team_name . '. It is kept as a record.');
    }

    /**
     * The outbox's check just before it sends (TeamOversight_Offer_Emails
     * prepare_not_selected()): the notice still applies when the
     * application exists and is live, THIS team's verdict is still Rejected
     * and the account exists. Returns array(ok, note, app).
     */
    public static function send_check($application_id, $team) {
        $app = self::load_application($application_id);
        if (!$app) {
            return array('ok' => false, 'note' => 'The application no longer exists.', 'app' => null);
        }
        if (!in_array((string) $app->application_status, self::LIVE_STATUSES, true)) {
            return array('ok' => false, 'note' => 'The application is no longer open (' . $app->application_status . ').', 'app' => $app);
        }
        $verdict = self::verdict_row($app->id, $team);
        if (!$verdict || (string) $verdict->status !== 'rejected') {
            return array('ok' => false, 'note' => 'The verdict for ' . $team . ' is no longer Rejected, so the notice no longer applies.', 'app' => $app);
        }
        return array('ok' => true, 'note' => '', 'app' => $app);
    }

    // ------------------------------------------------------------------
    // Reading the notices (the coach portal and the Offers page)
    // ------------------------------------------------------------------

    /** Outbox rows for some notice rows: dedupe key => the status_for_keys() row (a row mapped from before 1.80.0 reads its old per-application message). */
    private static function email_rows_for(array $rows) {
        $keys = array();
        foreach ($rows as $row) {
            $keys[] = self::email_key_of($row);
        }
        return (class_exists('TeamOversight_Offer_Emails') && !empty($keys)) ? TeamOversight_Offer_Emails::status_for_keys($keys) : array();
    }

    private static function email_key_of($row) {
        return ((string) $row->source === 'legacy' || (string) $row->team === '') ? 'not_selected:' . intval($row->application_id) : self::dedupe_key($row->application_id, $row->team);
    }

    /** acted_by => display name for some rows (one cache_users()). */
    private static function names_for(array $rows) {
        $ids = array();
        foreach ($rows as $row) {
            if (intval($row->acted_by) > 0) {
                $ids[] = intval($row->acted_by);
            }
        }
        $ids = array_values(array_unique($ids));
        $out = array();
        if (!empty($ids)) {
            cache_users($ids);
            foreach ($ids as $id) {
                $user = get_userdata($id);
                $out[$id] = $user ? (string) $user->display_name : '';
            }
        }
        return $out;
    }

    /** One notice row as the readers use it. */
    private static function info_from_row($row, array $emails, array $names) {
        $state = (string) $row->state;
        $public = $state === self::SENT ? 'sent' : ($state === self::STOPPED ? 'stopped' : ($state === self::FAILED ? 'failed' : $state));
        $key = self::email_key_of($row);
        $email = isset($emails[$key]) ? $emails[$key] : null;
        $by = intval($row->acted_by);
        $at = !empty($row->acted_at) ? (string) $row->acted_at : (!empty($row->queued_at) ? (string) $row->queued_at : (string) $row->since);
        return array(
            'id' => intval($row->id),
            'application_id' => intval($row->application_id),
            'team' => (string) $row->team,
            'season' => (string) $row->season,
            'state' => $public,
            'source' => (string) $row->source,
            'legacy' => (string) $row->source === 'legacy',
            'by' => $by,
            'by_name' => ($by > 0 && isset($names[$by])) ? $names[$by] : '',
            'at' => $at,
            'since' => (string) $row->since,
            'email_status' => $email ? (string) $email['status'] : '',
            'email_sent_at' => $email ? (string) $email['sent_at'] : '',
            'email_note' => $email ? (string) $email['note'] : '',
            'email_row_id' => $email ? intval($email['id']) : 0,
        );
    }

    /**
     * Every per-team notice of some applications, for the coach portal's
     * cards: application id => team => info (id, team, season, state
     * (sent | stopped | failed), source (coach | admin | legacy), legacy,
     * by, by_name, at (when the button was pressed), since, email_status
     * (the outbox row's status, '' when none), email_sent_at, email_note,
     * email_row_id). Three queries whatever the number (the rows, the
     * outbox, the names). Rows from before 1.80.0 (team '') are left out.
     */
    public static function for_applications(array $application_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $application_ids))));
        if (empty($ids)) {
            return array();
        }
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE team <> '' AND application_id IN (" . implode(',', $ids) . ') ORDER BY id');
        if (empty($rows)) {
            return array();
        }
        $emails = self::email_rows_for($rows);
        $names = self::names_for($rows);
        $out = array();
        foreach ($rows as $row) {
            $out[intval($row->application_id)][(string) $row->team] = self::info_from_row($row, $emails, $names);
        }
        return $out;
    }

    /** "28 Oct" from a datetime ('' when it isn't one). */
    private static function short($datetime) {
        $datetime = (string) $datetime;
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $datetime) === 1 ? date('j M', strtotime(substr($datetime, 0, 10))) : '';
    }

    /**
     * Where a sent notice is up to, in the coach's words (the wording
     * registry; no figure): sent (date, who pressed it), waiting for the
     * club's email permission, sending, held, a problem, skipped, or
     * Don't send. $info: one for_applications() entry.
     */
    public static function status_text(array $info) {
        $name = isset($info['by_name']) && $info['by_name'] !== '' ? (string) $info['by_name'] : self::w('ns_by_club');
        $pressed = self::short(isset($info['at']) ? $info['at'] : '');
        $vars = array('{date}' => $pressed, '{name}' => $name, '{team}' => isset($info['team']) ? (string) $info['team'] : '');
        if (isset($info['state']) && $info['state'] === 'stopped') {
            return self::w('ns_stopped_line', $vars);
        }
        if (isset($info['state']) && $info['state'] === 'failed') {
            return self::w('ns_problem', $vars); // the outbox refused it: nothing went (Send works again)
        }
        $email = isset($info['email_status']) ? (string) $info['email_status'] : '';
        if ($email === 'sent') {
            $sent = self::short(isset($info['email_sent_at']) ? $info['email_sent_at'] : '');
            $vars['{date}'] = $sent !== '' ? $sent : $pressed;
            return self::w(!empty($info['by_name']) ? 'ns_sent' : 'ns_sent_auto', $vars);
        }
        if (!empty($info['legacy'])) {
            // Mapped from a notice sent before 1.80.0 (one per player): it went out under its old message.
            return self::w('ns_sent_auto', $vars);
        }
        if ($email === 'held') {
            return self::w('ns_held', $vars);
        }
        if ($email === 'failed' || $email === 'unknown') {
            return self::w('ns_problem', $vars);
        }
        if ($email === 'skipped') {
            return self::w('ns_skipped', $vars);
        }
        $live = class_exists('TeamOversight_Offers') && TeamOversight_Offers::emails_live();
        return self::w($live ? 'ns_sending' : 'ns_waiting', $vars);
    }

    /**
     * The Rejected players of one team (the coach portal's board: the bulk
     * panel and the flow chart's count), one query: a list of objects with
     * selection_id, application_id, name, email, trial_number, user_id,
     * application_status, verdict_at, notice (for_applications() entry or
     * null), reason (block_reason() or null). Applications that are no
     * longer open (rejected by the club, expired, deleted) are left out,
     * like everywhere on the portal.
     */
    public static function team_rejections($team, $season) {
        global $wpdb;
        $team = self::clean_team($team);
        if ($team === '' || !preg_match('/^\d{4}$/D', (string) $season)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS selection_id, s.application_id, s.created_date, s.updated_date, a.name, a.email, a.trial_number, a.user_id, a.application_status
             FROM {$wpdb->prefix}team_trial_selections s
             JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
             WHERE s.team = %s AND s.season = %s AND s.status = 'rejected'
               AND a.application_status IN ('pending', 'accepted', 'awaiting_payment')
             ORDER BY a.trial_number, a.id",
            $team, (string) $season
        ));
        if (empty($rows)) {
            return array();
        }
        $ids = array();
        $people = array();
        foreach ($rows as $row) {
            $ids[] = intval($row->application_id);
            if (intval($row->user_id) > 0) {
                $people[] = intval($row->user_id);
            }
        }
        if ($people) {
            cache_users(array_values(array_unique($people)));
        }
        $notices = self::for_applications($ids);
        $team_name = self::team_name($team, $season);
        $out = array();
        foreach ($rows as $row) {
            $info = isset($notices[intval($row->application_id)][$team]) ? $notices[intval($row->application_id)][$team] : null;
            $row->selection_id = intval($row->selection_id);
            $row->application_id = intval($row->application_id);
            $row->verdict_at = !empty($row->updated_date) ? (string) $row->updated_date : (string) $row->created_date;
            $row->notice = $info;
            $row->reason = self::block_reason((string) $row->application_status, intval($row->user_id), 'rejected', $info, $team_name);
            $out[] = $row;
        }
        return $out;
    }

    /** Is a team_rejections() / untold() row still to be told (no notice, or one the outbox refused)? */
    public static function is_untold($row) {
        $info = is_object($row) ? $row->notice : (isset($row['notice']) ? $row['notice'] : null);
        return !$info || $info['state'] === 'failed';
    }

    /**
     * The Offers page's Not selected tab, part 1: every Rejected verdict of
     * the season whose player hasn't been told (no notice row, or one the
     * outbox refused) and whose application is still open (pending,
     * accepted, or Payment pending, which shows why it can't be sent), oldest
     * verdict first. A list of arrays: selection_id, application_id, team,
     * team_name, name, trial_number, user_id, application_status,
     * verdict_at, days (since the verdict), reason (block_reason() or null).
     */
    public static function untold($season) {
        global $wpdb;
        $season = is_scalar($season) ? (string) $season : '';
        if (!preg_match('/^\d{4}$/D', $season) || !class_exists('TeamOversight_Offers') || !TeamOversight_Offers::applies($season)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS selection_id, s.application_id, s.team, s.created_date, s.updated_date, a.name, a.trial_number, a.user_id, a.application_status
             FROM {$wpdb->prefix}team_trial_selections s
             JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
             LEFT JOIN " . self::table() . " n ON n.application_id = s.application_id AND n.team = s.team
             WHERE s.season = %s AND s.status = 'rejected' AND a.application_status IN ('pending', 'accepted', 'awaiting_payment')
               AND (n.id IS NULL OR n.state = %s)
             ORDER BY COALESCE(s.updated_date, s.created_date), s.id LIMIT 1000",
            $season, self::FAILED
        ));
        if (empty($rows)) {
            return array();
        }
        $people = array();
        foreach ($rows as $row) {
            if (intval($row->user_id) > 0) {
                $people[] = intval($row->user_id);
            }
        }
        if ($people) {
            cache_users(array_values(array_unique($people)));
        }
        $config = self::teams_config($season);
        $out = array();
        foreach ($rows as $row) {
            $at = !empty($row->updated_date) ? (string) $row->updated_date : (string) $row->created_date;
            $name = self::team_name($row->team, $season, $config);
            $out[] = array(
                'selection_id' => intval($row->selection_id),
                'application_id' => intval($row->application_id),
                'team' => (string) $row->team,
                'team_name' => $name,
                'name' => (string) $row->name,
                'trial_number' => intval($row->trial_number),
                'user_id' => intval($row->user_id),
                'application_status' => (string) $row->application_status,
                'verdict_at' => $at,
                'days' => self::days_since($at),
                'reason' => self::block_reason((string) $row->application_status, intval($row->user_id), 'rejected', null, $name),
            );
        }
        return $out;
    }

    /**
     * How many rejections of a season (or, with '', every offers season)
     * have not been told, counting only those whose verdict is at least
     * $older_than_days old. One query. The status strip, the setup
     * checklist and the one-off upgrade's note read it.
     */
    public static function untold_count($season = '', $older_than_days = 0) {
        global $wpdb;
        $where = array("s.status = 'rejected'", "a.application_status IN ('pending', 'accepted', 'awaiting_payment')", '(n.id IS NULL OR n.state = %s)');
        $args = array(self::FAILED);
        if ($season !== '' && $season !== null) {
            if (!preg_match('/^\d{4}$/D', (string) $season) || !class_exists('TeamOversight_Offers') || !TeamOversight_Offers::applies((string) $season)) {
                return 0;
            }
            $where[] = 's.season = %s';
            $args[] = (string) $season;
        } else {
            $where[] = 's.season REGEXP %s AND CAST(s.season AS UNSIGNED) >= %d';
            $args[] = '^[0-9]{4}$';
            $args[] = intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM);
        }
        if (intval($older_than_days) > 0) {
            $where[] = 'COALESCE(s.updated_date, s.created_date) < %s';
            $args[] = date('Y-m-d H:i:s', strtotime(self::now()) - intval($older_than_days) * DAY_IN_SECONDS);
        }
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}team_trial_selections s
             JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
             LEFT JOIN " . self::table() . " n ON n.application_id = s.application_id AND n.team = s.team
             WHERE " . implode(' AND ', $where),
            $args
        )));
    }

    /**
     * The one-off upgrade's backlog step (TeamOversight_Offers::
     * maybe_upgrade()): since 1.80.0 it writes NOTHING. Players rejected
     * before offers began are simply "not told yet" (the coach's card, the
     * Not selected tab), and nothing is sent unless someone presses Send.
     * Returns how many (player, team) rejections are not told, for the
     * upgrade's flag and log line.
     */
    public static function backlog_count() {
        return self::untold_count('', 0);
    }

    /**
     * The Offers page's Not selected tab, part 2: every per-team notice of
     * the season (sent, Don't send, refused by the outbox), newest first. A
     * list of arrays: the for_applications() info plus name, trial_number,
     * team_name, verdict (the team's verdict now, '' when none) and text
     * (status_text()).
     */
    public static function record($season) {
        global $wpdb;
        $season = is_scalar($season) ? (string) $season : '';
        if (!preg_match('/^\d{4}$/D', $season)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT n.*, a.name AS app_name, a.trial_number, s.status AS verdict FROM ' . self::table() . " n
             LEFT JOIN {$wpdb->prefix}trial_applications a ON a.id = n.application_id
             LEFT JOIN {$wpdb->prefix}team_trial_selections s ON s.application_id = n.application_id AND s.team = n.team
             WHERE n.season = %s AND n.team <> '' ORDER BY COALESCE(n.acted_at, n.queued_at, n.since) DESC, n.id DESC LIMIT 500",
            $season
        ));
        if (empty($rows)) {
            return array();
        }
        $emails = self::email_rows_for($rows);
        $names = self::names_for($rows);
        $config = self::teams_config($season);
        $out = array();
        foreach ($rows as $row) {
            $info = self::info_from_row($row, $emails, $names);
            $info['name'] = $row->app_name !== null ? (string) $row->app_name : '(application deleted)';
            $info['trial_number'] = $row->trial_number !== null ? intval($row->trial_number) : 0;
            $info['team_name'] = self::team_name($row->team, $season, $config);
            $info['verdict'] = $row->verdict !== null ? (string) $row->verdict : '';
            $info['text'] = self::status_text($info);
            $out[] = $info;
        }
        return $out;
    }

    /**
     * The Offers page's Not selected tab, part 3: the notices from before
     * 1.80.0 (one per application, team ''), kept as history and never
     * acted on. A list of arrays: application_id, name, trial_number,
     * state (scheduled | held | queued | cancelled; an administrator's
     * cancel reads 'cancelled'), since, send_after, queued_at, email_status.
     */
    public static function legacy_rows($season) {
        global $wpdb;
        $season = is_scalar($season) ? (string) $season : '';
        if (!preg_match('/^\d{4}$/D', $season)) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT n.*, a.name AS app_name, a.trial_number FROM ' . self::table() . " n
             LEFT JOIN {$wpdb->prefix}trial_applications a ON a.id = n.application_id
             WHERE n.season = %s AND n.team = '' ORDER BY n.since DESC, n.id DESC LIMIT 500",
            $season
        ));
        if (empty($rows)) {
            return array();
        }
        $emails = self::email_rows_for($rows);
        $out = array();
        foreach ($rows as $row) {
            $key = 'not_selected:' . intval($row->application_id);
            $out[] = array(
                'application_id' => intval($row->application_id),
                'name' => $row->app_name !== null ? (string) $row->app_name : '(application deleted)',
                'trial_number' => $row->trial_number !== null ? intval($row->trial_number) : 0,
                'state' => (string) $row->state === self::STOPPED ? 'cancelled' : (string) $row->state,
                'since' => (string) $row->since,
                'send_after' => (string) $row->send_after,
                'queued_at' => (string) $row->queued_at,
                'email_status' => isset($emails[$key]) ? (string) $emails[$key]['status'] : '',
            );
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The one-off data step and the daily repair
    // ------------------------------------------------------------------

    /**
     * 1.80.0, once per site (TeamOversight_Database::migrate_database() calls
     * it on every load; the autoloaded flag MIGRATE_OPTION makes that free
     * once done). A notice from before 1.80.0 was one per application. Each
     * such row that was SENT ('queued') or that an administrator cancelled
     * for good ('stopped') is mapped to a per-team row of the same state for
     * every team that has the player Rejected now (source 'legacy', the old
     * dates kept), so those teams' cards show "sent" (or Don't send) and
     * never offer the button again. Every other old row (scheduled, held,
     * cancelled) told nobody, so its rejections are simply "not told yet":
     * nothing is written for them and nothing is sent. The old rows are
     * never changed or deleted. INSERT IGNORE on the (application, team)
     * key, under a named lock, flag written last: idempotent. Returns the
     * flag, or null when it could not run on this request.
     */
    public static function migrate_legacy_rows() {
        global $wpdb;
        $flag = get_option(self::MIGRATE_OPTION, null);
        if (is_array($flag) && !empty($flag['done'])) {
            return $flag;
        }
        $table = self::table();
        if (empty($wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'acted_at'")) || !empty($wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'application'"))) {
            return null; // the structural step has not finished: the next request tries again
        }
        $lock = 'murvc_ns_per_team_' . md5($wpdb->prefix);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return null;
        }
        try {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::MIGRATE_OPTION));
            $stored = $raw === null ? null : maybe_unserialize($raw);
            if (is_array($stored) && !empty($stored['done'])) {
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete(self::MIGRATE_OPTION, 'options');
                return $stored;
            }
            $legacy = $wpdb->get_results("SELECT * FROM $table WHERE team = '' ORDER BY id");
            $mapped = 0;
            $kept = 0;
            foreach ((array) $legacy as $old) {
                if (!in_array((string) $old->state, array(self::SENT, self::STOPPED), true)) {
                    $kept++;
                    continue;
                }
                $teams = $wpdb->get_col($wpdb->prepare("SELECT team FROM {$wpdb->prefix}team_trial_selections WHERE application_id = %d AND status = 'rejected' ORDER BY team", intval($old->application_id)));
                foreach ((array) $teams as $team) {
                    $team = self::clean_team($team);
                    if ($team === '') {
                        continue;
                    }
                    $at = !empty($old->queued_at) ? (string) $old->queued_at : (string) $old->send_after;
                    $n = $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO $table (application_id, team, season, state, since, send_after, queued_at, source, acted_by, acted_at) VALUES (%d, %s, %s, %s, %s, %s, " . (empty($old->queued_at) ? 'NULL' : '%s') . ", 'legacy', NULL, %s)",
                        array_merge(
                            array(intval($old->application_id), $team, (string) $old->season, (string) $old->state, (string) $old->since, (string) $old->send_after),
                            empty($old->queued_at) ? array() : array((string) $old->queued_at),
                            array($at)
                        )
                    ));
                    if ($n === 1) {
                        $mapped++;
                    }
                }
            }
            $flag = array('done' => true, 'at' => self::now(), 'legacy' => count((array) $legacy), 'mapped' => $mapped, 'not_sent' => $kept, 'version' => defined('TEAM_OVERSIGHT_VERSION') ? TEAM_OVERSIGHT_VERSION : '');
            update_option(self::MIGRATE_OPTION, $flag, true);
            if (!empty($legacy)) {
                self::log('"Not selected" emails are now one per team and only sent when someone presses a button (1.80.0): ' . count((array) $legacy) . ' earlier notice(s) kept as history, ' . $mapped . ' team notice(s) recorded from the ones already sent or cancelled, and ' . $kept . ' earlier notice(s) had told nobody (their players\' rejections are listed as not told yet). Nothing was sent.');
            }
            return $flag;
        } finally {
            if ($got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * The daily job's not-selected step (step 5 of TeamOversight_Offers::
     * run_daily()). Since 1.80.0 it decides nothing and schedules nothing:
     * it only completes a press whose outbox row went missing (the request
     * died between claiming the row and queuing the message), for notices
     * pressed in the last 14 days (source coach or admin), while the email
     * permission is open. The outbox's dedupe key makes a repeat harmless.
     * $season (optional) limits it to one season. Returns the number
     * repaired; last_sweep() says what it did.
     */
    public static function sweep($season = null) {
        global $wpdb;
        self::$last_sweep = array('repaired' => 0, 'skipped' => '');
        if (!class_exists('TeamOversight_Offers') || !class_exists('TeamOversight_Offer_Emails')) {
            self::$last_sweep['skipped'] = 'Offers are not available.';
            return 0;
        }
        if (!TeamOversight_Offers::emails_live()) {
            self::$last_sweep['skipped'] = 'This website may not send offer emails.';
            return 0;
        }
        $sql = 'SELECT * FROM ' . self::table() . " WHERE state = %s AND team <> '' AND source IN ('coach', 'admin') AND queued_at >= %s";
        $args = array(self::SENT, date('Y-m-d H:i:s', strtotime(self::now()) - 14 * DAY_IN_SECONDS));
        if ($season !== null) {
            $sql .= ' AND season = %s';
            $args[] = (string) $season;
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql . ' LIMIT 500', $args));
        if (empty($rows)) {
            return 0;
        }
        $present = self::email_rows_for($rows);
        foreach ($rows as $row) {
            if (isset($present[self::email_key_of($row)])) {
                continue;
            }
            $q = TeamOversight_Offer_Emails::queue('not_selected', 0, array('application_id' => intval($row->application_id), 'team' => (string) $row->team, 'by' => intval($row->acted_by), 'via' => (string) $row->source));
            if (!empty($q['queued'])) {
                self::$last_sweep['repaired']++;
            }
        }
        return self::$last_sweep['repaired'];
    }

    /** What the last sweep() did: repaired, skipped. */
    public static function last_sweep() {
        return self::$last_sweep;
    }

    // ------------------------------------------------------------------
    // What the email says
    // ------------------------------------------------------------------

    /**
     * What the "not selected" email says for ONE team (the contract between
     * this class and the 'not_selected' email, which draws {next_steps} and
     * {other_teams} from it). $application: an id or a trial_applications
     * row (re-read by id); $team: the team code the notice is about.
     * Returns array(
     *   application_id  int,
     *   team            the team code,
     *   team_name       its name,
     *   rejected_teams  array(team_name) (kept for wording saved before 1.80.0),
     *   multi           bool: the player applied for at least one team other
     *                   than this one (the email then says this message is
     *                   only about this team). Nothing about any other team's
     *                   verdict or offer is in here,
     *   suggestions     list of array(label, level, codes): the divisions
     *                   below THIS team they are eligible for (gender, age
     *                   rule, date of birth), leaving out every team they
     *                   applied for (they are already being considered there)
     *                   and every team that has them Rejected, highest
     *                   division first, colour variants grouped ("State
     *                   League 2 Men (Blue or White)"). EMPTY unless can_edit
     *                   is true, so "suggestions, else pathway" is the whole
     *                   rule,
     *   eligible        the same list whatever can_edit says (for previews),
     *   can_edit        bool: applications are open for the season and this
     *                   application is still pending (editable),
     *   edit_url        the trial form (TeamOversight_Trial_Emails::get_form_url()),
     *   pathway_kind    'adult' | 'junior' (a date of birth on or after the
     *                   season's U19 cutoff = junior; unknown = adult),
     *   pathway         list of array(label, url): Mixed Training Program and
     *                   Reds Social League (adults) or the Junior Academy
     *                   (juniors, as extra training), from
     *                   team_oversight_pathway_pages; a page that is missing or
     *                   not published is left out
     * ).
     * An unknown application, or no team, gives an empty array.
     */
    public static function notice_content($application, $team = '') {
        $app = self::load_application($application);
        $team = self::clean_team($team);
        if (!$app || $team === '') {
            return array();
        }
        $season = (string) $app->season;
        $config = self::teams_config($season);
        $verdicts = self::verdict_map($app->id);
        $applied = self::applied_codes($app);
        $team_name = self::team_name($team, $season, $config);

        $others = array();
        foreach ($applied as $code) {
            if ($code !== $team) {
                $others[] = $code;
            }
        }

        $dob = self::dob_for($app);
        $eligible = self::eligible_lower_divisions($app, $config, $verdicts, $applied, $dob, $team);
        $can_edit = (string) $app->application_status === 'pending'
            && class_exists('TeamOversight_Trials') && TeamOversight_Trials::applications_open($season);

        $junior = false;
        if ($dob !== '') {
            $cutoff = TeamOversight_Database::get_dob_cutoff('u19', $season);
            $junior = $cutoff !== null && $dob >= $cutoff;
        }
        $kind = $junior ? 'junior' : 'adult';

        return array(
            'application_id' => intval($app->id),
            'team' => $team,
            'team_name' => $team_name,
            'rejected_teams' => array($team_name),
            'multi' => !empty($others),
            'suggestions' => $can_edit ? $eligible : array(),
            'eligible' => $eligible,
            'can_edit' => $can_edit,
            'edit_url' => class_exists('TeamOversight_Trial_Emails') ? TeamOversight_Trial_Emails::get_form_url() : home_url('/'),
            'pathway_kind' => $kind,
            'pathway' => self::pathway_links($kind),
        );
    }

    /** The player's date of birth as Y-m-d, from the profile (as the trial form reads it), else the application's snapshot; '' when unknown. */
    private static function dob_for($app) {
        $dob = self::normalise_dob(get_user_meta(intval($app->user_id), 'birth_date', true));
        if ($dob === '') {
            $form = json_decode((string) $app->form_data, true);
            if (is_array($form) && isset($form['Date of Birth (at submission)'])) {
                $dob = self::normalise_dob($form['Date of Birth (at submission)']);
            }
        }
        return $dob;
    }

    private static function normalise_dob($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime(str_replace('/', '-', $raw));
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /**
     * The lower divisions an applicant could still try after THIS team
     * said no, grouped. The baseline is this team's level; candidates are
     * the season's teams with a higher level number (level 0 = not set is
     * never a candidate, and a team with no level suggests nothing), that
     * the player did not apply for (they are already considered there) and
     * that do not have them Rejected, with a gender that is Mixed or their
     * competition and an age rule their date of birth meets (an age-ruled
     * team is not suggested when the date of birth is unknown). A team's
     * Tentative, Selected or Training Only verdict on a team they did not
     * apply for changes nothing, so the list never hints at another team's
     * verdict. Without a competition (no stored answer, no binary profile
     * gender) only Mixed teams qualify.
     */
    private static function eligible_lower_divisions($app, array $config, array $verdicts, array $applied, $dob, $team) {
        $baseline = isset($config[$team]) ? intval($config[$team]['level']) : 0;
        if ($baseline <= 0) {
            return array();
        }
        $competition = class_exists('TeamOversight_Trials') ? TeamOversight_Trials::application_competition($app) : null;
        $season = (string) $app->season;

        $groups = array();
        foreach ($config as $code => $info) {
            $level = intval($info['level']);
            if ($level <= $baseline || in_array($code, $applied, true) || (isset($verdicts[$code]) && $verdicts[$code] === 'rejected')) {
                continue;
            }
            if ($info['gender'] !== 'mixed' && ($competition === null || $info['gender'] !== $competition)) {
                continue;
            }
            if (!empty($info['age_rule'])) {
                $cutoff = TeamOversight_Database::get_dob_cutoff($info['age_rule'], $season);
                if ($cutoff !== null && ($dob === '' || $dob < $cutoff)) {
                    continue;
                }
            }
            list($base, $colour) = self::split_colour($info['name']);
            $key = $level . '|' . $info['gender'] . '|' . strtolower($base);
            if (!isset($groups[$key])) {
                $groups[$key] = array('level' => $level, 'gender' => $info['gender'], 'base' => $base, 'members' => array());
            }
            $groups[$key]['members'][] = array('code' => $code, 'name' => $info['name'], 'colour' => $colour);
        }

        $gender_order = array('mens' => 0, 'womens' => 1, 'mixed' => 2);
        uasort($groups, function ($a, $b) use ($gender_order) {
            if ($a['level'] !== $b['level']) {
                return $a['level'] - $b['level'];
            }
            if ($a['gender'] !== $b['gender']) {
                return $gender_order[$a['gender']] - $gender_order[$b['gender']];
            }
            return strcmp($a['base'], $b['base']);
        });

        $out = array();
        foreach ($groups as $group) {
            $members = $group['members'];
            $codes = array();
            $colours = array();
            foreach ($members as $m) {
                $codes[] = $m['code'];
                if ($m['colour'] !== '') {
                    $colours[] = $m['colour'];
                }
            }
            if (count($members) === 1 || count($colours) !== count($members)) {
                $label = $members[0]['name'];
            } else {
                sort($colours);
                $label = $group['base'] . ' (' . self::join_or($colours) . ')';
            }
            $out[] = array('label' => $label, 'level' => $group['level'], 'codes' => $codes);
        }
        return $out;
    }

    /** "State League 1 Men Blue" -> array('State League 1 Men', 'Blue'); a name with no trailing colour word -> array(name, ''). */
    private static function split_colour($name) {
        $name = trim((string) $name);
        $colours = array('blue', 'white', 'red', 'black', 'green', 'gold', 'yellow', 'orange', 'purple', 'navy', 'grey', 'gray', 'silver', 'maroon', 'pink');
        $pos = strrpos($name, ' ');
        if ($pos === false) {
            return array($name, '');
        }
        $last = substr($name, $pos + 1);
        if (in_array(strtolower($last), $colours, true)) {
            return array(rtrim(substr($name, 0, $pos)), $last);
        }
        return array($name, '');
    }

    private static function join_or(array $items) {
        $items = array_values($items);
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' or ' . $last;
    }

    // ------------------------------------------------------------------
    // The pathway pages (what a player is pointed to when no lower division is left)
    // ------------------------------------------------------------------

    /** The built-in page ids and fragment: Mixed Training Program, Reds Social League, Junior Academy (Juniors page, #academy). */
    public static function pathway_defaults() {
        return array('mixed_training' => 1071, 'social_league' => 1079, 'junior_academy' => 1149, 'junior_anchor' => 'academy');
    }

    /** The pathway pages in force: the saved option over the defaults, anything unusable ignored. */
    public static function pathway_pages() {
        $out = self::pathway_defaults();
        $saved = get_option(self::PATHWAY_OPTION, array());
        if (is_array($saved)) {
            foreach (array('mixed_training', 'social_league', 'junior_academy') as $key) {
                if (isset($saved[$key]) && is_scalar($saved[$key]) && intval($saved[$key]) > 0) {
                    $out[$key] = intval($saved[$key]);
                }
            }
            if (isset($saved['junior_anchor']) && is_string($saved['junior_anchor']) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $saved['junior_anchor'])) {
                $out['junior_anchor'] = $saved['junior_anchor'];
            }
        }
        return $out;
    }

    /**
     * Save the pathway pages (the Emails page's Offer links box calls this).
     * $input: mixed_training, social_league, junior_academy (page ids) and
     * junior_anchor; a blank value means the built-in default. A page must be
     * a published page or post. Only values that differ from the defaults are
     * stored. Returns array(ok, changed, errors, message, values).
     */
    public static function save_pathway_pages(array $input, $actor_id = 0) {
        $defaults = self::pathway_defaults();
        $labels = array('mixed_training' => 'Mixed Training Program', 'social_league' => 'Reds Social League', 'junior_academy' => 'Junior Academy');
        $clean = array();
        $errors = array();
        foreach ($labels as $key => $label) {
            $raw = isset($input[$key]) ? trim((string) $input[$key]) : '';
            if ($raw === '') {
                continue;
            }
            if (!ctype_digit($raw) || intval($raw) < 1) {
                $errors[] = $label . ': enter a page number.';
                continue;
            }
            $id = intval($raw);
            $post = get_post($id);
            if (!$post || !in_array($post->post_type, array('page', 'post'), true) || $post->post_status !== 'publish') {
                $errors[] = $label . ': page ' . $id . ' was not found or is not published.';
                continue;
            }
            if ($id !== $defaults[$key]) {
                $clean[$key] = $id;
            }
        }
        $anchor = isset($input['junior_anchor']) ? trim(ltrim(trim((string) $input['junior_anchor']), '#')) : '';
        if ($anchor !== '') {
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $anchor)) {
                $errors[] = 'Junior Academy section: use letters, numbers, dashes and underscores only.';
            } elseif ($anchor !== $defaults['junior_anchor']) {
                $clean['junior_anchor'] = $anchor;
            }
        }
        if (!empty($errors)) {
            return array('ok' => false, 'changed' => false, 'errors' => $errors, 'message' => implode(' ', $errors), 'values' => self::pathway_pages());
        }
        $old = get_option(self::PATHWAY_OPTION, array());
        $old = is_array($old) ? $old : array();
        ksort($old);
        ksort($clean);
        $changed = wp_json_encode($old) !== wp_json_encode($clean);
        if ($changed) {
            if (empty($clean)) {
                delete_option(self::PATHWAY_OPTION);
            } else {
                update_option(self::PATHWAY_OPTION, $clean, false);
            }
            if (class_exists('TeamOversight_Log')) {
                TeamOversight_Log::add('offer_flow', 'The pages a not-selected email points to were changed.', array());
            }
        }
        return array('ok' => true, 'changed' => $changed, 'errors' => array(), 'message' => $changed ? 'Saved.' : 'Nothing to change.', 'values' => self::pathway_pages());
    }

    /**
     * One pathway page as array(label, url), or null when that page is not
     * published (or has no address). $key: mixed_training, social_league or
     * junior_academy (which carries its #section). The one place a pathway
     * page's name and address are worked out: the Not selected email and the
     * offer's extra training / extra games line (the offer email, the offer
     * page and the "you're locked in" email) both read it.
     */
    public static function pathway_page_link($key) {
        $labels = array('mixed_training' => 'Mixed Training Program', 'social_league' => 'Reds Social League', 'junior_academy' => 'Junior Academy');
        if (!isset($labels[$key])) {
            return null;
        }
        $pages = self::pathway_pages();
        $anchor = $key === 'junior_academy' ? (string) $pages['junior_anchor'] : '';
        if (get_post_status($pages[$key]) !== 'publish') {
            return null;
        }
        $url = get_permalink($pages[$key]);
        if (!$url) {
            return null;
        }
        return array('label' => $labels[$key], 'url' => $anchor !== '' ? $url . '#' . $anchor : $url);
    }

    /** The pathway as array(array(label, url)): adults Mixed Training + Social League, juniors the Junior Academy. Pages that are not published are left out. */
    public static function pathway_links($kind) {
        $keys = $kind === 'junior' ? array('junior_academy') : array('mixed_training', 'social_league');
        $out = array();
        foreach ($keys as $key) {
            $link = self::pathway_page_link($key);
            if ($link) {
                $out[] = $link;
            }
        }
        return $out;
    }
}
