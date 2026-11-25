<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club mailouts (1.97.0, club request 25 Nov 2026): an administrator emails a
 * chosen set of people from Club Membership, in the club look, sent from the
 * site at a steady pace. This class is the engine; the screens are
 * TeamOversight_Mailouts_Admin.
 *
 * Who gets it is a set of groups (members by tier, VVL places, trial
 * applicants, Club Programs participants, Reds Social League players), each
 * resolved to ACCOUNTS (user ids): anyone in any included group gets it once.
 * A mailout is one of two kinds: a club notice (facts, no promotion: goes to
 * everyone picked) or club news (anything that promotes something: carries an
 * unsubscribe link, and anyone who has unsubscribed is left out, at the count
 * and again at send time).
 *
 * The rules:
 *  - Every mailout email is sent by tick() and nothing else (send() only
 *    freezes the list), at most BATCH a tick, HOUR_CAP an hour and DAY_CAP a
 *    day across all mailouts, so the site's other emails keep their share of
 *    the club mailer's daily limit.
 *  - Nothing is sent while TeamOversight_Offers::emails_live() is false (a
 *    staging copy of the live database), unless a caller passes $live = true
 *    (the tests, with a pre_wp_mail catcher installed).
 *  - The club news opt-out (user meta OPTOUT_META) is honoured at send time.
 *  - A mailout row is never deleted once sent; a draft can be. Recipient rows
 *    of a mailout finished more than KEEP_DAYS ago are pruned daily (the
 *    mailout keeps its totals).
 */
class TeamOversight_Mailouts {

    const TABLE = 'team_mailouts';
    const RECIPIENTS = 'team_mailout_recipients';

    /** At most this many emails a tick, this many in any hour and in any 24 hours (every mailout together). */
    const BATCH = 35;
    const HOUR_CAP = 400;
    const DAY_CAP = 1200;

    /** The sender: a WP-cron event every five minutes while any mailout is sending. */
    const TICK_HOOK = 'murvc_mailout_tick';
    const SCHEDULE = 'murvc_five_minutes';
    const SCHEDULE_SECONDS = 300;
    /** A failed email is tried again after RETRY_MINUTES, MAX_TRIES times in all. */
    const MAX_TRIES = 3;
    const RETRY_MINUTES = 5;
    /** A row left in 'sending' this long becomes 'unknown' (it may have gone) and is never retried. */
    const STUCK_MINUTES = 10;
    /** The most time one tick spends sending. */
    const TICK_SECONDS = 25;
    /**
     * Set (autoloaded, '1') while a mailout is sending, so every request can
     * put a lost five-minute event back without a query (heal_schedule()).
     */
    const SENDING_FLAG = 'murvc_mailouts_sending';
    /**
     * A sending mailout held this long (the email permission closed) is
     * paused when emails can go again, so an administrator decides whether it
     * still goes (as offer emails older than a day are held).
     */
    const HELD_PAUSE_SECONDS = 86400;
    /** Recipient rows of a mailout finished longer ago than this are deleted (the mailout keeps its totals). */
    const KEEP_DAYS = 400;

    /** Club news opt-out: user meta (site time 'Y-m-d H:i:s') and how (link, one_click, admin). */
    const OPTOUT_META = 'murvc_news_optout';
    const OPTOUT_VIA_META = 'murvc_news_optout_via';
    /**
     * An unsubscribe through an earlier email to an account since merged into
     * this one: OPTOUT_FROM_META = that account's id when the request made
     * this account's opt-out, and OPTOUT_ASKED_META = every such account id
     * that asked. If the merge is undone, the request goes back to the account
     * whose email it came from (after_merge_undone()).
     */
    const OPTOUT_FROM_META = 'murvc_news_optout_from';
    const OPTOUT_ASKED_META = 'murvc_news_optout_asked';
    /** The unsubscribe page: admin-post.php?action=murvc_news&t=TOKEN (signed in or out). */
    const NEWS_ACTION = 'murvc_news';
    /** The key unsubscribe links are signed with (not autoloaded; made on first use; never the site's salts). */
    const SECRET_OPTION = 'murvc_news_secret';
    const LOG_TYPE = 'mailout';
    const MAILOUT_HEADER = 'X-MURVC-Mailout';

    const SUBJECT_MAX = 150;
    const TEXT_MAX = 190;
    const BUTTON_LABEL_MAX = 60;
    const BUTTON_URL_MAX = 500;
    const BODY_MAX = 20000;
    const GROUPS_MAX = 100;
    const DEFAULT_BUTTON = 'Find out more';
    /** The picture's width in the email (the layout's content width). */
    const IMAGE_WIDTH = 536;

    /** The groups, in the fixed order the why line and the counts use. */
    const GROUPS = array('members', 'teams', 'trials', 'programs', 'league');
    const KINDS = array('notice', 'news');
    const STATES = array('draft', 'sending', 'paused', 'done', 'cancelled');
    const OPTOUT_VIA = array('link', 'one_click', 'admin');

    /** The VVL roles each Roles tick covers. */
    const ROLE_MAP = array(
        'players' => array('playing_member', 'training_only'),
        'coaches' => array('coach', 'assistant_coach'),
        'managers' => array('team_manager'),
        'supporters' => array('supporter'),
    );

    /** The trial application statuses each Status tick covers. */
    const TRIAL_STATUS_MAP = array(
        'applied' => array('pending'),
        'placed' => array('accepted'),
        'unpaid' => array('awaiting_payment', 'expired'),
        'turned_down' => array('rejected'),
    );

    /** Team places are recorded on the site from this season (2026's teams never were). */
    const FIRST_TEAM_SEASON = '2027';

    private static $hooks_registered = false;
    /** The signing key, once read in this request (secret()). */
    private static $secret = null;
    private static $tables_exist = null;

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    /** Added once, from team_oversight_init(). */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action(self::TICK_HOOK, array(__CLASS__, 'cron_tick'));
        add_action('init', array(__CLASS__, 'heal_on_init'), 30);
        add_action('admin_post_' . self::NEWS_ACTION, array(__CLASS__, 'handle_unsubscribe'));
        add_action('admin_post_nopriv_' . self::NEWS_ACTION, array(__CLASS__, 'handle_unsubscribe'));
        if (class_exists('TeamOversight_Memberships')) {
            add_action(TeamOversight_Memberships::CRON_HOOK, array(__CLASS__, 'prune'), 30);
        }
        add_action('murvc_account_merge_undone', array(__CLASS__, 'after_merge_undone'), 20, 3);
    }

    /**
     * After an account merge was undone (murvc_account_merge_undone: the kept
     * account, the restored ones, the merge id). Never throws.
     * - Club news: an unsubscribe that came through a restored account's
     *   earlier email (OPTOUT_ASKED_META on the kept account) is given back to
     *   that account, and the kept account's own opt-out goes when that request
     *   is what made it (OPTOUT_FROM_META): the kept account never asked.
     * - A sending or paused mailout's rows of these accounts that were left out
     *   as 'duplicate' wait again; the send-time check decides afresh, by
     *   account and by the address a row already went to.
     */
    public static function after_merge_undone($keep_id, $restored_ids, $merge_id = 0) {
        try {
            global $wpdb;
            $keep_id = intval($keep_id);
            $restored = array_values(array_filter(array_map('intval', (array) $restored_ids)));
            if ($keep_id <= 0 || !$restored) {
                return;
            }
            $asked = self::asked_list($keep_id);
            $back = array_values(array_intersect($asked, $restored));
            if ($back) {
                $via = (string) get_user_meta($keep_id, self::OPTOUT_VIA_META, true);
                $via = in_array($via, self::OPTOUT_VIA, true) ? $via : 'link';
                foreach ($back as $restored_id) {
                    self::opt_out($restored_id, $via);
                }
                $left = array_values(array_diff($asked, $back));
                if ($left) {
                    update_user_meta($keep_id, self::OPTOUT_ASKED_META, $left);
                } else {
                    delete_user_meta($keep_id, self::OPTOUT_ASKED_META);
                }
            }
            $from = intval(get_user_meta($keep_id, self::OPTOUT_FROM_META, true));
            if ($from > 0 && in_array($from, $restored, true)) {
                delete_user_meta($keep_id, self::OPTOUT_META);
                delete_user_meta($keep_id, self::OPTOUT_VIA_META);
                delete_user_meta($keep_id, self::OPTOUT_FROM_META);
                TeamOversight_Log::add(self::LOG_TYPE, 'Club news back on: the unsubscribe came through an earlier email to account #' . $from . ', and that merge was undone, so it went back to that account', array('user_id' => $keep_id));
            }
            if (!self::tables_exist()) {
                return;
            }
            $ids = implode(',', array_map('intval', array_merge(array($keep_id), $restored)));
            $n = $wpdb->query('UPDATE ' . self::recipients_table() . ' r JOIN ' . self::table() . " m ON m.id = r.mailout_id
                SET r.status = 'pending', r.reason = '', r.final = 0, r.tries = 0, r.next_try_at = NULL, r.claimed_at = NULL
                WHERE m.state IN ('sending', 'paused') AND r.status = 'skipped' AND r.reason = 'duplicate' AND r.user_id IN ($ids)");
            if (intval($n) > 0) {
                TeamOversight_Log::add(self::LOG_TYPE, 'Account merge undone: ' . intval($n) . ' ' . (intval($n) === 1 ? 'person' : 'people') . ' left out of a mailout as already on the list wait to be sent again', array('user_id' => $keep_id));
                self::heal_schedule(true);
            }
        } catch (\Throwable $e) {
            // A listener must never break the undo.
        }
    }

    /** The five-minute schedule the sender runs on. */
    public static function cron_schedules($schedules) {
        $schedules = is_array($schedules) ? $schedules : array();
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = array('interval' => self::SCHEDULE_SECONDS, 'display' => 'Every five minutes (club mailouts)');
        }
        return $schedules;
    }

    /** The cron event's callback (WP-cron passes nothing; tick()'s own argument must stay null). */
    public static function cron_tick() {
        self::tick();
    }

    /** init: put the five-minute event back if a mailout is sending and it is gone (no query: the flag is autoloaded). */
    public static function heal_on_init() {
        self::heal_schedule(false);
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function recipients_table() {
        global $wpdb;
        return $wpdb->prefix . self::RECIPIENTS;
    }

    /**
     * The two tables, short name => CREATE statement: one definition shared by
     * TeamOversight_Database::migrate_database() (behind SHOW TABLES checks),
     * create_tables() and team_oversight_create_tables(). The recipients table
     * has NO unique key on user_id: an account merge re-points user ids, and a
     * unique key would make it fail; send() dedupes in code, and the sender
     * leaves out a row whose account already has one sent (process_row()). Its groups column
     * is group_keys, because GROUPS is a reserved word in MySQL 8.
     */
    public static function tables_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return array(
            self::TABLE => "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                kind varchar(10) NOT NULL DEFAULT '',
                state varchar(12) NOT NULL DEFAULT 'draft',
                subject varchar(190) NOT NULL DEFAULT '',
                heading varchar(190) NOT NULL DEFAULT '',
                preheader varchar(190) NOT NULL DEFAULT '',
                body longtext,
                button_label varchar(60) NOT NULL DEFAULT '',
                button_url varchar(500) NOT NULL DEFAULT '',
                image_id bigint(20) unsigned NOT NULL DEFAULT 0,
                image_alt varchar(190) NOT NULL DEFAULT '',
                reply_to varchar(190) NOT NULL DEFAULT '',
                audience longtext,
                audience_words text,
                version int(10) unsigned NOT NULL DEFAULT 1,
                tested_hash char(32) NOT NULL DEFAULT '',
                tested_at datetime DEFAULT NULL,
                tested_by bigint(20) unsigned DEFAULT NULL,
                created_by bigint(20) unsigned DEFAULT NULL,
                created_at datetime DEFAULT NULL,
                updated_by bigint(20) unsigned DEFAULT NULL,
                updated_at datetime DEFAULT NULL,
                sent_by bigint(20) unsigned DEFAULT NULL,
                started_at datetime DEFAULT NULL,
                finished_at datetime DEFAULT NULL,
                total int(10) unsigned NOT NULL DEFAULT 0,
                sent_total int(10) unsigned NOT NULL DEFAULT 0,
                problem_total int(10) unsigned NOT NULL DEFAULT 0,
                skipped_total int(10) unsigned NOT NULL DEFAULT 0,
                held_since datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY state (state)
            ) ENGINE=InnoDB $charset_collate;",
            self::RECIPIENTS => "CREATE TABLE {$wpdb->prefix}" . self::RECIPIENTS . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                mailout_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                email varchar(190) NOT NULL DEFAULT '',
                name varchar(190) NOT NULL DEFAULT '',
                group_keys varchar(100) NOT NULL DEFAULT '',
                status varchar(12) NOT NULL DEFAULT 'pending',
                reason varchar(30) NOT NULL DEFAULT '',
                tries tinyint(3) unsigned NOT NULL DEFAULT 0,
                final tinyint(1) NOT NULL DEFAULT 0,
                claimed_at datetime DEFAULT NULL,
                last_try_at datetime DEFAULT NULL,
                next_try_at datetime DEFAULT NULL,
                sent_at datetime DEFAULT NULL,
                error varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY mailout_status (mailout_id, status),
                KEY status_sent (status, sent_at),
                KEY user_id (user_id)
            ) ENGINE=InnoDB $charset_collate;",
        );
    }

    /** Both tables exist (the migration runs on init; a request before it can't use mailouts). */
    public static function tables_exist($fresh = false) {
        global $wpdb;
        if ($fresh || self::$tables_exist === null) {
            self::$tables_exist = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table())))
                && (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::recipients_table())));
        }
        return self::$tables_exist;
    }

    // ------------------------------------------------------------------
    // Readers
    // ------------------------------------------------------------------

    /** One mailout row (every column), or null. */
    public static function get($id) {
        global $wpdb;
        $id = intval($id);
        if ($id <= 0 || !self::tables_exist()) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id));
        return $row ? $row : null;
    }

    /** Every mailout, newest first, without the message and audience. */
    public static function all() {
        global $wpdb;
        if (!self::tables_exist()) {
            return array();
        }
        return (array) $wpdb->get_results('SELECT id, kind, state, subject, version, created_by, created_at, updated_by, updated_at, sent_by, started_at, finished_at, total, sent_total, problem_total, skipped_total, held_since, audience_words FROM ' . self::table() . ' ORDER BY id DESC');
    }

    /**
     * A mailout's recipient rows, by name. $args: status (one status or a
     * list), limit, offset.
     */
    public static function recipients($id, $args = array()) {
        global $wpdb;
        if (!self::tables_exist()) {
            return array();
        }
        $where = array($wpdb->prepare('mailout_id = %d', intval($id)));
        if (!empty($args['status'])) {
            $statuses = array_values(array_filter((array) $args['status'], 'is_string'));
            if ($statuses) {
                $where[] = $wpdb->prepare('status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')', $statuses);
            }
        }
        $sql = 'SELECT * FROM ' . self::recipients_table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY name, id';
        if (!empty($args['limit'])) {
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', max(1, intval($args['limit'])), max(0, isset($args['offset']) ? intval($args['offset']) : 0));
        }
        return (array) $wpdb->get_results($sql);
    }

    /**
     * Where a mailout's recipients are: pending, sending, sent, failed (waiting
     * to try again), failed_final, unknown (may have gone), skipped (left out,
     * also by reason in skipped_reasons), cancelled; and the page's figures:
     * waiting (pending + sending + failed), problems (failed_final + unknown),
     * left_out (skipped), rows.
     */
    public static function counts($id) {
        global $wpdb;
        $out = array('pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'failed_final' => 0, 'unknown' => 0, 'skipped' => 0, 'cancelled' => 0, 'skipped_reasons' => array(), 'waiting' => 0, 'problems' => 0, 'left_out' => 0, 'rows' => 0);
        if (!self::tables_exist()) {
            return $out;
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, final, reason, COUNT(*) AS n FROM ' . self::recipients_table() . ' WHERE mailout_id = %d GROUP BY status, final, reason', intval($id)));
        foreach ((array) $rows as $r) {
            $n = intval($r->n);
            $out['rows'] += $n;
            $status = (string) $r->status;
            if ($status === 'failed') {
                $out[intval($r->final) ? 'failed_final' : 'failed'] += $n;
            } elseif (isset($out[$status]) && is_int($out[$status])) {
                $out[$status] += $n;
            }
            if ($status === 'skipped') {
                $reason = (string) $r->reason !== '' ? (string) $r->reason : 'other';
                $out['skipped_reasons'][$reason] = (isset($out['skipped_reasons'][$reason]) ? $out['skipped_reasons'][$reason] : 0) + $n;
            }
        }
        $out['waiting'] = $out['pending'] + $out['sending'] + $out['failed'];
        $out['problems'] = $out['failed_final'] + $out['unknown'];
        $out['left_out'] = $out['skipped'];
        return $out;
    }

    /**
     * Mailout emails sent in the last $seconds, every mailout together: rows
     * sent in that time, plus attempts in that time that didn't end sent
     * (failed, unknown, still sending), which used the mailer just the same.
     */
    public static function sent_in_window($seconds) {
        global $wpdb;
        if (!self::tables_exist()) {
            return 0;
        }
        $since = self::time_ago(intval($seconds));
        $sent = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::recipients_table() . " WHERE status = 'sent' AND sent_at >= %s", $since)));
        $tried = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::recipients_table() . " WHERE status IN ('failed', 'unknown', 'sending') AND last_try_at >= %s", $since)));
        return $sent + $tried;
    }

    /** The mailout's audience, normalised (an array of every group's options). */
    public static function audience_of($row) {
        $raw = is_object($row) ? (isset($row->audience) ? $row->audience : '') : (is_array($row) && isset($row['audience']) ? $row['audience'] : '');
        return self::normalise_audience(is_string($raw) ? $raw : (is_array($raw) ? $raw : ''));
    }

    /**
     * Roughly when a list of $total would finish from $from (now) at the caps
     * (35 every five minutes, 400 an hour, 1,200 a day), as a timestamp.
     * $day_used: mailout emails already sent in the last 24 hours, the first
     * of them at $window_start (a timestamp; default $from): only DAY_CAP
     * less those can go before that day is used up, and each later 1,200 goes
     * a day after. WP-cron runs on visits, so it can be later overnight; the
     * page says so.
     */
    public static function finish_estimate($total, $from = null, $day_used = 0, $window_start = null) {
        $total = max(0, intval($total));
        $from = $from === null ? time() : intval($from);
        if ($total === 0) {
            return $from;
        }
        $per_hour = min(self::HOUR_CAP, self::BATCH * intval(3600 / self::SCHEDULE_SECONDS));
        $room = max(0, self::DAY_CAP - max(0, intval($day_used)));
        if ($total <= $room) {
            return $from + (int) ceil($total / $per_hour * HOUR_IN_SECONDS);
        }
        $window_start = $window_start === null ? $from : min($from, intval($window_start));
        $rest = $total - $room;
        $days = intdiv($rest - 1, self::DAY_CAP) + 1;
        $last = $rest - ($days - 1) * self::DAY_CAP;
        return max($from, $window_start + $days * DAY_IN_SECONDS) + (int) ceil($last / $per_hour * HOUR_IN_SECONDS);
    }

    /**
     * finish_estimate() for a mailout in the queue: it goes after the waiting
     * rows of the sending mailouts ahead of it (every sending one for a new
     * mailout, $mailout_id 0; else those started before it, the order tick()
     * sends in), and after what the last 24 hours already used of the day
     * cap. Returns array(at (timestamp), ahead (emails waiting ahead of it)).
     */
    public static function queue_estimate($total, $mailout_id = 0) {
        global $wpdb;
        $total = max(0, intval($total));
        if (!self::tables_exist()) {
            return array('at' => self::finish_estimate($total), 'ahead' => 0);
        }
        $where = "m.state = 'sending'";
        $row = intval($mailout_id) > 0 ? self::get($mailout_id) : null;
        if ($row) {
            $started = !empty($row->started_at) ? (string) $row->started_at : self::now();
            $where .= $wpdb->prepare(' AND m.id <> %d AND (m.started_at < %s OR (m.started_at = %s AND m.id < %d))', intval($row->id), $started, $started, intval($row->id));
        }
        $ahead = intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::recipients_table() . ' r JOIN ' . self::table() . " m ON m.id = r.mailout_id
            WHERE $where AND r.final = 0 AND r.status IN ('pending', 'sending', 'failed')"));
        $day_used = self::sent_in_window(DAY_IN_SECONDS);
        $window_start = null;
        if ($day_used > 0) {
            $since = self::time_ago(DAY_IN_SECONDS);
            $firsts = array_filter(array(
                (string) $wpdb->get_var($wpdb->prepare('SELECT MIN(sent_at) FROM ' . self::recipients_table() . " WHERE status = 'sent' AND sent_at >= %s", $since)),
                (string) $wpdb->get_var($wpdb->prepare('SELECT MIN(last_try_at) FROM ' . self::recipients_table() . " WHERE status IN ('failed', 'unknown', 'sending') AND last_try_at >= %s", $since)),
            ), 'strlen');
            if ($firsts) {
                // Site-time strings: how long ago, then back from the real clock.
                $window_start = time() - max(0, strtotime(self::now()) - strtotime(min($firsts)));
            }
        }
        return array('at' => self::finish_estimate($ahead + $total, null, $day_used, $window_start), 'ahead' => $ahead);
    }

    // ------------------------------------------------------------------
    // The groups (choices for the form)
    // ------------------------------------------------------------------

    /** The groups, key => label, in the fixed order. */
    public static function group_types() {
        return array(
            'members' => 'Members',
            'teams' => 'VVL teams',
            'trials' => 'Trial applicants',
            'programs' => 'Club Programs',
            'league' => 'Reds Social League',
        );
    }

    /** Membership tiers, tier key => short label (Life, Full, Associate). */
    public static function tier_choices() {
        return array(
            TeamOversight_Memberships::TIER_LIFE => 'Life',
            TeamOversight_Memberships::TIER_FULL => 'Full',
            TeamOversight_Memberships::TIER_ASSOCIATE => 'Associate',
        );
    }

    public static function role_choices() {
        return array('players' => 'Players', 'coaches' => 'Coaches', 'managers' => 'Team managers', 'supporters' => 'Supporters');
    }

    public static function trial_status_choices() {
        return array('applied' => 'Applied', 'placed' => 'Placed', 'unpaid' => 'Fee not paid', 'turned_down' => 'Turned down by the club');
    }

    public static function league_who_choices() {
        return array('team' => 'On a team', 'free_agents' => 'Free agents');
    }

    /** Seasons with team places (2027 on), newest first. */
    public static function team_seasons() {
        global $wpdb;
        $seasons = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments WHERE season >= %s ORDER BY season DESC", self::FIRST_TEAM_SEASON));
        return array_values(array_filter(array_map('strval', (array) $seasons), array(__CLASS__, 'valid_season')));
    }

    /** A season's teams, code => "Name (CODE)". */
    public static function team_choices($season) {
        if (!self::valid_season($season)) {
            return array();
        }
        $config = class_exists('TeamOversight_Offers') ? TeamOversight_Offers::teams_config((string) $season) : TeamOversight_Database::shared()->get_teams_config((string) $season);
        $out = array();
        foreach ((array) $config as $code => $team) {
            $name = is_array($team) && isset($team['name']) ? (string) $team['name'] : (string) $team;
            $out[(string) $code] = ($name !== '' && $name !== (string) $code) ? $name . ' (' . $code . ')' : (string) $code;
        }
        return $out;
    }

    /** Seasons with trial applications, newest first. */
    public static function trial_seasons() {
        global $wpdb;
        $seasons = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}trial_applications ORDER BY season DESC");
        return array_values(array_filter(array_map('strval', (array) $seasons), array(__CLASS__, 'valid_season')));
    }

    /** Club Programs, key => name. */
    public static function program_choices() {
        if (!class_exists('TeamOversight_Programs')) {
            return array();
        }
        $out = array();
        foreach ((array) TeamOversight_Programs::get_programs() as $key => $program) {
            $name = is_array($program) && isset($program['name']) ? (string) $program['name'] : (string) $key;
            $out[(string) $key] = $name !== '' ? $name : (string) $key;
        }
        return $out;
    }

    /** The years the Programs group offers: this year and the four before, newest first. */
    public static function program_years() {
        $year = intval(current_time('Y'));
        return array_map('strval', range($year, $year - 4));
    }

    /** The Reds Social League is installed here (its class and its teams table). */
    public static function league_available() {
        global $wpdb;
        static $available = null;
        if ($available === null) {
            $available = class_exists('RSL_Teams') && method_exists('RSL_Teams', 'members_for_season')
                && (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'rsl_teams')));
        }
        return $available;
    }

    /**
     * League seasons with teams or free agents (a sign-up season can have free
     * agents before any team), key => label, newest first in the league's own
     * calendar order (RSL_Seasons::ordinal(): pre, mid1, mid2, post; never the
     * keys compared as text, which puts 2027-pre before 2027-post).
     */
    public static function league_seasons() {
        global $wpdb;
        if (!self::league_available()) {
            return array();
        }
        $keys = (array) $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}rsl_teams");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'rsl_free_agents')))) {
            $keys = array_merge($keys, (array) $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}rsl_free_agents"));
        }
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), array(__CLASS__, 'valid_league_season'))));
        $ordinal = class_exists('RSL_Seasons') && method_exists('RSL_Seasons', 'ordinal');
        usort($keys, function ($a, $b) use ($ordinal) {
            $x = $ordinal ? intval(RSL_Seasons::ordinal($a)) : -1;
            $y = $ordinal ? intval(RSL_Seasons::ordinal($b)) : -1;
            if ($x !== $y) {
                return $x < $y ? 1 : -1;
            }
            return strcmp($b, $a);
        });
        $out = array();
        foreach ($keys as $season) {
            $out[$season] = self::league_label($season);
        }
        return $out;
    }

    private static function league_label($season) {
        return (class_exists('RSL_Seasons') && method_exists('RSL_Seasons', 'label')) ? (string) RSL_Seasons::label($season) : (string) $season;
    }

    public static function valid_season($season) {
        return (is_string($season) || is_int($season)) && preg_match('/^\d{4}$/D', (string) $season) === 1;
    }

    private static function valid_league_season($season) {
        return is_string($season) && strlen($season) <= 20 && preg_match('/^\d{4}-[a-z0-9]+$/D', $season) === 1;
    }

    private static function valid_date($date) {
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1) {
            return false;
        }
        return checkdate(intval($m[2]), intval($m[3]), intval($m[1]));
    }

    // ------------------------------------------------------------------
    // The audience
    // ------------------------------------------------------------------

    /** A new mailout's audience: every group off, each with its usual options. */
    public static function default_audience() {
        return self::normalise_audience(array());
    }

    /**
     * Group options as stored: structural only, never refusing (junk values
     * dropped, a missing group given its usual options, off). $posted: the
     * form's array (group => options, 'on' or 'include' ticked), a stored
     * audience array, or its JSON. Within a posted group a missing list is
     * empty (an unticked box posts nothing).
     *
     *  members:  on, tiers (tier keys), when (today, range, lapsed), from, to
     *  teams:    on, season, all (bool; 'scope' all|some is read too), teams (codes), roles (players, coaches, managers, supporters)
     *  trials:   on, season, statuses (applied, placed, unpaid, turned_down)
     *  programs: on, all (bool; 'scope' any|some), programs (keys), year
     *  league:   on, seasons (league season keys), who (team, free_agents)
     */
    public static function normalise_audience($posted) {
        if (is_string($posted)) {
            $decoded = $posted !== '' ? json_decode($posted, true) : null;
            $posted = is_array($decoded) ? $decoded : array();
        }
        $posted = is_array($posted) ? $posted : array();
        $group = function ($key) use ($posted) {
            return isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : null;
        };
        $today = current_time('Y-m-d');
        $out = array();

        $m = $group('members');
        $tier_keys = array_keys(self::tier_choices());
        $aliases = array('life' => TeamOversight_Memberships::TIER_LIFE, 'full' => TeamOversight_Memberships::TIER_FULL, 'associate' => TeamOversight_Memberships::TIER_ASSOCIATE);
        $tiers = $m === null ? $tier_keys : array();
        if ($m !== null && isset($m['tiers'])) {
            $wanted = array();
            foreach ((array) $m['tiers'] as $t) {
                if (is_string($t)) {
                    $wanted[isset($aliases[$t]) ? $aliases[$t] : $t] = true;
                }
            }
            $tiers = array_values(array_filter($tier_keys, function ($t) use ($wanted) {
                return isset($wanted[$t]);
            }));
        }
        $when = $m !== null && isset($m['when']) && in_array($m['when'], array('today', 'range', 'lapsed'), true) ? $m['when'] : 'today';
        $out['members'] = array(
            'on' => self::flag($m, 'on') || self::flag($m, 'include'),
            'tiers' => $tiers,
            'when' => $when,
            'from' => $m !== null && isset($m['from']) && self::valid_date($m['from']) ? $m['from'] : '',
            'to' => $m !== null && isset($m['to']) && self::valid_date($m['to']) ? $m['to'] : '',
        );

        $t = $group('teams');
        $seasons = $t === null ? self::team_seasons() : array();
        $codes = array();
        if ($t !== null && isset($t['teams'])) {
            foreach ((array) $t['teams'] as $code) {
                if (is_string($code) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,49}$/D', $code)) {
                    $codes[$code] = true;
                }
            }
        }
        $all = $t !== null && isset($t['scope']) && is_string($t['scope'])
            ? $t['scope'] !== 'some'
            : ($t !== null && array_key_exists('all', $t) ? self::flag($t, 'all') : empty($codes));
        $out['teams'] = array(
            'on' => self::flag($t, 'on') || self::flag($t, 'include'),
            'season' => $t !== null ? (isset($t['season']) && self::valid_season($t['season']) ? (string) $t['season'] : '') : ($seasons ? $seasons[0] : ''),
            'all' => $all,
            'teams' => $all ? array() : array_keys($codes),
            'roles' => $t === null ? array('players') : self::pick($t, 'roles', array_keys(self::ROLE_MAP)),
        );

        $tr = $group('trials');
        $tseasons = $tr === null ? self::trial_seasons() : array();
        $out['trials'] = array(
            'on' => self::flag($tr, 'on') || self::flag($tr, 'include'),
            'season' => $tr !== null ? (isset($tr['season']) && self::valid_season($tr['season']) ? (string) $tr['season'] : '') : ($tseasons ? $tseasons[0] : ''),
            'statuses' => $tr === null ? array('applied', 'placed', 'unpaid') : self::pick($tr, 'statuses', array_keys(self::TRIAL_STATUS_MAP)),
        );

        $p = $group('programs');
        $keys = array();
        if ($p !== null && isset($p['programs'])) {
            foreach ((array) $p['programs'] as $key) {
                if (is_string($key) && $key !== '' && strlen($key) <= 60 && sanitize_key($key) === $key) {
                    $keys[$key] = true;
                }
            }
        }
        $any = $p !== null && isset($p['scope']) && is_string($p['scope'])
            ? $p['scope'] !== 'some'
            : ($p !== null && array_key_exists('all', $p) ? self::flag($p, 'all') : empty($keys));
        $out['programs'] = array(
            'on' => self::flag($p, 'on') || self::flag($p, 'include'),
            'all' => $any,
            'programs' => $any ? array() : array_keys($keys),
            'year' => $p !== null ? (isset($p['year']) && self::valid_season($p['year']) ? (string) $p['year'] : '') : substr($today, 0, 4),
        );

        $l = $group('league');
        $lseasons = array();
        if ($l !== null && isset($l['seasons'])) {
            foreach ((array) $l['seasons'] as $season) {
                if (self::valid_league_season($season)) {
                    $lseasons[$season] = true;
                }
            }
        }
        $out['league'] = array(
            'on' => self::flag($l, 'on') || self::flag($l, 'include'),
            'seasons' => array_keys($lseasons),
            'who' => $l === null ? array('team') : self::pick($l, 'who', array_keys(self::league_who_choices())),
        );
        return $out;
    }

    /** A ticked box: set, and not '', '0' or 'false'. */
    private static function flag($group, $key) {
        if (!is_array($group) || !isset($group[$key])) {
            return false;
        }
        $v = $group[$key];
        if (is_bool($v)) {
            return $v;
        }
        return is_scalar($v) && !in_array(strtolower(trim((string) $v)), array('', '0', 'false'), true);
    }

    /** The allowed values ticked in $group[$key], in the allowed order. */
    private static function pick($group, $key, array $allowed) {
        if (!is_array($group) || !isset($group[$key])) {
            return array();
        }
        $wanted = array();
        foreach ((array) $group[$key] as $v) {
            if (is_string($v)) {
                $wanted[$v] = true;
            }
        }
        return array_values(array_filter($allowed, function ($a) use ($wanted) {
            return isset($wanted[$a]);
        }));
    }

    /** The included groups, in order. */
    public static function included_groups($audience) {
        $audience = self::normalise_audience($audience);
        $out = array();
        foreach (self::GROUPS as $g) {
            if (!empty($audience[$g]['on'])) {
                $out[] = $g;
            }
        }
        return $out;
    }

    /**
     * What is wrong with an audience's included groups, as sentences for the
     * administrator ('' groups aren't judged). Empty when it can be saved.
     */
    public static function audience_problems($audience) {
        $a = self::normalise_audience($audience);
        $today = current_time('Y-m-d');
        $out = array();
        if ($a['members']['on']) {
            $m = $a['members'];
            if (!$m['tiers']) {
                $out[] = 'Members: tick at least one of Life, Full or Associate.';
            }
            if ($m['when'] !== 'today') {
                if ($m['from'] === '' || $m['to'] === '') {
                    $out[] = 'Members: give both dates.';
                } elseif ($m['from'] > $m['to']) {
                    $out[] = 'Members: the first date is after the second.';
                } elseif ($m['when'] === 'lapsed' && $m['to'] > $today) {
                    $out[] = 'Members: for lapsed members the second date can\'t be after today.';
                }
            }
        }
        if ($a['teams']['on']) {
            $t = $a['teams'];
            if ($t['season'] === '') {
                $out[] = 'VVL teams: pick a season.';
            } elseif ($t['season'] < self::FIRST_TEAM_SEASON) {
                $out[] = 'VVL teams: teams before ' . self::FIRST_TEAM_SEASON . ' aren\'t recorded on the site.';
            }
            if (!$t['all'] && !$t['teams']) {
                $out[] = 'VVL teams: tick at least one team, or pick All teams.';
            }
            if (!$t['roles']) {
                $out[] = 'VVL teams: tick at least one of Players, Coaches, Team managers or Supporters.';
            }
        }
        if ($a['trials']['on']) {
            if ($a['trials']['season'] === '') {
                $out[] = 'Trial applicants: pick a season.';
            }
            if (!$a['trials']['statuses']) {
                $out[] = 'Trial applicants: tick at least one status.';
            }
        }
        if ($a['programs']['on']) {
            if (!class_exists('TeamOversight_Programs')) {
                $out[] = 'Club Programs: not available on this site.';
            }
            if ($a['programs']['year'] === '') {
                $out[] = 'Club Programs: pick a year.';
            }
            if (!$a['programs']['all'] && !$a['programs']['programs']) {
                $out[] = 'Club Programs: tick at least one program, or pick Any program.';
            }
        }
        if ($a['league']['on']) {
            if (!self::league_available()) {
                $out[] = 'Reds Social League: the league isn\'t installed on this site.';
            } else {
                if (!$a['league']['seasons']) {
                    $out[] = 'Reds Social League: tick at least one season.';
                }
                if (!$a['league']['who']) {
                    $out[] = 'Reds Social League: tick On a team, Free agents or both.';
                }
            }
        }
        return $out;
    }

    /** "A", "A and B", "A, B and C". */
    private static function and_list(array $items) {
        $items = array_values(array_filter(array_map('strval', $items), 'strlen'));
        if (count($items) <= 1) {
            return $items ? $items[0] : '';
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private static function date_words($ymd) {
        $ts = strtotime($ymd . ' 12:00:00');
        return $ts ? date('j M Y', $ts) : (string) $ymd;
    }

    /**
     * The audience in plain words, one clause per included group joined with
     * semicolons ('' when no group is included). Stored on the mailout at each
     * save and again at Send.
     */
    public static function audience_words($audience) {
        $a = self::normalise_audience($audience);
        $parts = array();
        if ($a['members']['on']) {
            $m = $a['members'];
            $tiers = self::tier_choices();
            $names = array();
            foreach ($m['tiers'] as $t) {
                $names[] = $tiers[$t];
            }
            $who = (count($names) === count($tiers) ? 'All' : self::and_list($names)) . ' members';
            if ($m['when'] === 'today') {
                $parts[] = $who . ' today';
            } elseif ($m['when'] === 'range') {
                $parts[] = $who . ' at any time from ' . self::date_words($m['from']) . ' to ' . self::date_words($m['to']);
            } else {
                $parts[] = $who . ' from ' . self::date_words($m['from']) . ' to ' . self::date_words($m['to']) . ' who aren\'t members now';
            }
        }
        if ($a['teams']['on']) {
            $t = $a['teams'];
            $roles = self::role_choices();
            $r = array();
            foreach ($t['roles'] as $key) {
                $r[] = strtolower($roles[$key]);
            }
            $parts[] = $t['season'] . ' VVL ' . ($t['all'] ? 'teams' : self::and_list($t['teams'])) . ': ' . self::and_list($r);
        }
        if ($a['trials']['on']) {
            $statuses = self::trial_status_choices();
            $s = array();
            foreach ($a['trials']['statuses'] as $key) {
                $s[] = $statuses[$key];
            }
            $parts[] = $a['trials']['season'] . ' trial applicants: ' . self::and_list($s);
        }
        if ($a['programs']['on']) {
            $names = self::program_choices();
            $p = array();
            foreach ($a['programs']['programs'] as $key) {
                $p[] = isset($names[$key]) ? $names[$key] : $key;
            }
            $parts[] = ($a['programs']['all'] ? 'Any Club Program' : self::and_list($p)) . ' in ' . $a['programs']['year'];
        }
        if ($a['league']['on']) {
            $who = self::league_who_choices();
            $w = array();
            foreach ($a['league']['who'] as $key) {
                $w[] = strtolower($who[$key]);
            }
            $s = array();
            foreach ($a['league']['seasons'] as $season) {
                $s[] = self::league_label($season);
            }
            $parts[] = 'Reds Social League ' . self::and_list($s) . ': ' . self::and_list($w);
        }
        return implode('; ', $parts);
    }

    /**
     * Who an audience reaches, worked out now. $kind 'news' also leaves out
     * everyone who has unsubscribed from club news. Returns:
     *  people   user_id => array(user_id, name, email, groups (keys, in order), program (the first program they took part in, for the why line))
     *  left     user_id => the same plus reason (no_email, unsubscribed): accounts in a group who won't get it
     *  total    count(people)
     *  groups   group => array(people (getting it), only (getting it, in no other included group), left (left out), missing (rows not on an account), undated (programs: lines with no date))
     *  left_out array(unsubscribed, no_email, no_account (rows on no account, or an account that is gone), undated)
     */
    public static function resolve($audience, $kind = 'notice') {
        global $wpdb;
        $a = self::normalise_audience($audience);
        $kind = $kind === 'news' ? 'news' : 'notice';
        $out = array(
            'people' => array(),
            'left' => array(),
            'total' => 0,
            'groups' => array(),
            'left_out' => array('unsubscribed' => 0, 'no_email' => 0, 'no_account' => 0, 'undated' => 0),
        );
        $raw = array();
        foreach (self::GROUPS as $g) {
            if (!empty($a[$g]['on'])) {
                $raw[$g] = self::group_rows($g, $a[$g]);
            }
        }
        if (!$raw) {
            return $out;
        }

        // Accounts: by user_id when the row has one; else (a legacy or guest row) the account the email belongs to.
        $uids = array();
        $emails = array();
        foreach ($raw as $r) {
            foreach ($r['rows'] as $row) {
                if ($row[0] > 0) {
                    $uids[$row[0]] = true;
                } elseif ($row[1] !== '') {
                    $emails[strtolower($row[1])] = true;
                }
            }
        }
        $by_email = self::accounts_by_email(array_keys($emails));
        foreach ($by_email as $id) {
            $uids[$id] = true;
        }
        $users = self::load_users(array_keys($uids));

        $in = array();        // user_id => group => true
        $program_of = array();
        $missing_all = array();
        foreach ($raw as $g => $r) {
            $missing = array();
            $n = 0;
            foreach ($r['rows'] as $row) {
                $n++;
                list($uid, $email, $extra) = $row;
                $id = 0;
                if ($uid > 0) {
                    if (isset($users[$uid])) {
                        $id = $uid;
                    } else {
                        $missing['u' . $uid] = true;
                    }
                } else {
                    $e = strtolower(trim($email));
                    if ($e !== '' && isset($by_email[$e]) && isset($users[$by_email[$e]])) {
                        $id = $by_email[$e];
                    } else {
                        $missing[$e !== '' ? 'e' . $e : 'r' . $g . $n] = true;
                    }
                }
                if ($id > 0) {
                    $in[$id][$g] = true;
                    if ($g === 'programs' && $extra !== '' && !isset($program_of[$id])) {
                        $program_of[$id] = $extra;
                    }
                }
            }
            $out['groups'][$g] = array('people' => 0, 'only' => 0, 'left' => 0, 'missing' => count($missing), 'undated' => intval($r['undated']));
            $missing_all += $missing;
            $out['left_out']['undated'] += intval($r['undated']);
        }
        $out['left_out']['no_account'] = count($missing_all);

        $opted = array();
        if ($kind === 'news' && $in) {
            foreach (array_chunk(array_keys($in), 1000) as $chunk) {
                $ids = implode(',', array_map('intval', $chunk));
                foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' AND user_id IN ($ids)", self::OPTOUT_META)) as $id) {
                    $opted[intval($id)] = true;
                }
            }
        }

        foreach ($in as $id => $groups) {
            $user = $users[$id];
            $keys = array();
            foreach (self::GROUPS as $g) {
                if (isset($groups[$g])) {
                    $keys[] = $g;
                }
            }
            $person = array(
                'user_id' => intval($id),
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
                'groups' => $keys,
                'program' => isset($program_of[$id]) ? $program_of[$id] : '',
            );
            $reason = '';
            if (!is_email($person['email'])) {
                $reason = 'no_email';
            } elseif (isset($opted[$id])) {
                $reason = 'unsubscribed';
            }
            if ($reason !== '') {
                $person['reason'] = $reason;
                $out['left'][$id] = $person;
                $out['left_out'][$reason]++;
                foreach ($keys as $g) {
                    $out['groups'][$g]['left']++;
                }
                continue;
            }
            $out['people'][$id] = $person;
            foreach ($keys as $g) {
                $out['groups'][$g]['people']++;
            }
            if (count($keys) === 1) {
                $out['groups'][$keys[0]]['only']++;
            }
        }
        $by_name = function ($x, $y) {
            $c = strcasecmp(TeamOversight_Email::decode($x['name']), TeamOversight_Email::decode($y['name']));
            return $c !== 0 ? $c : $x['user_id'] - $y['user_id'];
        };
        uasort($out['people'], $by_name);
        uasort($out['left'], $by_name);
        $out['total'] = count($out['people']);
        return $out;
    }

    /**
     * One group's rows: array(rows => list of array(user_id (0 = none), email
     * ('' = none), extra (programs: the program key)), undated (programs: lines
     * with no date, which can't be placed in a year)).
     */
    private static function group_rows($group, $o) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = array();
        $undated = 0;
        if ($group === 'members') {
            if (!$o['tiers'] || ($o['when'] !== 'today' && ($o['from'] === '' || $o['to'] === ''))) {
                return array('rows' => array(), 'undated' => 0);
            }
            $today = current_time('Y-m-d');
            $from = $o['when'] === 'today' ? $today : $o['from'];
            $to = $o['when'] === 'today' ? $today : $o['to'];
            $in = implode(',', array_fill(0, count($o['tiers']), '%s'));
            $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$p}team_memberships WHERE tier IN ($in) AND start_date <= %s AND end_date >= %s", array_merge($o['tiers'], array($to, $from))));
            $now = array();
            if ($o['when'] === 'lapsed') {
                // Minus anyone holding ANY tier today.
                foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$p}team_memberships WHERE start_date <= %s AND end_date >= %s", $today, $today)) as $id) {
                    $now[intval($id)] = true;
                }
            }
            foreach ((array) $ids as $id) {
                $id = intval($id);
                if ($id > 0 && !isset($now[$id])) {
                    $rows[] = array($id, '', '');
                }
            }
        } elseif ($group === 'teams') {
            if ($o['season'] === '' || !$o['roles'] || (!$o['all'] && !$o['teams'])) {
                return array('rows' => array(), 'undated' => 0);
            }
            $roles = array();
            foreach ($o['roles'] as $key) {
                $roles = array_merge($roles, self::ROLE_MAP[$key]);
            }
            $sql = "SELECT user_id, email FROM {$p}team_assignments WHERE season = %s AND is_active = 1 AND role IN (" . implode(',', array_fill(0, count($roles), '%s')) . ')';
            $args = array_merge(array($o['season']), $roles);
            if (!$o['all']) {
                $sql .= ' AND team IN (' . implode(',', array_fill(0, count($o['teams']), '%s')) . ')';
                $args = array_merge($args, $o['teams']);
            }
            foreach ((array) $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY id', $args)) as $r) {
                $rows[] = array(intval($r->user_id), (string) $r->email, '');
            }
        } elseif ($group === 'trials') {
            if ($o['season'] === '' || !$o['statuses']) {
                return array('rows' => array(), 'undated' => 0);
            }
            $statuses = array();
            foreach ($o['statuses'] as $key) {
                $statuses = array_merge($statuses, self::TRIAL_STATUS_MAP[$key]);
            }
            $sql = "SELECT user_id, email FROM {$p}trial_applications WHERE season = %s AND application_status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ') ORDER BY id';
            foreach ((array) $wpdb->get_results($wpdb->prepare($sql, array_merge(array($o['season']), $statuses))) as $r) {
                $rows[] = array(intval($r->user_id), (string) $r->email, '');
            }
        } elseif ($group === 'programs') {
            if (!class_exists('TeamOversight_Programs') || $o['year'] === '') {
                return array('rows' => array(), 'undated' => 0);
            }
            $choices = self::program_choices();
            $keys = $o['all'] ? array_keys($choices) : array_values(array_filter($o['programs'], function ($k) use ($choices) {
                return isset($choices[$k]);
            }));
            foreach ($keys as $key) {
                foreach ((array) TeamOversight_Programs::get_program_history($key) as $line) {
                    $date = isset($line['date']) ? (string) $line['date'] : '';
                    if ($date === '') {
                        $undated++;
                        continue;
                    }
                    if (substr($date, 0, 4) === $o['year']) {
                        $rows[] = array(isset($line['user_id']) ? intval($line['user_id']) : 0, isset($line['email']) ? (string) $line['email'] : '', (string) $key);
                    }
                }
            }
        } elseif ($group === 'league') {
            if (!self::league_available() || !$o['seasons'] || !$o['who']) {
                return array('rows' => array(), 'undated' => 0);
            }
            if (in_array('team', $o['who'], true)) {
                foreach ($o['seasons'] as $season) {
                    foreach ((array) RSL_Teams::members_for_season($season) as $members) {
                        foreach ((array) $members as $member) {
                            $rows[] = array(intval($member->user_id), '', '');
                        }
                    }
                }
            }
            if (in_array('free_agents', $o['who'], true)) {
                $in = implode(',', array_fill(0, count($o['seasons']), '%s'));
                foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$p}rsl_free_agents WHERE season IN ($in) AND status <> 'withdrawn' ORDER BY id", $o['seasons'])) as $id) {
                    $rows[] = array(intval($id), '', '');
                }
            }
        }
        return array('rows' => $rows, 'undated' => $undated);
    }

    /** Lowercased email => the account it belongs to (the lowest id when two share it). */
    private static function accounts_by_email(array $emails) {
        global $wpdb;
        $out = array();
        foreach (array_chunk(array_values(array_filter($emails, 'strlen')), 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT ID, user_email FROM {$wpdb->users} WHERE user_email IN ($in) ORDER BY ID", $chunk)) as $u) {
                $key = strtolower((string) $u->user_email);
                if (!isset($out[$key])) {
                    $out[$key] = intval($u->ID);
                }
            }
        }
        return $out;
    }

    /** id => (ID, user_email, display_name) for the accounts that exist. */
    private static function load_users(array $ids) {
        global $wpdb;
        $out = array();
        foreach (array_chunk(array_values(array_filter(array_map('intval', $ids))), 1000) as $chunk) {
            foreach ((array) $wpdb->get_results("SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE ID IN (" . implode(',', $chunk) . ')') as $u) {
                $out[intval($u->ID)] = $u;
            }
        }
        return $out;
    }

    /**
     * The groups column of a recipient row: the keys in order, comma joined,
     * with ':KEY' after 'programs' naming the program they took part in (left
     * off when it wouldn't fit).
     */
    public static function group_string(array $keys, $program = '') {
        $ordered = array();
        foreach (self::GROUPS as $g) {
            if (in_array($g, $keys, true)) {
                $ordered[] = $g;
            }
        }
        $plain = implode(',', $ordered);
        if ($program !== '' && in_array('programs', $ordered, true)) {
            $with = str_replace('programs', 'programs:' . $program, $plain);
            if (strlen($with) <= self::GROUPS_MAX) {
                return $with;
            }
        }
        return $plain;
    }

    /** A groups column (or a list of keys) as array(keys, program key). */
    public static function parse_groups($groups) {
        $parts = is_array($groups) ? $groups : explode(',', (string) $groups);
        $keys = array();
        $program = '';
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (strpos($part, 'programs:') === 0) {
                $program = substr($part, 9);
                $part = 'programs';
            }
            if (in_array($part, self::GROUPS, true) && !in_array($part, $keys, true)) {
                $keys[] = $part;
            }
        }
        $ordered = array_values(array_filter(self::GROUPS, function ($g) use ($keys) {
            return in_array($g, $keys, true);
        }));
        return array($ordered, $program);
    }

    /** The groups as words for the recipients table ("Members, VVL teams"). */
    public static function group_labels($groups) {
        list($keys) = self::parse_groups($groups);
        $labels = self::group_types();
        return implode(', ', array_map(function ($k) use ($labels) {
            return $labels[$k];
        }, $keys));
    }

    /**
     * Why someone is getting it, from their FIRST group in the fixed order
     * (the footer line).
     */
    public static function why_line($groups, $audience) {
        list($keys, $program) = self::parse_groups($groups);
        if (!$keys) {
            return '';
        }
        $a = self::normalise_audience($audience);
        $start = 'You\'re getting this because ';
        switch ($keys[0]) {
            case 'members':
                return $start . ($a['members']['when'] === 'today' ? 'you\'re a member of the club.' : 'you were a member of the club.');
            case 'teams':
                return $start . 'you\'re on a ' . $a['teams']['season'] . ' club team.';
            case 'trials':
                return $start . 'you applied for ' . $a['trials']['season'] . ' trials.';
            case 'programs':
                $names = self::program_choices();
                $name = ($program !== '' && isset($names[$program])) ? $names[$program] : 'a Club Program';
                return $start . 'you took part in ' . $name . ' in ' . $a['programs']['year'] . '.';
            case 'league':
                return $start . 'you play in the Reds Social League.';
        }
        return '';
    }

    /** The groups a test email is written for: the audience's first included group (its first ticked program). */
    private static function test_groups($audience) {
        $a = self::normalise_audience($audience);
        foreach (self::GROUPS as $g) {
            if (!empty($a[$g]['on'])) {
                return self::group_string(array($g), $g === 'programs' && !$a['programs']['all'] && $a['programs']['programs'] ? $a['programs']['programs'][0] : '');
            }
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Writers
    // ------------------------------------------------------------------

    private static function can($actor) {
        return intval($actor) > 0 && user_can(intval($actor), 'manage_options');
    }

    private static function now() {
        return current_time('mysql');
    }

    /** Site time $seconds ago, as 'Y-m-d H:i:s' (the same clock as current_time('mysql')). */
    private static function time_ago($seconds) {
        return date('Y-m-d H:i:s', strtotime(current_time('mysql')) - intval($seconds));
    }

    private static function length($text) {
        return function_exists('mb_strlen') ? mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }

    private static function cut($text, $length) {
        $text = (string) $text;
        return function_exists('mb_substr') ? mb_substr($text, 0, intval($length), 'UTF-8') : substr($text, 0, intval($length));
    }

    /**
     * The draft's fields, cleaned, and what is wrong with them. $fields: kind,
     * subject, heading, preheader, body, button_label, button_url, image_id,
     * image_alt, reply_to, audience (the form's array or a stored one). A draft
     * may have no kind, subject or message yet (Send asks for them); anything
     * given must be right.
     */
    public static function clean_fields($fields) {
        $f = is_array($fields) ? $fields : array();
        $text = function ($key) use ($f) {
            return isset($f[$key]) && is_scalar($f[$key]) ? trim((string) $f[$key]) : '';
        };
        $errors = array();
        $kind = $text('kind');
        if ($kind !== '' && !in_array($kind, self::KINDS, true)) {
            $errors[] = 'Pick Club notice or Club news.';
            $kind = '';
        }
        $subject = sanitize_text_field($text('subject'));
        if (self::length($subject) > self::SUBJECT_MAX) {
            $errors[] = 'The subject is ' . self::length($subject) . ' characters long. Keep it to ' . self::SUBJECT_MAX . ' or fewer.';
        }
        $heading = sanitize_text_field($text('heading'));
        if (self::length($heading) > self::TEXT_MAX) {
            $errors[] = 'The heading is too long. Keep it to ' . self::TEXT_MAX . ' characters or fewer.';
        }
        $preheader = sanitize_text_field($text('preheader'));
        if (self::length($preheader) > self::TEXT_MAX) {
            $errors[] = 'The preview line is too long. Keep it to ' . self::TEXT_MAX . ' characters or fewer.';
        }
        $body = isset($f['body']) && is_string($f['body']) ? TeamOversight_Email::normalise(sanitize_textarea_field($f['body'])) : '';
        if (self::length($body) > self::BODY_MAX) {
            $errors[] = 'The message is too long for one email (' . number_format(self::length($body)) . ' characters; at most ' . number_format(self::BODY_MAX) . ').';
        }
        $label = sanitize_text_field($text('button_label'));
        if (self::length($label) > self::BUTTON_LABEL_MAX) {
            $errors[] = 'The button words are too long. Keep them to ' . self::BUTTON_LABEL_MAX . ' characters or fewer.';
        }
        $url = $text('button_url');
        $url_given = $url !== '';
        if ($url !== '') {
            $clean = esc_url_raw($url, array('http', 'https'));
            if ($clean === '' || !preg_match('~^https?://[^\s/]+~i', $clean) || strlen($clean) > self::BUTTON_URL_MAX) {
                $errors[] = 'The button link must be a web address starting with https:// (at most ' . self::BUTTON_URL_MAX . ' characters).';
            }
            $url = $clean;
        }
        if ($label !== '' && !$url_given) {
            $errors[] = 'Add the button link, or clear the button words.';
        }
        $image_id = isset($f['image_id']) && is_scalar($f['image_id']) ? max(0, intval($f['image_id'])) : 0;
        $alt = sanitize_text_field($text('image_alt'));
        if ($image_id > 0) {
            if (!wp_attachment_is_image($image_id)) {
                $errors[] = 'The picture isn\'t an image in the Media Library any more. Choose it again.';
            }
            if ($alt === '') {
                $errors[] = 'Describe the picture in a few words. Email programs read it out, or show it when pictures are off.';
            }
        }
        if (self::length($alt) > self::TEXT_MAX) {
            $errors[] = 'The picture description is too long. Keep it to ' . self::TEXT_MAX . ' characters or fewer.';
        }
        $reply_to = $text('reply_to');
        if ($reply_to !== '') {
            $clean = sanitize_email($reply_to);
            if ($clean === '' || !is_email($clean) || strlen($clean) > self::TEXT_MAX) {
                $errors[] = 'The Reply-To must be one email address, or blank for the club\'s usual one.';
            }
            $reply_to = $clean;
        }
        $audience = self::normalise_audience(isset($f['audience']) ? $f['audience'] : array());
        $errors = array_merge($errors, self::audience_problems($audience));
        return array(array(
            'kind' => $kind,
            'subject' => $subject,
            'heading' => $heading,
            'preheader' => $preheader,
            'body' => $body,
            'button_label' => $label,
            'button_url' => $url,
            'image_id' => $image_id,
            'image_alt' => $image_id > 0 ? $alt : '',
            'reply_to' => $reply_to,
            'audience' => wp_json_encode($audience),
            'audience_words' => self::audience_words($audience),
        ), $errors);
    }

    /**
     * Save a draft. $id 0 (or 'new') adds one; else $fields['version'] must be
     * the version the form was drawn from (compare-and-set: a second tab or
     * another administrator's save in between refuses it, as does a mailout
     * no longer a draft). Every save raises the version. Returns array(ok,
     * errors, id, version); nothing is written when errors isn't empty.
     */
    public static function save_draft($id, $fields, $actor) {
        global $wpdb;
        $out = array('ok' => false, 'errors' => array(), 'id' => 0, 'version' => 0);
        if (!self::tables_exist()) {
            $out['errors'][] = 'Mailouts aren\'t set up on this site yet. Reload the page.';
            return $out;
        }
        if (!self::can($actor)) {
            $out['errors'][] = 'Only administrators can change mailouts.';
            return $out;
        }
        list($clean, $errors) = self::clean_fields($fields);
        if ($errors) {
            $out['errors'] = $errors;
            return $out;
        }
        $now = self::now();
        $actor = intval($actor);
        $id = is_numeric($id) ? intval($id) : 0;
        if ($id <= 0) {
            $row = array_merge($clean, array(
                'state' => 'draft', 'version' => 1,
                'created_by' => $actor, 'created_at' => $now, 'updated_by' => $actor, 'updated_at' => $now,
            ));
            if ($wpdb->insert(self::table(), $row) === false) {
                $out['errors'][] = 'The draft couldn\'t be saved. Try again.';
                return $out;
            }
            $out['ok'] = true;
            $out['id'] = intval($wpdb->insert_id);
            $out['version'] = 1;
            return $out;
        }
        $expected = isset($fields['version']) && is_scalar($fields['version']) ? intval($fields['version']) : -1;
        $set = array();
        $args = array();
        foreach ($clean as $column => $value) {
            $set[] = "$column = " . ($column === 'image_id' ? '%d' : '%s');
            $args[] = $value;
        }
        $args = array_merge($args, array($actor, $now, $id, $expected));
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET ' . implode(', ', $set) . ", version = version + 1, updated_by = %d, updated_at = %s WHERE id = %d AND state = 'draft' AND version = %d",
            $args
        ));
        if ($n !== 1) {
            $row = self::get($id);
            if (!$row) {
                $out['errors'][] = 'That mailout doesn\'t exist any more.';
            } elseif ($row->state !== 'draft') {
                $out['errors'][] = 'This mailout has been sent, so it can\'t be changed. Copy it as a new draft to send a changed version.';
            } else {
                $out['errors'][] = 'This draft was changed in another tab, or by another administrator, since you opened it. Your changes weren\'t saved: reload the page to see the latest version.';
            }
            return $out;
        }
        $out['ok'] = true;
        $out['id'] = $id;
        $out['version'] = $expected + 1;
        return $out;
    }

    /** Delete a draft (drafts only: a mailout that was ever sent is never deleted). */
    public static function delete_draft($id, $actor) {
        global $wpdb;
        if (!self::can($actor) || !self::tables_exist()) {
            return false;
        }
        $n = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE id = %d AND state = 'draft'", intval($id)));
        if ($n === 1) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::recipients_table() . ' WHERE mailout_id = %d', intval($id)));
            return true;
        }
        return false;
    }

    /**
     * What the test send and Send check: md5 of the kind, subject, heading,
     * preview line, message, button, picture and Reply-To (never the audience).
     */
    public static function content_hash($row) {
        $row = (object) $row;
        $get = function ($key) use ($row) {
            return isset($row->$key) ? (string) $row->$key : '';
        };
        return md5((string) wp_json_encode(array(
            $get('kind'), $get('subject'), $get('heading'), $get('preheader'), $get('body'),
            $get('button_label'), $get('button_url'), (string) intval($get('image_id')), $get('image_alt'), $get('reply_to'),
        )));
    }

    /**
     * Record that a test of exactly version $version was sent (compare-and-set
     * on the version, so a save in between leaves it untested).
     */
    public static function mark_tested($id, $version, $actor) {
        global $wpdb;
        $row = self::get($id);
        if (!$row || $row->state !== 'draft' || intval($row->version) !== intval($version)) {
            return false;
        }
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET tested_hash = %s, tested_at = %s, tested_by = %d WHERE id = %d AND state = 'draft' AND version = %d",
            self::content_hash($row), self::now(), intval($actor), intval($id), intval($version)
        ));
        return $n === 1;
    }

    /** A draft whose last test was of exactly what it says now. */
    public static function is_tested($row) {
        $row = (object) $row;
        return isset($row->tested_hash) && (string) $row->tested_hash !== '' && hash_equals((string) $row->tested_hash, self::content_hash($row));
    }

    /**
     * Why a draft can't be sent yet (sentences; the recipient count is
     * checked separately, by send() and the page). Empty = it can.
     */
    public static function send_problems($row) {
        if (!$row) {
            return array('That mailout doesn\'t exist any more.');
        }
        $row = (object) $row;
        if ($row->state !== 'draft') {
            return array('This mailout has already been sent.');
        }
        $out = self::content_problems($row);
        if (!$out && !self::is_tested($row)) {
            $out[] = 'Send yourself a test of this version first.';
        }
        return $out;
    }

    /** What a test send needs: a kind, a subject and a message. */
    private static function content_problems($row) {
        $out = array();
        if (!in_array((string) $row->kind, self::KINDS, true)) {
            $out[] = 'Pick Club notice or Club news.';
        }
        if (trim((string) $row->subject) === '') {
            $out[] = 'Add a subject.';
        }
        if (trim((string) $row->body) === '') {
            $out[] = 'Write the message.';
        }
        return $out;
    }

    /**
     * Send the administrator a test of the draft as it is saved: to their own
     * address only, [TEST] in the subject, the news footer when it's news;
     * then mark that version tested. Works on any site (like the other test
     * sends), since it goes only to the person pressing it. Returns array(ok,
     * errors, to).
     */
    public static function send_test($id, $actor) {
        $out = array('ok' => false, 'errors' => array(), 'to' => '');
        if (!self::can($actor)) {
            $out['errors'][] = 'Only administrators can send mailouts.';
            return $out;
        }
        $row = self::get($id);
        if (!$row || $row->state !== 'draft') {
            $out['errors'][] = $row ? 'This mailout has already been sent.' : 'That mailout doesn\'t exist any more.';
            return $out;
        }
        $problems = self::content_problems($row);
        if ($problems) {
            $out['errors'] = $problems;
            return $out;
        }
        $user = get_userdata(intval($actor));
        if (!$user || !is_email($user->user_email)) {
            $out['errors'][] = 'Your account has no email address to send the test to.';
            return $out;
        }
        list($subject, $email, $headers) = self::build_email($row, intval($user->ID), (string) $user->display_name, (string) $user->user_email, self::test_groups(self::audience_of($row)), true);
        $sent = false;
        try {
            $sent = TeamOversight_Email::send($user->user_email, $subject, $email, $headers);
        } catch (\Throwable $e) {
            $sent = false;
        }
        if (!$sent) {
            $out['errors'][] = 'The test couldn\'t be sent. Check the site\'s mail setup.';
            return $out;
        }
        $out['to'] = (string) $user->user_email;
        if (!self::mark_tested(intval($row->id), intval($row->version), intval($actor))) {
            $out['errors'][] = 'The draft changed while the test was sending. Send another test of this version.';
            return $out;
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * Send a draft: work the audience out once more, refuse when the number of
     * people isn't the $shown_total the administrator confirmed (they look
     * again), when the page was drawn from another version, or when no test
     * of this version was sent; then, in one transaction, set the mailout
     * sending (compare-and-set on draft + version) and freeze the list: one
     * pending row per person (their email and name now, their groups) and one
     * skipped row per account left out (no email, unsubscribed). The sender
     * (tick()) does the rest. Returns array(ok, errors, total, left_out).
     */
    public static function send($id, $version, $shown_total, $actor) {
        global $wpdb;
        $out = array('ok' => false, 'errors' => array(), 'total' => 0, 'left_out' => 0);
        if (!self::tables_exist()) {
            $out['errors'][] = 'Mailouts aren\'t set up on this site yet. Reload the page.';
            return $out;
        }
        if (!self::can($actor)) {
            $out['errors'][] = 'Only administrators can send mailouts.';
            return $out;
        }
        $row = self::get($id);
        $problems = self::send_problems($row);
        if ($problems) {
            $out['errors'] = $problems;
            return $out;
        }
        if (intval($row->version) !== intval($version)) {
            $out['errors'][] = 'This draft changed since you opened it, so nothing was sent. Look at it again, then send.';
            return $out;
        }
        $audience = self::audience_of($row);
        $problems = self::audience_problems($audience);
        if ($problems) {
            $out['errors'] = $problems;
            return $out;
        }
        $resolved = self::resolve($audience, $row->kind);
        $total = intval($resolved['total']);
        if ($total === 0) {
            $out['errors'][] = 'Nobody would get this mailout. Pick who gets it first.';
            return $out;
        }
        if ($total !== intval($shown_total)) {
            $out['errors'][] = 'The number of people has changed since you looked: it\'s now ' . number_format($total) . ', not ' . number_format(intval($shown_total)) . '. Check who will get it, then send.';
            return $out;
        }
        $actor = intval($actor);
        $now = self::now();
        $left = count($resolved['left']);
        $wpdb->query('START TRANSACTION');
        $ok = false;
        try {
            $n = $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table() . " SET state = 'sending', sent_by = %d, started_at = %s, finished_at = NULL, held_since = NULL, total = %d, sent_total = 0, problem_total = 0, skipped_total = %d, audience_words = %s WHERE id = %d AND state = 'draft' AND version = %d",
                $actor, $now, $total, $left, self::audience_words($audience), intval($row->id), intval($version)
            ));
            if ($n === 1) {
                $ok = self::insert_rows(intval($row->id), $resolved['people'], 'pending')
                    && self::insert_rows(intval($row->id), $resolved['left'], 'skipped');
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            $fresh = self::get($id);
            $out['errors'][] = ($fresh && $fresh->state !== 'draft')
                ? 'This mailout has already been sent.'
                : 'The list couldn\'t be saved, so nothing was sent. Try again.';
            return $out;
        }
        $wpdb->query('COMMIT');
        self::log($row, 'sent to ' . number_format($total) . ' ' . ($total === 1 ? 'person' : 'people') . ' by ' . self::actor_name($actor) . ($left ? ' (' . number_format($left) . ' left out)' : ''));
        self::set_sending_flag();
        self::ensure_schedule();
        self::spawn();
        $out['ok'] = true;
        $out['total'] = $total;
        $out['left_out'] = $left;
        return $out;
    }

    /** Recipient rows, 200 to an INSERT. $status 'pending' or 'skipped' (then each person's reason). */
    private static function insert_rows($mailout_id, array $people, $status) {
        global $wpdb;
        foreach (array_chunk($people, 200) as $chunk) {
            $values = array();
            foreach ($chunk as $person) {
                $values[] = $wpdb->prepare(
                    '(%d, %d, %s, %s, %s, %s, %s)',
                    $mailout_id, intval($person['user_id']), self::cut($person['email'], 190), self::cut($person['name'], 190),
                    self::group_string($person['groups'], isset($person['program']) ? (string) $person['program'] : ''),
                    $status, $status === 'skipped' && isset($person['reason']) ? (string) $person['reason'] : ''
                );
            }
            if (!$values) {
                continue;
            }
            $n = $wpdb->query('INSERT INTO ' . self::recipients_table() . ' (mailout_id, user_id, email, name, group_keys, status, reason) VALUES ' . implode(', ', $values));
            if ($n === false || intval($n) !== count($values)) {
                return false;
            }
        }
        return true;
    }

    /** Pause a sending mailout (rows already being sent finish). */
    public static function pause($id, $actor) {
        return self::change_state($id, $actor, array('sending'), 'paused', 'paused');
    }

    /** Resume a paused mailout. */
    public static function resume($id, $actor) {
        $out = self::change_state($id, $actor, array('paused'), 'sending', 'resumed');
        if ($out['ok']) {
            self::set_sending_flag();
            self::ensure_schedule();
            self::spawn();
        }
        return $out;
    }

    private static function change_state($id, $actor, array $from, $to, $words) {
        global $wpdb;
        $out = array('ok' => false, 'errors' => array());
        if (!self::can($actor)) {
            $out['errors'][] = 'Only administrators can change mailouts.';
            return $out;
        }
        $row = self::get($id);
        if (!$row) {
            $out['errors'][] = 'That mailout doesn\'t exist any more.';
            return $out;
        }
        $in = implode(',', array_fill(0, count($from), '%s'));
        // held_since goes either way: an administrator has decided (a paused mailout keeps one only when the sender paused it after a long hold).
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET state = %s, held_since = NULL WHERE id = %d AND state IN ($in)", array_merge(array($to, intval($id)), $from)));
        if ($n !== 1) {
            $out['errors'][] = 'This mailout isn\'t ' . ($to === 'paused' ? 'sending' : 'paused') . ' any more. Reload the page.';
            return $out;
        }
        self::log($row, $words . ' by ' . self::actor_name($actor));
        if ($to === 'paused') {
            self::clear_schedule_if_idle();
        }
        $out['ok'] = true;
        return $out;
    }

    /**
     * Cancel what hasn't gone yet: waiting rows (pending, or failed and waiting
     * to try again) become cancelled; a row being sent this moment finishes.
     * The mailout ends cancelled when nothing was sent, else done.
     */
    public static function cancel_rest($id, $actor) {
        global $wpdb;
        $out = array('ok' => false, 'errors' => array(), 'cancelled' => 0);
        if (!self::can($actor)) {
            $out['errors'][] = 'Only administrators can change mailouts.';
            return $out;
        }
        $row = self::get($id);
        if (!$row || !in_array($row->state, array('sending', 'paused'), true)) {
            $out['errors'][] = $row ? 'This mailout isn\'t sending any more. Reload the page.' : 'That mailout doesn\'t exist any more.';
            return $out;
        }
        $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::recipients_table() . " SET status = 'cancelled', next_try_at = NULL WHERE mailout_id = %d AND final = 0 AND status IN ('pending', 'failed')", intval($id)));
        $n = $n === false ? 0 : intval($n);
        $gone = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::recipients_table() . " WHERE mailout_id = %d AND status IN ('sent', 'sending')", intval($id))));
        $state = $gone > 0 ? 'done' : 'cancelled';
        $c = self::counts($id);
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET state = %s, finished_at = %s, sent_total = %d, problem_total = %d, skipped_total = %d WHERE id = %d AND state IN ('sending', 'paused')",
            $state, self::now(), $c['sent'], $c['problems'], $c['skipped'], intval($id)
        ));
        if ($done !== 1) {
            $out['errors'][] = 'This mailout isn\'t sending any more. Reload the page.';
            return $out;
        }
        self::log($row, 'cancelled the rest by ' . self::actor_name($actor) . ' (' . number_format($n) . ' not sent)');
        self::clear_schedule_if_idle();
        $out['ok'] = true;
        $out['cancelled'] = $n;
        return $out;
    }

    /** A new draft with the same kind, email and audience (untested). Returns array(ok, errors, id). */
    public static function copy_as_draft($id, $actor) {
        global $wpdb;
        $out = array('ok' => false, 'errors' => array(), 'id' => 0);
        if (!self::can($actor)) {
            $out['errors'][] = 'Only administrators can change mailouts.';
            return $out;
        }
        $row = self::get($id);
        if (!$row) {
            $out['errors'][] = 'That mailout doesn\'t exist any more.';
            return $out;
        }
        $now = self::now();
        $audience = self::audience_of($row);
        $copy = array(
            'kind' => (string) $row->kind, 'state' => 'draft', 'subject' => (string) $row->subject, 'heading' => (string) $row->heading,
            'preheader' => (string) $row->preheader, 'body' => (string) $row->body, 'button_label' => (string) $row->button_label,
            'button_url' => (string) $row->button_url, 'image_id' => intval($row->image_id), 'image_alt' => (string) $row->image_alt,
            'reply_to' => (string) $row->reply_to, 'audience' => wp_json_encode($audience), 'audience_words' => self::audience_words($audience),
            'version' => 1, 'created_by' => intval($actor), 'created_at' => $now, 'updated_by' => intval($actor), 'updated_at' => $now,
        );
        if ($wpdb->insert(self::table(), $copy) === false) {
            $out['errors'][] = 'The copy couldn\'t be saved. Try again.';
            return $out;
        }
        $out['ok'] = true;
        $out['id'] = intval($wpdb->insert_id);
        return $out;
    }

    // ------------------------------------------------------------------
    // The email
    // ------------------------------------------------------------------

    /** The picture's address in the email (the large size, else the full one), or ''. */
    public static function image_url($image_id) {
        $image_id = intval($image_id);
        if ($image_id <= 0 || !wp_attachment_is_image($image_id)) {
            return '';
        }
        $url = wp_get_attachment_image_url($image_id, 'large');
        if (!$url) {
            $url = wp_get_attachment_url($image_id);
        }
        return is_string($url) && preg_match('~^https?://~i', $url) ? $url : '';
    }

    /** The message has a {button} line of its own (for the Button box's button; a line with its own link doesn't count). */
    private static function has_button_line($body) {
        foreach (explode("\n", str_replace(array("\r\n", "\r"), "\n", (string) $body)) as $line) {
            if (TeamOversight_Email::link_button($line) !== null) {
                continue;
            }
            if (preg_match(TeamOversight_Email::TOKEN_PATTERN, trim($line), $m) && strtolower($m[1]) === 'button') {
                return true;
            }
        }
        return false;
    }

    /**
     * 1.97.1: the message without its button lines that have their own link
     * ({button:Words https://...}), for the warnings that are about the
     * Button box's {button} and other placeholders.
     */
    public static function without_link_buttons($body) {
        $keep = array();
        foreach (explode("\n", str_replace(array("\r\n", "\r"), "\n", (string) $body)) as $line) {
            if (TeamOversight_Email::link_button($line) === null) {
                $keep[] = $line;
            }
        }
        return implode("\n", $keep);
    }

    /**
     * 1.97.1: warnings about button lines with their own link: a line that
     * looks like one (it starts {button and has an address in it) but isn't
     * right, or words longer than BUTTON_LABEL_MAX. Plain sentences.
     */
    public static function link_button_problems($body) {
        $problems = array();
        foreach (explode("\n", str_replace(array("\r\n", "\r"), "\n", (string) $body)) as $line) {
            $line = trim($line);
            if (stripos($line, '{button') !== 0) {
                continue;
            }
            $link = TeamOversight_Email::link_button($line);
            if ($link === null) {
                if (strpos($line, '://') !== false || stripos($line, 'www.') !== false) {
                    $shown = self::length($line) > 70 ? mb_substr($line, 0, 67) . '...' : $line;
                    $problems[] = 'This button line won\'t work as it is: ' . $shown . ' A button with its own link needs the words, then a web address starting with https://, all inside the braces on a line of its own, like {button:Register a team https://...}';
                }
                continue;
            }
            if (self::length($link[0]) > self::BUTTON_LABEL_MAX) {
                $problems[] = 'The words on the button "' . mb_substr($link[0], 0, 40) . '..." are long. Keep button words to ' . self::BUTTON_LABEL_MAX . ' characters or fewer.';
            }
        }
        return $problems;
    }

    /**
     * The headers: the Sender settings' From (as the other club emails), the
     * mailout's Reply-To or else the Sender settings' Reply-To, the mailout's
     * id, and for club news the one-click unsubscribe (RFC 8058).
     * TeamOversight_Email::send() adds the content type and the club marker.
     */
    private static function headers($row, $user_id) {
        $headers = array();
        foreach (TeamOversight_Payments::get_email_headers() as $header) {
            if (stripos($header, 'Reply-To:') !== 0) {
                $headers[] = $header;
            }
        }
        $reply_to = sanitize_email((string) $row->reply_to);
        if ($reply_to === '' || !is_email($reply_to)) {
            $reply_to = sanitize_email((string) get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION));
        }
        if ($reply_to !== '' && is_email($reply_to)) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }
        $headers[] = self::MAILOUT_HEADER . ': ' . intval($row->id);
        if ($row->kind === 'news' && intval($user_id) > 0) {
            $headers[] = 'List-Unsubscribe: <' . self::unsubscribe_url($user_id) . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }
        return $headers;
    }

    /**
     * One person's email: array(subject, email (render()'s html, text, id),
     * headers). $groups: the recipient row's groups (or keys), for the why
     * line. $test adds [TEST] to the subject.
     */
    public static function build_email($row, $user_id, $name, $email, $groups, $test = false) {
        $row = is_object($row) ? clone $row : (object) $row;
        foreach (array('kind', 'subject', 'heading', 'preheader', 'body', 'button_label', 'button_url', 'image_alt', 'reply_to') as $key) {
            $row->$key = isset($row->$key) ? (string) $row->$key : '';
        }
        $row->id = isset($row->id) ? intval($row->id) : 0;
        $row->image_id = isset($row->image_id) ? intval($row->image_id) : 0;
        $kind = $row->kind === 'news' ? 'news' : 'notice';
        $row->kind = $kind;
        $user_id = intval($user_id);
        $vars = array('{first_name}' => TeamOversight_Email::first_name($user_id, (string) $name));
        $body = $row->body;
        $button = null;
        if ($row->button_url !== '') {
            $button = array('url' => $row->button_url, 'label' => $row->button_label !== '' ? $row->button_label : self::DEFAULT_BUTTON);
            if (!self::has_button_line($body)) {
                // The button after the last paragraph.
                $body = rtrim($body) . "\n\n{button}";
            }
        }
        $image = null;
        $image_url = $row->image_id > 0 ? self::image_url($row->image_id) : '';
        if ($image_url !== '') {
            $image = array('url' => $image_url, 'alt' => $row->image_alt, 'link' => $row->button_url, 'width' => self::IMAGE_WIDTH);
        }
        $built = TeamOversight_Email::render(array(
            'program' => $kind === 'news' ? 'Club news' : 'Club notice',
            'heading' => $row->heading !== '' ? $row->heading : $row->subject,
            'body' => $body,
            'vars' => $vars,
            'button' => $button,
            'preheader' => $row->preheader,
            'why' => self::why_line($groups, self::audience_of($row)),
            'image' => $image,
            'unsubscribe_url' => ($kind === 'news' && $user_id > 0) ? self::unsubscribe_url($user_id) : '',
            'link_buttons' => true,
        ));
        $subject = TeamOversight_Email::fill($row->subject, $vars);
        if ($test) {
            $subject = '[TEST] ' . $subject;
        }
        return array($subject, $built, self::headers($row, $user_id));
    }

    // ------------------------------------------------------------------
    // The sender
    // ------------------------------------------------------------------

    public static function lock_name() {
        global $wpdb;
        return 'murvc_mailout_tick_' . md5($wpdb->prefix);
    }

    /**
     * One run of the sender (every five minutes while a mailout is sending).
     * $live null = TeamOversight_Offers::emails_live(); the tests pass true,
     * with a pre_wp_mail catcher installed. $only: mailout ids to send for (the
     * tests; null = every sending mailout). While the gate is closed it sends
     * nothing and notes held_since on the sending mailouts. Else, under a
     * named lock (not granted = another tick is running: return): a mailout
     * held for more than HELD_PAUSE_SECONDS is paused (an administrator
     * resumes it on purpose), the others' held_since is cleared; rows left
     * in 'sending' for STUCK_MINUTES become 'unknown'; room = BATCH, less what
     * the hour and day caps have left; then oldest mailout first, row by row:
     * claim (compare-and-set), check, send, record; finished mailouts are
     * closed with their totals; the schedule is cleared when nothing is
     * sending. Returns a report.
     */
    public static function tick($live = null, $only = null) {
        global $wpdb;
        $report = array('live' => false, 'held' => false, 'busy' => false, 'paused' => array(), 'stuck' => 0, 'hour' => 0, 'day' => 0, 'room' => 0, 'capped' => false, 'claimed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'finished' => array());
        if (!self::tables_exist()) {
            return $report;
        }
        $live = $live === null ? (class_exists('TeamOversight_Offers') && TeamOversight_Offers::emails_live()) : ($live === true);
        $report['live'] = $live;
        $only_sql = '';
        if ($only !== null) {
            $ids = array_values(array_filter(array_map('intval', (array) $only)));
            $only_sql = $ids ? ' AND m.id IN (' . implode(',', $ids) . ')' : ' AND 1 = 0';
        }
        if (!$live) {
            $report['held'] = true;
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " m SET m.held_since = %s WHERE m.state = 'sending' AND m.held_since IS NULL" . $only_sql, self::now()));
            self::clear_schedule_if_idle();
            return $report;
        }
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name())) !== '1') {
            $report['busy'] = true;
            return $report;
        }
        try {
            // Held for more than a day (the email permission was closed): paused, not sent, so an
            // administrator decides whether it still goes. held_since stays on it for the page.
            $stale = $wpdb->get_results($wpdb->prepare('SELECT m.* FROM ' . self::table() . " m WHERE m.state = 'sending' AND m.held_since IS NOT NULL AND m.held_since < %s" . $only_sql . ' ORDER BY m.id', self::time_ago(self::HELD_PAUSE_SECONDS)));
            foreach ((array) $stale as $m) {
                $n = $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET state = 'paused' WHERE id = %d AND state = 'sending' AND held_since IS NOT NULL", intval($m->id)));
                if ($n === 1) {
                    $report['paused'][] = intval($m->id);
                    $ts = strtotime((string) $m->held_since);
                    self::log($m, 'paused: emails were held from ' . ($ts ? date('j M Y, g:ia', $ts) : 'more than a day ago') . ', so it waits for an administrator to resume it');
                }
            }
            $wpdb->query('UPDATE ' . self::table() . " m SET m.held_since = NULL WHERE m.state = 'sending' AND m.held_since IS NOT NULL" . $only_sql);
            $report['stuck'] = self::mark_stuck();
            $report['hour'] = self::sent_in_window(HOUR_IN_SECONDS);
            $report['day'] = self::sent_in_window(DAY_IN_SECONDS);
            $room = min(self::BATCH, self::HOUR_CAP - $report['hour'], self::DAY_CAP - $report['day']);
            $report['room'] = max(0, $room);
            $report['capped'] = $room < self::BATCH;
            $deadline = microtime(true) + self::TICK_SECONDS;
            $mailouts = array();
            if ($room > 0) {
                $now = self::now();
                $candidates = $wpdb->get_results($wpdb->prepare(
                    'SELECT r.* FROM ' . self::recipients_table() . ' r JOIN ' . self::table() . " m ON m.id = r.mailout_id
                    WHERE m.state = 'sending' AND r.final = 0 AND (r.status = 'pending' OR (r.status = 'failed' AND r.next_try_at <= %s))" . $only_sql . '
                    ORDER BY m.started_at, m.id, r.id LIMIT %d',
                    $now, $room
                ));
                foreach ((array) $candidates as $r) {
                    if (microtime(true) > $deadline) {
                        break;
                    }
                    if (!self::claim(intval($r->id))) {
                        continue;
                    }
                    // The account as the row holds it now: an account merge that committed since the list was
                    // read re-points rows (the claim waits for its row lock), and the old account is then gone.
                    $fresh = $wpdb->get_row($wpdb->prepare('SELECT user_id, group_keys FROM ' . self::recipients_table() . ' WHERE id = %d', intval($r->id)));
                    if ($fresh) {
                        $r->user_id = $fresh->user_id;
                        $r->group_keys = $fresh->group_keys;
                    }
                    $report['claimed']++;
                    $mailout_id = intval($r->mailout_id);
                    if (!isset($mailouts[$mailout_id])) {
                        $mailouts[$mailout_id] = self::get($mailout_id);
                    }
                    $result = self::process_row($r, $mailouts[$mailout_id]);
                    if (isset($report[$result])) {
                        $report[$result]++;
                    }
                    self::after_row(intval($r->id), $mailout_id);
                }
            }
            // Close every sending mailout with nothing left to send.
            $sending = $wpdb->get_col('SELECT m.id FROM ' . self::table() . " m WHERE m.state = 'sending'" . $only_sql);
            foreach ((array) $sending as $mailout_id) {
                if (self::finish_if_done(intval($mailout_id))) {
                    $report['finished'][] = intval($mailout_id);
                }
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
        }
        self::clear_schedule_if_idle();
        return $report;
    }

    /** Rows left in 'sending' for STUCK_MINUTES become 'unknown' (they may have gone): never retried automatically. */
    private static function mark_stuck() {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::recipients_table() . " SET status = 'unknown', final = 1, reason = 'stuck', error = %s WHERE status = 'sending' AND (claimed_at IS NULL OR claimed_at < %s)",
            'Stopped part-way: it may or may not have been delivered.', self::time_ago(self::STUCK_MINUTES * 60)
        ));
        return $n === false ? 0 : intval($n);
    }

    /**
     * pending (or failed, its wait over) => sending, tries + 1, by
     * compare-and-set: true only for the one call whose UPDATE changed the
     * row, so two senders never send one email.
     */
    private static function claim($row_id) {
        global $wpdb;
        $now = self::now();
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::recipients_table() . " SET status = 'sending', tries = tries + 1, claimed_at = %s, last_try_at = %s WHERE id = %d AND final = 0 AND status IN ('pending', 'failed') AND (status = 'pending' OR next_try_at <= %s)",
            $now, $now, intval($row_id), $now
        ));
        return $n === 1;
    }

    /** sending => $status, compare-and-set on 'sending'. */
    private static function finish_row($row_id, array $set) {
        global $wpdb;
        $cols = array();
        $args = array();
        foreach ($set as $column => $value) {
            if ($value === null) {
                $cols[] = "$column = NULL";
                continue;
            }
            $cols[] = "$column = " . (is_int($value) ? '%d' : '%s');
            $args[] = $value;
        }
        $args[] = intval($row_id);
        return $wpdb->query($wpdb->prepare('UPDATE ' . self::recipients_table() . ' SET ' . implode(', ', $cols) . " WHERE id = %d AND status = 'sending'", $args)) === 1;
    }

    /** One claimed row to its result for this attempt: 'sent', 'failed', 'skipped' or 'returned' (the mailout stopped meanwhile). */
    private static function process_row($r, $mailout) {
        global $wpdb;
        $id = intval($r->id);
        $state = $mailout ? (string) $wpdb->get_var($wpdb->prepare('SELECT state FROM ' . self::table() . ' WHERE id = %d', intval($mailout->id))) : '';
        if ($state !== 'sending') {
            // Paused or ended between the pick and the claim: back to waiting (the try doesn't count), or cancelled.
            if ($state === 'paused') {
                self::finish_row($id, array('status' => $r->status === 'failed' ? 'failed' : 'pending', 'tries' => max(0, intval($r->tries)), 'claimed_at' => null));
            } else {
                self::finish_row($id, array('status' => 'cancelled', 'next_try_at' => null));
            }
            return 'returned';
        }
        $user = get_userdata(intval($r->user_id));
        if (!$user && intval($r->user_id) > 0 && class_exists('TeamOversight_Account_Merge') && method_exists('TeamOversight_Account_Merge', 'kept_account_for')) {
            // An account merged away whose row wasn't re-pointed: the person is the kept account.
            $kept = intval(TeamOversight_Account_Merge::kept_account_for(intval($r->user_id)));
            $user = $kept > 0 ? get_userdata($kept) : false;
            if ($user) {
                self::finish_row($id, array('user_id' => intval($user->ID)));
            }
        }
        if (!$user) {
            self::finish_row($id, array('status' => 'skipped', 'reason' => 'no_account', 'final' => 1));
            return 'skipped';
        }
        $email_address = (string) $user->user_email;
        if (!is_email($email_address)) {
            self::finish_row($id, array('status' => 'skipped', 'reason' => 'no_email', 'final' => 1));
            return 'skipped';
        }
        if ($mailout->kind === 'news' && self::is_opted_out(intval($user->ID))) {
            self::finish_row($id, array('status' => 'skipped', 'reason' => 'unsubscribed', 'final' => 1));
            return 'skipped';
        }
        // One email per person: an account merge after Send re-points a row to an account that may
        // already have one here, and an undo of that merge points it back. Another row of this mailout
        // for the account, or that went to this address, that went, is going or may have gone means
        // this one is left out.
        $other = intval($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::recipients_table() . " WHERE mailout_id = %d AND id <> %d AND (user_id = %d OR email = %s) AND status IN ('sent', 'sending', 'unknown')",
            intval($mailout->id), $id, intval($user->ID), $email_address
        )));
        if ($other > 0) {
            self::finish_row($id, array('status' => 'skipped', 'reason' => 'duplicate', 'final' => 1));
            return 'skipped';
        }
        $error = '';
        $catch = function ($wp_error) use (&$error) {
            if (is_wp_error($wp_error)) {
                $error = (string) $wp_error->get_error_message();
            }
        };
        $sent = false;
        add_action('wp_mail_failed', $catch);
        try {
            list($subject, $email, $headers) = self::build_email($mailout, intval($user->ID), (string) $user->display_name, $email_address, (string) $r->group_keys);
            $sent = TeamOversight_Email::send($email_address, $subject, $email, $headers);
        } catch (\Throwable $e) {
            $sent = false;
            $error = 'Sending failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').';
        } finally {
            remove_action('wp_mail_failed', $catch);
        }
        if ($sent) {
            self::finish_row($id, array('status' => 'sent', 'sent_at' => self::now(), 'email' => self::cut($email_address, 190), 'error' => '', 'next_try_at' => null));
            return 'sent';
        }
        $tries = intval($r->tries) + 1;
        $final = $tries >= self::MAX_TRIES;
        self::finish_row($id, array(
            'status' => 'failed',
            'final' => $final ? 1 : 0,
            'reason' => 'mail_failed',
            'next_try_at' => $final ? null : self::time_ago(-self::RETRY_MINUTES * 60),
            'error' => TeamOversight_Log::cut_bytes($error !== '' ? $error : 'The mail system reported a failure.', 250),
        ));
        return 'failed';
    }

    /**
     * A row that finished after its mailout was cancelled or ended (it was
     * being sent at that moment): a failure is final (nothing will try it
     * again) and the stored totals are brought up to date.
     */
    private static function after_row($row_id, $mailout_id) {
        global $wpdb;
        $state = (string) $wpdb->get_var($wpdb->prepare('SELECT state FROM ' . self::table() . ' WHERE id = %d', $mailout_id));
        if (!in_array($state, array('done', 'cancelled'), true)) {
            return;
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . self::recipients_table() . " SET final = 1, next_try_at = NULL WHERE id = %d AND status = 'failed' AND final = 0", $row_id));
        $c = self::counts($mailout_id);
        if ($c['rows'] > 0) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . self::table() . " SET sent_total = %d, problem_total = %d, skipped_total = %d WHERE id = %d AND state IN ('done', 'cancelled')",
                $c['sent'], $c['problems'], $c['skipped'], $mailout_id
            ));
        }
    }

    /** A sending mailout with no row waiting (pending, sending, or failed with tries left) is done: totals, finished_at, one log line. */
    private static function finish_if_done($mailout_id) {
        global $wpdb;
        $waiting = intval($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::recipients_table() . " WHERE mailout_id = %d AND (status IN ('pending', 'sending') OR (status = 'failed' AND final = 0))",
            $mailout_id
        )));
        if ($waiting > 0) {
            return false;
        }
        $c = self::counts($mailout_id);
        $n = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET state = 'done', finished_at = %s, sent_total = %d, problem_total = %d, skipped_total = %d WHERE id = %d AND state = 'sending'",
            self::now(), $c['sent'], $c['problems'], $c['skipped'], $mailout_id
        ));
        if ($n !== 1) {
            return false;
        }
        $row = self::get($mailout_id);
        self::log($row, 'finished: ' . number_format($c['sent']) . ' sent, ' . number_format($c['problems']) . ' ' . ($c['problems'] === 1 ? 'problem' : 'problems') . ', ' . number_format($c['skipped']) . ' left out');
        return true;
    }

    /** The sender's five-minute event, scheduled once (on Send and Resume, and by heal_schedule()). */
    public static function ensure_schedule() {
        if (!has_filter('cron_schedules', array(__CLASS__, 'cron_schedules'))) {
            add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        }
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE, self::TICK_HOOK);
        }
    }

    /** How many mailouts are sending. */
    public static function sending_count() {
        global $wpdb;
        return self::tables_exist() ? intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE state = 'sending'")) : 0;
    }

    /**
     * SENDING_FLAG is set. Read from the autoloaded options only, so a site
     * with nothing sending (no such option) issues no query on any request,
     * as get_option() would for a missing option.
     */
    private static function flag_on() {
        $all = wp_load_alloptions();
        return is_array($all) && isset($all[self::SENDING_FLAG]) && (string) $all[self::SENDING_FLAG] === '1';
    }

    private static function set_sending_flag() {
        if (!self::flag_on()) {
            update_option(self::SENDING_FLAG, '1', true);
        }
    }

    /**
     * Clear the five-minute event (and SENDING_FLAG) when no mailout is
     * sending. Then count again: a Send or Resume that committed while this
     * ran saw the old event and added none, so the schedule goes back.
     */
    public static function clear_schedule_if_idle() {
        if (!self::tables_exist() || self::sending_count() > 0) {
            return;
        }
        if (wp_next_scheduled(self::TICK_HOOK)) {
            wp_clear_scheduled_hook(self::TICK_HOOK);
        }
        if (self::flag_on()) {
            delete_option(self::SENDING_FLAG);
        }
        if (self::sending_count() > 0) {
            self::set_sending_flag();
            self::ensure_schedule();
        }
    }

    /**
     * Put the five-minute event back when a mailout is sending and the event
     * is gone, so a mailout never stalls: WordPress keeps every cron event in
     * one option, and a request saving an older copy of it can drop the
     * event; clearing can race with another administrator's Send; switching
     * the plugin off clears it. $check_db false (init, every request): trust
     * SENDING_FLAG, which is autoloaded, so this costs no query; true (the
     * Mailouts page, the daily membership cron, activation): count the
     * sending mailouts and set the flag to match. True when it scheduled.
     */
    public static function heal_schedule($check_db = false) {
        if ($check_db === true) {
            if (self::sending_count() === 0) {
                return false;
            }
            self::set_sending_flag();
        } elseif (!self::flag_on()) {
            return false;
        }
        if (wp_next_scheduled(self::TICK_HOOK)) {
            return false;
        }
        self::ensure_schedule();
        return true;
    }

    /** Ask WP-cron to run now, so the first emails don't wait for the next visit (not from WP-CLI). */
    private static function spawn() {
        if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron() || !function_exists('spawn_cron')) {
            return;
        }
        spawn_cron();
    }

    // ------------------------------------------------------------------
    // Club news opt-out and the unsubscribe page
    // ------------------------------------------------------------------

    public static function is_opted_out($user_id) {
        return intval($user_id) > 0 && (string) get_user_meta(intval($user_id), self::OPTOUT_META, true) !== '';
    }

    /** array(opted_out, at (site time), via (link, one_click, admin, or '')). */
    public static function optout_facts($user_id) {
        $at = intval($user_id) > 0 ? (string) get_user_meta(intval($user_id), self::OPTOUT_META, true) : '';
        $via = $at !== '' ? (string) get_user_meta(intval($user_id), self::OPTOUT_VIA_META, true) : '';
        return array('opted_out' => $at !== '', 'at' => $at, 'via' => in_array($via, self::OPTOUT_VIA, true) ? $via : '');
    }

    private static function via_words($via) {
        $words = array('link' => 'email link', 'one_click' => 'one click', 'admin' => 'by an administrator');
        return isset($words[$via]) ? $words[$via] : 'email link';
    }

    /**
     * Stop club news for an account. $via: link, one_click or admin (an
     * administrator's handler checks its own capability). $for_merged: the
     * account whose earlier email's link asked, when it has since been merged
     * into this one (remembered so an undo of that merge can give the request
     * back to it). True when it changed something (already unsubscribed = false).
     */
    public static function opt_out($user_id, $via, $for_merged = 0) {
        $user_id = intval($user_id);
        $for_merged = intval($for_merged);
        $via = in_array($via, self::OPTOUT_VIA, true) ? $via : 'link';
        if ($user_id <= 0 || !get_userdata($user_id)) {
            return false;
        }
        if ($for_merged > 0 && $for_merged !== $user_id) {
            $asked = self::asked_list($user_id);
            if (!in_array($for_merged, $asked, true)) {
                $asked[] = $for_merged;
                update_user_meta($user_id, self::OPTOUT_ASKED_META, $asked);
            }
        } else {
            $for_merged = 0;
        }
        if (self::is_opted_out($user_id)) {
            if ($for_merged === 0) {
                // The account's own request now stands behind the opt-out too.
                delete_user_meta($user_id, self::OPTOUT_FROM_META);
            }
            return false;
        }
        update_user_meta($user_id, self::OPTOUT_META, self::now());
        update_user_meta($user_id, self::OPTOUT_VIA_META, $via);
        if ($for_merged > 0) {
            update_user_meta($user_id, self::OPTOUT_FROM_META, $for_merged);
        } else {
            delete_user_meta($user_id, self::OPTOUT_FROM_META);
        }
        TeamOversight_Log::add(self::LOG_TYPE, 'Club news unsubscribed (' . self::via_words($via) . ')'
            . ($for_merged > 0 ? ', through an earlier email to account #' . $for_merged . ', since merged into this one' : ''), array('user_id' => $user_id));
        return true;
    }

    /** The merged-away account ids whose earlier emails' links asked this account to stop club news. */
    private static function asked_list($user_id) {
        $asked = get_user_meta(intval($user_id), self::OPTOUT_ASKED_META, true);
        return is_array($asked) ? array_values(array_unique(array_filter(array_map('intval', $asked)))) : array();
    }

    /**
     * Start club news again (deletes the opt-out metas). $for_merged: as for
     * opt_out(), the merged-away account whose earlier email's link was used;
     * its earlier request is withdrawn. True when it changed something.
     */
    public static function opt_in($user_id, $via, $for_merged = 0) {
        $user_id = intval($user_id);
        $for_merged = intval($for_merged);
        $via = in_array($via, self::OPTOUT_VIA, true) ? $via : 'link';
        if ($user_id > 0 && $for_merged > 0) {
            $asked = self::asked_list($user_id);
            $left = array_values(array_diff($asked, array($for_merged)));
            if (count($left) !== count($asked)) {
                if ($left) {
                    update_user_meta($user_id, self::OPTOUT_ASKED_META, $left);
                } else {
                    delete_user_meta($user_id, self::OPTOUT_ASKED_META);
                }
            }
        }
        if ($user_id <= 0 || !self::is_opted_out($user_id)) {
            return false;
        }
        delete_user_meta($user_id, self::OPTOUT_META);
        delete_user_meta($user_id, self::OPTOUT_VIA_META);
        delete_user_meta($user_id, self::OPTOUT_FROM_META);
        TeamOversight_Log::add(self::LOG_TYPE, 'Club news started again (' . self::via_words($via) . ')', array('user_id' => $user_id));
        return true;
    }

    /** How many accounts have unsubscribed from club news. */
    public static function optout_count() {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", self::OPTOUT_META)));
    }

    /**
     * The key unsubscribe links are signed with: its own option, made on first
     * use and never autoloaded. Not the site's salts, because changing those
     * (a security plugin does it in one click) would break every link already
     * sent, and an unsubscribe link has to keep working.
     */
    private static function secret() {
        if (is_string(self::$secret) && strlen(self::$secret) >= 32) {
            return self::$secret;
        }
        // Read from the table itself, never get_option(): a stale "doesn't exist" in the object cache, or one
        // failed read cached as missing, must never lead to a new key, because a new key breaks every link
        // already sent. A new key is made only when the row is really absent, by INSERT IGNORE, so a key
        // that exists is never replaced, and the row is read back so two first uses end up with the same key.
        $stored = self::read_secret();
        if ($stored === null) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::SECRET_OPTION, wp_generate_password(64, true, true)
            ));
            wp_cache_delete(self::SECRET_OPTION, 'options');
            $notoptions = wp_cache_get('notoptions', 'options');
            if (is_array($notoptions) && isset($notoptions[self::SECRET_OPTION])) {
                unset($notoptions[self::SECRET_OPTION]);
                wp_cache_set('notoptions', $notoptions, 'options');
            }
            $stored = self::read_secret();
        }
        if ($stored === null || strlen($stored) < 32) {
            // Never sign with anything else: a link signed some other way would never check out later.
            throw new \RuntimeException('The club news signing key could not be read.');
        }
        self::$secret = $stored;
        return $stored;
    }

    /** The stored key, or null when there is no row. Throws when the read fails (a failure is never "no key"). */
    private static function read_secret() {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::SECRET_OPTION));
        if ((string) $wpdb->last_error !== '') {
            throw new \RuntimeException('The club news signing key could not be read.');
        }
        return $row ? (string) $row->option_value : null;
    }

    /**
     * Makes the signing key now if it isn't there yet (the Mailouts page and
     * activation call it, so a signed-out request rarely has to). Never throws.
     */
    public static function ensure_secret() {
        try {
            self::secret();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Tests only: forget the key this request read, so the next use reads the table again. */
    public static function forget_secret() {
        self::$secret = null;
    }

    private static function signature($user_id) {
        return substr(hash_hmac('sha256', 'murvc-news|' . intval($user_id), self::secret()), 0, 24);
    }

    /** The account's unsubscribe token: ID.SIG (SIG = the first 24 hex of an HMAC of the id with the key in SECRET_OPTION). */
    public static function token($user_id) {
        return intval($user_id) . '.' . self::signature($user_id);
    }

    /**
     * The account a token belongs to, else 0. Compared with hash_equals(). An
     * account merged away since the email went is followed to the account it
     * was merged into (the same person; the merge carries an opt-out there
     * too), so an earlier email's link still works.
     */
    public static function check_token($token) {
        if (!is_string($token) || preg_match('/^([1-9][0-9]{0,19})\.([0-9a-f]{24})$/D', $token, $m) !== 1) {
            return 0;
        }
        $user_id = intval($m[1]);
        if (!hash_equals(self::signature($user_id), $m[2])) {
            return 0;
        }
        if (get_userdata($user_id)) {
            return $user_id;
        }
        return (class_exists('TeamOversight_Account_Merge') && method_exists('TeamOversight_Account_Merge', 'kept_account_for'))
            ? intval(TeamOversight_Account_Merge::kept_account_for($user_id))
            : 0;
    }

    /** The account's unsubscribe page (admin-post: never page-cached, works signed out). */
    public static function unsubscribe_url($user_id) {
        return self::token_url(self::token($user_id));
    }

    /** The unsubscribe page for a token already checked. */
    private static function token_url($token) {
        return admin_url('admin-post.php?action=' . self::NEWS_ACTION . '&t=' . rawurlencode((string) $token));
    }

    /** admin_post(_nopriv)_murvc_news: the unsubscribe page, its two buttons and the one-click POST. */
    public static function handle_unsubscribe() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $result = self::unsubscribe_request($method, wp_unslash($_GET), wp_unslash($_POST));
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        if ($result['redirect'] !== '') {
            wp_safe_redirect($result['redirect'], 303);
            exit;
        }
        status_header($result['status']);
        if ($result['status'] === 503) {
            header('Retry-After: 300', true);
        }
        if ($result['html'] !== '') {
            header('Content-Type: text/html; charset=utf-8', true);
            echo $result['html']; // Built from escaped parts in unsubscribe_page().
        }
        exit;
    }

    /**
     * The unsubscribe page's logic, without output (the tests call it):
     * returns array(status, html, redirect, done). A POST with
     * List-Unsubscribe=One-Click (RFC 8058) unsubscribes and answers 200 with
     * no page; any other POST is a button (do=stop / do=start), answered with
     * a redirect back to the page (Post/Redirect/Get); a GET draws the page.
     * The token is the only key: a bad one changes nothing.
     */
    public static function unsubscribe_request($method, $get, $post) {
        $get = is_array($get) ? $get : array();
        $post = is_array($post) ? $post : array();
        $token = isset($get['t']) && is_string($get['t']) ? $get['t'] : (isset($post['t']) && is_string($post['t']) ? $post['t'] : '');
        $out = array('status' => 200, 'html' => '', 'redirect' => '', 'done' => '');
        $one_click = strtoupper((string) $method) === 'POST' && isset($post['List-Unsubscribe']) && is_string($post['List-Unsubscribe']) && trim($post['List-Unsubscribe']) === 'One-Click';
        try {
            $user_id = self::check_token($token);
        } catch (\Throwable $e) {
            // The signing key couldn't be read just now: never "isn't valid" (the link is fine). A mail
            // program's one-click request gets 503, which it retries; a person gets a try-again page.
            $out['status'] = 503;
            if (!$one_click) {
                $out['html'] = self::unsubscribe_page('error', '', '');
            }
            return $out;
        }
        // The account the link was made for, when it has since been merged into $user_id.
        $token_id = $user_id ? intval(substr($token, 0, strpos($token, '.'))) : 0;
        $for_merged = ($token_id > 0 && $token_id !== $user_id) ? $token_id : 0;
        if (strtoupper((string) $method) === 'POST') {
            if ($one_click) {
                if (!$user_id) {
                    $out['status'] = 400;
                    return $out;
                }
                self::opt_out($user_id, 'one_click', $for_merged);
                $out['done'] = 'one_click';
                return $out;
            }
            if (!$user_id) {
                $out['html'] = self::unsubscribe_page('bad', '', '');
                return $out;
            }
            $do = isset($post['do']) && is_string($post['do']) ? $post['do'] : '';
            if ($do === 'stop') {
                self::opt_out($user_id, 'link', $for_merged);
            } elseif ($do === 'start') {
                self::opt_in($user_id, 'link', $for_merged);
            }
            $out['done'] = in_array($do, array('stop', 'start'), true) ? $do : '';
            // Back to the same link (not the kept account's), so a later click there still counts as that email's.
            $out['redirect'] = self::token_url($token) . ($out['done'] !== '' ? '&done=' . $out['done'] : '');
            return $out;
        }
        if (!$user_id) {
            $out['html'] = self::unsubscribe_page('bad', '', '');
            return $out;
        }
        $user = get_userdata($user_id);
        $first = TeamOversight_Email::first_name($user_id, $user ? (string) $user->display_name : '');
        $state = self::is_opted_out($user_id) ? 'done' : ((isset($get['done']) && $get['done'] === 'start') ? 'started' : 'ask');
        $out['html'] = self::unsubscribe_page($state, $first, self::token_url($token));
        return $out;
    }

    /**
     * The small standalone page in the club look: 'ask' (Stop club news),
     * 'done' (Start them again), 'started' (back on), 'bad' (a link that
     * isn't valid). Only the account's first name is shown.
     */
    private static function unsubscribe_page($state, $first_name, $action_url) {
        $first = esc_html(TeamOversight_Email::decode($first_name));
        $club = sanitize_email((string) get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION));
        if ($club === '') {
            $club = sanitize_email((string) get_option(TeamOversight_Payments::EMAIL_FROM_OPTION));
        }
        $button = function ($do, $label, $primary) use ($action_url) {
            return '<form method="post" action="' . esc_url($action_url) . '"><input type="hidden" name="do" value="' . esc_attr($do) . '">'
                . '<button type="submit" class="' . ($primary ? 'go' : 'quiet') . '">' . esc_html($label) . '</button></form>';
        };
        if ($state === 'error') {
            $heading = 'Club news emails';
            $body = '<p>Something went wrong on our side. Try the link again in a few minutes.</p>';
        } elseif ($state === 'bad') {
            $heading = 'Club news emails';
            $email_club = $club !== '' ? '<a href="mailto:' . esc_attr($club) . '">Email the club</a>' : 'Email the club';
            $body = '<p>This link isn\'t valid. ' . $email_club . ' to be taken off the list.</p>';
        } elseif ($state === 'done') {
            $heading = 'Done';
            $body = '<p>You won\'t get club news emails any more. You\'ll still get emails about your membership, fees, teams and club notices.</p>'
                . $button('start', 'Start them again', false);
        } elseif ($state === 'started') {
            $heading = 'Club news emails are back on';
            $body = '<p>' . ($first !== '' ? 'Thanks, ' . $first . '. ' : '') . 'You\'ll get club news emails again.</p>'
                . $button('stop', 'Stop club news', false);
        } else {
            $heading = 'Stop getting club news emails?';
            $body = '<p>' . ($first !== '' ? 'Hi ' . $first . '. ' : '') . 'This stops the club\'s emails about its programs and events. Club notices and emails about your membership still come.</p>'
                . $button('stop', 'Stop club news', true);
        }
        $c = array(
            'light' => TeamOversight_Email::LIGHT, 'red' => TeamOversight_Email::RED, 'navy' => TeamOversight_Email::NAVY,
            'ink' => TeamOversight_Email::INK, 'muted' => TeamOversight_Email::MUTED, 'blue' => TeamOversight_Email::BLUE, 'line' => TeamOversight_Email::LINE,
        );
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . esc_html($heading) . ' - ' . esc_html(TeamOversight_Email::CLUB_NAME) . '</title>
<style>
body{margin:0;padding:24px 12px;background:' . $c['light'] . ';font-family:' . TeamOversight_Email::FONT . ';color:' . $c['ink'] . ';}
.card{max-width:520px;margin:0 auto;background:#fff;border-top:4px solid ' . $c['red'] . ';border-radius:10px;padding:22px 28px 26px;}
.club{display:flex;align-items:center;gap:12px;padding-bottom:16px;margin-bottom:20px;border-bottom:1px solid ' . $c['line'] . ';}
.club img{width:48px;height:48px;border-radius:8px;}
.club small{display:block;font-size:12px;color:' . $c['muted'] . ';}
.club strong{display:block;font-size:17px;color:' . $c['navy'] . ';}
.label{margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:' . $c['red'] . ';}
h1{margin:0 0 14px;font-size:24px;line-height:1.25;color:' . $c['navy'] . ';}
p{font-size:16px;line-height:1.55;margin:0 0 18px;}
a{color:' . $c['blue'] . ';}
button{font:inherit;font-size:16px;font-weight:700;border-radius:6px;padding:12px 22px;cursor:pointer;}
button.go{background:' . $c['blue'] . ';color:#fff;border:0;}
button.quiet{background:#fff;color:' . $c['navy'] . ';border:2px solid ' . $c['navy'] . ';}
</style>
</head>
<body>
<div class="card">
<div class="club"><img src="' . esc_url(TeamOversight_Email::logo_url()) . '" alt="Renegades"><div><small>Melbourne University</small><strong>Renegades Volleyball Club</strong></div></div>
<p class="label">Club news emails</p>
<h1>' . esc_html($heading) . '</h1>
' . $body . '
</div>
</body>
</html>';
    }

    // ------------------------------------------------------------------
    // Retention and the log
    // ------------------------------------------------------------------

    /**
     * Daily (the membership cron): recipient rows of a mailout finished more
     * than KEEP_DAYS ago are deleted (the mailout keeps its totals); rows left
     * in 'sending' are settled as 'unknown'; a sending mailout whose
     * five-minute event is gone gets it back. Nothing else is deleted.
     * Returns the number of rows deleted.
     */
    public static function prune() {
        global $wpdb;
        if (!self::tables_exist()) {
            return 0;
        }
        self::heal_schedule(true);
        self::mark_stuck();
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT m.id FROM ' . self::table() . " m WHERE m.state IN ('done', 'cancelled') AND m.finished_at IS NOT NULL AND m.finished_at < %s AND EXISTS (SELECT 1 FROM " . self::recipients_table() . ' r WHERE r.mailout_id = m.id)',
            self::time_ago(self::KEEP_DAYS * DAY_IN_SECONDS)
        ));
        $deleted = 0;
        foreach ((array) $ids as $id) {
            $n = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::recipients_table() . ' WHERE mailout_id = %d', intval($id)));
            $deleted += $n === false ? 0 : intval($n);
        }
        return $deleted;
    }

    private static function actor_name($actor) {
        $user = get_userdata(intval($actor));
        return $user ? TeamOversight_Email::decode((string) $user->display_name) : 'user #' . intval($actor);
    }

    /** One 'mailout' log line: Mailout #ID "SUBJECT" WORDS (the subject only, never the message or an address). */
    private static function log($row, $words) {
        if (!$row) {
            return;
        }
        $subject = TeamOversight_Log::cut_bytes(TeamOversight_Email::decode((string) $row->subject), 200);
        TeamOversight_Log::add(self::LOG_TYPE, TeamOversight_Log::cut_bytes('Mailout #' . intval($row->id) . ' "' . $subject . '" ' . $words, 490));
    }

    /**
     * A recipient row's status in words: Sent DATE, Waiting, Problem: why,
     * Left out: unsubscribed / no email / no account / merged into an account
     * already on the list, Cancelled.
     */
    public static function status_words($r) {
        $r = (object) $r;
        switch ((string) $r->status) {
            case 'sent':
                $ts = !empty($r->sent_at) ? strtotime((string) $r->sent_at) : false;
                return 'Sent' . ($ts ? ' ' . date('j M Y, g:ia', $ts) : '');
            case 'pending':
            case 'sending':
                return 'Waiting';
            case 'failed':
                return intval($r->final) ? 'Problem: ' . ((string) $r->error !== '' ? (string) $r->error : 'not delivered') : 'Waiting (trying again)';
            case 'unknown':
                return 'Problem: stopped part-way, it may or may not have been delivered';
            case 'skipped':
                $reasons = array('unsubscribed' => 'unsubscribed', 'no_email' => 'no email', 'no_account' => 'no account', 'duplicate' => 'merged into an account already on the list');
                return 'Left out: ' . (isset($reasons[(string) $r->reason]) ? $reasons[(string) $r->reason] : 'left out');
            case 'cancelled':
                return 'Cancelled';
        }
        return (string) $r->status;
    }
}
