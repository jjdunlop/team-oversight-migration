<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The player's side of an offer (1.76.0): the [team_offer] page where they
 * read the offer, see a read-only preview of their Player Checklist, and
 * Accept or Decline; the deposit pay box; and the offers banner on
 * [ready_to_play].
 *
 * Accepting and declining are TeamOversight_Offers::player_accept() /
 * player_decline() (ownership is checked there, in SQL); this class only
 * draws and posts. Starting the deposit payment is
 * TeamOversight_Offers::maybe_start_deposit_payment() (the money side), which
 * the pay box's form posts to: the names it posts are
 * TeamOversight_Offers::DEPOSIT_FORM (design/offer-api.md section 6.11).
 *
 * Rules: Post/Redirect/Get for every POST (never process during shortcode
 * render); links are ?offer=<id>, never nonced action links (the page itself
 * carries fresh nonces); the offer page is personal, so it sends
 * nocache_headers() and defines DONOTCACHEPAGE; a logged-out visitor sees the
 * login prompt, and someone else's offer, a missing id and an invisible
 * state (awaiting_approval, not_approved, cancelled) all get the SAME
 * neutral message, so ids cannot be probed. Every page render is a pure
 * reader: nothing here writes on a GET (a complete deposit that has not yet
 * locked the player in is picked up by the payment hook, a release or the
 * daily reconcile, never by looking at the page). An offer that has been
 * sent can always be read, accepted, declined and paid. Design: section 8
 * of design/offer-deposit-tech-design.md.
 */
class TeamOversight_Offers_Player {

    /** Hidden field names of the accept / decline form. */
    const ACTION_FIELD = 'murvc_offer_action';
    const ID_FIELD = 'murvc_offer_id';
    const NONCE_FIELD = 'murvc_offer_nonce';
    const REASON_FIELD = 'murvc_offer_reason';

    /** How long a finished offer the player did not end themselves stays on the checklist banner. */
    const FINISHED_BANNER_DAYS = 60;

    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_shortcode('team_offer', array(__CLASS__, 'render_shortcode'));
        // Accept and decline post here (Post/Redirect/Get, before any output).
        add_action('template_redirect', array(__CLASS__, 'handle_post'));
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** The same words for a missing, foreign and invisible offer. */
    public static function neutral_message() {
        return TeamOversight_Offers::wording('page_neutral');
    }

    /** The team's name for a season (the code if it has none). */
    private static function team_name($season, $code) {
        static $cache = array();
        $season = (string) $season;
        if (!isset($cache[$season])) {
            $database = new TeamOversight_Database();
            $cache[$season] = $database->get_teams_config($season);
        }
        return isset($cache[$season][$code]['name']) ? (string) $cache[$season][$code]['name'] : (string) $code;
    }

    private static function role_label($role) {
        return $role === 'training_only' ? 'Training Only' : 'Playing member';
    }

    /** "14 Nov 2026" from a date or datetime ('' when there is none). */
    private static function fmt($value) {
        $value = substr((string) $value, 0, 10);
        return $value === '' ? '' : TeamOversight_Fee_Timeline::format_date($value);
    }

    private static function money_cents($cents) {
        return TeamOversight_Offers::money(intval($cents) / 100);
    }

    private static function percent_text($percent) {
        $text = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
        return ($text === '' ? '0' : $text) . '%';
    }

    /** The player's words for an offer's state. */
    private static function state_label($state) {
        $labels = array(
            'sent' => 'Offer sent',
            'accepted' => 'Accepted: deposit due',
            'locked_in' => 'Locked in',
            'declined' => 'Declined',
            'moved_declined' => 'Offer closed',
            'withdrawn' => 'Offer withdrawn',
            'left' => 'Left the team',
        );
        return isset($labels[$state]) ? $labels[$state] : '';
    }

    /** The newest offer per season and team (the list is newest first). */
    private static function latest_per_team(array $offers) {
        $latest = array();
        foreach ($offers as $offer) {
            $key = $offer->season . '|' . $offer->team;
            if (!isset($latest[$key])) {
                $latest[$key] = $offer;
            }
        }
        return array_values($latest);
    }

    /** The requested offer id from ?offer= (0 when none or not a whole number). */
    private static function requested_offer_id() {
        if (!isset($_GET['offer']) || !is_scalar($_GET['offer'])) {
            return 0;
        }
        $raw = (string) wp_unslash($_GET['offer']);
        return preg_match('/^\d{1,10}$/D', $raw) === 1 ? intval($raw) : 0;
    }

    /** Never cache a personal page. */
    private static function no_cache() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
            // A personal page: tell search engines not to index it (this replaces the by-hand noindex step in the SEO plugin).
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }

    /** The robots meta of the page that carries [team_offer]: noindex, nofollow (WordPress's wp_robots and The SEO Framework's own filter). */
    public static function robots_noindex($robots) {
        if (!is_array($robots)) {
            return $robots;
        }
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        unset($robots['index'], $robots['follow'], $robots['max-image-preview']);
        return $robots;
    }

    public static function seo_noindex($meta) {
        if (!is_array($meta)) {
            return $meta;
        }
        $meta['noindex'] = 'noindex';
        $meta['nofollow'] = 'nofollow';
        return $meta;
    }

    /** template_redirect: send the no-cache headers early for the page that carries the shortcode (the shortcode itself runs after output has begun). */
    private static function maybe_no_cache() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $post = get_queried_object();
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'team_offer')) {
            self::no_cache();
            add_filter('wp_robots', array(__CLASS__, 'robots_noindex'), 100);
            add_filter('the_seo_framework_robots_meta_array', array(__CLASS__, 'seo_noindex'), 100);
        }
    }

    /** The one-off notice left by the last accept / decline / refused deposit payment, as HTML ('' when none); consumed. */
    private static function take_flash($user_id) {
        $key = TeamOversight_Offers::FLASH_PREFIX . intval($user_id);
        $flash = get_transient($key);
        if (!is_array($flash) || empty($flash['message'])) {
            return '';
        }
        delete_transient($key);
        return '<div class="murvc-offer-notice ' . (!empty($flash['ok']) ? 'murvc-offer-notice-ok' : 'murvc-offer-notice-err') . '" role="status">' . esc_html($flash['message']) . '</div>';
    }

    private static function set_flash($user_id, $ok, $message) {
        set_transient(TeamOversight_Offers::FLASH_PREFIX . intval($user_id), array('ok' => (bool) $ok, 'message' => (string) $message), 120);
    }

    // ------------------------------------------------------------------
    // Accept and decline (Post/Redirect/Get)
    // ------------------------------------------------------------------

    /**
     * template_redirect: process the accept / decline form
     * (murvc_offer_action, murvc_offer_id, murvc_offer_nonce for the action
     * murvc_offer_<action>_<id>, shown_state, shown_target, and for a decline
     * murvc_offer_reason), then redirect back to the offer page with a flash
     * notice. Also sends the no-cache headers for the offer page.
     */
    public static function handle_post() {
        self::maybe_no_cache();
        if (empty($_POST[self::ACTION_FIELD]) || !is_user_logged_in()) {
            return;
        }
        $out = self::process_post($_POST, get_current_user_id());
        if (empty($out['handled'])) {
            return;
        }
        wp_safe_redirect($out['redirect']);
        exit;
    }

    /**
     * Everything handle_post() does except the redirect (so it can be tested):
     * checks the nonce and the figures the player saw, runs
     * TeamOversight_Offers::player_accept() / player_decline() (ownership is
     * checked there in SQL, by account id), and leaves the flash notice.
     * $post is the slashed request array. Returns array(handled (bool: it was
     * an accept / decline post), ok, code, message, redirect (the offer page
     * to go back to), result (the class's result or null)). A foreign or
     * missing offer leaves NO notice, so the page shows the same neutral
     * message it always does.
     */
    public static function process_post(array $post, $user_id) {
        $user_id = intval($user_id);
        $out = array('handled' => false, 'ok' => false, 'code' => '', 'message' => '', 'redirect' => '', 'result' => null);
        $action = (isset($post[self::ACTION_FIELD]) && is_scalar($post[self::ACTION_FIELD])) ? sanitize_key(wp_unslash((string) $post[self::ACTION_FIELD])) : '';
        if (!in_array($action, array('accept', 'decline'), true)) {
            return $out;
        }
        $out['handled'] = true;
        $offer_id = (isset($post[self::ID_FIELD]) && is_scalar($post[self::ID_FIELD])) ? absint(wp_unslash((string) $post[self::ID_FIELD])) : 0;
        $out['redirect'] = $offer_id > 0 ? TeamOversight_Offers::offer_url($offer_id) : TeamOversight_Offers::offer_page_url();
        $fail = function ($code, $message) use (&$out, $user_id) {
            $out['code'] = $code;
            $out['message'] = $message;
            self::set_flash($user_id, false, $message);
            return $out;
        };
        if ($user_id <= 0 || $offer_id <= 0 || get_current_user_id() !== $user_id) {
            $out['code'] = 'not_found';
            return $out;
        }
        $nonce = (isset($post[self::NONCE_FIELD]) && is_scalar($post[self::NONCE_FIELD])) ? (string) wp_unslash($post[self::NONCE_FIELD]) : '';
        if (!wp_verify_nonce($nonce, 'murvc_offer_' . $action . '_' . $offer_id)) {
            return $fail('expired', 'This page has expired. Please check the details below and try again.');
        }
        $shown = array('state' => (isset($post['shown_state']) && is_scalar($post['shown_state'])) ? sanitize_key(wp_unslash((string) $post['shown_state'])) : '');
        if ($action === 'accept') {
            // The figure the player was looking at must travel with the accept (an absent one would skip the check): they only ever accept the deposit they saw.
            $seen = (isset($post['shown_target']) && is_scalar($post['shown_target'])) ? trim((string) wp_unslash($post['shown_target'])) : '';
            if (!is_numeric($seen)) {
                return $fail('stale', 'Please reload the page and check your deposit before you accept.');
            }
            $shown['shown_target'] = $seen;
            $result = TeamOversight_Offers::player_accept($offer_id, $user_id, $shown);
        } else {
            $reason = (isset($post[self::REASON_FIELD]) && is_scalar($post[self::REASON_FIELD])) ? (string) wp_unslash($post[self::REASON_FIELD]) : '';
            $result = TeamOversight_Offers::player_decline($offer_id, $user_id, $reason, $shown);
        }
        $out['result'] = $result;
        $out['ok'] = !empty($result['ok']);
        $out['code'] = isset($result['code']) ? (string) $result['code'] : '';
        $out['message'] = isset($result['message']) ? (string) $result['message'] : '';
        if ($out['code'] !== 'not_found' && $out['message'] !== '') {
            self::set_flash($user_id, $out['ok'], $out['message']);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The [team_offer] page
    // ------------------------------------------------------------------

    /** The [team_offer] page: the player's offers list, or one offer with ?offer=<id>. */
    public static function render_shortcode($atts = array()) {
        if (doing_action('wp_head')) {
            return '';
        }
        self::no_cache();
        $offer_id = self::requested_offer_id();
        if (!is_user_logged_in()) {
            return self::wrap(self::login_prompt($offer_id));
        }
        $user = wp_get_current_user();
        $html = self::take_flash($user->ID);
        if ($offer_id > 0) {
            $offer = TeamOversight_Offers::get_for_user($offer_id, $user->ID);
            if (!$offer || !in_array($offer->state, TeamOversight_Offers::PLAYER_VISIBLE, true)) {
                return self::wrap($html . '<div class="murvc-offer-box"><p>' . esc_html(self::neutral_message()) . '</p></div>');
            }
            $decline = !empty($_GET['decline']) && in_array($offer->state, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), true);
            return self::wrap($html . self::render_offer($user, $offer, $decline));
        }
        return self::wrap($html . self::render_list($user));
    }

    /** The page's frame and style, once. */
    private static function wrap($inner) {
        return '<div class="murvc-offer-page">' . $inner . '</div>' . self::styles();
    }

    private static function login_prompt($offer_id) {
        $base = get_permalink();
        if (!$base) {
            $base = TeamOversight_Offers::offer_page_url();
        }
        $back = $offer_id > 0 ? add_query_arg('offer', $offer_id, $base) : $base;
        $login_url = function_exists('um_get_core_page')
            ? add_query_arg('redirect_to', urlencode($back), um_get_core_page('login'))
            : wp_login_url($back);
        return '<div class="murvc-offer-box"><p><strong>Please log in to see your offer.</strong></p>'
            . '<p>Log in with the account the club emailed.</p>'
            . '<p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p></div>';
    }

    /** No ?offer=: the player's visible offers, newest first, one line each. */
    private static function render_list($user) {
        $offers = self::latest_per_team(TeamOversight_Offers::visible_for_player($user->ID));
        if (empty($offers)) {
            return '<div class="murvc-offer-box"><h3>Your offers</h3><p>' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_none')) . '</p></div>';
        }
        $html = '<div class="murvc-offer-box"><h3>Your offers</h3>';
        foreach ($offers as $offer) {
            $html .= '<div class="murvc-offer-row">'
                . '<span class="murvc-offer-row-name">' . esc_html(self::team_name($offer->season, $offer->team)) . ' <small>' . esc_html($offer->season) . ' · ' . esc_html(self::role_label($offer->role)) . '</small></span> '
                . self::pill($offer->state)
                . ' <a class="button" href="' . esc_url(TeamOversight_Offers::offer_url($offer)) . '">View</a>'
                . '</div>';
        }
        return $html . '</div>';
    }

    private static function pill($state, $early = false) {
        $label = self::state_label($state);
        if ($early && $state === 'sent') {
            $label = 'Early Offer sent';
        } elseif ($early && $state === 'accepted') {
            $label = 'Early Offer accepted: deposit due';
        }
        return '<span class="murvc-offer-pill murvc-offer-pill-' . esc_attr($state) . '">' . esc_html($label) . '</span>';
    }

    /**
     * What accepting will charge, on exactly the figures TeamOversight_Offers::player_accept()
     * recomputes (the stored basis, start, percent and class; only the
     * player's OTHER accepted or locked-in places can move it; after the
     * release the smaller of that and 10% of the real fee), so the figure on
     * the page is the one the accept compares in cents. Pure: nothing is written.
     * Returns array(target (money), places (labels of the other places
     * counted), amount (the season fee the target is a share of: what
     * accepting will store as quote_amount, never below the sent one)).
     */
    public static function accept_target($offer) {
        // The same calculation player_accept() writes (TeamOversight_Offers::accept_figures()), so the figure on the page is the figure stored.
        $figures = TeamOversight_Offers::accept_figures($offer);
        return array('target' => $figures['target'], 'places' => $figures['places'], 'amount' => $figures['amount']);
    }

    /**
     * The fee as the player is told it (TeamOversight_Offers::fee_words(), the
     * one source the email uses too). Once the real fee is known for an offer
     * quoted on the upper limit, the real figure leads and the quoted maximum
     * is named after it.
     */
    private static function fee_line($offer) {
        $words = TeamOversight_Offers::fee_words($offer);
        if ($words !== '' && $offer->quote_basis === 'upper' && $offer->quote_released !== null && $offer->quote_amount !== null
            && TeamOversight_Offers::to_cents($offer->quote_released) < TeamOversight_Offers::to_cents($offer->quote_amount)) {
            $words .= ' (we quoted up to ' . TeamOversight_Offers::money($offer->quote_amount) . ')';
        }
        return $words;
    }

    /**
     * The offer as the page should describe it: a copy whose quote_amount is
     * the season fee accepting will store, when the player's other places make
     * it dearer than the figure sent (so the fee, the deposit and the
     * checklist preview all tell one story). The stored offer is untouched.
     */
    private static function view_offer($offer, $amount) {
        if ($amount !== null && $offer->quote_amount !== null && TeamOversight_Offers::to_cents($amount) > TeamOversight_Offers::to_cents($offer->quote_amount)) {
            $view = clone $offer;
            $view->quote_amount = floatval($amount);
            return $view;
        }
        return $offer;
    }

    /** The deposit as the player is told it: "$61.20 (10% of your fee)" or the no-deposit sentence. */
    private static function deposit_words($offer, $target) {
        if (TeamOversight_Offers::to_cents($target) <= 0) {
            return 'None needed: your place is locked in as soon as you accept';
        }
        $pct = ($offer->deposit_percent !== null) ? ' (' . self::percent_text($offer->deposit_percent) . ' of your fee)' : '';
        return TeamOversight_Offers::money($target) . $pct;
    }

    /** One offer, in full. */
    private static function render_offer($user, $offer, $decline) {
        $name = self::team_name($offer->season, $offer->team);
        $state = $offer->state;
        $live_choice = in_array($state, array(TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED), true);

        $target = null;
        $places = array();
        $amount = null;
        if ($state === TeamOversight_Offers::SENT) {
            $at = self::accept_target($offer);
            $target = $at['target'];
            $places = $at['places'];
            $amount = $at['amount'];
        } elseif ($live_choice || $state === TeamOversight_Offers::LOCKED_IN) {
            $target = TeamOversight_Offers::effective_target($offer);
        }
        $status = TeamOversight_Offers::deadline_status($offer);
        // What the page describes: the fee accepting will store (a second place can make it dearer than the figure sent).
        $view = self::view_offer($offer, $amount);
        // An Early Offer (quoted on the upper limit, sent before fees were out): one helper decides, the page and the email say the same thing.
        $early = TeamOversight_Offers::is_early($offer);

        ob_start();
        ?>
        <div class="murvc-offer-box">
            <p class="murvc-offer-eyebrow"><?php echo esc_html($offer->season); ?> season <?php echo self::pill($state, $early); ?></p>
            <h3><?php echo $state === TeamOversight_Offers::SENT ? 'Your ' . esc_html(TeamOversight_Offers::offer_name($offer)) . ' to join ' : 'Your place with '; ?><?php echo esc_html($name); ?></h3>

            <?php if ($state === TeamOversight_Offers::SENT || $state === TeamOversight_Offers::ACCEPTED || $state === TeamOversight_Offers::LOCKED_IN): ?>
                <table class="murvc-offer-facts">
                    <tr><th>Team</th><td><?php echo esc_html($name); ?></td></tr>
                    <tr><th>Role</th><td><?php echo esc_html(self::role_label($offer->role)); ?></td></tr>
                    <tr><th>Season</th><td><?php echo esc_html($offer->season); ?></td></tr>
                    <?php $fee = self::fee_line($view); ?>
                    <?php if ($fee !== ''): ?>
                        <tr><th>Season fee</th><td><?php echo esc_html($fee); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($state === TeamOversight_Offers::LOCKED_IN): ?>
                        <tr><th>Deposit</th><td>Complete</td></tr>
                    <?php else: ?>
                        <tr><th>Deposit</th><td><?php echo esc_html(self::deposit_words($offer, $target)); ?></td></tr>
                        <?php if ($state === TeamOversight_Offers::SENT && $offer->reply_by): ?>
                            <tr class="<?php echo ($status['overdue'] && $status['kind'] === 'reply') ? 'murvc-offer-late' : ''; ?>"><th>Reply by</th><td><?php echo esc_html(self::fmt($offer->reply_by)); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($offer->deposit_due && TeamOversight_Offers::to_cents($target) > 0): ?>
                            <tr class="<?php echo ($status['overdue'] && $status['kind'] === 'deposit') ? 'murvc-offer-late' : ''; ?>"><th>Deposit due by</th><td><?php echo esc_html(self::fmt($offer->deposit_due)); ?></td></tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </table>
                <?php $early_note = TeamOversight_Offers::early_note($view); ?>
                <?php if ($early_note !== ''): ?>
                    <p class="murvc-offer-early"><strong>Early Offer.</strong> <?php echo esc_html($early_note); ?></p>
                <?php endif; ?>
                <?php if (!empty($places)): ?>
                    <p class="murvc-offer-note">Your fee and deposit count your other <?php echo count($places) > 1 ? 'places' : 'place'; ?> this season as well, because your fee is worked out on the highest-priced place you hold.</p>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            switch ($state) {
                case TeamOversight_Offers::SENT:
                    echo self::sent_block($user, $view, $name, $target, $status, $decline);
                    break;
                case TeamOversight_Offers::ACCEPTED:
                    echo self::accepted_block($user, $offer, $name, $target, $status, $decline);
                    break;
                case TeamOversight_Offers::LOCKED_IN:
                    echo self::locked_in_block($offer, $name);
                    break;
                default:
                    echo self::finished_block($offer, $name);
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** The commitments and the deposit policy: the three terms lists, the same text as the email. */
    private static function terms_html() {
        $html = '';
        foreach (TeamOversight_Offers::terms() as $group) {
            if (empty($group['items'])) {
                continue;
            }
            $html .= '<h4>' . esc_html($group['title']) . '</h4><ul>';
            foreach ($group['items'] as $item) {
                $html .= '<li>' . esc_html($item) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    private static function training_html($offer) {
        $url = TeamOversight_Offers::training_url($offer->season);
        if ($url === '') {
            return '';
        }
        return '<p class="murvc-offer-training">Training times and the season calendar are on the <a href="' . esc_url($url) . '">' . esc_html($offer->season) . ' Trials &amp; Team Info page</a>.</p>';
    }

    /**
     * The one extra training / extra games line, by the player's age at the
     * season start (under 18 the Junior Academy, 18 and over the Reds Social
     * League): the same line and wording as the offer email. '' when the page
     * it points to isn't published. The viewer owns the offer, so it is read
     * for the offer's own account.
     */
    private static function extras_html($offer) {
        $line = TeamOversight_Offers::extras_line($offer->user_id, $offer->season);
        if (empty($line['html'])) {
            return '';
        }
        return '<p class="murvc-offer-extras" data-extras="' . esc_attr($line['kind']) . '">' . $line['html'] . '</p>';
    }

    private static function preview_html($user, $offer, $target) {
        if (!class_exists('TeamOversight_Readiness')) {
            return '';
        }
        try {
            $html = TeamOversight_Readiness::render_offer_preview($user, $offer, $target);
        } catch (\Throwable $e) {
            $html = '';
        }
        if ($html === '') {
            return '';
        }
        return '<div class="murvc-offer-preview"><p class="murvc-offer-note">' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_preview_note')) . '</p>' . $html . '</div>';
    }

    /** A sent offer: the commitments, the checklist preview, Accept, and Decline (or its confirm panel). */
    private static function sent_block($user, $offer, $name, $target, $status, $decline) {
        ob_start();
        if ($decline) {
            echo self::decline_panel($offer, $name);
        }
        if ($status['overdue'] && $status['kind'] === 'reply') {
            echo '<p class="murvc-offer-note murvc-offer-note-late">' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_late_reply')) . '</p>';
        }
        echo '<div class="murvc-offer-terms">' . self::terms_html() . '</div>';
        echo self::training_html($offer);
        echo self::extras_html($offer);
        echo self::preview_html($user, $offer, $target);
        if (!$decline) {
            ?>
            <form method="post" class="murvc-offer-accept murvc-offer-once">
                <input type="hidden" name="<?php echo esc_attr(self::ACTION_FIELD); ?>" value="accept">
                <input type="hidden" name="<?php echo esc_attr(self::ID_FIELD); ?>" value="<?php echo intval($offer->id); ?>">
                <input type="hidden" name="<?php echo esc_attr(self::NONCE_FIELD); ?>" value="<?php echo esc_attr(wp_create_nonce('murvc_offer_accept_' . $offer->id)); ?>">
                <input type="hidden" name="shown_state" value="sent">
                <input type="hidden" name="shown_target" value="<?php echo esc_attr(number_format((float) $target, 2, '.', '')); ?>">
                <p><?php echo TeamOversight_Offer_Wording::e(TeamOversight_Offers::to_cents($target) > 0
                    ? TeamOversight_Offers::wording('page_consent_deposit', array('{deposit}' => TeamOversight_Offers::money($target), '{by_date}' => $offer->deposit_due ? ' by ' . self::fmt($offer->deposit_due) : ''))
                    : TeamOversight_Offers::wording('page_consent')); ?></p>
                <p>
                    <button type="submit" class="button button-primary murvc-offer-accept-button">Accept and commit</button>
                    <a class="murvc-offer-decline-link" href="<?php echo esc_url(add_query_arg('decline', 1, TeamOversight_Offers::offer_url($offer))); ?>">Decline this offer</a>
                </p>
            </form>
            <?php
        }
        return ob_get_clean();
    }

    /**
     * An accepted offer: the deposit box (or the decline confirm), the
     * commitments, and the way on to the Player Checklist, which is live
     * from the moment the offer is accepted (1.79.0). No read-only preview
     * here (1.79.1): its "If you accept" heading and "nothing to tick here"
     * note are for an offer still to be answered.
     */
    private static function accepted_block($user, $offer, $name, $target, $status, $decline) {
        ob_start();
        if ($decline) {
            echo self::decline_panel($offer, $name);
        } else {
            echo '<p class="murvc-offer-lead">' . TeamOversight_Offer_Wording::html('page_accepted_lead', array('{by_date}' => $offer->deposit_due ? ' by ' . self::fmt($offer->deposit_due) : '')) . '</p>';
            echo self::render_deposit_box($offer);
            echo '<p><a class="murvc-offer-decline-link" href="' . esc_url(add_query_arg('decline', 1, TeamOversight_Offers::offer_url($offer))) . '">Decline this offer</a></p>';
        }
        echo '<details class="murvc-offer-more"><summary>The commitments and the deposit policy</summary><div class="murvc-offer-terms">' . self::terms_html() . '</div></details>';
        echo self::training_html($offer);
        echo self::extras_html($offer);
        echo '<div class="murvc-offer-checklist"><p class="murvc-offer-note">' . TeamOversight_Offer_Wording::html('page_accepted_checklist') . '</p>'
            . '<p><a class="button" href="' . esc_url(TeamOversight_Readiness::checklist_url()) . '">' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_checklist_button')) . '</a></p></div>';
        return ob_get_clean();
    }

    /** A locked-in offer: the confirmation and the way on to the Player Checklist. */
    private static function locked_in_block($offer, $name) {
        $html = '<p class="murvc-offer-lead">' . TeamOversight_Offer_Wording::html('page_locked_lead', array('{team}' => $name)) . ' ';
        $html .= TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording($offer->placed_at ? 'page_locked_placed' : 'page_locked_placing')) . '</p>';
        $invoice = TeamOversight_Offers::invoice_for($offer);
        if ($invoice) {
            try {
                $stage = TeamOversight_Offers::deposit_stage($invoice);
            } catch (\Throwable $e) {
                $stage = null;
            }
            if (is_array($stage) && !empty($stage['words'])) {
                $html .= '<p class="murvc-offer-note">' . esc_html($stage['words']) . '</p>';
            }
        }
        $html .= '<p><a class="button button-primary" href="' . esc_url(TeamOversight_Readiness::checklist_url()) . '">' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_checklist_button')) . '</a></p>';
        $html .= '<details class="murvc-offer-more"><summary>The commitments and the deposit policy</summary><div class="murvc-offer-terms">' . self::terms_html() . '</div></details>';
        return $html . self::training_html($offer) . self::extras_html($offer);
    }

    /** What happened to the deposit of a finished offer, in the player's words ('' when nothing was paid). */
    private static function money_line($offer) {
        $amount = ($offer->settled_amount !== null) ? floatval($offer->settled_amount) : 0.0;
        switch ((string) $offer->settlement) {
            case 'forfeited':
                return $amount > 0
                    ? TeamOversight_Offers::wording('page_money_forfeited', array('{amount}' => TeamOversight_Offers::money($amount)))
                    : '';
            case 'credit':
                return $amount > 0
                    ? TeamOversight_Offers::wording('page_money_credit', array('{amount}' => TeamOversight_Offers::money($amount)))
                    : TeamOversight_Offers::wording('page_money_credit_unknown');
            case 'pending':
                return TeamOversight_Offers::wording('page_money_pending');
            case 'carried':
                return TeamOversight_Offers::wording('page_money_carried');
        }
        return '';
    }

    /** A finished offer (declined, closed, withdrawn, left): one line on what happened, and the money. */
    private static function finished_block($offer, $name) {
        switch ($offer->state) {
            case TeamOversight_Offers::DECLINED:
                $line = TeamOversight_Offers::wording('page_declined', array('{on_date}' => $offer->declined_at ? ' on ' . self::fmt($offer->declined_at) : ''));
                $more = TeamOversight_Offers::wording('page_declined_more');
                break;
            case TeamOversight_Offers::MOVED_DECLINED:
                $line = TeamOversight_Offers::wording('page_closed', array('{on_date}' => $offer->closed_at ? ' on ' . self::fmt($offer->closed_at) : ''));
                $more = TeamOversight_Offers::wording('page_closed_more');
                break;
            case TeamOversight_Offers::WITHDRAWN:
                $line = TeamOversight_Offers::wording('page_withdrawn', array('{on_date}' => $offer->closed_at ? ' on ' . self::fmt($offer->closed_at) : ''));
                $more = TeamOversight_Offers::wording('page_withdrawn_more');
                break;
            default:
                $line = TeamOversight_Offers::wording('page_left', array('{team}' => $name, '{season}' => $offer->season));
                $more = TeamOversight_Offers::wording('page_left_more');
        }
        $html = '<p class="murvc-offer-lead">' . esc_html($line) . '</p>';
        $money = self::money_line($offer);
        if ($money === '') {
            // An ended offer the club is still deciding about: the money words of its holding invoice.
            $invoice = ($offer->settlement === 'pending') ? TeamOversight_Offers::invoice_for($offer) : null;
            if ($invoice) {
                try {
                    $stage = TeamOversight_Offers::deposit_stage($invoice);
                } catch (\Throwable $e) {
                    $stage = null;
                }
                $money = (is_array($stage) && !empty($stage['words'])) ? (string) $stage['words'] : '';
            }
        }
        if ($money !== '') {
            $html .= '<p class="murvc-offer-note">' . esc_html($money) . '</p>';
        }
        return $html . '<p>' . esc_html($more) . '</p>';
    }

    /** The decline confirm: a read-only panel (nothing is written by opening it) with an optional reason and a nonced button. */
    private static function decline_panel($offer, $name) {
        $held = 0;
        if ($offer->state === TeamOversight_Offers::ACCEPTED) {
            $held = max(0, intval(TeamOversight_Offers::held_for_offer($offer)));
        }
        ob_start();
        ?>
        <div class="murvc-offer-decline-panel">
            <h4>Decline this offer?</h4>
            <p><?php echo TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('decline_intro', array('{team}' => $name, '{season}' => (string) $offer->season))); ?></p>
            <?php if ($held > 0): ?>
                <p><?php echo TeamOversight_Offer_Wording::html('decline_warning', array('{held}' => self::money_cents($held))); ?></p>
            <?php endif; ?>
            <form method="post" class="murvc-offer-once">
                <input type="hidden" name="<?php echo esc_attr(self::ACTION_FIELD); ?>" value="decline">
                <input type="hidden" name="<?php echo esc_attr(self::ID_FIELD); ?>" value="<?php echo intval($offer->id); ?>">
                <input type="hidden" name="<?php echo esc_attr(self::NONCE_FIELD); ?>" value="<?php echo esc_attr(wp_create_nonce('murvc_offer_decline_' . $offer->id)); ?>">
                <input type="hidden" name="shown_state" value="<?php echo esc_attr($offer->state); ?>">
                <p>
                    <label for="murvc-offer-reason">Tell us why (optional)</label><br>
                    <textarea name="<?php echo esc_attr(self::REASON_FIELD); ?>" id="murvc-offer-reason" rows="3" maxlength="500" style="width:100%;max-width:480px;"></textarea>
                </p>
                <p>
                    <button type="submit" class="button button-primary murvc-offer-danger">Yes, decline this offer</button>
                    <a class="murvc-offer-decline-link" href="<?php echo esc_url(TeamOversight_Offers::offer_url($offer)); ?>">No, go back</a>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // The deposit box
    // ------------------------------------------------------------------

    /**
     * The deposit pay box for an accepted offer (HTML), on the offer page and
     * the Player Checklist: "Deposit $43.75, $31.25 to go" and one button that
     * pays exactly what is left, through
     * TeamOversight_Payments::render_pay_box() with the options of the
     * contract in design/offer-api.md section 6.11 (the field names are
     * TeamOversight_Offers::DEPOSIT_FORM). The deposit is paid in ONE payment
     * (club decision, 28 Oct 2026): there is no choice of amount and no part payment,
     * and a two-team top-up is just the difference. Nothing is drawn for any
     * state but `accepted`. With nothing left to pay it says so instead of
     * showing a box, and with no payment product it says online payment isn't
     * available. A pure reader.
     */
    public static function render_deposit_box($offer) {
        if (!is_object($offer) || $offer->state !== TeamOversight_Offers::ACCEPTED) {
            return '';
        }
        $target_cents = TeamOversight_Offers::to_cents(TeamOversight_Offers::effective_target($offer));
        $remaining = max(0, intval(TeamOversight_Offers::remaining_cents($offer)));
        $invoice = TeamOversight_Offers::invoice_for($offer);
        $paid = $invoice ? max(0, intval(TeamOversight_Offers::free_paid_cents($invoice))) : 0;
        $status = TeamOversight_Offers::deadline_status($offer);

        $html = '<div class="murvc-offer-deposit">';
        if ($remaining <= 0) {
            $html .= '<p class="murvc-offer-deposit-line">' . TeamOversight_Offer_Wording::html('page_deposit_paid', array('{deposit}' => self::money_cents($target_cents))) . '</p>';
            return $html . '</div>';
        }
        // 1.79.1: every sentence of the box comes from the wording registry (the built-in text is what was here before).
        $amount_vars = array('{deposit}' => self::money_cents($target_cents), '{remaining}' => self::money_cents($remaining));
        if ($paid > 0) {
            $amount_vars['{paid}'] = self::money_cents(min($paid, $target_cents));
        }
        $html .= '<p class="murvc-offer-deposit-line">' . TeamOversight_Offer_Wording::html($paid > 0 ? 'page_deposit_amount_paid' : 'page_deposit_amount', $amount_vars) . '</p>';
        if ($status['overdue'] && $status['kind'] === 'deposit') {
            $html .= '<p class="murvc-offer-note murvc-offer-note-late">' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_deposit_late', array('{date}' => self::fmt($status['date'])))) . '</p>';
        } elseif ($offer->deposit_due) {
            $html .= '<p class="murvc-offer-note">' . TeamOversight_Offer_Wording::html('page_deposit_due', array('{date}' => self::fmt($offer->deposit_due))) . '</p>';
        }
        if (!TeamOversight_Payments::get_payment_product()) {
            return $html . '<p><em>' . TeamOversight_Offer_Wording::e(TeamOversight_Offers::wording('page_no_payment')) . '</em></p></div>';
        }
        $form = TeamOversight_Offers::DEPOSIT_FORM;
        $html .= TeamOversight_Payments::render_pay_box($remaining / 100, 0, 'murvc-offer-pay-form', array(
            'action' => $form['action_value'],
            'nonce_action' => TeamOversight_Offers::deposit_pay_nonce_action($offer->id),
            'hidden' => array($form['offer_field'] => intval($offer->id)),
            'amount' => $remaining / 100,
            // Plain text there (the pay box escapes it), so a **bold** marker is dropped.
            'heading' => str_replace('**', '', TeamOversight_Offers::wording('page_deposit_heading')),
            'intro' => str_replace('**', '', TeamOversight_Offers::wording('page_deposit_intro')),
        ));
        return $html . '</div>';
    }

    // ------------------------------------------------------------------
    // The Player Checklist banner and the trial form line
    // ------------------------------------------------------------------

    /** The id of an accepted offer's card (with its deposit box) on the Player Checklist page, for the checklist's "Go to your deposit" link. */
    public static function deposit_anchor($offer) {
        return 'murvc-offer-deposit-' . intval(is_object($offer) && isset($offer->id) ? $offer->id : 0);
    }

    /**
     * The offers banner above [ready_to_play]'s season panels, for a logged-in
     * player (HTML, '' when there is nothing to say): a short card per offer
     * that needs them or that the club ended, with a link to the offer page,
     * and for an accepted offer the deposit step with the deposit pay box.
     * Live offers (sent, accepted, locked in but not placed yet) always show;
     * an offer the club withdrew, closed or ended shows for
     * FINISHED_BANNER_DAYS days; one the player declined themselves, and a
     * locked-in player who has a place (their panel is the checklist), never.
     * An accepted offer's card carries the id deposit_anchor() gives, which
     * the checklist's deposit step links to: the deposit box is drawn here
     * and nowhere else on the page.
     */
    public static function render_checklist_banner($user) {
        if (!is_object($user) || empty($user->ID)) {
            return '';
        }
        $cards = '';
        $cutoff = strtotime('-' . self::FINISHED_BANNER_DAYS . ' days', current_time('timestamp'));
        foreach (self::latest_per_team(TeamOversight_Offers::visible_for_player($user->ID)) as $offer) {
            // 1.79.1: every sentence comes from the wording registry (plain values in, escaped once by html()).
            $team = array('{team}' => self::team_name($offer->season, $offer->team));
            $season = esc_html($offer->season);
            $link = esc_url(TeamOversight_Offers::offer_url($offer));
            switch ($offer->state) {
                case TeamOversight_Offers::SENT:
                    $status = TeamOversight_Offers::deadline_status($offer);
                    $cards .= '<div class="murvc-offer-card murvc-offer-card-sent"><p class="murvc-offer-card-head"><strong>' . TeamOversight_Offer_Wording::html('banner_sent_head', array_merge($team, array('{offer_name}' => TeamOversight_Offers::offer_name($offer)))) . '</strong> <small>' . $season . '</small></p>'
                        . '<p>' . TeamOversight_Offer_Wording::html($status['overdue'] ? 'banner_sent_late' : 'banner_sent_reply', array('{date}' => self::fmt($offer->reply_by)))
                        . ' <a class="button button-primary" href="' . $link . '">See your offer</a></p></div>';
                    break;
                case TeamOversight_Offers::ACCEPTED:
                    // The anchor the checklist's deposit step links to (TeamOversight_Readiness::season_panel()).
                    $cards .= '<div class="murvc-offer-card murvc-offer-card-accepted" id="' . esc_attr(self::deposit_anchor($offer)) . '"><p class="murvc-offer-card-head"><strong>' . TeamOversight_Offer_Wording::html('banner_accepted_head', $team) . '</strong> <small>' . $season . '</small></p>'
                        . self::render_deposit_box($offer)
                        . '<p><a href="' . $link . '">Your offer</a></p></div>';
                    break;
                case TeamOversight_Offers::LOCKED_IN:
                    if (!$offer->placed_at) {
                        $cards .= '<div class="murvc-offer-card murvc-offer-card-locked"><p class="murvc-offer-card-head"><strong>' . TeamOversight_Offer_Wording::html('banner_locked_head', $team) . '</strong> <small>' . $season . '</small></p>'
                            . '<p>' . TeamOversight_Offer_Wording::html('banner_locked_placing') . ' <a href="' . $link . '">Your offer</a></p></div>';
                    }
                    break;
                case TeamOversight_Offers::WITHDRAWN:
                case TeamOversight_Offers::MOVED_DECLINED:
                case TeamOversight_Offers::LEFT:
                    $ended = strtotime((string) $offer->closed_at);
                    if ($ended && $ended >= $cutoff) {
                        $key = $offer->state === TeamOversight_Offers::WITHDRAWN ? 'banner_withdrawn' : ($offer->state === TeamOversight_Offers::LEFT ? 'banner_left' : 'banner_closed');
                        $cards .= '<div class="murvc-offer-card murvc-offer-card-ended"><p>' . TeamOversight_Offer_Wording::html($key, $team) . ' <small>' . $season . '</small> <a href="' . $link . '">Details</a></p></div>';
                    }
                    break;
            }
        }
        if ($cards === '') {
            return '';
        }
        // The banner carries fresh nonces and the player's own figures: never cache the page it is on.
        self::no_cache();
        return '<div class="murvc-offers-banner">' . self::take_flash($user->ID) . $cards . '</div>' . self::styles();
    }

    /**
     * One line for the [team_trial_form] status panel when the player has a
     * live offer for the application's season (HTML, '' when none): a link to
     * the offer, so a player who lost the email can find it.
     */
    public static function trial_form_line($user_id, $season) {
        if (intval($user_id) <= 0 || !preg_match('/^\d{4}$/D', (string) $season)) {
            return '';
        }
        $live = self::latest_per_team(TeamOversight_Offers::for_user($user_id, (string) $season, array(
            TeamOversight_Offers::SENT, TeamOversight_Offers::ACCEPTED, TeamOversight_Offers::LOCKED_IN,
        )));
        if (empty($live)) {
            return '';
        }
        $style = ' style="margin:10px 0 0;"';
        if (count($live) > 1) {
            return '<p class="trial-offer-line"' . $style . '><strong>You have ' . count($live) . ' team offers.</strong> <a href="' . esc_url(TeamOversight_Offers::offer_page_url()) . '">See your offers</a></p>';
        }
        $offer = $live[0];
        $name = esc_html(self::team_name($offer->season, $offer->team));
        $link = esc_url(TeamOversight_Offers::offer_url($offer));
        if ($offer->state === TeamOversight_Offers::ACCEPTED) {
            return '<p class="trial-offer-line"' . $style . '><strong>You accepted your ' . esc_html(TeamOversight_Offers::offer_name($offer)) . ' to join ' . $name . '.</strong> <a href="' . $link . '">Pay your deposit</a> to lock in your place.</p>';
        }
        if ($offer->state === TeamOversight_Offers::LOCKED_IN) {
            return '<p class="trial-offer-line"' . $style . '><strong>You are locked in for ' . $name . '.</strong> <a href="' . $link . '">See your offer</a></p>';
        }
        return '<p class="trial-offer-line"' . $style . '><strong>You have an ' . esc_html(TeamOversight_Offers::offer_name($offer)) . ' to join ' . $name . '.</strong> <a href="' . $link . '">See your offer</a></p>';
    }

    // ------------------------------------------------------------------
    // Style (printed once per request)
    // ------------------------------------------------------------------

    private static function styles() {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;
        return '<style>
        .murvc-offer-page, .murvc-offers-banner { max-width: 640px; margin: 0 0 24px 0; }
        .murvc-offer-box, .murvc-offer-card { border: 2px solid #e0e0e0; border-radius: 8px; padding: 18px 20px; background: #f9f9f9; margin: 0 0 16px 0; }
        .murvc-offer-card { padding: 12px 16px; background: #fff; border-left: 4px solid #f0b429; }
        .murvc-offer-card-accepted { border-left-color: #2271b1; }
        .murvc-offer-card-locked { border-left-color: #46b450; }
        .murvc-offer-card-ended { border-left-color: #999; }
        .murvc-offer-card p { margin: 6px 0; }
        .murvc-offer-box h3 { margin: 4px 0 12px 0; }
        .murvc-offer-box h4 { margin: 18px 0 6px 0; }
        .murvc-offer-eyebrow { margin: 0; color: #555; font-size: 13px; }
        .murvc-offer-pill { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; background: #fff4e5; color: #8a4b08; border: 1px solid #f5c58b; margin-left: 6px; }
        .murvc-offer-pill-accepted { background: #e8f1fb; color: #134e85; border-color: #b7d3ef; }
        .murvc-offer-pill-locked_in { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .murvc-offer-pill-declined, .murvc-offer-pill-moved_declined, .murvc-offer-pill-withdrawn, .murvc-offer-pill-left { background: #eee; color: #444; border-color: #ccc; }
        .murvc-offer-facts { border-collapse: collapse; width: 100%; margin: 6px 0 10px 0; }
        .murvc-offer-facts th { text-align: left; font-weight: 600; color: #444; padding: 6px 12px 6px 0; width: 38%; vertical-align: top; border-bottom: 1px solid #e8e8e8; }
        .murvc-offer-facts td { padding: 6px 0; border-bottom: 1px solid #e8e8e8; }
        .murvc-offer-late th, .murvc-offer-late td { color: #a00; }
        .murvc-offer-note { color: #555; font-size: 14px; margin: 8px 0; }
        .murvc-offer-note-late { color: #a00; }
        .murvc-offer-early { background: #fff4e5; border: 1px solid #f5c58b; border-radius: 6px; padding: 10px 14px; margin: 10px 0; font-size: 15px; }
        .murvc-offer-lead { font-size: 16px; margin: 8px 0; }
        .murvc-offer-terms ul { margin: 4px 0 4px 20px; }
        .murvc-offer-more { margin: 14px 0; }
        .murvc-offer-more summary { cursor: pointer; font-weight: 600; }
        .murvc-offer-preview { margin: 18px 0; }
        .murvc-offer-preview .rtp-panel { max-width: none; }
        .murvc-offer-accept { margin-top: 18px; padding-top: 14px; border-top: 1px solid #ddd; }
        .murvc-offer-decline-link { margin-left: 14px; font-size: 14px; }
        .murvc-offer-decline-panel { border: 2px solid #dc3232; background: #fdf0f0; border-radius: 6px; padding: 12px 16px; margin: 12px 0; }
        .murvc-offer-danger { background: #a00 !important; border-color: #a00 !important; color: #fff !important; }
        .murvc-offer-deposit { margin: 8px 0; }
        .murvc-offer-deposit-line { margin: 6px 0; font-size: 15px; }
        .murvc-offer-notice { border-radius: 6px; padding: 10px 14px; margin: 0 0 14px 0; font-weight: 600; }
        .murvc-offer-notice-ok { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .murvc-offer-notice-err { background: #fdf0f0; border: 1px solid #f0b8b8; color: #8a1f1f; }
        .murvc-offer-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid #e8e8e8; }
        .murvc-offer-row-name { flex: 1 1 200px; }
        @media (max-width: 480px) { .murvc-offer-box { padding: 14px; } .murvc-offer-facts th { width: 44%; } }
        </style>
        <script>
        (function () {
            document.addEventListener("submit", function (e) {
                var form = e.target;
                if (!form || !form.classList || !form.classList.contains("murvc-offer-once")) { return; }
                var buttons = form.querySelectorAll("button[type=submit]");
                setTimeout(function () { for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = true; } }, 0);
            });
        })();
        </script>';
    }
}
