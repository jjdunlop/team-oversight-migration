<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ready to Play checklist.
 *
 * [ready_to_play] renders, for players selected into a team (confirmed
 * playing/training assignment or a coach Selected/Training-Only verdict),
 * the steps to complete before playing, in priority order:
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
 * selected player in the season.
 */
class TeamOversight_Readiness {

    const VV_URL_OPTION = 'team_oversight_vv_reg_url';
    const KIT_URL_OPTION = 'team_oversight_kit_shop_url';
    const FEES_URL_OPTION = 'team_oversight_fees_page_url';
    const KIT_PRODUCTS_OPTION = 'team_oversight_kit_products';

    public function __construct() {
        add_shortcode('ready_to_play', array($this, 'render_shortcode'));
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
     * assignments plus coach Selected/Training-Only verdicts.
     */
    public static function get_player_teams($user_id, $email, $season) {
        global $wpdb;

        $assigned = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT team FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND is_active = 1
                AND role IN ('playing_member', 'training_only')
                AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
        ", $season, $user_id, $email));

        $selected = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT s.team
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.season = %s AND s.status IN ('selected', 'training_only')
                AND a.application_status IN ('pending', 'accepted')
                AND a.user_id = %d
        ", $season, $user_id));

        return array_values(array_unique(array_merge($assigned, $selected)));
    }

    /**
     * Kit quantities purchased in paid orders since 1 Jan of the season year,
     * per category. Older purchases don't count — that's what the manual
     * "I already have these" tick is for.
     */
    public static function get_kit_purchases($user_id, $season) {
        global $wpdb;

        $products = self::get_kit_products();
        $all_ids = array_merge($products['shirt'], $products['shorts'], $products['socks']);
        $purchased = array('shirt' => 0, 'shorts' => 0, 'socks' => 0);

        if (empty($all_ids)) {
            return $purchased;
        }

        $id_list = implode(',', array_map('intval', $all_ids));
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT opl.product_id, SUM(opl.product_qty) AS qty
            FROM {$wpdb->prefix}wc_order_product_lookup opl
            JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = opl.customer_id
            JOIN {$wpdb->posts} p ON p.ID = opl.order_id AND p.post_status IN ('wc-processing', 'wc-completed')
            WHERE cl.user_id = %d
                AND opl.date_created >= %s
                AND opl.product_id IN ({$id_list})
            GROUP BY opl.product_id
        ", $user_id, intval($season) . '-01-01'));

        foreach ($rows as $row) {
            foreach ($products as $category => $ids) {
                if (in_array(intval($row->product_id), $ids, true)) {
                    $purchased[$category] += intval($row->qty);
                }
            }
        }

        return $purchased;
    }

    /**
     * The full checklist for a player, or null if they aren't selected into
     * any team this season.
     */
    public static function compute($user, $season) {
        $teams = self::get_player_teams($user->ID, $user->user_email, $season);
        if (empty($teams)) {
            return null;
        }

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();

        // Highest shirt requirement across their teams.
        $shirts_required = 0;
        foreach ($teams as $team) {
            $shirts_required = max($shirts_required, isset($teams_config[$team]['shirts']) ? intval($teams_config[$team]['shirts']) : 1);
        }

        $manual = get_user_meta($user->ID, 'murvc_rtp_' . $season, true);
        $manual = is_array($manual) ? $manual : array();

        // 1. VV membership.
        $steps = array();
        $steps['vv'] = array(
            'title' => 'Register your Volleyball Victoria membership',
            'done' => in_array('vv', $manual, true),
            'manual' => true,
            'url' => get_option(self::VV_URL_OPTION),
            'url_label' => 'Register with VV',
            'detail' => 'Every player needs a current VV membership before taking the court. Register on the VV website, then tick this off.',
        );

        // 2. Playing kit.
        $required = array('shorts' => 1, 'socks' => 1);
        if ($shirts_required > 0) {
            $required = array('shirt' => $shirts_required) + $required;
        }
        $purchased = self::get_kit_purchases($user->ID, $season);
        $kit_products = self::get_kit_products();

        $labels = array('shirt' => 'Playing shirt', 'shorts' => 'Playing shorts', 'socks' => 'Club socks');
        $items = array();
        $auto_done = true;
        foreach ($required as $category => $qty) {
            $have = $purchased[$category];
            $satisfied = !empty($kit_products[$category]) && $have >= $qty;
            if (!$satisfied) {
                $auto_done = false;
            }
            $items[] = array(
                'label' => $labels[$category] . ($qty > 1 ? ' × ' . $qty : ''),
                'purchased' => $have,
                'satisfied' => $satisfied,
            );
        }

        $steps['kit'] = array(
            'title' => 'Get your playing kit',
            'done' => $auto_done || in_array('kit', $manual, true),
            'auto_done' => $auto_done,
            'manual' => true,
            'url' => get_option(self::KIT_URL_OPTION),
            'url_label' => 'Shop playing kit',
            'items' => $items,
            'detail' => $shirts_required === 0
                ? 'Your team supplies playing shirts — you just need shorts and socks.'
                : 'Purchases from the club shop this season tick off automatically. Already have your kit from a previous season? Tick the box.',
        );

        // 3. Club fees.
        global $wpdb;
        $invoices = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}team_invoices
            WHERE season = %s AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
        ", $season, $user->ID, $user->user_email));

        $outstanding = 0;
        $overdue = 0;
        foreach ($invoices as $invoice) {
            $outstanding += floatval($invoice->outstanding_amount);
            $overdue += TeamOversight_Payments::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $season);
        }

        if (empty($invoices)) {
            $fees_done = true;
            $fees_detail = 'Your season fee will appear once the club confirms your team — nothing to pay yet.';
        } elseif ($outstanding <= 0) {
            $fees_done = true;
            $fees_detail = 'Paid in full — thank you!';
        } elseif ($overdue <= 0) {
            $fees_done = true;
            $fees_detail = 'On track: $' . number_format($outstanding, 2) . ' remaining, falling due progressively across the season.';
        } else {
            $fees_done = false;
            $fees_detail = '$' . number_format($overdue, 2) . ' is overdue (of $' . number_format($outstanding, 2) . ' remaining). Please make a payment to get back on schedule.';
        }

        $steps['fees'] = array(
            'title' => 'Check and pay your club fees',
            'done' => $fees_done,
            'manual' => false,
            'url' => get_option(self::FEES_URL_OPTION),
            'url_label' => 'View and pay fees',
            'detail' => $fees_detail,
        );

        $ready = true;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $ready = false;
            }
        }

        return array(
            'teams' => $teams,
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
            return '';
        }

        $user = wp_get_current_user();
        $season = date('Y');

        // Manual tick/untick.
        if (isset($_POST['murvc_rtp_toggle'], $_POST['murvc_rtp_nonce'])
            && wp_verify_nonce($_POST['murvc_rtp_nonce'], 'murvc_rtp')) {
            $step = sanitize_text_field($_POST['murvc_rtp_toggle']);
            if (in_array($step, array('vv', 'kit'), true)) {
                $manual = get_user_meta($user->ID, 'murvc_rtp_' . $season, true);
                $manual = is_array($manual) ? $manual : array();
                if (in_array($step, $manual, true)) {
                    $manual = array_values(array_diff($manual, array($step)));
                } else {
                    $manual[] = $step;
                }
                update_user_meta($user->ID, 'murvc_rtp_' . $season, $manual);
            }
        }

        $checklist = self::compute($user, $season);
        if ($checklist === null) {
            return '';
        }

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();
        $team_names = array_map(function ($code) use ($teams_config) {
            return isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code;
        }, $checklist['teams']);

        ob_start();
        ?>
        <div class="rtp-panel">
            <h3>Get Ready to Play — <?php echo esc_html($season); ?></h3>
            <p class="rtp-teams">You're in: <strong><?php echo esc_html(implode(', ', $team_names)); ?></strong></p>

            <?php if ($checklist['ready']): ?>
                <p class="rtp-all-done">✔ You're all set — see you on court!</p>
            <?php endif; ?>

            <?php $step_number = 0; ?>
            <?php foreach ($checklist['steps'] as $step_key => $step): $step_number++; ?>
                <div class="rtp-step <?php echo $step['done'] ? 'rtp-done' : 'rtp-todo'; ?>">
                    <div class="rtp-step-header">
                        <span class="rtp-step-status"><?php echo $step['done'] ? '✔' : $step_number; ?></span>
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

                        <p class="rtp-step-actions">
                            <?php if (!empty($step['url'])): ?>
                                <a class="button" href="<?php echo esc_url($step['url']); ?>" <?php echo $step_key === 'vv' ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($step['url_label']); ?><?php echo ($step_key === 'kit' && $step['done']) ? ' / order more' : ''; ?></a>
                            <?php endif; ?>

                            <?php if (!empty($step['manual']) && empty($step['auto_done'])): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="murvc_rtp_toggle" value="<?php echo esc_attr($step_key); ?>">
                                    <?php wp_nonce_field('murvc_rtp', 'murvc_rtp_nonce'); ?>
                                    <?php if ($step['done']): ?>
                                        <button type="submit" class="button rtp-untick">Undo — not done yet</button>
                                    <?php else: ?>
                                        <button type="submit" class="button button-primary"><?php echo $step_key === 'kit' ? "I already have my kit" : "I've done this"; ?></button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </p>
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
        </style>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Admin: Player Readiness page (called from TeamOversight_Admin)
    // ------------------------------------------------------------------

    public function render_admin_page($season) {
        if (isset($_POST['action']) && $_POST['action'] === 'save_readiness_settings') {
            $this->save_settings();
        }

        global $wpdb;

        // Everyone selected/confirmed as a player this season.
        $player_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT user_id FROM (
                SELECT ta.user_id FROM {$wpdb->prefix}team_assignments ta
                WHERE ta.season = %s AND ta.is_active = 1 AND ta.role IN ('playing_member', 'training_only') AND ta.user_id > 0
                UNION
                SELECT a.user_id FROM {$wpdb->prefix}team_trial_selections s
                JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
                WHERE s.season = %s AND s.status IN ('selected', 'training_only') AND a.user_id > 0
            ) players
        ", $season, $season));

        $kit_products = self::get_kit_products();

        ?>
        <div class="wrap">
            <h1>Player Readiness</h1>

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

            <p><strong><?php echo count($player_ids); ?></strong> players selected/confirmed for <?php echo esc_html($season); ?>. Shirt requirements come from each team's configuration (Premier 2, YSL 0, default 1).</p>

            <?php if (!empty($player_ids)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th>Player</th>
                        <th>Teams</th>
                        <th>VV Registration</th>
                        <th>Kit</th>
                        <th>Fees</th>
                        <th style="width: 70px;">Ready</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($player_ids as $player_id): ?>
                            <?php
                            $player = get_userdata($player_id);
                            if (!$player) {
                                continue;
                            }
                            $checklist = self::compute($player, $season);
                            if ($checklist === null) {
                                continue;
                            }
                            $kit_step = $checklist['steps']['kit'];
                            $kit_summary = array();
                            foreach ($kit_step['items'] as $item) {
                                $kit_summary[] = $item['label'] . ': ' . ($item['satisfied'] ? '✔' : ($item['purchased'] > 0 ? $item['purchased'] : '—'));
                            }
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url(get_edit_user_link($player_id)); ?>"><?php echo esc_html($player->display_name); ?></a><br><small><?php echo esc_html($player->user_email); ?></small></td>
                                <td><?php echo esc_html(implode(', ', $checklist['teams'])); ?></td>
                                <td><?php echo $checklist['steps']['vv']['done'] ? '<span style="color:#1a7a2e;">✔ Confirmed by player</span>' : '<span style="color:#996800;">Not confirmed</span>'; ?></td>
                                <td style="font-size: 12px;">
                                    <?php echo $kit_step['done'] ? '<span style="color:#1a7a2e;">✔' . (empty($kit_step['auto_done']) ? ' (player ticked)' : ' (purchased)') . '</span><br>' : ''; ?>
                                    <?php echo esc_html(implode(' · ', $kit_summary)); ?>
                                </td>
                                <td><?php echo $checklist['steps']['fees']['done'] ? '<span style="color:#1a7a2e;">✔</span>' : '<span style="color:#a00;">' . esc_html($checklist['steps']['fees']['detail']) . '</span>'; ?></td>
                                <td><?php echo $checklist['ready'] ? '<strong style="color:#1a7a2e;">✔</strong>' : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No players selected or confirmed for this season yet.</p>
            <?php endif; ?>
        </div>
        <?php
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
