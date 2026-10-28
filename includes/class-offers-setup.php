<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Offers: setup, status and the admin's plain-English helpers (1.78.0).
 *
 * Everything an administrator needs to answer "is this ready?" and "why is
 * nothing happening?" without knowing seven screens:
 *  - checks(): one live checklist of what has to be right before and during
 *    a season (season dates, fee release, upper limits, division levels, the
 *    offer settings, the pages the site links to, the deposit pay product,
 *    the email permission, coaches on file, the 2027 reset, who can approve,
 *    the daily check). Read-only: every row reads an existing reader and
 *    links to the screen that fixes it. It never blocks anything.
 *  - pages(): every page the offers link to, how it was found.
 *  - the season offer settings form in plain words (defaults beside every
 *    field, a worked example, last saved by, a reset button).
 *  - the daily job's last-run record and a manual "run now" button.
 *  - the editor for the wording registry (TeamOversight_Offer_Wording).
 *  - glossary(): what each offer state means, from TeamOversight_Offers::states().
 *
 * Nothing here changes a calculation, a coach screen or a player screen.
 */
class TeamOversight_Offers_Setup {

    /** The last daily run: array(at, ts, steps => name => array(ok, summary)). Non-autoloaded. */
    const DAILY_OPTION = 'team_oversight_offers_daily_status';
    /** When the offer emails were last test-sent and by whom: array(at, by, count). */
    const TEST_SENT_OPTION = 'team_oversight_offer_test_sent';
    /** A daily run older than this many hours is "not running". */
    const DAILY_STALE_HOURS = 30;

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_post_murvc_offers_run_daily', array(__CLASS__, 'handle_run_daily'));
        add_action('admin_notices', array(__CLASS__, 'gate_notice'));
    }

    /**
     * A warning on the VVL Oversight pages when this website has no
     * permission to send offer emails and messages have been waiting more
     * than an hour: the most likely way offers go quiet (a changed web
     * address closes the permission) and nothing else would say so.
     */
    public static function gate_notice() {
        if (!current_user_can('manage_options') || TeamOversight_Offers::emails_live()) {
            return;
        }
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '' || (strpos($page, 'team-oversight') !== 0 && strpos($page, 'club-membership') !== 0)) {
            return;
        }
        list($count, $oldest) = TeamOversight_Offer_Emails::waiting_longer_than(60);
        if ($count < 1) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . intval($count) . ' offer email' . ($count === 1 ? ' is' : 's are') . ' waiting and nothing is being sent.</strong> '
            . 'This website (' . esc_html(TeamOversight_Offers::site_host()) . ') has not been given permission to send offer emails'
            . ($oldest !== '' ? '; the oldest has waited since ' . esc_html(wp_date('j M, g:i a', strtotime($oldest))) : '') . '. '
            . '<a href="' . esc_url(self::emails_url('offers', 'offer-gate')) . '">Give permission on Emails, Offers and selection</a> (only on the live website).</p></div>';
    }

    // ------------------------------------------------------------------
    // Addresses of the screens that fix things
    // ------------------------------------------------------------------

    public static function config_url($season, $anchor = '') {
        return admin_url('admin.php?page=team-oversight-fees&season=' . rawurlencode((string) $season)) . ($anchor !== '' ? '#' . $anchor : '');
    }

    public static function emails_url($tab = 'offers', $anchor = '') {
        return admin_url('admin.php?page=team-oversight-emails&tab=' . rawurlencode($tab)) . ($anchor !== '' ? '#' . $anchor : '');
    }

    private static function h($text) {
        return esc_html((string) $text);
    }

    /**
     * A link to the Offers page (always in the VVL Oversight menu since
     * 1.79.0). $args: page_url() arguments (tab, season).
     */
    public static function offers_link($label, $args = array()) {
        return '<a href="' . esc_url(TeamOversight_Offers_Admin::page_url($args)) . '">' . esc_html($label) . '</a>';
    }

    private static function date_words($ymd) {
        $valid = TeamOversight_Fee_Timeline::parse_date((string) $ymd);
        return $valid === null ? '' : TeamOversight_Fee_Timeline::format_date($valid);
    }

    // ------------------------------------------------------------------
    // The pages the offers link to
    // ------------------------------------------------------------------

    /** The published page or post holding a shortcode: array(id, url) or null. */
    public static function find_page($shortcode) {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page', 'post') AND post_content LIKE %s ORDER BY (post_type = 'page') DESC, ID LIMIT 1",
            '%' . $wpdb->esc_like($shortcode) . '%'
        ));
        return $id ? array('id' => intval($id), 'url' => (string) get_permalink($id)) : null;
    }

    /**
     * Every page the offers and their emails link to, for one season: a list
     * of array(key, label, ok, url, how, note, fix (url), fix_label).
     * how: 'found by its shortcode', 'set by hand', 'built-in page number',
     * 'a guess (no page found)' or 'missing'. ok is false only when a link
     * would point somewhere that probably does not exist.
     */
    public static function pages($season) {
        $out = array();
        $fix_emails = self::emails_url('offers', 'offer-pages');

        $offer = self::find_page('[team_offer');
        if ($offer) {
            $out[] = array('key' => 'offer', 'label' => 'Your offer page', 'ok' => true, 'url' => $offer['url'], 'how' => 'found by its shortcode [team_offer]', 'note' => '', 'fix' => '', 'fix_label' => '');
        } else {
            $set = trim((string) get_option(TeamOversight_Offers::PAGE_URL_OPTION, ''));
            $out[] = array('key' => 'offer', 'label' => 'Your offer page', 'ok' => false, 'url' => TeamOversight_Offers::offer_page_url(),
                'how' => $set !== '' ? 'an address typed in by hand' : 'a guess (no page holds [team_offer])',
                'note' => 'The "See your offer" button in every offer email points here, so a player would land on a page that may not exist. Use the Create the Your Team Offer page button.',
                'fix' => $fix_emails, 'fix_label' => 'Emails, Offers and selection');
        }
        $checklist = self::find_page('[ready_to_play');
        $out[] = array('key' => 'checklist', 'label' => 'Player Checklist', 'ok' => (bool) $checklist, 'url' => TeamOversight_Readiness::checklist_url(),
            'how' => $checklist ? 'found by its shortcode [ready_to_play]' : 'a guess (no page holds [ready_to_play])',
            'note' => $checklist ? '' : 'The offer page and the "you\'re locked in" email send players here.', 'fix' => '', 'fix_label' => '');
        $trial = self::find_page('[team_trial_form');
        $out[] = array('key' => 'trial', 'label' => 'Trial form', 'ok' => (bool) $trial, 'url' => TeamOversight_Trial_Emails::get_form_url(),
            'how' => $trial ? 'found by its shortcode [team_trial_form]' : 'a guess (the home page; no page holds [team_trial_form])',
            'note' => $trial ? '' : 'The "not selected" email\'s Edit my application button points here.', 'fix' => '', 'fix_label' => '');
        $coach = self::find_page('[team_coach_portal');
        $out[] = array('key' => 'coach', 'label' => 'Coach portal', 'ok' => (bool) $coach, 'url' => TeamOversight_Offer_Emails::coach_portal_url(),
            'how' => $coach ? 'found by its shortcode [team_coach_portal]' : 'a guess (the home page; no page holds [team_coach_portal])',
            'note' => $coach ? '' : 'The buttons in the coaches\' emails point here.', 'fix' => '', 'fix_label' => '');

        $training = TeamOversight_Offers::training_url($season);
        $per_season = get_option(TeamOversight_Offers::TRAINING_URLS_OPTION, array());
        $has_season = is_array($per_season) && !empty($per_season[(string) $season]);
        $out[] = array('key' => 'training', 'label' => $season . ' Trials & Team Info page', 'ok' => $training !== '', 'url' => $training,
            'how' => $training === '' ? 'missing' : ($has_season ? 'set for ' . $season . ' by hand' : 'the default from Trial Applications (used when a season has no page of its own)'),
            'note' => $training === '' ? 'The training line in the offer emails and on the offer page has no link.' : '',
            'fix' => $fix_emails, 'fix_label' => 'Emails, Offers and selection');

        $defaults = TeamOversight_Not_Selected::pathway_defaults();
        $pages = TeamOversight_Not_Selected::pathway_pages();
        $names = array('mixed_training' => 'Mixed Training Program page', 'social_league' => 'Reds Social League page', 'junior_academy' => 'Junior Academy page');
        foreach ($names as $key => $label) {
            $id = intval($pages[$key]);
            $published = get_post_status($id) === 'publish';
            $link = TeamOversight_Not_Selected::pathway_page_link($key);
            $out[] = array('key' => $key, 'label' => $label, 'ok' => $published, 'url' => $link ? $link['url'] : '',
                'how' => ($id === intval($defaults[$key]) ? 'the built-in page number #' . $id : 'page #' . $id . ' chosen by hand') . ($published ? ': "' . get_the_title($id) . '"' : ': not published'),
                'note' => $published ? '' : 'The "not selected" email and the extra training / extra games line leave this page out.',
                'fix' => $fix_emails, 'fix_label' => 'Emails, Offers and selection');
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Who can approve
    // ------------------------------------------------------------------

    /** Display names of the people who can approve offers (WordPress administrators; the murvc_offers_can_approve filter can widen it). */
    public static function approvers() {
        $names = array();
        $admins = get_users(array('role' => 'administrator', 'orderby' => 'display_name', 'fields' => array('ID', 'display_name')));
        foreach ((array) $admins as $admin) {
            if (TeamOversight_Offers::can_approve($admin->ID)) {
                $names[] = (string) $admin->display_name;
            }
        }
        return $names;
    }

    // ------------------------------------------------------------------
    // The daily check
    // ------------------------------------------------------------------

    /** One short line for a step's result. */
    private static function brief($value) {
        if (is_array($value)) {
            $n = 0;
            array_walk_recursive($value, function ($v) use (&$n) {
                if (is_numeric($v)) {
                    $n += intval($v);
                }
            });
            return $n > 0 ? $n . ' change' . ($n === 1 ? '' : 's') : 'nothing to do';
        }
        if (is_numeric($value)) {
            return intval($value) > 0 ? intval($value) . ' done' : 'nothing to do';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /** Record what a daily run did (run_daily() calls this; a failure to record never matters). */
    public static function record_daily(array $steps) {
        try {
            $rows = array();
            foreach ($steps as $name => $value) {
                $rows[(string) $name] = array(
                    'ok' => !(is_string($value) && in_array($value, array('failed', 'not available'), true)),
                    'summary' => self::brief($value),
                );
            }
            update_option(self::DAILY_OPTION, array('at' => current_time('mysql'), 'ts' => time(), 'steps' => $rows), false);
        } catch (\Throwable $e) {
            // The record is a convenience; the run itself already happened.
        }
    }

    /** The last run: array(at, ts, steps, hours_ago, failed (list of step names), stale (bool)) or null when none is recorded. */
    public static function daily_status() {
        $raw = get_option(self::DAILY_OPTION, null);
        if (!is_array($raw) || empty($raw['ts'])) {
            return null;
        }
        $failed = array();
        foreach ((array) $raw['steps'] as $name => $step) {
            if (empty($step['ok'])) {
                $failed[] = $name;
            }
        }
        $hours = max(0, (time() - intval($raw['ts'])) / 3600);
        return array('at' => (string) $raw['at'], 'ts' => intval($raw['ts']), 'steps' => (array) $raw['steps'], 'hours_ago' => $hours, 'failed' => $failed, 'stale' => $hours > self::DAILY_STALE_HOURS);
    }

    /** Words for the daily check: array(text, state ('ok'|'warn'|'bad')). */
    public static function daily_words() {
        $status = self::daily_status();
        $next = wp_next_scheduled(TeamOversight_Offers::DAILY_HOOK);
        $next_words = $next ? ' Next scheduled run: ' . wp_date('D j M, g:i a', $next) . '.' : ' It is not scheduled to run (it is scheduled again on the next page load).';
        if ($status === null) {
            return array('The daily check has not run yet (or not since this feature was added).' . $next_words, 'warn');
        }
        $when = wp_date('D j M, g:i a', $status['ts']);
        if (!empty($status['failed'])) {
            return array('The daily check last ran ' . $when . ' and these steps failed: ' . implode(', ', $status['failed']) . '. The Logs page has the detail.' . $next_words, 'bad');
        }
        if ($status['stale']) {
            $days = max(1, (int) floor($status['hours_ago'] / 24));
            return array('The daily check last ran ' . $when . ', ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago. It runs on the site\'s scheduled tasks, which only run when someone visits the site. Press Run the daily check now.' . $next_words, 'bad');
        }
        return array('The daily check last ran ' . $when . ' and every step was ok.' . $next_words, 'ok');
    }

    /** admin-post: run the daily check now (manage_options + nonce; the job takes its own lock). */
    public static function handle_run_daily() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        check_admin_referer('murvc_offers_run_daily', 'murvc_offers_run_daily_nonce');
        $out = TeamOversight_Offers::run_daily();
        if (isset($out['skipped'])) {
            $ok = false;
            $message = 'The daily check did not run: ' . $out['skipped'];
        } else {
            $failed = array();
            foreach ($out as $name => $value) {
                if ($value === 'failed' || $value === 'not available') {
                    $failed[] = $name;
                }
            }
            $ok = empty($failed);
            $message = $ok ? 'The daily check ran and every step was ok.' : 'The daily check ran but these steps failed: ' . implode(', ', $failed) . '.';
        }
        TeamOversight_Offers::flash_message($ok, $message);
        $default = admin_url('admin.php?page=' . TeamOversight_Offers_Admin::SLUG . '&tab=setup');
        $back = isset($_POST['return']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_POST['return'])), $default) : $default;
        wp_safe_redirect($back);
        exit;
    }

    /** The "Run the daily check now" button as HTML. */
    public static function run_daily_button($return_url) {
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block; margin: 4px 0 0;">
            <input type="hidden" name="action" value="murvc_offers_run_daily">
            <input type="hidden" name="return" value="<?php echo esc_attr($return_url); ?>">
            <?php wp_nonce_field('murvc_offers_run_daily', 'murvc_offers_run_daily_nonce'); ?>
            <button type="submit" class="button">Run the daily check now</button>
        </form>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // The checklist
    // ------------------------------------------------------------------

    private static function row($key, $state, $title, $text, $url = '', $label = '') {
        return array('key' => $key, 'state' => $state, 'title' => $title, 'text' => $text, 'url' => $url, 'label' => $label);
    }

    /**
     * The live checklist for one season: a list of array(key, state ('ok' |
     * 'warn' | 'bad'), title, text, url, label). Reads only. 'bad' = offers
     * would fail or mislead a player; 'warn' = worth a look.
     */
    public static function checks($season) {
        $season = (string) $season;
        $rows = array();
        $fees = TeamOversight_Offers::fees();

        // 1. Season dates and the payment window.
        $dates = TeamOversight_Fees::get_season_dates($season);
        $window = TeamOversight_Fees::get_payment_window($season);
        if (!$dates) {
            $rows[] = self::row('dates', 'bad', 'Season dates', 'No season dates are set for ' . $season . ', so fees are charged in full and nothing shows as overdue.', self::config_url($season, 'season-dates'), 'Set the season dates');
        } else {
            $text = 'The season runs ' . self::date_words($dates['start']) . ' to ' . self::date_words($dates['end']) . '.';
            if ($window) {
                $text .= ' Fees fall due from ' . self::date_words($window['from']) . ($window['from_set'] ? '' : ' (the season start)') . ' to ' . self::date_words($window['to']) . ($window['to_set'] ? '' : ' (the season end)') . '. That last date is also the late-join cutoff for deposits.';
            }
            $rows[] = self::row('dates', 'ok', 'Season dates and payment window', $text, self::config_url($season, 'season-dates'), 'Season dates');
        }

        // 2. Fee release and the basis offers use today.
        $release = TeamOversight_Fees::get_fee_release_date($season);
        if ($release === '') {
            $rows[] = self::row('release', 'warn', 'Fee release date', 'No release date is set, so ' . $season . ' fees already count as released and every offer is quoted on the real fee (there are no Early Offers). If players are being offered places before fees are set, set a release date.', self::config_url($season, 'fee-release'), 'Fee release');
        } else {
            $basis = TeamOversight_Offers::basis_now($season);
            $rows[] = self::row('release', 'ok', 'Fee release date', 'Fees release on ' . self::date_words($release) . '. ' . ($basis === 'upper'
                ? 'Until then offers are quoted on the upper limit and called Early Offers.'
                : 'That date has passed, so offers are quoted on the real fee.'), self::config_url($season, 'fee-release'), 'Fee release');
        }

        // 3. Upper limits.
        $report = $fees->matrix_report($season);
        $cells = count(TeamOversight_Fees::offerable_cells());
        $missing = count($report['no_limit']);
        if ($missing > 0 && TeamOversight_Offers::basis_now($season) === 'fee') {
            // After the release a new offer is quoted on the fee (row 3b), so a missing limit blocks nothing new.
            $rows[] = self::row('limits', 'warn', 'Upper limits', ($cells - $missing) . ' of ' . $cells . ' offerable fee cells have an upper limit. The fees are released, so new offers are quoted on the real fee and these cells block nothing: ' . implode(', ', array_slice($report['no_limit'], 0, 6)) . ($missing > 6 ? ' and ' . ($missing - 6) . ' more' : '') . '. (Copying the fee matrix to the next season copies these limits.)', self::config_url($season, 'fee-matrix'), 'Fee matrix');
        } elseif ($missing > 0) {
            $rows[] = self::row('limits', 'bad', 'Upper limits', ($cells - $missing) . ' of ' . $cells . ' offerable fee cells have an upper limit. An offer can\'t be sent to a player in the others: ' . implode(', ', array_slice($report['no_limit'], 0, 6)) . ($missing > 6 ? ' and ' . ($missing - 6) . ' more' : '') . '. Enter 0 for a free cell.', self::config_url($season, 'fee-matrix'), 'Fee matrix');
        } else {
            $text = 'All ' . $cells . ' offerable fee cells have an upper limit.';
            if (!empty($report['above'])) {
                $text .= ' ' . count($report['above']) . ' fee(s) are above their limit (' . implode(', ', $report['above']) . '): players quoted on the limit are capped at what they were quoted.';
            }
            $rows[] = self::row('limits', empty($report['above']) ? 'ok' : 'warn', 'Upper limits', $text, self::config_url($season, 'fee-matrix'), 'Fee matrix');
        }

        // 3b. Fees (1.79.1): once the fees are released (or with no release
        // date) an offer is quoted on the real fee, and cell_check() refuses a
        // cell with no fee, so a blank fee blocks offers as a missing limit
        // does before the release. Before the release blank fees are normal.
        $no_fee = count($report['no_fee']);
        if (TeamOversight_Offers::basis_now($season) === 'fee') {
            if ($no_fee > 0) {
                $rows[] = self::row('fees', 'bad', 'Fees', ($cells - $no_fee) . ' of ' . $cells . ' offerable fee cells have a fee. Offers are now quoted on the real fee, so an offer can\'t be sent to a player in the others (the coach sees "the season fee for this player\'s category isn\'t set"): ' . implode(', ', array_slice($report['no_fee'], 0, 6)) . ($no_fee > 6 ? ' and ' . ($no_fee - 6) . ' more' : '') . '. Enter 0 for a free cell.', self::config_url($season, 'fee-matrix'), 'Fee matrix');
            } else {
                $rows[] = self::row('fees', 'ok', 'Fees', 'All ' . $cells . ' offerable fee cells have a fee, so every offer can be quoted on the real fee.', self::config_url($season, 'fee-matrix'), 'Fee matrix');
            }
        }

        // 4. Division levels.
        $zero = array();
        $team_codes = array();
        $config = TeamOversight_Offers::teams_config($season);
        foreach ((array) $config as $code => $team) {
            $team_codes[] = $code;
            if (intval(isset($team['level']) ? $team['level'] : 0) === 0) {
                $zero[] = (isset($team['name']) ? $team['name'] : $code) . ' (' . $code . ')';
            }
        }
        if (empty($team_codes)) {
            $rows[] = self::row('levels', 'bad', 'Teams and division levels', 'There are no teams for ' . $season . ' yet.', self::config_url($season, 'team-management'), 'Team Management');
        } elseif (!empty($zero)) {
            $rows[] = self::row('levels', 'warn', 'Division levels', count($zero) . ' team(s) have no division level: ' . implode(', ', array_slice($zero, 0, 6)) . (count($zero) > 6 ? ' and more' : '') . '. A team with no level is never judged by the higher-team rule (a player can be offered it whatever else they are selected for) and is never suggested in the "not selected" email.', self::config_url($season, 'team-management'), 'Team Management');
        } else {
            $rows[] = self::row('levels', 'ok', 'Division levels', 'All ' . count($team_codes) . ' teams have a division level. They decide the higher-team rule and the lower divisions the "not selected" email suggests.', self::config_url($season, 'team-management'), 'Team Management');
        }

        // 5. The offer settings.
        $settings = TeamOversight_Offers::settings($season);
        if ($settings['saved']) {
            $by = $settings['saved_by'] > 0 ? get_userdata($settings['saved_by']) : false;
            $rows[] = self::row('settings', 'ok', 'Offer settings', 'Saved' . ($by ? ' by ' . $by->display_name : '') . ($settings['saved_at'] !== '' ? ' on ' . self::date_words(substr($settings['saved_at'], 0, 10)) : '') . ': ' . self::settings_sentence($settings) . '.', self::config_url($season, 'offer-settings'), 'Offer settings');
        } else {
            $rows[] = self::row('settings', 'warn', 'Offer settings', 'Still on the built-in numbers (' . self::settings_sentence($settings) . '). Check them and press Save so they are yours.', self::config_url($season, 'offer-settings'), 'Offer settings');
        }

        // 6. The pages the offers link to.
        $bad_pages = array();
        foreach (self::pages($season) as $page) {
            if (!$page['ok']) {
                $bad_pages[] = $page['label'];
            }
        }
        if (empty($bad_pages)) {
            $rows[] = self::row('pages', 'ok', 'Pages the offers link to', 'Every page was found: the offer page, the Player Checklist, the trial form, the coach portal, the Trials & Team Info page and the three pathway pages.', self::emails_url('offers', 'offer-pages'), 'Pages and links');
        } else {
            $rows[] = self::row('pages', 'bad', 'Pages the offers link to', count($bad_pages) . ' page(s) could not be found or are not published: ' . implode(', ', $bad_pages) . '. Links to them in emails would go to the wrong place.', self::emails_url('offers', 'offer-pages'), 'Pages and links');
        }

        // 7. The deposit pay product.
        $product = TeamOversight_Payments::get_payment_product();
        if ($product) {
            $rows[] = self::row('product', 'ok', 'Deposit payment product', 'Deposits are paid through the member payment product "' . $product->get_name() . '".', admin_url('admin.php?page=team-oversight-invoices'), 'Payment Management');
        } else {
            $rows[] = self::row('product', 'bad', 'Deposit payment product', 'No member payment product is set, so a player who accepts sees "' . TeamOversight_Offers::wording('page_no_payment') . '" instead of a way to pay.', admin_url('admin.php?page=team-oversight-invoices'), 'Payment Management, Payment settings');
        }

        // 8. Emails: permission, sender, tests.
        $live = TeamOversight_Offers::emails_live();
        $gate = TeamOversight_Offers::email_gate();
        if ($live) {
            $rows[] = self::row('gate', 'ok', 'Offer emails may be sent from this website', 'Permission was given' . (!empty($gate['host']) ? ' for ' . $gate['host'] : '') . (!empty($gate['at']) ? ' on ' . self::date_words(substr((string) $gate['at'], 0, 10)) : '') . '. If the website\'s address ever changes, the permission is lost and every offer email waits until you give it again.', self::emails_url('offers', 'offer-gate'), 'Offer emails');
        } else {
            $given_to = !empty($gate['host']) ? (string) $gate['host'] : (!empty($gate['was_host']) ? (string) $gate['was_host'] : '');
            $was = ($given_to !== '' && $given_to !== TeamOversight_Offers::site_host()) ? ' (permission was given to ' . $given_to . ', not to ' . TeamOversight_Offers::site_host() . ')' : '';
            $rows[] = self::row('gate', 'bad', 'Offer emails may be sent from this website', 'Not yet. Offer emails are queued but nothing leaves this website' . $was . '. Only give permission on the live website (a test copy should stay like this).', self::emails_url('offers', 'offer-gate'), 'Offer emails');
        }
        $from = trim((string) get_option(TeamOversight_Payments::EMAIL_FROM_OPTION, ''));
        $reply = trim((string) get_option(TeamOversight_Payments::EMAIL_REPLYTO_OPTION, ''));
        $contact = TeamOversight_Offers::club_contact();
        $rows[] = self::row('sender', 'ok', 'Who offer emails come from', 'From: ' . ($from !== '' ? $from : 'the website\'s default sender') . '. Reply-To: the team\'s coach (the trial applications address when a team has no coach on file). The club contact players can be pointed to ({club_contact}): ' . ($contact !== '' ? $contact : 'none set') . '.', self::emails_url('overview', 'sender'), 'Sender settings');
        $tested = get_option(self::TEST_SENT_OPTION, null);
        if (is_array($tested) && !empty($tested['at'])) {
            $rows[] = self::row('tested', 'ok', 'Test emails', 'The offer emails were last test-sent on ' . self::date_words(substr((string) $tested['at'], 0, 10)) . '.', self::emails_url('offers', 'offer-emails'), 'Offer emails');
        } else {
            $rows[] = self::row('tested', 'warn', 'Test emails', 'The offer emails have not been test-sent yet. Press "Send a test of each switched-on offer email to me" and read them in a real inbox.', self::emails_url('offers', 'offer-emails'), 'Offer emails');
        }
        $custom = count(TeamOversight_Offer_Wording::saved());
        $rows[] = self::row('wording', 'ok', 'Offer wording', $custom > 0 ? $custom . ' line(s) of the offer wording are changed from the built-in text.' : 'The offer wording is the built-in text. Read it once under Offer wording and change anything that doesn\'t sound like the club.', self::emails_url('offers', 'offer-wording'), 'Offer wording');

        // 9. Coaches on file.
        $no_coach = array();
        foreach ((array) $config as $code => $team) {
            $contacts = TeamOversight_Offers::team_contacts($code, $season);
            if (!empty($contacts['none'])) {
                $no_coach[] = (isset($team['name']) ? $team['name'] : $code);
            }
        }
        if (!empty($no_coach) && !empty($team_codes)) {
            $rows[] = self::row('coaches', 'warn', 'Coaches on file', count($no_coach) . ' team(s) have no coach or assistant coach on the roster: ' . implode(', ', array_slice($no_coach, 0, 6)) . (count($no_coach) > 6 ? ' and more' : '') . '. Their offer and "not selected" emails are Reply-To the trial applications address and the coaches\' notices go there too.', admin_url('admin.php?page=team-oversight-assignments'), 'Team Assignments');
        } elseif (!empty($team_codes)) {
            $rows[] = self::row('coaches', 'ok', 'Coaches on file', 'Every team has a coach on the roster, so offer and "not selected" emails are Reply-To the right person.', admin_url('admin.php?page=team-oversight-assignments'), 'Team Assignments');
        }

        // 10. The one-off upgrade (the 2027 reset), and (1.80.0) rejections nobody has told.
        $upgrade = TeamOversight_Offers::upgrade_state();
        $reset = TeamOversight_Offers::reset_state();
        if ($upgrade !== null) {
            $reset_count = ($reset && isset($reset['count'])) ? intval($reset['count']) : 0;
            $since = !empty($upgrade['offers_from']) ? self::date_words(substr((string) $upgrade['offers_from'], 0, 10)) : '';
            $rows[] = self::row('reset', 'ok', 'Offers took over selection', 'Offers have been how selection works on this website since ' . ($since !== '' ? $since : 'the update') . '. '
                . ($reset_count > 0 ? $reset_count . ' earlier coach-recorded acceptance(s) went back to "offer not sent" (the ids are kept in an audit).' : 'There were no earlier coach-recorded acceptances to reset.'));
        } else {
            $rows[] = self::row('reset', 'warn', 'Offers took over selection', 'The one-off update step has not run yet (it runs by itself on the next page load or daily check).');
        }
        $ns_old = class_exists('TeamOversight_Not_Selected') ? TeamOversight_Not_Selected::untold_count($season, TeamOversight_Not_Selected::UNTOLD_FLAG_DAYS) : 0;
        if ($ns_old > 0) {
            $rows[] = self::row('ns_untold', 'warn', 'Players not told they weren\'t selected', $ns_old . ' rejection(s) from more than ' . TeamOversight_Not_Selected::UNTOLD_FLAG_DAYS . ' days ago have not been sent a "not selected" email. Nothing is sent automatically: a coach presses Send "not selected" email on the player\'s card, or an administrator presses Send (or Don\'t send) on the Not selected tab.', TeamOversight_Offers_Admin::page_url(array('tab' => 'notselected', 'season' => $season)), 'Not selected');
        }

        // 11. Who can approve.
        $who = self::approvers();
        // 1.79.1: the link goes to the screen that fixes it (Users, the Administrator role), not Committee Access, which can't.
        $rows[] = self::row('approvers', empty($who) ? 'bad' : 'ok', 'Who can approve offers', (empty($who) ? 'Nobody can approve offers.' : implode(', ', $who) . '.') . ' Approving needs a WordPress Administrator account: to add an approver, give them the Administrator role under Users. Edit access on Committee Access lets someone send offers in the coach portal but not approve them. If these people are away the queue waits.', admin_url('users.php?role=administrator'), 'Users (Administrator role)');

        // 12. The daily check. (There is no offers switch since 1.79.0: the
        // email permission above and the committee's approval are the controls.)
        list($daily_text, $daily_state) = self::daily_words();
        $rows[] = self::row('daily', $daily_state, 'Daily check', $daily_text);

        return $rows;
    }

    /** "reply within 7 days, pay within 14 days (not before 10 Jan 2027), deposit 10%". */
    public static function settings_sentence(array $settings) {
        $pct = rtrim(rtrim(number_format((float) $settings['percent'], 2, '.', ''), '0'), '.');
        return 'reply within ' . intval($settings['reply_days']) . ' days, pay the deposit within ' . intval($settings['deposit_days']) . ' days but not before ' . self::date_words($settings['deposit_date']) . ', deposit ' . ($pct === '' ? '0' : $pct) . '%';
    }

    /** How many rows are in each state: array(ok, warn, bad). */
    public static function tally(array $rows) {
        $n = array('ok' => 0, 'warn' => 0, 'bad' => 0);
        foreach ($rows as $row) {
            $n[$row['state']]++;
        }
        return $n;
    }

    /** The checklist as HTML. */
    public static function render_checks($season, $opts = array()) {
        $rows = self::checks($season);
        $tally = self::tally($rows);
        $heading = !isset($opts['heading']) || $opts['heading'];
        $labels = array('ok' => 'Ready', 'warn' => 'Check', 'bad' => 'Fix');
        $colours = array('ok' => '#00a32a', 'warn' => '#dba617', 'bad' => '#d63638');
        ob_start();
        echo '<div class="mo-setup" id="offers-setup-checks">';
        if ($heading) {
            echo '<h3 style="margin: 0 0 4px;">Is it ready?</h3>';
        }
        echo '<p class="description" style="margin-top: 0;">' . self::h($tally['bad'] > 0
            ? $tally['bad'] . ' thing(s) need fixing before offers will work properly.'
            : ($tally['warn'] > 0 ? 'Nothing is broken. ' . $tally['warn'] . ' thing(s) are worth a look.' : 'Everything below is ready.'))
            . ' This list is read live from the settings, so it clears itself when you fix something. It never blocks anything.</p>';
        echo '<table class="widefat striped" style="max-width: 1100px;"><tbody>';
        foreach ($rows as $row) {
            echo '<tr id="check-' . esc_attr($row['key']) . '"><td style="width: 62px; vertical-align: top;"><span style="display: inline-block; min-width: 46px; text-align: center; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; background: ' . esc_attr($colours[$row['state']]) . ';">' . self::h($labels[$row['state']]) . '</span></td>'
                . '<td style="vertical-align: top;"><strong>' . self::h($row['title']) . '</strong><br><span style="color: #50575e;">' . self::h($row['text']) . '</span></td>'
                . '<td style="vertical-align: top; white-space: nowrap;">' . ($row['url'] !== '' ? '<a href="' . esc_url($row['url']) . '">' . self::h($row['label']) . '</a>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin: 8px 0 0;">' . self::run_daily_button(isset($opts['return']) ? $opts['return'] : '') . '</p>';
        echo '</div>';
        return ob_get_clean();
    }

    /** The pages table as HTML. */
    public static function render_pages_table($season) {
        ob_start();
        echo '<table class="widefat striped" style="max-width: 1100px;"><thead><tr><th>Page</th><th>Address the site uses</th><th>How it was found</th></tr></thead><tbody>';
        foreach (self::pages($season) as $page) {
            echo '<tr><td><strong>' . self::h($page['label']) . '</strong></td><td>'
                . ($page['url'] !== '' ? '<a href="' . esc_url($page['url']) . '" target="_blank" rel="noopener">' . self::h($page['url']) . '</a>' : '<span style="color: #b32d2e;">none</span>')
                . '</td><td>' . ($page['ok'] ? '' : '<span style="color: #b32d2e;">') . self::h($page['how']) . ($page['ok'] ? '' : '</span>')
                . ($page['note'] !== '' ? '<br><span class="description">' . self::h($page['note']) . '</span>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // The season offer settings, in plain words
    // ------------------------------------------------------------------

    /** The worked example under the form: what an offer approved today would get. */
    public static function settings_example($season) {
        $settings = TeamOversight_Offers::settings($season);
        $today = current_time('Y-m-d');
        $cutoff = TeamOversight_Fees::latest_start_date($season);
        $due = TeamOversight_Offers::deadlines($today, $settings, $cutoff ? $cutoff : null);
        $later = TeamOversight_Fee_Timeline::add_days($today, intval($settings['deposit_days']));
        $why = $settings['deposit_date'] >= $later
            ? 'the fixed deposit date (' . self::date_words($settings['deposit_date']) . ') is later than ' . intval($settings['deposit_days']) . ' days after sending (' . self::date_words($later) . ')'
            : intval($settings['deposit_days']) . ' days after sending (' . self::date_words($later) . ') is later than the fixed deposit date (' . self::date_words($settings['deposit_date']) . ')';
        return 'An offer approved and sent today (' . self::date_words($today) . ') would ask for a reply by ' . self::date_words($due['reply_by']) . ' and the deposit by ' . self::date_words($due['deposit_due']) . ': ' . $why . ', and the later of the two wins.'
            . ($cutoff && $due['deposit_due'] === $cutoff ? ' (Cut back to the late-join cutoff, ' . self::date_words($cutoff) . '.)' : '');
    }

    /** Handle Reset to the built-in numbers (the Configuration page's POST branch calls this after its nonce check). Returns a notice. */
    public static function reset_settings($season) {
        $season = (string) $season;
        $all = get_option(TeamOversight_Offers::SETTINGS_OPTION, array());
        if (!is_array($all) || !isset($all[$season])) {
            return '<div class="notice notice-info"><p>' . self::h($season) . ' offer settings were already the built-in numbers.</p></div>';
        }
        unset($all[$season]);
        if (empty($all)) {
            delete_option(TeamOversight_Offers::SETTINGS_OPTION);
        } else {
            update_option(TeamOversight_Offers::SETTINGS_OPTION, $all, false);
        }
        TeamOversight_Offers::flush_cache();
        TeamOversight_Log::add('season_config', $season . ' offer settings put back to the built-in numbers (live offers keep the figures they were sent with)');
        return '<div class="notice notice-success"><p>' . self::h($season) . ' offer settings are back to the built-in numbers. Offers already sent keep the dates and deposit they were sent with.</p></div>';
    }

    /** The settings form for one season (HTML). Field names, the action and the nonce are the ones Configuration has always posted. */
    public static function render_settings_form($season) {
        $season = (string) $season;
        $settings = TeamOversight_Offers::settings($season);
        $defaults = TeamOversight_Offers::settings_defaults($season);
        $pct = rtrim(rtrim(number_format($settings['percent'], 2, '.', ''), '0'), '.');
        $dpct = rtrim(rtrim(number_format($defaults['percent'], 2, '.', ''), '0'), '.');
        $by = $settings['saved_by'] > 0 ? get_userdata($settings['saved_by']) : false;
        ob_start();
        ?>
        <h3 id="offer-settings" style="margin: 16px 0 4px;"><?php echo self::h($season); ?> offer settings</h3>
        <p class="description" style="margin-top: 0; max-width: 900px;">These four numbers decide the two dates and the deposit on every offer sent from now on. Each offer keeps the dates and deposit it was sent with, so changing them never moves an offer that is already out.</p>
        <form method="post" style="display: flex; flex-wrap: wrap; align-items: flex-start; gap: 18px;">
            <?php wp_nonce_field('save_offer_settings', 'offer_settings_nonce'); ?>
            <input type="hidden" name="action" value="save_offer_settings">
            <label><strong>Reply within (days)</strong><br>
                <input type="number" name="reply_days" value="<?php echo intval($settings['reply_days']); ?>" step="1" min="1" max="60" style="width: 80px;" required><br>
                <span class="description">Built-in: <?php echo intval($defaults['reply_days']); ?>. Days after sending that a player has to accept or decline.</span></label>
            <label><strong>Pay the deposit within (days)</strong><br>
                <input type="number" name="deposit_days" value="<?php echo intval($settings['deposit_days']); ?>" step="1" min="1" max="90" style="width: 80px;" required><br>
                <span class="description">Built-in: <?php echo intval($defaults['deposit_days']); ?>. Days after sending.</span></label>
            <label><strong>...but not before (date)</strong><br>
                <input type="date" name="deposit_date" value="<?php echo esc_attr($settings['deposit_date']); ?>" required><br>
                <span class="description">Built-in: <?php echo self::h(self::date_words($defaults['deposit_date'])); ?>. The deposit is due on the later of this date and the days above.</span></label>
            <label><strong>Deposit (% of the quoted fee)</strong><br>
                <input type="number" name="percent" value="<?php echo esc_attr($pct === '' ? '0' : $pct); ?>" step="0.01" min="0" max="100" style="width: 90px;" required><br>
                <span class="description">Built-in: <?php echo self::h($dpct); ?>%. 0 locks a player in the moment they accept.</span></label>
            <span style="align-self: center;"><input type="submit" class="button button-primary" value="Save offer settings"></span>
        </form>
        <p style="margin: 8px 0 0; max-width: 900px;"><strong>Worked example.</strong> <?php echo self::h(self::settings_example($season)); ?></p>
        <p class="description" style="margin: 4px 0 8px;">
            <?php if ($settings['saved']): ?>
                Last saved<?php echo $by ? ' by ' . self::h($by->display_name) : ''; ?><?php echo $settings['saved_at'] !== '' ? ' on ' . self::h(self::date_words(substr($settings['saved_at'], 0, 10))) : ''; ?>.
            <?php else: ?>
                Not saved yet: these are the built-in numbers. Press Save once you have checked them.
            <?php endif; ?>
        </p>
        <?php if ($settings['saved']): ?>
            <form method="post" style="margin: 0 0 8px;" onsubmit="return confirm('Put the <?php echo esc_js($season); ?> offer settings back to the built-in numbers? Offers already sent keep what they were sent with.');">
                <?php wp_nonce_field('reset_offer_settings', 'offer_settings_reset_nonce'); ?>
                <input type="hidden" name="action" value="reset_offer_settings">
                <input type="submit" class="button" value="Reset to the built-in numbers">
            </form>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // The wording editor (Emails -> Offers and selection -> Offer wording)
    // ------------------------------------------------------------------

    /** Save the posted wording (save_offer_wording). Returns notice HTML. $posted: the offer_wording[] array, already unslashed. */
    public static function save_wording_post(array $posted, array $reset_keys, $actor_id) {
        $clean = array();
        foreach ($posted as $key => $text) {
            if (is_string($text)) {
                $clean[(string) $key] = sanitize_textarea_field($text);
            }
        }
        foreach ($reset_keys as $key) {
            $clean[(string) $key] = '';
        }
        $result = TeamOversight_Offer_Wording::save($clean, $actor_id);
        $html = '';
        if (!empty($result['errors'])) {
            $html .= '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $result['errors'])) . '</p></div>';
        }
        if (!empty($result['warnings'])) {
            $html .= '<div class="notice notice-warning"><p>' . implode('<br>', array_map('esc_html', $result['warnings'])) . '</p></div>';
        }
        $html .= '<div class="notice notice-' . ($result['ok'] ? 'success' : 'warning') . '"><p>Offer wording: '
            . intval($result['changed']) . ' line(s) changed, ' . intval($result['reset']) . ' put back to the built-in text.</p></div>';
        return $html;
    }

    /** The wording editor as HTML. $hidden: a callable action => hidden fields (the section's nonce). */
    public static function render_wording_panel($hidden) {
        $groups = TeamOversight_Offer_Wording::groups();
        $catalogue = TeamOversight_Offer_Wording::catalogue();
        $saved = TeamOversight_Offer_Wording::saved();
        ob_start();
        ?>
        <div id="offer-wording">
            <h3>Offer wording</h3>
            <p class="description" style="max-width: 900px;">Every sentence the offer emails and the offer page are built from that isn't an email body. Each box shows the built-in text underneath. Leave a box as it is, or tick "Put back", to use the built-in text (so later improvements still reach it). <code>{curly}</code> words are filled in by the site: each box lists the ones it can fill. <code>**double stars**</code> make bold text. Every line can also use <code>{club_contact}</code>, the club's contact address for offers (set on the Overview tab's Sender box) &mdash; for example &quot;Please contact the club on {club_contact}.&quot; <?php echo count($saved) > 0 ? '<strong>' . count($saved) . ' line(s) are changed from the built-in text.</strong>' : 'Nothing is changed from the built-in text.'; ?></p>
            <form method="post">
                <?php foreach ($groups as $group => $info): ?>
                    <details style="margin: 0 0 10px; background: #fff; border: 1px solid #dcdcde; padding: 8px 12px;"<?php echo $group === 'early' || $group === 'policy' ? ' open' : ''; ?>>
                        <summary style="cursor: pointer; font-weight: 600;"><?php echo esc_html($info[0]); ?>
                            <?php $mine = 0; foreach ($catalogue as $key => $item) { if ($item['group'] === $group && isset($saved[$key])) { $mine++; } } ?>
                            <?php if ($mine): ?><span style="color: #996800; font-weight: normal; font-size: 12px;"> &middot; <?php echo intval($mine); ?> changed</span><?php endif; ?>
                        </summary>
                        <p class="description"><?php echo esc_html($info[1]); ?></p>
                        <?php foreach ($catalogue as $key => $item): if ($item['group'] !== $group) { continue; } $custom = isset($saved[$key]); ?>
                            <div style="margin: 0 0 14px; padding: 0 0 10px; border-bottom: 1px solid #f0f0f1;">
                                <label for="ow-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($item['label']); ?></strong><?php if ($custom): ?><span style="color: #996800; font-size: 12px;"> &middot; changed</span><?php endif; ?></label>
                                <span class="description" style="display: block;">Appears: <?php echo esc_html($item['where']); ?></span>
                                <textarea id="ow-<?php echo esc_attr($key); ?>" name="offer_wording[<?php echo esc_attr($key); ?>]" rows="<?php echo intval($item['rows']); ?>" style="width: 100%; max-width: 900px; font-size: 13px;"><?php echo esc_textarea(TeamOversight_Offer_Wording::get($key)); ?></textarea>
                                <?php if (!empty($item['vars'])): ?>
                                    <span class="description" style="display: block;">Can fill: <?php
                                    $bits = array();
                                    foreach ($item['vars'] as $token => $meaning) {
                                        $bits[] = '<code>' . esc_html($token) . '</code> ' . esc_html($meaning);
                                    }
                                    echo implode('; ', $bits);
                                    ?></span>
                                <?php endif; ?>
                                <?php if ($custom): ?>
                                    <details style="margin-top: 2px;"><summary style="cursor: pointer; font-size: 12px;">Built-in wording</summary>
                                        <pre style="background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; padding: 8px; white-space: pre-wrap; font-size: 12px; margin: 4px 0 0;"><?php echo esc_html($item['default']); ?></pre>
                                    </details>
                                    <label style="font-size: 12px;"><input type="checkbox" name="offer_wording_reset[]" value="<?php echo esc_attr($key); ?>"> Put back to the built-in text</label>
                                <?php else: ?>
                                    <span class="description" style="display: block; font-size: 12px;">This is the built-in text.</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
                <p>
                    <input type="submit" class="button button-primary" value="Save offer wording">
                    <?php echo $hidden('save_offer_wording'); ?>
                </p>
            </form>
            <?php if (!empty($saved)): ?>
                <form method="post" onsubmit="return confirm('Put ALL the offer wording above back to the built-in text? This does not touch the email bodies or the commitments lists.');">
                    <?php echo $hidden('reset_offer_wording'); ?>
                    <input type="submit" class="button" value="Put all offer wording back to the built-in text">
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // The glossary (what each state means)
    // ------------------------------------------------------------------

    /** state => one plain sentence, in the committee's words. */
    public static function glossary() {
        return array(
            'awaiting_approval' => 'A coach has asked for an offer to go to this player. Nothing is emailed until an administrator approves it on To approve.',
            'sent' => 'Approved: the offer is live and the player can see it on their own pages. It is emailed straight away, or as soon as this website may send offer emails. The player has until the reply-by date (counted from the approval) to accept or decline. Shown on Waiting on players.',
            'accepted' => 'The player said yes. They now have until the deposit date to pay the deposit. Shown on Waiting on players.',
            'locked_in' => 'The deposit is complete. The player is put on the team automatically and the coach portal lists them under Confirmed ("placed" here means the place on the team exists).',
            'not_approved' => 'The committee said no. The coach sees "Not approved" and the player is told nothing.',
            'cancelled' => 'The coach cancelled the request before it was approved. Nothing was sent.',
            'declined' => 'The player said no.',
            'moved_declined' => 'A coach or administrator moved the player to Declined after a missed reply or deposit date. The player is not emailed.',
            'withdrawn' => 'The club took the offer back. The player is emailed a short note. Any deposit is theirs to have back.',
            'left' => 'A locked-in player left the team. The place comes off first, then the offer ends and the deposit is settled.',
        );
    }

    /** The glossary as a collapsible HTML block. */
    public static function render_glossary() {
        $states = TeamOversight_Offers::states();
        ob_start();
        echo '<details class="mo-box"><summary style="cursor: pointer; font-weight: 600;">What the states mean</summary><dl style="margin: 8px 0 0;">';
        foreach (self::glossary() as $state => $sentence) {
            echo '<dt style="font-weight: 600; margin-top: 6px;">' . self::h(isset($states[$state]) ? $states[$state] : $state) . '</dt><dd style="margin: 0 0 0 16px;">' . self::h($sentence) . '</dd>';
        }
        echo '<dt style="font-weight: 600; margin-top: 6px;">Credit and forfeit</dt><dd style="margin: 0 0 0 16px;">When an offer ends with a deposit paid, the site settles it straight away. A withdrawal by the club makes it credit: the club broke its side and the money stays on the player\'s account (Held deposits) until the treasurer refunds it by hand. A decline, or a move to Declined after a missed date, makes it a forfeit: the player broke theirs and the deposit is kept (the Forfeited list on Held deposits; Reverse forfeit gives it back). Only a place removed outside the Offers page, a reversed forfeit or money the site could not settle waits on Held deposits for the treasurer to choose "Refund owed" (credit) or "Club keeps it" (forfeit). Separately, money above the final fee also stays on the account as credit.</dd>';
        echo '<dt style="font-weight: 600; margin-top: 6px;">Placed, Locked in, Confirmed</dt><dd style="margin: 0 0 0 16px;">Three words for related things. Locked in is the offer\'s state (the deposit is complete). Placed is the admin word for the team place existing. Confirmed is the coach portal\'s list of players placed on a team.</dd>';
        echo '</dl></details>';
        return ob_get_clean();
    }
}
