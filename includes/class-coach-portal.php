<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end portal for coaches: [team_coach_portal]
 *
 * Visible only to logged-in users holding an active coach or assistant
 * coach team assignment for a season whose access hasn't ended
 * (coach_access_open), or committee access. Coaches work one team at a time (switcher for
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
 *
 * From the 2027 season a Selected / Training Only verdict is an offer
 * (offers are how selection works from then: there is no switch, club
 * decision, 28 Oct 2026). A coach
 * presses Send offer, the committee (administrators) approves it, the
 * site emails the player, and the player accepts or declines and pays the
 * deposit on the website. The portal shows where each offer is up to (the
 * card line, the Players heading, the flow chart at the top), the buttons
 * a coach may use at each step, a Send offers to all panel, the Declined
 * list and a Locked in chip on Confirmed players, and never a dollar
 * figure (percentages only). The portal sends no email itself: offers,
 * reminders and withdrawals are queued by TeamOversight_Offers and sent by
 * TeamOversight_Offer_Emails. There is no coach-recorded acceptance any
 * more (the retired set_offer action changes nothing and asks for a
 * reload). A season before 2027 ignores offers and is exactly as 1.75.3.
 *
 * From 2027 a Rejected player's card also carries Send "not selected" email
 * (1.80.0, club decision, 28 Oct 2026): nothing tells a rejected player anything
 * until someone with edit access to THAT team presses it (or Send "not
 * selected" emails for the board), and the email is about that one team
 * only. The card says plainly what was sent, when and by whom
 * (TeamOversight_Not_Selected decides and sends; this class draws).
 */
class TeamOversight_Coach_Portal {

    const COACH_ROLES = "'coach', 'assistant_coach'";
    const SELECTION_STATUSES = array('tentative', 'selected', 'training_only', 'rejected');

    /** First season whose offers need the player's acceptance before Finalise. */
    const OFFER_ACCEPTANCE_FROM = 2027;

    /**
     * The coach_action values that drive an offer (Send offer and the rest).
     * set_offer, the coach recording the player's yes before offers, is
     * retired: a page left open from before 1.79.0 gets a polite "reload"
     * and nothing changes. 1.80.0 adds the two "not selected" buttons
     * (offer_not_selected on a Rejected card, offer_bulk_not_selected for
     * the board), which go through the same handlers and checks.
     */
    const OFFER_ACTIONS = array('offer_request', 'offer_cancel', 'offer_withdraw', 'offer_remind', 'offer_extend', 'offer_move_declined', 'offer_bulk_request', 'offer_not_selected', 'offer_bulk_not_selected');

    /** The coach_action values that send many at once (the page reloads afterwards, and a refusal repaints no single card). */
    const BULK_ACTIONS = array('offer_bulk_request', 'offer_bulk_not_selected');

    /** The verdicts that are an offer (Tentative and Rejected can't be offered). */
    const OFFER_VERDICTS = array('selected', 'training_only');

    /** Verdicts that carry a team position (1.74.0). */
    const POSITION_STATUSES = array('tentative', 'selected', 'training_only');

    /**
     * The positions a coach can give a player for their team. Stored on
     * the team's verdict (team_trial_selections.team_position), never on
     * the application, whose positions are the player's own answer.
     */
    public static function get_team_positions() {
        return array(
            'pass_hitter' => 'Pass hitter',
            'opposite' => 'Opposite',
            'setter' => 'Setter',
            'middle' => 'Middle',
            'libero' => 'Libero',
        );
    }

    /** A team position's label, or '' for none / an unknown key. */
    public static function team_position_label($key) {
        $positions = self::get_team_positions();
        return ($key !== null && isset($positions[$key])) ? $positions[$key] : '';
    }

    /**
     * The position on a card's top line: the team position once the coach
     * has given one (for a verdict that takes one), else the positions the
     * player applied for (which are also in the card's Application).
     */
    private static function meta_position_html($status, $team_position, $applied) {
        $label = in_array($status, self::POSITION_STATUSES, true) ? self::team_position_label($team_position) : '';
        if ($label !== '') {
            return ' &middot; <strong title="' . esc_attr('Team position' . ($applied !== '' ? ' (applied for ' . $applied . ')' : '')) . '">' . esc_html($label) . '</strong>';
        }
        return $applied !== '' ? ' &middot; ' . esc_html($applied) : '';
    }

    public static function get_verdict_labels() {
        return array(
            'tentative' => 'Tentative',
            'selected' => 'Selected',
            'training_only' => 'Training Only',
            'rejected' => 'Rejected',
        );
    }

    /**
     * Does this season use the offer -> accepted -> finalised step? Earlier
     * seasons finalise Selected / Training Only verdicts as they are.
     */
    public static function offer_acceptance_applies($season) {
        return intval($season) >= self::OFFER_ACCEPTANCE_FROM;
    }

    /**
     * Is the offer flow in use for this season? It is for every season from
     * 2027 (TeamOversight_Offers::applies(): offers are how selection works,
     * with no switch). A season before 2027 shows the 1.75.3 wording and
     * controls everywhere.
     */
    public static function offers_on($season) {
        return class_exists('TeamOversight_Offers') && TeamOversight_Offers::applies((string) $season);
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
        add_action('wp_ajax_coach_set_offer', array($this, 'ajax_set_offer'));
        add_action('wp_ajax_coach_set_position', array($this, 'ajax_set_position'));
        add_action('wp_ajax_coach_set_member_position', array($this, 'ajax_set_member_position'));
    }

    // ------------------------------------------------------------------
    // Attendance
    // ------------------------------------------------------------------

    /** Season attendance, loaded once per request: person key => rows. */
    private $attendance_cache = array();

    /** True while rendering a team the viewer may see but not change. */
    private $read_only = false;

    /**
     * Set by handle_offer_action() once the team, season and application
     * have all been verified for an offer button: the background save may
     * then send the card's fresh state back even when the action itself was
     * refused (a stale page repaints itself). Never true for a bad nonce or
     * a team the coach may not edit, so a refusal never leaks a card.
     */
    private $offer_refresh_ok = false;

    /** Set when an offer action moves cards between lists (or changes many): the page reloads instead of patching one card. */
    private $offer_reload = false;

    /** Can this player's cell be priced for an offer? (cell_check() results for this request, by player, team and role.) */
    private $cell_cache = array();

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
        if ($this->read_only) {
            return '';
        }
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

        if (!in_array($team, $this->get_editable_team_codes($season), true)) {
            wp_send_json_error(array('message' => 'You can only mark attendance for teams you coach (or can edit as committee).'));
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
            wp_send_json_error(array('notice' => '<div class="coach-portal-notice"><p>Your session has expired — please reload the page.</p></div>', 'expired' => true));
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field(wp_unslash($_POST['coach_season'])) : '';
        $this->offer_refresh_ok = false;
        $notice = $this->handle_actions($this->get_editable_team_codes($season), $season);
        if (strpos($notice, 'coach-portal-success') === false) {
            $data = array(
                'notice' => $notice !== '' ? $notice : '<div class="coach-portal-notice"><p>That verdict could not be saved.</p></div>',
                // A stale page: the toast stays up, since only a reload helps.
                'expired' => $notice === self::expired_notice(),
            );
            // The offer behind the verdict refused the change (withdraw it
            // first): the card is sent back as it really is, so a stale page repaints.
            if ($this->offer_refresh_ok) {
                $data['refresh'] = $this->card_refresh($season);
            }
            wp_send_json_error($data);
        }

        wp_send_json_success(array_merge(array('notice' => $notice), $this->card_refresh($season)));
    }

    /**
     * An offer button (Send offer, Cancel request, Withdraw, Remind, +7
     * days, Move to declined, Send offers to all), in the background like a
     * verdict. Only those coach_action values are taken, plus the retired
     * set_offer from a page opened before 1.79.0 (it changes nothing, says
     * so and repaints the card). Same handle_actions() as the form post:
     * nonce, may edit this team, actionable application, then the one
     * TeamOversight_Offers method bound to that application and team.
     *
     * On success the card is sent back refreshed. A refusal on a verified
     * card (a stale page: the committee approved it a moment ago, the player
     * answered) sends the fresh card back as 'refresh' so the page repaints
     * itself. An action that moves cards between lists, or changes several,
     * asks for 'reload' instead, and the notice waits for the reloaded page.
     */
    public function ajax_set_offer() {
        $action = isset($_POST['coach_action']) ? $_POST['coach_action'] : '';
        if (!is_user_logged_in() || !is_string($action) || ($action !== 'set_offer' && !in_array($action, self::OFFER_ACTIONS, true))) {
            wp_send_json_error(array('notice' => '<div class="coach-portal-notice"><p>Your session has expired — please reload the page.</p></div>', 'expired' => true));
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field(wp_unslash($_POST['coach_season'])) : '';
        $this->offer_refresh_ok = false;
        $this->offer_reload = false;
        $notice = $this->handle_actions($this->get_editable_team_codes($season), $season);
        if (strpos($notice, 'coach-portal-success') === false) {
            $data = array(
                'notice' => $notice !== '' ? $notice : '<div class="coach-portal-notice"><p>That could not be saved.</p></div>',
                'expired' => $notice === self::expired_notice() || strpos($notice, 'coach-portal-expired') !== false,
            );
            if ($this->offer_refresh_ok && !in_array($action, self::BULK_ACTIONS, true)) {
                $data['refresh'] = $this->card_refresh($season);
            }
            wp_send_json_error($data);
        }

        if ($this->offer_reload || in_array($action, self::BULK_ACTIONS, true)) {
            // The page is about to reload: the notice is shown there.
            set_transient('murvc_coach_flash_' . get_current_user_id(), $notice, 60);
            wp_send_json_success(array('notice' => $notice, 'reload' => true));
        }

        wp_send_json_success(array_merge(array('notice' => $notice), $this->card_refresh($season)));
    }

    /**
     * Save a player's team position in the background, like a verdict.
     * Same handle_actions() as the form post: nonce, may edit this team,
     * actionable application, a Tentative / Selected / Training Only verdict.
     */
    public function ajax_set_position() {
        if (!is_user_logged_in() || !isset($_POST['coach_action']) || $_POST['coach_action'] !== 'set_position') {
            wp_send_json_error(array('notice' => '<div class="coach-portal-notice"><p>Your session has expired — please reload the page.</p></div>', 'expired' => true));
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field(wp_unslash($_POST['coach_season'])) : '';
        $notice = $this->handle_actions($this->get_editable_team_codes($season), $season);
        if (strpos($notice, 'coach-portal-success') === false) {
            wp_send_json_error(array(
                'notice' => $notice !== '' ? $notice : '<div class="coach-portal-notice"><p>That position could not be saved.</p></div>',
                'expired' => $notice === self::expired_notice(),
            ));
        }

        wp_send_json_success(array_merge(array('notice' => $notice), $this->card_refresh($season)));
    }

    /**
     * After a verdict, offer or position save: the card's verdict, its
     * chips, its offer line and buttons (2027 on; '' before) and its team
     * position control, read back from what was stored. From 2027 the reply
     * also carries the verdict menu's options, the Players heading's text
     * and the flow chart (offer_card_refresh()).
     */
    private function card_refresh($season) {
        global $wpdb;
        $application_id = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
        $team = isset($_POST['coach_team']) ? sanitize_text_field(wp_unslash($_POST['coach_team'])) : '';
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT s.status, s.offer_accepted, s.team_position, a.application_status, a.preferred_positions
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.application_id = %d AND s.team = %s
        ", $application_id, $team));
        $status = $row ? $row->status : '';
        $position = $row ? (string) $row->team_position : '';
        // No verdict any more: the top line goes back to the applied positions.
        $applied = $row ? $row->preferred_positions : $wpdb->get_var($wpdb->prepare("SELECT preferred_positions FROM {$wpdb->prefix}trial_applications WHERE id = %d", $application_id));
        if (self::offers_on($season)) {
            return $this->offer_card_refresh($season, $application_id, $team, $row, $position, $applied);
        }
        $board = isset($_POST['card_context']) && $_POST['card_context'] === 'board';
        $chips = $board
            ? self::render_board_verdict_chip($status)
            : $this->render_verdict_chips($this->get_selections_for_application($application_id, $season));

        return array(
            'status' => $status,
            'chips' => $chips,
            // A season before 2027 has no offer control (1.75.3 drew none there).
            'offer' => '',
            'position' => $this->render_position_control($application_id, $team, $season, $status, $position),
            // The card's top line follows the team position.
            'meta_position' => self::meta_position_html($status, $position, $this->format_positions($applied)),
        );
    }

    /**
     * The team position picker on a card, for a Tentative, Selected or
     * Training Only verdict on the active team. Viewers see the position
     * as text. '' otherwise.
     */
    private function render_position_control($application_id, $team, $season, $status, $position) {
        if (!in_array($status, self::POSITION_STATUSES, true)) {
            return '';
        }
        $label = self::team_position_label($position);
        if ($this->read_only) {
            return $label !== '' ? '<span class="cac-position-text">Team position: <strong>' . esc_html($label) . '</strong></span>' : '';
        }
        ob_start();
        ?>
        <form method="post" class="coach-position-form">
            <input type="hidden" name="coach_action" value="set_position">
            <input type="hidden" name="application_id" value="<?php echo intval($application_id); ?>">
            <input type="hidden" name="coach_team" value="<?php echo esc_attr($team); ?>">
            <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
            <?php wp_nonce_field('coach_portal_action', 'coach_nonce', false, true); ?>
            <label class="cac-verdict-label">Team position:
                <select name="team_position" onchange="if (window.murvcCoachPosition) { window.murvcCoachPosition(this); } else { this.form.submit(); }">
                    <option value=""<?php selected($label, ''); ?>>&mdash; Choose &mdash;</option>
                    <?php foreach (self::get_team_positions() as $key => $name): ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected($position, $key); ?>><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="button button-small">Save</button></noscript>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Team positions for a team's season, by account and by email: the
     * position set on the team list (team_positions, 1.75.0) over the one
     * given with the trial verdict, where it started. Two queries.
     * Returns array('users' => id => key, 'emails' => email => key).
     */
    private function get_team_positions_for_team($team, $season) {
        global $wpdb;
        $map = array('users' => array(), 'emails' => array());
        $add = function ($user_id, $email, $position) use (&$map) {
            if (intval($user_id) > 0) {
                $map['users'][intval($user_id)] = $position;
            }
            if ((string) $email !== '') {
                $map['emails'][strtolower((string) $email)] = $position;
            }
        };
        $from_verdicts = $wpdb->get_results($wpdb->prepare("
            SELECT a.user_id, a.email, s.team_position
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            WHERE s.team = %s AND s.season = %s AND s.team_position IS NOT NULL AND s.team_position <> ''
        ", $team, $season));
        foreach ($from_verdicts as $row) {
            $add($row->user_id, $row->email, $row->team_position);
        }
        foreach ($wpdb->get_results($wpdb->prepare("
            SELECT user_id, email, position FROM {$wpdb->prefix}team_positions WHERE team = %s AND season = %s
        ", $team, $season)) as $row) {
            $add($row->user_id, $row->email, $row->position);
        }
        return $map;
    }

    /**
     * The person's active playing place (playing member or training only)
     * on the team this season, by account or email, or null.
     */
    private static function playing_place($team, $season, $user_id, $email) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("
            SELECT ta.id, ta.user_id, ta.email, u.display_name
            FROM {$wpdb->prefix}team_assignments ta
            LEFT JOIN {$wpdb->users} u ON u.ID = ta.user_id
            WHERE ta.season = %s AND ta.team = %s AND ta.is_active = 1
                AND ta.role IN ('playing_member', 'training_only')
                AND ((%d > 0 AND ta.user_id = %d) OR (ta.email <> '' AND ta.email = %s))
            LIMIT 1
        ", $season, $team, intval($user_id), intval($user_id), (string) $email));
    }

    /**
     * Save (or with '' clear) a person's position on the team for the
     * season in team_positions: one row per person, by account, else email.
     */
    private static function store_team_position($team, $season, $user_id, $email, $position) {
        global $wpdb;
        $table = $wpdb->prefix . 'team_positions';
        $user_id = intval($user_id);
        $existing = $user_id > 0
            ? $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE season = %s AND team = %s AND user_id = %d", $season, $team, $user_id))
            : $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE season = %s AND team = %s AND (user_id IS NULL OR user_id = 0) AND email = %s", $season, $team, (string) $email));
        if ($position === '') {
            foreach ($existing as $id) {
                $wpdb->delete($table, array('id' => intval($id)), array('%d'));
            }
            return;
        }
        $fields = array('position' => $position, 'email' => (string) $email, 'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql'));
        if ($existing) {
            $wpdb->update($table, $fields, array('id' => intval($existing[0])), array('%s', '%s', '%d', '%s'), array('%d'));
            return;
        }
        $wpdb->insert($table, array_merge(array('season' => $season, 'team' => $team, 'user_id' => $user_id > 0 ? $user_id : null), $fields), array('%s', '%s', '%d', '%s', '%s', '%d', '%s'));
    }

    /**
     * The position menu on a confirmed player's card (team list), editors
     * only; viewers see the position as text.
     */
    private function render_member_position_control($team, $season, $user_id, $email, $position) {
        $label = self::team_position_label($position);
        if ($this->read_only) {
            return $label !== '' ? '<span class="cac-position-text">Team position: <strong>' . esc_html($label) . '</strong></span>' : '';
        }
        ob_start();
        ?>
        <form method="post" class="coach-position-form">
            <input type="hidden" name="coach_action" value="set_member_position">
            <input type="hidden" name="member_user_id" value="<?php echo intval($user_id); ?>">
            <input type="hidden" name="member_email" value="<?php echo esc_attr($email); ?>">
            <input type="hidden" name="coach_team" value="<?php echo esc_attr($team); ?>">
            <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
            <?php wp_nonce_field('coach_portal_action', 'coach_nonce', false, true); ?>
            <label class="cac-verdict-label">Team position:
                <select name="team_position" onchange="if (window.murvcCoachPosition) { window.murvcCoachPosition(this); } else { this.form.submit(); }">
                    <option value=""<?php selected($label, ''); ?>>&mdash; Choose &mdash;</option>
                    <?php foreach (self::get_team_positions() as $key => $name): ?>
                        <option value="<?php echo esc_attr($key); ?>"<?php selected($position, $key); ?>><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit" class="button button-small">Save</button></noscript>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Save a confirmed player's team position from the team list (a label,
     * so a coach of the team sets it, no admin step). In team_positions,
     * and on their trial verdict for the team too when there is one, so
     * the two never disagree.
     */
    private function save_member_position($team, $season) {
        global $wpdb;
        $user_id = isset($_POST['member_user_id']) ? intval($_POST['member_user_id']) : 0;
        $email = isset($_POST['member_email']) ? sanitize_email(wp_unslash($_POST['member_email'])) : '';
        $position = isset($_POST['team_position']) ? sanitize_key(wp_unslash($_POST['team_position'])) : '';
        if ($position !== '' && self::team_position_label($position) === '') {
            return '<div class="coach-portal-notice"><p>Please pick one of the positions.</p></div>';
        }
        $place = ($user_id > 0 || $email !== '') ? self::playing_place($team, $season, $user_id, $email) : null;
        if (!$place) {
            return '<div class="coach-portal-notice"><p>That player isn\'t on ' . esc_html($team) . ' — reload the page.</p></div>';
        }
        $user_id = intval($place->user_id);
        $email = (string) $place->email;
        self::store_team_position($team, $season, $user_id, $email, $position);
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            SET s.team_position = %s
            WHERE s.team = %s AND s.season = %s AND s.status IN ('tentative', 'selected', 'training_only')
                AND ((%d > 0 AND a.user_id = %d) OR a.email = %s)
        ", $position !== '' ? $position : null, $team, $season, $user_id, $user_id, $email));
        $who = esc_html($place->display_name ? $place->display_name : $email);
        if ($position === '') {
            return '<div class="coach-portal-success"><p>Team position cleared for ' . $who . ' on ' . esc_html($team) . '.</p></div>';
        }
        return '<div class="coach-portal-success"><p>' . $who . ': <strong>' . esc_html(self::team_position_label($position)) . '</strong> for ' . esc_html($team) . '.</p></div>';
    }

    /** After a team list position save: the card's menu and top line. */
    private function member_refresh($season) {
        global $wpdb;
        $team = isset($_POST['coach_team']) ? sanitize_text_field(wp_unslash($_POST['coach_team'])) : '';
        $user_id = isset($_POST['member_user_id']) ? intval($_POST['member_user_id']) : 0;
        $email = isset($_POST['member_email']) ? sanitize_email(wp_unslash($_POST['member_email'])) : '';
        $position = self::position_for_person($this->get_team_positions_for_team($team, $season), $user_id, $email);
        $applied = $wpdb->get_var($wpdb->prepare("
            SELECT preferred_positions FROM {$wpdb->prefix}trial_applications
            WHERE season = %s AND ((%d > 0 AND user_id = %d) OR email = %s)
            ORDER BY id DESC LIMIT 1
        ", $season, $user_id, $user_id, $email));
        return array(
            'position' => $this->render_member_position_control($team, $season, $user_id, $email, $position),
            'meta_position' => self::meta_position_html('selected', $position, $this->format_positions($applied)),
        );
    }

    /** Save a confirmed player's team position in the background. */
    public function ajax_set_member_position() {
        if (!is_user_logged_in() || !isset($_POST['coach_action']) || $_POST['coach_action'] !== 'set_member_position') {
            wp_send_json_error(array('notice' => '<div class="coach-portal-notice"><p>Your session has expired — please reload the page.</p></div>', 'expired' => true));
        }
        $season = isset($_POST['coach_season']) ? sanitize_text_field(wp_unslash($_POST['coach_season'])) : '';
        $notice = $this->handle_actions($this->get_editable_team_codes($season), $season);
        if (strpos($notice, 'coach-portal-success') === false) {
            wp_send_json_error(array(
                'notice' => $notice !== '' ? $notice : '<div class="coach-portal-notice"><p>That position could not be saved.</p></div>',
                'expired' => $notice === self::expired_notice(),
            ));
        }
        wp_send_json_success(array_merge(array('notice' => $notice), $this->member_refresh($season)));
    }

    /** A confirmed player's team position from get_team_positions_for_team(), or ''. */
    private static function position_for_person($map, $user_id, $email) {
        if (intval($user_id) > 0 && isset($map['users'][intval($user_id)])) {
            return $map['users'][intval($user_id)];
        }
        $email = strtolower((string) $email);
        return isset($map['emails'][$email]) ? $map['emails'][$email] : '';
    }

    /** Every team's verdict on one application, shaped for render_verdict_chips(). */
    private function get_selections_for_application($application_id, $season) {
        global $wpdb;
        $database = new TeamOversight_Database();
        $teams_config = $database->get_teams_config($season);
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT team, status, offer_accepted, team_position FROM {$wpdb->prefix}team_trial_selections
            WHERE application_id = %d
            ORDER BY team
        ", $application_id));
        $offers_on = self::offers_on($season);
        $index = $offers_on ? TeamOversight_Offers::index_for_applications(array(intval($application_id))) : array();
        $selections = array();
        foreach ($rows as $sel) {
            $selection = array(
                'team' => $sel->team,
                'team_name' => isset($teams_config[$sel->team]) ? $teams_config[$sel->team]['name'] : $sel->team,
                'status' => $sel->status,
                'position' => (string) $sel->team_position,
            );
            if ($offers_on) {
                $selection = array_merge($selection, self::offer_wording(isset($index[intval($application_id)][$sel->team]) ? $index[intval($application_id)][$sel->team] : null, $sel->status, $season));
            }
            $selections[] = $selection;
        }
        return $selections;
    }

    /**
     * A verdict's offer wording (2027 on): 'offer' (the offer in
     * force, or null), 'chip' ("Selected · offer sent", for the chips) and
     * 'plain' ("Selected - offer sent (reply by 14 Nov)", for the trial
     * book), both from TeamOversight_Offers and with no dollar figure.
     */
    private static function offer_wording($raw_offer, $status, $season) {
        $offer = self::effective_offer($raw_offer, $status);
        return array(
            'offer' => $offer,
            'chip' => TeamOversight_Offers::chip($offer, $status, array('season' => $season)),
            'plain' => TeamOversight_Offers::label($offer, 'plain', array('verdict' => $status, 'season' => $season)),
        );
    }

    /**
     * The selection board's own-verdict chip (players awaiting
     * finalisation), for a season before 2027 (from 2027 the offer's chip,
     * render_offer_verdict_chip(), is drawn instead, and uses this one for
     * Tentative and Rejected).
     */
    private static function render_board_verdict_chip($status) {
        if ($status === 'selected' || $status === 'training_only') {
            $label = $status === 'selected' ? 'Selected' : 'Training Only';
            $text = $label . ' — awaiting finalisation';
            return '<span class="verdict-chip verdict-chip-' . $status . '">' . esc_html($text) . '</span>';
        }
        if ($status === 'tentative') {
            return '<span class="verdict-chip verdict-chip-tentative">Tentative</span>';
        }
        if ($status === 'rejected') {
            return '<span class="verdict-chip verdict-chip-rejected">Rejected — leaves the board on reload</span>';
        }
        return '<span class="cac-unclaimed">No verdict — leaves the board on reload</span>';
    }

    // ------------------------------------------------------------------
    // Offers (1.76.0): states, card controls, flow chart, Declined list,
    // bulk send. For seasons from 2027 (offers_on($season)). Every piece of offer
    // wording comes from TeamOversight_Offers (label() / chip()); coaches
    // see states, dates and percentages, never a dollar figure.
    // ------------------------------------------------------------------

    /** '14 Nov' from a Y-m-d (or a datetime), '' when it isn't a date. */
    private static function short_date($date) {
        $date = substr((string) $date, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? date('j M', strtotime($date)) : '';
    }

    /**
     * The offer a card is drawn from: the latest offer for the application
     * and team, except that a cancelled one counts as none, and so does a
     * FINISHED one for the other role (the coach moved the verdict between
     * Selected and Training Only after it ended, so it says nothing about
     * the verdict in force now). A live offer always counts.
     */
    private static function effective_offer($offer, $status) {
        if (!$offer || $offer->state === TeamOversight_Offers::CANCELLED) {
            return null;
        }
        if ($offer->live !== 1 && TeamOversight_Offers::role_for_verdict((string) $status) !== (string) $offer->role) {
            return null;
        }
        return $offer;
    }

    /**
     * Where a player stands on the board, as one word: none (not an offer
     * verdict), unpaid, unsent (no live offer), awaiting, not_approved,
     * withdrawn, left, sent, sent_overdue, accepted, accepted_overdue,
     * locked_in, declined. Overdue is read from the offer's dates (it is
     * never stored).
     */
    private static function offer_phase($status, $offer, $payment_pending) {
        if (!in_array($status, self::OFFER_VERDICTS, true)) {
            return 'none';
        }
        if ($payment_pending) {
            return 'unpaid';
        }
        if (!$offer) {
            return 'unsent';
        }
        $overdue = false;
        if (in_array($offer->state, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), true)) {
            $deadline = TeamOversight_Offers::deadline_status($offer);
            $overdue = !empty($deadline['overdue']);
        }
        switch ($offer->state) {
            case TeamOversight_Offers::AWAITING:
                return 'awaiting';
            case TeamOversight_Offers::NOT_APPROVED:
                return 'not_approved';
            case TeamOversight_Offers::WITHDRAWN:
                return 'withdrawn';
            case TeamOversight_Offers::LEFT:
                return 'left';
            case TeamOversight_Offers::SENT:
                return $overdue ? 'sent_overdue' : 'sent';
            case TeamOversight_Offers::ACCEPTED:
                return $overdue ? 'accepted_overdue' : 'accepted';
            case TeamOversight_Offers::LOCKED_IN:
                return 'locked_in';
            case TeamOversight_Offers::DECLINED:
            case TeamOversight_Offers::MOVED_DECLINED:
                return 'declined';
        }
        return 'none';
    }

    /** Is the verdict held in place by its offer (sent, accepted or locked in)? A request still awaiting approval does not hold it: changing the verdict cancels the request. */
    private static function verdict_locked($phase) {
        return in_array($phase, array('sent', 'sent_overdue', 'accepted', 'accepted_overdue', 'locked_in'), true);
    }

    /**
     * Can this player's cell be priced for an offer right now? Cheap (no
     * pricing), remembered for the request. error_public is safe for
     * coaches: it names no class and no amount.
     */
    private function offer_cell($user_id, $season, $team, $status) {
        $role = TeamOversight_Offers::role_for_verdict((string) $status);
        $key = intval($user_id) . '|' . $season . '|' . $team . '|' . $role;
        if (!isset($this->cell_cache[$key])) {
            $this->cell_cache[$key] = $role === ''
                ? array('ok' => false, 'error_public' => 'Only Selected and Training Only players can be offered.')
                : TeamOversight_Offers::cell_check(intval($user_id), (string) $season, (string) $team, $role);
        }
        return $this->cell_cache[$key];
    }

    /**
     * The board chip for a Selected / Training Only player (2027 on): the
     * verdict and where the offer is up to ("Selected · offer sent"),
     * from TeamOversight_Offers::chip(). Other verdicts keep the usual chip.
     */
    private static function render_offer_verdict_chip($status, $offer, $season, $payment_pending = false) {
        if (!in_array($status, self::OFFER_VERDICTS, true)) {
            return self::render_board_verdict_chip($status);
        }
        $phase = self::offer_phase($status, $offer, $payment_pending);
        $text = TeamOversight_Offers::chip($offer, $status, array('season' => $season));
        return '<span class="verdict-chip verdict-chip-' . esc_attr($status) . ' offer-' . esc_attr(str_replace('_', '-', $phase)) . '">' . esc_html($text) . '</span>';
    }

    /** The "Locked in 12 Dec" chip beside Confirmed on a player who locked in through an offer ('' for anyone else). */
    private static function render_locked_in_chip($offer, $season) {
        if (!$offer || $offer->state !== TeamOversight_Offers::LOCKED_IN) {
            return '';
        }
        $label = TeamOversight_Offers::label($offer, 'card', array('verdict' => TeamOversight_Offers::verdict_for_role($offer->role), 'season' => $season));
        if (strpos($label, 'Locked in - ') === 0) {
            $label = 'Locked in';
        }
        return '<span class="verdict-chip verdict-chip-confirmed is-locked" title="Locked in by paying the deposit">' . esc_html($label) . '</span>';
    }

    /**
     * The verdict menu's options for a card once offers are on, "None"
     * included. While the offer holds the verdict (sent, accepted, locked
     * in) every other choice is disabled; the server gate refuses them too.
     */
    private static function render_verdict_select_options($status, $payment_pending, $locked) {
        $html = '<option value="clear"' . ($status === '' ? ' selected' : '') . ($locked ? ' disabled' : '') . '>&mdash; None &mdash;</option>';
        return $html . self::render_verdict_options($status, $payment_pending, $locked);
    }

    /**
     * A card's offer status line: the state in words ("Offer sent - reply by
     * 14 Nov", "Accepted - deposit 60% paid, due by 21 Nov"), then the small
     * print (when the email went, why an offer can't be sent). Everyone who
     * can see the team gets it, view-only committee included. '' when the
     * verdict is not an offer.
     *
     * $paid_cents: the ledger total on the player's season invoice, only
     * ever turned into a percentage. $mail: the offer's outbox statuses.
     * $block: the higher-team block for this player and team
     * (TeamOversight_Offers::higher_team_block(), null = none), explained on
     * the card of anyone who has no offer out.
     */
    private function render_offer_status($team, $season, $status, $offer, $payment_pending, $user_id, $paid_cents = 0, $mail = array(), $block = null) {
        $phase = self::offer_phase($status, $offer, $payment_pending);
        if ($phase === 'none') {
            return '';
        }
        $block = (is_array($block) && !empty($block['blocked'])) ? $block : null;
        $subs = array();
        if ($phase === 'unpaid') {
            $label = 'Trial fee unpaid';
            $subs[] = 'They can\'t be offered a place until the trial fee is paid.';
        } else {
            $label = TeamOversight_Offers::label($offer, 'card', array('verdict' => $status, 'season' => $season, 'paid_cents' => intval($paid_cents)));
            if ($phase === 'unsent' || $phase === 'not_approved' || $phase === 'withdrawn' || $phase === 'left') {
                $cell = $this->offer_cell($user_id, $season, $team, $status);
                if (!$cell['ok']) {
                    $subs[] = $cell['error_public'];
                }
                if ($block) {
                    $subs[] = $block['message'];
                }
            } elseif ($phase === 'awaiting') {
                $cell = $this->offer_cell($user_id, $season, $team, $status);
                if (!$cell['ok']) {
                    // The registry's line already starts "Can't send yet:" (no "Blocked: " in front of it).
                    $subs[] = $cell['error_public'];
                }
                if ($block) {
                    $subs[] = $block['awaiting_message'];
                }
            } elseif ($phase === 'sent' || $phase === 'sent_overdue') {
                $email_status = isset($mail['offer']) ? $mail['offer'] : '';
                if (in_array($email_status, array('pending', 'sending'), true)) {
                    // 1.79.1: with the club's email permission closed a pending email is not on its way yet.
                    $subs[] = TeamOversight_Offers::emails_live() ? 'Offer approved - email sending' : 'Offer approved - the email waits until the club allows offer emails';
                } elseif (in_array($email_status, array('held', 'failed', 'unknown'), true)) {
                    $subs[] = 'Offer approved - the email has not gone out yet';
                } elseif (self::short_date($offer->sent_at) !== '') {
                    $subs[] = 'Sent to the player\'s account email on ' . self::short_date($offer->sent_at);
                }
            }
            if (in_array($phase, array('sent', 'sent_overdue', 'accepted', 'accepted_overdue'), true) && intval($offer->reminder_count) > 0 && self::short_date($offer->last_reminded_at) !== '') {
                $subs[] = 'Last reminder ' . self::short_date($offer->last_reminded_at);
            }
        }
        $html = '<div class="cac-offer-status is-' . esc_attr(str_replace('_', '-', $phase)) . '"><span class="cos-label">' . esc_html($label) . '</span>';
        foreach ($subs as $sub) {
            $html .= ' <span class="cos-sub">' . esc_html($sub) . '</span>';
        }
        return $html . '</div>';
    }

    /**
     * One offer button: its own small form (the portal script saves it in the
     * background; data-confirm makes the script ask first, never an inline
     * onsubmit). The offer is found on the server from the application and
     * team, so the posted offer_id and shown_state only detect a stale page.
     * opts: primary, confirm, date (the deadline the page showed, for +7
     * days), reload (the page reloads afterwards).
     */
    private function offer_form($action, $label, $application_id, $team, $season, $offer, array $opts = array()) {
        $confirm = isset($opts['confirm']) ? (string) $opts['confirm'] : '';
        $html = '<form method="post" class="coach-offer-form"' . ($confirm !== '' ? ' data-confirm="' . esc_attr($confirm) . '"' : '') . '>';
        $html .= '<input type="hidden" name="coach_action" value="' . esc_attr($action) . '">';
        $html .= '<input type="hidden" name="application_id" value="' . intval($application_id) . '">';
        $html .= '<input type="hidden" name="coach_team" value="' . esc_attr($team) . '">';
        $html .= '<input type="hidden" name="coach_season" value="' . esc_attr($season) . '">';
        if ($offer) {
            $html .= '<input type="hidden" name="offer_id" value="' . intval($offer->id) . '">';
            $html .= '<input type="hidden" name="shown_state" value="' . esc_attr($offer->state) . '">';
        }
        if (!empty($opts['date'])) {
            $html .= '<input type="hidden" name="shown_date" value="' . esc_attr($opts['date']) . '">';
        }
        if (!empty($opts['reload'])) {
            $html .= '<input type="hidden" name="reload_page" value="1">';
        }
        $html .= wp_nonce_field('coach_portal_action', 'coach_nonce', false, false);
        $html .= '<button type="submit" class="button coach-offer-btn' . (empty($opts['primary']) ? ' coach-btn-secondary' : '') . '">' . esc_html($label) . '</button>';
        return $html . '</form>';
    }

    /**
     * The buttons on a card for the offer's state (editors only; viewers see
     * the status line and nothing to press). Send offer (greyed with the
     * reason when the player's category has no limit or fee yet), Cancel
     * request, Withdraw offer, Remind / +7 days / Move to declined once
     * overdue, Send offer again after a refusal or a withdrawal. '' when
     * there is nothing to press. $block: the higher-team block for this
     * player and team (null = none): Send offer is greyed with the reason,
     * and the server refuses it too.
     */
    private function render_offer_controls($application_id, $team, $season, $status, $offer, $payment_pending, $user_id, $paid_cents = 0, $block = null) {
        if ($this->read_only) {
            return '';
        }
        $phase = self::offer_phase($status, $offer, $payment_pending);
        $app = intval($application_id);
        $block = (is_array($block) && !empty($block['blocked'])) ? $block : null;
        $forms = '';
        $notes = array();
        $withdraw = function () use ($app, $team, $season, $offer) {
            return $this->offer_form('offer_withdraw', 'Withdraw offer', $app, $team, $season, $offer, array('confirm' => TeamOversight_Offers::wording('confirm_withdraw')));
        };
        switch ($phase) {
            case 'unsent':
            case 'not_approved':
            case 'withdrawn':
            case 'left':
                $label = $phase === 'unsent' ? 'Send offer' : 'Send offer again';
                $cell = $this->offer_cell($user_id, $season, $team, $status);
                if ($block) {
                    $forms .= '<button type="button" class="button coach-offer-btn coach-offer-blocked" aria-disabled="true" data-why="' . esc_attr($block['message']) . '" title="' . esc_attr($block['message']) . '">' . esc_html($label) . '</button>';
                } elseif ($cell['ok']) {
                    $forms .= $this->offer_form('offer_request', $label, $app, $team, $season, null, array('primary' => true));
                } else {
                    $forms .= '<button type="button" class="button coach-offer-btn coach-offer-blocked" aria-disabled="true" data-why="' . esc_attr($cell['error_public']) . '" title="' . esc_attr($cell['error_public']) . '">' . esc_html($label) . '</button>';
                }
                break;
            case 'awaiting':
                $forms .= $this->offer_form('offer_cancel', 'Cancel request', $app, $team, $season, $offer);
                break;
            case 'sent':
                $forms .= $withdraw();
                $notes[] = 'To change the verdict, withdraw the offer first.';
                break;
            case 'sent_overdue':
            case 'accepted_overdue':
                $deadline = TeamOversight_Offers::deadline_status($offer);
                $date = !empty($deadline['date']) ? $deadline['date'] : '';
                $forms .= $this->offer_form('offer_remind', 'Remind', $app, $team, $season, $offer);
                $forms .= $this->offer_form('offer_extend', '+7 days', $app, $team, $season, $offer, array('date' => $date));
                // The old text for an accepted offer said the player is told the offer has closed: no such email exists (28 Oct 2026), nobody is emailed.
                $decline_confirm = TeamOversight_Offers::wording($phase === 'sent_overdue' ? 'confirm_move_sent' : 'confirm_move_accepted');
                $forms .= $this->offer_form('offer_move_declined', 'Move to declined', $app, $team, $season, $offer, array('confirm' => $decline_confirm, 'reload' => true));
                if ($phase === 'sent_overdue' || intval($paid_cents) <= 0) {
                    $forms .= $withdraw();
                } else {
                    $notes[] = 'Money is held: ask the club to withdraw it.';
                }
                $notes[] = 'To change the verdict, withdraw the offer first.';
                break;
            case 'accepted':
                if (intval($paid_cents) <= 0) {
                    $forms .= $withdraw();
                } else {
                    $notes[] = 'Money is held: ask the club to withdraw it.';
                }
                $notes[] = 'To change the verdict, withdraw the offer first.';
                break;
            case 'locked_in':
                $notes[] = 'They are on the team: ask the club to remove them first.';
                break;
            default:
                return '';
        }
        $html = '<div class="coach-offer-actions">' . $forms;
        foreach ($notes as $note) {
            $html .= '<span class="coach-offer-note">' . esc_html($note) . '</span>';
        }
        return $html . '</div>';
    }

    /**
     * Where every player on the team's board stands, worked out once from the
     * roster, the selection roster and ONE offers query: the counts behind
     * the flow chart and the Players heading, the board (without the
     * Declined), the Declined list, each card's offer and the deposit
     * percentage / email status inputs. Counts follow the flow chart's rule:
     * the number on a step is the players waiting at that step now (Select =
     * Selected / Training Only with no live offer, so a request that was
     * cancelled or an offer that was withdrawn waits there too; Not approved
     * is its own exit). Overdue players are also counted in their step.
     */
    private function offer_snapshot($team, $season, $roster, $selection_roster) {
        $index = TeamOversight_Offers::index_for_team($team, $season);
        $confirmed = 0;
        foreach ($roster as $member) {
            if (in_array($member->role, array('playing_member', 'training_only'), true)) {
                $confirmed++;
            }
        }
        $counts = array(
            'confirmed' => $confirmed, 'board' => 0, 'select' => 0, 'awaiting' => 0, 'sent' => 0, 'accepted' => 0,
            'overdue_sent' => 0, 'overdue_accepted' => 0, 'not_approved' => 0, 'declined' => 0, 'placing' => 0,
            'tentative' => 0, 'unpaid' => 0, 'rejected_untold' => 0,
        );
        // 1.80.0: the team's Rejected players and whether each has been sent the "not selected" email (one query and its outbox read).
        $rejections = class_exists('TeamOversight_Not_Selected') ? TeamOversight_Not_Selected::team_rejections($team, $season) : array();
        foreach ($rejections as $rejection) {
            // Only the ones the coach can and should send now (1.80.0 review): not those something stops, nor players who already have a place.
            if (TeamOversight_Not_Selected::is_due($rejection)) {
                $counts['rejected_untold']++;
            }
        }
        $board = array();
        $declined = array();
        $offers = array();
        $accepted_offers = array();
        $sent_ids = array();
        $block_ids = array();
        foreach ($selection_roster as $member) {
            $application_id = intval($member->application_id);
            $raw = isset($index[$application_id]) ? $index[$application_id] : null;
            $offer = self::effective_offer($raw, $member->status);
            $pending = isset($member->application_status) && $member->application_status === 'awaiting_payment';
            $phase = self::offer_phase($member->status, $offer, $pending);
            if (in_array($phase, array('declined', 'unsent', 'withdrawn', 'left', 'not_approved', 'awaiting'), true)) {
                $block_ids[] = $application_id; // a higher team's claim matters to whoever has no offer out
            }
            if ($phase === 'declined') {
                $counts['declined']++;
                $declined[] = array($member, $offer);
                continue;
            }
            $board[] = $member;
            $counts['board']++;
            $offers[$application_id] = $offer;
            switch ($phase) {
                case 'none':
                    $counts['tentative']++;
                    break;
                case 'unpaid':
                    $counts['unpaid']++;
                    break;
                case 'unsent':
                case 'withdrawn':
                case 'left':
                    $counts['select']++;
                    break;
                case 'awaiting':
                    $counts['awaiting']++;
                    break;
                case 'not_approved':
                    $counts['not_approved']++;
                    break;
                case 'sent':
                case 'sent_overdue':
                    $counts['sent']++;
                    $sent_ids[] = $offer->id;
                    if ($phase === 'sent_overdue') {
                        $counts['overdue_sent']++;
                    }
                    break;
                case 'accepted':
                case 'accepted_overdue':
                    $counts['accepted']++;
                    $accepted_offers[] = $offer;
                    if ($phase === 'accepted_overdue') {
                        $counts['overdue_accepted']++;
                    }
                    break;
                case 'locked_in':
                    $counts['placing']++;
                    break;
            }
        }
        $locked_by_app = array();
        $locked_by_user = array();
        foreach ($index as $offer) {
            if ($offer->state === TeamOversight_Offers::LOCKED_IN) {
                $locked_by_app[intval($offer->application_id)] = $offer;
                if (intval($offer->user_id) > 0) {
                    $locked_by_user[intval($offer->user_id)] = $offer;
                }
            }
        }
        // A higher team's claim (Tentative or Selected there): application id => the block, only for players held back. Two queries for the whole board.
        $blocks = array();
        if (!empty($block_ids)) {
            $block_context = TeamOversight_Offers::block_context($block_ids);
            foreach ($block_ids as $block_id) {
                $found = TeamOversight_Offers::higher_team_block($block_id, $team, $season, $block_context);
                if ($found['blocked']) {
                    $blocks[$block_id] = $found;
                }
            }
        }
        return array(
            'index' => $index,
            'counts' => $counts,
            'board' => $board,
            'offers' => $offers,
            'blocks' => $blocks,
            'declined' => $declined,
            'paid' => $accepted_offers ? TeamOversight_Offers::paid_toward_deposit_map($accepted_offers) : array(),
            'mail' => ($sent_ids && class_exists('TeamOversight_Offer_Emails')) ? TeamOversight_Offer_Emails::status_for_offers($sent_ids) : array(),
            'locked_by_app' => $locked_by_app,
            'locked_by_user' => $locked_by_user,
            'rejections' => $rejections,
        );
    }

    /** The Players heading's text once offers are on, escaped: "12 Players (4 confirmed, 8 in selection — 2 not sent, 1 awaiting approval, 3 offers sent, 2 accepted — 1 declined)". Only non-zero parts. */
    private static function players_heading_html($c) {
        $total = intval($c['confirmed']) + intval($c['board']);
        $text = $total . ($total === 1 ? ' Player' : ' Players') . ' (' . intval($c['confirmed']) . ' confirmed';
        if ($c['board']) {
            $text .= ', ' . intval($c['board']) . ' in selection';
        }
        $parts = array();
        if ($c['select']) {
            $parts[] = intval($c['select']) . ' not sent';
        }
        if ($c['awaiting']) {
            $parts[] = intval($c['awaiting']) . ' awaiting approval';
        }
        if ($c['sent']) {
            $parts[] = intval($c['sent']) . (intval($c['sent']) === 1 ? ' offer' : ' offers') . ' sent' . ($c['overdue_sent'] ? ' (' . intval($c['overdue_sent']) . ' overdue)' : '');
        }
        if ($c['accepted']) {
            $parts[] = intval($c['accepted']) . ' accepted' . ($c['overdue_accepted'] ? ' (' . intval($c['overdue_accepted']) . ' overdue)' : '');
        }
        if ($c['not_approved']) {
            $parts[] = intval($c['not_approved']) . ' not approved';
        }
        if ($c['placing']) {
            $parts[] = intval($c['placing']) . ' locked in, being placed';
        }
        if ($parts) {
            $text .= ' — ' . implode(', ', $parts);
        }
        if ($c['declined']) {
            $text .= ' — ' . intval($c['declined']) . ' declined';
        }
        return esc_html($text . ')');
    }

    /**
     * The flow chart at the top of the team: Select > Send offer > Committee
     * approves > Offer emailed > Player accepts > Deposit paid > Confirmed,
     * with the side exits Declined, Overdue, Not approved and (1.80.0)
     * Rejected, not told (Rejected players who haven't been sent the "not
     * selected" email) beneath. The
     * number on a step is the players waiting there right now (Send offer and
     * Offer emailed are actions, not places, so carry none). Server-rendered
     * HTML and CSS only, wrapping on a phone; the same for view-only users.
     * $c: the counts from offer_snapshot().
     */
    public static function render_flow($c) {
        $n = function ($key) use ($c) {
            return isset($c[$key]) ? intval($c[$key]) : 0;
        };
        // The step and exit names and their tooltips are the committee's words (Emails, Offers and selection, Offer wording, The coach portal); never a dollar figure.
        $w = function ($key) {
            return TeamOversight_Offers::wording($key);
        };
        $steps = array(
            array('select', $w('flow_select_name'), $n('select'), $w('flow_select_tip'), 0),
            array('send', $w('flow_send_name'), null, $w('flow_send_tip'), 0),
            array('approve', $w('flow_approve_name'), $n('awaiting'), $w('flow_approve_tip'), 0),
            array('email', $w('flow_email_name'), null, $w('flow_email_tip'), 0),
            array('accept', $w('flow_accept_name'), $n('sent'), $w('flow_accept_tip'), $n('overdue_sent')),
            array('deposit', $w('flow_deposit_name'), $n('accepted'), $w('flow_deposit_tip'), $n('overdue_accepted')),
            array('confirmed', $w('flow_confirmed_name'), $n('confirmed') + $n('placing'), $w('flow_confirmed_tip'), 0),
        );
        $exits = array(
            array('declined', $w('exit_declined_name'), $n('declined'), $w('exit_declined_tip')),
            array('overdue', $w('exit_overdue_name'), $n('overdue_sent') + $n('overdue_accepted'), $w('exit_overdue_tip')),
            array('not_approved', $w('exit_not_approved_name'), $n('not_approved'), $w('exit_not_approved_tip')),
            // 1.80.0: Rejected for this team and not sent the "not selected" email yet (nothing goes until someone presses Send).
            array('rejected', $w('exit_rejected_name'), $n('rejected_untold'), $w('exit_rejected_tip')),
        );
        $html = '<div class="cp-flow" role="group" aria-label="Where this team\'s offers are up to"><ol class="cp-flow-steps">';
        foreach ($steps as $step) {
            list($key, $name, $count, $title, $overdue) = $step;
            $html .= '<li class="cp-flow-step is-' . ($count === null ? 'action' : 'wait') . ($count ? ' has-count' : '') . '" data-step="' . esc_attr($key) . '"' . ($count !== null ? ' data-count="' . intval($count) . '"' : '') . ' title="' . esc_attr($title) . '">';
            $html .= '<span class="cp-flow-name">' . esc_html($name) . '</span>';
            if ($count !== null) {
                $html .= '<span class="cp-flow-count">' . intval($count) . '</span>';
            }
            if ($overdue) {
                $html .= '<span class="cp-flow-flag">' . intval($overdue) . ' overdue</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ol><ul class="cp-flow-exits" aria-label="Side exits">';
        foreach ($exits as $exit) {
            list($key, $name, $count, $title) = $exit;
            $html .= '<li class="cp-flow-exit is-' . esc_attr($key) . ($count ? ' has-count' : '') . '" data-exit="' . esc_attr($key) . '" data-count="' . intval($count) . '" title="' . esc_attr($title) . '"><span class="cp-flow-name">' . esc_html($name) . '</span><span class="cp-flow-count">' . intval($count) . '</span></li>';
        }
        $html .= '</ul><p class="cp-flow-caption">' . esc_html($w('flow_caption')) . '</p></div>';
        return $html;
    }

    /**
     * "Send offers to all": a panel above the board for editors, listing who
     * would be sent to the committee (Selected / Training Only with no offer,
     * or whose request was cancelled) and, with the reason and no tick, who
     * can't be offered yet. Players whose offer ended any other way (declined,
     * moved to declined, not approved, withdrawn) are left out: each has its
     * own Send offer / Offer again button. A player a higher team has a claim
     * on ($snap['blocks']) is listed with the reason, never ticked or posted.
     * The server re-checks every id. Uses cell_check() only, so the page stays
     * cheap.
     */
    private function render_bulk_panel($team, $season, $snap) {
        if ($this->read_only) {
            return '';
        }
        $candidates = array();
        $blocked = array();
        foreach ($snap['board'] as $member) {
            if (!in_array($member->status, self::OFFER_VERDICTS, true)) {
                continue;
            }
            if (isset($member->application_status) && $member->application_status === 'awaiting_payment') {
                continue;
            }
            $raw = isset($snap['index'][intval($member->application_id)]) ? $snap['index'][intval($member->application_id)] : null;
            if ($raw && $raw->state !== TeamOversight_Offers::CANCELLED) {
                continue;
            }
            // A higher team's claim holds them back: listed with the reason, never ticked, and the server skips them too.
            if (isset($snap['blocks'][intval($member->application_id)])) {
                $blocked[] = array($member, $snap['blocks'][intval($member->application_id)]['message']);
                continue;
            }
            $cell = $this->offer_cell($member->user_id, $season, $team, $member->status);
            if ($cell['ok']) {
                $candidates[] = $member;
            } else {
                $blocked[] = array($member, $cell['error_public']);
            }
        }
        if (empty($candidates) && empty($blocked)) {
            return '';
        }
        $n = count($candidates);
        $html = '<details class="coach-app-details coach-bulk-offer"><summary>Send offers to all (' . $n . ')</summary><div class="coach-bulk-body">';
        $html .= '<p class="coach-portal-hint">' . esc_html(TeamOversight_Offers::wording('hint_bulk')) . '</p>';
        if ($n) {
            $html .= '<ul class="coach-bulk-list">';
            foreach ($candidates as $member) {
                $html .= '<li>' . esc_html($member->name ?: $member->email) . ' <span class="cos-sub">' . esc_html($member->status === 'training_only' ? 'Training Only' : 'Playing') . '</span></li>';
            }
            $html .= '</ul>';
        }
        if ($blocked) {
            $html .= '<p class="coach-portal-hint"><strong>Can\'t be offered yet:</strong></p><ul class="coach-bulk-list">';
            foreach ($blocked as $pair) {
                $html .= '<li>' . esc_html($pair[0]->name ?: $pair[0]->email) . ' <span class="cos-sub">' . esc_html($pair[1]) . '</span></li>';
            }
            $html .= '</ul>';
        }
        if ($n) {
            $html .= '<form method="post" class="coach-offer-form">';
            $html .= '<input type="hidden" name="coach_action" value="offer_bulk_request">';
            $html .= '<input type="hidden" name="coach_team" value="' . esc_attr($team) . '">';
            $html .= '<input type="hidden" name="coach_season" value="' . esc_attr($season) . '">';
            foreach ($candidates as $member) {
                $html .= '<input type="hidden" name="application_ids[]" value="' . intval($member->application_id) . '">';
            }
            $html .= wp_nonce_field('coach_portal_action', 'coach_nonce', false, false);
            $html .= '<button type="submit" class="button coach-offer-btn">Send these ' . $n . ' to the committee</button></form>';
        }
        return $html . '</div></details>';
    }

    /**
     * The Declined list: players whose verdict is still Selected / Training
     * Only and whose latest offer was declined by them or moved to Declined by
     * a coach. A collapsed section between the team list and the trial
     * applicants. Each card shows who declined and when, the player's own
     * reason (plain text), and Offer again (a new request through the
     * committee). A coach who changes the verdict removes the player from the
     * list. $declined: array of array(selection row, offer).
     */
    private function render_declined_section($team, $season, $declined, $teams_config, $blocks = array(), $ns_notices = array(), $ns_facts = array()) {
        if (empty($declined)) {
            return '';
        }
        $html = '<div class="coach-team-section" id="coach-declined"><details class="coach-app-details coach-declined"><summary>Declined (' . count($declined) . ')</summary>';
        $html .= '<p class="coach-portal-hint">' . esc_html(TeamOversight_Offers::wording('hint_declined')) . '</p>';
        foreach ($declined as $pair) {
            list($member, $offer) = $pair;
            $label = TeamOversight_Offers::label($offer, 'card', array('verdict' => $member->status, 'season' => $season));
            if ($offer->state === TeamOversight_Offers::MOVED_DECLINED && intval($offer->closed_by) > 0) {
                $mover = get_userdata(intval($offer->closed_by));
                if ($mover && $mover->display_name !== '' && strpos($label, 'Moved to declined') === 0) {
                    $label = 'Moved to declined by ' . $mover->display_name . substr($label, strlen('Moved to declined'));
                }
            }
            $html .= '<div class="coach-applicant-card verdict-' . esc_attr($member->status) . '" data-card-context="declined">';
            $html .= '<div class="cac-header"><span class="cac-number">#' . intval($member->trial_number) . '</span><span class="cac-name">' . esc_html($member->name) . '</span>';
            $html .= '<span class="cac-chips"><span class="verdict-chip verdict-chip-' . esc_attr($member->status) . ' offer-declined">' . esc_html($member->status === 'training_only' ? 'Training Only' : 'Selected') . ' · declined</span></span></div>';
            $html .= '<div class="cac-meta"><a href="mailto:' . esc_attr($member->email) . '">' . esc_html($member->email) . '</a>';
            if (!empty($member->mobile)) {
                $html .= ' &middot; ' . esc_html(self::format_phone($member->mobile));
            }
            $html .= '</div>';
            $html .= '<div class="cac-offer-line"><div class="cac-offer-status is-declined"><span class="cos-label">' . esc_html($label) . '</span>';
            $reason = trim((string) $offer->decline_reason);
            if ($offer->state === TeamOversight_Offers::DECLINED && $reason !== '') {
                $html .= ' <span class="cos-sub">Reason: ' . esc_html($reason) . '</span>';
            }
            // A higher team's claim: said on the status line for everyone who can see the team (Offer again waits for their coach; the server refuses it as well).
            $block = isset($blocks[intval($member->application_id)]) ? $blocks[intval($member->application_id)] : null;
            if ($block) {
                $html .= ' <span class="cos-sub">' . esc_html($block['message']) . '</span>';
            }
            $html .= '</div>';
            // 1.80.0: a "not selected" email sent before the verdict changed, and other teams that told the player.
            $html .= $this->render_ns_status($team, $season, $member->status, $member->application_status, $member->user_id, isset($ns_notices[intval($member->application_id)]) ? $ns_notices[intval($member->application_id)] : array(), $teams_config, isset($ns_facts[intval($member->application_id)]) ? $ns_facts[intval($member->application_id)] : null);
            $html .= '</div>';
            if (!$this->read_only) {
                $cell = $this->offer_cell($member->user_id, $season, $team, $member->status);
                $html .= '<div class="cac-decide"><span class="cac-offer"><div class="coach-offer-actions">';
                if ($block) {
                    $html .= '<button type="button" class="button coach-offer-btn coach-offer-blocked" aria-disabled="true" data-why="' . esc_attr($block['message']) . '" title="' . esc_attr($block['message']) . '">Offer again</button>';
                } elseif ($cell['ok']) {
                    $html .= $this->offer_form('offer_request', 'Offer again', $member->application_id, $team, $season, null, array('primary' => true, 'reload' => true));
                } else {
                    $html .= '<button type="button" class="button coach-offer-btn coach-offer-blocked" aria-disabled="true" data-why="' . esc_attr($cell['error_public']) . '" title="' . esc_attr($cell['error_public']) . '">Offer again</button><span class="coach-offer-note">' . esc_html($cell['error_public']) . '</span>';
                }
                $html .= '</div></span></div>';
            }
            $html .= '</div>';
        }
        return $html . '</details></div>';
    }

    // ------------------------------------------------------------------
    // "Not selected" (1.80.0, club decision, 28 Oct 2026): a button on a Rejected
    // card, about THIS team only. Nothing is sent until someone presses it.
    // The rule (who can be told) is TeamOversight_Not_Selected::
    // block_reason(); the words are the wording registry's; no figure.
    // ------------------------------------------------------------------

    /**
     * What a card says about the "not selected" email (2027 on), to everyone
     * who can see the team, view-only committee included. For THIS team: a
     * Rejected player reads "Rejected - not told yet" (with what stops it,
     * when something does) or where the email is up to ("Not selected email
     * sent 28 Oct by Sam Coach", or "waiting to send" while the club's email
     * permission is closed, or that the club chose not to send it); a player
     * whose verdict changed after the email went keeps it as a quiet history
     * line. Then one quiet line for each OTHER team whose email has really
     * gone to the player (never one still waiting, held or not sent: the
     * player hasn't been told), so nobody is surprised. '' when there is
     * nothing to say. $notices: this application's TeamOversight_Not_Selected::
     * for_applications() entry (team => info); $facts: its card_facts()
     * entry (applied, placed), or null when unknown.
     */
    private function render_ns_status($team, $season, $status, $application_status, $user_id, $notices, $teams_config, $facts = null) {
        if (!self::offers_on($season) || !class_exists('TeamOversight_Not_Selected')) {
            return '';
        }
        $notices = is_array($notices) ? $notices : array();
        $mine = isset($notices[$team]) ? $notices[$team] : null;
        $team_name = isset($teams_config[$team]['name']) ? $teams_config[$team]['name'] : $team;
        $applied = !is_array($facts) || in_array((string) $team, (array) $facts['applied'], true);
        $html = '';
        if ($status === 'rejected') {
            $reason = TeamOversight_Not_Selected::block_reason((string) $application_status, intval($user_id), 'rejected', $mine, $team_name, $applied);
            if ($reason && $reason['code'] === 'sent') {
                $html .= '<div class="cac-offer-status is-ns-sent"><span class="cos-label">' . esc_html($reason['text']) . '</span></div>';
            } elseif ($reason && $reason['code'] === 'stopped') {
                $html .= '<div class="cac-offer-status is-ns-stopped"><span class="cos-label">' . esc_html($reason['text']) . '</span></div>';
            } elseif ($reason && $reason['code'] === 'not_applied') {
                // They never applied for this team: nothing to tell them about it, so no "not told yet" and no button.
                $html .= '<div class="cac-offer-status is-ns-note"><span class="cos-sub">' . esc_html($reason['text']) . '</span></div>';
            } else {
                $html .= '<div class="cac-offer-status is-ns-untold"><span class="cos-label">' . esc_html(TeamOversight_Offers::wording('ns_untold')) . '</span>'
                    . ($reason ? ' <span class="cos-sub">' . esc_html($reason['text']) . '</span>' : '')
                    // Pressed before, but the email never went (discarded, switched off, things changed): say so; Send works again.
                    . (TeamOversight_Not_Selected::dropped($mine) ? ' <span class="cos-sub">' . esc_html(TeamOversight_Not_Selected::status_text($mine)) . '</span>' : '')
                    . (!$reason && is_array($facts) && !empty($facts['placed']) ? ' <span class="cos-sub">' . esc_html(TeamOversight_Offers::wording('ns_placed')) . '</span>' : '')
                    . '</div>';
            }
        } elseif ($mine && $mine['state'] === 'sent') {
            $html .= '<div class="cac-offer-status is-ns-note"><span class="cos-sub">' . esc_html(TeamOversight_Offers::wording('ns_history', array('{status}' => TeamOversight_Not_Selected::status_text($mine)))) . '</span></div>';
        }
        foreach ($notices as $code => $info) {
            // Another team's notice: only once its email has really gone (the outbox says sent), dated the day it went.
            if ((string) $code === (string) $team || $info['state'] !== 'sent' || $info['email_status'] !== 'sent') {
                continue;
            }
            $changed = $info['verdict_now'] !== null && $info['verdict_now'] !== 'rejected';
            $html .= '<div class="cac-offer-status is-ns-note"><span class="cos-sub">' . esc_html(TeamOversight_Offers::wording($changed ? 'ns_other_team_changed' : 'ns_other_team', array(
                '{team}' => isset($teams_config[$code]['name']) ? $teams_config[$code]['name'] : $code,
                '{date}' => self::short_date($info['email_sent_at'] !== '' ? $info['email_sent_at'] : $info['at']),
            ))) . '</span></div>';
        }
        return $html;
    }

    /**
     * The Send "not selected" email button on a Rejected card (editors of
     * this team only; viewers see the status line and nothing to press):
     * secondary, with a data-confirm that says the player is told about THIS
     * team only. Greyed with the reason when something stops it
     * (block_reason()); gone once it has been sent or the club chose not to,
     * and never there for a player who didn't apply for this team (nothing
     * to tell them). Back again when a pressed email was never sent (it was
     * discarded or skipped). The server checks everything again. $facts:
     * the application's card_facts() entry, or null when unknown.
     */
    private function render_ns_controls($application_id, $team, $season, $status, $application_status, $user_id, $player_name, $notices, $teams_config, $facts = null) {
        if ($this->read_only || $status !== 'rejected' || !self::offers_on($season) || !class_exists('TeamOversight_Not_Selected')) {
            return '';
        }
        $notices = is_array($notices) ? $notices : array();
        $mine = isset($notices[$team]) ? $notices[$team] : null;
        $team_name = isset($teams_config[$team]['name']) ? $teams_config[$team]['name'] : $team;
        $applied = !is_array($facts) || in_array((string) $team, (array) $facts['applied'], true);
        $reason = TeamOversight_Not_Selected::block_reason((string) $application_status, intval($user_id), 'rejected', $mine, $team_name, $applied);
        if ($reason && in_array($reason['code'], array('sent', 'stopped', 'not_applied'), true)) {
            return '';
        }
        $label = TeamOversight_Offers::wording('ns_button');
        if ($reason) {
            return '<div class="coach-offer-actions"><button type="button" class="button coach-offer-btn coach-btn-secondary coach-offer-blocked" aria-disabled="true" data-why="' . esc_attr($reason['text']) . '" title="' . esc_attr($reason['text']) . '">' . esc_html($label) . '</button></div>';
        }
        return '<div class="coach-offer-actions">' . $this->offer_form('offer_not_selected', $label, $application_id, $team, $season, null, array(
            // Names are stored entity-encoded (& as &amp;): decode once, offer_form() escapes once.
            'confirm' => TeamOversight_Offers::wording('ns_confirm', array('{player}' => wp_specialchars_decode((string) $player_name, ENT_QUOTES), '{team}' => $team_name)),
        )) . '</div>';
    }

    /**
     * Send "not selected" emails, for the board (editors only, 2027 on): a
     * panel beside Send offers to all listing exactly who on THIS team will
     * be emailed (Rejected and not told yet, nothing stopping it), who
     * can't be with the reason, and who already has a place this season and
     * is left out (neither is ever posted; with nobody to post the heading
     * and hint say so). A data-confirm asks first;
     * the server sends each through TeamOversight_Not_Selected::send_many(),
     * which re-checks every id. '' when there is nobody to list.
     */
    private function render_ns_bulk_panel($team, $season, $snap) {
        if ($this->read_only || !self::offers_on($season) || empty($snap['rejections']) || !class_exists('TeamOversight_Not_Selected')) {
            return '';
        }
        $candidates = array();
        $cant = array();
        $placed = array();
        foreach ($snap['rejections'] as $row) {
            if (!TeamOversight_Not_Selected::is_untold($row)) {
                continue;
            }
            if ($row->reason) {
                $cant[] = $row;
            } elseif (!empty($row->placed)) {
                // Already has a place this season: listed, never posted (the card's own button still works).
                $placed[] = $row;
            } else {
                $candidates[] = $row;
            }
        }
        if (empty($candidates) && empty($cant) && empty($placed)) {
            return '';
        }
        $team_name = TeamOversight_Not_Selected::team_name($team, $season, TeamOversight_Offers::teams_config($season));
        $n = count($candidates);
        // With nobody to post, the panel says so instead of promising one email each (1.80.0 review).
        $title = $n ? TeamOversight_Offers::wording('ns_bulk_title', array('{n}' => (string) $n)) : TeamOversight_Offers::wording('ns_bulk_title_none', array('{n}' => (string) (count($cant) + count($placed))));
        $hint = $n ? TeamOversight_Offers::wording('ns_bulk_hint', array('{team}' => $team_name)) : TeamOversight_Offers::wording('ns_bulk_hint_none', array('{team}' => $team_name));
        $html = '<details class="coach-app-details coach-bulk-offer coach-bulk-ns"><summary>' . esc_html($title) . '</summary><div class="coach-bulk-body">';
        $html .= '<p class="coach-portal-hint">' . esc_html($hint) . '</p>';
        if ($n) {
            $html .= '<ul class="coach-bulk-list">';
            foreach ($candidates as $row) {
                $html .= '<li>' . esc_html($row->name ?: $row->email) . ' <span class="cos-sub">#' . intval($row->trial_number) . '</span></li>';
            }
            $html .= '</ul>';
        }
        if ($cant) {
            $html .= '<p class="coach-portal-hint"><strong>' . esc_html(TeamOversight_Offers::wording('ns_bulk_cant')) . '</strong></p><ul class="coach-bulk-list">';
            foreach ($cant as $row) {
                $html .= '<li>' . esc_html($row->name ?: $row->email) . ' <span class="cos-sub">' . esc_html($row->reason['text']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        if ($placed) {
            $html .= '<p class="coach-portal-hint"><strong>' . esc_html(TeamOversight_Offers::wording('ns_bulk_placed')) . '</strong></p><ul class="coach-bulk-list">';
            foreach ($placed as $row) {
                $html .= '<li>' . esc_html($row->name ?: $row->email) . ' <span class="cos-sub">#' . intval($row->trial_number) . '</span></li>';
            }
            $html .= '</ul>';
        }
        if ($n) {
            $html .= '<form method="post" class="coach-offer-form" data-confirm="' . esc_attr(TeamOversight_Offers::wording('ns_bulk_confirm', array('{n}' => (string) $n, '{team}' => $team_name))) . '">';
            $html .= '<input type="hidden" name="coach_action" value="offer_bulk_not_selected">';
            $html .= '<input type="hidden" name="coach_team" value="' . esc_attr($team) . '">';
            $html .= '<input type="hidden" name="coach_season" value="' . esc_attr($season) . '">';
            foreach ($candidates as $row) {
                $html .= '<input type="hidden" name="application_ids[]" value="' . intval($row->application_id) . '">';
            }
            $html .= wp_nonce_field('coach_portal_action', 'coach_nonce', false, false);
            $html .= '<button type="submit" class="button coach-offer-btn coach-btn-secondary">' . esc_html(TeamOversight_Offers::wording('ns_bulk_button', array('{n}' => (string) $n))) . '</button></form>';
        }
        return $html . '</div></details>';
    }

    /**
     * Send "not selected" emails for the board, after handle_actions() has
     * checked the nonce and edit access to $team: every posted id goes
     * through TeamOversight_Not_Selected::send_many() (each re-checked one by
     * one: this season, Rejected for this team, live, never sent). Its result
     * names only applicants this board could list (this season, with a
     * verdict on this team); any other posted id is reported by number.
     */
    private function handle_ns_bulk($team, $season) {
        global $wpdb;
        if (!self::offers_on($season) || !class_exists('TeamOversight_Not_Selected')) {
            return '<div class="coach-portal-notice"><p>' . esc_html('Not selected emails are sent from the ' . self::OFFER_ACCEPTANCE_FROM . ' season.') . '</p></div>';
        }
        $ids = array();
        if (isset($_POST['application_ids']) && is_array($_POST['application_ids'])) {
            foreach ($_POST['application_ids'] as $id) {
                if (is_scalar($id) && intval($id) > 0) {
                    $ids[] = intval($id);
                }
            }
        }
        $ids = array_slice(array_values(array_unique($ids)), 0, TeamOversight_Not_Selected::BULK_MAX);
        if (empty($ids)) {
            return '<div class="coach-portal-notice"><p>There was nobody to send. Reload the page to see who can be told.</p></div>';
        }
        $result = TeamOversight_Not_Selected::send_many($team, $season, $ids, get_current_user_id());
        $names = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.name FROM {$wpdb->prefix}trial_applications a
             WHERE a.id IN (" . implode(',', array_map('intval', $ids)) . ") AND a.season = %s
               AND EXISTS (SELECT 1 FROM {$wpdb->prefix}team_trial_selections s WHERE s.application_id = a.id AND s.team = %s)",
            (string) $season, (string) $team
        )) as $row) {
            $names[intval($row->id)] = $row->name;
        }
        $html = '<p>' . esc_html($result['message']) . '</p>';
        if (!empty($result['skipped'])) {
            $html .= '<ul>';
            foreach ($result['skipped'] as $application_id => $reason) {
                $html .= '<li>' . esc_html(isset($names[intval($application_id)]) ? $names[intval($application_id)] : 'Application ' . intval($application_id)) . ': ' . esc_html($reason) . '</li>';
            }
            $html .= '</ul>';
        }
        $any = !empty($result['sent']) || !empty($result['already']);
        return '<div class="' . ($any ? 'coach-portal-success' : 'coach-portal-notice') . '">' . $html . '</div>';
    }

    /**
     * The fresh card after a save once offers are on: the verdict chips, the
     * offer buttons and status line, the verdict menu (locked while an offer
     * holds the verdict), the team position control, and the parts of the
     * page that follow (Players heading, flow chart, bulk panels). Since
     * 1.80.0 the buttons and status line include the "not selected" ones.
     */
    private function offer_card_refresh($season, $application_id, $team, $row, $position, $applied) {
        global $wpdb;
        $status = $row ? $row->status : '';
        $pending = $row && $row->application_status === 'awaiting_payment';
        $app_row = $wpdb->get_row($wpdb->prepare("SELECT user_id, name, application_status FROM {$wpdb->prefix}trial_applications WHERE id = %d", $application_id));
        $user_id = $app_row ? intval($app_row->user_id) : 0;
        $offer = self::effective_offer(TeamOversight_Offers::latest_for($application_id, $team), $status);
        $paid = 0;
        if ($offer && $offer->state === TeamOversight_Offers::ACCEPTED) {
            $map = TeamOversight_Offers::paid_toward_deposit_map(array($offer));
            $paid = isset($map[$offer->id]) ? intval($map[$offer->id]) : 0;
        }
        $mail = array();
        if ($offer && $offer->state === TeamOversight_Offers::SENT && class_exists('TeamOversight_Offer_Emails')) {
            $statuses = TeamOversight_Offer_Emails::status_for_offers(array($offer->id));
            $mail = isset($statuses[$offer->id]) ? $statuses[$offer->id] : array();
        }
        $board = isset($_POST['card_context']) && $_POST['card_context'] === 'board';
        $chips = $board
            ? self::render_offer_verdict_chip($status, $offer, $season, $pending)
            : $this->render_verdict_chips($this->get_selections_for_application($application_id, $season));
        $locked = self::verdict_locked(self::offer_phase($status, $offer, $pending));

        $roster = $this->get_roster($team, $season);
        $snap = $this->offer_snapshot($team, $season, $roster, $this->get_selection_roster($team, $season, $roster));
        // A higher team's claim on this player, read fresh (the save may have just changed their verdict here or there).
        $block = in_array($status, self::OFFER_VERDICTS, true) ? TeamOversight_Offers::higher_team_block($application_id, $team, $season) : null;
        // 1.80.0: the "not selected" line and button, read fresh (a verdict just set to Rejected shows "not told yet" and the button).
        $teams_config = TeamOversight_Offers::teams_config($season);
        $ns_all = class_exists('TeamOversight_Not_Selected') ? TeamOversight_Not_Selected::for_applications(array($application_id)) : array();
        $ns = isset($ns_all[$application_id]) ? $ns_all[$application_id] : array();
        $ns_facts_all = class_exists('TeamOversight_Not_Selected') ? TeamOversight_Not_Selected::card_facts(array($application_id)) : array();
        $ns_facts = isset($ns_facts_all[$application_id]) ? $ns_facts_all[$application_id] : null;
        $app_status = $app_row ? (string) $app_row->application_status : '';
        $app_name = $app_row ? (string) $app_row->name : '';

        return array(
            'status' => $status,
            'chips' => $chips,
            'offer' => $this->render_offer_controls($application_id, $team, $season, $status, $offer, $pending, $user_id, $paid, $block)
                . $this->render_ns_controls($application_id, $team, $season, $status, $app_status, $user_id, $app_name, $ns, $teams_config, $ns_facts),
            'offer_label' => $this->render_offer_status($team, $season, $status, $offer, $pending, $user_id, $paid, $mail, $block)
                . $this->render_ns_status($team, $season, $status, $app_status, $user_id, $ns, $teams_config, $ns_facts),
            'verdict_options' => self::render_verdict_select_options($status, $pending, $locked),
            'position' => $this->render_position_control($application_id, $team, $season, $status, $position),
            'meta_position' => self::meta_position_html($status, $position, $this->format_positions($applied)),
            'counts_html' => self::players_heading_html($snap['counts']),
            'flow_html' => self::render_flow($snap['counts']),
            'bulk_html' => $this->render_bulk_panel($team, $season, $snap),
            'ns_bulk_html' => $this->render_ns_bulk_panel($team, $season, $snap),
        );
    }

    /**
     * One offer button, after handle_actions() has verified the nonce, the
     * team and the application: the single TeamOversight_Offers method bound
     * to that application and team (the offer is found on the server, never
     * from a posted id). A refusal on a verified card lets the background
     * save send the fresh card back so a stale page repaints itself.
     */
    private function handle_offer_action($action, $team, $season, $application) {
        $who = esc_html($application->name) . ' (#' . intval($application->trial_number) . ')';
        if (!self::offers_on($season)) {
            return '<div class="coach-portal-notice"><p>' . esc_html('Offers apply from the ' . self::OFFER_ACCEPTANCE_FROM . ' season.') . '</p></div>';
        }
        $this->offer_refresh_ok = true;
        $application_id = intval($application->id);
        $actor = get_current_user_id();
        $shown = array();
        if (isset($_POST['offer_id'])) {
            $shown['offer_id'] = intval($_POST['offer_id']);
        }
        if (isset($_POST['shown_state']) && is_string($_POST['shown_state'])) {
            $shown['state'] = sanitize_key(wp_unslash($_POST['shown_state']));
        }
        if (isset($_POST['shown_date']) && is_string($_POST['shown_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $_POST['shown_date']) === 1) {
            $shown['date'] = $_POST['shown_date'];
        }
        // 1.80.0: Send "not selected" email, about THIS team only (handle_actions() checked the nonce and edit access to $team; the rest is checked there).
        if ($action === 'offer_not_selected') {
            if (!class_exists('TeamOversight_Not_Selected')) {
                return '<div class="coach-portal-notice"><p>' . $who . ': that can\'t be sent from here. Reload the page.</p></div>';
            }
            $r = TeamOversight_Not_Selected::send_for_team($application_id, $team, $season, $actor, 'coach');
            return '<div class="' . (!empty($r['ok']) ? 'coach-portal-success' : 'coach-portal-notice') . '"><p>' . $who . ': ' . esc_html((string) $r['message']) . '</p></div>';
        }
        switch ($action) {
            case 'offer_request':
                $r = TeamOversight_Offers::request($application_id, $team, $season, $actor);
                break;
            case 'offer_cancel':
                $r = TeamOversight_Offers::cancel($application_id, $team, $season, $actor, $shown);
                break;
            case 'offer_withdraw':
                $r = TeamOversight_Offers::withdraw($application_id, $team, $season, $actor, $shown);
                break;
            case 'offer_remind':
                $r = TeamOversight_Offers::remind($application_id, $team, $season, $actor, $shown);
                break;
            case 'offer_extend':
                $r = TeamOversight_Offers::extend($application_id, $team, $season, $actor, $shown);
                break;
            case 'offer_move_declined':
                $r = TeamOversight_Offers::move_to_declined($application_id, $team, $season, $actor, $shown);
                break;
            default:
                return '';
        }
        $message = isset($r['message']) ? (string) $r['message'] : '';
        if (isset($r['code']) && $r['code'] === 'stale') {
            $message = 'This offer changed after the page loaded, so nothing was done. The card now shows where it is up to.';
        }
        if (!empty($r['ok'])) {
            // Cards that change list (to Declined, or back to the board) reload the page.
            if ($action === 'offer_move_declined' || (isset($_POST['reload_page']) && $_POST['reload_page'] === '1')) {
                $this->offer_reload = true;
            }
            $text = (isset($r['code']) && $r['code'] === 'already') ? 'Nothing to do: that was already done.' : $message;
            return '<div class="coach-portal-success"><p>' . $who . ': ' . esc_html($text) . '</p></div>';
        }
        return '<div class="coach-portal-notice"><p>' . $who . ': ' . esc_html($message) . '</p></div>';
    }

    /**
     * Send offers to all: every id is sent through the same request() as a
     * single card (verdict still Selected / Training Only, no live offer,
     * not payment pending, the player's category priced), the ones that
     * can't go are listed with the reason. Players whose offer ended any way
     * but a cancelled request are left to their own button.
     */
    private function handle_offer_bulk($team, $season) {
        global $wpdb;
        if (!self::offers_on($season)) {
            return '<div class="coach-portal-notice"><p>' . esc_html('Offers apply from the ' . self::OFFER_ACCEPTANCE_FROM . ' season.') . '</p></div>';
        }
        $ids = array();
        if (isset($_POST['application_ids']) && is_array($_POST['application_ids'])) {
            foreach ($_POST['application_ids'] as $id) {
                if (is_scalar($id) && intval($id) > 0) {
                    $ids[] = intval($id);
                }
            }
        }
        $ids = array_slice(array_values(array_unique($ids)), 0, 200);
        if (empty($ids)) {
            return '<div class="coach-portal-notice"><p>There was nobody to send. Reload the page to see who can be offered.</p></div>';
        }
        $result = TeamOversight_Offers::request_many($team, $season, $ids, get_current_user_id());
        // 1.79.1: names only for applications this board could have listed (this season, with a verdict
        // on this team); any other posted id is reported by number, so made-up ids reveal no name.
        $names = array();
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.name FROM {$wpdb->prefix}trial_applications a
             WHERE a.id IN (" . implode(',', array_map('intval', $ids)) . ") AND a.season = %s
               AND EXISTS (SELECT 1 FROM {$wpdb->prefix}team_trial_selections s WHERE s.application_id = a.id AND s.team = %s)",
            (string) $season, (string) $team
        )) as $row) {
            $names[intval($row->id)] = $row->name;
        }
        $html = '<p>' . esc_html($result['message']) . '</p>';
        if (!empty($result['skipped'])) {
            $html .= '<ul>';
            foreach ($result['skipped'] as $application_id => $reason) {
                $html .= '<li>' . esc_html(isset($names[intval($application_id)]) ? $names[intval($application_id)] : 'Application ' . intval($application_id)) . ': ' . esc_html($reason) . '</li>';
            }
            $html .= '</ul>';
        }
        $any = !empty($result['requested']) || !empty($result['already']);
        return '<div class="' . ($any ? 'coach-portal-success' : 'coach-portal-notice') . '">' . $html . '</div>';
    }

    /**
     * Process verdict/note submissions before output, stash the result
     * notice for the next render, and redirect back to the page.
     */
    public function maybe_handle_actions() {
        if (!isset($_POST['coach_action']) || !in_array($_POST['coach_action'], array_merge(array('set_selection', 'set_offer', 'set_position', 'set_member_position', 'add_note', 'edit_note', 'delete_note'), self::OFFER_ACTIONS), true)) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }

        $season = isset($_POST['coach_season']) ? sanitize_text_field($_POST['coach_season']) : '';

        $notice = $this->handle_actions($this->get_editable_team_codes($season), $season);
        if ($notice === self::expired_notice()) {
            // The redirect below is the reload, so don't ask for another.
            $notice = self::expired_notice(true);
        }
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

        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT season, team, role FROM {$wpdb->prefix}team_assignments
            WHERE is_active = 1
                AND role IN (" . self::COACH_ROLES . ")
                AND (user_id = %d OR ((user_id IS NULL OR user_id = 0) AND email = %s))
            ORDER BY season DESC, team
        ", $user->ID, $user->user_email));

        // Seasons whose coaching access has run out drop away (committee
        // access is separate and unaffected).
        return array_values(array_filter($rows, function ($row) {
            return self::coach_access_open($row->season);
        }));
    }

    /**
     * Coaching a season opens it in the portal until the team's membership
     * runs out — 1 April the year after (assignment_grant_end) — so last
     * season stays reachable while the next teams are picked, then closes:
     * a former coach doesn't keep seeing old applicants' contact and
     * emergency details for ever. $today is for tests.
     */
    private static function coach_access_open($season, $today = null) {
        $today = $today !== null ? $today : wp_date('Y-m-d');
        return $today < TeamOversight_Memberships::assignment_grant_end($season);
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

    /**
     * Every team the current user can open in the portal for a season:
     * code => array('role', 'coach' (bool), 'edit' (bool)). Teams they
     * coach come first, with full access; committee access adds the rest
     * of the season's teams, editable only at the 'edit' level.
     */
    private function get_portal_teams($season) {
        if (!is_user_logged_in() || $season === null || $season === '') {
            return array();
        }
        // Each group in team-code order, the same as Configuration and the
        // rego form (natural, so SL2M sorts before SL10M).
        $teams = $this->get_my_teams($season);
        ksort($teams, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($teams as $code => $role) {
            $teams[$code] = array('role' => $role, 'coach' => true, 'edit' => true);
        }
        $level = TeamOversight_Committee_Access::get_level(get_current_user_id());
        if ($level !== '') {
            $database = new TeamOversight_Database();
            $all = $database->get_teams_config($season);
            ksort($all, SORT_NATURAL | SORT_FLAG_CASE);
            foreach (array_keys($all) as $code) {
                if (!isset($teams[$code])) {
                    $teams[$code] = array('role' => 'committee', 'coach' => false, 'edit' => $level === 'edit');
                }
            }
        }
        return $teams;
    }

    /** Teams the current user may change (verdicts, attendance, notes). */
    private function get_editable_team_codes($season) {
        return array_keys(array_filter($this->get_portal_teams($season), function ($team) {
            return $team['edit'];
        }));
    }

    /** May the current user see this team (portal, team list, trial book)? */
    private function can_view_team($team, $season) {
        $teams = $this->get_portal_teams($season);
        return isset($teams[$team]);
    }

    /**
     * Seasons the portal offers, newest first: those they coach in, plus —
     * with committee access — every season with applicants or assignments.
     */
    private function get_portal_seasons() {
        global $wpdb;
        $seasons = array_map(function ($a) {
            return (string) $a->season;
        }, $this->get_coach_assignments(wp_get_current_user()));

        if (TeamOversight_Committee_Access::get_level(get_current_user_id()) !== '') {
            $seasons = array_merge(
                $seasons,
                $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}trial_applications"),
                $wpdb->get_col("SELECT DISTINCT season FROM {$wpdb->prefix}team_assignments")
            );
        }
        $seasons = array_values(array_unique(array_map('strval', $seasons)));
        rsort($seasons);
        return $seasons;
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

        // Seasons and teams this person can open: the teams they coach,
        // plus every team with committee access.
        $seasons = $this->get_portal_seasons();

        if (empty($seasons)) {
            return '<div class="coach-portal-notice"><p>This page is for team coaches. Your account does not have a current coach or assistant coach assignment — if that\'s wrong, please contact the club.</p></div>';
        }

        $season = (isset($_GET['coach_season']) && in_array($_GET['coach_season'], $seasons, true))
            ? $_GET['coach_season']
            : $seasons[0];

        $portal_teams = $this->get_portal_teams($season);
        $my_team_codes = array_keys($portal_teams);

        if (empty($my_team_codes)) {
            return '<div class="coach-portal-notice"><p>No teams are set up for ' . esc_html($season) . ' yet.</p></div>';
        }

        $active_team = (isset($_GET['coach_team']) && in_array($_GET['coach_team'], $my_team_codes, true))
            ? $_GET['coach_team']
            : $my_team_codes[0];

        // View-only committee access: every edit control is left out (the
        // handlers refuse edits regardless).
        $active_access = $portal_teams[$active_team];
        $this->read_only = !$active_access['edit'];
        $committee_level = TeamOversight_Committee_Access::get_level(get_current_user_id());
        $team_word = $active_access['coach'] ? 'your team' : 'this team';

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
        // Unpaid players to the bottom of the board, keeping its order otherwise.
        $selection_roster = array_merge(
            array_values(array_filter($selection_roster, function ($m) { return !isset($m->application_status) || $m->application_status !== 'awaiting_payment'; })),
            array_values(array_filter($selection_roster, function ($m) { return isset($m->application_status) && $m->application_status === 'awaiting_payment'; }))
        );
        $last_season_members = $this->get_last_season_team_members($active_team, $season);
        $applicants = $this->get_applicants_by_gender($active_config['gender'], $season, $active_team, $my_team_codes);

        // A season from 2027 (offers are how selection works): where every
        // offer is up to, worked out once. The board loses the players who
        // declined (they move to the Declined list); before 2027 none of this
        // runs and the portal is exactly as 1.75.3.
        $offers_on = self::offers_on($season);
        $snap = null;
        $declined_list = array();
        if ($offers_on) {
            $snap = $this->offer_snapshot($active_team, $season, $roster, $selection_roster);
            $selection_roster = $snap['board'];
            $declined_list = $snap['declined'];
        }
        // 1.80.0: every "not selected" notice of the players on this page, read once (application id => team => notice).
        $ns_notices = array();
        $ns_facts = array();
        if ($offers_on && class_exists('TeamOversight_Not_Selected')) {
            $ns_ids = array();
            foreach ($applicants as $a) {
                $ns_ids[] = intval($a['id']);
            }
            foreach ($selection_roster as $m) {
                $ns_ids[] = intval($m->application_id);
            }
            foreach ($declined_list as $pair) {
                $ns_ids[] = intval($pair[0]->application_id);
            }
            $ns_notices = TeamOversight_Not_Selected::for_applications($ns_ids);
            // Which teams each applied for, and who already holds a place this season (1.80.0 review), read once for the page.
            $ns_facts = TeamOversight_Not_Selected::card_facts($ns_ids);
        }

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

            <?php if ($committee_level !== '' && count($my_team_codes) > 1): ?>
                <?php // Committee: every team, too many for tabs — one picker, own teams first. ?>
                <form method="get" class="coach-team-switcher coach-team-picker">
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if (!in_array($key, array('coach_team', 'coach_season', 'attendance_date'), true) && is_string($value)): ?>
                            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(wp_unslash($value)); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                    <label class="coach-field-label" for="coach-team-select">Team</label>
                    <select id="coach-team-select" name="coach_team" onchange="this.form.submit()">
                        <?php $coached = array_keys(array_filter($portal_teams, function ($t) { return $t['coach']; })); ?>
                        <?php if (!empty($coached)): ?>
                            <optgroup label="Teams you coach">
                                <?php foreach ($coached as $code): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($code, $active_team); ?>><?php echo esc_html(isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <optgroup label="<?php echo esc_attr($committee_level === 'edit' ? 'All teams (committee)' : 'All teams (committee, view only)'); ?>">
                            <?php foreach ($my_team_codes as $code): ?>
                                <?php if (in_array($code, $coached, true)) { continue; } ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($code, $active_team); ?>><?php echo esc_html(isset($teams_config[$code]) ? $teams_config[$code]['name'] : $code); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <noscript><button type="submit" class="button coach-btn-secondary">Open</button></noscript>
                </form>
            <?php elseif (count($my_team_codes) > 1): ?>
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
                <?php
                if ($active_access['coach']) {
                    $access_label = 'you are ' . str_replace('_', ' ', $active_access['role']);
                } else {
                    $access_label = $active_access['edit'] ? 'committee — can edit' : 'committee — view only';
                }
                ?>
                <h3><?php echo esc_html($active_config['name']); ?> <small>(<?php echo esc_html($season); ?> — <?php echo esc_html($access_label); ?>)</small></h3>

                <?php if ($offers_on): ?>
                    <div id="coach-offer-flow"><?php echo self::render_flow($snap['counts']); ?></div>
                <?php endif; ?>

                <?php $attendance_date = $this->get_attendance_date(); ?>
                <?php if ($this->read_only): ?>
                    <p class="coach-view-only">View only — you can see everything for this team, but verdicts, attendance and notes are left to its coaches.</p>
                <?php else: ?>
                <form method="get" class="coach-attendance-bar" id="coach-attendance-bar"
                      data-season="<?php echo esc_attr($season); ?>"
                      data-team="<?php echo esc_attr($active_team); ?>"
                      data-date="<?php echo esc_attr($attendance_date); ?>"
                      data-nonce="<?php echo esc_attr(wp_create_nonce('coach_attendance')); ?>"
                      data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                    <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                    <label class="coach-field-label">Attendance for
                        <input type="date" name="attendance_date" value="<?php echo esc_attr($attendance_date); ?>" max="<?php echo esc_attr(wp_date('Y-m-d')); ?>" onchange="this.form.submit()">
                    </label>
                    <span class="coach-portal-hint">Choose the session date, then tap <em>Mark attended</em> on each player who was there.</span>
                </form>
                <?php endif; ?>

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

                <div class="coach-staff-heading">
                    <h4>Coaching Staff (<?php echo count($staff); ?>)</h4>
                    <form method="post" class="coach-export-form">
                        <input type="hidden" name="coach_action" value="export_roster">
                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                        <button type="submit" class="button coach-btn-secondary" title="Everyone on the team or with a verdict for it (Selected, Training Only, Tentative, Rejected), and everyone who applied with no verdict yet, with emails: filter by the Verdict column to copy a group's emails.">Export team list &amp; verdicts (CSV)</button>
                    </form>
                </div>
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

                <?php
                // Everyone on the list, so coaches can see how big the team would be.
                $player_total = count($confirmed_players) + count($selection_roster);
                ?>
                <?php if ($offers_on): ?>
                    <h4 id="coach-players-heading"><?php echo self::players_heading_html($snap['counts']); ?></h4>
                    <div id="coach-offer-bulk"><?php echo $this->render_bulk_panel($active_team, $season, $snap); ?></div>
                    <div id="coach-ns-bulk"><?php echo $this->render_ns_bulk_panel($active_team, $season, $snap); ?></div>
                <?php else: ?>
                <h4><?php echo $player_total . ($player_total === 1 ? ' Player' : ' Players'); ?> (<?php echo count($confirmed_players); ?> confirmed<?php echo count($selection_roster) ? ', ' . count($selection_roster) . ' in selection' : ''; ?>)</h4>
                <?php endif; ?>
                <?php if (!empty($confirmed_players) || !empty($selection_roster)): ?>
                    <?php
                    // Other teams' verdicts and confirmed places for everyone
                    // in this section, fetched once for all the cards.
                    $confirmed_ctx = array();
                    $claim_apps = array();
                    $claim_people = array();
                    // Confirmed players' team positions (from their verdicts), in one query.
                    $position_map = $this->get_team_positions_for_team($active_team, $season);
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

                    // Where each confirmed player stands on fees, one query
                    // for all. Percentages only: coaches never see amounts.
                    $fee_people = array();
                    foreach ($confirmed_players as $i => $member) {
                        $fee_people[$i] = array('user_id' => isset($member->user_id) ? intval($member->user_id) : 0, 'email' => $member->email);
                    }
                    $fee_standings = TeamOversight_Payments::get_fee_standings($fee_people, $season);
                    $no_training = array();
                    foreach ($confirmed_players as $i => $member) {
                        if (!empty($fee_standings[$i]['flagged'])) {
                            $no_training[] = $member->name ?: $member->email;
                        }
                    }
                    ?>
                    <?php if (!empty($no_training)): ?>
                        <div class="coach-fee-alert">
                            <strong><?php echo count($no_training); ?> <?php echo count($no_training) === 1 ? 'player is' : 'players are'; ?> a month or more behind on fees</strong> and shouldn't train until they've sorted it with the club: <?php echo esc_html(implode(', ', $no_training)); ?>.
                        </div>
                    <?php endif; ?>
                    <?php foreach ($confirmed_players as $i => $member): ?>
                        <?php $app_ctx = $confirmed_ctx[$i]; ?>
                        <?php // The roster lists playing members first, then Training Only; each keeps its colour bar. ?>
                        <div class="coach-applicant-card confirmed-<?php echo esc_attr($member->role); ?>">
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
                                    <?php if ($offers_on): ?>
                                        <?php
                                        // A player who locked in through an offer: when.
                                        $locked_offer = null;
                                        if ($app_ctx && isset($snap['locked_by_app'][intval($app_ctx['id'])])) {
                                            $locked_offer = $snap['locked_by_app'][intval($app_ctx['id'])];
                                        } elseif (!empty($member->user_id) && isset($snap['locked_by_user'][intval($member->user_id)])) {
                                            $locked_offer = $snap['locked_by_user'][intval($member->user_id)];
                                        }
                                        echo self::render_locked_in_chip($locked_offer, $season);
                                        ?>
                                    <?php endif; ?>
                                    <?php echo $this->render_other_team_chips($claims, $app_ctx ? $app_ctx['id'] : 0, isset($member->user_id) ? $member->user_id : 0, $member->email, $teams_config); ?>
                                </span>
                            </div>
                            <div class="cac-meta">
                                <a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a>
                                <?php if ($member->mobile): ?> &middot; <?php echo esc_html(self::format_phone($member->mobile)); ?><?php endif; ?>
                                <?php $member_position = self::position_for_person($position_map, isset($member->user_id) ? $member->user_id : 0, $member->email); ?>
                                <span class="cac-meta-position"><?php echo self::meta_position_html('selected', $member_position, $this->format_positions($member->preferred_positions)); ?></span>
                            </div>
                            <?php $member_control = $this->render_member_position_control($active_team, $season, isset($member->user_id) ? $member->user_id : 0, $member->email, $member_position); ?>
                            <?php if ($member_control !== ''): ?>
                                <div class="cac-decide"><span class="cac-position"><?php echo $member_control; ?></span></div>
                            <?php endif; ?>
                            <?php // A confirmed player's card is the team list: no trial tools (notes, attendance), and their fees where Mark attended was. ?>
                            <div class="cac-footer">
                                <span class="cac-expanders"><?php echo $this->render_card_expanders(
                                    $member->email,
                                    $app_ctx ? $app_ctx['id'] : 0,
                                    $app_ctx ? $app_ctx['form_data'] : array(),
                                    array(),
                                    $active_team,
                                    $season,
                                    isset($member->user_id) ? $member->user_id : 0,
                                    false,
                                    $this->format_positions($member->preferred_positions)
                                ); ?></span>
                                <span class="cac-actions"><?php echo isset($fee_standings[$i]) ? self::render_fee_standing($fee_standings[$i], $season) : ''; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($selection_roster as $member): ?>
                        <?php
                        $member_pending = isset($member->application_status) && $member->application_status === 'awaiting_payment';
                        if ($offers_on) {
                            // This player's offer, the deposit paid so far (a percentage on the card, never a figure) and the offer email's status.
                            $m_offer = isset($snap['offers'][intval($member->application_id)]) ? $snap['offers'][intval($member->application_id)] : null;
                            $m_paid = ($m_offer && isset($snap['paid'][$m_offer->id])) ? intval($snap['paid'][$m_offer->id]) : 0;
                            $m_mail = ($m_offer && isset($snap['mail'][$m_offer->id])) ? $snap['mail'][$m_offer->id] : array();
                            $m_locked = self::verdict_locked(self::offer_phase($member->status, $m_offer, $member_pending));
                        }
                        ?>
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
                                    <?php if ($offers_on): ?>
                                    <span class="cac-verdicts"><?php echo self::render_offer_verdict_chip(in_array($member->status, self::OFFER_VERDICTS, true) ? $member->status : 'tentative', $m_offer, $season, $member_pending); ?></span>
                                    <?php else: ?>
                                    <span class="cac-verdicts"><?php echo self::render_board_verdict_chip(in_array($member->status, array('selected', 'training_only'), true) ? $member->status : 'tentative'); ?></span>
                                    <?php endif; ?>
                                    <?php echo $this->render_other_team_chips($claims, $member->application_id, $member->user_id, $member->email, $teams_config); ?>
                                </span>
                            </div>
                            <div class="cac-meta">
                                <a href="mailto:<?php echo esc_attr($member->email); ?>"><?php echo esc_html($member->email); ?></a>
                                <?php if ($member->mobile): ?> &middot; <?php echo esc_html(self::format_phone($member->mobile)); ?><?php endif; ?>
                                <?php if (!empty($member->age_flag)): ?>
                                    &middot; <span class="cac-age-flag">Age <?php echo esc_html($member->age); ?> (born <?php echo esc_html($member->dob_display); ?>) — over the <?php echo esc_html($member->rule_label); ?> limit for this team; VV exemption required</span>
                                <?php endif; ?>
                                <span class="cac-meta-position"><?php echo self::meta_position_html($member->status, $member->team_position, $this->format_positions($member->preferred_positions)); ?></span>
                            </div>
                            <?php if ($offers_on): ?>
                            <?php $m_block = isset($snap['blocks'][intval($member->application_id)]) ? $snap['blocks'][intval($member->application_id)] : null; ?>
                            <div class="cac-offer-line"><?php echo $this->render_offer_status($active_team, $season, $member->status, $m_offer, $member_pending, $member->user_id, $m_paid, $m_mail, $m_block)
                                . $this->render_ns_status($active_team, $season, $member->status, $member->application_status, $member->user_id, isset($ns_notices[intval($member->application_id)]) ? $ns_notices[intval($member->application_id)] : array(), $teams_config, isset($ns_facts[intval($member->application_id)]) ? $ns_facts[intval($member->application_id)] : null); ?></div>
                            <?php endif; ?>
                            <?php if (!$this->read_only): ?>
                            <div class="cac-decide">
                                <form method="post">
                                    <input type="hidden" name="coach_action" value="set_selection">
                                    <input type="hidden" name="application_id" value="<?php echo intval($member->application_id); ?>">
                                    <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                    <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                                    <label class="cac-verdict-label"><?php echo $active_access['coach'] ? 'My verdict:' : 'Team verdict:'; ?>
                                        <select name="selection_status" onchange="if (window.murvcCoachVerdict) { window.murvcCoachVerdict(this); } else { this.form.submit(); }">
                                            <?php if ($offers_on): ?>
                                            <?php echo self::render_verdict_select_options($member->status, $member_pending, $m_locked); ?>
                                            <?php else: ?>
                                            <option value="clear">&mdash; None &mdash;</option>
                                            <?php echo self::render_verdict_options($member->status, isset($member->application_status) && $member->application_status === 'awaiting_payment'); ?>
                                            <?php endif; ?>
                                        </select>
                                    </label>
                                    <noscript><button type="submit" class="button button-small">Save</button></noscript>
                                </form>
                                <?php if ($offers_on): ?>
                                <span class="cac-offer"><?php echo $this->render_offer_controls($member->application_id, $active_team, $season, $member->status, $m_offer, $member_pending, $member->user_id, $m_paid, $m_block); ?></span>
                                <?php else: ?>
                                <span class="cac-offer"></span>
                                <?php endif; ?>
                                <span class="cac-position"><?php echo $this->render_position_control($member->application_id, $active_team, $season, $member->status, (string) $member->team_position); ?></span>
                            </div>
                            <?php elseif (self::team_position_label($member->team_position) !== ''): ?>
                            <div class="cac-decide"><span class="cac-position"><?php echo $this->render_position_control($member->application_id, $active_team, $season, $member->status, (string) $member->team_position); ?></span></div>
                            <?php endif; ?>
                            <div class="cac-footer">
                                <span class="cac-expanders"><?php echo $this->render_card_expanders(
                                    $member->email,
                                    intval($member->application_id),
                                    (is_array($decoded_roster = ($member->form_data ? json_decode($member->form_data, true) : array())) ? $decoded_roster : array()),
                                    $this->get_notes_for_application(intval($member->application_id)),
                                    $active_team,
                                    $season,
                                    $member->user_id,
                                    true,
                                    $this->format_positions($member->preferred_positions)
                                ); ?></span>
                                <span class="cac-actions"><?php echo $this->render_attendance_button($member->user_id, $member->email, $active_team, $attendance_date, $season); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php else: ?>
                    <p>No players yet — record verdicts below to build your team.</p>
                <?php endif; ?>
            </div>

            <?php if ($offers_on): ?>
                <?php echo $this->render_declined_section($active_team, $season, $declined_list, $teams_config, $offers_on ? $snap['blocks'] : array(), $ns_notices, $ns_facts); ?>
            <?php endif; ?>

            <div class="coach-team-section">
                <h3>Trial Applicants — <?php echo $active_config['gender'] === 'womens' ? "Women's" : ($active_config['gender'] === 'mens' ? "Men's" : 'All'); ?> (<?php echo count($applicants); ?>)</h3>
                <p class="coach-portal-hint">Every applicant in this competition is shown — including players outside your age group or who selected other teams, since players get redirected between trials and VV can grant age exemptions. Your verdicts apply to <strong><?php echo esc_html($active_config['name']); ?></strong> only; a player can be Selected by multiple teams (e.g. YSL and JPL) and every coach sees every team's verdicts. <?php if ($offers_on): ?>Selected and Training Only are <strong>offers</strong>: press <em>Send offer</em> on a player's card (or <em>Send offers to all</em>) and the committee approves it before the site emails the player. The player accepts or declines on the website and locks in their place by paying the deposit, and then appears under Confirmed. Tentative and Rejected players can't be offered, and changing the verdict of an offer that has been sent means withdrawing the offer first. <?php echo esc_html(TeamOversight_Offers::wording('ns_hint_list')); ?><?php else: ?>Selected players become official (team assignment + fees) when the club finalises selections.<?php endif; ?> Applicants marked <strong>Payment pending</strong> haven't paid their trial fee yet — you can see and assess them and reject them, but Tentative, Selected and Training Only open once it's paid. They're listed at the bottom of each section.</p>

                <?php // Trial book first, so the search sits directly above the list it filters (matters on a phone). ?>
                <?php if (!empty($applicants)): ?>
                    <form method="post" target="_blank" class="coach-export-form">
                        <input type="hidden" name="coach_action" value="export_applicants">
                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                        <button type="submit" class="button coach-btn-secondary">Trial book (print / save as PDF)</button>
                        <span class="coach-portal-hint">Every applicant with their application, emergency contacts, notes and verdicts — opens ready to print. Save it as a PDF before trials so you have it when the gym has no reception.</span>
                    </form>
                <?php endif; ?>

                <p class="coach-search-bar">
                    <label for="coach-search">Search</label>
                    <input type="text" id="coach-search" placeholder="Name, email, position, team...">
                    <label class="coach-check"><input type="checkbox" id="coach-filter-mine"> Only my verdicts</label>
                </p>

                <?php if (!empty($applicants)): ?>
                    <?php
                    // Sections: the coach's to-do pile first, then their
                    // actioned applicants, then the rest of the competition.
                    $needs_action = array();
                    $actioned = array();
                    $others = array();
                    foreach ($applicants as $a) {
                        // A Tentative, Selected or Training Only verdict for
                        // this team puts them on the team list above; listing
                        // them here as well doubled them up.
                        if (in_array($a['my_status'], array('tentative', 'selected', 'training_only'), true)) {
                            continue;
                        }
                        if ($a['picked_mine'] && !$a['my_status']) {
                            $needs_action[] = $a;
                        } elseif ($a['picked_mine']) {
                            $actioned[] = $a;
                        } else {
                            $others[] = $a;
                        }
                    }
                    // In every section, unpaid (Payment pending) applicants
                    // go to the bottom, then each section's own order.
                    $unpaid_last = function ($x, $y) {
                        return (empty($x['payment_pending']) ? 0 : 1) - (empty($y['payment_pending']) ? 0 : 1);
                    };
                    usort($needs_action, function ($x, $y) use ($unpaid_last) {
                        return $unpaid_last($x, $y) ?: $x['trial_number'] - $y['trial_number'];
                    });
                    // Rejected by this team: by trial number.
                    usort($actioned, function ($x, $y) use ($unpaid_last) {
                        return $unpaid_last($x, $y) ?: $x['trial_number'] - $y['trial_number'];
                    });
                    // Other applicants: players this team can field first;
                    // over-age players (needing a VV exemption) go last.
                    usort($others, function ($x, $y) use ($unpaid_last) {
                        $fx = empty($x['age_flag']) ? 0 : 1;
                        $fy = empty($y['age_flag']) ? 0 : 1;
                        return $unpaid_last($x, $y) ?: ($fx !== $fy ? $fx - $fy : $x['trial_number'] - $y['trial_number']);
                    });
                    // Tentative, Selected and Training Only players are on the
                    // team list above (with the offer counts from 2027), so
                    // these sections hold no verdict yet, Rejected, and the
                    // rest of the competition.
                    $moves_up = ' Once they have a Tentative, Selected or Training Only verdict they move to the team list above.';
                    $sections = array(
                        $active_access['coach']
                            ? array('Applied to your team — awaiting your verdict', $needs_action, 'They selected ' . $active_config['name'] . ' on their form and you haven\'t recorded a verdict yet.' . $moves_up)
                            : array('Applied to this team — awaiting a verdict', $needs_action, 'They selected ' . $active_config['name'] . ' on their form and the team has no verdict on them yet.' . $moves_up),
                        array('Applied to ' . $team_word . ' — Rejected', $actioned, ''),
                        array('Other applicants', $others, 'Applicants who didn\'t select ' . $team_word . ' — shown because players get redirected between trials and VV can grant age exemptions. Over-age players are listed last.'),
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
                                    <span class="cac-meta-position"><?php echo self::meta_position_html($a['my_status'], $a['my_position'], $a['positions']); ?></span>
                                    &middot; <span title="<?php echo esc_attr($a['teams_selected_names']); ?>">Applied for: <?php echo esc_html($a['teams_selected']); ?></span>
                                </div>

                                <?php if ($offers_on): ?>
                                <?php $a_ns = isset($ns_notices[intval($a['id'])]) ? $ns_notices[intval($a['id'])] : array(); ?>
                                <?php $a_facts = isset($ns_facts[intval($a['id'])]) ? $ns_facts[intval($a['id'])] : null; ?>
                                <div class="cac-offer-line"><?php echo $this->render_offer_status($active_team, $season, $a['my_status'], $a['my_offer'], !empty($a['payment_pending']), $a['user_id'])
                                    . $this->render_ns_status($active_team, $season, $a['my_status'], $a['application_status'], $a['user_id'], $a_ns, $teams_config, $a_facts); ?></div>
                                <?php endif; ?>

                                <?php if (!$this->read_only): ?>
                                <div class="cac-decide">
                                    <form method="post">
                                        <input type="hidden" name="coach_action" value="set_selection">
                                        <input type="hidden" name="application_id" value="<?php echo intval($a['id']); ?>">
                                        <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                                        <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                                        <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                                        <label class="cac-verdict-label"><?php echo $active_access['coach'] ? 'My verdict:' : 'Team verdict:'; ?>
                                            <select name="selection_status" onchange="if (window.murvcCoachVerdict) { window.murvcCoachVerdict(this); } else { this.form.submit(); }">
                                                <?php if ($offers_on): ?>
                                                <?php echo self::render_verdict_select_options($a['my_status'], !empty($a['payment_pending']), false); ?>
                                                <?php else: ?>
                                                <option value="clear" <?php selected($a['my_status'], ''); ?>>&mdash; None &mdash;</option>
                                                <?php echo self::render_verdict_options($a['my_status'], !empty($a['payment_pending'])); ?>
                                                <?php endif; ?>
                                            </select>
                                        </label>
                                        <noscript><button type="submit" class="button button-small">Save</button></noscript>
                                    </form>
                                    <?php if ($offers_on): ?>
                                    <span class="cac-offer"><?php echo $this->render_offer_controls($a['id'], $active_team, $season, $a['my_status'], $a['my_offer'], !empty($a['payment_pending']), $a['user_id'])
                                        . $this->render_ns_controls($a['id'], $active_team, $season, $a['my_status'], $a['application_status'], $a['user_id'], $a['name'], $a_ns, $teams_config, $a_facts); ?></span>
                                    <?php else: ?>
                                    <span class="cac-offer"></span>
                                    <?php endif; ?>
                                    <span class="cac-position"><?php echo $this->render_position_control($a['id'], $active_team, $season, $a['my_status'], $a['my_position']); ?></span>
                                </div>
                                <?php endif; ?>

                                <div class="cac-footer">
                                    <span class="cac-expanders">
                                        <?php echo $this->render_card_expanders($a['email'], $a['id'], $a['form_data'], $a['notes'], $active_team, $season, $a['user_id'], true, $a['positions']); ?>
                                    </span>
                                    <span class="cac-actions"><?php echo $this->render_attendance_button($a['user_id'], $a['email'], $active_team, $attendance_date, $season); ?></span>
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
            var showFlash = function (html, sticky) {
                if (!flash) { return; }
                if (html !== undefined) { flash.innerHTML = html; }
                if (flash.textContent.trim() === '') { return; }
                clearTimeout(flashTimer);
                flash.classList.remove('is-fading');
                flash.classList.add('coach-flash-toast');
                // An expired page (or a result the coach must act on) stays
                // up until it's reloaded or tapped away.
                if (sticky) { return; }
                flashTimer = setTimeout(function () {
                    flash.classList.add('is-fading');
                    flashTimer = setTimeout(function () {
                        flash.classList.remove('coach-flash-toast', 'is-fading');
                        flash.innerHTML = '';
                    }, 600);
                }, 4000);
            };
            if (flash) {
                flash.addEventListener('click', function () {
                    if (!flash.classList.contains('coach-flash-toast')) { return; }
                    clearTimeout(flashTimer);
                    flash.classList.remove('coach-flash-toast', 'is-fading');
                    flash.innerHTML = '';
                });
            }
            // A greyed Send offer, Offer again or Send "not selected" email
            // button says why when tapped: phones show no hover hint, and a
            // disabled button ignores taps altogether.
            if (portal) {
                portal.addEventListener('click', function (event) {
                    var blocked = event.target.closest('.coach-offer-blocked');
                    if (!blocked) { return; }
                    event.preventDefault();
                    var why = blocked.getAttribute('data-why') || '';
                    if (why === '') { return; }
                    var box = document.createElement('div');
                    box.className = 'coach-portal-notice';
                    var line = document.createElement('p');
                    line.textContent = why;
                    box.appendChild(line);
                    showFlash(box.outerHTML);
                });
            }

            // Offers (2027 on): the parts of the page that follow a save. The
            // card's status line and verdict menu, the Players heading, the
            // flow chart and the Send offers to all panel are only sent (and
            // only found) for a season from 2027.
            var patchOffers = function (card, d) {
                if (!d) { return; }
                if (card) {
                    var line = card.querySelector('.cac-offer-line');
                    if (line && typeof d.offer_label === 'string') { line.innerHTML = d.offer_label; }
                    var menu = card.querySelector('select[name="selection_status"]');
                    if (menu && typeof d.verdict_options === 'string') { menu.innerHTML = d.verdict_options; }
                }
                var heading = document.getElementById('coach-players-heading');
                if (heading && typeof d.counts_html === 'string') { heading.innerHTML = d.counts_html; }
                var flowChart = document.getElementById('coach-offer-flow');
                if (flowChart && typeof d.flow_html === 'string') { flowChart.innerHTML = d.flow_html; }
                var bulk = document.getElementById('coach-offer-bulk');
                if (bulk && typeof d.bulk_html === 'string') { bulk.innerHTML = d.bulk_html; }
                var nsBulk = document.getElementById('coach-ns-bulk');
                if (nsBulk && typeof d.ns_bulk_html === 'string') { nsBulk.innerHTML = d.ns_bulk_html; }
            };
            // A card sent back as it really is (a stale page repainting itself).
            var repaintCard = function (card, d) {
                if (!card || !d) { return; }
                if (typeof d.status === 'string') {
                    card.className = card.className.replace(/\bverdict-\S+/g, '').trim();
                    if (d.status) { card.classList.add('verdict-' + d.status); }
                    if (card.hasAttribute('data-has-verdict')) { card.setAttribute('data-has-verdict', d.status ? '1' : '0'); }
                }
                var chips = card.querySelector('.cac-verdicts');
                if (chips && typeof d.chips === 'string') { chips.innerHTML = d.chips; }
                var offer = card.querySelector('.cac-offer');
                if (offer && typeof d.offer === 'string') { offer.innerHTML = d.offer; }
                var place = card.querySelector('.cac-position');
                if (place && typeof d.position === 'string') { place.innerHTML = d.position; }
                var shown = card.querySelector('.cac-meta-position');
                if (shown && typeof d.meta_position === 'string') { shown.innerHTML = d.meta_position; }
                patchOffers(card, d);
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
                            if (!res || typeof res !== 'object') {
                                // Not the handler's answer: WordPress replies 0 (with a 400)
                                // once the login has lapsed, so retrying can't help.
                                showFlash('<div class="coach-portal-notice coach-portal-expired"><p>Your session has expired — reload the page (you may need to log in again).</p></div>', true);
                                return;
                            }
                            showFlash(res.data && res.data.notice ? res.data.notice : '<div class="coach-portal-notice"><p>That verdict could not be saved — please try again.</p></div>', !!(res.data && res.data.expired));
                            // The offer behind the verdict said no: show the card as it really is.
                            if (res.data && res.data.refresh) { repaintCard(card, res.data.refresh); }
                            return;
                        }
                        Array.prototype.forEach.call(select.options, function (o) { o.defaultSelected = (o.value === select.value); });
                        if (card) {
                            card.className = card.className.replace(/\bverdict-\S+/g, '').trim();
                            if (res.data.status) { card.classList.add('verdict-' + res.data.status); }
                            if (card.hasAttribute('data-has-verdict')) { card.setAttribute('data-has-verdict', res.data.status ? '1' : '0'); }
                            var chips = card.querySelector('.cac-verdicts');
                            if (chips) { chips.innerHTML = res.data.chips; }
                            // The Offer accepted control follows the verdict
                            // (a changed verdict clears the acceptance).
                            var offer = card.querySelector('.cac-offer');
                            if (offer && typeof res.data.offer === 'string') { offer.innerHTML = res.data.offer; }
                            // So do the team position menu and the card's top line.
                            var place = card.querySelector('.cac-position');
                            if (place && typeof res.data.position === 'string') { place.innerHTML = res.data.position; }
                            var shown = card.querySelector('.cac-meta-position');
                            if (shown && typeof res.data.meta_position === 'string') { shown.innerHTML = res.data.meta_position; }
                        }
                        patchOffers(card, res.data);
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

            // Team position: saved in the background like a verdict, with
            // the card's chips and picker refreshed from what was stored.
            window.murvcCoachPosition = function (select) {
                var form = select.form;
                var card = select.closest('.coach-applicant-card');
                var previous = '';
                Array.prototype.forEach.call(select.options, function (o) { if (o.defaultSelected) { previous = o.value; } });
                var body = new FormData(form);
                // A confirmed player's card (team list) saves by person.
                var kind = form.querySelector('input[name="coach_action"]');
                body.append('action', kind && kind.value === 'set_member_position' ? 'coach_set_member_position' : 'coach_set_position');
                if (card && card.getAttribute('data-card-context') === 'board') { body.append('card_context', 'board'); }
                select.disabled = true;
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        select.disabled = false;
                        if (!res || typeof res !== 'object') {
                            select.value = previous;
                            showFlash('<div class="coach-portal-notice coach-portal-expired"><p>Your session has expired — reload the page (you may need to log in again).</p></div>', true);
                            return;
                        }
                        if (!res.success) {
                            select.value = previous;
                            showFlash(res.data && res.data.notice ? res.data.notice : '<div class="coach-portal-notice"><p>That position could not be saved — please try again.</p></div>', !!(res.data && res.data.expired));
                            return;
                        }
                        if (card) {
                            var place = card.querySelector('.cac-position');
                            if (place && typeof res.data.position === 'string') { place.innerHTML = res.data.position; }
                            var shown = card.querySelector('.cac-meta-position');
                            if (shown && typeof res.data.meta_position === 'string') { shown.innerHTML = res.data.meta_position; }
                        }
                        showFlash(res.data.notice);
                    })
                    .catch(function () {
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

            // Offer buttons (Send offer, Cancel request, Withdraw offer, Remind,
            // +7 days, Move to declined, Send offers to all): saved in the
            // background like a verdict, refreshing the card, the Players
            // heading and the flow chart. A form with data-confirm asks first
            // (cancelling sends nothing). A page that has to change lists
            // reloads, with the result shown after the reload. Without
            // JavaScript (or if the request can't be sent) it's a normal post.
            portal.addEventListener('submit', function (event) {
                var form = event.target;
                if (!form || !form.classList || !form.classList.contains('coach-offer-form')) { return; }
                event.preventDefault();
                try { sessionStorage.removeItem(KEY); } catch (e) {}
                var ask = form.getAttribute('data-confirm');
                if (ask && !window.confirm(ask)) { return; }
                var card = form.closest('.coach-applicant-card');
                var button = form.querySelector('button');
                var body = new FormData(form);
                body.append('action', 'coach_set_offer');
                if (card && card.getAttribute('data-card-context') === 'board') { body.append('card_context', 'board'); }
                if (button) { button.disabled = true; }
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (button) { button.disabled = false; }
                        if (!res || typeof res !== 'object') {
                            showFlash('<div class="coach-portal-notice coach-portal-expired"><p>Your session has expired — reload the page (you may need to log in again).</p></div>', true);
                            return;
                        }
                        if (!res.success) {
                            showFlash(res.data && res.data.notice ? res.data.notice : '<div class="coach-portal-notice"><p>That could not be saved — please try again.</p></div>', !!(res.data && res.data.expired));
                            // A stale card repaints itself as it really is.
                            if (res.data && res.data.refresh) { repaintCard(card, res.data.refresh); }
                            return;
                        }
                        if (res.data && res.data.reload) {
                            // The player moved to another list (or many were sent): reload, keeping the place on the page.
                            savePlace(form);
                            window.location.reload();
                            return;
                        }
                        if (card) {
                            var chips = card.querySelector('.cac-verdicts');
                            if (chips) { chips.innerHTML = res.data.chips; }
                            var offer = card.querySelector('.cac-offer');
                            if (offer) { offer.innerHTML = res.data.offer; }
                            patchOffers(card, res.data);
                        }
                        // A result the coach must act on (undone, but already
                        // on the team) stays up until tapped away.
                        showFlash(res.data.notice, String(res.data.notice).indexOf('coach-portal-sticky') !== -1);
                    })
                    .catch(function () {
                        if (button) { button.disabled = false; }
                        savePlace(form);
                        form.submit();
                    });
            });

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
            // An expired-page message stays up like the verdict one does, and
            // so does one the coach must act on (coach-portal-sticky).
            showFlash(undefined, !!(flash && flash.querySelector('.coach-portal-expired, .coach-portal-sticky')));
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

        /* Free agent: no paperwork needed. Soft pink — the one hue no
           verdict or other chip uses, so it never reads as "Selected"
           (green) on a card. */
        .chip-freeagent {
            background: #fbeaf3;
            color: #8c2361;
            border: 1px solid #e2a5c7;
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
        /* Confirmed players: white, but the side bar keeps their colour. */
        .coach-applicant-card.confirmed-playing_member { border-left-color: #46b450; }
        .coach-applicant-card.confirmed-training_only { border-left-color: #339af0; }

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

        /* Card controls, two rows: the verdict and offer (editors only),
           then the expanders with Mark attended at the right end, so the
           expanders keep to one line and Mark attended sits in the same
           place on every card. */
        .cac-decide {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .cac-decide form {
            margin: 0;
        }

        .cac-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 12px;
            margin-top: 8px;
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

        }

        /* ---- One look for every control --------------------------------
           The theme styles .button as cyan, square and uppercase, and native
           selects/date pickers bring the browser's own look; the portal's
           expanders were navy. Everything interactive in the portal now
           shares one set: navy filled = the main action, white with a navy
           outline = everything else; same font, size, height and corners. */
        .coach-portal {
            --cp-navy: #1d3d6e;
            --cp-navy-dark: #142c50;
            --cp-navy-tint: #eef2f8;
            --cp-border: #c3c8d0;
            --cp-text: #3c434a;
            --cp-muted: #6b7280;
            --cp-radius: 4px;
            --cp-control-h: 34px;
        }

        .coach-portal .button,
        .coach-portal .coach-app-details > summary,
        .coach-portal .coach-team-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            box-sizing: border-box;
            min-height: var(--cp-control-h);
            margin: 0;
            padding: 6px 12px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            letter-spacing: normal;
            text-transform: none;
            text-decoration: none;
            border: 1px solid var(--cp-navy);
            border-radius: var(--cp-radius);
            box-shadow: none;
            cursor: pointer;
            transition: background-color 0.15s, color 0.15s, border-color 0.15s;
        }

        /* Primary: the main action in a spot (Add note, Save). */
        .coach-portal .button {
            background: var(--cp-navy);
            color: #fff;
        }

        .coach-portal .button:hover,
        .coach-portal .button:focus {
            background: var(--cp-navy-dark);
            border-color: var(--cp-navy-dark);
            color: #fff;
        }

        /* Secondary: expanders, tabs, tools, Mark attended, Cancel. */
        .coach-portal .coach-btn-secondary,
        .coach-portal .coach-attend-btn,
        .coach-portal .coach-app-details > summary,
        .coach-portal .coach-team-tab {
            background: #fff;
            color: var(--cp-navy);
        }

        .coach-portal .coach-btn-secondary:hover,
        .coach-portal .coach-btn-secondary:focus,
        .coach-portal .coach-attend-btn:hover,
        .coach-portal .coach-attend-btn:focus,
        .coach-portal .coach-app-details > summary:hover,
        .coach-portal .coach-team-tab:hover {
            background: var(--cp-navy-tint);
            border-color: var(--cp-navy);
            color: var(--cp-navy);
        }

        /* Open expander / current team: filled, like a pressed button. */
        .coach-portal .coach-app-details[open] > summary,
        .coach-portal .coach-team-tab.active,
        .coach-portal .coach-team-tab.active:hover {
            background: var(--cp-navy);
            border-color: var(--cp-navy);
            color: #fff;
        }

        /* Expanders hold mixed inline content ("Attendance (" + count + ")"
           + arrow), which flex would space apart — lay them out inline,
           with the height coming from the line box instead. */
        .coach-portal .coach-app-details > summary {
            display: inline-block;
            height: var(--cp-control-h);
            padding: 0 12px;
            line-height: calc(var(--cp-control-h) - 2px);
            white-space: nowrap;
        }

        .coach-portal .coach-app-details > summary::after {
            font-size: 10px;
        }

        .coach-portal .coach-team-tab {
            font-size: 14px;
            margin: 0 6px 6px 0;
        }

        /* Committee team picker and the view-only banner. */
        .coach-team-picker {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .coach-team-picker select {
            min-width: 240px;
            max-width: 100%;
        }

        .coach-view-only {
            margin: 8px 0 14px;
            padding: 8px 12px;
            font-size: 13px;
            color: var(--cp-text);
            background: var(--cp-navy-tint);
            border: 1px solid var(--cp-border);
            border-left: 4px solid var(--cp-navy);
            border-radius: var(--cp-radius);
        }

        .coach-portal .coach-team-section h3 small {
            font-size: 0.6em;
            font-weight: 400;
            color: var(--cp-muted);
        }

        /* Attendance: marked = green, so it reads at a glance at the gym. */
        .coach-portal .coach-attend-btn.is-marked,
        .coach-portal .coach-attend-btn.is-marked:hover {
            background: #e8f5ea;
            border-color: #2e8540;
            color: #1e6b2a;
        }

        /* Offer accepted: green like a marked attendance. */
        .coach-portal .coach-offer-btn.is-accepted,
        .coach-portal .coach-offer-btn.is-accepted:hover,
        .coach-portal .coach-offer-btn.is-accepted:focus {
            background: #e8f5ea;
            border-color: #2e8540;
            color: #1e6b2a;
        }

        .coach-portal .cac-offer:empty,
        .coach-portal .cac-position:empty,
        .coach-portal .cac-offer-line:empty {
            display: none;
        }

        /* ---- Offers (1.76.0) --------------------------------------------
           The status line under a card's contact details, the offer buttons
           beside the verdict, the chips, the flow chart at the top of the
           team, the Send offers to all panel and the Declined list. Same
           tokens and controls as the rest of the portal. */
        .coach-portal .cac-offer-status {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 2px 10px;
            margin: 6px 0 0;
            font-size: 13px;
            color: var(--cp-text);
        }

        .coach-portal .cos-label {
            font-weight: 600;
        }

        .coach-portal .cos-sub {
            font-size: 12px;
            color: var(--cp-muted);
        }

        .coach-portal .cac-offer-status.is-awaiting .cos-label,
        .coach-portal .cac-offer-status.is-sent .cos-label {
            color: var(--cp-navy);
        }

        .coach-portal .cac-offer-status.is-accepted .cos-label,
        .coach-portal .cac-offer-status.is-locked-in .cos-label {
            color: #1e6b2a;
        }

        .coach-portal .cac-offer-status.is-sent-overdue .cos-label,
        .coach-portal .cac-offer-status.is-accepted-overdue .cos-label {
            color: #9a3412;
        }

        .coach-portal .cac-offer-status.is-not-approved .cos-label,
        .coach-portal .cac-offer-status.is-unpaid .cos-label {
            color: #a00;
        }

        .coach-portal .cac-offer-status.is-declined .cos-label,
        .coach-portal .cac-offer-status.is-withdrawn .cos-label,
        .coach-portal .cac-offer-status.is-left .cos-label {
            color: var(--cp-muted);
        }

        .coach-portal .coach-offer-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 8px;
        }

        .coach-portal .coach-offer-actions form {
            margin: 0;
        }

        .coach-portal .coach-offer-note {
            flex-basis: 100%;
            font-size: 12px;
            color: var(--cp-muted);
        }

        .coach-portal .coach-offer-btn[disabled] {
            cursor: not-allowed;
        }

        /* Greyed but still tappable, so a tap can say why (phones show no hover hint). */
        .coach-portal .coach-offer-btn[aria-disabled="true"] {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .verdict-chip.offer-sent-overdue,
        .verdict-chip.offer-accepted-overdue {
            background: #fdeee0;
            color: #8a4b00;
            border: 1px solid #e0a86b;
        }

        .verdict-chip.offer-declined,
        .verdict-chip.offer-left,
        .verdict-chip.offer-withdrawn,
        .verdict-chip.offer-not-approved {
            background: #f0f0f1;
            color: #50575e;
            border: 1px solid #c3c4c7;
        }

        .verdict-chip.is-locked {
            background: #dff3e4;
            color: #14532d;
            border: 1px solid #9fd3ad;
        }

        /* The flow chart: a row of steps with arrows, the players waiting at
           each step on it, the side exits beneath. Wraps on a phone. */
        .coach-portal .cp-flow {
            margin: 8px 0 14px;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid var(--cp-border);
            border-radius: var(--cp-radius);
        }

        .coach-portal .cp-flow-steps {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 22px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .coach-portal .cp-flow-step {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            padding: 5px 10px;
            font-size: 13px;
            line-height: 1.2;
            color: var(--cp-muted);
            background: #fff;
            border: 1px solid var(--cp-border);
            border-radius: var(--cp-radius);
        }

        .coach-portal .cp-flow-step.is-action {
            border-style: dashed;
        }

        .coach-portal .cp-flow-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 50%;
            right: -15px;
            width: 7px;
            height: 7px;
            border-top: 2px solid var(--cp-muted);
            border-right: 2px solid var(--cp-muted);
            transform: translateY(-50%) rotate(45deg);
        }

        .coach-portal .cp-flow-step.has-count {
            color: var(--cp-navy);
            background: var(--cp-navy-tint);
            border-color: var(--cp-navy);
        }

        .coach-portal .cp-flow-step[data-step="confirmed"].has-count {
            color: #14532d;
            background: #dff3e4;
            border-color: #2e8540;
        }

        .coach-portal .cp-flow-name {
            font-weight: 600;
        }

        .coach-portal .cp-flow-count {
            min-width: 22px;
            padding: 1px 6px;
            font-weight: 700;
            text-align: center;
            color: var(--cp-muted);
            background: var(--cp-navy-tint);
            border-radius: 10px;
        }

        .coach-portal .has-count > .cp-flow-count {
            color: #fff;
            background: var(--cp-navy);
        }

        .coach-portal .cp-flow-step[data-step="confirmed"].has-count > .cp-flow-count {
            background: #2e8540;
        }

        .coach-portal .cp-flow-flag {
            padding: 0 5px;
            font-size: 11px;
            font-weight: 600;
            color: #9a3412;
            background: #fdeee0;
            border: 1px solid #e0a86b;
            border-radius: 3px;
        }

        .coach-portal .cp-flow-exits {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 10px;
            margin: 10px 0 0;
            padding: 0;
            list-style: none;
        }

        .coach-portal .cp-flow-exits::before {
            content: "Side exits:";
            font-size: 12px;
            color: var(--cp-muted);
        }

        .coach-portal .cp-flow-exit {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            padding: 3px 9px;
            font-size: 12px;
            color: var(--cp-muted);
            background: #fafafa;
            border: 1px solid var(--cp-border);
            border-radius: var(--cp-radius);
        }

        .coach-portal .cp-flow-exit.has-count.is-overdue {
            color: #9a3412;
            background: #fdeee0;
            border-color: #e0a86b;
        }

        .coach-portal .cp-flow-exit.has-count.is-not-approved {
            color: #a00;
            background: #fdf0f0;
            border-color: #e6a0a0;
        }

        .coach-portal .cp-flow-exit.has-count.is-declined {
            color: var(--cp-text);
            background: #f0f0f1;
            border-color: #9aa0a6;
        }

        .coach-portal .cp-flow-exit.has-count.is-rejected {
            color: #6e4c00;
            background: #fcf9e8;
            border-color: #dba617;
        }

        .coach-portal .cp-flow-exit .cp-flow-count {
            min-width: 18px;
            padding: 0 5px;
            background: #fff;
        }

        .coach-portal .cp-flow-exit.has-count .cp-flow-count {
            color: var(--cp-text);
            background: #fff;
        }

        .coach-portal .cp-flow-caption {
            margin: 8px 0 0;
            font-size: 12px;
            color: var(--cp-muted);
        }

        /* Send offers to all, and the Declined list. */
        .coach-portal #coach-offer-bulk {
            margin: 6px 0 12px;
        }

        .coach-portal #coach-offer-bulk:empty {
            display: none;
        }

        /* 1.80.0: Send "not selected" emails, and what a Rejected card says about it. */
        .coach-portal #coach-ns-bulk {
            margin: 6px 0 12px;
        }

        .coach-portal #coach-ns-bulk:empty {
            display: none;
        }

        .coach-portal .cac-offer-status.is-ns-untold .cos-label {
            color: #9a3412;
        }

        .coach-portal .cac-offer-status.is-ns-sent .cos-label {
            color: var(--cp-navy);
        }

        .coach-portal .cac-offer-status.is-ns-stopped .cos-label {
            color: var(--cp-muted);
        }

        .coach-portal .cac-offer-status.is-ns-note {
            margin-top: 2px;
        }

        .coach-portal .coach-bulk-body {
            margin-top: 8px;
            padding: 10px 12px;
            background: var(--cp-navy-tint);
            border: 1px solid var(--cp-border);
            border-left: 4px solid var(--cp-navy);
            border-radius: var(--cp-radius);
        }

        .coach-portal .coach-bulk-body .coach-portal-hint {
            margin: 0 0 8px;
        }

        .coach-portal .coach-bulk-list {
            margin: 0 0 10px 18px;
            padding: 0;
            font-size: 13px;
        }

        .coach-portal .coach-declined > .coach-portal-hint {
            margin: 10px 0;
        }

        .coach-portal .cac-position-text {
            color: var(--cp-text);
        }

        /* The team list export, on the right of the Coaching Staff heading. */
        .coach-portal .coach-staff-heading {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px 12px;
            margin: 1.33em 0 8px;
        }

        .coach-portal .coach-staff-heading h4 {
            margin: 0;
        }

        .coach-portal .coach-export-form {
            margin: 0;
        }

        /* Team list fees: a status the height of the controls beside it,
           coloured by where the player stands (shared with Member Lookup). */
        <?php echo TeamOversight_Payments::standing_pill_css('.coach-portal'); ?>

        .coach-fee-alert {
            margin: 0 0 10px;
            padding: 8px 12px;
            font-size: 13px;
            color: #7a0000;
            background: #fce8e8;
            border: 1px solid #e3a1a1;
            border-left: 4px solid #c62d2d;
            border-radius: 4px;
        }

        .coach-portal .button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        /* Fields: same font, size, border and height as the buttons. */
        .coach-portal select,
        .coach-portal input[type=text],
        .coach-portal input[type=date],
        .coach-portal textarea {
            box-sizing: border-box;
            min-height: var(--cp-control-h);
            margin: 0;
            padding: 5px 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.3;
            color: var(--cp-text);
            background-color: #fff;
            border: 1px solid var(--cp-border);
            border-radius: var(--cp-radius);
            box-shadow: none;
        }

        .coach-portal select:focus,
        .coach-portal input[type=text]:focus,
        .coach-portal input[type=date]:focus,
        .coach-portal textarea:focus {
            border-color: var(--cp-navy);
            outline: 2px solid rgba(29, 61, 110, 0.18);
            outline-offset: 0;
        }

        .coach-portal textarea {
            display: block;
            width: 100%;
            min-height: 64px;
        }

        .coach-portal ::placeholder {
            color: #8a9099;
        }

        /* Labels and small text: one size. */
        .coach-portal label,
        .coach-portal .cac-verdict-label,
        .coach-portal .coach-field-label {
            font-size: 13px;
            color: var(--cp-text);
        }

        .coach-portal .cac-verdict-label,
        .coach-portal .coach-field-label,
        .coach-portal .coach-search-bar > label[for] {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            margin: 0;
        }

        .coach-portal .coach-attendance-empty,
        .coach-portal .coach-attendance-list {
            font-size: 13px;
        }

        .coach-portal .coach-attendance-empty {
            margin: 8px 0 0;
            color: var(--cp-muted);
        }

        .coach-portal .coach-attendance-bar input[type=date] {
            margin-left: 0;
        }

        .coach-search-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 14px;
        }

        .coach-search-bar #coach-search {
            width: 260px;
            max-width: 100%;
        }

        .coach-portal .coach-check {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .coach-export-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 12px;
            margin: 0 0 14px;
        }

        /* Card footer: expanders pack tightly; actions sit together. */
        .coach-portal .cac-expanders {
            gap: 6px;
        }

        .coach-portal .cac-actions {
            align-items: center;
            gap: 8px;
        }

        .coach-portal .coach-note-form .button {
            margin: 6px 6px 0 0;
        }

        /* Note Edit/Delete: plain text links, not buttons. */
        .coach-portal .coach-note-edit > summary,
        .coach-portal .coach-note-link {
            display: inline;
            min-height: 0;
            padding: 0;
            font-family: inherit;
            font-size: 12px;
            font-weight: 400;
            text-transform: none;
            letter-spacing: normal;
            color: var(--cp-navy);
            background: none;
            border: 0;
            box-shadow: none;
            text-decoration: underline;
        }

        .coach-portal .coach-note-delete .coach-note-link {
            color: #b32d2e;
        }

        /* The Edit link lives inside the Notes expander; keep the
           expander's hover fill and arrow off it. */
        .coach-portal .coach-app-details .coach-note-edit > summary:hover,
        .coach-portal .coach-app-details .coach-note-edit[open] > summary {
            background: none;
            color: var(--cp-navy);
        }

        .coach-portal .coach-app-details .coach-note-edit > summary::after {
            content: none;
        }

        @media (max-width: 782px) {
            /* Bigger tap targets; 16px fields stop iOS zooming on focus. */
            .coach-portal {
                --cp-control-h: 40px;
            }

            .coach-portal select,
            .coach-portal input[type=text],
            .coach-portal input[type=date],
            .coach-portal textarea {
                font-size: 16px;
            }

            .coach-search-bar #coach-search {
                width: 100%;
            }

            /* Full-width rows on a phone: the verdict with room to read the
               choice, the offer, a big Mark attended target, then the
               expanders filling each line. */
            .coach-portal .cac-decide {
                flex-direction: column;
                align-items: stretch;
            }

            .coach-portal .cac-decide form {
                display: flex;
            }

            .coach-portal .cac-actions {
                order: -1;
            }

            .coach-portal .cac-actions:empty {
                display: none;
            }

            .coach-portal .cac-actions .coach-attend-btn,
            .coach-portal .cac-actions .cac-fee,
            .coach-portal .cac-decide .coach-offer-btn {
                width: 100%;
            }

            /* Offers: the buttons share the width two to a row, the flow chart's steps wrap. */
            .coach-portal .coach-offer-actions > form,
            .coach-portal .coach-offer-actions > .coach-offer-btn {
                flex: 1 1 140px;
            }

            .coach-portal .coach-bulk-body .coach-offer-btn {
                width: 100%;
            }

            .coach-portal .cp-flow {
                padding: 8px 10px;
            }

            .coach-portal .cp-flow-steps {
                gap: 6px 18px;
            }

            .coach-portal .cp-flow-step {
                gap: 5px;
                padding: 4px 8px;
                font-size: 12px;
            }

            .coach-portal .cp-flow-step:not(:last-child)::after {
                right: -13px;
                width: 6px;
                height: 6px;
            }

            .coach-portal .cp-flow-count {
                min-width: 18px;
                padding: 0 5px;
            }

            .coach-portal .cp-flow-flag {
                font-size: 10px;
            }

            /* Closed expanders only, and only the card's own: an open one
               keeps its full row, and the note Edit nested in Notes is
               left alone. */
            .coach-portal .cac-expanders > details:not([open]) {
                flex: 1 1 auto;
            }

            .coach-portal .cac-expanders > .coach-app-details > summary {
                display: block;
                text-align: center;
            }

            .coach-portal .cac-verdict-label {
                flex: 1 1 auto;
                white-space: nowrap;
            }

            .coach-portal .cac-verdict-label select {
                flex: 1 1 auto;
                min-width: 0;
                width: 100%;
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
        if (!isset($_POST['coach_action']) || !in_array($_POST['coach_action'], array_merge(array('set_selection', 'set_offer', 'set_position', 'set_member_position', 'add_note', 'edit_note', 'delete_note'), self::OFFER_ACTIONS), true)) {
            return '';
        }

        if (!isset($_POST['coach_nonce']) || !wp_verify_nonce($_POST['coach_nonce'], 'coach_portal_action')) {
            // Almost always a page left open past the nonce lifetime (a day
            // at most), where retrying can never work — only a reload does.
            return self::expired_notice();
        }

        $team = isset($_POST['coach_team']) ? sanitize_text_field($_POST['coach_team']) : '';
        if (!in_array($team, $my_team_codes, true)) {
            return '<div class="coach-portal-notice"><p>You can only act for teams you coach (or can edit as committee).</p></div>';
        }

        // A confirmed player's position, set on the team list: by person,
        // not application (they may have been placed without one).
        if ($_POST['coach_action'] === 'set_member_position') {
            return $this->save_member_position($team, $season);
        }

        // Send offers to all: a list of applications, each re-checked one by one.
        if ($_POST['coach_action'] === 'offer_bulk_request') {
            return $this->handle_offer_bulk($team, $season);
        }

        // 1.80.0: Send "not selected" emails for the board, likewise.
        if ($_POST['coach_action'] === 'offer_bulk_not_selected') {
            return $this->handle_ns_bulk($team, $season);
        }

        global $wpdb;
        $application_id = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;

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

        // Send offer, Cancel request, Withdraw, Remind, +7 days, Move to declined.
        if (in_array($_POST['coach_action'], self::OFFER_ACTIONS, true)) {
            return $this->handle_offer_action($_POST['coach_action'], $team, $season, $application);
        }

        if ($_POST['coach_action'] === 'add_note') {
            $note = isset($_POST['coach_note']) ? sanitize_textarea_field(wp_unslash($_POST['coach_note'])) : '';
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

            $note = isset($_POST['coach_note']) ? sanitize_textarea_field(wp_unslash($_POST['coach_note'])) : '';
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

        $verdict_labels = self::get_verdict_labels();
        $who = esc_html($application->name) . ' (#' . intval($application->trial_number) . ')';

        if ($_POST['coach_action'] === 'set_offer') {
            // Retired (1.79.0): the coach no longer records the player's yes.
            // From 2027 the player answers the offer on the website, and a
            // page left open from before the update changes nothing and
            // repaints its card. Before 2027 there was never anything to mark.
            if (!self::offers_on($season)) {
                return '<div class="coach-portal-notice"><p>Offers only need the player\'s acceptance from the ' . self::OFFER_ACCEPTANCE_FROM . ' season.</p></div>';
            }
            $this->offer_refresh_ok = true;
            return '<div class="coach-portal-notice"><p>' . $who . ': players answer their offer on the website, so there is nothing to mark here. Reload the page; use <em>Send offer</em> on their card.</p></div>';
        }

        if ($_POST['coach_action'] === 'set_position') {
            // The coach's choice of position on their team, kept with the
            // team's verdict; the application's own positions are untouched.
            $position = isset($_POST['team_position']) ? sanitize_key(wp_unslash($_POST['team_position'])) : '';
            if ($position !== '' && self::team_position_label($position) === '') {
                return '<div class="coach-portal-notice"><p>Please pick one of the positions.</p></div>';
            }
            $verdict = $wpdb->get_row($wpdb->prepare("
                SELECT id, status FROM {$wpdb->prefix}team_trial_selections
                WHERE application_id = %d AND team = %s
            ", $application_id, $team));
            if (!$verdict || !in_array($verdict->status, self::POSITION_STATUSES, true)) {
                return '<div class="coach-portal-notice"><p>' . $who . ' has no Tentative, Selected or Training Only verdict for ' . esc_html($team) . ' — reload the page to see the current verdict.</p></div>';
            }
            $wpdb->update(
                $wpdb->prefix . 'team_trial_selections',
                array('team_position' => $position !== '' ? $position : null),
                array('id' => $verdict->id),
                array('%s'),
                array('%d')
            );
            // Already on the team: the team list's copy follows, so the two
            // never disagree.
            $place = self::playing_place($team, $season, intval($application->user_id), $application->email);
            if ($place) {
                self::store_team_position($team, $season, intval($place->user_id), (string) $place->email, $position);
            }
            if ($position === '') {
                return '<div class="coach-portal-success"><p>Team position cleared for ' . $who . ' on ' . esc_html($team) . '.</p></div>';
            }
            return '<div class="coach-portal-success"><p>' . $who . ': <strong>' . esc_html(self::team_position_label($position)) . '</strong> for ' . esc_html($team) . '.</p></div>';
        }

        // set_selection
        $status = sanitize_text_field($_POST['selection_status']);

        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT id, status, offer_accepted FROM {$wpdb->prefix}team_trial_selections
            WHERE application_id = %d AND team = %s
        ", $application_id, $team));

        if ($status === 'clear') {
            // A verdict with an offer behind it can't just be cleared (2027 on).
            $gate_note = '';
            $refusal = $this->offer_verdict_gate($application_id, $team, $season, '', $who, $gate_note);
            if ($refusal !== '') {
                return $refusal;
            }
            $wpdb->delete($wpdb->prefix . 'team_trial_selections', array(
                'application_id' => $application_id,
                'team' => $team,
            ), array('%d', '%s'));
            return '<div class="coach-portal-success"><p>Verdict cleared for ' . $who . '.' . $gate_note . '</p></div>';
        }

        if (!in_array($status, self::SELECTION_STATUSES, true)) {
            return '';
        }

        // Unpaid applicants can be assessed and rejected, but not put
        // forward (Tentative / Selected / Training Only) until they pay.
        if ($application->application_status === 'awaiting_payment' && $status !== 'rejected') {
            return '<div class="coach-portal-notice"><p>' . esc_html($application->name) . ' (#' . intval($application->trial_number) . ') hasn\'t paid the trial fee yet. You can reject them now; other verdicts open once the fee is paid.</p></div>';
        }

        // Last, just before the write: a request waiting for the committee is
        // cancelled by a change; once an offer is sent, accepted or locked in
        // the change is refused (withdraw the offer first).
        $gate_note = '';
        $refusal = $this->offer_verdict_gate($application_id, $team, $season, $status, $who, $gate_note);
        if ($refusal !== '') {
            return $refusal;
        }

        if ($existing) {
            $fields = array('status' => $status, 'created_by' => get_current_user_id(), 'updated_date' => current_time('mysql'));
            $formats = array('%s', '%d', '%s');
            if ($existing->status !== $status) {
                // A different verdict (Selected <-> Training Only included)
                // is a different offer: the locked-in mirror never carries
                // over (the gate above refuses a change under a locked-in
                // offer, so this only ever clears a stale flag).
                $fields['offer_accepted'] = 0;
                $fields['offer_accepted_at'] = null;
                $fields['offer_accepted_by'] = null;
                $formats = array_merge($formats, array('%d', '%s', '%d'));
            }
            $wpdb->update(
                $wpdb->prefix . 'team_trial_selections',
                $fields,
                array('id' => $existing->id),
                $formats,
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

        $offer_hint = '';
        if (self::offers_on($season)) {
            // 2027 on: the next step is Send offer, which goes to the committee first.
            if (in_array($status, self::OFFER_VERDICTS, true) && ($existing === null || $existing->status !== $status)) {
                $offer_hint = ' Press <em>Send offer</em> on their card when you want the committee to approve an offer.';
            }
            // 1.80.0: rejecting tells nobody; the card has the button (not for a player who never applied for this team: nothing to tell them).
            if ($status === 'rejected' && ($existing === null || $existing->status !== $status)) {
                $offer_hint = ' ' . esc_html(class_exists('TeamOversight_Not_Selected') && !TeamOversight_Not_Selected::applied_for($application, $team)
                    ? TeamOversight_Offers::wording('ns_not_applied', array('{team}' => TeamOversight_Not_Selected::team_name($team, $season, TeamOversight_Offers::teams_config($season))))
                    : TeamOversight_Offers::wording('ns_hint_rejected'));
            }
        }

        return '<div class="coach-portal-success"><p>' . $who . ' marked <strong>' . esc_html($verdict_labels[$status]) . '</strong> for ' . esc_html($team) . '.' . $gate_note . $offer_hint . '</p></div>';
    }

    /**
     * The offers gate on a verdict write (2027 on): '' when the change may go
     * ahead (any request still waiting for the committee has then been
     * cancelled; $note says so), else the refusal notice. $new_status is ''
     * for clear. Called just before the write, after every other refusal.
     */
    private function offer_verdict_gate($application_id, $team, $season, $new_status, $who, &$note) {
        $note = '';
        if (!class_exists('TeamOversight_Offers') || !TeamOversight_Offers::applies((string) $season)) {
            return '';
        }
        $gate = TeamOversight_Offers::verdict_gate($application_id, $team, $new_status, get_current_user_id());
        if (empty($gate['allowed'])) {
            $this->offer_refresh_ok = true;
            return '<div class="coach-portal-notice"><p>' . $who . ': ' . esc_html((string) $gate['message']) . '</p></div>';
        }
        if (!empty($gate['cancelled'])) {
            $note = ' ' . esc_html($gate['message']);
        }
        return '';
    }

    /**
     * Shown when a verdict or note post carries a stale nonce. $reloaded:
     * a form post, whose redirect has already brought a fresh page.
     * coach-portal-expired keeps the pop-up up until it's tapped away.
     */
    private static function expired_notice($reloaded = false) {
        if ($reloaded) {
            return '<div class="coach-portal-notice coach-portal-expired"><p>The page had expired, so that wasn\'t saved. It has been reloaded now — please re-enter anything you typed.</p></div>';
        }
        return '<div class="coach-portal-notice coach-portal-expired"><p>This page has expired — reload it to carry on. Anything you just typed may need re-entering.</p></div>';
    }

    /**
     * The verdict dropdown's options (after "None"). While the trial fee is
     * unpaid only Rejected can be picked; the others show as "(Disabled)"
     * and can't be chosen (handle_actions refuses them too). A verdict given
     * before this rule stays selected so the card shows the truth.
     *
     * $offer_locked (offers on): the verdict's offer is sent, accepted or
     * locked in, so every other choice is disabled (no "(Disabled)" label:
     * the card says to withdraw the offer first; the server gate refuses it
     * too). While an offer only awaits approval the menu works and a change
     * cancels the request.
     */
    private static function render_verdict_options($current, $payment_pending, $offer_locked = false) {
        $html = '';
        foreach (self::get_verdict_labels() as $key => $label) {
            $locked = $payment_pending && $key !== 'rejected' && $key !== $current;
            $held = $offer_locked && $key !== $current;
            $html .= '<option value="' . esc_attr($key) . '"' . selected($current, $key, false) . (($locked || $held) ? ' disabled' : '') . '>'
                . esc_html($label . ($locked ? ' (Disabled)' : '')) . '</option>';
        }
        return $html;
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
            if (isset($sel['chip'])) {
                // 2027 on: where the offer is up to ("Selected · offer sent").
                $label = $sel['chip'];
            }
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

        if (!$this->can_view_team($team, $season)) {
            return;
        }

        $filename = 'team_list_' . sanitize_file_name($team) . "_{$season}.csv";

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents/dashes correctly.
        fwrite($output, "\xEF\xBB\xBF");
        foreach ($this->roster_export_rows($team, $season) as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    /**
     * The team list CSV, header first: everyone with a place or a verdict
     * for the team, then everyone who applied for it with no verdict yet,
     * grouped by verdict (Confirmed, Selected, Training Only, Tentative,
     * Rejected, No verdict) so a coach can filter a group and copy its
     * emails. Team Position is the coach's choice; Applied Positions the
     * player's own answer.
     *
     * Names, positions and emails are member-controlled, so they go through
     * the shared TeamOversight_Exports::csv_cell() (a name like
     * =HYPERLINK(...) would otherwise be live when a coach opens the list).
     * Never the mobile column: international numbers start with +.
     */
    private function roster_export_rows($team, $season) {
        global $wpdb;
        $cell = array('TeamOversight_Exports', 'csv_cell');
        $rows = array(array('Name', 'Verdict', 'Status', 'Role', 'Team Position', 'Applied Positions', 'Email', 'Mobile'));
        $listed = array();
        $unpaid = function ($application_status) {
            return $application_status === 'awaiting_payment' ? ' · trial fee unpaid' : '';
        };

        $roster = $this->get_roster($team, $season);
        $position_map = $this->get_team_positions_for_team($team, $season);
        foreach ($roster as $member) {
            $rows[] = array(
                call_user_func($cell, $member->name ?: $member->email),
                'Confirmed',
                'Confirmed',
                str_replace('_', ' ', ucwords($member->role, '_')),
                self::team_position_label(self::position_for_person($position_map, isset($member->user_id) ? $member->user_id : 0, $member->email)),
                call_user_func($cell, $this->format_positions($member->preferred_positions)),
                call_user_func($cell, $member->email),
                $member->mobile ? self::format_phone($member->mobile) : '',
            );
            $listed[strtolower((string) $member->email)] = true;
        }

        $verdict_labels = self::get_verdict_labels();
        // Offers on: where each offer is up to, and the Declined group after Tentative.
        $offers_on = self::offers_on($season);
        $offer_index = $offers_on ? TeamOversight_Offers::index_for_team($team, $season) : array();
        $declined_rows = array();
        foreach ($this->get_selection_roster($team, $season, $roster) as $member) {
            $status_labels = array(
                'selected' => 'Selected (awaiting finalisation)',
                'training_only' => 'Training Only (awaiting finalisation)',
                'tentative' => 'Tentative',
            );
            $verdict_column = isset($verdict_labels[$member->status]) ? $verdict_labels[$member->status] : ucfirst($member->status);
            if ($offers_on) {
                // The status words come from TeamOversight_Offers::label() (no dollar figure).
                $member_offer = self::effective_offer(isset($offer_index[intval($member->application_id)]) ? $offer_index[intval($member->application_id)] : null, $member->status);
                $status_text = TeamOversight_Offers::label($member_offer, 'csv', array('verdict' => $member->status, 'season' => $season));
                if ($member_offer && in_array($member_offer->state, TeamOversight_Offers::DECLINED_STATES, true) && in_array($member->status, self::OFFER_VERDICTS, true)) {
                    $verdict_column = 'Declined';
                }
            } else {
                // A season before 2027: the 1.75.3 words.
                $status_text = isset($status_labels[$member->status]) ? $status_labels[$member->status] : ucfirst($member->status);
            }
            $row = array(
                call_user_func($cell, $member->name),
                $verdict_column,
                $status_text . $unpaid($member->application_status),
                $member->status === 'training_only' ? 'Training Only' : 'Playing Member',
                self::team_position_label($member->team_position),
                call_user_func($cell, $this->format_positions($member->preferred_positions)),
                call_user_func($cell, $member->email),
                $member->mobile ? self::format_phone($member->mobile) : '',
            );
            if ($verdict_column === 'Declined') {
                $declined_rows[] = $row;
            } else {
                $rows[] = $row;
            }
            $listed[strtolower((string) $member->email)] = true;
        }
        // Declined (the player said no, or was moved to Declined) sit after Tentative and before Rejected.
        foreach ($declined_rows as $row) {
            $rows[] = $row;
        }

        // Rejected for this team (by its coaches, or the application by the club).
        $rejected = $wpdb->get_results($wpdb->prepare("
            SELECT a.name, a.email, a.preferred_positions, a.application_status, um_mobile.meta_value AS mobile
            FROM {$wpdb->prefix}team_trial_selections s
            JOIN {$wpdb->prefix}trial_applications a ON a.id = s.application_id
            LEFT JOIN {$wpdb->usermeta} um_mobile ON a.user_id = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            WHERE s.team = %s AND s.season = %s AND s.status = 'rejected'
                AND a.application_status IN ('pending', 'accepted', 'awaiting_payment', 'rejected')
            ORDER BY a.trial_number
        ", $team, $season));
        foreach ($rejected as $member) {
            if (isset($listed[strtolower((string) $member->email)])) {
                continue;
            }
            $rows[] = array(
                call_user_func($cell, $member->name),
                'Rejected',
                'Rejected' . $unpaid($member->application_status),
                '',
                '',
                call_user_func($cell, $this->format_positions($member->preferred_positions)),
                call_user_func($cell, $member->email),
                $member->mobile ? self::format_phone($member->mobile) : '',
            );
            $listed[strtolower((string) $member->email)] = true;
        }

        // Applied for this team, no verdict for it yet.
        $applied = $wpdb->get_results($wpdb->prepare("
            SELECT a.name, a.email, a.preferred_positions, a.application_status, um_mobile.meta_value AS mobile
            FROM {$wpdb->prefix}trial_applications a
            LEFT JOIN {$wpdb->prefix}team_trial_selections s ON s.application_id = a.id AND s.team = %s
            LEFT JOIN {$wpdb->usermeta} um_mobile ON a.user_id = um_mobile.user_id AND um_mobile.meta_key = 'mobile_number'
            WHERE a.season = %s AND s.id IS NULL
                AND a.application_status IN ('pending', 'accepted', 'awaiting_payment')
                AND a.interested_teams LIKE %s
            ORDER BY a.trial_number
        ", $team, $season, '%' . $wpdb->esc_like('"' . $team . '"') . '%'));
        foreach ($applied as $member) {
            if (isset($listed[strtolower((string) $member->email)])) {
                continue;
            }
            $rows[] = array(
                call_user_func($cell, $member->name),
                'No verdict',
                'Applied, no verdict yet' . $unpaid($member->application_status),
                '',
                '',
                call_user_func($cell, $this->format_positions($member->preferred_positions)),
                call_user_func($cell, $member->email),
                $member->mobile ? self::format_phone($member->mobile) : '',
            );
            $listed[strtolower((string) $member->email)] = true;
        }

        return $rows;
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

        // Same authority as the portal itself: you coach this team, or
        // have committee access (view is enough — the book is read only).
        if (!$this->can_view_team($team, $season)) {
            return false;
        }

        return array('team' => $team, 'season' => $season);
    }

    private function render_applicant_book($team, $season) {
        $my_team_codes = array_keys($this->get_portal_teams($season));

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
                        $bits[] = $sel['team'] . ': ' . (isset($sel['plain']) ? $sel['plain'] : (isset($verdict_labels[$sel['status']]) ? $verdict_labels[$sel['status']] : $sel['status']));
                    }
                    echo esc_html($bits ? implode(', ', $bits) : '—');
                ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p class="meta">★ marks <?php echo esc_html($config['name']); ?> in the "applied for" column.</p>

<h2>Applicant details</h2>
<p class="meta">ICE = emergency contact. Verdicts show every team's call, not just yours.<?php if (self::offers_on($season)): ?> Selected and Training Only are offers: the status after each one shows where it is up to, from "offer not sent" to "locked in" (the player accepts on the website and pays the deposit to lock in).<?php endif; ?></p>
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
                    $bits[] = $sel['team'] . ' ' . (isset($sel['plain']) ? $sel['plain'] : (isset($verdict_labels[$sel['status']]) ? $verdict_labels[$sel['status']] : $sel['status']));
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
                foreach (self::coach_visible_form_data($a['form_data']) as $question => $answer) {
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
            'reg_type' => TeamOversight_Trials::registration_type($app),
            'transfer_club' => isset($form_data['Transfer: Previous Club']) ? $form_data['Transfer: Previous Club'] : '',
        );
    }

    /**
     * Application answers as coaches see them. A club waiver stores the
     * admin's free-text reason, who and when in 'Trial Fee' (the prefix
     * is what keeps it through player edits); coaches only need to know
     * it was waived. The stored answers and admin views are unchanged.
     */
    private static function coach_visible_form_data($form_data) {
        if (is_array($form_data) && isset($form_data['Trial Fee']) && is_string($form_data['Trial Fee'])
            && strpos($form_data['Trial Fee'], TeamOversight_Trials::CLUB_WAIVER_PREFIX) === 0) {
            $form_data['Trial Fee'] = 'Waived by club';
        }
        return $form_data;
    }

    /**
     * The one expander row every card shares: emergency contact,
     * application details, and coach notes with the add-note form.
     * Application/Notes only render when an application exists.
     * $trial_tools false (a confirmed player's card, the team list) leaves
     * out Notes and Attendance.
     */
    private function render_card_expanders($email, $application_id, $form_data, $notes, $active_team, $season, $user_id = 0, $trial_tools = true, $applied_positions = '') {
        ob_start();
        // By account: the email here is a snapshot from the application or
        // assignment, so after an email change it would find nobody, or
        // whoever holds that address now. Rows with no user_id fall back to it.
        echo self::render_emergency_details($email, $user_id);

        // Coaches see that a fee was waived, not the admin's reason.
        $form_data = self::coach_visible_form_data($form_data);

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
                    <?php if ($applied_positions !== ''): ?>
                        <dt>Positions applied for</dt>
                        <dd><?php echo esc_html($applied_positions); ?></dd>
                    <?php endif; ?>
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

        if ($trial_tools && $application_id) {
            ?>
            <details class="coach-app-details">
                <summary>Notes (<?php echo count($notes); ?>)</summary>
                <?php foreach ($notes as $note): ?>
                    <?php $is_mine = !$this->read_only && !empty($note['id']) && $note['author_id'] === get_current_user_id(); ?>
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
                                        <button type="button" class="button button-small coach-btn-secondary" onclick="this.closest('details').open = false;">Cancel</button>
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
                <?php if ($this->read_only): ?>
                    <?php if (empty($notes)): ?><p class="coach-attendance-empty">No notes yet.</p><?php endif; ?>
                <?php else: ?>
                <form method="post" class="coach-note-form">
                    <input type="hidden" name="coach_action" value="add_note">
                    <input type="hidden" name="application_id" value="<?php echo intval($application_id); ?>">
                    <input type="hidden" name="coach_team" value="<?php echo esc_attr($active_team); ?>">
                    <input type="hidden" name="coach_season" value="<?php echo esc_attr($season); ?>">
                    <?php wp_nonce_field('coach_portal_action', 'coach_nonce'); ?>
                    <textarea name="coach_note" rows="2" placeholder="Add a note visible to all coaches..." required></textarea>
                    <button type="submit" class="button button-small">Add Note</button>
                </form>
                <?php endif; ?>
            </details>
            <?php
        }

        // Attendance history across every team, visible to every coach.
        if ($trial_tools) {
            $attendance = $this->get_person_attendance($season, $user_id, $email);
            ?>
            <details class="coach-app-details coach-attendance">
                <summary>Attendance (<span class="coach-attendance-count"><?php echo count($attendance); ?></span>)</summary>
                <div class="coach-attendance-history"><?php echo $this->render_attendance_list($attendance); ?></div>
            </details>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * A confirmed player's fee standing for the team list: percentage
     * paid and how far behind the schedule, never an amount (fees differ
     * by class, and some are set by hand). More than a month behind is
     * flagged: they shouldn't train until it's sorted with the club.
     * $standing: one TeamOversight_Payments::get_fee_standings() entry.
     * Since 1.70.2 the pill (and its 2027+ tooltip, measured against each
     * player's own window) lives in TeamOversight_Payments::
     * render_standing_pill(), shared with Member Lookup's Fee timeline.
     */
    public static function render_fee_standing($standing, $season) {
        return TeamOversight_Payments::render_standing_pill($standing, $season);
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
        $offers = array();
        $application_ids = array_values(array_filter(array_map('intval', $application_ids)));
        if ($application_ids) {
            $ids = implode(',', $application_ids);
            $rows = $wpdb->get_results($wpdb->prepare("
                SELECT application_id, team, status, offer_accepted FROM {$wpdb->prefix}team_trial_selections
                WHERE application_id IN ({$ids}) AND team <> %s
            ", $active_team));
            $offers_on = self::offers_on($season);
            $offer_index = $offers_on ? TeamOversight_Offers::index_for_applications($application_ids) : array();
            foreach ($rows as $row) {
                $verdicts[intval($row->application_id)][$row->team] = $row->status;
                if ($offers_on) {
                    // 2027 on: the other team's chip text, e.g. "Selected · offer sent".
                    $other = self::offer_wording(isset($offer_index[intval($row->application_id)][$row->team]) ? $offer_index[intval($row->application_id)][$row->team] : null, $row->status, $season);
                    $offers[intval($row->application_id)][$row->team] = array('chip' => $other['chip']);
                }
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

        return array($verdicts, $placements, $offers);
    }

    /**
     * Chips for one card: other teams' verdicts, with a confirmed place on
     * a team replacing the verdict that led to it.
     */
    private function render_other_team_chips($claims, $application_id, $user_id, $email, $teams_config) {
        list($verdicts, $placements, $offers) = $claims;
        $teams = isset($verdicts[intval($application_id)]) ? $verdicts[intval($application_id)] : array();
        $offer_states = isset($offers[intval($application_id)]) ? $offers[intval($application_id)] : array();

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
            if (strpos($status, 'confirmed') !== 0 && isset($offer_states[$team]['chip'])) {
                // 2027 on: "Selected · offer sent", "Training Only · accepted", ...
                $label = $offer_states[$team]['chip'];
            }
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
            $title = 'Free agent — no club transfer required: skipped at least one VVL season, or last played Youth State League' . ($club ? ' (previously ' . $club . ')' : '') . '.';
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
     * them at trainings. Looked up by account; the email is only used when
     * there's no user id and $email_fallback allows it (legacy rows).
     */
    public static function render_emergency_details($email, $user_id = 0, $email_fallback = true) {
        $user_id = intval($user_id);
        if (!$user_id && $email_fallback && $email) {
            $user = get_user_by('email', $email);
            $user_id = $user ? $user->ID : 0;
        }
        $contacts = $user_id ? self::get_emergency_contacts($user_id) : array();

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
            SELECT s.status, s.offer_accepted, s.team_position, s.application_id, a.name, a.email, a.trial_number, a.user_id, a.preferred_positions,
                a.is_transfer_player, a.form_data, a.application_status, a.registration_override,
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
            $row->reg_type = TeamOversight_Trials::registration_type($row);
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

        // Unpaid applicants are included (flagged Payment pending, sorted
        // last) however long they've been unpaid, so late applicants can be
        // trialled; positive verdicts wait for payment (handle_actions).
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

        // Offers on: where each team's offer is up to, for the chips and the trial book.
        $offers_on = self::offers_on($season);
        $offer_index = $offers_on ? TeamOversight_Offers::index_for_applications(array_map('intval', $app_ids)) : array();

        $selections_by_app = array();
        foreach ($selection_rows as $sel) {
            $selection = array(
                'team' => $sel->team,
                'team_name' => isset($teams_config[$sel->team]) ? $teams_config[$sel->team]['name'] : $sel->team,
                'status' => $sel->status,
                'position' => (string) $sel->team_position,
            );
            if ($offers_on) {
                $selection = array_merge($selection, self::offer_wording(isset($offer_index[intval($sel->application_id)][$sel->team]) ? $offer_index[intval($sel->application_id)][$sel->team] : null, $sel->status, $season));
            }
            $selections_by_app[$sel->application_id][] = $selection;
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
            $my_position = '';
            $my_offer = null;
            foreach ($selections as $sel) {
                if ($sel['team'] === $active_team) {
                    $my_status = $sel['status'];
                    $my_position = $sel['position'];
                    $my_offer = isset($sel['offer']) ? $sel['offer'] : null;
                }
            }

            $applicants[] = array(
                'id' => intval($row->id),
                'user_id' => intval($row->user_id),
                'application_status' => (string) $row->application_status,
                'payment_pending' => $row->application_status === 'awaiting_payment',
                'trial_number' => intval($row->trial_number),
                'is_transfer' => intval($row->is_transfer_player) === 1,
                'transfer_club' => isset($form_data['Transfer: Previous Club']) ? $form_data['Transfer: Previous Club'] : '',
                'reg_type' => TeamOversight_Trials::registration_type($row),
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
                'my_offer' => $my_offer,
                'my_position' => $my_position,
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
