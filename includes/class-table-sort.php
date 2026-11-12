<?php
/**
 * TeamOversight_Table_Sort (1.94.0, club request 12 Nov 2026): every list
 * table on a Club Manager admin screen sorts by a click on a column heading.
 *
 * One script and one small stylesheet (assets/table-sort.js, table-sort.css),
 * loaded only on this plugin's admin screens (applies()). The script takes
 * every plain list table by itself, so a new table needs nothing; a page only
 * marks the exceptions:
 *
 *  - data-nosort on a table, or on anything around it: never sorted (a grid of
 *    settings such as the Fee Matrix, the Club documents list whose order is
 *    set with Move up and Move down, the How it works guide);
 *  - data-nosort on a heading: that column gets no button;
 *  - data-sort="..." on a cell: the value to sort by, in place of its text;
 *  - data-sort-child on a row: it belongs to the row above and moves with it
 *    (the details row under a trial application);
 *  - data-sort-pin on a row: it keeps its end of the table (a totals row at
 *    the bottom, the club-wide rows at the top of the transfer limits table);
 *  - data-sort-paged on a table: the heading's hint says only the rows on
 *    this page are sorted (the script also sees WordPress's own page links).
 *
 * Sorting is in the browser only: it moves the rows already on the page, asks
 * the server nothing and saves nothing, so a reload shows the page's own
 * order again (as does a third click on the same heading). Front-end pages
 * (the coach portal, the member's own pages) are not touched.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TeamOversight_Table_Sort {

    /** The script and stylesheet handle. */
    const HANDLE = 'murvc-table-sort';

    /** Every Club Manager admin page slug starts with one of these. */
    const PAGE_PREFIXES = array('team-oversight', 'club-membership', 'club-programs', 'club-coaches', 'club-documents');

    /** The enqueue hook, added once per request (from team_oversight_init()). */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    /** Is this admin page slug one of the Club Manager's? The current request's page when null. */
    public static function applies($page = null) {
        if ($page === null) {
            $page = isset($_GET['page']) ? wp_unslash($_GET['page']) : '';
        }
        if (!is_string($page) || $page === '') {
            return false;
        }
        foreach (self::PAGE_PREFIXES as $prefix) {
            if (strpos($page, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** admin_enqueue_scripts: the script (in the footer, after the tables) and its stylesheet, on Club Manager screens only. */
    public static function enqueue() {
        if (!self::applies()) {
            return;
        }
        wp_enqueue_style(self::HANDLE, TEAM_OVERSIGHT_PLUGIN_URL . 'assets/table-sort.css', array(), TEAM_OVERSIGHT_VERSION);
        wp_enqueue_script(self::HANDLE, TEAM_OVERSIGHT_PLUGIN_URL . 'assets/table-sort.js', array(), TEAM_OVERSIGHT_VERSION, true);
    }
}
