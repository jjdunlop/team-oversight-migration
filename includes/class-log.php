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
                'payment_unapplied' => 'Unapplied payment',
                'payment_refunded' => 'Payment refunded or cancelled',
                'trial_fee_refunded' => 'Trial fee refunded or cancelled',
                'email_reminder' => 'Reminder email',
                'email_trial' => 'Trial email',
                'trial_fee_waived' => 'Trial fee waived',
                'fee_edit' => 'Fee edited',
                'fee_manual_kept' => 'Manual fee kept',
                'payment_arrangement' => 'Payment arrangement',
                'committee_access' => 'Committee access',
                // Fee timeline (2027+). Registered in 1.70.0, ahead of
                // 1.70.1, which is the first to write them.
                'team_dates' => 'Team dates changed',
                'season_config' => 'Season dates / payment window',
                // Offers and deposits (1.76.0). offer_flow: one line per
                // offer transition and the one-off reset; offer_money:
                // routine money facts (holding invoice, forfeit, credit,
                // target drop, cap); offer_alert: things a person has to put
                // right (the only type Payment Management's banner lists);
                // email_offer: an offer-related email queued, sent, skipped,
                // failed or suppressed (not the live site).
                'offer_flow' => 'Offer flow',
                'offer_money' => 'Offer money',
                'offer_alert' => 'Offer alert',
                'email_offer' => 'Offer email',
                // 1.88.0: the club covering a player's playing fee (a mark
                // set, changed or cleared on a trial application, and a
                // locked-in offer whose cover changed afterwards).
                'fee_cover' => 'Fee covered by the club',
            ),
            'membership' => array(
                'membership_granted' => 'Granted',
                'membership_extended' => 'Extended',
                'membership_upgraded' => 'Upgraded',
                'membership_expired' => 'Expired',
                'membership_revoked' => 'Revoked',
                'membership_order_refunded' => 'Order refunded or cancelled',
                'membership_grant' => 'Granted (pre-1.22)',
                // Duplicate accounts (1.71.0).
                'account_merged' => 'Accounts merged',
                'account_merge_undone' => 'Account merge undone',
                'duplicate_dismissed' => 'Marked not duplicates',
                // Coaches (1.81.0): profile changes, the season line-up switch and the daily check, the editor role.
                'coach_profile' => 'Coach profile',
                'coach_website' => 'Coaches on the website',
                'coach_editor' => 'Coach bios editor role',
                // Club Programs (1.84.0): setup saves (the writer exists from 1.84.0; its screen from 1.85.0).
                'program_setup' => 'Club Programs setup',
                // Club Programs (1.85.0): session changes and refused deletions.
                'program_sessions' => 'Club Programs sessions',
                'program_guard' => 'Club Programs deletion refused',
                // Club documents (1.90.0): every change to a document, its versions, the groups and the
                // settings; and problems (the storage check failing, an address opened with its file missing).
                'club_document' => 'Club document',
                'club_document_problem' => 'Club documents problem',
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
                'message' => self::cut_bytes($message, 500),
            ),
            array('%s', '%d', '%d', '%f', '%s')
        );

        // Opportunistic prune (~every 200th write).
        if (mt_rand(1, 200) === 1) {
            self::prune();
        }
    }

    /**
     * $text cut to at most $bytes bytes, never inside a multibyte UTF-8
     * character (1.89.2). A plain substr() that splits one leaves invalid
     * UTF-8, and $wpdb->insert() / update() then refuse the whole row (the
     * log line was lost that way); a raw query keeps the text only up to
     * the split. Use it for any text cut to fit a column.
     */
    public static function cut_bytes($text, $bytes) {
        $text = (string) $text;
        $bytes = max(0, intval($bytes));
        if (strlen($text) <= $bytes) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $bytes, 'UTF-8');
        }
        return self::cut_bytes_plain($text, $bytes);
    }

    /**
     * cut_bytes() without mbstring: when the first byte left out is a
     * continuation byte (10xxxxxx), the cut split a character, so it backs
     * off to that character's first byte (at most three steps; text that is
     * not UTF-8 to begin with is cut where asked). $text is longer than
     * $bytes.
     */
    private static function cut_bytes_plain($text, $bytes) {
        $end = $bytes;
        while ($end > 0 && $end > $bytes - 3 && (ord($text[$end]) & 0xC0) === 0x80) {
            $end--;
        }
        return (ord($text[$end]) & 0xC0) === 0x80 ? substr($text, 0, $bytes) : substr($text, 0, $end);
    }

    public static function prune() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::KEEP_DAYS
        ));
    }

    /**
     * Warning notice listing the last $days of the given event types, for
     * events someone has to put right by hand (a WooCommerce refund the
     * books still count as paid), shown on the page where it's fixed.
     * Admins only; the caller picks the page. Prints nothing when quiet.
     */
    public static function render_alert_notice($types, $intro, $days = 30) {
        global $wpdb;

        $types = array_values((array) $types);
        if (empty($types) || !current_user_can('manage_options')) {
            return;
        }

        $params = $types;
        $params[] = intval($days);
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT l.created_date, l.message, u.display_name
            FROM " . self::table() . " l
            LEFT JOIN {$wpdb->users} u ON u.ID = l.subject_user_id
            WHERE l.event_type IN (" . implode(',', array_fill(0, count($types), '%s')) . ")
                AND l.created_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
            ORDER BY l.id DESC
            LIMIT 10
        ", $params));
        if (empty($rows)) {
            return;
        }
        $membership_types = self::get_types('membership');
        $log_page = isset($membership_types[$types[0]]) ? 'club-membership-logs' : 'team-oversight-logs';

        ?>
        <div class="notice notice-warning">
            <p><strong><?php echo esc_html($intro); ?></strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ($rows as $row): ?>
                    <li><?php echo esc_html(date('j M', strtotime($row->created_date))); ?> &mdash; <?php echo esc_html($row->display_name ? $row->display_name : 'No account'); ?>: <?php echo esc_html($row->message); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Each one stays listed for <?php echo intval($days); ?> days, whether or not it has been dealt with. <a href="<?php echo esc_url(admin_url('admin.php?page=' . $log_page . '&log_type=' . $types[0])); ?>">See them all on Logs</a></p>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Admin page (VVL Oversight → Logs)
    // ------------------------------------------------------------------

    public static function render_admin_page($channel = 'vvl') {
        global $wpdb;

        $types = self::get_types($channel);
        $page_slug = $channel === 'membership' ? 'club-membership-logs' : 'team-oversight-logs';
        $intro = $channel === 'membership'
            ? 'Membership lifecycle: grants, extensions, upgrades, expiries and revocations; account merges; coach profiles.'
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
