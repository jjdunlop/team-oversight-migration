<?php
/**
 * Committee access to the coach portal: lets club officials see (or run)
 * every team, not just the ones they coach.
 *
 * Levels:
 *  - 'edit' — every team in the season, with the same powers a coach has
 *    there (verdicts, attendance, notes). Admins (manage_options) always
 *    have this; it can also be granted to non-admins.
 *  - 'view' — every team, read only: no verdicts, attendance or notes.
 *    A coach with view access still edits the teams they coach.
 *
 * Grants live in one option (user id => level). VVL Oversight →
 * Committee Access manages them; every change is logged.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Committee_Access {

    const OPTION = 'team_oversight_committee_access';

    public static function get_levels() {
        return array(
            'view' => 'View all teams',
            'edit' => 'Edit all teams',
        );
    }

    /** Granted (non-admin) access: user id => 'view' | 'edit'. */
    public static function get_grants() {
        $grants = get_option(self::OPTION, array());
        $clean = array();
        if (is_array($grants)) {
            foreach ($grants as $user_id => $level) {
                if (intval($user_id) > 0 && isset(self::get_levels()[$level])) {
                    $clean[intval($user_id)] = $level;
                }
            }
        }
        return $clean;
    }

    /** A user's committee level: 'edit', 'view' or '' (none). */
    public static function get_level($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return '';
        }
        if (user_can($user_id, 'manage_options')) {
            return 'edit';
        }
        $grants = self::get_grants();
        return isset($grants[$user_id]) ? $grants[$user_id] : '';
    }

    // ------------------------------------------------------------------
    // Admin page
    // ------------------------------------------------------------------

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = self::handle_admin_post();
        $grants = self::get_grants();
        $levels = self::get_levels();
        $admins = get_users(array('role' => 'administrator', 'orderby' => 'display_name', 'fields' => array('ID', 'display_name', 'user_email')));
        ?>
        <div class="wrap">
            <h1>Committee Access</h1>
            <p>Gives club officials access to <strong>every team</strong> in the coach portal, not just the teams they coach.
                <strong>Edit all teams</strong> gives the same powers a coach has (verdicts, attendance, notes) on every team.
                <strong>View all teams</strong> is read only. Coaches always keep full access to the teams they coach.</p>
            <?php echo $notice; ?>

            <h2>Give access</h2>
            <form method="post">
                <?php wp_nonce_field('committee_access', 'committee_nonce'); ?>
                <input type="hidden" name="committee_action" value="grant">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="committee-user">Member</label></th>
                        <td><input type="text" id="committee-user" name="committee_user" class="regular-text" placeholder="Email or username" required>
                            <p class="description">Their account email or username. Use Member Lookup if you're not sure.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="committee-level">Access</label></th>
                        <td><select id="committee-level" name="committee_level">
                            <?php foreach ($levels as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">Give access</button></p>
            </form>

            <h2>Who has access</h2>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th>Name</th><th>Email</th><th>Access</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <tr>
                            <td><?php echo esc_html($admin->display_name); ?></td>
                            <td><?php echo esc_html($admin->user_email); ?></td>
                            <td>Edit all teams <span class="description">(site admin — automatic)</span></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($grants as $user_id => $level): ?>
                        <?php $user = get_userdata($user_id); if (!$user || user_can($user, 'manage_options')) { continue; } ?>
                        <tr>
                            <td><?php echo esc_html($user->display_name); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td>
                                <form method="post" style="display:inline-flex; gap:6px; align-items:center;">
                                    <?php wp_nonce_field('committee_access', 'committee_nonce'); ?>
                                    <input type="hidden" name="committee_action" value="change">
                                    <input type="hidden" name="committee_user_id" value="<?php echo intval($user_id); ?>">
                                    <select name="committee_level">
                                        <?php foreach ($levels as $key => $label): ?>
                                            <option value="<?php echo esc_attr($key); ?>" <?php selected($level, $key); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button">Save</button>
                                </form>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Remove committee access for this member?');">
                                    <?php wp_nonce_field('committee_access', 'committee_nonce'); ?>
                                    <input type="hidden" name="committee_action" value="remove">
                                    <input type="hidden" name="committee_user_id" value="<?php echo intval($user_id); ?>">
                                    <button type="submit" class="button-link-delete" style="background:none;border:0;cursor:pointer;">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($grants)): ?>
                <p class="description">No one else has committee access yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Process a grant / change / remove. Returns an admin notice ('' if none). */
    public static function handle_admin_post() {
        if (!isset($_POST['committee_action']) || !current_user_can('manage_options')) {
            return '';
        }
        if (!isset($_POST['committee_nonce']) || !wp_verify_nonce($_POST['committee_nonce'], 'committee_access')) {
            return '<div class="notice notice-error"><p>Security check failed — please try again.</p></div>';
        }

        $action = sanitize_key($_POST['committee_action']);
        $levels = self::get_levels();
        $grants = self::get_grants();

        if ($action === 'grant') {
            $who = isset($_POST['committee_user']) ? sanitize_text_field(wp_unslash($_POST['committee_user'])) : '';
            $user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
            if (!$user) {
                return '<div class="notice notice-error"><p>No account found for <strong>' . esc_html($who) . '</strong>.</p></div>';
            }
            $user_id = $user->ID;
        } else {
            $user_id = isset($_POST['committee_user_id']) ? intval($_POST['committee_user_id']) : 0;
            $user = get_userdata($user_id);
            if (!$user) {
                return '<div class="notice notice-error"><p>That account no longer exists.</p></div>';
            }
        }

        if (user_can($user, 'manage_options')) {
            return '<div class="notice notice-info"><p>' . esc_html($user->display_name) . ' is a site admin, so they already edit all teams.</p></div>';
        }

        if ($action === 'remove') {
            unset($grants[$user_id]);
            update_option(self::OPTION, $grants, false);
            TeamOversight_Log::add('committee_access', 'Committee access removed for ' . $user->display_name, array('user_id' => $user_id));
            return '<div class="notice notice-success"><p>Removed committee access for ' . esc_html($user->display_name) . '.</p></div>';
        }

        $level = isset($_POST['committee_level']) ? sanitize_key($_POST['committee_level']) : '';
        if (!isset($levels[$level])) {
            return '<div class="notice notice-error"><p>Pick an access level.</p></div>';
        }
        $grants[$user_id] = $level;
        update_option(self::OPTION, $grants, false);
        TeamOversight_Log::add('committee_access', 'Committee access for ' . $user->display_name . ': ' . $levels[$level], array('user_id' => $user_id));
        return '<div class="notice notice-success"><p>' . esc_html($user->display_name) . ' now has <strong>' . esc_html($levels[$level]) . '</strong>.</p></div>';
    }
}
