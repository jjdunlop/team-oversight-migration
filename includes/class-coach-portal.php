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
        // The printable trial book, likewise before any theme output.
        add_action('template_redirect', array($this, 'maybe_export_applicants'));
        // Verdicts and notes use Post/Redirect/Get: processed before output,
        // answered with a redirect, so refreshing never resubmits (which
        // would duplicate notes).
        add_action('template_redirect', array($this, 'maybe_handle_actions'));
        // Attendance marks save instantly, so a coach can tap through a
        // session without a page reload per player.
        add_action('wp_ajax_coach_mark_attendance', array($this, 'ajax_mark_attendance'));
        add_action('wp_ajax_coach_set_verdict', array($this, 'ajax_set_verdict'));
    }

    // ------------------------------------------------------------------
    // Attendance
    // ------------------------------------------------------------------

    /** Season attendance, loaded once per request: person key => rows. */
    private $attendance_cache = array();

    /** One person across records: their account, else their email. */
    public static function attendance_person_key($user_id, $email) {
        return intval($user_id) ? 'u' . intval($user_id) : strtolower(trim((string) $email));
    }

    /**
     * Every attendance mark for a season, newest first, grouped by person.
     * One query per page however many cards ask, since every card shows
     * its player's full history across all teams.
     */
    private function get_season_attendance($season) {
        global $wpdb;
        if (!isset($this->attendance_cache[$season])) {
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT a.person_key, a.team, a.session_date, u.display_name AS marked_by_name
                FROM {$wpdb->prefix}team_attendance a
                LEFT JOIN {$wpdb->users} u ON u.ID = a.marked_by
                WHERE a.season = %s
                ORDER BY a.session_date DESC, a.team
            ", $season));
            $by_person = array();
            foreach ($rows as $row) {
                $by_person[$row->person_key][] = $row;
            }
            $this->attendance_cache[$season] = $by_person;
        }
        return $this->attendance_cache[$season];
    }

    private function get_person_attendance($season, $user_id, $email) {
        $all = $this->get_season_attendance($season);
        $key = self::attendance_person_key($user_id, $email);
        return isset($all[$key]) ? $all[$key] : array();
    }

    /** The history list shown in a card's Attendance dropdown. */
    private function render_attendance_list($records) {
        if (empty($records)) {
            return '<p class="coach-attendance-empty">No attendance recorded yet.</p>';
        }
        $html = '<ul class="coach-attendance-list">';
        foreach ($records as $record) {
            $html .= '<li><strong>' . esc_html($record->team) . '</strong> &middot; '
                . esc_html(date('D j M Y', strtotime($record->session_date)))
                . ($record->marked_by_name ? ' <small>marked by ' . esc_html($record->marked_by_name) . '</small>' : '')
                . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * The date being marked: from the picker, else today. Never in the
     * future — you can't have attended a session that hasn't happened.
     */
    private function get_attendance_date() {
        $today = wp_date('Y-m-d');
        $date = isset($_GET['attendance_date']) ? sanitize_text_field(wp_unslash($_GET['attendance_date'])) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
            return $today;
        }
        return $date;
    }

    /** "Mark attended" button for one card, showing its state for the date. */
    private function render_attendance_button($user_id, $email, $active_team, $date, $season) {
        $marked = false;
        foreach ($this->get_person_attendance($season, $user_id, $email) as $record) {
            if ($record->team === $active_team && $record->session_date === $date) {
                $marked = true;
            }
        }
        return '<button type="button" class="button button-small coach-attend-btn' . ($marked ? ' is-marked' : '') . '"'
            . ' data-user="' . intval($user_id) . '" data-email="' . esc_attr($email) . '"'
            . ' data-marked="' . ($marked ? '1' : '0') . '"'
            . ' title="' . esc_attr($marked ? 'Click to undo' : 'Mark as attended on the chosen date') . '">'
            . ($marked ? '&#10003; Attended' : 'Mark attended') . '</button>';
    }

    /**
     * Mark or unmark one player for one of the coach's own teams on a date.
     * Coaches can only write to teams they coach; everyone's marks show to
     * every coach in the history.
     */
    public function ajax_mark_attendance() {
        if (!is_user_logged_in() || !isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'coach_attendance')) {
            wp_send_json_error(array('message' => 'Your session has expired — please reload the page.'));
        }

        global $wpdb;
        $season = isset($_POST['season']) ? sanitize_text_field(wp_unslash($_POST['season'])) : '';
        $team = isset($_POST['team']) ? sanitize_text_field(wp_unslash($_POST['team'])) : '';
        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $mark = !empty($_POST['mark']);

        $my_teams = $this->get_my_teams($season);
        if (!isset($my_teams[$team])) {
            wp_send_json_error(array('message' => 'You can only mark attendance for teams you coach.'));
        }

        // Sessions that have happened, in this season's trials or training
        // (trials for a season run late the year before).
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > wp_date('Y-m-d')
            || intval(substr($date, 0, 4)) < intval($season) - 1 || intval(substr($date, 0, 4)) > intval($season)) {
            wp_send_json_error(array('message' => 'Pick a date from this season that has already happened.'));
        }

        // Only people genuinely in this season: an applicant or a rostered player.
        $in_season = $wpdb->get_var($wpdb->prepare("
            SELECT 1 FROM {$wpdb->prefix}trial_applications
            WHERE season = %s AND application_status IN ('pending', 'accepted', 'awaiting_payment')
                AND ((user_id > 0 AND user_id = %d) OR (email <> '' AND email = %s))
            UNION
            SELECT 1 FROM {$wpdb->prefix}team_assignments
            WHERE season = %s AND is_active = 1
                AND ((user_id > 0 AND user_id = %d) OR (email <> '' AND email = %s))
            LIMIT 1
        ", $season, $user_id, $email, $season, $user_id, $email));
        if (!$in_season || ($user_id === 0 && $email === '')) {
            wp_send_json_error(array('message' => 'That player isn\'t part of this season.'));
        }

        $key = self::attendance_person_key($user_id, $email);
        if ($mark) {
            // Marking again is a no-op, and the first marker is kept.
            $wpdb->query($wpdb->prepare("
                INSERT INTO {$wpdb->prefix}team_attendance
                    (person_key, user_id, email, season, team, session_date, marked_by)
                VALUES (%s, %d, %s, %s, %s, %s, %d)
                ON DUPLICATE KEY UPDATE id = id
            ", $key, $user_id, $email, $season, $team, $date, get_current_user_id()));
        } else {
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->prefix}team_attendance
                WHERE person_key = %s AND team = %s AND session_date = %s
            ", $key, $team, $date));
        }

        unset($this->attendance_cache[$season]);
        $records = $this->get_person_attendance($season, $user_id, $email);
        wp_send_json_success(array(
            'marked' => $mark,
            'count' => count($records),
            'history' => $this->render_attendance_list($records),
        ));
    }

    /**
     * Save a verdict in the background so the coach keeps their place.
     * Runs the same handle_actions() as the form post (nonce, "coaches this
     * team", actionable application), then returns the refreshed chips for
     * the card. The form post stays as the no-JavaScript fallback.
     */
    public function ajax_set_verdict() {
        if (!is_user_logged_in() || !isset($_POST['coach_action']) || $_POST['coach_action'] !== 'set_selection') {
            wp_send_json_error(array('notice' => '<div class="coach-portal-notice"><p>Your session has expired — please reload the page.</p></div>'));
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field(wp_unslash($_POST['coach_season'])) : '';
        $notice = $this->handle_actions(array_keys($this->get_my_teams($season)), $season);
        if (strpos($notice, 'coach-portal-success') === false) {
            wp_send_json_error(array('notice' => $notice !== '' ? $notice : '<div class="coach-portal-notice"><p>That verdict could not be saved.</p></div>'));
        }

        $status = sanitize_text_field(wp_unslash($_POST['selection_status']));
        $status = $status === 'clear' ? '' : $status;
        $board = isset($_POST['card_context']) && $_POST['card_context'] === 'board';
        $chips = $board
            ? self::render_board_verdict_chip($status)
            : $this->render_verdict_chips($this->get_selections_for_application(intval($_POST['application_id']), $season));

        wp_send_json_success(array('notice' => $notice, 'status' => $status, 'chips' => $chips));
    }

    /** Every team's verdict on one application, shaped for render_verdict_chips(). */
    private function get_selections_for_application($application_id, $season) {
        global $wpdb;
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT team, status FROM {$wpdb->prefix}team_trial_selections
            WHERE application_id = %d
            ORDER BY team
        ", $application_id));
        $selections = array();
        foreach ($rows as $sel) {
            $selections[] = array(
                'team' => $sel->team,
                'team_name' => isset($teams_config[$sel->team]) ? $teams_config[$sel->team]['name'] : $sel->team,
                'status' => $sel->status,
            );
        }
        return $selections;
    }

    /** The selection board's own-verdict chip (players awaiting finalisation). */
    private static function render_board_verdict_chip($status) {
        if ($status === 'selected') {
            return '<span class="verdict-chip verdict-chip-selected">Selected — awaiting finalisation</span>';
        }
        if ($status === 'training_only') {
            return '<span class="verdict-chip verdict-chip-training_only">Training Only — awaiting finalisation</span>';
        }
        if ($status === 'tentative') {
            return '<span class="verdict-chip verdict-chip-tentative">Tentative</span>';
        }
        if ($status === 'rejected') {
            return '<span class="verdict-chip verdict-chip-rejected">Rejected — leaves the board on reload</span>';
        }
        return '<span class="cac-unclaimed">No verdict — leaves the board on reload</span>';
    }

    /**
     * Process verdict/note submissions before output, stash the result
     * notice for the next render, and redirect back to the page.
     */
    public function maybe_handle_actions() {
        if (!isset($_POST['coach_action']) || !in_array($_POST['coach_action'], array('set_selection', 'add_note', 'edit_note', 'delete_note'), true)) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field($_POST['coach_season']) : '';
        $my_teams = $this->get_my_teams($season);

        $notice = $this->handle_actions(array_keys($my_teams), $season);
        if ($notice !== '') {
            set_transient('murvc_coach_flash_' . get_current_user_id(), $notice, 60);
        }

        wp_safe_redirect($_SERVER['REQUEST_URI']);
        exit;
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

        // Action results arrive via a flash notice set before the redirect.
        $action_notice = '';
        $flash = get_transient('murvc_coach_flash_' . get_current_user_id());
        if ($flash) {
            $action_notice = $flash;
            delete_transient('murvc_coach_flash_' . get_current_user_id());
        }

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
        $active_config = isset($teams_config[$active_team]) ? $teams_config[$active_team] : array('name' => $active_team, 'gender' => 'mixed', 'age_rule' => '');
        $roster = $this->get_roster($active_team, $season);
        $selection_roster = $this->get_selection_roster($active_team, $season, $roster);
        $last_season_members = $this->get_last_season_team_members($active_team, $season);
        $applicants = $this->get_applicants_by_gender($active_config['gender'], $season, $active_team, $my_team_codes);

        $base_url = remove_query_arg(array('coach_team', 'coach_season'));

        ob_start();
        ?>
        <div class="coach-portal">
            <h2>Coach Portal</h2>
            <div id="coach-flash"><?php echo $action_notice; ?></div>

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

                <?php $attendance_date = $this->get_attendance_date(); ?>
                <form method="get" class="coach-attendance-bar" id="coach-attendance-bar"
                      data-season="<?php echo esc_attr($season); ?>"
                      data-team="<?php echo esc_attr($active_team); ?>"
                      data-date="<?php echo esc_attr($attendance_date); ?>"
                      data-nonce="<?php echo esc_attr(wp_create_nonce('coach_attendance')); ?>"
                      data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                    <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                    <label><strong>Attendance for</strong>
                        <input type="date" name="attendance_date" value="<?php echo esc_attr($attendance_date); ?>" max="<?php echo esc_attr(wp_date('Y-m-d')); ?>" onchange="this.form.submit()">
                    </label>
                    <span class="coach-portal-hint">Choose the session date, then tap <em>Mark attended</em> on each player who was there.</span>
                </form>

                <?php
                // Staff stay a compact table; players render as cards like
                // the applicant list, so a selection visually "moves" the
                // same card into the team.
                $staff = array();
                $confirmed_players = array();
                foreach ($roster as $member) {
                    if (in_array($member->role, array('playing_member', 'training_only'), true)) {
                        $confirmed_players[] = $member;
                    } else {
                        $staff[] = $member;
                    }
                }
                ?>

                <h4>Coaching Staff (<?php echo count($staff); ?>)</h4>
                <?php if (!empty($staff)): ?>
                    <table class="coach-portal-table coach-roster-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Mobile</th></tr></thead>
                        <tbody>
                            <?php foreach ($staff as $member): ?>
                                <tr>
                                    <td data-label="Name"><?php echo esc_html($member->name ?: $member->email); ?></td>
                                    <td data-label="Role"><?php echo esc_html(str_replace('_', ' ', ucwords($member->role, '_'))); ?></td>
                                    <td data-label="Email"><a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a></td>
                                    <td data-label="Mobile"><?php echo esc_html($member->mobile ? self::format_phone($member->mobile) : ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No coaching staff assigned yet.</p>
                <?php endif; ?>

                <h4>Players (<?php echo count($confirmed_players); ?> confirmed<?php echo count($selection_roster) ? ', ' . count($selection_roster) . ' in selection' : ''; ?>)</h4>
                <?php if (!empty($confirmed_players) || !empty($selection_roster)): ?>
                    <?php
                    // Other teams' verdicts and confirmed places for everyone
                    // in this section, fetched once for all the cards.
                    $confirmed_ctx = array();
                    $claim_apps = array();
                    $claim_people = array();
                    foreach ($confirmed_players as $i => $member) {
                        $confirmed_ctx[$i] = $this->get_application_context($member->user_id, $member->email, $season);
                        if ($confirmed_ctx[$i]) {
                            $claim_apps[] = $confirmed_ctx[$i]['id'];
                        }
                        $claim_people[] = array(isset($member->user_id) ? $member->user_id : 0, $member->email);
                    }
                    foreach ($selection_roster as $member) {
                        $claim_apps[] = $member->application_id;
                        $claim_people[] = array($member->user_id, $member->email);
                    }
                    $claims = $this->get_other_team_claims($claim_apps, $claim_people, $active_team, $season);
                    ?>
                    <?php foreach ($confirmed_players as $i => $member): ?>
                        <?php $app_ctx = $confirmed_ctx[$i]; ?>
                        <div class="coach-applicant-card">
                            <div class="cac-header">
                                <?php if ($app_ctx): ?>
                                    <span class="cac-number">#<?php echo intval($app_ctx['trial_number']); ?></span>
                                <?php endif; ?>
                                <span class="cac-name"><?php echo esc_html($member->name ?: $member->email); ?></span>
                                <span class="cac-chips">
                                    <?php if ($app_ctx): ?>
                                        <?php echo $this->render_reg_chip($app_ctx['reg_type'], $app_ctx['transfer_club']); ?>
                                    <?php endif; ?>
                                    <?php if ($this->was_on_team_last_season($last_season_members, isset($member->user_id) ? $member->user_id : 0, $member->email)): ?>
                                        <?php echo $this->render_same_team_chip($active_team, $season); ?>
                                    <?php endif; ?>
                                    <span class="verdict-chip verdict-chip-confirmed">Confirmed<?php echo $member->role === 'training_only' ? ' — Training Only' : ''; ?></span>
                                    <?php echo $this->render_other_team_chips($claims, $app_ctx ? $app_ctx['id'] : 0, isset($member->user_id) ? $member->user_id : 0, $member->email, $teams_config); ?>
                                </span>
                            </div>
                            <div class="cac-meta">
                                <a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a>
                                <?php if ($member->mobile): ?> &middot; <?php echo esc_html(self::format_phone($member->mobile)); ?><?php endif; ?>
                                <?php if ($this->format_positions($member->preferred_positions)): ?> &middot; <?php echo esc_html($this->format_positions($member->preferred_positions)); ?><?php endif; ?>
                            </div>
                            <div class="cac-footer">
                                <span class="cac-expanders"><?php echo $this->render_card_expanders(
                                    $member->email,
                                    $app_ctx ? $app_ctx['id'] : 0,
                                    $app_ctx ? $app_ctx['form_data'] : array(),
                                    $app_ctx ? $app_ctx['notes'] : array(),
                                    $active_team,
                                    $season,
                                    isset($member->user_id) ? $member->user_id : 0
                                ); ?></span>
                                <span class="cac-actions">
                                    <?php echo $this->render_attendance_button(isset($member->user_id) ? $member->user_id : 0, $member->email, $active_team, $attendance_date, $season); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($selection_roster as $member): ?>
                        <div class="coach-applicant-card verdict-<?php echo esc_attr($member->status); ?>" data-card-context="board">
                            <div class="cac-header">
                                <span class="cac-number">#<?php echo intval($member->trial_number); ?></span>
                                <span class="cac-name"><?php echo esc_html($member->name); ?></span>
                                <span class="cac-chips">
                                    <?php echo $this->render_reg_chip($member->reg_type, $member->transfer_club); ?>
                                    <?php if (isset($member->application_status) && $member->application_status === 'awaiting_payment'): ?>
                                        <?php echo self::render_payment_pending_chip(); ?>
                                    <?php endif; ?>
                                    <?php if ($this->was_on_team_last_season($last_season_members, $member->user_id, $member->email)): ?>
                                        <?php echo $this->render_same_team_chip($active_team, $season); ?>
                                    <?php endif; ?>
                                    <span class="cac-verdicts"><?php echo self::render_board_verdict_chip(in_array($member->status, array('selected', 'training_only'), true) ? $member->status : 'tentative'); ?></span>
                                    <?php echo $this->render_other_team_chips($claims, $member->application_id, $member->user_id, $member->email, $teams_config); ?>
                                </span>
                            </div>
                            <div class="cac-meta">
                                <a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a>
                                <?php if ($member->mobile): ?> &middot; <?php echo esc_html(self::format_phone($member->mobile)); ?><?php endif; ?>
                                <?php if (!empty($member->age_flag)): ?>
                                    &middot; <span class="cac-age-flag">Age <?php echo esc_html($member->age); ?> (born <?php echo esc_html($member->dob_display); ?>) — over the <?php echo esc_html($member->rule_label); ?> limit for this team; VV exemption required</span>
                                <?php endif; ?>
                                <?php if ($this->format_positions($member->preferred_positions)): ?> &middot; <?php echo esc_html($this->format_positions($member->preferred_positions)); ?><?php endif; ?>
                            </div>
                            <div class="cac-footer">
                                <span class="cac-expanders"><?php echo $this->render_card_expanders(
                                    $member->email,
                                    intval($member->application_id),
                                    (is_array($decoded_roster = ($member->form_data ? json_decode($member->form_data, true) : array())) ? $decoded_roster : array()),
                                    $this->get_notes_for_application(intval($member->application_id)),
                                    $active_team,
                                    $season,
                                    $member->user_id
                                ); ?></span>
                                <span class="cac-actions">
                                    <?php echo $this->render_attendance_button($member->user_id, $member->email, $active_team, $attendance_date, $season); ?>
                                    <form method="post">
                                        <input type="hidden" name="coach_action" value="set_selection">
                                        <input type="hidden" name="application_id" value="<?php echo intval($member->application_id); ?>">
                                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                                        <label class="cac-verdict-label">My verdict:
                                            <select name="selection_status" onchange="if (window.murvcCoachVerdict) { window.murvcCoachVerdict(this); } else { this.form.submit(); }">
                                                <option value="clear">&mdash; None &mdash;</option>
                                                <?php foreach (self::get_verdict_labels() as $status_key => $status_label): ?>
                                                    <option value="<?php echo esc_attr($status_key); ?>" <?php selected($member->status, $status_key); ?>><?php echo esc_html($status_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <noscript><button type="submit" class="button button-small">Save</button></noscript>
                                    </form>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <form method="post">
                        <input type="hidden" name="coach_action" value="export_roster">
                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                        <button type="submit" class="button">Export team list (CSV)</button>
                    </form>
                <?php else: ?>
                    <p>No players yet — record verdicts below to build your team.</p>
                <?php endif; ?>
            </div>

            <div class="coach-team-section">
                <h3>Trial Applicants — <?php echo $active_config['gender'] === 'womens' ? "Women's" : ($active_config['gender'] === 'mens' ? "Men's" : 'All'); ?> (<?php echo count($applicants); ?>)</h3>
                <p class="coach-portal-hint">Every applicant in this competition is shown — including players outside your age group or who selected other teams, since players get redirected between trials and VV can grant age exemptions. Your verdicts apply to <strong><?php echo esc_html($active_config['name']); ?></strong> only; a player can be Selected by multiple teams (e.g. YSL and JPL) and every coach sees every team's verdicts. Selected players become official (team assignment + fees) when the club finalises selections. Applicants marked <strong>Payment pending</strong> haven't paid their trial fee yet — trial and assess them as normal, but they won't be finalised onto a team until it's paid.</p>

                <?php // Trial book first, so the search sits directly above the list it filters (matters on a phone). ?>
                <?php if (!empty($applicants)): ?>
                    <form method="post" target="_blank" class="coach-export-form">
                        <input type="hidden" name="coach_action" value="export_applicants">
                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                        <button type="submit" class="button">Trial book (print / save as PDF)</button>
                        <span class="coach-portal-hint">Every applicant with their application, emergency contacts, notes and verdicts — opens ready to print. Save it as a PDF before trials so you have it when the gym has no reception.</span>
                    </form>
                <?php endif; ?>

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
                    // Verdict recorded: grouped by the verdict, strongest
                    // first, then by trial number within each verdict.
                    $verdict_rank = array('selected' => 0, 'tentative' => 1, 'training_only' => 2, 'rejected' => 3);
                    usort($actioned, function ($x, $y) use ($verdict_rank) {
                        $rx = isset($verdict_rank[$x['my_status']]) ? $verdict_rank[$x['my_status']] : 9;
                        $ry = isset($verdict_rank[$y['my_status']]) ? $verdict_rank[$y['my_status']] : 9;
                        return $rx !== $ry ? $rx - $ry : $x['trial_number'] - $y['trial_number'];
                    });
                    $sections = array(
                        array('Applied to your team — awaiting your verdict', $needs_action, 'They selected ' . $active_config['name'] . ' on their form and you haven\'t recorded a verdict yet.'),
                        array('Applied to your team — verdict recorded', $actioned, ''),
                        array('Other applicants', $others, 'Applicants who didn\'t select your team — shown because players get redirected between trials and VV can grant age exemptions.'),
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
                                        <?php echo $this->render_reg_chip($a['reg_type'], $a['transfer_club']); ?>
                                        <?php if (!empty($a['payment_pending'])): ?>
                                            <?php echo self::render_payment_pending_chip(); ?>
                                        <?php endif; ?>
                                        <?php if ($this->was_on_team_last_season($last_season_members, $a['user_id'], $a['email'])): ?>
                                            <?php echo $this->render_same_team_chip($active_team, $season); ?>
                                        <?php endif; ?>
                                        <span class="cac-verdicts"><?php echo $this->render_verdict_chips($a['selections']); ?></span>
                                    </span>
                                </div>

                                <div class="cac-meta">
                                    <a href="mailto:<?php echo esc_attr($a['email']); ?>"><?php echo esc_html($a['email']); ?></a>
                                    <?php if ($a['age'] !== ''): ?>
                                        &middot;
                                        <?php if (!empty($a['age_flag'])): ?>
                                            <span class="cac-age-flag">Age <?php echo esc_html($a['age']); ?> (born <?php echo esc_html($a['dob_display']); ?>) — over the <?php echo esc_html($a['age_rule_label']); ?> limit for this team; VV exemption required</span>
                                        <?php else: ?>
                                            Age <?php echo esc_html($a['age']); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($a['positions']): ?> &middot; <?php echo esc_html($a['positions']); ?><?php endif; ?>
                                    &middot; <span title="<?php echo esc_attr($a['teams_selected_names']); ?>">Applied for: <?php echo esc_html($a['teams_selected']); ?></span>
                                </div>

                                <div class="cac-footer">
                                    <span class="cac-expanders">
                                        <?php echo $this->render_card_expanders($a['email'], $a['id'], $a['form_data'], $a['notes'], $active_team, $season, $a['user_id']); ?>
                                    </span>
                                    <span class="cac-actions">
                                        <?php echo $this->render_attendance_button($a['user_id'], $a['email'], $active_team, $attendance_date, $season); ?>
                                        <form method="post">
                                            <input type="hidden" name="coach_action" value="set_selection">
                                            <input type="hidden" name="application_id" value="<?php echo intval($a['id']); ?>">
                                            <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                            <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                            <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                                            <label class="cac-verdict-label">My verdict:
                                                <select name="selection_status" onchange="if (window.murvcCoachVerdict) { window.murvcCoachVerdict(this); } else { this.form.submit(); }">
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

        <script>
        // Verdicts save in the background (no reload). Notes save with a
        // normal form post + reload, so keep the coach's place: remember
        // scroll position, search and filter (and which Notes panel was in
        // use) on submit and put them back after the reload. Results show
        // as a pop-up rather than at the top of the page.
        (function () {
            var KEY = 'murvcCoachPlace:' + location.pathname + location.search;
            var portal = document.querySelector('.coach-portal');
            if (!portal) { return; }

            var flash = document.getElementById('coach-flash');
            var flashTimer = null;
            var showFlash = function (html) {
                if (!flash) { return; }
                if (html !== undefined) { flash.innerHTML = html; }
                if (flash.textContent.trim() === '') { return; }
                clearTimeout(flashTimer);
                flash.classList.remove('is-fading');
                flash.classList.add('coach-flash-toast');
                flashTimer = setTimeout(function () {
                    flash.classList.add('is-fading');
                    flashTimer = setTimeout(function () {
                        flash.classList.remove('coach-flash-toast', 'is-fading');
                        flash.innerHTML = '';
                    }, 600);
                }, 4000);
            };

            window.murvcCoachVerdict = function (select) {
                var form = select.form;
                var card = select.closest('.coach-applicant-card');
                var previous = '';
                Array.prototype.forEach.call(select.options, function (o) { if (o.defaultSelected) { previous = o.value; } });
                var body = new FormData(form);
                body.append('action', 'coach_set_verdict');
                if (card && card.getAttribute('data-card-context') === 'board') { body.append('card_context', 'board'); }
                select.disabled = true;
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        select.disabled = false;
                        if (!res || !res.success) {
                            select.value = previous;
                            showFlash(res && res.data && res.data.notice ? res.data.notice : '<div class="coach-portal-notice"><p>That verdict could not be saved — please try again.</p></div>');
                            return;
                        }
                        Array.prototype.forEach.call(select.options, function (o) { o.defaultSelected = (o.value === select.value); });
                        if (card) {
                            card.className = card.className.replace(/\bverdict-\S+/g, '').trim();
                            if (res.data.status) { card.classList.add('verdict-' + res.data.status); }
                            if (card.hasAttribute('data-has-verdict')) { card.setAttribute('data-has-verdict', res.data.status ? '1' : '0'); }
                            var chips = card.querySelector('.cac-verdicts');
                            if (chips) { chips.innerHTML = res.data.chips; }
                        }
                        showFlash(res.data.notice);
                    })
                    .catch(function () {
                        // Couldn't reach the server this way — fall back to a
                        // normal save (keeping the coach's place).
                        select.disabled = false;
                        savePlace(form);
                        form.submit();
                    });
            };

            var savePlace = function (form) {
                if (!form || !form.querySelector('input[name="coach_action"]')) { return; }
                var search = document.getElementById('coach-search');
                var mine = document.getElementById('coach-filter-mine');
                var notesFor = '';
                if (form.closest('.coach-note-form, .coach-note-delete')) {
                    var app = form.querySelector('input[name="application_id"]');
                    notesFor = app ? app.value : '';
                }
                try {
                    sessionStorage.setItem(KEY, JSON.stringify({
                        y: window.scrollY,
                        q: search ? search.value : '',
                        mine: mine ? mine.checked : false,
                        notes: notesFor,
                        at: Date.now()
                    }));
                } catch (e) {}
            };
            portal.addEventListener('submit', function (event) { savePlace(event.target); });

            var saved = null;
            try {
                saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
                sessionStorage.removeItem(KEY);
            } catch (e) {}
            if (!saved || Date.now() - saved.at > 60000) { return; }

            var search = document.getElementById('coach-search');
            var mine = document.getElementById('coach-filter-mine');
            if (search && saved.q) { search.value = saved.q; search.dispatchEvent(new Event('input')); }
            if (mine && saved.mine) { mine.checked = true; mine.dispatchEvent(new Event('change')); }
            if (saved.notes) {
                portal.querySelectorAll('.coach-note-form input[name="application_id"]').forEach(function (input) {
                    if (input.value === saved.notes && !input.closest('.coach-note-edit')) {
                        var panel = input.closest('details');
                        if (panel) { panel.open = true; }
                    }
                });
            }
            var restore = function () { window.scrollTo(0, saved.y); };
            restore();
            window.addEventListener('load', restore);
            showFlash();
        })();
        </script>

        <script>
        // Attendance: tap to mark (or tap again to undo) the chosen date for
        // this team. Saves in the background and refreshes the card's
        // history without reloading the page.
        (function () {
            var bar = document.getElementById('coach-attendance-bar');
            if (!bar) { return; }
            document.addEventListener('click', function (event) {
                var btn = event.target.closest('.coach-attend-btn');
                if (!btn || btn.disabled) { return; }
                var mark = btn.getAttribute('data-marked') !== '1';
                var body = new FormData();
                body.append('action', 'coach_mark_attendance');
                body.append('nonce', bar.getAttribute('data-nonce'));
                body.append('season', bar.getAttribute('data-season'));
                body.append('team', bar.getAttribute('data-team'));
                body.append('date', bar.getAttribute('data-date'));
                body.append('user_id', btn.getAttribute('data-user'));
                body.append('email', btn.getAttribute('data-email'));
                body.append('mark', mark ? '1' : '');
                btn.disabled = true;
                fetch(bar.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        btn.disabled = false;
                        if (!res || !res.success) {
                            alert(res && res.data && res.data.message ? res.data.message : 'Could not save attendance — please try again.');
                            return;
                        }
                        btn.setAttribute('data-marked', res.data.marked ? '1' : '0');
                        btn.classList.toggle('is-marked', !!res.data.marked);
                        btn.innerHTML = res.data.marked ? '&#10003; Attended' : 'Mark attended';
                        btn.title = res.data.marked ? 'Click to undo' : 'Mark as attended on the chosen date';
                        var card = btn.closest('.coach-applicant-card');
                        if (card) {
                            var count = card.querySelector('.coach-attendance-count');
                            var history = card.querySelector('.coach-attendance-history');
                            if (count) { count.textContent = res.data.count; }
                            if (history) { history.innerHTML = res.data.history; }
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        alert('Could not save attendance — check your connection and try again.');
                    });
            });
        })();
        </script>

        <style>
        .coach-attendance-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 8px 12px;
            margin: 8px 0 14px;
        }

        .coach-attendance-bar input[type=date] {
            margin-left: 6px;
        }

        .coach-attend-btn.is-marked {
            background: #edf7ee;
            border-color: #46b450;
            color: #1e6b2a;
            font-weight: 600;
        }

        .coach-attendance-list {
            margin: 6px 0 0 18px;
            padding: 0;
        }

        .coach-attendance-list small,
        .coach-attendance-empty {
            color: #777;
        }

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

        /* After a save, the result floats over the spot the coach was at. */
        .coach-flash-toast {
            position: fixed;
            left: 50%;
            bottom: 16px;
            transform: translateX(-50%);
            width: calc(100% - 32px);
            max-width: 520px;
            z-index: 9999;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
            transition: opacity 0.6s;
        }

        .coach-flash-toast > div {
            margin: 0;
        }

        .coach-flash-toast.is-fading {
            opacity: 0;
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
        /* One typeface throughout the portal: headings, summaries, form
           controls and tables all inherit the page body font instead of
           the theme's mixed families. */
        .coach-portal h2, .coach-portal h3, .coach-portal h4,
        .coach-portal summary, .coach-portal input, .coach-portal select,
        .coach-portal textarea, .coach-portal button, .coach-portal table,
        .coach-portal dl, .coach-portal p {
            font-family: inherit;
        }

        .coach-emergency {
            font-size: 13px;
            margin: 8px 0 2px 0;
            padding: 6px 8px;
            background: #fff7f0;
            border-left: 3px solid #e07b00;
            border-radius: 4px;
        }

        /* Transfer chip: distinct from verdict colours, club name inline,
           truncated so long club names never blow the card layout (full
           name in the hover title). */
        .chip-transfer,
        .chip-itc,
        .chip-freeagent,
        .chip-new,
        .chip-returning {
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
            vertical-align: middle;
        }

        .chip-transfer {
            background: #eee6f7;
            color: #4b2e83;
            border: 1px solid #b9a3d8;
        }

        /* ITC: overseas clearance, slowest paperwork — warm warning tint. */
        .chip-itc {
            background: #fdeee0;
            color: #8a4b00;
            border: 1px solid #e0a86b;
        }

        /* Free agent: no paperwork needed — calm green. */
        .chip-freeagent {
            background: #e6f4ea;
            color: #1a5c31;
            border: 1px solid #9fcfae;
        }

        /* New to VVL: fresh blue. */
        .chip-new {
            background: #e7f1fa;
            color: #1d4f7c;
            border: 1px solid #9cc3e5;
        }

        /* Returning Renegade: neutral grey — the unremarkable happy path. */
        .chip-returning {
            background: #f0f0f1;
            color: #50575e;
            border: 1px solid #c3c4c7;
        }

        .chip-sameteam {
            background: #e8f1fb;
            color: #1d4f8a;
            border: 1px solid #9ec1e6;
        }

        .chip-paypending {
            background: #fdf0f0;
            color: #a00;
            border: 1px solid #e6a0a0;
            font-weight: 600;
        }

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

        .coach-note-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 12px;
            margin-top: 4px;
            font-size: 12px;
        }

        /* "Edit  Delete" on one line; an open editor takes the full width. */
        .coach-note-edit[open] {
            flex: 1 1 100%;
        }

        .coach-note-edit summary,
        .coach-note-link {
            display: inline;
            cursor: pointer;
            color: #2271b1;
            text-decoration: underline;
            background: none;
            border: 0;
            padding: 0;
            font-size: 12px;
            list-style: none;
        }

        .coach-note-edit summary::-webkit-details-marker {
            display: none;
        }

        .coach-note-delete {
            margin: 0;
        }

        .coach-note-delete .coach-note-link {
            color: #b32d2e;
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

        .cac-age-flag {
            color: #a00;
            font-weight: 600;
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
        if (!isset($_POST['coach_action']) || !in_array($_POST['coach_action'], array('set_selection', 'add_note', 'edit_note', 'delete_note'), true)) {
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
        // Unpaid applicants are actionable too, so late applicants can be
        // assessed at trials before their fee lands.
        $application = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE id = %d AND season = %s AND application_status IN ('pending', 'accepted', 'awaiting_payment')
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

        if ($_POST['coach_action'] === 'edit_note' || $_POST['coach_action'] === 'delete_note') {
            // Only the note's author may change it; hiding the links isn't
            // enough, so the author is checked here against the stored row.
            $note_id = isset($_POST['note_id']) ? intval($_POST['note_id']) : 0;
            $notes_table = $wpdb->prefix . 'team_trial_notes';
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, author_id FROM $notes_table WHERE id = %d AND application_id = %d",
                $note_id, $application_id
            ));
            if (!$existing) {
                return '<div class="coach-portal-notice"><p>Note not found.</p></div>';
            }
            if (intval($existing->author_id) !== get_current_user_id()) {
                return '<div class="coach-portal-notice"><p>You can only change notes you wrote.</p></div>';
            }

            if ($_POST['coach_action'] === 'delete_note') {
                $wpdb->delete($notes_table, array('id' => $note_id), array('%d'));
                return '<div class="coach-portal-success"><p>Note deleted for ' . esc_html($application->name) . ' (#' . intval($application->trial_number) . ').</p></div>';
            }

            $note = isset($_POST['coach_note']) ? sanitize_textarea_field($_POST['coach_note']) : '';
            if ($note === '') {
                return '<div class="coach-portal-notice"><p>A note can\'t be empty. Use Delete to remove it.</p></div>';
            }
            $wpdb->update($notes_table,
                array('note' => $note, 'updated_date' => current_time('mysql')),
                array('id' => $note_id),
                array('%s', '%s'), array('%d')
            );

            return '<div class="coach-portal-success"><p>Note updated for ' . esc_html($application->name) . ' (#' . intval($application->trial_number) . ').</p></div>';
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
                $member->mobile ? self::format_phone($member->mobile) : '',
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
                $member->mobile ? self::format_phone($member->mobile) : '',
            ));
        }

        fclose($output);
        exit;
    }

    // ------------------------------------------------------------------
    // Printable trial book
    // ------------------------------------------------------------------

    /**
     * Every applicant in the competition on one printable page, with
     * everything the on-screen cards keep behind expanders: application
     * answers, emergency contacts, notes and all teams' verdicts.
     *
     * Rendered as a print-optimised page rather than a server-generated
     * PDF so the plugin carries no PDF library to maintain: the browser's
     * own "Save as PDF" produces a searchable file, on a phone as well as
     * a desktop. Halls without reception are the whole point, so the page
     * is self-contained — no external CSS, fonts or images.
     */
    public function maybe_export_applicants() {
        $context = $this->applicant_export_context();
        if (!$context) {
            return;
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo $this->render_applicant_book($context['team'], $context['season']);
        exit;
    }

    /**
     * Is this request a valid trial-book export, and for which team?
     * Returns array(team, season) or false. Separate from the rendering so
     * the authority check is testable without the exit.
     */
    private function applicant_export_context() {
        if (!isset($_POST['coach_action']) || $_POST['coach_action'] !== 'export_applicants') {
            return false;
        }

        if (!is_user_logged_in()
            || !isset($_POST['coach_nonce'])
            || !wp_verify_nonce($_POST['coach_nonce'], 'coach_portal_action')) {
            return false;
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field($_POST['coach_season']) : date('Y');
        $team = isset($_POST['coach_team']) ? sanitize_text_field($_POST['coach_team']) : '';

        // Same authority as the portal itself: you must coach this team.
        $my_teams = $this->get_my_teams($season);
        if (!isset($my_teams[$team])) {
            return false;
        }

        return array('team' => $team, 'season' => $season);
    }

    private function render_applicant_book($team, $season) {
        $my_team_codes = array_keys($this->get_my_teams($season));

        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
        $config = isset($teams_config[$team]) ? $teams_config[$team] : array('name' => $team, 'gender' => 'mixed', 'age_rule' => '');
        $applicants = $this->get_applicants_by_gender($config['gender'], $season, $team, $my_team_codes);
        $last_season_members = $this->get_last_season_team_members($team, $season);
        $verdict_labels = self::get_verdict_labels();

        $competition = $config['gender'] === 'womens' ? "Women's" : ($config['gender'] === 'mens' ? "Men's" : 'All');
        $title = $config['name'] . ' — trial book ' . $season;

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
           color: #222; margin: 0; padding: 24px; font-size: 13px; line-height: 1.45; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 15px; margin: 24px 0 8px; border-bottom: 2px solid #333; padding-bottom: 4px; }
    .meta { color: #555; margin: 0 0 16px; }
    .toolbar { background: #eef3fa; border: 1px solid #b9cde6; border-radius: 6px; padding: 10px 14px; margin-bottom: 20px; }
    .toolbar button { font: inherit; padding: 6px 14px; cursor: pointer; }
    table.index { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.index th, table.index td { border: 1px solid #ccc; padding: 2px 5px; text-align: left; vertical-align: top; }
    table.index th { background: #f2f2f2; }

    /* One applicant = a few dense lines, so a squad fits on a page or two. */
    .applicant { border-bottom: 1px solid #ccc; padding: 5px 0; }
    .a-head { margin-bottom: 1px; }
    .a-head strong { font-size: 14px; }
    .num { display: inline-block; background: #333; color: #fff; border-radius: 3px;
           padding: 0 5px; margin-right: 5px; font-variant-numeric: tabular-nums; }
    .chip { display: inline-block; border: 1px solid #999; border-radius: 9px; padding: 0 6px; font-size: 10px; }
    .chip-flag { border-color: #a00; color: #a00; }
    .line { margin: 0; }
    .lbl { color: #666; font-weight: 600; }
    .sep { color: #bbb; }
    .ice { color: #8a4b00; }
    .none { color: #777; font-style: italic; }

    /* Application answers are the bulky part: off unless asked for. */
    .app-answers { display: none; }
    body.with-answers .app-answers { display: block; }

    @page { margin: 10mm; }
    @media print {
        body { padding: 0; font-size: 9.5px; line-height: 1.35; }
        .toolbar { display: none; }
        .applicant { page-break-inside: avoid; }
        .a-head strong { font-size: 11px; }
        h1 { font-size: 15px; }
        h2 { font-size: 12px; page-break-after: avoid; }
        table.index th, table.index td { padding: 1px 4px; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
    <label style="margin-left: 14px;">
        <input type="checkbox" onchange="document.body.classList.toggle('with-answers', this.checked)">
        Include full application answers
    </label>
    <div style="margin-top: 6px;">Choose <strong>Save as PDF</strong> as the destination to keep it on your phone for trials — it stays searchable offline. Leaving the answers off keeps it to a couple of pages if you're printing on paper.</div>
</div>

<h1><?php echo esc_html($config['name']); ?> — trial book</h1>
<p class="meta">
    <?php echo esc_html($season); ?> season &middot; <?php echo esc_html($competition); ?> competition &middot;
    <?php echo count($applicants); ?> applicant<?php echo count($applicants) === 1 ? '' : 's'; ?> &middot;
    generated <?php echo esc_html(wp_date('j M Y, g:ia')); ?>
</p>

<?php if (empty($applicants)): ?>
    <p class="none">No applicants yet for this competition.</p>
<?php else: ?>

<h2>All applicants</h2>
<table class="index">
    <thead><tr><th>#</th><th>Name</th><th>Age</th><th>Positions</th><th>Applied for</th><th>Verdicts</th></tr></thead>
    <tbody>
        <?php foreach ($applicants as $a): ?>
            <tr>
                <td><?php echo intval($a['trial_number']); ?></td>
                <td><?php echo esc_html($a['name']); ?><?php if (!empty($a['payment_pending'])): ?> <strong style="color: #a00;">(unpaid)</strong><?php endif; ?></td>
                <td><?php echo esc_html($a['age']); ?></td>
                <td><?php echo esc_html($a['positions']); ?></td>
                <td><?php echo esc_html($a['teams_selected']); ?></td>
                <td><?php
                    $bits = array();
                    foreach ($a['selections'] as $sel) {
                        $bits[] = $sel['team'] . ': ' . (isset($verdict_labels[$sel['status']]) ? $verdict_labels[$sel['status']] : $sel['status']);
                    }
                    echo esc_html($bits ? implode(', ', $bits) : '—');
                ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p class="meta">★ marks <?php echo esc_html($config['name']); ?> in the "applied for" column.</p>

<h2>Applicant details</h2>
<p class="meta">ICE = emergency contact. Verdicts show every team's call, not just yours.</p>
<?php foreach ($applicants as $a): ?>
    <div class="applicant">
        <div class="a-head">
            <span class="num">#<?php echo intval($a['trial_number']); ?></span><strong><?php echo esc_html($a['name']); ?></strong>
            <span class="sep">·</span> <?php echo esc_html($a['age'] !== '' ? $a['age'] . 'y' : 'age ?'); ?><?php if ($a['dob_display']): ?> (<?php echo esc_html($a['dob_display']); ?>)<?php endif; ?>
            <span class="sep">·</span> <?php echo esc_html($a['positions'] ?: 'no position'); ?>
            <span class="sep">·</span> <?php echo esc_html($a['teams_selected'] ?: 'no teams'); ?>
            <?php if ($a['reg_type']): ?>
                <span class="chip"><?php echo esc_html($a['reg_type'] . ($a['transfer_club'] ? ': ' . $a['transfer_club'] : '')); ?></span>
            <?php endif; ?>
            <?php if ($this->was_on_team_last_season($last_season_members, $a['user_id'], $a['email'])): ?>
                <span class="chip">Same team</span>
            <?php endif; ?>
            <?php if (!empty($a['age_flag'])): ?>
                <span class="chip chip-flag">Over <?php echo esc_html($a['age_rule_label']); ?></span>
            <?php endif; ?>
            <?php if (!empty($a['payment_pending'])): ?>
                <span class="chip chip-flag">Payment pending</span>
            <?php endif; ?>
        </div>

        <div class="line">
            <span class="lbl">Contact</span> <?php echo esc_html($a['email']); ?><?php if (!empty($a['mobile'])): ?> <span class="sep">·</span> <?php echo esc_html(self::format_phone($a['mobile'])); ?><?php endif; ?>
            <span class="sep">·</span> <span class="lbl">Verdicts</span>
            <?php
            if (!empty($a['selections'])) {
                $bits = array();
                foreach ($a['selections'] as $sel) {
                    $bits[] = $sel['team'] . ' ' . (isset($verdict_labels[$sel['status']]) ? $verdict_labels[$sel['status']] : $sel['status']);
                }
                echo esc_html(implode(', ', $bits));
            } else {
                echo '<span class="none">none yet</span>';
            }
            ?>
        </div>

        <div class="line ice">
            <span class="lbl">ICE</span>
            <?php
            $contacts = $a['user_id'] ? self::get_emergency_contacts($a['user_id']) : array();
            if (!empty($contacts)) {
                $bits = array();
                foreach ($contacts as $contact) {
                    $bit = $contact['name'] ? $contact['name'] : 'unnamed';
                    if ($contact['relationship']) { $bit .= ' (' . $contact['relationship'] . ')'; }
                    if ($contact['number']) { $bit .= ' ' . self::format_phone($contact['number']); }
                    $bits[] = $bit;
                }
                echo esc_html(implode(' · ', $bits));
            } else {
                echo '<span class="none">none on file</span>';
            }
            ?>
        </div>

        <?php if (!empty($a['notes'])): ?>
            <div class="line">
                <span class="lbl">Notes</span>
                <?php
                $bits = array();
                foreach ($a['notes'] as $note) {
                    $bits[] = '[' . $note['author'] . ', ' . $note['date'] . '] ' . $note['note'];
                }
                echo esc_html(implode(' ', $bits));
                ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($a['form_data'])): ?>
            <div class="line app-answers">
                <span class="lbl">Application</span>
                <?php
                $bits = array();
                foreach ($a['form_data'] as $question => $answer) {
                    if ($answer === '' || $answer === null) {
                        continue;
                    }
                    $bits[] = $question . ': ' . (is_array($answer) ? implode(', ', $answer) : str_replace(array("\r\n", "\n"), ' ', $answer));
                }
                echo esc_html(implode(' · ', $bits));
                ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php endif; ?>

<p class="meta">Confidential — contains member contact and emergency details. Delete your copy once trials are done.</p>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    private function get_roster($team_code, $season) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT ta.role, ta.email,
                MAX(ta.user_id) AS user_id,
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

    /** Shared notes for one application, oldest first. */
    private function get_notes_for_application($application_id) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT n.*, u.display_name AS author
            FROM {$wpdb->prefix}team_trial_notes n
            LEFT JOIN {$wpdb->users} u ON u.ID = n.author_id
            WHERE n.application_id = %d
            ORDER BY n.created_date
        ", $application_id));

        $notes = array();
        foreach ($rows as $note) {
            $notes[] = self::shape_note($note);
        }
        return $notes;
    }

    /** One note row (with author display_name joined) as the cards use it. */
    private static function shape_note($row) {
        return array(
            'id' => intval($row->id),
            'author_id' => intval($row->author_id),
            'author' => $row->author ?: 'Unknown',
            'date' => date('j M Y', strtotime($row->created_date)),
            'edited' => !empty($row->updated_date),
            'note' => $row->note,
        );
    }

    /**
     * A confirmed player's trial application for the season (if any):
     * everything the applicant cards show — trial number, questionnaire,
     * notes, registration status — so confirmed players keep their full
     * context on the roster.
     */
    private function get_application_context($user_id, $email, $season) {
        global $wpdb;

        // Match by user id only when we actually have one — user_id = 0
        // would otherwise pair the card with any legacy application row.
        $app = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE season = %s AND ((user_id > 0 AND user_id = %d) OR email = %s)
            ORDER BY id DESC LIMIT 1
        ", $season, intval($user_id), $email));

        if (!$app) {
            return null;
        }

        $form_data = $app->form_data ? json_decode($app->form_data, true) : array();
        $form_data = is_array($form_data) ? $form_data : array();

        return array(
            'id' => intval($app->id),
            'trial_number' => intval($app->trial_number),
            'form_data' => $form_data,
            'notes' => $this->get_notes_for_application(intval($app->id)),
            'reg_type' => isset($form_data['Registration Type']) ? $form_data['Registration Type'] : (intval($app->is_transfer_player) === 1 ? 'Club Transfer' : ''),
            'transfer_club' => isset($form_data['Transfer: Previous Club']) ? $form_data['Transfer: Previous Club'] : '',
        );
    }

    /**
     * The one expander row every card shares: emergency contact,
     * application details, and coach notes with the add-note form.
     * Application/Notes only render when an application exists.
     */
    private function render_card_expanders($email, $application_id, $form_data, $notes, $active_team, $season, $user_id = 0) {
        ob_start();
        echo $this->render_emergency_details($email);

        if (!empty($form_data)) {
            if (!$user_id && $email) {
                $by_email = get_user_by('email', $email);
                $user_id = $by_email ? $by_email->ID : 0;
            }
            $lowest_comp = $user_id
                ? TeamOversight_Database::get_lowest_eligible_competition(get_user_meta($user_id, 'birth_date', true), $season)
                : '';
            ?>
            <details class="coach-app-details">
                <summary>Application</summary>
                <dl class="coach-application-details">
                    <dt>Lowest eligible competition (<?php echo esc_html($season); ?>)</dt>
                    <dd><?php echo $lowest_comp !== '' ? esc_html($lowest_comp) : '<em>Unknown — no date of birth on their profile</em>'; ?></dd>
                    <?php foreach ($form_data as $question => $answer): ?>
                        <?php if ($answer !== '' && $answer !== null): ?>
                            <dt><?php echo esc_html($question); ?></dt>
                            <dd><?php echo nl2br(esc_html(is_array($answer) ? implode(', ', $answer) : $answer)); ?></dd>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </dl>
            </details>
            <?php
        }

        if ($application_id) {
            ?>
            <details class="coach-app-details">
                <summary>Notes (<?php echo count($notes); ?>)</summary>
                <?php foreach ($notes as $note): ?>
                    <?php $is_mine = !empty($note['id']) && $note['author_id'] === get_current_user_id(); ?>
                    <div class="coach-note">
                        <strong><?php echo esc_html($note['author']); ?></strong> <small><?php echo esc_html($note['date']); ?><?php echo !empty($note['edited']) ? ' (edited)' : ''; ?></small><br><?php echo nl2br(esc_html($note['note'])); ?>
                        <?php if ($is_mine): ?>
                            <div class="coach-note-actions">
                                <details class="coach-note-edit">
                                    <summary>Edit</summary>
                                    <form method="post" class="coach-note-form">
                                        <input type="hidden" name="coach_action" value="edit_note">
                                        <input type="hidden" name="note_id" value="<?php echo intval($note['id']); ?>">
                                        <input type="hidden" name="application_id" value="<?php echo intval($application_id); ?>">
                                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce', true, true); ?>
                                        <textarea name="coach_note" rows="3" required><?php echo esc_textarea($note['note']); ?></textarea>
                                        <button type="submit" class="button button-small">Save</button>
                                        <button type="button" class="button button-small" onclick="this.closest('details').open = false;">Cancel</button>
                                    </form>
                                </details>
                                <form method="post" class="coach-note-delete" onsubmit="return confirm('Delete this note? This can\'t be undone.');">
                                    <input type="hidden" name="coach_action" value="delete_note">
                                    <input type="hidden" name="note_id" value="<?php echo intval($note['id']); ?>">
                                    <input type="hidden" name="application_id" value="<?php echo intval($application_id); ?>">
                                    <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                    <?php wp_nonce_field('coach_portal_action', 'coach_nonce', true, true); ?>
                                    <button type="submit" class="coach-note-link">Delete</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <form method="post" class="coach-note-form">
                    <input type="hidden" name="coach_action" value="add_note">
                    <input type="hidden" name="application_id" value="<?php echo intval($application_id); ?>">
                    <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                    <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                    <textarea name="coach_note" rows="2" placeholder="Add a note visible to all coaches..." required></textarea>
                    <button type="submit" class="button button-small">Add Note</button>
                </form>
            </details>
            <?php
        }

        // Attendance history across every team, visible to every coach.
        // Shown for confirmed players too, who may have no application.
        $attendance = $this->get_person_attendance($season, $user_id, $email);
        ?>
        <details class="coach-app-details coach-attendance">
            <summary>Attendance (<span class="coach-attendance-count"><?php echo count($attendance); ?></span>)</summary>
            <div class="coach-attendance-history"><?php echo $this->render_attendance_list($attendance); ?></div>
        </details>
        <?php

        return ob_get_clean();
    }

    /**
     * Who played for this team LAST season, so a card can say they're
     * back on the same team. This is a different question from the VV
     * "Returning" chip, which is about the club: a Renegades player moving
     * from SL3M to SL2M is Returning but not Same team. One query per
     * page; keyed 'u<id>' and by lowercased email.
     */
    private function get_last_season_team_members($team_code, $season) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT user_id, email FROM {$wpdb->prefix}team_assignments
            WHERE team = %s AND season = %s AND is_active = 1
                AND role IN ('playing_member', 'training_only')
        ", $team_code, strval(intval($season) - 1)));

        $members = array();
        foreach ($rows as $row) {
            if (intval($row->user_id)) {
                $members['u' . intval($row->user_id)] = true;
            }
            if ($row->email) {
                $members[strtolower($row->email)] = true;
            }
        }
        return $members;
    }

    private function was_on_team_last_season($members, $user_id, $email) {
        if (empty($members)) {
            return false;
        }
        if (intval($user_id) && isset($members['u' . intval($user_id)])) {
            return true;
        }
        return $email !== '' && $email !== null && isset($members[strtolower($email)]);
    }

    /**
     * What OTHER teams have said about the players in this team's section:
     * their verdicts, and — once finalised — confirmed places. The
     * applicant cards already show every team's verdict; without this,
     * moving a player up into your team hid the fact that, say, PL2M has
     * selected them too. Two queries for the whole section.
     * Returns array(verdicts by application id, placements by person key).
     */
    private function get_other_team_claims($application_ids, $people, $active_team, $season) {
        global $wpdb;

        $verdicts = array();
        $application_ids = array_values(array_filter(array_map('intval', $application_ids)));
        if ($application_ids) {
            $ids = implode(',', $application_ids);
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT application_id, team, status FROM {$wpdb->prefix}team_trial_selections
                WHERE application_id IN ({$ids}) AND team <> %s
            ", $active_team));
            foreach ($rows as $row) {
                $verdicts[intval($row->application_id)][$row->team] = $row->status;
            }
        }

        $placements = array();
        $user_ids = array();
        $emails = array();
        foreach ($people as $person) {
            if (intval($person[0])) {
                $user_ids[] = intval($person[0]);
            }
            if ($person[1] !== '' && $person[1] !== null) {
                $emails[] = strtolower($person[1]);
            }
        }
        if ($user_ids || $emails) {
            $where = array();
            $params = array($season, $active_team);
            if ($user_ids) {
                $where[] = 'user_id IN (' . implode(',', array_fill(0, count($user_ids), '%d')) . ')';
                $params = array_merge($params, $user_ids);
            }
            if ($emails) {
                $where[] = 'LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '%s')) . ')';
                $params = array_merge($params, $emails);
            }
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT user_id, email, team, role FROM {$wpdb->prefix}team_assignments
                WHERE season = %s AND team <> %s AND is_active = 1
                    AND role IN ('playing_member', 'training_only')
                    AND (" . implode(' OR ', $where) . ")
            ", $params));
            foreach ($rows as $row) {
                $status = $row->role === 'training_only' ? 'confirmed_training' : 'confirmed';
                if (intval($row->user_id)) {
                    $placements['u' . intval($row->user_id)][$row->team] = $status;
                }
                if ($row->email) {
                    $placements[strtolower($row->email)][$row->team] = $status;
                }
            }
        }

        return array($verdicts, $placements);
    }

    /**
     * Chips for one card: other teams' verdicts, with a confirmed place on
     * a team replacing the verdict that led to it.
     */
    private function render_other_team_chips($claims, $application_id, $user_id, $email, $teams_config) {
        list($verdicts, $placements) = $claims;
        $teams = isset($verdicts[intval($application_id)]) ? $verdicts[intval($application_id)] : array();

        $placed = array();
        if (intval($user_id) && isset($placements['u' . intval($user_id)])) {
            $placed = $placements['u' . intval($user_id)];
        } elseif ($email && isset($placements[strtolower($email)])) {
            $placed = $placements[strtolower($email)];
        }
        foreach ($placed as $team => $status) {
            $teams[$team] = $status;
        }
        if (empty($teams)) {
            return '';
        }
        uksort($teams, 'strnatcasecmp');

        $labels = self::get_verdict_labels();
        $labels['confirmed'] = 'Confirmed';
        $labels['confirmed_training'] = 'Confirmed (Training Only)';
        $html = '';
        foreach ($teams as $team => $status) {
            $label = isset($labels[$status]) ? $labels[$status] : ucfirst($status);
            $class = strpos($status, 'confirmed') === 0 ? 'confirmed' : $status;
            $name = isset($teams_config[$team]) ? $teams_config[$team]['name'] : $team;
            $html .= '<span class="verdict-chip verdict-chip-' . esc_attr($class) . '" title="' . esc_attr($name . ' — ' . $label) . '">'
                . esc_html($team) . ': ' . esc_html($label) . '</span> ';
        }
        return $html;
    }

    /**
     * Applied but hasn't paid the trial fee. They can be trialled and
     * given verdicts; the club's Finalise step holds them back until the
     * fee is paid (or marked paid).
     */
    public static function render_payment_pending_chip() {
        return '<span class="verdict-chip chip-paypending" title="Trial fee not paid yet. They can trial and receive verdicts, but won\'t be finalised onto a team until it\'s paid.">Payment pending</span>';
    }

    private function render_same_team_chip($team_code, $season) {
        $last = intval($season) - 1;
        return '<span class="verdict-chip chip-sameteam" title="Played for ' . esc_attr($team_code) . ' in ' . $last . ' — back on the same team, not just returning to the club.">↩ Same team</span>';
    }

    /**
     * VV registration-status chip — every applicant gets one:
     * New / Returning / Free Agent / Transfer / ITC. Empty string only
     * for legacy applications with no derivable status.
     */
    private function render_reg_chip($reg_type, $club) {
        if ($reg_type === '' || $reg_type === null) {
            return '';
        }
        if ($reg_type === 'ITC') {
            $class = 'chip-itc';
            $label = 'ITC' . ($club ? ': ' . $club : '');
            $title = 'International Transfer Certificate required for Premier League 1 — registered with a volleyball federation in another country. Allow extra processing time.';
        } elseif ($reg_type === 'Free Agent') {
            $class = 'chip-freeagent';
            $label = 'FA' . ($club ? ': ' . $club : '');
            $title = 'Free agent — skipped at least one VVL season, no club transfer required' . ($club ? ' (previously ' . $club . ')' : '') . '.';
        } elseif ($reg_type === 'New') {
            $class = 'chip-new';
            $label = 'New';
            $title = 'New to VVL — first Volleyball Victoria League season, no transfer paperwork.';
        } elseif ($reg_type === 'Returning') {
            $class = 'chip-returning';
            $label = 'Returning';
            $title = 'Renegades history with no other VVL club since — no transfer paperwork.';
        } else {
            $class = 'chip-transfer';
            $label = '⇄ ' . ($club ? $club : 'Transfer');
            $title = 'Club transfer required' . ($club ? ' — transferring from ' . $club : '') . '.';
        }
        return '<span class="verdict-chip ' . $class . '" title="' . esc_attr($title) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Normalise an Australian phone number for display: restore the
     * leading zero that spreadsheets/imports strip (411222333 becomes
     * 0411 222 333), fold +61 forms back to local, and space-group.
     * Returns the input unchanged when it doesn't look like an AU number.
     */
    public static function format_phone($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $raw);

        // +61 / 61 country-code forms -> local 0-form.
        if (preg_match('/^61([2-478]\d{8})$/', $digits, $m)) {
            $digits = '0' . $m[1];
        }
        // Nine digits starting 4 (mobile) or 2/3/7/8 (landline): the
        // classic stripped leading zero.
        if (preg_match('/^[2-478]\d{8}$/', $digits)) {
            $digits = '0' . $digits;
        }

        if (preg_match('/^04\d{8}$/', $digits)) {
            return substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7); // 04xx xxx xxx
        }
        if (preg_match('/^0[2378]\d{8}$/', $digits)) {
            return substr($digits, 0, 2) . ' ' . substr($digits, 2, 4) . ' ' . substr($digits, 6); // 0x xxxx xxxx
        }
        return $raw;
    }

    /** Digits-only form for tel: links (leading zero restored). */
    public static function phone_tel_href($raw) {
        return preg_replace('/[^0-9+]/', '', self::format_phone($raw));
    }

    /**
     * The emergency contacts on a member's profile, in order. The profile
     * form holds up to two: the primary (emergency_contact_*) and a second
     * set stored under UM's duplicated-field keys (_37/_38/_39) — those are
     * a genuine second contact, not a legacy duplicate of the first.
     * Returns rows of name / number / relationship; empty when none.
     */
    public static function get_emergency_contacts($user_id) {
        $sets = array(
            array('emergency_contact_name', 'emergency_contact_number', 'emergency_contact_relationship'),
            array('emergency_contact_name_37', 'emergency_contact_number_38', 'emergency_contact_relationship_39'),
        );

        $contacts = array();
        foreach ($sets as $keys) {
            $name = get_user_meta($user_id, $keys[0], true);
            $number = get_user_meta($user_id, $keys[1], true);
            $relationship = get_user_meta($user_id, $keys[2], true);
            if ($name || $number) {
                $contacts[] = array('name' => $name, 'number' => $number, 'relationship' => $relationship);
            }
        }
        return $contacts;
    }

    /**
     * Emergency contacts from the member's profile. The profile form holds
     * up to two: the primary (emergency_contact_*) and a second set stored
     * under UM's duplicated-field keys (_37/_38/_39). Rendered as the same
     * details-dropdown pattern as Application/Notes so coaches can reach
     * them at trainings.
     */
    public static function render_emergency_details($email) {
        $user = get_user_by('email', $email);
        $contacts = $user ? self::get_emergency_contacts($user->ID) : array();

        ob_start();
        ?>
        <details class="coach-app-details">
            <summary>Emergency contact<?php echo count($contacts) > 1 ? 's' : ''; ?></summary>
            <?php if (!empty($contacts)): ?>
                <?php foreach ($contacts as $contact): ?>
                    <p class="coach-emergency">
                        <strong><?php echo esc_html($contact['name'] ?: 'Name not recorded'); ?></strong><?php if ($contact['relationship']): ?> (<?php echo esc_html($contact['relationship']); ?>)<?php endif; ?>
                        <?php if ($contact['number']): ?>
                            &middot; <a href="tel:<?php echo esc_attr(self::phone_tel_href($contact['number'])); ?>"><?php echo esc_html(self::format_phone($contact['number'])); ?></a>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="coach-emergency">No emergency contact recorded on this member's profile — worth chasing before the season starts.</p>
            <?php endif; ?>
        </details>
        <?php
        return ob_get_clean();
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
            SELECT s.status, s.application_id, a.name, a.email, a.trial_number, a.user_id, a.preferred_positions,
                a.is_transfer_player, a.form_data, a.application_status,
                um_mobile.meta_value AS mobile
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            LEFT JOIN {$wpdb->usermeta} um_mobile ON a.user_id = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            WHERE s.team = %s AND s.season = %s
                AND s.status IN ('selected', 'training_only', 'tentative')
                AND a.application_status IN ('pending', 'accepted', 'awaiting_payment')
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
        $rows = array_values(array_filter($rows, function ($row) use ($playing_emails) {
            return !in_array(strtolower($row->email), $playing_emails, true);
        }));

        // Carry the over-age flag into the team list so a tentatively
        // selected over-age player stays visibly exemption-dependent.
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
        $age_rule = isset($teams_config[$team_code]) ? $teams_config[$team_code]['age_rule'] : '';
        $cutoff = $age_rule ? TeamOversight_Database::get_dob_cutoff($age_rule, $season) : null;

        foreach ($rows as $row) {
            $row->age_flag = false;
            $row->age = '';
            $row->dob_display = '';
            $row->rule_label = strtoupper($age_rule);
            $row->is_transfer = intval($row->is_transfer_player) === 1;
            $decoded = $row->form_data ? json_decode($row->form_data, true) : array();
            $row->transfer_club = (is_array($decoded) && isset($decoded['Transfer: Previous Club'])) ? $decoded['Transfer: Previous Club'] : '';
            $row->reg_type = (is_array($decoded) && isset($decoded['Registration Type'])) ? $decoded['Registration Type'] : ($row->is_transfer ? 'Club Transfer' : '');
            if ($cutoff) {
                $birth_date = get_user_meta($row->user_id, 'birth_date', true);
                $birth_ts = $birth_date ? strtotime(str_replace('/', '-', $birth_date)) : false;
                if ($birth_ts && date('Y-m-d', $birth_ts) < $cutoff) {
                    $row->age_flag = true;
                    $row->age = (new DateTime('@' . $birth_ts))->diff(new DateTime())->y;
                    $row->dob_display = date('d/m/Y', $birth_ts);
                }
            }
        }

        return $rows;
    }

    /**
     * All paid/reviewable applicants in a competition for a season, with
     * every team's selection verdicts and all shared notes attached.
     * Sorted: picked-my-active-team first, then by trial number.
     */
    private function get_applicants_by_gender($gender, $season, $active_team, $my_team_codes) {
        global $wpdb;

        // Unpaid applicants are included (flagged Payment pending) so late
        // applicants can be trialled; expired ones (unpaid 7+ days) are not.
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}trial_applications
            WHERE season = %s
                AND application_status IN ('pending', 'accepted', 'awaiting_payment')
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
        $teams_config = $database->get_teams_config($season);

        // The active team's DOB cutoff, so over-age applicants are flagged
        // (they can still be selected — VV can grant exemptions).
        $active_age_rule = isset($teams_config[$active_team]) ? $teams_config[$active_team]['age_rule'] : '';
        $age_cutoff = $active_age_rule ? TeamOversight_Database::get_dob_cutoff($active_age_rule, $season) : null;

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
            $notes_by_app[$note->application_id][] = self::shape_note($note);
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
            $dob_display = '';
            $age_flag = false;
            $birth_date = get_user_meta($row->user_id, 'birth_date', true);
            if ($birth_date) {
                $birth_ts = strtotime(str_replace('/', '-', $birth_date));
                if ($birth_ts) {
                    $age = (new DateTime('@' . $birth_ts))->diff(new DateTime())->y;
                    $dob_display = date('d/m/Y', $birth_ts);
                    if ($age_cutoff && date('Y-m-d', $birth_ts) < $age_cutoff) {
                        $age_flag = true;
                    }
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
                'user_id' => intval($row->user_id),
                'payment_pending' => $row->application_status === 'awaiting_payment',
                'trial_number' => intval($row->trial_number),
                'is_transfer' => intval($row->is_transfer_player) === 1,
                'transfer_club' => isset($form_data['Transfer: Previous Club']) ? $form_data['Transfer: Previous Club'] : '',
                'reg_type' => isset($form_data['Registration Type']) ? $form_data['Registration Type'] : (intval($row->is_transfer_player) === 1 ? 'Club Transfer' : ''),
                'name' => $row->name,
                'email' => $row->email,
                'mobile' => $row->user_id ? get_user_meta($row->user_id, 'mobile_number', true) : '',
                'age' => $age,
                'dob_display' => $dob_display,
                'age_flag' => $age_flag,
                'age_rule_label' => strtoupper($active_age_rule),
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
