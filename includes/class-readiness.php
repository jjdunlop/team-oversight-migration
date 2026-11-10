<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ready to Play checklist.
 *
 * [ready_to_play] renders, for players on a team (an active playing /
 * training assignment: a locked-in offer, the club's Finalise, Accept or Add
 * Assignment — never a coach verdict, which is still the club's working
 * shortlist), the steps to complete before playing, in priority order. Since
 * 1.76.0 it also learns about selection from an OFFER that has been sent to
 * the player (a banner above the panels, and a read-only preview of these
 * same steps on the offer page), and from 2027 an offer the player has
 * ACCEPTED (or that is locked in and not placed yet) makes that season's
 * panel the player checklist with the offered team counted, its heading
 * saying they must pay the deposit to lock the place in and its fees step
 * pointing at the deposit box (season_panel()); a verdict alone, a sent
 * offer and an ended offer still show nothing:
 *
 *  1. VV membership — external registration, manual "I've done it" tick
 *  2. Playing kit — shirt(s)/shorts/socks from the club shop. Purchases
 *     this season auto-complete the step; a manual tick covers players who
 *     already own kit; the shop link stays visible for re-orders. Shirt
 *     quantity comes from the team config (Premier 2, YSL 0, default 1) —
 *     the highest requirement across the player's teams applies.
 *  3. Club fees — judged from the payment schedule: paid in full or
 *     on-track (nothing overdue) counts as ready.
 *
 * The admin Player Readiness page shows the same computation for every
 * selected player in the season, coach Selected / Training Only verdicts
 * included, so the club can plan kit before Finalise.
 */
class TeamOversight_Readiness {

    const VV_URL_OPTION = 'team_oversight_vv_reg_url';
    const KIT_URL_OPTION = 'team_oversight_kit_shop_url';
    const FEES_URL_OPTION = 'team_oversight_fees_page_url';
    const KIT_PRODUCTS_OPTION = 'team_oversight_kit_products';

    public function __construct() {
        add_shortcode('ready_to_play', array($this, 'render_shortcode'));
        // Ticks are processed before output and answered with a redirect
        // (Post/Redirect/Get), so refreshing never resubmits the form.
        add_action('template_redirect', array($this, 'maybe_handle_toggle'));
    }

    /**
     * Handle a manual step tick/untick, then redirect back to the page.
     * The form sends the explicit target state, so even a resubmission
     * is idempotent rather than a toggle-flip.
     */
    public function maybe_handle_toggle() {
        if (!isset($_POST['murvc_rtp_toggle'], $_POST['murvc_rtp_nonce']) || !is_user_logged_in()) {
            return;
        }
        if (!wp_verify_nonce($_POST['murvc_rtp_nonce'], 'murvc_rtp')) {
            return;
        }

        $user = wp_get_current_user();
        $current_year = intval(date('Y'));
        $seasons = array((string) $current_year, (string) ($current_year + 1));

        $step = sanitize_text_field($_POST['murvc_rtp_toggle']);
        $season = isset($_POST['murvc_rtp_season']) ? sanitize_text_field($_POST['murvc_rtp_season']) : $seasons[0];
        $target_done = isset($_POST['murvc_rtp_value']) && $_POST['murvc_rtp_value'] === '1';

        // 1.92.1 (club decision, 10 Nov 2026): VV registration is ticked by the club on Player Readiness, never by
        // the player, so only the kit step can be ticked here (an old page's vv tick changes nothing).
        if ($step === 'kit' && in_array($season, $seasons, true)) {
            $manual = get_user_meta($user->ID, 'murvc_rtp_' . $season, true);
            $manual = is_array($manual) ? $manual : array();
            if ($target_done && !in_array($step, $manual, true)) {
                $manual[] = $step;
            } elseif (!$target_done) {
                $manual = array_values(array_diff($manual, array($step)));
            }
            update_user_meta($user->ID, 'murvc_rtp_' . $season, $manual);
        }

        wp_safe_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    // ------------------------------------------------------------------
    // VV registration, ticked by the club (1.92.1, club decision 10 Nov 2026)
    // ------------------------------------------------------------------

    /** User meta: season => array(by, at), the club's tick that a person's VV registration is done. */
    const VV_CONFIRM_META = 'murvc_vv_confirmed';
    /** User meta: the person's Volleyball Victoria ID (VVID), kept on their account across seasons. */
    const VVID_META = 'murvc_vvid';
    const VVID_MAX = 20;

    /** The club's VV tick for a season: array(by, at) or null. */
    public static function vv_confirmed($user_id, $season) {
        $all = get_user_meta(intval($user_id), self::VV_CONFIRM_META, true);
        $season = (string) $season;
        return (is_array($all) && isset($all[$season]) && is_array($all[$season])) ? $all[$season] : null;
    }

    public static function get_vvid($user_id) {
        $v = get_user_meta(intval($user_id), self::VVID_META, true);
        return is_string($v) ? $v : '';
    }

    /**
     * Save the club's VV tick for a season and the person's VVID. Administrators
     * only. $vvid: letters, digits and dashes, at most VVID_MAX; blank clears it.
     * Returns array(ok, message).
     */
    public static function save_vv_registration($user_id, $season, $confirmed, $vvid, $actor) {
        if (!user_can($actor, 'manage_options')) {
            return array('ok' => false, 'message' => 'Only an administrator can confirm a VV registration.');
        }
        $user = get_userdata(intval($user_id));
        if (!$user) {
            return array('ok' => false, 'message' => 'That account no longer exists.');
        }
        $season = (string) $season;
        if (preg_match('/^\d{4}$/D', $season) !== 1) {
            return array('ok' => false, 'message' => 'Choose a season.');
        }
        $vvid = strtoupper(trim((string) $vvid));
        if ($vvid !== '' && (strlen($vvid) > self::VVID_MAX || preg_match('/^[A-Z0-9-]+$/', $vvid) !== 1)) {
            return array('ok' => false, 'message' => 'A VVID is letters, numbers and dashes only, up to ' . self::VVID_MAX . ' characters. Nothing was saved.');
        }
        $all = get_user_meta($user->ID, self::VV_CONFIRM_META, true);
        $all = is_array($all) ? $all : array();
        $was = isset($all[$season]);
        if ($confirmed && !$was) {
            $all[$season] = array('by' => intval($actor), 'at' => current_time('mysql'));
        } elseif (!$confirmed && $was) {
            unset($all[$season]);
        }
        if (empty($all)) {
            delete_user_meta($user->ID, self::VV_CONFIRM_META);
        } else {
            update_user_meta($user->ID, self::VV_CONFIRM_META, $all);
        }
        $old_vvid = self::get_vvid($user->ID);
        if ($vvid === '') {
            delete_user_meta($user->ID, self::VVID_META);
        } else {
            update_user_meta($user->ID, self::VVID_META, $vvid);
        }
        $parts = array();
        if ($confirmed !== $was) {
            $parts[] = $confirmed ? 'VV registration confirmed for ' . $season : 'VV registration no longer confirmed for ' . $season;
        }
        if ($vvid !== $old_vvid) {
            $parts[] = $vvid === '' ? 'VVID cleared' : 'VVID saved as ' . $vvid;
        }
        return array('ok' => true, 'message' => empty($parts) ? 'No change for ' . $user->display_name . '.' : $user->display_name . ': ' . implode('; ', $parts) . '.');
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * Product IDs per kit category: array(shirt => [ids], shorts => [ids], socks => [ids]).
     */
    public static function get_kit_products() {
        $stored = get_option(self::KIT_PRODUCTS_OPTION, array());
        $products = array();
        foreach (array('shirt', 'shorts', 'socks') as $category) {
            $ids = isset($stored[$category]) ? $stored[$category] : array();
            $products[$category] = array_values(array_filter(array_map('intval', (array) $ids)));
        }
        return $products;
    }

    // ------------------------------------------------------------------
    // Computation
    // ------------------------------------------------------------------

    /**
     * Teams the user is playing/training for this season: confirmed
     * (active) assignments. $include_verdicts adds coach Selected /
     * Training-Only verdicts — for the admin Player Readiness page only.
     * $extra_teams (1.76.0) adds those bare team codes and nothing else: the
     * offer page's read-only preview passes the one team an offer was SENT
     * for, which $include_verdicts could not do (it would show a player every
     * team a coach has picked them for, whether or not an offer went out).
     * What a player sees about their own selection comes from assignments
     * and from an offer that has been sent to them, never from a verdict
     * alone.
     */
    public static function get_player_teams($user_id, $email, $season, $include_verdicts = false, $extra_teams = array()) {
        global $wpdb;

        // Team managers count as players: the separate role only exists for
        // their fee discount, so they need the full kit checklist too.
        $assigned = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT team FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND is_active = 1
                AND role IN ('playing_member', 'training_only', 'team_manager')
                AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
        ", $season, $user_id, $email));

        $extra = array();
        foreach ((array) $extra_teams as $code) {
            $code = is_scalar($code) ? trim(sanitize_text_field((string) $code)) : '';
            if ($code !== '') {
                $extra[] = $code;
            }
        }

        if (!$include_verdicts) {
            return array_values(array_unique(array_merge($assigned, $extra)));
        }

        $selected = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT s.team
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.season = %s AND s.status IN ('selected', 'training_only')
                AND a.application_status IN ('pending', 'accepted')
                AND a.user_id = %d
        ", $season, $user_id));

        return array_values(array_unique(array_merge($assigned, $selected, $extra)));
    }

    /**
     * Teams the user coaches this season (coach / assistant coach only —
     * team managers are players who happen to get a fee discount, so they
     * take the full player checklist). Coaches get a reduced checklist:
     * VV registration and fees, no kit steps.
     */
    public static function get_staff_teams($user_id, $email, $season) {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT team FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND is_active = 1
                AND role IN ('coach', 'assistant_coach')
                AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
        ", $season, $user_id, $email));
    }

    /**
     * Kit quantities purchased in paid orders, per category.
     *
     * Shirts count ALL-TIME: the club issues the shirt and the payment is
     * once-ever — a shirt paid for years ago is still paid for. Shorts and
     * socks count this season only; the manual "I already have these" tick
     * covers older pairs.
     */
    public static function get_kit_purchases($user_id, $season) {
        global $wpdb;

        $products = self::get_kit_products();
        $all_ids = array_merge($products['shirt'], $products['shorts'], $products['socks']);
        $purchased = array('shirt' => 0, 'shorts' => 0, 'socks' => 0);

        if (empty($all_ids)) {
            return $purchased;
        }

        $season_start = intval($season) . '-01-01';
        $id_list = implode(',', array_map('intval', $all_ids));
        // Status via wc_order_stats (WooCommerce analytics) — maintained
        // under both legacy and HPOS order storage, unlike wp_posts.
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT opl.product_id, opl.date_created, opl.product_qty
            FROM {$wpdb->prefix}wc_order_product_lookup opl
            JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = opl.customer_id
            JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id AND os.status IN ('wc-processing', 'wc-completed')
            WHERE cl.user_id = %d
                AND opl.product_id IN ({$id_list})
        ", $user_id));

        foreach ($rows as $row) {
            $product_id = intval($row->product_id);
            if (in_array($product_id, $products['shirt'], true)) {
                $purchased['shirt'] += intval($row->product_qty);
            } elseif ($row->date_created >= $season_start) {
                if (in_array($product_id, $products['shorts'], true)) {
                    $purchased['shorts'] += intval($row->product_qty);
                } elseif (in_array($product_id, $products['socks'], true)) {
                    $purchased['socks'] += intval($row->product_qty);
                }
            }
        }

        return $purchased;
    }

    /**
     * Admin-recorded shirt credit for payments made outside this account
     * (e.g. under an old email). array(qty, note).
     */
    public static function get_shirt_credit($user_id) {
        $credit = get_user_meta($user_id, 'murvc_shirt_credit', true);
        return array(
            'qty' => is_array($credit) && isset($credit['qty']) ? max(0, intval($credit['qty'])) : 0,
            'note' => is_array($credit) && isset($credit['note']) ? $credit['note'] : '',
        );
    }

    /**
     * The full checklist for a player, or null if they aren't on any team
     * this season. $include_verdicts: also count coach Selected / Training
     * Only verdicts (the admin Player Readiness page) — never for the
     * player-facing panel. $extra_teams (1.76.0): bare team codes counted
     * as the player's teams as well (the offer page's read-only preview
     * passes the team an offer was sent for). Writes nothing.
     */
    public static function compute($user, $season, $include_verdicts = false, $extra_teams = array()) {
        $teams = self::get_player_teams($user->ID, $user->user_email, $season, $include_verdicts, $extra_teams);

        // Coaches/staff who aren't also playing get a reduced checklist:
        // VV registration + fees only (no shirt, no shorts & socks).
        $is_staff_only = false;
        if (empty($teams)) {
            $staff_teams = self::get_staff_teams($user->ID, $user->user_email, $season);
            if (empty($staff_teams)) {
                return null;
            }
            $teams = $staff_teams;
            $is_staff_only = true;
        }

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);

        // Highest shirt requirement across their teams.
        $shirts_required = 0;
        foreach ($teams as $team) {
            $shirts_required = max($shirts_required, isset($teams_config[$team]['shirts']) ? intval($teams_config[$team]['shirts']) : 1);
        }

        $manual = get_user_meta($user->ID, 'murvc_rtp_' . $season, true);
        $manual = is_array($manual) ? $manual : array();

        // 1. VV membership. 1.92.1 (club decision, 10 Nov 2026): done only once the club ticks it on Player
        // Readiness (vv_confirmed()); the player can't tick it. An earlier self-tick is kept as a hint for the club.
        $steps = array();
        $steps['vv'] = array(
            'title' => 'Register your Volleyball Victoria membership',
            'done' => self::vv_confirmed($user->ID, $season) !== null,
            'manual' => false,
            'self_ticked' => in_array('vv', $manual, true),
            'url' => get_option(self::VV_URL_OPTION),
            'url_label' => 'Register with VV',
            'detail' => $is_staff_only
                ? 'Every coach needs a current VV membership before taking the bench. Register on the VV website; the club ticks this off once your registration comes through.'
                : 'Every player needs a current VV membership before taking the court. Register on the VV website; the club ticks this off once your registration comes through.',
        );

        $purchased = $is_staff_only ? array('shirt' => 0, 'shorts' => 0, 'socks' => 0) : self::get_kit_purchases($user->ID, $season);
        $kit_products = self::get_kit_products();

        // 2. Playing shirt — a payment obligation, not a purchase decision.
        // The club issues the shirt regardless; it must be PAID for, once
        // ever (all-time purchases count, plus any admin-recorded credit for
        // payments made under a different account). No self-tick.
        // Coaches don't play, so no shirt step for them.
        if (!$is_staff_only && $shirts_required > 0) {
            $credit = self::get_shirt_credit($user->ID);
            $shirts_paid = $purchased['shirt'] + $credit['qty'];
            $shirt_done = !empty($kit_products['shirt']) && $shirts_paid >= $shirts_required;

            $detail = 'The club issues your playing shirt, but it must be paid for — a one-off payment that carries over between seasons. ';
            $detail .= 'Paid: ' . min($shirts_paid, $shirts_required) . ' of ' . $shirts_required . ' shirt' . ($shirts_required > 1 ? 's' : '') . '.';
            if ($credit['qty'] > 0) {
                $detail .= ' (Includes ' . $credit['qty'] . ' recorded by the club' . ($credit['note'] ? ': ' . $credit['note'] : '') . '.)';
            }
            if (!$shirt_done) {
                $detail .= ' If you paid under a different account or email, contact the club and we\'ll record it against this one.';
            }

            $steps['shirt'] = array(
                'title' => 'Pay for your playing shirt' . ($shirts_required > 1 ? 's' : ''),
                'done' => $shirt_done,
                'manual' => false,
                'url' => get_option(self::KIT_URL_OPTION),
                'url_label' => 'Pay for shirt' . ($shirts_required > 1 ? 's' : ''),
                'shirt_paid' => $shirts_paid,
                'shirt_purchased' => $purchased['shirt'],
                'shirt_credit' => $credit,
                'shirt_required' => $shirts_required,
                'detail' => $detail,
            );
        }

        // 3. Shorts & socks — regular products, only issued once purchased.
        // Coaches don't need kit either.
        if (!$is_staff_only) {
            $required = array('shorts' => 1, 'socks' => 1);
            $labels = array('shorts' => 'Playing shorts', 'socks' => 'Club socks');
            $items = array();
            $auto_done = true;
            foreach ($required as $category => $qty) {
                $have = $purchased[$category];
                $satisfied = !empty($kit_products[$category]) && $have >= $qty;
                if (!$satisfied) {
                    $auto_done = false;
                }
                $items[] = array(
                    'label' => $labels[$category],
                    'purchased' => $have,
                    'satisfied' => $satisfied,
                );
            }

            $steps['kit'] = array(
                'title' => 'Get your shorts and socks',
                'done' => $auto_done || in_array('kit', $manual, true),
                'auto_done' => $auto_done,
                'manual' => true,
                'url' => get_option(self::KIT_URL_OPTION),
                'url_label' => 'Shop shorts & socks',
                'items' => $items,
                'detail' => 'Purchases from the club shop this season tick off automatically. Still using shorts and socks from a previous season? Tick the box.',
            );
        }

        // 4. Club fees — ALL seasons, so debts carried over from earlier
        // years surface here too (they count as fully overdue). The
        // schedule bar and next-due date stay scoped to this panel's season.
        global $wpdb;
        $invoices = $wpdb->get_results($wpdb->prepare("
            SELECT *, UNIX_TIMESTAMP(created_date) AS created_ts FROM {$wpdb->prefix}team_invoices
            WHERE user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s)
        ", $user->ID, $user->user_email));
        // 1.76.0: a $0 holding invoice that carries deposit money is not a
        // fee either, whether or not the fees are released. Ask the offers
        // code what it is (null for everyone with no offer, and always for a
        // season before 2027, so nothing below changes for them).
        $stage = self::season_deposit_stage($invoices, $season);
        $stage_words = $stage ? trim((string) $stage['words']) : '';

        // Until the club releases this season's fees, an invoice for it with
        // nothing owing is only a placeholder ($0, or an early payment held
        // as credit): not a fee, so not "paid in full", and left out of the
        // figures. A fee set by hand before the release (money owing on it)
        // still counts, as it always has.
        $released = TeamOversight_Fees::fees_released($season);
        $held = 0.0;
        if (!$released) {
            $counted = array();
            foreach ($invoices as $invoice) {
                if ((string) $invoice->season === (string) $season && floatval($invoice->outstanding_amount) <= 0) {
                    $held += max(0, TeamOversight_Payments::get_ledger_paid($invoice->id));
                    continue;
                }
                $counted[] = $invoice;
            }
            $invoices = $counted;
        }
        $season_fee_set = false;
        foreach ($invoices as $invoice) {
            if ((string) $invoice->season === (string) $season) {
                $season_fee_set = true;
            }
        }
        $not_released = '';
        if (!$released && !$season_fee_set) {
            $release_date = TeamOversight_Fees::get_fee_release_date($season);
            $not_released = 'Your ' . $season . ' season fee hasn\'t been released yet'
                . ($release_date !== '' ? ': it will appear here on ' . TeamOversight_Fee_Timeline::format_date($release_date) : '') . '.'
                . ($held >= 0.01 ? ' The $' . number_format($held, 2) . ' you\'ve already paid will come off it.' : '');
        }

        // Each invoice on its own season's schedule, the same one the pay
        // box charges by (TeamOversight_Payments::invoice_schedule()).
        TeamOversight_Fee_Timeline::preload_for_invoices($invoices);

        $outstanding = 0;
        $overdue = 0;
        $invoiced_total = 0;
        $season_invoiced = 0;
        $season_outstanding = 0;
        $season_invoice = null;
        $other_season_debt = false;
        foreach ($invoices as $invoice) {
            $outstanding += floatval($invoice->outstanding_amount);
            $invoiced_total += floatval($invoice->invoice_amount);
            $overdue += TeamOversight_Payments::invoice_overdue($invoice);
            if ((string) $invoice->season === (string) $season) {
                $season_invoiced += floatval($invoice->invoice_amount);
                $season_outstanding += floatval($invoice->outstanding_amount);
                if ($season_invoice === null) {
                    $season_invoice = $invoice;
                }
            } elseif (floatval($invoice->outstanding_amount) > 0) {
                $other_season_debt = true;
            }
        }
        // 2027+: the panel season's invoice on the player's own schedule
        // (2026 and earlier keep the season-long wording and maths).
        $season_schedule = (TeamOversight_Fees::timeline_applies($season) && $season_invoice)
            ? TeamOversight_Payments::invoice_schedule($season_invoice)
            : null;
        $own_schedule = $season_schedule ? TeamOversight_Payments::describe_member_schedule($season_schedule) : '';

        // Nothing to show but "not released yet": no table of $0 figures.
        $only_not_released = $not_released !== '' && $outstanding <= 0;
        // A $0 holding invoice the offers code can describe (deposit paid,
        // fee not set yet, money held for a refund ...): say that, never
        // "Paid in full" and never "will come off it" for money that won't.
        $says_stage = $stage && $stage_words !== '' && $stage['holding'] && $outstanding <= 0;
        if ($says_stage) {
            $only_not_released = true;
            $fees_done = true;
            $fees_detail = $stage_words;
        } elseif ($only_not_released) {
            $fees_done = true;
            $fees_detail = $not_released . ' Nothing to pay until then.';
        } elseif (empty($invoices)) {
            $fees_done = true;
            $fees_detail = 'Your season fee will appear here once the club issues this season\'s fees — nothing to pay yet.';
        } elseif ($outstanding <= 0) {
            $fees_done = true;
            $fees_detail = 'Paid in full — thank you!';
        } elseif ($overdue <= 0) {
            $fees_done = true;
            $fees_detail = $own_schedule !== ''
                ? 'You\'re on track. ' . $own_schedule . ' Pay any amount, as often as you like, as long as you stay ahead of your schedule.'
                : 'You\'re on track. Fees fall due progressively across the season — pay any amount, as often as you like, as long as you stay ahead of the schedule.';
        } else {
            $fees_done = false;
            $fees_detail = $own_schedule !== ''
                ? 'You\'ve fallen behind your payment schedule — please make a payment to get back on track. ' . $own_schedule
                : 'You\'ve fallen behind the payment schedule — please make a payment to get back on track.';
            if ($other_season_debt) {
                $fees_detail .= ' Your balance includes unpaid fees carried over from another season.';
            }
        }
        if ($not_released !== '' && !$only_not_released) {
            $fees_detail .= ' ' . $not_released;
        }
        // A real fee invoice that also has an offer's money on it: add what the club says about that money.
        if ($stage_words !== '' && !$says_stage) {
            $fees_detail .= ' ' . $stage_words;
        }

        $steps['fees'] = array(
            'title' => 'Stay up to date on your fees',
            'done' => $fees_done,
            'manual' => false,
            'url' => get_option(self::FEES_URL_OPTION),
            'url_label' => 'Fee details',
            'detail' => $fees_detail,
            'has_invoice' => !empty($invoices) && !$only_not_released,
            // Nothing to pay until the release, so it counts as done (the
            // admin Ready flag doesn't change), but the player sees it in
            // orange as one to come back to.
            'waiting' => $only_not_released,
            'invoiced' => round($invoiced_total, 2),
            'paid' => round(max(0, $invoiced_total - $outstanding), 2),
            'outstanding' => round($outstanding, 2),
            'overdue' => round($overdue, 2),
            'other_season_debt' => $other_season_debt,
            'season_invoiced' => round($season_invoiced, 2),
            'season_outstanding' => round($season_outstanding, 2),
            'paid_through' => $season_schedule
                ? $season_schedule['next_due']
                : (TeamOversight_Fees::timeline_applies($season) ? null : TeamOversight_Payments::get_paid_through_date($season_invoiced, $season_outstanding, $season)),
            'season_progress_html' => $season_schedule ? $season_schedule['progress_html'] : null,
            // 1.76.0: 'deposit_due' | 'locked_in' | 'fee_not_set' | 'refund_due' | 'under_review', else null.
            'stage' => $stage ? $stage['stage'] : null,
            // 1.79.1: the offers sentence added to the end of detail ('' when none), so the Player Checklist of an
            // accepted offer can keep the deposit's dollar sentence out of the step for other money owed.
            'stage_words' => ($stage_words !== '' && !$says_stage) ? $stage_words : '',
        );

        $ready = true;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $ready = false;
            }
        }

        return array(
            'teams' => $teams,
            'staff_only' => $is_staff_only,
            'shirts_required' => $shirts_required,
            'steps' => $steps,
            'ready' => $ready,
        );
    }

    // ------------------------------------------------------------------
    // Player-facing shortcode
    // ------------------------------------------------------------------

    public function render_shortcode() {
        if (!is_user_logged_in()) {
            // The fee reminder's button lands here, often in a browser that
            // isn't logged in: say how to see it rather than show nothing.
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            return '<div class="rtp-panel rtp-logged-out" style="border:2px solid #e0e0e0;border-radius:8px;padding:20px;background:#f9f9f9;max-width:640px;margin:0 0 20px;">'
                . '<p style="margin:0 0 12px;"><strong>Please log in to see your Player Checklist and club fees.</strong></p>'
                . '<p style="margin:0;"><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p></div>';
        }

        $user = wp_get_current_user();

        // Selections for NEXT season happen during the current year (trials
        // run pre-season), so check the current season and the next one and
        // render a panel for each the player is on a team in — once the club
        // has finalised it (assignments only; coach verdicts never show here).
        // Tick submissions are handled on template_redirect (Post/Redirect/Get).
        $current_year = intval(date('Y'));
        $seasons = array((string) $current_year, (string) ($current_year + 1));

        // 1.76.0: the offers banner (an offer that was sent to them, the
        // deposit step for one they accepted) sits above the panels. It is
        // '' for everyone with no visible offer, so the page is exactly as
        // before for them.
        $banner = '';
        if (class_exists('TeamOversight_Offers_Player')) {
            try {
                $banner = TeamOversight_Offers_Player::render_checklist_banner($user);
            } catch (\Throwable $e) {
                $banner = '';
            }
        }

        $output = '';
        $panels = 0;
        foreach ($seasons as $season) {
            // 2027 on, an accepted (or locked-in, not yet placed) offer counts its team here: see season_panel().
            $panel = self::season_panel($user, $season);
            if ($panel !== '') {
                $output .= $panel;
                $panels++;
            }
        }

        // Not selected into anything? Fees still always show: someone
        // removed from a team may carry a debt they need to see and pay,
        // and paid-up members get a "nothing owing" confirmation. It is the
        // PANELS that decide this, not whether any output exists: a player
        // whose only output is the offers banner still sees their balance.
        if ($panels === 0) {
            $payments = new TeamOversight_Payments();
            $output = $payments->render_member_fees();
        }

        return $banner . $output;
    }

    /**
     * One season's panel on [ready_to_play] for $user (HTML, '' when they
     * have none that season). Public and static so a test can draw a fixture
     * season.
     *
     * 2027 on (club decision, 28 Oct 2026: the player checklist is there from
     * the moment they accept, so they can see what kit they need): an offer
     * the player has ACCEPTED, or one that is LOCKED IN and not placed yet,
     * counts its team as one of theirs through compute()'s $extra_teams (the
     * path the offer page's preview uses), so the panel is the player
     * checklist, kit steps and ticks included, combined with any team they
     * really hold and any team they coach. The heading never says they are on
     * the offered team: it says they accepted a place and must pay the
     * deposit to lock it in (or that they are locked in and the club is
     * placing them), in the wording registry's words. While a deposit is
     * still to pay, the fees step is the deposit: it points at the deposit box
     * in the offers banner above (an anchor link) and draws no pay box or
     * figure of its own (money owed elsewhere, e.g. an older season, still
     * shows with its own pay box, as it always has). A sent offer, a coach
     * verdict alone and every ended offer count for nothing; a season before
     * 2027, a player with no such offer and a locked-in player who is placed
     * get exactly the panel they always got. A pure reader.
     */
    public static function season_panel($user, $season) {
        if (!is_object($user) || empty($user->ID)) {
            return '';
        }
        $season = (string) $season;
        $offers = self::checklist_offers($user, $season);
        if (empty($offers)) {
            $checklist = self::compute($user, $season);
            return $checklist === null ? '' : self::render_panel($season, $checklist);
        }

        $accepted = array();
        $locked = array();
        foreach ($offers as $offer) {
            $code = (string) $offer->team;
            if ($offer->state === TeamOversight_Offers::ACCEPTED) {
                if (!isset($accepted[$code])) {
                    $accepted[$code] = $offer;
                }
            } elseif (!isset($locked[$code])) {
                $locked[$code] = $offer;
            }
        }
        $locked = array_diff_key($locked, $accepted);
        $offer_codes = array_merge(array_keys($accepted), array_keys($locked));

        $checklist = self::compute($user, $season, false, $offer_codes);
        if ($checklist === null) {
            return '';
        }

        $lines = array();
        foreach ($accepted as $code => $accepted_offer) {
            // 1.88.0 review: a covered offer's payment is the supporter fee, never a deposit.
            $lines[] = TeamOversight_Offer_Wording::html(TeamOversight_Offers::is_covered($accepted_offer) ? 'checklist_cover_accepted' : 'checklist_accepted', array('{team}' => TeamOversight_Offers::team_name($season, $code)));
        }
        foreach (array_keys($locked) as $code) {
            $lines[] = TeamOversight_Offer_Wording::html('checklist_locked', array('{team}' => TeamOversight_Offers::team_name($season, $code)));
        }
        if (!empty($accepted)) {
            $checklist['steps'] = self::with_deposit_step($checklist['steps'], array_values($accepted), $season);
            $checklist['ready'] = false;
        }

        return self::render_panel($season, $checklist, array(
            'offer_lines' => $lines,
            // The teams they really hold (active places), never the offered ones.
            'in_teams' => self::get_player_teams($user->ID, $user->user_email, $season),
            'coaching' => self::get_staff_teams($user->ID, $user->user_email, $season),
        ));
    }

    /**
     * The player's offers that count on the season's checklist (2027 on):
     * ACCEPTED, and LOCKED IN without a place yet (once placed, the active
     * team place carries it). Never a sent offer, never an ended one, never
     * for another account (matched by account id). array() for a season
     * before 2027 or when the offers code is not there. Never throws.
     */
    private static function checklist_offers($user, $season) {
        if (!class_exists('TeamOversight_Offers') || !class_exists('TeamOversight_Offer_Wording') || !class_exists('TeamOversight_Offers_Player')
            || !TeamOversight_Offers::applies($season) || intval($user->ID) <= 0) {
            return array();
        }
        try {
            $rows = TeamOversight_Offers::for_user(intval($user->ID), (string) $season, array(TeamOversight_Offers::ACCEPTED, TeamOversight_Offers::LOCKED_IN));
        } catch (\Throwable $e) {
            return array();
        }
        $out = array();
        foreach ((array) $rows as $offer) {
            if (!is_object($offer) || (string) $offer->season !== (string) $season || (string) $offer->team === '') {
                continue;
            }
            if ($offer->state === TeamOversight_Offers::LOCKED_IN && !empty($offer->placed_at)) {
                continue;
            }
            if ($offer->state === TeamOversight_Offers::ACCEPTED || $offer->state === TeamOversight_Offers::LOCKED_IN) {
                $out[] = $offer;
            }
        }
        return $out;
    }

    /**
     * The steps of a season with an accepted offer (1.79.1): the fees step
     * is replaced by the deposit step (key 'deposit', deposit_step()), and
     * when the player also owes other money (an older season, a fee already
     * charged) the plain fees step follows it as its own step, with its
     * table and pay box, but without the deposit's sentence. So the only
     * pay box under "Pay your deposit" is the deposit box it links to, and
     * the oldest-first fee box never sits under a deposit heading (spec:
     * the deposit step never draws a second pay box).
     */
    private static function with_deposit_step(array $steps, array $accepted, $season) {
        $fees = isset($steps['fees']) ? $steps['fees'] : array();
        $owing = !empty($fees['has_invoice']) && isset($fees['outstanding']) && floatval($fees['outstanding']) > 0;
        $out = array();
        foreach ($steps as $key => $step) {
            if ($key !== 'fees') {
                $out[$key] = $step;
                continue;
            }
            $out['deposit'] = self::deposit_step($fees, $accepted, $season);
            if ($owing) {
                $other = $fees;
                $words = isset($fees['stage_words']) ? (string) $fees['stage_words'] : '';
                if ($words !== '' && substr((string) $other['detail'], -strlen(' ' . $words)) === ' ' . $words) {
                    $other['detail'] = substr((string) $other['detail'], 0, -strlen(' ' . $words));
                }
                $other['stage_words'] = '';
                $out['fees'] = $other;
            }
        }
        return $out;
    }

    /**
     * The deposit step while an accepted offer's deposit is still to pay:
     * the deposit comes first, and it is paid in the deposit box the offers
     * banner draws above the panels (the step links to it by its anchor). No
     * pay box, no fee table and no amount of its own: the box is the one
     * calculation. Other money owing is a separate step after it
     * (with_deposit_step()).
     */
    private static function deposit_step($fees, array $accepted, $season) {
        $names = array();
        $target = null;
        $paid = true;
        foreach ($accepted as $offer) {
            $names[] = TeamOversight_Offers::team_name($season, $offer->team);
            try {
                $remaining = intval(TeamOversight_Offers::remaining_cents($offer));
            } catch (\Throwable $e) {
                $remaining = 1;
            }
            if ($remaining > 0) {
                $paid = false;
                if ($target === null) {
                    $target = $offer;
                }
            }
        }
        if ($target === null) {
            $target = $accepted[0];
        }
        $vars = array('{team}' => implode(' and ', $names), '{season}' => (string) $season);
        // 1.88.0: a covered offer's deposit is the whole supporter fee, so the step says so.
        $covered = TeamOversight_Offers::is_covered($target);
        if ($covered) {
            $lead = TeamOversight_Offers::wording($paid ? 'checklist_cover_paid' : 'checklist_cover_step', $vars);
        } else {
            $lead = TeamOversight_Offers::wording($paid ? 'checklist_deposit_paid' : 'checklist_deposit_step', $vars);
        }

        $step = $fees;
        $step['title'] = TeamOversight_Offers::wording($covered ? 'checklist_cover_title' : 'checklist_deposit_title');
        $step['done'] = false;
        $step['manual'] = false;
        // Paid but not locked in yet: waiting on the club, like a fee not released.
        $step['waiting'] = $paid;
        $step['url'] = '#' . TeamOversight_Offers_Player::deposit_anchor($target);
        $step['url_label'] = TeamOversight_Offers::wording($covered ? 'checklist_cover_link' : 'checklist_deposit_link');
        // The deposit sentence only: no fee table, no pay box, no dollar figure (1.79.1).
        $step['detail'] = $lead;
        $step['has_invoice'] = false;
        $step['stage_words'] = '';
        $step['stage'] = 'deposit_due';
        $step['deposit_offer_id'] = intval($target->id);
        return $step;
    }

    /** The admin page's words for a fees step that is waiting on the club (1.76.0: a deposit-stage holding invoice is not "not released"). */
    private static function waiting_label($fees_step) {
        $stage = isset($fees_step['stage']) ? $fees_step['stage'] : null;
        if ($stage === 'fee_not_set') {
            return 'Fee not set yet (deposit held)';
        }
        if ($stage === 'refund_due' || $stage === 'under_review') {
            return 'Deposit held by the club';
        }
        return 'Fee not released yet';
    }

    /**
     * What the offers code says about the season invoice among $invoices
     * (1.76.0): array(stage, words, holding (the invoice's fee is $0)) or
     * null (no offer, a plain fee invoice, an empty holding invoice, a season
     * before 2027, or the offers code is not there). Never throws.
     */
    private static function season_deposit_stage($invoices, $season) {
        if (!class_exists('TeamOversight_Offers') || !TeamOversight_Offers::applies($season)) {
            return null;
        }
        foreach ((array) $invoices as $invoice) {
            if ((string) $invoice->season !== (string) $season) {
                continue;
            }
            try {
                $stage = TeamOversight_Offers::deposit_stage($invoice);
            } catch (\Throwable $e) {
                return null;
            }
            if (!is_array($stage) || empty($stage['stage'])) {
                return null;
            }
            return array(
                'stage' => (string) $stage['stage'],
                'words' => isset($stage['words']) ? (string) $stage['words'] : '',
                'holding' => floatval($invoice->invoice_amount) <= 0,
            );
        }
        return null;
    }

    /**
     * The page carrying [ready_to_play] (found by its shortcode so a moved
     * page never breaks a link, as the offer and trial pages are), else
     * /player-checklist/. For links from the offer page.
     */
    public static function checklist_url() {
        global $wpdb;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $page_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('page', 'post') AND post_content LIKE '%[ready_to_play%'
            ORDER BY (post_type = 'page') DESC, ID
            LIMIT 1
        ");
        $cache = $page_id ? (string) get_permalink($page_id) : home_url('/player-checklist/');
        return $cache;
    }

    /**
     * The read-only preview of the Player Checklist for an offer (the offer
     * page): the same steps and wording a player on the team gets (VV
     * registration, shirt(s), shorts and socks), computed for the player with
     * the offered team counted as theirs, and a fees step written from the
     * offer. No ticks, forms, shop links or pay box. Writes nothing. $target:
     * the deposit to say (money), else the offer's stored one. Returns HTML
     * ('' when nothing can be computed).
     */
    public static function render_offer_preview($user, $offer, $target = null) {
        if (!$user || !$offer) {
            return '';
        }
        $season = (string) $offer->season;
        $checklist = self::compute($user, $season, false, array($offer->team));
        if ($checklist === null) {
            return '';
        }
        $checklist['steps']['fees'] = self::offer_fees_step($offer, $target);
        return self::render_panel($season, $checklist, array('read_only' => true, 'only_teams' => array($offer->team)));
    }

    /**
     * The fees step of the preview, from the offer: the fee as the player was
     * told it, the deposit, and that the rest follows their payment schedule
     * once fees are released. Plain text.
     */
    private static function offer_fees_step($offer, $target = null) {
        $fee = TeamOversight_Offers::fee_words($offer);
        $target = $target === null ? (($offer->deposit_target !== null) ? floatval($offer->deposit_target) : 0.0) : floatval($target);
        $upper = ($offer->quote_basis === 'upper' && !TeamOversight_Fees::fees_released($offer->season));
        if ($fee !== '' && $upper) {
            $detail = 'Your fee is ' . $fee . '. The club confirms the exact fee in January and it can only be lower than the figure you were quoted.';
        } elseif ($fee !== '') {
            $detail = 'Your fee is ' . $fee . '.';
        } else {
            $detail = 'Your season fee is confirmed by the club.';
        }
        if (TeamOversight_Offers::to_cents($target) > 0) {
            $detail .= ' You pay a deposit of ' . TeamOversight_Offers::money($target) . ' to lock in your place, and it comes off your fee. The rest is paid as your payment schedule says once fees are released.';
        } else {
            $detail .= ' No deposit is needed to lock in your place. The fee is paid as your payment schedule says once fees are released.';
        }
        return array(
            'title' => 'Stay up to date on your fees',
            'done' => false,
            'manual' => false,
            'url' => '',
            'url_label' => '',
            'detail' => $detail,
            'has_invoice' => false,
            'waiting' => false,
            'stage' => null,
        );
    }

    /**
     * Draw one season's panel. Public and static since 1.76.0 so the offer
     * page can draw the same markup read-only. $options: read_only (true
     * draws what the player will need to do and nothing they can do yet:
     * no ticks, forms, shop links, pay box or "you're all set"), only_teams
     * (list of bare codes to name in the "If you accept" line instead of
     * every team the checklist counted), and for a season where the player
     * has accepted an offer (season_panel()): offer_lines (HTML lines from
     * the wording registry, one per accepted or locked-in offer, drawn in
     * place of "You're in" for those teams), in_teams (the codes they really
     * hold, named in "You're in" when there are any) and coaching (the codes
     * they coach, named in "You're coaching"). With no options the markup is
     * exactly what a player on a team has always seen. 1.89.0: when the
     * committee shows Where your fees go for the panel's season
     * (TeamOversight_Fee_Breakdown::for_checklist()), its block ends the fees
     * step, or the deposit step when there is no fees step; once per panel,
     * read-only panels included.
     */
    public static function render_panel($season, $checklist, $options = array()) {
        $read_only = !empty($options['read_only']);
        $offer_lines = (!$read_only && !empty($options['offer_lines'])) ? array_values((array) $options['offer_lines']) : array();
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
        $shown_codes = ($read_only && !empty($options['only_teams'])) ? array_values((array) $options['only_teams']) : $checklist['teams'];
        $name_of = function ($code) use ($teams_config) {
            return isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code;
        };
        $team_names = array_map($name_of, $shown_codes);
        $in_names = $offer_lines && !empty($options['in_teams']) ? array_map($name_of, array_values(array_unique((array) $options['in_teams']))) : array();
        $coach_names = $offer_lines && !empty($options['coaching']) ? array_map($name_of, array_values(array_unique((array) $options['coaching']))) : array();

        ob_start();
        ?>
        <div class="rtp-panel">
            <?php if ($read_only): ?>
            <h3>What you'll need to do — <?php echo esc_html($season); ?></h3>
            <p class="rtp-teams">If you accept, you'll be in: <strong><?php echo esc_html(implode(', ', $team_names)); ?></strong></p>
            <?php elseif ($offer_lines): ?>
            <h3>Get Ready to Play — <?php echo esc_html($season); ?></h3>
            <?php if ($in_names): ?>
            <p class="rtp-teams">You're in: <strong><?php echo esc_html(implode(', ', $in_names)); ?></strong></p>
            <?php endif; ?>
            <?php foreach ($offer_lines as $offer_line): ?>
            <p class="rtp-teams rtp-offer-place" style="background:#e8f1fb;border:1px solid #b7d3ef;border-radius:4px;color:#134e85;padding:8px 12px;"><?php echo $offer_line; ?></p>
            <?php endforeach; ?>
            <?php if ($coach_names): ?>
            <p class="rtp-teams">You're coaching: <strong><?php echo esc_html(implode(', ', $coach_names)); ?></strong></p>
            <?php endif; ?>
            <?php else: ?>
            <h3>Get Ready to <?php echo !empty($checklist['staff_only']) ? 'Coach' : 'Play'; ?> — <?php echo esc_html($season); ?></h3>
            <p class="rtp-teams"><?php echo !empty($checklist['staff_only']) ? "You're coaching:" : "You're in:"; ?> <strong><?php echo esc_html(implode(', ', $team_names)); ?></strong></p>
            <?php endif; ?>

            <?php
            // A step that's waiting on the club (fees not released yet)
            // shows in orange, so the player knows to come back to it.
            $any_waiting = false;
            foreach ($checklist['steps'] as $step) {
                if (!empty($step['waiting'])) {
                    $any_waiting = true;
                }
            }
            ?>
            <?php if ($read_only): ?>
                <?php /* a preview: nothing to tick off, and never "you're all set" */ ?>
            <?php elseif ($checklist['ready'] && $any_waiting): ?>
                <p class="rtp-all-done rtp-nearly">All done for now — just your fee to come once it's released.</p>
            <?php elseif ($checklist['ready']): ?>
                <p class="rtp-all-done">✔ You're all set — see you on court!</p>
            <?php endif; ?>

            <?php
            // 1.89.0: Where your fees go (the committee's percentages for the panel's season), once per panel:
            // at the end of the fees step, else of the accepted-offer deposit step when there is no fees step.
            $breakdown_step = isset($checklist['steps']['fees']) ? 'fees' : (isset($checklist['steps']['deposit']) ? 'deposit' : '');
            $breakdown_html = ($breakdown_step !== '' && class_exists('TeamOversight_Fee_Breakdown')) ? TeamOversight_Fee_Breakdown::checklist_block($season) : '';
            ?>
            <?php $step_number = 0; ?>
            <?php foreach ($checklist['steps'] as $step_key => $step): $step_number++; $waiting = !empty($step['waiting']); ?>
                <div class="rtp-step <?php echo $waiting ? 'rtp-waiting' : ($step['done'] ? 'rtp-done' : 'rtp-todo'); ?>">
                    <div class="rtp-step-header">
                        <span class="rtp-step-status"><?php echo ($step['done'] && !$waiting) ? '✔' : $step_number; ?></span>
                        <span class="rtp-step-title"><?php echo esc_html($step['title']); ?></span>
                    </div>
                    <div class="rtp-step-body">
                        <p><?php echo esc_html($step['detail']); ?></p>

                        <?php if (!empty($step['items'])): ?>
                            <ul class="rtp-kit-list">
                                <?php foreach ($step['items'] as $item): ?>
                                    <li class="<?php echo $item['satisfied'] ? 'rtp-kit-have' : ''; ?>">
                                        <?php echo esc_html($item['label']); ?>
                                        <?php if ($item['purchased'] > 0): ?>
                                            <small>(<?php echo intval($item['purchased']); ?> purchased this season)</small>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($step_key === 'fees' && !empty($step['has_invoice'])): ?>
                            <table class="rtp-fees-table">
                                <tr><th><?php echo !empty($step['other_season_debt']) ? 'Total fees (all seasons)' : 'Season fee'; ?></th><td>$<?php echo number_format($step['invoiced'], 2); ?></td></tr>
                                <tr><th>Paid so far</th><td>$<?php echo number_format($step['paid'], 2); ?></td></tr>
                                <tr class="rtp-fees-owing"><th>Remaining</th><td>$<?php echo number_format($step['outstanding'], 2); ?></td></tr>
                                <tr class="<?php echo $step['overdue'] > 0 ? 'rtp-fees-overdue' : ''; ?>"><th>Overdue now</th><td>$<?php echo number_format($step['overdue'], 2); ?></td></tr>
                                <?php if ($step['overdue'] <= 0 && $step['outstanding'] > 0 && !empty($step['paid_through'])): ?>
                                    <tr class="rtp-fees-uptodate"><th>Next payment due</th><td><?php echo esc_html(date('j M Y', strtotime($step['paid_through']))); ?></td></tr>
                                <?php endif; ?>
                            </table>
                            <?php if ($step['season_progress_html'] !== null): ?>
                                <?php echo $step['season_progress_html']; ?>
                            <?php elseif ($step['season_invoiced'] > 0 && !TeamOversight_Fees::timeline_applies($season)): ?>
                                <?php echo TeamOversight_Payments::render_fee_progress($step['season_invoiced'], $step['season_outstanding'], $season); ?>
                            <?php endif; ?>

                            <?php if ($step['outstanding'] > 0): ?>
                                <?php $payment_product = TeamOversight_Payments::get_payment_product(); ?>
                                <?php if ($payment_product): ?>
                                    <?php echo TeamOversight_Payments::render_pay_box($step['outstanding'], $step['overdue'], 'rtp-pay-form'); ?>
                                <?php else: ?>
                                    <p class="rtp-pay-form rtp-pay-disabled">
                                        <label>Pay amount ($)
                                            <input type="number" value="<?php echo esc_attr(number_format($step['outstanding'], 2, '.', '')); ?>" disabled>
                                        </label>
                                        <button type="button" class="button" disabled>Pay Now</button>
                                        <small class="rtp-pay-note">Online payment is coming soon — you'll be able to pay any amount here.</small>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!$read_only): ?>
                        <p class="rtp-step-actions">
                            <?php if (!empty($step['url'])): ?>
                                <a class="button" href="<?php echo esc_url($step['url']); ?>" <?php echo $step_key === 'vv' ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($step['url_label']); ?><?php echo ($step_key === 'kit' && $step['done']) ? ' / order more' : ''; ?></a>
                            <?php endif; ?>

                            <?php if (!empty($step['manual']) && empty($step['auto_done'])): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="murvc_rtp_toggle" value="<?php echo esc_attr($step_key); ?>">
                                    <input type="hidden" name="murvc_rtp_season" value="<?php echo esc_attr($season); ?>">
                                    <input type="hidden" name="murvc_rtp_value" value="<?php echo $step['done'] ? '0' : '1'; ?>">
                                    <?php wp_nonce_field('murvc_rtp', 'murvc_rtp_nonce'); ?>
                                    <?php if ($step['done']): ?>
                                        <button type="submit" class="button rtp-untick">Undo — not done yet</button>
                                    <?php else: ?>
                                        <button type="submit" class="button button-primary"><?php echo $step_key === 'kit' ? "I already have shorts & socks" : "I've done this"; ?></button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>

                        <?php if ($breakdown_html !== '' && $step_key === $breakdown_step): ?>
                            <?php echo $breakdown_html; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <style>
        .rtp-panel {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #f9f9f9;
            max-width: 640px;
        }

        .rtp-teams {
            color: #555;
        }

        .rtp-all-done {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            font-weight: 600;
            padding: 10px 15px;
            border-radius: 4px;
        }

        .rtp-step {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-left: 4px solid #f0b429;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 12px;
        }

        .rtp-step.rtp-done {
            border-left-color: #46b450;
            opacity: 0.85;
        }

        .rtp-step-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rtp-step-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #f0b429;
            color: #fff;
            font-weight: 700;
            flex-shrink: 0;
        }

        .rtp-done .rtp-step-status {
            background: #46b450;
        }

        .rtp-step.rtp-waiting {
            border-left-color: #e8740c;
        }

        .rtp-waiting .rtp-step-status {
            background: #e8740c;
        }

        .rtp-all-done.rtp-nearly {
            background: #fff4e5;
            border-color: #f5c58b;
            color: #8a4b08;
        }

        .rtp-step-title {
            font-weight: 600;
            font-size: 15px;
        }

        .rtp-step-body {
            margin-left: 36px;
            font-size: 14px;
        }

        .rtp-step-body p {
            margin: 6px 0;
        }

        .rtp-kit-list {
            margin: 6px 0 6px 18px;
        }

        .rtp-kit-list .rtp-kit-have {
            color: #155724;
        }

        .rtp-kit-list .rtp-kit-have::after {
            content: " ✔";
        }

        .rtp-untick {
            font-size: 12px;
        }

        .rtp-fees-table {
            border-collapse: collapse;
            margin: 8px 0;
            min-width: 240px;
        }

        .rtp-fees-table th {
            text-align: left;
            font-weight: 600;
            color: #444;
            padding: 3px 25px 3px 0;
        }

        .rtp-fees-table td {
            text-align: right;
            padding: 3px 0;
        }

        .rtp-fees-owing th, .rtp-fees-owing td {
            border-top: 1px solid #ddd;
            font-weight: 700;
        }

        .rtp-fees-overdue th, .rtp-fees-overdue td {
            color: #a00;
            font-weight: 700;
        }

        .rtp-fees-uptodate th, .rtp-fees-uptodate td {
            color: #155724;
            font-weight: 600;
        }

        .fee-progress {
            position: relative;
            height: 10px;
            background: #e8e8e8;
            border-radius: 5px;
            margin: 10px 0 2px 0;
            max-width: 340px;
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

        .rtp-pay-form {
            margin: 10px 0 4px 0;
        }

        .rtp-pay-form input[type="number"] {
            width: 110px;
            padding: 6px;
            font-size: 16px;
        }

        .rtp-pay-note {
            display: block;
            color: #666;
            margin-top: 4px;
        }

        .rtp-pay-disabled {
            opacity: 0.65;
        }
        </style>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Admin: Player Readiness page (called from TeamOversight_Admin)
    // ------------------------------------------------------------------

    /**
     * The admin Player Readiness page for one season ($seasons: the season
     * selector's list). 1.92.0: one season at a time, chosen at the top.
     * From 2027 (offers seasons) a player is listed only once they hold a
     * place on a team or have ACCEPTED an offer (accepted or locked in; club
     * decision, 10 Nov 2026), never for a coach's verdict alone; before 2027
     * the verdicts still count, as they did. A Transfer column tracks a club
     * transfer's or ITC's paperwork (Not Submitted, Pending, Completed).
     */
    public function render_admin_page($season, $seasons = array()) {
        if (isset($_POST['action']) && $_POST['action'] === 'save_readiness_settings') {
            $this->save_settings();
        }

        if (isset($_POST['action']) && $_POST['action'] === 'save_shirt_credit') {
            $this->save_shirt_credit();
        }

        if (isset($_POST['action']) && $_POST['action'] === 'save_transfer_status') {
            $this->save_transfer_status();
        }

        if (isset($_POST['action']) && $_POST['action'] === 'save_vv_registration') {
            $this->handle_vv_registration();
        }

        global $wpdb;
        $season = (string) $season;
        $offers_season = class_exists('TeamOversight_Offers') && TeamOversight_Offers::applies($season);

        // Who is listed: an active place on a team this season, and (2027 on)
        // an accepted or locked-in offer, else (before 2027) a Selected or
        // Training Only verdict.
        $user_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT ta.user_id FROM {$wpdb->prefix}team_assignments ta
            WHERE ta.season = %s AND ta.is_active = 1 AND ta.role IN ('playing_member', 'training_only', 'team_manager') AND ta.user_id > 0
        ", $season));
        $offer_teams = array(); // user id => team code => offer (accepted, or locked in and not yet placed)
        if ($offers_season) {
            $page = TeamOversight_Offers::list_offers(array('season' => $season, 'states' => array(TeamOversight_Offers::ACCEPTED, TeamOversight_Offers::LOCKED_IN), 'limit' => 500));
            foreach ($page['rows'] as $offer) {
                $uid = intval($offer->user_id);
                if ($uid <= 0 || (string) $offer->team === '') {
                    continue;
                }
                $user_ids[] = $uid;
                if ($offer->state === TeamOversight_Offers::LOCKED_IN && !empty($offer->placed_at)) {
                    continue; // placed: the team place itself is counted
                }
                if (!isset($offer_teams[$uid][(string) $offer->team])) {
                    $offer_teams[$uid][(string) $offer->team] = $offer;
                }
            }
        } else {
            $user_ids = array_merge($user_ids, $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT a.user_id FROM {$wpdb->prefix}team_trial_selections s
                JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
                WHERE s.season = %s AND s.status IN ('selected', 'training_only') AND a.user_id > 0
            ", $season)));
        }
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        if (!empty($user_ids)) {
            cache_users($user_ids);
        }

        // Each player's newest application for the season (the transfer column), one query.
        $applications = array();
        if (!empty($user_ids)) {
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}trial_applications
                WHERE season = %s AND user_id IN (" . implode(',', $user_ids) . ")
                ORDER BY id DESC
            ", $season));
            foreach ((array) $rows as $row) {
                if (!isset($applications[intval($row->user_id)])) {
                    $applications[intval($row->user_id)] = $row;
                }
            }
        }
        $track_transfers = class_exists('TeamOversight_Transfer_Limits');

        $kit_products = self::get_kit_products();

        ?>
        <div class="wrap">
            <h1>Player Readiness</h1>

            <?php if (!empty($seasons)): ?>
                <div class="season-filter" style="margin: 10px 0;">
                    <label for="readiness-season-select">Season:</label>
                    <select id="readiness-season-select" onchange="location.href=this.value;">
                        <?php foreach ($seasons as $s): ?>
                            <option value="<?php echo esc_url(admin_url('admin.php?page=team-oversight-readiness&season=' . $s)); ?>" <?php selected($season, (string) $s); ?>><?php echo esc_html($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;" <?php echo (!get_option(self::VV_URL_OPTION) && empty($kit_products['shirt'])) ? 'open' : ''; ?>>
                <summary style="cursor: pointer; font-weight: 600;">Readiness settings</summary>
                <form method="post">
                    <table class="form-table-compact">
                        <tr>
                            <th><label>VV registration URL</label></th>
                            <td><input type="url" name="vv_reg_url" value="<?php echo esc_attr(get_option(self::VV_URL_OPTION)); ?>" style="width: 420px;" placeholder="https://vq.volleyballvictoria.com.au/..."></td>
                        </tr>
                        <tr>
                            <th><label>Kit shop URL</label></th>
                            <td><input type="url" name="kit_shop_url" value="<?php echo esc_attr(get_option(self::KIT_URL_OPTION)); ?>" style="width: 420px;" placeholder="https://members.renegades.com.au/product-category/merchandise/"></td>
                        </tr>
                        <tr>
                            <th><label>Fees page URL</label></th>
                            <td><input type="url" name="fees_page_url" value="<?php echo esc_attr(get_option(self::FEES_URL_OPTION)); ?>" style="width: 420px;" placeholder="Page containing [member_fees]"></td>
                        </tr>
                        <tr>
                            <th><label>Shirt product IDs</label></th>
                            <td><input type="text" name="kit_shirt_ids" value="<?php echo esc_attr(implode(', ', $kit_products['shirt'])); ?>" style="width: 300px;" placeholder="e.g. 123, 456"><span class="description"> Comma-separated WooCommerce product IDs that count as a playing shirt.</span></td>
                        </tr>
                        <tr>
                            <th><label>Shorts product IDs</label></th>
                            <td><input type="text" name="kit_shorts_ids" value="<?php echo esc_attr(implode(', ', $kit_products['shorts'])); ?>" style="width: 300px;"></td>
                        </tr>
                        <tr>
                            <th><label>Socks product IDs</label></th>
                            <td><input type="text" name="kit_socks_ids" value="<?php echo esc_attr(implode(', ', $kit_products['socks'])); ?>" style="width: 300px;"></td>
                        </tr>
                    </table>
                    <p>
                        <input type="submit" class="button button-primary" value="Save Readiness Settings">
                        <input type="hidden" name="action" value="save_readiness_settings">
                        <?php wp_nonce_field('save_readiness_settings', 'readiness_nonce'); ?>
                    </p>
                </form>
            </details>

            <p><strong><?php echo count($user_ids); ?></strong> players for <?php echo esc_html($season); ?>.
                <?php if ($offers_season): ?>
                    A player appears here once they have accepted their offer or hold a place on a team.
                <?php else: ?>
                    Everyone on a team, or Selected or Training Only by a coach.
                <?php endif; ?>
                Shirt requirements come from each team's configuration (Premier 2, YSL 0, default 1).</p>

            <?php if (!empty($user_ids)): ?>
                <table class="wp-list-table widefat fixed striped murvc-sortable" id="murvc-readiness-table">
                    <thead><tr>
                        <th>Player</th>
                        <th style="width: 12%;">Teams</th>
                        <?php if ($track_transfers): ?><th style="width: 11%;" title="Club transfers and ITC players only">Transfer</th><?php endif; ?>
                        <th style="width: 12%;">VV Registration</th>
                        <th>Shirt Payment</th>
                        <th style="width: 14%;">Shorts &amp; Socks</th>
                        <th style="width: 16%;">Fees</th>
                        <th style="width: 55px;">Ready</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($user_ids as $player_id): ?>
                            <?php
                            $player_season = $season;
                            $player = get_userdata($player_id);
                            if (!$player) {
                                continue;
                            }
                            $my_offers = isset($offer_teams[$player_id]) ? $offer_teams[$player_id] : array();
                            // 2027 on: the accepted (or locked-in, not yet placed) offers' teams count, as on the player's own
                            // checklist (season_panel()); never a bare verdict. Before 2027: the verdicts, as before.
                            $checklist = $offers_season ? self::compute($player, $player_season, false, array_keys($my_offers)) : self::compute($player, $player_season, true);
                            if ($checklist === null) {
                                continue;
                            }
                            $deposit_unpaid = false;
                            $offer_words = array();
                            foreach ($my_offers as $code => $offer) {
                                if ($offer->state === TeamOversight_Offers::ACCEPTED) {
                                    $deposit_unpaid = true;
                                    $offer_words[] = $code . ': accepted, deposit not paid yet';
                                } else {
                                    $offer_words[] = $code . ': locked in, not on the team list yet';
                                }
                            }
                            if ($deposit_unpaid) {
                                $checklist['ready'] = false;
                            }
                            $player_app = isset($applications[$player_id]) ? $applications[$player_id] : null;
                            $needs_transfer = $track_transfers && $player_app && TeamOversight_Transfer_Limits::needs_paperwork($player_app);
                            $tstatus = $needs_transfer ? TeamOversight_Transfer_Limits::transfer_status($player_app) : '';
                            // 1.92.1 (club decision, 10 Nov 2026): a club transfer or ITC isn't Ready until their transfer is Completed.
                            $transfer_blocks = $needs_transfer && $tstatus !== 'completed';
                            if ($transfer_blocks) {
                                $checklist['ready'] = false;
                            }
                            $kit_step = $checklist['steps']['kit'];
                            $kit_summary = array();
                            foreach ($kit_step['items'] as $item) {
                                $kit_summary[] = $item['label'] . ': ' . ($item['satisfied'] ? '✔' : ($item['purchased'] > 0 ? $item['purchased'] : '—'));
                            }
                            $shirt_step = isset($checklist['steps']['shirt']) ? $checklist['steps']['shirt'] : null;
                            ?>
                            <tr>
                                <td data-sort="<?php echo esc_attr(strtolower($player->display_name)); ?>"><a href="<?php echo esc_url(get_edit_user_link($player_id)); ?>"><?php echo esc_html($player->display_name); ?></a><br><small><?php echo esc_html($player->user_email); ?></small></td>
                                <td data-sort="<?php echo esc_attr(strtolower(implode(', ', $checklist['teams']))); ?>"><?php echo esc_html(implode(', ', $checklist['teams'])); ?><?php foreach ($offer_words as $w): ?><br><small style="color:#996800;"><?php echo esc_html($w); ?></small><?php endforeach; ?></td>
                                <?php if ($track_transfers): ?>
                                    <td style="font-size: 12px;" data-sort="<?php echo $needs_transfer ? intval(array_search($tstatus, array_keys(TeamOversight_Transfer_Limits::TRANSFER_STATUSES), true)) : '-1'; ?>">
                                        <?php if ($needs_transfer): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="save_transfer_status">
                                                <input type="hidden" name="transfer_application_id" value="<?php echo intval($player_app->id); ?>">
                                                <?php wp_nonce_field('save_transfer_status', 'transfer_status_nonce'); ?>
                                                <select name="transfer_status" onchange="this.form.submit()" aria-label="Transfer status for <?php echo esc_attr($player->display_name); ?>" style="font-size: 12px; max-width: 100%;<?php echo $tstatus === 'completed' ? ' color:#1a7a2e;' : ($tstatus === 'pending' ? ' color:#996800;' : ' color:#a00;'); ?>">
                                                    <?php foreach (TeamOversight_Transfer_Limits::TRANSFER_STATUSES as $key => $label): ?>
                                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($tstatus, $key); ?>><?php echo esc_html($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            <small><?php echo esc_html(TeamOversight_Trials::registration_type($player_app)); ?></small>
                                        <?php else: ?>
                                            <span style="color: #666;">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php $vv_conf = self::vv_confirmed($player_id, $player_season); ?>
                                <td style="font-size: 12px;" data-sort="<?php echo $vv_conf !== null ? '1' : '0'; ?>">
                                    <form method="post">
                                        <input type="hidden" name="action" value="save_vv_registration">
                                        <input type="hidden" name="vv_user_id" value="<?php echo intval($player_id); ?>">
                                        <input type="hidden" name="vv_season" value="<?php echo esc_attr($player_season); ?>">
                                        <?php wp_nonce_field('save_vv_registration', 'vv_nonce'); ?>
                                        <label style="<?php echo $vv_conf !== null ? 'color:#1a7a2e;' : 'color:#a00;'; ?>"><input type="checkbox" name="vv_confirmed" value="1" <?php checked($vv_conf !== null); ?> onchange="this.form.submit()"> Registered</label><br>
                                        <input type="text" name="vv_vvid" value="<?php echo esc_attr(self::get_vvid($player_id)); ?>" placeholder="VVID" maxlength="<?php echo intval(self::VVID_MAX); ?>" style="width: 90px; font-size: 12px;" aria-label="VVID for <?php echo esc_attr($player->display_name); ?>">
                                        <button type="submit" class="button button-small">Save</button>
                                    </form>
                                    <?php if ($vv_conf !== null): ?>
                                        <?php $vv_by = get_userdata(isset($vv_conf['by']) ? intval($vv_conf['by']) : 0); ?>
                                        <small>Ticked<?php echo $vv_by ? ' by ' . esc_html($vv_by->display_name) : ''; ?><?php echo !empty($vv_conf['at']) ? ', ' . esc_html(mysql2date('j M Y', (string) $vv_conf['at'])) : ''; ?></small>
                                    <?php elseif (!empty($checklist['steps']['vv']['self_ticked'])): ?>
                                        <small style="color:#996800;">The player ticked this themselves earlier. Check VV before confirming.</small>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px;" data-sort="<?php echo $shirt_step === null ? '1' : esc_attr(number_format(min(1, intval($shirt_step['shirt_required']) > 0 ? intval($shirt_step['shirt_paid']) / intval($shirt_step['shirt_required']) : 1), 2, '.', '')); ?>">
                                    <?php if ($shirt_step === null): ?>
                                        <span style="color: #666;">Not required (supplied)</span>
                                    <?php else: ?>
                                        <?php if ($shirt_step['done']): ?>
                                            <span style="color:#1a7a2e;">✔ Paid <?php echo intval($shirt_step['shirt_paid']); ?>/<?php echo intval($shirt_step['shirt_required']); ?></span>
                                        <?php else: ?>
                                            <span style="color:#a00;">Paid <?php echo intval($shirt_step['shirt_paid']); ?>/<?php echo intval($shirt_step['shirt_required']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($shirt_step['shirt_credit']['qty'] > 0): ?>
                                            <br><small title="<?php echo esc_attr($shirt_step['shirt_credit']['note']); ?>">incl. <?php echo intval($shirt_step['shirt_credit']['qty']); ?> credited<?php echo $shirt_step['shirt_credit']['note'] ? ' — ' . esc_html($shirt_step['shirt_credit']['note']) : ''; ?></small>
                                        <?php endif; ?>
                                        <details style="margin-top: 4px;">
                                            <summary style="cursor: pointer; font-size: 11px;">Record credit</summary>
                                            <form method="post" style="margin-top: 4px;">
                                                <input type="hidden" name="action" value="save_shirt_credit">
                                                <input type="hidden" name="credit_user_id" value="<?php echo intval($player_id); ?>">
                                                <?php wp_nonce_field('save_shirt_credit', 'shirt_credit_nonce'); ?>
                                                <input type="number" name="credit_qty" value="<?php echo intval($shirt_step['shirt_credit']['qty']); ?>" min="0" max="5" style="width: 50px;"> shirts
                                                <input type="text" name="credit_note" value="<?php echo esc_attr($shirt_step['shirt_credit']['note']); ?>" placeholder="e.g. paid 2019 under old@email.com" style="width: 95%; margin-top: 3px;">
                                                <button type="submit" class="button button-small">Save</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px;" data-sort="<?php echo $kit_step['done'] ? '1' : '0'; ?>">
                                    <?php echo $kit_step['done'] ? '<span style="color:#1a7a2e;">✔' . (empty($kit_step['auto_done']) ? ' (player ticked)' : ' (purchased)') . '</span><br>' : ''; ?>
                                    <?php echo esc_html(implode(' · ', $kit_summary)); ?>
                                </td>
                                <td data-sort="<?php echo !empty($checklist['steps']['fees']['waiting']) ? '0.5' : ($checklist['steps']['fees']['done'] ? '1' : '0'); ?>"><?php echo !empty($checklist['steps']['fees']['waiting']) ? '<span style="color:#996800;">' . esc_html(self::waiting_label($checklist['steps']['fees'])) . '</span>' : ($checklist['steps']['fees']['done'] ? '<span style="color:#1a7a2e;">✔</span>' : '<span style="color:#a00;">' . esc_html($checklist['steps']['fees']['detail']) . '</span>'); ?></td>
                                <td data-sort="<?php echo $checklist['ready'] ? '1' : '0'; ?>"><?php echo $checklist['ready'] ? '<strong style="color:#1a7a2e;">✔</strong>' : '—'; ?><?php echo $transfer_blocks ? '<br><small style="color:#996800;">transfer not completed</small>' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <style>
                    #murvc-readiness-table th .murvc-sort { background: none; border: 0; padding: 0; font: inherit; color: inherit; cursor: pointer; text-align: left; }
                    #murvc-readiness-table th .murvc-sort::after { content: " \2195"; color: #a7aaad; }
                    #murvc-readiness-table th[aria-sort="ascending"] .murvc-sort::after { content: " \2191"; color: inherit; }
                    #murvc-readiness-table th[aria-sort="descending"] .murvc-sort::after { content: " \2193"; color: inherit; }
                </style>
                <script>
                // 1.92.1: click a column heading to sort the players by it (again to reverse). A cell's data-sort
                // value is the key when it has one (numbers sort as numbers), else its text. Rows keep their forms.
                (function () {
                    var table = document.getElementById('murvc-readiness-table');
                    if (!table || !table.tHead || !table.tBodies.length) { return; }
                    var heads = table.tHead.rows[0].cells;
                    var isNum = function (v) { return /^-?\d+(\.\d+)?$/.test(v); };
                    var key = function (row, col) {
                        var cell = row.cells[col];
                        if (!cell) { return ''; }
                        var v = cell.getAttribute('data-sort');
                        return v !== null ? v : cell.textContent.trim().toLowerCase();
                    };
                    Array.prototype.forEach.call(heads, function (th, col) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'murvc-sort';
                        while (th.firstChild) { btn.appendChild(th.firstChild); }
                        th.appendChild(btn);
                        btn.addEventListener('click', function () {
                            var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
                            Array.prototype.forEach.call(heads, function (h) { h.removeAttribute('aria-sort'); });
                            th.setAttribute('aria-sort', dir);
                            var body = table.tBodies[0];
                            var rows = Array.prototype.slice.call(body.rows);
                            rows.sort(function (a, b) {
                                var x = key(a, col), y = key(b, col);
                                var cmp = (isNum(x) && isNum(y)) ? parseFloat(x) - parseFloat(y) : x.localeCompare(y);
                                return dir === 'ascending' ? cmp : -cmp;
                            });
                            rows.forEach(function (r) { body.appendChild(r); });
                        });
                    });
                })();
                </script>
            <?php else: ?>
                <p>No players selected or confirmed for this season yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** 1.92.1: the club's VV registration tick and the VVID, from the VV Registration column (administrators only). */
    private function handle_vv_registration() {
        if (!isset($_POST['vv_nonce']) || !wp_verify_nonce($_POST['vv_nonce'], 'save_vv_registration')) {
            echo '<div class="notice notice-error"><p>Security check failed. Reload the page and try again.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }
        $result = self::save_vv_registration(
            isset($_POST['vv_user_id']) ? intval($_POST['vv_user_id']) : 0,
            isset($_POST['vv_season']) ? sanitize_text_field(wp_unslash($_POST['vv_season'])) : '',
            !empty($_POST['vv_confirmed']),
            isset($_POST['vv_vvid']) ? sanitize_text_field(wp_unslash($_POST['vv_vvid'])) : '',
            get_current_user_id()
        );
        echo '<div class="notice notice-' . ($result['ok'] ? 'success' : 'error') . ' is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
    }

    /** 1.92.0: a club transfer's or ITC's paperwork status, from the Transfer column (administrators only; TeamOversight_Transfer_Limits decides and logs). */
    private function save_transfer_status() {
        if (!isset($_POST['transfer_status_nonce']) || !wp_verify_nonce($_POST['transfer_status_nonce'], 'save_transfer_status')) {
            echo '<div class="notice notice-error"><p>Security check failed. Reload the page and try again.</p></div>';
            return;
        }
        if (!current_user_can('manage_options') || !class_exists('TeamOversight_Transfer_Limits')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }
        $result = TeamOversight_Transfer_Limits::set_transfer_status(
            isset($_POST['transfer_application_id']) ? intval($_POST['transfer_application_id']) : 0,
            isset($_POST['transfer_status']) ? sanitize_key(wp_unslash($_POST['transfer_status'])) : '',
            get_current_user_id()
        );
        echo '<div class="notice notice-' . ($result['ok'] ? 'success' : 'error') . ' is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
    }

    /**
     * Record shirt payments made outside this account (old email, cash,
     * different account) as a credit toward the shirt requirement.
     */
    private function save_shirt_credit() {
        if (!isset($_POST['shirt_credit_nonce']) || !wp_verify_nonce($_POST['shirt_credit_nonce'], 'save_shirt_credit')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        $user_id = intval($_POST['credit_user_id']);
        $user = get_userdata($user_id);
        if (!$user) {
            echo '<div class="notice notice-error"><p>User not found.</p></div>';
            return;
        }

        $qty = max(0, min(5, intval($_POST['credit_qty'])));
        $note = sanitize_text_field(wp_unslash($_POST['credit_note']));

        if ($qty > 0 || $note !== '') {
            update_user_meta($user_id, 'murvc_shirt_credit', array('qty' => $qty, 'note' => $note));
        } else {
            delete_user_meta($user_id, 'murvc_shirt_credit');
        }

        echo '<div class="notice notice-success"><p>Shirt credit for ' . esc_html($user->display_name) . ' set to ' . $qty . '.</p></div>';
    }

    private function save_settings() {
        if (!isset($_POST['readiness_nonce']) || !wp_verify_nonce($_POST['readiness_nonce'], 'save_readiness_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        update_option(self::VV_URL_OPTION, esc_url_raw(trim($_POST['vv_reg_url'])));
        update_option(self::KIT_URL_OPTION, esc_url_raw(trim($_POST['kit_shop_url'])));
        update_option(self::FEES_URL_OPTION, esc_url_raw(trim($_POST['fees_page_url'])));

        $parse_ids = function ($raw) {
            return array_values(array_filter(array_map('intval', explode(',', $raw))));
        };
        update_option(self::KIT_PRODUCTS_OPTION, array(
            'shirt' => $parse_ids($_POST['kit_shirt_ids']),
            'shorts' => $parse_ids($_POST['kit_shorts_ids']),
            'socks' => $parse_ids($_POST['kit_socks_ids']),
        ));

        echo '<div class="notice notice-success"><p>Readiness settings saved.</p></div>';
    }
}
