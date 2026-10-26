<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Finding likely duplicate accounts (1.71.0), for Club Membership →
 * Duplicate accounts. Nothing is shown to members: new duplicates are
 * flagged to the admin here instead of being blocked at registration
 * (a person often can't remember which email the old account used).
 *
 * One read of every account's name, date of birth, phones, emails and
 * student number; candidate pairs are compared only within shared
 * "buckets" (same name, same surname and birth date, same phone...), never
 * all pairs. A pair needs a name match to count; the birth date and shared
 * contact details set its tier:
 *  - Strong: same name and birth date; or same name, no birth date to
 *    compare, and a shared phone / email / student number; or a near name
 *    (a surname typo, swapped order) with the same birth date and a shared
 *    contact. A near first name with the same surname and birth date
 *    (Sam/Samuel, but also Ella/Bella twins) is at most Likely, flagged.
 *  - Likely: a near name with the same birth date; the same name with a
 *    birth date one digit apart and something shared; and so on.
 *  - Possible: the same name and nothing else to go on.
 * Pairs that must never be joined ("cannot-links"): two different birth
 * dates (a parent and child with one name), same surname and birth date
 * with unrelated first names (twins), Jr/Sr on one side only, different
 * genders (female/male; anything else counts as unknown) with the same
 * surname and birth date, and any pair an admin marked "not the same
 * person". Groups are built strongest first; a join (in any tier) that
 * would put a cannot-link pair, or two different birth dates, in one group
 * is refused, judging any pair the buckets never compared.
 */
class TeamOversight_Duplicates {

    const SCAN_TRANSIENT = 'murvc_dup_scan';
    const SEEN_OPTION = 'murvc_dup_seen';
    const BADGE_OPTION = 'murvc_dup_badge';
    const RESCAN_HOOK = 'murvc_dup_rescan';
    const NEW_DAYS = 14;
    const BUCKET_MAX = 12;

    private static $hooks_added = false;

    public static function register_hooks() {
        if (self::$hooks_added) {
            return;
        }
        self::$hooks_added = true;
        foreach (array('user_register', 'profile_update', 'deleted_user') as $hook) {
            add_action($hook, array(__CLASS__, 'forget_scan'));
        }
        add_action('deleted_user', array(__CLASS__, 'forget_decisions_for'));
        add_action('updated_user_meta', array(__CLASS__, 'meta_changed'), 10, 3);
        add_action('added_user_meta', array(__CLASS__, 'meta_changed'), 10, 3);
        // The menu badge: a scan a few minutes after accounts change, and daily.
        add_action(self::RESCAN_HOOK, array(__CLASS__, 'rescan'));
        add_action('team_oversight_membership_sync', array(__CLASS__, 'rescan'), 20);
    }

    public static function meta_changed($meta_id, $user_id, $key) {
        if (in_array($key, self::scanned_keys(), true)) {
            self::forget_scan();
        }
    }

    /**
     * Drop the cached list (accounts changed); a fresh scan runs five
     * minutes later for the badge. Scheduling waits for the end of the
     * request, because this runs from meta hooks, some inside a merge's
     * database transaction, which would otherwise hold the cron row locked.
     */
    public static function forget_scan() {
        delete_transient(self::SCAN_TRANSIENT);
        if (!has_action('shutdown', array(__CLASS__, 'schedule_rescan'))) {
            add_action('shutdown', array(__CLASS__, 'schedule_rescan'));
        }
    }

    public static function schedule_rescan() {
        if (!wp_next_scheduled(self::RESCAN_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::RESCAN_HOOK);
        }
    }

    public static function decisions_table() {
        global $wpdb;
        return $wpdb->prefix . 'team_duplicate_decisions';
    }

    /** "Not the same person" decisions: one row per pair of accounts. */
    public static function decisions_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE " . self::decisions_table() . " (
                id int(11) NOT NULL AUTO_INCREMENT,
                user_lo bigint(20) unsigned NOT NULL,
                user_hi bigint(20) unsigned NOT NULL,
                decision varchar(20) NOT NULL DEFAULT 'different',
                reason varchar(30) NOT NULL DEFAULT '',
                note varchar(255) DEFAULT NULL,
                decided_by bigint(20) unsigned DEFAULT NULL,
                decided_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pair (user_lo, user_hi),
                KEY user_hi (user_hi)
            ) ENGINE=InnoDB $charset_collate;";
    }

    private static function scanned_keys() {
        global $wpdb;
        return array('first_name', 'last_name', 'preferred_name', 'birth_date', 'mobile_number', 'billing_phone',
            'billing_email', 'secondary_user_email', 'StudentID', 'gender_dropdown', 'gender', 'account_status',
            $wpdb->get_blog_prefix() . 'capabilities');
    }

    // ------------------------------------------------------------------
    // Normalising
    // ------------------------------------------------------------------

    /** array(key, had_suffix) for a name: lower case, no accents, letters and digits only. */
    private static function name_key($raw) {
        $s = html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8');
        $s = mb_strtolower(remove_accents(trim($s)));
        $suffix = false;
        $s = preg_replace_callback('/\b(jr|junior|snr|sr|ii|iii)\b\.?/u', function () use (&$suffix) {
            $suffix = true;
            return ' ';
        }, $s);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
        if (mb_strlen($key) < 2 || in_array($key, array('test', 'na', 'none', 'user', 'xx', 'xxx', 'nil'), true)) {
            $key = '';
        }
        return array($key, $suffix);
    }

    private static function tokens($raw) {
        $s = mb_strtolower(remove_accents(html_entity_decode((string) $raw, ENT_QUOTES, 'UTF-8')));
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $s), function ($t) {
            return mb_strlen($t) >= 2 && !in_array($t, array('jr', 'junior', 'snr', 'sr', 'ii', 'iii'), true);
        }));
    }

    private static function dob($raw, $registered) {
        $s = trim((string) $raw);
        if ($s === '' || preg_match('/^\d{9,}$/', $s)) {
            return '';
        }
        $y = $m = $d = 0;
        if (preg_match('#^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})$#', $s, $x)) {
            list(, $y, $m, $d) = $x;
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $x)) {
            list(, $d, $m, $y) = $x;
        } elseif (preg_match('#^(\d{4})(\d{2})(\d{2})$#', $s, $x)) {
            list(, $y, $m, $d) = $x;
        } else {
            return '';
        }
        $y = (int) $y;
        $m = (int) $m;
        $d = (int) $d;
        $max_year = (int) substr((string) $registered, 0, 4) - 3;
        if (!checkdate($m, $d, $y) || $y < 1925 || ($max_year > 1900 && $y > $max_year)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private static function phone($raw) {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strpos($d, '0011') === 0) {
            $d = substr($d, 4);
        }
        if (strpos($d, '0061') === 0) {
            $d = substr($d, 4);
        }
        if (strlen($d) === 11 && strpos($d, '61') === 0 && preg_match('/^[2-478]/', substr($d, 2))) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10 && preg_match('/^0[2-478]/', $d)) {
            $d = substr($d, 1);
        }
        if (strlen($d) < 8 || preg_match('/^(\d)\1+$/', $d) || in_array($d, array('400000000', '412345678', '123456789'), true)) {
            return '';
        }
        return $d;
    }

    private static function gmail($email) {
        $email = strtolower(trim((string) $email));
        if (!preg_match('/^([^@]+)@(gmail|googlemail)\.com$/', $email, $x)) {
            return '';
        }
        $local = preg_replace('/\+.*$/', '', $x[1]);
        return str_replace('.', '', $local) . '@gmail';
    }

    private static function local_part($email) {
        $email = strtolower(trim((string) $email));
        $at = strpos($email, '@');
        if ($at === false) {
            return '';
        }
        $local = preg_replace('/\+.*$/', '', substr($email, 0, $at));
        $local = preg_replace('/[^a-z0-9]/', '', $local);
        if (strlen($local) < 5 || in_array($local, array('info', 'admin', 'contact', 'hello', 'office', 'mail', 'volleyball'), true)) {
            return '';
        }
        return $local;
    }

    /**
     * 'f', 'm' or '' (anything else — non-binary, other, prefer not to say,
     * an unexpected label — is unknown, never a conflict). The current
     * field wins over the older one.
     */
    private static function gender($dropdown, $legacy) {
        foreach (array($dropdown, $legacy) as $raw) {
            $v = maybe_unserialize((string) $raw);
            if (is_array($v)) {
                $v = reset($v);
            }
            $v = strtolower(trim((string) $v));
            if ($v === '') {
                continue;
            }
            if (in_array($v, array('female', 'woman', 'f', 'girl'), true)) {
                return 'f';
            }
            if (in_array($v, array('male', 'man', 'm', 'boy'), true)) {
                return 'm';
            }
            return '';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // The scan
    // ------------------------------------------------------------------

    /** Every account, normalised, keyed by ID (one query). */
    private static function load_accounts() {
        global $wpdb;
        $keys = array_merge(self::scanned_keys(), array('_um_last_login', 'wc_last_active', 'itsec_user_activity_last_seen'));
        $cols = '';
        foreach ($keys as $i => $k) {
            $cols .= $wpdb->prepare(", MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS k$i", $k);
        }
        $in = "'" . implode("','", array_map('esc_sql', $keys)) . "'";
        $rows = $wpdb->get_results("SELECT u.ID, u.user_login, u.user_email, u.user_registered, u.display_name $cols
            FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key IN ($in)
            GROUP BY u.ID", ARRAY_A);

        $idx = array_flip($keys);
        $accounts = array();
        foreach ($rows as $r) {
            $v = function ($key) use ($r, $idx) {
                return isset($idx[$key]) && isset($r['k' . $idx[$key]]) ? (string) $r['k' . $idx[$key]] : '';
            };
            list($first, $suffix_f) = self::name_key($v('first_name'));
            list($last, $suffix_l) = self::name_key($v('last_name'));
            if ($first === '' && $last === '' && strpos($r['display_name'], '@') === false) {
                $t = self::tokens($r['display_name']);
                if (count($t) >= 2) {
                    $first = $t[0];
                    $last = end($t);
                }
            }
            $first_tokens = self::tokens($v('first_name'));
            $bag = array_merge($first_tokens, self::tokens($v('last_name')));
            sort($bag);
            list($pref) = self::name_key($v('preferred_name'));
            $emails = array();
            foreach (array($r['user_email'], $v('billing_email'), $v('secondary_user_email'), strpos($r['user_login'], '@') !== false ? $r['user_login'] : '') as $e) {
                $e = strtolower(trim($e));
                if ($e !== '' && is_email($e)) {
                    $emails[$e] = true;
                }
            }
            $phones = array();
            foreach (array($v('mobile_number'), $v('billing_phone')) as $p) {
                $p = self::phone($p);
                if ($p !== '') {
                    $phones[$p] = true;
                }
            }
            $sid = preg_replace('/\D+/', '', $v('StudentID'));
            if (strlen($sid) < 6 || preg_match('/^(\d)\1+$/', $sid) || strpos('12345678901234567890', $sid) !== false) {
                $sid = '';
            }
            $caps = maybe_unserialize($v($wpdb->get_blog_prefix() . 'capabilities'));
            $last_seen = 0;
            foreach (array($v('itsec_user_activity_last_seen'), $v('wc_last_active')) as $ts) {
                if (ctype_digit($ts)) {
                    $last_seen = max($last_seen, (int) $ts);
                }
            }
            $um = $v('_um_last_login');
            if ($um !== '' && !ctype_digit($um)) {
                $t = strtotime($um . ' UTC');
                if ($t) {
                    $last_seen = max($last_seen, $t);
                }
            } elseif (ctype_digit($um)) {
                $last_seen = max($last_seen, (int) $um);
            }
            $accounts[(int) $r['ID']] = array(
                'id' => (int) $r['ID'],
                'first' => $first,
                'last' => $last,
                'first_tok' => isset($first_tokens[0]) ? $first_tokens[0] : $first,
                'bag' => count($bag) >= 2 ? implode(' ', $bag) : '',
                'suffix' => $suffix_f || $suffix_l,
                'pref' => $pref,
                'dob' => self::dob($v('birth_date'), $r['user_registered']),
                'gender' => self::gender($v('gender_dropdown'), $v('gender')),
                'phones' => array_keys($phones),
                'emails' => array_keys($emails),
                'gmail' => array_values(array_filter(array_map(array(__CLASS__, 'gmail'), array_keys($emails)))),
                'locals' => array_values(array_unique(array_filter(array_map(array(__CLASS__, 'local_part'), array_keys($emails))))),
                'sid' => $sid,
                'status' => $v('account_status'),
                'roles' => is_array($caps) ? array_keys(array_filter($caps)) : array(),
                'registered' => $r['user_registered'],
                'last_seen' => $last_seen,
            );
        }
        return $accounts;
    }

    private static function lev1($a, $b, $min) {
        return mb_strlen($a) >= $min && mb_strlen($b) >= $min && abs(strlen($a) - strlen($b)) <= 1 && levenshtein($a, $b) === 1;
    }

    private static function first_linked($a, $b) {
        if ($a['first'] === '' || $b['first'] === '') {
            return false;
        }
        if ($a['first'] === $b['first'] || ($a['first_tok'] !== '' && $a['first_tok'] === $b['first_tok'])) {
            return true;
        }
        $x = $a['first_tok'];
        $y = $b['first_tok'];
        if (mb_strlen($x) >= 3 && mb_strlen($y) >= 3 && (strpos($x, $y) === 0 || strpos($y, $x) === 0)) {
            return true;
        }
        foreach (array(array($a['pref'], $b), array($b['pref'], $a)) as $p) {
            if ($p[0] !== '' && ($p[0] === $p[1]['first'] || $p[0] === $p[1]['first_tok'] || $p[0] === $p[1]['pref'])) {
                return true;
            }
        }
        return self::lev1($x, $y, 4);
    }

    private static function last_linked($a, $b) {
        if ($a['last'] === '' || $b['last'] === '') {
            return false;
        }
        if ($a['last'] === $b['last']) {
            return true;
        }
        return self::lev1($a['last'], $b['last'], 5);
    }

    /** 3 = same name, 2 = near name, 0 = no name match. */
    private static function name_level($a, $b) {
        if ($a['first'] !== '' && $a['last'] !== '' && $a['first'] === $b['first'] && $a['last'] === $b['last']) {
            return 3;
        }
        if ($a['last'] !== '' && $a['last'] === $b['last'] && self::first_linked($a, $b)) {
            return 2;
        }
        if ($a['first'] !== '' && $a['first'] === $b['first'] && self::last_linked($a, $b)) {
            return 2;
        }
        if ($a['bag'] !== '' && $a['bag'] === $b['bag']) {
            return 2;
        }
        return 0;
    }

    /** SAME, NEAR, UNKNOWN or CONFLICT. */
    private static function dob_relation($a, $b) {
        if ($a['dob'] === '' || $b['dob'] === '') {
            return 'UNKNOWN';
        }
        if ($a['dob'] === $b['dob']) {
            return 'SAME';
        }
        $diff = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($a['dob'][$i] !== $b['dob'][$i]) {
                $diff++;
            }
        }
        list($ay, $am, $ad) = explode('-', $a['dob']);
        list($by, $bm, $bd) = explode('-', $b['dob']);
        if ($diff === 1 || ($ay === $by && $am === $bd && $ad === $bm) || ($am === $bm && $ad === $bd && abs((int) $ay - (int) $by) === 1)) {
            return 'NEAR';
        }
        return 'CONFLICT';
    }

    private static function age_on($dob, $date) {
        if ($dob === '' || !$date) {
            return null;
        }
        $a = new DateTime($dob);
        $b = new DateTime(substr($date, 0, 10));
        return (int) $a->diff($b)->y;
    }

    /**
     * Groups of likely duplicates (cached for 15 minutes):
     * array('groups' => list of array(ids, tier, reasons, flags, signature,
     * suggested, first_seen), 'scanned_at' => time()).
     */
    public static function scan($force = false) {
        if (!$force) {
            $cached = get_transient(self::SCAN_TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $acc = self::load_accounts();

        // A phone used by three or more different names is a family or club number.
        $phone_names = array();
        foreach ($acc as $a) {
            foreach ($a['phones'] as $p) {
                $phone_names[$p][$a['first'] . '|' . $a['last']] = true;
            }
        }

        $buckets = array();
        foreach ($acc as $a) {
            $keys = array();
            if ($a['first'] !== '' && $a['last'] !== '') {
                $keys[] = 'n:' . $a['first'] . '|' . $a['last'];
            }
            if ($a['dob'] !== '') {
                if ($a['last'] !== '') {
                    $keys[] = 'ld:' . $a['last'] . '|' . $a['dob'];
                }
                if ($a['first'] !== '') {
                    $keys[] = 'fd:' . $a['first_tok'] . '|' . $a['dob'];
                }
            }
            if ($a['bag'] !== '') {
                $keys[] = 'b:' . $a['bag'];
            }
            foreach ($a['phones'] as $p) {
                if (count($phone_names[$p]) < 3) {
                    $keys[] = 'p:' . $p;
                }
            }
            foreach ($a['emails'] as $e) {
                $keys[] = 'e:' . $e;
            }
            foreach ($a['gmail'] as $g) {
                $keys[] = 'g:' . $g;
            }
            foreach ($a['locals'] as $l) {
                $keys[] = 'lp:' . $l;
            }
            if ($a['sid'] !== '') {
                $keys[] = 's:' . $a['sid'];
            }
            foreach (array_unique($keys) as $k) {
                $buckets[$k][] = $a['id'];
            }
        }

        // Decisions already made by an admin.
        $cannot = array();
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::decisions_table()))) {
            foreach ($wpdb->get_results('SELECT user_lo, user_hi FROM ' . self::decisions_table(), ARRAY_A) as $d) {
                $cannot[$d['user_lo'] . '-' . $d['user_hi']] = true;
            }
        }

        $edges = array('strong' => array(), 'likely' => array(), 'possible' => array());
        $seen_pairs = array();
        foreach ($buckets as $k => $ids) {
            if (count($ids) < 2 || count($ids) > self::BUCKET_MAX) {
                continue;
            }
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $lo = min($ids[$i], $ids[$j]);
                    $hi = max($ids[$i], $ids[$j]);
                    $pk = "$lo-$hi";
                    if (isset($seen_pairs[$pk])) {
                        continue;
                    }
                    $seen_pairs[$pk] = true;
                    $res = self::judge($acc[$lo], $acc[$hi], $phone_names);
                    if ($res === null) {
                        continue;
                    }
                    if ($res['tier'] === 'cannot') {
                        $cannot[$pk] = true;
                        continue;
                    }
                    if (isset($cannot[$pk])) {
                        continue;
                    }
                    $edges[$res['tier']][] = array($lo, $hi, $res['reasons'], $res['flags']);
                }
            }
        }

        // Strong, then Likely, never joining a cannot-link pair.
        $parent = array();
        $members = array();
        $find = function ($x) use (&$parent, &$find) {
            if (!isset($parent[$x])) {
                $parent[$x] = $x;
            }
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $info = array();
        // Two accounts that never shared a bucket were never judged, so a
        // chain (A-B, B-C) could still join a parent and child: judge every
        // pair a join would bring together, and never join different
        // birth dates.
        $apart = function ($ma, $mb) use (&$cannot, &$seen_pairs, $acc, $phone_names) {
            foreach ($ma as $x) {
                foreach ($mb as $y) {
                    $pk = min($x, $y) . '-' . max($x, $y);
                    if (!isset($seen_pairs[$pk])) {
                        $seen_pairs[$pk] = true;
                        $res = self::judge($acc[min($x, $y)], $acc[max($x, $y)], $phone_names);
                        if ($res !== null && $res['tier'] === 'cannot') {
                            $cannot[$pk] = true;
                        }
                    }
                    if (isset($cannot[$pk]) || self::dob_relation($acc[$x], $acc[$y]) === 'CONFLICT') {
                        return true;
                    }
                }
            }
            return false;
        };
        $join = function ($a, $b, $tier, $reasons, $flags) use (&$parent, &$members, &$find, &$info, $apart) {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra === $rb) {
                $info[$ra]['reasons'] = array_values(array_unique(array_merge($info[$ra]['reasons'], $reasons)));
                $info[$ra]['flags'] = array_values(array_unique(array_merge($info[$ra]['flags'], $flags)));
                return;
            }
            $ma = isset($members[$ra]) ? $members[$ra] : array($a);
            $mb = isset($members[$rb]) ? $members[$rb] : array($b);
            if ($apart($ma, $mb)) {
                return;
            }
            $parent[$rb] = $ra;
            $members[$ra] = array_merge($ma, $mb);
            unset($members[$rb]);
            $ia = isset($info[$ra]) ? $info[$ra] : array('tier' => 'strong', 'reasons' => array(), 'flags' => array());
            $ib = isset($info[$rb]) ? $info[$rb] : array('tier' => 'strong', 'reasons' => array(), 'flags' => array());
            $info[$ra] = array(
                'tier' => ($tier === 'likely' || $ia['tier'] === 'likely' || $ib['tier'] === 'likely') ? 'likely' : 'strong',
                'reasons' => array_values(array_unique(array_merge($ia['reasons'], $ib['reasons'], $reasons))),
                'flags' => array_values(array_unique(array_merge($ia['flags'], $ib['flags'], $flags))),
            );
            unset($info[$rb]);
        };
        foreach (array('strong', 'likely') as $tier) {
            foreach ($edges[$tier] as $e) {
                $join($e[0], $e[1], $tier, $e[2], $e[3]);
            }
        }
        $groups = array();
        $grouped = array();
        foreach ($members as $root => $ids) {
            sort($ids);
            foreach ($ids as $id) {
                $grouped[$id] = $root;
            }
            $groups[] = array('ids' => $ids, 'tier' => $info[$root]['tier'], 'reasons' => $info[$root]['reasons'], 'flags' => $info[$root]['flags']);
        }

        // Possible: little more than the name; separate groups, never
        // chained into the ones above, and the same keep-apart rules.
        $pparent = array();
        $pmembers = array();
        $pinfo = array();
        $pfind = function ($x) use (&$pparent, &$pfind) {
            if (!isset($pparent[$x])) {
                $pparent[$x] = $x;
            }
            return $pparent[$x] === $x ? $x : ($pparent[$x] = $pfind($pparent[$x]));
        };
        foreach ($edges['possible'] as $e) {
            if (isset($grouped[$e[0]], $grouped[$e[1]]) && $grouped[$e[0]] === $grouped[$e[1]]) {
                continue;
            }
            $ra = $pfind($e[0]);
            $rb = $pfind($e[1]);
            if ($ra !== $rb) {
                $ma = isset($pmembers[$ra]) ? $pmembers[$ra] : array($e[0]);
                $mb = isset($pmembers[$rb]) ? $pmembers[$rb] : array($e[1]);
                if ($apart($ma, $mb)) {
                    continue;
                }
                $pparent[$rb] = $ra;
                $pmembers[$ra] = array_merge($ma, $mb);
                unset($pmembers[$rb]);
                $ia = isset($pinfo[$ra]) ? $pinfo[$ra] : array('reasons' => array(), 'flags' => array());
                $ib = isset($pinfo[$rb]) ? $pinfo[$rb] : array('reasons' => array(), 'flags' => array());
                unset($pinfo[$rb]);
            } else {
                $ia = $pinfo[$ra];
                $ib = array('reasons' => array(), 'flags' => array());
            }
            $pinfo[$ra] = array(
                'reasons' => array_values(array_unique(array_merge($ia['reasons'], $ib['reasons'], $e[2]))),
                'flags' => array_values(array_unique(array_merge($ia['flags'], $ib['flags'], $e[3]))),
            );
        }
        foreach ($pmembers as $root => $ids) {
            sort($ids);
            $groups[] = array('ids' => $ids, 'tier' => 'possible', 'reasons' => $pinfo[$root]['reasons'], 'flags' => $pinfo[$root]['flags']);
        }

        // Suggested keeper, "new" marks, order.
        $seen = get_option(self::SEEN_OPTION, null);
        $first_run = !is_array($seen);
        $seen = is_array($seen) ? $seen : array();
        $now = time();
        $still = array();
        foreach ($groups as &$g) {
            $g['signature'] = implode(',', $g['ids']);
            $g['suggested'] = self::suggest_keeper($g['ids'], $acc);
            if (!isset($seen[$g['signature']])) {
                $seen[$g['signature']] = $first_run ? 0 : $now;
            }
            $g['first_seen'] = (int) $seen[$g['signature']];
            $still[$g['signature']] = $g['first_seen'];
            $g['last_seen'] = 0;
            foreach ($g['ids'] as $id) {
                $g['last_seen'] = max($g['last_seen'], $acc[$id]['last_seen']);
            }
        }
        unset($g);
        update_option(self::SEEN_OPTION, $still, false);
        // The menu badge reads this, so it survives the scan cache being dropped.
        $badge = array();
        foreach ($groups as $g) {
            if ($g['tier'] !== 'possible') {
                $badge[] = $g['first_seen'];
            }
        }
        update_option(self::BADGE_OPTION, $badge, false);
        $rank = array('strong' => 0, 'likely' => 1, 'possible' => 2);
        usort($groups, function ($a, $b) use ($rank) {
            if ($rank[$a['tier']] !== $rank[$b['tier']]) {
                return $rank[$a['tier']] - $rank[$b['tier']];
            }
            if ($a['last_seen'] !== $b['last_seen']) {
                return $b['last_seen'] - $a['last_seen'];
            }
            return $a['ids'][0] - $b['ids'][0];
        });

        $result = array('groups' => $groups, 'scanned_at' => $now, 'accounts' => count($acc));
        set_transient(self::SCAN_TRANSIENT, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    /** One pair: null (unrelated), array('tier' => 'cannot'), or tier + reasons + flags. */
    private static function judge($a, $b, $phone_names) {
        $name = self::name_level($a, $b);
        $dob = self::dob_relation($a, $b);

        if ($name === 0) {
            // Same surname and birth date, unrelated first names: twins or siblings.
            if ($a['last'] !== '' && $a['last'] === $b['last'] && $dob === 'SAME') {
                return array('tier' => 'cannot');
            }
            return null;
        }
        if ($dob === 'CONFLICT' || $a['suffix'] !== $b['suffix']) {
            return array('tier' => 'cannot');
        }
        $gender_conflict = $a['gender'] !== '' && $b['gender'] !== '' && $a['gender'] !== $b['gender'];
        if ($gender_conflict && $dob === 'SAME' && $a['last'] === $b['last']) {
            return array('tier' => 'cannot');
        }

        $reasons = array($name === 3 ? 'same name' : 'similar name');
        $strong_c = false;
        $weak_c = false;
        $shared_phones = array_intersect($a['phones'], $b['phones']);
        foreach ($shared_phones as $p) {
            if (count($phone_names[$p]) < 3) {
                $strong_c = true;
                $reasons[] = 'same phone';
                break;
            }
        }
        if (array_intersect($a['emails'], $b['emails'])) {
            $strong_c = true;
            $reasons[] = 'shared email';
        }
        if (array_intersect($a['gmail'], $b['gmail'])) {
            $strong_c = true;
            $reasons[] = 'same Gmail inbox';
        }
        if ($a['sid'] !== '' && $a['sid'] === $b['sid']) {
            $strong_c = true;
            $reasons[] = 'same student number';
        }
        if (array_intersect($a['locals'], $b['locals'])) {
            $weak_c = true;
            $reasons[] = 'similar email';
        }
        if ($dob === 'SAME') {
            $reasons[] = 'same birth date';
        } elseif ($dob === 'NEAR') {
            $reasons[] = 'birth date one typo apart';
        }

        $tier = null;
        if (($name === 3 && $dob === 'SAME') || ($name === 3 && $dob === 'UNKNOWN' && $strong_c) || ($name === 2 && $dob === 'SAME' && $strong_c)) {
            $tier = 'strong';
        } elseif (($name === 2 && $dob === 'SAME') || ($name === 3 && $dob === 'NEAR' && ($strong_c || $weak_c))
            || ($name === 3 && $dob === 'UNKNOWN' && $weak_c) || ($name === 2 && $dob === 'UNKNOWN' && $strong_c)) {
            $tier = 'likely';
        } elseif (($name === 3 && $dob === 'UNKNOWN') || ($name === 3 && $dob === 'NEAR') || ($name === 2 && $dob === 'UNKNOWN' && $weak_c)) {
            $tier = 'possible';
        }
        if ($tier === null) {
            return null;
        }
        $flags = array();
        if ($gender_conflict) {
            $tier = $tier === 'strong' ? 'likely' : ($tier === 'likely' ? 'possible' : null);
            $flags[] = 'different genders recorded';
            if ($tier === null) {
                return null;
            }
        }
        // Ella and Bella Lee, same birthday, mum's phone on both: twins look
        // like this. A near first name with the same surname and birth date
        // is never Strong.
        // Mary and Mary Ann can be one person; Mary-Kate and Mary-Ann can't.
        $near_first = $a['last'] !== '' && $a['last'] === $b['last'] && $a['first'] !== $b['first']
            && !($a['first'] === $b['first_tok'] || $b['first'] === $a['first_tok']);
        if ($near_first && $dob === 'SAME') {
            if ($tier === 'strong') {
                $tier = 'likely';
            }
            $flags[] = 'could be twins or siblings: check both first names';
        }
        // A dated junior and an undated account with the same name: maybe a parent.
        if ($dob === 'UNKNOWN') {
            foreach (array(array($a, $b), array($b, $a)) as $p) {
                $age = self::age_on($p[0]['dob'], $p[1]['registered']);
                if ($age !== null && $age < 18) {
                    if ($tier === 'strong') {
                        $tier = 'likely';
                    }
                    $flags[] = 'check this is not a parent with the same name';
                }
            }
        }
        return array('tier' => $tier, 'reasons' => array_values(array_unique($reasons)), 'flags' => $flags);
    }

    private static function suggest_keeper($ids, $acc) {
        $staff = array('administrator', 'editor', 'shop_manager', 'author', 'contributor');
        $best = null;
        $score = null;
        foreach ($ids as $id) {
            $a = $acc[$id];
            $s = array(
                array_intersect($a['roles'], $staff) ? 1 : 0,
                $a['status'] === 'approved' || $a['status'] === '' ? 1 : 0,
                $a['last_seen'],
                strtotime($a['registered']),
            );
            if ($score === null || $s > $score) {
                $score = $s;
                $best = $id;
            }
        }
        return $best;
    }

    /**
     * Groups first seen in the last NEW_DAYS (Strong and Likely only), from
     * the last scan. Kept in its own option, so a registration dropping the
     * scan cache doesn't hide it; a scan runs a few minutes after accounts
     * change and once a day (rescan()).
     */
    public static function new_count() {
        $badge = get_option(self::BADGE_OPTION, array());
        $n = 0;
        $since = time() - self::NEW_DAYS * DAY_IN_SECONDS;
        foreach ((array) $badge as $first_seen) {
            if ((int) $first_seen >= $since) {
                $n++;
            }
        }
        return $n;
    }

    /** The background scan (cron): refreshes the list and the menu badge. */
    public static function rescan() {
        self::scan(true);
    }

    // ------------------------------------------------------------------
    // Search (the page's search box)
    // ------------------------------------------------------------------

    /**
     * What was typed: a phone number (8 or more digits in any format), or
     * words that must all be found on one account (any order, any case,
     * accents ignored). Null when nothing was typed.
     */
    public static function parse_search($term) {
        $term = trim((string) $term);
        if ($term === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $term);
        if (preg_match('/^[\d\s+().\-]+$/', $term) && strlen($digits) >= 8) {
            $p = self::phone($term);
            return array('phone' => $p !== '' ? $p : $digits, 'words' => array());
        }
        $words = preg_split('/\s+/', strtolower(remove_accents($term)));
        return array('phone' => '', 'words' => array_values(array_filter($words, 'strlen')));
    }

    /** One account's searchable text, from its number, names, emails, login and phones. */
    public static function search_entry($id, $strings, $phones = array()) {
        $text = array();
        foreach ($strings as $s) {
            $s = trim((string) $s);
            if ($s !== '') {
                $text[] = strtolower(remove_accents(html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
            }
        }
        return array('id' => (int) $id, 'text' => implode("\n", $text), 'phones' => $phones);
    }

    /** Searchable entries for these accounts (one query); deleted accounts are left out. */
    public static function search_entries($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return array();
        }
        $keys = array('first_name', 'last_name', 'preferred_name', 'billing_email', 'secondary_user_email', 'mobile_number', 'billing_phone');
        $cols = '';
        foreach ($keys as $i => $k) {
            $cols .= $wpdb->prepare(", MAX(CASE WHEN m.meta_key = %s THEN m.meta_value END) AS k$i", $k);
        }
        $in_keys = "'" . implode("','", array_map('esc_sql', $keys)) . "'";
        $rows = $wpdb->get_results("SELECT u.ID, u.user_login, u.user_email, u.display_name $cols
            FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key IN ($in_keys)
            WHERE u.ID IN (" . implode(',', $ids) . ') GROUP BY u.ID', ARRAY_A);
        $out = array();
        foreach ($rows as $r) {
            $phones = array();
            foreach (array($r['k5'], $r['k6']) as $p) {
                $p = self::phone($p);
                if ($p !== '') {
                    $phones[] = $p;
                }
            }
            $out[(int) $r['ID']] = self::search_entry($r['ID'], array($r['display_name'], $r['user_login'], $r['user_email'], $r['k0'], $r['k1'], $r['k2'], $r['k3'], $r['k4']), $phones);
        }
        return $out;
    }

    /**
     * Does one account match? A number word is the account number (#449 or
     * 449); from 4 digits it may also be part of an email or login, and from
     * 6 part of a phone.
     */
    public static function search_matches($entry, $q) {
        if ($q['phone'] !== '') {
            foreach ($entry['phones'] as $p) {
                if (strpos($p, $q['phone']) !== false || strpos($q['phone'], $p) !== false) {
                    return true;
                }
            }
            return false;
        }
        foreach ($q['words'] as $w) {
            $n = ltrim($w, '#');
            if ($n !== '' && ctype_digit($n)) {
                if ((int) $n === $entry['id']) {
                    continue;
                }
                if (strlen($n) >= 4 && $w === $n && strpos($entry['text'], $n) !== false) {
                    continue;
                }
                $in_phone = false;
                $needle = ltrim($n, '0');
                if (strlen($n) >= 6 && strlen($needle) >= 5) {
                    foreach ($entry['phones'] as $p) {
                        $in_phone = $in_phone || strpos($p, $needle) !== false;
                    }
                }
                if ($in_phone) {
                    continue;
                }
                return false;
            }
            if (strpos($entry['text'], $w) === false) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // What the admin sees per account
    // ------------------------------------------------------------------

    /**
     * Facts for choosing the keeper, for a page of accounts (batched):
     * id => array(orders, last_order, club (season lists), tier, roles,
     * status, last_seen, profile_year, dob, mobile, emails).
     */
    public static function details($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return array();
        }
        $in = implode(',', $ids);
        $out = array();
        foreach ($ids as $id) {
            $u = get_userdata($id);
            if (!$u) {
                continue;
            }
            $seen = array();
            foreach (array('itsec_user_activity_last_seen', 'wc_last_active') as $k) {
                $v = (string) get_user_meta($id, $k, true);
                if (ctype_digit($v)) {
                    $seen[] = (int) $v;
                }
            }
            $um = (string) get_user_meta($id, '_um_last_login', true);
            if ($um !== '') {
                $seen[] = ctype_digit($um) ? (int) $um : (int) strtotime($um . ' UTC');
            }
            $out[$id] = array(
                'user' => $u,
                'orders' => 0,
                'last_order' => '',
                'spent' => 0,
                'club' => array(),
                'tier' => '',
                'status' => (string) get_user_meta($id, 'account_status', true),
                'last_seen' => $seen ? max($seen) : 0,
                'profile_year' => (string) get_user_meta($id, 'profile_confirmed_year', true),
                'dob' => (string) get_user_meta($id, 'birth_date', true),
                'mobile' => (string) get_user_meta($id, 'mobile_number', true),
                'billing_email' => (string) get_user_meta($id, 'billing_email', true),
                'form' => (string) get_user_meta($id, 'form_id', true),
            );
        }
        $stats = $wpdb->prefix . 'wc_order_stats';
        $look = $wpdb->prefix . 'wc_customer_lookup';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats))) {
            foreach ($wpdb->get_results("SELECT cl.user_id, COUNT(*) n, MAX(os.date_created) last, SUM(os.total_sales) spent FROM $stats os JOIN $look cl ON cl.customer_id = os.customer_id WHERE cl.user_id IN ($in) AND os.parent_id = 0 AND os.status IN ('wc-completed','wc-processing') GROUP BY cl.user_id", ARRAY_A) as $r) {
                if (isset($out[$r['user_id']])) {
                    $out[$r['user_id']]['orders'] = (int) $r['n'];
                    $out[$r['user_id']]['last_order'] = $r['last'];
                    $out[$r['user_id']]['spent'] = (float) $r['spent'];
                }
            }
        }
        foreach (array('team_assignments' => 'Team', 'team_invoices' => 'Fees', 'trial_applications' => 'Trial') as $short => $label) {
            $table = $wpdb->prefix . $short;
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                continue;
            }
            foreach ($wpdb->get_results("SELECT user_id, GROUP_CONCAT(DISTINCT season ORDER BY season DESC) seasons FROM $table WHERE user_id IN ($in) GROUP BY user_id", ARRAY_A) as $r) {
                if (isset($out[$r['user_id']])) {
                    $out[$r['user_id']]['club'][] = $label . ' ' . str_replace(',', ', ', $r['seasons']);
                }
            }
        }
        $today = current_time('Y-m-d');
        foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id, tier FROM {$wpdb->prefix}team_memberships WHERE user_id IN ($in) AND start_date <= %s AND end_date >= %s", $today, $today), ARRAY_A) as $r) {
            $rank = array('life-member' => 3, 'full-member' => 2, 'associate-member' => 1);
            $cur = $out[$r['user_id']]['tier'];
            if ($cur === '' || (isset($rank[$r['tier']], $rank[$cur]) && $rank[$r['tier']] > $rank[$cur])) {
                $out[$r['user_id']]['tier'] = $r['tier'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Decisions
    // ------------------------------------------------------------------

    /** Mark every pair in $ids as different people. */
    public static function dismiss($ids, $reason = 'other', $note = '') {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $n = 0;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $n += (int) $wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . self::decisions_table() . ' (user_lo, user_hi, decision, reason, note, decided_by, decided_at) VALUES (%d, %d, %s, %s, %s, %d, %s)',
                    min($ids[$i], $ids[$j]), max($ids[$i], $ids[$j]), 'different', substr($reason, 0, 30), substr($note, 0, 255), get_current_user_id(), current_time('mysql')
                ));
            }
        }
        self::forget_scan();
        if (class_exists('TeamOversight_Log')) {
            TeamOversight_Log::add('duplicate_dismissed', 'Marked accounts #' . implode(', #', $ids) . ' as different people.', array('user_id' => $ids[0]));
        }
        return $n;
    }

    public static function undismiss($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $in = implode(',', $ids);
        if ($in === '') {
            return 0;
        }
        $n = (int) $wpdb->query('DELETE FROM ' . self::decisions_table() . " WHERE user_lo IN ($in) AND user_hi IN ($in)");
        self::forget_scan();
        return $n;
    }

    /** Groups of accounts marked as different people (connected pairs). */
    public static function dismissed_groups() {
        global $wpdb;
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::decisions_table()))) {
            return array();
        }
        $rows = $wpdb->get_results('SELECT user_lo, user_hi, reason, decided_by, decided_at FROM ' . self::decisions_table() . ' ORDER BY decided_at DESC, id DESC', ARRAY_A);
        // Connected pairs, so a decision linking two others is never left out.
        $parent = array();
        $find = function ($x) use (&$parent, &$find) {
            if (!isset($parent[$x])) {
                $parent[$x] = $x;
            }
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($rows as $r) {
            $ra = $find((int) $r['user_lo']);
            $rb = $find((int) $r['user_hi']);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        }
        $groups = array();
        foreach ($rows as $r) {
            $root = $find((int) $r['user_lo']);
            if (!isset($groups[$root])) {
                // The newest decision in the group speaks for it.
                $groups[$root] = array('ids' => array(), 'reason' => $r['reason'], 'by' => (int) $r['decided_by'], 'at' => $r['decided_at']);
            }
        }
        foreach (array_keys($parent) as $x) {
            $groups[$find($x)]['ids'][] = $x;
        }
        foreach ($groups as &$g) {
            sort($g['ids']);
        }
        unset($g);
        return array_values($groups);
    }

    /** Before a merge deletes accounts, carry their decisions over to the keeper. */
    /**
     * Returns what it did, for the merge's backup (so Undo can put it back):
     * 'deleted' (whole rows) and 'inserted' (ids of rows it added).
     */
    public static function rekey_decisions($keep_id, $merged_ids) {
        global $wpdb;
        $done = array('deleted' => array(), 'inserted' => array());
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::decisions_table()))) {
            return $done;
        }
        foreach ((array) $merged_ids as $old) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::decisions_table() . ' WHERE user_lo = %d OR user_hi = %d', $old, $old), ARRAY_A);
            foreach ($rows as $r) {
                $other = (int) $r['user_lo'] === (int) $old ? (int) $r['user_hi'] : (int) $r['user_lo'];
                $wpdb->delete(self::decisions_table(), array('id' => $r['id']));
                $done['deleted'][] = $r;
                if ($other === (int) $keep_id || in_array($other, array_map('intval', (array) $merged_ids), true)) {
                    continue;
                }
                $added = $wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . self::decisions_table() . ' (user_lo, user_hi, decision, reason, note, decided_by, decided_at) VALUES (%d, %d, %s, %s, %s, %d, %s)',
                    min($keep_id, $other), max($keep_id, $other), $r['decision'], $r['reason'], (string) $r['note'], (int) $r['decided_by'], $r['decided_at']
                ));
                if ($added === 1) {
                    $done['inserted'][] = (int) $wpdb->insert_id;
                }
            }
        }
        return $done;
    }

    public static function forget_decisions_for($user_id) {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::decisions_table()))) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . self::decisions_table() . ' WHERE user_lo = %d OR user_hi = %d', $user_id, $user_id));
        }
    }

    public static function after_merge($keep_id, $merged_ids) {
        self::forget_scan();
    }
}
