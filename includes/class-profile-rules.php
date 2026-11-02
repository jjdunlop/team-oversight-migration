<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Profile rules (1.81.2): what the member's own profile answers mean, as
 * pure static functions. No hooks, no database reads or writes, no output:
 * every input is passed in, so any screen, email or calculation can ask the
 * same question and get the same answer. Later releases add to it.
 *
 * Today it holds the meaning of each stored MU Sport category
 * (MUSEligibilityCategory) and the 2027+ fee rule's adapter,
 * fee_institution(): TeamOversight_Fees::determine_fee_class() asks it which
 * institution a person studies at for a season from 2027, and Payment
 * Management's fee-info line asks fee_institution_source() for the same
 * answer with where it came from. Never copy the rule: add a caller.
 */
class TeamOversight_Profile_Rules {

    /**
     * Stored MU Sport category => its meaning. The stored value is the
     * option text already on file and never changes; the last two are
     * retired options still held by older accounts.
     */
    const MUS_MEANINGS = array(
        'Melbourne University - Undergrad. Student' => 'mu_undergrad',
        'Melbourne University - Postgrad. Student' => 'mu_postgrad',
        'Melbourne University - Alumni' => 'mu_alumni',
        'Melbourne University - Staff' => 'mu_staff',
        'Student of another university' => 'other_uni_student',
        'Alumni of another university' => 'other_uni_alumni',
        'Junior (U/19) - High school or below' => 'junior',
        'Other' => 'other',
        'Student/Alumni of another university' => 'other_uni_mixed',
        'Adult - High School Only' => 'other',
    );

    /** The two institutions the fee classes Melb Uni Student and Other Student follow. */
    const FEE_INSTITUTIONS = array('Melbourne University', 'Other University');

    /** The meaning of a stored MU Sport category: '' when it is empty or not a known value. */
    public static function category_meaning($stored) {
        $stored = trim((string) $stored);
        return ($stored !== '' && isset(self::MUS_MEANINGS[$stored])) ? self::MUS_MEANINGS[$stored] : '';
    }

    /** A profile date (YYYY/MM/DD as stored, or YYYY-MM-DD) as 'Y-m-d'; null when it isn't a real date. */
    public static function parse_date($raw) {
        if (!is_scalar($raw)) {
            return null;
        }
        return TeamOversight_Fee_Timeline::parse_date(str_replace('/', '-', trim((string) $raw)));
    }

    /**
     * Is a student MU Sport category current enough to price the season by?
     * Yes when the member confirmed their profile for the season or the year
     * before (profile_confirmed_year), or gave a course end date
     * (degree1enddate; the fee rule's alumni check has already dealt with
     * one that has passed). Otherwise the category may be years old.
     */
    public static function category_trusted(array $p, $season) {
        $confirmed = intval(self::field($p, 'profile_confirmed_year'));
        if ($confirmed > 0 && $confirmed >= intval($season) - 1) {
            return true;
        }
        return self::parse_date(self::field($p, 'degree1enddate')) !== null;
    }

    /**
     * The institution the 2027+ fee class follows, and where it came from.
     * $p holds the stored MUSEligibilityCategory, institution1,
     * degree1enddate and profile_confirmed_year (strings, possibly '').
     * Returns array('institution' => 'Melbourne University' | 'Other
     * University' | '', 'source' => 'category' | 'institution1' | 'none',
     * 'meaning' => the category's meaning or '').
     *
     * - An MU undergraduate or postgraduate category, trusted: Melbourne
     *   University. A student of another university, trusted: Other
     *   University.
     * - The retired "Student/Alumni of another university": Other University
     *   only with a course end date, else none.
     * - Every other known category (alumni, staff, junior, other): none,
     *   from the category, never institution1.
     * - A student category that isn't trusted, an empty category or an
     *   unknown value: institution1 as held, which only the old registration
     *   forms wrote (the rule before 1.81.2).
     */
    public static function fee_institution_source(array $p, $season) {
        $meaning = self::category_meaning(self::field($p, 'MUSEligibilityCategory'));
        switch ($meaning) {
            case 'mu_undergrad':
            case 'mu_postgrad':
                if (self::category_trusted($p, $season)) {
                    return self::source('Melbourne University', 'category', $meaning);
                }
                break;
            case 'other_uni_student':
                if (self::category_trusted($p, $season)) {
                    return self::source('Other University', 'category', $meaning);
                }
                break;
            case 'other_uni_mixed':
                $ended = self::parse_date(self::field($p, 'degree1enddate')) !== null;
                return self::source($ended ? 'Other University' : '', 'category', $meaning);
            case 'mu_alumni':
            case 'mu_staff':
            case 'other_uni_alumni':
            case 'junior':
            case 'other':
                return self::source('', 'category', $meaning);
        }
        // Not trusted, empty or unknown: institution1 exactly as held.
        $institution1 = isset($p['institution1']) && is_scalar($p['institution1']) ? (string) $p['institution1'] : '';
        if (in_array($institution1, self::FEE_INSTITUTIONS, true)) {
            return self::source($institution1, 'institution1', $meaning);
        }
        return self::source('', 'none', $meaning);
    }

    /** The institution of fee_institution_source(): 'Melbourne University', 'Other University' or ''. */
    public static function fee_institution(array $p, $season) {
        $found = self::fee_institution_source($p, $season);
        return $found['institution'];
    }

    private static function source($institution, $source, $meaning) {
        return array('institution' => $institution, 'source' => $source, 'meaning' => $meaning);
    }

    /** One value of $p as a string ('' when missing). */
    private static function field(array $p, $key) {
        return isset($p[$key]) && is_scalar($p[$key]) ? trim((string) $p[$key]) : '';
    }
}
