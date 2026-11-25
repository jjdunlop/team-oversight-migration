<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep it up on the website (1.98.0): the [club_keep_it_up] shortcode (the
 * start card and the leaderboard; compact="1" for the 404 page) and the
 * footer link. The game, the boards and every request are drawn by
 * assets/keep-it-up.js from the routes of TeamOversight_Keep_It_Up.
 *
 * The page a guest sees is the same for every guest (the leaderboard and the
 * guest's best arrive in the browser), so it stays cacheable. A logged-in
 * viewer's render carries a wp_rest nonce and defines DONOTCACHEPAGE (the
 * site's page cache skips logged-in requests anyway).
 */
class TeamOversight_Keep_It_Up_Public {

    const SHORTCODE = 'club_keep_it_up';
    const ATTS = array('compact' => '');
    /** The game script's handle (assets/keep-it-up.js, in the footer, the plugin version). */
    const SCRIPT = 'murvc-keep-it-up';
    /** Before the theme's credit line (Poseidon adds it at 10). */
    const FOOTER_PRIORITY = 5;
    const HOW = 'Tap the ball to keep it up. Hit it in the middle to send it straight up. Don\'t let it touch the floor.';

    /** What has been printed this request (styles): once. */
    private static $printed = array();

    /** Added once, from team_oversight_init(): the shortcode on init, the footer link. */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (did_action('init')) {
            self::register_shortcode();
        } else {
            add_action('init', array(__CLASS__, 'register_shortcode'));
        }
        add_action('poseidon_footer_text', array(__CLASS__, 'print_footer_link'), self::FOOTER_PRIORITY);
    }

    public static function register_shortcode() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));
    }

    public static function ball_url() {
        return TEAM_OVERSIGHT_PLUGIN_URL . 'assets/keep-it-up-ball.svg';
    }

    // ------------------------------------------------------------------
    // The footer link
    // ------------------------------------------------------------------

    /** poseidon_footer_text, priority 5: the link, before the credit. */
    public static function print_footer_link() {
        echo self::footer_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped below.
    }

    /**
     * A small volleyball and "Play Keep it up", linking to the game page;
     * '' unless the game is on and its page is set and published.
     */
    public static function footer_html() {
        if (is_admin() || !TeamOversight_Keep_It_Up::is_on()) {
            return '';
        }
        $url = TeamOversight_Keep_It_Up::page_url();
        if ($url === '') {
            return '';
        }
        return '<span class="murvc-kiu-footer" style="display:inline-block;margin:0 1.5em 0 0;white-space:nowrap">'
            . '<a href="' . esc_url($url) . '">'
            . '<img src="' . esc_url(self::ball_url()) . '" alt="" width="16" height="16" style="display:inline-block;width:16px;height:16px;margin:0 6px 0 0;vertical-align:-3px;border:0;box-shadow:none">'
            . 'Play Keep it up</a></span>';
    }

    // ------------------------------------------------------------------
    // The shortcode
    // ------------------------------------------------------------------

    /** compact="1" (also yes or true) draws the 404 page's small card. */
    public static function is_compact($atts) {
        $atts = shortcode_atts(self::ATTS, is_array($atts) ? $atts : array(), self::SHORTCODE);
        $v = strtolower(trim(is_scalar($atts['compact']) ? (string) $atts['compact'] : ''));
        return in_array($v, array('1', 'yes', 'true'), true);
    }

    /**
     * [club_keep_it_up] and [club_keep_it_up compact="1"]. Returns the HTML,
     * never echoes; '' inside wp_head (so a thrown-away render can't use up
     * the print-once styles), while the game is off, or before the table
     * exists. Writes nothing.
     */
    public static function render_shortcode($atts = array()) {
        if (doing_action('wp_head') || !TeamOversight_Keep_It_Up::is_on() || !TeamOversight_Keep_It_Up::tables_exist()) {
            return '';
        }
        $compact = self::is_compact($atts);
        $user_id = get_current_user_id();
        if ($user_id > 0 && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        wp_enqueue_script(self::SCRIPT, TEAM_OVERSIGHT_PLUGIN_URL . 'assets/keep-it-up.js', array(), TEAM_OVERSIGHT_VERSION, true);
        $config = self::config($compact, $user_id);
        $ball = '<img class="murvc-kiu-icon" src="' . esc_url(self::ball_url()) . '" alt="" width="' . ($compact ? 56 : 80) . '" height="' . ($compact ? 56 : 80) . '">';
        $play = '<p class="murvc-kiu-row"><button type="button" class="murvc-kiu-play" disabled>Play</button> <span class="murvc-kiu-best" hidden></span></p>';
        $noscript = '<noscript><p class="murvc-kiu-small">The game needs JavaScript.</p></noscript>';

        $html = '<div class="murvc-kiu murvc-kiu--' . ($compact ? 'compact' : 'full') . '" data-config="' . esc_attr(wp_json_encode($config)) . '">';
        $html .= '<div class="murvc-kiu-card">' . $ball . '<div class="murvc-kiu-text">';
        if ($compact) {
            $html .= '<p class="murvc-kiu-lead">Keep this one up instead.</p>';
        }
        $html .= '<p class="murvc-kiu-how">' . esc_html(self::HOW) . '</p>' . $play;
        if (!$compact) {
            if ($user_id > 0) {
                $html .= '<p class="murvc-kiu-show"><label><input type="checkbox" class="murvc-kiu-show-box"' . (TeamOversight_Keep_It_Up::is_hidden($user_id) ? '' : ' checked') . ' disabled> Show me on the leaderboard</label> <span class="murvc-kiu-show-note" role="status"></span></p>';
            } else {
                $html .= '<p class="murvc-kiu-small"><a href="' . esc_url($config['login']) . '">Log in</a> to get on the leaderboard.</p>';
            }
            $html .= '<p class="murvc-kiu-small">On a keyboard, Space or Enter hits the ball while it\'s falling low.</p>';
        }
        $html .= $noscript . '</div></div>';
        $html .= '<div class="murvc-kiu-board" role="region" aria-label="' . esc_attr($compact ? 'Top 3 this month' : 'Leaderboard') . '">'
            . '<h2 class="murvc-kiu-board-title">' . esc_html($compact ? 'Top 3 this month' : 'Leaderboard') . '</h2>'
            . '<div class="murvc-kiu-board-body"><p class="murvc-kiu-status">Loading the leaderboard...</p></div>';
        if ($compact && $config['page'] !== '') {
            $html .= '<p class="murvc-kiu-more"><a href="' . esc_url($config['page']) . '">Full leaderboard</a></p>';
        }
        $html .= '</div></div>';
        return self::styles() . $html;
    }

    /**
     * What the script needs, as the data-config attribute: the routes, the
     * nonce (logged-in viewers only), the login link with a return here
     * (guests), the game page (the compact card's Full leaderboard), the
     * ball's address and the board size. Nothing about who is looking
     * beyond logged in or not, and whether they are shown.
     */
    public static function config($compact, $user_id) {
        $user_id = intval($user_id);
        $page = TeamOversight_Keep_It_Up::page_url();
        $back = '';
        if (!$compact && is_singular()) {
            $back = (string) get_permalink(get_queried_object_id());
        }
        if ($back === '') {
            $back = $page !== '' ? $page : home_url('/');
        }
        if ($user_id <= 0) {
            $login = class_exists('TeamOversight_Club_Documents_Route') ? TeamOversight_Club_Documents_Route::login_url($back) : wp_login_url($back);
        } else {
            $login = '';
        }
        return array(
            'compact' => (bool) $compact,
            'limit' => $compact ? TeamOversight_Keep_It_Up::COMPACT_SIZE : TeamOversight_Keep_It_Up::BOARD_SIZE,
            'board' => TeamOversight_Keep_It_Up::route_url('board'),
            'start' => TeamOversight_Keep_It_Up::route_url('start'),
            'finish' => TeamOversight_Keep_It_Up::route_url('finish'),
            'settings' => TeamOversight_Keep_It_Up::route_url('settings'),
            'nonce' => $user_id > 0 ? wp_create_nonce('wp_rest') : '',
            'member' => $user_id > 0,
            'shown' => $user_id > 0 ? !TeamOversight_Keep_It_Up::is_hidden($user_id) : true,
            'login' => $login,
            'page' => $compact ? $page : '',
            'ball' => self::ball_url(),
        );
    }

    /**
     * The start card's, the board's and the game layer's styles, once per
     * request. Colours from the theme's presets with the club's colours as
     * fallbacks; buttons styled here (the theme restyles .button and
     * button); the layer covers the window above the site's header.
     */
    public static function styles() {
        if (!empty(self::$printed['styles'])) {
            return '';
        }
        self::$printed['styles'] = true;
        return '<style>
        .murvc-kiu{--kiu-red:var(--wp--preset--color--accent-1,#c8102e);--kiu-blue:var(--wp--preset--color--accent-2,#1f4e9c);--kiu-navy:var(--wp--preset--color--accent-3,#0f2a52);--kiu-muted:var(--wp--preset--color--accent-4,#5b6474);--kiu-bg:var(--wp--preset--color--accent-5,#f3f5f8);--kiu-border:var(--wp--preset--color--accent-6,#d9dee6);--kiu-tint:#fbe9ec;margin:16px 0;color:var(--kiu-navy)}
        .murvc-kiu [hidden]{display:none!important}
        .murvc-kiu-card{display:flex;gap:16px;align-items:center;background:var(--kiu-bg);border-left:5px solid var(--kiu-red);border-radius:10px;padding:16px 18px}
        .murvc-kiu-icon{flex:0 0 auto;width:80px;height:80px;border:0;box-shadow:none;animation:murvc-kiu-bob 2.4s ease-in-out infinite}
        .murvc-kiu--compact .murvc-kiu-icon{width:56px;height:56px}
        @keyframes murvc-kiu-bob{0%,100%{transform:translateY(0) rotate(0)}50%{transform:translateY(-6px) rotate(12deg)}}
        .murvc-kiu-text{flex:1 1 auto;min-width:0}
        .murvc-kiu-text p{margin:0 0 8px}
        .murvc-kiu-text p:last-child{margin-bottom:0}
        .murvc-kiu-lead{font-weight:700;font-size:1.1em}
        .murvc-kiu-small{font-size:14px;color:var(--kiu-muted)}
        .murvc-kiu-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px}
        .murvc-kiu button.murvc-kiu-play{font:inherit;font-size:17px;font-weight:700;line-height:1.2;text-transform:none;letter-spacing:normal;margin:0;padding:10px 28px;border:0;border-radius:24px;background:var(--kiu-red);color:#fff;cursor:pointer;box-shadow:none}
        .murvc-kiu button.murvc-kiu-play:hover,.murvc-kiu button.murvc-kiu-play:focus-visible{background:#a50d26;color:#fff}
        .murvc-kiu button.murvc-kiu-play:focus-visible{outline:3px solid var(--kiu-blue);outline-offset:2px}
        .murvc-kiu button.murvc-kiu-play:disabled{opacity:.55;cursor:default}
        .murvc-kiu-best{font-weight:600}
        .murvc-kiu-show label{cursor:pointer}
        .murvc-kiu-show-note{font-size:14px;color:var(--kiu-muted)}
        .murvc-kiu-board{margin:16px 0 0}
        .murvc-kiu h2.murvc-kiu-board-title{margin:0 0 8px;font-size:1.25em}
        .murvc-kiu-month{margin:0 0 8px;font-size:14px;color:var(--kiu-muted)}
        .murvc-kiu-tabs{display:flex;gap:8px;margin:0 0 10px}
        .murvc-kiu button.murvc-kiu-tab{font:inherit;font-size:14px;line-height:1.2;text-transform:none;letter-spacing:normal;margin:0;padding:6px 14px;border-radius:20px;border:2px solid var(--kiu-blue);background:#fff;color:var(--kiu-blue);cursor:pointer;box-shadow:none}
        .murvc-kiu button.murvc-kiu-tab[aria-selected=true]{background:var(--kiu-blue);color:#fff}
        .murvc-kiu button.murvc-kiu-tab:focus-visible{outline:3px solid var(--kiu-navy);outline-offset:2px}
        .murvc-kiu ol.murvc-kiu-list{list-style:none;margin:0;padding:0;max-width:30rem}
        .murvc-kiu-list li{display:flex;gap:10px;align-items:baseline;margin:0;padding:7px 10px;border-bottom:1px solid var(--kiu-border)}
        .murvc-kiu-list li.is-me{background:var(--kiu-tint);border-left:4px solid var(--kiu-red);font-weight:700}
        .murvc-kiu-place{flex:0 0 2.6em;font-weight:700;color:var(--kiu-muted)}
        .murvc-kiu-name{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
        .murvc-kiu-score{flex:0 0 auto;font-weight:700;font-variant-numeric:tabular-nums}
        .murvc-kiu-me,.murvc-kiu-status{margin:8px 0 0;font-size:14px;color:var(--kiu-muted)}
        .murvc-kiu-more{margin:8px 0 0}
        .murvc-kiu-sr{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        @media (max-width:480px){.murvc-kiu-card{padding:14px;gap:12px;align-items:flex-start}.murvc-kiu-icon,.murvc-kiu--compact .murvc-kiu-icon{width:44px;height:44px}}
        @media (prefers-reduced-motion:reduce){.murvc-kiu-icon{animation:none}}
        html.murvc-kiu-open,html.murvc-kiu-open body{overflow:hidden!important;overscroll-behavior:none}
        .murvc-kiu-layer{position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147482000;background:linear-gradient(#e8eef8,#fdfdfb 70%);color:#0f2a52;touch-action:none;overscroll-behavior:contain;-webkit-user-select:none;user-select:none;-webkit-tap-highlight-color:transparent;-webkit-touch-callout:none;font-family:inherit;line-height:1.4}
        .murvc-kiu-layer:focus{outline:none}
        .murvc-kiu-layer:focus-visible{outline:3px solid #1f4e9c;outline-offset:-3px}
        .murvc-kiu-layer canvas{position:absolute;top:0;left:0;display:block;width:100%;height:100%;touch-action:none}
        .murvc-kiu-layer [hidden]{display:none!important}
        .murvc-kiu-hud{position:absolute;top:10px;left:0;right:0;text-align:center;pointer-events:none;font-weight:800;font-size:clamp(34px,9vmin,72px);line-height:1;font-variant-numeric:tabular-nums;color:#0f2a52;text-shadow:0 2px 0 #fff}
        .murvc-kiu-tip{position:absolute;left:0;right:0;bottom:30%;text-align:center;pointer-events:none;font-weight:700;font-size:18px;color:#5b6474}
        .murvc-kiu-layer button{font:inherit;text-transform:none;letter-spacing:normal;line-height:1.2;box-shadow:none;margin:0;cursor:pointer}
        .murvc-kiu-layer button:focus-visible{outline:3px solid #1f4e9c;outline-offset:2px}
        .murvc-kiu-layer button.murvc-kiu-close{position:absolute;top:10px;right:10px;width:44px;height:44px;padding:0;border-radius:50%;border:2px solid #0f2a52;background:#fff;color:#0f2a52;font-size:26px;font-weight:700;line-height:1}
        .murvc-kiu-word{position:absolute;transform:translate(-50%,-50%);pointer-events:none;font-weight:800;font-size:clamp(24px,6vmin,40px);color:#c8102e;white-space:nowrap;text-shadow:0 2px 0 #fff,0 -2px 0 #fff,2px 0 0 #fff,-2px 0 0 #fff;animation:murvc-kiu-word 1.2s ease-out forwards}
        @keyframes murvc-kiu-word{0%{opacity:0;transform:translate(-50%,-30%) scale(.6)}20%{opacity:1;transform:translate(-50%,-50%) scale(1.1)}70%{opacity:1;transform:translate(-50%,-70%) scale(1)}100%{opacity:0;transform:translate(-50%,-110%) scale(1)}}
        @media (prefers-reduced-motion:reduce){.murvc-kiu-word{animation:murvc-kiu-fade 1.2s linear forwards}@keyframes murvc-kiu-fade{0%,70%{opacity:1}100%{opacity:0}}}
        .murvc-kiu-panel{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(340px,calc(100% - 32px));max-height:calc(100% - 32px);overflow:auto;box-sizing:border-box;background:#fff;border-radius:14px;padding:22px 20px;text-align:center;box-shadow:0 12px 40px rgba(15,42,82,.25);-webkit-user-select:text;user-select:text;touch-action:auto}
        .murvc-kiu-panel h2{margin:0 0 4px;font-size:18px;color:#5b6474}
        .murvc-kiu-panel p{margin:0 0 8px}
        .murvc-kiu-big{font-size:56px;font-weight:800;line-height:1.05;color:#0f2a52;font-variant-numeric:tabular-nums}
        .murvc-kiu-new{font-weight:800;color:#c8102e;font-size:20px}
        .murvc-kiu-note{font-size:15px;color:#5b6474}
        .murvc-kiu-panel a{color:#1f4e9c;font-weight:700}
        .murvc-kiu-buttons{display:flex;gap:10px;justify-content:center;margin:14px 0 0}
        .murvc-kiu-layer button.murvc-kiu-again{padding:10px 22px;border:0;border-radius:24px;background:#c8102e;color:#fff;font-weight:700;font-size:16px}
        .murvc-kiu-layer button.murvc-kiu-quit{padding:10px 22px;border:2px solid #1f4e9c;border-radius:24px;background:#fff;color:#1f4e9c;font-weight:700;font-size:16px}
        </style>';
    }
}
