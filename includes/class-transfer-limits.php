<?php
/**
 * Club transfer limits (1.91.0, club decision 10 Nov 2026).
 *
 * Volleyball Victoria limits how many club transfers a club fields. Only a
 * player whose registration type (TeamOversight_Trials::registration_type(),
 * the admin's override included) is Club Transfer counts: ITC, Free Agent,
 * Returning and New never do. Limits per team come from the team's kind:
 * Premier League and Junior Premier League (age rule u19) teams take
 * 'premier', State League and Youth State League (u17 / u15) teams 'state'.
 * Club-wide limits are per gender (men's and boys' teams, women's and girls'
 * teams) and count PEOPLE, so a player selected in two teams uses one place.
 *
 * A club transfer counts for a team while their verdict there is Selected,
 * the application is still open, and the latest offer for that team has not
 * ended without them joining (TeamOversight_Offers::CLAIM_RELEASED_STATES:
 * declined, moved to Declined, withdrawn, not approved). Tentative and
 * Training Only never count. 2027 seasons on only (offers seasons).
 *
 * check() is the one rule, asked by the coach portal right before it writes
 * a Selected verdict (under lock()). An administrator can allow one player
 * on one team over the limit with a reason (exceptions). Nothing here ever
 * changes anyone's verdict: a team that ends up over a limit is flagged.
 */
if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Transfer_Limits {

    const OPTION = 'team_oversight_transfer_limits';
    const EXCEPTIONS_OPTION = 'team_oversight_transfer_exceptions';
    const ACTION = 'murvc_transfer_limits';
    const NONCE_FIELD = '_murvc_tl_nonce';
    const FLASH_PREFIX = 'murvc_transfer_limits_flash_';
    const FLASH_SECONDS = 120;
    const LOG_TYPE = 'transfer_limits';
    const REG_TYPE = 'Club Transfer';
    const OPEN_STATUSES = array('pending', 'accepted', 'awaiting_payment');
    const REASON_MAX = 200;
    const MAX_LIMIT = 99;

    private static $hooked = false;
    private static $counts_cache = array();

    public static function register_hooks() {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_post'));
    }

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    public static function defaults() {
        return array('premier' => 3, 'state' => 4, 'mens' => 12, 'womens' => 12);
    }

    /** The four limits: stored whole numbers 0 to MAX_LIMIT, else the defaults. */
    public static function limits() {
        $stored = get_option(self::OPTION, array());
        $out = self::defaults();
        foreach ($out as $key => $default) {
            if (is_array($stored) && isset($stored[$key]) && is_numeric($stored[$key])) {
                $value = intval($stored[$key]);
                if ($value >= 0 && $value <= self::MAX_LIMIT) {
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    public static function applies($season) {
        return class_exists('TeamOversight_Offers') && TeamOversight_Offers::applies((string) $season);
    }

    public static function valid_season($season) {
        return is_string($season) && preg_match('/^\d{4}$/D', $season) === 1;
    }

    /**
     * A team's kind, gender and limit for a season: array(team, name, kind
     * 'premier' | 'state' | '', gender 'mens' | 'womens' | '', limit int or
     * null). A team that is none of the four kinds has no limit.
     */
    public static function team_facts($team, $season) {
        $team = (string) $team;
        $config = TeamOversight_Offers::teams_config($season);
        $t = isset($config[$team]) && is_array($config[$team]) ? $config[$team] : null;
        $name = ($t && isset($t['name']) && (string) $t['name'] !== '') ? (string) $t['name'] : $team;
        $rule = ($t && isset($t['age_rule'])) ? (string) $t['age_rule'] : '';
        $kind = '';
        if ($rule === 'u19') {
            $kind = 'premier';
        } elseif ($rule === 'u17' || $rule === 'u15') {
            $kind = 'state';
        } elseif (preg_match('/^(JPL|PL)/i', $team)) {
            $kind = 'premier';
        } elseif (preg_match('/^(YSL|SL)/i', $team)) {
            $kind = 'state';
        }
        $gender = ($t && isset($t['gender']) && in_array($t['gender'], array('mens', 'womens'), true)) ? $t['gender'] : '';
        if ($gender === '' && preg_match('/^(?:JPL|YSL|PL|SL)\d*([MWBG])/i', $team, $m)) {
            $gender = in_array(strtoupper($m[1]), array('M', 'B'), true) ? 'mens' : 'womens';
        }
        $limits = self::limits();
        return array(
            'team' => $team,
            'name' => $name,
            'kind' => $kind,
            'gender' => $gender,
            'limit' => $kind !== '' ? $limits[$kind] : null,
        );
    }

    public static function gender_words($gender) {
        return $gender === 'mens' ? 'men\'s and boys\' teams' : 'women\'s and girls\' teams';
    }

    public static function is_club_transfer($application) {
        return $application && class_exists('TeamOversight_Trials') && TeamOversight_Trials::registration_type($application) === self::REG_TYPE;
    }

    /** One person across applications: the account, else the email. */
    private static function person_key($row) {
        $row = (object) $row;
        $user_id = isset($row->user_id) ? intval($row->user_id) : 0;
        return $user_id > 0 ? 'u' . $user_id : 'e' . strtolower(trim(isset($row->email) ? (string) $row->email : ''));
    }

    /**
     * Who counts in a season: array(teams => team => list of array(
     * application_id, name, trial_number, person) for Selected club
     * transfers, genders => gender => person => list of teams (Selected
     * only), tentative => team => the same list for Tentative club
     * transfers, which only the club-wide reservation reads). Two queries
     * plus block_context()'s two. Cached for the request unless $fresh.
     */
    public static function counts($season, $fresh = false) {
        global $wpdb;
        $season = (string) $season;
        if (!$fresh && isset(self::$counts_cache[$season])) {
            return self::$counts_cache[$season];
        }
        $out = array('teams' => array(), 'genders' => array('mens' => array(), 'womens' => array()), 'tentative' => array());
        if (!self::applies($season)) {
            return self::$counts_cache[$season] = $out;
        }
        $statuses = "'" . implode("','", self::OPEN_STATUSES) . "'";
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT s.application_id, s.team, s.status, a.user_id, a.email, a.name, a.trial_number,
                   a.form_data, a.is_transfer_player, a.registration_override
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.season = %s AND a.season = %s AND s.status IN ('selected', 'tentative')
              AND a.application_status IN ($statuses)
            ORDER BY a.trial_number, a.id
        ", $season, $season));
        $transfers = array();
        foreach ((array) $rows as $row) {
            if (self::is_club_transfer($row)) {
                $transfers[] = $row;
            }
        }
        $context = empty($transfers) ? array() : TeamOversight_Offers::block_context(array_map(function ($r) {
            return intval($r->application_id);
        }, $transfers));
        foreach ($transfers as $row) {
            $app_id = intval($row->application_id);
            $team = (string) $row->team;
            $person = self::person_key($row);
            $entry = array(
                'application_id' => $app_id,
                'name' => (string) $row->name,
                'trial_number' => intval($row->trial_number),
                'person' => $person,
            );
            if ($row->status === 'tentative') {
                $out['tentative'][$team][] = $entry;
                continue;
            }
            $latest = isset($context[$app_id]['offers'][$team]) ? $context[$app_id]['offers'][$team] : null;
            if ($latest && isset($latest->role, $latest->state) && $latest->role === 'playing_member'
                && in_array($latest->state, TeamOversight_Offers::CLAIM_RELEASED_STATES, true)) {
                continue;
            }
            $out['teams'][$team][] = $entry;
            $facts = self::team_facts($team, $season);
            if ($facts['kind'] !== '' && $facts['gender'] !== '') {
                $out['genders'][$facts['gender']][$person][] = $team;
            }
        }
        return self::$counts_cache[$season] = $out;
    }

    /**
     * Club-wide places held for HIGHER teams' Tentative club transfers (club
     * decision, 10 Nov 2026): a higher team is assumed to fill its own limit
     * from its Tentative picks before a lower team uses a club-wide place.
     * Higher = the same gender and a division level above 0 and smaller than
     * $team's. EVERY team takes part, JPL and YSL included (club decision,
     * 10 Nov 2026: unlike the offers' higher-team rule, no age-rule team is
     * left out); teams on the same level never hold places for each other,
     * and a team with no level is never judged. Each higher team holds at
     * most (its limit - its Selected club transfers); a person already
     * Selected anywhere in the gender, $exclude_person, and anyone already
     * held for another team are not counted again.
     * Returns array(count, by => list of array(name, count)).
     */
    public static function reserved_for($team, $season, array $counts, $exclude_person = '') {
        $none = array('count' => 0, 'by' => array());
        $config = TeamOversight_Offers::teams_config($season);
        $mine = isset($config[$team]) && is_array($config[$team]) ? $config[$team] : null;
        $my_level = $mine && isset($mine['level']) ? intval($mine['level']) : 0;
        $facts = self::team_facts($team, $season);
        if ($my_level <= 0 || $facts['gender'] === '' || $facts['kind'] === '') {
            return $none;
        }
        $selected_people = $counts['genders'][$facts['gender']];
        $higher = array();
        foreach ($config as $code => $t) {
            $code = (string) $code;
            $level = (is_array($t) && isset($t['level'])) ? intval($t['level']) : 0;
            if ($code === $team || $level <= 0 || $level >= $my_level) {
                continue;
            }
            $f = self::team_facts($code, $season);
            if ($f['kind'] === '' || $f['gender'] !== $facts['gender']) {
                continue;
            }
            $higher[] = array('code' => $code, 'level' => $level, 'facts' => $f);
        }
        usort($higher, function ($a, $b) {
            return $a['level'] !== $b['level'] ? $a['level'] - $b['level'] : strcmp($a['code'], $b['code']);
        });
        $held = array();
        $out = $none;
        foreach ($higher as $h) {
            $selected_here = isset($counts['teams'][$h['code']]) ? count($counts['teams'][$h['code']]) : 0;
            $room = max(0, intval($h['facts']['limit']) - $selected_here);
            $n = 0;
            foreach (isset($counts['tentative'][$h['code']]) ? $counts['tentative'][$h['code']] : array() as $r) {
                if ($n >= $room) {
                    break;
                }
                if ($r['person'] === $exclude_person || isset($selected_people[$r['person']]) || isset($held[$r['person']])) {
                    continue;
                }
                $held[$r['person']] = true;
                $n++;
            }
            if ($n > 0) {
                $out['count'] += $n;
                $out['by'][] = array('name' => $h['facts']['name'], 'count' => $n);
            }
        }
        return $out;
    }

    /**
     * How many PEOPLE in a gender are Tentative club transfers on any team and
     * not Selected anywhere in that gender (the dashboard's and Configuration's
     * "+N Tentative"). $counts: a counts() result.
     */
    public static function tentative_people(array $counts, $gender, $season) {
        $selected = isset($counts['genders'][$gender]) ? $counts['genders'][$gender] : array();
        $people = array();
        foreach ($counts['tentative'] as $team => $list) {
            $f = self::team_facts($team, $season);
            if ($f['kind'] === '' || $f['gender'] !== $gender) {
                continue;
            }
            foreach ($list as $r) {
                if (!isset($selected[$r['person']])) {
                    $people[$r['person']] = true;
                }
            }
        }
        return count($people);
    }

    private static function by_words(array $by) {
        $parts = array();
        foreach ($by as $b) {
            $parts[] = $b['name'] . ' (' . intval($b['count']) . ')';
        }
        return implode(', ', $parts);
    }

    /**
     * The one rule: '' when $application may be moved to Selected for $team
     * in $season, else the refusal (plain text, safe for coaches). Reads
     * fresh; call it under lock() right before the write.
     */
    public static function check($application, $team, $season) {
        $season = (string) $season;
        if (!self::applies($season) || !$application || !self::is_club_transfer($application)) {
            return '';
        }
        $facts = self::team_facts($team, $season);
        if ($facts['kind'] === '') {
            return '';
        }
        $app_id = intval($application->id);
        if (self::exception($app_id, $team, $season) !== null) {
            return '';
        }
        $counts = self::counts($season, true);
        $on_team = 0;
        foreach (isset($counts['teams'][$team]) ? $counts['teams'][$team] : array() as $r) {
            if ($r['application_id'] !== $app_id) {
                $on_team++;
            }
        }
        if ($on_team >= $facts['limit']) {
            return sprintf('%s already has %d of %d club transfers. Ask a club admin if this player needs an exception.', $facts['name'], $on_team, $facts['limit']);
        }
        if ($facts['gender'] !== '') {
            $people = $counts['genders'][$facts['gender']];
            $me = self::person_key($application);
            if (!isset($people[$me])) {
                $limits = self::limits();
                $cap = $limits[$facts['gender']];
                if (count($people) >= $cap) {
                    return sprintf('The club already has %d of %d club transfers across its %s. Ask a club admin if this player needs an exception.', count($people), $cap, self::gender_words($facts['gender']));
                }
                $held = self::reserved_for($team, $season, $counts, $me);
                if (count($people) + $held['count'] >= $cap) {
                    return sprintf('The club has %d of %d club transfers selected across its %s, and higher teams have Tentative club transfers that would use the rest: %s. Their coaches decide first. Ask a club admin if this player needs an exception.', count($people), $cap, self::gender_words($facts['gender']), self::by_words($held['by']));
                }
            }
        }
        return '';
    }

    /** A named lock around check() and the verdict write, so two quick moves can't both pass. Returns the lock name, or null. */
    public static function lock() {
        global $wpdb;
        $name = 'murvc_transfers_' . md5($wpdb->prefix);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        return intval($got) === 1 ? $name : null;
    }

    public static function unlock($name) {
        global $wpdb;
        if (is_string($name) && $name !== '') {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    // ------------------------------------------------------------------
    // Exceptions
    // ------------------------------------------------------------------

    /** season => key "application|team" => array(application_id, team, reason, by, by_name, at). */
    private static function all_exceptions() {
        $all = get_option(self::EXCEPTIONS_OPTION, array());
        return is_array($all) ? $all : array();
    }

    public static function exceptions($season) {
        $all = self::all_exceptions();
        return isset($all[(string) $season]) && is_array($all[(string) $season]) ? $all[(string) $season] : array();
    }

    public static function exception($application_id, $team, $season) {
        $list = self::exceptions($season);
        $key = intval($application_id) . '|' . (string) $team;
        return isset($list[$key]) && is_array($list[$key]) ? $list[$key] : null;
    }

    /** Allow one player on one team over the limit. Administrators only. Returns array(ok, message). */
    public static function add_exception($application_id, $team, $season, $reason, $actor) {
        global $wpdb;
        if (!user_can($actor, 'manage_options')) {
            return array('ok' => false, 'message' => 'Only an administrator can allow an exception.');
        }
        $season = (string) $season;
        $team = (string) $team;
        $reason = trim(sanitize_text_field((string) $reason));
        if ($reason === '') {
            return array('ok' => false, 'message' => 'Give a reason for the exception.');
        }
        if (strlen($reason) > self::REASON_MAX) {
            return array('ok' => false, 'message' => 'Keep the reason under ' . self::REASON_MAX . ' characters.');
        }
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d AND season = %s", intval($application_id), $season));
        if (!$app) {
            return array('ok' => false, 'message' => 'That application isn\'t in the ' . $season . ' season.');
        }
        if (!self::is_club_transfer($app)) {
            return array('ok' => false, 'message' => $app->name . ' isn\'t a club transfer, so no exception is needed.');
        }
        $facts = self::team_facts($team, $season);
        $config = TeamOversight_Offers::teams_config($season);
        if (!isset($config[$team]) || $facts['kind'] === '') {
            return array('ok' => false, 'message' => 'Choose a Premier, JPL, State League or YSL team.');
        }
        $all = self::all_exceptions();
        $key = intval($app->id) . '|' . $team;
        $user = get_userdata($actor);
        $all[$season][$key] = array(
            'application_id' => intval($app->id),
            'team' => $team,
            'reason' => $reason,
            'by' => intval($actor),
            'by_name' => $user ? (string) $user->display_name : '',
            'at' => current_time('mysql'),
        );
        update_option(self::EXCEPTIONS_OPTION, $all, false);
        $who = $app->name . ($app->trial_number ? ' (#' . intval($app->trial_number) . ')' : '');
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::LOG_TYPE, 'Exception allowed: ' . $who . ' may go over the club transfer limit on ' . $facts['name'] . ' (' . $season . '). Reason: ' . $reason, array('user_id' => intval($app->user_id)));
        }
        return array('ok' => true, 'message' => $who . ' can now be selected for ' . $facts['name'] . ' over the club transfer limit.');
    }

    public static function remove_exception($application_id, $team, $season, $actor) {
        global $wpdb;
        if (!user_can($actor, 'manage_options')) {
            return array('ok' => false, 'message' => 'Only an administrator can remove an exception.');
        }
        $season = (string) $season;
        $all = self::all_exceptions();
        $key = intval($application_id) . '|' . (string) $team;
        if (!isset($all[$season][$key])) {
            return array('ok' => false, 'message' => 'That exception was already removed.');
        }
        unset($all[$season][$key]);
        if (empty($all[$season])) {
            unset($all[$season]);
        }
        update_option(self::EXCEPTIONS_OPTION, $all, false);
        $app = $wpdb->get_row($wpdb->prepare("SELECT name, trial_number, user_id FROM {$wpdb->prefix}trial_applications WHERE id = %d", intval($application_id)));
        $who = $app ? $app->name . ($app->trial_number ? ' (#' . intval($app->trial_number) . ')' : '') : 'Application #' . intval($application_id);
        $facts = self::team_facts($team, $season);
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::LOG_TYPE, 'Exception removed: ' . $who . ' on ' . $facts['name'] . ' (' . $season . '). A verdict already Selected stays as it is.', array('user_id' => $app ? intval($app->user_id) : 0));
        }
        return array('ok' => true, 'message' => 'Exception removed for ' . $who . ' on ' . $facts['name'] . '. If they are already Selected, that stays; the team is flagged if it is over the limit.');
    }

    /** Save the four limits. Administrators only. Returns array(ok, message, errors). */
    public static function save_limits(array $fields, $actor) {
        if (!user_can($actor, 'manage_options')) {
            return array('ok' => false, 'message' => 'Only an administrator can change the limits.', 'errors' => array());
        }
        $labels = array('premier' => 'Premier and JPL', 'state' => 'State League and YSL', 'mens' => 'Men\'s and boys\' teams', 'womens' => 'Women\'s and girls\' teams');
        $old = self::limits();
        $new = array();
        $errors = array();
        foreach ($labels as $key => $label) {
            $value = isset($fields[$key]) ? trim((string) $fields[$key]) : '';
            if ($value === '' || !ctype_digit($value) || intval($value) > self::MAX_LIMIT) {
                $errors[] = $label . ': type a whole number from 0 to ' . self::MAX_LIMIT . '.';
                continue;
            }
            $new[$key] = intval($value);
        }
        if (!empty($errors)) {
            return array('ok' => false, 'message' => 'Nothing was saved.', 'errors' => $errors);
        }
        if ($new === $old) {
            return array('ok' => true, 'message' => 'No change.', 'errors' => array());
        }
        update_option(self::OPTION, $new, false);
        $changes = array();
        foreach ($labels as $key => $label) {
            if ($old[$key] !== $new[$key]) {
                $changes[] = $label . ' ' . $old[$key] . ' to ' . $new[$key];
            }
        }
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::LOG_TYPE, 'Club transfer limits changed: ' . implode(', ', $changes) . '.');
        }
        return array('ok' => true, 'message' => 'Limits saved: ' . implode(', ', $changes) . '. Nobody already selected is changed; a team over a new limit is flagged.', 'errors' => array());
    }

    // ------------------------------------------------------------------
    // Transfer paperwork status (1.92.0, club decision 10 Nov 2026)
    // ------------------------------------------------------------------

    /** The statuses an administrator can set; NULL in the column reads as not_submitted. */
    const TRANSFER_STATUSES = array('not_submitted' => 'Not Submitted', 'pending' => 'Pending', 'completed' => 'Completed');
    /**
     * Registration types whose paperwork is tracked: a club transfer, a free
     * agent (1.93.1, club decision 11 Nov 2026) and an ITC (overseas clearance).
     */
    const PAPERWORK_TYPES = array('Club Transfer', 'Free Agent', 'ITC');

    /** Does this application's player need transfer paperwork (Club Transfer, Free Agent or ITC, override included)? */
    public static function needs_paperwork($application) {
        return $application && class_exists('TeamOversight_Trials') && in_array(TeamOversight_Trials::registration_type($application), self::PAPERWORK_TYPES, true);
    }

    /**
     * The player's own checklist step for their paperwork (1.93.1): the title
     * and one plain sentence for the status, or null when the application has
     * none to track. Read-only: only an administrator changes the status.
     */
    public static function player_step($application) {
        if (!self::needs_paperwork($application)) {
            return null;
        }
        $type = TeamOversight_Trials::registration_type($application);
        $names = array('Club Transfer' => 'club transfer', 'Free Agent' => 'free agent registration', 'ITC' => 'international transfer (ITC)');
        $name = isset($names[$type]) ? $names[$type] : 'transfer';
        $status = self::transfer_status($application);
        $details = array(
            'not_submitted' => 'Not submitted yet. The club updates this as your ' . $name . ' moves along.',
            'pending' => 'Pending: submitted, and not completed yet. The club updates this as your ' . $name . ' moves along.',
            'completed' => 'Completed.',
        );
        return array(
            'title' => 'Your ' . $name,
            'status' => $status,
            'status_label' => self::TRANSFER_STATUSES[$status],
            'detail' => $details[$status],
        );
    }

    /** The stored status key of an application row (not_submitted when unset or unknown). */
    public static function transfer_status($application) {
        $value = ($application && isset($application->transfer_status)) ? (string) $application->transfer_status : '';
        return isset(self::TRANSFER_STATUSES[$value]) ? $value : 'not_submitted';
    }

    /** Set an application's transfer status. Administrators only; logged. Returns array(ok, message). */
    public static function set_transfer_status($application_id, $status, $actor) {
        global $wpdb;
        if (!user_can($actor, 'manage_options')) {
            return array('ok' => false, 'message' => 'Only an administrator can change a transfer status.');
        }
        $status = (string) $status;
        if (!isset(self::TRANSFER_STATUSES[$status])) {
            return array('ok' => false, 'message' => 'Choose Not Submitted, Pending or Completed.');
        }
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d", intval($application_id)));
        if (!$app) {
            return array('ok' => false, 'message' => 'Application not found.');
        }
        if (!self::needs_paperwork($app)) {
            return array('ok' => false, 'message' => $app->name . ' isn\'t a club transfer, free agent or ITC, so there is no transfer to track.');
        }
        $old = self::transfer_status($app);
        if ($old === $status) {
            return array('ok' => true, 'message' => 'No change.');
        }
        $written = $wpdb->update($wpdb->prefix . 'trial_applications', array(
            'transfer_status' => $status === 'not_submitted' ? null : $status,
            'transfer_status_by' => intval($actor),
            'transfer_status_at' => current_time('mysql'),
        ), array('id' => intval($app->id)), array('%s', '%d', '%s'), array('%d'));
        if ($written === false) {
            return array('ok' => false, 'message' => 'The transfer status couldn\'t be saved. Reload the page and try again.');
        }
        // The player's checklist reads the status too (1.93.1): drop any page copy of the application.
        if (class_exists('TeamOversight_Readiness') && method_exists('TeamOversight_Readiness', 'flush_cache')) {
            TeamOversight_Readiness::flush_cache();
        }
        $who = $app->name . ($app->trial_number ? ' (#' . intval($app->trial_number) . ')' : '');
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add(self::LOG_TYPE, 'Transfer status for ' . $who . ' (' . $app->season . '): ' . self::TRANSFER_STATUSES[$old] . ' to ' . self::TRANSFER_STATUSES[$status] . '.', array('user_id' => intval($app->user_id)));
        }
        return array('ok' => true, 'message' => 'Transfer status for ' . $who . ' set to ' . self::TRANSFER_STATUSES[$status] . '.');
    }

    // ------------------------------------------------------------------
    // Flags
    // ------------------------------------------------------------------

    /** The coach board's line for a team ('' when the team has no limit or the season has none). Plain HTML, escaped. */
    public static function board_html($team, $season, $fresh = false) {
        if (!self::applies($season)) {
            return '';
        }
        $facts = self::team_facts($team, $season);
        if ($facts['kind'] === '') {
            return '';
        }
        $counts = self::counts($season, $fresh);
        $on_team = isset($counts['teams'][$team]) ? count($counts['teams'][$team]) : 0;
        $parts = array('<strong>Club transfers: ' . intval($on_team) . ' of ' . intval($facts['limit']) . '</strong> on this team');
        if ($facts['gender'] !== '') {
            $limits = self::limits();
            $club = count($counts['genders'][$facts['gender']]);
            $parts[] = intval($club) . ' of ' . intval($limits[$facts['gender']]) . ' across the club\'s ' . esc_html(self::gender_words($facts['gender']));
            $held = self::reserved_for($team, $season, $counts);
            if ($held['count'] > 0) {
                $parts[] = intval($held['count']) . ' more held for higher teams\' Tentative club transfers (' . esc_html(self::by_words($held['by'])) . ')';
            }
            if ($club > $limits[$facts['gender']]) {
                $parts[] = '<span class="coach-tl-over">club-wide limit passed</span>';
            }
        }
        if ($on_team > $facts['limit']) {
            $parts[] = '<span class="coach-tl-over">over the team limit</span>';
        }
        $html = '<p class="coach-transfer-limits">' . implode(' · ', $parts) . '. ITC and free agents don\'t count.</p>';
        $names = array();
        foreach (self::exceptions($season) as $ex) {
            if (isset($ex['team']) && $ex['team'] === $team) {
                $names[] = self::application_label(intval($ex['application_id']));
            }
        }
        if (!empty($names)) {
            $html .= '<p class="coach-transfer-limits coach-tl-exceptions">Allowed over the limit by the club: ' . esc_html(implode(', ', $names)) . '.</p>';
        }
        return $html;
    }

    private static function application_label($application_id) {
        global $wpdb;
        $app = $wpdb->get_row($wpdb->prepare("SELECT name, trial_number FROM {$wpdb->prefix}trial_applications WHERE id = %d", $application_id));
        if (!$app) {
            return 'Application #' . $application_id;
        }
        return (string) $app->name . ($app->trial_number ? ' (#' . intval($app->trial_number) . ')' : '');
    }

    /**
     * The committee's flag for a request on the Offers page: array(text,
     * over (bool)) or null when the player isn't a club transfer, the role
     * isn't a playing place, or the team has no limit. Plain text.
     */
    public static function offer_flag($application, $team, $season, $role) {
        if (!self::applies($season) || $role !== 'playing_member' || !self::is_club_transfer($application)) {
            return null;
        }
        $facts = self::team_facts($team, $season);
        if ($facts['kind'] === '') {
            return null;
        }
        $counts = self::counts($season);
        $limits = self::limits();
        $on_team = isset($counts['teams'][$team]) ? count($counts['teams'][$team]) : 0;
        $text = 'Club transfer. ' . $facts['name'] . ': ' . $on_team . ' of ' . $facts['limit'] . '.';
        $over = $on_team > $facts['limit'];
        if ($facts['gender'] !== '') {
            $club = count($counts['genders'][$facts['gender']]);
            $text .= ' ' . ucfirst(self::gender_words($facts['gender'])) . ': ' . $club . ' of ' . $limits[$facts['gender']] . '.';
            $over = $over || $club > $limits[$facts['gender']];
        }
        $ex = self::exception(intval($application->id), $team, $season);
        if ($ex !== null) {
            $text .= ' Allowed over the limit by ' . ($ex['by_name'] !== '' ? $ex['by_name'] : 'an administrator') . ': ' . $ex['reason'];
        } elseif ($over) {
            $text .= ' Over the limit.';
        }
        return array('text' => $text, 'over' => $over, 'exception' => $ex !== null);
    }

    // ------------------------------------------------------------------
    // Configuration box (VVL Oversight → Configuration, the selected season)
    // ------------------------------------------------------------------

    public static function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        $season = (isset($_POST['season']) && is_string($_POST['season']) && self::valid_season($_POST['season'])) ? (string) $_POST['season'] : '';
        check_admin_referer(self::ACTION . '_' . $season, self::NONCE_FIELD);
        $do = isset($_POST['do']) && is_string($_POST['do']) ? sanitize_key($_POST['do']) : '';
        $actor = get_current_user_id();
        $post = wp_unslash($_POST);
        if ($do === 'limits') {
            $result = self::save_limits(array(
                'premier' => isset($post['premier']) ? $post['premier'] : '',
                'state' => isset($post['state']) ? $post['state'] : '',
                'mens' => isset($post['mens']) ? $post['mens'] : '',
                'womens' => isset($post['womens']) ? $post['womens'] : '',
            ), $actor);
        } elseif ($do === 'add') {
            $result = self::add_exception(isset($post['application_id']) ? intval($post['application_id']) : 0, isset($post['team']) ? sanitize_text_field((string) $post['team']) : '', $season, isset($post['reason']) ? (string) $post['reason'] : '', $actor);
        } elseif ($do === 'remove') {
            $result = self::remove_exception(isset($post['application_id']) ? intval($post['application_id']) : 0, isset($post['team']) ? sanitize_text_field((string) $post['team']) : '', $season, $actor);
        } else {
            $result = array('ok' => false, 'message' => 'Nothing was saved.');
        }
        set_transient(self::FLASH_PREFIX . $actor, array(
            'ok' => !empty($result['ok']),
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'errors' => isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array(),
        ), self::FLASH_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=team-oversight-fees' . ($season !== '' ? '&season=' . $season : '')) . '#transfer-limits');
        exit;
    }

    private static function take_flash() {
        $key = self::FLASH_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        if ($flash !== false) {
            delete_transient($key);
        }
        return is_array($flash) ? $flash : null;
    }

    /** ECHOES the Club transfer limits box. Administrators only; 2027 seasons on. */
    public static function render_admin_box($season) {
        global $wpdb;
        $season = (string) $season;
        if (!current_user_can('manage_options') || !self::valid_season($season) || !self::applies($season)) {
            return;
        }
        $flash = self::take_flash();
        $limits = self::limits();
        $counts = self::counts($season, true);
        $config = TeamOversight_Offers::teams_config($season);
        $teams = array();
        foreach ($config as $code => $t) {
            $facts = self::team_facts($code, $season);
            if ($facts['kind'] !== '') {
                $teams[$code] = $facts;
            }
        }
        $exceptions = self::exceptions($season);
        $statuses = "'" . implode("','", self::OPEN_STATUSES) . "'";
        $apps = $wpdb->get_results($wpdb->prepare("
            SELECT id, name, trial_number, form_data, is_transfer_player, registration_override
            FROM {$wpdb->prefix}trial_applications
            WHERE season = %s AND application_status IN ($statuses)
            ORDER BY name
        ", $season));
        $transfer_apps = array();
        foreach ((array) $apps as $app) {
            if (self::is_club_transfer($app)) {
                $transfer_apps[] = $app;
            }
        }
        $nonce = function () use ($season) {
            wp_nonce_field(self::ACTION . '_' . $season, self::NONCE_FIELD);
        };
        $kind_words = array('premier' => 'Premier / JPL', 'state' => 'State League / YSL');
        ?>
        <div id="transfer-limits" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
            <h2 style="margin-top: 0;">Club transfer limits (<?php echo esc_html($season); ?>)</h2>
            <p class="description">Coaches can't move a club transfer to Selected once their team or the club has reached its limit. Only players whose registration type is Club Transfer count: ITC, free agents, returning and new players don't. A player counts from Selected until their offer is declined, withdrawn or not approved. For the club-wide limit, a lower team also has to leave room for higher teams' Tentative club transfers, up to each higher team's own limit. Nobody already selected is ever changed: a team over a limit is flagged here, on the coach board and on Offers.</p>
            <?php if ($flash !== null): ?>
                <div class="notice notice-<?php echo !empty($flash['ok']) ? 'success' : 'error'; ?> inline">
                    <p><?php echo esc_html($flash['message']); ?></p>
                    <?php foreach ((array) $flash['errors'] as $error): ?>
                        <p><?php echo esc_html((string) $error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 10px 0 18px;">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
                <input type="hidden" name="do" value="limits">
                <?php $nonce(); ?>
                <label style="margin-right: 14px;">Premier and JPL, per team <input type="number" name="premier" min="0" max="<?php echo intval(self::MAX_LIMIT); ?>" value="<?php echo intval($limits['premier']); ?>" style="width: 60px;"></label>
                <label style="margin-right: 14px;">State League and YSL, per team <input type="number" name="state" min="0" max="<?php echo intval(self::MAX_LIMIT); ?>" value="<?php echo intval($limits['state']); ?>" style="width: 60px;"></label>
                <label style="margin-right: 14px;">Men's and boys' teams, club-wide <input type="number" name="mens" min="0" max="<?php echo intval(self::MAX_LIMIT); ?>" value="<?php echo intval($limits['mens']); ?>" style="width: 60px;"></label>
                <label style="margin-right: 14px;">Women's and girls' teams, club-wide <input type="number" name="womens" min="0" max="<?php echo intval(self::MAX_LIMIT); ?>" value="<?php echo intval($limits['womens']); ?>" style="width: 60px;"></label>
                <button type="submit" class="button">Save limits</button>
            </form>

            <h3>Club transfers selected now</h3>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th>Team</th><th>Club transfers</th><th>Who</th></tr></thead>
                <tbody>
                    <?php foreach (array('mens', 'womens') as $gender): ?>
                        <?php $club = count($counts['genders'][$gender]); ?>
                        <tr data-sort-pin>
                            <td><strong>All <?php echo esc_html(self::gender_words($gender)); ?></strong></td>
                            <?php $club_tentative = self::tentative_people($counts, $gender, $season); ?>
                            <td><strong><?php echo intval($club); ?> of <?php echo intval($limits[$gender]); ?></strong><?php echo $club_tentative ? ' <span class="description">(+' . intval($club_tentative) . ' Tentative)</span>' : ''; ?><?php echo $club > $limits[$gender] ? ' <span style="color: #b32d2e; font-weight: 600;">Over the limit</span>' : ''; ?></td>
                            <td>Each player counts once, even if selected in two teams.</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($teams as $code => $facts): ?>
                        <?php $list = isset($counts['teams'][$code]) ? $counts['teams'][$code] : array(); ?>
                        <tr>
                            <td><?php echo esc_html($facts['name']); ?> <span class="description">(<?php echo esc_html($kind_words[$facts['kind']]); ?>)</span></td>
                            <?php $tentative = isset($counts['tentative'][$code]) ? count($counts['tentative'][$code]) : 0; ?>
                            <td><?php echo intval(count($list)); ?> of <?php echo intval($facts['limit']); ?><?php echo $tentative ? ' <span class="description">(+' . intval($tentative) . ' Tentative)</span>' : ''; ?><?php echo count($list) > $facts['limit'] ? ' <span style="color: #b32d2e; font-weight: 600;">Over the limit</span>' : ''; ?></td>
                            <td><?php
                                $names = array();
                                foreach ($list as $r) {
                                    $names[] = $r['name'] . ($r['trial_number'] ? ' (#' . $r['trial_number'] . ')' : '') . (self::exception($r['application_id'], $code, $season) !== null ? ' (exception)' : '');
                                }
                                echo esc_html(empty($names) ? '—' : implode(', ', $names));
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Exceptions</h3>
            <p class="description">An exception lets a coach select one club transfer for one team even when a limit is reached. It is recorded in the log with your name and reason.</p>
            <?php if (empty($exceptions)): ?>
                <p>No exceptions for <?php echo esc_html($season); ?>.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead><tr><th>Player</th><th>Team</th><th>Reason</th><th>Allowed by</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($exceptions as $ex): ?>
                            <?php $facts = self::team_facts($ex['team'], $season); ?>
                            <tr>
                                <td><?php echo esc_html(self::application_label(intval($ex['application_id']))); ?></td>
                                <td><?php echo esc_html($facts['name']); ?></td>
                                <td><?php echo esc_html((string) $ex['reason']); ?></td>
                                <td><?php echo esc_html(($ex['by_name'] !== '' ? $ex['by_name'] : 'Administrator') . ', ' . mysql2date('j M Y', (string) $ex['at'])); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Remove this exception? If the player is already Selected, that stays.');">
                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                                        <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
                                        <input type="hidden" name="do" value="remove">
                                        <input type="hidden" name="application_id" value="<?php echo intval($ex['application_id']); ?>">
                                        <input type="hidden" name="team" value="<?php echo esc_attr((string) $ex['team']); ?>">
                                        <?php $nonce(); ?>
                                        <button type="submit" class="button-link">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($transfer_apps) && !empty($teams)): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
                    <input type="hidden" name="do" value="add">
                    <?php $nonce(); ?>
                    <label>Player
                        <select name="application_id" required>
                            <option value="">Choose a club transfer</option>
                            <?php foreach ($transfer_apps as $app): ?>
                                <option value="<?php echo intval($app->id); ?>"><?php echo esc_html($app->name . ($app->trial_number ? ' (#' . intval($app->trial_number) . ')' : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="margin-left: 10px;">Team
                        <select name="team" required>
                            <option value="">Choose a team</option>
                            <?php foreach ($teams as $code => $facts): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($facts['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="margin-left: 10px;">Reason
                        <input type="text" name="reason" maxlength="<?php echo intval(self::REASON_MAX); ?>" required style="width: 280px;" placeholder="e.g. Volleyball Victoria approved an extra transfer">
                    </label>
                    <button type="submit" class="button">Allow over the limit</button>
                </form>
            <?php elseif (empty($transfer_apps)): ?>
                <p class="description">No open <?php echo esc_html($season); ?> applications are club transfers yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
