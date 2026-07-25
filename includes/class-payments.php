<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member fee payments.
 *
 * Fees are a balance against a season timeline, not an invoice event:
 *  - the season has manually-entered start/end dates (Configuration page)
 *  - a player's fee accrues linearly across the season, so "overdue" =
 *    what the schedule says should be paid by today minus what has been
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
        add_action('woocommerce_order_status_processing', array($this, 'handle_payment_order'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_payment_order'));

        // Overdue reminder emails (daily cron; only sends when enabled).
        add_action('init', array($this, 'maybe_schedule_reminder_cron'));
        add_action(self::REMINDER_CRON, array($this, 'run_reminder_cron'));
    }

    public function maybe_schedule_reminder_cron() {
        if (!wp_next_scheduled(self::REMINDER_CRON)) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::REMINDER_CRON);
        }
    }

    public function run_reminder_cron() {
        $this->send_overdue_reminders(false, false);
    }

    // ------------------------------------------------------------------
    // Overdue reminder emails
    // ------------------------------------------------------------------

    /**
     * Everyone with money overdue (all seasons; past-season debt counts in
     * full), one entry per person, resolved to their WP account. Rows that
     * can't be matched to an account are returned with user_id 0.
     */
    public static function get_overdue_people() {
        global $wpdb;

        $invoices = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}team_invoices WHERE outstanding_amount > 0
        ");

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
                );
            }
            $people[$key]['outstanding'] += floatval($invoice->outstanding_amount);
            $people[$key]['overdue'] += self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
        }

        return array_values(array_filter($people, function ($p) {
            return $p['overdue'] >= 1;
        }));
    }

    /**
     * Email everyone whose fees are overdue, at most once per configured
     * interval per person. $force ignores the master switch (admin "send
     * now"); $dry_run reports who WOULD be emailed without sending.
     * Returns a report array.
     */
    public function send_overdue_reminders($force = false, $dry_run = false) {
        $report = array('enabled' => (bool) get_option(self::REMINDERS_ENABLED_OPTION), 'sent' => 0, 'skipped_recent' => 0, 'skipped_no_account' => 0, 'recipients' => array());

        if (!$force && !$report['enabled']) {
            return $report;
        }

        $days = max(1, intval(get_option(self::REMINDER_DAYS_OPTION, 7)));
        $checklist_url = get_option('team_oversight_fees_page_url');
        if (!$checklist_url) {
            $checklist_url = home_url('/player-checklist/');
        }

        foreach (self::get_overdue_people() as $person) {
            if (!$person['user_id']) {
                $report['skipped_no_account']++;
                continue;
            }

            $last = intval(get_user_meta($person['user_id'], self::REMINDER_META, true));
            if ($last && (time() - $last) < $days * DAY_IN_SECONDS) {
                $report['skipped_recent']++;
                continue;
            }

            $report['recipients'][] = $person['name'] . ' <' . $person['email'] . '> $' . number_format($person['overdue'], 2);

            if ($dry_run) {
                $report['sent']++;
                continue;
            }

            $subject = 'MURVC club fees overdue — $' . number_format($person['overdue'], 2);
            $message = 'Hi ' . $person['name'] . ",\n\n"
                . "Our records show your MURVC club fees have fallen behind the payment schedule.\n\n"
                . '  Overdue now:     $' . number_format($person['overdue'], 2) . "\n"
                . '  Total remaining: $' . number_format($person['outstanding'], 2) . "\n\n"
                . "You can pay any amount online — every payment comes straight off your balance:\n"
                . $checklist_url . "\n\n"
                . "If you think this is a mistake, or you'd like to arrange a payment plan, just reply to this email.\n\n"
                . "Melbourne University Renegades Volleyball Club";

            if (wp_mail($person['email'], $subject, $message)) {
                update_user_meta($person['user_id'], self::REMINDER_META, time());
                $report['sent']++;
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
     * newest season first.
     */
    public static function get_user_invoices($user) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_invoices
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
            $overdue += self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
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
            return '<div class="member-fees-panel"><p>If you had any outstanding VVL fees, they would appear here.</p></div>';
        }

        $payment_product = self::get_payment_product();
        $total_outstanding = 0;

        ob_start();
        ?>
        <div class="member-fees-panel">
            <h3>Your Club Fees</h3>

            <?php foreach ($invoices as $invoice): ?>
                <?php
                $paid = floatval($invoice->invoice_amount) - floatval($invoice->outstanding_amount);
                $overdue = self::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
                $total_outstanding += floatval($invoice->outstanding_amount);
                $dates = TeamOversight_Fees::get_season_dates($invoice->season);
                ?>
                <div class="member-fees-season">
                    <h4><?php echo esc_html($invoice->season); ?> Season</h4>
                    <?php if ($dates): ?>
                        <p class="member-fees-schedule">Season runs <?php echo esc_html(date('j M Y', strtotime($dates['start']))); ?> &ndash; <?php echo esc_html(date('j M Y', strtotime($dates['end']))); ?>. Fees fall due progressively across the season — pay any amount, as often as you like, as long as you keep ahead of the schedule.</p>
                    <?php endif; ?>
                    <table class="member-fees-table">
                        <tr><th>Season fee</th><td>$<?php echo number_format($invoice->invoice_amount, 2); ?></td></tr>
                        <tr><th>Paid so far</th><td>$<?php echo number_format(max(0, $paid), 2); ?></td></tr>
                        <tr class="member-fees-owing"><th>Remaining</th><td>$<?php echo number_format($invoice->outstanding_amount, 2); ?></td></tr>
                        <tr class="<?php echo $overdue > 0 ? 'member-fees-overdue' : ''; ?>"><th>Overdue now</th><td>$<?php echo number_format($overdue, 2); ?></td></tr>
                        <?php $paid_through = self::get_paid_through_date($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season); ?>
                        <?php if ($overdue <= 0 && floatval($invoice->outstanding_amount) > 0 && $paid_through): ?>
                            <tr class="member-fees-uptodate"><th>Next payment due</th><td><?php echo esc_html(date('j M Y', strtotime($paid_through))); ?></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php echo self::render_fee_progress($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season); ?>
                </div>
            <?php endforeach; ?>

            <?php if ($total_outstanding > 0 && $payment_product): ?>
                <form method="post" class="member-fees-pay-form">
                    <h4>Make a Payment</h4>
                    <p>
                        <label for="murvc_pay_amount">Amount ($)</label>
                        <input type="number" name="murvc_pay_amount" id="murvc_pay_amount" min="1" max="<?php echo esc_attr(number_format($total_outstanding, 2, '.', '')); ?>" step="0.01" value="<?php echo esc_attr(number_format($total_outstanding, 2, '.', '')); ?>" required>
                    </p>
                    <input type="hidden" name="murvc_pay_action" value="pay_fees">
                    <?php wp_nonce_field('murvc_pay_fees', 'murvc_pay_nonce'); ?>
                    <button type="submit" class="button button-primary">Pay Now</button>
                </form>
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

        $amount = round(floatval($_POST['murvc_pay_amount']), 2);
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

    /**
     * Paid order containing a fee payment: reduce the member's outstanding
     * balances (oldest season first) and record it in the ledger.
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

            // Dedupe: each order item is applied once.
            $already = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}team_invoice_payments WHERE order_item_id = %d
            ", $item_id));
            if ($already) {
                continue;
            }

            $amount = round(floatval($item->get_total()) + floatval($item->get_total_tax()), 2);
            if ($amount <= 0) {
                continue;
            }

            $payer = get_userdata($payer_id);
            if (!$payer) {
                continue;
            }

            $remaining = $amount;
            $invoices = array_reverse(self::get_user_invoices($payer)); // oldest season first
            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }
                $outstanding = floatval($invoice->outstanding_amount);
                if ($outstanding <= 0) {
                    continue;
                }

                $applied = min($remaining, $outstanding);
                $wpdb->update(
                    $wpdb->prefix . 'team_invoices',
                    array('outstanding_amount' => round($outstanding - $applied, 2)),
                    array('id' => $invoice->id),
                    array('%f'),
                    array('%d')
                );

                $wpdb->insert(
                    $wpdb->prefix . 'team_invoice_payments',
                    array(
                        'invoice_id' => $invoice->id,
                        'user_id' => $payer_id,
                        'order_id' => $order_id,
                        'order_item_id' => $item_id,
                        'amount' => $applied,
                        'source' => 'online',
                    ),
                    array('%d', '%d', '%d', '%d', '%f', '%s')
                );

                $remaining = round($remaining - $applied, 2);
            }
        }
    }
}
