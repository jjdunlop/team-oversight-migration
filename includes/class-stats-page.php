<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data Quality page (Club Membership menu).
 *
 * Shows how complete member profile data is (gender, DOB, mobile, MUS
 * category, annual confirmation, membership ledger coverage) for two
 * scopes: everyone with an account, and people active this year.
 *
 * "Over time" needs history, and nothing records when a profile field was
 * filled in — so a snapshot of the counts is recorded once a day (on the
 * membership sync cron, plus a fallback whenever this page is viewed) into
 * a non-autoloaded option. Trends accrue from the day this ships.
 */
class TeamOversight_Stats_Page {

    const HISTORY_OPTION = 'team_oversight_data_quality_history';
    const HISTORY_MAX_DAYS = 1100; // ~3 years of daily snapshots

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
            'membership' => 'Current membership on ledger',
        );
    }

    /**
     * Compute today's counts. Scope 'active' = anyone with a membership
     * grant overlapping this calendar year or a team assignment this season.
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
                MAX(CASE WHEN um.meta_key = 'profile_confirmed_year' AND um.meta_value = %s THEN 1 ELSE 0 END) AS confirmed,
                EXISTS(SELECT 1 FROM {$wpdb->prefix}team_memberships m
                    WHERE m.user_id = u.ID AND m.start_date <= %s AND m.end_date >= %s) AS has_membership,
                (EXISTS(SELECT 1 FROM {$wpdb->prefix}team_memberships m2
                    WHERE m2.user_id = u.ID AND m2.start_date <= %s AND m2.end_date >= %s)
                 OR EXISTS(SELECT 1 FROM {$wpdb->prefix}team_assignments a
                    WHERE (a.user_id = u.ID OR a.email = u.user_email) AND a.season = %s AND a.is_active = 1)) AS is_active_person
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
                AND um.meta_key IN ('gender', 'gender_dropdown', 'birth_date', 'mobile_number', 'MUSEligibilityCategory', 'profile_confirmed_year')
            GROUP BY u.ID
        ", $year, $today, $today, $year . '-12-31', $year . '-01-01', $year));

        $snapshot = array(
            'totals' => array('all' => 0, 'active' => 0),
            'metrics' => array(),
        );
        foreach (array_keys(self::get_metrics()) as $key) {
            $snapshot['metrics'][$key] = array('all' => 0, 'active' => 0);
        }

        $flag_map = array(
            'gender' => 'has_gender',
            'dob' => 'has_dob',
            'mobile' => 'has_mobile',
            'mus' => 'has_mus',
            'confirmed' => 'confirmed',
            'membership' => 'has_membership',
        );

        foreach ($rows as $row) {
            $scopes = array('all');
            if (intval($row->is_active_person)) {
                $scopes[] = 'active';
            }
            foreach ($scopes as $scope) {
                $snapshot['totals'][$scope]++;
                foreach ($flag_map as $key => $col) {
                    if (intval($row->$col)) {
                        $snapshot['metrics'][$key][$scope]++;
                    }
                }
            }
        }

        return $snapshot;
    }

    public function get_history() {
        $history = get_option(self::HISTORY_OPTION, array());
        return is_array($history) ? $history : array();
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
    // Rendering
    // ------------------------------------------------------------------

    private function render_bar($pct, $state) {
        $color = $state === 'good' ? '#46b450' : ($state === 'mid' ? '#f0b849' : '#dc3232');
        return '<div style="background: #e5e5e5; border-radius: 3px; height: 14px; width: 160px; display: inline-block; vertical-align: middle;">'
            . '<div style="background: ' . $color . '; height: 14px; border-radius: 3px; width: ' . max(2, min(100, round($pct))) . '%;"></div></div>';
    }

    /**
     * Inline SVG sparkline of a metric's percentage over the recorded
     * history (active scope). Fixed 0-100 vertical scale so lines are
     * comparable across metrics.
     */
    private function render_sparkline($history, $metric_key, $scope) {
        $points = array();
        $i = 0;
        foreach ($history as $snap) {
            $total = isset($snap['totals'][$scope]) ? intval($snap['totals'][$scope]) : 0;
            $n = isset($snap['metrics'][$metric_key][$scope]) ? intval($snap['metrics'][$metric_key][$scope]) : 0;
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
            <h1>Data Quality</h1>
            <p class="description">How complete member data is, for people <strong>active this year</strong> (a current membership or a team this season) and for <strong>all accounts</strong>. A snapshot is stored daily so the trend builds over time — sparklines show the active-scope percentage across <?php echo count($history); ?> recorded day<?php echo count($history) === 1 ? '' : 's'; ?>.</p>

            <form method="post" style="margin: 10px 0;">
                <input type="hidden" name="action" value="record_stats_snapshot">
                <?php wp_nonce_field('record_stats_snapshot', 'stats_nonce'); ?>
                <input type="submit" class="button" value="Record snapshot now">
            </form>

            <table class="wp-list-table widefat fixed striped" style="max-width: 1000px;">
                <thead>
                    <tr>
                        <th style="width: 22%;">Metric</th>
                        <th>Active this year (<?php echo intval($current['totals']['active']); ?>)</th>
                        <th>All accounts (<?php echo intval($current['totals']['all']); ?>)</th>
                        <th style="width: 24%;">Trend (active)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metrics as $key => $label): ?>
                        <?php
                        $cells = array();
                        foreach (array('active', 'all') as $scope) {
                            $total = intval($current['totals'][$scope]);
                            $n = intval($current['metrics'][$key][$scope]);
                            $pct = $total > 0 ? $n / $total * 100 : 0;
                            $state = $pct >= 80 ? 'good' : ($pct >= 50 ? 'mid' : 'low');

                            $delta = '';
                            if ($compare && !empty($compare['totals'][$scope])) {
                                $old_pct = intval($compare['metrics'][$key][$scope]) / intval($compare['totals'][$scope]) * 100;
                                $diff = round($pct - $old_pct, 1);
                                if (abs($diff) >= 0.1) {
                                    $delta = ' <span style="font-size: 11px; color: ' . ($diff > 0 ? '#46b450' : '#dc3232') . ';">'
                                        . ($diff > 0 ? '▲' : '▼') . abs($diff) . '% since ' . esc_html($compare_date) . '</span>';
                                }
                            }

                            $cells[$scope] = $this->render_bar($pct, $state) . ' '
                                . '<strong>' . round($pct) . '%</strong> (' . $n . '/' . $total . ')' . $delta;
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><?php echo $cells['active']; ?></td>
                            <td><?php echo $cells['all']; ?></td>
                            <td><?php echo $this->render_sparkline($history, $key, 'active'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (count($history) > 1): ?>
                <details style="margin-top: 20px; max-width: 1000px;">
                    <summary style="cursor: pointer; font-weight: 600;">Snapshot history (<?php echo count($history); ?> days)</summary>
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Active people</th>
                                <?php foreach ($metrics as $label): ?>
                                    <th style="font-size: 11px;"><?php echo esc_html($label); ?> (active)</th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($history, true) as $date => $snap): ?>
                                <tr>
                                    <td><?php echo esc_html($date); ?></td>
                                    <td><?php echo intval($snap['totals']['active']); ?></td>
                                    <?php foreach (array_keys($metrics) as $key): ?>
                                        <td><?php echo intval($snap['metrics'][$key]['active']); ?></td>
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
}
