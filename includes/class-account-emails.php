<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ultimate Member's account emails in the club's email look (1.73.0):
 * welcome, activate your account, approved / rejected / under review,
 * deactivated, deleted, reset your password, password changed, account
 * updated. Ultimate Member still decides when each one goes and whether
 * it's switched on (Ultimate Member → Settings → Email), and supplies the
 * member and the links; this replaces the email it built with the club
 * layout, in wording edited on VVL Oversight → Emails.
 *
 * It works on um_email_send_message_content, the last step before Ultimate
 * Member sends, and only when Ultimate Member sends HTML email. Admin
 * emails (new user, suspicious activity) are left as they are.
 */
class TeamOversight_Account_Emails {

    /** Each email's subject, heading and body (blank = the built-in text). */
    const TEXT_OPTION = 'team_oversight_account_email_texts';

    private static $hooks_registered = false;

    /** The email being sent now: its id and Reply-To, for the wp_mail filter. */
    private static $sending = null;
    private static $detach = null;

    public static function register_hooks() {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;
        add_filter('um_email_send_subject', array(__CLASS__, 'filter_subject'), 20, 2);
        add_filter('um_email_send_message_content', array(__CLASS__, 'filter_message'), 20, 3);
        add_filter('wp_mail', array(__CLASS__, 'filter_wp_mail'), 20);
        add_action('um_after_email_notification_sending', array(__CLASS__, 'after_send'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'um_settings_notice'));
    }

    /**
     * The emails, with their default wording (TeamOversight_Email's
     * format). 'link' is the Ultimate Member value the button goes to.
     * Placeholders: {first_name} {name} {email} {username} {link}, and in
     * Approved {reset_link}.
     */
    public static function get_events() {
        $signed_up = 'You\'re getting this because you signed up for a Renegades account.';
        $yours = 'You\'re getting this because it\'s about your Renegades account.';
        $not_you = "> If this wasn't you, reply to this email straight away so we can secure your account.";
        return array(
            'welcome_email' => array(
                'label' => 'Welcome',
                'when' => 'Someone signs up and their account is active straight away.',
                'subject' => 'Welcome to the Renegades',
                'heading' => 'Welcome to the club',
                'preheader' => 'Your Renegades account is ready.',
                'button' => 'Log in',
                // Ultimate Member's own choice: the log-in page, or its
                // set-password link for an account made without a password
                // (the button then says so).
                'link' => '{action_url}',
                'link_fallback' => '{login_url}',
                'button_when_reset' => 'Set your password',
                'why' => $signed_up,
                'body' => "Hi {first_name},\n\nThanks for signing up. Your Renegades account is ready to use.\n\n{button}\n\nLog in any time to update your details, sign up for programs and pay club fees.",
            ),
            'checkmail_email' => array(
                'label' => 'Activate your account',
                'when' => 'Someone signs up and needs to confirm their email address first.',
                'subject' => 'Please activate your Renegades account',
                'heading' => 'Activate your account',
                'preheader' => 'Confirm your email address to finish signing up.',
                'button' => 'Activate my account',
                'link' => '{account_activation_link}',
                'why' => $signed_up,
                'body' => "Hi {first_name},\n\nThanks for signing up. Please confirm your email address to activate your Renegades account.\n\n{button}\n\nIf you didn't sign up, you can ignore this email.",
            ),
            'pending_email' => array(
                'label' => 'Under review',
                'when' => 'Someone signs up and their account has to be approved by hand.',
                'subject' => 'Your Renegades account is being checked',
                'heading' => 'We\'re checking your account',
                'preheader' => 'We\'ll email you once your account is approved.',
                'button' => '',
                'link' => '',
                'why' => $signed_up,
                'body' => "Hi {first_name},\n\nThanks for signing up. We check new accounts by hand, and we'll email you as soon as yours is approved.",
            ),
            'approved_email' => array(
                'label' => 'Approved',
                'when' => 'An account waiting for approval is approved.',
                'subject' => 'Your Renegades account is ready',
                'heading' => 'Your account is ready',
                'preheader' => 'Your account has been approved. You can log in now.',
                'button' => 'Log in',
                'link' => '{login_url}',
                'why' => $signed_up,
                'body' => "Hi {first_name},\n\nYour Renegades account has been approved, so you can log in now.\n\n{button}\n\nYour username is {username}. Haven't set a password yet? Set one here: {reset_link}",
            ),
            'rejected_email' => array(
                'label' => 'Not approved',
                'when' => 'An account waiting for approval is rejected.',
                'subject' => 'Your Renegades account request',
                'heading' => 'Your account request',
                'preheader' => 'About your Renegades account request.',
                'button' => '',
                'link' => '',
                'why' => $signed_up,
                'body' => "Hi {first_name},\n\nSorry, your account request wasn't approved this time. If you think that's a mistake, or you'd like us to look again, just reply to this email.",
            ),
            'inactive_email' => array(
                'label' => 'Deactivated',
                'when' => 'An admin deactivates an account.',
                'subject' => 'Your Renegades account has been deactivated',
                'heading' => 'Account deactivated',
                'preheader' => 'Your Renegades account has been deactivated.',
                'button' => '',
                'link' => '',
                'why' => $yours,
                'body' => "Hi {first_name},\n\nYour Renegades account has been deactivated. If you'd like it switched back on, just reply to this email.",
            ),
            'deletion_email' => array(
                'label' => 'Deleted',
                'when' => 'An account is deleted.',
                'subject' => 'Your Renegades account has been deleted',
                'heading' => 'Account deleted',
                'preheader' => 'Your Renegades account has been deleted.',
                'button' => '',
                'link' => '',
                'why' => $yours,
                'body' => "Hi {first_name},\n\nYour Renegades account has been deleted, along with the personal details and uploads on it.\n\nIf this was a mistake, just reply to this email.",
            ),
            'resetpw_email' => array(
                'label' => 'Reset your password',
                'when' => 'Someone asks to reset their password.',
                'subject' => 'Reset your Renegades password',
                'heading' => 'Reset your password',
                'preheader' => 'Use the button inside to choose a new password.',
                'button' => 'Choose a new password',
                'link' => '{password_reset_link}',
                'why' => 'You\'re getting this because someone asked to reset the password on your Renegades account.',
                'body' => "Hi {first_name},\n\nWe got a request to reset the password for your Renegades account. Use the button below to choose a new one.\n\n{button}\n\nIf you didn't ask for this, you can ignore this email: your password won't change.",
            ),
            'changedpw_email' => array(
                'label' => 'Password changed',
                'when' => 'Someone changes their password.',
                'subject' => 'Your Renegades password has been changed',
                'heading' => 'Password changed',
                'preheader' => 'The password on your Renegades account was just changed.',
                'button' => 'Go to my account',
                'link' => '{user_account_link}',
                'why' => $yours,
                'body' => "Hi {first_name},\n\nThe password for your Renegades account was just changed.\n\n" . $not_you . "\n\n{button}",
            ),
            'changedaccount_email' => array(
                'label' => 'Account updated',
                'when' => 'Someone changes their account details.',
                'subject' => 'Your Renegades account details were updated',
                'heading' => 'Account details updated',
                'preheader' => 'The details on your Renegades account were just updated.',
                'button' => 'Go to my account',
                'link' => '{user_account_link}',
                'why' => $yours,
                'body' => "Hi {first_name},\n\nThe details on your Renegades account were just updated.\n\n" . $not_you . "\n\n{button}",
            ),
        );
    }

    /** Saved wording over the defaults (blank = the built-in text). */
    public static function get_settings() {
        $saved = get_option(self::TEXT_OPTION, array());
        $saved = is_array($saved) ? $saved : array();
        $settings = array();
        foreach (self::get_events() as $key => $event) {
            $mine = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();
            $setting = array('customised' => false);
            foreach (array('subject', 'heading', 'body') as $field) {
                $text = isset($mine[$field]) ? trim((string) $mine[$field]) : '';
                $setting[$field] = $text !== '' ? $text : $event[$field];
                if ($text !== '') {
                    $setting['customised'] = true;
                }
            }
            $settings[$key] = $setting;
        }
        return $settings;
    }

    /** Is Ultimate Member sending HTML email (the only time this takes over)? */
    private static function um_html() {
        return function_exists('UM') && UM()->options()->get('email_html');
    }

    /**
     * Placeholder values from Ultimate Member for the member it's emailing
     * (um_convert_tags() with the email's own arguments, so the links are
     * the ones Ultimate Member made for this email).
     */
    private static function vars($event, $args) {
        $tag = function ($placeholder) use ($args) {
            return function_exists('um_convert_tags') ? (string) um_convert_tags($placeholder, $args) : '';
        };
        $name = $tag('{display_name}');
        $user_id = function_exists('um_user') ? intval(um_user('ID')) : 0;
        $link = $event['link'] !== '' ? $tag($event['link']) : '';
        // An Ultimate Member value it couldn't fill comes back as typed.
        if (strpos($link, '{') !== false || $link === '') {
            $link = !empty($event['link_fallback']) ? $tag($event['link_fallback']) : '';
            if (strpos($link, '{') !== false) {
                $link = '';
            }
        }
        $reset = $tag('{password_reset_link}');
        return array(
            '{first_name}' => TeamOversight_Email::first_name($user_id, TeamOversight_Email::decode($name)),
            '{name}' => $name,
            '{email}' => $tag('{email}'),
            '{username}' => $tag('{username}'),
            '{link}' => $link,
            '{reset_link}' => strpos($reset, '{') === false ? $reset : '',
        );
    }

    /** Made-up values for previews and test sends. */
    public static function get_sample_vars() {
        return array(
            '{first_name}' => 'Alex',
            '{name}' => 'Alex Example',
            '{email}' => 'alex@example.com',
            '{username}' => 'alex.example',
            '{link}' => home_url('/'),
            '{reset_link}' => home_url('/password-reset/'),
        );
    }

    /** Returns array(subject, email) for an email with the given values. */
    public static function render($key, $vars) {
        $events = self::get_events();
        $settings = self::get_settings();
        $event = $events[$key];
        $setting = $settings[$key];
        $label = $event['button'];
        if (!empty($event['button_when_reset']) && $vars['{reset_link}'] !== '' && $vars['{link}'] === $vars['{reset_link}']) {
            $label = $event['button_when_reset'];
        }
        $email = TeamOversight_Email::render(array(
            'program' => 'Your club account',
            'heading' => $setting['heading'],
            'body' => $setting['body'],
            'vars' => $vars,
            'button' => ($label !== '' && $vars['{link}'] !== '') ? array('url' => $vars['{link}'], 'label' => $label) : null,
            'preheader' => $event['preheader'],
            'why' => $event['why'],
        ));
        return array(TeamOversight_Email::fill($setting['subject'], $vars), $email);
    }

    public static function filter_subject($subject, $key) {
        $events = self::get_events();
        if (!isset($events[$key]) || !self::um_html()) {
            return $subject;
        }
        $settings = self::get_settings();
        // Ultimate Member fills its own placeholders in the subject next,
        // so ours are handed to it as its own.
        return strtr($settings[$key]['subject'], array(
            '{name}' => '{display_name}',
            '{link}' => $events[$key]['link'],
            '{reset_link}' => '{password_reset_link}',
        ));
    }

    /**
     * Ultimate Member's finished email, replaced with the club layout. Its
     * {placeholders} are then run once more by Ultimate Member, which
     * finds none left to fill.
     */
    public static function filter_message($message, $key, $args) {
        $events = self::get_events();
        if (!isset($events[$key]) || !self::um_html()) {
            return $message;
        }
        list($subject, $email) = self::render($key, self::vars($events[$key], (array) $args));
        if (self::$detach) {
            call_user_func(self::$detach);
        }
        self::$detach = TeamOversight_Email::prepare_mailer($email);
        $reply_to = function_exists('UM') ? sanitize_email((string) UM()->options()->get('mail_from_addr')) : '';
        self::$sending = array('id' => $email['id'], 'reply_to' => $reply_to);
        return $email['html'];
    }

    /**
     * On the message just built: the club-template marker, and a Reply-To
     * (Ultimate Member sets none, so replies would go to the sending
     * account rather than the club's address).
     */
    public static function filter_wp_mail($atts) {
        if (!self::$sending || strpos((string) $atts['message'], self::$sending['id']) === false) {
            return $atts;
        }
        $headers = $atts['headers'];
        $list = is_array($headers) ? $headers : preg_split("/\r\n|\n/", (string) $headers);
        $list = array_values(array_filter(array_map('trim', $list), 'strlen'));
        $has_reply_to = false;
        foreach ($list as $header) {
            if (stripos($header, 'Reply-To:') === 0) {
                $has_reply_to = true;
            }
        }
        if (!$has_reply_to && self::$sending['reply_to'] !== '') {
            $list[] = 'Reply-To: ' . self::$sending['reply_to'];
        }
        $list[] = TeamOversight_Email::MARKER_HEADER;
        $atts['headers'] = $list;
        return $atts;
    }

    public static function after_send($email, $key, $args) {
        if (self::$detach) {
            call_user_func(self::$detach);
            self::$detach = null;
        }
        self::$sending = null;
    }

    /** On Ultimate Member's email settings: where the wording now lives. */
    public static function um_settings_notice() {
        if (!isset($_GET['page'], $_GET['tab']) || $_GET['page'] !== 'um_options' || $_GET['tab'] !== 'email' || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-info"><p>The member emails\' wording and look are set on <a href="' . esc_url(admin_url('admin.php?page=team-oversight-emails#account-emails')) . '">VVL Oversight → Emails</a> (Club Manager). The on/off switches here still decide which ones are sent.</p></div>';
    }

    // ------------------------------------------------------------------
    // Admin (VVL Oversight → Emails)
    // ------------------------------------------------------------------

    /** Things in an account email's text that won't come out as meant. */
    public static function check_body($body) {
        return TeamOversight_Email::check_text($body, array_keys(self::get_sample_vars()), array('button'));
    }

    public static function render_admin_section() {
        if (!function_exists('UM')) {
            return;
        }
        $events = self::get_events();
        $settings = self::get_settings();
        $sample = self::get_sample_vars();
        $um = get_option('um_options', array());
        ?>
        <div id="account-emails" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-top: 20px;">
            <h2 style="margin-top: 0;">Account emails</h2>
            <p class="description" style="max-width: 900px;">
                Ultimate Member's emails about a member's account, in the club look. Ultimate Member still sends them: switch each on or off in
                <a href="<?php echo esc_url(admin_url('admin.php?page=um_options&tab=email')); ?>">Ultimate Member → Settings → Email</a>, which also sets the From name and address
                (replies go to that address). Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{email}</code> <code>{username}</code>
                <code>{link}</code> (the email's link: log in, activate, reset password or your account), and in Approved <code>{reset_link}</code>.
                <?php echo TeamOversight_Email::format_help(); ?>
                Clear a subject, heading or message and save to go back to the built-in wording.
            </p>
            <form method="post">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 15px;">
                    <?php foreach ($events as $key => $event): $s = $settings[$key]; $on = !empty($um[$key . '_on']); list($p_subject, $p_email) = self::render($key, $sample); ?>
                        <div style="border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 14px; <?php echo $on ? '' : 'background: #f6f7f7;'; ?>">
                            <p style="font-size: 14px; margin: 0;">
                                <strong><?php echo esc_html($event['label']); ?></strong>
                                <span style="font-size: 12px; color: <?php echo $on ? '#1a7a2e' : '#646970'; ?>;"> · <?php echo $on ? 'on' : 'off in Ultimate Member'; ?></span>
                                <?php if ($s['customised']): ?><span style="color: #996800; font-size: 12px;"> · customised</span><?php endif; ?>
                            </p>
                            <p class="description" style="margin: 4px 0 8px;"><?php echo esc_html($event['when']); ?></p>
                            <p style="margin: 0 0 6px;">
                                <label>Subject<br>
                                    <input type="text" name="account_emails[<?php echo esc_attr($key); ?>][subject]" value="<?php echo esc_attr($s['subject']); ?>" style="width: 100%;">
                                </label>
                            </p>
                            <p style="margin: 0 0 6px;">
                                <label>Heading<br>
                                    <input type="text" name="account_emails[<?php echo esc_attr($key); ?>][heading]" value="<?php echo esc_attr($s['heading']); ?>" style="width: 100%;">
                                </label>
                            </p>
                            <label>Message<br>
                                <textarea name="account_emails[<?php echo esc_attr($key); ?>][body]" rows="9" style="width: 100%; font-size: 13px;"><?php echo esc_textarea($s['body']); ?></textarea>
                            </label>
                            <?php foreach (self::check_body($s['body']) as $warning): ?>
                                <p style="color: #b32d2e; margin: 4px 0;">&#9888; <?php echo esc_html($warning); ?></p>
                            <?php endforeach; ?>
                            <?php if (TeamOversight_Email::normalise($s['body']) !== TeamOversight_Email::normalise($event['body'])): ?>
                                <details style="margin-top: 6px;">
                                    <summary style="cursor: pointer;">Built-in wording</summary>
                                    <pre style="background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; padding: 10px; white-space: pre-wrap; font-size: 12px; margin: 6px 0 0;"><?php echo esc_html($event['body']); ?></pre>
                                </details>
                            <?php endif; ?>
                            <details style="margin-top: 6px;">
                                <summary style="cursor: pointer;">Preview</summary>
                                <p style="background: #f0f0f1; padding: 6px 10px; border-radius: 4px; margin: 6px 0;"><strong>Subject:</strong> <?php echo esc_html($p_subject); ?></p>
                                <?php echo TeamOversight_Email::preview_frame($p_email['html']); ?>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <input type="submit" class="button button-primary" value="Save Account Emails">
                    <input type="hidden" name="action" value="save_account_email_settings">
                    <?php wp_nonce_field('account_email_settings', 'account_email_settings_nonce'); ?>
                </p>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="send_test_account_emails">
                <?php wp_nonce_field('account_email_settings', 'account_email_settings_nonce'); ?>
                <input type="submit" class="button" value="Send test of each switched-on account email to me">
            </form>
        </div>
        <?php
    }

    public static function save_admin_settings() {
        if (!isset($_POST['account_email_settings_nonce']) || !wp_verify_nonce($_POST['account_email_settings_nonce'], 'account_email_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }
        $posted = isset($_POST['account_emails']) && is_array($_POST['account_emails']) ? wp_unslash($_POST['account_emails']) : array();
        $texts = array();
        $warnings = array();
        foreach (self::get_events() as $key => $event) {
            $mine = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();
            $subject = isset($mine['subject']) ? sanitize_text_field($mine['subject']) : '';
            $heading = isset($mine['heading']) ? sanitize_text_field($mine['heading']) : '';
            $body = isset($mine['body']) ? sanitize_textarea_field($mine['body']) : '';
            // Wording left identical to the default is stored as "default".
            $texts[$key] = array(
                'subject' => trim($subject) === $event['subject'] ? '' : $subject,
                'heading' => trim($heading) === $event['heading'] ? '' : $heading,
                'body' => TeamOversight_Email::normalise($body) === TeamOversight_Email::normalise($event['body']) ? '' : TeamOversight_Email::normalise($body),
            );
            foreach (self::check_body($body !== '' ? $body : $event['body']) as $warning) {
                $warnings[] = $event['label'] . ': ' . $warning;
            }
        }
        update_option(self::TEXT_OPTION, $texts);
        if ($warnings) {
            echo '<div class="notice notice-warning"><p>' . implode('<br>', array_map('esc_html', $warnings)) . '</p></div>';
        }
        echo '<div class="notice notice-success"><p>Account email wording saved.</p></div>';
    }

    /** One of each switched-on email, with made-up values, to the admin. */
    public static function send_test_emails() {
        if (!isset($_POST['account_email_settings_nonce']) || !wp_verify_nonce($_POST['account_email_settings_nonce'], 'account_email_settings')) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
            return;
        }
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
            return;
        }
        $admin = wp_get_current_user();
        $sent = 0;
        $failed = 0;
        foreach (array_keys(self::get_events()) as $key) {
            if (!function_exists('UM') || !UM()->options()->get($key . '_on')) {
                continue;
            }
            list($subject, $email) = self::render($key, self::get_sample_vars());
            if (TeamOversight_Email::send($admin->user_email, '[TEST] ' . $subject, $email, self::test_headers())) {
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
            echo '<div class="notice notice-info"><p>Every account email is switched off in Ultimate Member, so there was nothing to send.</p></div>';
        }
    }

    /** Ultimate Member's From and a Reply-To to the same address, as its real sends get. */
    public static function test_headers() {
        $headers = array();
        if (function_exists('UM')) {
            $name = (string) UM()->options()->get('mail_from');
            $address = sanitize_email((string) UM()->options()->get('mail_from_addr'));
            if ($address !== '') {
                $headers[] = 'From: ' . ($name !== '' ? $name . ' <' . $address . '>' : $address);
                $headers[] = 'Reply-To: ' . $address;
            }
        }
        return $headers;
    }
}
