<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The wording of offers that is not an email body (1.78.0): the Early Offer
 * explanation, the money policy, the sentences the offer emails are built
 * from, the lines on the player's offer page and the words in the coach
 * portal. ONE catalogue, so an administrator can read every sentence in one
 * place, see where it appears and what it can fill, and put any of them back.
 *
 * The rules (the same as the email wording, so nothing new to learn):
 *  - the built-in text is the default in catalogue(); what an administrator
 *    saves lives in ONE non-autoloaded option (OPTION: key => text);
 *  - a blank box, or text identical to the default, is stored as "built in"
 *    (nothing), so a later improvement to the default still reaches a site
 *    that never customised that line;
 *  - text() fills {placeholders} from the values the caller gives, and the
 *    two money-policy sentences ({policy_club}, {policy_player}) from the
 *    policy items, so editing the policy once changes everything that quotes it;
 *  - the coach portal group may never carry a dollar figure (coaches see
 *    percentages only): save() refuses it;
 *  - plain text only. Output goes through esc_html(); the only mark-up is
 *    **bold**, drawn by html().
 *
 * Callers use TeamOversight_Offers::wording($key, $vars), the same call.
 */
class TeamOversight_Offer_Wording {

    /** key => text, only for lines an administrator changed. Non-autoloaded. */
    const OPTION = 'team_oversight_offer_wording';

    /** The longest a line may be (characters). */
    const MAX_LENGTH = 1200;

    /** The two policy sentences other wording may quote. */
    const POLICY_KEYS = array('policy_club', 'policy_player');

    private static $saved_cache = null;
    private static $catalogue_cache = null;

    /** group => heading, in the order the admin screen shows them. */
    public static function groups() {
        return array(
            'early' => array('Early Offer and the fee', 'What an Early Offer is, and how the fee is described before it is set.'),
            'policy' => array('Money policy', 'What happens to a deposit. These sentences are quoted by the commitments list and by the notes on a withdrawal, a decline and a finished offer, so a change here reaches all of them.'),
            'email' => array('Sentences used inside the offer emails', 'The short sentences the email wording fills in with {reply_sentence}, {deposit_sentence}, {deposit_status}, {reason_line}, {deposit_line}, {deposit_note} and {overdue_what}, and the "not selected" email\'s {other_teams}, its suggestions for what to do next and its sign-off ({reply_line}, {coach_title}, and {coach_name} when no coach can be named).'),
            'page' => array('The offer page', 'What the player reads on their offer page, in the deposit box, in the offers banner at the top of the Player Checklist, and on the Player Checklist once they have accepted an offer.'),
            'coach' => array('The coach portal', 'The flow chart, the hints and the confirm boxes, and the Send "not selected" email button with what the card says about it. Coaches see percentages only, so these may never carry a dollar figure.'),
        );
    }

    /** One catalogue entry. */
    private static function item($group, $label, $where, $default, array $vars = array(), $rows = 2) {
        return array('group' => $group, 'label' => $label, 'where' => $where, 'default' => $default, 'vars' => $vars, 'rows' => $rows);
    }

    /**
     * Every line: key => array(group, label, where (plain words), default,
     * vars (placeholder => what it is), rows). The defaults are today's
     * wording, character for character (a test holds them to it).
     */
    public static function catalogue() {
        if (self::$catalogue_cache !== null) {
            return self::$catalogue_cache;
        }
        $limit = array('{limit}' => 'the upper limit quoted, e.g. $612.00');
        $c = array();

        // ---- Early Offer and the fee ----
        $c['early_unset'] = self::item('early', 'Early Offer explanation: the season fee is not set yet',
            'The offer page, the {early_note} of the offer email and its two reminders, before the fees are released.',
            'Because this is an Early Offer, your season fee isn\'t set yet. It\'s set in {fee_month} and won\'t be more than {limit}, the upper limit.',
            array_merge($limit, array('{fee_month}' => 'the month fees are released, from the season\'s fee release date, e.g. January')), 3);
        $c['early_releasing'] = self::item('early', 'Early Offer explanation: the fees are being set now',
            'The same places, once the fees have been released but this player\'s own fee is not worked out yet.',
            'Because this is an Early Offer, your season fee isn\'t set yet. It\'s being set now and won\'t be more than {limit}, the upper limit.',
            $limit, 3);
        $c['early_deposit'] = self::item('early', 'Early Offer explanation: the deposit sentence',
            'Added after either explanation above when the offer has a deposit.',
            'Your deposit is {percent}% of that upper limit and comes off your final fee.',
            array('{percent}' => 'the deposit percent, e.g. 10'), 2);
        $c['early_set'] = self::item('early', 'Early Offer explanation: the real fee is now known',
            'The offer page and the emails once the season fee is worked out for an Early Offer.',
            'This was an Early Offer, sent before the season fee was set. Your season fee is now {real_fee}, which is not more than the {limit} we quoted.',
            array_merge($limit, array('{real_fee}' => 'the real season fee, e.g. $540.00')), 3);
        $c['early_set_deposit'] = self::item('early', 'Early Offer explanation: the deposit sentence once the fee is known',
            'Added after the sentence above when the offer has a deposit.',
            'Your deposit comes off your final fee.', array(), 2);
        $c['fee_upto_unset'] = self::item('early', 'The fee as the player is told it, before it is set',
            'The {fee} of the offer emails, the Fee row of the offer box and the offer page.',
            'up to {limit}, set in {fee_month}',
            array_merge($limit, array('{fee_month}' => 'the month fees are released, e.g. January')), 1);

        // ---- Money policy ----
        $c['policy_club'] = self::item('policy', 'If the club breaks its commitment',
            'The first commitments list (our commitment to you).',
            'If we don\'t keep our side, you can have your deposit back. That includes your fee coming in above the maximum we quoted, or your training days changing.',
            array(), 3);
        $c['policy_player'] = self::item('policy', 'If the player breaks theirs',
            'The deposit list on the offer page and in the offer emails.',
            'If you don\'t keep yours (you decline after paying something, miss the deposit date and are moved to Declined, or pull out of the team), your deposit isn\'t refunded.',
            array(), 3);
        $c['deposit_back_note'] = self::item('policy', 'Withdrawal email: the deposit is yours to have back',
            'The {deposit_note} of the "offer withdrawn" email, only when the player had paid something.',
            'Because this was our decision, the {held} you paid towards your deposit is yours to have back. The club will be in touch to arrange the refund.',
            array('{held}' => 'the deposit paid, e.g. $20.00'), 3);
        $c['deposit_kept_note'] = self::item('policy', 'Coaches\' declined email: the deposit is kept',
            'The {deposit_line} of the "player declined" email to the coaches, only when a deposit had been paid.',
            'Their deposit had already been paid, so it isn\'t refunded.', array(), 2);
        $c['decline_warning'] = self::item('policy', 'Offer page: warning when declining after paying',
            'The decline box on the offer page, only when the player has paid part of the deposit.',
            '**You have already paid {held} toward your deposit. A deposit is not refunded if you decline.**',
            array('{held}' => 'the deposit paid so far'), 2);
        $c['page_money_forfeited'] = self::item('policy', 'Offer page: a finished offer, deposit kept',
            'The offer page of an offer that ended with the deposit kept.',
            'The deposit you paid ({amount}) is not refunded: under the deposit policy it is kept when a player declines after paying, misses the deposit date or pulls out.',
            array('{amount}' => 'the deposit kept'), 3);
        $c['page_money_credit'] = self::item('policy', 'Offer page: a finished offer, deposit held as credit',
            'The offer page of an offer that ended with the club owing the deposit back.',
            'You had paid a deposit of {amount}. The club is holding it as credit on your account and will refund it.',
            array('{amount}' => 'the deposit held'), 3);
        $c['page_money_credit_unknown'] = self::item('policy', 'Offer page: deposit held as credit, amount not known',
            'The same, when no amount was recorded.',
            'You had paid a deposit. The club is holding it as credit on your account and will refund it.', array(), 2);
        $c['page_money_pending'] = self::item('policy', 'Offer page: the club has not decided about the deposit yet',
            'The offer page of an offer that ended while the treasurer has not yet marked the deposit.',
            'You had paid a deposit. The club will be in touch with you about it.', array(), 2);
        $c['page_money_carried'] = self::item('policy', 'Offer page: the deposit moved to another offer',
            'The offer page of an offer whose deposit was applied to the player\'s other offer.',
            'The deposit you paid has been applied to your other offer.', array(), 2);

        // ---- Sentences used inside the offer emails ----
        $c['email_reply_future'] = self::item('email', 'Reply sentence (the reply-by date is ahead)',
            '{reply_sentence} in the offer and its first reminder.',
            'Please accept or decline by {reply_by}.', array('{reply_by}' => 'the reply-by date, e.g. 14 Nov 2026'), 1);
        $c['email_reply_late'] = self::item('email', 'Reply sentence (the reply-by date has passed)',
            '{reply_sentence} once the date has passed.',
            'Your reply was due by {reply_by}, so please accept or decline as soon as you can.', array('{reply_by}' => 'the reply-by date'), 2);
        $c['email_deposit_future'] = self::item('email', 'Deposit sentence, before the player has accepted (date ahead)',
            '{deposit_sentence} in the offer reminder.',
            'Once you accept, pay your deposit of {deposit} by {deposit_due} to lock in your place.',
            array('{deposit}' => 'the deposit, e.g. $61.20', '{deposit_due}' => 'the deposit due date'), 2);
        $c['email_deposit_late'] = self::item('email', 'Deposit sentence, before the player has accepted (date passed)',
            '{deposit_sentence} in the offer reminder once the date has passed.',
            'Once you accept, please pay your deposit of {deposit} as soon as you can to lock in your place.',
            array('{deposit}' => 'the deposit'), 2);
        $c['email_accepted_future'] = self::item('email', 'Deposit sentence, after the player accepted (date ahead)',
            '{deposit_sentence} in the deposit reminder.',
            'Your deposit of {deposit} is due by {deposit_due}.',
            array('{deposit}' => 'the deposit', '{deposit_due}' => 'the deposit due date'), 1);
        $c['email_accepted_late'] = self::item('email', 'Deposit sentence, after the player accepted (date passed)',
            '{deposit_sentence} in the deposit reminder once the date has passed.',
            'Your deposit of {deposit} was due by {deposit_due}, so please pay it as soon as you can.',
            array('{deposit}' => 'the deposit', '{deposit_due}' => 'the deposit due date'), 2);
        $c['email_status_locked'] = self::item('email', 'Coaches\' accepted email: the player is locked in',
            '{deposit_status} when the player accepted and no deposit is needed.',
            'Their deposit is complete, so they are locked in.', array(), 1);
        $c['email_status_due'] = self::item('email', 'Coaches\' accepted email: the deposit is due',
            '{deposit_status} when the player accepted and still has a deposit to pay.',
            'Their deposit is due by {deposit_due}.', array('{deposit_due}' => 'the deposit due date'), 1);
        $c['email_reason_line'] = self::item('email', 'Coaches\' declined email: the player\'s reason',
            '{reason_line}, only when the player gave a reason.',
            'Their reason: {reason}', array('{reason}' => 'what the player wrote'), 1);
        $c['email_overdue_deposit'] = self::item('email', 'Coaches\' deadline email: the deposit was not completed',
            '{overdue_what} when the missed deadline was the deposit.',
            'hasn\'t completed their deposit for the {team} {offer_name}',
            array('{team}' => 'the team', '{offer_name}' => 'Early Offer or offer'), 1);
        $c['email_overdue_reply'] = self::item('email', 'Coaches\' deadline email: no reply',
            '{overdue_what} when the missed deadline was the reply.',
            'hasn\'t replied to the {team} {offer_name}',
            array('{team}' => 'the team', '{offer_name}' => 'Early Offer or offer'), 1);

        // 1.80.0: the "not selected" email is about ONE team (club decision, 28 Oct 2026).
        $ns_team = array('{team}' => 'the one team the email is about, e.g. State League 1 Men');
        $c['ns_other_teams'] = self::item('email', '"Not selected" email: they applied for more than one team',
            '{other_teams} in the "not selected" email, only when the player applied for at least one other team. It never says anything about another team\'s decision.',
            'You applied for more than one team, and this message is only about {team}. Your other applications are decided separately, and you\'ll hear about those from the club.',
            $ns_team, 3);
        $c['ns_lower_intro'] = self::item('email', '"Not selected" email: lower divisions to try (above the list)',
            'The {next_steps} of the "not selected" email, above the list of lower divisions they can still trial for (below that team, by age and gender, in division order, leaving out teams they already applied for or were turned down by).',
            'You can still try for a lower division than {team}. Based on your age and gender you\'re eligible to trial for:',
            $ns_team, 2);
        $c['ns_lower_outro'] = self::item('email', '"Not selected" email: lower divisions to try (under the list)',
            'Under that list, above the Edit my application button.',
            'If you\'d like to be considered, edit your application to add one.', array(), 2);
        $c['ns_pathway_two'] = self::item('email', '"Not selected" email: other ways in (adults, two pages)',
            'The {next_steps} of the "not selected" email when there is no lower division to suggest, above the Mixed Training Program and Reds Social League links.',
            'If you\'d still like to play with us this season, there are two other ways in:', array(), 2);
        $c['ns_pathway_one'] = self::item('email', '"Not selected" email: another way in (adults, one page)',
            'The same, when only one of those two pages is published.',
            'If you\'d still like to play with us this season, there is another way in:', array(), 2);
        $c['ns_pathway_junior'] = self::item('email', '"Not selected" email: the Junior Academy (juniors)',
            'The {next_steps} of the "not selected" email for a junior with no lower division to suggest, above the Junior Academy link. The Academy is extra training.',
            'If you\'d still like to train with us this season, our Junior Academy runs extra training:', array(), 2);
        // The sign-off of the "not selected" email (club decision, 28 Oct 2026): the coach is the voice, as in the offer emails.
        $c['ns_sign_reply'] = self::item('email', '"Not selected" email: the sign-off\'s first line',
            '{reply_line} in the "not selected" email when a coach of the team can be named: the coach who pressed Send when they coach that team, else its head coach. Replies go to that coach.',
            'Questions? Just reply to this email and it comes straight to me.', array(), 1);
        $c['ns_sign_reply_team'] = self::item('email', '"Not selected" email: the sign-off\'s first line, no coach on file',
            'The same when the team has no coach or assistant coach on file: replies go to the trial applications address.',
            'Questions? Just reply to this email and the club will get back to you.', array(), 1);
        $c['ns_sign_title'] = self::item('email', '"Not selected" email: the line under the coach\'s name',
            '{coach_title} in the sign-off, under the coach\'s name ({coach_name}). Left out when no coach can be named.',
            '{team} coach', $ns_team, 1);
        $c['ns_sign_team'] = self::item('email', '"Not selected" email: signed by the coaching team',
            '{coach_name} in the sign-off when the team has no coach or assistant coach on file, so nobody can be named.',
            'The {team} coaching team', $ns_team, 1);

        // ---- The offer page ----
        $c['page_neutral'] = self::item('page', 'No offer found',
            'One message for an offer that is missing, belongs to someone else or is not visible, so the page never reveals which.',
            'No offer found for this account. If you were sent a link, check you are logged in as the person it was sent to, or contact the club.', array(), 3);
        $c['page_none'] = self::item('page', 'The player has no offer',
            'The offer page opened with no offer link, for a player with nothing sent.',
            'You don\'t have an offer at the moment. When a coach sends you one it will be on this page, and we will email you the link.', array(), 3);
        $c['page_late_reply'] = self::item('page', 'The reply-by date has passed',
            'Under a sent offer once its reply-by date is behind.',
            'The reply-by date has passed. You can still answer, but please do it soon, or talk to your coach.', array(), 2);
        $c['page_consent_deposit'] = self::item('page', 'Above the Accept button (with a deposit)',
            'Above Accept and commit when the offer has a deposit.',
            'By accepting you agree to the commitments above and to pay your deposit of {deposit}{by_date}.',
            array('{deposit}' => 'the deposit', '{by_date}' => '" by 14 Nov 2026", or nothing when there is no date'), 2);
        $c['page_consent'] = self::item('page', 'Above the Accept button (no deposit)',
            'Above Accept and commit when the offer needs no deposit.',
            'By accepting you agree to the commitments above.', array(), 1);
        // 1.79.1: the Player Checklist opens when the offer is accepted (1.79.0), not once the player is on the team.
        $c['page_preview_note'] = self::item('page', 'Note above the Player Checklist preview',
            'Above the read-only checklist preview under an offer that has been sent and not answered yet (an accepted offer shows the line below instead).',
            'A preview of your Player Checklist, so you know what to expect. There is nothing to tick here: once you accept, your Player Checklist page has it all, ready to tick off.', array(), 3);
        $c['page_accepted_checklist'] = self::item('page', 'After accepting: the Player Checklist is ready',
            'Under an accepted offer, above the Go to your Player Checklist button (in place of the preview a sent offer shows).',
            '**Your Player Checklist is ready now.** It lists what you need to do before the season, like your registration and your kit, and you can tick things off there as you go. Your place is confirmed once your deposit is paid.', array(), 3);
        $c['page_checklist_button'] = self::item('page', 'The Go to your Player Checklist button',
            'The button under an accepted or locked-in offer that opens the Player Checklist.',
            'Go to your Player Checklist', array(), 1);
        $c['page_accepted_lead'] = self::item('page', 'After accepting: pay the deposit',
            'The first line of an accepted offer.',
            '**You have accepted this offer.** Pay your deposit{by_date} to lock in your place.',
            array('{by_date}' => '" by 14 Nov 2026", or nothing when there is no date'), 2);
        $c['page_deposit_paid'] = self::item('page', 'The deposit is paid in full',
            'The deposit box, once everything is paid and the club is confirming the place.',
            '**Your deposit of {deposit} is paid in full.** The club is confirming your place; you will get an email when you are locked in.',
            array('{deposit}' => 'the deposit'), 2);
        $c['page_deposit_late'] = self::item('page', 'The deposit date has passed',
            'The deposit box when the date is behind.',
            'Your deposit was due on {date}. Please pay as soon as you can, or talk to your coach.', array('{date}' => 'the deposit due date'), 2);
        $c['page_no_payment'] = self::item('page', 'Online payment is not available',
            'The deposit box when no payment product is set on Payment Management.',
            'Online payment isn\'t available yet. Please contact the club to arrange your deposit.', array(), 2);
        // 1.79.1: the deposit box's own lines (it is on the offer page and in the Player Checklist banner).
        $c['page_deposit_amount'] = self::item('page', 'Deposit box: the amount (nothing paid yet)',
            'The first line of the deposit box when nothing has been paid towards it.',
            '**Deposit {deposit}**, **{remaining} to go**.',
            array('{deposit}' => 'the deposit', '{remaining}' => 'what is still to pay (the one payment)'), 1);
        $c['page_deposit_amount_paid'] = self::item('page', 'Deposit box: the amount (part already paid)',
            'The same line when some of it is already paid (for example a two-team top-up).',
            '**Deposit {deposit}**, {paid} paid, **{remaining} to go**.',
            array('{deposit}' => 'the deposit', '{paid}' => 'what is already paid towards it', '{remaining}' => 'what is still to pay (the one payment)'), 1);
        $c['page_deposit_due'] = self::item('page', 'Deposit box: the due date',
            'Under the amount while the deposit date is still ahead.',
            'Due by {date}.', array('{date}' => 'the deposit due date'), 1);
        $c['page_deposit_heading'] = self::item('page', 'Deposit box: the pay box heading',
            'The heading of the pay box inside the deposit box.',
            'Pay your deposit', array(), 1);
        $c['page_deposit_intro'] = self::item('page', 'Deposit box: under the heading',
            'The line under that heading, above the amount to pay and the button.',
            'It is paid in one payment and comes off your season fee.', array(), 2);

        // 1.79.1: the offers banner at the top of the Player Checklist (one short card per offer).
        $c['banner_sent_head'] = self::item('page', 'Checklist banner: an offer to answer',
            'The heading of the banner card for an offer the player has not answered yet.',
            'You have an {offer_name} to join {team}',
            array('{offer_name}' => 'Early Offer or offer', '{team}' => 'the team'), 1);
        $c['banner_sent_reply'] = self::item('page', 'Checklist banner: reply by',
            'Under that heading while the reply-by date is ahead.',
            'Reply by {date}.', array('{date}' => 'the reply-by date'), 1);
        $c['banner_sent_late'] = self::item('page', 'Checklist banner: reply-by date passed',
            'Under that heading once the reply-by date has passed.',
            'The reply-by date ({date}) has passed: please answer soon.', array('{date}' => 'the reply-by date'), 1);
        $c['banner_accepted_head'] = self::item('page', 'Checklist banner: accepted, deposit to pay',
            'The heading of the banner card that holds the deposit box.',
            'Lock in your place with {team}', array('{team}' => 'the team'), 1);
        $c['banner_locked_head'] = self::item('page', 'Checklist banner: locked in',
            'The heading of the banner card once the deposit is complete, before the club has placed them.',
            'You are locked in for {team}', array('{team}' => 'the team'), 1);
        $c['banner_locked_placing'] = self::item('page', 'Checklist banner: the club is placing them',
            'Under that heading.',
            'The club is placing you on the team.', array(), 1);
        $c['banner_withdrawn'] = self::item('page', 'Checklist banner: the club withdrew the offer',
            'The short card shown for a few days after the club withdrew an offer.',
            'The club withdrew your offer for {team}.', array('{team}' => 'the team'), 1);
        $c['banner_closed'] = self::item('page', 'Checklist banner: the offer was closed',
            'The short card shown for a few days after the player was moved to Declined.',
            'Your offer for {team} was closed.', array('{team}' => 'the team'), 1);
        $c['banner_left'] = self::item('page', 'Checklist banner: no longer on the team',
            'The short card shown for a few days after a locked-in player left the team.',
            'You are no longer on {team}.', array('{team}' => 'the team'), 1);
        $c['page_locked_lead'] = self::item('page', 'Locked in: the first line',
            'The top of a locked-in offer.', '**You are locked in for {team}.**', array('{team}' => 'the team'), 1);
        $c['page_locked_placed'] = self::item('page', 'Locked in and on the team',
            'After the first line, once the player has a place on the team.',
            'Your deposit is complete and you are on the team.', array(), 1);
        $c['page_locked_placing'] = self::item('page', 'Locked in, the club is placing them',
            'After the first line, before the place is made.',
            'Your deposit is complete. The club is placing you on the team, and your Player Checklist is ready for you now.', array(), 3);
        $c['decline_intro'] = self::item('page', 'Decline box: the question',
            'The decline confirm box.',
            'You are about to decline your place with {team} for {season}. Your place may then be offered to someone else.',
            array('{team}' => 'the team', '{season}' => 'the season'), 2);
        $c['page_declined'] = self::item('page', 'Finished offer: the player declined',
            'The offer page after the player declined.', 'You declined this offer{on_date}.', array('{on_date}' => '" on 14 Nov 2026", or nothing'), 1);
        $c['page_declined_more'] = self::item('page', 'Finished offer: the player declined, what next',
            'Under the line above.', 'If you changed your mind, talk to your coach.', array(), 1);
        $c['page_closed'] = self::item('page', 'Finished offer: closed after a missed date',
            'The offer page after a coach moved the player to Declined.',
            'This offer was closed{on_date} because the date to reply or to pay the deposit passed.', array('{on_date}' => '" on 14 Nov 2026", or nothing'), 2);
        $c['page_closed_more'] = self::item('page', 'Finished offer: closed, what next',
            'Under the line above.', 'If you still want the place, talk to your coach.', array(), 1);
        $c['page_withdrawn'] = self::item('page', 'Finished offer: withdrawn by the club',
            'The offer page after the club withdrew the offer.', 'The club withdrew this offer{on_date}.', array('{on_date}' => '" on 14 Nov 2026", or nothing'), 1);
        $c['page_withdrawn_more'] = self::item('page', 'Finished offer: withdrawn, what next',
            'Under the line above.', 'If you have questions, contact the club.', array(), 1);
        $c['page_left'] = self::item('page', 'Finished offer: the player left the team',
            'The offer page after a locked-in player left.', 'You are no longer on {team} for {season}.', array('{team}' => 'the team', '{season}' => 'the season'), 1);
        $c['page_left_more'] = self::item('page', 'Finished offer: left, what next',
            'Under the line above.', 'If that is not right, contact the club.', array(), 1);

        // The Player Checklist of an accepted offer (club decision, 28 Oct 2026): the
        // checklist counts the team from the moment they accept, but never
        // says they are on it before the deposit is paid.
        $c['checklist_accepted'] = self::item('page', 'Player Checklist: accepted, deposit still to pay',
            'The top of the Player Checklist for a season where the player has accepted an offer and not yet paid the deposit. The checklist already counts that team (shirt, shorts and socks), but the place is not theirs until the deposit is paid.',
            '**You\'ve accepted a place with {team}.** Pay your deposit to lock it in. Until then your place isn\'t confirmed.',
            array('{team}' => 'the team, e.g. State League 2 Men'), 2);
        $c['checklist_locked'] = self::item('page', 'Player Checklist: locked in, the club is placing them',
            'The top of the Player Checklist once the deposit is complete and before the club has put the player on the team.',
            '**You\'re locked in for {team}.** The club is adding you to the team.',
            array('{team}' => 'the team'), 2);
        $c['checklist_deposit_title'] = self::item('page', 'Player Checklist: the deposit step\'s title',
            'The fees step of the Player Checklist while a deposit is still to pay (in place of "Stay up to date on your fees").',
            'Pay your deposit to lock in your place', array(), 1);
        $c['checklist_deposit_step'] = self::item('page', 'Player Checklist: the deposit step',
            'Under that title. The deposit box itself is at the top of the page, above the checklist, and the step links to it.',
            'Your deposit for {team} is in the box above. Paying it locks in your place, and it comes off your season fee. The rest of your fee follows your payment schedule.',
            array('{team}' => 'the team (two teams are joined with "and")'), 3);
        $c['checklist_deposit_paid'] = self::item('page', 'Player Checklist: the deposit is paid, the club is confirming',
            'The deposit step in the short time between paying the deposit and being locked in.',
            'Your deposit for {team} is paid. The club is confirming your place.',
            array('{team}' => 'the team (two teams are joined with "and")'), 2);
        $c['checklist_deposit_link'] = self::item('page', 'Player Checklist: the deposit step\'s button',
            'The button on the deposit step that jumps to the deposit box above.',
            'Go to your deposit', array(), 1);

        // ---- The coach portal (no dollar figure, ever) ----
        $steps = array(
            'select' => array('Select', 'Selected or Training Only, with no offer sent yet'),
            'send' => array('Send offer', 'The coach presses Send offer on the card, or Send offers to all'),
            'approve' => array('Committee approves', 'Waiting for the committee to approve the offer'),
            'email' => array('Offer emailed', 'The site emails the offer to the player once it is approved'),
            'accept' => array('Player accepts', 'Offer sent, waiting for the player to reply'),
            'deposit' => array('Deposit paid', 'Accepted, waiting for the deposit'),
            'confirmed' => array('Confirmed', 'Locked in and on the team'),
        );
        foreach ($steps as $key => $pair) {
            $c['flow_' . $key . '_name'] = self::item('coach', 'Flow chart step ' . $pair[0] . ': name', 'The flow chart at the top of the team.', $pair[0], array(), 1);
            $c['flow_' . $key . '_tip'] = self::item('coach', 'Flow chart step ' . $pair[0] . ': tooltip', 'The hover text of that step.', $pair[1], array(), 1);
        }
        $exits = array(
            'declined' => array('Declined', 'The player said no, or the coach moved them to Declined'),
            'overdue' => array('Overdue', 'Past a reply or deposit deadline (they are also counted in their step)'),
            'not_approved' => array('Not approved', 'The committee did not approve the offer'),
        );
        foreach ($exits as $key => $pair) {
            $c['exit_' . $key . '_name'] = self::item('coach', 'Flow chart exit ' . $pair[0] . ': name', 'The side exits under the flow chart.', $pair[0], array(), 1);
            $c['exit_' . $key . '_tip'] = self::item('coach', 'Flow chart exit ' . $pair[0] . ': tooltip', 'The hover text of that exit.', $pair[1], array(), 1);
        }
        $c['flow_caption'] = self::item('coach', 'Flow chart caption', 'Under the flow chart.',
            'The number on a step is the players waiting at that step right now. Overdue players are counted in their step as well as under Overdue.', array(), 2);
        $c['hint_bulk'] = self::item('coach', 'Send offers to all: the hint', 'Inside the Send offers to all panel.',
            'These players are Selected or Training Only and have no offer yet. Sending asks the committee to approve each offer; nothing is emailed to a player until the committee approves it, and you can cancel a request that is still waiting.', array(), 3);
        $c['hint_declined'] = self::item('coach', 'Declined list: the hint', 'Above the Declined list.',
            'Players who turned the offer down, or who you moved to Declined after a missed deadline. Their verdict is unchanged; change it to take them off this list, or press Offer again to send a new request to the committee.', array(), 3);
        $c['confirm_withdraw'] = self::item('coach', 'Confirm: Withdraw offer', 'The box that asks before Withdraw offer.',
            'Withdraw this offer? The player is emailed a short note, and you can change their verdict afterwards.', array(), 2);
        $c['confirm_move_sent'] = self::item('coach', 'Confirm: Move to declined (no reply)', 'The box that asks before Move to declined on an offer the player has not answered.',
            'Move this player to Declined? The offer closes and they are not emailed.', array(), 2);
        $c['confirm_move_accepted'] = self::item('coach', 'Confirm: Move to declined (deposit not complete)', 'The same, for an accepted offer with a deposit still to pay.',
            'Move this player to Declined? The offer closes and they are not emailed. Any deposit they have paid is not refunded.', array(), 2);
        $c['cell_missing_limit'] = self::item('coach', 'Can\'t send yet: no upper limit for the player\'s fee category',
            'Beside a greyed Send offer or Offer again, in the Send offers to all list and as the answer to Send offer, before the season\'s fees are released (an Early Offer is quoted on the upper limit), when the fee matrix has no upper limit for the player\'s fee category and role. Administrators see the exact cell instead.',
            'Can\'t send yet: the club hasn\'t set the upper limit for this player\'s fee category. The committee needs to set it first.', array(), 2);
        $c['cell_missing_fee'] = self::item('coach', 'Can\'t send yet: no season fee for the player\'s category',
            'The same places once the fees are released (an offer is then quoted on the real fee), when the fee matrix has no fee for the player\'s category and role. Administrators see the exact cell instead.',
            'Can\'t send yet: the season fee for this player\'s category isn\'t set.', array(), 2);

        // ---- 1.80.0: telling a Rejected player (club decision, 28 Oct 2026). Sent only when someone presses the button, about one team. ----
        $team = array('{team}' => 'the team, e.g. State League 1 Men');
        $who = array('{date}' => 'the day, e.g. 28 Oct', '{name}' => 'who pressed Send (the club, for a notice sent automatically before 1.80.0)');
        $c['exit_rejected_name'] = self::item('coach', 'Flow chart exit Rejected, not told: name', 'The side exits under the flow chart.', 'Rejected, not told', array(), 1);
        $c['exit_rejected_tip'] = self::item('coach', 'Flow chart exit Rejected, not told: tooltip', 'The hover text of that exit.',
            'Rejected for this team and not sent the "not selected" email yet. Nothing is sent until you press Send "not selected" email on their card (or send them all at once).', array(), 2);
        $c['ns_button'] = self::item('coach', 'The Send "not selected" email button', 'On a Rejected player\'s card (the team\'s coaches and committee with edit access).',
            'Send "not selected" email', array(), 1);
        $c['ns_confirm'] = self::item('coach', 'Confirm: Send "not selected" email', 'The box that asks before that button sends.',
            'Email {player} that they weren\'t selected for {team}? The email is only about {team}: it says nothing about any other team they applied for. Replies go to you if you coach {team} (else its head coach), and the team\'s other coaches are copied in. It can only be sent once.',
            array_merge($team, array('{player}' => 'the player\'s name')), 3);
        $c['ns_hint_list'] = self::item('coach', 'Trial applicants: the hint about Rejected players', 'In the hint above the trial applicants list.',
            'A Rejected player is told nothing until someone presses Send "not selected" email on their card. That email is about your team only and says nothing about any other team they applied for.', array(), 3);
        $c['ns_hint_rejected'] = self::item('coach', 'After marking a player Rejected', 'Added to the message a coach sees after choosing Rejected.',
            'Nothing is sent to them: press Send "not selected" email on their card when you want to tell them.', array(), 2);
        $c['ns_untold'] = self::item('coach', 'Card: Rejected, not told yet', 'The status line of a Rejected player\'s card before anyone has sent the email.',
            'Rejected - not told yet', array(), 1);
        $c['ns_sent'] = self::item('coach', 'Card: the email was sent', 'The status line once the email has gone.',
            'Not selected email sent {date} by {name}', $who, 1);
        $c['ns_sent_auto'] = self::item('coach', 'Card: the email was sent (no name on record)', 'The same for a notice sent automatically before 1.80.0, when it was one per player.',
            'Not selected email sent {date}', $who, 1);
        $c['ns_by_club'] = self::item('coach', 'Card: who sent it, when nobody is on record', 'Fills {name} in the lines below when no person pressed the button.',
            'the club', array(), 1);
        $c['ns_waiting'] = self::item('coach', 'Card: waiting for the club\'s email permission', 'The status line after Send while this website may not send offer emails.',
            'Not selected email waiting to send: the club hasn\'t allowed emails yet (pressed {date} by {name})', $who, 2);
        $c['ns_sending'] = self::item('coach', 'Card: the email is on its way', 'The status line in the minutes after Send.',
            'Not selected email sending (pressed {date} by {name})', $who, 1);
        $c['ns_held'] = self::item('coach', 'Card: the club is holding the email', 'The status line when the email waited more than a day and is held for the club to send or discard.',
            'Not selected email held by the club (pressed {date} by {name})', $who, 1);
        $c['ns_problem'] = self::item('coach', 'Card: the email has not gone out', 'The status line when sending failed and the club is looking at it.',
            'Not selected email not sent yet: the club is looking at it (pressed {date} by {name})', $who, 2);
        $c['ns_skipped'] = self::item('coach', 'Card: the email was not sent after all', 'The status line when things changed before it went (the verdict, the application or the account), or the club switched these emails off.',
            'Not selected email not sent: things changed before it went (pressed {date} by {name})', $who, 2);
        $c['ns_stopped'] = self::item('coach', 'Card: the club chose not to send it', 'The status line when an administrator pressed Don\'t send on the Offers page.',
            'The club decided not to send this player a "not selected" email for {team}', $team, 2);
        $c['ns_stopped_line'] = self::item('coach', 'Offers page: Don\'t send was chosen', 'The record of a notice an administrator marked Don\'t send.',
            'Don\'t send: decided {date} by {name}', $who, 1);
        $c['ns_history'] = self::item('coach', 'Card: sent before the verdict changed', 'A quiet line on the card when the email was sent while the player was Rejected and the verdict has changed since.',
            'Before their verdict changed: {status}', array('{status}' => 'the sent line above, e.g. Not selected email sent 28 Oct by Sam'), 1);
        $c['ns_other_team'] = self::item('coach', 'Card: told about another team', 'A quiet line on other teams\' cards, so nobody is surprised.',
            'Told they weren\'t selected for {team} ({date})', array_merge($team, array('{date}' => 'the day it was sent')), 1);
        $c['ns_reason_unpaid'] = self::item('coach', 'Can\'t send: the trial fee isn\'t paid', 'Beside a greyed Send "not selected" email button, and as the answer to it.',
            'Can\'t send yet: their trial fee isn\'t paid.', array(), 1);
        $c['ns_reason_closed'] = self::item('coach', 'Can\'t send: the application is no longer open', 'The same, when the trial application was withdrawn, rejected by the club or has expired.',
            'Can\'t send: their trial application is no longer open.', array(), 1);
        $c['ns_reason_account'] = self::item('coach', 'Can\'t send: the account is gone', 'The same, when the player\'s website account (or its email address) no longer exists.',
            'Can\'t send: their website account no longer exists.', array(), 1);
        $c['ns_reason_off'] = self::item('coach', 'Can\'t send: the email is switched off', 'The same, while the "not selected" email is unticked under Emails, Offers and selection.',
            'Can\'t send: the club has switched "not selected" emails off.', array(), 1);
        $c['ns_reason_verdict'] = self::item('coach', 'Not sent: the verdict changed', 'The answer to Send when the player is no longer Rejected for the team (a page left open).',
            'Their verdict for {team} isn\'t Rejected any more, so nothing was sent. Reload the page.', $team, 2);
        $c['ns_done'] = self::item('coach', 'After Send: on its way', 'The answer to Send while this website may send offer emails.',
            '"Not selected" email on its way: they are told they weren\'t selected for {team}, and nothing about any other team.', $team, 2);
        $c['ns_done_waiting'] = self::item('coach', 'After Send: waiting for the club', 'The answer to Send while this website may not send offer emails yet.',
            '"Not selected" email queued: it waits until the club allows offer emails, then tells them they weren\'t selected for {team}.', $team, 2);
        $c['ns_already'] = self::item('coach', 'After Send: it had already gone', 'The answer to Send when someone else had already sent it (another tab, the committee).',
            'Nothing to do, it was already sent: {status}.', array_merge($team, array('{status}' => 'where that email is up to')), 1);
        $c['ns_bulk_title'] = self::item('coach', 'Send "not selected" emails: the panel', 'The heading of the panel above the board that sends them all at once.',
            'Send "not selected" emails ({n})', array('{n}' => 'how many will be emailed'), 1);
        $c['ns_bulk_hint'] = self::item('coach', 'Send "not selected" emails: the hint', 'Inside that panel.',
            'These players are Rejected for {team} and haven\'t been told yet. Each gets one email saying they weren\'t selected for {team}; it says nothing about any other team they applied for. Nothing is sent until you press the button, and each player can only be sent it once.', $team, 3);
        $c['ns_bulk_button'] = self::item('coach', 'Send "not selected" emails: the button', 'The button in that panel.',
            'Email these {n}', array('{n}' => 'how many'), 1);
        $c['ns_bulk_confirm'] = self::item('coach', 'Confirm: Send "not selected" emails', 'The box that asks before that button sends.',
            'Email these {n} players that they weren\'t selected for {team}? Each email is only about {team}. Replies go to you if you coach {team} (else its head coach), and the team\'s other coaches are copied in. This can\'t be undone.', array_merge($team, array('{n}' => 'how many')), 3);
        $c['ns_bulk_cant'] = self::item('coach', 'Send "not selected" emails: who can\'t be emailed', 'Above the players in that panel who can\'t be emailed, each with the reason.',
            'Can\'t be emailed:', array(), 1);
        $c['ns_bulk_done'] = self::item('coach', 'After Send "not selected" emails', 'The answer to that button while this website may send offer emails.',
            '{n} "not selected" email(s) on their way for {team}.', array_merge($team, array('{n}' => 'how many')), 1);
        $c['ns_bulk_done_waiting'] = self::item('coach', 'After Send "not selected" emails: waiting for the club', 'The same while this website may not send offer emails yet.',
            '{n} "not selected" email(s) queued for {team}: they wait until the club allows offer emails.', array_merge($team, array('{n}' => 'how many')), 2);

        self::$catalogue_cache = $c;
        return $c;
    }

    /** key => built-in text. */
    public static function defaults() {
        $out = array();
        foreach (self::catalogue() as $key => $item) {
            $out[$key] = $item['default'];
        }
        return $out;
    }

    /** What an administrator changed: key => text (only known keys, only non-blank). */
    public static function saved() {
        if (self::$saved_cache !== null) {
            return self::$saved_cache;
        }
        $raw = get_option(self::OPTION, array());
        $out = array();
        if (is_array($raw)) {
            $catalogue = self::catalogue();
            foreach ($raw as $key => $text) {
                if (isset($catalogue[$key]) && is_string($text) && trim($text) !== '' && $text !== $catalogue[$key]['default']) {
                    $out[$key] = $text;
                }
            }
        }
        self::$saved_cache = $out;
        return $out;
    }

    /** Forget what was read this request (every write calls it). */
    public static function flush() {
        self::$saved_cache = null;
    }

    /** The text in force for a key, with its {placeholders} still in it ('' for an unknown key). */
    public static function get($key) {
        $catalogue = self::catalogue();
        if (!isset($catalogue[$key])) {
            return '';
        }
        $saved = self::saved();
        return isset($saved[$key]) ? $saved[$key] : $catalogue[$key]['default'];
    }

    /** Is this line customised? */
    public static function is_customised($key) {
        $saved = self::saved();
        return isset($saved[$key]);
    }

    /**
     * The line, filled in. $vars: '{placeholder}' => value (plain text).
     * {policy_club} and {policy_player} are filled from the policy lines
     * (never inside the policy lines themselves).
     */
    public static function text($key, array $vars = array()) {
        $text = self::get($key);
        if ($text === '') {
            return '';
        }
        if (strpos($text, '{club_contact}') !== false && !isset($vars['{club_contact}'])) {
            $vars['{club_contact}'] = TeamOversight_Offers::club_contact();
        }
        if (!in_array($key, self::POLICY_KEYS, true) && strpos($text, '{policy_') !== false) {
            $policy = array();
            foreach (self::POLICY_KEYS as $policy_key) {
                $policy['{' . $policy_key . '}'] = self::get($policy_key);
            }
            $text = strtr($text, $policy);
        }
        return $vars ? strtr($text, $vars) : $text;
    }

    /** Escape plain text for an HTML text node (< > & only: apostrophes and quotes stay as typed, like the static HTML these lines replaced). */
    public static function e($text) {
        return htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
    }

    /** A filled line as safe HTML: escaped, with **bold** drawn as <strong>. */
    public static function html($key, array $vars = array()) {
        return self::to_html(self::text($key, $vars));
    }

    /** Escape plain text and draw its **bold** parts. */
    public static function to_html($text) {
        $html = self::e($text);
        return preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
    }

    /** The placeholders a line may use (its own, plus the two policy sentences for every line but those). */
    public static function allowed_placeholders($key) {
        $catalogue = self::catalogue();
        if (!isset($catalogue[$key])) {
            return array();
        }
        $allowed = array_keys($catalogue[$key]['vars']);
        if (!in_array($key, self::POLICY_KEYS, true)) {
            $allowed[] = '{policy_club}';
            $allowed[] = '{policy_player}';
        }
        $allowed[] = '{club_contact}';
        return $allowed;
    }

    /** Things wrong with a proposed text for a key: array of plain-words problems (errors first-class: a coach line with a dollar figure is refused by save()). */
    public static function problems($key, $text) {
        $out = array();
        $catalogue = self::catalogue();
        if (!isset($catalogue[$key])) {
            return array('Unknown line.');
        }
        $text = (string) $text;
        if (preg_match_all('/\{[a-z_]+\}/', $text, $found)) {
            $allowed = self::allowed_placeholders($key);
            $bad = array();
            foreach (array_unique($found[0]) as $token) {
                if (!in_array($token, $allowed, true)) {
                    $bad[] = $token;
                }
            }
            if ($bad) {
                $out[] = implode(' ', $bad) . ' ' . (count($bad) === 1 ? 'is' : 'are') . ' not something this line can fill, so it would show as typed. It can fill: ' . (empty($allowed) ? 'nothing' : implode(' ', $allowed)) . '.';
            }
        }
        return $out;
    }

    /** Would this text show a dollar figure to a coach? (the coach group's rule) */
    public static function has_money($text) {
        return strpos((string) $text, '$') !== false || preg_match('/\b\d[\d,]*\.\d{2}\b/', (string) $text) === 1 || preg_match('/\bdollars?\b/i', (string) $text) === 1;
    }

    /**
     * Save lines from a form. $posted: key => text (only the keys present
     * are touched). Blank or identical-to-default means "built in". Refuses
     * a line that is too long, and a coach line with a dollar figure; warns
     * about a placeholder the line cannot fill (saved anyway: it is the
     * administrator's wording). Returns array(ok, changed (count), reset
     * (count), errors, warnings).
     */
    public static function save(array $posted, $actor_id = 0) {
        $catalogue = self::catalogue();
        $raw = get_option(self::OPTION, array());
        $store = is_array($raw) ? $raw : array();
        $result = array('ok' => true, 'changed' => 0, 'reset' => 0, 'errors' => array(), 'warnings' => array());
        foreach ($posted as $key => $text) {
            if (!isset($catalogue[$key]) || !is_string($text)) {
                continue;
            }
            $item = $catalogue[$key];
            $text = trim(preg_replace('/\r\n|\r/', "\n", $text));
            $had = isset($store[$key]) && is_string($store[$key]) && trim($store[$key]) !== '' && $store[$key] !== $item['default'];
            if ($text === '' || $text === $item['default']) {
                if ($had) {
                    unset($store[$key]);
                    $result['reset']++;
                } elseif (isset($store[$key])) {
                    unset($store[$key]);
                }
                continue;
            }
            if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > self::MAX_LENGTH : strlen($text) > self::MAX_LENGTH) {
                $result['errors'][] = $item['label'] . ': too long (the most is ' . self::MAX_LENGTH . ' characters). It was not saved.';
                continue;
            }
            if ($item['group'] === 'coach' && self::has_money($text)) {
                $result['errors'][] = $item['label'] . ': the coach portal never shows a dollar figure, so this line cannot carry one. It was not saved.';
                continue;
            }
            foreach (self::problems($key, $text) as $problem) {
                $result['warnings'][] = $item['label'] . ': ' . $problem;
            }
            if (!isset($store[$key]) || $store[$key] !== $text) {
                $result['changed']++;
            }
            $store[$key] = $text;
        }
        $result['ok'] = empty($result['errors']);
        if (empty($store)) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, $store, false);
        }
        self::flush();
        if (class_exists('TeamOversight_Offers')) {
            TeamOversight_Offers::flush_cache();
        }
        if ($result['changed'] || $result['reset']) {
            TeamOversight_Log::add('email_offer', 'The offer wording was saved (' . $result['changed'] . ' changed, ' . $result['reset'] . ' put back to the built-in text).', array('user_id' => intval($actor_id)));
        }
        return $result;
    }

    /** Put some lines (or, with no keys, all of them) back to the built-in text. Returns how many were customised. */
    public static function reset(array $keys = array(), $actor_id = 0) {
        $raw = get_option(self::OPTION, array());
        $store = is_array($raw) ? $raw : array();
        $n = 0;
        if (empty($keys)) {
            $n = count(self::saved());
            $store = array();
        } else {
            foreach ($keys as $key) {
                if (isset($store[$key])) {
                    unset($store[$key]);
                    $n++;
                }
            }
        }
        if (empty($store)) {
            delete_option(self::OPTION);
        } else {
            update_option(self::OPTION, $store, false);
        }
        self::flush();
        if (class_exists('TeamOversight_Offers')) {
            TeamOversight_Offers::flush_cache();
        }
        if ($n > 0) {
            TeamOversight_Log::add('email_offer', 'The offer wording was put back to the built-in text (' . $n . ' line' . ($n === 1 ? '' : 's') . ').', array('user_id' => intval($actor_id)));
        }
        return $n;
    }

    /** Which screens quote a key (shown beside the box so an edit is never a surprise). */
    public static function used_by($key) {
        $catalogue = self::catalogue();
        return isset($catalogue[$key]) ? $catalogue[$key]['where'] : '';
    }
}
