<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Membership tier engine.
 *
 * Memberships are time-bound grants stored in {prefix}team_memberships
 * (user, tier, start/end date, source). A user's current status is the
 * highest tier with an unexpired grant. The full-member / associate-member
 * WordPress roles are kept in sync as a projection of the ledger so the
 * [murvc_member_role] profile shortcode and any role-based gating keep working.
 *
 * Grants come from three sources:
 *  - 'purchase': WooCommerce order containing a product configured with a
 *    membership tier + term (product meta, or a product-category rule)
 *  - 'manual': granted by an admin from the Members page
 *  - 'import': seeded from this year's purchase history (stop-gap migration)
 */
class TeamOversight_Memberships {

    const TIER_LIFE = 'life-member';
    const TIER_FULL = 'full-member';
    const TIER_ASSOCIATE = 'associate-member';

    // End dates on/after this are treated as "never expires" (life members).
    const PERMANENT_FROM = '2099-01-01';
    const PERMANENT_END = '2099-12-31';

    const PRODUCT_TIER_META = '_murvc_membership_tier';
    const PRODUCT_TERM_META = '_murvc_membership_term_months';

    const CATEGORY_RULES_OPTION = 'team_oversight_membership_category_rules';
    const CRON_HOOK = 'team_oversight_membership_sync';

    private static $hooks_registered = false;

    public function __construct() {
        // The engine gets re-instantiated by admin pages; only the first
        // instance owns the hooks.
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        add_action('init', array($this, 'register_roles'));
        add_action('init', array($this, 'maybe_schedule_cron'));
        add_action(self::CRON_HOOK, array($this, 'sync_all_users'));

        // Grant memberships when orders are paid.
        add_action('woocommerce_order_status_processing', array($this, 'handle_order'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_order'));

        // Tier/term fields on the product edit screen.
        add_action('woocommerce_product_options_general_product_data', array($this, 'render_product_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_fields'));

        // Membership badge on UM profiles. Supersedes the WPCode snippet of
        // the same name — delete that snippet once this version is live.
        add_shortcode('murvc_member_role', array($this, 'render_member_role_shortcode'));
    }

    /**
     * Shows the profile user's membership tier (highest role they hold),
     * falling back to other known roles.
     */
    public function render_member_role_shortcode() {
        $uid = 0;
        if (function_exists('um_profile_id')) {
            $uid = um_profile_id();
        }
        if (!$uid && function_exists('um_get_requested_user')) {
            $uid = um_get_requested_user();
        }
        if (!$uid) {
            $uid = get_current_user_id();
        }
        if (!$uid) {
            return '';
        }

        $user = get_userdata($uid);
        if (!$user) {
            return '';
        }
        $roles = (array) $user->roles;

        $labels = self::get_tiers() + array(
            'non-member' => 'Non-Member',
            'administrator' => 'Administrator',
            'shop_manager' => 'Committee',
            'customer' => 'Member',
        );

        foreach ($labels as $slug => $label) {
            if (in_array($slug, $roles, true)) {
                return '<span class="murvc-member-role">' . esc_html($label) . '</span>';
            }
        }
        return '';
    }

    public static function get_tiers() {
        return array(
            self::TIER_LIFE => 'Life Member',
            self::TIER_FULL => 'Full Member',
            self::TIER_ASSOCIATE => 'Associate Member',
        );
    }

    /**
     * Tiers a product purchase can grant. Life membership is bestowed by the
     * club, not bought, so it is manual-grant only.
     */
    public static function get_purchasable_tiers() {
        return array(
            self::TIER_FULL => 'Full Member',
            self::TIER_ASSOCIATE => 'Associate Member',
        );
    }

    public function register_roles() {
        if (!get_role(self::TIER_LIFE)) {
            add_role(self::TIER_LIFE, 'Life Member', array('read' => true));
        }
        if (!get_role(self::TIER_FULL)) {
            add_role(self::TIER_FULL, 'Full Member', array('read' => true));
        }
        if (!get_role(self::TIER_ASSOCIATE)) {
            add_role(self::TIER_ASSOCIATE, 'Associate Member', array('read' => true));
        }
        if (!get_role('non-member')) {
            add_role('non-member', 'Non-Member', array('read' => true));
        }
    }

    public function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    // ------------------------------------------------------------------
    // Product configuration
    // ------------------------------------------------------------------

    public function render_product_fields() {
        if (!function_exists('woocommerce_wp_select')) {
            return;
        }

        echo '<div class="options_group">';

        woocommerce_wp_select(array(
            'id' => self::PRODUCT_TIER_META,
            'label' => 'Membership tier granted',
            'description' => 'Buying this product grants the member this MURVC tier.',
            'desc_tip' => true,
            'options' => array_merge(
                array('' => 'None — no membership granted'),
                self::get_purchasable_tiers()
            ),
        ));

        woocommerce_wp_text_input(array(
            'id' => self::PRODUCT_TERM_META,
            'label' => 'Membership term (months)',
            'description' => 'How long the membership lasts, counted from the purchase date.',
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array('min' => '1', 'max' => '60', 'step' => '1'),
        ));

        echo '</div>';
    }

    public function save_product_fields($post_id) {
        $tier = isset($_POST[self::PRODUCT_TIER_META]) ? sanitize_text_field($_POST[self::PRODUCT_TIER_META]) : '';
        $months = isset($_POST[self::PRODUCT_TERM_META]) ? intval($_POST[self::PRODUCT_TERM_META]) : 0;

        if ($tier && array_key_exists($tier, self::get_purchasable_tiers())) {
            update_post_meta($post_id, self::PRODUCT_TIER_META, $tier);
        } else {
            delete_post_meta($post_id, self::PRODUCT_TIER_META);
        }

        if ($months > 0) {
            update_post_meta($post_id, self::PRODUCT_TERM_META, $months);
        } else {
            delete_post_meta($post_id, self::PRODUCT_TERM_META);
        }
    }

    /**
     * Resolve what membership (if any) a product grants.
     * Product meta wins; otherwise fall back to a product-category rule.
     * Returns array('tier' => ..., 'months' => ...) or null.
     */
    public function get_product_mapping($product_id) {
        $tier = get_post_meta($product_id, self::PRODUCT_TIER_META, true);
        $months = intval(get_post_meta($product_id, self::PRODUCT_TERM_META, true));

        if ($tier && $months > 0 && array_key_exists($tier, self::get_purchasable_tiers())) {
            return array('tier' => $tier, 'months' => $months);
        }

        $rules = get_option(self::CATEGORY_RULES_OPTION, array());
        if (empty($rules) || !is_array($rules)) {
            return null;
        }

        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) {
            return null;
        }

        // If multiple category rules match, the highest tier / longest term wins.
        $best = null;
        foreach ($terms as $term) {
            if (empty($rules[$term->term_id]['tier']) || empty($rules[$term->term_id]['months'])) {
                continue;
            }
            $candidate = array(
                'tier' => $rules[$term->term_id]['tier'],
                'months' => intval($rules[$term->term_id]['months']),
            );
            if (!array_key_exists($candidate['tier'], self::get_purchasable_tiers()) || $candidate['months'] < 1) {
                continue;
            }
            if ($best === null
                || $this->tier_rank($candidate['tier']) > $this->tier_rank($best['tier'])
                || ($candidate['tier'] === $best['tier'] && $candidate['months'] > $best['months'])) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function tier_rank($tier) {
        if ($tier === self::TIER_LIFE) {
            return 3;
        }
        return $tier === self::TIER_FULL ? 2 : ($tier === self::TIER_ASSOCIATE ? 1 : 0);
    }

    // ------------------------------------------------------------------
    // Order processing
    // ------------------------------------------------------------------

    public function handle_order($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }

        $granted = false;
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $mapping = $this->get_product_mapping($product_id);
            if (!$mapping) {
                continue;
            }

            if ($this->grant_exists_for_order_item($item_id)) {
                continue;
            }

            $start = current_time('Y-m-d');
            $end = date('Y-m-d', strtotime($start . ' +' . $mapping['months'] . ' months'));

            $this->insert_grant(array(
                'user_id' => $user_id,
                'tier' => $mapping['tier'],
                'start_date' => $start,
                'end_date' => $end,
                'source' => 'purchase',
                'order_id' => $order_id,
                'order_item_id' => $item_id,
                'product_id' => $product_id,
            ));
            $granted = true;
        }

        if ($granted) {
            $this->sync_user_roles($user_id);
        }
    }

    private function grant_exists_for_order_item($order_item_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}team_memberships WHERE order_item_id = %d
        ", $order_item_id));
    }

    // ------------------------------------------------------------------
    // Grants
    // ------------------------------------------------------------------

    public function insert_grant($data) {
        global $wpdb;

        $defaults = array(
            'user_id' => 0,
            'tier' => '',
            'start_date' => current_time('Y-m-d'),
            'end_date' => '',
            'source' => 'manual',
            'order_id' => null,
            'order_item_id' => null,
            'product_id' => null,
            'granted_by' => null,
            'note' => '',
        );
        $data = array_merge($defaults, $data);

        if (!$data['user_id'] || !array_key_exists($data['tier'], self::get_tiers()) || !$data['end_date']) {
            return false;
        }

        return $wpdb->insert(
            $wpdb->prefix . 'team_memberships',
            $data,
            array('%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s')
        ) !== false;
    }

    public function grant_manual($user_id, $tier, $end_date, $note = '') {
        $ok = $this->insert_grant(array(
            'user_id' => $user_id,
            'tier' => $tier,
            'end_date' => $end_date,
            'source' => 'manual',
            'granted_by' => get_current_user_id(),
            'note' => $note,
        ));

        if ($ok) {
            $this->sync_user_roles($user_id);
        }
        return $ok;
    }

    /**
     * End all active grants for a user (sets end_date to yesterday) and
     * strips their tier roles.
     */
    public function revoke_all($user_id) {
        global $wpdb;

        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}team_memberships
            SET end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            WHERE user_id = %d AND end_date >= CURDATE()
        ", $user_id));

        // Revoking also overrides any role left over from the stop-gap
        // snippet, so strip tier roles even if the user had no ledger rows.
        $user = get_userdata($user_id);
        if ($user) {
            $user->remove_role(self::TIER_LIFE);
            $user->remove_role(self::TIER_FULL);
            $user->remove_role(self::TIER_ASSOCIATE);
        }
    }

    /**
     * Highest active tier from the ledger, or null.
     */
    public function get_active_tier($user_id) {
        global $wpdb;

        $tiers = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT tier FROM {$wpdb->prefix}team_memberships
            WHERE user_id = %d AND start_date <= CURDATE() AND end_date >= CURDATE()
        ", $user_id));

        if (in_array(self::TIER_LIFE, $tiers, true)) {
            return self::TIER_LIFE;
        }
        if (in_array(self::TIER_FULL, $tiers, true)) {
            return self::TIER_FULL;
        }
        if (in_array(self::TIER_ASSOCIATE, $tiers, true)) {
            return self::TIER_ASSOCIATE;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Role sync
    // ------------------------------------------------------------------

    /**
     * Make the user's tier roles match their ledger status.
     *
     * IMPORTANT: users with no ledger rows at all are left untouched — their
     * roles may come from the stop-gap snippet and must survive until the
     * purchase-history seeding has been run.
     */
    public function sync_user_roles($user_id) {
        global $wpdb;

        $has_grants = (bool) $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}team_memberships WHERE user_id = %d LIMIT 1
        ", $user_id));

        if (!$has_grants) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $active_tier = $this->get_active_tier($user_id);

        foreach (array_keys(self::get_tiers()) as $tier) {
            if ($tier === $active_tier) {
                if (!in_array($tier, (array) $user->roles, true)) {
                    $user->add_role($tier);
                }
            } else {
                if (in_array($tier, (array) $user->roles, true)) {
                    $user->remove_role($tier);
                }
            }
        }
    }

    /**
     * Daily cron: re-sync everyone who has ledger rows, which demotes
     * members whose last grant has expired.
     */
    public function sync_all_users() {
        global $wpdb;

        $user_ids = $wpdb->get_col("
            SELECT DISTINCT user_id FROM {$wpdb->prefix}team_memberships
        ");

        foreach ($user_ids as $user_id) {
            $this->sync_user_roles(intval($user_id));
        }
    }

    // ------------------------------------------------------------------
    // Seeding from purchase history
    // ------------------------------------------------------------------

    /**
     * Convert this calendar year's qualifying purchases into ledger grants
     * with real start/end dates, using the same category rules as the
     * stop-gap role-assignment snippet:
     *   FULL (12 months): Player Fees, VVL Registration, YSL Registration,
     *     Training Only Fees, Supporter Fees, or "Membership Payment Plan" items
     *   ASSOCIATE (3 months): Programs - Training Fees, Reds Social Competition,
     *     Events/Tournaments (except Trivia)
     *
     * Idempotent: order items that already have a grant are skipped.
     * Returns a report array; pass $apply = false for a dry run.
     */
    public function seed_from_purchases($apply = false) {
        global $wpdb;

        $year = wp_date('Y');
        $start = $year . '-01-01';
        $end = ((int) $year + 1) . '-01-01';

        $full_cats = array('Player Fees', 'VVL Registration', 'YSL Registration', 'Training Only Fees', 'Supporter Fees');
        $assoc_cats = array('Programs - Training Fees', 'Reds Social Competition');

        $items = $wpdb->get_results($wpdb->prepare("
            SELECT opl.order_item_id,
                MAX(cl.user_id) AS user_id,
                MAX(opl.order_id) AS order_id,
                MAX(opl.product_id) AS product_id,
                MAX(DATE(opl.date_created)) AS purchase_date,
                MAX(oi.order_item_name) AS order_item_name,
                GROUP_CONCAT(DISTINCT t.name SEPARATOR '||') AS categories
            FROM {$wpdb->prefix}wc_order_product_lookup opl
            JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = opl.order_item_id
            JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = opl.customer_id
            LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = opl.product_id
            LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE opl.date_created >= %s AND opl.date_created < %s
                AND cl.user_id IS NOT NULL AND cl.user_id > 0
            GROUP BY opl.order_item_id
        ", $start, $end));

        $report = array(
            'year' => $year,
            'mode' => $apply ? 'apply' : 'dry-run',
            'full' => 0,
            'associate' => 0,
            'skipped_existing' => 0,
            'sample' => array(),
            'affected_users' => array(),
        );

        foreach ($items as $item) {
            $cats = $item->categories ? explode('||', $item->categories) : array();

            $tier = null;
            $months = 0;
            if (array_intersect($cats, $full_cats) || stripos($item->order_item_name, 'Membership Payment Plan') !== false) {
                $tier = self::TIER_FULL;
                $months = 12;
            } elseif (array_intersect($cats, $assoc_cats)
                || (in_array('Events/Tournaments', $cats, true) && stripos($item->order_item_name, 'Trivia') === false)) {
                $tier = self::TIER_ASSOCIATE;
                $months = 3;
            }

            if (!$tier) {
                continue;
            }

            if ($this->grant_exists_for_order_item($item->order_item_id)) {
                $report['skipped_existing']++;
                continue;
            }

            $tier === self::TIER_FULL ? $report['full']++ : $report['associate']++;
            $report['affected_users'][$item->user_id] = true;

            if (count($report['sample']) < 25) {
                $user = get_userdata($item->user_id);
                $report['sample'][] = sprintf(
                    '%s  %s  %s → %s  (%s)',
                    strtoupper($tier === self::TIER_FULL ? 'full' : 'assoc'),
                    $user ? $user->user_email : ('user #' . $item->user_id),
                    $item->purchase_date,
                    date('Y-m-d', strtotime($item->purchase_date . ' +' . $months . ' months')),
                    $item->order_item_name
                );
            }

            if ($apply) {
                $this->insert_grant(array(
                    'user_id' => intval($item->user_id),
                    'tier' => $tier,
                    'start_date' => $item->purchase_date,
                    'end_date' => date('Y-m-d', strtotime($item->purchase_date . ' +' . $months . ' months')),
                    'source' => 'import',
                    'order_id' => intval($item->order_id),
                    'order_item_id' => intval($item->order_item_id),
                    'product_id' => intval($item->product_id),
                ));
            }
        }

        if ($apply) {
            foreach (array_keys($report['affected_users']) as $user_id) {
                $this->sync_user_roles(intval($user_id));
            }
        }

        $report['affected_users'] = count($report['affected_users']);
        return $report;
    }
}
