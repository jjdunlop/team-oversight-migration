<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stats page (Club Membership menu) — tabs of club statistics.
 *
 * Population for every stat: CURRENT MEMBERS, i.e. people with an unexpired
 * grant on the membership ledger. (Run seeding / the order re-scan first or
 * everything undercounts.)
 *
 * Tabs:
 *  - Data Quality: profile completeness (gender, DOB, mobile, MUS category,
 *    annual confirmation) with a once-daily snapshot recorded to a
 *    non-autoloaded option so trends accrue from the day this ships.
 *  - Locations: postcode distribution (UM postal_code, falling back to the
 *    WooCommerce billing postcode) plus a saved watchlist of postcodes to
 *    count members inside a target area.
 */
class TeamOversight_Stats_Page {

    const HISTORY_OPTION = 'team_oversight_data_quality_history';
    const HISTORY_MAX_DAYS = 1100; // ~3 years of daily snapshots
    const WATCHLIST_OPTION = 'team_oversight_postcode_watchlist';

    public function __construct() {
        add_action(TeamOversight_Memberships::CRON_HOOK, array($this, 'record_snapshot'));
    }

    public static function get_metrics() {
        return array(
            'gender' => 'Gender set',
            'dob' => 'Date of birth set',
            'mobile' => 'Mobile number set',
            'mus' => 'MUS category set',
            'confirmed' => 'Profile confirmed this year',
        );
    }

    // ------------------------------------------------------------------
    // Data quality snapshots
    // ------------------------------------------------------------------

    /**
     * Profile completeness among current members (unexpired ledger grant).
     * Returns array('members' => N, 'metrics' => array(key => n)).
     */
    public function compute_snapshot() {
        global $wpdb;

        $year = wp_date('Y');
        $today = current_time('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT u.ID,
                MAX(CASE WHEN um.meta_key IN ('gender', 'gender_dropdown') AND um.meta_value <> '' THEN 1 ELSE 0 END) AS has_gender,
                MAX(CASE WHEN um.meta_key = 'birth_date' AND um.meta_value <> '' THEN 1 ELSE 0 END) AS has_dob,
                MAX(CASE WHEN um.meta_key = 'mobile_number' AND um.meta_value <> '' THEN 1 ELSE 0 END) AS has_mobile,
                MAX(CASE WHEN um.meta_key = 'MUSEligibilityCategory' AND um.meta_value <> '' THEN 1 ELSE 0 END) AS has_mus,
                MAX(CASE WHEN um.meta_key = 'profile_confirmed_year' AND um.meta_value = %s THEN 1 ELSE 0 END) AS confirmed
            FROM {$wpdb->users} u
            JOIN (SELECT DISTINCT user_id FROM {$wpdb->prefix}team_memberships
                  WHERE start_date <= %s AND end_date >= %s) m ON m.user_id = u.ID
            LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
                AND um.meta_key IN ('gender', 'gender_dropdown', 'birth_date', 'mobile_number', 'MUSEligibilityCategory', 'profile_confirmed_year')
            GROUP BY u.ID
        ", $year, $today, $today));

        $snapshot = array('members' => 0, 'metrics' => array());
        foreach (array_keys(self::get_metrics()) as $key) {
            $snapshot['metrics'][$key] = 0;
        }

        $flag_map = array(
            'gender' => 'has_gender',
            'dob' => 'has_dob',
            'mobile' => 'has_mobile',
            'mus' => 'has_mus',
            'confirmed' => 'confirmed',
        );

        foreach ($rows as $row) {
            $snapshot['members']++;
            foreach ($flag_map as $key => $col) {
                if (intval($row->$col)) {
                    $snapshot['metrics'][$key]++;
                }
            }
        }

        return $snapshot;
    }

    /**
     * Recorded snapshots, oldest first. Entries from the pre-1.13 format
     * (scoped 'all'/'active') are dropped on read.
     */
    public function get_history() {
        $history = get_option(self::HISTORY_OPTION, array());
        if (!is_array($history)) {
            return array();
        }
        return array_filter($history, function ($snap) {
            return isset($snap['members']);
        });
    }

    /**
     * Record today's snapshot once. Runs on the daily cron; also called on
     * page view as a fallback so history accrues even if wp-cron is quiet.
     */
    public function record_snapshot() {
        $today = current_time('Y-m-d');
        $history = $this->get_history();
        if (isset($history[$today])) {
            return false;
        }

        $history[$today] = $this->compute_snapshot();
        ksort($history);
        if (count($history) > self::HISTORY_MAX_DAYS) {
            $history = array_slice($history, -self::HISTORY_MAX_DAYS, null, true);
        }

        // Non-autoloaded: only this page and the cron ever read it.
        if (get_option(self::HISTORY_OPTION, null) === null) {
            add_option(self::HISTORY_OPTION, $history, '', 'no');
        } else {
            update_option(self::HISTORY_OPTION, $history);
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------------

    /**
     * Extract a 4-digit Australian postcode from a raw profile value
     * (handles 'VIC 3000', '3000 ', etc.). Empty string if none found.
     */
    public static function normalize_postcode($raw) {
        if (preg_match('/\b(\d{4})\b/', (string) $raw, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Postcode per current member: UM postal_code first, WooCommerce
     * billing_postcode as fallback. Returns array(postcode => count),
     * '' key = members with no usable postcode.
     */
    public function get_postcode_counts() {
        global $wpdb;

        $today = current_time('Y-m-d');
        $values = $wpdb->get_col($wpdb->prepare("
            SELECT COALESCE(NULLIF(MAX(pc.meta_value), ''), MAX(bpc.meta_value), '')
            FROM (SELECT DISTINCT user_id FROM {$wpdb->prefix}team_memberships
                  WHERE start_date <= %s AND end_date >= %s) m
            LEFT JOIN {$wpdb->usermeta} pc ON pc.user_id = m.user_id AND pc.meta_key = 'postal_code'
            LEFT JOIN {$wpdb->usermeta} bpc ON bpc.user_id = m.user_id AND bpc.meta_key = 'billing_postcode'
            GROUP BY m.user_id
        ", $today, $today));

        $counts = array();
        foreach ($values as $raw) {
            $postcode = self::normalize_postcode($raw);
            $counts[$postcode] = isset($counts[$postcode]) ? $counts[$postcode] + 1 : 1;
        }
        return $counts;
    }

    public function get_watchlist() {
        $list = get_option(self::WATCHLIST_OPTION, array());
        return is_array($list) ? $list : array();
    }

    private function save_watchlist_from_post() {
        $raw = isset($_POST['watchlist_postcodes']) ? wp_unslash($_POST['watchlist_postcodes']) : '';
        preg_match_all('/\b(\d{4})\b/', $raw, $m);
        $list = array_values(array_unique($m[1]));
        sort($list);
        update_option(self::WATCHLIST_OPTION, $list, false);
        return $list;
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private function render_tabs($active) {
        $base = admin_url('admin.php?page=club-membership-stats');
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom: 15px;">
            <a href="<?php echo esc_url($base); ?>" class="nav-tab <?php echo $active === 'quality' ? 'nav-tab-active' : ''; ?>">Data Quality</a>
            <a href="<?php echo esc_url($base . '&tab=locations'); ?>" class="nav-tab <?php echo $active === 'locations' ? 'nav-tab-active' : ''; ?>">Locations</a>
        </nav>
        <?php
    }

    private function render_bar($pct, $state) {
        $color = $state === 'good' ? '#46b450' : ($state === 'mid' ? '#f0b849' : '#dc3232');
        return '<div style="background: #e5e5e5; border-radius: 3px; height: 14px; width: 160px; display: inline-block; vertical-align: middle;">'
            . '<div style="background: ' . $color . '; height: 14px; border-radius: 3px; width: ' . max(2, min(100, round($pct))) . '%;"></div></div>';
    }

    /**
     * Inline SVG sparkline of a metric's percentage over the recorded
     * history. Fixed 0-100 vertical scale so lines compare across metrics.
     */
    private function render_sparkline($history, $metric_key) {
        $points = array();
        $i = 0;
        foreach ($history as $snap) {
            $total = intval($snap['members']);
            $n = isset($snap['metrics'][$metric_key]) ? intval($snap['metrics'][$metric_key]) : 0;
            $points[] = array($i++, $total > 0 ? ($n / $total * 100) : 0);
        }
        if (count($points) < 2) {
            return '<span style="color: #999; font-size: 11px;">collecting…</span>';
        }

        $w = 220;
        $h = 36;
        $max_x = count($points) - 1;
        $coords = array();
        foreach ($points as $p) {
            $x = round($p[0] / $max_x * ($w - 4) + 2, 1);
            $y = round($h - 3 - ($p[1] / 100 * ($h - 6)), 1);
            $coords[] = $x . ',' . $y;
        }
        return '<svg width="' . $w . '" height="' . $h . '" style="vertical-align: middle; background: #fafafa; border: 1px solid #eee; border-radius: 3px;">'
            . '<polyline fill="none" stroke="#2271b1" stroke-width="1.5" points="' . esc_attr(implode(' ', $coords)) . '"/>'
            . '</svg>';
    }

    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'quality';
        if ($tab === 'locations') {
            $this->render_locations_tab();
            return;
        }
        $this->render_quality_tab();
    }

    private function render_quality_tab() {
        if (isset($_POST['action']) && $_POST['action'] === 'record_stats_snapshot') {
            if (isset($_POST['stats_nonce']) && wp_verify_nonce($_POST['stats_nonce'], 'record_stats_snapshot') && current_user_can('manage_options')) {
                $recorded = $this->record_snapshot();
                echo '<div class="notice notice-' . ($recorded ? 'success' : 'info') . '"><p>'
                    . ($recorded ? 'Snapshot recorded for today.' : 'Today\'s snapshot already exists.') . '</p></div>';
            }
        } else {
            // Fallback recorder: viewing the page banks today's snapshot.
            $this->record_snapshot();
        }

        $current = $this->compute_snapshot();
        $history = $this->get_history();
        $metrics = self::get_metrics();

        // Comparison point: the oldest snapshot within the last ~30 days,
        // or the earliest ever if history is younger than that.
        $compare = null;
        $compare_date = '';
        $cutoff = date('Y-m-d', strtotime('-30 days', strtotime(current_time('Y-m-d'))));
        foreach ($history as $date => $snap) {
            if ($compare === null || $date <= $cutoff) {
                $compare = $snap;
                $compare_date = $date;
            }
        }

        ?>
        <div class="wrap">
            <h1>Stats</h1>
            <?php $this->render_tabs('quality'); ?>

            <p class="description">Profile completeness for the <strong><?php echo intval($current['members']); ?> current members</strong> on the membership ledger (unexpired grant — run seeding / the order re-scan first, or this undercounts). A snapshot is stored daily; sparklines show the trend across <?php echo count($history); ?> recorded day<?php echo count($history) === 1 ? '' : 's'; ?>.</p>

            <form method="post" style="margin: 10px 0;">
                <input type="hidden" name="action" value="record_stats_snapshot">
                <?php wp_nonce_field('record_stats_snapshot', 'stats_nonce'); ?>
                <input type="submit" class="button" value="Record snapshot now">
            </form>

            <table class="wp-list-table widefat fixed striped" style="max-width: 850px;">
                <thead>
                    <tr>
                        <th style="width: 28%;">Metric</th>
                        <th>Current members (<?php echo intval($current['members']); ?>)</th>
                        <th style="width: 30%;">Trend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metrics as $key => $label): ?>
                        <?php
                        $total = intval($current['members']);
                        $n = intval($current['metrics'][$key]);
                        $pct = $total > 0 ? $n / $total * 100 : 0;
                        $state = $pct >= 80 ? 'good' : ($pct >= 50 ? 'mid' : 'low');

                        $delta = '';
                        if ($compare && !empty($compare['members'])) {
                            $old_pct = intval($compare['metrics'][$key]) / intval($compare['members']) * 100;
                            $diff = round($pct - $old_pct, 1);
                            if (abs($diff) >= 0.1) {
                                $delta = ' <span style="font-size: 11px; color: ' . ($diff > 0 ? '#46b450' : '#dc3232') . ';">'
                                    . ($diff > 0 ? '▲' : '▼') . abs($diff) . '% since ' . esc_html($compare_date) . '</span>';
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><?php echo $this->render_bar($pct, $state); ?> <strong><?php echo round($pct); ?>%</strong> (<?php echo $n; ?>/<?php echo $total; ?>)<?php echo $delta; ?></td>
                            <td><?php echo $this->render_sparkline($history, $key); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($history) > 1): ?>
                <details style="margin-top: 20px; max-width: 850px;">
                    <summary style="cursor: pointer; font-weight: 600;">Snapshot history (<?php echo count($history); ?> days)</summary>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Members</th>
                                <?php foreach ($metrics as $label): ?>
                                    <th style="font-size: 11px;"><?php echo esc_html($label); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($history, true) as $date => $snap): ?>
                                <tr>
                                    <td><?php echo esc_html($date); ?></td>
                                    <td><?php echo intval($snap['members']); ?></td>
                                    <?php foreach (array_keys($metrics) as $key): ?>
                                        <td><?php echo intval($snap['metrics'][$key]); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_locations_tab() {
        if (isset($_POST['action']) && $_POST['action'] === 'save_postcode_watchlist') {
            if (isset($_POST['stats_nonce']) && wp_verify_nonce($_POST['stats_nonce'], 'save_postcode_watchlist') && current_user_can('manage_options')) {
                $list = $this->save_watchlist_from_post();
                echo '<div class="notice notice-success"><p>Watchlist saved (' . count($list) . ' postcodes).</p></div>';
            }
        }

        $counts = $this->get_postcode_counts();
        $watchlist = $this->get_watchlist();

        $total = array_sum($counts);
        $unknown = isset($counts['']) ? $counts[''] : 0;
        $known = $total - $unknown;
        unset($counts['']);
        arsort($counts);

        $watch_total = 0;
        foreach ($watchlist as $pc) {
            $watch_total += isset($counts[$pc]) ? $counts[$pc] : 0;
        }

        ?>
        <div class="wrap">
            <h1>Stats</h1>
            <?php $this->render_tabs('locations'); ?>

            <p class="description">Where the <strong><?php echo intval($total); ?> current members</strong> on the ledger live, by profile postcode (falling back to their billing postcode). <?php echo intval($known); ?> have a usable postcode; <?php echo intval($unknown); ?> don't.</p>

            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">

                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-width: 340px;">
                    <h2 style="margin-top: 0;">Postcode watchlist</h2>
                    <p class="description" style="max-width: 320px;">Postcodes you care about (e.g. on/near campus, a target catchment). Separate with spaces, commas or new lines.</p>
                    <form method="post">
                        <textarea name="watchlist_postcodes" rows="3" style="width: 100%;" placeholder="3000, 3052, 3053"><?php echo esc_textarea(implode(', ', $watchlist)); ?></textarea>
                        <input type="hidden" name="action" value="save_postcode_watchlist">
                        <?php wp_nonce_field('save_postcode_watchlist', 'stats_nonce'); ?>
                        <p><input type="submit" class="button button-primary" value="Save Watchlist"></p>
                    </form>

                    <?php if (!empty($watchlist)): ?>
                        <p style="font-size: 14px;"><strong><?php echo intval($watch_total); ?></strong> of <?php echo intval($total); ?> current members
                            (<?php echo $total > 0 ? round($watch_total / $total * 100) : 0; ?>%) live in the watchlist postcodes.</p>
                        <table class="wp-list-table widefat fixed striped">
                            <thead><tr><th>Postcode</th><th style="text-align: right;">Members</th></tr></thead>
                            <tbody>
                                <?php foreach ($watchlist as $pc): ?>
                                    <tr>
                                        <td><?php echo esc_html($pc); ?></td>
                                        <td style="text-align: right;"><?php echo isset($counts[$pc]) ? intval($counts[$pc]) : 0; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-width: 340px;">
                    <h2 style="margin-top: 0;">All postcodes</h2>
                    <table class="wp-list-table widefat fixed striped" id="postcode-table">
                        <thead><tr><th>Postcode</th><th style="text-align: right;">Members</th><th style="text-align: right;">%</th></tr></thead>
                        <tbody>
                            <?php foreach ($counts as $pc => $n): ?>
                                <tr>
                                    <td><?php echo esc_html($pc); ?></td>
                                    <td style="text-align: right;"><?php echo intval($n); ?></td>
                                    <td style="text-align: right;"><?php echo $total > 0 ? round($n / $total * 100, 1) : 0; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($unknown > 0): ?>
                                <tr style="color: #888;">
                                    <td>No postcode</td>
                                    <td style="text-align: right;"><?php echo intval($unknown); ?></td>
                                    <td style="text-align: right;"><?php echo $total > 0 ? round($unknown / $total * 100, 1) : 0; ?>%</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
        <?php
    }
}
