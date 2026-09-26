<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transactional emails to trial applicants — one email per thing the
 * applicant did or paid for: application received, payment needed, payment
 * received, changes saved. Each can be switched off or reworded on
 * VVL Oversight → Emails, and they share the From / Reply-To set there.
 *
 * Deliberately NOT here: selection outcomes (accepted, rejected, team
 * offers). Those are the club's to communicate personally, so nothing in
 * the plugin emails them.
 */
class TeamOversight_Trial_Emails {

    const OPTION = 'team_oversight_trial_emails';

    /**
     * Trial emails have their own Reply-To, separate from the fee
     * reminders': applicants' questions belong with whoever runs trials,
     * not the treasurer. The From address stays shared (it must be one the
     * server is allowed to send as).
     */
    const REPLYTO_OPTION = 'team_oversight_trial_email_replyto';
    const DEFAULT_REPLYTO = 'vvldelegate@renegades.com.au';

    /**
     * Where applicant replies go. Never saved -> the VVL delegate. Saved
     * blank -> '' , meaning "use the fee reminders' Reply-To" (see
     * get_headers()), so the admin can opt back into one shared inbox.
     */
    public static function get_reply_to() {
        $saved = get_option(self::REPLYTO_OPTION, null);
        if ($saved === null) {
            return self::DEFAULT_REPLYTO;
        }
        return sanitize_email((string) $saved);
    }

    /** The shared From, with the trial-specific Reply-To in place. */
    public static function get_headers() {
        $headers = array();
        foreach (TeamOversight_Payments::get_email_headers() as $header) {
            if (stripos($header, 'Reply-To:') !== 0) {
                $headers[] = $header;
            }
        }

        $reply_to = self::get_reply_to();
        if ($reply_to === '') {
            $reply_to = sanitize_email((string) get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION));
        }
        if ($reply_to !== '') {
            $headers[] = 'Reply-To: ' . $reply_to;
        }
        return $headers;
    }

    /**
     * The emails, with their default wording. Placeholders:
     * {first_name} {name} {season} {trial_number} {teams} {positions}
     * {fee} {link}.
     */
    public static function get_events() {
        $sign_off = "Melbourne University Renegades Volleyball Club";

        return array(
            'submitted' => array(
                'label' => 'Application received',
                'when' => 'An application is submitted and needs no payment — or a previously unpaid one becomes confirmed.',
                'subject' => 'Your {season} MURVC trial application — #{trial_number}',
                'body' => "Hi {first_name},\n\n"
                    . "Thanks for applying to trial with MURVC for the {season} season.\n\n"
                    . "  Trial number: #{trial_number}\n"
                    . "  Teams:        {teams}\n"
                    . "  Positions:    {positions}\n\n"
                    . "Coaches may ask for your trial number at trials — plenty of players write it on their hand or arm on the day.\n\n"
                    . "Need to change something? Your application stays editable until applications close:\n"
                    . "{link}\n\n"
                    . $sign_off,
            ),
            'payment_needed' => array(
                'label' => 'Payment needed',
                'when' => 'An application is saved but the trial fee is due — on first submission, or when an edit makes the fee apply.',
                'subject' => 'Action needed: pay your {season} MURVC trial fee',
                'body' => "Hi {first_name},\n\n"
                    . "Your {season} MURVC trial application has been saved, but it won't be confirmed until the trial fee ({fee}) is paid. Unpaid applications are removed after 7 days.\n\n"
                    . "  Trial number: #{trial_number}\n"
                    . "  Teams:        {teams}\n\n"
                    . "You can pay from the trial page — look for the \"Pay the trial fee\" button:\n"
                    . "{link}\n\n"
                    . "Already paid, or think this is a mistake? Just reply to this email.\n\n"
                    . $sign_off,
            ),
            'paid' => array(
                'label' => 'Payment received',
                'when' => 'The trial fee is paid online, or an admin uses Mark as Paid.',
                'subject' => 'Payment received — {season} MURVC trial #{trial_number}',
                'body' => "Hi {first_name},\n\n"
                    . "Thanks — we've received your trial fee, and your {season} application is confirmed.\n\n"
                    . "  Trial number: #{trial_number}\n"
                    . "  Teams:        {teams}\n"
                    . "  Positions:    {positions}\n\n"
                    . "Coaches may ask for your trial number at trials — plenty of players write it on their hand or arm on the day.\n\n"
                    . $sign_off,
            ),
            'edited' => array(
                'label' => 'Changes saved',
                'when' => 'A confirmed application is edited and saved (no new payment needed).',
                'subject' => 'Your {season} MURVC trial application has been updated',
                'body' => "Hi {first_name},\n\n"
                    . "Your changes to your {season} MURVC trial application have been saved.\n\n"
                    . "  Trial number: #{trial_number} (unchanged)\n"
                    . "  Teams:        {teams}\n"
                    . "  Positions:    {positions}\n\n"
                    . "You can keep making changes until applications close:\n"
                    . "{link}\n\n"
                    . "If you didn't make this change, please reply to this email.\n\n"
                    . $sign_off,
            ),
        );
    }

    /**
     * Saved settings merged over the defaults: every email on unless
     * switched off; an empty subject or body means "use the default", so
     * improvements to the built-in wording reach anyone who never
     * customised it.
     */
    public static function get_settings() {
        $saved = get_option(self::OPTION, array());
        $saved = is_array($saved) ? $saved : array();

        $settings = array();
        foreach (self::get_events() as $key => $event) {
            $mine = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();
            $subject = isset($mine['subject']) ? trim((string) $mine['subject']) : '';
            $body = isset($mine['body']) ? trim((string) $mine['body']) : '';
            $settings[$key] = array(
                'enabled' => isset($mine['enabled']) ? !empty($mine['enabled']) : true,
                'subject' => $subject !== '' ? $subject : $event['subject'],
                'body' => $body !== '' ? $body : $event['body'],
                'customised' => ($subject !== '' || $body !== ''),
            );
        }
        return $settings;
    }

    /** Placeholder values for one application. */
    public static function get_vars($application) {
        $user = !empty($application->user_id) ? get_userdata($application->user_id) : false;
        $name = $user ? $user->display_name : (string) $application->name;
        $first = $user ? trim((string) get_user_meta($user->ID, 'first_name', true)) : '';
        if ($first === '') {
            $first = trim((string) strtok($name, ' '));
        }

        $database = new TeamOversight_Database();
        $config = $database->get_teams_config($application->season);
        $team_names = array();
        foreach ((json_decode((string) $application->interested_teams, true) ?: array()) as $code) {
            $team_names[] = isset($config[$code]) ? $config[$code]['name'] : $code;
        }

        $position_options = TeamOversight_Trials::get_position_options();
        $positions = array();
        foreach ((json_decode((string) $application->preferred_positions, true) ?: array()) as $key) {
            $positions[] = isset($position_options[$key]) ? $position_options[$key] : $key;
        }

        return array(
            '{name}' => $name,
            '{first_name}' => $first !== '' ? $first : $name,
            '{season}' => (string) $application->season,
            '{trial_number}' => (string) intval($application->trial_number),
            '{teams}' => $team_names ? implode(', ', $team_names) : '—',
            '{positions}' => $positions ? implode(', ', $positions) : '—',
            '{fee}' => self::get_fee_text(),
            '{link}' => self::get_form_url(),
        );
    }

    /** Illustrative values for previews and test sends. */
    public static function get_sample_vars() {
        return array(
            '{name}' => 'Alex Example',
            '{first_name}' => 'Alex',
            '{season}' => wp_date('Y'),
            '{trial_number}' => '42',
            '{teams}' => 'State League 2 Men, State League 3 Men Red',
            '{positions}' => 'Middle, Setter',
            '{fee}' => self::get_fee_text() !== '' ? self::get_fee_text() : '$55.00',
            '{link}' => self::get_form_url(),
        );
    }

    /** Returns array(subject, body) for an event with the given values. */
    public static function render($event, $vars) {
        $settings = self::get_settings();
        if (!isset($settings[$event])) {
            return array('', '');
        }
        return array(
            strtr($settings[$event]['subject'], $vars),
            strtr($settings[$event]['body'], $vars),
        );
    }

    /**
     * Send one email for an application, if that email is switched on.
     * Never throws or blocks: a mail failure must not fail the submission
     * or payment it describes. Logged either way (VVL Oversight → Logs).
     */
    public static function send($event, $application) {
        $events = self::get_events();
        $settings = self::get_settings();
        if (!$application || !isset($events[$event]) || empty($settings[$event]['enabled'])) {
            return false;
        }

        $user = !empty($application->user_id) ? get_userdata($application->user_id) : false;
        $to = $user ? $user->user_email : (string) $application->email;
        if (!is_email($to)) {
            return false;
        }

        $vars = self::get_vars($application);
        list($subject, $body) = self::render($event, $vars);
        $sent = wp_mail($to, $subject, $body, self::get_headers());

        TeamOversight_Log::add(
            'email_trial',
            $events[$event]['label'] . ' email to ' . $vars['{name}'] . ' (trial #' . $vars['{trial_number}'] . ')' . ($sent ? '' : ' — NOT delivered, check the site\'s mail setup'),
            array('user_id' => !empty($application->user_id) ? intval($application->user_id) : null)
        );
        return $sent;
    }

    /** Fetch the application fresh and send — for callers holding only an id. */
    public static function send_for_id($event, $application_id) {
        global $wpdb;
        $application = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}trial_applications WHERE id = %d",
            intval($application_id)
        ));
        return $application ? self::send($event, $application) : false;
    }

    /** The trial fee as display text, or '' when no fee product is set. */
    private static function get_fee_text() {
        $product = (new TeamOversight_Trials())->get_trial_fee_product();
        if (!$product || $product->get_price() === '') {
            return '';
        }
        return html_entity_decode(wp_strip_all_tags(wc_price($product->get_price())), ENT_QUOTES, 'UTF-8');
    }

    /**
     * The page carrying the trial form, found by its shortcode — so links
     * in emails keep working if the page moves, with no setting to keep in
     * sync. Links go to the page itself (never a nonced action link): a
     * nonce would expire within a day, while the page always shows the
     * applicant their current status and, if unpaid, the Pay button.
     */
    public static function get_form_url() {
        global $wpdb;
        $page_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('page', 'post')
                AND post_content LIKE '%[team_trial_form%'
            ORDER BY (post_type = 'page') DESC, ID
            LIMIT 1
        ");
        return $page_id ? get_permalink($page_id) : home_url('/');
    }

    // ------------------------------------------------------------------
    // Admin (VVL Oversight → Emails)
    // ------------------------------------------------------------------

    public static function render_admin_section() {
        $events = self::get_events();
        $settings = self::get_settings();
        $sample = self::get_sample_vars();
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-top: 20px;">
            <h2 style="margin-top: 0;">Trial application emails</h2>
            <p class="description" style="max-width: 900px;">
                One email per thing the applicant did — never more — sent from the From address above, with their own Reply-To below.
                <strong>Selection outcomes (accepted, not selected, team offers) are never emailed from here</strong>; the club communicates those personally.
                Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{season}</code> <code>{trial_number}</code>
                <code>{teams}</code> <code>{positions}</code> <code>{fee}</code> <code>{link}</code>
                (the trial page: <a href="<?php echo esc_url(self::get_form_url()); ?>" target="_blank"><?php echo esc_html(self::get_form_url()); ?></a>).
                Clear a subject or body and save to go back to the built-in wording.
            </p>

            <form method="post">
                <p>
                    <label><strong>Reply-To for trial emails</strong><br>
                        <input type="email" name="trial_email_replyto" value="<?php echo esc_attr(get_option(self::REPLYTO_OPTION, self::DEFAULT_REPLYTO)); ?>" style="width: 320px;">
                    </label>
                    <span class="description" style="display: block; margin-top: 4px;">Where applicants' replies land — separate from the fee reminders' Reply-To, so trial questions reach whoever runs trials. Leave blank to use the reminders' Reply-To instead.</span>
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 15px;">
                    <?php foreach ($events as $key => $event): $s = $settings[$key]; list($p_subject, $p_body) = self::render($key, $sample); ?>
                        <div style="border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 14px; <?php echo $s['enabled'] ? '' : 'background: #f6f7f7;'; ?>">
                            <label style="font-size: 14px;">
                                <input type="checkbox" name="trial_emails[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked($s['enabled']); ?>>
                                <strong><?php echo esc_html($event['label']); ?></strong>
                                <?php if ($s['customised']): ?><span style="color: #996800; font-size: 12px;"> · customised</span><?php endif; ?>
                            </label>
                            <p class="description" style="margin: 4px 0 8px;"><?php echo esc_html($event['when']); ?></p>
                            <p style="margin: 0 0 6px;">
                                <input type="text" name="trial_emails[<?php echo esc_attr($key); ?>][subject]" value="<?php echo esc_attr($s['subject']); ?>" style="width: 100%;">
                            </p>
                            <textarea name="trial_emails[<?php echo esc_attr($key); ?>][body]" rows="10" style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($s['body']); ?></textarea>
                            <details style="margin-top: 6px;">
                                <summary style="cursor: pointer;">Preview</summary>
                                <p style="background: #f0f0f1; padding: 6px 10px; border-radius: 4px; margin: 6px 0;"><strong>Subject:</strong> <?php echo esc_html($p_subject); ?></p>
                                <pre style="background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; padding: 10px; white-space: pre-wrap; font-size: 12px; margin: 0;"><?php echo esc_html($p_body); ?></pre>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <input type="submit" class="button button-primary" value="Save Trial Emails">
                    <input type="hidden" name="action" value="save_trial_email_settings">
                    <?php wp_nonce_field('trial_email_settings', 'trial_email_settings_nonce'); ?>
                </p>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="send_test_trial_emails">
                <?php wp_nonce_field('trial_email_settings', 'trial_email_settings_nonce'); ?>
                <input type="submit" class="button" value="Send test of each switched-on email to me">
            </form>
        </div>
        <?php
    }

    public static function save_admin_settings() {
        if (!isset($_POST['trial_email_settings_nonce']) || !wp_verify_nonce($_POST['trial_email_settings_nonce'], 'trial_email_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        $posted = isset($_POST['trial_emails']) && is_array($_POST['trial_emails']) ? wp_unslash($_POST['trial_emails']) : array();
        $saved = array();
        foreach (self::get_events() as $key => $event) {
            $mine = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();
            $subject = isset($mine['subject']) ? sanitize_text_field($mine['subject']) : '';
            $body = isset($mine['body']) ? sanitize_textarea_field($mine['body']) : '';
            // Wording left identical to the default is stored as "default",
            // so later improvements to the built-in text still reach it.
            $saved[$key] = array(
                'enabled' => !empty($mine['enabled']) ? 1 : 0,
                'subject' => (trim($subject) === $event['subject']) ? '' : $subject,
                'body' => (self::normalise($body) === self::normalise($event['body'])) ? '' : $body,
            );
        }
        update_option(self::OPTION, $saved);

        $reply_raw = isset($_POST['trial_email_replyto']) ? trim(wp_unslash($_POST['trial_email_replyto'])) : '';
        $reply_to = sanitize_email($reply_raw);
        update_option(self::REPLYTO_OPTION, $reply_to);
        if ($reply_raw !== '' && $reply_to === '') {
            echo '<div class="notice notice-warning"><p>"' . esc_html($reply_raw) . '" isn\'t a valid email address, so trial emails will use the fee reminders\' Reply-To until it\'s fixed.</p></div>';
        }

        echo '<div class="notice notice-success"><p>Trial email settings saved.</p></div>';
    }

    /** Line endings differ between a textarea round-trip and PHP source. */
    private static function normalise($text) {
        return trim(str_replace("\r\n", "\n", (string) $text));
    }

    public static function send_test_emails() {
        if (!isset($_POST['trial_email_settings_nonce']) || !wp_verify_nonce($_POST['trial_email_settings_nonce'], 'trial_email_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }

        $admin = wp_get_current_user();
        $sample = self::get_sample_vars();
        $sent = 0;
        $failed = 0;
        foreach (self::get_settings() as $key => $s) {
            if (!$s['enabled']) {
                continue;
            }
            list($subject, $body) = self::render($key, $sample);
            if (wp_mail($admin->user_email, '[TEST] ' . $subject, $body, self::get_headers())) {
                $sent++;
            } else {
                $failed++;
            }
        }

        if ($failed) {
            echo '<div class="notice notice-error"><p>' . intval($failed) . ' test email(s) failed to send — check the site\'s email configuration.</p></div>';
        } elseif ($sent) {
            echo '<div class="notice notice-success"><p>' . intval($sent) . ' test email(s) sent to ' . esc_html($admin->user_email) . '.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>Every trial email is switched off, so there was nothing to send.</p></div>';
        }
    }
}
