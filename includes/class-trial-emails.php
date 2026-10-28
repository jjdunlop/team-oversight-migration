<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transactional emails to trial applicants — one email per thing the
 * applicant did or paid for: application received, payment needed, payment
 * received, changes saved. Each can be switched off or reworded on
 * VVL Oversight → Emails, and they share the From / Reply-To set there.
 * Since 1.72.0 they're HTML in the club's email look (TeamOversight_Email);
 * the wording stays plain text, with {details} (the trial number, teams and
 * positions) and {button} drawn by the plugin.
 *
 * Not here: selection outcomes. From 1.77.0 the offer, its reminder, the
 * withdrawal, the coaches' accepted / declined / deadline notices, the
 * "you're locked in" note and the "not selected" email (1.80.0: only when
 * someone presses Send, about one team) are sent
 * by TeamOversight_Offer_Emails (class-offer-emails.php) through the offers
 * outbox and the live-site gate. This class stays applicant-event-only:
 * what the applicant did or paid for. Accepting, rejecting and Finalise
 * themselves still email nobody.
 */
class TeamOversight_Trial_Emails {

    /**
     * Each email's on/off switch. Before 1.72.0 it held the wording too;
     * that wording is left there untouched, for a site put back on 1.71.
     */
    const OPTION = 'team_oversight_trial_emails';

    /** 1.72.0 on: each email's subject, heading and body (blank = the built-in text). */
    const TEXT_OPTION = 'team_oversight_trial_email_texts';

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
     * The emails, with their default wording (TeamOversight_Email's
     * format). Placeholders: {first_name} {name} {season} {trial_number}
     * {teams} {positions} {fee} {link}. Blocks: {details}, {button}.
     * 'button' is the button's default words, 'details' what the details
     * box lists under the trial number.
     */
    public static function get_events() {
        $tip = "> Coaches may ask for your trial number at trials — plenty of players write it on their hand or arm on the day.";

        return array(
            'submitted' => array(
                'label' => 'Application received',
                'when' => 'An application is submitted and needs no payment — or a previously unpaid one becomes confirmed.',
                'subject' => 'Your {season} MURVC trial application — #{trial_number}',
                'heading' => 'Application received',
                'preheader' => 'Your trial number is #{trial_number}.',
                'button' => 'View or change my application',
                'details' => array('teams', 'positions'),
                'body' => "Hi {first_name},\n\n"
                    . "Thanks for applying to trial with MURVC for the {season} season.\n\n"
                    . "{details}\n\n"
                    . $tip . "\n\n"
                    . "Need to change something? Your application stays editable until applications close.\n\n"
                    . "{button}",
            ),
            'payment_needed' => array(
                'label' => 'Payment needed',
                'when' => 'An application is saved but the trial fee is due — on first submission, or when an edit makes the fee apply.',
                'subject' => 'Action needed: pay your {season} MURVC trial fee',
                'heading' => 'Pay your trial fee to confirm your application',
                'preheader' => 'Your application is saved. Pay the trial fee to confirm it.',
                'button' => 'Go to the trial page',
                'details' => array('teams', 'fee'),
                'body' => "Hi {first_name},\n\n"
                    . "Your {season} MURVC trial application has been saved, but it won't be confirmed until the trial fee ({fee}) is paid. Coaches can see your application, but can't select you for a team until it's paid.\n\n"
                    . "{details}\n\n"
                    . "You can pay from the trial page — look for the \"Pay the trial fee\" button.\n\n"
                    . "{button}\n\n"
                    . "Already paid, or think this is a mistake? Just reply to this email.",
            ),
            'paid' => array(
                'label' => 'Payment received',
                'when' => 'The trial fee is paid online, or an admin uses Mark as Paid.',
                'subject' => 'Payment received — {season} MURVC trial #{trial_number}',
                'heading' => 'Payment received',
                'preheader' => 'Your {season} trial application is confirmed. Your trial number is #{trial_number}.',
                'button' => 'View my application',
                'details' => array('teams', 'positions'),
                'body' => "Hi {first_name},\n\n"
                    . "Thanks — we've received your trial fee, and your {season} application is confirmed.\n\n"
                    . "{details}\n\n"
                    . $tip,
            ),
            'edited' => array(
                'label' => 'Changes saved',
                'when' => 'A confirmed application is edited and saved (no new payment needed).',
                'subject' => 'Your {season} MURVC trial application has been updated',
                'heading' => 'Application updated',
                'preheader' => 'Your changes are saved. Your trial number is still #{trial_number}.',
                'button' => 'View or change my application',
                'details' => array('teams', 'positions'),
                'unchanged' => true,
                'body' => "Hi {first_name},\n\n"
                    . "Your changes to your {season} MURVC trial application have been saved.\n\n"
                    . "{details}\n\n"
                    . "You can keep making changes until applications close.\n\n"
                    . "{button}\n\n"
                    . "If you didn't make this change, please reply to this email.",
            ),
        );
    }

    /**
     * Saved settings merged over the defaults: every email on unless
     * switched off; an empty subject, heading or body means "use the
     * default", so improvements to the built-in wording reach anyone who
     * never customised it.
     */
    public static function get_settings() {
        $switches = get_option(self::OPTION, array());
        $switches = is_array($switches) ? $switches : array();
        $saved = get_option(self::TEXT_OPTION, array());
        $saved = is_array($saved) ? $saved : array();

        $settings = array();
        foreach (self::get_events() as $key => $event) {
            $switch = isset($switches[$key]) && is_array($switches[$key]) ? $switches[$key] : array();
            $mine = isset($saved[$key]) && is_array($saved[$key]) ? $saved[$key] : array();
            $setting = array(
                'enabled' => isset($switch['enabled']) ? !empty($switch['enabled']) : true,
                'customised' => false,
            );
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

    /** Placeholder values for one application. */
    public static function get_vars($application) {
        $user = !empty($application->user_id) ? get_userdata($application->user_id) : false;
        $name = $user ? $user->display_name : (string) $application->name;

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
            '{first_name}' => TeamOversight_Email::first_name($user ? $user->ID : 0, $name),
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

    /**
     * Returns array(subject, email) for an event with the given values,
     * email = array('html' => ..., 'text' => ...).
     */
    public static function render($event, $vars) {
        $events = self::get_events();
        $settings = self::get_settings();
        if (!isset($settings[$event])) {
            return array('', array('html' => '', 'text' => ''));
        }
        $setting = $settings[$event];
        $email = TeamOversight_Email::render(array(
            'program' => 'Trials · ' . $vars['{season}'],
            'heading' => $setting['heading'],
            'body' => $setting['body'],
            'vars' => $vars,
            'blocks' => array('details' => self::details_block($events[$event], $vars)),
            'button' => array('url' => $vars['{link}'], 'label' => $events[$event]['button']),
            'preheader' => $events[$event]['preheader'],
            'why' => 'You\'re getting this because you applied to trial with the Renegades.',
        ));
        return array(TeamOversight_Email::fill($setting['subject'], $vars), $email);
    }

    /** {details}: the trial number, big, then the teams and positions (or fee). */
    private static function details_block($event, $vars) {
        $vars = array_map(function ($value) {
            return html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        }, $vars);
        $labels = array('teams' => 'Teams', 'positions' => 'Positions', 'fee' => 'Trial fee');
        $rows = array();
        foreach ($event['details'] as $field) {
            $value = $vars['{' . $field . '}'];
            if ($value !== '') {
                $rows[] = array($labels[$field], $value);
            }
        }
        $label = 'Your trial number' . (!empty($event['unchanged']) ? ' (unchanged)' : '');
        $number = '#' . $vars['{trial_number}'];

        $text = array($label . ': ' . $number);
        foreach ($rows as $row) {
            $text[] = $row[0] . ': ' . $row[1];
        }
        return array(
            'html' => TeamOversight_Email::details($rows, $label, $number),
            'text' => implode("\n", $text),
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
        list($subject, $email) = self::render($event, $vars);
        $sent = TeamOversight_Email::send($to, $subject, $email, self::get_headers());

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
                One email per thing the applicant did — never more — sent from the From address on the Overview tab, with their own Reply-To below.
                <strong>Offers and selection outcomes are not sent from here</strong>: the offer, its reminder, the withdrawal, the coaches' notices, "you're locked in" and "not selected" have their own <a href="<?php echo esc_url(admin_url('admin.php?page=team-oversight-emails&tab=offers#offer-emails')); ?>">Offers and selection</a> tab.
                Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{season}</code> <code>{trial_number}</code>
                <code>{teams}</code> <code>{positions}</code> <code>{fee}</code> <code>{link}</code>
                (the trial page: <a href="<?php echo esc_url(self::get_form_url()); ?>" target="_blank"><?php echo esc_html(self::get_form_url()); ?></a>).
                Put <code>{details}</code> on a line of its own for the box with the trial number, teams and positions.
                <?php echo TeamOversight_Email::format_help(); ?>
                Clear a subject, heading or body and save to go back to the built-in wording.
            </p>

            <form method="post">
                <p>
                    <label><strong>Reply-To for trial emails</strong><br>
                        <input type="email" name="trial_email_replyto" value="<?php echo esc_attr(get_option(self::REPLYTO_OPTION, self::DEFAULT_REPLYTO)); ?>" style="width: 320px;">
                    </label>
                    <span class="description" style="display: block; margin-top: 4px;">Where applicants' replies land — separate from the fee reminders' Reply-To, so trial questions reach whoever runs trials. Leave blank to use the reminders' Reply-To instead.</span>
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 15px;">
                    <?php foreach ($events as $key => $event): $s = $settings[$key]; list($p_subject, $p_email) = self::render($key, $sample); ?>
                        <div style="border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 14px; <?php echo $s['enabled'] ? '' : 'background: #f6f7f7;'; ?>">
                            <label style="font-size: 14px;">
                                <input type="checkbox" name="trial_emails[<?php echo esc_attr($key); ?>][enabled]" value="1" <?php checked($s['enabled']); ?>>
                                <strong><?php echo esc_html($event['label']); ?></strong>
                                <?php if ($s['customised']): ?><span style="color: #996800; font-size: 12px;"> · customised</span><?php endif; ?>
                            </label>
                            <p class="description" style="margin: 4px 0 8px;"><?php echo esc_html($event['when']); ?></p>
                            <p style="margin: 0 0 6px;">
                                <label>Subject<br>
                                    <input type="text" name="trial_emails[<?php echo esc_attr($key); ?>][subject]" value="<?php echo esc_attr($s['subject']); ?>" style="width: 100%;">
                                </label>
                            </p>
                            <p style="margin: 0 0 6px;">
                                <label>Heading<br>
                                    <input type="text" name="trial_emails[<?php echo esc_attr($key); ?>][heading]" value="<?php echo esc_attr($s['heading']); ?>" style="width: 100%;">
                                </label>
                            </p>
                            <label>Message<br>
                                <textarea name="trial_emails[<?php echo esc_attr($key); ?>][body]" rows="12" style="width: 100%; font-size: 13px;"><?php echo esc_textarea($s['body']); ?></textarea>
                            </label>
                            <?php foreach (self::check_body($s['body']) as $warning): ?>
                                <p style="color: #b32d2e; margin: 4px 0;">&#9888; <?php echo esc_html($warning); ?></p>
                            <?php endforeach; ?>
                            <?php echo TeamOversight_Email::wording_footer(array('trial_emails[' . $key . '][subject]', 'trial_emails[' . $key . '][heading]', 'trial_emails[' . $key . '][body]'), array($event['subject'], $event['heading'], $event['body']), $s['customised']); ?>
                            <details style="margin-top: 6px;">
                                <summary style="cursor: pointer;">Preview</summary>
                                <p style="background: #f0f0f1; padding: 6px 10px; border-radius: 4px; margin: 6px 0;"><strong>Subject:</strong> <?php echo esc_html($p_subject); ?></p>
                                <?php echo TeamOversight_Email::preview_frame($p_email['html']); ?>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <input type="submit" class="button button-primary" value="Save Trial Emails">
                    <input type="hidden" name="action" value="save_trial_email_settings">
                    <input type="hidden" name="email_format" value="<?php echo esc_attr(TeamOversight_Email::FORM_FORMAT); ?>">
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
        // A page opened before 1.72 posts the old plain-text layout: move
        // it to this format, as the update did, so it never comes back.
        $stale = !isset($_POST['email_format']) || wp_unslash($_POST['email_format']) !== TeamOversight_Email::FORM_FORMAT;
        $switches = get_option(self::OPTION, array());
        $switches = is_array($switches) ? $switches : array();
        $texts = array();
        $warnings = array();
        foreach (self::get_events() as $key => $event) {
            $mine = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();
            $subject = isset($mine['subject']) ? sanitize_text_field($mine['subject']) : '';
            $heading = isset($mine['heading']) ? sanitize_text_field($mine['heading']) : '';
            $body = isset($mine['body']) ? sanitize_textarea_field($mine['body']) : '';
            if ($stale) {
                $body = TeamOversight_Email::upgrade_posted($body, 'trial', $key);
            }
            // Only the switch is written to the 1.71 option; its old
            // wording stays as it was.
            $switch = isset($switches[$key]) && is_array($switches[$key]) ? $switches[$key] : array();
            $switch['enabled'] = !empty($mine['enabled']) ? 1 : 0;
            $switches[$key] = $switch;
            // Wording left identical to the default is stored as "default",
            // so later improvements to the built-in text still reach it.
            $texts[$key] = array(
                'subject' => (trim($subject) === $event['subject']) ? '' : $subject,
                'heading' => (trim($heading) === $event['heading']) ? '' : $heading,
                'body' => (TeamOversight_Email::normalise($body) === TeamOversight_Email::normalise($event['body'])) ? '' : TeamOversight_Email::normalise($body),
            );
            foreach (self::check_body($body !== '' ? $body : $event['body']) as $warning) {
                $warnings[] = $event['label'] . ': ' . $warning;
            }
        }
        update_option(self::OPTION, $switches);
        update_option(self::TEXT_OPTION, $texts);
        if ($stale) {
            echo '<div class="notice notice-info"><p>This page was opened before the emails were updated, so the wording you saved has been moved to the new layout. Check the previews below.</p></div>';
        }
        if ($warnings) {
            echo '<div class="notice notice-warning"><p>' . implode('<br>', array_map('esc_html', $warnings)) . '</p></div>';
        }

        $reply_raw = isset($_POST['trial_email_replyto']) ? trim(wp_unslash($_POST['trial_email_replyto'])) : '';
        $reply_to = sanitize_email($reply_raw);
        update_option(self::REPLYTO_OPTION, $reply_to);
        if ($reply_raw !== '' && $reply_to === '') {
            echo '<div class="notice notice-warning"><p>"' . esc_html($reply_raw) . '" isn\'t a valid email address, so trial emails will use the fee reminders\' Reply-To until it\'s fixed.</p></div>';
        }

        echo '<div class="notice notice-success"><p>Trial email settings saved.</p></div>';
    }

    /** Things in a trial email's text that won't come out as meant. */
    public static function check_body($body) {
        return TeamOversight_Email::check_text($body, array_keys(self::get_sample_vars()), array('details', 'button'));
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
            list($subject, $email) = self::render($key, $sample);
            if (TeamOversight_Email::send($admin->user_email, '[TEST] ' . $subject, $email, self::get_headers())) {
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
