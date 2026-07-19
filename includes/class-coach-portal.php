<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end portal for coaches: [team_coach_portal]
 *
 * Visible only to logged-in users holding an active coach or assistant
 * coach team assignment. Coaches work one team at a time (switcher for
 * multi-team coaches) and run their selections from here:
 *
 *  - roster for the active team, with CSV export
 *  - the full applicant pool for the team's competition (searchable)
 *  - per-team selection verdicts: Tentative / Selected / Rejected.
 *    Verdicts are per TEAM, not global — a player can be Selected by
 *    several teams (e.g. YSL + JPL) and Rejected by another, and every
 *    coach sees every team's verdicts.
 *  - shared notes on applications (visible to all coaches and admins)
 *
 * Selections are a working shortlist: converting Selected players into
 * actual team assignments + fee invoices is done by the club admin from
 * the Trial Applications page (finalisation), so fees don't fire while
 * coaches are still trading players mid-trials.
 */
class TeamOversight_Coach_Portal {

    const COACH_ROLES = "'coach', 'assistant_coach'";
    const SELECTION_STATUSES = array('tentative', 'selected', 'training_only', 'rejected');

    public static function get_verdict_labels() {
        return array(
            'tentative' => 'Tentative',
            'selected' => 'Selected',
            'training_only' => 'Training Only',
            'rejected' => 'Rejected',
        );
    }

    public function __construct() {
        add_shortcode('team_coach_portal', array($this, 'render'));
        // CSV export must run before the theme outputs anything.
        add_action('template_redirect', array($this, 'maybe_export_roster'));
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    private function get_coach_assignments($user) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT season, team, role FROM {$wpdb->prefix}team_assignments
            WHERE is_active = 1
                AND role IN (" . self::COACH_ROLES . ")
                AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
            ORDER BY season DESC, team
        ", $user->ID, $user->user_email));
    }

    /**
     * Teams coached by the current user for a season, or empty array.
     */
    private function get_my_teams($season = null) {
        if (!is_user_logged_in()) {
            return array();
        }

        $assignments = $this->get_coach_assignments(wp_get_current_user());
        $teams = array();
        foreach ($assignments as $assignment) {
            if ($season === null || $assignment->season === $season) {
                $teams[$assignment->team] = $assignment->role;
            }
        }
        return $teams;
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    public function render() {
        if (!is_user_logged_in()) {
            $login_url = function_exists('um_get_core_page')
                ? add_query_arg('redirect_to', urlencode(get_permalink()), um_get_core_page('login'))
                : wp_login_url(get_permalink());
            return '<div class="coach-portal-notice"><p><strong>Please log in to view the coach portal.</strong></p>'
                . '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p></div>';
        }

        $user = wp_get_current_user();
        $coach_assignments = $this->get_coach_assignments($user);

        if (empty($coach_assignments)) {
            return '<div class="coach-portal-notice"><p>This page is for team coaches. Your account does not have a coach or assistant coach assignment — if that\'s wrong, please contact the club.</p></div>';
        }

        // Season + active team selection, limited to what they coach.
        $seasons = array_values(array_unique(array_map(function ($a) {
            return $a->season;
        }, $coach_assignments)));

        $season = (isset($_GET['coach_season']) && in_array($_GET['coach_season'], $seasons, true))
            ? $_GET['coach_season']
            : $seasons[0];

        $my_teams = $this->get_my_teams($season);
        $my_team_codes = array_keys($my_teams);

        $active_team = (isset($_GET['coach_team']) && in_array($_GET['coach_team'], $my_team_codes, true))
            ? $_GET['coach_team']
            : $my_team_codes[0];

        // Handle selection/note submissions before building the page.
        $action_notice = $this->handle_actions($my_team_codes, $season);

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();
        $active_config = isset($teams_config[$active_team]) ? $teams_config[$active_team] : array('name' => $active_team, 'gender' => 'mixed', 'age_rule' => '');
        $roster = $this->get_roster($active_team, $season);
        $selection_roster = $this->get_selection_roster($active_team, $season, $roster);
        $applicants = $this->get_applicants_by_gender($active_config['gender'], $season, $active_team, $my_team_codes);

        $base_url = remove_query_arg(array('coach_team', 'coach_season'));

        ob_start();
        ?>
        <div class="coach-portal">
            <h2>Coach Portal</h2>
            <?php echo $action_notice; ?>

            <?php if (count($seasons) > 1): ?>
                <p>
                    Season:
                    <?php foreach ($seasons as $s): ?>
                        <?php if ($s === $season): ?>
                            <strong><?php echo esc_html($s); ?></strong>
                        <?php else: ?>
                            <a href="<?php echo esc_url(add_query_arg('coach_season', $s, $base_url)); ?>"><?php echo esc_html($s); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <?php if (count($my_team_codes) > 1): ?>
                <div class="coach-team-switcher">
                    <?php foreach ($my_team_codes as $code): ?>
                        <?php $label = isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code; ?>
                        <?php if ($code === $active_team): ?>
                            <span class="coach-team-tab active"><?php echo esc_html($label); ?></span>
                        <?php else: ?>
                            <a class="coach-team-tab" href="<?php echo esc_url(add_query_arg(array('coach_season' => $season, 'coach_team' => $code), $base_url)); ?>"><?php echo esc_html($label); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="coach-team-section">
                <h3><?php echo esc_html($active_config['name']); ?> <small>(<?php echo esc_html($season); ?> — you are <?php echo esc_html(str_replace('_', ' ', $my_teams[$active_team])); ?>)</small></h3>

                <h4>Current Team (<?php echo count($roster); ?> confirmed<?php echo count($selection_roster) ? ', ' . count($selection_roster) . ' in selection' : ''; ?>)</h4>
                <?php if (!empty($roster) || !empty($selection_roster)): ?>
                    <table class="coach-portal-table coach-roster-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Positions</th><th>Status</th><th>Email</th><th>Mobile</th></tr></thead>
                        <tbody>
                            <?php foreach ($roster as $member): ?>
                                <tr>
                                    <td data-label="Name"><?php echo esc_html($member->name ?: $member->email); ?></td>
                                    <td data-label="Role"><?php echo esc_html(str_replace('_', ' ', ucwords($member->role, '_'))); ?></td>
                                    <td data-label="Positions"><?php echo esc_html($this->format_positions($member->preferred_positions)); ?></td>
                                    <td data-label="Status"><span class="verdict-chip verdict-chip-confirmed">Confirmed</span></td>
                                    <td data-label="Email"><a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a></td>
                                    <td data-label="Mobile"><?php echo esc_html($member->mobile ?: ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($selection_roster as $member): ?>
                                <tr class="verdict-<?php echo esc_attr($member->status); ?>">
                                    <td data-label="Name"><?php echo esc_html($member->name); ?> <small>#<?php echo intval($member->trial_number); ?></small></td>
                                    <td data-label="Role"><?php echo $member->status === 'training_only' ? 'Training Only' : 'Playing Member'; ?></td>
                                    <td data-label="Positions"><?php echo esc_html($this->format_positions($member->preferred_positions)); ?></td>
                                    <td data-label="Status">
                                        <?php if ($member->status === 'selected'): ?>
                                            <span class="verdict-chip verdict-chip-selected">Selected — awaiting finalisation</span>
                                        <?php elseif ($member->status === 'training_only'): ?>
                                            <span class="verdict-chip verdict-chip-training_only">Training Only — awaiting finalisation</span>
                                        <?php else: ?>
                                            <span class="verdict-chip verdict-chip-tentative">Tentative</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Email"><a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a></td>
                                    <td data-label="Mobile"><?php echo esc_html($member->mobile ?: ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <form method="post">
                        <input type="hidden" name="coach_action" value="export_roster">
                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                        <button type="submit" class="button">Export team list (CSV)</button>
                    </form>
                <?php else: ?>
                    <p>No active members assigned yet.</p>
                <?php endif; ?>
            </div>

            <div class="coach-team-section">
                <h3>Trial Applicants — <?php echo $active_config['gender'] === 'womens' ? "Women's" : ($active_config['gender'] === 'mens' ? "Men's" : 'All'); ?> (<?php echo count($applicants); ?>)</h3>
                <p class="coach-portal-hint">Every applicant in this competition is shown — including players outside your age group or who selected other teams, since players get redirected between trials and VV can grant age exemptions. Your verdicts apply to <strong><?php echo esc_html($active_config['name']); ?></strong> only; a player can be Selected by multiple teams (e.g. YSL and JPL) and every coach sees every team's verdicts. Selected players become official (team assignment + fees) when the club finalises selections.</p>

                <p>
                    <label for="coach-search">Search:</label>
                    <input type="text" id="coach-search" placeholder="Name, email, position, team..." style="width: 240px;">
                    <label style="margin-left: 12px;"><input type="checkbox" id="coach-filter-mine"> Only my verdicts</label>
                </p>

                <?php if (!empty($applicants)): ?>
                    <?php
                    // Sections: the coach's to-do pile first, then their
                    // actioned applicants, then the rest of the competition.
                    $needs_action = array();
                    $actioned = array();
                    $others = array();
                    foreach ($applicants as $a) {
                        if ($a['picked_mine'] && !$a['my_status']) {
                            $needs_action[] = $a;
                        } elseif ($a['picked_mine']) {
                            $actioned[] = $a;
                        } else {
                            $others[] = $a;
                        }
                    }
                    $sections = array(
                        array('Applied to your team — awaiting your verdict', $needs_action, 'They selected ' . $active_config['name'] . ' on their form and you haven\'t recorded a verdict yet.'),
                        array('Applied to your team — verdict recorded', $actioned, ''),
                        array('Everyone else in this competition', $others, 'Applicants who didn\'t select your team — shown because players get redirected between trials and VV can grant age exemptions.'),
                    );
                    ?>
                    <div id="coach-applicant-list">
                        <?php foreach ($sections as $section): list($section_title, $section_items, $section_desc) = $section; ?>
                            <?php if (empty($section_items)) { continue; } ?>
                            <h4 class="coach-section-heading"><?php echo esc_html($section_title); ?> (<?php echo count($section_items); ?>)</h4>
                            <?php if ($section_desc): ?><p class="coach-portal-hint"><?php echo esc_html($section_desc); ?></p><?php endif; ?>
                            <?php foreach ($section_items as $a): ?>
                            <div class="coach-applicant-card <?php echo $a['my_status'] ? 'verdict-' . esc_attr($a['my_status']) : ''; ?>" data-has-verdict="<?php echo $a['my_status'] ? '1' : '0'; ?>">
                                <div class="cac-header">
                                    <span class="cac-number">#<?php echo intval($a['trial_number']); ?></span>
                                    <span class="cac-name"><?php echo esc_html($a['name']); ?></span>
                                    <span class="cac-chips">
                                        <?php echo $this->render_verdict_chips($a['selections']); ?>
                                    </span>
                                </div>

                                <div class="cac-meta">
                                    <a href="mailto:<?php echo esc_attr($a['email']); ?>"><?php echo esc_html($a['email']); ?></a>
                                    <?php if ($a['age'] !== ''): ?> &middot; Age <?php echo esc_html($a['age']); ?><?php endif; ?>
                                    <?php if ($a['positions']): ?> &middot; <?php echo esc_html($a['positions']); ?><?php endif; ?>
                                    &middot; <span title="<?php echo esc_attr($a['teams_selected_names']); ?>">Applied for: <?php echo esc_html($a['teams_selected']); ?></span>
                                </div>

                                <div class="cac-footer">
                                    <span class="cac-expanders">
                                        <?php if (!empty($a['form_data'])): ?>
                                            <details class="coach-app-details">
                                                <summary>Application</summary>
                                                <dl class="coach-application-details">
                                                    <?php foreach ($a['form_data'] as $question => $answer): ?>
                                                        <?php if ($answer !== '' && $answer !== null): ?>
                                                            <dt><?php echo esc_html($question); ?></dt>
                                                            <dd><?php echo nl2br(esc_html(is_array($answer) ? implode(', ', $answer) : $answer)); ?></dd>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </dl>
                                            </details>
                                        <?php endif; ?>
                                        <details class="coach-app-details">
                                            <summary>Notes (<?php echo count($a['notes']); ?>)</summary>
                                            <?php foreach ($a['notes'] as $note): ?>
                                                <p class="coach-note"><strong><?php echo esc_html($note['author']); ?></strong> <small><?php echo esc_html($note['date']); ?></small><br><?php echo nl2br(esc_html($note['note'])); ?></p>
                                            <?php endforeach; ?>
                                            <form method="post" class="coach-note-form">
                                                <input type="hidden" name="coach_action" value="add_note">
                                                <input type="hidden" name="application_id" value="<?php echo intval($a['id']); ?>">
                                                <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                                <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                                <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                                                <textarea name="coach_note" rows="2" placeholder="Add a note visible to all coaches..." required></textarea>
                                                <button type="submit" class="button button-small">Add Note</button>
                                            </form>
                                        </details>
                                    </span>
                                    <span class="cac-actions">
                                        <form method="post">
                                            <input type="hidden" name="coach_action" value="set_selection">
                                            <input type="hidden" name="application_id" value="<?php echo intval($a['id']); ?>">
                                            <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                            <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                            <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                                            <label class="cac-verdict-label">My verdict:
                                                <select name="selection_status" onchange="this.form.submit()">
                                                    <option value="clear" <?php selected($a['my_status'], ''); ?>>&mdash; None &mdash;</option>
                                                    <?php foreach (self::get_verdict_labels() as $status_key => $status_label): ?>
                                                        <option value="<?php echo esc_attr($status_key); ?>" <?php selected($a['my_status'], $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <noscript><button type="submit" class="button button-small">Save</button></noscript>
                                        </form>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <script>
                    (function() {
                        var search = document.getElementById('coach-search');
                        var mineOnly = document.getElementById('coach-filter-mine');
                        function filterCoachList() {
                            var q = search.value.toLowerCase();
                            var mine = mineOnly.checked;
                            document.querySelectorAll('#coach-applicant-list .coach-applicant-card').forEach(function(card) {
                                var matchesSearch = q === '' || card.textContent.toLowerCase().indexOf(q) !== -1;
                                var matchesMine = !mine || card.getAttribute('data-has-verdict') === '1';
                                card.style.display = (matchesSearch && matchesMine) ? '' : 'none';
                            });
                        }
                        search.addEventListener('input', filterCoachList);
                        mineOnly.addEventListener('change', filterCoachList);
                    })();
                    </script>
                <?php else: ?>
                    <p>No trial applications yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .coach-portal-notice {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #f9f9f9;
        }

        .coach-portal-success {
            border: 1px solid #46b450;
            background: #f0f7f0;
            border-radius: 4px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }

        .coach-team-switcher {
            margin: 15px 0;
        }

        .coach-team-tab {
            display: inline-block;
            padding: 8px 14px;
            margin-right: 6px;
            margin-bottom: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-decoration: none;
        }

        .coach-team-tab.active {
            background: #1d3d6e;
            color: #fff;
            border-color: #1d3d6e;
            font-weight: 600;
        }

        .coach-team-section {
            margin-bottom: 40px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
        }

        .coach-team-section h3 {
            margin-top: 0;
        }

        .coach-portal-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .coach-portal-table th,
        .coach-portal-table td {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid #e8e8e8;
            vertical-align: top;
            font-size: 14px;
        }

        .coach-portal-table thead th {
            background: #f5f5f5;
            border-bottom: 2px solid #ddd;
        }

        .coach-portal-table tr.verdict-selected {
            background: #f0f7f0;
        }

        .coach-portal-table tr.verdict-tentative {
            background: #fff8e1;
        }

        .coach-portal-table tr.verdict-training_only {
            background: #f5faff;
        }

        .coach-portal-table tr.verdict-rejected {
            background: #fbf0f0;
            opacity: 0.75;
        }

        .verdict-chip {
            display: inline-block;
            padding: 1px 7px;
            margin: 1px 2px 1px 0;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .verdict-chip-selected { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .verdict-chip-tentative { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .verdict-chip-training_only { background: #e7f5ff; color: #1864ab; border: 1px solid #74c0fc; }
        .verdict-chip-rejected { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .verdict-chip-confirmed { background: #e2e6ea; color: #1b1e21; border: 1px solid #c6c8ca; }

        /* Application / Notes toggles look like buttons, not plain text. */
        .coach-app-details summary {
            display: inline-block;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            padding: 5px 12px;
            border: 1px solid #1d3d6e;
            border-radius: 4px;
            color: #1d3d6e;
            background: #fff;
            list-style: none;
            user-select: none;
        }

        .coach-app-details summary::-webkit-details-marker {
            display: none;
        }

        .coach-app-details summary::after {
            content: " ▾";
            font-size: 11px;
        }

        .coach-app-details[open] summary::after {
            content: " ▴";
        }

        .coach-app-details summary:hover,
        .coach-app-details[open] summary {
            background: #1d3d6e;
            color: #fff;
        }

        .coach-application-details {
            margin: 10px 0 0 0;
            font-size: 13px;
        }

        .coach-application-details dt {
            font-weight: 600;
            margin-top: 8px;
        }

        .coach-application-details dd {
            margin: 0;
            color: #444;
        }

        .coach-note {
            font-size: 13px;
            margin: 8px 0;
            padding: 6px 8px;
            background: #f7f7f7;
            border-radius: 4px;
        }

        .coach-note-form textarea {
            width: 100%;
            margin: 6px 0 4px 0;
            font-size: 13px;
        }

        .coach-portal-hint {
            color: #666;
            font-size: 13px;
        }

        .coach-actions-cell .button-small {
            margin: 1px 2px 1px 0;
        }

        /* Applicant cards: flow at any width, no horizontal scrolling. */
        .coach-applicant-card {
            border: 1px solid #e0e0e0;
            border-left: 4px solid #ccc;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 10px;
            background: #fff;
        }

        .coach-applicant-card.verdict-selected { border-left-color: #46b450; background: #f7fcf7; }
        .coach-applicant-card.verdict-tentative { border-left-color: #f0b429; background: #fffdf5; }
        .coach-applicant-card.verdict-training_only { border-left-color: #339af0; background: #f5faff; }
        .coach-applicant-card.verdict-rejected { border-left-color: #dc3232; background: #fdf8f8; opacity: 0.8; }

        .coach-section-heading {
            margin: 24px 0 6px 0;
            padding-bottom: 4px;
            border-bottom: 2px solid #1d3d6e;
            color: #1d3d6e;
        }

        .cac-header {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
        }

        .cac-number {
            font-weight: 700;
            font-size: 16px;
            color: #1d3d6e;
        }

        .cac-name {
            font-weight: 600;
            font-size: 15px;
        }

        .cac-chips {
            margin-left: auto;
        }

        .cac-unclaimed {
            color: #999;
            font-size: 12px;
        }

        .cac-meta {
            font-size: 13px;
            color: #555;
            margin: 4px 0;
        }

        .cac-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 12px;
            margin-top: 6px;
        }

        .cac-expanders {
            flex: 1 1 250px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .cac-expanders details[open] {
            flex-basis: 100%;
        }

        .cac-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-left: auto;
        }

        .cac-actions form {
            margin: 0;
            display: inline;
        }

        /* Roster table stacks into labelled blocks on small screens. */
        @media (max-width: 640px) {
            .coach-roster-table thead {
                display: none;
            }

            .coach-roster-table tr {
                display: block;
                border: 1px solid #e8e8e8;
                border-radius: 6px;
                margin-bottom: 10px;
                padding: 6px 12px;
            }

            .coach-roster-table td {
                display: flex;
                gap: 10px;
                border-bottom: none;
                padding: 3px 0;
            }

            .coach-roster-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #555;
                min-width: 62px;
                flex-shrink: 0;
            }
        }

        @media (max-width: 782px) {
            .coach-team-section {
                padding: 12px;
            }

            .coach-team-tab {
                padding: 10px 14px;
                margin-bottom: 8px;
            }

            #coach-search {
                width: 100% !important;
                max-width: 100%;
                box-sizing: border-box;
                padding: 8px;
                font-size: 16px; /* prevents iOS zoom-on-focus */
            }

            .cac-chips {
                margin-left: 0;
                flex-basis: 100%;
            }

            .cac-actions {
                margin-left: 0;
                width: 100%;
            }

            .cac-actions .button-small,
            .coach-note-form .button-small {
                padding: 8px 12px;
                font-size: 13px;
                line-height: 1.2;
            }

            .coach-note-form textarea {
                font-size: 16px;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Actions (selections + notes), processed during shortcode render
    // ------------------------------------------------------------------

    private function handle_actions($my_team_codes, $season) {
        if (!isset($_POST['coach_action']) || !in_array($_POST['coach_action'], array('set_selection', 'add_note'), true)) {
            return '';
        }

        if (!isset($_POST['coach_nonce']) || !wp_verify_nonce($_POST['coach_nonce'], 'coach_portal_action')) {
            return '<div class="coach-portal-notice"><p>Security check failed — please try again.</p></div>';
        }

        $team = isset($_POST['coach_team']) ? sanitize_text_field($_POST['coach_team']) : '';
        if (!in_array($team, $my_team_codes, true)) {
            return '<div class="coach-portal-notice"><p>You can only act for teams you coach.</p></div>';
        }

        global $wpdb;
        $application_id = intval($_POST['application_id']);

        // The application must exist, be actionable, and match the season.
        $application = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE id = %d AND season = %s AND application_status IN ('pending', 'accepted')
        ", $application_id, $season));

        if (!$application) {
            return '<div class="coach-portal-notice"><p>Application not found.</p></div>';
        }

        if ($_POST['coach_action'] === 'add_note') {
            $note = isset($_POST['coach_note']) ? sanitize_textarea_field($_POST['coach_note']) : '';
            if ($note === '') {
                return '';
            }
            $wpdb->insert($wpdb->prefix . 'team_trial_notes', array(
                'application_id' => $application_id,
                'author_id' => get_current_user_id(),
                'note' => $note,
            ), array('%d', '%d', '%s'));

            return '<div class="coach-portal-success"><p>Note added for ' . esc_html($application->name) . ' (#' . intval($application->trial_number) . ').</p></div>';
        }

        // set_selection
        $status = sanitize_text_field($_POST['selection_status']);

        if ($status === 'clear') {
            $wpdb->delete($wpdb->prefix . 'team_trial_selections', array(
                'application_id' => $application_id,
                'team' => $team,
            ), array('%d', '%s'));
            return '<div class="coach-portal-success"><p>Verdict cleared for ' . esc_html($application->name) . ' (#' . intval($application->trial_number) . ').</p></div>';
        }

        if (!in_array($status, self::SELECTION_STATUSES, true)) {
            return '';
        }

        $verdict_labels = self::get_verdict_labels();

        $existing = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}team_trial_selections
            WHERE application_id = %d AND team = %s
        ", $application_id, $team));

        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'team_trial_selections',
                array('status' => $status, 'created_by' => get_current_user_id(), 'updated_date' => current_time('mysql')),
                array('id' => $existing),
                array('%s', '%d', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert($wpdb->prefix . 'team_trial_selections', array(
                'application_id' => $application_id,
                'season' => $season,
                'team' => $team,
                'status' => $status,
                'created_by' => get_current_user_id(),
            ), array('%d', '%s', '%s', '%s', '%d'));
        }

        return '<div class="coach-portal-success"><p>' . esc_html($application->name) . ' (#' . intval($application->trial_number) . ') marked <strong>' . esc_html($verdict_labels[$status]) . '</strong> for ' . esc_html($team) . '.</p></div>';
    }

    /**
     * Verdict chips markup for an applicant's selections across all teams.
     */
    private function render_verdict_chips($selections) {
        if (empty($selections)) {
            return '<span class="cac-unclaimed">Unclaimed</span>';
        }

        $labels = self::get_verdict_labels();
        $html = '';
        foreach ($selections as $sel) {
            $label = isset($labels[$sel['status']]) ? $labels[$sel['status']] : ucfirst($sel['status']);
            $html .= '<span class="verdict-chip verdict-chip-' . esc_attr($sel['status']) . '" title="' . esc_attr($sel['team_name'] . ' — ' . $label) . '">'
                . esc_html($sel['team']) . ': ' . esc_html($label)
                . '</span> ';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Roster CSV export (runs on template_redirect, before any output)
    // ------------------------------------------------------------------

    public function maybe_export_roster() {
        if (!isset($_POST['coach_action']) || $_POST['coach_action'] !== 'export_roster') {
            return;
        }

        if (!is_user_logged_in()
            || !isset($_POST['coach_nonce'])
            || !wp_verify_nonce($_POST['coach_nonce'], 'coach_portal_action')) {
            return;
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field($_POST['coach_season']) : date('Y');
        $team = isset($_POST['coach_team']) ? sanitize_text_field($_POST['coach_team']) : '';

        $my_teams = $this->get_my_teams($season);
        if (!isset($my_teams[$team])) {
            return;
        }

        $roster = $this->get_roster($team, $season);
        $selection_roster = $this->get_selection_roster($team, $season, $roster);

        $filename = 'team_list_' . sanitize_file_name($team) . "_{$season}.csv";

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array('Name', 'Role', 'Positions', 'Status', 'Email', 'Mobile'));

        foreach ($roster as $member) {
            fputcsv($output, array(
                $member->name ?: $member->email,
                str_replace('_', ' ', ucwords($member->role, '_')),
                $this->format_positions($member->preferred_positions),
                'Confirmed',
                $member->email,
                $member->mobile ?: '',
            ));
        }

        foreach ($selection_roster as $member) {
            $status_labels = array(
                'selected' => 'Selected (awaiting finalisation)',
                'training_only' => 'Training Only (awaiting finalisation)',
                'tentative' => 'Tentative',
            );
            fputcsv($output, array(
                $member->name,
                $member->status === 'training_only' ? 'Training Only' : 'Playing Member',
                $this->format_positions($member->preferred_positions),
                isset($status_labels[$member->status]) ? $status_labels[$member->status] : ucfirst($member->status),
                $member->email,
                $member->mobile ?: '',
            ));
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    private function get_roster($team_code, $season) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT ta.role, ta.email,
                MAX(u.display_name) AS name,
                MAX(um_mobile.meta_value) AS mobile,
                MAX(app.preferred_positions) AS preferred_positions
            FROM {$wpdb->prefix}team_assignments ta
            LEFT JOIN {$wpdb->users} u ON (ta.user_id = u.ID OR ((ta.user_id IS NULL OR ta.user_id = 0) AND ta.email = u.user_email))
            LEFT JOIN {$wpdb->usermeta} um_mobile ON u.ID = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            LEFT JOIN {$wpdb->prefix}trial_applications app ON app.season = ta.season
                AND app.application_status IN ('pending', 'accepted')
                AND (app.user_id = u.ID OR app.email = ta.email)
            WHERE ta.team = %s AND ta.season = %s AND ta.is_active = 1
            GROUP BY ta.id
            ORDER BY FIELD(ta.role, 'coach', 'assistant_coach', 'team_manager', 'playing_member', 'training_only', 'supporter'), MAX(u.display_name)
        ", $team_code, $season));
    }

    /**
     * JSON position keys -> readable labels.
     */
    private function format_positions($json) {
        $keys = $json ? json_decode($json, true) : array();
        if (!is_array($keys) || empty($keys)) {
            return '';
        }
        $positions = TeamOversight_Trials::get_position_options();
        return implode(', ', array_map(function ($key) use ($positions) {
            return isset($positions[$key]) ? $positions[$key] : $key;
        }, $keys));
    }

    /**
     * Selection-board players for a team (tentative + selected verdicts)
     * who aren't already on the confirmed roster.
     */
    private function get_selection_roster($team_code, $season, $roster) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT s.status, a.name, a.email, a.trial_number, a.user_id, a.preferred_positions,
                um_mobile.meta_value AS mobile
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            LEFT JOIN {$wpdb->usermeta} um_mobile ON a.user_id = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            WHERE s.team = %s AND s.season = %s
                AND s.status IN ('selected', 'training_only', 'tentative')
                AND a.application_status IN ('pending', 'accepted')
            ORDER BY FIELD(s.status, 'selected', 'training_only', 'tentative'), a.trial_number
        ", $team_code, $season));

        // Skip anyone already confirmed in a PLAYING role. People confirmed
        // as coach/manager still show their player selection separately —
        // a coach can legitimately also be selected as a player.
        $playing_emails = array();
        foreach ($roster as $member) {
            if (in_array($member->role, array('playing_member', 'training_only'), true)) {
                $playing_emails[] = strtolower($member->email);
            }
        }
        return array_values(array_filter($rows, function ($row) use ($playing_emails) {
            return !in_array(strtolower($row->email), $playing_emails, true);
        }));
    }

    /**
     * All paid/reviewable applicants in a competition for a season, with
     * every team's selection verdicts and all shared notes attached.
     * Sorted: picked-my-active-team first, then by trial number.
     */
    private function get_applicants_by_gender($gender, $season, $active_team, $my_team_codes) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE season = %s
                AND application_status IN ('pending', 'accepted')
            ORDER BY trial_number
        ", $season));

        if (empty($rows)) {
            return array();
        }

        $app_ids = wp_list_pluck($rows, 'id');
        $id_list = implode(',', array_map('intval', $app_ids));

        // Batch: all selections and notes for these applications.
        $selection_rows = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}team_trial_selections
            WHERE application_id IN ({$id_list})
            ORDER BY team
        ");
        $note_rows = $wpdb->get_results("
            SELECT n.*, u.display_name AS author
            FROM {$wpdb->prefix}team_trial_notes n
            LEFT JOIN {$wpdb->users} u ON u.ID = n.author_id
            WHERE n.application_id IN ({$id_list})
            ORDER BY n.created_date
        ");

        $positions = TeamOversight_Trials::get_position_options();
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();

        $selections_by_app = array();
        foreach ($selection_rows as $sel) {
            $selections_by_app[$sel->application_id][] = array(
                'team' => $sel->team,
                'team_name' => isset($teams_config[$sel->team]) ? $teams_config[$sel->team]['name'] : $sel->team,
                'status' => $sel->status,
            );
        }

        $notes_by_app = array();
        foreach ($note_rows as $note) {
            $notes_by_app[$note->application_id][] = array(
                'author' => $note->author ?: 'Unknown',
                'date' => date('j M Y', strtotime($note->created_date)),
                'note' => $note->note,
            );
        }

        $applicants = array();
        foreach ($rows as $row) {
            // Which competition is this applicant in? Unknown -> shown everywhere.
            $comp = null;
            $form_data = $row->form_data ? json_decode($row->form_data, true) : array();
            if (!empty($form_data['Trialling For'])) {
                if (stripos($form_data['Trialling For'], 'wom') === 0) {
                    $comp = 'womens';
                } elseif (stripos($form_data['Trialling For'], 'men') === 0) {
                    $comp = 'mens';
                }
            }
            if ($comp === null) {
                $profile = TeamOversight_Trials::get_competition_from_profile($row->user_id);
                $comp = $profile['competition'];
            }
            if ($gender !== 'mixed' && $comp !== null && $comp !== $gender) {
                continue;
            }

            $age = '';
            $birth_date = get_user_meta($row->user_id, 'birth_date', true);
            if ($birth_date) {
                $birth_ts = strtotime(str_replace('/', '-', $birth_date));
                if ($birth_ts) {
                    $age = (new DateTime('@' . $birth_ts))->diff(new DateTime())->y;
                }
            }

            $position_keys = json_decode($row->preferred_positions, true) ?: array();
            $position_labels = array_map(function ($key) use ($positions) {
                return isset($positions[$key]) ? $positions[$key] : $key;
            }, $position_keys);

            $selected_codes = json_decode($row->interested_teams, true) ?: array();
            $picked_mine = in_array($active_team, $selected_codes, true);

            $selected_display = array();
            $selected_names = array();
            foreach ($selected_codes as $code) {
                $selected_display[] = ($code === $active_team ? '★' : '') . $code;
                $selected_names[] = isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code;
            }

            $selections = isset($selections_by_app[$row->id]) ? $selections_by_app[$row->id] : array();
            $my_status = '';
            foreach ($selections as $sel) {
                if ($sel['team'] === $active_team) {
                    $my_status = $sel['status'];
                }
            }

            $applicants[] = array(
                'id' => intval($row->id),
                'trial_number' => intval($row->trial_number),
                'name' => $row->name,
                'email' => $row->email,
                'age' => $age,
                'positions' => implode(', ', $position_labels),
                'teams_selected' => implode(', ', $selected_display),
                'teams_selected_names' => implode(', ', $selected_names),
                'picked_mine' => $picked_mine,
                'selections' => $selections,
                'my_status' => $my_status,
                'notes' => isset($notes_by_app[$row->id]) ? $notes_by_app[$row->id] : array(),
                'form_data' => $form_data,
            );
        }

        usort($applicants, function ($a, $b) {
            if ($a['picked_mine'] !== $b['picked_mine']) {
                return $a['picked_mine'] ? -1 : 1;
            }
            return $a['trial_number'] - $b['trial_number'];
        });

        return $applicants;
    }
}
