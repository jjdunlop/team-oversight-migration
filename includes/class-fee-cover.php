<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 1.88.0 (club decision, 5 Nov 2026): the playing fee covered by the club.
 *
 * A person is FEE-COVERED for a season S (a fee-timeline season, 2027 on)
 * when one of their trial applications for S (by account id) carries the
 * committee's mark (trial_applications.fee_cover_reason, e.g. Premier League
 * MVP 2026), set and cleared by an administrator on Trial Applications. The
 * mark is the only way in.
 *
 * 1.88.1 (club decision, 5 Nov 2026): life members are NOT covered. They pay
 * their playing fee like everyone else, so a life-member grant changes
 * nothing here and no membership hook calls this class. Every cover's
 * source is 'mark' (SOURCE_MARK); an offer quoted under 1.88.0 with source
 * 'life' reads as a mark the person no longer has, so the daily step
 * re-quotes it at the playing fee.
 *
 * What it changes (TeamOversight_Fees::fee_breakdown() is the only place a
 * rate changes): for that person and season every playing_member and
 * training_only place, on every team, is charged at the SUPPORTER column of
 * that place's fee class. Coach and assistant coach places already are;
 * a team manager place is still charged as a playing member. Fees never add
 * up, so a covered person pays one supporter fee for the season. An offer
 * for a covered player quotes the supporter cell (the fee if entered, else
 * the upper limit) and the deposit is all of it (DEPOSIT_PERCENT). Trial
 * fees and Club Programs never use the fee engine and are not affected.
 *
 * The cover is per person and season, not dated: setting or clearing it
 * re-prices the whole season and re-quotes the person's open offers at once
 * (TeamOversight_Offers::requote(); no email: an administrator presses
 * Email the new offer). Seasons before 2027 are never covered and no query
 * is made for them.
 *
 * Readers are pure and cheap: season_index() loads a season once per request
 * with one query (the marks); for_person() / for_email() are array lookups. Every
 * writer calls flush_cache().
 */
class TeamOversight_Fee_Cover {

    /** The deposit of a covered offer: all of the quote (the supporter fee is small). */
    const DEPOSIT_PERCENT = 100.0;

    /** The places the cover charges at the supporter column. */
    const COVERED_ROLES = array('playing_member', 'training_only');

    /** The longest reason an administrator may type (characters). */
    const REASON_MAX = 100;

    /** The activity log type (registered in class-log.php). */
    const LOG_TYPE = 'fee_cover';

    /** The one source of a cover (offers store it in quote_cover_source). */
    const SOURCE_MARK = 'mark';

    /** season => array(marks => user id => mark). */
    private static $index = array();

    private static $hooks_added = false;

    /** The two account-merge hooks (once per request, statically). */
    public static function register_hooks() {
        if (self::$hooks_added) {
            return;
        }
        self::$hooks_added = true;
        add_action('murvc_account_merged', array(__CLASS__, 'after_merge'), 20, 3);
        add_action('murvc_account_merge_undone', array(__CLASS__, 'after_merge_undone'), 20, 3);
    }

    // ------------------------------------------------------------------
    // Readers (pure: they never write)
    // ------------------------------------------------------------------

    /** Can a person be fee-covered in this season? (A fee-timeline season, 2027 on.) */
    public static function applies($season) {
        return class_exists('TeamOversight_Fees') && TeamOversight_Fees::timeline_applies($season);
    }

    /** Forget what this request read (every writer calls it, and so do the tests). */
    public static function flush_cache() {
        self::$index = array();
    }

    /**
     * Who is covered in a season: array(marks => user id => array(reason,
     * application_id, by, at)). One query the first time a season is asked
     * for in a request, none after; nothing at all for a season before 2027.
     * Marks only count on applications with an account (strict identity);
     * when one account has two marked applications in a season the older one
     * is read.
     */
    public static function season_index($season) {
        global $wpdb;
        $season = is_scalar($season) ? (string) $season : '';
        $empty = array('marks' => array());
        if (!self::applies($season)) {
            return $empty;
        }
        if (isset(self::$index[$season])) {
            return self::$index[$season];
        }
        $index = $empty;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, fee_cover_reason, fee_cover_by, fee_cover_at FROM {$wpdb->prefix}trial_applications WHERE season = %s AND fee_cover_reason IS NOT NULL AND user_id > 0 ORDER BY id",
            $season
        ));
        foreach ((array) $rows as $row) {
            $uid = intval($row->user_id);
            $reason = trim((string) $row->fee_cover_reason);
            if ($uid <= 0 || $reason === '' || isset($index['marks'][$uid])) {
                continue;
            }
            $index['marks'][$uid] = array('reason' => $reason, 'application_id' => intval($row->id), 'by' => intval($row->fee_cover_by), 'at' => (string) $row->fee_cover_at);
        }
        self::$index[$season] = $index;
        return $index;
    }

    /**
     * A person's cover for a season, or null: array(reason, source (always
     * mark), application_id (the marked application), by, at, mark (the mark
     * itself)). By account id only.
     */
    public static function for_person($user_id, $season) {
        $uid = intval($user_id);
        if ($uid <= 0) {
            return null;
        }
        $index = self::season_index($season);
        $mark = isset($index['marks'][$uid]) ? $index['marks'][$uid] : null;
        if ($mark) {
            return array('reason' => $mark['reason'], 'source' => self::SOURCE_MARK, 'application_id' => $mark['application_id'], 'by' => $mark['by'], 'at' => $mark['at'], 'mark' => $mark);
        }
        return null;
    }

    /**
     * for_person() by email (the fee engine's identity): null at once, with
     * the email never resolved, when nobody in the season is covered.
     */
    public static function for_email($email, $season) {
        $index = self::season_index($season);
        if (empty($index['marks'])) {
            return null;
        }
        $uid = TeamOversight_Fees::resolve_user_id((string) $email);
        return $uid > 0 ? self::for_person($uid, $season) : null;
    }

    /**
     * A cover reduced to what pricing and offers keep: array(reason, source),
     * or null. The source is always mark (1.88.1: a 1.88.0 'life' value
     * reads as mark too, so it can never match a live cover by its source).
     */
    public static function normalise($cover) {
        if (!is_array($cover) || !isset($cover['reason']) || trim((string) $cover['reason']) === '') {
            return null;
        }
        return array('reason' => trim((string) $cover['reason']), 'source' => self::SOURCE_MARK);
    }

    /** Two covers are the same: both none, or the same reason and source. */
    public static function same($a, $b) {
        $a = self::normalise($a);
        $b = self::normalise($b);
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a['reason'] === $b['reason'] && $a['source'] === $b['source'];
    }

    /** The cover an offer's quote was made with (quote_cover), or null for an ordinary quote. */
    public static function stored_cover($offer) {
        if (!is_object($offer) || !isset($offer->quote_cover) || $offer->quote_cover === null || trim((string) $offer->quote_cover) === '') {
            return null;
        }
        return array('reason' => trim((string) $offer->quote_cover), 'source' => self::SOURCE_MARK);
    }

    /** The mark an application carries: array(reason, by, at), or null. */
    public static function mark_of_application($application) {
        if (!is_object($application) || !isset($application->fee_cover_reason) || $application->fee_cover_reason === null || trim((string) $application->fee_cover_reason) === '') {
            return null;
        }
        return array(
            'reason' => trim((string) $application->fee_cover_reason),
            'by' => isset($application->fee_cover_by) ? intval($application->fee_cover_by) : 0,
            'at' => isset($application->fee_cover_at) ? (string) $application->fee_cover_at : '',
        );
    }

    /** The reason a mark gets when none is typed: Premier League MVP and the season before (Premier League MVP 2026 for 2027). */
    public static function default_reason($season) {
        return 'Premier League MVP ' . (intval($season) - 1);
    }

    /** The words "fee covered: <reason>" (log lines, notes, the re-price context). */
    public static function words($cover) {
        $cover = self::normalise($cover);
        return $cover ? 'fee covered: ' . $cover['reason'] : 'fee cover ended';
    }

    /**
     * How many are covered in a season: array(marked (marked people), people
     * (the same count), user_ids (every covered account)). One
     * season_index().
     */
    public static function season_summary($season) {
        $index = self::season_index($season);
        $ids = array_map('intval', array_keys($index['marks']));
        sort($ids);
        return array('marked' => count($ids), 'people' => count($ids), 'user_ids' => $ids);
    }

    /**
     * 1.88.0 review: the matrix cells a covered person's season is charged
     * on: for each playing or Training Only place they hold in the season
     * (stopped places count, as in pricing) and each offer of theirs for one
     * (awaiting approval, sent, accepted or locked in), the class that
     * place is priced on (TeamOversight_Fees::fee_class_for_team(): a junior
     * on a JPL team is charged the JPL row). Returns class => list of team
     * codes. Reads only; the setup checklist's fee cover row.
     */
    public static function covered_classes($user_id, $season, $fees = null) {
        $user = get_userdata(intval($user_id));
        if (!$user || !is_email($user->user_email) || !self::applies($season)) {
            return array();
        }
        $fees = $fees instanceof TeamOversight_Fees ? $fees : TeamOversight_Fees::shared();
        $teams = array();
        foreach (TeamOversight_Fee_Timeline::rows($user->user_email, $season) as $row) {
            if (in_array((string) $row->role, self::COVERED_ROLES, true)) {
                $teams[(string) $row->team] = true;
            }
        }
        foreach (TeamOversight_Offers::for_user($user->ID, $season, array(TeamOversight_Offers::AWAITING, TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED, TeamOversight_Offers::LOCKED_IN)) as $offer) {
            if (in_array((string) $offer->role, self::COVERED_ROLES, true)) {
                $teams[(string) $offer->team] = true;
            }
        }
        if (empty($teams)) {
            return array();
        }
        $person = $fees->determine_person_fee_class($user->user_email, $season);
        $out = array();
        foreach (array_keys($teams) as $team) {
            $out[(string) $fees->fee_class_for_team($person, $team, $season)][] = $team;
        }
        return $out;
    }

    /** Payment Management's tag for a covered person ('' when not covered). Escaped HTML, no figure. */
    public static function tag_html($user_id, $season) {
        $cover = self::for_person($user_id, $season);
        if (!$cover) {
            return '';
        }
        $title = 'Marked on their trial application: their playing and Training Only places are charged at the supporter fee.';
        return '<span class="murvc-fee-cover-tag" style="display:inline-block;margin-left:4px;padding:1px 6px;border-radius:8px;background:#e7f5ea;color:#1e6b34;font-size:11px;" title="' . esc_attr($title) . '">' . esc_html('Fee covered: ' . $cover['reason']) . '</span>';
    }

    /** The coach portal's read-only badge text for a covered player ('' when not covered). Plain text, no figure. */
    public static function coach_badge($user_id, $season) {
        $cover = self::for_person($user_id, $season);
        if (!$cover) {
            return '';
        }
        return TeamOversight_Offers::wording('cover_badge', array('{reason}' => $cover['reason']));
    }

    /**
     * The Trial Applications cell (administrators only; 2027 on). Not covered
     * with an account: a reason box (the default filled in) and Cover playing
     * fee. Marked: the reason, who set it and when, the box to change it and
     * Clear. No account: says so. Escaped HTML.
     *
     * 1.88.0 review: what the cover does is printed under the form (linked to
     * the reason box with aria-describedby), never only in a title, and Cover
     * playing fee asks first when the player has an offer out (it is
     * re-quoted at once). $open_offers: how many sent or accepted offers the
     * player has in the season (the page passes it from the offers it has
     * already read; null reads them here).
     */
    public static function render_application_cell($trial, $open_offers = null) {
        if (!is_object($trial) || !self::applies(isset($trial->season) ? $trial->season : '')) {
            return '';
        }
        $season = (string) $trial->season;
        $uid = isset($trial->user_id) ? intval($trial->user_id) : 0;
        if ($uid <= 0) {
            return '<small style="color:#646970;">No account, so the fee can\'t be covered.</small>';
        }
        $mark = self::mark_of_application($trial);
        $hint_id = 'murvc-fee-cover-hint-' . intval($trial->id);
        $form = function ($action, $value, $button, $confirm = '') use ($trial, $hint_id) {
            $out = '<form method="post" style="margin:2px 0;"' . ($confirm !== '' ? ' onsubmit="return confirm(\'' . esc_js($confirm) . '\');"' : '') . '>';
            $out .= '<input type="hidden" name="trial_id" value="' . intval($trial->id) . '">';
            $out .= '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
            $out .= wp_nonce_field('process_trial', 'trial_nonce', true, false);
            if ($value !== null) {
                $out .= '<input type="text" name="fee_cover_reason" value="' . esc_attr($value) . '" maxlength="' . intval(self::REASON_MAX) . '" style="font-size:12px;width:100%;max-width:170px;" aria-label="Reason the player reads" aria-describedby="' . esc_attr($hint_id) . '">';
            }
            $out .= '<input type="submit" class="button button-small" value="' . esc_attr($button) . '">';
            return $out . '</form>';
        };
        $html = '';
        $hint = '<p id="' . esc_attr($hint_id) . '" style="margin:2px 0;font-size:11px;color:#646970;max-width:220px;">'
            . esc_html('Their ' . $season . ' playing and Training Only places are charged at the supporter fee, and their offer quotes it with the deposit all of it. An offer already out is re-quoted at once, with no email. The player reads: Congratulations again on being named (the reason).')
            . '</p>';
        if ($mark) {
            $by = $mark['by'] > 0 ? get_userdata($mark['by']) : false;
            $html .= '<strong style="color:#1e6b34;">Covered: ' . esc_html($mark['reason']) . '</strong><br><small style="color:#996800;">Set by ' . esc_html($by ? $by->display_name : 'an admin') . ($mark['at'] !== '' ? ', ' . esc_html(mysql2date('j M Y', $mark['at'])) : '') . '</small>';
            $html .= $form('set_fee_cover', $mark['reason'], 'Save reason') . $hint;
            $html .= $form('clear_fee_cover', null, 'Clear', 'Clear the fee cover? Their open offers are re-quoted at the playing fee and their ' . $season . ' fee is worked out again.');
            return $html;
        }
        if ($open_offers === null) {
            $open_offers = count(TeamOversight_Offers::for_user($uid, $season, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED)));
        }
        $confirm = intval($open_offers) > 0
            ? 'Cover this player\'s ' . $season . ' playing fee? Their ' . (intval($open_offers) === 1 ? 'offer is' : intval($open_offers) . ' offers are') . ' re-quoted at once at the supporter fee. Nobody is emailed until you press Email the new offer on Offers, Waiting on players.'
            : '';
        return $form('set_fee_cover', self::default_reason($season), 'Cover playing fee', $confirm) . $hint;
    }

    // ------------------------------------------------------------------
    // Writers
    // ------------------------------------------------------------------

    private static function res($ok, $code, $message, array $extra = array()) {
        return array_merge(array('ok' => (bool) $ok, 'code' => (string) $code, 'message' => (string) $message, 'changed' => false), $extra);
    }

    private static function application($application_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d", intval($application_id)));
    }

    /**
     * Check a typed reason: trimmed plain text, blank = the default for the
     * season, more than REASON_MAX characters refused (never clipped), a
     * dollar figure refused (coaches see the reason on the player's card).
     * $raw must already be unslashed. Returns array(ok, reason, error).
     */
    public static function check_reason($raw, $season) {
        $reason = trim(preg_replace('/\s+/u', ' ', sanitize_text_field((string) $raw)));
        if ($reason === '') {
            $reason = self::default_reason($season);
        }
        $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($length > self::REASON_MAX) {
            return array('ok' => false, 'reason' => $reason, 'error' => 'The reason can be at most ' . self::REASON_MAX . ' characters (this one has ' . $length . '). Shorten it: nothing was saved.');
        }
        if (TeamOversight_Offer_Wording::has_money($reason)) {
            return array('ok' => false, 'reason' => $reason, 'error' => 'The reason can\'t hold a dollar figure: coaches see it on the player\'s card. Nothing was saved.');
        }
        return array('ok' => true, 'reason' => $reason, 'error' => '');
    }

    /**
     * Price the person's open offers (sent or accepted) of the season with
     * $cover (null = none) as a re-quote would, writing nothing. Returns the
     * admin sentences of every offer that couldn't be re-quoted (a missing
     * supporter cell, a team manager place that sets the rate), or array().
     */
    private static function dry_run($user_id, $season, $cover) {
        $problems = array();
        foreach (TeamOversight_Offers::for_user($user_id, $season, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED)) as $offer) {
            if (self::same(self::stored_cover($offer), $cover)) {
                continue;
            }
            $quote = TeamOversight_Offers::compute_quote($offer->user_id, $offer->season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'requote', 'fee_cover' => $cover ? $cover : false));
            if (empty($quote['ok'])) {
                $problems[] = $offer->season . ' ' . $offer->team . ' offer #' . intval($offer->id) . ': ' . ($quote['error_admin'] !== '' ? $quote['error_admin'] : $quote['error_public']);
            }
        }
        return $problems;
    }

    /**
     * 1.88.0 review: once the season's fees are released, would the person's
     * fee be worked out with $cover (null = none)? Their places priced with
     * the would-be cover, writing nothing: the matrix cells it needs and
     * finds blank (TeamOversight_Fees::unpriced_cells(), e.g. the supporter
     * fee of their category, or of the JPL row a junior's place is charged
     * on) give one admin sentence; '' when everything can be priced, before
     * the release (nothing is invoiced then: the setup checklist asks for
     * the cell), or for someone with no place in the season. Only a gap the
     * change itself makes counts: a cell already blank with $before (the
     * cover they have now) is the matrix's problem, not this change's.
     */
    private static function price_problem($user_id, $season, $cover, $before) {
        if (!TeamOversight_Fees::fees_released($season)) {
            return '';
        }
        $user = get_userdata(intval($user_id));
        if (!$user || !is_email($user->user_email)) {
            return '';
        }
        $fees = TeamOversight_Fees::shared();
        $cells = TeamOversight_Fees::unpriced_cells($fees->fee_breakdown($user->user_email, $season, null, TeamOversight_Fees::RATE_FEE, $cover === null ? false : $cover));
        if (empty($cells)) {
            return '';
        }
        $already = array();
        foreach (TeamOversight_Fees::unpriced_cells($fees->fee_breakdown($user->user_email, $season, null, TeamOversight_Fees::RATE_FEE, $before === null ? false : $before)) as $cell) {
            $already[$cell['class'] . '|' . $cell['column']] = true;
        }
        $new = array();
        foreach ($cells as $cell) {
            if (!isset($already[$cell['class'] . '|' . $cell['column']])) {
                $new[] = $cell;
            }
        }
        if (empty($new)) {
            return '';
        }
        return 'the ' . $season . ' ' . TeamOversight_Fees::unpriced_words($new, $season) . ' isn\'t set, so their ' . $season . ' fee can\'t be worked out ' . ($cover === null ? 'without the cover' : 'at the supporter fee') . '. Set it on Configuration, Fee matrix first.';
    }

    /** One fee_cover log line about the player. */
    private static function log($user_id, $message) {
        TeamOversight_Log::add(self::LOG_TYPE, $message, intval($user_id) > 0 ? array('user_id' => intval($user_id)) : array());
    }

    /** A permanent note on the application (team_trial_notes is never pruned, unlike the log). */
    private static function note($application_id, $text, $author_id) {
        global $wpdb;
        if (intval($application_id) <= 0 || trim((string) $text) === '') {
            return;
        }
        $wpdb->insert($wpdb->prefix . 'team_trial_notes', array(
            'application_id' => intval($application_id),
            'author_id' => intval($author_id),
            'note' => (string) $text,
        ), array('%d', '%d', '%s'));
    }

    /**
     * Mark an application: the club covers the player's playing fee for its
     * season. Administrators only (TeamOversight_Offers::can_approve()). The
     * application must exist, be for 2027 or later and carry an account; the
     * reason is checked (check_reason(); blank = the default). Every open
     * offer of the player in the season is priced with the cover first, and
     * any that couldn't be re-quoted refuses the mark with nothing written.
     * Then ONE compare-and-set UPDATE (a second press with the same reason is
     * 'already'), a fee_cover log line (set, or the reason changed), a note
     * on the application, and sync_person(): open offers re-quoted (no email),
     * the season fee worked out again. $reason is unslashed text.
     * Returns array(ok, code, message, changed, sync (sync_person()'s result)).
     */
    public static function set_mark($application_id, $reason, $actor_id) {
        global $wpdb;
        if (!TeamOversight_Offers::can_approve($actor_id)) {
            return self::res(false, 'forbidden', 'Only administrators can cover a player\'s fee.');
        }
        $app = self::application($application_id);
        if (!$app) {
            return self::res(false, 'not_found', 'Application not found.');
        }
        if (!self::applies($app->season)) {
            return self::res(false, 'refused', 'The club covers playing fees from the ' . TeamOversight_Fees::FEE_TIMELINE_FROM . ' season.');
        }
        $uid = intval($app->user_id);
        if ($uid <= 0) {
            return self::res(false, 'refused', 'This application has no account, so its fee can\'t be covered.');
        }
        $check = self::check_reason($reason, $app->season);
        if (!$check['ok']) {
            return self::res(false, 'invalid', $check['error']);
        }
        $reason = $check['reason'];
        $old = self::mark_of_application($app);
        if ($old && $old['reason'] === $reason) {
            return self::res(true, 'already', 'This application already carries that fee cover.');
        }
        $season = (string) $app->season;
        self::flush_cache();
        $live = self::for_person($uid, $season);
        $after = array('reason' => $reason, 'source' => self::SOURCE_MARK);
        $problems = self::dry_run($uid, $season, self::normalise($after));
        // 1.88.0 review: after the release the fee itself must be priceable at the supporter fee too, or the invoice
        // would stay at the playing fee beside a "Fee covered" tag. Only when they weren't covered (a reason change
        // prices nothing differently).
        if ($live === null) {
            $gap = self::price_problem($uid, $season, self::normalise($after), null);
            if ($gap !== '') {
                $problems[] = ucfirst($gap);
            }
        }
        if (!empty($problems)) {
            return self::res(false, 'no_price', 'Not covered: ' . implode(' ', $problems) . ' Nothing was changed.');
        }
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}trial_applications SET fee_cover_reason = %s, fee_cover_by = %d, fee_cover_at = %s WHERE id = %d AND (fee_cover_reason IS NULL OR fee_cover_reason <> %s)",
            $reason, intval($actor_id), current_time('mysql'), intval($app->id), $reason
        ));
        self::flush_cache();
        if (!$changed) {
            return self::res(true, 'already', 'This application already carries that fee cover.');
        }
        $where = 'trial application #' . intval($app->id) . ' (trial #' . intval($app->trial_number) . ')';
        if ($old) {
            self::log($uid, $season . ' fee cover on ' . $where . ': reason changed from ' . $old['reason'] . ' to ' . $reason);
            self::note($app->id, 'The reason for the club covering the ' . $season . ' playing fee changed from ' . $old['reason'] . ' to ' . $reason . '.', $actor_id);
        } else {
            self::log($uid, $season . ' fee cover set on ' . $where . ': ' . $reason);
            self::note($app->id, 'Playing fee covered by the club for ' . $season . ': ' . $reason . '.', $actor_id);
        }
        $sync = self::sync_person($uid, self::words($after), array($season), $actor_id);
        $message = $old ? 'The reason is now ' . $reason . '.' : 'The ' . $season . ' playing fee is covered: ' . $reason . '.';
        return self::res(true, 'done', $message, array('changed' => true, 'sync' => $sync, 'reason' => $reason));
    }

    /**
     * Clear an application's mark (administrators only): the player's open
     * offers are priced without the cover first (a missing playing cell
     * refuses the clear with nothing written), then ONE compare-and-set
     * UPDATE, a log line, a note and sync_person().
     */
    public static function clear_mark($application_id, $actor_id) {
        global $wpdb;
        if (!TeamOversight_Offers::can_approve($actor_id)) {
            return self::res(false, 'forbidden', 'Only administrators can clear a fee cover.');
        }
        $app = self::application($application_id);
        if (!$app) {
            return self::res(false, 'not_found', 'Application not found.');
        }
        $old = self::mark_of_application($app);
        if (!$old) {
            return self::res(true, 'already', 'This application carries no fee cover.');
        }
        $season = (string) $app->season;
        $uid = intval($app->user_id);
        self::flush_cache();
        $after = null;
        if ($uid > 0 && self::applies($season)) {
            $problems = self::dry_run($uid, $season, $after);
            // 1.88.0 review: after the release their fee must be priceable without the cover too.
            $gap = self::price_problem($uid, $season, null, self::normalise(self::for_person($uid, $season)));
            if ($gap !== '') {
                $problems[] = ucfirst($gap);
            }
            if (!empty($problems)) {
                return self::res(false, 'no_price', 'Not cleared: ' . implode(' ', $problems) . ' Nothing was changed.');
            }
        }
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}trial_applications SET fee_cover_reason = NULL, fee_cover_by = NULL, fee_cover_at = NULL WHERE id = %d AND fee_cover_reason IS NOT NULL",
            intval($app->id)
        ));
        self::flush_cache();
        if (!$changed) {
            return self::res(true, 'already', 'This application carries no fee cover.');
        }
        self::log($uid, $season . ' fee cover cleared on trial application #' . intval($app->id) . ' (trial #' . intval($app->trial_number) . ') (was: ' . $old['reason'] . ')');
        self::note($app->id, 'The club no longer covers the ' . $season . ' playing fee (was: ' . $old['reason'] . ').', $actor_id);
        $sync = ($uid > 0 && self::applies($season)) ? self::sync_person($uid, self::words($after), array($season), $actor_id) : self::empty_sync();
        return self::res(true, 'done', 'The fee cover is cleared.', array('changed' => true, 'sync' => $sync));
    }

    private static function empty_sync() {
        return array('requoted' => 0, 'unchanged' => 0, 'failed' => 0, 'locked' => 0, 'repriced' => 0, 'details' => array());
    }

    /** The 2027+ seasons a person has anything in: a live offer, an invoice, a place or an application. */
    private static function seasons_of($user_id) {
        global $wpdb;
        $uid = intval($user_id);
        $from = (string) TeamOversight_Fees::FEE_TIMELINE_FROM;
        $seasons = array();
        foreach (TeamOversight_Offers::for_user($uid, null, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED, TeamOversight_Offers::LOCKED_IN)) as $offer) {
            $seasons[(string) $offer->season] = true;
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT season FROM {$wpdb->prefix}team_invoices WHERE user_id = %d AND season >= %s
             UNION SELECT season FROM {$wpdb->prefix}team_assignments WHERE user_id = %d AND season >= %s
             UNION SELECT season FROM {$wpdb->prefix}trial_applications WHERE user_id = %d AND season >= %s",
            $uid, $from, $uid, $from, $uid, $from
        ));
        foreach ((array) $rows as $season) {
            $seasons[(string) $season] = true;
        }
        $out = array();
        foreach (array_keys($seasons) as $season) {
            if (self::applies($season)) {
                $out[] = (string) $season;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Bring a person's offers and fee into line with their cover now, after
     * it changed (a mark set or cleared, an account merge or its undo). Per season ($seasons, else every 2027+
     * season they hold anything in): each SENT or ACCEPTED offer whose stored
     * cover differs from the live one is re-quoted
     * (TeamOversight_Offers::requote(); no email is queued); a LOCKED-IN offer
     * whose cover differs stays locked in and gets one log line (from then on
     * the fee follows the cover; anything paid above it is credit); then the
     * season fee is worked out again (TeamOversight_Fees::reprice_person():
     * before the fee release that only checkpoints). Each season in its own
     * try/catch (an offer_alert on failure; the daily reconcile tries the
     * offers again). Returns array(requoted, unchanged, failed, locked,
     * repriced, details (list of plain sentences)).
     */
    public static function sync_person($user_id, $context, $seasons = null, $actor_id = 0) {
        $out = self::empty_sync();
        $uid = intval($user_id);
        if ($uid <= 0 || !class_exists('TeamOversight_Offers')) {
            return $out;
        }
        self::flush_cache();
        $seasons = $seasons === null ? self::seasons_of($uid) : array_values(array_filter(array_map('strval', (array) $seasons), array(__CLASS__, 'applies')));
        foreach ($seasons as $season) {
            try {
                $cover = self::normalise(self::for_person($uid, $season));
                foreach (TeamOversight_Offers::for_user($uid, $season) as $offer) {
                    if (self::same(self::stored_cover($offer), $cover)) {
                        if (in_array($offer->state, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), true)) {
                            $out['unchanged']++;
                        }
                        continue;
                    }
                    if (in_array($offer->state, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), true)) {
                        $r = TeamOversight_Offers::requote($offer->id, $actor_id, (string) $context);
                        if (!empty($r['ok']) && !empty($r['requoted'])) {
                            $out['requoted']++;
                            $out['details'][] = $r['message'];
                        } elseif (!empty($r['ok'])) {
                            $out['unchanged']++;
                        } else {
                            $out['failed']++;
                            $out['details'][] = $offer->team . ' offer #' . intval($offer->id) . ' was not re-quoted: ' . $r['message'];
                        }
                    } elseif ($offer->state === TeamOversight_Offers::LOCKED_IN) {
                        $out['locked']++;
                        $line = $season . ' ' . $offer->team . ' offer #' . intval($offer->id) . ' was locked in before the cover changed (' . $context . '): the ' . $season . ' fee now follows the cover; anything paid above it is credit on Payment Management.';
                        self::log($uid, $line);
                        $out['details'][] = 'The ' . $offer->team . ' offer was already locked in: it stays locked in, and their fee follows the cover. Anything paid above it shows as credit on Payment Management.';
                    }
                }
                $user = get_userdata($uid);
                if ($user && is_email($user->user_email)) {
                    // The re-price's log line says what happened (1.88.0 review): the caller's context (a mark set or
                    // cleared is already "fee covered: ..." / "fee cover ended"; a merge record names itself) and,
                    // when that isn't already it, the cover the season has now.
                    $why = $cover ? self::words($cover) : 'not covered';
                    $repriced = TeamOversight_Fees::shared()->reprice_person($user->user_email, $season, ($context === '' || $context === self::words($cover)) ? self::words($cover) : $context . ': ' . $why);
                    if (!empty($repriced['repriced'])) {
                        $out['repriced']++;
                    }
                    if (!empty($repriced['message'])) {
                        $out['details'][] = $repriced['message'];
                    }
                }
            } catch (\Throwable $e) {
                $out['failed']++;
                TeamOversight_Log::add('offer_alert', $season . ' fee cover: the offers or the fee of user #' . $uid . ' could not be brought into line after the cover changed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . '). The daily check re-quotes their offers; their fee is worked out again at the next re-price.', array('user_id' => $uid));
            }
        }
        return $out;
    }

    /**
     * The daily step (TeamOversight_Offers::run_daily(), 'fee_cover'): every
     * open offer of a 2027+ season whose stored cover differs from the live
     * one is re-quoted (a failed hook, the upgrade, and from 1.88.1 an offer
     * quoted under 1.88.0 as a life member's). Never re-quotes for any other
     * difference. Once a
     * season's fees are released, a covered person's fee that couldn't be
     * priced at the supporter fee is worked out again when it can be
     * (reconcile_season()). Returns array(checked, requoted, failed, repriced).
     */
    public static function reconcile() {
        $out = array('checked' => 0, 'requoted' => 0, 'failed' => 0, 'repriced' => 0);
        $seasons = array();
        foreach (TeamOversight_Offers::open_offers() as $offer) {
            $seasons[(string) $offer->season] = true;
        }
        // 1.88.0 review: this year's and next year's season too, for the covered people's released fees (heal_fee()).
        $year = intval(current_time('Y'));
        foreach (array((string) $year, (string) ($year + 1)) as $season) {
            if (self::applies($season)) {
                $seasons[$season] = true;
            }
        }
        foreach (array_keys($seasons) as $season) {
            $r = self::reconcile_season($season);
            foreach ($out as $k => $v) {
                $out[$k] += isset($r[$k]) ? intval($r[$k]) : 0;
            }
        }
        return $out;
    }

    /**
     * reconcile_season()'s fee step for one covered person: their released
     * season invoice (not an Edit Fee) is worked out again when what their
     * cover charges can be priced and differs from it by a cent or more.
     * Returns true when the invoice amount changed.
     */
    private static function heal_fee($user_id, $season) {
        global $wpdb;
        $user = get_userdata(intval($user_id));
        if (!$user || !is_email($user->user_email)) {
            return false;
        }
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT id, invoice_amount, fee_overridden FROM {$wpdb->prefix}team_invoices WHERE (user_id = %d OR email = %s) AND season = %s ORDER BY id LIMIT 1",
            intval($user_id), $user->user_email, (string) $season
        ));
        if (!$invoice || intval($invoice->fee_overridden) === 1) {
            return false;
        }
        $fees = TeamOversight_Fees::shared();
        $breakdown = $fees->fee_breakdown($user->user_email, $season);
        if (empty($breakdown['fee_cover']) || !empty(TeamOversight_Fees::unpriced_cells($breakdown))) {
            return false;
        }
        $total = TeamOversight_Fees::breakdown_fee($breakdown);
        if ($total === null || abs($total - floatval($invoice->invoice_amount)) < 0.005) {
            return false;
        }
        $r = $fees->reprice_person($user->user_email, $season, 'daily check: ' . self::words(self::for_person($user_id, $season)));
        return !empty($r['repriced']);
    }

    /**
     * reconcile() for one season (the tests call this, never run_daily()).
     *
     * 1.88.0 review: once the season's fees are released it also works out
     * again the fee of each covered person whose invoice isn't what their
     * cover charges now and can be priced (the supporter fee of their
     * category was blank when the cover started, and has been entered
     * since: TeamOversight_Fees::reprice_person() reported the gap and raised
     * an offer alert). Never an Edit Fee, never anyone not covered, never a
     * fee that still can't be priced. Returns array(checked, requoted,
     * failed, repriced).
     */
    public static function reconcile_season($season) {
        $out = array('checked' => 0, 'requoted' => 0, 'failed' => 0, 'repriced' => 0);
        $season = (string) $season;
        if (!self::applies($season)) {
            return $out;
        }
        self::flush_cache();
        if (TeamOversight_Fees::fees_released($season)) {
            foreach (self::season_summary($season)['user_ids'] as $uid) {
                try {
                    if (self::heal_fee($uid, $season)) {
                        $out['repriced']++;
                    }
                } catch (\Throwable $e) {
                    $out['failed']++;
                }
            }
            self::flush_cache();
        }
        foreach (TeamOversight_Offers::open_offers($season) as $offer) {
            $out['checked']++;
            if (self::same(self::stored_cover($offer), self::for_person($offer->user_id, $season))) {
                continue;
            }
            try {
                $r = TeamOversight_Offers::requote($offer->id, 0, 'daily check');
                if (!empty($r['requoted'])) {
                    $out['requoted']++;
                } elseif (empty($r['ok'])) {
                    $out['failed']++;
                }
            } catch (\Throwable $e) {
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * The 2027+ seasons in which the fee cover touches a person: they are
     * covered now, or an offer of theirs that is sent, accepted or locked in
     * carries a cover they no longer have. Reads only. (1.88.0 review: the
     * merge hooks bring only these seasons into line, so a merge with no
     * cover in it changes no money, as TeamOversight_Account_Merge promises.)
     */
    private static function cover_seasons($user_id) {
        $out = array();
        foreach (self::seasons_of($user_id) as $season) {
            if (self::for_person($user_id, $season) !== null) {
                $out[] = $season;
                continue;
            }
            foreach (TeamOversight_Offers::for_user($user_id, $season, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED, TeamOversight_Offers::LOCKED_IN)) as $offer) {
                if (self::stored_cover($offer) !== null) {
                    $out[] = $season;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * After an account merge (murvc_account_merged): a merged account may
     * have brought the cover (a marked application), or the
     * kept account's cover now reaches what the merged one brought (its
     * offers, its invoice). Only the seasons where a cover is involved
     * (cover_seasons()) are brought into line, and they are written to the
     * merge record's summary ('fee_cover_seasons') so its undo brings the
     * same seasons back. A merge with no cover in it re-prices nothing.
     */
    public static function after_merge($keep_id, $merged_ids, $merge_id) {
        try {
            self::flush_cache();
            $seasons = self::cover_seasons($keep_id);
            if (empty($seasons)) {
                return;
            }
            if (class_exists('TeamOversight_Account_Merge')) {
                TeamOversight_Account_Merge::add_to_summary($merge_id, 'fee_cover_seasons', $seasons);
            }
            self::sync_person($keep_id, 'account merge record ' . intval($merge_id), $seasons);
        } catch (\Throwable $e) {
            TeamOversight_Log::add('offer_alert', 'Fee cover: the offers and fee of user #' . intval($keep_id) . ' could not be brought into line after merge record ' . intval($merge_id) . '. The daily check re-quotes their offers.', array('user_id' => intval($keep_id)));
        }
    }

    /**
     * After an account merge was undone (murvc_account_merge_undone): each of
     * the kept and the restored accounts, for the seasons the merge brought
     * into line (its summary's 'fee_cover_seasons') and any season where a
     * cover touches it now. Nothing for a merge with no cover in it.
     */
    public static function after_merge_undone($keep_id, $merged_ids, $merge_id) {
        self::flush_cache();
        $recorded = array();
        if (class_exists('TeamOversight_Account_Merge')) {
            $record = TeamOversight_Account_Merge::get($merge_id);
            if ($record && is_array($record['summary']) && !empty($record['summary']['fee_cover_seasons']) && is_array($record['summary']['fee_cover_seasons'])) {
                $recorded = array_map('strval', $record['summary']['fee_cover_seasons']);
            }
        }
        foreach (array_merge(array(intval($keep_id)), array_map('intval', (array) $merged_ids)) as $uid) {
            try {
                $seasons = array_values(array_unique(array_merge($recorded, self::cover_seasons($uid))));
                if (empty($seasons)) {
                    continue;
                }
                sort($seasons, SORT_STRING);
                self::sync_person($uid, 'account merge record ' . intval($merge_id) . ' undone', $seasons);
            } catch (\Throwable $e) {
                TeamOversight_Log::add('offer_alert', 'Fee cover: the offers and fee of user #' . intval($uid) . ' could not be brought into line after merge record ' . intval($merge_id) . ' was undone. The daily check re-quotes their offers.', array('user_id' => intval($uid)));
            }
        }
    }
}
