<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Offers, committee approval and deposits (1.76.0, the 2027 season on).
 *
 * The coach's verdict (team_trial_selections) stays the coach's call; an
 * offer (team_offers, one row per offer cycle, never deleted) is the
 * lifecycle on top of it: awaiting_approval > sent > accepted > locked_in,
 * with not_approved, cancelled, declined, moved_declined, withdrawn and
 * left as the ways it ends. The quote is fixed when the committee approves
 * it (the fee matrix upper limit before fees are released, the real fee
 * after), and so are the reply-by and deposit-due dates, so a later
 * settings change never moves a live offer.
 *
 * Design: design/offer-deposit-tech-design.md. API reference:
 * design/offer-api.md (read it before changing any signature here).
 *
 * Rules every transition follows:
 *  - validate, compute, then ONE compare-and-set UPDATE on the offer row;
 *    only when it changed exactly one row do the side effects run (outbox
 *    rows, log line, coach note, badge). Run twice, the second call returns
 *    ok with changed = false and does nothing.
 *  - everything ending an offer is one UPDATE (state, live = NULL, who/why,
 *    settlement = 'pending' when the player's invoice holds credit).
 *  - coach actors are bound to (application, verified team, season): the
 *    offer is found from those, never from a posted offer id, and every
 *    UPDATE repeats application, team and season.
 *  - money is integer cents in every comparison.
 *  - nothing here sends email: transitions call
 *    TeamOversight_Offer_Emails::queue(), which only records the message.
 *  - offers ARE how selection works from the 2027 season (club decision,
 *    28 Oct 2026, 1.79.0): there is no master switch, no paused state and no
 *    coach-recorded acceptance for 2027+. Seasons before 2027 ignore
 *    offers completely (applies() is false, and label() / chip() return the
 *    1.75.3 wording), exactly as 1.75.3. The controls are the email
 *    permission (emails_live(): nothing leaves a copy of the site) and the
 *    committee's approval of every offer. maybe_upgrade() runs the one-off
 *    work once per site (the 2027 reset, reconcile, and a count of the
 *    rejections not told yet: since 1.80.0 a "not selected" email is only
 *    sent when someone presses Send).
 *  - every administrator-only transition (approve, reject, admin_*, and the
 *    money methods) checks can_approve() itself, so a handler that forgot
 *    its capability check still can't let a coach or a player act as one.
 *
 * The file runs top to bottom: the season gate and settings, the quote maths,
 * readers, wording (cards, chips, the Early Offer, the terms, the extra
 * training line), the higher-team rule, the coach, committee and player
 * transitions, the daily job, the one-off upgrade and the email gate, then the money half
 * (holding invoice, lock-in and placement, settlement, the deposit payment,
 * the release drop, the cap, the safety nets and the treasurer's lists).
 */
class TeamOversight_Offers {

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    /**
     * The retired master switch (1.76.0 to 1.78.0). Since 1.79.0 nothing reads
     * or writes it: offers are how selection works from 2027. A site that
     * switched it on keeps the row (data is never deleted); it means nothing.
     */
    const RETIRED_SWITCH_OPTION = 'team_oversight_offers_enabled';
    /** The one-off 1.79.0 upgrade (maybe_upgrade()): array(done, at, offers_from, reset, reconcile, backlog). Autoloaded and small, so the check costs no query. */
    const UPGRADE_OPTION = 'team_oversight_offers_upgrade';
    /** Per-season settings: season => array(deposit_date, percent, reply_days, deposit_days, saved_at, saved_by). */
    const SETTINGS_OPTION = 'team_oversight_offer_settings';
    /** The one-off reset's audit and two-step flag. */
    const RESET_OPTION = 'team_oversight_offer_reset_2027';
    /** The live-site gate for offer emails: array(fingerprint, at, by). */
    const EMAILS_LIVE_OPTION = 'team_oversight_offer_emails_live';
    /** The cached count behind the menu badge. */
    const BADGE_OPTION = 'team_oversight_offers_badge';
    /** The three terms lists, group => text (one item per line), blank = built in. */
    const TERMS_OPTION = 'team_oversight_offer_terms';
    /** The club's contact address for offers (a person who cannot pay online or lost the email writes here). Blank = the trial applications address. */
    const CONTACT_OPTION = 'team_oversight_offer_contact';
    /** Optional override of the offer page URL. */
    const PAGE_URL_OPTION = 'team_oversight_offer_page_url';
    /** Optional per-season override of the training information page: season => url. */
    const TRAINING_URLS_OPTION = 'team_oversight_offer_training_urls';
    /** The daily job's cron event. */
    const DAILY_HOOK = 'murvc_offers_daily';
    /** Flash notice transient prefix for the email permission buttons. */
    const FLASH_PREFIX = 'murvc_offer_flash_';
    /** At most one "emails are paused" log line a day (a transient, so no option row). */
    const SUPPRESSED_TRANSIENT = 'murvc_offer_suppressed';

    /**
     * The deposit payment form's contract, ONE set of names for both sides:
     * the player page posts it (TeamOversight_Offers_Player::render_deposit_box()
     * through TeamOversight_Payments::render_pay_box($options)) and
     * maybe_start_deposit_payment() reads it. The nonce ACTION is
     * deposit_pay_nonce_action($offer_id); its FIELD is nonce_field. The deposit
     * is paid in ONE payment of exactly the remaining deposit: there is no
     * choice and no typed amount. amount_field is a hidden field carrying the
     * figure the page showed (dollars, 2 dp); the receiver refuses any other
     * amount. See design/offer-api.md section 6.11.
     */
    const DEPOSIT_FORM = array(
        'action_field' => 'murvc_pay_action',
        'action_value' => 'pay_deposit',
        'offer_field' => 'murvc_offer_id',
        'nonce_field' => 'murvc_pay_nonce',
        'nonce_prefix' => 'murvc_pay_deposit_',
        'amount_field' => 'murvc_pay_amount',
    );

    const AWAITING = 'awaiting_approval';
    const SENT = 'sent';
    const ACCEPTED = 'accepted';
    const LOCKED_IN = 'locked_in';
    const NOT_APPROVED = 'not_approved';
    const CANCELLED = 'cancelled';
    const DECLINED = 'declined';
    const MOVED_DECLINED = 'moved_declined';
    const WITHDRAWN = 'withdrawn';
    const LEFT = 'left';

    /** States an offer can be in while not finished (team_offers.live = 1). */
    const LIVE_STATES = array('awaiting_approval', 'sent', 'accepted', 'locked_in');
    /** States a player may see (the rest are invisible to them: the player gets nothing). */
    const PLAYER_VISIBLE = array('sent', 'accepted', 'locked_in', 'declined', 'moved_declined', 'withdrawn', 'left');
    /** The two "declined" outcomes (one Declined list). */
    const DECLINED_STATES = array('declined', 'moved_declined');

    /** Days "+7 days" adds to an overdue offer, counted from today (a fixed rule; the coach_overdue email's wording says 7 too). */
    const EXTEND_DAYS = 7;

    /** Verdict status <-> offer role. */
    const ROLE_FOR_VERDICT = array('selected' => 'playing_member', 'training_only' => 'training_only');

    // ------------------------------------------------------------------
    // State labels, small helpers
    // ------------------------------------------------------------------

    /** state => short name (admin lists, logs). */
    public static function states() {
        return array(
            'awaiting_approval' => 'Awaiting committee approval',
            'sent' => 'Offer sent',
            'accepted' => 'Accepted, deposit due',
            'locked_in' => 'Locked in',
            'not_approved' => 'Not approved',
            'cancelled' => 'Cancelled',
            'declined' => 'Declined by the player',
            'moved_declined' => 'Moved to declined',
            'withdrawn' => 'Withdrawn',
            'left' => 'Left the team',
        );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'team_offers';
    }

    public static function is_live_state($state) {
        return in_array($state, self::LIVE_STATES, true);
    }

    /** The nonce action for one offer's deposit payment form (the field name is DEPOSIT_FORM['nonce_field']). */
    public static function deposit_pay_nonce_action($offer_id) {
        return self::DEPOSIT_FORM['nonce_prefix'] . intval($offer_id);
    }

    /** "playing_member" -> "Playing member", "training_only" -> "Training Only". */
    public static function role_name($role) {
        return $role === 'training_only' ? 'Training Only' : 'Playing';
    }

    /** The offer role for a verdict status, or '' when that verdict cannot be offered. */
    public static function role_for_verdict($status) {
        return isset(self::ROLE_FOR_VERDICT[$status]) ? self::ROLE_FOR_VERDICT[$status] : '';
    }

    /** The verdict status an offer's role belongs to. */
    public static function verdict_for_role($role) {
        return $role === 'training_only' ? 'training_only' : 'selected';
    }

    /** The shared TeamOversight_Fees (the same object the rest of the plugin uses). */
    public static function fees() {
        return TeamOversight_Fees::shared();
    }

    /** Integer cents from a money value. */
    public static function to_cents($amount) {
        return (int) round(floatval($amount) * 100);
    }

    /** Cut text to $length CHARACTERS (never in the middle of a multibyte character: the columns are characters, not bytes). */
    public static function cut($text, $length) {
        $text = (string) $text;
        return function_exists('mb_substr') ? mb_substr($text, 0, intval($length), 'UTF-8') : substr($text, 0, intval($length));
    }

    /** "$1,234.50" from a money value. */
    public static function money($amount) {
        return '$' . number_format((float) $amount, 2);
    }

    private static function fmt_date($date) {
        $valid = TeamOversight_Fee_Timeline::parse_date((string) $date);
        return $valid === null ? '' : date('j M', strtotime($valid));
    }

    private static function fmt_date_year($date) {
        $valid = TeamOversight_Fee_Timeline::parse_date((string) $date);
        return $valid === null ? '' : date('j M Y', strtotime($valid));
    }

    /** The result every transition returns. ok = what was asked is now true; changed = this call made it so. */
    private static function res($ok, $code, $message = '', array $extra = array()) {
        return array_merge(array(
            'ok' => (bool) $ok,
            'code' => (string) $code,
            'message' => (string) $message,
            'changed' => false,
            'offer_id' => 0,
            'state' => '',
        ), $extra);
    }

    private static function already($offer, $message = 'Already done.') {
        return self::res(true, 'already', $message, array('offer_id' => $offer ? $offer->id : 0, 'state' => $offer ? $offer->state : ''));
    }

    private static function stale($offer = null) {
        return self::res(false, 'stale', 'This offer changed after the page loaded: reload and check it.', array('offer_id' => $offer ? $offer->id : 0, 'state' => $offer ? $offer->state : ''));
    }

    private static function done($offer, $message = '', array $extra = array()) {
        $fresh = $offer ? self::get($offer->id) : null;
        return self::res(true, 'done', $message, array_merge(array('changed' => true, 'offer_id' => $offer ? $offer->id : 0, 'state' => $fresh ? $fresh->state : ''), $extra));
    }

    private static function not_found() {
        return self::res(false, 'not_found', 'No such offer.');
    }

    /** An administrator-only transition was asked for by someone who may not approve (the class enforces the allowed-actor table itself; callers still check the nonce and manage_options). */
    private static function forbidden($what = 'administrators') {
        return self::res(false, 'forbidden', 'Only ' . $what . ' can do this.');
    }

    // ------------------------------------------------------------------
    // The season gate, settings
    // ------------------------------------------------------------------

    /**
     * Does the offer flow apply to this season? (2027 on: the offer step and
     * the fee timeline.) This is the only test: from 2027 offers are how
     * selection works, and a season before 2027 ignores them completely.
     */
    public static function applies($season) {
        return is_scalar($season) && preg_match('/^\d{4}$/D', (string) $season) === 1
            && TeamOversight_Coach_Portal::offer_acceptance_applies($season)
            && TeamOversight_Fees::timeline_applies($season);
    }

    /** May this user approve or refuse offers? Administrators (manage_options); a filter so a committee screen can widen it later. */
    public static function can_approve($user_id) {
        $user_id = intval($user_id);
        $allowed = $user_id > 0 && user_can($user_id, 'manage_options');
        return (bool) apply_filters('murvc_offers_can_approve', $allowed, $user_id);
    }

    /** Forget everything cached this request (every write calls this). */
    public static function flush_cache() {
        self::$settings_cache = array();
        self::$page_url_cache = null;
        self::$teams_config_cache = array();
        if (class_exists('TeamOversight_Fees')) {
            // The cap looks offers up per request; its cache hangs off this hook too.
            do_action('murvc_offers_cache_flushed');
        }
    }

    // ---- per-season settings ----

    private static $settings_cache = array();

    /** The defaults for a season. */
    public static function settings_defaults($season) {
        return array(
            'deposit_date' => sprintf('%04d-01-10', intval($season)),
            'percent' => 10.0,
            'reply_days' => 7,
            'deposit_days' => 14,
        );
    }

    /** One setting value if valid, else null (read-time validation: a bad saved value falls back to the default). */
    private static function valid_setting($key, $value, $season) {
        switch ($key) {
            case 'deposit_date':
                $date = is_string($value) ? TeamOversight_Fee_Timeline::parse_date($value) : null;
                $year = $date === null ? 0 : intval(substr($date, 0, 4));
                return ($date !== null && ($year === intval($season) || $year === intval($season) - 1)) ? $date : null;
            case 'percent':
                if (!is_numeric($value)) {
                    return null;
                }
                $v = round(floatval($value), 2);
                return ($v >= 0 && $v <= 100) ? $v : null;
            case 'reply_days':
                return (is_numeric($value) && intval($value) == $value && intval($value) >= 1 && intval($value) <= 60) ? intval($value) : null;
            case 'deposit_days':
                return (is_numeric($value) && intval($value) == $value && intval($value) >= 1 && intval($value) <= 90) ? intval($value) : null;
        }
        return null;
    }

    /**
     * A season's offer settings: array(deposit_date 'Y-m-d', percent float,
     * reply_days int, deposit_days int, saved_at, saved_by, saved). Saved
     * values merged over the defaults; a saved value that fails validation
     * is ignored. Live offers hold their own snapshot, so a change here
     * never moves one.
     */
    public static function settings($season) {
        $season = (string) $season;
        if (isset(self::$settings_cache[$season])) {
            return self::$settings_cache[$season];
        }
        $out = self::settings_defaults($season);
        $all = get_option(self::SETTINGS_OPTION, array());
        $saved = (is_array($all) && isset($all[$season]) && is_array($all[$season])) ? $all[$season] : array();
        foreach (array('deposit_date', 'percent', 'reply_days', 'deposit_days') as $key) {
            if (array_key_exists($key, $saved)) {
                $v = self::valid_setting($key, $saved[$key], $season);
                if ($v !== null) {
                    $out[$key] = $v;
                }
            }
        }
        $out['saved_at'] = isset($saved['saved_at']) ? (string) $saved['saved_at'] : '';
        $out['saved_by'] = isset($saved['saved_by']) ? intval($saved['saved_by']) : 0;
        $out['saved'] = !empty($saved);
        self::$settings_cache[$season] = $out;
        return $out;
    }

    /**
     * Validate and save a season's settings from an input array
     * (deposit_date, percent, reply_days, deposit_days). Strict: a bad
     * value refuses the whole save with every problem listed; an unchanged
     * save writes nothing. Re-prices nothing and moves no live offer. Logs
     * one season_config line with old and new values. Returns array(ok,
     * changed, errors, message, values).
     */
    public static function save_settings_values($season, array $input, $actor_id = 0) {
        $season = (string) $season;
        $result = array('ok' => false, 'changed' => false, 'errors' => array(), 'message' => '', 'values' => array());
        if (!self::applies($season)) {
            $result['errors'][] = 'Offers apply from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.';
            $result['message'] = $result['errors'][0];
            return $result;
        }
        $values = array();
        $labels = array('deposit_date' => 'Fixed deposit date', 'percent' => 'Deposit percent', 'reply_days' => 'Reply days', 'deposit_days' => 'Deposit days');
        foreach ($labels as $key => $label) {
            $raw = isset($input[$key]) ? trim((string) $input[$key]) : '';
            $v = $raw === '' ? null : self::valid_setting($key, $key === 'percent' ? (preg_match('/^\d{1,3}(\.\d{1,2})?$/D', $raw) === 1 ? $raw : 'x') : $raw, $season);
            if ($v === null) {
                switch ($key) {
                    case 'deposit_date':
                        $result['errors'][] = $label . ' must be a real date in ' . $season . ' or ' . (intval($season) - 1) . '.';
                        break;
                    case 'percent':
                        $result['errors'][] = $label . ' must be a number from 0 to 100 with up to two decimals (0 locks a player in the moment they accept).';
                        break;
                    case 'reply_days':
                        $result['errors'][] = $label . ' must be a whole number from 1 to 60.';
                        break;
                    default:
                        $result['errors'][] = $label . ' must be a whole number from 1 to 90.';
                }
                continue;
            }
            $values[$key] = $v;
        }
        if (!empty($result['errors'])) {
            $result['message'] = 'Nothing was saved. ' . implode(' ', $result['errors']);
            return $result;
        }
        $old = self::settings($season);
        $changes = array();
        foreach ($values as $key => $v) {
            $same = $key === 'deposit_date' ? $old[$key] === $v : (abs(floatval($old[$key]) - floatval($v)) < 0.0001);
            if (!$same) {
                $changes[] = strtolower($labels[$key]) . ' ' . $old[$key] . ' to ' . $v;
            }
        }
        $result['values'] = $values;
        if (empty($changes)) {
            $result['ok'] = true;
            $result['message'] = 'Nothing changed for ' . $season . '.';
            return $result;
        }
        $all = get_option(self::SETTINGS_OPTION, array());
        $all = is_array($all) ? $all : array();
        $all[$season] = array_merge($values, array('saved_at' => current_time('mysql'), 'saved_by' => intval($actor_id)));
        update_option(self::SETTINGS_OPTION, $all, false);
        self::$settings_cache = array();
        TeamOversight_Log::add('season_config', $season . ' offer settings saved: ' . implode('; ', $changes) . ' (live offers keep the figures they were sent with)');
        $result['ok'] = true;
        $result['changed'] = true;
        $result['message'] = $season . ' offer settings saved. Offers already sent keep the dates and deposit they were sent with.';
        return $result;
    }

    /** The Configuration page's POST branch: reads $_POST (unslashed here), checks nothing about nonces (the caller did). */
    public static function save_settings($season) {
        $input = array();
        foreach (array('deposit_date', 'percent', 'reply_days', 'deposit_days') as $key) {
            $input[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        }
        return self::save_settings_values($season, $input, get_current_user_id());
    }

    /** HTML notice for a save_settings*() result. */
    public static function settings_notice($result) {
        if (!empty($result['ok'])) {
            return '<div class="notice notice-' . (!empty($result['changed']) ? 'success' : 'info') . '"><p>' . esc_html($result['message']) . '</p></div>';
        }
        return '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
    }

    // ------------------------------------------------------------------
    // Quote and deposit maths (the fee engine does the pricing)
    // ------------------------------------------------------------------

    /** Which basis a new quote uses: 'fee' once the season's fees are released (a season with no release date counts as released), else 'upper'. */
    public static function basis_now($season) {
        return TeamOversight_Fees::fees_released($season) ? 'fee' : 'upper';
    }

    /**
     * Deposit target in cents: the quote (money) times the percent,
     * rounded half up to the cent, in integer arithmetic so there is no
     * float drift. 437.50 at 10% = 4375; 612.33 at 10% = 6123. A $0 quote or
     * 0% is 0. The stored target is target_cents / 100.
     */
    public static function target_cents($quote, $percent) {
        $q = (int) round(floatval($quote) * 100);
        $pct = (int) round(floatval($percent) * 100); // hundredths of a percent: 10% -> 1000
        return intdiv($q * $pct + 5000, 10000);
    }

    /**
     * The two dates stored on an offer when it is sent (a pure function, so
     * the maths can be tested for any day):
     *   reply_by    = sent date + reply days
     *   deposit_due = the later of the season's fixed deposit date and the
     *                 sent date + deposit days
     * both clamped to the late-join cutoff ($cutoff, the effective "Paid in
     * full by"; null = none), and reply_by never after deposit_due.
     * $settings: settings() (deposit_date, reply_days, deposit_days).
     */
    public static function deadlines($sent_date, array $settings, $cutoff = null) {
        $reply_by = TeamOversight_Fee_Timeline::add_days($sent_date, intval($settings['reply_days']));
        $deposit_due = max($settings['deposit_date'], TeamOversight_Fee_Timeline::add_days($sent_date, intval($settings['deposit_days'])));
        if ($cutoff !== null && $cutoff !== '') {
            $reply_by = min($reply_by, $cutoff);
            $deposit_due = min($deposit_due, $cutoff);
        }
        return array('reply_by' => min($reply_by, $deposit_due), 'deposit_due' => $deposit_due);
    }

    /**
     * The fee class and matrix column a player is priced on for one place:
     * their person class, then JPL/YSL for a junior on a junior-league
     * team (exactly what the engine does). Returns array(fee_class, column)
     * or null when the account can't be read. $fees: an optional
     * TeamOversight_Fees (pass one with enable_rate_cache() for a list).
     */
    public static function fee_cell($user_id, $season, $team, $role, $fees = null) {
        $user = intval($user_id) > 0 ? get_userdata(intval($user_id)) : false;
        if (!$user || !is_email($user->user_email)) {
            return null;
        }
        $fees = $fees instanceof TeamOversight_Fees ? $fees : self::fees();
        $person = $fees->determine_person_fee_class($user->user_email, $season);
        return array($fees->fee_class_for_team($person, $team, $season), TeamOversight_Fees::charged_as($role));
    }

    /** The person fee class behind a matrix cell's class (the JPL/YSL cell belongs to a Junior U/19 (VVL) person on a junior-league team). */
    private static function person_class_for_cell($cell_class) {
        return (string) $cell_class === 'JPL/YSL' ? 'Junior U/19 (VVL)' : (string) $cell_class;
    }

    /**
     * Can this player's cell be priced on the given basis ('upper' = it has
     * an upper limit, 'fee' = it has a fee)? Needs no pricing, so a card or
     * the bulk panel can ask cheaply. A blank limit NEVER falls back to a
     * fee. Returns array(ok, error_public (safe for coaches: no class, no
     * amount), error_admin (names the class and role), basis, fee_class,
     * column, limit, fee).
     */
    public static function cell_check($user_id, $season, $team, $role, $basis = null, $fees = null) {
        $out = array('ok' => false, 'error_public' => '', 'error_admin' => '', 'basis' => '', 'fee_class' => '', 'column' => '', 'limit' => null, 'fee' => null);
        if (!self::applies($season)) {
            $out['error_public'] = $out['error_admin'] = 'Offers apply from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.';
            return $out;
        }
        $cell = self::fee_cell($user_id, $season, $team, $role, $fees);
        if ($cell === null) {
            $out['error_public'] = $out['error_admin'] = 'This player\'s account can\'t be found, so no offer can be priced.';
            return $out;
        }
        list($class, $column) = $cell;
        $basis = $basis === 'fee' || $basis === 'upper' ? $basis : self::basis_now($season);
        $out['basis'] = $basis;
        $out['fee_class'] = $class;
        $out['column'] = $column;
        $out['limit'] = TeamOversight_Fees::get_limit($class, $column, $season);
        $fee = ($fees instanceof TeamOversight_Fees ? $fees : self::fees())->get_fee($class, $column, $season);
        $out['fee'] = $fee === null ? null : floatval($fee);
        $has = $basis === 'upper' ? $out['limit'] !== null : $out['fee'] !== null;
        if ($has) {
            $out['ok'] = true;
            return $out;
        }
        // Coach-safe (no class, no figure), from the wording registry: before the release an Early Offer needs the upper limit, after it the fee.
        $out['error_public'] = self::wording($basis === 'upper' ? 'cell_missing_limit' : 'cell_missing_fee');
        $where = $class . ' / ' . strtolower(TeamOversight_Fees::role_label($column));
        if ($basis === 'upper') {
            $out['error_admin'] = 'No upper limit is set for ' . $where . ' in the ' . $season . ' fee matrix.';
        } elseif (TeamOversight_Fees::get_fee_release_date($season) === '') {
            $out['error_admin'] = 'No fee release date is set for ' . $season . ', so offers need real fees and there is no fee for ' . $where . '. Set a release date to quote on upper limits.';
        } else {
            $out['error_admin'] = 'No fee is set for ' . $where . ' in the ' . $season . ' fee matrix, and the fees are released, so offers are quoted on real fees.';
        }
        return $out;
    }

    /**
     * The team places to price an offer against: the player's real places
     * in the season, plus a hypothetical one for this offer (from $start)
     * and one for each other of their offers in the season that is accepted
     * or locked in without a real place yet (from that offer's own quote
     * start). Offers that are only sent are not counted: the player has not
     * agreed to them. Deduplicated by team|role, a real open place winning.
     * Returns array(rows, labels of the other places counted).
     */
    public static function quote_rows($user, $season, $team, $role, $start, $offer = null) {
        $real = TeamOversight_Fee_Timeline::rows($user->user_email, $season);
        $rows = $real;
        $labels = array();
        $make = function ($t, $r, $from) use ($user) {
            return (object) array('id' => 0, 'user_id' => intval($user->ID), 'email' => $user->user_email, 'team' => $t, 'role' => $r,
                'start_date' => $from, 'end_date' => null, 'is_active' => 1, 'registration_status' => 'active', 'start_missing' => false, 'created_ts' => 0);
        };
        $held = function ($t, $r, $from) use ($real) {
            foreach ($real as $row) {
                if ($row->team === $t && $row->role === $r && ($row->end_date === null || $row->end_date > $from)) {
                    return true;
                }
            }
            return false;
        };
        if (!$held($team, $role, $start)) {
            $rows[] = $make($team, $role, $start);
        }
        $own_id = $offer ? intval($offer->id) : 0;
        foreach (self::for_user($user->ID, $season) as $other) {
            if ($other->id === $own_id || !in_array($other->state, array(self::ACCEPTED, self::LOCKED_IN), true)) {
                continue;
            }
            if ($other->team === $team && $other->role === $role) {
                continue;
            }
            $from = $other->quote_start ? $other->quote_start : current_time('Y-m-d');
            if ($held($other->team, $other->role, $from)) {
                continue;
            }
            $rows[] = $make($other->team, $other->role, $from);
            $labels[] = $other->team . ' ' . strtolower(TeamOversight_Fees::role_label($other->role));
        }
        return array($rows, $labels);
    }

    /**
     * Work out a quote (the season fee with this place included alongside
     * the player's other accepted or locked-in places) and the deposit
     * target. Reads only; never writes.
     *
     * $opts:
     *  - offer: the offer being priced (excluded from "other places"; the
     *    source of the stored basis, start and percent in accept / real mode)
     *  - mode: 'send' (default; basis from the release state now, start
     *    today, percent from settings), 'accept' (the offer's STORED basis,
     *    quote_start, deposit_percent, quote_fee_class and quote_amount /
     *    deposit_target: ONLY the other places can move the result, so with
     *    none counted the sent figures come back unchanged, and with some
     *    the sum is priced on the stored class; the player's live fee class
     *    and this place's live limit are never read) or 'real' (the real fee
     *    on the player's live class whatever the stored basis: the
     *    fee-release drop; stored start and percent)
     *  - fees: a TeamOversight_Fees to price with (give one with
     *    enable_rate_cache() when quoting a whole list)
     *
     * Returns array(ok, error_public, error_admin, basis, amount, fee_class,
     * start, percent, target, amount_cents, target_cents, places (labels
     * of the other places counted), mode). error_public is safe for coaches
     * (no class, no amount); error_admin names the class and role.
     */
    public static function compute_quote($user_id, $season, $team, $role, $opts = array()) {
        $mode = (isset($opts['mode']) && in_array($opts['mode'], array('send', 'accept', 'real'), true)) ? $opts['mode'] : 'send';
        $offer = isset($opts['offer']) ? $opts['offer'] : null;
        $out = array('ok' => false, 'error_public' => '', 'error_admin' => '', 'basis' => '', 'amount' => 0.0, 'fee_class' => '', 'start' => '',
            'percent' => 0.0, 'target' => 0.0, 'amount_cents' => 0, 'target_cents' => 0, 'places' => array(), 'mode' => $mode);
        if (!self::applies($season)) {
            $out['error_public'] = $out['error_admin'] = 'Offers apply from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.';
            return $out;
        }
        $user = intval($user_id) > 0 ? get_userdata(intval($user_id)) : false;
        if (!$user || !is_email($user->user_email)) {
            $out['error_public'] = $out['error_admin'] = 'This player\'s account can\'t be found, so no offer can be priced.';
            return $out;
        }
        $fees = (isset($opts['fees']) && $opts['fees'] instanceof TeamOversight_Fees) ? $opts['fees'] : self::fees();
        $settings = self::settings($season);
        $today = current_time('Y-m-d');

        if ($mode === 'real') {
            $basis = 'fee';
        } elseif ($mode === 'accept' && $offer && $offer->quote_basis) {
            $basis = $offer->quote_basis === 'fee' ? 'fee' : 'upper';
        } else {
            $basis = self::basis_now($season);
        }
        $stored = ($mode !== 'send' && $offer);
        $start = ($stored && $offer->quote_start) ? $offer->quote_start : $today;
        $percent = ($stored && $offer->deposit_percent !== null) ? floatval($offer->deposit_percent) : floatval($settings['percent']);

        $out['basis'] = $basis;
        $out['start'] = $start;
        $out['percent'] = $percent;
        $source = $basis === 'upper' ? TeamOversight_Fees::RATE_UPPER : TeamOversight_Fees::RATE_FEE;

        // Accept mode (3.5 of the design): the quote was FIXED when it was
        // sent, so only the player's other accepted or locked-in places may
        // move it. The player's live fee class, the live matrix limit for
        // THIS place and a later class change (a degree that ended since)
        // must not: with no other place counted the sent figures are returned
        // as they were, and with others the sum is priced on the class the
        // quote was made on.
        $fixed_class = null;
        if ($mode === 'accept' && $offer && $offer->quote_amount !== null && $offer->deposit_target !== null && (string) $offer->quote_fee_class !== '') {
            list($rows, $places) = self::quote_rows($user, $season, $team, $role, $start, $offer);
            $out['fee_class'] = (string) $offer->quote_fee_class;
            if (empty($places)) {
                $out['ok'] = true;
                $out['amount'] = round(floatval($offer->quote_amount), 2);
                $out['amount_cents'] = self::to_cents($out['amount']);
                $out['target_cents'] = self::to_cents($offer->deposit_target);
                $out['target'] = $out['target_cents'] / 100.0;
                return $out;
            }
            $fixed_class = self::person_class_for_cell($offer->quote_fee_class);
        } else {
            $check = self::cell_check($user_id, $season, $team, $role, $basis, $fees);
            $out['fee_class'] = $check['fee_class'];
            if (!$check['ok']) {
                $out['error_public'] = $check['error_public'];
                $out['error_admin'] = $check['error_admin'];
                return $out;
            }
            list($rows, $places) = self::quote_rows($user, $season, $team, $role, $start, $offer);
        }
        $breakdown = $fixed_class !== null
            ? $fees->fee_breakdown_for_class($season, $rows, $fixed_class, $source)
            : $fees->fee_breakdown($user->user_email, $season, $rows, $source);
        $priced = false;
        if (is_array($breakdown)) {
            foreach ($breakdown['stretches'] as $s) {
                if ($s['top']) {
                    $priced = true;
                    break;
                }
            }
        }
        if (!$priced) {
            $out['error_public'] = 'This offer can\'t be priced yet: check the season dates and the player\'s start.';
            $out['error_admin'] = 'The fee engine priced nothing for ' . $team . ' (' . $out['fee_class'] . ' / ' . $role . '): check the ' . $season . ' season dates, the late-join cutoff and the fee matrix.';
            return $out;
        }
        $out['ok'] = true;
        $out['amount'] = round(floatval($breakdown['total']), 2);
        $out['amount_cents'] = self::to_cents($out['amount']);
        $out['target_cents'] = self::target_cents($out['amount'], $percent);
        $out['target'] = $out['target_cents'] / 100.0;
        $out['places'] = $places;
        return $out;
    }

    // ------------------------------------------------------------------
    // Readers (pure: they never write)
    // ------------------------------------------------------------------

    private static function normalise($row) {
        if (!$row) {
            return null;
        }
        $row = (object) $row;
        foreach (array('id', 'application_id', 'extended_count', 'reminder_count') as $c) {
            $row->$c = isset($row->$c) ? intval($row->$c) : 0;
        }
        foreach (array('user_id', 'live', 'requested_by', 'decided_by', 'invoice_id', 'place_id', 'closed_by', 'settled_by') as $c) {
            $row->$c = (isset($row->$c) && $row->$c !== null) ? intval($row->$c) : null;
        }
        foreach (array('quote_amount', 'deposit_percent', 'deposit_target', 'quote_released', 'cap_logged', 'settled_amount') as $c) {
            $row->$c = (isset($row->$c) && $row->$c !== null) ? floatval($row->$c) : null;
        }
        return $row;
    }

    private static function normalise_all($rows) {
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = self::normalise($row);
        }
        return $out;
    }

    /** One offer by id (normalised row: ints, floats, null where unset), or null. */
    public static function get($id) {
        global $wpdb;
        if (intval($id) <= 0) {
            return null;
        }
        return self::normalise($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', intval($id))));
    }

    /**
     * Every live (not finished) offer behind an application, any team, oldest
     * first (1.77.0): the admin guards on Accept, Reject and Delete use it.
     * A pure read; one indexed query, nothing for a site that never made an offer.
     */
    public static function live_for_application($application_id) {
        global $wpdb;
        if (intval($application_id) <= 0) {
            return array();
        }
        return self::normalise_all($wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE application_id = %d AND live = 1 ORDER BY id',
            intval($application_id)
        )));
    }

    /**
     * The applications whose player holds a place through an offer (1.80.0,
     * for the "not selected" email): application id => list of team codes
     * with a live ACCEPTED or LOCKED-IN offer. The player knows of it (they
     * accepted it themselves), so TeamOversight_Not_Selected may leave such a
     * player out of its nagging counts and drop the "other ways in" from the
     * email; it never names the team. One indexed query; a pure read.
     */
    public static function places_held(array $application_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $application_ids))));
        $out = array();
        if (empty($ids)) {
            return $out;
        }
        $rows = $wpdb->get_results('SELECT application_id, team FROM ' . self::table() . ' WHERE application_id IN (' . implode(',', $ids) . ") AND live = 1 AND state IN ('" . self::ACCEPTED . "', '" . self::LOCKED_IN . "') ORDER BY id");
        foreach ((array) $rows as $row) {
            $out[intval($row->application_id)][] = (string) $row->team;
        }
        return $out;
    }

    /**
     * Payment Management's tags for a season (1.77.0), from ONE query:
     * array('by_user' => user id => tags, 'by_email' => lower email => tags),
     * a tag being 'deposit' (a live accepted offer: the invoice is still the
     * holding invoice for its deposit), 'held' (a finished offer whose deposit
     * is held as credit or still to be settled) or 'forfeited'. Accounts are
     * matched by id; the email map is for offers whose account is gone. Never
     * a dollar figure. Pure read.
     */
    public static function invoice_tags($season) {
        global $wpdb;
        $out = array('by_user' => array(), 'by_email' => array());
        if (!self::applies((string) $season)) {
            return $out;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, email, state, live, settlement FROM ' . self::table() . " WHERE season = %s AND (state = 'accepted' OR settlement IN ('pending', 'credit', 'forfeited'))",
            (string) $season
        ));
        foreach ((array) $rows as $row) {
            $tags = array();
            if ($row->state === self::ACCEPTED && intval($row->live) === 1) {
                $tags['deposit'] = true;
            }
            if ($row->settlement === 'forfeited') {
                $tags['forfeited'] = true;
            } elseif ($row->settlement === 'pending' || $row->settlement === 'credit') {
                $tags['held'] = true;
            }
            if (!$tags) {
                continue;
            }
            if (intval($row->user_id) > 0) {
                $out['by_user'][intval($row->user_id)] = array_merge(isset($out['by_user'][intval($row->user_id)]) ? $out['by_user'][intval($row->user_id)] : array(), $tags);
            } elseif ((string) $row->email !== '') {
                $key = strtolower((string) $row->email);
                $out['by_email'][$key] = array_merge(isset($out['by_email'][$key]) ? $out['by_email'][$key] : array(), $tags);
            }
        }
        return $out;
    }

    /** The live (not finished) offer for an application and team, or null. */
    public static function live_for($application_id, $team) {
        global $wpdb;
        return self::normalise($wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE application_id = %d AND team = %s AND live = 1 ORDER BY id DESC LIMIT 1',
            intval($application_id), (string) $team
        )));
    }

    /**
     * The SQL condition Finalise's three queries add from 2027 (its $selected
     * query and both button counts on Trial Applications; keep them in step):
     * the verdict, aliased $alias, has a LOCKED-IN offer behind it (the one
     * live offer of that application and team, so no offer still out can be
     * placed from under the player). From 2027 Finalise is only the retry
     * for a placement that failed (1.79.0: there is no coach-recorded
     * acceptance any more), so offer_accepted = 1, the mirror of a locked-in
     * offer that reconcile_state() keeps true, is not enough on its own: a
     * stray flag with no locked-in offer is never placed. Returns
     * ' AND EXISTS (...)'.
     */
    public static function finalise_guard_sql($alias = 's') {
        $alias = preg_match('/^[a-z_][a-z0-9_]*$/i', (string) $alias) ? (string) $alias : 's';
        return " AND EXISTS (SELECT 1 FROM " . self::table() . " o_live WHERE o_live.application_id = $alias.application_id AND o_live.team = $alias.team AND o_live.live = 1 AND o_live.state = '" . self::LOCKED_IN . "')";
    }

    /** The latest offer (live or finished) for an application and team, or null. */
    public static function latest_for($application_id, $team) {
        global $wpdb;
        return self::normalise($wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE application_id = %d AND team = %s ORDER BY (live = 1) DESC, id DESC LIMIT 1',
            intval($application_id), (string) $team
        )));
    }

    /**
     * Offers for many applications in one query: application id => team =>
     * offer, a live offer winning over the latest finished one. A
     * 'cancelled' latest offer is returned too (treat it as "no offer").
     */
    public static function index_for_applications(array $application_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $application_ids))));
        $index = array();
        if (empty($ids)) {
            return $index;
        }
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE application_id IN (' . implode(',', $ids) . ') ORDER BY id');
        foreach ((array) $rows as $row) {
            $offer = self::normalise($row);
            $current = isset($index[$offer->application_id][$offer->team]) ? $index[$offer->application_id][$offer->team] : null;
            // Later rows win, except that a finished offer never replaces a live one.
            if ($current && $current->live === 1 && $offer->live !== 1) {
                continue;
            }
            $index[$offer->application_id][$offer->team] = $offer;
        }
        return $index;
    }

    /** A team's offers for a season, application id => offer (live wins over the latest finished). One query. */
    public static function index_for_team($team, $season) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE team = %s AND season = %s ORDER BY id', (string) $team, (string) $season));
        $index = array();
        foreach ((array) $rows as $row) {
            $offer = self::normalise($row);
            $current = isset($index[$offer->application_id]) ? $index[$offer->application_id] : null;
            if ($current && $current->live === 1 && $offer->live !== 1) {
                continue;
            }
            $index[$offer->application_id] = $offer;
        }
        return $index;
    }

    /** A player's offers (newest first), optionally for one season and/or only some states. Matched by account id. */
    public static function for_user($user_id, $season = null, $states = null) {
        global $wpdb;
        $where = array($wpdb->prepare('user_id = %d', intval($user_id)));
        if ($season !== null) {
            $where[] = $wpdb->prepare('season = %s', (string) $season);
        }
        if (is_array($states) && !empty($states)) {
            $where[] = 'state IN (' . implode(',', array_map(function ($s) {
                return "'" . esc_sql((string) $s) . "'";
            }, $states)) . ')';
        }
        return self::normalise_all($wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC'));
    }

    /**
     * A page of offers for the admin lists (the only generic query: no
     * other class runs SQL on team_offers). $args: season, states (list),
     * team, user_id, order ('oldest' = id ascending, the default, or
     * 'newest'), limit (1-500, default 40), offset. Returns array(rows
     * (normalised offers), total (matching rows, before limit)).
     */
    public static function list_offers(array $args = array()) {
        global $wpdb;
        $where = array('1 = 1');
        if (isset($args['season']) && preg_match('/^\d{4}$/D', (string) $args['season']) === 1) {
            $where[] = $wpdb->prepare('season = %s', (string) $args['season']);
        }
        if (!empty($args['states']) && is_array($args['states'])) {
            $valid = array_values(array_intersect(array_map('strval', $args['states']), array_keys(self::states())));
            $where[] = empty($valid) ? '1 = 0' : 'state IN (' . implode(',', array_map(function ($s) {
                return "'" . esc_sql($s) . "'";
            }, $valid)) . ')';
        }
        if (!empty($args['team'])) {
            $where[] = $wpdb->prepare('team = %s', (string) $args['team']);
        }
        if (!empty($args['user_id'])) {
            $where[] = $wpdb->prepare('user_id = %d', intval($args['user_id']));
        }
        $limit = isset($args['limit']) ? max(1, min(500, intval($args['limit']))) : 40;
        $offset = isset($args['offset']) ? max(0, intval($args['offset'])) : 0;
        $order = (isset($args['order']) && $args['order'] === 'newest') ? 'id DESC' : 'id ASC';
        $sql_where = implode(' AND ', $where);
        $total = intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $sql_where));
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' WHERE ' . $sql_where . " ORDER BY $order LIMIT $limit OFFSET $offset");
        return array('rows' => self::normalise_all($rows), 'total' => $total);
    }

    /** How many offers are in each state for a season: state => count (every state present, 0 when none). */
    public static function counts_by_state($season) {
        global $wpdb;
        $out = array_fill_keys(array_keys(self::states()), 0);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT state, COUNT(*) AS n FROM ' . self::table() . ' WHERE season = %s GROUP BY state', (string) $season));
        foreach ((array) $rows as $row) {
            if (isset($out[$row->state])) {
                $out[$row->state] = intval($row->n);
            }
        }
        return $out;
    }

    /**
     * The seasons that have offers needing the committee or the players
     * now (1.79.1): season => array(awaiting, out) where awaiting counts
     * requests awaiting approval and out counts sent and accepted offers,
     * for every season with at least one of either, oldest season first.
     * ONE grouped query. The Offers page opens on the first season here
     * (TeamOversight_Offers_Admin::default_season()) and says how many
     * requests wait in the seasons it is not showing.
     */
    public static function active_by_season() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT season, state, COUNT(*) AS n FROM ' . self::table() . " WHERE state IN ('awaiting_approval', 'sent', 'accepted') GROUP BY season, state");
        $out = array();
        foreach ((array) $rows as $row) {
            $season = (string) $row->season;
            if (!preg_match('/^\d{4}$/D', $season)) {
                continue;
            }
            if (!isset($out[$season])) {
                $out[$season] = array('awaiting' => 0, 'out' => 0);
            }
            $out[$season][$row->state === self::AWAITING ? 'awaiting' : 'out'] += intval($row->n);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** What a player may see: their offers in the visible states. */
    public static function visible_for_player($user_id, $season = null) {
        return self::for_user($user_id, $season, self::PLAYER_VISIBLE);
    }

    /** One player's offer by id, only if it is theirs (SQL ownership by account id, never email). */
    public static function get_for_user($offer_id, $user_id) {
        global $wpdb;
        if (intval($offer_id) <= 0 || intval($user_id) <= 0) {
            return null;
        }
        return self::normalise($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d AND user_id = %d', intval($offer_id), intval($user_id))));
    }

    /** The ledger total in cents per invoice id (one grouped query). */
    public static function paid_map(array $invoice_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoice_ids))));
        $map = array();
        foreach ($ids as $id) {
            $map[$id] = 0;
        }
        if (empty($ids)) {
            return $map;
        }
        $rows = $wpdb->get_results("SELECT invoice_id, ROUND(SUM(amount) * 100) AS cents FROM {$wpdb->prefix}team_invoice_payments WHERE invoice_id IN (" . implode(',', $ids) . ') GROUP BY invoice_id');
        foreach ((array) $rows as $row) {
            $map[intval($row->invoice_id)] = (int) $row->cents;
        }
        return $map;
    }

    /**
     * Where an offer stands against its deadlines, read at the time (nothing
     * is stored): 'reply' when it is sent and today is after reply_by,
     * 'deposit' when it is accepted and today is after deposit_due, else
     * null. Overdue means strictly after the date. Returns array(kind,
     * days_late, date, overdue). Nothing is ever released automatically.
     */
    public static function deadline_status($offer, $today = null) {
        $out = array('kind' => null, 'days_late' => 0, 'date' => null, 'overdue' => false);
        if (!$offer) {
            return $out;
        }
        $today = $today !== null && TeamOversight_Fee_Timeline::parse_date((string) $today) !== null ? (string) $today : current_time('Y-m-d');
        $kind = null;
        $date = null;
        if ($offer->state === self::SENT && $offer->reply_by) {
            $kind = 'reply';
            $date = $offer->reply_by;
        } elseif ($offer->state === self::ACCEPTED && $offer->deposit_due) {
            $kind = 'deposit';
            $date = $offer->deposit_due;
        }
        if ($kind === null || TeamOversight_Fee_Timeline::parse_date($date) === null || $today <= $date) {
            return $out;
        }
        return array('kind' => $kind, 'days_late' => TeamOversight_Fee_Timeline::days_between($date, $today), 'date' => $date, 'overdue' => true);
    }

    /** The offer's stored deposit target in cents. */
    public static function stored_target_cents($offer) {
        return ($offer && $offer->deposit_target !== null) ? self::to_cents($offer->deposit_target) : 0;
    }

    /**
     * Deposit money, in cents, that ending this offer NOW would put at
     * stake: the credit on the player's season invoice that belongs to THIS
     * offer. The player has ONE season invoice, so its credit is not
     * automatically this offer's:
     *  - only an offer that was accepted can have had a deposit paid toward
     *    it (nothing can be paid before accepting), so an awaiting, sent or
     *    already finished offer holds nothing;
     *  - when the player has another live accepted or locked-in offer in the
     *    season the money is that place's (settle() marks this one
     *    'carried'), so nothing is at stake here either;
     *  - otherwise it is the invoice's credit (held_credit_cents()).
     * It decides settlement = 'pending' in the ending UPDATE, the coach's
     * money_held gate and the emails' first reading of what happened (before
     * settle() reports what it really did).
     */
    public static function held_for_offer($offer) {
        if (!$offer || !in_array($offer->state, array(self::ACCEPTED, self::LOCKED_IN), true)) {
            return 0;
        }
        $held = self::held_credit_cents($offer);
        if ($held <= 0) {
            return 0;
        }
        foreach (self::for_user($offer->user_id, $offer->season, array(self::ACCEPTED, self::LOCKED_IN)) as $other) {
            if ($other->id !== $offer->id) {
                return 0;
            }
        }
        return $held;
    }

    /**
     * What settle() really did, in cents: the amount it forfeited or
     * credited (its result's 'amount_cents', see the API's section 6.10), or
     * null when it did not decide (an error, a settlement still pending) so
     * the caller keeps the figure it read before settling.
     */
    private static function settled_cents($settled) {
        if (is_array($settled) && !empty($settled['ok']) && isset($settled['amount_cents'])) {
            return max(0, intval($settled['amount_cents']));
        }
        return null;
    }

    /** The menu badge: how many offers await approval (a cached option, no query on a normal page). */
    public static function pending_count() {
        return max(0, intval(get_option(self::BADGE_OPTION, 0)));
    }

    /** Recount offers awaiting approval and store it (every transition and the daily job call this). */
    public static function refresh_badge() {
        global $wpdb;
        $n = intval($wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE state = 'awaiting_approval'"));
        if ($n !== self::pending_count() || get_option(self::BADGE_OPTION, null) === null) {
            update_option(self::BADGE_OPTION, $n, false);
        }
        return $n;
    }

    /** A menu title with the count badge, e.g. "Offers <span class="awaiting-mod">3</span>" (the badge counts offers awaiting approval). */
    public static function menu_title($title = 'Offers') {
        $n = self::pending_count();
        return $n > 0 ? esc_html($title) . ' <span class="awaiting-mod">' . intval($n) . '</span>' : esc_html($title);
    }

    // ------------------------------------------------------------------
    // Wording (the one place card, chip, CSV, trial-book and admin text comes from)
    // ------------------------------------------------------------------

    /**
     * A player's offer state in words, as plain text (callers escape).
     * Never contains a dollar figure: coaches see percentages only.
     *
     * $offer: the offer (null = none, a 'cancelled' one counts as none).
     * $context: 'card' (the status line on a coach card), 'csv' (the Status
     * column of the team list export) or 'plain' (the trial book). $extra:
     * verdict ('selected', 'training_only', ...), season (always pass it),
     * paid_cents (the ledger total on the player's season invoice, for the
     * deposit percentage only), today.
     *
     * For a season before 2027 it returns the 1.75.3 wording exactly as
     * chip() does: the card line is '' (1.75.3 had none), the CSV Status
     * column 'Selected (awaiting finalisation)', the trial book 'Selected'.
     */
    public static function label($offer, $context = 'card', $extra = array()) {
        $verdict = isset($extra['verdict']) ? (string) $extra['verdict'] : '';
        $labels = TeamOversight_Coach_Portal::get_verdict_labels();
        $vlabel = isset($labels[$verdict]) ? $labels[$verdict] : '';
        $season = isset($extra['season']) ? (string) $extra['season'] : (($offer && isset($offer->season)) ? (string) $offer->season : '');
        if ($season !== '' && !self::applies($season)) {
            return self::legacy_label($context, $verdict);
        }
        $today = isset($extra['today']) ? $extra['today'] : current_time('Y-m-d');
        $card = ($context === 'card');
        $pre = function ($text) use ($card, $vlabel) {
            return ($card || $vlabel === '') ? $text : $vlabel . ' - ' . $text;
        };
        if (!$offer || $offer->state === self::CANCELLED) {
            return in_array($verdict, array('selected', 'training_only'), true) ? $vlabel . ' - offer not sent' : $vlabel;
        }
        $d = function ($date) {
            return self::fmt_date($date);
        };
        $status = self::deadline_status($offer, $today);
        // An Early Offer (quoted on the upper limit, sent before fees are out) says so on the card, in the CSV and in the trial book. Wording only: never a figure.
        $early = self::is_early($offer);
        switch ($offer->state) {
            case self::AWAITING:
                return $pre($early ? 'Early Offer awaiting committee approval' : 'Awaiting committee approval');
            case self::NOT_APPROVED:
                $note = trim((string) $offer->closed_reason);
                return $pre('Not approved' . (($card && $note !== '') ? ': ' . $note : ''));
            case self::WITHDRAWN:
                return $pre($early ? 'Early Offer withdrawn' : 'Offer withdrawn');
            case self::SENT:
                if ($status['overdue']) {
                    if ($early) {
                        return $card ? 'Overdue - no reply to the Early Offer (was due ' . $d($status['date']) . ')' : $pre('Early Offer sent, no reply (was due ' . $d($status['date']) . ')');
                    }
                    return $card ? 'Overdue - no reply (was due ' . $d($status['date']) . ')' : $pre('offer sent, no reply (was due ' . $d($status['date']) . ')');
                }
                if ($early) {
                    return $card ? 'Early Offer sent - reply by ' . $d($offer->reply_by) : $pre('Early Offer sent (reply by ' . $d($offer->reply_by) . ')');
                }
                return $card ? 'Offer sent - reply by ' . $d($offer->reply_by) : $pre('offer sent (reply by ' . $d($offer->reply_by) . ')');
            case self::ACCEPTED:
                if ($status['overdue']) {
                    if ($early) {
                        return $card ? 'Overdue - Early Offer deposit not complete (was due ' . $d($status['date']) . ')' : $pre('Early Offer accepted, deposit overdue (was due ' . $d($status['date']) . ')');
                    }
                    return $card ? 'Overdue - deposit not complete (was due ' . $d($status['date']) . ')' : $pre('accepted, deposit overdue (was due ' . $d($status['date']) . ')');
                }
                if ($card) {
                    $target = self::stored_target_cents($offer);
                    $lead = $early ? 'Early Offer accepted' : 'Accepted';
                    if (isset($extra['paid_cents']) && $extra['paid_cents'] !== null) {
                        $pct = $target > 0 ? min(100, (int) floor(max(0, intval($extra['paid_cents'])) * 100 / $target)) : 100;
                        return $lead . ' - deposit ' . $pct . '% paid, due by ' . $d($offer->deposit_due);
                    }
                    return $lead . ' - deposit due by ' . $d($offer->deposit_due);
                }
                return $pre(($early ? 'Early Offer accepted' : 'accepted') . ', deposit due ' . $d($offer->deposit_due));
            case self::LOCKED_IN:
                if ($card) {
                    return $offer->placed_at ? 'Locked in ' . $d(substr((string) $offer->locked_in_at, 0, 10)) : 'Locked in - the club is placing them';
                }
                return 'Locked in';
            case self::DECLINED:
                return $card ? 'Declined by the player ' . $d(substr((string) $offer->declined_at, 0, 10)) : 'Declined (player)';
            case self::MOVED_DECLINED:
                return $card ? 'Moved to declined ' . $d(substr((string) $offer->closed_at, 0, 10)) : 'Declined (moved by coach)';
            case self::LEFT:
                return 'Left the team';
        }
        return $vlabel;
    }

    /**
     * The 1.75.3 wording behind label() for a season before 2027 (which
     * ignores offers): the roster CSV's Status column and the trial book's
     * verdict text. The card line did not exist then: ''.
     */
    private static function legacy_label($context, $verdict) {
        if ($context === 'card') {
            return '';
        }
        $labels = TeamOversight_Coach_Portal::get_verdict_labels();
        $vlabel = isset($labels[$verdict]) ? $labels[$verdict] : ucfirst($verdict);
        if ($context === 'csv') {
            $csv = array('selected' => 'Selected (awaiting finalisation)', 'training_only' => 'Training Only (awaiting finalisation)', 'tentative' => 'Tentative');
            return isset($csv[$verdict]) ? $csv[$verdict] : ucfirst($verdict);
        }
        return $vlabel;
    }

    /**
     * The short verdict chip for an applicant, e.g. "Selected · offer sent".
     * Never a dollar figure. For a season before 2027 (pass
     * $extra['season']) it returns the 1.75.3 wording: the verdict alone.
     */
    public static function chip($offer, $verdict, $extra = array()) {
        $labels = TeamOversight_Coach_Portal::get_verdict_labels();
        $vlabel = isset($labels[$verdict]) ? $labels[$verdict] : ucfirst((string) $verdict);
        $season = isset($extra['season']) ? $extra['season'] : '';
        if (!self::applies($season)) {
            return $vlabel;
        }
        if (!in_array($verdict, array('selected', 'training_only'), true)) {
            return $vlabel;
        }
        $today = isset($extra['today']) ? $extra['today'] : current_time('Y-m-d');
        if (!$offer || $offer->state === self::CANCELLED) {
            return $vlabel . ' · offer not sent';
        }
        $status = self::deadline_status($offer, $today);
        $short = array(
            self::AWAITING => 'awaiting approval', self::NOT_APPROVED => 'not approved', self::WITHDRAWN => 'offer withdrawn',
            self::SENT => 'offer sent', self::ACCEPTED => 'accepted', self::LOCKED_IN => 'locked in',
            self::DECLINED => 'declined', self::MOVED_DECLINED => 'declined', self::LEFT => 'left the team',
        );
        // An Early Offer is named as one while it is still being answered (wording only, no figure).
        if (self::is_early($offer)) {
            $short[self::AWAITING] = 'Early Offer awaiting approval';
            $short[self::SENT] = 'Early Offer sent';
            $short[self::ACCEPTED] = 'Early Offer accepted';
            $short[self::WITHDRAWN] = 'Early Offer withdrawn';
        }
        $text = isset($short[$offer->state]) ? $short[$offer->state] : $offer->state;
        if ($status['overdue']) {
            $text .= ' (overdue)';
        }
        return $vlabel . ' · ' . $text;
    }

    /**
     * The fee as the player is told it, in plain text (one source for the
     * email's {fee} and the offer page): "up to $612.00, set in January"
     * for an offer quoted on the upper limit while fees are not released,
     * the real fee ("$480.00") for an offer quoted on the real fee, and for
     * an upper-limit offer once the real fee is known (quote_released) that
     * real fee. After release with no real fee worked out yet it reads
     * "up to $612.00". Empty before the offer is sent.
     */
    public static function fee_words($offer) {
        if (!$offer || $offer->quote_amount === null) {
            return '';
        }
        $quoted = self::money($offer->quote_amount);
        if ($offer->quote_basis !== 'upper') {
            return $quoted;
        }
        if ($offer->quote_released !== null) {
            return self::money($offer->quote_released);
        }
        return TeamOversight_Fees::fees_released($offer->season)
            ? 'up to ' . $quoted
            : self::wording('fee_upto_unset', array('{limit}' => $quoted, '{fee_month}' => self::fee_month($offer->season)));
    }

    /**
     * The address a player is told to write to about their offer ({club_contact}
     * in the offer wording): the one saved on the Emails page's Sender box,
     * else the trial applications address (the Reply-To of the trial emails),
     * else ''.
     */
    public static function club_contact() {
        $saved = sanitize_email((string) get_option(self::CONTACT_OPTION, ''));
        if ($saved !== '') {
            return $saved;
        }
        return class_exists('TeamOversight_Trial_Emails') ? (string) TeamOversight_Trial_Emails::get_reply_to() : '';
    }

    /**
     * The one reader of the wording that is not an email body (1.78.0): the
     * Early Offer explanation, the money policy, the offer emails' sentences,
     * the lines of the offer page and the coach portal's words. The text in
     * force (the administrator's, else the built-in text) with $vars
     * ('{placeholder}' => value) filled in. See TeamOversight_Offer_Wording.
     */
    public static function wording($key, array $vars = array()) {
        return TeamOversight_Offer_Wording::text($key, $vars);
    }

    /**
     * The month a season's fees are released in, for "set in January": taken
     * from the season's fee release date, else January (the built-in
     * assumption). Plain text.
     */
    public static function fee_month($season = null) {
        $date = ($season !== null && (string) $season !== '') ? TeamOversight_Fees::get_fee_release_date((string) $season) : '';
        $valid = $date !== '' ? TeamOversight_Fee_Timeline::parse_date((string) $date) : null;
        return $valid === null ? 'January' : date('F', strtotime($valid));
    }

    // ------------------------------------------------------------------
    // Early Offers (one place decides, every screen and email asks it)
    // ------------------------------------------------------------------

    /**
     * Is this an Early Offer? An offer whose quote is on the fee matrix
     * UPPER LIMIT, because it was sent before the season's fees were
     * released (quote_basis 'upper'), is called an Early Offer (club
     * decision, 28 Oct 2026). It stays one after the fees are released: it was quoted on the
     * limit. An offer sent after release quotes the real fee and is just an
     * offer. An offer nobody has approved yet has no quote, so it is judged
     * as approving it now would quote it (basis_now()), which is what the
     * coach's card and the Offers to approve page show before the committee
     * acts. False for a season before 2027 and for anything that is not an
     * offer. EVERY screen and email takes its wording from this function
     * (and offer_name() / early_note()); none of them reads quote_basis for
     * wording itself.
     */
    public static function is_early($offer) {
        if (!is_object($offer)) {
            return false;
        }
        $season = isset($offer->season) ? (string) $offer->season : '';
        if ($season === '' || !self::applies($season)) {
            return false;
        }
        $basis = isset($offer->quote_basis) ? (string) $offer->quote_basis : '';
        if ($basis === 'upper') {
            return true;
        }
        if ($basis === 'fee') {
            return false;
        }
        return self::basis_now($season) === 'upper';
    }

    /**
     * What to call the offer in a sentence: 'Early Offer' for an Early
     * Offer, else 'offer' ('Offer' when $capitalised). Plain text, no figure.
     */
    public static function offer_name($offer, $capitalised = false) {
        if (self::is_early($offer)) {
            return 'Early Offer';
        }
        return $capitalised ? 'Offer' : 'offer';
    }

    /**
     * The plain-words explanation of an Early Offer, for the offer page and
     * the email's {early_note} ('' when it is not one). $limit is the upper
     * limit quoted (dollars), $percent the deposit percent, $real the real
     * season fee once it is known for this offer (null while it is not),
     * $released whether the season's fees have been released, $deposit false
     * for a no-deposit offer. Pure.
     *
     * Before the fee is known: because it is an Early Offer the season fee
     * isn't set yet; it is set in January and won't be more than the upper
     * limit; the deposit is the percent of that limit and comes off the
     * final fee.
     */
    public static function early_note_text($limit, $percent, $real = null, $released = false, $deposit = true, $season = null) {
        $limit_words = self::money($limit);
        // The wording is the administrator's (Emails -> Offers and selection -> Offer wording); {fee_month} comes from the season's fee release date.
        $vars = array('{limit}' => $limit_words, '{fee_month}' => self::fee_month($season));
        if ($real !== null) {
            $vars['{real_fee}'] = self::money($real);
            return self::wording('early_set', $vars) . ($deposit ? ' ' . self::wording('early_set_deposit', $vars) : '');
        }
        $pct = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
        $vars['{percent}'] = ($pct === '' ? '0' : $pct);
        $text = self::wording($released ? 'early_releasing' : 'early_unset', $vars);
        if ($deposit) {
            $text .= ' ' . self::wording('early_deposit', $vars);
        }
        return $text;
    }

    /**
     * early_note_text() for a real offer: '' unless it is an Early Offer
     * with a quote. Reads the offer only (the release date for the wording).
     */
    public static function early_note($offer) {
        if (!self::is_early($offer) || !isset($offer->quote_amount) || $offer->quote_amount === null) {
            return '';
        }
        $percent = isset($offer->deposit_percent) && $offer->deposit_percent !== null ? floatval($offer->deposit_percent) : 0.0;
        $has_deposit = isset($offer->deposit_target) && $offer->deposit_target !== null ? self::to_cents($offer->deposit_target) > 0 : $percent > 0;
        $real = (isset($offer->quote_released) && $offer->quote_released !== null) ? floatval($offer->quote_released) : null;
        return self::early_note_text(floatval($offer->quote_amount), $percent, $real, TeamOversight_Fees::fees_released($offer->season), $has_deposit, $offer->season);
    }

    /**
     * How many live offers (sent, accepted or locked in) were quoted on the
     * upper limit of one fee-matrix cell. Changing that limit later never
     * moves them (each offer stores its own quote), so the Fee Matrix save
     * says so. A pure read; the only place outside the offers code that asks.
     */
    public static function quoted_on_limit_count($season, $fee_class, $team_role) {
        global $wpdb;
        $roles = $team_role === 'training_only' ? array('training_only') : array('playing_member', 'team_manager');
        $in = "'" . implode("','", array_map('esc_sql', $roles)) . "'";
        $live = "'" . implode("','", array(self::SENT, self::ACCEPTED, self::LOCKED_IN)) . "'";
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE season = %s AND quote_basis = 'upper' AND quote_fee_class = %s AND role IN ($in) AND state IN ($live)",
            (string) $season, (string) $fee_class
        )));
    }

    /** The Trials & Team Info page for a season: the per-season override (option season => url), else the existing Training info page URL setting, else ''. */
    public static function training_url($season) {
        $all = get_option(self::TRAINING_URLS_OPTION, array());
        if (is_array($all) && !empty($all[(string) $season]) && is_string($all[(string) $season])) {
            return esc_url_raw($all[(string) $season]);
        }
        $default = get_option('team_oversight_training_info_url');
        return is_string($default) ? esc_url_raw($default) : '';
    }

    // ------------------------------------------------------------------
    // Terms (the commitments and deposit policy, one source for email and page)
    // ------------------------------------------------------------------

    /**
     * The built-in wording: group => array(title, items). Spec text word for
     * word, plus the deposit group (a design addition). The two money-policy
     * sentences come from the wording registry (TeamOversight_Offer_Wording,
     * policy_club / policy_player), so the commitments, the withdrawal and
     * decline notes and the finished-offer lines all quote the same policy.
     * (1.78.0: the deposit group no longer repeats the club's "you can have
     * your deposit back" point, which the first list already makes.)
     */
    public static function terms_defaults() {
        return array(
            'club' => array(
                'title' => 'Our commitment to you',
                'items' => array(
                    'Training with expert coaches, built around improving your game.',
                    'Fair treatment under our code of conduct.',
                    'A say in your role and position through the season.',
                    self::wording('policy_club'),
                ),
            ),
            'player' => array(
                'title' => 'Your commitment to the team',
                'items' => array(
                    'Register with Volleyball Victoria and keep your club profile up to date.',
                    'Pay your club fees as they fall due.',
                    'Follow the Volleyball Victoria, University of Melbourne and club codes of conduct.',
                    'Come to training regularly and be available on game days.',
                    'Get yourself to training and games. We can help organise carpools, but we can\'t guarantee them.',
                    'Understand that roles and positions can change during the season, depending on what the team needs.',
                    'If you pull out after accepting, your deposit isn\'t refunded.',
                ),
            ),
            'deposit' => array(
                'title' => 'Your deposit and fee',
                'items' => array(
                    'Your deposit comes off your season fee. If you pay more than your final fee, the extra stays on your account as credit.',
                    self::wording('policy_player'),
                    'If your offer says "up to", that figure is a maximum: your fee won\'t be higher than it.',
                ),
            ),
        );
    }

    /** The terms in force: the saved wording (one item per line) over the built-in; blank = built in. group => array(title, items). */
    public static function terms() {
        $terms = self::terms_defaults();
        $saved = get_option(self::TERMS_OPTION, array());
        if (is_array($saved)) {
            foreach ($terms as $group => $def) {
                if (isset($saved[$group]) && is_string($saved[$group]) && trim($saved[$group]) !== '') {
                    $items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $saved[$group]))));
                    if (!empty($items)) {
                        $terms[$group]['items'] = $items;
                    }
                }
            }
        }
        return $terms;
    }

    // ------------------------------------------------------------------
    // The extra training / extra games line (club decision, 28 Oct 2026)
    //
    // The offer email, the offer page and the "you're locked in" email each
    // carry ONE short line, chosen by the player's age at the season start:
    // under 18 the Junior Academy ("Want extra training?"), 18 and over the
    // Reds Social League (extra games). Everyone gets exactly one. The two
    // pages are the pathway pages the "not selected" email already uses
    // (Emails -> Offer links); the wording is editable on the Emails page.
    // The Academy line is about extra training.
    // ------------------------------------------------------------------

    /** The saved wording of the line: array('junior' => text, 'adult' => text), each with {page} where the page's name and link go. Blank = built in. */
    const EXTRAS_OPTION = 'team_oversight_offer_extras';

    /** This age or older at the season start gets the extra games line; younger gets the extra training line. */
    const EXTRAS_ADULT_AGE = 18;

    /** The built-in wording: kind => text with {page}. */
    public static function extras_defaults() {
        return array(
            'junior' => 'Want extra training? Have a look at the {page}.',
            'adult' => 'Want extra games? Have a look at the {page}.',
        );
    }

    /** The wording in force: the saved text over the built-in (blank = built in). */
    public static function extras_wording() {
        $out = self::extras_defaults();
        $saved = get_option(self::EXTRAS_OPTION, array());
        if (is_array($saved)) {
            foreach ($out as $kind => $default) {
                if (isset($saved[$kind]) && is_string($saved[$kind]) && trim($saved[$kind]) !== '') {
                    $out[$kind] = trim($saved[$kind]);
                }
            }
        }
        return $out;
    }

    /** The day the season starts (Y-m-d): the configured season start, else 1 January of the season year. */
    private static function season_start_day($season) {
        $dates = TeamOversight_Fees::get_season_dates((string) $season);
        $start = ($dates && !empty($dates['start']) && is_string($dates['start'])) ? TeamOversight_Fee_Timeline::parse_date($dates['start']) : null;
        return $start !== null ? $start : sprintf('%04d-01-01', intval($season));
    }

    /**
     * 'junior' (under 18 at the season start) or 'adult' (18 and over). The
     * date of birth is the one on the player's profile; a player with none on
     * file counts as an adult (the Not selected email's rule too), so
     * everyone gets exactly one line.
     */
    public static function extras_kind($user_id, $season) {
        $raw = intval($user_id) > 0 ? get_user_meta(intval($user_id), 'birth_date', true) : '';
        $dob = TeamOversight_Fee_Timeline::parse_date(str_replace('/', '-', trim((string) $raw)));
        if ($dob === null) {
            return 'adult';
        }
        $cutoff = DateTime::createFromFormat('!Y-m-d', self::season_start_day($season), new DateTimeZone('UTC'));
        $cutoff->modify('-' . self::EXTRAS_ADULT_AGE . ' years');
        return $dob > $cutoff->format('Y-m-d') ? 'junior' : 'adult';
    }

    /**
     * The player's one extra line, or array() when the page it points to is
     * not published (a line with nothing to link is left out, never half
     * written). Returns array(kind ('junior'|'adult'), label (the page's
     * name), url, html (escaped, the page name linked), text (plain, with the
     * address after the name)).
     */
    public static function extras_line($user_id, $season) {
        return self::extras_for_kind(self::extras_kind($user_id, $season));
    }

    /**
     * The line for one kind ('junior' or 'adult'), in the shape extras_line()
     * returns. $preview true (the Emails page's previews and test sends) falls
     * back to the site address when the page isn't published, so a preview
     * always shows the whole line; a real email never does.
     */
    public static function extras_for_kind($kind, $preview = false) {
        $kind = $kind === 'junior' ? 'junior' : 'adult';
        $page = (class_exists('TeamOversight_Not_Selected') && method_exists('TeamOversight_Not_Selected', 'pathway_page_link'))
            ? TeamOversight_Not_Selected::pathway_page_link($kind === 'junior' ? 'junior_academy' : 'social_league')
            : null;
        if (!$page && $preview) {
            $page = array('label' => $kind === 'junior' ? 'Junior Academy' : 'Reds Social League', 'url' => home_url('/'));
        }
        if (!$page) {
            return array();
        }
        $template = self::extras_wording()[$kind];
        if (strpos($template, '{page}') === false) {
            $template = rtrim($template) . ' {page}';
        }
        $parts = explode('{page}', $template);
        $html = '';
        $text = '';
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            $html .= esc_html($part);
            $text .= $part;
            if ($i < $last) {
                $html .= '<a href="' . esc_url($page['url']) . '">' . esc_html($page['label']) . '</a>';
                $text .= $page['label'] . ' (' . $page['url'] . ')';
            }
        }
        return array('kind' => $kind, 'label' => $page['label'], 'url' => $page['url'], 'html' => $html, 'text' => $text);
    }

    // ------------------------------------------------------------------
    // Where an offer lives on the website
    // ------------------------------------------------------------------

    private static $page_url_cache = null;

    /** The page carrying [team_offer] (found by its shortcode so a moved page never breaks an email), else the override option, else /your-offer/. */
    public static function offer_page_url() {
        global $wpdb;
        if (self::$page_url_cache !== null) {
            return self::$page_url_cache;
        }
        $page_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('page', 'post') AND post_content LIKE '%[team_offer%'
            ORDER BY (post_type = 'page') DESC, ID LIMIT 1
        ");
        if ($page_id) {
            self::$page_url_cache = (string) get_permalink($page_id);
        } else {
            $override = trim((string) get_option(self::PAGE_URL_OPTION, ''));
            self::$page_url_cache = $override !== '' ? esc_url_raw($override) : home_url('/your-offer/');
        }
        return self::$page_url_cache;
    }

    /** The link to one offer (?offer=ID; the page itself carries fresh nonces, an email link never does). */
    public static function offer_url($offer) {
        $id = is_object($offer) ? intval($offer->id) : intval($offer);
        return add_query_arg('offer', $id, self::offer_page_url());
    }

    // ------------------------------------------------------------------
    // Who is copied: the team's coaches
    // ------------------------------------------------------------------

    /**
     * The team's coaches for an offer email (read at send time from the
     * live roster, so a Remind after a staff change reaches the right
     * people). Active coach and assistant_coach places for the season and
     * team, each resolved to the CURRENT account email by user_id (the
     * assignment's own email is a snapshot and can be stale); a row with no
     * resolvable account is skipped.
     *
     * Primary (Reply-To and the sign-off name): $prefer_user_id (the
     * requesting coach) if they hold a place on the team in one of
     * $prefer_roles (the offers: a coach place only; the "not selected"
     * email: coach or assistant coach, so whoever of the team pressed Send
     * is the voice); else the lowest-id coach place; else the lowest-id
     * assistant coach. Others: every other coach and assistant. A player
     * email's Cc is the primary PLUS these (TeamOversight_Offer_Emails::
     * copied_coaches(), 1.80.1), so the Reply-To coach is copied too. The
     * player's own account ($exclude_user_id) is never listed.
     *
     * Returns array(primary (array user_id, email, name, role) or null,
     * others (list of the same), all_emails (deduplicated, primary first),
     * none (bool: no coach on file), reply_to (the primary's email, else
     * the trial Reply-To), coach_name, notice (a line for the admin when
     * there is no coach on file)).
     */
    public static function team_contacts($team, $season, $prefer_user_id = 0, $exclude_user_id = 0, $prefer_roles = array('coach')) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT id, user_id, email, role FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND team = %s AND is_active = 1 AND role IN ('coach', 'assistant_coach')
            ORDER BY id
        ", (string) $season, (string) $team));
        $people = array();
        foreach ((array) $rows as $row) {
            $user = null;
            if (intval($row->user_id) > 0) {
                $user = get_userdata(intval($row->user_id));
            }
            if (!$user && intval($row->user_id) <= 0 && is_email($row->email)) {
                $user = get_user_by('email', $row->email);
            }
            if (!$user || !is_email($user->user_email) || intval($user->ID) === intval($exclude_user_id)) {
                continue;
            }
            $people[] = array('id' => intval($row->id), 'user_id' => intval($user->ID), 'email' => $user->user_email, 'name' => $user->display_name, 'role' => $row->role);
        }
        $primary = null;
        foreach ($people as $p) {
            if (in_array($p['role'], (array) $prefer_roles, true) && intval($prefer_user_id) > 0 && $p['user_id'] === intval($prefer_user_id)) {
                $primary = $p;
                break;
            }
        }
        if (!$primary) {
            foreach (array('coach', 'assistant_coach') as $role) {
                foreach ($people as $p) {
                    if ($p['role'] === $role) {
                        $primary = $p;
                        break 2;
                    }
                }
            }
        }
        $others = array();
        $emails = array();
        if ($primary) {
            $emails[strtolower($primary['email'])] = $primary['email'];
        }
        foreach ($people as $p) {
            if ($primary && $p['user_id'] === $primary['user_id']) {
                continue;
            }
            if (isset($emails[strtolower($p['email'])])) {
                continue;
            }
            $emails[strtolower($p['email'])] = $p['email'];
            $others[] = $p;
        }
        $fallback = class_exists('TeamOversight_Trial_Emails') ? TeamOversight_Trial_Emails::get_reply_to() : '';
        return array(
            'primary' => $primary,
            'others' => $others,
            'all_emails' => array_values($emails),
            'none' => !$primary,
            'reply_to' => $primary ? $primary['email'] : $fallback,
            'coach_name' => $primary ? $primary['name'] : 'The coaching panel',
            'notice' => $primary ? '' : 'No coach on file for ' . $team . ': replies will go to ' . ($fallback !== '' ? $fallback : 'the club address') . '.',
        );
    }

    // ------------------------------------------------------------------
    // Write helpers
    // ------------------------------------------------------------------

    /**
     * UPDATE offers SET ... WHERE ... (prepared). $set: column => value;
     * null writes NULL, ints %d, floats two decimals, an array('raw' => sql)
     * an expression (reminder_count + 1). Returns the rows changed (0 when
     * the compare-and-set lost). The columns are internal constants, never
     * request input.
     */
    private static function update_where(array $set, $where_sql, array $where_args = array()) {
        global $wpdb;
        $parts = array();
        $args = array();
        foreach ($set as $col => $val) {
            if ($val === null) {
                $parts[] = "`$col` = NULL";
            } elseif (is_array($val) && isset($val['raw'])) {
                $parts[] = "`$col` = " . $val['raw'];
            } elseif (is_int($val) || is_bool($val)) {
                $parts[] = "`$col` = %d";
                $args[] = (int) $val;
            } elseif (is_float($val)) {
                $parts[] = "`$col` = %s";
                $args[] = number_format($val, 2, '.', '');
            } else {
                $parts[] = "`$col` = %s";
                $args[] = (string) $val;
            }
        }
        $sql = $wpdb->prepare('UPDATE ' . self::table() . ' SET ' . implode(', ', $parts) . ' WHERE ' . $where_sql, array_merge($args, $where_args));
        $changed = $wpdb->query($sql);
        self::flush_cache();
        return $changed === false ? 0 : intval($changed);
    }

    /** The WHERE that pins an UPDATE to one offer (id, application, team, season) and the states it may be in. */
    private static function pin($offer, array $from_states) {
        $in = implode(',', array_map(function ($s) {
            return "'" . esc_sql((string) $s) . "'";
        }, $from_states));
        return array('id = %d AND application_id = %d AND team = %s AND season = %s AND state IN (' . $in . ')',
            array(intval($offer->id), intval($offer->application_id), (string) $offer->team, (string) $offer->season));
    }

    /**
     * End an offer in ONE compare-and-set UPDATE: the new state, live =
     * NULL, who / when / why it ended, and settlement = 'pending' when this
     * offer holds deposit money (held_for_offer(): an offer that was
     * accepted, net of the player's other live places; a request, a refused
     * request or an offer nobody accepted holds none, whatever else the
     * player's one season invoice carries), so a crash can never leave a
     * finished offer with its money outcome undecided. $from: the states it
     * may be in; the update is held to the state this $offer was READ in,
     * because the settlement above was decided about that state (an offer
     * accepted a moment later fails the compare-and-set and the caller says
     * "reload"). $extra: more columns (a caller that has already worked out
     * the money passes 'settlement' itself). Returns the rows changed (0 or 1).
     */
    private static function finish($offer, array $from_states, $new_state, $actor_id, $reason, array $extra = array()) {
        $set = array_merge(array(
            'state' => $new_state,
            'live' => null,
            'closed_by' => intval($actor_id) > 0 ? intval($actor_id) : null,
            'closed_at' => current_time('mysql'),
            'closed_reason' => $reason === '' ? null : self::cut((string) $reason, 255),
        ), $extra);
        if (!array_key_exists('settlement', $set)) {
            $set['settlement'] = self::held_for_offer($offer) > 0 ? 'pending' : '';
        }
        if (in_array($offer->state, $from_states, true)) {
            $from_states = array($offer->state);
        }
        list($where, $args) = self::pin($offer, $from_states);
        return self::update_where($set, $where, $args);
    }

    /**
     * An application is being deleted: detach its FINISHED offers
     * (application_id = 0) so nothing keeps pointing at an application id
     * that could be reused. Offer rows are NEVER deleted (they are the only
     * permanent record of a forfeit, a credit and who approved what), and
     * keep their own name, email, season and team snapshots. Refuses while
     * any offer for the application is live (the caller must refuse the
     * delete). Returns array(ok, detached (rows), live (live offers found)).
     */
    public static function detach_application($application_id) {
        global $wpdb;
        $id = intval($application_id);
        if ($id <= 0) {
            return array('ok' => false, 'detached' => 0, 'live' => 0);
        }
        $live = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE application_id = %d AND live = 1', $id)));
        if ($live > 0) {
            return array('ok' => false, 'detached' => 0, 'live' => $live);
        }
        $n = intval($wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET application_id = 0 WHERE application_id = %d AND live IS NULL', $id)));
        self::flush_cache();
        if ($n > 0) {
            TeamOversight_Log::add('offer_flow', 'Application deleted: ' . $n . ' finished offer(s) detached from it and kept.');
        }
        return array('ok' => true, 'detached' => $n, 'live' => 0);
    }

    /** A shared coach note on the application (visible to every coach and admin; never a dollar figure). */
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

    /** One offer_flow log line (no names or emails: the player is the log's subject account). */
    private static function log_flow($offer, $text) {
        TeamOversight_Log::add('offer_flow', $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': ' . $text, $offer->user_id ? array('user_id' => $offer->user_id) : array());
    }

    /** Queue an email (records it in the outbox; nothing is sent here). Never throws. */
    private static function queue($type, $offer, array $context = array()) {
        if (!class_exists('TeamOversight_Offer_Emails')) {
            return null;
        }
        try {
            $result = TeamOversight_Offer_Emails::queue($type, $offer ? intval($offer->id) : 0, $context);
            // A refusal (an unknown type, an unusable context) must never be silent: the transition went ahead and nobody would be told.
            if (is_array($result) && empty($result['queued']) && !empty($result['error'])) {
                TeamOversight_Log::add('offer_alert', 'The ' . $type . ' email could not be queued' . ($offer ? ' for offer #' . intval($offer->id) : '') . ': ' . $result['error']);
            }
            return $result;
        } catch (\Throwable $e) {
            TeamOversight_Log::add('offer_alert', 'Could not queue the ' . $type . ' email' . ($offer ? ' for offer #' . intval($offer->id) : '') . '.');
            return null;
        }
    }

    // ---- the application, the verdict and the player's place ----

    private static function application_row($application_id, $season = null) {
        global $wpdb;
        if ($season !== null) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d AND season = %s", intval($application_id), (string) $season));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d", intval($application_id)));
    }

    /** The coach's verdict row for an application and team (id, status), or null. */
    public static function verdict_for($application_id, $team) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id, status, offer_accepted FROM {$wpdb->prefix}team_trial_selections WHERE application_id = %d AND team = %s", intval($application_id), (string) $team));
    }

    /** Does the player (by account email) hold an open playing or training place on the team now? */
    private static function placed_on_team($user, $season, $team) {
        $today = current_time('Y-m-d');
        foreach (TeamOversight_Fee_Timeline::rows($user->user_email, $season) as $row) {
            if ($row->team === $team && in_array($row->role, array('playing_member', 'training_only'), true) && ($row->end_date === null || $row->end_date > $today)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // A higher team's claim (club decision, 28 Oct 2026)
    //
    // A coach can't send an offer for team T while the player is Tentative
    // or Selected on a team H in the same season with a HIGHER division (a
    // smaller level number above 0). Their coach decides first. higher_team_block()
    // is the ONE place that rule lives: request() (Send offer and Offer
    // again), approve() (the committee's re-check), request_many() (the bulk
    // send, through request()), the coach's card and the Offers to approve
    // rows all ask it.
    // ------------------------------------------------------------------

    /** Verdicts on a higher team that hold a player back from a lower team's offer. Training Only and Rejected never do. */
    const BLOCKING_VERDICTS = array('tentative', 'selected');

    /** Teams with these age rules (JPL = U19, YSL 17 = U17) are exempt on BOTH sides: juniors can play in two of them. By rule, never by code. */
    const BLOCK_EXEMPT_AGE_RULES = array('u19', 'u17');

    /** A Selected verdict on a higher team stops blocking once its offer ended without the player joining. */
    const CLAIM_RELEASED_STATES = array('declined', 'moved_declined', 'withdrawn', 'not_approved');

    /** Teams for a season with their name, gender, age rule and division level (one shared TeamOversight_Database, cached for the request). */
    private static $teams_config_cache = array();
    private static $database_instance = null;

    public static function teams_config($season) {
        $season = (string) $season;
        if (!isset(self::$teams_config_cache[$season])) {
            if (!(self::$database_instance instanceof TeamOversight_Database)) {
                self::$database_instance = new TeamOversight_Database();
            }
            $config = self::$database_instance->get_teams_config($season);
            self::$teams_config_cache[$season] = is_array($config) ? $config : array();
        }
        return self::$teams_config_cache[$season];
    }

    /** Is this team exempt from the higher-team rule (a U19 or U17 team, by its age rule)? $team: one entry of teams_config(). */
    public static function block_exempt($team) {
        return is_array($team) && isset($team['age_rule']) && in_array((string) $team['age_rule'], self::BLOCK_EXEMPT_AGE_RULES, true);
    }

    /**
     * What higher_team_block() reads for some applications, in two queries:
     * application id => array(verdicts (team => status), offers (team =>
     * the latest offer, a live one winning)). Pass the result to
     * higher_team_block() as $context when checking a whole board. A pure
     * read; hold it only for the request that built it.
     */
    public static function block_context(array $application_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $application_ids))));
        $context = array();
        if (empty($ids)) {
            return $context;
        }
        foreach ($ids as $id) {
            $context[$id] = array('verdicts' => array(), 'offers' => array());
        }
        $rows = $wpdb->get_results("SELECT application_id, team, status FROM {$wpdb->prefix}team_trial_selections WHERE application_id IN (" . implode(',', $ids) . ')');
        foreach ((array) $rows as $row) {
            $context[intval($row->application_id)]['verdicts'][(string) $row->team] = (string) $row->status;
        }
        foreach (self::index_for_applications($ids) as $application_id => $by_team) {
            if (isset($context[intval($application_id)])) {
                $context[intval($application_id)]['offers'] = $by_team;
            }
        }
        return $context;
    }

    /**
     * Is an offer for $team held back by a higher team's claim on the player?
     *
     * The rule: for another team H in the same season whose division level is
     * above 0 and SMALLER than this team's (a higher division; teams in the
     * same division share a level, so neither is higher), the player's verdict
     * on H is Tentative or Selected. Training Only and Rejected never block.
     * A team whose age rule is U19 or U17 is exempt on both sides: an offer
     * for one is never blocked, and a verdict on one never blocks another
     * team. A Selected verdict on H stops blocking once H's latest offer for
     * the playing role ended without the player joining (declined, moved to
     * Declined, withdrawn or not approved); a cancelled request, an offer
     * still out, a locked-in player and a player who left do not release it.
     * A team with no level (0) or no entry in the season's teams is never
     * judged.
     *
     * $application: an application id, or a row with an id. $context: a
     * block_context() result for a board, or null to read this player now
     * (what the server-side paths do). Pure: never writes.
     *
     * Returns array(blocked (bool), by (list of array(team, name, verdict,
     * level), highest division first), reason ("Tentative for X, a higher
     * team"), message (the coach's card: "Can't send yet: ... Their coach
     * decides first."), awaiting_message (the card of a request already
     * waiting for the committee), admin_message (the committee's page and
     * approve()'s refusal)). Every text is plain, names no amount and is safe
     * for coaches.
     */
    public static function higher_team_block($application, $team, $season, $context = null) {
        $none = array('blocked' => false, 'by' => array(), 'reason' => '', 'message' => '', 'awaiting_message' => '', 'admin_message' => '');
        $team = (string) $team;
        $season = (string) $season;
        if ($team === '' || !self::applies($season)) {
            return $none;
        }
        $application_id = 0;
        if (is_object($application) && isset($application->id)) {
            $application_id = intval($application->id);
        } elseif (is_scalar($application)) {
            $application_id = intval($application);
        }
        if ($application_id <= 0) {
            return $none;
        }
        $config = self::teams_config($season);
        $mine = isset($config[$team]) ? $config[$team] : null;
        $my_level = $mine ? intval($mine['level']) : 0;
        if ($my_level <= 0 || self::block_exempt($mine)) {
            return $none;
        }
        if (!(is_array($context) && isset($context[$application_id]))) {
            $context = self::block_context(array($application_id));
        }
        $verdicts = isset($context[$application_id]['verdicts']) ? $context[$application_id]['verdicts'] : array();
        $offers = isset($context[$application_id]['offers']) ? $context[$application_id]['offers'] : array();
        $by = array();
        foreach ($verdicts as $code => $status) {
            $code = (string) $code;
            if ($code === $team || !in_array($status, self::BLOCKING_VERDICTS, true) || !isset($config[$code])) {
                continue;
            }
            $level = intval($config[$code]['level']);
            if ($level <= 0 || $level >= $my_level || self::block_exempt($config[$code])) {
                continue;
            }
            if ($status === 'selected') {
                $latest = isset($offers[$code]) ? $offers[$code] : null;
                if ($latest && $latest->role === 'playing_member' && in_array($latest->state, self::CLAIM_RELEASED_STATES, true)) {
                    continue;
                }
            }
            $name = (isset($config[$code]['name']) && (string) $config[$code]['name'] !== '') ? (string) $config[$code]['name'] : $code;
            $by[] = array('team' => $code, 'name' => $name, 'verdict' => $status, 'level' => $level);
        }
        if (empty($by)) {
            return $none;
        }
        usort($by, function ($a, $b) {
            return $a['level'] !== $b['level'] ? $a['level'] - $b['level'] : strcmp($a['name'], $b['name']);
        });
        $parts = array();
        foreach ($by as $b) {
            $parts[] = ($b['verdict'] === 'selected' ? 'Selected' : 'Tentative') . ' for ' . $b['name'];
        }
        $last = array_pop($parts);
        $list = empty($parts) ? $last : implode(', ', $parts) . ' and ' . $last;
        $one = count($by) === 1;
        $reason = $list . ($one ? ', a higher team' : ', higher teams');
        $decides = $one ? 'Their coach decides first.' : 'Their coaches decide first.';
        return array(
            'blocked' => true,
            'by' => $by,
            'reason' => $reason,
            'message' => 'Can\'t send yet: ' . $reason . '. ' . $decides,
            'awaiting_message' => 'Blocked: ' . $reason . '. The committee can\'t approve it until ' . ($one ? 'their coach decides.' : 'their coaches decide.'),
            'admin_message' => 'Can\'t be approved yet: ' . $reason . '. ' . $decides . ' Decline this request, or approve it once that has changed.',
        );
    }

    // ------------------------------------------------------------------
    // Transitions: the coach (bound to application, verified team, season)
    // ------------------------------------------------------------------

    /**
     * Find the live offer a coach action is about, from (application, team,
     * season) and never from a posted offer id; a posted offer_id / state /
     * date ($shown) is only compared with it. Returns array(offer, result):
     * the offer, or null and a result to return. $already: states whose
     * latest finished offer means "already done" (an ok no-op).
     */
    private static function coach_offer($application_id, $team, $season, $shown, array $already = array()) {
        $offer = self::live_for($application_id, $team);
        if (!$offer || $offer->season !== (string) $season) {
            $latest = self::latest_for($application_id, $team);
            if ($latest && (string) $latest->season === (string) $season && in_array($latest->state, $already, true)) {
                return array(null, self::already($latest));
            }
            return array(null, self::res(false, 'not_found', 'There is no live offer for this player and team: reload the page.'));
        }
        if (!empty($shown['offer_id']) && intval($shown['offer_id']) !== $offer->id) {
            return array(null, self::stale($offer));
        }
        if (isset($shown['state']) && $shown['state'] !== '' && $shown['state'] !== $offer->state) {
            return array(null, self::stale($offer));
        }
        return array($offer, null);
    }

    /**
     * The coach sends an offer to the committee ("Send offer"; also Offer
     * again after a finished offer: a new request through approval).
     * Refused unless: the season applies (2027 on); the application
     * is pending or accepted (not payment pending); the verdict for the team
     * is Selected or Training Only; the player is not already on the team;
     * no higher team has a claim on them (higher_team_block(), code
     * 'higher_team'); their cell can be priced (cell_check); today is not
     * past the late-join cutoff. Inserts the row (state awaiting_approval, live = 1,
     * role from the verdict). A second live offer fails on the unique key
     * and returns 'already'. Queues nothing (nothing is emailed until the
     * committee approves).
     */
    public static function request($application_id, $team, $season, $actor_id, $opts = array()) {
        global $wpdb;
        $application_id = intval($application_id);
        $team = (string) $team;
        $season = (string) $season;
        if (!self::applies($season)) {
            return self::res(false, 'refused', 'Offers apply from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.');
        }
        $live = self::live_for($application_id, $team);
        if ($live) {
            return self::already($live, 'An offer for this player and team is already ' . strtolower(self::states()[$live->state]) . '.');
        }
        $application = self::application_row($application_id, $season);
        if (!$application) {
            return self::res(false, 'refused', 'Application not found for ' . $season . '.');
        }
        if ($application->application_status === 'awaiting_payment') {
            return self::res(false, 'refused', 'The trial fee is unpaid, so there is nothing to offer yet.');
        }
        if (!in_array($application->application_status, array('pending', 'accepted'), true)) {
            return self::res(false, 'refused', 'This application can\'t be offered a place.');
        }
        $verdict = self::verdict_for($application_id, $team);
        $role = $verdict ? self::role_for_verdict($verdict->status) : '';
        if ($role === '') {
            return self::res(false, 'refused', 'Only Selected and Training Only players can be offered.');
        }
        $user = intval($application->user_id) > 0 ? get_userdata(intval($application->user_id)) : false;
        if (!$user || !is_email($user->user_email)) {
            return self::res(false, 'refused', 'The player\'s account can\'t be found, so no offer can go out.');
        }
        if (self::placed_on_team($user, $season, $team)) {
            return self::res(false, 'refused', 'This player is already on ' . $team . '.');
        }
        // A higher team's claim comes first (Tentative or Selected there): also covers Offer again and the bulk send.
        $block = self::higher_team_block($application, $team, $season);
        if ($block['blocked']) {
            return self::res(false, 'higher_team', $block['message'], array('blocked_by' => $block['by']));
        }
        $check = self::cell_check($user->ID, $season, $team, $role);
        if (!$check['ok']) {
            return self::res(false, 'no_price', $check['error_public'], array('error_admin' => $check['error_admin']));
        }
        $invalid = TeamOversight_Fee_Timeline::validate_start($season, current_time('Y-m-d'));
        if ($invalid !== null) {
            return self::res(false, 'refused', 'No offer can go out now: ' . $invalid);
        }

        $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(self::table(), array(
            'application_id' => $application_id,
            'user_id' => intval($user->ID),
            'email' => $user->user_email,
            'name' => $application->name,
            'season' => $season,
            'team' => $team,
            'role' => $role,
            'state' => self::AWAITING,
            'live' => 1,
            'requested_by' => intval($actor_id) > 0 ? intval($actor_id) : null,
            'requested_at' => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'));
        $wpdb->suppress_errors(false);
        self::flush_cache();
        if (!$inserted) {
            // The unique key said no: a racing request made the offer first.
            $live = self::live_for($application_id, $team);
            return $live ? self::already($live, 'An offer for this player and team was just requested.') : self::res(false, 'error', 'The offer could not be saved. Try again.');
        }
        $offer = self::get($wpdb->insert_id);
        self::note($application_id, 'Offer for ' . $team . ' (' . ($role === 'training_only' ? 'Training Only' : 'Selected') . ') sent to the committee for approval.', $actor_id);
        self::log_flow($offer, 'requested');
        self::refresh_badge();
        return self::done($offer, 'Sent to the committee for approval. Nothing is emailed until they approve it.');
    }

    /**
     * Send offers for several applications at once (the bulk panel). Each
     * is re-checked on its own. Players whose latest offer finished any way
     * other than 'cancelled' (declined, moved to declined, not approved,
     * withdrawn, left) are NOT sent in bulk: they have their own Send offer /
     * Offer again button. A player held back by a higher team's claim is
     * skipped and listed (also in 'blocked'). Returns array(requested
     * (ids), already (ids), skipped (application id => reason), blocked
     * (application id => the higher-team reason, a subset of skipped),
     * message).
     */
    public static function request_many($team, $season, array $application_ids, $actor_id) {
        $out = array('requested' => array(), 'already' => array(), 'skipped' => array(), 'blocked' => array(), 'message' => '');
        foreach (array_values(array_unique(array_map('intval', $application_ids))) as $id) {
            if ($id <= 0) {
                continue;
            }
            $latest = self::latest_for($id, $team);
            if ($latest && $latest->season === (string) $season && $latest->live !== 1 && $latest->state !== self::CANCELLED) {
                $out['skipped'][$id] = 'They have an earlier offer that ended (' . strtolower(self::states()[$latest->state]) . '): use Send offer on their card.';
                continue;
            }
            $r = self::request($id, $team, $season, $actor_id);
            if ($r['ok'] && $r['changed']) {
                $out['requested'][] = $id;
            } elseif ($r['ok']) {
                $out['already'][] = $id;
            } else {
                $out['skipped'][$id] = $r['message'];
                if ($r['code'] === 'higher_team') {
                    $out['blocked'][$id] = $r['message'];
                }
            }
        }
        $out['message'] = count($out['requested']) . ' sent to the committee' . (count($out['skipped']) ? '; ' . count($out['skipped']) . ' skipped' . (count($out['blocked']) ? ' (' . count($out['blocked']) . ' held back by a higher team)' : '') : '') . (count($out['already']) ? '; ' . count($out['already']) . ' already requested' : '') . '.';
        return $out;
    }

    /**
     * The coach takes back a request still waiting for the committee
     * (Cancel request). Awaiting only; the offer ends as 'cancelled' and the
     * player was never told.
     */
    public static function cancel($application_id, $team, $season, $actor_id, $shown = array()) {
        list($offer, $refusal) = self::coach_offer($application_id, $team, $season, $shown, array(self::CANCELLED));
        if ($refusal) {
            return $refusal;
        }
        if ($offer->state !== self::AWAITING) {
            return self::res(false, 'refused', 'This offer has already been decided: ask for it to be withdrawn instead.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        return self::do_cancel($offer, $actor_id, 'cancelled by the coach', true);
    }

    private static function do_cancel($offer, $actor_id, $reason, $note) {
        if (!self::finish($offer, array(self::AWAITING), self::CANCELLED, $actor_id, $reason)) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::CANCELLED) ? self::already($now) : self::stale($now);
        }
        if ($note) {
            self::note($offer->application_id, 'Offer request for ' . $offer->team . ' cancelled.', $actor_id);
        }
        self::log_flow($offer, 'request cancelled (' . $reason . ')');
        self::refresh_badge();
        return self::done($offer, 'The request was cancelled.');
    }

    /**
     * Remind: queue the offer email again (subject "Reminder: ..."). Offered
     * to coaches only when the offer is overdue (admins any time, through
     * admin_remind()); at most once in 24 hours, enforced in the UPDATE, so
     * two coaches pressing together send one. Sent or accepted offers only.
     */
    public static function remind($application_id, $team, $season, $actor_id, $shown = array()) {
        list($offer, $refusal) = self::coach_offer($application_id, $team, $season, $shown);
        if ($refusal) {
            return $refusal;
        }
        return self::do_remind($offer, $actor_id, true);
    }

    /** Remind, by offer id, for an administrator (any time the offer is sent or accepted). */
    public static function admin_remind($offer_id, $admin_id, $shown = array()) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if (isset($shown['state']) && $shown['state'] !== '' && $shown['state'] !== $offer->state) {
            return self::stale($offer);
        }
        return self::do_remind($offer, $admin_id, false);
    }

    private static function do_remind($offer, $actor_id, $coach_only_when_overdue) {
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return self::res(false, 'refused', 'Only an offer that has been sent and not yet locked in can be reminded.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if ($coach_only_when_overdue && !self::deadline_status($offer)['overdue']) {
            return self::res(false, 'refused', 'A reminder can be sent once the deadline has passed.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $cutoff_time = current_time('mysql');
        $since = date('Y-m-d H:i:s', strtotime($cutoff_time) - DAY_IN_SECONDS);
        if ($offer->last_reminded_at && $offer->last_reminded_at > $since) {
            return self::res(false, 'too_soon', 'A reminder was already sent in the last 24 hours.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        list($where, $args) = self::pin($offer, array(self::SENT, self::ACCEPTED));
        $changed = self::update_where(
            array('reminder_count' => array('raw' => 'reminder_count + 1'), 'last_reminded_at' => $cutoff_time),
            $where . ' AND reminder_count = %d AND (last_reminded_at IS NULL OR last_reminded_at <= %s)',
            array_merge($args, array(intval($offer->reminder_count), $since))
        );
        if (!$changed) {
            return self::res(false, 'stale', 'Someone else just sent a reminder, or the offer changed: reload.', array('offer_id' => $offer->id));
        }
        $number = intval($offer->reminder_count) + 1;
        self::queue('reminder', $offer, array('reminder_number' => $number, 'by' => intval($actor_id)));
        self::note($offer->application_id, 'Reminder sent to the player for the ' . $offer->team . ' offer.', $actor_id);
        self::log_flow($offer, 'reminder ' . $number . ' queued');
        return self::done($offer, 'The offer email will be sent again.', array('reminder_number' => $number));
    }

    /**
     * Give 7 more days: the overdue deadline becomes today plus 7 days,
     * clamped to the late-join cutoff, as a compare-and-set on the date the
     * page showed ($shown['date']). For an unanswered offer (reply overdue)
     * the deposit date is also pushed to at least seven days after the new
     * reply-by (clamped), so a last-day answer is not overdue at once.
     * Refused once today is past the cutoff, and when the new date would not
     * be later than the current one. The UPDATE is held to the state the
     * offer was read in (an offer accepted meanwhile is 'stale'). Does not
     * queue a second "deadline not met" email.
     */
    public static function extend($application_id, $team, $season, $actor_id, $shown = array()) {
        list($offer, $refusal) = self::coach_offer($application_id, $team, $season, $shown);
        if ($refusal) {
            return $refusal;
        }
        return self::do_extend($offer, $actor_id, $shown, true);
    }

    /** Give 7 more days, by offer id, for an administrator. */
    public static function admin_extend($offer_id, $admin_id, $shown = array()) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        return $offer ? self::do_extend($offer, $admin_id, $shown, false) : self::not_found();
    }

    private static function do_extend($offer, $actor_id, $shown, $coach_only_when_overdue) {
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return self::res(false, 'refused', 'Only an offer waiting for the player can be extended.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $status = self::deadline_status($offer);
        if ($coach_only_when_overdue && !$status['overdue']) {
            return self::res(false, 'refused', 'More time can be given once the deadline has passed.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $column = $offer->state === self::SENT ? 'reply_by' : 'deposit_due';
        $current = $offer->$column;
        if (!empty($shown['date']) && $shown['date'] !== $current) {
            return self::stale($offer);
        }
        $today = current_time('Y-m-d');
        $cutoff = TeamOversight_Fees::latest_start_date($offer->season);
        if ($cutoff !== null && $today > $cutoff) {
            return self::res(false, 'refused', 'The late-join cutoff (' . self::fmt_date_year($cutoff) . ') has passed, so nobody can be placed any more.', array('offer_id' => $offer->id));
        }
        $new = TeamOversight_Fee_Timeline::add_days($today, self::EXTEND_DAYS);
        if ($cutoff !== null && $new > $cutoff) {
            $new = $cutoff;
        }
        if ($current && $new <= $current) {
            return self::res(false, 'refused', 'That would not give them any more time than they have.', array('offer_id' => $offer->id));
        }
        $set = array($column => $new, 'extended_count' => array('raw' => 'extended_count + 1'));
        if ($offer->state === self::SENT) {
            $deposit = TeamOversight_Fee_Timeline::add_days($new, self::EXTEND_DAYS);
            if ($cutoff !== null && $deposit > $cutoff) {
                $deposit = $cutoff;
            }
            if (!$offer->deposit_due || $offer->deposit_due < $deposit) {
                $set['deposit_due'] = $deposit;
            }
        }
        // Held to the state it was read in: the column (and, for a sent offer, the deposit date push) was chosen for that
        // state, and accepting never changes reply_by, so a +7 days meant for an overdue reply must not land on an offer
        // the player accepted a moment ago.
        list($where, $args) = self::pin($offer, array($offer->state));
        $where .= $current ? " AND `$column` = %s" : " AND `$column` IS NULL";
        if ($current) {
            $args[] = $current;
        }
        if (!self::update_where($set, $where, $args)) {
            return self::stale($offer);
        }
        self::note($offer->application_id, 'Gave the player 7 more days for the ' . $offer->team . ' offer (now ' . self::fmt_date($new) . ').', $actor_id);
        self::log_flow($offer, ($column === 'reply_by' ? 'reply-by' : 'deposit date') . ' extended to ' . $new);
        return self::done($offer, 'They now have until ' . self::fmt_date_year($new) . '.', array('new_date' => $new, 'column' => $column));
    }

    /**
     * Move an overdue offer to Declined (the coach's call). Sent or accepted
     * offers that are overdue only. lock_in_if_met() runs first: a player who
     * has in fact paid in full is locked in and this is refused. Otherwise
     * one UPDATE (state moved_declined, settlement 'pending' if money is
     * held), then settle('forfeit'); the verdict is untouched. The player is
     * emailed nothing: the deposit is one payment that locks the player in, so
     * a missed deadline never holds money (any that is held is still settled).
     */
    public static function move_to_declined($application_id, $team, $season, $actor_id, $shown = array()) {
        list($offer, $refusal) = self::coach_offer($application_id, $team, $season, $shown, array(self::MOVED_DECLINED));
        if ($refusal) {
            return $refusal;
        }
        return self::do_move_to_declined($offer, $actor_id, true);
    }

    /** Move to Declined, by offer id, for an administrator (overdue offers only). */
    public static function admin_move_to_declined($offer_id, $admin_id, $shown = array()) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state === self::MOVED_DECLINED) {
            return self::already($offer);
        }
        if (isset($shown['state']) && $shown['state'] !== '' && $shown['state'] !== $offer->state) {
            return self::stale($offer);
        }
        return self::do_move_to_declined($offer, $admin_id, true);
    }

    private static function do_move_to_declined($offer, $actor_id, $overdue_only) {
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return self::res(false, 'refused', 'Only an offer waiting for the player can be moved to Declined.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $status = self::deadline_status($offer);
        if ($overdue_only && !$status['overdue']) {
            return self::res(false, 'refused', 'An offer can be moved to Declined once its deadline has passed.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $locked = self::lock_in_if_met($offer);
        if (!empty($locked['locked'])) {
            return self::res(false, 'locked_in', 'Their deposit is complete, so they are locked in and can\'t be moved to Declined.', array('offer_id' => $offer->id, 'state' => 'locked_in'));
        }
        // Money at stake is THIS offer's (it was accepted, and no other live place owns the credit), not whatever the player's one season invoice holds.
        $held = self::held_for_offer($offer);
        $because = $status['kind'] === 'reply' ? 'reply' : 'deposit';
        if (!self::finish($offer, array(self::SENT, self::ACCEPTED), self::MOVED_DECLINED, $actor_id, 'moved to declined: ' . ($because === 'reply' ? 'no reply' : 'deposit not complete'), array('settlement' => $held > 0 ? 'pending' : ''))) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::MOVED_DECLINED) ? self::already($now) : self::stale($now);
        }
        $settled = $held > 0 ? self::settle($offer->id, 'forfeit') : null;
        // What settle() really forfeited is reported back; its pre-settle figure only stands in when it did not decide. No email goes out.
        $kept = self::settled_cents($settled);
        $kept = $kept === null ? $held : $kept;
        self::cleanup_holding_invoice($offer);
        self::note($offer->application_id, 'Moved ' . $offer->team . ' offer to Declined.', $actor_id);
        self::log_flow($offer, 'moved to declined (' . ($because === 'reply' ? 'no reply' : 'deposit not complete') . ')' . ($kept > 0 ? ', deposit kept' : ''));
        self::refresh_badge();
        return self::done($offer, 'Moved to Declined.', array('held_cents' => $kept, 'settle' => $settled));
    }

    /**
     * Withdraw the offer (the club takes it back). A coach may only do it
     * while nothing has been paid; with money held it needs an administrator
     * (admin_withdraw). Sent or accepted offers; the verdict can be changed
     * afterwards. Queues the player a short "withdrawn" note. An empty
     * holding invoice is removed.
     */
    public static function withdraw($application_id, $team, $season, $actor_id, $shown = array()) {
        list($offer, $refusal) = self::coach_offer($application_id, $team, $season, $shown, array(self::WITHDRAWN));
        if ($refusal) {
            return $refusal;
        }
        if ($offer->state === self::AWAITING) {
            return self::res(false, 'refused', 'This offer is still waiting for the committee: use Cancel request.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return self::res(false, 'refused', 'They are on the team: ask the club to remove them first.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        // Deposit money paid toward THIS offer (it was accepted, and no other live place owns the credit), not whatever the player's one season invoice holds.
        if (self::held_for_offer($offer) > 0) {
            return self::res(false, 'money_held', 'Money is held for this offer: ask the club to withdraw it.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        return self::do_withdraw($offer, $actor_id);
    }

    /**
     * Withdraw, by offer id, for an administrator: always allowed for a
     * sent or accepted offer, with money held or not (the deposit becomes
     * credit under the club-breaks-its-commitment rule). A locked-in offer
     * is withdrawn by taking the place off first (withdraw_locked_in()).
     */
    public static function admin_withdraw($offer_id, $admin_id, $shown = array()) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state === self::WITHDRAWN) {
            return self::already($offer);
        }
        if (isset($shown['state']) && $shown['state'] !== '' && $shown['state'] !== $offer->state) {
            return self::stale($offer);
        }
        if ($offer->state === self::LOCKED_IN) {
            return self::withdraw_locked_in($offer, $admin_id);
        }
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return self::res(false, 'refused', 'Only a sent or accepted offer can be withdrawn here (a request waiting for approval is refused instead).', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        return self::do_withdraw($offer, $admin_id);
    }

    private static function do_withdraw($offer, $actor_id) {
        $held = self::held_for_offer($offer);
        if (!self::finish($offer, array(self::SENT, self::ACCEPTED), self::WITHDRAWN, $actor_id, 'withdrawn by the club', array('settlement' => $held > 0 ? 'pending' : ''))) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::WITHDRAWN) ? self::already($now) : self::stale($now);
        }
        $settled = $held > 0 ? self::settle($offer->id, 'credit') : null;
        // What settle() really credited decides what the player is told; its pre-settle figure only stands in when it did not decide.
        $credited = self::settled_cents($settled);
        $credited = $credited === null ? $held : $credited;
        self::cleanup_holding_invoice($offer);
        self::queue('withdrawn', $offer, array('held_cents' => $credited, 'by' => intval($actor_id)));
        self::note($offer->application_id, 'Withdrew the ' . $offer->team . ' offer.', $actor_id);
        self::log_flow($offer, 'withdrawn' . ($credited > 0 ? ' (deposit held as credit)' : ''));
        self::refresh_badge();
        return self::done($offer, 'The offer was withdrawn and the player will be told.', array('held_cents' => $credited, 'settle' => $settled));
    }

    // ------------------------------------------------------------------
    // Transitions: the committee (administrators)
    // ------------------------------------------------------------------

    /**
     * Approve an offer request: work out the quote and the deadlines, then
     * ONE UPDATE sets state sent, the quote (basis, amount, class, start),
     * the deposit (percent, target), sent_at, reply_by, deposit_due and the
     * decision. The UPDATE also requires the verdict row to still exist and
     * still match the offer's role. Queues the 'offer' email (To the
     * player's account email, Reply-To the team's coach, Cc all the team's coaches).
     *
     * $opts: shown_quote and shown_target, the figures the approver saw: if
     * the recomputed ones differ (in integer cents) the offer is NOT
     * approved and code 'changed' returns the new figures, so the committee
     * never approves a number it did not see.
     *
     * Refused when: the actor can't approve; the offer is
     * not awaiting approval; the verdict changed; a higher team has a claim
     * on the player now (higher_team_block(), code 'higher_team'); the cell
     * can't be priced; today is past the late-join cutoff.
     * Result extras: quote (float), target (float), basis, reply_by,
     * deposit_due.
     */
    public static function approve($offer_id, $admin_id, $opts = array()) {
        global $wpdb;
        if (!self::can_approve($admin_id)) {
            return self::res(false, 'forbidden', 'Only administrators can approve offers.');
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state === self::SENT) {
            return self::already($offer);
        }
        if ($offer->state !== self::AWAITING) {
            return self::res(false, 'refused', 'This offer is no longer waiting for approval (' . strtolower(self::states()[$offer->state]) . ').', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (!self::applies($offer->season)) {
            return self::res(false, 'refused', 'Offers apply from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.');
        }
        $application = self::application_row($offer->application_id);
        if (!$application || !in_array($application->application_status, array('pending', 'accepted'), true)) {
            return self::res(false, 'refused', 'The application is no longer open for an offer.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $verdict = self::verdict_for($offer->application_id, $offer->team);
        if (!$verdict || self::role_for_verdict($verdict->status) !== $offer->role) {
            return self::res(false, 'changed', 'The coach\'s verdict for this player changed after the request: ask the coach to send the offer again.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        // A block can appear after the request (the higher team's coach picked the player since): checked again, now.
        $block = self::higher_team_block($application, $offer->team, $offer->season);
        if ($block['blocked']) {
            return self::res(false, 'higher_team', $block['admin_message'], array('offer_id' => $offer->id, 'state' => $offer->state, 'blocked_by' => $block['by']));
        }
        $invalid = TeamOversight_Fee_Timeline::validate_start($offer->season, current_time('Y-m-d'));
        if ($invalid !== null) {
            return self::res(false, 'refused', 'It is too late to send this offer: ' . $invalid, array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $quote = self::compute_quote($offer->user_id, $offer->season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'send', 'fees' => isset($opts['fees']) ? $opts['fees'] : null));
        if (!$quote['ok']) {
            return self::res(false, 'no_price', $quote['error_admin'] !== '' ? $quote['error_admin'] : $quote['error_public'], array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (isset($opts['shown_quote']) && $opts['shown_quote'] !== null && $opts['shown_quote'] !== '' && self::to_cents($opts['shown_quote']) !== $quote['amount_cents']) {
            return self::res(false, 'changed', 'The figures changed since you looked: the quote is now ' . self::money($quote['amount']) . ' and the deposit ' . self::money($quote['target']) . '. Review it and approve again.',
                array('offer_id' => $offer->id, 'state' => $offer->state, 'quote' => $quote['amount'], 'target' => $quote['target']));
        }
        if (isset($opts['shown_target']) && $opts['shown_target'] !== null && $opts['shown_target'] !== '' && self::to_cents($opts['shown_target']) !== $quote['target_cents']) {
            return self::res(false, 'changed', 'The figures changed since you looked: the quote is now ' . self::money($quote['amount']) . ' and the deposit ' . self::money($quote['target']) . '. Review it and approve again.',
                array('offer_id' => $offer->id, 'state' => $offer->state, 'quote' => $quote['amount'], 'target' => $quote['target']));
        }

        // Deadlines (stored; a later settings change never moves them).
        $deadlines = self::deadlines(current_time('Y-m-d'), self::settings($offer->season), TeamOversight_Fees::latest_start_date($offer->season));
        $reply_by = $deadlines['reply_by'];
        $deposit_due = $deadlines['deposit_due'];

        $set = array(
            'state' => self::SENT,
            'quote_basis' => $quote['basis'],
            'quote_amount' => $quote['amount'],
            'quote_fee_class' => $quote['fee_class'],
            'quote_start' => $quote['start'],
            'deposit_percent' => $quote['percent'],
            'deposit_target' => $quote['target'],
            'sent_at' => current_time('mysql'),
            'reply_by' => $reply_by,
            'deposit_due' => $deposit_due,
            'decided_by' => intval($admin_id),
            'decided_at' => current_time('mysql'),
        );
        list($where, $args) = self::pin($offer, array(self::AWAITING));
        // The verdict row must still exist and still match the offer's role.
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}team_trial_selections s WHERE s.application_id = " . self::table() . ".application_id AND s.team = " . self::table() . ".team AND s.status = %s)";
        $args[] = self::verdict_for_role($offer->role);
        if (!self::update_where($set, $where, $args)) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::SENT) ? self::already($now) : self::stale($now);
        }
        $contacts = self::team_contacts($offer->team, $offer->season, $offer->requested_by, $offer->user_id);
        self::queue('offer', $offer, array('approved_by' => intval($admin_id), 'no_coach' => $contacts['none']));
        self::note($offer->application_id, 'Offer for ' . $offer->team . ' approved and sent (reply by ' . self::fmt_date($reply_by) . ').', $admin_id);
        self::log_flow($offer, 'approved and sent (' . $quote['basis'] . ' basis, reply by ' . $reply_by . ', deposit due ' . $deposit_due . ')');
        TeamOversight_Log::add('offer_money', $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': quoted ' . self::money($quote['amount']) . ' on the ' . ($quote['basis'] === 'upper' ? 'upper limit' : 'real fee') . ', deposit ' . self::money($quote['target']) . ' (' . rtrim(rtrim(number_format($quote['percent'], 2), '0'), '.') . '%)',
            $offer->user_id ? array('user_id' => $offer->user_id, 'amount' => $quote['target']) : array());
        self::refresh_badge();
        // 1.79.1: with the email permission closed the offer is still live (its dates run and the player can see it); only the email waits. Say so.
        return self::done($offer, self::emails_live()
            ? 'Approved. The offer email is queued.'
            : 'Approved. The offer email is queued, but this website may not send offer emails yet: the player is not emailed until you allow it, and their reply-by date (' . self::fmt_date($reply_by) . ') is already running.', array(
            'quote' => $quote['amount'], 'target' => $quote['target'], 'basis' => $quote['basis'], 'reply_by' => $reply_by, 'deposit_due' => $deposit_due,
            'coach_notice' => $contacts['notice'],
        ));
    }

    /**
     * Approve many (bulk Approve selected / Approve all shown). $items: a
     * list of offer ids, or id => array(shown_quote, shown_target). Each is
     * its own approve() (nothing is emailed here: the outbox queues and the
     * flush sends). Returns array(approved (ids), already (ids), refused
     * (id => message), changed (id => result), message).
     */
    public static function approve_many(array $items, $admin_id, $opts = array()) {
        $out = array('approved' => array(), 'already' => array(), 'refused' => array(), 'changed' => array(), 'message' => '');
        $fees = isset($opts['fees']) ? $opts['fees'] : null;
        foreach ($items as $key => $value) {
            $id = is_array($value) ? intval($key) : intval($value);
            $shown = is_array($value) ? $value : array();
            if ($id <= 0) {
                continue;
            }
            $r = self::approve($id, $admin_id, array_merge($shown, array('fees' => $fees)));
            if ($r['ok'] && $r['changed']) {
                $out['approved'][] = $id;
            } elseif ($r['ok']) {
                $out['already'][] = $id;
            } elseif ($r['code'] === 'changed' && isset($r['quote'])) {
                $out['changed'][$id] = $r;
                $out['refused'][$id] = $r['message'];
            } else {
                $out['refused'][$id] = $r['message'];
            }
        }
        $out['message'] = count($out['approved']) . ' approved' . (count($out['refused']) ? ', ' . count($out['refused']) . ' refused' : '') . (count($out['already']) ? ', ' . count($out['already']) . ' already approved' : '') . '.';
        return $out;
    }

    /**
     * Refuse an offer request ("Decline selected" on the approvals page):
     * state not_approved, with an optional short note the coach sees on the
     * card (never sent to the player, who is told nothing). Awaiting
     * approval only; administrators only.
     */
    public static function reject($offer_id, $admin_id, $note = '') {
        if (!self::can_approve($admin_id)) {
            return self::res(false, 'forbidden', 'Only administrators can refuse offers.');
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state === self::NOT_APPROVED) {
            return self::already($offer);
        }
        if ($offer->state !== self::AWAITING) {
            return self::res(false, 'refused', 'This offer is no longer waiting for approval (' . strtolower(self::states()[$offer->state]) . ').', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $note = trim(self::cut(sanitize_textarea_field((string) $note), 255));
        if (!self::finish($offer, array(self::AWAITING), self::NOT_APPROVED, $admin_id, $note, array('decided_by' => intval($admin_id), 'decided_at' => current_time('mysql')))) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::NOT_APPROVED) ? self::already($now) : self::stale($now);
        }
        self::note($offer->application_id, 'Offer for ' . $offer->team . ' not approved' . ($note !== '' ? ': ' . $note : '.'), $admin_id);
        self::log_flow($offer, 'not approved');
        self::refresh_badge();
        return self::done($offer, 'Not approved. The player was told nothing.');
    }

    // ------------------------------------------------------------------
    // Transitions: the player (ownership is checked here, by account id)
    // ------------------------------------------------------------------

    /**
     * The player accepts (sent -> accepted). Ownership is checked in SQL
     * (offer id AND user_id). The deposit target is recomputed first on the
     * offer's STORED basis, start and percent, so only the player's other
     * accepted or locked-in places can move it (two teams): quote_amount
     * becomes the larger of its stored value and the new quote (it is the
     * cap's ceiling and never falls); before release the target becomes the
     * larger of the stored and new target; after release it becomes the
     * smaller of the new target and 10% of the real season quote (the
     * fee-release drop applied at the same time). Everything is written in
     * the SAME UPDATE as the state change, a compare-and-set on the state,
     * the deposit target and the release stamp as read: a release drop
     * written in between is never overwritten (the player is told the new
     * figure with 'target_changed', or, when the deposit they are accepting
     * did not move, the accept runs once more on the fresh row).
     *
     * $shown: state ('sent', the state the page showed) and shown_target
     * (the figure the page showed, compared in integer cents; a different
     * one refuses with code 'target_changed' and the new figure so the
     * player can check it and accept again).
     *
     * After the winning UPDATE: ensure_holding_invoice() and
     * lock_in_if_met() (a $0 target, or a ledger already at the target,
     * locks in at once), and the 'coach_accepted' email is queued.
     * Result extras: target, locked_in (bool).
     */
    public static function player_accept($offer_id, $user_id, $shown = array()) {
        $offer = self::get_for_user($offer_id, $user_id);
        if (!$offer) {
            return self::res(false, 'not_found', 'No offer found for this account.');
        }
        if ($offer->state === self::ACCEPTED || $offer->state === self::LOCKED_IN) {
            return self::already($offer, 'You have already accepted this offer.');
        }
        if ($offer->state !== self::SENT) {
            return self::res(false, 'refused', 'This offer can\'t be accepted any more.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (!self::applies($offer->season)) {
            return self::res(false, 'refused', 'This offer can\'t be accepted.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (isset($shown['state']) && $shown['state'] !== '' && $shown['state'] !== $offer->state) {
            return self::stale($offer);
        }
        $application = self::application_row($offer->application_id);
        if (!$application || !in_array($application->application_status, array('pending', 'accepted'), true)) {
            return self::res(false, 'refused', 'This offer can\'t be accepted any more.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        // The coach's verdict must still be the one this offer was sent for (the verdict gate holds it in place, but an admin path or a system downgrade must never leave a player accepting, and paying toward, a place for a verdict that is gone).
        $verdict = self::verdict_for($offer->application_id, $offer->team);
        if (!$verdict || self::role_for_verdict($verdict->status) !== $offer->role) {
            TeamOversight_Log::add('offer_alert', $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': the player tried to accept but the coach\'s verdict no longer matches the offer. Withdraw the offer.', $offer->user_id ? array('user_id' => $offer->user_id) : array());
            return self::res(false, 'refused', 'This offer can\'t be accepted any more.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }

        // The target on the stored basis, start and percent (3.5 of the design): the ONE calculation, shared with the offer page (accept_figures()).
        $figures = self::accept_figures($offer);
        $quote_amount = $figures['amount'];
        $target = $figures['target'];
        $extra_set = array();
        if ($figures['quote_released'] !== null) {
            $extra_set['quote_released'] = $figures['quote_released'];
        }
        if ($figures['dropped']) {
            $extra_set['target_dropped_at'] = current_time('mysql');
        }
        if (isset($shown['shown_target']) && $shown['shown_target'] !== null && $shown['shown_target'] !== '' && self::to_cents($shown['shown_target']) !== self::to_cents($target)) {
            $why = self::to_cents($target) < self::to_cents($offer->deposit_target) ? 'because the season fee has now been set' : 'because of your other offers';
            return self::res(false, 'target_changed', 'Your deposit is now ' . self::money($target) . ' ' . $why . '. Check it and accept again.',
                array('offer_id' => $offer->id, 'state' => $offer->state, 'target' => $target));
        }

        $set = array_merge(array(
            'state' => self::ACCEPTED,
            'accepted_at' => current_time('mysql'),
            'quote_amount' => round($quote_amount, 2),
            'deposit_target' => round($target, 2),
        ), $extra_set);
        list($where, $args) = self::pin($offer, array(self::SENT));
        $where .= ' AND user_id = %d';
        $args[] = intval($user_id);
        // The figures above were worked out from the deposit target and the release stamp as read: a release drop
        // (apply_release_drop()) written in between must not be overwritten by the pre-release target, which would leave
        // quote_released stamped and the drop never applied. Compare-and-set on both.
        if ($offer->deposit_target === null) {
            $where .= ' AND deposit_target IS NULL';
        } else {
            $where .= ' AND deposit_target = %s';
            $args[] = number_format(self::to_cents($offer->deposit_target) / 100, 2, '.', '');
        }
        if ($offer->quote_released === null) {
            $where .= ' AND quote_released IS NULL';
        } else {
            $where .= ' AND quote_released = %s';
            $args[] = number_format(self::to_cents($offer->quote_released) / 100, 2, '.', '');
        }
        // The verdict row must still exist and still match the offer's role (as approve() requires).
        global $wpdb;
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}team_trial_selections s WHERE s.application_id = " . self::table() . ".application_id AND s.team = " . self::table() . ".team AND s.status = %s)";
        $args[] = self::verdict_for_role($offer->role);
        if (!self::update_where($set, $where, $args)) {
            $now = self::get($offer->id);
            if ($now && in_array($now->state, array(self::ACCEPTED, self::LOCKED_IN), true)) {
                return self::already($now);
            }
            // Still sent, but its figures moved meanwhile (the fees were released): show the player the new deposit.
            if ($now && $now->state === self::SENT
                && (self::to_cents($now->deposit_target) !== self::to_cents($offer->deposit_target) || ($now->quote_released === null) !== ($offer->quote_released === null))) {
                $again = self::accept_figures($now);
                if (self::to_cents($again['target']) === self::to_cents($target) && empty($shown['retried'])) {
                    // The release stamp moved but not the deposit the player is accepting: accept again, once, on the fresh row.
                    return self::player_accept($offer_id, $user_id, array_merge($shown, array('retried' => true)));
                }
                $why = self::to_cents($again['target']) < self::to_cents($offer->deposit_target) ? 'because the season fee has now been set' : 'because of your other offers';
                return self::res(false, 'target_changed', 'Your deposit is now ' . self::money($again['target']) . ' ' . $why . '. Check it and accept again.',
                    array('offer_id' => $now->id, 'state' => $now->state, 'target' => $again['target']));
            }
            return self::stale($now);
        }
        $fresh = self::get($offer->id);
        $locked = false;
        try {
            self::ensure_holding_invoice($fresh);
            $result = self::lock_in_if_met($fresh);
            $locked = !empty($result['locked']);
        } catch (\Throwable $e) {
            TeamOversight_Log::add('offer_alert', $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': the holding invoice or lock-in check failed after the player accepted.', $offer->user_id ? array('user_id' => $offer->user_id) : array());
        }
        self::queue('coach_accepted', $offer, array());
        self::log_flow($offer, 'accepted by the player' . ($locked ? ', locked in at once' : ''));
        self::refresh_badge();
        return self::done($offer, $locked ? 'You are locked in.' : 'Accepted. The next step is your deposit.', array('target' => round($target, 2), 'locked_in' => $locked));
    }

    /**
     * The player declines (sent or accepted -> declined), with an optional
     * reason (up to 500 characters; pass it already wp_unslash()ed; it is
     * sanitised here and never goes into a ledger note). lock_in_if_met()
     * runs first: a player whose deposit is in fact complete is locked in and
     * the decline is refused (code 'locked_in'). Otherwise one UPDATE
     * (state declined, declined_at, decline_reason, settlement 'pending' if
     * money is held), then settle('forfeit'), and the coaches are queued the
     * 'coach_declined' email.
     */
    public static function player_decline($offer_id, $user_id, $reason = '', $shown = array()) {
        $offer = self::get_for_user($offer_id, $user_id);
        if (!$offer) {
            return self::res(false, 'not_found', 'No offer found for this account.');
        }
        if ($offer->state === self::DECLINED) {
            return self::already($offer, 'You have already declined this offer.');
        }
        if ($offer->state === self::LOCKED_IN) {
            return self::res(false, 'locked_in', 'Your deposit is complete and you are locked in. Contact the club if you need to pull out.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return self::res(false, 'refused', 'This offer can\'t be declined any more.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (isset($shown['state']) && $shown['state'] !== '' && $shown['state'] !== $offer->state) {
            return self::stale($offer);
        }
        $reason = trim(self::cut(sanitize_textarea_field((string) $reason), 500));
        $locked = self::lock_in_if_met($offer);
        if (!empty($locked['locked'])) {
            return self::res(false, 'locked_in', 'Your deposit is complete, so you are locked in. Contact the club if you need to pull out.', array('offer_id' => $offer->id, 'state' => 'locked_in'));
        }
        // Money at stake is THIS offer's (it was accepted, and no other live place owns the credit), not whatever the player's one season invoice holds.
        $held = self::held_for_offer($offer);
        // Held to the state it was read in: the settlement below was decided about that state.
        list($where, $args) = self::pin($offer, array($offer->state));
        $set = array(
            'state' => self::DECLINED,
            'live' => null,
            'declined_at' => current_time('mysql'),
            'decline_reason' => $reason === '' ? null : $reason,
            'closed_by' => intval($user_id),
            'closed_at' => current_time('mysql'),
            'closed_reason' => 'declined by the player',
            'settlement' => $held > 0 ? 'pending' : '',
        );
        if (!self::update_where($set, $where . ' AND user_id = %d', array_merge($args, array(intval($user_id))))) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::DECLINED) ? self::already($now) : self::stale($now);
        }
        $settled = $held > 0 ? self::settle($offer->id, 'forfeit') : null;
        // What settle() really forfeited decides what the coaches are told; its pre-settle figure only stands in when it did not decide.
        $kept = self::settled_cents($settled);
        $kept = $kept === null ? $held : $kept;
        self::cleanup_holding_invoice($offer);
        self::queue('coach_declined', $offer, array('had_deposit' => $kept > 0));
        self::log_flow($offer, 'declined by the player' . ($kept > 0 ? ', deposit kept' : ''));
        self::refresh_badge();
        return self::done($offer, 'You have declined this offer.', array('held_cents' => $kept, 'settle' => $settled));
    }

    /**
     * What accepting will store for a SENT offer: the season fee (quote_amount,
     * never below the one sent), the deposit target, the player's other
     * accepted or locked-in places that moved them (labels), and, once the
     * season's fees are released and the real fee can be priced, the fee the
     * player is now told (quote_released) and whether the target dropped.
     * ONE calculation (1.78.0): player_accept() writes these figures and the
     * offer page (TeamOversight_Offers_Player::accept_target()) shows them, so
     * what is shown and what is stored cannot drift. A pure read: it never
     * writes. Only the player's OTHER places can move the figures: with none,
     * the sent figures come back unchanged.
     * Returns array(ok (bool: it could be priced), target (money), amount
     * (money), places (list), quote_released (money|null), dropped (bool)).
     */
    public static function accept_figures($offer) {
        $stored_target = $offer->deposit_target !== null ? floatval($offer->deposit_target) : 0.0;
        $stored_amount = $offer->quote_amount !== null ? floatval($offer->quote_amount) : 0.0;
        $out = array('ok' => false, 'target' => $stored_target, 'amount' => $stored_amount, 'places' => array(), 'quote_released' => null, 'dropped' => false);
        $new = self::compute_quote($offer->user_id, $offer->season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'accept'));
        if (empty($new['ok'])) {
            return $out;
        }
        $out['ok'] = true;
        $out['places'] = $new['places'];
        $out['amount'] = max($stored_amount, floatval($new['amount']));
        $real = null;
        if (TeamOversight_Fees::fees_released($offer->season)) {
            $real = self::compute_quote($offer->user_id, $offer->season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'real'));
        }
        if ($real && !empty($real['ok'])) {
            // Released and the real fee can be priced: the smaller of the new target and the percent of the real season quote
            // (the release drop applied at the same moment as the other places).
            $dropped = min($new['target_cents'], $real['target_cents']);
            // The fee the player is told once fees are out. An upper-limit quote is a promise: the invoice is capped at it, so the figure shown never exceeds it.
            $out['quote_released'] = $offer->quote_basis === 'upper' ? min($real['amount'], round($out['amount'], 2)) : $real['amount'];
            $out['dropped'] = $dropped < self::to_cents($stored_target);
            $out['target'] = $dropped / 100.0;
        } else {
            // Not released (or the real fee cell is still blank): the larger of the stored and the new target.
            $out['target'] = max($stored_target, floatval($new['target']));
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The coach's verdict once an offer exists
    // ------------------------------------------------------------------

    /**
     * May the coach change this verdict? Call it from the portal's
     * set_selection after every refusal that can precede the write and
     * immediately before it, including clear (which deletes the row).
     * $new_status: tentative, selected, training_only, rejected, or ''
     * (cleared). Returns array(allowed, message, cancelled (a waiting
     * request was cancelled), offer).
     *
     *  - no live offer, or the same status: allowed;
     *  - awaiting approval: allowed, and the request is cancelled (the
     *    compare-and-set result is checked: if the committee approved at
     *    that moment the sent rule applies and the change is refused);
     *  - sent or accepted: refused, "Withdraw the offer first";
     *  - locked in: refused, they are on the team.
     * With no live offer behind the verdict everything is allowed, exactly as
     * before 1.76.0 (and for a season before 2027 there never is one).
     */
    public static function verdict_gate($application_id, $team, $new_status, $actor_id = 0) {
        $out = array('allowed' => true, 'message' => '', 'cancelled' => false, 'offer' => null);
        $offer = self::live_for($application_id, $team);
        if (!$offer) {
            return $out;
        }
        $out['offer'] = $offer;
        $verdict = self::verdict_for($application_id, $team);
        if ($verdict && $new_status !== '' && $verdict->status === $new_status) {
            return $out;
        }
        if ($offer->state === self::AWAITING) {
            $r = self::do_cancel($offer, $actor_id, 'verdict changed', false);
            if ($r['ok']) {
                $out['cancelled'] = true;
                $out['message'] = 'The offer request that was waiting for the committee was cancelled.';
                return $out;
            }
            $offer = self::live_for($application_id, $team);
            if (!$offer) {
                return $out; // it ended some other way meanwhile
            }
            $out['offer'] = $offer;
        }
        $out['allowed'] = false;
        $out['message'] = $offer->state === self::LOCKED_IN
            ? 'They are on the team: ask the club to remove them first.'
            : 'Withdraw the offer first (that emails the player a short note), then change the verdict.';
        return $out;
    }

    // ------------------------------------------------------------------
    // Hooks, the daily job, reconcile
    // ------------------------------------------------------------------

    private static $hooks_registered = false;

    /**
     * Add this class's hooks once per request (the Fee timeline's pattern:
     * static, from team_oversight_init()).
     */
    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_action('init', array(__CLASS__, 'schedule_events'));
        // The one-off 1.79.0 upgrade, after migrate_database() (init, priority 10) has made the tables. One option check once done.
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 20, 0);
        add_action(self::DAILY_HOOK, array(__CLASS__, 'run_daily'));
        // The email permission buttons (the action name is the 1.77.0 one, so a page opened before 1.79.0 still posts here).
        add_action('admin_post_murvc_offer_switch', array(__CLASS__, 'handle_switch_post'));
        // The player's deposit payment starts on the offer page or the checklist: the form it receives is
        // contracted in DEPOSIT_FORM and deposit_pay_nonce_action(), which the offer page posts.
        add_action('template_redirect', array(__CLASS__, 'maybe_start_deposit_payment'), 10, 0);
    }

    /** Schedule the daily job if it is missing (cleared on deactivation). */
    public static function schedule_events() {
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::DAILY_HOOK);
        }
    }

    /**
     * The daily job: side effects only, nothing is released automatically.
     * Takes its own named lock (an overlapping or manual second call exits
     * at once) and runs each step in its own try/catch so one failure never
     * stops the rest. It first makes sure the one-off upgrade has run
     * (maybe_upgrade()). Steps:
     *  1. reconcile()                      (state and money repairs)
     *  2. daily_overdue_emails()           (the coaches' one "deadline not met" email per deadline)
     *  3. daily_retry_money()              (retry placement / pricing / release drop)
     *  4. TeamOversight_Offer_Emails::daily_upkeep()   (stuck and failed outbox rows, then a flush)
     *  5. TeamOversight_Not_Selected::sweep()          (1.80.0: sends nothing by itself; it only re-queues a
     *                                                    "not selected" email someone pressed whose outbox row went missing)
     *  6. refresh_badge()
     * Returns array(step => summary) for the CLI and tests.
     */
    public static function run_daily() {
        global $wpdb;
        $out = array();
        self::maybe_upgrade();
        $lock = 'murvc_offers_daily_' . md5($wpdb->prefix);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return array('skipped' => 'Another run holds the lock.');
        }
        try {
            $steps = array(
                'reconcile' => array(__CLASS__, 'reconcile'),
                'overdue_emails' => array(__CLASS__, 'daily_overdue_emails'),
                'retry_money' => array(__CLASS__, 'daily_retry_money'),
                'email_upkeep' => array('TeamOversight_Offer_Emails', 'daily_upkeep'),
                'not_selected' => array('TeamOversight_Not_Selected', 'sweep'),
            );
            foreach ($steps as $name => $callable) {
                if (!is_callable($callable)) {
                    $out[$name] = 'not available';
                    continue;
                }
                try {
                    $out[$name] = call_user_func($callable);
                } catch (\Throwable $e) {
                    $out[$name] = 'failed';
                    TeamOversight_Log::add('offer_alert', 'The daily offers job step "' . $name . '" failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
                }
            }
            self::refresh_badge();
            // What this run did, for the Offers page (Setup tab) and the Configuration box: when it ran and which steps failed.
            if (class_exists('TeamOversight_Offers_Setup')) {
                TeamOversight_Offers_Setup::record_daily($out);
            }
        } finally {
            if ($got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
        return $out;
    }

    /**
     * Daily step 2: for each live offer whose reply-by (sent) or deposit
     * date (accepted) has passed, queue the coaches ONE 'coach_overdue'
     * email per deadline per offer (the outbox key is offer + 'reply' or
     * 'deposit', so running again, or missing an extended date, queues
     * nothing more). While the live-site gate is closed (a copy of the
     * database, or the emails paused) it queues and stamps NOTHING, so a
     * pause never piles up a week of rows to mass-send when it ends: what is
     * overdue is simply queued by the first run after the gate reopens, and
     * one "suppressed" line a day says so. Returns the number queued.
     */
    public static function daily_overdue_emails() {
        global $wpdb;
        if (!self::emails_live()) {
            self::note_suppressed('The daily "deadline not met" step queued nothing.');
            return 0;
        }
        $today = current_time('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE (state = 'sent' AND reply_by < %s) OR (state = 'accepted' AND deposit_due < %s) ORDER BY id",
            $today, $today
        ));
        $n = 0;
        foreach ((array) $rows as $row) {
            $offer = self::normalise($row);
            $status = self::deadline_status($offer, $today);
            if (!$status['overdue'] || !self::applies($offer->season)) {
                continue;
            }
            $queued = self::queue('coach_overdue', $offer, array('kind' => $status['kind'], 'date' => $status['date'], 'days_late' => $status['days_late']));
            if (is_array($queued) && !empty($queued['queued'])) {
                $n++;
                self::log_flow($offer, 'deadline not met (' . $status['kind'] . '), coaches told');
            }
        }
        return $n;
    }

    /**
     * Repair what is meant to happen once and might not have. Idempotent;
     * runs from the daily job, from the Offers admin page load (at most once
     * a minute), in the one-off upgrade (maybe_upgrade()) and as the first
     * step after any rollback.
     * Returns array(step => count). The STATE repairs (steps 4 and 5 of the
     * design) are here; the MONEY repairs (lock in accepted offers whose
     * ledger already meets the target, stamp placements, offers whose place
     * is gone, settlements, outbox gaps, missing holding invoices) are
     * reconcile_money().
     */
    public static function reconcile() {
        $out = array('state' => self::reconcile_state());
        $out['money'] = self::reconcile_money();
        return $out;
    }

    /**
     * Step 4: state and live must agree (a finished state with live = 1
     * would block Offer again through the unique key; a live state with live
     * NULL would escape it). Step 5: the mirror, team_trial_selections
     * .offer_accepted, is 1 only while an offer is locked in, so a 2027+
     * row marked while 1.75.x ran with no locked-in offer is cleared and a
     * locked-in offer whose row reads 0 is set to 1. Step 5 waits for the
     * one-off reset (reset_legacy_acceptances(), run by maybe_upgrade()), so
     * every coach-recorded acceptance it ever clears is in the reset's audit
     * first. Returns array(live_repaired, mirror_cleared, mirror_set).
     */
    public static function reconcile_state() {
        global $wpdb;
        $t = self::table();
        $out = array('live_repaired' => 0, 'mirror_cleared' => 0, 'mirror_set' => 0);
        $live_list = "'" . implode("','", self::LIVE_STATES) . "'";
        $wpdb->suppress_errors(true);
        $finished = $wpdb->get_results("SELECT id FROM $t WHERE state NOT IN ($live_list) AND live IS NOT NULL");
        foreach ((array) $finished as $row) {
            $wpdb->query($wpdb->prepare("UPDATE $t SET live = NULL WHERE id = %d AND state NOT IN ($live_list)", intval($row->id)));
            $out['live_repaired']++;
        }
        $unkeyed = $wpdb->get_results("SELECT id, application_id, team FROM $t WHERE state IN ($live_list) AND live IS NULL");
        foreach ((array) $unkeyed as $row) {
            $ok = $wpdb->query($wpdb->prepare("UPDATE $t SET live = 1 WHERE id = %d AND state IN ($live_list) AND live IS NULL", intval($row->id)));
            if ($ok === false) {
                TeamOversight_Log::add('offer_alert', 'Offer #' . intval($row->id) . ' is live but another live offer exists for the same application and team: check the Offers page.');
            } else {
                $out['live_repaired']++;
            }
        }
        $wpdb->suppress_errors(false);
        if ($out['live_repaired']) {
            TeamOversight_Log::add('offer_flow', 'Reconcile repaired ' . $out['live_repaired'] . ' offer(s) whose state and live flag disagreed.');
        }
        $reset = self::reset_state();
        if ($reset && !empty($reset['done'])) {
            $sel = $wpdb->prefix . 'team_trial_selections';
            $stale = $wpdb->get_results("
                SELECT s.id FROM $sel s
                WHERE CAST(s.season AS UNSIGNED) >= " . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . " AND s.offer_accepted = 1
                  AND NOT EXISTS (SELECT 1 FROM $t o WHERE o.application_id = s.application_id AND o.team = s.team AND o.state = 'locked_in')
            ");
            foreach ((array) $stale as $row) {
                $wpdb->query($wpdb->prepare("UPDATE $sel SET offer_accepted = 0, offer_accepted_at = NULL, offer_accepted_by = NULL WHERE id = %d AND offer_accepted = 1", intval($row->id)));
                $out['mirror_cleared']++;
            }
            $missing = $wpdb->get_results("
                SELECT s.id FROM $sel s JOIN $t o ON o.application_id = s.application_id AND o.team = s.team AND o.state = 'locked_in'
                WHERE s.offer_accepted = 0
            ");
            foreach ((array) $missing as $row) {
                $wpdb->query($wpdb->prepare("UPDATE $sel SET offer_accepted = 1, offer_accepted_at = %s, offer_accepted_by = NULL WHERE id = %d AND offer_accepted = 0", current_time('mysql'), intval($row->id)));
                $out['mirror_set']++;
            }
            if ($out['mirror_cleared'] || $out['mirror_set']) {
                TeamOversight_Log::add('offer_flow', 'Reconcile: ' . $out['mirror_cleared'] . ' offer-accepted flag(s) cleared with no locked-in offer behind them, ' . $out['mirror_set'] . ' set for locked-in offers.');
            }
        }
        self::flush_cache();
        return $out;
    }

    // ------------------------------------------------------------------
    // The one-off upgrade (the 2027 reset), the offer-email gate
    // ------------------------------------------------------------------

    /**
     * How many 2027+ verdict rows carry offer_accepted = 1 with no locked-in
     * offer behind them: coach-recorded acceptances from before offers, which
     * the one-off reset sets back to "offer not sent" (0 once it has run).
     */
    public static function legacy_acceptance_count() {
        global $wpdb;
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}team_trial_selections s WHERE CAST(s.season AS UNSIGNED) >= " . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . " AND s.offer_accepted = 1
            AND NOT EXISTS (SELECT 1 FROM " . self::table() . " o WHERE o.application_id = s.application_id AND o.team = s.team AND o.state = 'locked_in')"));
    }

    /** The one-off reset's audit option, or null when it has not run. */
    public static function reset_state() {
        $state = get_option(self::RESET_OPTION, null);
        return is_array($state) ? $state : null;
    }

    /**
     * The one-off reset: every 2027+ verdict row with offer_accepted = 1
     * goes back to 0 (the verdict itself is never touched), so those
     * players read "Selected / Training Only - offer not sent". It runs once,
     * from the one-off upgrade (maybe_upgrade(); on a site where offers were
     * switched on under 1.78.0 it ran then), under a named lock, auditing the ids
     * BEFORE it writes, in two steps (done false then true) so a crash
     * between the audit and the UPDATE is completed on the next run, never
     * skipped; the audit option keeps every id so it is reversible by hand.
     * Once done it is a no-op and the audit is never overwritten.
     * Returns array(ok, ran, count, message).
     */
    public static function reset_legacy_acceptances() {
        global $wpdb;
        $p = $wpdb->prefix;
        $lock = 'murvc_offer_reset_' . md5($p);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return array('ok' => false, 'ran' => false, 'count' => 0, 'message' => 'Another request is running the reset: try again in a moment.');
        }
        try {
            $state = get_option(self::RESET_OPTION, null);
            if (is_array($state) && !empty($state['done'])) {
                return array('ok' => true, 'ran' => false, 'count' => intval($state['count']), 'message' => 'The reset already ran.');
            }
            $ids = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$p}team_trial_selections WHERE CAST(season AS UNSIGNED) >= " . intval(TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM) . ' AND offer_accepted = 1 ORDER BY id'));
            if (is_array($state) && !empty($state['ids'])) {
                $ids = array_values(array_unique(array_merge(array_map('intval', $state['ids']), $ids)));
            }
            $started = (is_array($state) && !empty($state['started'])) ? $state['started'] : current_time('mysql');
            update_option(self::RESET_OPTION, array('started' => $started, 'done' => false, 'count' => count($ids), 'ids' => $ids), false);
            if (!empty($ids)) {
                $wpdb->query("UPDATE {$p}team_trial_selections SET offer_accepted = 0, offer_accepted_at = NULL, offer_accepted_by = NULL WHERE id IN (" . implode(',', $ids) . ') AND offer_accepted = 1');
            }
            update_option(self::RESET_OPTION, array('started' => $started, 'finished' => current_time('mysql'), 'done' => true, 'count' => count($ids), 'ids' => $ids), false);
            TeamOversight_Log::add('offer_flow', count($ids) . ' ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . '+ offers marked accepted before the new flow were reset to Selected / Training Only, offer not sent.');
            return array('ok' => true, 'ran' => true, 'count' => count($ids), 'message' => count($ids) . ' earlier acceptance(s) were reset to "offer not sent".');
        } finally {
            if ($got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    /**
     * The one-off upgrade's flag (UPGRADE_OPTION) when it has run, else null:
     * array(done, at (when it ran, site time), offers_from (when the 2027
     * reset ran: the moment offers became how selection works on this site),
     * version, reset (what the reset did), reconcile (its counts), backlog
     * (1.79.x: not-selected notices it held for an administrator; since
     * 1.80.0 the number of rejections not told yet, nothing written)).
     */
    public static function upgrade_state() {
        $flag = get_option(self::UPGRADE_OPTION, null);
        return (is_array($flag) && !empty($flag['done'])) ? $flag : null;
    }

    /** Has the one-off upgrade run on this site? */
    public static function upgraded() {
        return self::upgrade_state() !== null;
    }

    /**
     * The one-off 1.79.0 upgrade (club decision, 28 Oct 2026: offers are how
     * selection works from the 2027 season, with no switch). It does what
     * switching offers on used to do, once per site, on the first request
     * after the update (init, priority 20, after migrate_database() has made
     * the tables) or from the daily job, whichever comes first:
     *  1. reset_legacy_acceptances(): every 2027+ coach-recorded acceptance
     *     back to "offer not sent", ids audited first. On a site where offers
     *     were switched on under 1.78.0 it has already run: a no-op;
     *  2. reconcile(): the state and money repairs (idempotent; with no
     *     offer to repair it writes nothing);
     *  3. TeamOversight_Not_Selected::backlog_count() (1.80.0; read-only):
     *     players rejected before offers began are simply "not told yet"
     *     (the coach's card, the Offers page's Not selected tab), and no
     *     "not selected" email is sent unless someone presses Send, so this
     *     step only counts them for the flag and the log line;
     *  then the flag (written last, so a request that dies part way is
     *  completed by the next one: every step is idempotent) and one audited
     *  line in the log. Under a named lock: a request that finds it taken
     *  does nothing (the next request checks again). Once done the check is
     *  one autoloaded option. The retired switch option is neither read nor
     *  written. Never throws. Returns the flag, or null when it could not run
     *  on this request.
     */
    public static function maybe_upgrade() {
        $flag = self::upgrade_state();
        if ($flag !== null) {
            return $flag;
        }
        global $wpdb;
        $lock = 'murvc_offers_upgrade_' . md5($wpdb->prefix);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return null;
        }
        try {
            // Straight from the database: another request may have finished it a moment ago.
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::UPGRADE_OPTION));
            $stored = $raw === null ? null : maybe_unserialize($raw);
            if (is_array($stored) && !empty($stored['done'])) {
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete(self::UPGRADE_OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
                return $stored;
            }
            foreach (array('team_offers', 'team_not_selected', 'team_trial_selections', 'trial_applications') as $short) {
                if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $short))) {
                    return null; // the migration has not made it yet: the next request tries again
                }
            }
            $reset = self::reset_legacy_acceptances();
            if (empty($reset['ok'])) {
                return null;
            }
            $state = self::reset_state();
            $reconcile = null;
            try {
                $reconcile = self::reconcile();
            } catch (\Throwable $e) {
                TeamOversight_Log::add('offer_alert', 'The reconcile step of the offers upgrade failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . '); the daily job runs it again.');
            }
            $backlog = 0;
            if (class_exists('TeamOversight_Not_Selected') && method_exists('TeamOversight_Not_Selected', 'backlog_count')) {
                try {
                    $backlog = TeamOversight_Not_Selected::backlog_count();
                } catch (\Throwable $e) {
                    $backlog = 0; // a count for the log line only: never worth holding the upgrade back
                }
            }
            $now = current_time('mysql');
            $flag = array(
                'done' => true,
                'at' => $now,
                'offers_from' => ($state && !empty($state['started'])) ? (string) $state['started'] : $now,
                'version' => defined('TEAM_OVERSIGHT_VERSION') ? TEAM_OVERSIGHT_VERSION : '',
                'reset' => array('ran' => !empty($reset['ran']), 'count' => intval($reset['count'])),
                'reconcile' => is_array($reconcile) ? $reconcile : null,
                'backlog' => intval($backlog),
            );
            update_option(self::UPGRADE_OPTION, $flag, true);
            self::flush_cache();
            self::refresh_badge();
            TeamOversight_Log::add('offer_flow', 'Offers are now how selection works from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season (one-off upgrade, version ' . $flag['version'] . '): '
                . ($reset['ran'] ? intval($reset['count']) . ' earlier coach-recorded acceptance(s) reset to offer not sent' : 'the 2027 reset had already run (' . intval($reset['count']) . ' acceptance(s) on ' . $flag['offers_from'] . ')')
                . '; ' . intval($backlog) . ' earlier rejection(s) have not been sent a "not selected" email (none is sent unless a coach or an administrator presses Send). Offer emails still need the email permission on the live website.');
            return $flag;
        } catch (\Throwable $e) {
            TeamOversight_Log::add('offer_alert', 'The offers upgrade failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . '); it will be tried again.');
            return null;
        } finally {
            if ($got !== null) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    // ---- the live-site gate for offer emails ----

    /**
     * The plain sentence the Setup tab, the email permission box and How it
     * works all use: with no switch, the email permission and the
     * committee's approval are the two controls.
     */
    public static function controls_sentence() {
        return 'From ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ', offers are how selection works: there is no on/off switch. Two things control them: '
            . 'nothing is emailed until an administrator allows this website to send offer emails (only on the live website, so a test copy never emails anyone), '
            . 'and no offer goes to a player until an administrator approves it.';
    }

    /** A fingerprint of the address this request runs on (a hash of the site's host). */
    public static function site_fingerprint() {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return md5(strtolower($host));
    }

    /** This website's address (host only, lower case), for words like "permission was given to members.example". */
    public static function site_host() {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    /** The stored gate: array(fingerprint, at, by, host) or an empty array. host is the readable address the permission was given to (added in 1.78.0; older records have none). */
    public static function email_gate() {
        $gate = get_option(self::EMAILS_LIVE_OPTION, array());
        return is_array($gate) ? $gate : array();
    }

    /**
     * Are offer emails allowed to leave THIS site? True only when the
     * stored fingerprint equals this request's. A staging or local copy of
     * the live database therefore sends nothing, whatever the switches say.
     */
    public static function emails_live() {
        $gate = self::email_gate();
        return !empty($gate['fingerprint']) && hash_equals((string) $gate['fingerprint'], self::site_fingerprint());
    }

    /**
     * One "offer emails are not being sent from this site" line in the log
     * per day (the daily job and the flush both call it, so a long pause
     * writes one line a day, not one per run).
     */
    public static function note_suppressed($what = '') {
        if (get_transient(self::SUPPRESSED_TRANSIENT) !== false) {
            return;
        }
        set_transient(self::SUPPRESSED_TRANSIENT, current_time('Y-m-d'), DAY_IN_SECONDS);
        TeamOversight_Log::add('email_offer', 'Offer emails are not being sent from this site.' . ($what !== '' ? ' ' . $what : ''));
    }

    /**
     * Open the gate for this site ("Send offer emails from this site").
     * Messages that have waited more than 24 hours (a pause, or a long gap)
     * are first set to 'held' (TeamOversight_Offer_Emails::hold_stale()), so
     * opening the gate never mass-sends stale mail: they wait until an
     * administrator sends them (release_held()) or discards them
     * (discard_held()). Since 1.80.0 a "not selected" email is queued in the
     * outbox the moment someone presses Send, so it is held exactly like any
     * other message (the 1.79.1 separate hold of due notices is gone: nothing
     * is scheduled any more). $include_stale = true opens the gate without
     * holding anything (the administrator already chose to send them).
     * Returns array(ok, message, stale_pending (older than 24 hours when the
     * gate opened), held (messages now waiting for that decision)).
     */
    public static function email_gate_open($actor_id = 0, $include_stale = false) {
        $stale = 0;
        $held = 0;
        if (class_exists('TeamOversight_Offer_Emails')) {
            $stale = TeamOversight_Offer_Emails::stale_count();
            if (!$include_stale && $stale > 0) {
                TeamOversight_Offer_Emails::hold_stale($actor_id);
            }
            $held = TeamOversight_Offer_Emails::held_count();
        }
        // Held first, then open: no flush can send a stale row in between.
        update_option(self::EMAILS_LIVE_OPTION, array('fingerprint' => self::site_fingerprint(), 'at' => current_time('mysql'), 'by' => intval($actor_id), 'host' => self::site_host()), false);
        delete_transient(self::SUPPRESSED_TRANSIENT);
        TeamOversight_Log::add('email_offer', 'Offer emails opened for sending from this site' . ($held > 0 ? ' (' . $held . ' older message(s) held for a decision)' : '') . '.');
        return array('ok' => true, 'message' => 'Offer emails will be sent from this site.', 'stale_pending' => $stale, 'held' => $held);
    }

    /** Close the gate ("Pause offer emails"): nothing is sent, queued rows wait. */
    public static function email_gate_close($actor_id = 0) {
        $before = self::email_gate();
        update_option(self::EMAILS_LIVE_OPTION, array('fingerprint' => '', 'at' => current_time('mysql'), 'by' => intval($actor_id), 'host' => '', 'was_host' => isset($before['host']) ? (string) $before['host'] : ''), false);
        TeamOversight_Log::add('email_offer', 'Offer emails paused.');
        return array('ok' => true, 'message' => 'Offer emails are paused: nothing will be sent until you send them from this site again. Queued messages wait.');
    }

    // ---- the buttons (admin-post), flash notices, the Configuration box ----

    private static function set_flash($ok, $message) {
        set_transient(self::FLASH_PREFIX . get_current_user_id(), array('ok' => (bool) $ok, 'message' => (string) $message), 120);
    }

    /** Leave a one-off notice for the current administrator (shown by flash_notice() on the next page). */
    public static function flash_message($ok, $message) {
        self::set_flash($ok, $message);
    }

    /** The notice left by the last email permission button (shown once). */
    public static function flash_notice() {
        $key = self::FLASH_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        if (!is_array($flash)) {
            return '';
        }
        delete_transient($key);
        return '<div class="notice notice-' . (!empty($flash['ok']) ? 'success' : 'error') . '"><p>' . esc_html($flash['message']) . '</p></div>';
    }

    /**
     * admin-post action murvc_offer_switch (the 1.77.0 name, kept so a page
     * opened before 1.79.0 still posts here): open or close the offer-email
     * gate, and send or discard held messages. Administrators only, with its
     * own nonce; Post/Redirect/Get with a flash notice back to the page it
     * came from. There is no offers on/off switch any more: an old page's
     * 'on' or 'off' changes nothing and says so.
     */
    public static function handle_switch_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        check_admin_referer('murvc_offer_switch', 'murvc_offer_switch_nonce');
        $do = isset($_POST['switch_action']) ? sanitize_key(wp_unslash($_POST['switch_action'])) : '';
        $actor = get_current_user_id();
        if ($do === 'on' || $do === 'off') {
            $r = array('ok' => false, 'message' => 'Offers no longer have an on/off switch: from ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' they are how selection works. Nothing was changed. Reload the page.');
        } elseif ($do === 'emails_on') {
            $r = self::email_gate_open($actor);
            if (!empty($r['held'])) {
                $r['message'] .= ' ' . intval($r['held']) . ' message(s) have been waiting more than a day and are being held: send or discard them below.';
            }
        } elseif ($do === 'emails_send_held' || $do === 'emails_discard_held') {
            if (!class_exists('TeamOversight_Offer_Emails')) {
                $r = array('ok' => false, 'message' => 'Offer emails are not available.');
            } elseif ($do === 'emails_send_held') {
                $r = TeamOversight_Offer_Emails::release_held($actor);
            } else {
                $r = TeamOversight_Offer_Emails::discard_held($actor);
            }
        } elseif ($do === 'emails_off') {
            $r = self::email_gate_close($actor);
        } else {
            $r = array('ok' => false, 'message' => 'Unknown action.');
        }
        self::set_flash(!empty($r['ok']), $r['message']);
        $default = admin_url('admin.php?page=team-oversight-fees');
        $back = isset($_POST['return']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return'])), $default) : $default;
        wp_safe_redirect($back);
        exit;
    }

    /**
     * The email permission controls, for the Configuration page, the Offers
     * page and the Emails page: whether offer emails may leave this site, the
     * button (and, when old messages are being held, send or discard). With
     * no switch, this permission and the committee's approval of every offer
     * are the two controls, and the box says so. $return_url: where the
     * button sends the admin back to. RETURNS the HTML as a string ('' for
     * anyone who may not manage options): `echo` it. (render_settings_box()
     * below ECHOES.)
     */
    public static function render_gate_box($return_url = '') {
        if (!current_user_can('manage_options')) {
            return '';
        }
        $return_url = $return_url !== '' ? $return_url : admin_url('admin.php?page=team-oversight-fees');
        $live = self::emails_live();
        $held = class_exists('TeamOversight_Offer_Emails') ? TeamOversight_Offer_Emails::held_count() : 0;
        $gate = self::email_gate();
        $host = self::site_host();
        $given_to = !empty($gate['host']) ? (string) $gate['host'] : (!empty($gate['was_host']) ? (string) $gate['was_host'] : '');
        $counts = class_exists('TeamOversight_Offer_Emails') ? TeamOversight_Offer_Emails::status_counts() : array();
        $last_sent = !empty($counts['last_sent']) ? (string) $counts['last_sent'] : '';
        $form = function ($action, $label, $confirm, $primary) use ($return_url) {
            ob_start();
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block; margin: 0 8px 4px 0;"<?php echo $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : ''; ?>>
                <input type="hidden" name="action" value="murvc_offer_switch">
                <input type="hidden" name="switch_action" value="<?php echo esc_attr($action); ?>">
                <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>">
                <?php wp_nonce_field('murvc_offer_switch', 'murvc_offer_switch_nonce'); ?>
                <button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html($label); ?></button>
            </form>
            <?php
            return ob_get_clean();
        };
        ob_start();
        ?>
        <div style="border-left: 4px solid <?php echo $live ? '#00a32a' : '#d63638'; ?>; background: #f6f7f7; padding: 10px 14px; margin: 0 0 12px;">
            <p style="margin: 0 0 6px;"><?php echo esc_html(self::controls_sentence()); ?></p>
            <p id="offer-gate" style="margin: 0 0 6px;"><strong><?php echo $live ? 'Offer emails: sending is ON for ' . esc_html($host) . '.' : 'Offer emails: sending is OFF on ' . esc_html($host) . '.'; ?></strong>
                <?php if ($live): ?>
                    The permission to send belongs to this website's address, so a copy of the website (a test site) sends nothing.
                <?php elseif ($given_to !== '' && $given_to !== $host): ?>
                    Permission was given to <?php echo esc_html($given_to); ?>, not to this address, so every offer, reminder and coach notice waits in the queue. If the website's address has changed, press the button once the change is final. Only press it on the live website.
                <?php else: ?>
                    Messages queue and wait. Only press the button on the live website.
                <?php endif; ?>
                <?php if ($last_sent !== ''): ?>Last offer email sent <?php echo esc_html(wp_date('j M Y, g:i a', strtotime($last_sent))); ?>.<?php else: ?>No offer email has been sent yet.<?php endif; ?>
            </p>
            <?php if (!$live): ?>
                <p style="margin: 0 0 6px;">While sending is off, approving an offer still makes it live: its reply-by and deposit dates start that day and the player can see it on their own pages, but nobody is emailed. On the live website, allow sending before the committee approves the first offer.</p>
            <?php endif; ?>
            <?php if ($live): ?>
                <?php echo $form('emails_off', 'Pause offer emails', 'Pause offer emails? Nothing will be sent until you allow this website to send them again; queued messages wait. Offers already out stay live: their dates keep running.', false); ?>
            <?php else: ?>
                <?php echo $form('emails_on', 'Allow this website to send offer emails', 'Allow ' . $host . ' to send offer emails? Only do this on the live website. Real players and coaches will be emailed. Messages that have waited more than a day are held for you to send or discard. Coaches are emailed a "deadline not met" notice by the next daily check for each offer that is overdue then.', false); ?>
            <?php endif; ?>
            <?php if ($held > 0): ?>
                <p style="margin: 8px 0 6px;"><strong><?php echo intval($held); ?> message(s) older than a day are being held</strong> (queued during a pause or a long gap). Send them only if they are still wanted. An offer email that is discarded leaves the offer live and its dates running, with the player never emailed: remind them from Waiting on players (Remind), give them more time (+7 days) or withdraw the offer. A "not selected" email that is discarded leaves that player not told: they go back on the Offers page's Not selected tab (and their coach's card) as not told yet, where it can be sent again.</p>
                <?php if ($live) { echo $form('emails_send_held', 'Send the held messages', 'Send ' . intval($held) . ' held message(s) now? They are more than a day old: real players and coaches will receive them, and an offer email gives the reply-by date the offer was approved with (give +7 days on Waiting on players first if that date has passed or is close).', false); } ?>
                <?php echo $form('emails_discard_held', 'Discard the held messages', 'Discard ' . intval($held) . ' held message(s)? They will never be sent. A discarded offer email leaves the offer live with the player never emailed: Remind or Withdraw it on Waiting on players. A discarded "not selected" email leaves that player not told: they go back on the Not selected tab, where it can be sent again.', false); ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The Configuration page's "Offers and deposits" box for one season: the
     * email permission controls and this season's settings form. The
     * form posts action=save_offer_settings with nonce offer_settings_nonce
     * (handled by the page; see save_settings()).
     */
    public static function render_settings_box($season) {
        $season = (string) $season;
        $settings = self::settings($season);
        $released = TeamOversight_Fees::fees_released($season);
        $release_date = TeamOversight_Fees::get_fee_release_date($season);
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
            <h2 id="offers-and-deposits" style="margin-top: 0;">Offers and deposits</h2>
            <p class="description" style="max-width: 900px; margin-top: 0;">Everything about offers in one place: the permission to send offer emails, this season's numbers, and a live checklist of what has to be right. The wording of the offer emails and the offer page is under <a href="<?php echo esc_url(admin_url('admin.php?page=team-oversight-emails&tab=offers')); ?>">Emails, Offers and selection</a>. Offers are approved on the <?php echo TeamOversight_Offers_Setup::offers_link('Offers'); ?> page.</p>
            <?php echo self::render_gate_box(admin_url('admin.php?page=team-oversight-fees&season=' . rawurlencode($season))); ?>
            <?php echo TeamOversight_Offers_Setup::render_settings_form($season); ?>
            <p style="margin: 8px 0 0;">
                <?php if ($release_date === ''): ?>
                    <span style="color: #996800;"><strong>No fee release date is set for <?php echo esc_html($season); ?>,</strong> so its fees count as released and offers are quoted on real fees. Set a release date to quote on the upper limits until then.</span>
                <?php elseif (!$released): ?>
                    Offers sent now are quoted on the <strong>upper limits</strong> (fees release on <?php echo esc_html(self::fmt_date_year($release_date)); ?>).
                <?php else: ?>
                    <?php echo esc_html($season); ?> fees are released, so offers sent now are quoted on the <strong>real fees</strong>.
                <?php endif; ?>
            </p>
            <div style="margin-top: 16px;"><?php echo TeamOversight_Offers_Setup::render_checks($season, array('return' => admin_url('admin.php?page=team-oversight-fees&season=' . rawurlencode($season)))); ?></div>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // The money half: invoice, lock-in and placement, settlement, the cap and
    // the release drop. Signatures and return shapes are stable (see
    // design/offer-api.md); nothing here moves money except through
    // TeamOversight_Payments::record_payment().
    // ------------------------------------------------------------------

    /**
     * The money that counts toward a deposit, in cents, for many offers at
     * once: offer id => cents (0 for an offer with no invoice). THE one
     * reading of "paid toward the deposit" (1.78.0). For a LIVE offer it is
     * the player's ledger total on the season invoice less any credit
     * earmarked on a finished offer (free_paid_cents(), the figure lock-in
     * itself compares); for a finished offer, the ledger total its invoice
     * holds (that money is what is being settled). So the
     * coach portal's "deposit 60% paid", the admin lists, the emails and the
     * pay box all agree with whether the player is locked in. Each offer's
     * season invoice is found as invoice_for() does (invoice_id, else the
     * strict identity rule, once per player and season); ONE grouped query
     * sums the ledgers, and only an invoice holding more than its amount
     * (a $0 holding invoice, or money above the fee) asks free_paid_cents().
     * Never a dollar figure to a coach.
     */
    public static function paid_toward_deposit_map(array $offers) {
        $by_offer = array();
        $seen = array();
        $rows = array();
        foreach ($offers as $offer) {
            $key = $offer->invoice_id ? 'i' . $offer->invoice_id : 'u' . $offer->user_id . '|' . $offer->season . '|' . $offer->email;
            if (!array_key_exists($key, $seen)) {
                $invoice = self::invoice_for($offer);
                $seen[$key] = $invoice ? intval($invoice->id) : 0;
                if ($invoice) {
                    $rows[intval($invoice->id)] = $invoice;
                }
            }
            $by_offer[intval($offer->id)] = $seen[$key];
        }
        $paid = self::paid_map(array_values(array_unique(array_filter($by_offer))));
        $free = array();
        foreach ($rows as $invoice_id => $invoice) {
            $ledger = isset($paid[$invoice_id]) ? intval($paid[$invoice_id]) : 0;
            $free[$invoice_id] = $ledger > self::to_cents($invoice->invoice_amount) ? self::free_paid_cents($invoice) : $ledger;
        }
        $out = array();
        foreach ($offers as $offer) {
            $invoice_id = isset($by_offer[intval($offer->id)]) ? $by_offer[intval($offer->id)] : 0;
            if ($invoice_id <= 0) {
                $out[intval($offer->id)] = 0;
                continue;
            }
            // A live offer counts what lock-in counts (credit earmarked on a finished offer does not count toward it). A finished offer
            // shows what its invoice holds: that money is what is being settled, and it is earmarked for this very offer.
            $live = self::is_live_state($offer->state);
            $out[intval($offer->id)] = $live ? $free[$invoice_id] : (isset($paid[$invoice_id]) ? intval($paid[$invoice_id]) : 0);
        }
        return $out;
    }

    /** paid_toward_deposit_map() under its old name (the API's shape is kept); the same figure. */
    public static function paid_cents_map(array $offers) {
        return self::paid_toward_deposit_map($offers);
    }

    /**
     * The season invoice row for an offer's player (strict identity: the
     * account's own invoice, or an email-only one with no account), or
     * null.
     */
    public static function invoice_for($offer) {
        global $wpdb;
        if (!$offer) {
            return null;
        }
        if ($offer->invoice_id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}team_invoices WHERE id = %d AND season = %s", $offer->invoice_id, $offer->season));
            if ($row) {
                return $row;
            }
        }
        $user = $offer->user_id ? get_userdata($offer->user_id) : false;
        $email = $user ? $user->user_email : $offer->email;
        return $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_invoices
            WHERE season = %s AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
            ORDER BY id LIMIT 1
        ", $offer->season, intval($offer->user_id), $email));
    }

    /** What counts toward this offer's deposit, in cents (0 with no invoice): paid_toward_deposit_map() for one offer. */
    public static function paid_toward_deposit_cents($offer) {
        $map = self::paid_toward_deposit_map(array($offer));
        return isset($map[intval($offer->id)]) ? intval($map[intval($offer->id)]) : 0;
    }

    /** paid_toward_deposit_cents() under its old name (the API's shape is kept); the same figure. */
    public static function paid_cents($offer) {
        return self::paid_toward_deposit_cents($offer);
    }

    /**
     * The money above the invoice's fee, in cents: what a finished offer
     * leaves held as credit (the ledger total less the invoice amount, never
     * negative), less the credit the player's OTHER finished offers have
     * already promised elsewhere (settled credit and pending targets, design
     * 4.7), so the same dollars are never claimed twice. Decides whether the
     * ending UPDATE writes settlement 'pending'. With no earlier finished
     * offer on the invoice it is the plain credit.
     */
    public static function held_credit_cents($offer) {
        $invoice = self::invoice_for($offer);
        if (!$invoice) {
            return 0;
        }
        return self::unpromised_credit_cents($invoice, intval($offer->id));
    }

    // ------------------------------------------------------------------
    // MONEY: shared money helpers
    // ------------------------------------------------------------------

    /** Caches that hang off flush_cache() (the cap's offers, the in-memory release drop, the stage lookup). */
    private static $cap_cache = array();
    private static $target_cache = array();
    private static $stage_cache = array();
    private static $money_hooked = false;

    /** Register the money caches' reset on the core's flush action (once; register_hooks() is the core's, so this hangs itself on first use). */
    private static function money_hook() {
        if (!self::$money_hooked) {
            self::$money_hooked = true;
            add_action('murvc_offers_cache_flushed', array(__CLASS__, 'flush_money_caches'));
        }
    }

    /** Forget the money caches (hooked to murvc_offers_cache_flushed, which every write fires). */
    public static function flush_money_caches() {
        self::$cap_cache = array();
        self::$target_cache = array();
        self::$stage_cache = array();
    }

    /**
     * Places a locked-in offer's own removal (withdraw, pull out) is taking
     * off right now: 'user|season|team' => true. on_place_left() ignores
     * them, because that transition finishes the offer itself.
     */
    private static $placing_out = array();

    /** The team's name for words a player reads: the season's configured name, else its code. */
    public static function team_name($season, $team) {
        static $cache = array();
        $key = (string) $season . '|' . (string) $team;
        if (!isset($cache[$key])) {
            $name = (string) $team;
            try {
                $database = new TeamOversight_Database();
                $config = $database->get_teams_config((string) $season);
                if (isset($config[$team]['name']) && (string) $config[$team]['name'] !== '') {
                    $name = (string) $config[$team]['name'];
                }
            } catch (\Throwable $e) {
                // the code will do
            }
            $cache[$key] = $name;
        }
        return $cache[$key];
    }

    /** The player's account email (the account's CURRENT address; the offer's snapshot only when the account has gone). */
    private static function account_email($offer) {
        $user = (!empty($offer->user_id)) ? get_userdata(intval($offer->user_id)) : false;
        return ($user && $user->user_email) ? (string) $user->user_email : (string) $offer->email;
    }

    /** One alert line in the log per key per 20 hours (a failing retry must not write one a day for ever per run). */
    private static function alert_once($key, $message, $user_id = 0) {
        $transient = 'murvc_offer_alert_' . md5((string) $key);
        if (get_transient($transient) !== false) {
            return;
        }
        set_transient($transient, 1, 20 * HOUR_IN_SECONDS);
        TeamOversight_Log::add('offer_alert', $message, intval($user_id) > 0 ? array('user_id' => intval($user_id)) : array());
    }

    /** One offer_money line (a routine money fact: a holding invoice, a forfeit, a cap). */
    private static function log_money($offer, $text, $amount = null) {
        $args = array();
        if (!empty($offer->user_id)) {
            $args['user_id'] = intval($offer->user_id);
        }
        if ($amount !== null) {
            $args['amount'] = $amount;
        }
        TeamOversight_Log::add('offer_money', $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': ' . $text, $args);
    }

    /** An offer_alert line about one offer (something a person must put right). */
    private static function log_alert($offer, $text) {
        TeamOversight_Log::add('offer_alert', $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': ' . $text, !empty($offer->user_id) ? array('user_id' => intval($offer->user_id)) : array());
    }

    /**
     * For the Record Payment warning (1.77.0): what a negative payment could
     * leave a locked-in player short of. Returns array(target_cents, ledger_cents)
     * for an invoice: the largest stored deposit target among the live
     * locked-in offers of its owner in its season (0 when there is none, a
     * season before 2027, or no owner), and the invoice's ledger total now.
     * Pure read.
     */
    public static function locked_in_stake($invoice) {
        $none = array('target_cents' => 0, 'ledger_cents' => 0);
        $invoice = self::invoice_fields($invoice);
        if (!$invoice || !self::applies($invoice->season)) {
            return $none;
        }
        $target = 0;
        foreach (self::offers_for_identity($invoice->user_id, $invoice->email, $invoice->season) as $offer) {
            if ($offer->state === self::LOCKED_IN && $offer->live === 1) {
                $target = max($target, self::stored_target_cents($offer));
            }
        }
        if ($target <= 0) {
            return $none;
        }
        return array('target_cents' => $target, 'ledger_cents' => self::ledger_cents($invoice->id));
    }

    /** The ledger total of one invoice in cents (negative lines included). */
    private static function ledger_cents($invoice_id) {
        $map = self::paid_map(array(intval($invoice_id)));
        return isset($map[intval($invoice_id)]) ? intval($map[intval($invoice_id)]) : 0;
    }

    /**
     * Money on the player's season invoice that finished offers have already
     * promised elsewhere, in cents (design 4.7): the settled amount of those
     * settled 'credit' plus the deposit target of those still 'pending'. The
     * caller caps it at the invoice's credit. $exclude: an offer id whose own
     * promise is not counted. Matched by account id (an account-less invoice
     * by its email), like the invoice itself.
     */
    private static function earmarked_cents($user_id, $email, $season, $exclude = 0) {
        global $wpdb;
        $who = intval($user_id) > 0
            ? $wpdb->prepare('user_id = %d', intval($user_id))
            : $wpdb->prepare('(user_id IS NULL OR user_id = 0) AND email = %s', (string) $email);
        $sql = $wpdb->prepare(
            "SELECT settlement, settled_amount, deposit_target FROM " . self::table() . " WHERE season = %s AND live IS NULL AND settlement IN ('credit', 'pending') AND id <> %d AND ",
            (string) $season, intval($exclude)
        ) . $who;
        $total = 0;
        foreach ((array) $wpdb->get_results($sql) as $row) {
            if ($row->settlement === 'credit') {
                $total += $row->settled_amount !== null ? self::to_cents($row->settled_amount) : self::to_cents($row->deposit_target);
            } else {
                $total += self::to_cents($row->deposit_target);
            }
        }
        return $total;
    }

    /**
     * The credit on a season invoice that no finished offer has promised
     * elsewhere, in cents: the ledger total above the fee, less what the
     * player's OTHER finished offers earmarked (never more than the credit).
     * $invoice: a team_invoices row (id, user_id, email, season,
     * invoice_amount).
     */
    private static function unpromised_credit_cents($invoice, $exclude_offer_id = 0) {
        $credit = max(0, self::ledger_cents($invoice->id) - self::to_cents($invoice->invoice_amount));
        if ($credit <= 0) {
            return 0;
        }
        $earmarked = self::earmarked_cents(isset($invoice->user_id) ? $invoice->user_id : 0, isset($invoice->email) ? $invoice->email : '', $invoice->season, $exclude_offer_id);
        return $credit - min($credit, $earmarked);
    }

    /** A player's offers in a season by identity (account id; an account-less one by email), newest first. */
    private static function offers_for_identity($user_id, $email, $season) {
        global $wpdb;
        if (intval($user_id) > 0) {
            return self::for_user(intval($user_id), (string) $season);
        }
        if ((string) $email === '') {
            return array();
        }
        return self::normalise_all($wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE season = %s AND (user_id IS NULL OR user_id = 0) AND email = %s ORDER BY id DESC',
            (string) $season, (string) $email
        )));
    }

    /**
     * What a player's invoice is making of a refund: the invoice row filled
     * in with the fields the money readers need (a caller may pass a thin row).
     */
    private static function invoice_fields($invoice) {
        global $wpdb;
        if (!$invoice || empty($invoice->id)) {
            return null;
        }
        if (isset($invoice->invoice_amount, $invoice->season, $invoice->user_id, $invoice->email)) {
            return $invoice;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}team_invoices WHERE id = %d", intval($invoice->id)));
        return $row ? $row : null;
    }

    /**
     * The ledger net of one offer's forfeit lines, in cents (negative when
     * money was forfeited): forfeit lines less their reversals, found by the
     * fixed notes the forfeit and its reversal write ("offer #N").
     */
    private static function forfeit_net_cents($invoice_id, $offer_id) {
        global $wpdb;
        $cents = $wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(COALESCE(SUM(amount), 0) * 100) FROM {$wpdb->prefix}team_invoice_payments
             WHERE invoice_id = %d AND ((source = 'forfeit' AND note = %s) OR (source = 'forfeit_undo' AND note = %s))",
            intval($invoice_id), self::forfeit_note($offer_id), self::forfeit_undo_note($offer_id)
        ));
        return intval($cents);
    }

    /** The fixed ledger note of a forfeit (never free text: a decline reason can be 500 characters, the note column 255). */
    private static function forfeit_note($offer_id) {
        return 'Deposit forfeited, offer #' . intval($offer_id);
    }

    private static function forfeit_undo_note($offer_id) {
        return 'Forfeit reversed, offer #' . intval($offer_id);
    }

    /**
     * The legacy mirror on the coach's verdict row (offer_accepted): 1 only
     * while an offer is locked in, so Finalise's three queries and every old
     * reader keep working.
     */
    private static function set_mirror($offer, $on) {
        global $wpdb;
        $sel = $wpdb->prefix . 'team_trial_selections';
        if ($on) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $sel SET offer_accepted = 1, offer_accepted_at = %s, offer_accepted_by = NULL WHERE application_id = %d AND team = %s AND offer_accepted = 0",
                current_time('mysql'), intval($offer->application_id), (string) $offer->team
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE $sel SET offer_accepted = 0, offer_accepted_at = NULL, offer_accepted_by = NULL WHERE application_id = %d AND team = %s AND offer_accepted = 1",
                intval($offer->application_id), (string) $offer->team
            ));
        }
    }

    // ------------------------------------------------------------------
    // MONEY: the holding invoice
    // ------------------------------------------------------------------

    /**
     * Return the player's season invoice, making the $0 holding invoice when
     * they have none (the shape withdraw_unreleased_invoices() already
     * leaves behind: fee $0, not a manual fee), and store invoice_id on the
     * offer. Called at accept, at payment start and at order time. The
     * invoice is looked up by the plugin's STRICT identity rule and made
     * under the same named lock as generate_invoice()'s INSERT, so an accept
     * racing a placement or a Finalise leaves exactly one invoice.
     * Returns array(ok, code, message, invoice_id, created).
     */
    public static function ensure_holding_invoice($offer) {
        $out = array('ok' => false, 'code' => 'error', 'message' => '', 'invoice_id' => 0, 'created' => false);
        if (!$offer || empty($offer->id)) {
            $out['code'] = 'not_found';
            $out['message'] = 'No such offer.';
            return $out;
        }
        if (!self::applies($offer->season)) {
            $out['code'] = 'refused';
            $out['message'] = 'Offers apply from the ' . TeamOversight_Coach_Portal::OFFER_ACCEPTANCE_FROM . ' season.';
            return $out;
        }
        $user = !empty($offer->user_id) ? get_userdata(intval($offer->user_id)) : false;
        if (!$user || !$user->user_email) {
            $out['code'] = 'no_account';
            $out['message'] = 'The player\'s account can\'t be found.';
            return $out;
        }
        $invoice = TeamOversight_Fees::find_season_invoice($user->ID, $user->user_email, $offer->season);
        $created = false;
        if ($invoice) {
            $invoice_id = intval($invoice->id);
        } else {
            $made = self::fees()->insert_holding_invoice($user->ID, $user->user_email, $offer->season);
            $invoice_id = intval($made['id']);
            if ($invoice_id <= 0) {
                self::alert_once('holding_' . $offer->id, $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': the deposit invoice could not be made (' . (isset($made['error']) ? $made['error'] : 'unknown') . '). The player can\'t pay a deposit until it exists.', $offer->user_id);
                $out['message'] = 'The deposit invoice could not be made.';
                return $out;
            }
            $created = !empty($made['created']);
            if ($created) {
                self::log_money($offer, 'holding invoice made ($0, it receives the deposit)');
            }
        }
        self::update_where(array('invoice_id' => $invoice_id), 'id = %d AND (invoice_id IS NULL OR invoice_id <> %d)', array(intval($offer->id), $invoice_id));
        return array('ok' => true, 'code' => 'done', 'message' => '', 'invoice_id' => $invoice_id, 'created' => $created);
    }

    /**
     * After an offer ended, delete the player's holding invoice if it is
     * still empty: fee $0, not a manual fee, no ledger rows, no team place
     * in the season, nothing accrued (Fees::delete_invoice_if_empty() is the
     * one rule for that), and no other live accepted / locked-in offer of
     * theirs waiting to use it. Returns true when it was deleted.
     */
    public static function cleanup_holding_invoice($offer) {
        if (!$offer || empty($offer->season) || !self::applies($offer->season)) {
            return false;
        }
        $invoice = self::invoice_for($offer);
        if (!$invoice || abs(floatval($invoice->invoice_amount)) >= 0.005 || intval($invoice->fee_overridden) === 1) {
            return false;
        }
        if (self::ledger_rows($invoice->id) > 0) {
            return false;
        }
        foreach (self::for_user($offer->user_id, $offer->season, array(self::ACCEPTED, self::LOCKED_IN)) as $other) {
            if ($other->id !== intval($offer->id)) {
                return false;
            }
        }
        $removed = self::fees()->delete_invoice_if_empty(self::account_email($offer), $offer->season, intval($invoice->id), array(
            'reason' => 'an empty deposit invoice: the offer ended with nothing paid',
            'user_id' => intval($offer->user_id),
        ));
        self::flush_cache();
        return !empty($removed['removed']);
    }

    /** How many ledger rows an invoice has. */
    private static function ledger_rows($invoice_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_invoice_payments WHERE invoice_id = %d", intval($invoice_id))));
    }

    // ------------------------------------------------------------------
    // MONEY: what is paid, what is left, what to show
    // ------------------------------------------------------------------

    /**
     * The player's paid deposit money that is free to count toward a
     * deposit, in cents: the invoice's ledger total less the money finished
     * offers have earmarked (promised back) on it, never below zero and the
     * earmark never more than the invoice's credit (money paying a real fee
     * is never earmarked). A pure read. $invoice: a team_invoices row.
     */
    public static function free_paid_cents($invoice) {
        $invoice = self::invoice_fields($invoice);
        if (!$invoice) {
            return 0;
        }
        $paid = self::ledger_cents($invoice->id);
        $credit = max(0, $paid - self::to_cents($invoice->invoice_amount));
        if ($credit > 0) {
            $earmarked = self::earmarked_cents($invoice->user_id, $invoice->email, $invoice->season);
            $paid -= min($credit, $earmarked);
        }
        return max(0, $paid);
    }

    /**
     * The target to show and charge against, in money: the stored one, or,
     * when the season's fees are released, the offer is not locked in and its
     * release drop has not been written yet (quote_released empty), the
     * smaller of the stored target and the percent of the real fee worked out
     * in memory. A pure read: it never writes, so a page can call it for every
     * card; the write is apply_release_drop(). Cached for the request.
     */
    public static function effective_target($offer) {
        if (!$offer) {
            return 0.0;
        }
        $stored = $offer->deposit_target !== null ? floatval($offer->deposit_target) : 0.0;
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true) || $offer->quote_basis !== 'upper' || $offer->quote_released !== null
            || $offer->deposit_target === null || !self::applies($offer->season) || !TeamOversight_Fees::fees_released($offer->season)) {
            return $stored;
        }
        self::money_hook();
        $key = intval($offer->id) . '|' . self::to_cents($stored);
        if (!array_key_exists($key, self::$target_cache)) {
            $real = self::compute_quote($offer->user_id, $offer->season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'real'));
            self::$target_cache[$key] = ($real['ok'] && $real['target_cents'] < self::to_cents($stored)) ? $real['target_cents'] / 100.0 : $stored;
        }
        return self::$target_cache[$key];
    }

    /**
     * The deposit still to pay, in cents: the effective target less the
     * player's free ledger total (free_paid_cents()). A pure read.
     */
    public static function remaining_cents($offer) {
        if (!$offer) {
            return 0;
        }
        $invoice = self::invoice_for($offer);
        $free = $invoice ? self::free_paid_cents($invoice) : 0;
        return max(0, self::to_cents(self::effective_target($offer)) - $free);
    }

    /**
     * What stage an invoice's money is at, for the pages a player reads (the
     * offer page, [member_fees], the Player Checklist), or null for an
     * invoice with no offer, a plain fee invoice, or an empty holding invoice
     * (fee $0 and nothing paid: not shown at all). A pure read. See the API
     * file's section 6.10 for the shape. Priority when several apply:
     * a deposit still due, then a locked-in player's held deposit, then money
     * the club is holding for a finished offer.
     */
    public static function deposit_stage($invoice) {
        $invoice = self::invoice_fields($invoice);
        if (!$invoice || !self::applies($invoice->season)) {
            return null;
        }
        self::money_hook();
        $key = intval($invoice->id) . '|' . self::to_cents($invoice->invoice_amount) . '|' . self::ledger_cents($invoice->id);
        if (array_key_exists($key, self::$stage_cache)) {
            return self::$stage_cache[$key];
        }
        $stage = self::build_stage($invoice);
        self::$stage_cache[$key] = $stage;
        return $stage;
    }

    private static function build_stage($invoice) {
        $offers = self::offers_for_identity($invoice->user_id, $invoice->email, $invoice->season);
        if (empty($offers)) {
            return null;
        }
        $paid = self::ledger_cents($invoice->id);
        $fee_cents = self::to_cents($invoice->invoice_amount);
        $free = self::free_paid_cents($invoice);
        $season = (string) $invoice->season;

        $accepted = null;
        $locked = null;
        $closed = null;
        foreach ($offers as $offer) { // newest first
            if ($offer->state === self::ACCEPTED) {
                if ($accepted === null || self::stored_target_cents($offer) < self::stored_target_cents($accepted)) {
                    $accepted = $offer;
                }
            } elseif ($offer->state === self::LOCKED_IN) {
                if ($locked === null) {
                    $locked = $offer;
                }
            } elseif ($offer->live === null && in_array($offer->settlement, array('credit', 'pending'), true) && $closed === null) {
                $closed = $offer;
            }
        }

        if ($accepted) {
            $target = self::to_cents(self::effective_target($accepted));
            $remaining = max(0, $target - $free);
            // One payment: normally the whole deposit; a second place's top-up is the difference, because what the player has already paid this season counts.
            $words = 'Your deposit for ' . self::team_name($season, $accepted->team) . ' is ' . self::money($target / 100) . '.';
            if ($remaining > 0) {
                if ($free > 0) {
                    $words .= ' ' . self::money(min($free, $target) / 100) . ' of what you have already paid counts towards it, so pay the remaining ' . self::money($remaining / 100);
                } else {
                    $words .= ' Pay it';
                }
                $words .= ($accepted->deposit_due ? ' by ' . self::fmt_date_year($accepted->deposit_due) : '') . ' to lock in your place.';
            } else {
                $words .= ' It is complete: the club is confirming your place.';
            }
            return array('stage' => 'deposit_due', 'words' => $words, 'offer_id' => intval($accepted->id), 'paid_cents' => $free, 'target_cents' => $target);
        }

        // An empty holding invoice (fee $0, nothing paid) with no deposit due is not shown at all.
        if ($fee_cents <= 0 && $paid <= 0) {
            return null;
        }

        // A locked-in player whose invoice still carries no fee: before the
        // release the deposit will come off the fee when it is set; after it,
        // a fee that could not be worked out (a blank cell) leaves the
        // deposit held. Once the invoice has a real fee it is a plain fee
        // invoice and says nothing.
        if ($locked && $fee_cents <= 0 && $paid > 0) {
            $name = self::team_name($season, $locked->team);
            $target_cents = self::stored_target_cents($locked);
            if (!TeamOversight_Fees::fees_released($season)) {
                return array('stage' => 'locked_in', 'offer_id' => intval($locked->id), 'paid_cents' => $free, 'target_cents' => $target_cents,
                    'words' => 'You are locked in for ' . $name . '. You have paid ' . self::money($paid / 100) . ' toward your ' . $season . ' fee, which the club sets in January; what you have paid comes off it.');
            }
            if ((string) $locked->pricing_error !== '') {
                return array('stage' => 'fee_not_set', 'offer_id' => intval($locked->id), 'paid_cents' => $free, 'target_cents' => $target_cents,
                    'words' => 'Your place with ' . $name . ' is confirmed, but your ' . $season . ' fee isn\'t set yet. The ' . self::money($paid / 100) . ' you have paid is held and will come off it.');
            }
        }

        if ($closed && !$locked) {
            $credit = max(0, $paid - $fee_cents);
            if ($credit > 0) {
                $name = self::team_name($season, $closed->team);
                if ($closed->settlement === 'credit') {
                    $amount = $closed->settled_amount !== null ? self::to_cents($closed->settled_amount) : $credit;
                    return array('stage' => 'refund_due', 'offer_id' => intval($closed->id), 'paid_cents' => $credit, 'target_cents' => 0,
                        'words' => 'The club is holding ' . self::money($amount / 100) . ' from your offer for ' . $name . ' and will refund it.');
                }
                return array('stage' => 'under_review', 'offer_id' => intval($closed->id), 'paid_cents' => $credit, 'target_cents' => 0,
                    'words' => 'The club will be in touch about the ' . self::money(min($credit, max(0, self::stored_target_cents($closed))) / 100) . ' you paid toward your offer for ' . $name . '.');
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // MONEY: lock-in and placement
    // ------------------------------------------------------------------

    /**
     * If an accepted offer's free ledger total already meets its target (or
     * the target is $0), lock the player in. Writes the release drop first
     * when fees were released while the deposit was incomplete, so a player
     * who paid the real fee's deposit is never held to the quoted maximum's.
     * Only ever called from write paths (accept, decline, move to declined,
     * the payment hook, reconcile). Returns array(ok, locked (bool: the
     * offer IS locked in now), code, message).
     */
    public static function lock_in_if_met($offer) {
        $out = function ($ok, $locked, $code, $message) {
            return array('ok' => (bool) $ok, 'locked' => (bool) $locked, 'code' => (string) $code, 'message' => (string) $message);
        };
        $offer = ($offer && !empty($offer->id)) ? self::get($offer->id) : null;
        if (!$offer) {
            return $out(false, false, 'not_found', 'No such offer.');
        }
        if ($offer->state === self::LOCKED_IN) {
            return $out(true, true, 'already', 'Already locked in.');
        }
        if ($offer->state !== self::ACCEPTED) {
            return $out(true, false, 'not_accepted', 'Only an accepted offer can be locked in.');
        }
        if (!self::applies($offer->season)) {
            return $out(true, false, 'refused', 'This offer can\'t be locked in.');
        }
        self::apply_release_drop($offer);
        $offer = self::get($offer->id);
        if (!$offer || $offer->state !== self::ACCEPTED) {
            return $out(true, $offer && $offer->state === self::LOCKED_IN, 'already', 'The offer changed.');
        }
        $target = self::stored_target_cents($offer);
        if ($target > 0) {
            $invoice = self::invoice_for($offer);
            if (!$invoice || self::free_paid_cents($invoice) < $target) {
                return $out(true, false, 'not_met', 'The deposit is not complete yet.');
            }
        }
        $result = self::lock_in($offer->id, 'payment');
        $now = self::get($offer->id);
        return $out(!empty($result['ok']), $now && $now->state === self::LOCKED_IN, $result['code'], $result['message']);
    }

    /**
     * accepted -> locked_in. First re-checks the coach's verdict row still
     * exists with the offer's role (if not it stays accepted, an offer_alert
     * says so and nobody is placed); then ONE compare-and-set UPDATE (state
     * and locked_in_at; also requiring the verdict row in the UPDATE itself);
     * only the request that wins it goes on: the legacy offer_accepted mirror,
     * the placement through the fee timeline (source 'offer', recorded by the
     * system), the application marked accepted, and the player's "you're
     * locked in" email queued. A placement that fails leaves the offer locked
     * in (the money is in and the player was promised a place): the reason is
     * kept in placement_error, an admin alert is raised, and Finalise, the
     * daily job or Retry placement finish it. $source (payment, accept,
     * release, reconcile, admin) is for the log. Result extras: placed
     * (bool), place_id, placement_error.
     */
    public static function lock_in($offer_id, $source = 'payment') {
        global $wpdb;
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state === self::LOCKED_IN) {
            return self::already($offer, 'Already locked in.');
        }
        if ($offer->state !== self::ACCEPTED) {
            return self::res(false, 'refused', 'Only an accepted offer can be locked in.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if (!self::applies($offer->season)) {
            return self::res(false, 'refused', 'This offer can\'t be locked in.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $verdict = self::verdict_for($offer->application_id, $offer->team);
        if (!$verdict || self::role_for_verdict($verdict->status) !== $offer->role) {
            self::alert_once('lockin_verdict_' . $offer->id, $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': the deposit is complete but the coach\'s verdict no longer matches the offer, so nobody was placed. Put the verdict back, or withdraw the offer (the deposit stays as credit).', $offer->user_id);
            return self::res(false, 'refused', 'The coach\'s verdict no longer matches this offer, so they can\'t be locked in yet.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }

        list($where, $args) = self::pin($offer, array(self::ACCEPTED));
        // The verdict row must still exist with the offer's role, in the UPDATE itself (as accept and approve do).
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}team_trial_selections s WHERE s.application_id = " . self::table() . ".application_id AND s.team = " . self::table() . ".team AND s.status = %s)";
        $args[] = self::verdict_for_role($offer->role);
        if (!self::update_where(array('state' => self::LOCKED_IN, 'locked_in_at' => current_time('mysql')), $where, $args)) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::LOCKED_IN) ? self::already($now, 'Already locked in.') : self::stale($now);
        }

        // The winner of the compare-and-set goes on.
        $fresh = self::get($offer->id);
        self::set_mirror($fresh, true);
        self::log_flow($fresh, 'locked in (' . (string) $source . ')');
        $placed = self::place($fresh);
        self::queue('locked_in', $fresh, array());
        return self::done($fresh, $placed['ok'] ? 'Locked in.' : 'Locked in, but placing them on the team needs attention: ' . $placed['message'], array(
            'placed' => !empty($placed['ok']),
            'place_id' => isset($placed['place_id']) ? intval($placed['place_id']) : 0,
            'placement_error' => !empty($placed['ok']) ? '' : (string) $placed['message'],
        ));
    }

    /**
     * Place a locked-in player on the team through the fee timeline (the
     * only writer of 2027+ team places): TeamOversight_Fee_Timeline::
     * add_stretches() with source 'offer', recorded by the system (0) so the
     * change row never names the payer or the treasurer who recorded the cash
     * deposit. A pre-season start is charged from the season start, as ever;
     * a team the player already holds is skipped, so running it twice adds
     * nothing. On success: place_id and placed_at stored, placement_error
     * cleared, the application marked accepted, and the fee checked
     * (pricing_error). On failure: the reason is kept in placement_error and
     * an alert is raised. $start: 'Y-m-d' (default today, site time).
     * Returns array(ok, place_id, code ('done' or 'already' or the failure),
     * message).
     */
    public static function place($offer, $start = null) {
        $offer = ($offer && !empty($offer->id)) ? self::get($offer->id) : null;
        if (!$offer) {
            return array('ok' => false, 'place_id' => 0, 'code' => 'not_found', 'message' => 'No such offer.');
        }
        if ($offer->state !== self::LOCKED_IN) {
            return array('ok' => false, 'place_id' => 0, 'code' => 'refused', 'message' => 'Only a locked-in player is placed.');
        }
        $user = !empty($offer->user_id) ? get_userdata(intval($offer->user_id)) : false;
        $fail = function ($code, $message) use ($offer) {
            self::update_where(array('placement_error' => self::cut($message, 255)), 'id = %d AND state = %s', array(intval($offer->id), self::LOCKED_IN));
            self::alert_once('placement_' . $offer->id . '_' . md5($message), $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': locked in but not placed on the team: ' . $message . ' Use Retry placement with a start date on the Offers page, or Finalise.', $offer->user_id);
            return array('ok' => false, 'place_id' => 0, 'code' => $code, 'message' => $message);
        };
        if (!$user || !$user->user_email) {
            return $fail('no_account', 'The player\'s account can\'t be found.');
        }
        $start = (is_string($start) && trim($start) !== '') ? trim($start) : current_time('Y-m-d');
        try {
            $result = TeamOversight_Fee_Timeline::add_stretches(
                array('user_id' => $user->ID, 'email' => $user->user_email),
                $offer->season,
                array(array('team' => $offer->team, 'role' => $offer->role)),
                $start,
                'offer',
                array('recorded_by' => 0, 'note' => 'placed from ' . $start . ' (offer #' . $offer->id . ' locked in)')
            );
        } catch (\Throwable $e) {
            return $fail('error', 'The team place could not be written (' . get_class($e) . ').');
        }
        if (empty($result['ok'])) {
            return $fail('refused', (isset($result['error']) && $result['error'] !== '') ? (string) $result['error'] : 'The place could not be added.');
        }
        $place_id = 0;
        if (!empty($result['row_ids'])) {
            $place_id = intval($result['row_ids'][0]);
        } elseif (!empty($result['skipped'])) {
            $place_id = intval($result['skipped'][0]['existing_id']);
        }
        $was_placed = $offer->placed_at !== null && $offer->placed_at !== '';
        self::update_where(array(
            'place_id' => $place_id > 0 ? $place_id : null,
            'placed_at' => $offer->placed_at !== null && $offer->placed_at !== '' ? (string) $offer->placed_at : current_time('mysql'),
            'placement_error' => null,
        ), 'id = %d AND state = %s', array(intval($offer->id), self::LOCKED_IN));
        self::mark_application_placed($offer);
        self::check_pricing($offer);
        self::log_flow($offer, 'placed on the team from ' . $start . (!empty($result['added']) ? '' : ' (they already held the place)'));
        return array('ok' => true, 'place_id' => $place_id, 'code' => (!empty($result['added']) || !$was_placed) ? 'done' : 'already', 'message' => '');
    }

    /**
     * The Finalise tail as one helper: mark the application accepted and put
     * the offer's team on its assigned_team list ("SL2M", "JPLM (T/O)" for a
     * Training Only place), keeping any teams already there. Idempotent.
     * Returns false when the application is gone or is not pending / accepted.
     */
    public static function mark_application_placed($offer) {
        global $wpdb;
        $application = self::application_row($offer->application_id);
        if (!$application || !in_array($application->application_status, array('pending', 'accepted'), true)) {
            return false;
        }
        $tokens = array();
        foreach (TeamOversight_Trials::parse_assigned_team($application->assigned_team) as $entry) {
            $tokens[$entry['code']] = $entry['code'] . ($entry['training_only'] ? ' (T/O)' : '');
        }
        $tokens[$offer->team] = $offer->team . ($offer->role === 'training_only' ? ' (T/O)' : '');
        $wpdb->update(
            $wpdb->prefix . 'trial_applications',
            array('application_status' => 'accepted', 'assigned_team' => implode(', ', $tokens)),
            array('id' => intval($application->id)),
            array('%s', '%s'),
            array('%d')
        );
        return true;
    }

    /**
     * After release, can a fee be worked out for a placed player? A blank fee
     * cell leaves the holding invoice at $0 and the player reading "paid in
     * full": that sets pricing_error and an alert (the player is told the fee
     * isn't set yet and the deposit is held), and the daily job re-prices
     * once the cell is filled in. Clears the error when a fee exists, the
     * season isn't released or the fee is a manual one. Returns true when
     * there is nothing wrong.
     */
    private static function check_pricing($offer) {
        $offer = self::get($offer->id);
        if (!$offer || $offer->state !== self::LOCKED_IN) {
            return true;
        }
        $clear = function () use ($offer) {
            if ($offer->pricing_error !== null && (string) $offer->pricing_error !== '') {
                self::update_where(array('pricing_error' => null), 'id = %d AND pricing_error IS NOT NULL', array(intval($offer->id)));
                self::log_flow($offer, 'its fee is set now, the pricing alert is cleared');
            }
            return true;
        };
        if (!TeamOversight_Fees::fees_released($offer->season)) {
            return $clear();
        }
        $invoice = self::invoice_for($offer);
        if ($invoice && intval($invoice->fee_overridden) === 1) {
            return $clear();
        }
        if (self::fees()->has_priced_fee(self::account_email($offer), $offer->season)) {
            return $clear();
        }
        $message = 'No ' . $offer->season . ' fee could be worked out for this place: the fee matrix has no amount for it yet.';
        if ((string) $offer->pricing_error !== $message) {
            self::update_where(array('pricing_error' => $message), 'id = %d', array(intval($offer->id)));
        }
        self::alert_once('pricing_' . $offer->id, $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': locked in and placed, but ' . $message . ' Their deposit is held. Fill in the fee matrix cell; the daily job then prices them.', $offer->user_id);
        return false;
    }

    /**
     * Administrators: place a locked-in player whose automatic placement
     * failed (the Locked in tab's Retry placement). $start is 'Y-m-d' and is
     * validated with TeamOversight_Fee_Timeline::validate_start() (the
     * typo guard and the late-join cutoff). Result extra: place_id.
     */
    public static function retry_placement($offer_id, $admin_id, $start) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state !== self::LOCKED_IN) {
            return self::res(false, 'refused', 'Only a locked-in player can be placed.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $start = is_string($start) ? trim($start) : '';
        $invalid = TeamOversight_Fee_Timeline::validate_start($offer->season, $start);
        if ($invalid !== null) {
            return self::res(false, 'refused', $invalid, array('offer_id' => $offer->id, 'state' => $offer->state, 'place_id' => 0));
        }
        $placed = self::place($offer, $start);
        if (!$placed['ok']) {
            return self::res(false, 'refused', $placed['message'], array('offer_id' => $offer->id, 'state' => $offer->state, 'place_id' => 0));
        }
        if ($placed['code'] === 'already') {
            return array_merge(self::already($offer, 'They already hold the place.'), array('place_id' => $placed['place_id']));
        }
        self::log_flow($offer, 'placement retried by an administrator, from ' . $start);
        return self::done($offer, 'Placed on the team from ' . $start . '.', array('place_id' => $placed['place_id']));
    }

    // ------------------------------------------------------------------
    // MONEY: a locked-in offer ending (the place goes first)
    // ------------------------------------------------------------------

    /**
     * Take a locked-in player's team place off, before their offer is ended:
     * removed (Fee_Timeline::remove_mistake(), source 'offer') when the
     * season has not started or they would never have been on the team, else
     * stopped today (stop(), source 'offer'), so the invoice is the time
     * played and the deposit is judged against that. The place hook is told
     * to ignore it (this transition finishes the offer itself). Returns
     * array(ok, message, how ('removed', 'stopped' or 'none' when no open
     * place was found)).
     */
    private static function take_place_off($offer) {
        $out = array('ok' => true, 'message' => '', 'how' => 'none');
        $user = !empty($offer->user_id) ? get_userdata(intval($offer->user_id)) : false;
        if (!$user || !$user->user_email) {
            return $out;
        }
        $today = current_time('Y-m-d');
        $row = null;
        foreach (TeamOversight_Fee_Timeline::rows($user->user_email, $offer->season) as $candidate) {
            if ((string) $candidate->team !== (string) $offer->team || !in_array($candidate->role, array('playing_member', 'training_only'), true)) {
                continue;
            }
            if ($candidate->end_date !== null && $candidate->end_date <= $today) {
                continue; // already off the team
            }
            if ($row === null || intval($candidate->id) === intval($offer->place_id)) {
                $row = $candidate;
            }
        }
        if ($row === null) {
            return $out;
        }
        $dates = TeamOversight_Fees::get_season_dates($offer->season);
        $not_started = !$dates || $today < $dates['start'];
        $never_on = $row->start_date === null || $row->start_date >= $today;
        $marker = intval($user->ID) . '|' . $offer->season . '|' . $offer->team;
        self::$placing_out[$marker] = true;
        try {
            if ($not_started || $never_on) {
                $result = TeamOversight_Fee_Timeline::remove_mistake(intval($row->id), null, 'offer');
                $out['how'] = 'removed';
            } else {
                $result = TeamOversight_Fee_Timeline::stop(intval($row->id), $today, false, 'offer');
                $out['how'] = 'stopped';
            }
        } catch (\Throwable $e) {
            $result = array('ok' => false, 'error' => 'The team place could not be taken off (' . get_class($e) . ').');
        }
        unset(self::$placing_out[$marker]);
        self::flush_cache();
        if (empty($result['ok'])) {
            $out['ok'] = false;
            $out['message'] = (isset($result['error']) && $result['error'] !== '') ? (string) $result['error'] : 'The team place could not be taken off.';
        }
        return $out;
    }

    /**
     * Withdraw a locked-in offer (admin_withdraw() has checked can_approve()):
     * the place is taken off FIRST (if that is refused nothing changes), then
     * the offer ends in one UPDATE and the deposit is settled as credit (the
     * club ended it), evaluated after the invoice has its final shape. The
     * player is queued the withdrawn note.
     */
    private static function withdraw_locked_in($offer, $admin_id) {
        $took = self::take_place_off($offer);
        if (!$took['ok']) {
            return self::res(false, 'refused', 'Their team place could not be taken off, so the offer was not withdrawn: ' . $took['message'], array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $fresh = self::get($offer->id);
        if (!$fresh || $fresh->state !== self::LOCKED_IN) {
            return self::stale($fresh);
        }
        $held = self::held_for_offer($fresh);
        if (!self::finish($fresh, array(self::LOCKED_IN), self::WITHDRAWN, $admin_id, 'withdrawn by the club', array('settlement' => $held > 0 ? 'pending' : ''))) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::WITHDRAWN) ? self::already($now) : self::stale($now);
        }
        self::set_mirror($fresh, false);
        $settled = $held > 0 ? self::settle($fresh->id, 'credit') : null;
        $credited = self::settled_cents($settled);
        $credited = $credited === null ? $held : $credited;
        self::cleanup_holding_invoice($fresh);
        self::queue('withdrawn', $fresh, array('held_cents' => $credited, 'by' => intval($admin_id)));
        self::note($fresh->application_id, 'Withdrew the ' . $fresh->team . ' offer (they had been locked in; the place was taken off).', $admin_id);
        self::log_flow($fresh, 'withdrawn after lock-in (the place was ' . $took['how'] . ')' . ($credited > 0 ? ', deposit held as credit' : ''));
        self::refresh_badge();
        return self::done($fresh, 'The offer was withdrawn, their place taken off and the player will be told.', array('held_cents' => $credited, 'settle' => $settled));
    }

    /**
     * Administrators: a locked-in player pulled out (the Locked in tab's
     * Record pulled out). $who_broke: 'player' (the commitment was theirs:
     * the deposit is forfeited) or 'club' (it was the club's: it stays as
     * credit). The place is taken off FIRST, then the offer ends as 'left' in
     * one UPDATE and settles from the choice, evaluated after the invoice has
     * its final shape. No email is sent (the club handles a pull-out
     * personally; the money is still settled). Result extras: held_cents (what was kept or held),
     * settle.
     */
    public static function pull_out($offer_id, $admin_id, $who_broke = 'player') {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if ($offer->state === self::LEFT) {
            return self::already($offer);
        }
        if ($offer->state !== self::LOCKED_IN) {
            return self::res(false, 'refused', 'Only a locked-in player can be recorded as pulled out.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $club = ($who_broke === 'club');
        $took = self::take_place_off($offer);
        if (!$took['ok']) {
            return self::res(false, 'refused', 'Their team place could not be taken off, so nothing was recorded: ' . $took['message'], array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $fresh = self::get($offer->id);
        if (!$fresh || $fresh->state !== self::LOCKED_IN) {
            return self::stale($fresh);
        }
        $held = self::held_for_offer($fresh);
        $reason = $club ? 'left the team: ended by the club' : 'pulled out of the team';
        if (!self::finish($fresh, array(self::LOCKED_IN), self::LEFT, $admin_id, $reason, array('settlement' => $held > 0 ? 'pending' : ''))) {
            $now = self::get($offer->id);
            return ($now && $now->state === self::LEFT) ? self::already($now) : self::stale($now);
        }
        self::set_mirror($fresh, false);
        $settled = $held > 0 ? self::settle($fresh->id, $club ? 'credit' : 'forfeit') : null;
        $amount = self::settled_cents($settled);
        $amount = $amount === null ? $held : $amount;
        self::cleanup_holding_invoice($fresh);
        self::note($fresh->application_id, 'Recorded the ' . $fresh->team . ' player as ' . ($club ? 'leaving (the club\'s doing).' : 'pulled out.'), $admin_id);
        self::log_flow($fresh, ($club ? 'left the team, the club\'s doing' : 'pulled out') . ' (the place was ' . $took['how'] . ')' . ($amount > 0 ? ($club ? ', deposit held as credit' : ', deposit kept') : ''));
        self::refresh_badge();
        return self::done($fresh, $club ? 'Recorded: the place was taken off and the deposit stays as credit.' : 'Recorded: the place was taken off' . ($amount > 0 ? ' and the deposit is kept.' : '.'), array('held_cents' => $amount, 'settle' => $settled));
    }

    /**
     * A playing place left its team (removed, stopped or undone through the
     * fee timeline): a locked-in offer on that team ends as 'left' with
     * settlement 'pending', for the treasurer (nobody knows who broke what,
     * and the invoice has not been re-priced yet). Nothing else: no email.
     * Ignores a removal the offers class is making itself (withdraw, pull
     * out), a season before 2027 and a player with no locked-in offer there.
     * Called from sync_trial_after_assignment_removal() inside its own
     * try/catch.
     */
    public static function on_place_left($user_id, $email, $season, $team) {
        global $wpdb;
        $season = (string) $season;
        if (!self::applies($season)) {
            return null;
        }
        $uid = intval($user_id) > 0 ? intval($user_id) : max(0, intval(TeamOversight_Fees::resolve_user_id((string) $email)));
        if ($uid <= 0 || isset(self::$placing_out[$uid . '|' . $season . '|' . (string) $team])) {
            return null;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND season = %s AND team = %s AND state = %s',
            $uid, $season, (string) $team, self::LOCKED_IN
        ));
        $n = 0;
        foreach ((array) $rows as $row) {
            if (self::end_left(self::normalise($row), 'place removed')) {
                $n++;
            }
        }
        return $n;
    }

    /** End a locked-in offer as 'left' with settlement 'pending' (its place has gone). Returns true when this call ended it. */
    private static function end_left($offer, $reason) {
        if (!self::finish($offer, array(self::LOCKED_IN), self::LEFT, 0, $reason, array('settlement' => 'pending'))) {
            return false;
        }
        self::set_mirror($offer, false);
        self::log_flow($offer, 'left the team (' . $reason . '); any deposit waits on the Held deposits tab for a decision');
        return true;
    }

    // ------------------------------------------------------------------
    // MONEY: settlement (credit or forfeit), exactly once
    // ------------------------------------------------------------------

    /**
     * Take the named lock that serialises one player's settlements (one
     * season invoice at a time; the offer's id when there is no invoice).
     * Returns the lock name, '' when the database can't do named locks (run
     * unlocked, as the plugin does elsewhere) or false when another request
     * holds it.
     */
    private static function settle_lock($offer) {
        global $wpdb;
        $invoice = self::invoice_for($offer);
        $key = $invoice ? 'i' . intval($invoice->id) : 'o' . intval($offer->id);
        $lock = 'murvc_settle_' . md5($wpdb->prefix . '|' . $key);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ($got !== null && intval($got) !== 1) {
            return false;
        }
        return $got === null ? '' : $lock;
    }

    private static function settle_unlock($lock) {
        global $wpdb;
        if ($lock !== false && $lock !== '') {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * The compare-and-set that moves a finished offer's settlement. Returns
     * the rows changed (0 or 1). $from: the settlements it may be in now.
     * $amount_cents null leaves settled_amount NULL. $actor_id 0 = the system.
     */
    private static function cas_settlement($offer, array $from, $to, $amount_cents, $actor_id) {
        $in = implode(',', array_map(function ($s) {
            return "'" . esc_sql((string) $s) . "'";
        }, $from));
        return self::update_where(array(
            'settlement' => $to,
            'settled_amount' => $amount_cents === null ? null : $amount_cents / 100.0,
            'settled_by' => intval($actor_id) > 0 ? intval($actor_id) : null,
            'settled_at' => current_time('mysql'),
        ), 'id = %d AND live IS NULL AND settlement IN (' . $in . ')', array(intval($offer->id)));
    }

    /** What an already-decided settlement reads as, for a repeated call. */
    private static function settled_result($offer, array $base) {
        $map = array('forfeited' => 'forfeited', 'credit' => 'credit', 'carried' => 'carried', '' => 'none');
        $outcome = isset($map[(string) $offer->settlement]) ? $map[(string) $offer->settlement] : 'pending';
        $cents = in_array($outcome, array('forfeited', 'credit'), true) && $offer->settled_amount !== null ? self::to_cents($offer->settled_amount) : 0;
        if ($outcome === 'pending') {
            return self::res(false, 'refused', 'This offer\'s deposit has not been decided yet.', array_merge($base, array('offer_id' => $offer->id, 'state' => $offer->state)));
        }
        return array_merge(self::already($offer, 'Already settled.'), $base, array('offer_id' => $offer->id, 'state' => $offer->state, 'outcome' => $outcome, 'amount_cents' => $cents));
    }

    /**
     * Write the forfeit as a negative ledger line, once. A crash between the
     * settlement's compare-and-set and this write is repaired by reconcile()
     * with the same call, so the net of this offer's forfeit lines decides
     * what is still to write. Returns true when the ledger now holds the
     * forfeit.
     */
    private static function write_forfeit_line($offer, $invoice, $amount_cents) {
        $net = self::forfeit_net_cents($invoice->id, $offer->id);
        if ($net <= -$amount_cents) {
            return true; // already there
        }
        $to_write = $amount_cents + $net; // the part still missing (net is zero or negative)
        return (bool) TeamOversight_Payments::record_payment(intval($invoice->id), -($to_write / 100), 'forfeit', self::forfeit_note($offer->id));
    }

    /**
     * Resolve a finished offer's pending settlement exactly once, under a
     * lock. $intent: 'forfeit' (the player broke the commitment: a negative
     * 'forfeit' ledger line, fixed wording) or 'credit' (the club did: the
     * money stays as credit on the invoice, nothing is written). The amount
     * is the lesser of the offer's deposit target and the credit nobody has
     * promised elsewhere, read inside the lock and after any place removal.
     * A live accepted / locked-in offer of the same player makes it 'carried'
     * instead (the money is theirs for that place); nothing to settle is
     * 'none'. Actor 0 is the system (the transitions, after their own checks);
     * any other actor must pass can_approve() or gets 'forbidden'.
     *
     * Result: the standard result plus outcome ('forfeited', 'credit',
     * 'carried', 'none', 'pending' when it could not decide) and amount_cents
     * (what was really forfeited or credited; 0 otherwise). ok is true once
     * decided. If the ledger write fails the settlement goes back to
     * 'pending' with an alert and ok false; reconcile() retries it.
     */
    public static function settle($offer_id, $intent, $actor_id = 0) {
        if (intval($actor_id) > 0 && !self::can_approve($actor_id)) {
            return self::forbidden();
        }
        $base = array('offer_id' => intval($offer_id), 'intent' => (string) $intent, 'outcome' => 'pending', 'amount_cents' => 0);
        if (!in_array($intent, array('forfeit', 'credit'), true)) {
            return self::res(false, 'refused', 'Settle an offer as a forfeit or as credit.', $base);
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::res(false, 'not_found', 'No such offer.', $base);
        }
        if (self::is_live_state($offer->state)) {
            return self::res(false, 'refused', 'An offer that is still live can\'t be settled.', array_merge($base, array('state' => $offer->state)));
        }
        if ($offer->settlement !== 'pending') {
            return self::settled_result($offer, $base);
        }
        $lock = self::settle_lock($offer);
        if ($lock === false) {
            return self::res(false, 'busy', 'Another request is settling this player\'s deposit: try again in a moment.', array_merge($base, array('state' => $offer->state)));
        }
        try {
            $offer = self::get($offer_id); // again, inside the lock
            if (!$offer || $offer->settlement !== 'pending') {
                return $offer ? self::settled_result($offer, $base) : self::res(false, 'not_found', 'No such offer.', $base);
            }
            $state = array('offer_id' => $offer->id, 'state' => $offer->state);
            // Another live place of the player's owns the money.
            foreach (self::for_user($offer->user_id, $offer->season, array(self::ACCEPTED, self::LOCKED_IN)) as $other) {
                if ($other->id !== $offer->id) {
                    if (self::cas_settlement($offer, array('pending'), 'carried', null, $actor_id)) {
                        self::log_money($offer, 'deposit carried to the player\'s other live offer #' . $other->id . ' (nothing written to the ledger)');
                        return self::res(true, 'done', 'The deposit stays with their other live offer.', array_merge($base, $state, array('changed' => true, 'outcome' => 'carried', 'amount_cents' => 0)));
                    }
                    return self::settled_result(self::get($offer->id), $base);
                }
            }
            $invoice = self::invoice_for($offer);
            $amount = $invoice ? min(self::stored_target_cents($offer), self::unpromised_credit_cents($invoice, $offer->id)) : 0;
            if ($amount <= 0 || !$invoice) {
                if (self::cas_settlement($offer, array('pending'), '', null, $actor_id)) {
                    self::log_money($offer, 'nothing was held to settle');
                    return self::res(true, 'done', 'Nothing was held, so there is nothing to settle.', array_merge($base, $state, array('changed' => true, 'outcome' => 'none', 'amount_cents' => 0)));
                }
                return self::settled_result(self::get($offer->id), $base);
            }
            if ($intent === 'credit') {
                if (!self::cas_settlement($offer, array('pending'), 'credit', $amount, $actor_id)) {
                    return self::settled_result(self::get($offer->id), $base);
                }
                self::log_money($offer, 'deposit of ' . self::money($amount / 100) . ' held as credit (the club ended the offer; the treasurer refunds it)', $amount / 100);
                return self::res(true, 'done', 'The deposit is held as credit.', array_merge($base, $state, array('changed' => true, 'outcome' => 'credit', 'amount_cents' => $amount)));
            }
            // forfeit
            if (!self::cas_settlement($offer, array('pending'), 'forfeited', $amount, $actor_id)) {
                return self::settled_result(self::get($offer->id), $base);
            }
            if (!self::write_forfeit_line($offer, $invoice, $amount)) {
                // The ledger write failed: take the decision back so reconcile() tries again, and say so.
                self::update_where(array('settlement' => 'pending', 'settled_amount' => null, 'settled_by' => null, 'settled_at' => null), 'id = %d AND settlement = %s', array(intval($offer->id), 'forfeited'));
                self::log_alert($offer, 'the deposit forfeit of ' . self::money($amount / 100) . ' could not be written to the ledger; it is left pending and will be tried again.');
                return self::res(false, 'error', 'The forfeit could not be written to the ledger.', array_merge($base, $state));
            }
            self::log_money($offer, 'deposit of ' . self::money($amount / 100) . ' forfeited (a negative forfeit line in the ledger)', -$amount / 100);
            return self::res(true, 'done', 'The deposit was forfeited.', array_merge($base, $state, array('changed' => true, 'outcome' => 'forfeited', 'amount_cents' => $amount)));
        } finally {
            self::settle_unlock($lock);
        }
    }

    /**
     * The player's live place, if any: another accepted / locked-in offer in
     * the season (an administrator's Mark credit / Forfeit refuse then; the
     * money is that place's, or Apply to a new offer moves it).
     */
    private static function live_claim($offer) {
        foreach (self::for_user($offer->user_id, $offer->season, array(self::ACCEPTED, self::LOCKED_IN)) as $other) {
            if ($other->id !== $offer->id) {
                return $other;
            }
        }
        return null;
    }

    /**
     * The Held deposits tab's decisions, Mark credit and Forfeit: a
     * compare-and-set out of settlement 'pending' (or '' for money no
     * settlement decided, a late payment on a settled offer) into 'credit' or
     * 'forfeited'. Credit writes nothing to the ledger; a forfeit writes the
     * negative line for the lesser of the offer's target and the credit nobody
     * has promised elsewhere. A double click or two tabs change it once.
     */
    private static function admin_settle($offer_id, $admin_id, $to) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $verb = $to === 'forfeited' ? 'forfeit' : 'mark as credit';
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        if (self::is_live_state($offer->state)) {
            return self::res(false, 'refused', 'This offer is still live; there is nothing to ' . $verb . ' yet.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        if ($offer->settlement === $to) {
            return array_merge(self::already($offer, 'Already decided.'), array('amount_cents' => $offer->settled_amount !== null ? self::to_cents($offer->settled_amount) : 0));
        }
        if (!in_array($offer->settlement, array('pending', ''), true)) {
            return self::res(false, 'refused', 'This deposit has already been decided (' . ($offer->settlement === 'forfeited' ? 'forfeited' : ($offer->settlement === 'credit' ? 'held as credit' : $offer->settlement)) . ').', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $claim = self::live_claim($offer);
        if ($claim) {
            return self::res(false, 'refused', 'This player has another live offer (#' . $claim->id . '), so the money is that place\'s: use Apply to a new offer, or wait until it ends.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        $lock = self::settle_lock($offer);
        if ($lock === false) {
            return self::res(false, 'busy', 'Another request is deciding this deposit: try again in a moment.', array('offer_id' => $offer->id, 'state' => $offer->state));
        }
        try {
            $offer = self::get($offer_id);
            if (!$offer || !in_array($offer->settlement, array('pending', ''), true)) {
                return $offer ? self::already($offer, 'Already decided.') : self::not_found();
            }
            $invoice = self::invoice_for($offer);
            $free = $invoice ? self::unpromised_credit_cents($invoice, $offer->id) : 0;
            if ($to === 'credit' && $offer->settlement === '') {
                $amount = $free; // money nothing decided: all of it
            } else {
                $amount = min(self::stored_target_cents($offer), $free);
            }
            if ($amount <= 0 || !$invoice) {
                return self::res(false, 'refused', 'There is no money held for this offer to ' . $verb . '.', array('offer_id' => $offer->id, 'state' => $offer->state, 'amount_cents' => 0));
            }
            $from = array($offer->settlement);
            if (!self::cas_settlement($offer, $from, $to, $amount, $admin_id)) {
                $now = self::get($offer->id);
                return ($now && $now->settlement === $to) ? self::already($now, 'Already decided.') : self::stale($now);
            }
            if ($to === 'forfeited' && !self::write_forfeit_line($offer, $invoice, $amount)) {
                self::update_where(array('settlement' => $offer->settlement, 'settled_amount' => null, 'settled_by' => null, 'settled_at' => null), 'id = %d AND settlement = %s', array(intval($offer->id), 'forfeited'));
                self::log_alert($offer, 'the forfeit of ' . self::money($amount / 100) . ' could not be written to the ledger, so it was not recorded. Try again.');
                return self::res(false, 'error', 'The forfeit could not be written to the ledger, so nothing changed.', array('offer_id' => $offer->id, 'state' => $offer->state, 'amount_cents' => 0));
            }
            if ($to === 'forfeited') {
                self::log_money($offer, 'deposit of ' . self::money($amount / 100) . ' forfeited by an administrator (a negative forfeit line in the ledger)', -$amount / 100);
            } else {
                self::log_money($offer, self::money($amount / 100) . ' marked as credit by an administrator (the treasurer refunds it)', $amount / 100);
            }
            self::refresh_badge();
            return self::done($offer, $to === 'forfeited' ? 'The deposit was forfeited.' : 'Marked as credit.', array('amount_cents' => $amount));
        } finally {
            self::settle_unlock($lock);
        }
    }

    /**
     * Administrators, the Held deposits tab's "Mark credit": the club will
     * refund this finished offer's money. A compare-and-set out of settlement
     * 'pending' (or '' for money no settlement decided) into 'credit';
     * nothing is written to the ledger (the treasurer refunds by hand with a
     * negative Record Payment). Result extra: amount_cents.
     */
    public static function mark_credit($offer_id, $admin_id) {
        return self::admin_settle($offer_id, $admin_id, 'credit');
    }

    /**
     * Administrators, the Held deposits tab's "Forfeit": keep the money. The
     * same compare-and-set into 'forfeited', writing the negative 'forfeit'
     * ledger line for the lesser of the offer's deposit target and the free
     * credit. Result extra: amount_cents.
     */
    public static function forfeit_held($offer_id, $admin_id) {
        return self::admin_settle($offer_id, $admin_id, 'forfeited');
    }

    /**
     * Administrators, the Held deposits tab's "Apply to a new offer": the
     * finished offer $from_offer_id's held money is freed for the same
     * player's live accepted offer $to_offer_id (the finished offer's
     * settlement becomes 'carried', nothing is written to the ledger) and
     * lock_in_if_met() runs for the new one. Only for one player and season.
     * Result extras: amount_cents, locked_in (bool).
     */
    public static function apply_to_new_offer($from_offer_id, $to_offer_id, $admin_id) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $from = self::get($from_offer_id);
        $to = self::get($to_offer_id);
        if (!$from || !$to) {
            return self::not_found();
        }
        $state = array('offer_id' => $from->id, 'state' => $from->state);
        if (self::is_live_state($from->state)) {
            return self::res(false, 'refused', 'The offer the money is held for is still live.', $state);
        }
        if ($from->settlement === 'carried') {
            return array_merge(self::already($from, 'That money was already applied.'), array('amount_cents' => 0, 'locked_in' => $to->state === self::LOCKED_IN));
        }
        if (!in_array($from->settlement, array('pending', 'credit', ''), true)) {
            return self::res(false, 'refused', 'That deposit was forfeited, so there is no money to apply.', $state);
        }
        if ($to->state !== self::ACCEPTED || intval($to->user_id) !== intval($from->user_id) || (string) $to->season !== (string) $from->season || $to->id === $from->id) {
            return self::res(false, 'refused', 'Money can only be applied to the same player\'s accepted offer in the same season.', $state);
        }
        $lock = self::settle_lock($from);
        if ($lock === false) {
            return self::res(false, 'busy', 'Another request is deciding this deposit: try again in a moment.', $state);
        }
        try {
            $from = self::get($from_offer_id);
            if (!$from || !in_array($from->settlement, array('pending', 'credit', ''), true)) {
                return $from ? self::already($from, 'Already decided.') : self::not_found();
            }
            $invoice = self::invoice_for($from);
            // What the offer had set aside (settled credit, else its target), limited to the credit nobody else has promised: that is freed for the new offer.
            $amount = $from->settlement === 'credit' && $from->settled_amount !== null ? self::to_cents($from->settled_amount) : self::stored_target_cents($from);
            $amount = $invoice ? min($amount, self::unpromised_credit_cents($invoice, $from->id)) : 0;
            if ($amount <= 0) {
                return self::res(false, 'refused', 'There is no money held for that offer to apply.', $state);
            }
            if (!self::cas_settlement($from, array($from->settlement), 'carried', $amount, $admin_id)) {
                $now = self::get($from->id);
                return ($now && $now->settlement === 'carried') ? self::already($now, 'Already applied.') : self::stale($now);
            }
            self::log_money($from, self::money($amount / 100) . ' applied to the player\'s new offer #' . $to->id . ' by an administrator (nothing written to the ledger)', $amount / 100);
        } finally {
            self::settle_unlock($lock);
        }
        $locked = self::lock_in_if_met($to);
        return self::done($from, 'The money was applied to their new offer' . (!empty($locked['locked']) ? ', which is now locked in.' : '.'), array('amount_cents' => $amount, 'locked_in' => !empty($locked['locked'])));
    }

    /**
     * Administrators, the Forfeited list's "Reverse forfeit": a compare-and-set
     * 'forfeited' to 'pending', then a positive 'forfeit_undo' ledger line
     * (its own source, never 'manual': it logs no payment_manual line and the
     * lock-in hook ignores it); the settlement is 'pending' again for the
     * treasurer to decide Credit or Forfeit. If the line can't be written the
     * compare-and-set is put back. The daily job does NOT settle it again by
     * itself (it has been decided once). Result extra: amount_cents.
     */
    public static function reverse_forfeit($offer_id, $admin_id) {
        if (!self::can_approve($admin_id)) {
            return self::forbidden();
        }
        $offer = self::get($offer_id);
        if (!$offer) {
            return self::not_found();
        }
        $state = array('offer_id' => $offer->id, 'state' => $offer->state);
        if ($offer->settlement === 'pending' && $offer->settled_at !== null) {
            return array_merge(self::already($offer, 'That forfeit was already reversed.'), array('amount_cents' => 0));
        }
        if ($offer->settlement !== 'forfeited') {
            return self::res(false, 'refused', 'This deposit was not forfeited.', $state);
        }
        $lock = self::settle_lock($offer);
        if ($lock === false) {
            return self::res(false, 'busy', 'Another request is deciding this deposit: try again in a moment.', $state);
        }
        try {
            $offer = self::get($offer_id);
            if (!$offer || $offer->settlement !== 'forfeited') {
                return $offer ? self::already($offer, 'Already reversed.') : self::not_found();
            }
            $invoice = self::invoice_for($offer);
            $cents = $offer->settled_amount !== null ? self::to_cents($offer->settled_amount) : 0;
            if (!$invoice || $cents <= 0) {
                return self::res(false, 'refused', 'There is no forfeit line to reverse.', $state);
            }
            // The decision keeps settled_at (it was decided once), so nothing settles it again by itself.
            $set = array('settlement' => 'pending', 'settled_by' => intval($admin_id), 'settled_at' => current_time('mysql'));
            if (!self::update_where($set, 'id = %d AND settlement = %s', array(intval($offer->id), 'forfeited'))) {
                $now = self::get($offer->id);
                return ($now && $now->settlement === 'pending') ? self::already($now, 'Already reversed.') : self::stale($now);
            }
            $net = self::forfeit_net_cents($invoice->id, $offer->id);
            $to_give_back = min($cents, max(0, -$net));
            if ($to_give_back > 0 && !TeamOversight_Payments::record_payment(intval($invoice->id), $to_give_back / 100, 'forfeit_undo', self::forfeit_undo_note($offer->id))) {
                self::update_where(array('settlement' => 'forfeited', 'settled_by' => $offer->settled_by, 'settled_at' => $offer->settled_at), 'id = %d AND settlement = %s', array(intval($offer->id), 'pending'));
                self::log_alert($offer, 'the forfeit could not be reversed in the ledger, so nothing changed. Try again.');
                return self::res(false, 'error', 'The reversal could not be written to the ledger, so nothing changed.', array_merge($state, array('amount_cents' => 0)));
            }
            self::log_money($offer, 'forfeit of ' . self::money($cents / 100) . ' reversed by an administrator; the deposit is pending a decision again', $cents / 100);
            return self::done($offer, 'The forfeit was reversed. Decide whether the money is credit or forfeited.', array('amount_cents' => $to_give_back));
        } finally {
            self::settle_unlock($lock);
        }
    }

    // ------------------------------------------------------------------
    // MONEY: the deposit payment (start, order, lock-in)
    // ------------------------------------------------------------------

    /**
     * template_redirect: the player's deposit form (TeamOversight_Offers::
     * DEPOSIT_FORM, design/offer-api.md 6.11) posted on the offer page or the
     * Player Checklist. Does nothing unless this request is that form from a
     * logged-in player; process_deposit_post() does the work and this only
     * redirects (to the checkout, or back to the page with the reason).
     */
    public static function maybe_start_deposit_payment() {
        $form = self::DEPOSIT_FORM;
        if (empty($_POST[$form['action_field']]) || !is_scalar($_POST[$form['action_field']]) || (string) $_POST[$form['action_field']] !== $form['action_value']) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }
        $out = self::process_deposit_post($_POST, get_current_user_id());
        if (!empty($out['handled']) && $out['redirect'] !== '') {
            wp_safe_redirect($out['redirect']);
            exit;
        }
    }

    /**
     * Everything maybe_start_deposit_payment() does except the redirect (so
     * it can be tested). Checks, in this order: the post is the deposit form;
     * the user; the offer exists and is the user's (by account id: a
     * read-only lookup, met with silence when it isn't); the nonce for that
     * offer (a stale page gets a notice); the season applies; the state is
     * accepted; a payment product exists. Then the release drop is written (so the target it
     * charges against is current) and the amount rules run on the server:
     *
     *   remaining = target - free ledger total   (cents)
     *   amount    = remaining, always (one payment, one fixed amount)
     *
     * The page may post the figure it showed (the hidden DEPOSIT_FORM
     * amount_field); if it does and it is not exactly `remaining`, the payment
     * is refused. So a player can't pay part of the deposit, can't pay more
     * than it needs, and is never charged a figure they were not shown (a
     * two-team top-up is just the difference). On success the cart carries the deposit
     * (purpose, offer, invoice, season, team, the amount it was built for).
     * A refusal leaves the player a notice the page shows (the same flash the
     * accept and decline notices use). $post is the slashed request array.
     * Returns array(handled, ok, code, message, redirect, amount_cents,
     * offer_id). handled false = not our form / not the player's offer: no
     * notice, the page shows what it always does.
     */
    public static function process_deposit_post(array $post, $user_id) {
        $form = self::DEPOSIT_FORM;
        $user_id = intval($user_id);
        $out = array('handled' => false, 'ok' => false, 'code' => '', 'message' => '', 'redirect' => '', 'amount_cents' => 0, 'offer_id' => 0);
        if (!isset($post[$form['action_field']]) || !is_scalar($post[$form['action_field']]) || (string) wp_unslash($post[$form['action_field']]) !== $form['action_value']) {
            return $out;
        }
        if ($user_id <= 0 || get_current_user_id() !== $user_id) {
            return $out;
        }
        $offer_id = (isset($post[$form['offer_field']]) && is_scalar($post[$form['offer_field']])) ? absint(wp_unslash((string) $post[$form['offer_field']])) : 0;
        if ($offer_id <= 0) {
            return $out;
        }
        // Read-only lookup first (ownership by account id, in SQL): an offer that isn't theirs or isn't there is met with the same silence everywhere.
        $offer = self::get_for_user($offer_id, $user_id);
        if (!$offer) {
            return $out;
        }
        $out['handled'] = true;
        $out['offer_id'] = $offer->id;
        $back = wp_get_referer();
        $out['redirect'] = $back ? (string) $back : self::offer_url($offer);
        $refuse = function ($code, $message) use (&$out, $user_id) {
            $out['code'] = $code;
            $out['message'] = $message;
            set_transient(self::FLASH_PREFIX . $user_id, array('ok' => false, 'message' => $message), 120);
            return $out;
        };
        $nonce = (isset($post[$form['nonce_field']]) && is_scalar($post[$form['nonce_field']])) ? (string) wp_unslash($post[$form['nonce_field']]) : '';
        if (!wp_verify_nonce($nonce, self::deposit_pay_nonce_action($offer_id))) {
            return $refuse('expired', 'This page has expired. Please check the details below and try again.');
        }
        if (!self::applies($offer->season)) {
            return $refuse('refused', 'This offer can\'t take a deposit.');
        }
        if ($offer->state !== self::ACCEPTED) {
            return $refuse('refused', $offer->state === self::LOCKED_IN
                ? 'Your deposit is already complete and you are locked in.'
                : 'This offer isn\'t waiting for a deposit.');
        }
        if (!TeamOversight_Payments::get_payment_product() || !function_exists('WC')) {
            return $refuse('no_product', 'Online payment isn\'t available yet. Please contact the club to arrange your deposit.');
        }

        // The target it charges against must be current: write the release drop (a no-op unless fees were released while it was incomplete).
        self::apply_release_drop($offer);
        $offer = self::get($offer->id);
        $ensured = self::ensure_holding_invoice($offer);
        if (empty($ensured['ok'])) {
            return $refuse('error', 'We couldn\'t set up your deposit just now. Please try again, or contact the club.');
        }
        $invoice = self::invoice_for(self::get($offer->id));
        if (!$invoice) {
            return $refuse('error', 'We couldn\'t set up your deposit just now. Please try again, or contact the club.');
        }
        $target = self::stored_target_cents($offer);
        $remaining = max(0, $target - self::free_paid_cents($invoice));
        if ($remaining <= 0) {
            $locked = self::lock_in_if_met($offer);
            $out['ok'] = true;
            $out['code'] = 'complete';
            $out['message'] = !empty($locked['locked']) ? 'Your deposit is complete and you are locked in.' : 'Your deposit is already paid in full.';
            set_transient(self::FLASH_PREFIX . $user_id, array('ok' => true, 'message' => $out['message']), 120);
            return $out;
        }

        // One payment, one fixed amount: exactly what is still to pay. The page
        // posts the figure it showed (a hidden field, never a typed amount) so a
        // stale page or a doctored form can't pay part of the deposit or be
        // charged a figure the player didn't see: any other amount is refused.
        $amount_cents = $remaining;
        if (isset($post[$form['amount_field']])) {
            $posted = is_scalar($post[$form['amount_field']]) ? trim((string) wp_unslash($post[$form['amount_field']])) : '';
            $posted_cents = is_numeric($posted) ? self::to_cents(round(floatval($posted), 2)) : -1;
            $problem = self::deposit_amount_problem($posted_cents, $remaining);
            if ($problem !== '') {
                return $refuse('amount', $problem);
            }
        }

        $user = get_userdata($user_id);
        $amount = $amount_cents / 100.0;
        $added = TeamOversight_Payments::add_fee_payment_to_cart($user, $amount, array(
            'purpose' => 'deposit',
            'offer_id' => intval($offer->id),
            'invoice_id' => intval($invoice->id),
            'season' => (string) $offer->season,
            'team' => (string) $offer->team,
            'expected' => $amount,
        ));
        if (!$added) {
            return $refuse('cart', 'We couldn\'t start the payment just now. Please try again, or contact the club.');
        }
        $out['ok'] = true;
        $out['code'] = 'done';
        $out['amount_cents'] = $amount_cents;
        $out['redirect'] = function_exists('wc_get_checkout_url') ? (string) wc_get_checkout_url() : $out['redirect'];
        return $out;
    }

    /**
     * Why a deposit payment of $amount_cents is not allowed against
     * $remaining_cents still to pay ('' when it is). The deposit is paid in
     * one payment: the only amount allowed is exactly what is still to pay
     * (a two-team top-up is just the difference), so there is no minimum,
     * no part payment and no remainder rule. The words say what is expected,
     * so the player can see what changed. Pure.
     */
    public static function deposit_amount_problem($amount_cents, $remaining_cents) {
        $amount_cents = intval($amount_cents);
        $remaining_cents = intval($remaining_cents);
        if ($remaining_cents <= 0) {
            return 'Your deposit is already paid in full.';
        }
        if ($amount_cents !== $remaining_cents) {
            return 'Your deposit is paid in one payment of ' . self::money($remaining_cents / 100) . '. Please check the amount and try again.';
        }
        return '';
    }

    /**
     * The ledger parts of a paid deposit order item (the order hook,
     * handle_payment_order(), calls this instead of its oldest-first
     * allocation). Returns a list of array(invoice_id, amount, over, note) or
     * an empty array when nothing sensible can be done (the item is then
     * flagged unapplied and logged for the treasurer, and the player's older
     * balances are left alone). The whole amount, any overshoot included,
     * goes to ONE invoice: the player's own invoice for the item's season,
     * checked by the strict identity rule. If the invoice named has gone it is
     * found or made again, but only for a player who has an offer in that
     * season. An invoice of another player or another season gets nothing.
     * Logs an offer_alert when what was paid differs from what the cart was
     * built for by a cent or more (tax on the payment product, a changed
     * cart), and when the money lands on a finished offer.
     */
    public static function deposit_parts($payer_id, $invoice_id, $season, $amount, $item_id) {
        global $wpdb;
        $payer = intval($payer_id) > 0 ? get_userdata(intval($payer_id)) : false;
        $season = (string) $season;
        $amount = round(floatval($amount), 2);
        if (!$payer || !$payer->user_email || $amount <= 0 || !self::applies($season)) {
            return array();
        }
        $invoice = intval($invoice_id) > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}team_invoices WHERE id = %d", intval($invoice_id))) : null;
        if ($invoice) {
            $owner = intval($invoice->user_id);
            $is_theirs = $owner === intval($payer->ID) || ($owner <= 0 && strcasecmp((string) $invoice->email, (string) $payer->user_email) === 0);
            if (!$is_theirs || (string) $invoice->season !== $season) {
                self::alert_once('deposit_mismatch_' . $item_id, 'A deposit payment (order item #' . intval($item_id) . ') named an invoice that is not this player\'s ' . $season . ' invoice, so it was not applied: the treasurer must put it right.', $payer->ID);
                return array();
            }
        } else {
            // The invoice has gone (deleted while accepted with nothing paid): the player's own for the season, else made again.
            $invoice = TeamOversight_Fees::find_season_invoice($payer->ID, $payer->user_email, $season);
            if (!$invoice) {
                if (empty(self::for_user($payer->ID, $season))) {
                    return array();
                }
                $made = self::fees()->insert_holding_invoice($payer->ID, $payer->user_email, $season);
                if (intval($made['id']) <= 0) {
                    return array();
                }
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}team_invoices WHERE id = %d", intval($made['id'])));
                if (!$invoice) {
                    return array();
                }
                TeamOversight_Log::add('offer_money', $season . ' deposit payment: the player\'s invoice had gone, so the deposit invoice was made again.', array('user_id' => $payer->ID));
            }
        }
        $offer_id = $item_id > 0 && function_exists('wc_get_order_item_meta') ? intval(wc_get_order_item_meta(intval($item_id), '_murvc_fee_payment_offer', true)) : 0;
        $offer = $offer_id > 0 ? self::get($offer_id) : null;
        $expected = $item_id > 0 && function_exists('wc_get_order_item_meta') ? (string) wc_get_order_item_meta(intval($item_id), '_murvc_fee_payment_expected', true) : '';
        if ($expected !== '' && is_numeric($expected) && abs(floatval($expected) - $amount) >= 0.01) {
            self::alert_once('deposit_expected_' . $item_id, $season . ' deposit payment (order item #' . intval($item_id) . '): $' . number_format($amount, 2) . ' was paid but the cart was built for $' . number_format(floatval($expected), 2) . '. Check the payment product is tax-free; the full amount was credited to the player\'s invoice.', $payer->ID);
        }
        if ($offer && !in_array($offer->state, array(self::ACCEPTED, self::LOCKED_IN), true)) {
            self::alert_once('deposit_finished_' . $item_id, $season . ' ' . $offer->team . ' offer #' . $offer->id . ' had ended (' . $offer->state . ') when a deposit of $' . number_format($amount, 2) . ' was paid. The money is on the player\'s invoice and shows on Held deposits.', $payer->ID);
        }
        $label = $offer ? $season . ' ' . $offer->team . ' offer #' . $offer->id : $season . ' offer';
        return array(array(
            'invoice_id' => intval($invoice->id),
            'amount' => $amount,
            'over' => false,
            'note' => 'Deposit for ' . $label . ', order item #' . intval($item_id),
        ));
    }

    /**
     * Called by TeamOversight_Payments::record_payment() after a positive
     * online or manual payment is recorded. Locks in every accepted offer of
     * the invoice's owner whose deposit the player's free ledger total now
     * meets, cheapest target first (so one payment that reaches two equal
     * targets locks in both). A payment that reaches a player with NO live
     * offer but a finished one is money on a finished offer: an offer_alert
     * says so (it also shows on Held deposits). Never throws and never
     * touches the payment: any failure is logged and swallowed. Inert for a
     * season before 2027 and for a player with no offers.
     */
    public static function on_payment_recorded($invoice_id) {
        global $wpdb;
        try {
            $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}team_invoices WHERE id = %d", intval($invoice_id)));
            if (!$invoice || !self::applies($invoice->season)) {
                return null;
            }
            $offers = self::offers_for_identity(intval($invoice->user_id) > 0 ? $invoice->user_id : 0, $invoice->email, $invoice->season);
            if (empty($offers)) {
                return null;
            }
            $accepted = array();
            $live = false;
            foreach ($offers as $offer) {
                if ($offer->state === self::ACCEPTED) {
                    $accepted[] = $offer;
                    $live = true;
                } elseif ($offer->state === self::LOCKED_IN) {
                    $live = true;
                }
            }
            usort($accepted, function ($a, $b) {
                $ta = self::stored_target_cents($a);
                $tb = self::stored_target_cents($b);
                return $ta === $tb ? $a->id - $b->id : $ta - $tb;
            });
            $locked = 0;
            foreach ($accepted as $offer) {
                $result = self::lock_in_if_met($offer);
                if (!empty($result['locked'])) {
                    $locked++;
                }
            }
            if (!$live) {
                $credit = max(0, self::ledger_cents($invoice->id) - self::to_cents($invoice->invoice_amount));
                if ($credit > 0) {
                    $latest = $offers[0];
                    self::alert_once('finished_money_' . $invoice->id . '_' . $credit, $latest->season . ' ' . $latest->team . ' offer #' . $latest->id . ' (' . $latest->state . '): a payment arrived on the player\'s invoice, which now holds ' . self::money($credit / 100) . ' of credit and no live offer. It is on Held deposits for a decision.', $latest->user_id);
                }
            }
            return $locked;
        } catch (\Throwable $e) {
            try {
                TeamOversight_Log::add('offer_alert', 'The offers hook for a payment on invoice #' . intval($invoice_id) . ' failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . '). The payment itself is recorded; the reconcile job locks in anyone it missed.');
            } catch (\Throwable $ignore) {
                // nothing more can be done
            }
            return null;
        }
    }

    // ------------------------------------------------------------------
    // MONEY: fees released while a deposit is incomplete
    // ------------------------------------------------------------------

    /**
     * The write version of the release drop (design 4.8): an offer quoted on
     * the upper limit that is not locked in, once its season's fees are
     * released, has its deposit target dropped to the percent of the REAL
     * season quote (the player's other accepted / locked places counted),
     * never raised: target = min(stored, real). quote_released is stamped
     * (capped at the promise, quote_amount) and target_dropped_at when the
     * target fell; both are written in one compare-and-set that requires the
     * release not to have been written yet. A real fee cell that is still
     * blank cannot be priced: nothing changes and an alert says so (the daily
     * job retries). Reads never write: pages use effective_target().
     * Result extras: target (money, after the call), changed (this call wrote
     * the release: quote_released is stamped), dropped (the target fell).
     */
    public static function apply_release_drop($offer) {
        $offer = ($offer && !empty($offer->id)) ? self::get($offer->id) : null;
        if (!$offer) {
            return self::not_found();
        }
        $base = array('offer_id' => $offer->id, 'state' => $offer->state, 'target' => $offer->deposit_target !== null ? floatval($offer->deposit_target) : 0.0, 'changed' => false, 'dropped' => false);
        $nothing = function ($code, $message, $ok = true) use ($base) {
            return array_merge(self::res($ok, $code, $message), $base);
        };
        if (!in_array($offer->state, array(self::SENT, self::ACCEPTED), true)) {
            return $nothing('not_unlocked', 'Only an offer that is not locked in has a release drop.');
        }
        if ($offer->quote_basis !== 'upper' || $offer->deposit_target === null) {
            return $nothing('not_upper', 'This offer was quoted on the real fee: there is nothing to drop.');
        }
        if (!self::applies($offer->season) || !TeamOversight_Fees::fees_released($offer->season)) {
            return $nothing('not_released', 'The season\'s fees are not released yet.');
        }
        if ($offer->quote_released !== null) {
            return $nothing('already', 'The release drop was already written.');
        }
        $real = self::compute_quote($offer->user_id, $offer->season, $offer->team, $offer->role, array('offer' => $offer, 'mode' => 'real'));
        if (!$real['ok']) {
            self::alert_once('drop_' . $offer->id, $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': the season\'s fees are released but the real fee for this player can\'t be worked out yet (' . (string) $real['error_admin'] . '). Their deposit stays at the quoted figure until it can; the daily job tries again.', $offer->user_id);
            return array_merge(self::res(false, 'no_price', 'The real fee can\'t be worked out yet.'), $base);
        }
        $stored_cents = self::to_cents($offer->deposit_target);
        $new_cents = min($stored_cents, intval($real['target_cents']));
        $set = array('quote_released' => round(min(floatval($real['amount']), floatval($offer->quote_amount)), 2));
        if ($new_cents < $stored_cents) {
            $set['deposit_target'] = $new_cents / 100.0;
            $set['target_dropped_at'] = current_time('mysql');
        }
        list($where, $args) = self::pin($offer, array(self::SENT, self::ACCEPTED));
        $where .= ' AND quote_released IS NULL AND deposit_target = %s';
        $args[] = number_format($stored_cents / 100, 2, '.', '');
        if (!self::update_where($set, $where, $args)) {
            return $nothing('stale', 'The offer changed meanwhile: nothing was written.');
        }
        if ($new_cents < $stored_cents) {
            self::log_money($offer, 'deposit target dropped from ' . self::money($stored_cents / 100) . ' to ' . self::money($new_cents / 100) . ' (the season\'s fees are released)', $new_cents / 100);
        } else {
            self::log_money($offer, 'fees released: the real fee needs a deposit of ' . self::money($stored_cents / 100) . ', unchanged');
        }
        return array_merge(self::res(true, 'done', 'The deposit target was updated for the released fee.'), $base, array('changed' => true, 'dropped' => $new_cents < $stored_cents, 'target' => $new_cents / 100.0));
    }

    /**
     * Called by TeamOversight_Fees::apply_fees_for_season() after it stamps
     * the release (inside its own try/catch): every sent or accepted offer of
     * the season quoted on the upper limit gets its release drop, each in its
     * own try/catch so one failure never stops the rest, and an accepted one
     * whose payments now meet the lower target locks in. Returns
     * array(checked, dropped, locked, failed).
     */
    public static function on_fees_released($season) {
        global $wpdb;
        $season = (string) $season;
        if (!self::applies($season)) {
            return null;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE season = %s AND state IN ('sent', 'accepted') AND quote_basis = 'upper' AND quote_released IS NULL ORDER BY id",
            $season
        ));
        $out = array('checked' => 0, 'dropped' => 0, 'locked' => 0, 'failed' => 0);
        foreach ((array) $rows as $row) {
            $offer = self::normalise($row);
            $out['checked']++;
            try {
                $result = self::apply_release_drop($offer);
                if (!empty($result['dropped'])) {
                    $out['dropped']++;
                }
                if ($offer->state === self::ACCEPTED) {
                    $locked = self::lock_in_if_met($offer);
                    if (!empty($locked['locked'])) {
                        $out['locked']++;
                    }
                }
            } catch (\Throwable $e) {
                $out['failed']++;
                TeamOversight_Log::add('offer_alert', $season . ' fee release: the deposit target of offer #' . intval($offer->id) . ' could not be updated (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . '). The daily job tries again.');
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // MONEY: the cap on an upper-limit quote
    // ------------------------------------------------------------------

    /**
     * The largest upper-limit quote among a player's offers that were ever
     * locked in for the invoice's season: the ceiling the cap holds the
     * invoice to, for the Edit Fee warning ("above the maximum quoted in the
     * offer") and Record Payment. $invoice: a team_invoices row (user_id,
     * email, season). Returns array(amount (float), offer_id) or null (no
     * such offer, a season before 2027, or no invoice). The offers are
     * matched by account id (an invoice with no account, by its email),
     * whatever state they are in now, the way the cap itself finds them.
     */
    public static function cap_ceiling_for_invoice($invoice) {
        global $wpdb;
        if (!$invoice || !isset($invoice->season) || !self::applies($invoice->season)) {
            return null;
        }
        if (!empty($invoice->user_id) && intval($invoice->user_id) > 0) {
            $who = 'user_id = %d';
            $who_arg = intval($invoice->user_id);
        } elseif (!empty($invoice->email)) {
            $who = '(user_id IS NULL OR user_id = 0) AND email = %s';
            $who_arg = (string) $invoice->email;
        } else {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, quote_amount FROM " . self::table() . " WHERE season = %s AND $who AND quote_basis = 'upper' AND locked_in_at IS NOT NULL AND quote_amount IS NOT NULL ORDER BY quote_amount DESC, id LIMIT 1",
            (string) $invoice->season, $who_arg
        ));
        return $row ? array('amount' => floatval($row->quote_amount), 'offer_id' => intval($row->id)) : null;
    }

    /**
     * The offers that can cap a player's fee in a season: quoted on the upper
     * limit and locked in at some point, WHATEVER state they are in now (a
     * player who left or was withdrawn keeps the cap for the time they
     * played). One query per season per request, grouped by account id (an
     * offer with an account) and by the snapshot email (only an offer with no
     * account: strict identity); every write to the offers (flush_cache())
     * forgets it. Returns array(by_user => user id => rows, by_email => lower
     * email => rows), a row being array(id, team, role, quote_cents).
     */
    private static function cap_offers($season) {
        global $wpdb;
        $season = (string) $season;
        self::money_hook();
        if (isset(self::$cap_cache[$season])) {
            return self::$cap_cache[$season];
        }
        $cache = array('by_user' => array(), 'by_email' => array());
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, email, team, role, quote_amount FROM " . self::table() . " WHERE season = %s AND quote_basis = 'upper' AND locked_in_at IS NOT NULL AND quote_amount IS NOT NULL ORDER BY id",
            $season
        ));
        foreach ((array) $rows as $row) {
            $entry = array('id' => intval($row->id), 'team' => (string) $row->team, 'role' => (string) $row->role, 'quote_cents' => self::to_cents($row->quote_amount));
            // Strict identity, as cap_ceiling_for_invoice(): an offer with an account belongs to that account only; only an
            // offer with none is matched by its email snapshot (a reused address must never inherit someone else's cap).
            if (intval($row->user_id) > 0) {
                $cache['by_user'][intval($row->user_id)][] = $entry;
            } elseif ((string) $row->email !== '') {
                $cache['by_email'][strtolower((string) $row->email)][] = $entry;
            }
        }
        self::$cap_cache[$season] = $cache;
        return $cache;
    }

    /**
     * The cap inside fee_breakdown() (design 3.6): a player whose place came
     * from an offer quoted on the upper limit is never invoiced above the
     * quoted amount. $result is the price_stretches() result of a breakdown
     * priced on the REAL fee (fee_breakdown() never asks for an upper one).
     *
     * Per stretch: it is COVERED when a bucket whose rate equals the
     * stretch's top rate (ties count, so the alphabetically first bucket is
     * not special) is a team and role a cap offer was made for. The covered
     * stretches' amounts add up; every other stretch (an admin-added place, a
     * coach or team manager row that sets the rate, a place from an offer
     * quoted on the real fee) is left alone and is never capped itself. If
     * the covered sum is above the largest matching quote (compared in
     * cents) the total becomes that quote plus the uncovered stretches. The
     * stretches' own amounts stay as priced, so the working still shows.
     *
     * Returns null (no cap) or array(amount (the ceiling), offer_id (the offer
     * that set it), uncapped_total, covered (the covered sum), total (the
     * capped total fee_breakdown() uses)). Reads only; the invoice writers
     * log it (log_cap()). The first lines return null for a season before
     * 2027, a breakdown with no stretches and a player with no cap offer.
     */
    public static function cap_for_breakdown($email, $season, $result) {
        $season = (string) $season;
        if (!is_array($result) || empty($result['stretches']) || !self::applies($season)) {
            return null;
        }
        $offers = self::cap_offers($season);
        if (empty($offers['by_user']) && empty($offers['by_email'])) {
            return null;
        }
        // Strict identity (the rule cap_ceiling_for_invoice() and the season invoice follow): a person with an account is
        // capped only by that account's offers; only a person with no account by the account-less offers under the email.
        $uid = TeamOversight_Fees::resolve_user_id((string) $email);
        $mine = array();
        if ($uid > 0) {
            if (isset($offers['by_user'][$uid])) {
                foreach ($offers['by_user'][$uid] as $entry) {
                    $mine[$entry['id']] = $entry;
                }
            }
        } else {
            $lower = strtolower((string) $email);
            if (isset($offers['by_email'][$lower])) {
                foreach ($offers['by_email'][$lower] as $entry) {
                    $mine[$entry['id']] = $entry;
                }
            }
        }
        if (empty($mine)) {
            return null;
        }
        $covered_sum = 0.0;
        $uncovered_sum = 0.0;
        $ceiling_cents = 0;
        $ceiling_offer = 0;
        $any_covered = false;
        foreach ($result['stretches'] as $stretch) {
            $amount = (isset($stretch['rate']) ? floatval($stretch['rate']) : 0.0) * (isset($stretch['share']) ? floatval($stretch['share']) : 0.0);
            $covered = false;
            if (!empty($stretch['top']) && isset($stretch['top']['rate'])) {
                $top_rate = floatval($stretch['top']['rate']);
                foreach ((array) $stretch['buckets'] as $bucket) {
                    if (!isset($bucket['rate']) || $bucket['rate'] === null || abs(floatval($bucket['rate']) - $top_rate) >= 0.005) {
                        continue;
                    }
                    foreach ($mine as $entry) {
                        if ($entry['team'] === (string) $bucket['team'] && $entry['role'] === (string) $bucket['role']) {
                            $covered = true;
                            if ($ceiling_offer === 0 || $entry['quote_cents'] > $ceiling_cents) {
                                $ceiling_cents = $entry['quote_cents'];
                                $ceiling_offer = $entry['id'];
                            }
                        }
                    }
                }
            }
            if ($covered) {
                $any_covered = true;
                $covered_sum += $amount;
            } else {
                $uncovered_sum += $amount;
            }
        }
        if (!$any_covered || self::to_cents($covered_sum) <= $ceiling_cents) {
            return null;
        }
        $ceiling = $ceiling_cents / 100.0;
        return array(
            'amount' => $ceiling,
            'offer_id' => $ceiling_offer,
            'uncapped_total' => round(floatval($result['total']), 2),
            'covered' => round($covered_sum, 2),
            'total' => round($ceiling + $uncovered_sum, 2),
        );
    }

    /**
     * Log a cap an invoice writer has just applied, once per uncapped amount
     * (an offer_money line recording the uncapped and the capped amounts;
     * compared with team_offers.cap_logged, so a second re-price at the same
     * figure is silent). Called by TeamOversight_Fees' invoice writers. Never
     * throws into them. Returns true when a line was written.
     */
    public static function log_cap($offer_id, $uncapped_total, $charged, $user_id, $season) {
        global $wpdb;
        $offer = self::get($offer_id);
        if (!$offer) {
            return false;
        }
        $uncapped = number_format(round(floatval($uncapped_total), 2), 2, '.', '');
        $changed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET cap_logged = %s WHERE id = %d AND (cap_logged IS NULL OR cap_logged <> %s)',
            $uncapped, intval($offer_id), $uncapped
        ));
        if (!$changed) {
            return false;
        }
        self::flush_cache();
        TeamOversight_Log::add(
            'offer_money',
            (string) $season . ' ' . $offer->team . ' offer #' . $offer->id . ': the fee was held to the maximum quoted in the offer (' . self::money($offer->quote_amount) . '): the calculation gave ' . self::money($uncapped) . ', charged ' . self::money($charged) . '.',
            intval($user_id) > 0 ? array('user_id' => intval($user_id), 'amount' => $charged) : array('amount' => $charged)
        );
        return true;
    }

    // ------------------------------------------------------------------
    // MONEY: safety nets (reconcile and the daily retries)
    // ------------------------------------------------------------------

    /**
     * The money half of reconcile() (design 5.3, idempotent, each step in
     * its own try/catch): (1) accepted offers whose ledger already meets the
     * target are locked in; (2) locked-in offers with no placed_at whose
     * place exists (an earlier Finalise retry placed them) are stamped;
     * (3) locked-in offers that were placed and whose place has since gone
     * (removed while the old code ran) end as left / pending; (6) pending
     * settlements are resolved (declined and moved-to-declined as forfeits,
     * withdrawn as credit; a left one stays pending for the treasurer; one an
     * administrator reversed is never settled again by itself) and a
     * forfeited offer with no ledger line gets it; (7) outbox gaps: an
     * offer, withdrawn or locked-in message missing in the last three days
     * is queued, an older gap raises an alert; (8) accepted offers with no
     * season invoice get the holding invoice. Steps 4 and 5 are the core's
     * (reconcile_state()). Returns array(step => count).
     */
    public static function reconcile_money() {
        global $wpdb;
        $t = self::table();
        $out = array('locked_in' => 0, 'stamped' => 0, 'left' => 0, 'settled' => 0, 'forfeit_lines' => 0, 'outbox' => 0, 'holding_invoices' => 0, 'failed' => 0);
        $guard = function ($name, $fn) use (&$out) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $out['failed']++;
                TeamOversight_Log::add('offer_alert', 'Reconcile (' . $name . ') failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
            }
        };

        // 8. an accepted offer needs a season invoice to receive the deposit (before 1, so a $0 target can lock in).
        $guard('holding invoices', function () use ($wpdb, $t, &$out) {
            foreach ((array) $wpdb->get_results("SELECT * FROM $t WHERE state = 'accepted' ORDER BY id") as $row) {
                $offer = self::normalise($row);
                if (!self::applies($offer->season) || self::invoice_for($offer)) {
                    continue;
                }
                $made = self::ensure_holding_invoice($offer);
                if (!empty($made['created'])) {
                    $out['holding_invoices']++;
                }
            }
        });

        // 1. the payment hook is a convenience, not the only path.
        $guard('lock in', function () use ($wpdb, $t, &$out) {
            $rows = $wpdb->get_results("SELECT * FROM $t WHERE state = 'accepted' ORDER BY deposit_target, id");
            foreach ((array) $rows as $row) {
                $offer = self::normalise($row);
                if (!self::applies($offer->season)) {
                    continue;
                }
                $result = self::lock_in_if_met($offer);
                if (!empty($result['locked']) && $result['code'] !== 'already') {
                    $out['locked_in']++;
                }
            }
        });

        // 2 and 3. locked in: stamp a placement that already exists; end one whose place has gone.
        $guard('placements', function () use ($wpdb, $t, &$out) {
            $today = current_time('Y-m-d');
            foreach ((array) $wpdb->get_results("SELECT * FROM $t WHERE state = 'locked_in' ORDER BY id") as $row) {
                $offer = self::normalise($row);
                if (!self::applies($offer->season)) {
                    continue;
                }
                $user = !empty($offer->user_id) ? get_userdata($offer->user_id) : false;
                if (!$user) {
                    continue;
                }
                $open = null;
                foreach (TeamOversight_Fee_Timeline::rows($user->user_email, $offer->season) as $place) {
                    if ((string) $place->team === (string) $offer->team && in_array($place->role, array('playing_member', 'training_only'), true) && ($place->end_date === null || $place->end_date > $today)) {
                        $open = $place;
                        break;
                    }
                }
                $placed = $offer->placed_at !== null && (string) $offer->placed_at !== '';
                if (!$placed && $open) {
                    self::update_where(array('place_id' => intval($open->id), 'placed_at' => current_time('mysql'), 'placement_error' => null), 'id = %d AND state = %s AND placed_at IS NULL', array(intval($offer->id), self::LOCKED_IN));
                    self::mark_application_placed($offer);
                    self::check_pricing($offer);
                    self::log_flow($offer, 'reconcile: the player was already placed (place #' . intval($open->id) . '), the offer is stamped');
                    $out['stamped']++;
                } elseif ($placed && !$open) {
                    if (self::end_left($offer, 'place removed (found by reconcile)')) {
                        $out['left']++;
                    }
                }
            }
        });

        // 6. settlements nobody has decided.
        $guard('settlements', function () use ($wpdb, $t, &$out) {
            $rows = $wpdb->get_results("SELECT * FROM $t WHERE live IS NULL AND settlement = 'pending' AND settled_at IS NULL AND state IN ('declined', 'moved_declined', 'withdrawn') ORDER BY id");
            foreach ((array) $rows as $row) {
                $offer = self::normalise($row);
                $result = self::settle($offer->id, $offer->state === self::WITHDRAWN ? 'credit' : 'forfeit');
                if (!empty($result['ok']) && !empty($result['changed'])) {
                    $out['settled']++;
                }
            }
            // A forfeit decided but never written to the ledger (a crash between the two).
            $forfeited = $wpdb->get_results("SELECT * FROM $t WHERE live IS NULL AND settlement = 'forfeited' AND settled_amount IS NOT NULL AND settled_amount > 0 ORDER BY id");
            foreach ((array) $forfeited as $row) {
                $offer = self::normalise($row);
                $invoice = self::invoice_for($offer);
                $cents = self::to_cents($offer->settled_amount);
                if (!$invoice || self::forfeit_net_cents($invoice->id, $offer->id) <= -$cents) {
                    continue;
                }
                if (self::write_forfeit_line($offer, $invoice, $cents)) {
                    self::log_money($offer, 'reconcile wrote the forfeit line the ledger was missing (' . self::money($cents / 100) . ')', -$cents / 100);
                    $out['forfeit_lines']++;
                } else {
                    self::alert_once('forfeit_line_' . $offer->id, $offer->season . ' ' . $offer->team . ' offer #' . $offer->id . ': the forfeit of ' . self::money($cents / 100) . ' is decided but its ledger line could not be written. Check the Forfeited list.', $offer->user_id);
                }
            }
        });

        // 7. outbox gaps.
        $guard('outbox', function () use ($wpdb, $t, &$out) {
            if (!class_exists('TeamOversight_Offer_Emails') || !method_exists('TeamOversight_Offer_Emails', 'status_for_keys')) {
                return;
            }
            $recent = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 3 * DAY_IN_SECONDS);
            $checks = array(
                array('offer', "SELECT * FROM $t WHERE state IN ('sent', 'accepted', 'locked_in') AND sent_at IS NOT NULL ORDER BY id", 'sent_at'),
                array('withdrawn', "SELECT * FROM $t WHERE state = 'withdrawn' ORDER BY id", 'closed_at'),
                array('locked_in', "SELECT * FROM $t WHERE state = 'locked_in' AND locked_in_at IS NOT NULL ORDER BY id", 'locked_in_at'),
            );
            foreach ($checks as $check) {
                list($type, $sql, $when_col) = $check;
                $offers = array();
                $keys = array();
                foreach ((array) $wpdb->get_results($sql) as $row) {
                    $offer = self::normalise($row);
                    $offers[$offer->id] = $offer;
                    $keys[$offer->id] = $type . ':' . $offer->id;
                }
                if (empty($keys)) {
                    continue;
                }
                $have = TeamOversight_Offer_Emails::status_for_keys(array_values($keys));
                foreach ($offers as $id => $offer) {
                    if (isset($have[$keys[$id]])) {
                        continue;
                    }
                    $when = $offer->$when_col;
                    if ($when !== null && (string) $when >= $recent) {
                        $context = $type === 'withdrawn' ? array('held_cents' => 0, 'by' => 0) : array();
                        self::queue($type, $offer, $context);
                        $out['outbox']++;
                    } else {
                        self::alert_once('outbox_gap_' . $type . '_' . $id, $offer->season . ' ' . $offer->team . ' offer #' . $id . ' (' . $offer->state . '): the ' . $type . ' email was never queued and it is too long ago to send now. Check the player was told.', $offer->user_id);
                    }
                }
            }
        });

        if ($out['locked_in'] || $out['stamped'] || $out['left'] || $out['settled'] || $out['forfeit_lines'] || $out['outbox'] || $out['holding_invoices']) {
            TeamOversight_Log::add('offer_flow', 'Reconcile (money): ' . $out['locked_in'] . ' locked in, ' . $out['stamped'] . ' placement(s) stamped, ' . $out['left'] . ' ended as left, ' . $out['settled'] . ' settled, ' . $out['forfeit_lines'] . ' forfeit line(s) written, ' . $out['outbox'] . ' email(s) queued, ' . $out['holding_invoices'] . ' holding invoice(s) made.');
        }
        return $out;
    }

    /**
     * Daily retries (design 5.3 steps 3 and 4), each offer at most once a day
     * (last_retry_at): a locked-in player with no placement is placed again
     * (the cutoff may have been moved, the overlap cleared); one with a pricing
     * error is re-priced (the fee cell may be filled in now) and the alert
     * cleared; an unlocked upper-limit offer in a released season whose real
     * fee cell was blank gets its release drop again (and locks in if the
     * lower target is met). Returns array(placed, priced, dropped, failed).
     */
    public static function daily_retry_money() {
        global $wpdb;
        $t = self::table();
        $out = array('placed' => 0, 'priced' => 0, 'dropped' => 0, 'failed' => 0);
        $due = date('Y-m-d H:i:s', strtotime(current_time('mysql')) - 20 * HOUR_IN_SECONDS);

        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t WHERE state = 'locked_in' AND (placed_at IS NULL OR (pricing_error IS NOT NULL AND pricing_error <> '')) AND (last_retry_at IS NULL OR last_retry_at < %s) ORDER BY id",
                $due
            ));
            foreach ((array) $rows as $row) {
                $offer = self::normalise($row);
                if (!self::applies($offer->season)) {
                    continue;
                }
                try {
                    self::update_where(array('last_retry_at' => current_time('mysql')), 'id = %d', array($offer->id));
                    if ($offer->placed_at === null || (string) $offer->placed_at === '') {
                        $placed = self::place($offer);
                        if (!empty($placed['ok'])) {
                            $out['placed']++;
                        }
                    } else {
                        // Re-price under the account's email (an existing invoice is re-priced; a manual fee is kept), then look again.
                        self::fees()->generate_invoice(self::account_email($offer), $offer->season);
                        if (self::check_pricing($offer)) {
                            $out['priced']++;
                        }
                    }
                } catch (\Throwable $e) {
                    $out['failed']++;
                    TeamOversight_Log::add('offer_alert', 'The daily retry for offer #' . intval($offer->id) . ' failed (' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ').');
                }
            }
        } catch (\Throwable $e) {
            $out['failed']++;
            TeamOversight_Log::add('offer_alert', 'The daily placement retry failed (' . get_class($e) . ').');
        }

        try {
            $rows = $wpdb->get_results("SELECT * FROM $t WHERE state IN ('sent', 'accepted') AND quote_basis = 'upper' AND quote_released IS NULL ORDER BY id");
            foreach ((array) $rows as $row) {
                $offer = self::normalise($row);
                if (!self::applies($offer->season) || !TeamOversight_Fees::fees_released($offer->season)) {
                    continue;
                }
                try {
                    $result = self::apply_release_drop($offer);
                    if (!empty($result['dropped'])) {
                        $out['dropped']++;
                    }
                    if ($offer->state === self::ACCEPTED) {
                        self::lock_in_if_met($offer);
                    }
                } catch (\Throwable $e) {
                    $out['failed']++;
                    TeamOversight_Log::add('offer_alert', 'The daily release-drop retry for offer #' . intval($offer->id) . ' failed (' . get_class($e) . ').');
                }
            }
        } catch (\Throwable $e) {
            $out['failed']++;
            TeamOversight_Log::add('offer_alert', 'The daily release-drop retry failed (' . get_class($e) . ').');
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // MONEY: the treasurer's lists
    // ------------------------------------------------------------------

    /**
     * Held deposits for the treasurer, derived from the LEDGER, not from
     * offer flags: one row per season invoice (2027 and later) that has at
     * least one finished offer in the season and whose credit (ledger total
     * less the invoice amount) is above zero, newest first. A player who is
     * locked in is never listed (their money is paying for a place); a player
     * with a live ACCEPTED offer is listed only for money a finished offer is
     * still holding (settlement pending or credit), with can_apply true, so
     * the treasurer can Apply it to the new offer. So a late payment on a
     * settled offer, an overshoot and a plain withdrawal all appear, and two
     * offers can never each claim the same money. A row is an array with
     * EXACTLY these keys:
     *   invoice_id (int), user_id (int), email, name, season,
     *   paid_cents (int), invoice_cents (int), credit_cents (int),
     *   offer_id (int, the latest finished offer; one that was accepted wins
     *   over one that never was), team, ended_state (that offer's state),
     *   ended_at (its closed_at), settlement ('' | 'pending' | 'credit' |
     *   'carried' | 'forfeited'), decision ('none' | 'pending' | 'credit':
     *   '' , 'carried' and 'forfeited' read as 'none'), decided_by (int),
     *   decided_at ('' when none), settled_cents (int, the settled amount of a
     *   credit decision, else 0), can_apply (bool: the player has a live
     *   accepted offer to apply it to), apply_offer_id (int, that offer, else 0).
     * A pure read.
     */
    public static function held_deposits($season) {
        global $wpdb;
        $season = (string) $season;
        if (!self::applies($season)) {
            return array();
        }
        $t = self::table();
        $i = $wpdb->prefix . 'team_invoices';
        $p = $wpdb->prefix . 'team_invoice_payments';
        $invoices = $wpdb->get_results($wpdb->prepare("
            SELECT i.id, i.user_id, i.email, i.name, i.season, i.invoice_amount, ROUND(COALESCE(SUM(p.amount), 0) * 100) AS paid_cents
            FROM $i i
            LEFT JOIN $p p ON p.invoice_id = i.id
            WHERE i.season = %s
              AND EXISTS (
                  SELECT 1 FROM $t o
                  WHERE o.season = i.season AND o.live IS NULL
                    AND ((i.user_id > 0 AND o.user_id = i.user_id) OR ((i.user_id IS NULL OR i.user_id = 0) AND o.email = i.email))
              )
            GROUP BY i.id, i.user_id, i.email, i.name, i.season, i.invoice_amount
            HAVING paid_cents > ROUND(i.invoice_amount * 100)
        ", $season));
        $rows = array();
        foreach ((array) $invoices as $invoice) {
            $offers = self::offers_for_identity($invoice->user_id, $invoice->email, $season);
            $live_accepted = 0;
            $locked_live = false;
            $finished = array();
            $awaiting = array(); // finished offers whose money still waits for a decision (pending or credit)
            foreach ($offers as $offer) { // newest first
                if ($offer->state === self::ACCEPTED) {
                    $live_accepted = $offer->id;
                } elseif ($offer->state === self::LOCKED_IN) {
                    $locked_live = true;
                } elseif ($offer->live === null) {
                    $finished[] = $offer;
                    if (in_array($offer->settlement, array('pending', 'credit'), true)) {
                        $awaiting[] = $offer;
                    }
                }
            }
            // A player who is locked in is paying for a place: nothing is "held". A player with a live ACCEPTED offer is listed only for money a finished offer is still holding (the deposit they are paying now is not), so the treasurer can Apply it to the new offer.
            if ($locked_live || empty($finished) || ($live_accepted > 0 && empty($awaiting))) {
                continue;
            }
            $pick = $live_accepted > 0 ? $awaiting[0] : $finished[0];
            if ($live_accepted === 0) {
                foreach ($finished as $candidate) {
                    if ($candidate->accepted_at !== null && (string) $candidate->accepted_at !== '') {
                        $pick = $candidate;
                        break;
                    }
                }
            }
            $paid = intval($invoice->paid_cents);
            $fee = self::to_cents($invoice->invoice_amount);
            $decision = $pick->settlement === 'credit' ? 'credit' : ($pick->settlement === 'pending' ? 'pending' : 'none');
            $rows[] = array(
                'invoice_id' => intval($invoice->id),
                'user_id' => intval($invoice->user_id),
                'email' => (string) $invoice->email,
                'name' => (string) $invoice->name,
                'season' => $season,
                'paid_cents' => $paid,
                'invoice_cents' => $fee,
                'credit_cents' => max(0, $paid - $fee),
                'offer_id' => intval($pick->id),
                'team' => (string) $pick->team,
                'ended_state' => (string) $pick->state,
                'ended_at' => $pick->closed_at !== null ? (string) $pick->closed_at : '',
                'settlement' => (string) $pick->settlement,
                'decision' => $decision,
                'decided_by' => $pick->settled_by !== null ? intval($pick->settled_by) : 0,
                'decided_at' => $pick->settled_at !== null ? (string) $pick->settled_at : '',
                'settled_cents' => ($pick->settlement === 'credit' && $pick->settled_amount !== null) ? self::to_cents($pick->settled_amount) : 0,
                'can_apply' => $live_accepted > 0,
                'apply_offer_id' => $live_accepted,
            );
        }
        // Newest ending first.
        usort($rows, function ($a, $b) {
            $cmp = strcmp($b['ended_at'], $a['ended_at']);
            return $cmp !== 0 ? $cmp : $b['offer_id'] - $a['offer_id'];
        });
        return $rows;
    }

    /**
     * The Forfeited list (money the club kept): finished offers settled
     * 'forfeited', newest first. A row has EXACTLY: offer_id, user_id, email,
     * name, season, team, ended_state, forfeited_cents (int), settled_by (int),
     * settled_at (datetime string), invoice_id (int, 0 when the invoice has
     * gone). A pure read.
     */
    public static function forfeited_deposits($season) {
        global $wpdb;
        $season = (string) $season;
        if (!self::applies($season)) {
            return array();
        }
        $rows = array();
        $offers = self::normalise_all($wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE season = %s AND settlement = 'forfeited' ORDER BY settled_at DESC, id DESC",
            $season
        )));
        foreach ($offers as $offer) {
            $invoice = self::invoice_for($offer);
            $rows[] = array(
                'offer_id' => intval($offer->id),
                'user_id' => intval($offer->user_id),
                'email' => (string) $offer->email,
                'name' => (string) $offer->name,
                'season' => (string) $offer->season,
                'team' => (string) $offer->team,
                'ended_state' => (string) $offer->state,
                'forfeited_cents' => $offer->settled_amount !== null ? self::to_cents($offer->settled_amount) : 0,
                'settled_by' => $offer->settled_by !== null ? intval($offer->settled_by) : 0,
                'settled_at' => $offer->settled_at !== null ? (string) $offer->settled_at : '',
                'invoice_id' => $invoice ? intval($invoice->id) : 0,
            );
        }
        return $rows;
    }
}
