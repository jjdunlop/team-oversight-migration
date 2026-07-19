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

    public function __construct() {
        add_shortcode('member_fees', array($this, 'render_member_fees'));

        // Pay-any-amount flow: process the form before output, override the
        // cart line price, carry meta onto the order, apply on payment.
        add_action('template_redirect', array($this, 'maybe_start_payment'));
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_cart_payment_amount'), 20);
        add_filter('woocommerce_get_item_data', array($this, 'display_payment_in_cart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'attach_payment_to_order_item'), 10, 3);
        add_action('woocommerce_order_status_processing', array($this, 'handle_payment_order'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_payment_order'));
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
     * Overdue amount for an invoice under the linear schedule.
     */
    public static function get_overdue($invoice_amount, $outstanding_amount, $season) {
        $factor = self::get_expected_factor($season);
        if ($factor === null) {
            return 0.0;
        }
        $paid = floatval($invoice_amount) - floatval($outstanding_amount);
        $expected = floatval($invoice_amount) * $factor;
        return round(max(0, min($expected - $paid, floatval($outstanding_amount))), 2);
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
            return '<div class="member-fees-panel"><p>You have no club fees on record. Once you are confirmed into a team your season fee will appear here.</p></div>';
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
                        <?php if ($overdue > 0): ?>
                            <tr class="member-fees-overdue"><th>Overdue now</th><td>$<?php echo number_format($overdue, 2); ?></td></tr>
                        <?php endif; ?>
                    </table>
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
