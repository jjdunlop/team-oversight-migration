<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end portal for coaches: [team_coach_portal]
 *
 * Visible only to logged-in users holding an active coach or assistant
 * coach team assignment. Shows, for each of their teams: the current
 * roster and the trial applications that selected that team. Strictly
 * limited to the viewer's own teams.
 */
class TeamOversight_Coach_Portal {

    const COACH_ROLES = "'coach', 'assistant_coach'";

    public function __construct() {
        add_shortcode('team_coach_portal', array($this, 'render'));
    }

    /**
     * Active coach/assistant-coach assignments for a user, matched by
     * user ID with an email fallback for rows predating user_id keying.
     */
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

        // Season selection, limited to seasons where they actually coach.
        $seasons = array_values(array_unique(array_map(function ($a) {
            return $a->season;
        }, $coach_assignments)));

        $season = (isset($_GET['coach_season']) && in_array($_GET['coach_season'], $seasons, true))
            ? $_GET['coach_season']
            : $seasons[0];

        $my_teams = array();
        foreach ($coach_assignments as $assignment) {
            if ($assignment->season === $season) {
                $my_teams[$assignment->team] = $assignment->role;
            }
        }

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();

        ob_start();
        ?>
        <div class="coach-portal">
            <h2>Coach Portal</h2>

            <?php if (count($seasons) > 1): ?>
                <p>
                    Season:
                    <?php foreach ($seasons as $s): ?>
                        <?php if ($s === $season): ?>
                            <strong><?php echo esc_html($s); ?></strong>
                        <?php else: ?>
                            <a href="<?php echo esc_url(add_query_arg('coach_season', $s)); ?>"><?php echo esc_html($s); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <?php foreach ($my_teams as $team_code => $coach_role): ?>
                <?php
                $team_name = isset($teams_config[$team_code]) ? $teams_config[$team_code]['name'] : $team_code;
                $roster = $this->get_roster($team_code, $season);
                ?>
                <div class="coach-team-section">
                    <h3><?php echo esc_html($team_name); ?> <small>(<?php echo esc_html($season); ?> — you are <?php echo esc_html(str_replace('_', ' ', $coach_role)); ?>)</small></h3>

                    <h4>Current Team (<?php echo count($roster); ?>)</h4>
                    <?php if (!empty($roster)): ?>
                        <table class="coach-portal-table">
                            <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Mobile</th></tr></thead>
                            <tbody>
                                <?php foreach ($roster as $member): ?>
                                    <tr>
                                        <td><?php echo esc_html($member->name ?: $member->email); ?></td>
                                        <td><?php echo esc_html(str_replace('_', ' ', ucwords($member->role, '_'))); ?></td>
                                        <td><a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a></td>
                                        <td><?php echo esc_html($member->mobile ?: ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No active members assigned yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php
            // Applicant pools are gender-wide, not per-team: players are
            // sometimes redirected between trials, and borderline-age players
            // can attend with a VV exemption — coaches need to see everyone
            // in their competition. Applicants who picked one of the coach's
            // teams sort first.
            $my_team_codes = array_keys($my_teams);
            $genders_coached = array();
            foreach ($my_team_codes as $code) {
                $g = isset($teams_config[$code]) ? $teams_config[$code]['gender'] : 'mixed';
                if ($g === 'mixed') {
                    $genders_coached['mens'] = true;
                    $genders_coached['womens'] = true;
                } else {
                    $genders_coached[$g] = true;
                }
            }
            ?>

            <?php foreach (array_keys($genders_coached) as $gender): ?>
                <?php
                $applicants = $this->get_applicants_by_gender($gender, $season, $my_team_codes);
                $gender_label = $gender === 'mens' ? "Men's" : "Women's";
                ?>
                <div class="coach-team-section">
                    <h3>Trial Applicants — <?php echo esc_html($gender_label); ?> (<?php echo count($applicants); ?>)</h3>
                    <p class="coach-portal-hint">All <?php echo esc_html(strtolower($gender_label)); ?> applicants for <?php echo esc_html($season); ?> are shown — including players outside your team's age group or who selected other teams, since players are sometimes redirected between trials and VV can grant age exemptions. Those who selected your team<?php echo count($my_team_codes) > 1 ? 's' : ''; ?> (★) are listed first.</p>

                    <?php if (!empty($applicants)): ?>
                        <table class="coach-portal-table">
                            <thead><tr><th style="width: 60px;">Trial #</th><th>Name</th><th style="width: 45px;">Age</th><th>Positions</th><th>Teams Selected</th><th>Status</th><th>Details</th></tr></thead>
                            <tbody>
                                <?php foreach ($applicants as $applicant): ?>
                                    <tr<?php echo $applicant['picked_mine'] ? ' class="picked-my-team"' : ''; ?>>
                                        <td><strong>#<?php echo intval($applicant['trial_number']); ?></strong></td>
                                        <td>
                                            <?php echo $applicant['picked_mine'] ? '★ ' : ''; ?><?php echo esc_html($applicant['name']); ?><br>
                                            <small><a href="mailto:<?php echo esc_attr($applicant['email']); ?>"><?php echo esc_html($applicant['email']); ?></a></small>
                                        </td>
                                        <td><?php echo esc_html($applicant['age']); ?></td>
                                        <td><?php echo esc_html($applicant['positions']); ?></td>
                                        <td title="<?php echo esc_attr($applicant['teams_selected_names']); ?>"><?php echo esc_html($applicant['teams_selected']); ?></td>
                                        <td>
                                            <?php echo esc_html($applicant['status']); ?>
                                            <?php if ($applicant['assigned_team']): ?><br><small>→ <?php echo esc_html($applicant['assigned_team']); ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($applicant['form_data'])): ?>
                                                <details>
                                                    <summary>View application</summary>
                                                    <dl class="coach-application-details">
                                                        <?php foreach ($applicant['form_data'] as $question => $answer): ?>
                                                            <?php if ($answer !== '' && $answer !== null): ?>
                                                                <dt><?php echo esc_html($question); ?></dt>
                                                                <dd><?php echo nl2br(esc_html(is_array($answer) ? implode(', ', $answer) : $answer)); ?></dd>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </dl>
                                                </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No trial applications yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <style>
        .coach-portal-notice {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #f9f9f9;
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

        .coach-portal-hint {
            color: #666;
            font-size: 13px;
        }

        .coach-portal-table tr.picked-my-team {
            background: #f0f7f0;
        }
        </style>
        <?php
        return ob_get_clean();
    }

    private function get_roster($team_code, $season) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT ta.role, ta.email,
                u.display_name AS name,
                um_mobile.meta_value AS mobile
            FROM {$wpdb->prefix}team_assignments ta
            LEFT JOIN {$wpdb->users} u ON (ta.user_id = u.ID OR ((ta.user_id IS NULL OR ta.user_id = 0) AND ta.email = u.user_email))
            LEFT JOIN {$wpdb->usermeta} um_mobile ON u.ID = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            WHERE ta.team = %s AND ta.season = %s AND ta.is_active = 1
            ORDER BY FIELD(ta.role, 'coach', 'assistant_coach', 'team_manager', 'playing_member', 'training_only', 'supporter'), u.display_name
        ", $team_code, $season));
    }

    /**
     * All paid/reviewable applicants in a competition (mens/womens) for a
     * season. Applicant competition comes from their stored answer, falling
     * back to profile gender; applicants whose competition can't be
     * determined are included in both pools rather than hidden.
     * Sorted with those who selected one of the coach's teams first.
     */
    private function get_applicants_by_gender($gender, $season, $my_team_codes) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE season = %s
                AND application_status IN ('pending', 'accepted')
            ORDER BY trial_number
        ", $season));

        $positions = TeamOversight_Trials::get_position_options();
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config();

        $applicants = array();
        foreach ($rows as $row) {
            // Which competition is this applicant in?
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
            if ($comp !== null && $comp !== $gender) {
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
            $picked_mine = (bool) array_intersect($selected_codes, $my_team_codes);

            $selected_display = array();
            $selected_names = array();
            foreach ($selected_codes as $code) {
                $selected_display[] = (in_array($code, $my_team_codes, true) ? '★' : '') . $code;
                $selected_names[] = isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code;
            }

            $applicants[] = array(
                'trial_number' => intval($row->trial_number),
                'name' => $row->name,
                'email' => $row->email,
                'age' => $age,
                'positions' => implode(', ', $position_labels),
                'teams_selected' => implode(', ', $selected_display),
                'teams_selected_names' => implode(', ', $selected_names),
                'picked_mine' => $picked_mine,
                'status' => ucwords(str_replace('_', ' ', $row->application_status)),
                'assigned_team' => $row->assigned_team ?: '',
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
