<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activity log: an append-only record of things the club cares about
 * having happened — payments recorded, reminder emails sent, fees edited,
 * memberships granted. Viewed on VVL Oversight → Logs.
 *
 * Rows older than two years are pruned opportunistically on write.
 */
class TeamOversight_Log {

    const KEEP_DAYS = 730;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'team_activity_log';
    }

    /**
     * Event types, grouped into channels: 'vvl' shows on VVL Oversight →
     * Logs (money and emails), 'membership' on Club Membership → Logs
     * (membership lifecycle). One underlying table, two windows onto it.
     */
    public static function get_types($channel = null) {
        $channels = array(
            'vvl' => array(
                'payment_online' => 'Online payment',
                'payment_manual' => 'Manual payment',
                'email_reminder' => 'Reminder email',
                'fee_edit' => 'Fee edited',
            ),
            'membership' => array(
                'membership_granted' => 'Granted',
                'membership_extended' => 'Extended',
                'membership_upgraded' => 'Upgraded',
                'membership_expired' => 'Expired',
                'membership_revoked' => 'Revoked',
                'membership_grant' => 'Granted (pre-1.22)',
            ),
        );

        if ($channel === null) {
            return array_merge($channels['vvl'], $channels['membership']);
        }
        return isset($channels[$channel]) ? $channels[$channel] : array();
    }

    /**
     * Append a log row. $args: user_id (the member it's about),
     * amount (money involved, if any).
     */
    public static function add($type, $message, $args = array()) {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            array(
                'event_type' => substr((string) $type, 0, 40),
                'subject_user_id' => isset($args['user_id']) ? intval($args['user_id']) : null,
                'actor_id' => get_current_user_id(),
                'amount' => isset($args['amount']) ? round(floatval($args['amount']), 2) : null,
                'message' => substr((string) $message, 0, 500),
            ),
            array('%s', '%d', '%d', '%f', '%s')
        );

        // Opportunistic prune (~every 200th write).
        if (mt_rand(1, 200) === 1) {
            self::prune();
        }
    }

    public static function prune() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::KEEP_DAYS
        ));
    }

    // ------------------------------------------------------------------
    // Admin page (VVL Oversight → Logs)
    // ------------------------------------------------------------------

    public static function render_admin_page($channel = 'vvl') {
        global $wpdb;

        $types = self::get_types($channel);
        $page_slug = $channel === 'membership' ? 'club-membership-logs' : 'team-oversight-logs';
        $intro = $channel === 'membership'
            ? 'Membership lifecycle: grants, extensions, upgrades, expiries and revocations.'
            : 'Payments, reminder emails and fee edits.';
        $type = isset($_GET['log_type']) && isset($types[$_GET['log_type']]) ? $_GET['log_type'] : '';
        $search = isset($_GET['log_search']) ? sanitize_text_field(wp_unslash($_GET['log_search'])) : '';

        // Each page only ever shows its own channel's events.
        $where = array('l.event_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')');
        $params = array_keys($types);
        if ($type !== '') {
            $where[] = 'l.event_type = %s';
            $params[] = $type;
        }
        if ($search !== '') {
            $where[] = '(l.message LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "
            SELECT l.*, u.display_name AS subject_name, u.user_email AS subject_email,
                a.display_name AS actor_name
            FROM " . self::table() . " l
            LEFT JOIN {$wpdb->users} u ON u.ID = l.subject_user_id
            LEFT JOIN {$wpdb->users} a ON a.ID = l.actor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY l.id DESC
            LIMIT 500
        ";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

        ?>
        <div class="wrap">
            <h1>Logs</h1>
            <p class="description"><?php echo esc_html($intro); ?> The last 500 matching events; entries are kept for two years.</p>

            <form method="get" style="margin: 15px 0; padding: 12px 15px; background: #f9f9f9; border: 1px solid #ddd; display: inline-block;">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <label>Event:
                    <select name="log_type">
                        <option value="">All events</option>
                        <?php foreach ($types as $slug => $label): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($type, $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="margin-left: 10px;">Search:
                    <input type="text" name="log_search" value="<?php echo esc_attr($search); ?>" placeholder="name, email or message" style="width: 220px;">
                </label>
                <input type="submit" class="button" value="Filter">
                <?php if ($type !== '' || $search !== ''): ?>
                    <a href="<?php echo admin_url('admin.php?page=' . $page_slug); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($rows)): ?>
                <p>No log entries<?php echo ($type !== '' || $search !== '') ? ' match the filter' : ' yet — they appear as payments, emails and admin changes happen'; ?>.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 13%;">When</th>
                            <th style="width: 12%;">Event</th>
                            <th style="width: 18%;">Member</th>
                            <th style="width: 8%;">Amount</th>
                            <th>Details</th>
                            <th style="width: 11%;">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html(date('j M Y H:i', strtotime($row->created_date))); ?></td>
                                <td><?php echo esc_html(isset($types[$row->event_type]) ? $types[$row->event_type] : $row->event_type); ?></td>
                                <td>
                                    <?php if ($row->subject_name): ?>
                                        <a href="<?php echo esc_url(get_edit_user_link($row->subject_user_id)); ?>"><?php echo esc_html($row->subject_name); ?></a>
                                        <br><small><?php echo esc_html($row->subject_email); ?></small>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $row->amount !== null ? '$' . number_format($row->amount, 2) : '—'; ?></td>
                                <td style="font-size: 12px;"><?php echo esc_html($row->message); ?></td>
                                <td><?php echo $row->actor_name ? esc_html($row->actor_name) : '<span style="color: #888;">System</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
