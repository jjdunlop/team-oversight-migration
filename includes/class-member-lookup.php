<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Member Lookup: search any account on the site and see everything the club
 * holds about that one person on one screen — profile and emergency
 * contacts, membership grants, team history, fees and payments, trial
 * applications, recent orders and activity.
 *
 * Deliberately search-first. The site has thousands of accounts, so this
 * page never lists them: nothing is queried until a search term is entered,
 * results are capped, and the detail view costs one person's worth of
 * queries. It also covers *every* account, not just people on the
 * membership ledger — casual program participants and lapsed members have
 * emergency contacts too.
 */
class TeamOversight_Member_Lookup {

    /** Most search results shown at once. */
    const RESULT_LIMIT = 25;

    /** Minimum search length — stops a stray keystroke scanning the table. */
    const MIN_TERM = 2;

    /** Profile fields worth searching, beyond the users table itself. */
    const SEARCH_META = array(
        'first_name', 'last_name', 'full_name', 'mobile_number', 'StudentID',
        'emergency_contact_name', 'emergency_contact_number',
        'emergency_contact_name_37', 'emergency_contact_number_38',
    );

    /** The subset of the above that identifies someone's emergency contact. */
    const EMERGENCY_META = array(
        'emergency_contact_name' => 'emergency_contact_number',
        'emergency_contact_name_37' => 'emergency_contact_number_38',
    );

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $term = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
        $user_id = isset($_GET['user']) ? intval($_GET['user']) : 0;
        $user = $user_id ? get_user_by('id', $user_id) : null;

        ?>
        <div class="wrap murvc-lookup">
            <h1>Member Lookup</h1>
            <p class="description" style="max-width: 760px;">
                Search any account on the site — members, casual program participants, lapsed
                accounts, anyone with a login. Emergency contacts, fees, teams and membership
                history for one person, in one place. Searching a name or number also matches
                <strong>emergency contacts</strong>, so an unknown number that rings the club can
                be traced back to whose contact it is.
            </p>

            <?php $this->render_styles(); ?>

            <form method="get" class="murvc-lookup-search">
                <input type="hidden" name="page" value="club-membership-lookup">
                <input type="search" name="s" value="<?php echo esc_attr($term); ?>"
                       placeholder="Name, email, username, mobile, student ID or emergency contact&hellip;"
                       autofocus autocomplete="off">
                <input type="submit" class="button button-primary" value="Search">
                <?php if ($user): ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=club-membership-lookup' . ($term !== '' ? '&s=' . urlencode($term) : ''))); ?>">&laquo; Back to results</a>
                <?php endif; ?>
            </form>

            <?php
            if ($user) {
                $this->render_profile($user);
            } elseif ($user_id) {
                echo '<div class="notice notice-error"><p>No account with that ID — it may have been deleted.</p></div>';
            } elseif ($term !== '') {
                $this->render_results($term);
            } else {
                echo '<p class="murvc-lookup-empty">Type at least ' . intval(self::MIN_TERM) . ' characters above to find someone.</p>';
            }
            ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    /**
     * Accounts matching a term across the users table and the profile
     * fields worth searching — including emergency contacts, so an unknown
     * number that rings the club can be traced back to whose contact it is.
     * Capped at RESULT_LIMIT + 1 so the page can say "narrow this down"
     * without counting the whole table.
     */
    private function search_users($term) {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($term) . '%';
        $meta_clauses = array('m.meta_value LIKE %s');
        $meta_params = array($like);

        // Phone numbers are stored every which way — spaced, +61, and some
        // imports dropped the leading zero — so numeric searches compare
        // digits to digits, with and without that zero.
        foreach ($this->phone_variants($term) as $variant) {
            $meta_clauses[] = self::PHONE_NORMALISE . ' LIKE %s';
            $meta_params[] = '%' . $wpdb->esc_like($variant) . '%';
        }

        $meta_clause = implode(' OR ', $meta_clauses);
        $keys = "'" . implode("', '", self::SEARCH_META) . "'";
        $params = array_merge(array($like, $like, $like), $meta_params, array(self::RESULT_LIMIT + 1));

        return $wpdb->get_results($wpdb->prepare("
            SELECT u.ID, u.display_name, u.user_email, u.user_login, u.user_registered
            FROM {$wpdb->users} u
            WHERE u.user_email LIKE %s
                OR u.display_name LIKE %s
                OR u.user_login LIKE %s
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} m
                    WHERE m.user_id = u.ID
                        AND m.meta_key IN ({$keys})
                        AND ({$meta_clause})
                )
            ORDER BY u.display_name
            LIMIT %d
        ", $params));
    }

    /** Strips formatting off a stored number so digits can be compared. */
    const PHONE_NORMALISE = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(m.meta_value, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

    /**
     * Digit forms of a search term worth trying against stored numbers:
     * the digits as typed, and the same without a leading zero (so 0411…
     * finds 411… and +61411…). Empty when the term isn't phone-like.
     */
    private function phone_variants($term) {
        $digits = preg_replace('/[^0-9]/', '', $term);
        if (strlen($digits) < 6) {
            return array();
        }

        $variants = array($digits);
        if (strpos($digits, '0') === 0) {
            $variants[] = substr($digits, 1);
        }
        return array_values(array_unique($variants));
    }

    /**
     * For a batch of results, which of their emergency contacts matched the
     * search term — so a hit on someone else's number is labelled as such
     * rather than looking like an unexplained result. One query.
     */
    private function get_emergency_matches($user_ids, $term) {
        global $wpdb;

        $user_ids = array_filter(array_map('intval', (array) $user_ids));
        if (empty($user_ids)) {
            return array();
        }

        $ids = implode(',', $user_ids);
        $keys = "'" . implode("', '", array_merge(array_keys(self::EMERGENCY_META), array_values(self::EMERGENCY_META))) . "'";
        $rows = $wpdb->get_results("
            SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
            WHERE user_id IN ({$ids}) AND meta_key IN ({$keys})
        ");

        $by_user = array();
        foreach ($rows as $row) {
            $by_user[$row->user_id][$row->meta_key] = $row->meta_value;
        }

        $variants = $this->phone_variants($term);
        $matches = array();
        foreach ($by_user as $user_id => $meta) {
            foreach (self::EMERGENCY_META as $name_key => $number_key) {
                $name = isset($meta[$name_key]) ? $meta[$name_key] : '';
                $number = isset($meta[$number_key]) ? $meta[$number_key] : '';
                if ($this->value_matches($name, $term, array()) || $this->value_matches($number, $term, $variants)) {
                    $label = $name !== '' ? $name : 'Unnamed contact';
                    if ($number !== '') {
                        $label .= ' — ' . TeamOversight_Coach_Portal::format_phone($number);
                    }
                    $matches[$user_id][] = $label;
                }
            }
        }
        return $matches;
    }

    /** Same matching rule as the SQL above, applied to one stored value. */
    private function value_matches($value, $term, $phone_variants) {
        if ($value === '' || $term === '') {
            return false;
        }
        if (stripos($value, $term) !== false) {
            return true;
        }

        $value_digits = preg_replace('/[^0-9]/', '', $value);
        if ($value_digits === '') {
            return false;
        }
        foreach ($phone_variants as $variant) {
            if (strpos($value_digits, $variant) !== false) {
                return true;
            }
        }
        return false;
    }

    private function render_results($term) {
        if (strlen($term) < self::MIN_TERM) {
            echo '<p class="murvc-lookup-empty">Search for at least ' . intval(self::MIN_TERM) . ' characters.</p>';
            return;
        }

        $rows = $this->search_users($term);
        $truncated = count($rows) > self::RESULT_LIMIT;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::RESULT_LIMIT);
        }

        if (empty($rows)) {
            echo '<p class="murvc-lookup-empty">No account matches <strong>' . esc_html($term) . '</strong>. Try an email, a surname, or a mobile number.</p>';
            return;
        }

        $tiers = $this->get_active_tiers(wp_list_pluck($rows, 'ID'));
        $tier_labels = TeamOversight_Memberships::get_tiers();
        $emergency = $this->get_emergency_matches(wp_list_pluck($rows, 'ID'), $term);
        ?>
        <p><strong><?php echo count($rows); ?></strong> match<?php echo count($rows) === 1 ? '' : 'es'; ?><?php echo $truncated ? ' (showing the first ' . intval(self::RESULT_LIMIT) . ' — narrow your search)' : ''; ?>.</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 24%;">Name</th>
                    <th style="width: 26%;">Email</th>
                    <th style="width: 14%;">Mobile</th>
                    <th style="width: 16%;">Membership</th>
                    <th style="width: 12%;">Joined</th>
                    <th style="width: 8%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row):
                    $mobile = get_user_meta($row->ID, 'mobile_number', true);
                    $tier = isset($tiers[$row->ID]) ? $tiers[$row->ID] : '';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($row->display_name); ?></strong>
                            <?php if (!empty($emergency[$row->ID])): ?>
                                <?php foreach ($emergency[$row->ID] as $match): ?>
                                    <div class="murvc-match">emergency contact: <?php echo esc_html($match); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->user_email); ?></td>
                        <td><?php echo esc_html(TeamOversight_Coach_Portal::format_phone($mobile)); ?></td>
                        <td><?php echo $tier ? esc_html(isset($tier_labels[$tier]) ? $tier_labels[$tier] : $tier) : '<span class="murvc-muted">&mdash;</span>'; ?></td>
                        <td><?php echo esc_html(date('j M Y', strtotime($row->user_registered))); ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url($this->profile_url($row->ID, $term)); ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** user_id => tier for a batch of accounts, in one query. */
    private function get_active_tiers($user_ids) {
        global $wpdb;

        $user_ids = array_filter(array_map('intval', (array) $user_ids));
        if (empty($user_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT user_id, tier FROM {$wpdb->prefix}team_memberships
            WHERE user_id IN ({$placeholders})
                AND start_date <= CURDATE() AND end_date >= CURDATE()
        ", $user_ids));

        $rank = array(
            TeamOversight_Memberships::TIER_ASSOCIATE => 1,
            TeamOversight_Memberships::TIER_FULL => 2,
            TeamOversight_Memberships::TIER_LIFE => 3,
        );
        $best = array();
        foreach ($rows as $row) {
            $current = isset($best[$row->user_id]) ? $best[$row->user_id] : '';
            $current_rank = isset($rank[$current]) ? $rank[$current] : 0;
            $row_rank = isset($rank[$row->tier]) ? $rank[$row->tier] : 0;
            if ($row_rank >= $current_rank) {
                $best[$row->user_id] = $row->tier;
            }
        }
        return $best;
    }

    public static function profile_url($user_id, $term = '') {
        $url = admin_url('admin.php?page=club-membership-lookup&user=' . intval($user_id));
        return $term !== '' ? $url . '&s=' . urlencode($term) : $url;
    }

    // ------------------------------------------------------------------
    // One person
    // ------------------------------------------------------------------

    private function render_profile($user) {
        $meta = get_user_meta($user->ID); // one query for the whole profile
        $get = function ($key) use ($meta) {
            return isset($meta[$key][0]) ? $meta[$key][0] : '';
        };

        $gender = maybe_unserialize($get('gender'));
        if (is_array($gender)) {
            $gender = reset($gender);
        }
        if (!$gender) {
            $gender = $get('gender_dropdown');
        }

        $tier = $this->get_active_tiers(array($user->ID));
        $tier = isset($tier[$user->ID]) ? $tier[$user->ID] : '';
        $tier_labels = TeamOversight_Memberships::get_tiers();

        $address = array_filter(array($get('street_address'), $get('City'), $get('postal_code'), $get('country')));
        $last_login = $get('_um_last_login');

        ?>
        <div class="murvc-lookup-head">
            <h2><?php echo esc_html($user->display_name); ?></h2>
            <p class="murvc-chips">
                <?php if ($tier): ?>
                    <span class="murvc-chip chip-tier"><?php echo esc_html(isset($tier_labels[$tier]) ? $tier_labels[$tier] : $tier); ?></span>
                <?php else: ?>
                    <span class="murvc-chip chip-none">No current membership</span>
                <?php endif; ?>
                <?php if ($get('account_status') && $get('account_status') !== 'approved'): ?>
                    <span class="murvc-chip chip-warn">Account: <?php echo esc_html($get('account_status')); ?></span>
                <?php endif; ?>
                <?php foreach ($user->roles as $role): ?>
                    <span class="murvc-chip chip-role"><?php echo esc_html($role); ?></span>
                <?php endforeach; ?>
            </p>
            <p class="murvc-lookup-links">
                <a class="button button-small" href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>">WordPress user</a>
                <?php $um_url = $this->um_profile_url($user->ID); ?>
                <?php if ($um_url): ?>
                    <a class="button button-small" href="<?php echo esc_url($um_url); ?>" target="_blank" rel="noopener">Member profile</a>
                <?php endif; ?>
                <a class="button button-small" href="mailto:<?php echo esc_attr($user->user_email); ?>">Email</a>
            </p>
        </div>

        <div class="murvc-cards">

            <?php // Emergency contacts first — the reason most people open this page. ?>
            <div class="murvc-card card-emergency">
                <h3>Emergency contacts</h3>
                <?php $contacts = TeamOversight_Coach_Portal::get_emergency_contacts($user->ID); ?>
                <?php if (!empty($contacts)): ?>
                    <?php foreach ($contacts as $contact): ?>
                        <p class="murvc-contact">
                            <strong><?php echo esc_html($contact['name'] ? $contact['name'] : 'Name not recorded'); ?></strong>
                            <?php if ($contact['relationship']): ?><span class="murvc-muted"> (<?php echo esc_html($contact['relationship']); ?>)</span><?php endif; ?>
                            <?php if ($contact['number']): ?>
                                <br><a href="tel:<?php echo esc_attr(TeamOversight_Coach_Portal::phone_tel_href($contact['number'])); ?>"><?php echo esc_html(TeamOversight_Coach_Portal::format_phone($contact['number'])); ?></a>
                            <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="murvc-muted">Nothing recorded on this profile — worth chasing before they next train or play.</p>
                <?php endif; ?>
            </div>

            <div class="murvc-card">
                <h3>Profile</h3>
                <table class="murvc-kv">
                    <tr><th>Email</th><td><a href="mailto:<?php echo esc_attr($user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a></td></tr>
                    <tr><th>Mobile</th><td>
                        <?php if ($get('mobile_number')): ?>
                            <a href="tel:<?php echo esc_attr(TeamOversight_Coach_Portal::phone_tel_href($get('mobile_number'))); ?>"><?php echo esc_html(TeamOversight_Coach_Portal::format_phone($get('mobile_number'))); ?></a>
                        <?php else: ?><span class="murvc-muted">&mdash;</span><?php endif; ?>
                    </td></tr>
                    <tr><th>Date of birth</th><td><?php echo esc_html($this->format_dob($get('birth_date'))); ?></td></tr>
                    <tr><th>Gender</th><td><?php echo $gender ? esc_html($gender) : '<span class="murvc-muted">&mdash;</span>'; ?></td></tr>
                    <tr><th>Address</th><td><?php echo !empty($address) ? esc_html(implode(', ', $address)) : '<span class="murvc-muted">&mdash;</span>'; ?></td></tr>
                    <tr><th>MUS category</th><td><?php echo $get('MUSEligibilityCategory') ? esc_html($get('MUSEligibilityCategory')) : '<span class="murvc-muted">&mdash;</span>'; ?></td></tr>
                    <tr><th>Student ID</th><td><?php echo $get('StudentID') ? esc_html($get('StudentID')) : '<span class="murvc-muted">&mdash;</span>'; ?></td></tr>
                    <tr><th>Username</th><td><?php echo esc_html($user->user_login); ?></td></tr>
                    <tr><th>Account created</th><td><?php echo esc_html(date('j M Y', strtotime($user->user_registered))); ?></td></tr>
                    <tr><th>Last login</th><td><?php echo $last_login ? esc_html(date('j M Y', intval($last_login))) : '<span class="murvc-muted">never</span>'; ?></td></tr>
                </table>
            </div>

            <div class="murvc-card">
                <h3>Membership</h3>
                <?php $this->render_membership($user); ?>
            </div>

            <div class="murvc-card">
                <h3>Teams</h3>
                <?php $this->render_assignments($user); ?>
            </div>

            <div class="murvc-card card-wide">
                <h3>Fees</h3>
                <?php $this->render_fees($user); ?>
            </div>

            <div class="murvc-card">
                <h3>Trial applications</h3>
                <?php $this->render_trials($user); ?>
            </div>

            <div class="murvc-card">
                <h3>Recent orders</h3>
                <?php $this->render_orders($user); ?>
            </div>

            <div class="murvc-card card-wide">
                <h3>Recent activity</h3>
                <?php $this->render_activity($user); ?>
            </div>

        </div>
        <?php
    }

    private function render_membership($user) {
        global $wpdb;

        $grants = $wpdb->get_results($wpdb->prepare("
            SELECT tier, start_date, end_date, source, note, order_id
            FROM {$wpdb->prefix}team_memberships
            WHERE user_id = %d
            ORDER BY end_date DESC, start_date DESC
            LIMIT 20
        ", $user->ID));

        if (empty($grants)) {
            echo '<p class="murvc-muted">No membership has ever been granted to this account.</p>';
            return;
        }

        $labels = TeamOversight_Memberships::get_tiers();
        $today = date('Y-m-d');
        ?>
        <table class="murvc-list">
            <?php foreach ($grants as $grant):
                $current = $grant->start_date <= $today && $grant->end_date >= $today;
                $permanent = $grant->end_date >= '2099-01-01';
                ?>
                <tr class="<?php echo $current ? 'is-current' : ''; ?>">
                    <td><strong><?php echo esc_html(isset($labels[$grant->tier]) ? $labels[$grant->tier] : $grant->tier); ?></strong></td>
                    <td>
                        <?php echo esc_html(date('j M Y', strtotime($grant->start_date))); ?> &rarr;
                        <?php echo $permanent ? 'no expiry' : esc_html(date('j M Y', strtotime($grant->end_date))); ?>
                    </td>
                    <td class="murvc-muted"><?php echo esc_html($grant->source); ?><?php echo $grant->note ? ' — ' . esc_html($grant->note) : ''; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private function render_assignments($user) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT season, team, role, registration_status, is_active
            FROM {$wpdb->prefix}team_assignments
            WHERE user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s)
            ORDER BY season DESC, team
        ", $user->ID, $user->user_email));

        if (empty($rows)) {
            echo '<p class="murvc-muted">Never assigned to a VVL team.</p>';
            return;
        }

        $roles = array(
            'playing_member' => 'Player',
            'training_only' => 'Training only',
            'coach' => 'Coach',
            'assistant_coach' => 'Assistant coach',
            'team_manager' => 'Team manager',
        );
        ?>
        <table class="murvc-list">
            <?php foreach ($rows as $row): ?>
                <tr class="<?php echo $row->is_active ? '' : 'is-inactive'; ?>">
                    <td><strong><?php echo esc_html($row->season); ?></strong></td>
                    <td><?php echo esc_html($row->team); ?></td>
                    <td><?php echo esc_html(isset($roles[$row->role]) ? $roles[$row->role] : $row->role); ?></td>
                    <td class="murvc-muted"><?php echo $row->is_active ? esc_html($row->registration_status) : 'removed'; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private function render_fees($user) {
        global $wpdb;

        $invoices = TeamOversight_Payments::get_user_invoices($user);
        if (empty($invoices)) {
            echo '<p class="murvc-muted">No fees have ever been invoiced to this account.</p>';
            return;
        }

        $ids = wp_list_pluck($invoices, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $payments = $wpdb->get_results($wpdb->prepare("
            SELECT invoice_id, amount, source, note, created_date
            FROM {$wpdb->prefix}team_invoice_payments
            WHERE invoice_id IN ({$placeholders})
            ORDER BY created_date
        ", array_map('intval', $ids)));

        $by_invoice = array();
        foreach ($payments as $payment) {
            $by_invoice[$payment->invoice_id][] = $payment;
        }
        ?>
        <table class="wp-list-table widefat striped murvc-fees">
            <thead>
                <tr>
                    <th>Season</th><th>Fee</th><th>Paid</th><th>Outstanding</th><th>Overdue</th><th>Payments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice):
                    $paid = floatval($invoice->invoice_amount) - floatval($invoice->outstanding_amount);
                    $overdue = TeamOversight_Payments::get_overdue($invoice->invoice_amount, $invoice->outstanding_amount, $invoice->season);
                    $rows = isset($by_invoice[$invoice->id]) ? $by_invoice[$invoice->id] : array();
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($invoice->season); ?></strong></td>
                        <td>$<?php echo number_format($invoice->invoice_amount, 2); ?></td>
                        <td>$<?php echo number_format($paid, 2); ?></td>
                        <td><?php echo $invoice->outstanding_amount > 0 ? '<span class="murvc-owing">$' . number_format($invoice->outstanding_amount, 2) . '</span>' : '$0.00'; ?></td>
                        <td><?php echo $overdue > 0 ? '<span class="murvc-owing">$' . number_format($overdue, 2) . '</span>' : '<span class="murvc-muted">&mdash;</span>'; ?></td>
                        <td>
                            <?php if (empty($rows)): ?>
                                <span class="murvc-muted">none recorded</span>
                            <?php else: ?>
                                <details>
                                    <summary><?php echo count($rows); ?> payment<?php echo count($rows) === 1 ? '' : 's'; ?></summary>
                                    <?php foreach ($rows as $payment): ?>
                                        <div class="murvc-payment">
                                            <?php echo esc_html(date('j M Y', strtotime($payment->created_date))); ?>
                                            &middot; $<?php echo number_format($payment->amount, 2); ?>
                                            &middot; <?php echo esc_html($payment->source); ?>
                                            <?php echo $payment->note ? '<span class="murvc-muted"> — ' . esc_html($payment->note) . '</span>' : ''; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_trials($user) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT season, trial_number, application_status, assigned_team, created_date
            FROM {$wpdb->prefix}trial_applications
            WHERE user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s)
            ORDER BY season DESC
        ", $user->ID, $user->user_email));

        if (empty($rows)) {
            echo '<p class="murvc-muted">Never applied for VVL trials.</p>';
            return;
        }
        ?>
        <table class="murvc-list">
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?php echo esc_html($row->season); ?></strong></td>
                    <td><?php echo $row->trial_number ? '#' . intval($row->trial_number) : ''; ?></td>
                    <td><?php echo esc_html(ucfirst($row->application_status)); ?></td>
                    <td class="murvc-muted"><?php echo esc_html($row->assigned_team); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private function render_orders($user) {
        if (!function_exists('wc_get_orders')) {
            echo '<p class="murvc-muted">WooCommerce is not active.</p>';
            return;
        }

        $orders = wc_get_orders(array(
            'type' => 'shop_order',
            'customer_id' => $user->ID,
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if (empty($orders)) {
            echo '<p class="murvc-muted">No orders placed with this account.</p>';
            return;
        }
        ?>
        <table class="murvc-list">
            <?php foreach ($orders as $order):
                $items = array();
                foreach ($order->get_items() as $item) {
                    $items[] = $item->get_name();
                }
                ?>
                <tr>
                    <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></td>
                    <td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date('j M Y') : ''); ?></td>
                    <td>$<?php echo esc_html($order->get_total()); ?></td>
                    <td class="murvc-muted"><?php echo esc_html($order->get_status()); ?> — <?php echo esc_html(implode(', ', $items)); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private function render_activity($user) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT event_type, message, amount, created_date
            FROM " . TeamOversight_Log::table() . "
            WHERE subject_user_id = %d
            ORDER BY created_date DESC
            LIMIT 15
        ", $user->ID));

        if (empty($rows)) {
            echo '<p class="murvc-muted">Nothing logged against this account yet.</p>';
            return;
        }

        $types = TeamOversight_Log::get_types();
        ?>
        <table class="murvc-list">
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td style="white-space: nowrap;"><?php echo esc_html(date('j M Y', strtotime($row->created_date))); ?></td>
                    <td><?php echo esc_html(isset($types[$row->event_type]) ? $types[$row->event_type] : $row->event_type); ?></td>
                    <td class="murvc-muted"><?php echo esc_html($row->message); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** UM stores birth dates as YYYY/MM/DD; show the date plus current age. */
    private function format_dob($birth_date) {
        if (!$birth_date) {
            return '—';
        }
        $timestamp = strtotime(str_replace('/', '-', $birth_date));
        if (!$timestamp) {
            return $birth_date;
        }
        $age = date_diff(date_create(date('Y-m-d', $timestamp)), date_create(date('Y-m-d')));
        return date('j M Y', $timestamp) . ' (' . $age->y . ')';
    }

    private function um_profile_url($user_id) {
        if (!function_exists('um_fetch_user') || !function_exists('um_user_profile_url')) {
            return '';
        }
        um_fetch_user($user_id);
        $url = um_user_profile_url();
        if (function_exists('um_reset_user')) {
            um_reset_user();
        }
        return $url;
    }

    private function render_styles() {
        ?>
        <style>
        .murvc-lookup-search { margin: 15px 0 20px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .murvc-lookup-search input[type=search] { width: 380px; max-width: 100%; padding: 6px 10px; }
        .murvc-lookup-empty { color: #666; font-style: italic; margin: 25px 0; }
        .murvc-lookup-head { margin: 20px 0 10px; }
        .murvc-lookup-head h2 { margin: 0 0 6px; }
        .murvc-lookup-links { margin: 8px 0 0; }
        .murvc-chips { margin: 0; }
        .murvc-chip { display: inline-block; padding: 2px 9px; border-radius: 11px; font-size: 12px; margin-right: 5px; background: #eef1f4; color: #333; }
        .murvc-chip.chip-tier { background: #d8f0dd; color: #14611f; font-weight: 600; }
        .murvc-chip.chip-none { background: #f2f2f2; color: #777; }
        .murvc-chip.chip-warn { background: #ffe9cc; color: #8a5000; }
        .murvc-chip.chip-role { background: #e6ecf5; color: #2b4d80; }
        .murvc-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 15px; margin-top: 15px; align-items: start; }
        .murvc-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 12px 15px; }
        .murvc-card.card-wide { grid-column: 1 / -1; }
        .murvc-card h3 { margin: 0 0 10px; font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: #555; }
        .murvc-card.card-emergency { border-left: 4px solid #e07b00; background: #fffaf4; }
        .murvc-contact { margin: 0 0 10px; font-size: 14px; }
        .murvc-contact:last-child { margin-bottom: 0; }
        .murvc-kv { width: 100%; border-collapse: collapse; }
        .murvc-kv th { text-align: left; font-weight: 500; color: #666; padding: 3px 12px 3px 0; width: 38%; vertical-align: top; }
        .murvc-kv td { padding: 3px 0; }
        .murvc-list { width: 100%; border-collapse: collapse; font-size: 13px; }
        .murvc-list td { padding: 4px 10px 4px 0; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        .murvc-list tr.is-current td { background: #f4fbf5; }
        .murvc-list tr.is-inactive { opacity: .55; }
        .murvc-muted { color: #888; }
        .murvc-match { font-size: 12px; color: #8a5000; background: #fff7ec; border-left: 3px solid #e07b00; padding: 1px 6px; margin-top: 3px; display: inline-block; }
        .murvc-owing { color: #a00; font-weight: 600; }
        .murvc-payment { font-size: 12px; padding: 2px 0 2px 10px; }
        .murvc-fees details summary { cursor: pointer; }
        </style>
        <?php
    }
}
