<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Members admin page: one row per person with membership status, profile
 * data, team assignments, fees owing and accreditation — filterable,
 * sortable and exportable. Also hosts manual grant/revoke, the
 * category-rule settings and the purchase-history seeding tools.
 */
class TeamOversight_Members_Page {

    private $memberships;

    public function __construct() {
        $this->memberships = new TeamOversight_Memberships();
    }

    private function get_current_season() {
        if (isset($_GET['season']) && preg_match('/^\d{4}$/', $_GET['season'])) {
            return $_GET['season'];
        }
        $remembered = get_option('team_oversight_selected_season');
        return $remembered ? $remembered : date('Y');
    }

    public function render_page() {
        if (isset($_POST['action'])) {
            $this->handle_action();
        }

        $season = $this->get_current_season();
        $show_all = !empty($_GET['show_all']);
        $members = $this->get_members($season, $show_all);
        $tiers = TeamOversight_Memberships::get_tiers();

        ?>
        <div class="wrap">
            <h1>Club Membership <a href="<?php echo admin_url('admin.php?page=club-membership-history'); ?>" class="page-title-action">Membership History</a></h1>

            <div class="season-filter">
                <label for="season-select">Season (VVL teams &amp; fees columns):</label>
                <select id="season-select" onchange="location.href=this.value;">
                    <?php foreach ($this->get_available_seasons() as $available_season): ?>
                        <option value="<?php echo admin_url('admin.php?page=club-membership&season=' . $available_season . ($show_all ? '&show_all=1' : '')); ?>" <?php selected($season, $available_season); ?>><?php echo esc_html($available_season); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin-left: 15px;">
                    <input type="checkbox" onchange="location.href='<?php echo admin_url('admin.php?page=club-membership&season=' . $season); ?>' + (this.checked ? '&show_all=1' : '');" <?php checked($show_all); ?>>
                    Show all accounts (including people with no membership or team)
                </label>
            </div>

            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">Grant a membership manually</summary>
                <form method="post" style="margin-top: 10px;">
                    <table class="form-table-compact">
                        <tr>
                            <th><label for="grant_user_email">Member Email</label></th>
                            <td>
                                <input type="email" name="grant_user_email" id="grant_user_email" required autocomplete="off" placeholder="Search by email..." style="width: 280px;">
                                <div id="grant-email-suggestions" style="display: none; position: absolute; background: white; border: 1px solid #ccc; max-height: 200px; overflow-y: auto; z-index: 1000; width: 300px;"></div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="grant_tier">Tier</label></th>
                            <td>
                                <select name="grant_tier" id="grant_tier" required>
                                    <?php foreach ($tiers as $slug => $label): ?>
                                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="grant_end_date">Valid Until</label></th>
                            <td>
                                <input type="date" name="grant_end_date" id="grant_end_date" value="<?php echo date('Y-m-d', strtotime('+12 months')); ?>" required>
                                <p class="description" id="grant-life-note" style="display: none;">Life memberships don't expire — the date is set to <?php echo esc_html(TeamOversight_Memberships::PERMANENT_END); ?> and shown as "no expiry".</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="grant_note">Note</label></th>
                            <td><input type="text" name="grant_note" placeholder="Optional — e.g. committee decision, comp membership" style="width: 350px;"></td>
                        </tr>
                    </table>
                    <p>
                        <input type="submit" class="button button-primary" value="Grant Membership">
                        <input type="hidden" name="action" value="grant_membership">
                        <?php wp_nonce_field('grant_membership', 'members_nonce'); ?>
                    </p>
                </form>
            </details>

            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">Membership rules by product category</summary>
                <p class="description">Products in these categories grant a membership when purchased. A tier/term set on an individual product (Product &gt; Edit &gt; General) overrides its category rule. Categories left on "None" grant nothing.</p>
                <form method="post">
                    <table class="wp-list-table widefat striped" style="max-width: 700px;">
                        <thead><tr><th>Product Category</th><th>Tier Granted</th><th>Term (months)</th></tr></thead>
                        <tbody>
                            <?php
                            $rules = get_option(TeamOversight_Memberships::CATEGORY_RULES_OPTION, array());
                            $categories = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
                            if (is_wp_error($categories)) { $categories = array(); }
                            foreach ($categories as $cat):
                                $rule_tier = isset($rules[$cat->term_id]['tier']) ? $rules[$cat->term_id]['tier'] : '';
                                $rule_months = isset($rules[$cat->term_id]['months']) ? intval($rules[$cat->term_id]['months']) : '';
                            ?>
                                <tr>
                                    <td><?php echo esc_html($cat->name); ?></td>
                                    <td>
                                        <select name="rules[<?php echo $cat->term_id; ?>][tier]">
                                            <option value="">None</option>
                                            <?php foreach ($tiers as $slug => $label): ?>
                                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($rule_tier, $slug); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="rules[<?php echo $cat->term_id; ?>][months]" value="<?php echo esc_attr($rule_months); ?>" min="1" max="60" style="width: 70px;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <input type="submit" class="button button-primary" value="Save Category Rules">
                        <input type="hidden" name="action" value="save_membership_rules">
                        <?php wp_nonce_field('save_membership_rules', 'members_nonce'); ?>
                    </p>
                </form>
            </details>

            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">Seed memberships from this year's purchases</summary>
                <p class="description">Converts this calendar year's qualifying purchases into membership grants with real expiry dates (Full = 12 months, Associate = 3 months from purchase), using the same category rules as the stop-gap role snippet. Idempotent — already-seeded purchases are skipped. <strong>Note:</strong> after applying, members whose seeded grants have already expired (e.g. a 3-month associate term bought in February) will lose their tier role on the next sync — that is the intended lapse behaviour.</p>
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <input type="submit" class="button" value="Dry Run (report only)">
                    <input type="hidden" name="action" value="seed_memberships_dry">
                    <?php wp_nonce_field('seed_memberships', 'members_nonce'); ?>
                </form>
                <form method="post" style="display: inline-block;" onsubmit="return confirm('Apply seeding? This writes membership grants and re-syncs roles for everyone affected.');">
                    <input type="submit" class="button button-primary" value="Apply Seeding">
                    <input type="hidden" name="action" value="seed_memberships_apply">
                    <?php wp_nonce_field('seed_memberships', 'members_nonce'); ?>
                </form>
            </details>

            <details class="import-export-section" style="margin: 15px 0; padding: 10px 15px; background: #fff; border: 1px solid #ccd0d4;">
                <summary style="cursor: pointer; font-weight: 600;">Re-scan this year's paid orders</summary>
                <p class="description">Replays every paid (processing/completed) order from this calendar year through the normal membership-grant logic using the <strong>current</strong> product settings and category rules. Use this after adding membership attributes to a product — orders paid before the attributes were set never granted anything. Idempotent: purchases that already granted are skipped, and grants are dated from when the order was paid.</p>
                <form method="post" onsubmit="return confirm('Re-scan all of this year\'s paid orders? New grants are created for any qualifying purchase that has none, dated from payment.');">
                    <input type="submit" class="button button-primary" value="Re-scan Paid Orders">
                    <input type="hidden" name="action" value="rescan_orders">
                    <?php wp_nonce_field('rescan_orders', 'members_nonce'); ?>
                </form>
            </details>

            <div class="invoices-filters" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <div>
                        <label for="search-members">Search:</label>
                        <input type="text" id="search-members" placeholder="Search name, email, team..." style="width: 200px;">
                    </div>
                    <div>
                        <label for="filter-membership">Membership:</label>
                        <select id="filter-membership">
                            <option value="">All</option>
                            <option value="life">Life Member</option>
                            <option value="full">Full Member</option>
                            <option value="associate">Associate Member</option>
                            <option value="none">No Membership</option>
                        </select>
                    </div>
                    <div>
                        <label for="filter-member-team">VVL Team:</label>
                        <select id="filter-member-team">
                            <option value="">All Teams</option>
                            <?php
                            $database = new TeamOversight_Database();
                            foreach ($database->get_teams() as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="filter-owing">Fees:</label>
                        <select id="filter-owing">
                            <option value="">All</option>
                            <option value="owing">Owing</option>
                            <option value="clear">Nothing owing</option>
                        </select>
                    </div>
                    <div style="margin-left: auto;">
                        <form method="post" style="display: inline;">
                            <input type="submit" class="button" value="Export CSV">
                            <input type="hidden" name="action" value="export_members_csv">
                            <input type="hidden" name="export_season" value="<?php echo esc_attr($season); ?>">
                            <input type="hidden" name="export_show_all" value="<?php echo $show_all ? '1' : ''; ?>">
                            <?php wp_nonce_field('export_members_csv', 'members_nonce'); ?>
                        </form>
                    </div>
                </div>
            </div>

            <p><strong><?php echo count($members); ?></strong> people shown<span id="filtered-count"></span>.</p>

            <?php if (!empty($members)): ?>
                <table class="wp-list-table widefat fixed striped" id="members-table">
                    <thead>
                        <tr>
                            <th class="sortable-col" style="width: 12%;">Name</th>
                            <th class="sortable-col" style="width: 15%;">Email</th>
                            <th style="width: 8%;">Mobile</th>
                            <th class="sortable-col" style="width: 4%;">Age</th>
                            <th style="width: 10%;">MUS Category</th>
                            <th class="sortable-col" style="width: 11%;">Membership</th>
                            <th style="width: 14%;">VVL Teams (<?php echo esc_html($season); ?>)</th>
                            <th class="sortable-col" style="width: 8%;">Owing</th>
                            <th style="width: 5%;">VA</th>
                            <th style="width: 6%;">Confirmed</th>
                            <th style="width: 9%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                            <tr data-status="<?php echo esc_attr($m['status_key']); ?>" data-teams="<?php echo esc_attr($m['team_codes']); ?>" data-owing="<?php echo $m['outstanding'] > 0 ? 'owing' : 'clear'; ?>">
                                <td data-sort="<?php echo esc_attr($m['name']); ?>"><?php echo esc_html($m['name']); ?></td>
                                <td data-sort="<?php echo esc_attr($m['email']); ?>"><?php echo esc_html($m['email']); ?></td>
                                <td><?php echo esc_html($m['mobile']); ?></td>
                                <td data-sort="<?php echo esc_attr($m['age'] !== '' ? $m['age'] : -1); ?>"><?php echo esc_html($m['age']); ?></td>
                                <td style="font-size: 11px;" title="<?php echo esc_attr($m['mus_category']); ?>"><?php echo esc_html($m['mus_short']); ?></td>
                                <td data-sort="<?php echo esc_attr($m['status_sort']); ?>" title="<?php echo esc_attr($m['status_detail']); ?>">
                                    <?php echo esc_html($m['status_label']); ?>
                                    <?php if ($m['status_until']): ?><br><small>until <?php echo esc_html($m['status_until']); ?></small><?php endif; ?>
                                    <?php if ($m['role_only']): ?><br><small style="color: #996800;">role only — no expiry set</small><?php endif; ?>
                                </td>
                                <td style="font-size: 11px;"><?php echo esc_html($m['team_list']); ?></td>
                                <td data-sort="<?php echo esc_attr($m['outstanding']); ?>">
                                    <?php if ($m['invoiced'] > 0): ?>
                                        <?php if ($m['outstanding'] > 0): ?>
                                            <span style="color: #a00;">$<?php echo number_format($m['outstanding'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color: #1a7a2e;">$0.00</span>
                                        <?php endif; ?>
                                        <br><small>of $<?php echo number_format($m['invoiced'], 2); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td title="<?php echo esc_attr($m['accreditation']); ?>"><?php echo $m['accreditation'] ? '&#10003;' : ''; ?></td>
                                <td><?php echo $m['confirmed'] ? '&#10003; ' . esc_html(date('Y')) : ''; ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_user_link($m['user_id'])); ?>" class="button button-small">Profile</a>
                                    <?php if ($m['has_active_grant'] || $m['role_only']): ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Revoke <?php echo esc_js($m['name']); ?>\'s membership? Active grants are ended today and tier roles removed.');">
                                            <input type="hidden" name="action" value="revoke_membership">
                                            <input type="hidden" name="revoke_user_id" value="<?php echo intval($m['user_id']); ?>">
                                            <?php wp_nonce_field('revoke_membership', 'members_nonce'); ?>
                                            <input type="submit" class="button button-small" style="color: #a00;" value="Revoke">
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No members found.</p>
            <?php endif; ?>

            <script>
            // Filtering
            function filterMembers() {
                const search = document.getElementById('search-members').value.toLowerCase();
                const membershipFilter = document.getElementById('filter-membership').value;
                const teamFilter = document.getElementById('filter-member-team').value;
                const owingFilter = document.getElementById('filter-owing').value;

                let visible = 0;
                document.querySelectorAll('#members-table tbody tr').forEach(row => {
                    const matchesSearch = search === '' || row.textContent.toLowerCase().includes(search);
                    const matchesMembership = membershipFilter === '' || row.dataset.status === membershipFilter;
                    const matchesTeam = teamFilter === '' || (row.dataset.teams || '').split('|').includes(teamFilter);
                    const matchesOwing = owingFilter === '' || row.dataset.owing === owingFilter;
                    const show = matchesSearch && matchesMembership && matchesTeam && matchesOwing;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                const total = document.querySelectorAll('#members-table tbody tr').length;
                document.getElementById('filtered-count').textContent = visible < total ? ' (' + visible + ' matching filters)' : '';
            }
            ['search-members', 'filter-membership', 'filter-member-team', 'filter-owing'].forEach(id => {
                const el = document.getElementById(id);
                el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', filterMembers);
            });

            // Column sorting
            document.querySelectorAll('#members-table th.sortable-col').forEach((th) => {
                th.style.cursor = 'pointer';
                th.title = 'Click to sort';
                th.addEventListener('click', () => {
                    const table = th.closest('table');
                    const tbody = table.querySelector('tbody');
                    const index = Array.from(th.parentNode.children).indexOf(th);
                    const asc = th.dataset.sorted !== 'asc';
                    table.querySelectorAll('th').forEach(h => delete h.dataset.sorted);
                    th.dataset.sorted = asc ? 'asc' : 'desc';

                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    rows.sort((a, b) => {
                        const cellA = a.children[index], cellB = b.children[index];
                        const rawA = cellA.dataset.sort !== undefined ? cellA.dataset.sort : cellA.textContent.trim();
                        const rawB = cellB.dataset.sort !== undefined ? cellB.dataset.sort : cellB.textContent.trim();
                        const numA = parseFloat(rawA), numB = parseFloat(rawB);
                        let cmp;
                        if (!isNaN(numA) && !isNaN(numB)) {
                            cmp = numA - numB;
                        } else {
                            cmp = String(rawA).localeCompare(String(rawB));
                        }
                        return asc ? cmp : -cmp;
                    });
                    rows.forEach(r => tbody.appendChild(r));
                });
            });

            // Email autocomplete for the grant form (reuses the search_users AJAX handler)
            document.getElementById('grant_user_email').addEventListener('input', function() {
                const query = this.value;
                const suggestions = document.getElementById('grant-email-suggestions');
                if (query.length < 2) {
                    suggestions.style.display = 'none';
                    return;
                }
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=search_users&query=' + encodeURIComponent(query) + '&search_type=email&nonce=' + '<?php echo wp_create_nonce('search_users'); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        suggestions.innerHTML = data.data.map(user =>
                            `<div class="user-suggestion" onclick="selectGrantUser('${user.email}')" style="padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;">
                                <strong>${user.name}</strong><br><small>${user.email}</small>
                            </div>`
                        ).join('');
                        suggestions.style.display = 'block';
                    } else {
                        suggestions.style.display = 'none';
                    }
                });
            });

            function selectGrantUser(email) {
                document.getElementById('grant_user_email').value = email;
                document.getElementById('grant-email-suggestions').style.display = 'none';
            }

            document.addEventListener('click', function(e) {
                if (!e.target.closest('#grant_user_email') && !e.target.closest('#grant-email-suggestions')) {
                    document.getElementById('grant-email-suggestions').style.display = 'none';
                }
            });

            // Life memberships never expire — auto-fill the sentinel date.
            document.getElementById('grant_tier').addEventListener('change', function() {
                const isLife = this.value === '<?php echo TeamOversight_Memberships::TIER_LIFE; ?>';
                const dateInput = document.getElementById('grant_end_date');
                if (isLife) {
                    dateInput.value = '<?php echo TeamOversight_Memberships::PERMANENT_END; ?>';
                } else if (dateInput.value === '<?php echo TeamOversight_Memberships::PERMANENT_END; ?>') {
                    dateInput.value = '<?php echo date('Y-m-d', strtotime('+12 months')); ?>';
                }
                document.getElementById('grant-life-note').style.display = isLife ? '' : 'none';
            });
            </script>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Membership History
    // ------------------------------------------------------------------

    /**
     * Everyone who held a membership grant overlapping the date range.
     * Anyone whose grant touches the window counts — even a single day.
     */
    private function get_membership_history($from, $to) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT m.user_id, u.display_name, u.user_email,
                MAX(bd.meta_value) AS birth_date,
                MAX(g.meta_value) AS gender,
                MAX(mus.meta_value) AS mus_category,
                GROUP_CONCAT(CONCAT(m.tier, '|', m.start_date, '|', m.end_date, '|', m.source) ORDER BY m.start_date SEPARATOR ',') AS grants
            FROM {$wpdb->prefix}team_memberships m
            LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
            LEFT JOIN {$wpdb->usermeta} bd ON bd.user_id = m.user_id AND bd.meta_key = 'birth_date'
            LEFT JOIN {$wpdb->usermeta} g ON g.user_id = m.user_id AND g.meta_key = 'gender'
            LEFT JOIN {$wpdb->usermeta} mus ON mus.user_id = m.user_id AND mus.meta_key = 'MUSEligibilityCategory'
            WHERE m.start_date <= %s AND m.end_date >= %s
            GROUP BY m.user_id
            ORDER BY u.display_name
        ", $to, $from));

        $tiers = TeamOversight_Memberships::get_tiers();
        $rank = array(
            TeamOversight_Memberships::TIER_LIFE => 3,
            TeamOversight_Memberships::TIER_FULL => 2,
            TeamOversight_Memberships::TIER_ASSOCIATE => 1,
        );
        $today = current_time('Y-m-d');

        $history = array();
        foreach ($rows as $row) {
            $highest = null;
            $current = null;
            $periods = array();

            foreach (explode(',', $row->grants) as $grant) {
                $parts = explode('|', $grant);
                if (count($parts) !== 4 || !isset($tiers[$parts[0]])) {
                    continue;
                }
                list($tier, $start, $end, $source) = $parts;

                if ($highest === null || $rank[$tier] > $rank[$highest]) {
                    $highest = $tier;
                }
                if ($start <= $today && $end >= $today && ($current === null || $rank[$tier] > $rank[$current])) {
                    $current = $tier;
                }

                $end_display = ($end >= TeamOversight_Memberships::PERMANENT_FROM) ? 'no expiry' : $end;
                $periods[] = $tiers[$tier] . ': ' . $start . ' → ' . $end_display . ' (' . $source . ')';
            }

            if ($highest === null) {
                continue;
            }

            // Age at the end of the reporting range.
            $age = '';
            if ($row->birth_date) {
                $birth_ts = strtotime(str_replace('/', '-', $row->birth_date));
                if ($birth_ts) {
                    $age = (new DateTime('@' . $birth_ts))->diff(new DateTime($to))->y;
                }
            }

            $gender = $row->gender ? maybe_unserialize($row->gender) : '';
            if (is_array($gender)) {
                $gender = reset($gender);
            }

            $history[] = array(
                'user_id' => intval($row->user_id),
                'name' => $row->display_name ?: ($row->user_email ?: 'deleted user #' . $row->user_id),
                'email' => $row->user_email ?: '',
                'age' => $age,
                'gender' => is_string($gender) ? $gender : '',
                'mus_category' => $row->mus_category ?: '',
                'highest_tier' => $highest,
                'highest_label' => $tiers[$highest],
                'current_label' => $current ? $tiers[$current] : 'None',
                'periods' => $periods,
            );
        }

        return $history;
    }

    private function get_history_range() {
        $from = (isset($_REQUEST['history_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['history_from']))
            ? $_REQUEST['history_from'] : date('Y') . '-01-01';
        $to = (isset($_REQUEST['history_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['history_to']))
            ? $_REQUEST['history_to'] : current_time('Y-m-d');
        if ($from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }
        return array($from, $to);
    }

    public function render_history_page() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($tab === 'mus-matrix') {
            $this->render_mus_matrix_page();
            return;
        }
        if ($tab === 'vv-report') {
            $this->render_vv_report_page();
            return;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'export_membership_history_csv') {
            $this->action_export_history_csv();
        }

        list($from, $to) = $this->get_history_range();
        $history = $this->get_membership_history($from, $to);

        $tier_counts = array();
        foreach ($history as $h) {
            $tier_counts[$h['highest_label']] = isset($tier_counts[$h['highest_label']]) ? $tier_counts[$h['highest_label']] + 1 : 1;
        }

        ?>
        <div class="wrap">
            <h1>Membership History</h1>
            <?php $this->render_history_tabs('history'); ?>
            <p class="description">Everyone who held a membership at any point in the selected period, based on dated membership grants. Members whose tier role predates the grant ledger (stop-gap assignments) only appear once purchase-history seeding has been run.</p>

            <form method="get" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; display: inline-block;">
                <input type="hidden" name="page" value="club-membership-history">
                <label>Members between
                    <input type="date" name="history_from" value="<?php echo esc_attr($from); ?>">
                </label>
                <label> and
                    <input type="date" name="history_to" value="<?php echo esc_attr($to); ?>">
                </label>
                <input type="submit" class="button button-primary" value="Show">
            </form>

            <form method="post" style="display: inline-block; margin-left: 10px;">
                <input type="hidden" name="action" value="export_membership_history_csv">
                <input type="hidden" name="history_from" value="<?php echo esc_attr($from); ?>">
                <input type="hidden" name="history_to" value="<?php echo esc_attr($to); ?>">
                <?php wp_nonce_field('export_membership_history_csv', 'members_nonce'); ?>
                <input type="submit" class="button" value="Export CSV">
            </form>

            <p>
                <strong><?php echo count($history); ?></strong> people held a membership between <?php echo esc_html($from); ?> and <?php echo esc_html($to); ?>
                <?php if (!empty($tier_counts)): ?>
                    (<?php
                    $parts = array();
                    foreach ($tier_counts as $label => $count) {
                        $parts[] = $count . ' ' . $label;
                    }
                    echo esc_html(implode(', ', $parts));
                    ?> at highest)
                <?php endif; ?>
            </p>

            <?php if (!empty($history)): ?>
                <div style="margin: 10px 0;">
                    <label for="history-search">Search:</label>
                    <input type="text" id="history-search" placeholder="Search name or email..." style="width: 220px;">
                    <label for="history-tier" style="margin-left: 10px;">Tier held:</label>
                    <select id="history-tier">
                        <option value="">All</option>
                        <?php foreach (TeamOversight_Memberships::get_tiers() as $slug => $label): ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <table class="wp-list-table widefat fixed striped" id="history-table">
                    <thead>
                        <tr>
                            <th style="width: 14%;">Name</th>
                            <th style="width: 16%;">Email</th>
                            <th style="width: 4%;">Age</th>
                            <th style="width: 7%;">Gender</th>
                            <th style="width: 11%;">MUS Category</th>
                            <th style="width: 11%;">Highest Tier (period)</th>
                            <th>Membership Periods</th>
                            <th style="width: 10%;">Current Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr data-tier="<?php echo esc_attr($h['highest_tier']); ?>">
                                <td>
                                    <?php if ($h['email']): ?>
                                        <a href="<?php echo esc_url(get_edit_user_link($h['user_id'])); ?>"><?php echo esc_html($h['name']); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($h['name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($h['email']); ?></td>
                                <td><?php echo esc_html($h['age']); ?></td>
                                <td><?php echo esc_html($h['gender']); ?></td>
                                <td style="font-size: 11px;" title="<?php echo esc_attr($h['mus_category']); ?>"><?php echo esc_html($h['mus_category']); ?></td>
                                <td><?php echo esc_html($h['highest_label']); ?></td>
                                <td style="font-size: 12px;"><?php echo esc_html(implode('; ', $h['periods'])); ?></td>
                                <td><?php echo esc_html($h['current_label']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script>
                function filterHistory() {
                    const search = document.getElementById('history-search').value.toLowerCase();
                    const tier = document.getElementById('history-tier').value;
                    document.querySelectorAll('#history-table tbody tr').forEach(row => {
                        const matchesSearch = search === '' || row.textContent.toLowerCase().includes(search);
                        const matchesTier = tier === '' || row.dataset.tier === tier;
                        row.style.display = matchesSearch && matchesTier ? '' : 'none';
                    });
                }
                document.getElementById('history-search').addEventListener('input', filterHistory);
                document.getElementById('history-tier').addEventListener('change', filterHistory);
                </script>
            <?php else: ?>
                <p>No membership grants overlap this period.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function action_export_history_csv() {
        if (!isset($_POST['members_nonce']) || !wp_verify_nonce($_POST['members_nonce'], 'export_membership_history_csv')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        list($from, $to) = $this->get_history_range();
        $history = $this->get_membership_history($from, $to);

        $filename = "membership_history_{$from}_to_{$to}.csv";

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array('Name', 'Email', 'Age (at range end)', 'Gender', 'MUS Category', 'Highest Tier In Period', 'Membership Periods', 'Current Status'));

        foreach ($history as $h) {
            fputcsv($output, array(
                $h['name'],
                $h['email'],
                $h['age'],
                $h['gender'],
                $h['mus_category'],
                $h['highest_label'],
                implode('; ', $h['periods']),
                $h['current_label'],
            ));
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // MUS matrix (Membership History → MUS Matrix tab)
    // ------------------------------------------------------------------

    private function render_history_tabs($active) {
        $base = admin_url('admin.php?page=club-membership-history');
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom: 15px;">
            <a href="<?php echo esc_url($base); ?>" class="nav-tab <?php echo $active === 'history' ? 'nav-tab-active' : ''; ?>">History</a>
            <a href="<?php echo esc_url($base . '&tab=mus-matrix'); ?>" class="nav-tab <?php echo $active === 'mus-matrix' ? 'nav-tab-active' : ''; ?>">MUS Matrix</a>
            <a href="<?php echo esc_url($base . '&tab=vv-report'); ?>" class="nav-tab <?php echo $active === 'vv-report' ? 'nav-tab-active' : ''; ?>">VV Report</a>
        </nav>
        <?php
    }

    /**
     * Fold the raw MUSEligibilityCategory profile values into the row labels
     * MUS reporting uses. Unrecognised values keep their raw label so new
     * profile options are never silently lumped together.
     */
    private function mus_matrix_row_label($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 'Unknown';
        }

        $map = array(
            'Melbourne University - Undergrad. Student' => 'Melbourne University - Undergrad. Student',
            'Melbourne University - Postgrad. Student' => 'Melbourne University - Postgrad. Student',
            'Melbourne University - Alumni' => 'Melbourne University - Alumni',
            'Melbourne University - Staff' => 'Melbourne University - Staff',
            'Melbourne University - Unknown' => 'Melbourne University - Unknown',
            'Student of another university' => 'Student / Alumni of another university',
            'Alumni of another university' => 'Student / Alumni of another university',
            'Student/Alumni of another university' => 'Student / Alumni of another university',
            'Student / Alumni of another university' => 'Student / Alumni of another university',
            'Junior (U/19) - High school or below' => 'Other',
            'Adult - High School Only' => 'Other',
            'Other' => 'Other',
        );
        return isset($map[$raw]) ? $map[$raw] : $raw;
    }

    private function mus_matrix_gender_col($gender) {
        $g = strtolower(trim((string) $gender));
        if ($g === '') {
            return 'Unknown';
        }
        if (strpos($g, 'non') !== false || strpos($g, 'binary') !== false) {
            return 'Non-Binary';
        }
        // Check female before male: 'female' contains 'male'.
        if (strpos($g, 'female') !== false || strpos($g, 'woman') !== false || $g === 'f') {
            return 'Female';
        }
        if (strpos($g, 'male') !== false || strpos($g, 'man') !== false || $g === 'm') {
            return 'Male';
        }
        return 'Unknown';
    }

    /**
     * Counts everyone holding a membership grant overlapping [$from, $to],
     * grouped MUS category × gender. Returns array(rows, gender_cols) where
     * each row = label => array('Male' => n, ..., 'Total' => n), template rows
     * always present (zeros included), unexpected categories appended, and a
     * final TOTAL row.
     */
    private function get_mus_matrix($from, $to) {
        $genders = array('Male', 'Female', 'Non-Binary', 'Unknown');
        $template_rows = array(
            'Melbourne University - Undergrad. Student',
            'Melbourne University - Postgrad. Student',
            'Melbourne University - Alumni',
            'Melbourne University - Staff',
            'Student / Alumni of another university',
            'Other',
            'Melbourne University - Unknown',
            'Unknown',
        );

        $empty = array_fill_keys($genders, 0);
        $empty['Total'] = 0;

        $rows = array_fill_keys($template_rows, $empty);
        foreach ($this->get_membership_history($from, $to) as $person) {
            $row = $this->mus_matrix_row_label($person['mus_category']);
            $col = $this->mus_matrix_gender_col($person['gender']);
            if (!isset($rows[$row])) {
                $rows[$row] = $empty;
            }
            $rows[$row][$col]++;
            $rows[$row]['Total']++;
        }

        $total = $empty;
        foreach ($rows as $counts) {
            foreach (array_merge($genders, array('Total')) as $key) {
                $total[$key] += $counts[$key];
            }
        }
        $rows['TOTAL'] = $total;

        return array($rows, $genders);
    }

    private function render_mus_matrix_page() {
        if (isset($_POST['action']) && $_POST['action'] === 'export_mus_matrix_csv') {
            $this->action_export_mus_matrix_csv();
        }

        list($from, $to) = $this->get_history_range();
        list($rows, $genders) = $this->get_mus_matrix($from, $to);

        ?>
        <div class="wrap">
            <h1>Membership History</h1>
            <?php $this->render_history_tabs('mus-matrix'); ?>

            <p class="description">Active members for MUS reporting: everyone who held a membership (any tier, from the grants ledger) during the selected period, grouped by MUS eligibility category and gender. "Student / Alumni of another university" folds together the student/alumni profile variants; junior and high-school categories count as "Other"; people with no category or gender on their profile count as "Unknown".</p>

            <form method="get" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; display: inline-block;">
                <input type="hidden" name="page" value="club-membership-history">
                <input type="hidden" name="tab" value="mus-matrix">
                <label>Members between
                    <input type="date" name="history_from" value="<?php echo esc_attr($from); ?>">
                </label>
                <label> and
                    <input type="date" name="history_to" value="<?php echo esc_attr($to); ?>">
                </label>
                <input type="submit" class="button button-primary" value="Show">
            </form>

            <form method="post" style="display: inline-block; margin-left: 10px;">
                <input type="hidden" name="action" value="export_mus_matrix_csv">
                <input type="hidden" name="history_from" value="<?php echo esc_attr($from); ?>">
                <input type="hidden" name="history_to" value="<?php echo esc_attr($to); ?>">
                <?php wp_nonce_field('export_mus_matrix_csv', 'members_nonce'); ?>
                <input type="submit" class="button" value="Export CSV">
            </form>

            <table class="wp-list-table widefat fixed striped" style="max-width: 900px; margin-top: 10px;">
                <thead>
                    <tr>
                        <th>MUS Category</th>
                        <?php foreach ($genders as $g): ?>
                            <th style="width: 11%; text-align: right;"><?php echo esc_html($g); ?></th>
                        <?php endforeach; ?>
                        <th style="width: 11%; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $label => $counts): ?>
                        <tr<?php echo $label === 'TOTAL' ? ' style="font-weight: 600; border-top: 2px solid #333;"' : ''; ?>>
                            <td><?php echo esc_html($label); ?></td>
                            <?php foreach ($genders as $g): ?>
                                <td style="text-align: right;"><?php echo intval($counts[$g]); ?></td>
                            <?php endforeach; ?>
                            <td style="text-align: right;"><strong><?php echo intval($counts['Total']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function action_export_mus_matrix_csv() {
        if (!isset($_POST['members_nonce']) || !wp_verify_nonce($_POST['members_nonce'], 'export_mus_matrix_csv')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        list($from, $to) = $this->get_history_range();
        list($rows, $genders) = $this->get_mus_matrix($from, $to);

        $filename = "mus_matrix_{$from}_to_{$to}.csv";

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_merge(array('MUS Category'), $genders, array('Total')));

        foreach ($rows as $label => $counts) {
            $line = array($label);
            foreach ($genders as $g) {
                $line[] = $counts[$g];
            }
            $line[] = $counts['Total'];
            fputcsv($output, $line);
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // VV report (Membership History → VV Report tab)
    // ------------------------------------------------------------------

    /**
     * Which membership tiers the VV report includes. Defaults to associate
     * only (the usual VV return) unless the form was submitted with other
     * boxes ticked.
     */
    private function get_vv_selected_tiers() {
        if (empty($_REQUEST['vv_tiers_set'])) {
            return array(TeamOversight_Memberships::TIER_ASSOCIATE);
        }
        $valid = array_keys(TeamOversight_Memberships::get_tiers());
        $picked = isset($_REQUEST['vv_tiers']) ? (array) $_REQUEST['vv_tiers'] : array();
        return array_values(array_intersect($valid, array_map('sanitize_text_field', $picked)));
    }

    private function vv_gender_col($gender) {
        $g = strtolower(trim((string) $gender));
        if ($g === '') {
            return 'Unknown gender';
        }
        if (strpos($g, 'non') !== false || strpos($g, 'binary') !== false || strpos($g, 'other') !== false) {
            return 'Other';
        }
        // Check female before male: 'female' contains 'male'.
        if (strpos($g, 'female') !== false || strpos($g, 'woman') !== false || $g === 'f') {
            return 'Female';
        }
        if (strpos($g, 'male') !== false || strpos($g, 'man') !== false || $g === 'm') {
            return 'Male';
        }
        return 'Unknown gender';
    }

    /**
     * Age-band × gender counts for everyone whose HIGHEST tier held in
     * [$from, $to] is one of $tiers (so tier selections never double-count
     * a person). Age is taken at the end of the range; missing/invalid DOB
     * lands in the "Unknown DOB" row. The 10–79 template bands always show;
     * other bands (0 to 9, 80 to 89, ...) appear only when occupied.
     */
    private function get_vv_matrix($from, $to, $tiers) {
        $genders = array('Male', 'Female', 'Other', 'Unknown gender');
        $empty = array_fill_keys($genders, 0);
        $empty['Total'] = 0;

        $bands = array();
        foreach (range(10, 70, 10) as $decade) {
            $bands[$decade] = $empty;
        }
        $unknown_dob = $empty;

        foreach ($this->get_membership_history($from, $to) as $person) {
            if (!in_array($person['highest_tier'], $tiers, true)) {
                continue;
            }
            $col = $this->vv_gender_col($person['gender']);
            if ($person['age'] === '' || intval($person['age']) < 0) {
                $unknown_dob[$col]++;
                $unknown_dob['Total']++;
                continue;
            }
            $decade = intval(floor(intval($person['age']) / 10) * 10);
            if (!isset($bands[$decade])) {
                $bands[$decade] = $empty;
            }
            $bands[$decade][$col]++;
            $bands[$decade]['Total']++;
        }
        ksort($bands);

        $rows = array();
        foreach ($bands as $decade => $counts) {
            $rows[$decade . ' to ' . ($decade + 9)] = $counts;
        }
        $rows['Unknown DOB'] = $unknown_dob;

        $total = $empty;
        foreach ($rows as $counts) {
            foreach (array_merge($genders, array('Total')) as $key) {
                $total[$key] += $counts[$key];
            }
        }
        $rows['Total'] = $total;

        return array($rows, $genders);
    }

    private function render_vv_report_page() {
        if (isset($_POST['action']) && $_POST['action'] === 'export_vv_report_csv') {
            $this->action_export_vv_report_csv();
        }

        list($from, $to) = $this->get_history_range();
        $selected = $this->get_vv_selected_tiers();
        list($rows, $genders) = $this->get_vv_matrix($from, $to, $selected);
        $tiers = TeamOversight_Memberships::get_tiers();

        ?>
        <div class="wrap">
            <h1>Membership History</h1>
            <?php $this->render_history_tabs('vv-report'); ?>

            <p class="description">VV return: members counted by age band and gender. A person counts once, under the <em>highest</em> tier they held during the selected period — so ticking Associate only excludes anyone who was also a Full or Life member in the period. Age is at the end of the period; missing date of birth lands in "Unknown DOB".</p>

            <form method="get" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; display: inline-block;">
                <input type="hidden" name="page" value="club-membership-history">
                <input type="hidden" name="tab" value="vv-report">
                <input type="hidden" name="vv_tiers_set" value="1">
                <label>Members between
                    <input type="date" name="history_from" value="<?php echo esc_attr($from); ?>">
                </label>
                <label> and
                    <input type="date" name="history_to" value="<?php echo esc_attr($to); ?>">
                </label>
                <span style="margin-left: 15px;">Include:</span>
                <?php foreach ($tiers as $slug => $label): ?>
                    <label style="margin-left: 8px;">
                        <input type="checkbox" name="vv_tiers[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $selected, true)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
                <input type="submit" class="button button-primary" value="Show" style="margin-left: 15px;">
            </form>

            <form method="post" style="display: inline-block; margin-left: 10px;">
                <input type="hidden" name="action" value="export_vv_report_csv">
                <input type="hidden" name="history_from" value="<?php echo esc_attr($from); ?>">
                <input type="hidden" name="history_to" value="<?php echo esc_attr($to); ?>">
                <input type="hidden" name="vv_tiers_set" value="1">
                <?php foreach ($selected as $slug): ?>
                    <input type="hidden" name="vv_tiers[]" value="<?php echo esc_attr($slug); ?>">
                <?php endforeach; ?>
                <?php wp_nonce_field('export_vv_report_csv', 'members_nonce'); ?>
                <input type="submit" class="button" value="Export CSV">
            </form>

            <p><strong><?php echo intval($rows['Total']['Total']); ?></strong> people counted
                (<?php echo $selected ? esc_html(implode(', ', array_intersect_key($tiers, array_flip($selected)))) : 'no tiers selected'; ?>,
                <?php echo esc_html($from); ?> to <?php echo esc_html($to); ?>).</p>

            <table class="wp-list-table widefat fixed striped" style="max-width: 750px; margin-top: 10px;">
                <thead>
                    <tr>
                        <th>Age band</th>
                        <?php foreach ($genders as $g): ?>
                            <th style="width: 14%; text-align: right;"><?php echo esc_html($g); ?></th>
                        <?php endforeach; ?>
                        <th style="width: 14%; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $label => $counts): ?>
                        <tr<?php echo $label === 'Total' ? ' style="font-weight: 600; border-top: 2px solid #333;"' : ''; ?>>
                            <td><?php echo esc_html($label); ?></td>
                            <?php foreach ($genders as $g): ?>
                                <td style="text-align: right;"><?php echo intval($counts[$g]); ?></td>
                            <?php endforeach; ?>
                            <td style="text-align: right;"><strong><?php echo intval($counts['Total']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function action_export_vv_report_csv() {
        if (!isset($_POST['members_nonce']) || !wp_verify_nonce($_POST['members_nonce'], 'export_vv_report_csv')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        list($from, $to) = $this->get_history_range();
        $selected = $this->get_vv_selected_tiers();
        list($rows, $genders) = $this->get_vv_matrix($from, $to, $selected);

        $filename = "vv_report_{$from}_to_{$to}.csv";

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_merge(array('Age band'), $genders, array('Total')));

        foreach ($rows as $label => $counts) {
            $line = array($label);
            foreach ($genders as $g) {
                $line[] = $counts[$g];
            }
            $line[] = $counts['Total'];
            fputcsv($output, $line);
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // POST actions
    // ------------------------------------------------------------------

    private function handle_action() {
        $nonce_actions = array(
            'grant_membership' => 'grant_membership',
            'revoke_membership' => 'revoke_membership',
            'seed_memberships_dry' => 'seed_memberships',
            'seed_memberships_apply' => 'seed_memberships',
            'rescan_orders' => 'rescan_orders',
            'save_membership_rules' => 'save_membership_rules',
            'export_members_csv' => 'export_members_csv',
        );

        $action = $_POST['action'];
        if (!isset($nonce_actions[$action])) {
            return;
        }

        if (!isset($_POST['members_nonce']) || !wp_verify_nonce($_POST['members_nonce'], $nonce_actions[$action])) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }

        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        switch ($action) {
            case 'grant_membership':
                $this->action_grant();
                break;
            case 'revoke_membership':
                $this->action_revoke();
                break;
            case 'seed_memberships_dry':
                $this->action_seed(false);
                break;
            case 'seed_memberships_apply':
                $this->action_seed(true);
                break;
            case 'rescan_orders':
                $this->action_rescan();
                break;
            case 'save_membership_rules':
                $this->action_save_rules();
                break;
            case 'export_members_csv':
                $this->action_export_csv();
                break;
        }
    }

    private function action_grant() {
        $email = sanitize_email($_POST['grant_user_email']);
        $tier = sanitize_text_field($_POST['grant_tier']);
        $end_date = sanitize_text_field($_POST['grant_end_date']);
        $note = sanitize_text_field($_POST['grant_note']);

        $user = get_user_by('email', $email);
        if (!$user) {
            echo '<div class="notice notice-error"><p>No user found with email ' . esc_html($email) . '.</p></div>';
            return;
        }
        if (!array_key_exists($tier, TeamOversight_Memberships::get_tiers())) {
            echo '<div class="notice notice-error"><p>Invalid tier.</p></div>';
            return;
        }
        if (!$end_date || strtotime($end_date) < strtotime('today')) {
            echo '<div class="notice notice-error"><p>Valid-until date must be today or later.</p></div>';
            return;
        }

        if ($this->memberships->grant_manual($user->ID, $tier, $end_date, $note)) {
            $tiers = TeamOversight_Memberships::get_tiers();
            echo '<div class="notice notice-success"><p>Granted ' . esc_html($tiers[$tier]) . ' to ' . esc_html($user->display_name) . ' until ' . esc_html($end_date) . '.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to save the membership grant.</p></div>';
        }
    }

    private function action_revoke() {
        $user_id = intval($_POST['revoke_user_id']);
        $user = get_userdata($user_id);
        if (!$user) {
            echo '<div class="notice notice-error"><p>User not found.</p></div>';
            return;
        }

        $this->memberships->revoke_all($user_id);
        echo '<div class="notice notice-success"><p>Membership revoked for ' . esc_html($user->display_name) . '.</p></div>';
    }

    private function action_seed($apply) {
        $report = $this->memberships->seed_from_purchases($apply);

        echo '<div class="notice notice-' . ($apply ? 'success' : 'info') . '"><p>';
        echo '<strong>Seeding ' . ($apply ? 'applied' : 'dry run') . ' (' . esc_html($report['year']) . '):</strong> ';
        echo intval($report['full']) . ' full grants, ' . intval($report['associate']) . ' associate grants across ' . intval($report['affected_users']) . ' people';
        if ($report['skipped_existing'] > 0) {
            echo ' (' . intval($report['skipped_existing']) . ' already-seeded purchases skipped)';
        }
        echo '.';
        if (!empty($report['sample'])) {
            echo '<br><small>Sample:<br>' . implode('<br>', array_map('esc_html', $report['sample'])) . '</small>';
        }
        echo '</p></div>';
    }

    private function action_rescan() {
        $report = $this->memberships->rescan_paid_orders();

        echo '<div class="notice notice-success"><p>';
        echo '<strong>Order re-scan (' . esc_html($report['year']) . '):</strong> ';
        echo intval($report['orders_checked']) . ' paid orders checked, ' . intval($report['grants_created']) . ' new membership grants created.';
        if (intval($report['grants_created']) === 0) {
            echo ' Every qualifying purchase already has its grant.';
        }
        echo '</p></div>';
    }

    private function action_save_rules() {
        $clean = array();
        if (!empty($_POST['rules']) && is_array($_POST['rules'])) {
            foreach ($_POST['rules'] as $term_id => $rule) {
                $tier = isset($rule['tier']) ? sanitize_text_field($rule['tier']) : '';
                $months = isset($rule['months']) ? intval($rule['months']) : 0;
                if ($tier && $months > 0 && array_key_exists($tier, TeamOversight_Memberships::get_tiers())) {
                    $clean[intval($term_id)] = array('tier' => $tier, 'months' => $months);
                }
            }
        }

        update_option(TeamOversight_Memberships::CATEGORY_RULES_OPTION, $clean);
        echo '<div class="notice notice-success"><p>Membership category rules saved (' . count($clean) . ' active rules).</p></div>';
    }

    private function action_export_csv() {
        $season = preg_match('/^\d{4}$/', $_POST['export_season']) ? $_POST['export_season'] : date('Y');
        $show_all = !empty($_POST['export_show_all']);
        $members = $this->get_members($season, $show_all);

        $filename = "members_{$season}_" . date('Y-m-d') . ".csv";

        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array(
            'Name', 'Email', 'Mobile', 'Age', 'Gender', 'MUS Category',
            'Membership', 'Membership Until', 'VVL Teams', 'Invoiced', 'Outstanding',
            'VA Accreditation', 'Profile Confirmed'
        ));

        foreach ($members as $m) {
            fputcsv($output, array(
                $m['name'],
                $m['email'],
                $m['mobile'],
                $m['age'],
                $m['gender'],
                $m['mus_category'],
                $m['status_label'] . ($m['role_only'] ? ' (role only)' : ''),
                $m['status_until'],
                $m['team_list'],
                $m['invoiced'] > 0 ? number_format($m['invoiced'], 2, '.', '') : '',
                $m['invoiced'] > 0 ? number_format($m['outstanding'], 2, '.', '') : '',
                $m['accreditation'],
                $m['confirmed'] ? 'Yes' : 'No',
            ));
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    private function get_available_seasons() {
        global $wpdb;

        $seasons = $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments ORDER BY season DESC");
        $current = date('Y');
        if (!in_array($current, $seasons)) {
            $seasons[] = $current;
        }
        rsort($seasons);
        return $seasons;
    }

    private function get_members($season, $show_all) {
        global $wpdb;

        $caps_key = $wpdb->prefix . 'capabilities';

        $where = '';
        $params = array($season, $season);
        if (!$show_all) {
            $where = "
                WHERE caps.meta_value LIKE %s
                    OR caps.meta_value LIKE %s
                    OR caps.meta_value LIKE %s
                    OR mem.user_id IS NOT NULL
                    OR teams.email IS NOT NULL
            ";
            $params[] = '%"life-member"%';
            $params[] = '%"full-member"%';
            $params[] = '%"associate-member"%';
        }

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT u.ID, u.user_email, u.display_name,
                caps.meta_value AS caps,
                bd.meta_value AS birth_date,
                mob.meta_value AS mobile,
                g.meta_value AS gender,
                mus.meta_value AS mus_category,
                pcy.meta_value AS confirmed_year,
                mem.grants AS grants,
                teams.team_list AS team_list,
                teams.team_codes AS team_codes,
                inv.invoiced AS invoiced,
                inv.outstanding AS outstanding,
                acc.accreditation_list AS accreditation
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} caps ON caps.user_id = u.ID AND caps.meta_key = '{$caps_key}'
            LEFT JOIN {$wpdb->usermeta} bd ON bd.user_id = u.ID AND bd.meta_key = 'birth_date'
            LEFT JOIN {$wpdb->usermeta} mob ON mob.user_id = u.ID AND mob.meta_key = 'mobile_number'
            LEFT JOIN {$wpdb->usermeta} g ON g.user_id = u.ID AND g.meta_key = 'gender'
            LEFT JOIN {$wpdb->usermeta} mus ON mus.user_id = u.ID AND mus.meta_key = 'MUSEligibilityCategory'
            LEFT JOIN {$wpdb->usermeta} pcy ON pcy.user_id = u.ID AND pcy.meta_key = 'profile_confirmed_year'
            LEFT JOIN (
                SELECT user_id, GROUP_CONCAT(CONCAT(tier, ':', end_date) ORDER BY end_date DESC SEPARATOR ',') AS grants
                FROM {$wpdb->prefix}team_memberships
                WHERE start_date <= CURDATE() AND end_date >= CURDATE()
                GROUP BY user_id
            ) mem ON mem.user_id = u.ID
            LEFT JOIN (
                SELECT email, GROUP_CONCAT(DISTINCT team ORDER BY team SEPARATOR '|') AS team_codes,
                    GROUP_CONCAT(DISTINCT CONCAT(team, ' (', role, ')') ORDER BY team SEPARATOR ', ') AS team_list
                FROM {$wpdb->prefix}team_assignments
                WHERE season = %s AND is_active = 1
                GROUP BY email
            ) teams ON teams.email = u.user_email
            LEFT JOIN (
                SELECT email, SUM(invoice_amount) AS invoiced, SUM(outstanding_amount) AS outstanding
                FROM {$wpdb->prefix}team_invoices
                WHERE season = %s
                GROUP BY email
            ) inv ON inv.email = u.user_email
            LEFT JOIN (
                SELECT email, MAX(accreditation_list) AS accreditation_list
                FROM {$wpdb->prefix}team_accreditations
                GROUP BY email
            ) acc ON acc.email = u.user_email
            {$where}
            ORDER BY u.display_name
        ", ...$params));

        $tiers = TeamOversight_Memberships::get_tiers();
        $members = array();

        foreach ($rows as $row) {
            $roles = array();
            if ($row->caps) {
                $caps = maybe_unserialize($row->caps);
                if (is_array($caps)) {
                    $roles = array_keys(array_filter($caps));
                }
            }

            // Ledger status: highest active tier + its latest expiry.
            $ledger_tier = null;
            $ledger_until = '';
            if ($row->grants) {
                $active = array();
                foreach (explode(',', $row->grants) as $grant) {
                    $parts = explode(':', $grant);
                    if (count($parts) === 2 && isset($tiers[$parts[0]])) {
                        if (!isset($active[$parts[0]]) || $parts[1] > $active[$parts[0]]) {
                            $active[$parts[0]] = $parts[1];
                        }
                    }
                }
                if (isset($active[TeamOversight_Memberships::TIER_LIFE])) {
                    $ledger_tier = TeamOversight_Memberships::TIER_LIFE;
                } elseif (isset($active[TeamOversight_Memberships::TIER_FULL])) {
                    $ledger_tier = TeamOversight_Memberships::TIER_FULL;
                } elseif (isset($active[TeamOversight_Memberships::TIER_ASSOCIATE])) {
                    $ledger_tier = TeamOversight_Memberships::TIER_ASSOCIATE;
                }
                if ($ledger_tier) {
                    $ledger_until = $active[$ledger_tier];
                    // Life-member sentinel dates display as "no expiry".
                    if ($ledger_until >= TeamOversight_Memberships::PERMANENT_FROM) {
                        $ledger_until = '';
                    }
                }
            }

            // Fall back to role-only status (stop-gap assignments with no ledger row).
            $role_tier = null;
            if (in_array(TeamOversight_Memberships::TIER_LIFE, $roles, true)) {
                $role_tier = TeamOversight_Memberships::TIER_LIFE;
            } elseif (in_array(TeamOversight_Memberships::TIER_FULL, $roles, true)) {
                $role_tier = TeamOversight_Memberships::TIER_FULL;
            } elseif (in_array(TeamOversight_Memberships::TIER_ASSOCIATE, $roles, true)) {
                $role_tier = TeamOversight_Memberships::TIER_ASSOCIATE;
            }

            $effective_tier = $ledger_tier ? $ledger_tier : $role_tier;
            $role_only = (!$ledger_tier && $role_tier);

            $status_key = 'none';
            $status_sort = 0;
            if ($effective_tier === TeamOversight_Memberships::TIER_LIFE) {
                $status_key = 'life';
                $status_sort = 3;
            } elseif ($effective_tier === TeamOversight_Memberships::TIER_FULL) {
                $status_key = 'full';
                $status_sort = 2;
            } elseif ($effective_tier === TeamOversight_Memberships::TIER_ASSOCIATE) {
                $status_key = 'associate';
                $status_sort = 1;
            }

            $age = '';
            if ($row->birth_date) {
                $birth = strtotime(str_replace('/', '-', $row->birth_date));
                if ($birth) {
                    $age = (new DateTime())->diff(new DateTime('@' . $birth))->y;
                }
            }

            $gender = $row->gender ? maybe_unserialize($row->gender) : '';
            if (is_array($gender)) {
                $gender = reset($gender);
            }

            $mus = $row->mus_category ?: '';
            $mus_short = str_replace(array('Melbourne University - ', 'Student / Alumni of another university'), array('MU ', 'Other Uni'), $mus);

            $members[] = array(
                'user_id' => intval($row->ID),
                'name' => $row->display_name ?: $row->user_email,
                'email' => $row->user_email,
                'mobile' => $row->mobile ?: '',
                'age' => $age,
                'gender' => is_string($gender) ? $gender : '',
                'mus_category' => $mus,
                'mus_short' => $mus_short,
                'status_key' => $status_key,
                'status_sort' => $status_sort,
                'status_label' => $effective_tier ? $tiers[$effective_tier] : '—',
                'status_until' => $ledger_until,
                'status_detail' => $row->grants ? str_replace(',', ', ', $row->grants) : ($role_only ? 'Role assigned by stop-gap snippet; run seeding to backfill expiry dates' : ''),
                'role_only' => $role_only,
                'has_active_grant' => (bool) $ledger_tier,
                'team_list' => $row->team_list ?: '',
                'team_codes' => $row->team_codes ?: '',
                'invoiced' => floatval($row->invoiced),
                'outstanding' => floatval($row->outstanding),
                'accreditation' => $row->accreditation ?: '',
                'confirmed' => ($row->confirmed_year == date('Y')),
            );
        }

        return $members;
    }
}
