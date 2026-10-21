<?php

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Fees {
    
    public function __construct() {
        add_action('wp_ajax_save_fee_matrix', array($this, 'save_fee_matrix'));
        add_action('wp_ajax_save_version', array($this, 'save_version'));
        add_action('wp_ajax_restore_version', array($this, 'restore_version'));
        add_action('wp_ajax_save_team', array($this, 'save_team'));
        add_action('wp_ajax_update_team', array($this, 'update_team'));
        add_action('wp_ajax_delete_team', array($this, 'delete_team'));
        add_action('wp_ajax_reset_default_teams', array($this, 'reset_default_teams'));
        // Fee release dates are checked once a day, on the membership cron.
        add_action(TeamOversight_Memberships::CRON_HOOK, array(__CLASS__, 'apply_due_releases'));
    }

    /**
     * Sanitized gender + age-limit meta from a team save/update request.
     */
    private function get_posted_team_meta() {
        $gender = isset($_POST['team_gender']) ? sanitize_text_field($_POST['team_gender']) : 'mixed';
        if (!in_array($gender, array('mens', 'womens', 'mixed'), true)) {
            $gender = 'mixed';
        }
        $age_rule = isset($_POST['team_age_rule']) ? sanitize_text_field($_POST['team_age_rule']) : '';
        if (!array_key_exists($age_rule, TeamOversight_Database::get_age_rules())) {
            $age_rule = '';
        }
        $shirts = isset($_POST['team_shirts']) ? max(0, min(5, intval($_POST['team_shirts']))) : 1;
        return array('gender' => $gender, 'age_rule' => $age_rule, 'shirts' => $shirts);
    }
    
    /**
     * Manually-entered season start/end dates (Configuration page), used
     * for pro-rata fees and the payment schedule. Null until both are set.
     */
    public static function get_season_dates($season) {
        $all = get_option('team_oversight_season_dates', array());
        if (!empty($all[$season]['start']) && !empty($all[$season]['end'])) {
            return $all[$season];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Fee timeline (1.70, from the 2027 season). Each team place has a real
    // start and stop date and the fee follows those dates (1.70.1), and
    // each player's fee falls due across their own part of a payment
    // window the club sets (1.70.2): "Fees due from" to "Paid in full by",
    // each falling back to the season dates when blank. "Paid in full by"
    // is also the late-join cutoff. Seasons before 2027 charge and fall
    // due as they always did.
    // ------------------------------------------------------------------

    /** First season on the fee timeline. Earlier seasons keep the fee-segment engine and the season-long schedule. */
    const FEE_TIMELINE_FROM = 2027;

    /**
     * The payment window per season: season => array(due_from, paid_by,
     * raised_at, saved_at, saved_by). Saved non-autoloaded, like
     * RELEASE_OPTION, and kept out of team_oversight_season_dates so that
     * option's shape and get_season_dates() never change. Nothing saves it
     * yet; it is read lazily, with fallbacks, so it needs no migration.
     */
    const PAYMENT_WINDOW_OPTION = 'team_oversight_payment_window';

    /** Is this season on the fee timeline? A four-digit season from FEE_TIMELINE_FROM on. */
    public static function timeline_applies($season) {
        return is_scalar($season) && preg_match('/^\d{4}$/D', (string) $season) === 1 && intval($season) >= self::FEE_TIMELINE_FROM;
    }

    /**
     * The season's payment window: array(from, to, from_set, to_set,
     * raised_at). A blank or unsaved date falls back to the season start
     * (from) or end (to); from_set / to_set say whether it was saved.
     * raised_at is when a configuration save last moved the window earlier
     * (null if never). Null when the season has no dates.
     */
    public static function get_payment_window($season) {
        $dates = self::get_season_dates($season);
        if (!$dates) {
            return null;
        }
        $all = get_option(self::PAYMENT_WINDOW_OPTION, array());
        $saved = (is_array($all) && isset($all[$season]) && is_array($all[$season])) ? $all[$season] : array();
        $from_set = !empty($saved['due_from']) && is_string($saved['due_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $saved['due_from']) === 1;
        $to_set = !empty($saved['paid_by']) && is_string($saved['paid_by']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $saved['paid_by']) === 1;
        return array(
            'from' => $from_set ? $saved['due_from'] : $dates['start'],
            'to' => $to_set ? $saved['paid_by'] : $dates['end'],
            'from_set' => $from_set,
            'to_set' => $to_set,
            'raised_at' => !empty($saved['raised_at']) ? (string) $saved['raised_at'] : null,
        );
    }

    /** The late-join cutoff: the effective "Paid in full by" date ('YYYY-MM-DD'), or null when the season has no dates. */
    public static function latest_start_date($season) {
        $window = self::get_payment_window($season);
        return $window ? $window['to'] : null;
    }

    // ------------------------------------------------------------------
    // Fee release (1.64.0): people can be put on next season's teams before
    // that season's fees are known. Until the season's release date nobody
    // is invoiced for it — assignments still record their fee-role history
    // — and on the release date every assigned person is invoiced at once
    // (daily cron, or "Release fees now"). Pro-rata still runs from the
    // season start/end, so anyone added before the season starts pays the
    // full season. No release date = released (the original behaviour).
    // ------------------------------------------------------------------

    const RELEASE_OPTION = 'team_oversight_fee_release';
    const APPLIED_OPTION = 'team_oversight_fees_applied';

    /** What is re-pricing invoices right now, for each re-price's log line ('' = their teams changed). */
    private $reprice_context = '';

    /** Did the last generate_invoice() keep a fee set with Edit Fee instead of re-pricing it? */
    private $kept_manual = false;

    /** The season's fee release date ('YYYY-MM-DD'), or '' when none is set. */
    public static function get_fee_release_date($season) {
        $all = get_option(self::RELEASE_OPTION, array());
        return (is_array($all) && !empty($all[$season])) ? $all[$season] : '';
    }

    /** Are this season's fees being charged yet? */
    public static function fees_released($season) {
        $date = self::get_fee_release_date($season);
        return $date === '' || current_time('Y-m-d') >= $date;
    }

    /** Set (or with '' clear) a season's release date. A new date re-arms the release. */
    public static function set_fee_release_date($season, $date) {
        $all = get_option(self::RELEASE_OPTION, array());
        $all = is_array($all) ? $all : array();
        $applied = get_option(self::APPLIED_OPTION, array());
        $applied = is_array($applied) ? $applied : array();
        if ($date === '') {
            unset($all[$season]);
        } else {
            $all[$season] = $date;
        }
        unset($applied[$season]);
        update_option(self::RELEASE_OPTION, $all, false);
        update_option(self::APPLIED_OPTION, $applied, false);
    }

    /**
     * Invoice everyone with an active assignment in the season (an existing
     * invoice is just re-priced, so running this twice is a no-op). Returns
     * counts plus the people whose fee couldn't be worked out (missing fee
     * matrix entries), the people invoiced only partly priced (a role with
     * no matrix amount, so charged the highest role that has one), and the
     * people whose existing invoice was left as it was because none of
     * their roles has a matrix amount ('unpriced', not counted as updated).
     * A fee set with Edit Fee is never re-priced: those people are listed
     * as 'manual' (not counted as updated; the calculated amount is logged).
     * $new_only (a season already being charged): invoice only people with
     * no invoice yet and leave every existing invoice as it is.
     *
     * From 2027 (timeline_applies()) everyone with a place in the season
     * counts, active or stopped, so a player who stopped before the release
     * is still invoiced for the time they played. Someone with no invoice
     * whose places all fall outside the season dates has nothing to pay
     * and is skipped quietly (counted in 'skipped', never in 'missing').
     */
    public function apply_fees_for_season($season, $new_only = false) {
        global $wpdb;
        $timeline = self::timeline_applies($season);
        if ($timeline) {
            $people = $wpdb->get_results($wpdb->prepare("
                SELECT MAX(user_id) AS user_id, email FROM {$wpdb->prefix}team_assignments
                WHERE season = %s
                GROUP BY email
            ", $season));
        } else {
            $people = $wpdb->get_results($wpdb->prepare("
            SELECT MAX(user_id) AS user_id, email FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND is_active = 1
            GROUP BY email
        ", $season));
        }

        $result = array('invoiced' => 0, 'updated' => 0, 'missing' => array(), 'partial' => array(), 'unpriced' => array(), 'manual' => array(), 'kept' => 0, 'skipped' => 0);
        $seen = array();
        $this->reprice_context = 'fee release';
        foreach ($people as $person) {
            $uid = intval($person->user_id) > 0 ? intval($person->user_id) : self::resolve_user_id($person->email);
            $key = $uid > 0 ? 'u' . $uid : strtolower($person->email);
            if (isset($seen[$key])) {
                continue; // one person, several emails over time
            }
            $seen[$key] = true;
            $had = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_invoices WHERE (user_id = %d OR email = %s) AND season = %s
            ", $uid, $person->email, $season));
            if ($had && $new_only) {
                $result['kept']++;
                continue;
            }
            if ($this->generate_invoice($person->email, $season)) {
                // A fee set with Edit Fee was kept, not re-priced (any
                // difference from the calculation is logged).
                if ($had && $this->kept_manual) {
                    $result['manual'][] = $person->email;
                    continue;
                }
                // A role with no matrix amount: say so (fee_breakdown() is
                // the calculation the invoice came from). Some role priced
                // = partly priced; none = an existing invoice was kept as
                // it was, so it wasn't re-priced at all.
                $breakdown = $this->fee_breakdown($person->email, $season);
                $priced_any = false;
                foreach ($breakdown['stretches'] as $s) {
                    if ($s['top']) {
                        $priced_any = true;
                        break;
                    }
                }
                if ($breakdown['missing'] && !$priced_any && $had) {
                    $result['unpriced'][] = $person->email;
                    continue;
                }
                $result[$had ? 'updated' : 'invoiced']++;
                if ($breakdown['missing'] && $priced_any) {
                    $result['partial'][] = $person->email;
                }
            } else {
                // 2027+: no place inside the season dates (every one ended
                // before it started, say), so nothing to bill: not a gap.
                if ($timeline && !$had) {
                    $breakdown = $this->fee_breakdown($person->email, $season);
                    if (empty($breakdown['stretches'])) {
                        $result['skipped']++;
                        continue;
                    }
                }
                $result['missing'][] = $person->email;
            }
        }
        $this->reprice_context = '';

        $applied = get_option(self::APPLIED_OPTION, array());
        $applied = is_array($applied) ? $applied : array();
        $applied[$season] = current_time('Y-m-d');
        update_option(self::APPLIED_OPTION, $applied, false);
        return $result;
    }

    /**
     * Re-price every invoice in a fee-timeline season (2027+) straight
     * away, e.g. after its season start or end changed: everyone with an
     * invoice in the season, once per account, under the account's current
     * email, through generate_invoice() (so fees set with Edit Fee are
     * kept and every change is logged old -> new; payments are never
     * touched). Creates no invoice. Does nothing for a season before the
     * fee timeline or before the season's fees are released (the release
     * prices everyone). Logs one season_config line when it runs.
     * Returns array(released, people, updated, unchanged, manual (emails
     * of fees set with Edit Fee, kept), unpriced (emails whose invoice was
     * kept because none of their roles has a matrix amount)).
     */
    public function reprice_season($season, $context = 'season dates changed') {
        global $wpdb;
        $result = array('released' => self::fees_released($season), 'people' => 0, 'updated' => 0, 'unchanged' => 0, 'manual' => array(), 'unpriced' => array());
        if (!self::timeline_applies($season) || !$result['released']) {
            return $result;
        }
        $invoices = $wpdb->get_results($wpdb->prepare("
            SELECT id, user_id, email, invoice_amount FROM {$wpdb->prefix}team_invoices WHERE season = %s ORDER BY id
        ", $season));
        $seen = array();
        $this->reprice_context = (string) $context;
        foreach ($invoices as $invoice) {
            $uid = intval($invoice->user_id) > 0 ? intval($invoice->user_id) : self::resolve_user_id($invoice->email);
            $key = $uid > 0 ? 'u' . $uid : 'e' . strtolower((string) $invoice->email);
            if (isset($seen[$key])) {
                continue; // one person, one re-price
            }
            $seen[$key] = true;
            $result['people']++;
            $email = (string) $invoice->email;
            $account = $uid > 0 ? get_userdata($uid) : false;
            if ($account && $account->user_email) {
                $email = $account->user_email;
            }
            $this->generate_invoice($email, $season);
            if ($this->kept_manual) {
                $result['manual'][] = $email;
                continue;
            }
            $after = $wpdb->get_var($wpdb->prepare("SELECT invoice_amount FROM {$wpdb->prefix}team_invoices WHERE id = %d", $invoice->id));
            if ($after !== null && abs(floatval($after) - floatval($invoice->invoice_amount)) >= 0.005) {
                $result['updated']++;
                continue;
            }
            $breakdown = $this->fee_breakdown($email, $season);
            if ($breakdown['missing'] && self::priced_total($breakdown) === null && !empty($breakdown['stretches'])) {
                $result['unpriced'][] = $email;
            } else {
                $result['unchanged']++;
            }
        }
        $this->reprice_context = '';
        TeamOversight_Log::add('season_config', sprintf('%s invoices re-priced (%s): %d person(s), %d re-priced, %d unchanged', $season, $context, $result['people'], $result['updated'], $result['unchanged'])
            . (empty($result['manual']) ? '' : ', ' . count($result['manual']) . ' manual fee(s) kept (set with Edit Fee)')
            . (empty($result['unpriced']) ? '' : ', ' . count($result['unpriced']) . ' not priced (invoice kept as it was)'));
        return $result;
    }

    /**
     * The people a release couldn't fully price, for its admin notice: no
     * fee at all (not invoiced), partly priced (a role with no matrix
     * amount, charged the highest of their roles that has one), and not
     * priced (an existing invoice kept as it was: none of their roles has
     * a matrix amount). Also the fees set with Edit Fee it kept as they
     * were. Admin-only HTML; the activity log gets counts
     * (release_gaps_note()).
     */
    private static function release_gaps_html($result) {
        $html = '';
        if (!empty($result['missing'])) {
            $html .= '<p>No fee could be worked out for ' . count($result['missing']) . ' person(s) — check every fee class and role has an amount in the matrix, then save a release date that has passed (today will do) to invoice them; invoices already out stay as they are: ' . esc_html(implode(', ', $result['missing'])) . '</p>';
        }
        if (!empty($result['partial'])) {
            $html .= '<p><strong>Partly priced:</strong> ' . count($result['partial']) . ' person(s) hold a role with no amount in the fee matrix, so they were charged the highest of their roles that has one, which may be too low. Fill in the blank cells, then correct their fee on Payment Management (it flags the difference): their next team change re-prices it, or set it with Edit Fee (it then stays as set; Use calculated fee puts it back to automatic): ' . esc_html(implode(', ', $result['partial'])) . '</p>';
        }
        if (!empty($result['unpriced'])) {
            $html .= '<p><strong>Not priced:</strong> ' . count($result['unpriced']) . ' person(s) already had an invoice, but none of their roles has an amount in the fee matrix, so it was left as it was (a $0 invoice stays $0). Fill in the blank cells, then set their fee with Edit Fee on Payment Management (it then stays as set; Use calculated fee puts it back to automatic): ' . esc_html(implode(', ', $result['unpriced'])) . '</p>';
        }
        if (!empty($result['manual'])) {
            $html .= '<p><strong>Manual fees kept:</strong> ' . count($result['manual']) . ' person(s) have a fee set with Edit Fee, so it was left as it was (a different calculated amount is logged on Logs). To re-price one, use Use calculated fee on Payment Management: ' . esc_html(implode(', ', $result['manual'])) . '</p>';
        }
        return $html;
    }

    /** ", N partly priced, M not priced (invoice kept as it was), K manual fee(s) kept" for a release's log line ('' when nobody was). */
    private static function release_gaps_note($result) {
        $note = empty($result['partial']) ? '' : ', ' . count($result['partial']) . ' partly priced';
        if (!empty($result['unpriced'])) {
            $note .= ', ' . count($result['unpriced']) . ' not priced (invoice kept as it was)';
        }
        if (!empty($result['manual'])) {
            $note .= ', ' . count($result['manual']) . ' manual fee(s) kept (set with Edit Fee)';
        }
        return $note;
    }

    /**
     * Are a season's invoices still out from when it was being charged:
     * some at a real amount, and none taken back to $0 holding credit by
     * Remove fees until release? Then a season moved to a future release
     * date (by mistake, say) and put back is still being charged, and
     * putting a passed date back, or clearing it, must re-price nothing.
     * Withdrawn credit means a real release is due, which re-prices.
     * Only automatic invoices count, on both sides: a fee set with Edit Fee
     * stays through Remove fees until release (a manual $0 holding payments
     * is not taken-back credit), so it says nothing either way.
     */
    private static function invoices_still_out($season) {
        global $wpdb;
        $charged = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_invoices WHERE season = %s AND invoice_amount > 0 AND fee_overridden = 0", $season));
        if ($charged === 0) {
            return false;
        }
        $credit = $wpdb->get_var($wpdb->prepare("
            SELECT i.id FROM {$wpdb->prefix}team_invoices i
            JOIN {$wpdb->prefix}team_invoice_payments p ON p.invoice_id = i.id
            WHERE i.season = %s AND i.invoice_amount <= 0 AND i.fee_overridden = 0 LIMIT 1
        ", $season));
        return !$credit;
    }

    /**
     * Take back invoices issued for a season whose fees aren't released
     * yet. Unpaid invoices are deleted (a fresh one is made on release).
     * Invoices with money on them stay, re-priced to $0, so what was paid
     * shows as credit; on release they're re-priced to the real fee and
     * the credit comes off it. Never touches the payments ledger. A fee
     * set with Edit Fee is kept as it is, like every re-price (counted as
     * 'manual'; the $0 it would have become is logged once).
     */
    public function withdraw_unreleased_invoices($season) {
        global $wpdb;
        $result = array('deleted' => 0, 'credited' => 0, 'credit_total' => 0.0, 'manual' => 0);
        if (self::fees_released($season)) {
            return $result;
        }
        $invoices = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, email, invoice_amount, fee_overridden FROM {$wpdb->prefix}team_invoices WHERE season = %s", $season));
        foreach ($invoices as $invoice) {
            if (intval($invoice->fee_overridden) === 1) {
                $this->reprice_context = 'Remove fees until release';
                $this->note_manual_fee_kept($invoice->id, 0.0);
                $this->reprice_context = '';
                $result['manual']++;
                continue;
            }
            $has_payments = (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}team_invoice_payments WHERE invoice_id = %d LIMIT 1", $invoice->id));
            if (!$has_payments) {
                $wpdb->delete($wpdb->prefix . 'team_invoices', array('id' => $invoice->id), array('%d'));
                $result['deleted']++;
                TeamOversight_Log::add('fee_edit', $season . ' fee of $' . number_format($invoice->invoice_amount, 2) . ' removed (fees not released yet)', array('user_id' => intval($invoice->user_id)));
                continue;
            }
            $wpdb->update($wpdb->prefix . 'team_invoices', array('invoice_amount' => 0), array('id' => $invoice->id), array('%f'), array('%d'));
            TeamOversight_Payments::recompute_invoice($invoice->id);
            $paid = TeamOversight_Payments::get_ledger_paid($invoice->id);
            $result['credited']++;
            $result['credit_total'] += $paid;
            TeamOversight_Log::add('fee_edit', $season . ' fee of $' . number_format($invoice->invoice_amount, 2) . ' set to $0 until fees are released — $' . number_format($paid, 2) . ' already paid kept as credit', array('user_id' => intval($invoice->user_id)));
        }
        $result['credit_total'] = round($result['credit_total'], 2);
        return $result;
    }

    /** Daily: invoice any season whose release date has arrived and hasn't been applied yet. */
    public static function apply_due_releases() {
        $dates = get_option(self::RELEASE_OPTION, array());
        $applied = get_option(self::APPLIED_OPTION, array());
        if (!is_array($dates)) {
            return;
        }
        foreach ($dates as $season => $date) {
            if (self::fees_released($season) && empty($applied[$season])) {
                $result = (new self())->apply_fees_for_season($season);
                TeamOversight_Log::add('fee_edit', sprintf('%s fees released (%s): %d invoiced, %d already invoiced, %d without a fee', $season, $date, $result['invoiced'], $result['updated'], count($result['missing'])) . self::release_gaps_note($result));
            }
        }
    }

    /**
     * Resolve the WP user behind an identity email: the account with that
     * email, else the user_id on any assignment row carrying it as a
     * snapshot (covers accounts whose email changed after rows were
     * written). Returns -1 when no account is found so the value can sit
     * directly in "(user_id = %d OR email = %s)" clauses and match nothing
     * by id — every fees query matches people this way, so one person's
     * rows stay unified across email changes.
     */
    public static function resolve_user_id($email) {
        $user = get_user_by('email', $email);
        if ($user) {
            return intval($user->ID);
        }
        global $wpdb;
        $uid = intval($wpdb->get_var($wpdb->prepare("
            SELECT user_id FROM {$wpdb->prefix}team_assignments
            WHERE email = %s AND user_id > 0 ORDER BY id DESC LIMIT 1
        ", $email)));
        return $uid > 0 ? $uid : -1;
    }

    // ------------------------------------------------------------------
    // How a season fee is worked out (1.66.0)
    //
    // Every active assignment is a "bucket": team + role + fee class. The
    // fee class can differ per team — an under-19 is JPL/YSL on a junior-
    // league team and Junior U/19 (VVL) on any other team.
    //  1. Each bucket is priced from the matrix: playing member, training
    //     only and supporter at their own rate; coach and assistant coach
    //     at the SUPPORTER rate (there's no coaching discount — the club
    //     handles that elsewhere — so someone who only coaches pays the
    //     supporter fee); team manager as a PLAYING MEMBER.
    //  2. The fee is the HIGHEST bucket. Buckets never add up.
    //  3. Pro-rata: the fee-segment history records the buckets for each
    //     stretch of the season; each stretch is charged for its share of
    //     the season dates, and the stretches add up.
    // fee_breakdown() is the one place this happens — invoices and the
    // Payment Management breakdown both come from it.
    // ------------------------------------------------------------------

    /** Roles with their own column in the fee matrix. */
    const PAYING_ROLES = array('playing_member', 'training_only', 'supporter');

    /** Roles without a matrix column, and the column they're charged at. */
    const CHARGED_AS = array('coach' => 'supporter', 'assistant_coach' => 'supporter', 'team_manager' => 'playing_member');

    /**
     * First season whose fee class doesn't depend on the day it's worked
     * out (club decision): junior follows VV's U19 date-of-birth cutoff for
     * the season being priced, and alumni is judged as at the day their fee
     * was first recorded for it. Earlier seasons keep the original rules
     * (age and degree end on the day the fee is worked out), so they price
     * exactly as they always have.
     */
    const SEASON_CLASS_RULES_FROM = 2027;

    /** The matrix column a role is charged at. */
    public static function charged_as($role) {
        return isset(self::CHARGED_AS[$role]) ? self::CHARGED_AS[$role] : $role;
    }

    /** Matrix lookups cached for the request (a page computing many breakdowns). */
    private $rate_cache = null;

    public function enable_rate_cache() {
        $this->rate_cache = array();
    }

    private function rate($fee_class, $role, $season) {
        if ($this->rate_cache === null) {
            return $this->get_fee($fee_class, $role, $season);
        }
        if (!isset($this->rate_cache[$season])) {
            global $wpdb;
            $this->rate_cache[$season] = array();
            foreach ($this->get_fee_matrix_for_season($season) as $row) {
                $this->rate_cache[$season][$row->fee_class . '|' . $row->team_role] = $row->fee_amount;
            }
        }
        $key = $fee_class . '|' . $role;
        return isset($this->rate_cache[$season][$key]) ? $this->rate_cache[$season][$key] : null;
    }

    /** Is this a junior-league (JPL / YSL) team? By its age rule, or its code. */
    public static function is_junior_league_team($team, $season) {
        if (strpos($team, 'JPL') === 0 || strpos($team, 'YSL') === 0) {
            return true;
        }
        $database = new TeamOversight_Database();
        $config = $database->get_teams_config($season);
        return isset($config[$team]) && $config[$team]['age_rule'] !== '';
    }

    /**
     * The person's fee class ignoring teams: a junior (under 19; from 2027,
     * VV's U19 cutoff for the season) → Junior U/19 (VVL), then the same
     * alumni / staff / student rules as determine_fee_class().
     */
    public function determine_person_fee_class($email, $season = null) {
        $class = $this->determine_fee_class($email, $season);
        return $class === 'JPL/YSL' ? 'Junior U/19 (VVL)' : $class;
    }

    /** Fee class for one team: juniors on a JPL/YSL team pay the JPL/YSL rates. */
    public function fee_class_for_team($person_class, $team, $season) {
        if ($person_class === 'Junior U/19 (VVL)' && self::is_junior_league_team($team, $season)) {
            return 'JPL/YSL';
        }
        return $person_class;
    }

    /** The person's active buckets right now: list of array(team, role, class), sorted. */
    public function current_buckets($email, $season) {
        global $wpdb;
        $uid = self::resolve_user_id($email);
        $assignments = $wpdb->get_results($wpdb->prepare("
            SELECT team, role FROM {$wpdb->prefix}team_assignments
            WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1
        ", $uid, $email, $season));
        if (empty($assignments)) {
            return array();
        }
        $person_class = $this->determine_person_fee_class($email, $season);
        $buckets = array();
        foreach ($assignments as $a) {
            $key = $a->team . '|' . $a->role;
            $buckets[$key] = array('team' => $a->team, 'role' => $a->role, 'class' => $this->fee_class_for_team($person_class, $a->team, $season));
        }
        ksort($buckets);
        return array_values($buckets);
    }

    /**
     * Price one stretch's buckets: each bucket's rate (coaching roles at
     * the supporter rate, team manager as a player), the highest bucket,
     * and so the rate charged.
     */
    public function price_buckets($buckets, $season) {
        $priced = array();
        $top = null;
        $missing = false;
        foreach ($buckets as $b) {
            $b['charged_as'] = self::charged_as($b['role']);
            $rate = $this->rate($b['class'], $b['charged_as'], $season);
            $b['rate'] = $rate === null ? null : floatval($rate);
            $priced[] = $b;
            if ($b['rate'] === null) {
                $missing = true;
                continue;
            }
            if ($top === null || $b['rate'] > $top['rate']) {
                $top = $b;
            }
        }

        return array(
            'buckets' => $priced,
            'top' => $top,
            'rate' => $top ? $top['rate'] : 0.0,
            'missing' => $missing,
        );
    }

    /**
     * The full working for a person's season fee: the stretches of the
     * season (from the fee-segment history, plus where their teams stand
     * now if that hasn't been recorded yet), each priced, its share of the
     * season and its amount, and the total. Reads only — never writes.
     *
     * From 2027 (timeline_applies()) the stretches come from the dates of
     * the person's team places instead (timeline_stretches()), and the
     * result also carries 'engine' => 'timeline' and the 'rows' it was
     * worked out from. $rows (timeline seasons only): price these places
     * instead of the saved ones, so a Preview prices an edit in memory
     * through the same calculation. Both paths price through
     * price_stretches().
     */
    public function fee_breakdown($email, $season, $rows = null) {
        if (self::timeline_applies($season)) {
            if ($rows === null) {
                $rows = TeamOversight_Fee_Timeline::rows($email, $season);
            }
            $result = $this->price_stretches($this->timeline_stretches($email, $season, $rows), $season);
            $result['engine'] = 'timeline';
            $result['rows'] = $rows;
            return $result;
        }

        global $wpdb;

        $uid = self::resolve_user_id($email);
        $segments = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_fee_segments
            WHERE (user_id = %d OR email = %s) AND season = %s
            ORDER BY start_date, id
        ", $uid, $email, $season));

        $legacy_class = null;
        $today = current_time('Y-m-d');
        $stretches = array();
        foreach ($segments as $seg) {
            if ($seg->buckets) {
                $buckets = json_decode($seg->buckets, true);
                $legacy = false;
            } else {
                // Segments recorded before 1.65.0 hold one fee role, priced
                // at the person's single fee class, as they were then.
                if ($legacy_class === null) {
                    $legacy_class = $this->determine_fee_class($email, $season);
                }
                $buckets = array(array('team' => '', 'role' => $seg->fee_role, 'class' => $legacy_class));
                $legacy = true;
            }
            $stretches[] = array('from' => $seg->start_date, 'to' => $seg->end_date, 'buckets' => is_array($buckets) ? $buckets : array(), 'legacy' => $legacy);
        }

        // Where their teams stand now, if the history hasn't caught up
        // (shown as the stretch a checkpoint would record today).
        $current = $this->current_buckets($email, $season);
        $last = empty($stretches) ? null : $stretches[count($stretches) - 1];
        $open = ($last && $last['to'] === null) ? $last : null;
        if ($open && (empty($current) || $open['legacy'] || $open['buckets'] !== $current)) {
            if ($open['from'] >= $today && !empty($current)) {
                $stretches[count($stretches) - 1]['buckets'] = $current;
                $stretches[count($stretches) - 1]['legacy'] = false;
            } else {
                $stretches[count($stretches) - 1]['to'] = $today;
                if (!empty($current)) {
                    $stretches[] = array('from' => $today, 'to' => null, 'buckets' => $current, 'legacy' => false);
                }
            }
        } elseif (!$open && !empty($current)) {
            $join = $wpdb->get_var($wpdb->prepare("
                SELECT MIN(start_date) FROM {$wpdb->prefix}team_assignments
                WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1
            ", $uid, $email, $season));
            // Back on a team after their history closed: the new stretch
            // starts no earlier than the last one ended (never overlaps) —
            // the same rule the checkpoint uses.
            $last_end = null;
            foreach ($stretches as $s) {
                if ($s['to'] !== null && ($last_end === null || $s['to'] > $last_end)) {
                    $last_end = $s['to'];
                }
            }
            $from = self::next_segment_start($join, $last_end, $today);
            $stretches[] = array('from' => $from, 'to' => null, 'buckets' => $current, 'legacy' => false);
        }

        return $this->price_stretches($stretches, $season);
    }

    /**
     * The fee timeline's stretches (2027+) for price_stretches(): the
     * season cut at every place's start, every stop, every date the
     * person's fee class was recorded, and today when their class as
     * judged now differs from the last one recorded. In each piece the
     * places held are those started on or before it and not yet stopped
     * (start inclusive, stop exclusive), each a bucket (team, role, class
     * for that team), keyed team|role and sorted like current_buckets().
     * Pieces holding no place are left out and neighbours with the same
     * buckets are merged. No checkpoints, no next_segment_start() and no
     * dates taken from the day it runs, so a backdated start or stop
     * prices to its real date and stretches can never overlap.
     *
     * The class is read from the class history: the class recorded in
     * force on each day, the first record's class before it was first
     * recorded, and from today the class as judged now when it differs
     * (what record_class() would write now). With no history at all, the
     * class as judged now applies throughout. Reads only.
     *
     * $fixed_class (a person fee class, see person_fee_classes()): price a
     * hypothetical person who holds that class throughout, with no class
     * history and no profile to read (fee_breakdown_for_class(), the How it
     * works page). Everything else is the same code.
     */
    private function timeline_stretches($email, $season, $rows, $fixed_class = null) {
        $places = array();
        $points = array();
        foreach ((array) $rows as $row) {
            $row = (object) $row;
            $from = TeamOversight_Fee_Timeline::parse_date(isset($row->start_date) ? $row->start_date : null);
            $to = TeamOversight_Fee_Timeline::parse_date(isset($row->end_date) ? $row->end_date : null);
            if ($from === null || ($to !== null && $to <= $from)) {
                continue; // no start, or stopped the day it began: never on the team
            }
            $places[] = array('team' => (string) $row->team, 'role' => (string) $row->role, 'from' => $from, 'to' => $to);
            $points[$from] = true;
            if ($to !== null) {
                $points[$to] = true;
            }
        }
        if (empty($places)) {
            return array();
        }

        $today = current_time('Y-m-d');
        if ($fixed_class !== null) {
            $live = (string) $fixed_class;
            $history = array();
        } else {
            $live = $this->determine_person_fee_class($email, $season);
            $history = TeamOversight_Fee_Timeline::class_history($email, $season);
        }
        $last = empty($history) ? null : $history[count($history) - 1]['class'];
        $live_differs = $last !== null && $last !== $live;
        foreach ($history as $h) {
            $points[$h['date']] = true;
        }
        if ($live_differs) {
            $points[$today] = true;
        }
        $points = array_keys($points);
        sort($points, SORT_STRING);

        $stretches = array();
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            $at = $points[$i];
            $next = $i + 1 < $count ? $points[$i + 1] : null;
            if (empty($history) || ($live_differs && $at >= $today)) {
                $class = $live;
            } else {
                $class = $history[0]['class'];
                foreach ($history as $h) {
                    if ($h['date'] <= $at) {
                        $class = $h['class'];
                    }
                }
            }
            $buckets = array();
            foreach ($places as $place) {
                if ($place['from'] <= $at && ($place['to'] === null || $place['to'] > $at)) {
                    $buckets[$place['team'] . '|' . $place['role']] = array(
                        'team' => $place['team'],
                        'role' => $place['role'],
                        'class' => $this->fee_class_for_team($class, $place['team'], $season),
                    );
                }
            }
            if (empty($buckets)) {
                continue;
            }
            ksort($buckets);
            $buckets = array_values($buckets);
            $previous = count($stretches) - 1;
            if ($previous >= 0 && $stretches[$previous]['to'] === $at && $stretches[$previous]['buckets'] === $buckets) {
                $stretches[$previous]['to'] = $next;
                continue;
            }
            $stretches[] = array('from' => $at, 'to' => $next, 'buckets' => $buckets, 'legacy' => false);
        }
        return $stretches;
    }

    /**
     * fee_breakdown() for a hypothetical person on the fee timeline (2027+):
     * $rows (team places as fee_breakdown() takes them: team, role,
     * start_date, end_date) priced for someone who holds $person_class (one
     * of person_fee_classes()) all season, through the same
     * timeline_stretches() and price_stretches() as every real fee. It
     * reads the season dates and the fee matrix and nothing about any
     * person, and writes nothing. The How it works page's worked examples
     * and fee calculator price through it. Null for a season before the fee
     * timeline (those seasons price from fee segments, which a hypothetical
     * person has none of).
     */
    public function fee_breakdown_for_class($season, $rows, $person_class) {
        if (!self::timeline_applies($season)) {
            return null;
        }
        $result = $this->price_stretches($this->timeline_stretches('', $season, (array) $rows, (string) $person_class), $season);
        $result['engine'] = 'timeline';
        $result['rows'] = $rows;
        return $result;
    }

    /**
     * Price a season's stretches, the second half of fee_breakdown(): each
     * stretch is array(from, to (null = still open), buckets, legacy). With
     * no season dates the latest stretch's full rate applies (no
     * pro-rata); otherwise each stretch is clamped to the season dates,
     * priced at its highest bucket for its share of the season, and the
     * amounts add up, rounded once at the end. Returns fee_breakdown()'s
     * array. Reads only. Public so the fee timeline (1.70.1) prices its
     * stretches through exactly the same calculation.
     */
    public function price_stretches(array $stretches, $season) {
        $dates = self::get_season_dates($season);
        $result = array('has_dates' => (bool) $dates, 'season_start' => $dates ? $dates['start'] : '', 'season_end' => $dates ? $dates['end'] : '',
            'season_days' => 0, 'stretches' => array(), 'total' => 0.0, 'missing' => false);
        if (empty($stretches)) {
            return $result;
        }

        if (!$dates || strtotime($dates['end']) <= strtotime($dates['start'])) {
            // No season dates: the latest stretch's full fee (no pro-rata).
            $s = $stretches[count($stretches) - 1];
            $p = $this->price_buckets($s['buckets'], $season);
            $result['stretches'][] = array_merge($s, $p, array('days' => 0, 'share' => 1.0, 'amount' => round($p['rate'], 2)));
            $result['total'] = round($p['rate'], 2);
            $result['missing'] = $p['missing'];
            return $result;
        }

        $season_start = strtotime($dates['start']);
        $season_end = strtotime($dates['end']);
        $result['season_days'] = (int) round(($season_end - $season_start) / DAY_IN_SECONDS);
        $total = 0.0;
        foreach ($stretches as $s) {
            $from = max($season_start, strtotime($s['from']));
            $to = $s['to'] ? min($season_end, strtotime($s['to'])) : $season_end;
            if ($to <= $from) {
                continue; // entirely outside the season (e.g. before it starts)
            }
            $p = $this->price_buckets($s['buckets'], $season);
            $share = ($to - $from) / ($season_end - $season_start);
            $amount = $p['rate'] * $share;
            $total += $amount;
            $result['missing'] = $result['missing'] || $p['missing'];
            $result['stretches'][] = array_merge($s, $p, array(
                'from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to),
                'days' => (int) round(($to - $from) / DAY_IN_SECONDS), 'share' => $share, 'amount' => round($amount, 2),
            ));
        }
        $result['total'] = round($total, 2);
        return $result;
    }

    /** "playing_member" → "Playing member". */
    public static function role_label($role) {
        return ucfirst(str_replace('_', ' ', $role));
    }

    /**
     * The breakdown as a dropdown for the Payment Management table: each
     * stretch's buckets with their rates (and what each is charged as),
     * the highest fee, then pro-rata and the total. Flags a difference
     * from the invoiced amount ($manual: the fee was set with Edit Fee, so
     * re-pricing keeps it and only Use calculated fee applies this).
     */
    public function render_fee_breakdown($breakdown, $invoice_amount = null, $season = null, $manual = false) {
        $money = function ($n) { return '$' . number_format((float) $n, 2); };
        $date = function ($d) { return date('j M Y', strtotime($d)); };
        $stretches = $breakdown['stretches'];
        // Before release an invoice is expected not to match (it's $0 or
        // left over): explain that instead of flagging it.
        $unreleased = $season !== null && !self::fees_released($season);
        $differs = !$unreleased && $invoice_amount !== null && abs(floatval($invoice_amount) - $breakdown['total']) >= 0.01;
        // A role with no matrix amount is left out of the highest-fee pick,
        // so the fee can be lower than it should be: flag it on the summary
        // line, not only inside the dropdown.
        $priced_any = false;
        foreach ($stretches as $s) {
            if ($s['top']) {
                $priced_any = true;
            }
        }

        ob_start();
        ?>
        <details class="fee-breakdown">
            <summary>How it's worked out<?php if ($differs): ?> <span class="fee-breakdown-flag" title="<?php echo $manual ? 'The fee was set by hand with Edit Fee and differs from the current calculation' : 'The invoice doesn\'t match the current calculation'; ?>">&#9888; <?php echo esc_html($money($breakdown['total'])); ?></span><?php endif; ?><?php if ($breakdown['missing']): ?> <span class="fee-breakdown-flag" title="Some roles have no amount in the fee matrix">&#9888; <?php echo $priced_any ? 'partly priced' : 'no matrix amount'; ?></span><?php endif; ?></summary>
            <div class="fee-breakdown-body">
                <?php if (empty($stretches)): ?>
                    <p>No team assignments in this season's fee period.</p>
                <?php endif; ?>
                <?php foreach ($stretches as $i => $s): ?>
                    <?php if (count($stretches) > 1 || ($breakdown['has_dates'] && $s['share'] < 0.9999)): ?>
                        <p class="fee-breakdown-stretch"><strong><?php echo esc_html($date($s['from']) . ' – ' . $date($s['to'])); ?></strong> <span>(<?php echo intval($s['days']); ?> days)</span></p>
                    <?php endif; ?>
                    <ul>
                        <?php foreach ($s['buckets'] as $b): ?>
                            <li>
                                <?php echo esc_html(($b['team'] !== '' ? $b['team'] . ' · ' : '') . $b['class'] . ' · ' . self::role_label($b['role'])); ?>
                                <?php if ($b['charged_as'] !== $b['role']): ?><span class="description">(charged as <?php echo esc_html(strtolower(self::role_label($b['charged_as']))); ?>)</span><?php endif; ?>
                                — <?php echo $b['rate'] === null ? '<em>no amount in the fee matrix</em>' : esc_html($money($b['rate'])); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <table class="fee-breakdown-sums">
                        <?php if ($s['top']): ?>
                            <tr><td>Highest fee<?php echo $s['top']['team'] !== '' ? ' (' . esc_html($s['top']['team'] . ' ' . strtolower(self::role_label($s['top']['role']))) . ')' : ''; ?><?php echo count($s['buckets']) > 1 ? ' — fees never add up' : ''; ?></td><td><?php echo esc_html($money($s['top']['rate'])); ?></td></tr>
                        <?php else: ?>
                            <tr><td>No amount in the fee matrix for these roles</td><td><?php echo esc_html($money(0)); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($breakdown['has_dates'] && $s['share'] < 0.9999): ?>
                            <tr><td>Pro-rata: <?php echo intval($s['days']); ?> of <?php echo intval($breakdown['season_days']); ?> season days (<?php echo esc_html(number_format($s['share'] * 100, 1)); ?>%)</td><td><?php echo esc_html($money($s['amount'])); ?></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php if (!empty($s['legacy'])): ?>
                        <p class="description">Recorded before per-team fee classes — priced at the person's single fee class.</p>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!empty($stretches)): ?>
                    <p class="fee-breakdown-total"><strong>Season fee: <?php echo esc_html($money($breakdown['total'])); ?></strong>
                        <?php if (!$breakdown['has_dates']): ?><span class="description">(no season dates — no pro-rata)</span><?php endif; ?></p>
                    <?php if ($unreleased): ?>
                        <p class="description"><?php echo esc_html($season); ?> fees aren't released until <?php echo esc_html($date(self::get_fee_release_date($season))); ?> — this is what they'll be charged then<?php echo $invoice_amount !== null && floatval($invoice_amount) < 0.01 ? ', less anything already paid' : ''; ?>.</p>
                    <?php endif; ?>
                    <?php if ($differs && $manual): ?>
                        <p class="fee-breakdown-warn">The fee was set by hand with Edit Fee (<?php echo esc_html($money($invoice_amount)); ?>), so team changes, Finalise and fee releases leave it as it is. Use calculated fee sets it to this calculation.</p>
                    <?php elseif ($differs): ?>
                        <p class="fee-breakdown-warn">The invoice is <?php echo esc_html($money($invoice_amount)); ?>, which doesn't match this calculation. It updates the next time their teams change, or edit the season fee to correct it.</p>
                    <?php endif; ?>
                    <?php if ($breakdown['missing'] && $priced_any): ?>
                        <p class="fee-breakdown-warn">Some roles have no amount in the fee matrix and were left out, so this is the highest of the roles that do have one — it may be lower than it should be. Fill in the blank cells on Configuration, then edit the season fee to correct it.</p>
                    <?php elseif ($breakdown['missing']): ?>
                        <p class="fee-breakdown-warn">None of these roles has an amount in the fee matrix, so no fee could be worked out from it. Fill in the blank cells on Configuration.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </details>
        <?php
        return ob_get_clean();
    }

    /**
     * Where a new stretch starts when no segment is open ('YYYY-MM-DD').
     * No history yet: their join date (earliest active assignment start).
     * Back on a team after their history closed: never before the last
     * segment ended — that time is already charged, and the gap they were
     * off isn't owed — so today (or that end date, if later), unless the
     * join date itself falls after it (re-added with a deliberate later
     * start date), which then counts from the join date. Used for the real
     * checkpoint and fee_breakdown()'s not-yet-recorded stretch alike, so
     * the two can't diverge and a reactivation never overlaps.
     */
    private static function next_segment_start($join, $last_end, $today) {
        if ($last_end === null || $last_end === '') {
            return $join ? $join : $today;
        }
        if ($join && $join > $last_end) {
            return $join;
        }
        return max($today, $last_end);
    }

    /**
     * Record where the person's teams stand as a dated segment (all active
     * buckets). Called whenever assignments change: unchanged buckets are a
     * no-op; a change closes the open segment today and opens a new one, so
     * mid-season changes charge each stretch at its own rate.
     *
     * From 2027 (timeline_applies()) fee segments are frozen: nothing
     * writes them and pricing never reads them, because the fee follows the
     * places' own dates. The one thing still recorded is the person's fee
     * class (TeamOversight_Fee_Timeline::record_class()), so the first
     * record keeps the moment an alumni class is judged at and a profile
     * correction counts from the day a re-price picks it up.
     */
    public function checkpoint_fee_role($email, $season) {
        if (self::timeline_applies($season)) {
            TeamOversight_Fee_Timeline::record_class($email, $season, '', $this);
            return;
        }

        global $wpdb;

        $uid = self::resolve_user_id($email);
        $assignments = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_assignments
            WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1
        ", $uid, $email, $season));

        $today = current_time('Y-m-d');
        $open = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_fee_segments
            WHERE (user_id = %d OR email = %s) AND season = %s AND end_date IS NULL
            ORDER BY start_date DESC, id DESC LIMIT 1
        ", $uid, $email, $season));

        if (empty($assignments)) {
            // No active assignments: close the open segment — the fee
            // freezes at what accrued while they were on a team.
            if ($open) {
                $wpdb->update(
                    $wpdb->prefix . 'team_fee_segments',
                    array('end_date' => $today),
                    array('id' => $open->id),
                    array('%s'),
                    array('%d')
                );
            }
            return;
        }

        // Every active bucket, plus (for reports that read fee_role) the
        // role that sets what they pay.
        $buckets = $this->current_buckets($email, $season);
        $signature = wp_json_encode($buckets);
        $priced = $this->price_buckets($buckets, $season);
        $best_role = $priced['top'] ? $priced['top']['charged_as'] : self::charged_as($assignments[0]->role);

        if ($open && $open->buckets === $signature) {
            return;
        }

        // Changed again on the day it started: just update that segment.
        if ($open && $open->start_date >= $today) {
            $wpdb->update(
                $wpdb->prefix . 'team_fee_segments',
                array('buckets' => $signature, 'fee_role' => $best_role),
                array('id' => $open->id),
                array('%s', '%s'),
                array('%d')
            );
            return;
        }

        if ($open) {
            $wpdb->update(
                $wpdb->prefix . 'team_fee_segments',
                array('end_date' => $today),
                array('id' => $open->id),
                array('%s'),
                array('%d')
            );
        }

        // First-ever segment starts at their join date; later ones today.
        // Back on a team after their history closed, it never starts before
        // the last segment ended (next_segment_start()).
        $start = $today;
        if (!$open) {
            $join = $wpdb->get_var($wpdb->prepare("
                SELECT MIN(start_date) FROM {$wpdb->prefix}team_assignments
                WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1
            ", $uid, $email, $season));
            $last_end = $wpdb->get_var($wpdb->prepare("
                SELECT MAX(end_date) FROM {$wpdb->prefix}team_fee_segments
                WHERE (user_id = %d OR email = %s) AND season = %s AND end_date IS NOT NULL
            ", $uid, $email, $season));
            $start = self::next_segment_start($join, $last_end, $today);
        }

        // created_date in site time (the column default is the database
        // clock): a person's first segment is when a 2027+ alumni class is
        // judged (fee_class_reference_time()).
        $wpdb->insert(
            $wpdb->prefix . 'team_fee_segments',
            array(
                'user_id' => $uid > 0 ? $uid : intval($assignments[0]->user_id),
                'email' => $email,
                'season' => $season,
                'fee_role' => $best_role,
                'buckets' => $signature,
                'start_date' => $start,
                'created_date' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Season fee from fee_breakdown(): each stretch's highest bucket for its
     * share of the season. Null when nothing could be priced (no teams, or
     * no matrix amounts for their roles). Without season dates, the latest
     * stretch's full rate applies.
     */
    private function compute_segment_fee($email, $season, $fee_class) {
        return self::priced_total($this->fee_breakdown($email, $season));
    }

    /**
     * A fee_breakdown()'s total, or null when it has no stretches or none
     * of them could be priced (no matrix entries for any bucket).
     */
    private static function priced_total($breakdown) {
        if (empty($breakdown['stretches'])) {
            return null;
        }
        // Nothing priced at all (no matrix entries for any bucket): no fee.
        $priced_any = false;
        foreach ($breakdown['stretches'] as $s) {
            if ($s['top']) {
                $priced_any = true;
            }
        }
        return $priced_any ? $breakdown['total'] : null;
    }

    /**
     * Recalculate a player's fee after an assignment change. Creates no
     * invoice for players who never had one — it just checkpoints the
     * segment history so a later invoice charges the right blend.
     */
    public function recalculate_after_assignment_change($email, $season) {
        global $wpdb;

        $uid = self::resolve_user_id($email);
        $has_invoice = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}team_invoices WHERE (user_id = %d OR email = %s) AND season = %s
        ", $uid, $email, $season));

        if ($has_invoice) {
            $this->generate_invoice($email, $season);
        } else {
            $this->checkpoint_fee_role($email, $season);
        }
    }

    /**
     * Delete a season invoice that is a clean slate, once the person's
     * playing rows are gone and the fee has been re-priced (call
     * recalculate_after_assignment_change() first). It goes only when they
     * hold no active team place that season, nothing was paid on it, it
     * isn't a fee set with Edit Fee, and nothing accrued (pro-rata from the
     * fee history, worked out only once fees are released and only with
     * season dates). Otherwise it stays, with every payment on it. This is
     * Undo Assignment's rule, moved here unchanged in 1.70.0 so the fee
     * timeline's Remove can share it.
     * $email is the account's current email, the identity the re-price ran
     * under. $log: 'amount' (the fee before the re-price, for the log line;
     * defaults to the invoice's amount now), 'reason' (shown in brackets on
     * the log line) and 'user_id' (logged against when $email resolves to
     * no account).
     * Returns array(still_on_team, removed, accrued, no_dates, manual).
     *
     * Fee-timeline seasons (2027+, 1.70.1): "on a team" means any place
     * left in the season, stopped or not (a stopped place still counts for
     * the time it ran), so the invoice goes only when no season row is left.
     */
    public function delete_invoice_if_empty($email, $season, $invoice_id, $log = array()) {
        global $wpdb;

        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, invoice_amount FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id));
        $uid = self::resolve_user_id($email);
        if (self::timeline_applies($season)) {
            $still_on_team = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_assignments
                WHERE (user_id = %d OR email = %s) AND season = %s LIMIT 1
            ", $uid, $email, $season));
        } else {
            $still_on_team = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}team_assignments
            WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1 LIMIT 1
        ", $uid, $email, $season));
        }
        $invoice_removed = false;
        $accrued = 0.0;
        $no_dates = false;
        // A fee set with Edit Fee is never changed or removed by this:
        // it stays, and Payment Management flags it while they're on
        // no team, for the treasurer to decide.
        $invoice_manual = $invoice && self::fee_is_manual($invoice->id);
        if ($invoice && !$still_on_team && !$invoice_manual) {
            // What their time on teams accrued, pro-rata from the fee
            // history — only with season dates, once fees are released.
            if (self::fees_released($season)) {
                $season_dates = self::get_season_dates($season);
                $no_dates = !$season_dates || strtotime($season_dates['end']) <= strtotime($season_dates['start']);
                if (!$no_dates) {
                    $breakdown = $this->fee_breakdown($email, $season);
                    $accrued = floatval($breakdown['total']);
                }
            }
            $payment_count = intval($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}team_invoice_payments WHERE invoice_id = %d
            ", $invoice->id)));
            if ($payment_count === 0 && $accrued < 0.005) {
                // Nothing paid, nothing accrued, on no team any more:
                // a clean slate. (Accrued mid-season, it stays at that
                // amount — the same as deleting the assignment.)
                $amount = isset($log['amount']) ? $log['amount'] : $invoice->invoice_amount;
                $reason = isset($log['reason']) ? $log['reason'] : 'on no team any more, nothing paid';
                $fallback_uid = isset($log['user_id']) ? intval($log['user_id']) : 0;
                $wpdb->delete($wpdb->prefix . 'team_invoices', array('id' => $invoice->id), array('%d'));
                $invoice_removed = true;
                TeamOversight_Log::add('fee_edit', $season . ' fee of $' . number_format($amount, 2) . ' removed (' . $reason . ')', array('user_id' => $uid > 0 ? $uid : $fallback_uid));
            }
        }

        return array(
            'still_on_team' => (bool) $still_on_team,
            'removed' => $invoice_removed,
            'accrued' => $accrued,
            'no_dates' => $no_dates,
            'manual' => $invoice_manual,
        );
    }

    public function import_price_matrix($season = null) {
        global $wpdb;
        
        if (!$season) {
            $season = date('Y');
        }
        
        $price_matrix_file = TEAM_OVERSIGHT_PLUGIN_DIR . 'price-matrix.csv';
        
        if (!file_exists($price_matrix_file)) {
            $price_matrix_file = ABSPATH . 'Price Matrix.csv';
        }
        
        if (file_exists($price_matrix_file)) {
            $handle = fopen($price_matrix_file, 'r');
            
            if ($handle !== FALSE) {
                $header = fgetcsv($handle);
                
                // Clear current active fees for this season only
                $wpdb->update(
                    $wpdb->prefix . 'fee_matrix',
                    array('is_active' => 0),
                    array('season' => $season, 'is_active' => 1),
                    array('%d'),
                    array('%s', '%d')
                );
                
                $version = 'csv-import-' . $season . '-' . time();
                
                while (($data = fgetcsv($handle)) !== FALSE) {
                    if (count($data) >= 3 && !empty($data[0])) {
                        $fee_class = sanitize_text_field($data[0]);
                        $team_role = sanitize_text_field($data[1]);
                        $fee_amount = floatval($data[2]);
                        
                        $wpdb->insert(
                            $wpdb->prefix . 'fee_matrix',
                            array(
                                'version' => $version,
                                'season' => $season,
                                'fee_class' => $fee_class,
                                'team_role' => $team_role,
                                'fee_amount' => $fee_amount,
                                'is_active' => 1
                            ),
                            array('%s', '%s', '%s', '%s', '%f', '%d')
                        );
                    }
                }
                
                fclose($handle);
                return true;
            }
        }
        
        return false;
    }
    
    public function get_active_fee_matrix($season = null) {
        global $wpdb;
        
        if (!$season) {
            $season = date('Y');
        }
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fee_matrix 
            WHERE season = %s AND is_active = 1
            ORDER BY fee_class, team_role
        ", $season));
    }
    
    public function get_saved_versions() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT version, version_name, created_date, COUNT(*) as fee_count
            FROM {$wpdb->prefix}fee_matrix_versions v
            LEFT JOIN {$wpdb->prefix}fee_matrix f ON v.version = f.version
            GROUP BY v.version, v.version_name, v.created_date
            ORDER BY v.created_date DESC
        ");
    }
    
    public function save_fee_matrix() {
        if (!wp_verify_nonce($_POST['fee_nonce'], 'save_fee_matrix')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $fees = isset($_POST['fees']) && is_array($_POST['fees']) ? wp_unslash($_POST['fees']) : array();
        $new_fees = isset($_POST['new_fees']) && is_array($_POST['new_fees']) ? wp_unslash($_POST['new_fees']) : array();
        $season = sanitize_text_field($_POST['season'] ?? date('Y'));
        $table = $wpdb->prefix . 'fee_matrix';

        // Update fees for the specific season only
        foreach ($fees as $fee_id => $fee_amount) {
            if (trim((string) $fee_amount) === '') {
                continue;
            }
            $wpdb->update(
                $table,
                array('fee_amount' => max(0, floatval($fee_amount))),
                array('id' => intval($fee_id), 'season' => $season, 'is_active' => 1),
                array('%f'),
                array('%d', '%s', '%d')
            );
        }

        // Grid cells that had no amount yet (a new season, or a class/role
        // combination missing from the matrix). Blank cells stay blank; a
        // combination that already exists is updated, never duplicated.
        $inserted = 0;
        $version = $wpdb->get_var($wpdb->prepare("SELECT version FROM $table WHERE season = %s AND is_active = 1 LIMIT 1", $season));
        $version = $version ?: 'grid-' . $season . '-' . time();
        foreach ($new_fees as $cell) {
            if (!is_array($cell) || !isset($cell['class'], $cell['role'], $cell['amount']) || trim((string) $cell['amount']) === '') {
                continue;
            }
            $class = sanitize_text_field($cell['class']);
            $role = sanitize_key($cell['role']);
            $amount = max(0, floatval($cell['amount']));
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE season = %s AND fee_class = %s AND team_role = %s AND is_active = 1", $season, $class, $role));
            if ($existing) {
                $wpdb->update($table, array('fee_amount' => $amount), array('id' => $existing), array('%f'), array('%d'));
            } else {
                $wpdb->insert($table, array('version' => $version, 'season' => $season, 'fee_class' => $class, 'team_role' => $role, 'fee_amount' => $amount, 'is_active' => 1),
                    array('%s', '%s', '%s', '%s', '%f', '%d'));
                $inserted++;
            }
        }

        wp_send_json_success(array('message' => 'Fees updated successfully for ' . $season . ' season', 'inserted' => $inserted));
    }
    
    public function save_version() {
        if (!wp_verify_nonce($_POST['version_nonce'], 'save_version')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $version_name = sanitize_text_field(wp_unslash($_POST['version_name']));
        if (empty($version_name)) {
            wp_send_json_error('Version name is required');
        }
        
        $version = sanitize_title($version_name) . '-' . time();
        
        // Save version metadata
        $wpdb->insert(
            $wpdb->prefix . 'fee_matrix_versions',
            array(
                'version' => $version,
                'version_name' => $version_name,
                'created_by' => wp_get_current_user()->display_name
            ),
            array('%s', '%s', '%s')
        );
        
        // Copy current active fees to this version
        $current_fees = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fee_matrix WHERE is_active = 1");
        
        foreach ($current_fees as $fee) {
            $wpdb->insert(
                $wpdb->prefix . 'fee_matrix',
                array(
                    'version' => $version,
                    'fee_class' => $fee->fee_class,
                    'team_role' => $fee->team_role,
                    'fee_amount' => $fee->fee_amount,
                    'is_active' => 0
                ),
                array('%s', '%s', '%s', '%f', '%d')
            );
        }
        
        wp_send_json_success('Version saved successfully');
    }
    
    public function restore_version() {
        if (!wp_verify_nonce($_POST['version_nonce'], 'restore_version')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $version = sanitize_text_field($_POST['version']);
        
        // Deactivate current fees
        $wpdb->update(
            $wpdb->prefix . 'fee_matrix',
            array('is_active' => 0),
            array('is_active' => 1),
            array('%d'),
            array('%d')
        );
        
        // Copy version fees as new active fees
        $version_fees = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fee_matrix WHERE version = %s
        ", $version));
        
        $new_version = 'restored-' . time();
        
        foreach ($version_fees as $fee) {
            $wpdb->insert(
                $wpdb->prefix . 'fee_matrix',
                array(
                    'version' => $new_version,
                    'fee_class' => $fee->fee_class,
                    'team_role' => $fee->team_role,
                    'fee_amount' => $fee->fee_amount,
                    'is_active' => 1
                ),
                array('%s', '%s', '%s', '%f', '%d')
            );
        }
        
        wp_send_json_success('Version restored successfully');
    }
    
    private function get_remembered_season() {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            return get_user_meta($user_id, 'team_oversight_selected_season', true);
        }
        return false;
    }
    
    private function remember_season($season) {
        $user_id = get_current_user_id();
        if ($user_id > 0 && !empty($season)) {
            update_user_meta($user_id, 'team_oversight_selected_season', $season);
        }
    }
    
    private function get_current_season() {
        // Priority: URL parameter -> user memory -> current year. Seasons
        // are four-digit years; anything else is ignored (never echoed or
        // remembered), so a crafted ?season= link can't inject markup.
        if (isset($_GET['season']) && is_string($_GET['season']) && preg_match('/^\d{4}$/D', $_GET['season'])) {
            $season = $_GET['season'];
            $this->remember_season($season); // Remember the selection
            return $season;
        }

        $remembered_season = $this->get_remembered_season();
        if (is_string($remembered_season) && preg_match('/^\d{4}$/D', $remembered_season)) {
            return $remembered_season;
        }
        
        return date('Y');
    }
    
    public function render_fee_matrix_page() {
        global $wpdb;
        
        // Get selected season using memory system
        $selected_season = $this->get_current_season();

        // Create the next season so it appears in every season selector
        // before any data exists for it.
        if (isset($_POST['action']) && $_POST['action'] === 'create_season') {
            if (isset($_POST['create_season_nonce']) && wp_verify_nonce($_POST['create_season_nonce'], 'create_season') && current_user_can('manage_options')) {
                $created = get_option('team_oversight_created_seasons', array());
                $created = is_array($created) ? $created : array();
                $known = array_map('intval', array_merge($this->get_available_seasons(), $created));
                $next_season = strval(max($known) + 1); // one past the highest known season
                $created[] = $next_season;
                update_option('team_oversight_created_seasons', array_values(array_unique(array_map('strval', $created))));
                // The new season starts with the previous season's teams,
                // then evolves independently.
                $copied = TeamOversight_Database::seed_season_teams($next_season, strval(max($known)));
                echo '<div class="notice notice-success"><p>Season <strong>' . esc_html($next_season) . '</strong> created — it now appears in all season selectors' . ($copied ? ', starting with the ' . esc_html(max($known)) . ' team list (' . intval($copied) . ' teams) which you can now edit for ' . esc_html($next_season) . ' alone' : '') . '. Set its season dates and fee matrix here when ready, and open its trial applications from the Trial Applications settings.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        // Save trial application rules: the transfer club list and the
        // trial-fee if-then rules.
        if (isset($_POST['action']) && $_POST['action'] === 'save_trial_rules') {
            if (isset($_POST['trial_rules_nonce']) && wp_verify_nonce($_POST['trial_rules_nonce'], 'save_trial_rules') && current_user_can('manage_options')) {
                $clubs_raw = isset($_POST['transfer_clubs']) ? sanitize_textarea_field(wp_unslash($_POST['transfer_clubs'])) : '';
                $clubs = array_values(array_unique(array_filter(array_map('trim', explode("\n", $clubs_raw)))));
                if (!empty($clubs)) {
                    update_option('team_oversight_transfer_clubs', $clubs);
                } else {
                    delete_option('team_oversight_transfer_clubs'); // fall back to the built-in default list
                }

                update_option('team_oversight_trial_fee_rules', array(
                    'charge_transfer' => !empty($_POST['charge_transfer']) ? 1 : 0,
                    'charge_new' => !empty($_POST['charge_new']) ? 1 : 0,
                    'charge_returning' => !empty($_POST['charge_returning']) ? 1 : 0,
                    'charge_lapsed' => !empty($_POST['charge_lapsed']) ? 1 : 0,
                    'free_under' => max(0, min(30, intval(isset($_POST['free_under']) ? $_POST['free_under'] : 0))),
                ));

                echo '<div class="notice notice-success"><p>Trial application rules saved — ' . (count($clubs) ? count($clubs) . ' clubs in the transfer list' : 'transfer club list reset to the built-in default') . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        // Save season start/end dates
        if (isset($_POST['action']) && $_POST['action'] === 'save_season_dates') {
            if (self::timeline_applies($selected_season)) {
                // 2027+ dates are saved with the payment window (below), so
                // they are validated together and re-price the season.
                echo '<div class="notice notice-error"><p>' . esc_html($selected_season) . ' season dates are saved with the payment window — use the Season dates and payment window form below.</p></div>';
            } elseif (isset($_POST['season_dates_nonce']) && wp_verify_nonce($_POST['season_dates_nonce'], 'save_season_dates') && current_user_can('manage_options')) {
                $start = (isset($_POST['season_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['season_start'])) ? $_POST['season_start'] : '';
                $end = (isset($_POST['season_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['season_end'])) ? $_POST['season_end'] : '';

                if ($start && $end && $start < $end) {
                    // Merge into the season's entry rather than replace it,
                    // so any other key saved on it survives a date change.
                    $all_dates = get_option('team_oversight_season_dates', array());
                    $all_dates = is_array($all_dates) ? $all_dates : array();
                    $entry = (isset($all_dates[$selected_season]) && is_array($all_dates[$selected_season])) ? $all_dates[$selected_season] : array();
                    $all_dates[$selected_season] = array_merge($entry, array('start' => $start, 'end' => $end));
                    update_option('team_oversight_season_dates', $all_dates);
                    echo '<div class="notice notice-success"><p>Season dates saved for ' . esc_html($selected_season) . ': ' . esc_html($start) . ' &ndash; ' . esc_html($end) . '. Pro-rata fees and the payment schedule now use these dates.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Please provide valid dates with the start before the end.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        // 2027+: season dates and the payment window, saved together.
        if (isset($_POST['action']) && $_POST['action'] === 'save_season_config') {
            if (isset($_POST['season_config_nonce']) && wp_verify_nonce($_POST['season_config_nonce'], 'save_season_config') && current_user_can('manage_options')) {
                $this->save_season_config($selected_season);
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        // Fee release: a date, or release now.
        if (isset($_POST['action']) && in_array($_POST['action'], array('save_fee_release', 'release_fees_now', 'withdraw_unreleased_invoices'), true)) {
            if (isset($_POST['fee_release_nonce']) && wp_verify_nonce($_POST['fee_release_nonce'], 'fee_release') && current_user_can('manage_options')) {
                if ($_POST['action'] === 'withdraw_unreleased_invoices') {
                    $result = $this->withdraw_unreleased_invoices($selected_season);
                    echo '<div class="notice notice-success"><p><strong>' . esc_html($selected_season) . ' fees removed until release.</strong> '
                        . intval($result['deleted']) . ' unpaid invoice(s) deleted'
                        . ($result['credited'] ? '; ' . intval($result['credited']) . ' with payments set to $0, keeping $' . esc_html(number_format($result['credit_total'], 2)) . ' as credit — it comes off their fee on release' : '')
                        . ($result['manual'] ? '; ' . intval($result['manual']) . ' fee(s) set by hand with Edit Fee kept as they are (to take one back too, use Use calculated fee on it on Payment Management, then remove fees again)' : '')
                        . '.</p></div>';
                } elseif ($_POST['action'] === 'release_fees_now') {
                    self::set_fee_release_date($selected_season, current_time('Y-m-d'));
                    $result = $this->apply_fees_for_season($selected_season);
                    TeamOversight_Log::add('fee_edit', sprintf('%s fees released now: %d invoiced, %d already invoiced, %d without a fee', $selected_season, $result['invoiced'], $result['updated'], count($result['missing'])) . self::release_gaps_note($result));
                    echo '<div class="notice notice-success"><p><strong>' . esc_html($selected_season) . ' fees released.</strong> ' . intval($result['invoiced']) . ' new invoice(s), ' . intval($result['updated']) . ' existing invoice(s) re-priced.</p>'
                        . self::release_gaps_html($result)
                        . '</div>';
                } else {
                    $date = isset($_POST['fee_release_date']) ? sanitize_text_field(wp_unslash($_POST['fee_release_date'])) : '';
                    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        echo '<div class="notice notice-error"><p>Please pick a valid release date.</p></div>';
                    } else {
                        // Clearing a date that hadn't been applied yet releases
                        // the season now, so invoice everyone already on a team
                        // (nothing else would: the daily check only looks at
                        // seasons with a date; a season already being charged,
                        // below, only gets its new people invoiced). Clearing
                        // an applied date — or none — just clears it, with no
                        // re-pricing.
                        $previous_date = self::get_fee_release_date($selected_season);
                        $applied_before = get_option(self::APPLIED_OPTION, array());
                        $release_on_clear = $date === '' && $previous_date !== ''
                            && (!is_array($applied_before) || empty($applied_before[$selected_season]));
                        // Already being charged before this save: its release
                        // was applied, or it had no release date (charged as
                        // people were added), or it was moved to a future
                        // date (by mistake, say) with its invoices still out
                        // (invoices_still_out()). A passed date, or clearing
                        // the date, then releases nothing again — existing
                        // invoices keep their amounts (a fee set with Edit
                        // Fee is never reverted) and only people with no
                        // invoice yet are invoiced.
                        $was_charging = $previous_date === ''
                            || ($previous_date <= current_time('Y-m-d') && is_array($applied_before) && !empty($applied_before[$selected_season]))
                            || self::invoices_still_out($selected_season);
                        self::set_fee_release_date($selected_season, $date);
                        if ($release_on_clear && $was_charging) {
                            $result = $this->apply_fees_for_season($selected_season, true);
                            TeamOversight_Log::add('fee_edit', sprintf('%s fee release date cleared (fees were already being charged, so no invoice was re-priced): %d invoiced, %d kept as they were, %d without a fee', $selected_season, $result['invoiced'], $result['kept'], count($result['missing'])) . self::release_gaps_note($result));
                            echo '<div class="notice notice-success"><p><strong>Release date cleared.</strong> ' . esc_html($selected_season) . ' fees were already being charged, so no existing invoice was re-priced (a fee set with Edit Fee stays as it is)'
                                . ($result['invoiced'] ? '; ' . intval($result['invoiced']) . ' person(s) on a team with no invoice yet were invoiced' : '') . '. Anyone added to a team from now on is invoiced straight away.</p>'
                                . self::release_gaps_html($result)
                                . '</div>';
                        } elseif ($release_on_clear) {
                            $result = $this->apply_fees_for_season($selected_season);
                            TeamOversight_Log::add('fee_edit', sprintf('%s fee release date cleared, fees released now: %d invoiced, %d already invoiced, %d without a fee', $selected_season, $result['invoiced'], $result['updated'], count($result['missing'])) . self::release_gaps_note($result));
                            echo '<div class="notice notice-success"><p><strong>Release date cleared, so ' . esc_html($selected_season) . ' fees are released now.</strong> ' . intval($result['invoiced']) . ' new invoice(s), ' . intval($result['updated']) . ' existing invoice(s) re-priced. Anyone added to a team from now on is invoiced straight away.</p>'
                                . self::release_gaps_html($result)
                                . '</div>';
                        } elseif ($date === '') {
                            TeamOversight_Log::add('fee_edit', $selected_season . ' fee release date cleared (fees charged straight away)');
                            echo '<div class="notice notice-success"><p>Release date cleared — ' . esc_html($selected_season) . ' fees are charged as people are added to teams.</p></div>';
                        } elseif (self::fees_released($selected_season) && $was_charging) {
                            $result = $this->apply_fees_for_season($selected_season, true);
                            TeamOversight_Log::add('fee_edit', sprintf('%s fee release date set to %s (passed; fees were already being charged, so no invoice was re-priced): %d invoiced, %d kept as they were, %d without a fee', $selected_season, $date, $result['invoiced'], $result['kept'], count($result['missing'])) . self::release_gaps_note($result));
                            echo '<div class="notice notice-success"><p>Release date set to ' . esc_html(date('j M Y', strtotime($date))) . ', which has passed. ' . esc_html($selected_season) . ' fees were already being charged, so no existing invoice was re-priced (a fee set with Edit Fee stays as it is)'
                                . ($result['invoiced'] ? '; ' . intval($result['invoiced']) . ' person(s) on a team with no invoice yet were invoiced' : '') . '.</p>'
                                . self::release_gaps_html($result)
                                . '</div>';
                        } elseif (self::fees_released($selected_season)) {
                            $result = $this->apply_fees_for_season($selected_season);
                            TeamOversight_Log::add('fee_edit', sprintf('%s fee release date set to %s (passed, so fees released): %d invoiced, %d already invoiced, %d without a fee', $selected_season, $date, $result['invoiced'], $result['updated'], count($result['missing'])) . self::release_gaps_note($result));
                            echo '<div class="notice notice-success"><p>Release date set to ' . esc_html(date('j M Y', strtotime($date))) . ', which has passed, so fees were applied: ' . intval($result['invoiced']) . ' new invoice(s), ' . intval($result['updated']) . ' re-priced.</p>'
                                . self::release_gaps_html($result)
                                . '</div>';
                        } else {
                            TeamOversight_Log::add('fee_edit', $selected_season . ' fee release date set to ' . $date);
                            // Moving a season that was already charged back to
                            // a future date re-arms the release: say what that
                            // date (and anything else that releases it) will
                            // do to the invoices already out.
                            $existing_invoices = $was_charging ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_invoices WHERE season = %s", $selected_season)) : 0;
                            $what_releases = self::invoices_still_out($selected_season)
                                ? ' on that date (or by Release fees now), except fees set with Edit Fee, which are kept (each change is logged). Saving a date that has passed, or clearing the date, keeps them as they are.'
                                : ' on that date, or straight away if you save a date that has passed, clear the date or use Release fees now, except fees set with Edit Fee, which are kept (each change is logged).';
                            echo '<div class="notice notice-success"><p>' . esc_html($selected_season) . ' fees will be released on ' . esc_html(date('j M Y', strtotime($date))) . '. Until then nobody is invoiced for ' . esc_html($selected_season) . '; on that date everyone on a team is invoiced automatically.'
                                . ($existing_invoices ? ' The ' . intval($existing_invoices) . ' ' . esc_html($selected_season) . ' invoice(s) already issued are re-priced from the fee matrix' . $what_releases : '')
                                . '</p></div>';
                        }
                    }
                }
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        // Start an empty season's fee matrix from another season's amounts.
        if (isset($_POST['action']) && $_POST['action'] === 'copy_fee_matrix') {
            if (isset($_POST['copy_fees_nonce']) && wp_verify_nonce($_POST['copy_fees_nonce'], 'copy_fee_matrix') && current_user_can('manage_options')) {
                $from = isset($_POST['copy_from']) ? sanitize_text_field(wp_unslash($_POST['copy_from'])) : '';
                $copied = $this->copy_fee_matrix($from, $selected_season);
                echo $copied
                    ? '<div class="notice notice-success"><p>Copied ' . intval($copied) . ' fees from ' . esc_html($from) . ' into ' . esc_html($selected_season) . ' — update any amounts that change and save.</p></div>'
                    : '<div class="notice notice-error"><p>Nothing copied — ' . esc_html($selected_season) . ' already has fees, or ' . esc_html($from) . ' has none.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            }
        }

        $fee_matrix = $this->get_fee_matrix_for_season($selected_season);
        
        // Get available seasons for the dropdown
        $available_seasons = $this->get_available_seasons();
        
        ?>
        <div class="wrap">
            <h1>Configuration</h1>
            
            <!-- Season Selector -->
            <div class="season-filter" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <label for="season-select"><strong>Season:</strong></label>
                <select id="season-select" onchange="location.href=this.value;">
                    <?php foreach ($available_seasons as $season): ?>
                        <option value="<?php echo esc_url(admin_url('admin.php?page=team-oversight-fees&season=' . $season)); ?>" <?php selected($selected_season, $season); ?>><?php echo esc_html($season); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php
                $created_list = get_option('team_oversight_created_seasons', array());
                $known_max = max(array_map('intval', array_merge($available_seasons, is_array($created_list) ? $created_list : array())));
                ?>
                <form method="post" style="display: inline; margin-left: 10px;" onsubmit="return confirm('Create season <?php echo intval($known_max) + 1; ?>? It will appear in every season selector, ready for dates, fee matrix and trial settings.');">
                    <input type="hidden" name="action" value="create_season">
                    <?php wp_nonce_field('create_season', 'create_season_nonce'); ?>
                    <button type="submit" class="button">+ Create season <?php echo intval($known_max) + 1; ?></button>
                </form>
            </div>
            
            <!-- Season Context Note -->
            <div class="notice notice-info" style="margin-bottom: 20px;">
                <p><strong>Configuration for <?php echo esc_html($selected_season); ?> Season</strong> - Each season has its own independent fee structure and team settings.</p>
            </div>
            
            <!-- Trial application rules (season-independent) -->
            <?php $trial_fee_rules = TeamOversight_Trials::get_trial_fee_rules(); ?>
            <details class="import-export-section" style="margin-bottom: 20px; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">Trial application rules — fee rules &amp; transfer club list</summary>
                <form method="post">
                    <p style="margin: 12px 0 4px 0;"><strong>Who pays the trial fee?</strong> <span class="description">(only applies when a trial fee product is set on the Trial Applications page)</span></p>
                    <p style="margin: 0 0 4px 0;">
                        <label><input type="checkbox" name="charge_new" value="1" <?php checked(!empty($trial_fee_rules['charge_new'])); ?>> New to VVL</label>
                        <label style="margin-left: 15px;"><input type="checkbox" name="charge_transfer" value="1" <?php checked(!empty($trial_fee_rules['charge_transfer'])); ?>> Transferring from another club</label>
                        <label style="margin-left: 15px;"><input type="checkbox" name="charge_returning" value="1" <?php checked(!empty($trial_fee_rules['charge_returning'])); ?>> Played for Renegades <strong>last season</strong></label>
                        <label style="margin-left: 15px;"><input type="checkbox" name="charge_lapsed" value="1" <?php checked(!empty($trial_fee_rules['charge_lapsed'])); ?>> Renegades history, but <strong>sat a season out</strong></label>
                    </p>
                    <p class="description" style="margin: 0 0 12px 0;">The two Renegades categories are separate because the waiver exists for players whose yearly membership already covers the trial — someone returning after a season away holds no current membership, so by default they pay.</p>
                    <p style="margin: 0 0 12px 0;">
                        <label>Free for Youth State League trials under age
                            <input type="number" name="free_under" value="<?php echo intval($trial_fee_rules['free_under']); ?>" min="0" max="30" style="width: 60px;">
                        </label>
                        <span class="description">(applies regardless of history, but <strong>only when every team they pick is YSL</strong> — U/17 or U/15. A junior reaching up to JPL or a senior team pays. 0 disables the age exemption.)</span>
                    </p>
                    <p style="margin: 0 0 4px 0;"><strong>Transfer club list</strong> <span class="description">— the dropdown on the trial form, one club per line. Empty the box and save to reset to the built-in VVL list.</span></p>
                    <textarea name="transfer_clubs" rows="10" style="width: 100%; max-width: 480px; font-family: monospace;"><?php echo esc_textarea(implode("\n", TeamOversight_Trials::get_transfer_clubs())); ?></textarea>
                    <p>
                        <input type="submit" class="button button-primary" value="Save Trial Rules">
                        <input type="hidden" name="action" value="save_trial_rules">
                        <?php wp_nonce_field('save_trial_rules', 'trial_rules_nonce'); ?>
                    </p>
                </form>
            </details>

            <!-- Season Dates -->
            <?php $season_dates = self::get_season_dates($selected_season); ?>
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px 20px; margin-bottom: 20px;">
                <?php if (self::timeline_applies($selected_season)): ?>
                    <?php $this->render_season_config_form($selected_season); ?>
                <?php else: ?>
                <h2 style="margin-top: 0;">Season Dates</h2>
                <p class="description">Used for pro-rata fees (players confirmed after the start owe the remaining fraction of the season) and the payment schedule (fees fall due linearly between these dates, driving the "overdue" amount members see).</p>
                <form method="post">
                    <label>Season start
                        <input type="date" name="season_start" value="<?php echo esc_attr($season_dates ? $season_dates['start'] : ''); ?>" required>
                    </label>
                    <label style="margin-left: 15px;">Season end
                        <input type="date" name="season_end" value="<?php echo esc_attr($season_dates ? $season_dates['end'] : ''); ?>" required>
                    </label>
                    <input type="hidden" name="action" value="save_season_dates">
                    <?php wp_nonce_field('save_season_dates', 'season_dates_nonce'); ?>
                    <input type="submit" class="button button-primary" value="Save Season Dates" style="margin-left: 15px;">
                    <?php if (!$season_dates): ?>
                        <span class="description" style="margin-left: 10px; color: #996800;">No dates set — fees are charged in full and nothing shows as overdue.</span>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

                <?php
                $release_date = self::get_fee_release_date($selected_season);
                $released = self::fees_released($selected_season);
                global $wpdb;
                $season_invoices = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_invoices WHERE season = %s", $selected_season));
                $season_people = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}team_assignments WHERE season = %s AND is_active = 1", $selected_season));
                // Saving an empty date releases the season when its date
                // hasn't been applied yet (see save_fee_release), so ask first.
                $applied_seasons = get_option(self::APPLIED_OPTION, array());
                $clear_releases = $release_date !== '' && (!is_array($applied_seasons) || empty($applied_seasons[$selected_season]));
                ?>
                <h3 style="margin: 18px 0 4px;">Fee release</h3>
                <p class="description" style="margin-top: 0;">People can be put on <?php echo esc_html($selected_season); ?> teams before the <?php echo esc_html($selected_season); ?> fees are known. Until the release date nobody is invoiced for <?php echo esc_html($selected_season); ?>; on that date everyone on a team is invoiced automatically, and anyone added afterwards is invoiced straight away. Pro-rata still runs from the season start, so people added before then pay the full season.</p>
                <form method="post" style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
                    <?php wp_nonce_field('fee_release', 'fee_release_nonce'); ?>
                    <input type="hidden" name="action" value="save_fee_release">
                    <label>Release fees on
                        <input type="date" name="fee_release_date" value="<?php echo esc_attr($release_date); ?>">
                    </label>
                    <button type="submit" class="button"<?php if ($clear_releases): ?> onclick="return this.form.fee_release_date.value !== '' || confirm('Clearing the date releases <?php echo esc_js($selected_season); ?> fees now: everyone on a <?php echo esc_js($selected_season); ?> team (<?php echo intval($season_people); ?> people) is invoiced straight away.');"<?php endif; ?>>Save release date</button>
                    <?php if (!$released): ?>
                        <button type="submit" class="button button-primary" name="action" value="release_fees_now" onclick="return confirm('Release <?php echo esc_js($selected_season); ?> fees now? Everyone on a <?php echo esc_js($selected_season); ?> team (<?php echo intval($season_people); ?> people) is invoiced straight away.');">Release fees now</button>
                    <?php endif; ?>
                </form>
                <p style="margin: 8px 0 0;">
                    <?php if ($release_date === ''): ?>
                        <strong>No release date</strong> — <?php echo esc_html($selected_season); ?> fees are charged as soon as people are added to teams.
                    <?php elseif ($released): ?>
                        <strong>Released</strong> on <?php echo esc_html(date('j M Y', strtotime($release_date))); ?> — <?php echo intval($season_invoices); ?> invoice(s) for <?php echo intval($season_people); ?> people on teams.
                    <?php else: ?>
                        <strong>Not released yet</strong> — fees go out on <?php echo esc_html(date('j M Y', strtotime($release_date))); ?> to the <?php echo intval($season_people); ?> people on <?php echo esc_html($selected_season); ?> teams.
                        <?php
                        // Automatic invoices only: Remove fees until release
                        // keeps a fee set with Edit Fee, so those are counted
                        // on their own line below.
                        $season_charged = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_invoices WHERE season = %s AND invoice_amount > 0 AND fee_overridden = 0", $selected_season));
                        $season_paid_people = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT i.id) FROM {$wpdb->prefix}team_invoices i JOIN {$wpdb->prefix}team_invoice_payments p ON p.invoice_id = i.id WHERE i.season = %s AND i.invoice_amount > 0 AND i.fee_overridden = 0", $selected_season));
                        $season_manual = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}team_invoices WHERE season = %s AND fee_overridden = 1", $selected_season));
                        ?>
                        <?php if ($season_charged > 0): ?>
                            <br><span style="color: #996800;"><?php echo intval($season_charged); ?> <?php echo esc_html($selected_season); ?> invoice(s) were issued before the release date was set.</span>
                            <form method="post" style="display: inline; margin-left: 6px;" onsubmit="return confirm('Remove the <?php echo intval($season_charged); ?> <?php echo esc_js($selected_season); ?> fee(s) until release?\n\nUnpaid invoices are deleted. <?php echo intval($season_paid_people); ?> with payments are set to $0 and what they paid is kept as credit, coming off their fee on release.');">
                                <?php wp_nonce_field('fee_release', 'fee_release_nonce'); ?>
                                <input type="hidden" name="action" value="withdraw_unreleased_invoices">
                                <button type="submit" class="button">Remove <?php echo esc_html($selected_season); ?> fees until release</button>
                            </form>
                            <br><span class="description">Unpaid invoices are deleted<?php echo $season_paid_people ? '; ' . intval($season_paid_people) . ' with payments are set to $0 and the money is kept as credit' : ''; ?>. Everyone is invoiced afresh on <?php echo esc_html(date('j M Y', strtotime($release_date))); ?>, with any credit taken off.</span>
                        <?php elseif ($season_invoices - $season_manual > 0): ?>
                            <br><span class="description"><?php echo intval($season_invoices - $season_manual); ?> <?php echo esc_html($selected_season); ?> invoice(s) at $0 holding credit — re-priced on release with the credit taken off.</span>
                        <?php endif; ?>
                        <?php if ($season_manual > 0): ?>
                            <br><span class="description"><?php echo intval($season_manual); ?> <?php echo esc_html($selected_season); ?> fee(s) set with Edit Fee stay as they are through the release and Remove fees until release (use Use calculated fee on Payment Management to take one back).</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Fee matrix: full width (it's a grid), then teams -->
            <div style="display: flex; flex-wrap: wrap; gap: 30px; align-items: flex-start;">

                <div style="flex: 1 1 100%;">
                    <h2>Fee Matrix</h2>
                    <?php $grid = $this->get_fee_grid($selected_season); ?>

                    <?php if (empty($fee_matrix)): ?>
                        <div class="notice notice-warning inline">
                            <p><strong>No fees set for <?php echo esc_html($selected_season); ?> yet.</strong> Type the amounts into the grid and save, or start from another season's amounts.</p>
                        </div>
                        <?php
                        $copy_sources = array();
                        foreach ($available_seasons as $s) {
                            if ($s !== $selected_season && !empty($this->get_fee_matrix_for_season($s))) {
                                $copy_sources[] = $s;
                            }
                        }
                        ?>
                        <?php if (!empty($copy_sources)): ?>
                            <form method="post" style="margin: 10px 0; display: flex; align-items: center; gap: 8px;">
                                <?php wp_nonce_field('copy_fee_matrix', 'copy_fees_nonce'); ?>
                                <input type="hidden" name="action" value="copy_fee_matrix">
                                <label>Copy amounts from
                                    <select name="copy_from">
                                        <?php foreach ($copy_sources as $s): ?>
                                            <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="button">Copy</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php
                    // Blank cells someone can actually land in (every class
                    // but Unknown): a person holding several roles is then
                    // charged the highest role that HAS an amount, and someone
                    // with no priced role isn't invoiced at all.
                    $blank_cells = array();
                    if (!empty($fee_matrix)) {
                        foreach (array_diff(self::standard_fee_classes(), array('Unknown')) as $class) {
                            foreach ($grid['roles'] as $role) {
                                if (empty($grid['cells'][$class][$role])) {
                                    $blank_cells[] = $class . ' · ' . strtolower(self::role_label($role));
                                }
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($blank_cells)): ?>
                        <div class="notice notice-warning inline">
                            <p><strong>Blank cells in the <?php echo esc_html($selected_season); ?> fee matrix:</strong> <?php echo esc_html(implode(', ', $blank_cells)); ?>. Anyone in one of these is charged the highest of their other roles that has an amount (a player who also coaches would pay only the supporter amount), or isn't invoiced if none has one. Fill them in before fees are released or selections are finalised — enter 0 where a role is meant to be free.</p>
                        </div>
                    <?php endif; ?>

                    <form id="fee-matrix-form">
                        <?php wp_nonce_field('save_fee_matrix', 'fee_nonce'); ?>
                        <input type="hidden" name="season" value="<?php echo esc_attr($selected_season); ?>">

                        <p class="description">Each person pays the <strong>highest</strong> amount across their teams (fees never add up). Coaches and assistant coaches are charged the <strong>supporter</strong> amount for their fee class, and team managers the <strong>playing member</strong> amount. A blank cell has no fee set.</p>
                        <div style="overflow-x: auto;">
                            <table class="wp-list-table widefat striped fee-grid">
                                <thead>
                                    <tr>
                                        <th>Fee class</th>
                                        <?php foreach ($grid['roles'] as $role): ?>
                                            <th><?php echo esc_html(ucfirst(str_replace('_', ' ', $role))); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $n = 0; ?>
                                    <?php foreach ($grid['classes'] as $class): ?>
                                        <tr>
                                            <th scope="row"><strong><?php echo esc_html($class); ?></strong></th>
                                            <?php foreach ($grid['roles'] as $role): ?>
                                                <?php $cell = isset($grid['cells'][$class][$role]) ? $grid['cells'][$class][$role] : null; ?>
                                                <td>
                                                    <span class="fee-cell">$<?php if ($cell): ?><input type="number" name="fees[<?php echo intval($cell->id); ?>]" value="<?php echo esc_attr(number_format($cell->fee_amount, 2, '.', '')); ?>" step="0.01" min="0" onchange="markChanged()" aria-label="<?php echo esc_attr($class . ', ' . $role); ?>"><?php else: ?><input type="hidden" name="new_fees[<?php echo $n; ?>][class]" value="<?php echo esc_attr($class); ?>"><input type="hidden" name="new_fees[<?php echo $n; ?>][role]" value="<?php echo esc_attr($role); ?>"><input type="number" name="new_fees[<?php echo $n; ?>][amount]" value="" step="0.01" min="0" placeholder="—" onchange="markChanged()" aria-label="<?php echo esc_attr($class . ', ' . $role); ?>"><?php $n++; endif; ?></span>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="fee-matrix-actions" style="margin-top: 12px;">
                            <button type="button" id="save-fees" class="button button-primary" onclick="saveFees()">Save Changes</button>
                            <div id="save-status" style="display: inline-block; margin-left: 10px;"></div>
                        </div>
                    </form>
                    <style>
                        .fee-grid th, .fee-grid td { white-space: nowrap; vertical-align: middle; }
                        .fee-grid .fee-cell { display: inline-flex; align-items: center; gap: 2px; }
                        .fee-grid input[type=number] { width: 84px; text-align: right; }
                    </style>
                </div>

                <!-- Team Management -->
                <div style="flex: 1; min-width: 500px;">
                    <h2>Team Management</h2>
                    
                    <div class="team-management-section">
                        <p style="margin-bottom: 15px; color: #666;">Manage team names, codes, and configuration for the <?php echo esc_html($selected_season); ?> season. Teams are configured <strong>per season</strong>: adding, renaming or deleting here changes <?php echo esc_html($selected_season); ?> only, and creating a new season copies the previous season's list forward.</p>
                        
                        <!-- Add New Team Form -->
                        <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                            <h3 style="margin-top: 0;">Add New Team</h3>
                            <form id="add-team-form">
                                <table class="form-table-compact" style="margin: 0;">
                                    <tr>
                                        <th style="width: 100px;"><label for="team-code">Team Code</label></th>
                                        <td><input type="text" id="team-code" name="team_code" placeholder="e.g. PLD1-M" style="width: 150px;" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-name">Team Name</label></th>
                                        <td><input type="text" id="team-name" name="team_name" placeholder="e.g. Melbourne University Renegades Blue" style="width: 300px;" required></td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-gender">Gender</label></th>
                                        <td>
                                            <select id="team-gender" name="team_gender">
                                                <option value="mens">Men's</option>
                                                <option value="womens">Women's</option>
                                                <option value="mixed">Mixed / Open</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-age-rule">Age Eligibility</label></th>
                                        <td>
                                            <select id="team-age-rule" name="team_age_rule">
                                                <?php foreach (TeamOversight_Database::get_age_rules() as $rule_key => $rule_label): ?>
                                                    <option value="<?php echo esc_attr($rule_key); ?>"><?php echo esc_html($rule_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description">DOB cutoffs follow the VVL By-Laws and shift automatically each season.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="team-shirts">Playing Shirts</label></th>
                                        <td>
                                            <input type="number" id="team-shirts" name="team_shirts" value="1" min="0" max="5" style="width: 60px;">
                                            <span class="description">Shirts a player must purchase for this team (Premier 2, YSL 0 — supplied).</span>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin: 15px 0 0 0;">
                                    <button type="button" id="save-team" class="button button-primary" onclick="saveTeam()">Add Team</button>
                                    <span id="team-save-status" style="margin-left: 10px;"></span>
                                </p>
                            </form>
                        </div>
                        
                        <!-- Current Teams List -->
                        <div>
                            <h3>Current Teams</h3>
                            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Team Code</th>
                                        <th>Team Name</th>
                                        <th style="width: 90px;">Gender</th>
                                        <th style="width: 80px;">Age</th>
                                        <th style="width: 70px;">Shirts</th>
                                        <th style="width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="teams-tbody">
                                    <?php
                                    $database = new TeamOversight_Database();
                                    $teams_config = $database->get_teams_config($selected_season);
                                    $gender_labels = array('mens' => "Men's", 'womens' => "Women's", 'mixed' => 'Mixed');
                                    // Listed by code, naturally (SL2M before SL10M), so a
                                    // team is found by where its code sits, not by when
                                    // it was added.
                                    ksort($teams_config, SORT_NATURAL | SORT_FLAG_CASE);
                                    foreach ($teams_config as $code => $team):
                                    ?>
                                        <tr data-team-code="<?php echo esc_attr($code); ?>">
                                            <td><strong><?php echo esc_html($code); ?></strong></td>
                                            <td class="team-name-cell">
                                                <span class="display-value"><?php echo esc_html($team['name']); ?></span>
                                                <input type="text" class="edit-value" value="<?php echo esc_attr($team['name']); ?>" style="display: none; width: 100%;">
                                            </td>
                                            <td class="team-gender-cell">
                                                <span class="display-value"><?php echo esc_html($gender_labels[$team['gender']]); ?></span>
                                                <select class="edit-value" style="display: none;">
                                                    <option value="mens" <?php selected($team['gender'], 'mens'); ?>>Men's</option>
                                                    <option value="womens" <?php selected($team['gender'], 'womens'); ?>>Women's</option>
                                                    <option value="mixed" <?php selected($team['gender'], 'mixed'); ?>>Mixed</option>
                                                </select>
                                            </td>
                                            <td class="team-age-cell">
                                                <span class="display-value"><?php echo $team['age_rule'] ? strtoupper($team['age_rule']) : 'Open'; ?></span>
                                                <select class="edit-value" style="display: none;">
                                                    <?php foreach (TeamOversight_Database::get_age_rules() as $rule_key => $rule_label): ?>
                                                        <option value="<?php echo esc_attr($rule_key); ?>" <?php selected($team['age_rule'], $rule_key); ?>><?php echo esc_html($rule_key ? strtoupper($rule_key) : 'Open'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="team-shirts-cell">
                                                <span class="display-value"><?php echo intval($team['shirts']); ?></span>
                                                <input type="number" class="edit-value" value="<?php echo intval($team['shirts']); ?>" min="0" max="5" style="display: none; width: 55px;">
                                            </td>
                                            <td>
                                                <button type="button" class="button button-small edit-team-btn" onclick="editTeam('<?php echo esc_js($code); ?>')">Edit</button>
                                                <button type="button" class="button button-small save-team-btn" onclick="saveTeamEdit('<?php echo esc_js($code); ?>')" style="display: none;">Save</button>
                                                <button type="button" class="button button-small cancel-team-btn" onclick="cancelTeamEdit('<?php echo esc_js($code); ?>')" style="display: none;">Cancel</button>
                                                <button type="button" class="button button-small button-link-delete" onclick="deleteTeam('<?php echo esc_js($code); ?>')">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p style="margin-top: 15px;">
                                <button type="button" class="button" onclick="resetDefaultTeams()">Load default club team list</button>
                                <span class="description">Replaces the <?php echo esc_html($selected_season); ?> team list with the plugin's default club list (with gender + age limits). Other seasons are untouched; existing team assignments keep their codes.</span>
                            </p>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
        
        <script>
        let hasChanges = false;
        // Every team action on this page is scoped to the season being viewed.
        const murvcTeamSeason = '<?php echo esc_js($selected_season); ?>';

        function markChanged() {
            hasChanges = true;
            document.getElementById('save-fees').style.background = '#dc3232';
        }
        
        function saveFees() {
            const form = document.getElementById('fee-matrix-form');
            const formData = new FormData(form);
            formData.append('action', 'save_fee_matrix');
            
            const statusDiv = document.getElementById('save-status');
            statusDiv.textContent = 'Saving...';
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.textContent = '✓ Saved';
                    statusDiv.style.color = '#0073aa';
                    document.getElementById('save-fees').style.background = '';
                    hasChanges = false;
                    // Newly filled cells become real matrix rows: reload so
                    // the next save updates them rather than adding again.
                    if (data.data && data.data.inserted > 0) {
                        window.location.reload();
                        return;
                    }
                } else {
                    statusDiv.textContent = '✗ Error: ' + (data.data || 'Unknown error');
                    statusDiv.style.color = '#dc3232';
                }
                setTimeout(() => statusDiv.textContent = '', 3000);
            })
            .catch(error => {
                statusDiv.textContent = '✗ Error: ' + error.message;
                statusDiv.style.color = '#dc3232';
            });
        }
        
        // Team Management Functions
        function saveTeam() {
            const teamCode = document.getElementById('team-code').value.trim();
            const teamName = document.getElementById('team-name').value.trim();
            const teamGender = document.getElementById('team-gender').value;
            const teamAgeRule = document.getElementById('team-age-rule').value;
            const teamShirts = document.getElementById('team-shirts').value || '1';

            if (!teamCode || !teamName) {
                alert('Please fill in both team code and team name.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_team');
            formData.append('season', murvcTeamSeason);
            formData.append('team_code', teamCode);
            formData.append('team_name', teamName);
            formData.append('team_gender', teamGender);
            formData.append('team_age_rule', teamAgeRule);
            formData.append('team_shirts', teamShirts);
            formData.append('team_nonce', '<?php echo wp_create_nonce('save_team'); ?>');
            
            const statusDiv = document.getElementById('team-save-status');
            statusDiv.textContent = 'Saving...';
            statusDiv.style.color = '';
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusDiv.textContent = '✓ Team added successfully';
                    statusDiv.style.color = '#0073aa';
                    
                    // Reset form
                    document.getElementById('team-code').value = '';
                    document.getElementById('team-name').value = '';
                    document.getElementById('team-age-rule').value = '';

                    // Add new row to teams table
                    addTeamRow(teamCode, teamName, teamGender, teamAgeRule, parseInt(teamShirts, 10) || 0);
                } else {
                    statusDiv.textContent = '✗ Error: ' + (data.data || 'Unknown error');
                    statusDiv.style.color = '#dc3232';
                }
                setTimeout(() => statusDiv.textContent = '', 3000);
            })
            .catch(error => {
                statusDiv.textContent = '✗ Error: ' + error.message;
                statusDiv.style.color = '#dc3232';
            });
        }
        
        function addTeamRow(code, name, gender, ageRule, shirts) {
            const genderLabels = {mens: "Men's", womens: "Women's", mixed: 'Mixed'};
            const tbody = document.getElementById('teams-tbody');
            const newRow = document.createElement('tr');
            newRow.setAttribute('data-team-code', code);
            newRow.innerHTML = `
                <td><strong>${escapeHtml(code)}</strong></td>
                <td class="team-name-cell">
                    <span class="display-value">${escapeHtml(name)}</span>
                    <input type="text" class="edit-value" value="${escapeHtml(name)}" style="display: none; width: 100%;">
                </td>
                <td class="team-gender-cell">
                    <span class="display-value">${genderLabels[gender] || gender}</span>
                    <select class="edit-value" style="display: none;">
                        <option value="mens"${gender === 'mens' ? ' selected' : ''}>Men's</option>
                        <option value="womens"${gender === 'womens' ? ' selected' : ''}>Women's</option>
                        <option value="mixed"${gender === 'mixed' ? ' selected' : ''}>Mixed</option>
                    </select>
                </td>
                <td class="team-age-cell">
                    <span class="display-value">${ageRule ? ageRule.toUpperCase() : 'Open'}</span>
                    <select class="edit-value" style="display: none;">
                        <option value=""${ageRule === '' ? ' selected' : ''}>Open</option>
                        <option value="u19"${ageRule === 'u19' ? ' selected' : ''}>U19</option>
                        <option value="u17"${ageRule === 'u17' ? ' selected' : ''}>U17</option>
                        <option value="u15"${ageRule === 'u15' ? ' selected' : ''}>U15</option>
                    </select>
                </td>
                <td class="team-shirts-cell">
                    <span class="display-value">${shirts}</span>
                    <input type="number" class="edit-value" value="${shirts}" min="0" max="5" style="display: none; width: 55px;">
                </td>
                <td>
                    <button type="button" class="button button-small edit-team-btn" onclick="editTeam('${escapeHtml(code)}')">Edit</button>
                    <button type="button" class="button button-small save-team-btn" onclick="saveTeamEdit('${escapeHtml(code)}')" style="display: none;">Save</button>
                    <button type="button" class="button button-small cancel-team-btn" onclick="cancelTeamEdit('${escapeHtml(code)}')" style="display: none;">Cancel</button>
                    <button type="button" class="button button-small button-link-delete" onclick="deleteTeam('${escapeHtml(code)}')">Delete</button>
                </td>
            `;
            tbody.appendChild(newRow);
        }

        function editTeam(teamCode) {
            const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
            row.querySelectorAll('.display-value').forEach(el => el.style.display = 'none');
            row.querySelectorAll('.edit-value').forEach(el => el.style.display = 'inline-block');
            row.querySelector('.edit-team-btn').style.display = 'none';
            row.querySelector('.save-team-btn').style.display = 'inline-block';
            row.querySelector('.cancel-team-btn').style.display = 'inline-block';
            row.querySelector('.team-name-cell .edit-value').focus();
        }

        function cancelTeamEdit(teamCode) {
            const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
            row.querySelectorAll('.display-value').forEach(el => el.style.display = '');
            row.querySelectorAll('.edit-value').forEach(el => el.style.display = 'none');
            row.querySelector('.edit-team-btn').style.display = 'inline-block';
            row.querySelector('.save-team-btn').style.display = 'none';
            row.querySelector('.cancel-team-btn').style.display = 'none';
        }

        function saveTeamEdit(teamCode) {
            const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
            const newName = row.querySelector('.team-name-cell .edit-value').value.trim();
            const newGender = row.querySelector('.team-gender-cell .edit-value').value;
            const newAgeRule = row.querySelector('.team-age-cell .edit-value').value;
            const newShirts = row.querySelector('.team-shirts-cell .edit-value').value || '0';

            if (!newName) {
                alert('Team name cannot be empty.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_team');
            formData.append('season', murvcTeamSeason);
            formData.append('team_code', teamCode);
            formData.append('team_name', newName);
            formData.append('team_gender', newGender);
            formData.append('team_age_rule', newAgeRule);
            formData.append('team_shirts', newShirts);
            formData.append('team_nonce', '<?php echo wp_create_nonce('update_team'); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const genderLabels = {mens: "Men's", womens: "Women's", mixed: 'Mixed'};
                    row.querySelector('.team-name-cell .display-value').textContent = newName;
                    row.querySelector('.team-gender-cell .display-value').textContent = genderLabels[newGender] || newGender;
                    row.querySelector('.team-age-cell .display-value').textContent = newAgeRule ? newAgeRule.toUpperCase() : 'Open';
                    row.querySelector('.team-shirts-cell .display-value').textContent = parseInt(newShirts, 10) || 0;
                    cancelTeamEdit(teamCode);
                } else {
                    alert('Error updating team: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error updating team: ' + error.message);
            });
        }

        function resetDefaultTeams() {
            if (!confirm('Replace the ' + murvcTeamSeason + ' team list with the default club team list (including gender and age limits)?\n\nOther seasons are not affected. Existing team assignments are not changed, but any assignment using an old team code will show that code without a matching team entry.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'reset_default_teams');
            formData.append('season', murvcTeamSeason);
            formData.append('team_nonce', '<?php echo wp_create_nonce('reset_default_teams'); ?>');

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }
        
        function deleteTeam(teamCode) {
            if (!confirm('Delete this team from the ' + murvcTeamSeason + ' season? Other seasons keep it. This action cannot be undone.\n\nTeam Code: ' + teamCode)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_team');
            formData.append('season', murvcTeamSeason);
            formData.append('team_code', teamCode);
            formData.append('team_nonce', '<?php echo wp_create_nonce('delete_team'); ?>');
            
            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove row from table
                    const row = document.querySelector(`tr[data-team-code="${teamCode}"]`);
                    if (row) {
                        row.remove();
                    }
                    
                    // Show success message temporarily
                    const tempMessage = document.createElement('div');
                    tempMessage.textContent = '✓ Team deleted successfully';
                    tempMessage.style.color = '#0073aa';
                    tempMessage.style.fontWeight = 'bold';
                    tempMessage.style.margin = '10px 0';
                    
                    const teamsTable = document.querySelector('.team-management-section h3');
                    teamsTable.parentNode.insertBefore(tempMessage, teamsTable.nextSibling);
                    
                    setTimeout(() => tempMessage.remove(), 3000);
                } else {
                    alert('Error deleting team: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error deleting team: ' + error.message);
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        </script>

        <?php
    }

    /**
     * Configuration (2027+, fee timeline): save the season's dates and its
     * payment window together. The caller checked the nonce
     * (save_season_config) and manage_options. Validated as one: season
     * start <= Fees due from < Paid in full by <= season end, a blank
     * window date falling back to the season start or end; a season-date
     * change that would leave a saved window outside the season is
     * refused unless the window is fixed in the same save. The dates merge
     * into the season's entry of team_oversight_season_dates (its shape is
     * unchanged); the window goes in PAYMENT_WINDOW_OPTION (non-autoloaded)
     * with raised_at stamped when the effective window moves earlier (the
     * behind clock's floor). Logs one season_config line with old and new
     * values. A changed season start or end re-prices the season's
     * invoices (reprice_season(): once released, manual fees kept, counts
     * reported); a window change re-prices nothing. Warns about places
     * that start after the (new) late-join cutoff, without changing them.
     */
    private function save_season_config($season) {
        global $wpdb;
        if (!self::timeline_applies($season)) {
            echo '<div class="notice notice-error"><p>' . esc_html($season) . ' season dates are saved with the Season Dates form.</p></div>';
            return;
        }
        $read = function ($key) {
            return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim(sanitize_text_field(wp_unslash($_POST[$key]))) : '';
        };
        $valid = function ($date) {
            return TeamOversight_Fee_Timeline::parse_date($date) !== null;
        };
        $fmt = function ($date) {
            return TeamOversight_Fee_Timeline::format_date($date);
        };
        $start = $read('season_start');
        $end = $read('season_end');
        $due_from = $read('due_from');
        $paid_by = $read('paid_by');

        if (!$valid($start) || !$valid($end) || $start >= $end) {
            echo '<div class="notice notice-error"><p>Please provide valid season dates with the start before the end. Nothing was saved.</p></div>';
            return;
        }
        if (($due_from !== '' && !$valid($due_from)) || ($paid_by !== '' && !$valid($paid_by))) {
            echo '<div class="notice notice-error"><p>Fees due from and Paid in full by must each be a valid date, or blank (blank uses the season start or end). Nothing was saved.</p></div>';
            return;
        }
        $p0 = $due_from !== '' ? $due_from : $start;
        $p1 = $paid_by !== '' ? $paid_by : $end;

        $all_windows = get_option(self::PAYMENT_WINDOW_OPTION, array());
        $all_windows = is_array($all_windows) ? $all_windows : array();
        $saved = (isset($all_windows[$season]) && is_array($all_windows[$season])) ? $all_windows[$season] : array();
        $saved_from = (isset($saved['due_from']) && is_string($saved['due_from']) && $valid($saved['due_from'])) ? $saved['due_from'] : '';
        $saved_to = (isset($saved['paid_by']) && is_string($saved['paid_by']) && $valid($saved['paid_by'])) ? $saved['paid_by'] : '';
        $old_dates = self::get_season_dates($season);
        $old_window = self::get_payment_window($season);
        $dates_changed = !$old_dates || $old_dates['start'] !== $start || $old_dates['end'] !== $end;
        $window_changed = $saved_from !== $due_from || $saved_to !== $paid_by;

        if (!($start <= $p0 && $p0 < $p1 && $p1 <= $end)) {
            if ($dates_changed && !$window_changed && ($saved_from !== '' || $saved_to !== '')) {
                echo '<div class="notice notice-error"><p>The saved payment window (Fees due from ' . esc_html($saved_from !== '' ? $fmt($saved_from) : 'the season start') . ', Paid in full by ' . esc_html($saved_to !== '' ? $fmt($saved_to) : 'the season end') . ') would fall outside the new season dates (' . esc_html($fmt($start) . ' – ' . $fmt($end)) . '). Change or clear it in the same save. Nothing was saved.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>The payment window must sit inside the season: season start ≤ Fees due from &lt; Paid in full by ≤ season end (a blank date uses the season start or end). Nothing was saved.</p></div>';
            }
            return;
        }
        if (!$dates_changed && !$window_changed) {
            echo '<div class="notice notice-info"><p>Nothing changed for ' . esc_html($season) . '.</p></div>';
            return;
        }

        if ($dates_changed) {
            // Merged into the season's entry, so any other key survives.
            $all_dates = get_option('team_oversight_season_dates', array());
            $all_dates = is_array($all_dates) ? $all_dates : array();
            $entry = (isset($all_dates[$season]) && is_array($all_dates[$season])) ? $all_dates[$season] : array();
            $all_dates[$season] = array_merge($entry, array('start' => $start, 'end' => $end));
            update_option('team_oversight_season_dates', $all_dates);
        }
        // The effective window moved earlier: more falls due sooner, so the
        // behind clock never counts from before today (floor rule 4). A
        // season getting its first dates is the biggest move earlier of all:
        // nothing was due, and now some may be.
        $moved_earlier = !$old_window || $p0 < $old_window['from'] || $p1 < $old_window['to'];
        $raised_at = (isset($saved['raised_at']) && is_string($saved['raised_at'])) ? $saved['raised_at'] : '';
        if ($moved_earlier) {
            $raised_at = current_time('mysql');
        }
        if ($window_changed || $moved_earlier) {
            $all_windows[$season] = array(
                'due_from' => $due_from,
                'paid_by' => $paid_by,
                'raised_at' => $raised_at,
                'saved_at' => current_time('mysql'),
                'saved_by' => get_current_user_id(),
            );
            update_option(self::PAYMENT_WINDOW_OPTION, $all_windows, false);
        }

        $parts = array();
        if ($dates_changed) {
            $parts[] = 'season dates ' . ($old_dates ? $fmt($old_dates['start']) . ' – ' . $fmt($old_dates['end']) : 'not set') . ' → ' . $fmt($start) . ' – ' . $fmt($end);
        }
        if ($window_changed) {
            $parts[] = 'fees due from ' . ($saved_from !== '' ? $fmt($saved_from) : '(season start)') . ' → ' . ($due_from !== '' ? $fmt($due_from) : '(season start)');
            $parts[] = 'paid in full by ' . ($saved_to !== '' ? $fmt($saved_to) : '(season end)') . ' → ' . ($paid_by !== '' ? $fmt($paid_by) : '(season end)');
        }
        $parts[] = 'payment window now ' . $fmt($p0) . ' – ' . $fmt($p1) . ($old_window ? ' (was ' . $fmt($old_window['from']) . ' – ' . $fmt($old_window['to']) . ')' : '');
        if ($moved_earlier) {
            $parts[] = 'window moved earlier, so nobody counts as behind from before today';
        }
        TeamOversight_Log::add('season_config', $season . ' season configuration saved: ' . implode('; ', $parts));

        $notice = '<p><strong>' . esc_html($season) . ' season dates and payment window saved.</strong> Fees cover ' . esc_html($fmt($start) . ' – ' . $fmt($end))
            . '; the payment window runs from ' . esc_html($fmt($p0)) . ' to ' . esc_html($fmt($p1)) . ', which is also the late-join cutoff.</p>';
        if ($dates_changed) {
            $repriced = $this->reprice_season($season, 'season dates changed');
            if (!$repriced['released']) {
                $notice .= '<p>' . esc_html($season) . ' fees aren\'t released yet, so nothing was re-priced; the release prices everyone at these dates.</p>';
            } else {
                $notice .= '<p>Invoices re-priced to the new season dates: ' . intval($repriced['people']) . ' person(s), ' . intval($repriced['updated']) . ' re-priced, ' . intval($repriced['unchanged']) . ' unchanged.</p>'
                    . self::release_gaps_html(array('manual' => $repriced['manual'], 'unpriced' => $repriced['unpriced']));
            }
        } else {
            $notice .= '<p>Only the payment window changed, so no fee was re-priced; what each player has due follows the new window straight away.</p>';
        }
        echo '<div class="notice notice-success">' . $notice . '</div>';

        $late = $wpdb->get_results($wpdb->prepare("
            SELECT ta.id, ta.team, ta.role, ta.start_date, u.display_name
            FROM {$wpdb->prefix}team_assignments ta
            LEFT JOIN {$wpdb->users} u ON u.ID = ta.user_id
            WHERE ta.season = %s AND ta.start_date > %s
            ORDER BY ta.start_date, ta.id
        ", $season, $p1));
        if ($late) {
            $list = array();
            foreach ($late as $place) {
                $list[] = ($place->display_name ? $place->display_name : 'place #' . intval($place->id)) . ' (' . $place->team . ' ' . strtolower(self::role_label($place->role)) . ', from ' . $fmt($place->start_date) . ')';
            }
            echo '<div class="notice notice-warning"><p><strong>' . count($late) . ' ' . esc_html($season) . ' place(s) start after the late-join cutoff (' . esc_html($fmt($p1)) . '):</strong> '
                . esc_html(implode('; ', $list)) . '. Nothing was changed: if a start date is wrong, correct it on that person\'s Fee timeline.</p></div>';
        }
    }

    /**
     * Configuration (2027+): the one form for the season's dates and its
     * payment window (save_season_config()), in place of the Season Dates
     * form earlier seasons keep.
     */
    private function render_season_config_form($season) {
        $dates = self::get_season_dates($season);
        $all = get_option(self::PAYMENT_WINDOW_OPTION, array());
        $saved = (is_array($all) && isset($all[$season]) && is_array($all[$season])) ? $all[$season] : array();
        $due_from = (isset($saved['due_from']) && is_string($saved['due_from']) && TeamOversight_Fee_Timeline::parse_date($saved['due_from']) !== null) ? $saved['due_from'] : '';
        $paid_by = (isset($saved['paid_by']) && is_string($saved['paid_by']) && TeamOversight_Fee_Timeline::parse_date($saved['paid_by']) !== null) ? $saved['paid_by'] : '';
        $window = self::get_payment_window($season);
        $confirm = 'Changing the ' . $season . ' season start or end re-prices every automatic ' . $season . ' invoice straight away (once fees are released). Fees set with Edit Fee are kept, payments are never changed, and each change is logged. Save?';
        ?>
        <h2 style="margin-top: 0;">Season dates and payment window</h2>
        <p class="description">From <?php echo intval(self::FEE_TIMELINE_FROM); ?> each team place's own start and stop dates set the fee. <strong>Season start and end</strong> are what fees cover (pro-rata; days outside them aren't charged). <strong>Fees due from</strong> and <strong>Paid in full by</strong> are the payment window; left blank they use the season start and end. Paid in full by is also the <strong>late-join cutoff</strong>: nobody starts on a team after it. Changing the season start or end re-prices this season's invoices straight away (once fees are released; fees set with Edit Fee are kept); changing only the payment window re-prices nothing. Every change is logged. Each player's fee falls due evenly from their own first start (not before Fees due from) to Paid in full by, and all at once when they stop; a window change moves what everyone has due straight away. Moving the window earlier never makes anyone count as behind from before the day it was saved.</p>
        <form method="post" id="season-config-form" data-start="<?php echo esc_attr($dates ? $dates['start'] : ''); ?>" data-end="<?php echo esc_attr($dates ? $dates['end'] : ''); ?>" data-confirm="<?php echo esc_attr($confirm); ?>">
            <p style="margin: 8px 0;">
                <label>Season start
                    <input type="date" name="season_start" value="<?php echo esc_attr($dates ? $dates['start'] : ''); ?>" required>
                </label>
                <label style="margin-left: 15px;">Season end
                    <input type="date" name="season_end" value="<?php echo esc_attr($dates ? $dates['end'] : ''); ?>" required>
                </label>
            </p>
            <p style="margin: 8px 0;">
                <label>Fees due from
                    <input type="date" name="due_from" value="<?php echo esc_attr($due_from); ?>">
                </label>
                <span class="description">(blank: the season start)</span>
                <label style="margin-left: 15px;">Paid in full by
                    <input type="date" name="paid_by" value="<?php echo esc_attr($paid_by); ?>">
                </label>
                <span class="description">(blank: the season end)</span>
            </p>
            <input type="hidden" name="action" value="save_season_config">
            <?php wp_nonce_field('save_season_config', 'season_config_nonce'); ?>
            <input type="submit" class="button button-primary" value="Save season dates and payment window">
        </form>
        <p style="margin: 8px 0 0;">
            <?php if ($window): ?>
                Fees cover <strong><?php echo esc_html(TeamOversight_Fee_Timeline::format_date($dates['start']) . ' – ' . TeamOversight_Fee_Timeline::format_date($dates['end'])); ?></strong>.
                Payment window <strong><?php echo esc_html(TeamOversight_Fee_Timeline::format_date($window['from']) . ' – ' . TeamOversight_Fee_Timeline::format_date($window['to'])); ?></strong><?php echo ($window['from_set'] && $window['to_set']) ? '' : ' (the season dates where blank)'; ?>.
                Late-join cutoff <strong><?php echo esc_html(TeamOversight_Fee_Timeline::format_date($window['to'])); ?></strong>.
            <?php else: ?>
                <span style="color: #996800;">No dates set — fees are charged in full (no pro-rata), nothing shows as overdue, and there is no late-join cutoff.</span>
            <?php endif; ?>
        </p>
        <script>
        (function () {
            var form = document.getElementById('season-config-form');
            if (!form) {
                return;
            }
            form.addEventListener('submit', function (event) {
                var start = form.querySelector('[name="season_start"]').value;
                var end = form.querySelector('[name="season_end"]').value;
                var changed = (form.dataset.start !== '' && start !== form.dataset.start) || (form.dataset.end !== '' && end !== form.dataset.end);
                if (changed && !window.confirm(form.dataset.confirm)) {
                    event.preventDefault();
                }
            });
        })();
        </script>
        <?php
    }

    public function get_available_seasons() {
        global $wpdb;
        
        // Get all seasons from database (from multiple tables for comprehensive list)
        $seasons_assignments = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments ORDER BY season DESC");
        $seasons_invoices = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_invoices ORDER BY season DESC");
        $seasons_trials = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}trial_applications ORDER BY season DESC");
        
        // Merge and deduplicate all seasons from different tables, plus
        // explicitly created future seasons.
        $created_seasons = get_option('team_oversight_created_seasons', array());
        $all_seasons = array_unique(array_map('strval', array_merge($seasons_assignments, $seasons_invoices, $seasons_trials, is_array($created_seasons) ? $created_seasons : array())));
        rsort($all_seasons); // Sort descending

        $current_year = date('Y');
        $next_year = $current_year + 1;
        $previous_year = $current_year - 1;
        
        // Always ensure current and next year are available
        if (!in_array($current_year, $all_seasons)) {
            $all_seasons[] = $current_year;
        }
        if (!in_array($next_year, $all_seasons)) {
            $all_seasons[] = $next_year;
        }
        
        rsort($all_seasons); // Re-sort after adding current/next year
        
        // For fee matrix, show limited recent seasons (previous, current, next)
        $recent_seasons = array($next_year, $current_year, $previous_year);
        $filtered_seasons = array();
        foreach ($all_seasons as $season) {
            if (in_array($season, $recent_seasons)) {
                $filtered_seasons[] = $season;
            }
        }
        // Ensure we have at least the three key years
        foreach ($recent_seasons as $year) {
            if (!in_array($year, $filtered_seasons)) {
                $filtered_seasons[] = $year;
            }
        }
        // Explicitly created seasons always show, even beyond next year.
        if (is_array($created_seasons)) {
            foreach ($created_seasons as $created) {
                if (!in_array($created, $filtered_seasons)) {
                    $filtered_seasons[] = $created;
                }
            }
        }
        $filtered_seasons = array_values(array_unique(array_map('strval', $filtered_seasons)));
        rsort($filtered_seasons);
        return $filtered_seasons;
    }

    /** Fee classes, most common first — the grid's rows. */
    public static function standard_fee_classes() {
        return array('Full Adult', 'Melb Uni Student', 'Other Student', 'Junior U/19 (VVL)', 'JPL/YSL', 'Unknown');
    }

    /**
     * The classes determine_person_fee_class() can give a person (their
     * class ignoring teams): Junior U/19 (VVL) becomes JPL/YSL per place on
     * a junior-league team (fee_class_for_team()), and nobody is ever put
     * in Unknown. Keep in step with determine_fee_class().
     */
    public static function person_fee_classes() {
        return array('Full Adult', 'Melb Uni Student', 'Other Student', 'Junior U/19 (VVL)');
    }

    /** Team roles, players first — the grid's columns. */
    public static function standard_fee_roles() {
        // Coach / assistant coach / team manager have no column: they're
        // charged at the supporter / playing member rate (CHARGED_AS).
        return self::PAYING_ROLES;
    }

    /** Preferred order first, then anything else found, alphabetically. */
    private static function order_like($found, $preferred) {
        $extra = array_diff($found, $preferred);
        sort($extra);
        return array_merge(array_values(array_intersect($preferred, $found)), $extra);
    }

    /**
     * The season's fee matrix as a grid: rows = fee classes, columns = team
     * roles, cell = the matrix row (or null when that combination has no
     * amount yet). Every season shows the standard rows and columns, so a
     * class missing from the matrix can still be filled in.
     */
    public function get_fee_grid($season) {
        $cells = array();
        foreach ($this->get_fee_matrix_for_season($season) as $fee) {
            $cells[$fee->fee_class][$fee->team_role] = $fee;
        }
        $classes = array_keys($cells);
        $roles = array();
        foreach ($cells as $by_role) {
            $roles = array_merge($roles, array_keys($by_role));
        }
        // Every standard class always has a row (blank cells can be filled
        // in), then any other class found in the matrix.
        $classes = self::order_like(array_values(array_unique(array_merge($classes, self::standard_fee_classes()))), self::standard_fee_classes());
        // Always exactly the paying columns; old coach/manager amounts in the
        // table are no longer used and aren't shown.
        $roles = self::standard_fee_roles();
        return array('classes' => $classes, 'roles' => $roles, 'cells' => $cells);
    }

    /** Copy one season's active fees into a season that has none. Returns rows copied. */
    public function copy_fee_matrix($from, $to) {
        global $wpdb;
        if ($from === '' || $from === $to || !empty($this->get_fee_matrix_for_season($to))) {
            return 0;
        }
        $version = 'copied-' . $from . '-' . $to . '-' . time();
        $n = 0;
        foreach ($this->get_fee_matrix_for_season($from) as $fee) {
            $wpdb->insert($wpdb->prefix . 'fee_matrix', array(
                'version' => $version, 'season' => $to, 'fee_class' => $fee->fee_class,
                'team_role' => $fee->team_role, 'fee_amount' => $fee->fee_amount, 'is_active' => 1,
            ), array('%s', '%s', '%s', '%s', '%f', '%d'));
            $n++;
        }
        if ($n) {
            TeamOversight_Log::add('fee_edit', 'Fee matrix for ' . $to . ' copied from ' . $from . ' (' . $n . ' amounts)');
        }
        return $n;
    }

    public function get_fee_matrix_for_season($season) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}fee_matrix 
            WHERE season = %s AND is_active = 1
            ORDER BY fee_class, team_role
        ", $season));
    }
    
    // Keep existing methods for backwards compatibility
    public function determine_fee_class($email, $season = null) {
        $uid = self::resolve_user_id($email);
        $user = $uid > 0 ? get_userdata($uid) : false;
        if (!$user) return 'Full Adult';
        
        // Default to current year if no season provided
        $season_given = (bool) $season;
        if (!$season) {
            $season = date('Y');
        }

        $birth_date = get_user_meta($user->ID, 'birth_date', true);
        $degree1_type = get_user_meta($user->ID, 'degree1type', true);
        $institution1 = get_user_meta($user->ID, 'institution1', true);
        $degree1_enddate = get_user_meta($user->ID, 'degree1enddate', true);

        // 2027 on (SEASON_CLASS_RULES_FROM) the class is judged at a fixed
        // point for the season, so the day it's worked out never matters:
        // junior = born on or after VV's U19 cutoff for the season (no 19th
        // birthday during it), and alumni = degree ended before their fee
        // was first recorded for the season (fee_class_reference_time()).
        // The profile is still read each time, so a later edit to it
        // (institution, staff, degree end date) changes the class from the
        // next re-price — corrected data, not the date passing. 2026 and
        // earlier, and callers that give no season (a display), keep the
        // original rule: age and degree end as at today.
        $season_rules = $season_given && intval($season) >= self::SEASON_CLASS_RULES_FROM;
        $is_junior = false;
        if ($season_rules) {
            $dob = str_replace('/', '-', trim((string) $birth_date));
            $is_junior = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) && $dob >= TeamOversight_Database::get_dob_cutoff('u19', $season);
        } elseif ($birth_date) {
            // Calculate age
            $today = new DateTime();
            $birthdate_obj = new DateTime($birth_date);
            $age = $today->diff($birthdate_obj)->y;
            $is_junior = $age < 19;
        }

        if ($is_junior) {
            // Check if they're in junior leagues - use correct season
            $assigned_teams = $this->get_user_teams($email, $season);
            foreach ($assigned_teams as $team) {
                if (strpos($team, 'JPL') === 0 || strpos($team, 'YSL') === 0) {
                    return 'JPL/YSL';
                }
            }
            return 'Junior U/19 (VVL)';
        }

        // Check if alumni (graduated) - this is critical for correct billing
        if ($degree1_enddate && strtotime($degree1_enddate) < ($season_rules ? strtotime(self::fee_class_reference_time($email, $season)) : time())) {
            return 'Full Adult'; // Alumni get adult rates regardless of institution
        }
        
        // Check if staff - staff get adult rates
        if ($degree1_type === 'Staff') {
            return 'Full Adult';
        }
        
        // Check education status for current students only
        if ($institution1 === 'Melbourne University') {
            return 'Melb Uni Student'; // Current Melbourne Uni students
        } elseif ($institution1 === 'Other University') {
            return 'Other Student'; // Current other university students
        }
        
        // Default to Full Adult for 'Other' institution or no institution data
        return 'Full Adult';
    }

    /**
     * When a person's date-based fee class (alumni: degree ended or not) is
     * judged for a season from 2027 on: the moment their fee was first
     * recorded for that season — their earliest fee segment, written by the
     * first checkpoint (Finalise, accept or add to a team) — so that moment
     * is kept with the history and time passing never moves them (an edit
     * to their profile still does, from the next re-price). Now, when
     * nothing is recorded yet (the checkpoint about to run records it).
     * Site time, 'Y-m-d H:i:s'.
     *
     * Fee-timeline seasons (2027+) keep the first record in the class
     * history instead: the earliest class_recorded row after the person's
     * latest history_reset, or their first fee segment if that came
     * earlier and no reset was written (a fee recorded before 1.70.1; see
     * TeamOversight_Fee_Timeline::first_record_time()). A backdated start
     * never moves it; a Remove that left them with no place restarts it.
     */
    public static function fee_class_reference_time($email, $season) {
        if (self::timeline_applies($season)) {
            $first = TeamOversight_Fee_Timeline::first_record_time($email, $season);
            return $first ? $first : current_time('mysql');
        }
        global $wpdb;
        $uid = self::resolve_user_id($email);
        $first = $wpdb->get_var($wpdb->prepare("
            SELECT MIN(created_date) FROM {$wpdb->prefix}team_fee_segments
            WHERE (user_id = %d OR email = %s) AND season = %s
        ", $uid, $email, $season));
        return $first ? $first : current_time('mysql');
    }

    public function generate_invoice($email, $season) {
        global $wpdb;
        $this->kept_manual = false;

        // Check if invoice already exists for this user and season
        $uid = self::resolve_user_id($email);
        $existing_invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, invoice_amount FROM {$wpdb->prefix}team_invoices
            WHERE (user_id = %d OR email = %s) AND season = %s
        ", $uid, $email, $season));

        // Fees for this season not released yet: bill nothing — no new
        // invoice, and no re-pricing of one that exists (e.g. a withdrawn
        // invoice holding a credit) — but keep the fee-role history so the
        // invoice made on release is right.
        if (!self::fees_released($season)) {
            $this->checkpoint_fee_role($email, $season);
            return false;
        }

        if ($existing_invoice) {
            // Invoice already exists - update it if needed with new minimum fee calculation
            return $this->update_existing_invoice($email, $season, $existing_invoice->id);
        }

        if (self::timeline_applies($season)) {
            // 2027+ (fee timeline): every place in the season counts,
            // active or stopped, so someone who stopped before the release
            // is still invoiced for the time they played. Record their
            // class first (the checkpoint), then price their places' dates.
            $assignments = TeamOversight_Fee_Timeline::rows($email, $season);
            $fee_class = $this->determine_fee_class($email, $season);
            $this->checkpoint_fee_role($email, $season);
            $breakdown = $this->fee_breakdown($email, $season);
            if (empty($breakdown['stretches'])) {
                // No place inside the season dates (none at all, or every
                // one outside them): nothing to bill, and nothing is wrong,
                // so no server log or activity-log line.
                return false;
            }
            $minimum_fee = self::priced_total($breakdown);
        } else {
            // Get all active assignments for this user and season
            $assignments = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_assignments
            WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1
        ", $uid, $email, $season));

            if (empty($assignments)) {
                return false;
            }

            // The fee comes from the one calculation (fee_breakdown): highest
            // bucket per stretch, pro-rated. Record where their teams stand first.
            $fee_class = $this->determine_fee_class($email, $season);
            $this->checkpoint_fee_role($email, $season);
            $minimum_fee = $this->compute_segment_fee($email, $season, $fee_class);
        }

        if ($minimum_fee === null) {
            // No amount in the matrix for any of their roles: a configuration
            // gap. The server log gets an account number, never the email;
            // the activity log shows admins who wasn't invoiced.
            $assignment_roles = array_values(array_unique(array_map(function($a) { return $a->role; }, $assignments)));
            $log_uid = $uid > 0 ? $uid : intval($assignments[0]->user_id);
            $who = $log_uid > 0 ? 'user #' . $log_uid : 'assignment #' . intval($assignments[0]->id);
            error_log(sprintf('Team Oversight: no %s fee could be worked out for %s (fee class %s, roles %s) - check the fee matrix and season dates', $season, $who, $fee_class, implode(', ', $assignment_roles)));
            TeamOversight_Log::add(
                'fee_edit',
                $season . ' fee could not be worked out (no fee matrix amount for ' . $fee_class . ' / ' . implode(', ', $assignment_roles) . ') — not invoiced',
                $log_uid > 0 ? array('user_id' => $log_uid) : array()
            );
            return false;
        }

        // Create single invoice for all assignments
        $user = $uid > 0 ? get_userdata($uid) : false;
        $name = $user ? $user->display_name : $email;

        // Generate sequential invoice reference: SEASON-MBRFEE-NNNN
        $invoice_reference = $this->generate_invoice_reference($season);

        $result = $wpdb->insert(
            $wpdb->prefix . 'team_invoices',
            array(
                'user_id' => $user ? $user->ID : null,
                'email' => $email,
                'name' => $name,
                'season' => $season,
                'invoice_amount' => $minimum_fee,
                'outstanding_amount' => $minimum_fee,
                'invoice_reference' => $invoice_reference
            ),
            array('%d', '%s', '%s', '%s', '%f', '%f', '%s')
        );
        
        if ($result === false) {
            // Log error for debugging
            error_log('Team Oversight: failed to create the ' . $season . ' invoice for ' . ($user ? 'user #' . intval($user->ID) : 'an account-less assignment') . ': ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    public function get_fee($fee_class, $team_role, $season = null) {
        global $wpdb;
        
        if (!$season) {
            $season = date('Y');
        }
        
        return $wpdb->get_var($wpdb->prepare("
            SELECT fee_amount FROM {$wpdb->prefix}fee_matrix 
            WHERE fee_class = %s AND team_role = %s AND season = %s AND is_active = 1
        ", $fee_class, $team_role, $season));
    }
    
    private function get_user_teams($email, $season) {
        global $wpdb;

        $uid = self::resolve_user_id($email);
        $teams = $wpdb->get_col($wpdb->prepare("
            SELECT team FROM {$wpdb->prefix}team_assignments
            WHERE (user_id = %d OR email = %s) AND season = %s AND is_active = 1
        ", $uid, $email, $season));

        return $teams;
    }
    
    private function update_existing_invoice($email, $season, $invoice_id) {
        global $wpdb;

        $fee_class = $this->determine_fee_class($email, $season);

        // Segment history first: it handles role changes AND freezing the
        // fee at the accrued amount when someone leaves all teams.
        $this->checkpoint_fee_role($email, $season);
        $segment_fee = $this->compute_segment_fee($email, $season, $fee_class);

        // A fee set by hand with Edit Fee sticks (club decision): no
        // automatic re-price changes it, even when they leave every team.
        // The history above is still recorded, and what this re-price would
        // have set is logged, so the treasurer can see the difference and
        // use Use calculated fee.
        $timeline = self::timeline_applies($season);
        if (self::fee_is_manual($invoice_id)) {
            $calculated = $segment_fee;
            if ($calculated === null && ($timeline ? $this->timeline_nothing_accrued($email, $season, $invoice_id) : $this->left_before_season($email, $season, $invoice_id))) {
                $calculated = 0.0;
            }
            $this->note_manual_fee_kept($invoice_id, $calculated);
            return true;
        }

        if ($segment_fee !== null) {
            $this->apply_invoice_amount($invoice_id, $segment_fee, 'worked out again from their teams and the fee matrix' . ($this->reprice_context !== '' ? ' (' . $this->reprice_context . ')' : ''));
            return true;
        }

        if ($timeline) {
            // 2027+: no place left inside the season dates, under this email
            // or the invoice owner's account (removed by mistake, or every
            // place outside the season): nothing accrued, so the fee is $0
            // and anything already paid shows as credit (the ledger is never
            // touched). Places that can't be priced (a matrix gap) keep the
            // invoice as it is.
            if ($this->timeline_nothing_accrued($email, $season, $invoice_id)) {
                $this->apply_invoice_amount($invoice_id, 0.0, 'no ' . $season . ' team place left (removed, or every place outside the season dates)' . ($this->reprice_context !== '' ? ' (' . $this->reprice_context . ')' : ''));
            }
            return true;
        }

        // Left every team before the season started: nothing accrued, so
        // the fee is $0 — anything already paid shows as credit (the
        // ledger is never touched).
        if ($this->left_before_season($email, $season, $invoice_id)) {
            $before = $wpdb->get_row($wpdb->prepare("
                SELECT invoice_amount, user_id FROM {$wpdb->prefix}team_invoices WHERE id = %d
            ", $invoice_id));
            if ($before && abs(floatval($before->invoice_amount)) >= 0.005 && $this->apply_invoice_amount($invoice_id, 0.0)) {
                TeamOversight_Log::add(
                    'fee_edit',
                    $season . ' season fee changed from $' . number_format($before->invoice_amount, 2) . ' to $0.00 — left every team before the season started',
                    array('user_id' => intval($before->user_id), 'amount' => 0)
                );
            }
            return true;
        }

        // No priced history otherwise (no segment history, or no matrix
        // amounts for their roles): keep the invoice as it is.
        return true;
    }

    /**
     * Has the person left every team with none of their fee history inside
     * the season (they left before it started)? The season has dates, they
     * have recorded segments but no active teams, and every stretch falls
     * outside the season window — so their accrued fee is $0, whatever the
     * fee matrix says. Invoices with no segment history (pre-1.9) are not
     * this and keep their amount, as do matrix gaps mid-season (stretches
     * inside the season that can't be priced).
     *
     * Judged under the invoice owner's account as well as $email: the
     * re-price can run under an older email that no longer resolves to them
     * (its last assignment row was just deleted), and someone still on a
     * team under their current email must never drop to $0 — an email
     * change never splits a person.
     */
    private function left_before_season($email, $season, $invoice_id) {
        global $wpdb;
        $dates = self::get_season_dates($season);
        if (!$dates || strtotime($dates['end']) <= strtotime($dates['start'])) {
            return false;
        }
        $identities = array($email);
        $owner = intval($wpdb->get_var($wpdb->prepare("
            SELECT user_id FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id)));
        if ($owner > 0 && self::resolve_user_id($email) !== $owner) {
            $account = get_userdata($owner);
            if (!$account || !$account->user_email) {
                return false;
            }
            $identities[] = $account->user_email;
        }
        $has_segments = false;
        foreach ($identities as $who) {
            if (!empty($this->current_buckets($who, $season))) {
                return false;
            }
            $uid = self::resolve_user_id($who);
            if ($wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_fee_segments
                WHERE (user_id = %d OR email = %s) AND season = %s LIMIT 1
            ", $uid, $who, $season))) {
                $has_segments = true;
            }
            $breakdown = $this->fee_breakdown($who, $season);
            if (!empty($breakdown['stretches'])) {
                return false;
            }
        }
        return $has_segments;
    }

    /**
     * Fee-timeline seasons (2027+): has nothing accrued for this invoice?
     * True when the person has no stretch inside the season dates under
     * $email AND none under the invoice owner's current account email (the
     * same two-identity guard as left_before_season(): a re-price can run
     * under an older email that no longer resolves to them, and someone
     * still on a team must never drop to $0). False when the owner's
     * account can't be read, so the invoice keeps its amount.
     */
    private function timeline_nothing_accrued($email, $season, $invoice_id) {
        global $wpdb;
        $identities = array($email);
        $owner = intval($wpdb->get_var($wpdb->prepare("
            SELECT user_id FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id)));
        if ($owner > 0 && self::resolve_user_id($email) !== $owner) {
            $account = get_userdata($owner);
            if (!$account || !$account->user_email) {
                return false;
            }
            $identities[] = $account->user_email;
        }
        foreach ($identities as $who) {
            $breakdown = $this->fee_breakdown($who, $season);
            if (!empty($breakdown['stretches'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set an invoice to a new fee amount, preserving payments already made
     * (outstanding = new fee minus paid, never below zero). With a $reason
     * the change is logged with the old and new amounts, so every automatic
     * re-price can always be seen and put back. Never writes over a fee set
     * with Edit Fee: the update only matches fee_overridden = 0, so a fee
     * marked manual meanwhile (or by a caller that didn't check) is left as
     * it is, with nothing recomputed or logged. Returns false when nothing
     * was set (same amount, no invoice, or a manual fee).
     */
    private function apply_invoice_amount($invoice_id, $new_fee, $reason = '') {
        global $wpdb;

        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT invoice_amount, season, user_id FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id));
        $current_fee = $invoice ? $invoice->invoice_amount : null;

        if ($current_fee === null || floatval($current_fee) == floatval($new_fee)) {
            return false;
        }

        // Set the new fee; outstanding is always fee minus the payments
        // ledger — recorded money is never touched by repricing. A rise is
        // stamped (fee_rise_fields()), so the fee standing never counts it
        // as behind from before today.
        list($rise_fields, $rise_formats) = self::fee_rise_fields($current_fee, $new_fee);
        $updated = $wpdb->update(
            $wpdb->prefix . 'team_invoices',
            array_merge(array('invoice_amount' => $new_fee), $rise_fields),
            array('id' => $invoice_id, 'fee_overridden' => 0),
            array_merge(array('%f'), $rise_formats),
            array('%d', '%d')
        );
        if (!$updated && self::fee_is_manual($invoice_id)) {
            return false; // set with Edit Fee: left as it is, nothing to recompute or log
        }
        TeamOversight_Payments::recompute_invoice($invoice_id);
        if ($reason !== '' && abs(floatval($current_fee) - floatval($new_fee)) >= 0.005) {
            TeamOversight_Log::add(
                'fee_edit',
                $invoice->season . ' season fee re-priced from $' . number_format($current_fee, 2) . ' to $' . number_format($new_fee, 2) . ' — ' . $reason,
                array('user_id' => intval($invoice->user_id), 'amount' => $new_fee)
            );
        }
        return true;
    }

    /**
     * The fee-standing column to write with a new fee: fee_raised_at = now
     * when the fee goes up, so the raised amount is never behind from
     * before today. A lower fee or a re-save never restarts the behind
     * clock. Every rise does, even back up to an amount held before (off a
     * team and back on): that errs towards not flagging someone, never
     * towards flagging a player who has just come back. Every writer of
     * invoice_amount uses it: apply_invoice_amount() and Edit Fee.
     * Returns array(fields, formats) to merge into the update.
     */
    public static function fee_rise_fields($current_fee, $new_fee) {
        if (floatval($new_fee) > floatval($current_fee) + 0.005) {
            return array(array('fee_raised_at' => current_time('mysql')), array('%s'));
        }
        return array(array(), array());
    }

    /**
     * Was this invoice's fee set by hand with Edit Fee (fee_overridden)?
     * Then no automatic re-price changes it — any new re-pricing path must
     * check this too — until Use calculated fee clears the marker.
     */
    public static function fee_is_manual($invoice_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("
            SELECT fee_overridden FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id))) === 1;
    }

    /** Did the last generate_invoice() keep a fee set with Edit Fee instead of re-pricing it? */
    public function last_kept_manual() {
        return $this->kept_manual;
    }

    /**
     * A re-price left a fee set with Edit Fee as it was. Log the amount it
     * would have set ($calculated; null when nothing could be worked out,
     * which logs nothing), only when that differs from the manual fee and
     * only once per amount (fee_calculated_logged), so the treasurer sees
     * the difference without a line for every re-price.
     */
    private function note_manual_fee_kept($invoice_id, $calculated) {
        global $wpdb;
        $this->kept_manual = true;
        if ($calculated === null) {
            return;
        }
        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT invoice_amount, season, user_id, fee_calculated_logged FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id));
        $calculated = round(floatval($calculated), 2);
        if (!$invoice) {
            return;
        }
        if (abs(floatval($invoice->invoice_amount) - $calculated) < 0.005) {
            // The calculation agrees with the manual fee now: forget the last
            // amount logged, so a later swing back to it is logged again.
            if ($invoice->fee_calculated_logged !== null) {
                $wpdb->update(
                    $wpdb->prefix . 'team_invoices',
                    array('fee_calculated_logged' => null),
                    array('id' => $invoice_id),
                    array('%f'),
                    array('%d')
                );
            }
            return;
        }
        if ($invoice->fee_calculated_logged !== null && abs(floatval($invoice->fee_calculated_logged) - $calculated) < 0.005) {
            return; // this amount was logged already
        }
        $wpdb->update(
            $wpdb->prefix . 'team_invoices',
            array('fee_calculated_logged' => $calculated),
            array('id' => $invoice_id),
            array('%f'),
            array('%d')
        );
        TeamOversight_Log::add(
            'fee_manual_kept',
            $invoice->season . ' season fee kept at $' . number_format($invoice->invoice_amount, 2) . ', set by hand with Edit Fee; re-pricing would have made it $' . number_format($calculated, 2)
                . ' (' . ($this->reprice_context !== '' ? $this->reprice_context : 'their teams changed') . '). Use calculated fee on Payment Management applies the calculation.',
            array('user_id' => intval($invoice->user_id), 'amount' => $calculated)
        );
    }

    /**
     * Payment Management's "Use calculated fee": clear the Edit Fee marker
     * and re-price the invoice through the normal path (generate_invoice:
     * fee history checkpointed, the change logged, payments untouched),
     * under the owner's current account email. Before the season's fees
     * are released nothing is re-priced; the release does it. Returns null
     * when the invoice doesn't exist, else before / after / released.
     */
    public function use_calculated_fee($invoice_id) {
        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare("
            SELECT id, user_id, email, season, invoice_amount FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice_id));
        if (!$invoice) {
            return null;
        }
        $wpdb->update(
            $wpdb->prefix . 'team_invoices',
            array('fee_overridden' => 0, 'fee_overridden_at' => null, 'fee_overridden_by' => null, 'fee_calculated_logged' => null),
            array('id' => $invoice->id),
            array('%d', '%s', '%d', '%f'),
            array('%d')
        );

        $email = $invoice->email;
        $account = intval($invoice->user_id) > 0 ? get_userdata(intval($invoice->user_id)) : false;
        if ($account && $account->user_email) {
            $email = $account->user_email;
        }
        $released = self::fees_released($invoice->season);
        $this->reprice_context = 'Use calculated fee';
        $this->generate_invoice($email, $invoice->season);
        $this->reprice_context = '';

        $after = floatval($wpdb->get_var($wpdb->prepare("
            SELECT invoice_amount FROM {$wpdb->prefix}team_invoices WHERE id = %d
        ", $invoice->id)));
        // A changed fee was logged by the re-price (old -> new); an unchanged
        // one still gets a line, so the switch back is on record.
        if (abs($after - floatval($invoice->invoice_amount)) < 0.005) {
            TeamOversight_Log::add(
                'fee_edit',
                $invoice->season . ' season fee is automatic again (Use calculated fee) and stays at $' . number_format($after, 2)
                    . ($released ? '' : ' until ' . $invoice->season . ' fees are released'),
                array('user_id' => intval($invoice->user_id), 'amount' => $after)
            );
        }
        return array('before' => floatval($invoice->invoice_amount), 'after' => $after, 'released' => $released);
    }

    private function generate_invoice_reference($season) {
        global $wpdb;
        
        // Get the highest invoice number for this season
        $last_invoice = $wpdb->get_var($wpdb->prepare("
            SELECT invoice_reference FROM {$wpdb->prefix}team_invoices 
            WHERE season = %s AND invoice_reference LIKE %s
            ORDER BY invoice_reference DESC
            LIMIT 1
        ", $season, $season . '-MBRFEE-%'));
        
        $next_number = 100; // Start at 0100
        
        if ($last_invoice) {
            // Extract the number from the last invoice reference
            $parts = explode('-', $last_invoice);
            if (count($parts) >= 3) {
                $last_number = intval($parts[2]);
                $next_number = $last_number + 1;
            }
        }
        
        return $season . '-MBRFEE-' . sprintf('%04d', $next_number);
    }
    
    /**
     * The season a team action applies to. Every team edit is scoped to
     * one season (1.48.0+); a request without one is refused rather than
     * guessed, so nothing can silently change another season's list.
     */
    private function posted_team_season() {
        $season = isset($_POST['season']) ? sanitize_text_field(wp_unslash($_POST['season'])) : '';
        if (!preg_match('/^\d{4}$/', $season)) {
            wp_send_json_error('Season is required');
        }
        // A season still showing the pre-1.48 global list gets its own
        // copy before it is edited, so the edit lands on that season only.
        TeamOversight_Database::seed_season_teams($season);
        return $season;
    }

    public function save_team() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'save_team')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $season = $this->posted_team_season();
        $team_code = sanitize_text_field($_POST['team_code']);
        $team_name = sanitize_text_field(wp_unslash($_POST['team_name']));

        if (empty($team_code) || empty($team_name)) {
            wp_send_json_error('Team code and name are required');
        }
        if (preg_match('/-\d{4}$/', $team_code)) {
            wp_send_json_error('Team codes must not end in a year — the season is attached automatically');
        }

        $key = TeamOversight_Database::season_team_key($team_code, $season);
        $teams = get_option('team_oversight_teams', array());
        if (isset($teams[$key])) {
            wp_send_json_error('Team code already exists for the ' . $season . ' season');
        }

        $teams[$key] = $team_name;
        update_option('team_oversight_teams', $teams);

        $meta = get_option('team_oversight_team_meta', array());
        $meta[$key] = $this->get_posted_team_meta();
        update_option('team_oversight_team_meta', $meta);

        wp_send_json_success('Team added to the ' . $season . ' season');
    }

    public function update_team() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'update_team')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $season = $this->posted_team_season();
        $team_code = sanitize_text_field($_POST['team_code']);
        $team_name = sanitize_text_field(wp_unslash($_POST['team_name']));

        if (empty($team_code) || empty($team_name)) {
            wp_send_json_error('Team code and name are required');
        }

        $key = TeamOversight_Database::season_team_key($team_code, $season);
        $teams = get_option('team_oversight_teams', array());
        if (!isset($teams[$key])) {
            wp_send_json_error('Team code not found in the ' . $season . ' season');
        }

        $teams[$key] = $team_name;
        update_option('team_oversight_teams', $teams);

        $meta = get_option('team_oversight_team_meta', array());
        $meta[$key] = $this->get_posted_team_meta();
        update_option('team_oversight_team_meta', $meta);

        wp_send_json_success('Team updated for the ' . $season . ' season');
    }

    public function delete_team() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'delete_team')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $season = $this->posted_team_season();
        $team_code = sanitize_text_field($_POST['team_code']);

        if (empty($team_code)) {
            wp_send_json_error('Team code is required');
        }

        $key = TeamOversight_Database::season_team_key($team_code, $season);
        $teams = get_option('team_oversight_teams', array());
        if (!isset($teams[$key])) {
            wp_send_json_error('Team code not found in the ' . $season . ' season');
        }

        // Only THIS season's assignments block the delete — the same code
        // in another season is a different team entry.
        global $wpdb;
        $usage_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}team_assignments
            WHERE team = %s AND season = %s AND is_active = 1
        ", $team_code, $season));

        if ($usage_count > 0) {
            wp_send_json_error('Cannot delete team: ' . $usage_count . ' active ' . $season . ' assignment' . ($usage_count == 1 ? '' : 's') . ' exist for this team. Remove them in Team Assignments first.');
        }

        unset($teams[$key]);
        update_option('team_oversight_teams', $teams);

        $meta = get_option('team_oversight_team_meta', array());
        if (isset($meta[$key])) {
            unset($meta[$key]);
            update_option('team_oversight_team_meta', $meta);
        }

        wp_send_json_success('Team deleted from the ' . $season . ' season');
    }

    public function reset_default_teams() {
        if (!wp_verify_nonce($_POST['team_nonce'], 'reset_default_teams')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $season = $this->posted_team_season();
        $names = get_option('team_oversight_teams', array());
        $meta = get_option('team_oversight_team_meta', array());

        // Drop only this season's entries, then lay the defaults in.
        foreach (array_keys((array) $names) as $key) {
            list(, $key_season) = TeamOversight_Database::split_team_key($key);
            if ($key_season === $season) {
                unset($names[$key], $meta[$key]);
            }
        }
        foreach (TeamOversight_Database::get_default_teams() as $code => $team) {
            $key = TeamOversight_Database::season_team_key($code, $season);
            $names[$key] = $team['name'];
            $meta[$key] = array(
                'gender' => $team['gender'],
                'age_rule' => $team['age_rule'],
                'shirts' => isset($team['shirts']) ? $team['shirts'] : 1,
            );
        }
        update_option('team_oversight_teams', $names);
        update_option('team_oversight_team_meta', $meta);

        wp_send_json_success('Default team list loaded for the ' . $season . ' season');
    }
}