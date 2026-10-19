```text
                                          .--""--.
                            *    .       /  .--.  \
                     o/        *        ;  /    \  ;
                    /|      .    *      :  \    /  :
                    / \          .       \  '--'  /
                   /   \      *           '--..--'
                                              \\
     __________________________________________\\________________
    |    |    |    |    |    |    |    |    |   \\   |    |    |
    |____|____|____|____|____|____|____|____|____\\__|____|____|
    |    |    |    |    |    |    |    |    |     \\ |    |    |
    |____|____|____|____|____|____|____|____|______vv|____|____|
   ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

        B u m p .   S e t .   S p i k e .   A d m i n i s t r a t e .
```

# MURVC Club Manager

MURVC's club management system for WordPress, integrating Ultimate Member (accounts/profiles) and WooCommerce (payments via Square). The admin splits into three areas:

- **Club Membership** — club-wide member list, time-bound membership tiers, membership history reporting, stats and logs
- **VVL Oversight** — competition machinery: teams, trials, coach selections, team assignments, fees and payments, player readiness
- **Club Programs** — attendance for casual programs (Mixed Development and anything else sold as a product with date variations)

> The plugin folder is still `team-oversight-migration` and the constant is `TEAM_OVERSIGHT_VERSION`; the display name changed in 1.33.0. Renaming the folder/slug is a deliberate cutover-day job (it deactivates the plugin and changes the deploy path).

## Requirements

- WordPress 5.0+, PHP 7.4+
- Ultimate Member (profiles, registration, login)
- WooCommerce with legacy (CPT) order storage — HPOS is not currently supported by the kit-purchase detection

---

## Club Membership

### Membership tiers

Memberships are **time-bound grants** stored in `team_memberships`: tier + start/end date + source (`purchase` / `manual` / `import`). A member's current status is their highest unexpired grant. Three tiers:

| Tier | WP role | How granted | Expiry |
|------|---------|-------------|--------|
| Life Member | `life-member` | Manual only | Never (2099 sentinel, shown as "no expiry") |
| Full Member | `full-member` | Purchase, manual, or automatic on team assignment | Term from purchase date; assignment grants run to 1 April of the year after the season |
| Associate Member | `associate-member` | Purchase or manual | Term from purchase date |

- **From purchases**: set "Membership tier granted" + "Membership term (months)" on a WooCommerce product (Product → Edit → General), or configure product-category rules on the Members page. Only explicitly configured products grant anything. Terms run from the purchase date; overlapping grants simply overlap (highest active tier wins).
- **From team assignments**: anyone with an active team assignment (any role — player, training only, coach, assistant coach, team manager) for the current season or later is automatically granted Full Membership running to **1 April of the following year** (`source = assignment`) — so a 2027 VVL player stays a member until the 2028 teams are finalised just before April 2028. (Before 1.67.0 these ran to 31 Dec; a one-off update moved every still-running team grant to the new date, logged per person.) Synced immediately after plugin admin changes and by the daily cron; deduped per person per season, and removing someone from a team never revokes the grant.
- **Role sync**: the `full-member`/`associate-member`/`life-member` WP roles are kept in sync on every grant and by a daily cron (`team_oversight_membership_sync`), including automatic demotion when the last grant expires. Users with roles but **no** ledger rows are never touched (protects pre-ledger role assignments until seeding runs).
- **Seeding**: Members page → "Seed memberships from this year's purchases" converts the year's qualifying purchases into dated grants (dry-run available, idempotent).
- **Order re-scan**: Members page → "Re-scan this year's paid orders" replays every paid order through the grant logic with the *current* product/category configuration — run it after adding membership attributes to a product, since orders paid before the attributes were set granted nothing. Idempotent; backfilled grants are dated from the order's paid date. (Grants require the order to reach Processing/Completed *and* be linked to a WP account — guest orders never grant.)
- The `[murvc_member_role]` shortcode (registered by the plugin) shows the profile owner's tier badge on Ultimate Member profiles.

### Admin dashboard

The WP dashboard is replaced (for admins) with a fast **MURVC Club Overview** widget: current members, fees outstanding, overdue people/amount, trial applications awaiting action, people on teams — each tile linking to its admin page — plus the latest activity-log entries and quick links. The slow stock widgets (WordPress news, WooCommerce status/report queries, quick draft, activity, Solid Security panel) are removed; the widget itself renders from the plugin's indexed tables in a few milliseconds.

### Members page (Club Membership menu)

One row per person: membership status + expiry, age, gender, MUS category, VVL teams for the season, fees owing, VA accreditation, profile-confirmation status. Filter/search/sort, CSV export, manual grant (with email autocomplete) and revoke.

### Member Lookup

Club Membership → Member Lookup: search **any account on the site** — not just people on the membership ledger — and get everything the club holds about that one person on a single screen. Emergency contacts sit at the top (both of them, phone numbers repaired and tap-to-call), followed by profile details, membership grant history, every team assignment across all seasons, fees with the payment ledger and computed overdue amount, trial applications, recent WooCommerce orders and recent activity-log entries.

Search matches display name, email, username, first/last name, mobile, student ID **and emergency contacts** — so an unknown number that rings the club can be traced back to whose contact it is. When a result matched on someone else's details the row says so ("emergency contact: Jane Smith — 0411 222 333") rather than looking unexplained.

Phone matching compares digits to digits, so formatting never gets in the way: `0411 222 333`, `0411222333`, `411222333` and `+61 411 222 333` all find each other. That covers both the imports that dropped the leading zero and the profiles saved with spaces or a country code.

Deliberately search-first: with thousands of accounts the page never lists them all. Nothing is queried until you type at least two characters, results are capped at 25, and the detail view costs one person's worth of queries (a search runs in ~20ms against ~3,800 accounts). The **Profile** button on the Members page opens this view.

### Stats

Club Membership → Stats — statistics about **current members** (unexpired ledger grants), in tabs designed to grow over time:

- **Data Quality**: profile completeness — gender, DOB, mobile, MUS category, this-year confirmation — as percentages with colour bars and 30-day deltas. A snapshot is recorded once a day (membership cron, page-view fallback, manual button) into a non-autoloaded option capped at ~3 years, so sparkline trends accrue from first deploy.
- **Locations**: postcode distribution (UM `postal_code`, falling back to the WooCommerce billing postcode; messy values like "VIC 3000" are normalised), plus a saved **postcode watchlist** — enter any list of postcodes and see how many members live in them. Defaults to current members; a date-range picker widens the population to anyone who held a membership during the period.

### Membership History

Club Membership → Membership History: pick a date range and see everyone whose membership overlapped it — highest tier held, every membership period with dates and source, age/gender/MUS, current status. CSV export. History exists from the moment grants exist (run seeding to backfill).

The **MUS Matrix** tab on the same page produces the periodic MUS return: active members (anyone holding a membership grant in the date range) as a matrix of MUS eligibility category × gender (Male / Female / Non-Binary / Unknown) with totals, CSV export. Profile category variants fold into the MUS row labels (other-university student/alumni variants merge; junior/high-school count as Other; missing data counts as Unknown; unrecognised categories keep their own row rather than being lumped).

The **VV Report** tab produces the VV return: age band (10-year bands, age at the end of the period; missing DOB → "Unknown DOB") × gender (Male / Female / Other / Unknown gender) with totals, CSV export. Defaults to Associate members only, with tier checkboxes to change what's included; each person counts once under the *highest* tier they held in the period, so tier selections never double-count.

---

## VVL Oversight

### Teams (Configuration page)

Teams are configured **per season** (1.48.0+) with a **code** (stable internal ID — never shown to players), **name** (what players see), **gender** (men's/women's/mixed), **age eligibility rule** and **playing-shirt count**:

- **Per season**: the Configuration page's season selector decides which season's list you are editing. Adding, renaming, re-ruling or deleting a team changes that season only — 2027's SL3M-R and 2026's SL3M-R are separate entries. Internally the registry is keyed `CODE-YYYY`; assignments, applications and selections keep the bare code plus their own season, so nothing historical is rewritten and a code still identifies "the same team" across years.
- **Deleting** is blocked only by active assignments *in that season*. Removing a team from 2027 while people are still assigned to it in 2026 is fine — that was the bug this replaced.
- Age rules follow the VVL By-Laws and compute their DOB cutoff from the season year automatically (nothing to update each season): **U19** = no 19th birthday during the season year; **U17**/**U15** (YSL) = 16/14 or younger as of 31 August.
- Shirts: how many playing shirts a player must pay for on this team (Premier 2, YSL 0 — supplied, default 1).
- "Load default club team list" resets the *selected season's* list to the club defaults; other seasons are untouched.
- **Create season** (button beside the Configuration season selector): registers the next season (highest known + 1, e.g. 2028) so it appears in every season selector before any data exists, and **copies the previous season's team list** into it as a starting point — ready for season dates, fee matrix, and trial opening.
- Upgrading from a pre-1.48 global list is automatic: every season with data (plus this year and next) receives its own copy of the old list, and the old list stays behind as the fallback for any season never seeded. The trial form shows each open season its own teams and switches lists when the applicant changes season.

### Trials

Front-end form via `[team_trial_form]` (login required; prompts to log in / create an account with an explanation):

- **Prefilled, read-only account details** (name, email, phone, DOB, gender, institution) — edited via the profile, never the form. Submission requires only what trials genuinely need: name, contact number, DOB and gender (age rules + competition). MUS/degree fields are **not** required to apply — they're a fee-class matter, collected by the annual profile wall and resolved at invoicing.
- Competition (men's/women's) derives from profile gender; the question is only asked when the profile can't answer (unset/non-binary). The team list leads with the applicant's own competition, so a women's-competition player sees Women's Teams first rather than scrolling past a greyed-out men's block. Within each group teams appear in the same natural code order as the Configuration page, and once eligibility is worked out (competition + age rule) the teams they can't pick sink to the bottom of the group, so what they *can* apply for is always at the top.
- Questionnaire mirrors the club's VVL trials form: VVL history (with conditional returning-player and club-transfer sections — the previous club is a dropdown of the VVL clubs plus Other Victorian / Interstate / International options that reveal a club-name field, stored as e.g. "Interstate: Sydney Uni"), international player details, team selection (real teams, grouped by gender, with ineligible teams greyed out live by competition and DOB cutoff — enforced server-side too), positions, venue availability, trial-date availability, experience. Answers stored as JSON (`form_data`).
- **Per-season opening**: "Accepting trial applications for: [year checkboxes]" (Trial Applications → settings). Only ticked seasons appear in the trial form's season selector, and submissions for unticked seasons are rejected server-side; with nothing ticked the form shows a friendly closed notice. The status panel (trial number, confirmed / awaiting payment / assigned) is about **one season only** — the latest open season, or when nothing is open the newest season anyone has applied for — so last season's "your 2026 application is confirmed" never lingers once 2027 opens. The settings summary shows the open seasons, or APPLICATIONS CLOSED in red.
- **Trial fee rules** (Configuration → Trial application rules): who pays the trial fee is an if-then rule set — by default **new-to-VVL and transferring players pay**, **returning Renegades players are free**, and **anyone under 18 is free regardless** (threshold configurable, 0 disables). Waived applications submit directly (no checkout) and record the waiver reason in the application ("Waived — under 18" / "Waived — returning Renegades player"). The form's fee notice adapts: juniors see "trials are free for players under X", adults see who the fee applies to. The **transfer club list** is editable in the same section (one club per line; empty = built-in VVL default list).
- **Trial fee**: configure a product in the Trial Applications settings box. Submissions save as `awaiting_payment`, go to checkout, and become reviewable (`pending`) when the order is paid. Unpaid applications stay `awaiting_payment` until paid (they no longer expire); "Mark as Paid" covers offline payments. No fee product = direct submission.
- **Who pays** (Configuration → Trial application rules — four independent toggles): new to VVL ✓, transferring from another club ✓, **played for Renegades last season** ✗ (their yearly membership already covers it), **Renegades history but sat a season out** ✓ (no current membership, so they pay like anyone else). The last two were one rule before 1.51.0, which wrongly waived lapsed players.
- **Waive fee** (Trial Applications, on any unpaid application, next to Mark as Paid): for when the club decides someone shouldn't pay — typically a last-season player who ticked the wrong history option. A reason is required; the application moves to the review queue, the Trial Fee reads "Waived by club — *reason* (*admin*, *date*)" (the reason is for admins only, as the form says — coaches see just "Waived by club"), it's logged in VVL Oversight → Logs, and the player gets the *Application received* confirmation (not a payment receipt — nothing was paid). The waiver **sticks**: if the player edits their application later, the fee is not re-assessed. Use Mark as Paid only when money actually changed hands outside the site.
- The history options are mutually exclusive: "played for Renegades **last season**" (free) versus "played for Renegades before, **but not last season**" (pays by default). The second option's old wording was also true of last-season players, who sometimes picked it.
- **Junior exemption**: free under a configurable age (default 18) — but *only when every team they pick is Youth State League* (U/17 or U/15). A junior reaching up to JPL or a senior team pays. The form spells this out to under-age applicants, and the rule is enforced server-side from the teams actually submitted.
- **Unpaid applications** show a **red** status panel with a direct **Pay the trial fee** button rather than asking the applicant to fill the form in again. The link is nonced and bound to the application: it rebuilds the cart line and goes straight to checkout, and does nothing for anyone but the owner of a genuinely unpaid application.
- **After saving**: when no payment is needed, submitting or saving changes reloads the rego page itself, back at the **top of the page** (the browser's usual "restore where you were" is switched off for that reload, so they don't land back down by the Save button) — green status panel with the trial number, the form ready for more edits — with a one-off notice on top ("Thanks — your application has been submitted" / "Your changes have been saved"). When the fee is due it goes straight to checkout instead.
- **Editing after submitting**: coming back to the form reopens the application with every answer filled in ("Edit your trial application" / **Save changes**), until the season's applications close or a team is assigned. It is the same application — same trial number, coach verdicts and notes kept — and the edit is stamped **Last Edited** on the application so coaches can see it changed. Answers are rebuilt from the stored snapshot, so applications submitted before this feature are editable too. Fees are re-assessed on edit: **paid stays paid** (online or Mark as Paid — never charged twice), but a waived application edited into a paying category (e.g. switching to a club transfer, or a junior adding a senior team) becomes due and goes to checkout, closing the "apply free, then edit" gap. The 7-day unpaid expiry counts from the latest edit (`updated_date`), not first submission. Assigned (accepted) applications are locked with a "contact the club" note — which also fixes an old gap where submitting again after acceptance created a duplicate application with a new trial number.
- **One application per account per season**, even under a double-tap: saves for a season are serialised with a database lock, so two requests arriving together can't both see "no application yet" and create two (the second waits, then edits the first). The same lock stops two different players being handed the same trial number. The Submit button also locks while sending ("Sending…") and unlocks if the save fails.
- **Trial numbers**: per-season sequential number assigned at submission, shown persistently to the player (status panel on the form page) and throughout admin/coach views — players can write it on themselves at busy trials.
- Optional **training-info page URL** linked at the top of the form so players pick teams by training venue/day.

### Coach portal

`[team_coach_portal]` — visible only to logged-in users with an active coach/assistant-coach assignment or committee access; each coach sees only their own teams (server-enforced). Mobile-friendly card layout.

- **Committee access** (VVL Oversight → **Committee Access**): club officials can open **every team** in the portal, picked from a team dropdown (teams they coach listed first, each group in team-code order, as on the Configuration page). **Edit all teams** gives a coach's powers on every team (verdicts — labelled *Team verdict* — attendance, notes); **View all teams** is read only: the cards, applications, notes, attendance history and trial book are all visible, but no verdict, attendance or note controls are shown and the server refuses those actions. Site admins always have edit access. A coach with view access still edits the teams they coach. Grants are made by email or username, can be changed or removed, and every change is logged.

- **Team switcher** for multi-team coaches; **Coaching Staff** table + **Players** cards (confirmed members plus selection-board players, tinted by verdict, over-age players flagged with DOB and "VV exemption required"). **All cards share one format**: trial number, registration-status chip, and the expander row (emergency contact / application details / shared notes with add-note) appear on confirmed players and selection-roster players too — a confirmed player keeps their full trial context. Players without an application (added manually) get the plain card.
- **Selection board**: per-team verdicts — Tentative / Selected / Training Only / Rejected — via a dropdown on each applicant card. Choosing a verdict saves in the background (no page reload): the card's colour and chips update in place, and a short confirmation pops up, so coaches keep their place in a long list (without JavaScript it falls back to a normal save). Verdicts are per team, never global: a player can be Selected by multiple teams (e.g. YSL + JPL), and every coach sees every team's verdicts. "Unclaimed" = no verdicts anywhere.
- Applicant pools are **competition-wide** (all men's or women's applicants, not just those who picked the team — players get redirected between trials and VV grants age exemptions), sectioned: *awaiting your verdict* first, then *verdict recorded*, then *other applicants* (players within the team's age limit first, over-age players last). Search + only-my-verdicts filter.
- The **Application** dropdown on each card opens with the player's **lowest eligible competition** for the season — U15, U17, JPL or Senior comps only — worked out from their profile date of birth with the same VVL cutoffs as the team age rules (so it rolls over with the season), or "Unknown" when there's no date of birth.
- **Shared notes** on applications (author + date, visible to all coaches and admins). The author can **edit** (in place) or **delete** (after a confirmation) their own notes; other people's notes are read-only, and the author check is enforced on the server, not just by hiding the links. Edited notes show "(edited)". After saving a note the page comes back at the same spot, with the search, filter and Notes panel as they were.
- **Registration-status chip** on every applicant and selection-roster card, derived automatically at submission: **New** (first VVL season), **Returning** (Renegades history, no other club since), **FA: [club]** (free agent — skipped a season or more, no transfer needed), **⇄ [club]** (club transfer required — played elsewhere as recently as last season), **ITC** (registered with an overseas federation *and* trialling for a Premier League 1 team — ITC only applies at P1). Colour-coded, truncated for long club names, full explanation on hover. The "season last played" input is a structured dropdown so the Free Agent / Transfer split is computed reliably.
- **Payment pending** — applicants who haven't paid their trial fee yet still appear (with a red chip on their card, on the selection board, and "(unpaid)" in the trial book), so last-minute applicants can be trialled, noted and rejected — but **Tentative, Selected and Training Only stay locked until the fee is paid** (shown as "(Disabled)" in the dropdown and refused by the server). They're listed at the bottom of every section, however long they've been unpaid. Their trial number is reserved at submission regardless. **Finalise Coach Selections holds unpaid players back** — it finalises everyone else and names the unpaid ones in a warning, pointing at Mark as Paid for cash/EFT — so nobody reaches a team without the fee being settled.
- **Attendance**: a date picker under the team heading (defaults to today, can go back to any past session of the season, never forward) and a **Mark attended** button on every card — players in selection, confirmed players, and applicants. A tap saves instantly without a page reload and a second tap undoes it. Records are per player, per team, per date (`JPLM · Mon 6 Oct 2026`), stored in `team_attendance`; an **Attendance** dropdown after Notes on each card shows the player's full history across all teams with who marked it, visible to every coach. A coach can only mark or undo for a team they coach; marking the same player twice, or two coaches at once, never creates a duplicate; only applicants and rostered players of that season can be marked. Confirmed players with no application are included.
- **Other teams' calls stay visible after a player moves into your team**: cards in the Players section (selection and confirmed) carry the same per-team chips the applicant cards do — e.g. a JPLM coach sees `PL2M: Selected` on a player in JPLM's selection. Once a player is finalised onto another team, that team's chip reads `Confirmed` (or `Confirmed (Training Only)`) in place of the verdict that led to it. Your own team's status keeps its own chip and isn't repeated. Two queries for the whole section.
- **Same team chip** (`↩ Same team`) on any card — roster, selection board or applicant — for someone who played for *this* team last season. Deliberately separate from the VV **Returning** chip, which is about the club: a Renegades player moving from SL3M to SL2M is Returning but not Same team. One query per page, matched by account or email.
- **Emergency contacts dropdown** on every player and applicant card — both profile contacts when recorded (primary + the second-contact fields), each with name, relationship and a tap-to-call number (AU numbers normalised, stripped leading zeros restored) — and a nudge when a member has none recorded.
- **Roster CSV export** (with positions and selection status).
- **Trial book** — a printable page of every applicant in the competition, opened in a new tab from the applicants section. Everything the on-screen cards keep behind expanders is laid out flat, but **densely** — an applicant is three or four lines (identity/age/positions/teams, contact + every team's verdict, emergency contacts, notes), not a section-headed block, so a squad fits on a page or two of paper. The bulky full application answers are **off by default** with a checkbox to include them, and a one-line-per-player index sits above. Intended for halls with no reception: the coach hits **Print / Save as PDF** beforehand and has a searchable file on their phone. It is a print-optimised page rather than a server-generated PDF on purpose — the plugin carries no PDF library to maintain, the browser's own print engine produces the file, and the page is self-contained (no external CSS, fonts or images). Same authority as the portal: you can only produce the book for a team you coach.
- Coaches never trigger fees: converting Selected/Training-Only verdicts into real assignments + invoices is the admin **"Finalise Coach Selections"** button on Trial Applications (idempotent; Training Only finalises as the `training_only` role and rate). The button shows whenever a Selected / Training Only verdict isn't reflected in the team assignments yet — on pending applications, and on accepted ones when the verdict is new or changed since the last Finalise, so a coach changing a verdict afterwards, or a second team picking an accepted player, brings it back — and whenever finalised players still on a team have no invoice once fees are released (Finalise creates one, so an unpaid invoice deleted on Payment Management comes back). Taking a player off a team on Team Assignments — deleting or deactivating the row, setting it Inactive or giving it a past end date, moving it to another team, or changing it to a staff role — sets that team's Selected / Training Only verdict back to Tentative, so Finalise doesn't put them back. Existing playing rows and invoices are matched by account or email (no duplicates under an old email), and one Finalise per season runs at a time. A re-run only invoices players it adds to a team or who have no invoice yet, so a fee set with Edit Fee survives it. The notice counts the invoices actually created or re-priced; players it couldn't invoice (no fee matrix amount for their class and role) are named, with each one logged in VVL Oversight → Logs — fill in the matrix and run Finalise again. Finalise never changes an existing role: a verdict that differs from the player's assignment (Selected on a Training Only row, say) is listed so an admin can change the role on Team Assignments.
- **Undo Assignment** (Trial Applications, on an accepted application) works like deleting the assignment on Team Assignments: it removes only the playing / training-only assignments for the accepted teams (matched by account or email — coach and manager roles are never touched), sets those teams' Selected / Training Only verdicts back to Tentative so the next Finalise doesn't quietly put the player back, and returns the application to pending. The season invoice is re-priced from the fee history first, never deleted blindly: it's removed only when nothing was paid on it, they're on no team that season and nothing accrued (they left before the season started, fees aren't released yet, or the season has no dates); otherwise it stays with every payment on it — a staff role they still hold is charged from that day, leaving before the season starts drops the fee to $0 with what was paid shown as credit, and mid-season it freezes at what accrued, paid or not (the same as deleting the assignment). In a season with no dates the fee can't be pro-rated, so an invoice with payments on it stays at the full amount — the notice says so, and to use Edit Fee if they shouldn't owe it. The notice says which happened.

### Fees & Payment Management

Fees are a **balance against a season timeline**, not an invoice event:

- **Season dates** (Configuration, per season) drive everything. No dates = full fees, nothing overdue.
- **Fee matrix** (Configuration, per season): rate per fee class × role, edited as a **grid** — one row per fee class, three columns: playing member, training only, supporter (coaches pay the supporter amount, team managers the playing member amount). Blank cells have no fee set and can be filled in; a season with no fees starts as an empty grid, with a button to **copy another season's amounts** as a starting point. 
- **How a season fee is worked out** (1.66.0): every team someone is on is a *bucket* — team + role + fee class, where the class can differ per team (an under-19 pays JPL/YSL rates on a junior-league team and Junior U/19 (VVL) rates elsewhere). (1) Each bucket is priced from the matrix — playing member, training only and supporter at their own amounts; **coach and assistant coach at the supporter amount**, **team manager at the playing member amount** (the matrix only has those three columns). (2) The fee is the **highest bucket** — buckets never add up, and there's **no coaching discount** (handled outside this system), so someone who only coaches pays the supporter fee. (3) **Pro-rata per stretch**: the fee history records the buckets for each stretch of the season, each stretch is charged for its share of the season dates, and the stretches add up — so a mid-season upgrade or a late start is charged exactly. Payment Management shows this working in a **"How it's worked out"** dropdown on every invoice (buckets with what each is charged as, the highest fee, pro-rata, total) and flags any invoice that no longer matches the calculation.
- **Fee release date** (Configuration, per season, beside the season dates): people can be put on next season's teams before its fees are known. Until the release date **nobody is invoiced** for that season (their fee-role history is still recorded); on the date, a daily check invoices everyone on a team at once, and anyone added afterwards is invoiced straight away. **Release fees now** does it immediately, and so does clearing a release date that hasn't been applied yet (the page asks first; everyone already on a team is invoiced, with the same counts; clearing a date that was already applied just removes it, re-pricing nothing). Pro-rata still runs from the season start/end, so people added before the season starts pay the full season. No release date = fees are charged as people are added (the original behaviour). Before release nothing is charged or re-priced, even when someone's teams change. Invoices issued before a release date was set can be taken back with **Remove fees until release**: unpaid ones are deleted; ones with payments drop to $0 and the money shows as **credit** (the payments ledger is never touched), then on release they're re-priced to the real fee with the credit taken off. Releases, removals and date changes are logged.
- **Fee segments** (`team_fee_segments`): a dated history of each member's fee role per season. Every assignment change (accept, finalise, role edit, delete, deactivate) checkpoints it; the season fee is the sum of each segment's rate × its share of the season window. So joining mid-season pro-rates, upgrading training-only → playing mid-season charges each period at its own rate, becoming a coach mid-season applies the coach rate from that date, and leaving all teams freezes the fee at the accrued amount — **$0 when they leave before the season starts**, whatever the fee matrix says (anything already paid shows as credit, and the change is logged; invoices with no fee history keep their amount, and so, mid-season, do roles missing from the fee matrix). Someone whose email changed is judged by their account as a whole, so removing a row under an old email never drops the fee of someone still on a team. Segments clamp to the season window, so anything effective before the season start charges from the start. Coming back onto a team after leaving every team never charges twice or charges the time they were off: the new stretch starts that day, or at the new assignment's start date when that falls after they left (a deliberate backdated re-add). Payments already made always carry forward (outstanding = new fee − paid).
- **Payment schedule**: fees fall due linearly between the season dates. Overdue = expected-by-today − paid. Members see a breakdown (season fee / paid / remaining / overdue), a "Next payment due" date when on-track, and a progress bar (paid % vs season % elapsed). **Past-season debts roll forward**: once a season's year is over, whatever remains outstanding counts as fully overdue (season dates or not) everywhere overdue is shown, and payments always clear the oldest season first.
- **The payments ledger is the single source of truth for money.** Paid = the sum of `team_invoice_payments` rows for the invoice; outstanding = fee − paid, always computed, never edited. Every dollar — online, cash, EFT, corrections — is a ledger row with a date, source, and note. (A one-time migration backfilled `reconciliation` rows for payments recorded before this model.)
- **Pay-any-amount**: `[member_fees]` (and the Ready to Play fees step) let members pay whatever they choose whenever they like via a dedicated payment product whose price is overridden to the chosen amount. The pay box asks explicitly — **pay what's overdue** / **pay the full balance** / **pay a different amount** — each option showing its figure, the custom field left empty (a prefilled amount used to read as fixed), and the button naming exactly what it will charge ("Pay $150.00 now"). The choice is resolved server-side, so it holds without JavaScript, and overpayments are still capped at the balance. Paid orders apply oldest season first, each application recorded as an `online` ledger row: the first carries the order item, which stops the processing and completed hooks from applying it twice, and the rest carry the order number with a "Part n of m of order item #X" note. Anything paid above the balance (e.g. a cart left over from before a cash payment or fee change) is recorded on the newest invoice as an "Overpayment" row, so it shows as in credit for the treasurer to offset or refund. A payment that can't be recorded (no invoice at all, or a part that fails to save) is logged as **Unapplied payment** on VVL Oversight → Logs with the order number and amount, for the treasurer to record with Record Payment or refund. The payment product is sold individually (quantity is always 1).
- **Payment Management** (admin): one row per member per season — fee, paid (from the ledger), outstanding, overdue, payment count (hover for the ledger detail). **Edit Fee** reprices the invoice (payments are untouched; outstanding recomputes); **Record Payment** adds a `manual` ledger row for cash/EFT/corrections (negative amounts allowed for refunds), stamped with who recorded it. Outstanding itself is not editable — by design.
- **Overdue reminder emails**: optional (off by default), managed on **VVL Oversight → Emails**: editable subject/body templates with placeholders (`{first_name}` `{name}` `{overdue}` `{outstanding}` `{time_overdue}` `{days_overdue}` `{link}`), configurable From name/address + Reply-To (the From address must be one the web server may send as — the WooCommerce order-email address is the proven-safe choice; Reply-To can be any real mailbox), a live preview, "Send test email to me", the automatic-send switch, per-person interval (default 7 days), a **grace period** before anyone is emailed (default **7 days behind**, plus a token $1 floor). Fees fall due gradually, so everyone tips a few dollars overdue almost immediately — gating on *how long* someone has been behind rather than *how much* means small-but-genuine debts still get chased, while nobody is emailed the day the schedule ticks over. "Behind since" is computed from the payment schedule (the date the accrual overtook what they'd paid); past-season debt counts from the season end. The email says how long: "behind the payment schedule for 2 weeks". Both gates are email-only — members always see their exact figure on site, **send time + timezone** (default **6:00pm Australia/Melbourne**; the page shows the next scheduled run and the cron re-anchors itself whenever the setting or DST moves it), "Preview recipients" (lists who would be emailed without sending) and "Send reminders now". The daily check covers rolled-over past-season debts; only people resolvable to a WP account are emailed (trials are account-gated, so that's everyone with a fee).

### Ready to Play

`[ready_to_play]` — shows the checklist for people selected into a team (confirmed assignment or a coach Selected/Training-Only verdict) for the current or next season. Anyone logged in who *isn't* selected sees the fee panel instead, so ex-players carrying a debt can still see and pay it. Members with **no fee record at all** see nothing: the plugin deliberately never says "you have no outstanding fees", because legacy fees can exist outside this system and implying someone is square would be wrong. (Logged-out visitors also see nothing.) Team managers count as players (their role only exists for the fee discount). Coaches/assistant coaches who aren't also playing get a reduced "Get Ready to Coach" checklist: VV registration + fees only, no kit steps. Player steps in priority order:

1. **VV membership** — external registration link + manual tick.
2. **Playing shirt payment** — the club issues the shirt; the payment is once-ever, so **all-time** purchases of the configured shirt products count, no self-tick, and admins can record a **credit** (with note) for shirts paid under a different account. Quantity from the team config.
3. **Shorts & socks** — regular products: this-season purchases auto-complete, manual tick for older kit, shop link always available for re-orders.
4. **Fees** — breakdown across **all seasons** (carried-over debts surface here as overdue), schedule status for the panel's season, and the pay box (live when the payment product is configured).

Admin: VVL Oversight → **Player Readiness** — every selected player's VV/shirt/kit/fees status and a Ready flag, plus the settings (URLs and kit product IDs) and per-player shirt credits. Each player appears once, evaluated for the **latest season they're in** (current year or later) — so end-of-year selections for next season show next-season readiness immediately.

### Logs

One append-only activity log, two windows onto it (filter by event type, search by name/email/message; entries kept two years; each row stamped with when, who it was about, and which admin — or "System" for cron — did it):

- **VVL Oversight → Logs**: online and manual payments (amounts, notes, order refs), unapplied online payments (no invoice to apply them to, or a part that could not be recorded), reminder emails sent, trial applicant emails (including any that failed to deliver), fee edits (old → new amount). Bulk reconciliation backfills deliberately stay out.

### Trial applicant emails

VVL Oversight → Emails → *Trial application emails*: one card per email with an on/off switch, subject, body and live preview. They share the page's **From** (it must be an address the server may send as), but have **their own Reply-To** — `vvldelegate@renegades.com.au` by default, editable — so applicants' questions reach whoever runs trials rather than the treasurer's inbox the fee reminders reply to. Blank means "use the reminders' Reply-To"; an invalid address is flagged on save and falls back the same way. All on by default — each is transactional (a response to something the applicant just did), never a broadcast:

| Email | Sent when |
|---|---|
| Application received | submitted with no fee due, or an unpaid application becomes confirmed |
| Payment needed | saved with the fee due — first submission, or an edit that makes it apply |
| Payment received | paid online (sent once even though WooCommerce fires both *processing* and *completed*), or Mark as Paid |
| Changes saved | a confirmed application is edited, with no new payment (includes "didn't make this change? reply") |

Exactly **one email per action** — an edit that makes the fee due sends *Payment needed*, not both. **Selection outcomes are never emailed** — accepting, rejecting and Finalise send nothing; the club communicates those personally. Placeholders: `{first_name}` `{name}` `{season}` `{trial_number}` `{teams}` (team names) `{positions}` `{fee}` `{link}`. `{link}` is the page carrying `[team_trial_form]`, found automatically — deliberately the page rather than a one-click pay link, because a signed link would expire within a day while the page always shows the current status and, if unpaid, the Pay button. Wording left identical to the default is stored as "default", so later improvements to the built-in text still reach it; clearing a field restores the default. A mail failure never fails the submission or payment it describes — it's logged as "NOT delivered" instead. "Send test" delivers each switched-on email to you with sample data.
- **Club Membership → Logs**: the membership lifecycle — **Granted** (first membership, or one that doesn't change current status), **Extended** (same tier, new end date), **Upgraded** (e.g. Associate → Full, message shows the transition), **Expired** (logged once by the daily role sync when the last grant lapses), **Revoked** (admin action, shows the tier held). Past-dated grants from seeding log as "recorded".

---

## Club Programs

Casual programs (Mixed Development and similar) sell one WooCommerce product per program with a **variation per session date**, so "who is coming on Monday?" is already in the orders. This area reads it live — no separate attendance database.

- **Programs** are configured in Club Programs → Settings as `Name | product ID` (Mixed Development is auto-detected on first use). Each configured program gets its own admin sub-page listing its sessions.
- **Session roster**: a session picker defaulting to today (or the next upcoming session), a booked count, and one card per paying attendee — name, email, tap-to-call mobile, order number, and the same **emergency contact dropdown** the coach portal uses.
- **Guests**: someone who books multiple spots shows a `×3 (2 guests)` chip — the extra spots are real attendees whose names the club doesn't have. Orders placed without a club account show a **No account** chip (no profile, so no emergency contact).
- **First-timers**: someone with no earlier session of that program gets a **👋 New here** badge and a highlighted card; everyone else shows an unobtrusive "5th session" count, and the header totals the first-timers. History is computed from paid orders for the program's product with a session date *before* the one being viewed, so booking three weeks ahead doesn't hide a genuine first-timer.
- **Ticking people off**: each card has a **Mark here** button (undo-able) that saves over AJAX — no page reload. Ticked attendees fade, strike through, and **sort to the bottom**, so the top of the list is always "still to arrive"; the header counts who's in, and each tick shows who marked it and when.
- **Multiple supervisors at once** (several courts) is safe by design: writes are atomic upserts keyed on program + session + booking, so simultaneous marks never collide or duplicate, marking someone already marked is a no-op, and every open roster polls every 20 seconds so supervisors converge on the same view without refreshing. A no-JS form fallback still works via Post/Redirect/Get.
- Ticks live in `team_program_attendance`, are explicit-state (a refresh can't flip anyone), and work identically on the front-end page and the admin sub-page.
- **Session dates**: programs can declare the weekday they run on (e.g. Mixed Dev = Tuesday). Variation labels like "13-May" carry no year, so the weekday is what pins each session to the right year — without it, last season's dates sort into this season. Pickers show the fully resolved date ("Tue 14 Jul 2026").
- **Search**: an instant filter over name and email — no page reload, sized for a phone at the door.
- **Supervisor access is per program**: each program has its own supervisors, added in Club Programs → Settings with an account search (type a name or email, click to add, × to remove), so Mixed Dev supervisors see only Mixed Dev. Administrators see everything. `[session_attendance]` shows a supervisor only the programs they run; everyone else gets a polite notice. Lock a page to one program with `[session_attendance program="mixed-dev"]`. (A pre-1.35 club-wide supervisor list is still honoured, with a tick-box in Settings to clear it once migrated.)
- **Participation stats** sit above the roster on each program's admin page, over a date range that defaults to **year to date** (with Last 12 months / All time shortcuts). Five tiles, plus a collapsible session-by-session table:
  - **Participants** — distinct people, however many sessions each booked.
  - **Newcomers** — first-ever session of this program falls in the range. Measured against *all* history, so last year's regulars never read as new, and it matches the roster's 👋 New here badge.
  - **Returning** — the remainder: they had been before the range started.
  - **New to the club** — a different question, worth seeing beside the others when a program is an on-ramp: their *account* was created in the range. A three-season VVL player trying Mixed Dev is a newcomer to the program but not new to the club. Bookings made without an account can't answer this and are reported separately.
  - **Spots booked** — guests included (a booking of 3 is one participant, three spots), with the per-session average.

  Counted from paid bookings rather than attendance ticks, so the numbers hold whether or not anyone marked people off. Sessions whose label has no readable date are excluded and disclosed rather than silently dropped.
- **Mixed Development** has its own shortcode, `[mixed_dev_attendance]`, so the club's weekly program can grow its own behaviour without touching the generic one. Both accept `history="0"` to hide the first-timer/session-count badges.

### Data imports/exports

- **RevSport CSV import** (accreditations: VA ID, payment status, coach/referee accreditation).
- **Team Lists** and **MUS Membership Report** CSV exports. All CSVs are UTF-8 with BOM (Excel-safe).

---

## Shortcodes

| Shortcode | Audience | Purpose |
|-----------|----------|---------|
| `[team_trial_form]` | Logged-in members | Trial application (+ payment) |
| `[team_coach_portal]` | Coaches, committee | Selections, notes, rosters |
| `[member_fees]` | Logged-in members | Fee balance + pay any amount |
| `[player_fees]` | Members with overdue fees | Compact overdue-fees flag linking to the Player Checklist page (`url` attribute overrides the default `/player-checklist/`); renders nothing at all unless the viewer has overdue fees — safe on any page |
| `[ready_to_play]` | Selected players | Pre-season checklist |
| `[murvc_member_role]` | Profile pages | Membership tier badge |
| `[session_attendance]` | Program supervisors | Club program session roster + emergency contacts |
| `[mixed_dev_attendance]` | Program supervisors | Mixed Development roster (program-specific variant) |

## Options reference

| Option | Set from |
|--------|----------|
| `team_oversight_teams` / `team_oversight_team_meta` | Configuration → Team Management |
| `team_oversight_season_dates` | Configuration → Season Dates |
| `team_oversight_trial_fee_product`, `team_oversight_training_info_url`, `team_oversight_trial_open_seasons` | Trial Applications → settings |
| `team_oversight_created_seasons` | Configuration → Create season |
| `team_oversight_reminder_hour`, `team_oversight_reminder_timezone`, `team_oversight_reminder_minimum`, `team_oversight_reminder_grace_days` | Emails → reminder settings |
| `team_oversight_transfer_clubs`, `team_oversight_trial_fee_rules` | Configuration → Trial application rules |
| `team_oversight_programs`, `team_oversight_program_supervisors` | Club Programs → Settings |
| `team_oversight_payment_product` | Payment Management → settings |
| `team_oversight_vv_reg_url`, `team_oversight_kit_shop_url`, `team_oversight_fees_page_url`, `team_oversight_kit_products` | Player Readiness → settings |
| `team_oversight_membership_category_rules` | Club Membership → category rules |

## Database tables

All created/migrated automatically on load (`TeamOversight_Database::migrate_database`); migrations are additive and idempotent.

- `team_assignments` — team/role assignments (user-ID keyed with email snapshot)
- `team_invoices` — season fee balances (user-ID keyed)
- `team_invoice_payments` — payment ledger, the source of truth for paid amounts (order-item deduped; sources: online / manual / reconciliation)
- `team_fee_segments` — dated fee-role history per member per season
- `team_memberships` — membership tier grants
- `trial_applications` — applications (trial number, questionnaire JSON, fee order)
- `team_trial_selections` — per-team coach verdicts
- `team_trial_notes` — shared coach notes
- `team_accreditations` — RevSport data (email keyed — CSV matches by email)
- `team_activity_log` — append-only activity log (payments, emails, fee edits, grants; pruned after 2 years)
- `team_program_attendance` — club program attendance ticks (one row per booking per session)
- `team_attendance` — coach-portal trial/training attendance (one row per player per team per date; unique on person + team + date)
- `fee_matrix`, `fee_matrix_versions` — season fee rates

## Season rollover checklist

1. Configuration: select the new season → set **Season Dates**, import/adjust the **Fee Matrix** (include $0 rows for exempt roles: Coach, Assistant Coach, Team Manager), review teams (age rules shift automatically).
2. Trial Applications: set the season's **trial fee product**; update the training-info page.
3. Verify the payment product and Ready to Play settings still point at the right products/pages.
4. After trials: coaches record verdicts → admin **Finalise Coach Selections** → fees generate automatically.

## Development notes

- **Conventions**: all POST/AJAX handlers verify a nonce and (admin actions) `manage_options`; front-end forms use Post/Redirect/Get via `template_redirect` (never process POSTs during shortcode render — refresh would resubmit); all SQL through `$wpdb->prepare`; people are keyed by `user_id` with email kept as a display/import snapshot; roles are handled role-aware (staff assignments are never collateral damage of player operations).
- **Identity**: the fees engine resolves people via `TeamOversight_Fees::resolve_user_id()` and matches all rows with `(user_id = X OR email = Y)`, so one person's assignments/segments/invoices stay unified across account email changes.
- **Integrity guards**: `order_item_id` carries a UNIQUE index on both the payments ledger and membership grants (concurrent order hooks physically cannot double-apply); invoices with recorded payments cannot be deleted (zero the fee instead); overpayments (fee reduced below ledger paid, or an online payment above the balance) surface as "in credit" on the member panel and Payment Management.
- **Order storage**: all order queries are storage-agnostic as of 1.23.0 (`wc_get_orders` and WooCommerce analytics tables — no direct `wp_posts` order queries), so the plugin works under both legacy and HPOS order storage. Verify on staging before flipping HPOS on live, but it is no longer blocked by this plugin.
- **Known limitations**: read-path queries in some legacy admin *display* screens still join by email (write paths all record `user_id`); strings are not internationalised (club-internal plugin); no uninstall routine — deleting the plugin never deletes data. Overdue reminders (like all crons here) depend on WP-cron, which fires on site traffic. Multi-step money operations are not wrapped in DB transactions (the ledger + recompute design makes partial failures recoverable rather than corrupting).
- **Local dev**: Docker copy of the site; lint against PHP 7.4 (`php -l`); deploy by copying the plugin folder into the container. Deployed to production from this repo via Deployer for Git (`main`).
