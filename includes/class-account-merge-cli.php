<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI for duplicate accounts (1.71.0): the same checks, backup and undo
 * as Club Membership → Duplicate accounts. Run with --user=<an admin> so
 * merges are recorded against a person. Output gives account numbers and
 * counts; look people up in Member Lookup.
 *
 *   wp club-manager accounts duplicates [--tier=strong] [--format=json]
 *   wp club-manager accounts preview <keep> <merge>...
 *   wp club-manager accounts merge <keep> <merge>... [--confirm=<codes>] [--yes]
 *   wp club-manager accounts undo <merge-record> [--yes]
 *   wp club-manager accounts history
 */
class TeamOversight_Account_Merge_CLI {

    /**
     * List suspected duplicate groups.
     *
     * ## OPTIONS
     *
     * [--tier=<tier>]
     * : strong, likely or possible (default: all).
     *
     * [--format=<format>]
     * : table or json. Default table.
     */
    public function duplicates($args, $assoc) {
        $scan = TeamOversight_Duplicates::scan(true);
        $tier = isset($assoc['tier']) ? $assoc['tier'] : '';
        $rows = array();
        foreach ($scan['groups'] as $g) {
            if ($tier !== '' && $g['tier'] !== $tier) {
                continue;
            }
            $rows[] = array(
                'tier' => $g['tier'],
                'accounts' => implode(',', $g['ids']),
                'suggested_keep' => $g['suggested'],
                'why' => implode('; ', array_merge($g['reasons'], $g['flags'])),
            );
        }
        WP_CLI\Utils\format_items(isset($assoc['format']) ? $assoc['format'] : 'table', $rows, array('tier', 'accounts', 'suggested_keep', 'why'));
    }

    /**
     * Show what merging would do, without changing anything.
     *
     * ## OPTIONS
     *
     * <keep>
     * : The account that lives on.
     *
     * <merge>...
     * : The accounts folded into it.
     */
    public function preview($args, $assoc) {
        $keep = (int) array_shift($args);
        $plan = TeamOversight_Account_Merge::plan($keep, $args);
        if (is_wp_error($plan)) {
            WP_CLI::error($plan->get_error_message());
        }
        unset($plan['orders']);
        WP_CLI::line(wp_json_encode($plan, JSON_PRETTY_PRINT));
        if ($plan['blockers']) {
            WP_CLI::warning('Blocked: ' . implode(' ', $plan['blockers']));
        }
        if ($plan['confirm']) {
            WP_CLI::line('Needs --confirm=' . implode(',', array_keys($plan['confirm'])));
        }
    }

    /**
     * Merge accounts into one.
     *
     * ## OPTIONS
     *
     * <keep>
     * : The account that lives on.
     *
     * <merge>...
     * : The accounts folded into it and deleted.
     *
     * [--confirm=<codes>]
     * : Comma-separated confirmation codes the preview asked for.
     *
     * [--yes]
     * : Don't ask.
     */
    public function merge($args, $assoc) {
        $keep = (int) array_shift($args);
        $plan = TeamOversight_Account_Merge::plan($keep, $args);
        if (is_wp_error($plan)) {
            WP_CLI::error($plan->get_error_message());
        }
        WP_CLI::confirm('Merge #' . implode(', #', array_map('intval', $args)) . " into #$keep?", $assoc);
        $confirm = isset($assoc['confirm']) ? array_filter(array_map('trim', explode(',', $assoc['confirm']))) : array();
        $result = TeamOversight_Account_Merge::apply($keep, $args, $confirm, $plan['hash']);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Merged. Merge record ' . $result['id'] . '.');
    }

    /**
     * Undo a merge from its record.
     *
     * ## OPTIONS
     *
     * <id>
     * : The merge record number.
     *
     * [--yes]
     * : Don't ask.
     */
    public function undo($args, $assoc) {
        $id = (int) $args[0];
        WP_CLI::confirm("Undo merge record $id?", $assoc);
        $result = TeamOversight_Account_Merge::undo($id);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success(sprintf('Undone: %d change(s) put back, %d left as they were.', $result['restored'], count($result['skipped'])));
        foreach ($result['skipped'] as $s) {
            WP_CLI::line('  left: ' . $s);
        }
    }

    /**
     * Recent merges.
     */
    public function history($args, $assoc) {
        WP_CLI\Utils\format_items('table', TeamOversight_Account_Merge::recent(100), array('id', 'kept_user_id', 'merged_user_ids', 'status', 'created_by', 'created_at', 'undone_at'));
    }
}

WP_CLI::add_command('club-manager accounts', 'TeamOversight_Account_Merge_CLI');
