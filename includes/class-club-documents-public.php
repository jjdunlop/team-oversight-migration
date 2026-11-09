<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Club documents on the website (1.90.0): the [club_documents] shortcode,
 * which draws the Policies and documents page (every group, each under its
 * heading) or one group on another page ([club_documents
 * group="child-safety" heading="no"]), and its style block.
 *
 * The rules, in one place:
 *  - Two queries per page, however many documents: the published documents
 *    (TeamOversight_Club_Documents::published_documents()), then their
 *    current versions (versions_by_id()). A document is_listed() rejects
 *    (only boxes the page doesn't list are ticked, or none) is dropped in
 *    PHP before the second query, so it is never drawn.
 *  - The output never depends on the viewer: the same bytes for everyone,
 *    so the page cache can keep one copy. A document not open to Everyone
 *    is listed with its label (public_label(): For club members, For full
 *    members, For associate members) and its click goes through the
 *    address, which answers by viewer. This class writes nothing, sets no
 *    header and never asks for the page not to be cached.
 *  - Every text node goes through esc_html(), every attribute through
 *    esc_attr() and every URL through esc_url(); a group intro goes
 *    through wp_kses() with INTRO_TAGS. Any problem (an unknown group) or
 *    an empty result is '', so the shortcode placed early shows nothing;
 *    the To do panel names the problem.
 *  - A link opens the PDF in a new tab and says so to screen readers. The
 *    Open button repeats the title's link for sighted visitors and is
 *    hidden from screen readers and the tab order, so they meet one link.
 */
class TeamOversight_Club_Documents_Public {

    /** The shortcode's attributes and their defaults (shortcode_atts()). */
    const ATTS = array('group' => '', 'heading' => '');

    /** What has been printed this request (the styles): once. */
    private static $printed = array();

    /** Add the shortcode on init, once per request (team_oversight_init() calls this). */
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
    }

    public static function register_shortcode() {
        add_shortcode(TeamOversight_Club_Documents::SHORTCODE, array(__CLASS__, 'render_shortcode'));
    }

    /**
     * [club_documents]: every group with documents, in the groups' order,
     * each under its heading (id = the group key, so a link can jump to
     * it); group="KEY" for one group; heading="no" leaves the group heading
     * out. Returns the HTML and never echoes; '' inside wp_head (so a
     * thrown-away render never uses up the once-per-request styles), for an
     * unknown group and for nothing to show.
     */
    public static function render_shortcode($atts = array()) {
        if (doing_action('wp_head')) {
            return '';
        }
        $D = 'TeamOversight_Club_Documents';
        $atts = shortcode_atts(self::ATTS, is_array($atts) ? $atts : array(), $D::SHORTCODE);
        $only = strtolower(trim(is_scalar($atts['group']) ? (string) $atts['group'] : ''));
        $headings = strtolower(trim(is_scalar($atts['heading']) ? (string) $atts['heading'] : '')) !== 'no';
        $groups = $D::groups();
        if ($only !== '' && !isset($groups[$only])) {
            return '';
        }

        // Query 1: the published documents; the ones the page doesn't list are dropped here, before any version is read.
        $docs = array();
        foreach ($D::published_documents() as $doc) {
            $key = (string) $doc->group_key;
            if (!isset($groups[$key]) || ($only !== '' && $key !== $only) || !$D::is_listed($doc) || empty($doc->current_version_id)) {
                continue;
            }
            $docs[] = $doc;
        }
        if (empty($docs)) {
            return '';
        }
        // Query 2: their current versions.
        $ids = array();
        foreach ($docs as $doc) {
            $ids[] = (int) $doc->current_version_id;
        }
        $versions = $D::versions_by_id($ids);

        $by_group = array();
        foreach ($docs as $doc) {
            $v = isset($versions[(int) $doc->current_version_id]) ? $versions[(int) $doc->current_version_id] : null;
            if (!$v || (int) $v->document_id !== (int) $doc->id || !empty($v->file_deleted_at)) {
                continue;
            }
            $by_group[(string) $doc->group_key][] = array($doc, $v);
        }
        if (empty($by_group)) {
            return '';
        }

        $html = '';
        foreach ($groups as $key => $group) {
            if (empty($by_group[$key])) {
                continue;
            }
            $html .= self::render_group((string) $key, $group, $by_group[$key], $headings);
        }
        return self::styles() . '<div class="club-docs">' . $html . '</div>';
    }

    /** One group: its heading (unless left out), its intro, then its rows (by year, newest first, in a group shown by year). */
    private static function render_group($key, array $group, array $rows, $headings) {
        $out = '<section class="club-docs-group"' . ($headings ? '' : ' id="' . esc_attr($key) . '"') . '>';
        if ($headings) {
            $out .= '<h2 class="club-docs-heading" id="' . esc_attr($key) . '">' . esc_html((string) $group['label']) . '</h2>';
        }
        $intro = trim(wp_kses((string) $group['intro'], TeamOversight_Club_Documents::INTRO_TAGS));
        if ($intro !== '') {
            $out .= '<div class="club-docs-intro">' . wpautop($intro) . '</div>';
        }
        if (!empty($group['by_year'])) {
            // The documents arrive newest year first (list order); a year heading over each year's rows.
            $years = array();
            foreach ($rows as $pair) {
                $year = $pair[0]->doc_year === null ? 0 : (int) $pair[0]->doc_year;
                $years[$year][] = $pair;
            }
            krsort($years);
            $level = $headings ? 'h3' : 'h2';
            foreach ($years as $year => $list) {
                if ($year > 0) {
                    $out .= '<' . $level . ' class="club-docs-year">' . esc_html((string) $year) . '</' . $level . '>';
                }
                $out .= self::render_rows($list);
            }
        } else {
            $out .= self::render_rows($rows);
        }
        return $out . '</section>';
    }

    /**
     * The rows of one list: the title (the link), the description, the
     * line "PDF · 240 KB · Updated 12 Mar 2026", plus " · For club members"
     * when Everyone isn't ticked, and the Open button.
     */
    private static function render_rows(array $rows) {
        $D = 'TeamOversight_Club_Documents';
        $out = '<ul class="club-docs-list">';
        foreach ($rows as $pair) {
            list($doc, $v) = $pair;
            $url = $D::document_url((string) $doc->slug);
            $label = $D::public_label($doc);
            $meta = 'PDF · ' . self::size_words((int) $v->size_bytes) . ' · Updated ' . $D::nice_date((string) $v->dated, 'j M Y') . ($label !== '' ? ' · ' . $label : '');
            $desc = trim((string) $doc->description);
            $out .= '<li class="club-docs-row">'
                . '<div class="club-docs-text">'
                . '<a class="club-docs-title" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string) $doc->title) . '<span class="club-docs-sr"> (opens in a new tab)</span></a>'
                . ($desc !== '' ? '<p class="club-docs-desc">' . esc_html($desc) . '</p>' : '')
                . '<p class="club-docs-meta">' . esc_html($meta) . '</p>'
                . '</div>'
                . '<a class="club-docs-open" href="' . esc_url($url) . '" target="_blank" rel="noopener" aria-hidden="true" tabindex="-1">Open</a>'
                . '</li>';
        }
        return $out . '</ul>';
    }

    /** A file size as the page shows it: "240 KB" under 1 MB, "2.4 MB" above (one decimal, none when whole). */
    public static function size_words($bytes) {
        $bytes = max(0, (int) $bytes);
        if ($bytes < 1048576) {
            return size_format(max(1024, $bytes), 0);
        }
        $text = size_format($bytes, 1);
        return preg_replace('/\.0(?=\s)/', '', (string) $text);
    }

    /**
     * The style block, once per request, scoped under .club-docs. Colours
     * from the theme's palette where it has one. On a phone each row
     * stacks: title, description, the PDF line, the button.
     */
    public static function styles() {
        if (!empty(self::$printed['styles'])) {
            return '';
        }
        self::$printed['styles'] = true;
        return '<style>
        .club-docs{--cd-blue:var(--wp--preset--color--accent-2,#1f4e9c);--cd-navy:var(--wp--preset--color--accent-3,#0f2a52);--cd-muted:var(--wp--preset--color--accent-4,#5b6474);--cd-border:var(--wp--preset--color--accent-6,#d9dee6);margin:16px 0}
        .club-docs-group{margin:0 0 28px}
        .club-docs-heading{scroll-margin-top:90px}
        .club-docs-year{margin:18px 0 6px;font-size:1.05em;color:var(--cd-navy)}
        .club-docs-intro{margin:0 0 10px}
        .club-docs .club-docs-list{list-style:none;margin:0;padding:0;border-top:1px solid var(--cd-border)}
        .club-docs-row{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--cd-border)}
        .club-docs-text{flex:1 1 auto;min-width:0;overflow-wrap:break-word}
        .club-docs-title{font-weight:700;color:var(--cd-blue)}
        .club-docs .club-docs-desc{margin:4px 0 0}
        .club-docs .club-docs-meta{margin:4px 0 0;font-size:14px;color:var(--cd-muted)}
        .club-docs-open{flex:0 0 auto;display:inline-block;padding:6px 16px;border:2px solid var(--cd-blue);border-radius:6px;color:var(--cd-blue);font-weight:700;text-decoration:none}
        .club-docs-open:hover{background:var(--cd-blue);color:#fff}
        .club-docs-sr{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        @media (max-width:600px){.club-docs-row{flex-direction:column;align-items:flex-start;gap:8px}}
        </style>';
    }
}
