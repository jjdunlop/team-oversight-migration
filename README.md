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
| Full Member | `full-member` | Purchase, manual, or automatic on team assignment | Term from purchase date; assignment grants run to 31 Dec of the season year |
| Associate Member | `associate-member` | Purchase or manual | Term from purchase date |

- **From purchases**: set "Membership tier granted" + "Membership term (months)" on a WooCommerce product (Product → Edit → General), or configure product-category rules on the Members page. Only explicitly configured products grant anything. Terms run from the purchase date; overlapping grants simply overlap (highest active tier wins).
- **From team assignments**: anyone with an active team assignment (any role — player, training only, coach, assistant coach, team manager) for the current season or later is automatically granted Full Membership running to 31 Dec of the season year (`source = assignment`). Synced immediately after plugin admin changes and by the daily cron; deduped per person per season, and removing someone from a team never revokes the grant.
- **Role sync**: the `full-member`/`associate-member`/`life-member` WP roles are kept in sync on every grant and by a daily cron (`team_oversight_membership_sync`), including automatic demotion when the last grant expires. Users with roles but **no** ledger rows are never touched (protects pre-ledger role assignments until seeding runs).
- **Seeding**: Members page → "Seed memberships from this year's purchases" converts the year's qualifying purchases into dated grants (dry-run available, idempotent).
- **Order re-scan**: Members page → "Re-scan this year's paid orders" replays every paid order through the grant logic with the *current* product/category configuration — run it after adding membership attributes to a product, since orders paid before the attributes were set granted nothing. Idempotent; backfilled grants are dated from the order's paid date. (Grants require the order to reach Processing/Completed *and* be linked to a WP account — guest orders never grant.)
- The `[murvc_member_role]` shortcode (registered by the plugin) shows the profile owner's tier badge on Ultimate Member profiles.

### Admin dashboard

The WP dashboard is replaced (for admins) with a fast **MURVC Club Overview** widget: current members, fees outstanding, overdue people/amount, trial applications awaiting action, people on teams — each tile linking to its admin page — plus the latest activity-log entries and quick links. The slow stock widgets (WordPress news, WooCommerce status/report queries, quick draft, activity, Solid Security panel) are removed; the widget itself renders from the plugin's indexed tables in a few milliseconds.

### Members page (Club Membership menu)

One row per person: membership status + expiry, age, gender, MUS category, VVL teams for the season, fees owing, VA accreditation, profile-confirmation status. Filter/search/sort, CSV export, manual grant (with email autocomplete) and revoke.

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

Teams are configured with a **code** (stable internal ID — never shown to players), **name** (what players see), **gender** (men's/women's/mixed), **age eligibility rule** and **playing-shirt count**:

- Age rules follow the VVL By-Laws and compute their DOB cutoff from the season year automatically (nothing to update each season): **U19** = no 19th birthday during the season year; **U17**/**U15** (YSL) = 16/14 or younger as of 31 August.
- Shirts: how many playing shirts a player must pay for on this team (Premier 2, YSL 0 — supplied, default 1).
- "Load default club team list" resets to the current club teams.
- **Create season** (button beside the Configuration season selector): registers the next season (highest known + 1, e.g. 2028) so it appears in every season selector before any data exists — ready for season dates, fee matrix, and trial opening.

### Trials

Front-end form via `[team_trial_form]` (login required; prompts to log in / create an account with an explanation):

- **Prefilled, read-only account details** (name, email, phone, DOB, gender, institution) — edited via the profile, never the form. Submission requires only what trials genuinely need: name, contact number, DOB and gender (age rules + competition). MUS/degree fields are **not** required to apply — they're a fee-class matter, collected by the annual profile wall and resolved at invoicing.
- Competition (men's/women's) derives from profile gender; the question is only asked when the profile can't answer (unset/non-binary).
- Questionnaire mirrors the club's VVL trials form: VVL history (with conditional returning-player and club-transfer sections — the previous club is a dropdown of the VVL clubs plus Other Victorian / Interstate / International options that reveal a club-name field, stored as e.g. "Interstate: Sydney Uni"), international player details, team selection (real teams, grouped by gender, with ineligible teams greyed out live by competition and DOB cutoff — enforced server-side too), positions, venue availability, trial-date availability, experience. Answers stored as JSON (`form_data`).
- **Per-season opening**: "Accepting trial applications for: [year checkboxes]" (Trial Applications → settings). Only ticked seasons appear in the trial form's season selector, and submissions for unticked seasons are rejected server-side; with nothing ticked the form shows a friendly closed notice (existing applicants always keep seeing their trial number and status). The settings summary shows the open seasons, or APPLICATIONS CLOSED in red.
- **Trial fee rules** (Configuration → Trial application rules): who pays the trial fee is an if-then rule set — by default **new-to-VVL and transferring players pay**, **returning Renegades players are free**, and **anyone under 18 is free regardless** (threshold configurable, 0 disables). Waived applications submit directly (no checkout) and record the waiver reason in the application ("Waived — under 18" / "Waived — returning Renegades player"). The form's fee notice adapts: juniors see "trials are free for players under X", adults see who the fee applies to. The **transfer club list** is editable in the same section (one club per line; empty = built-in VVL default list).
- **Trial fee**: configure a product in the Trial Applications settings box. Submissions save as `awaiting_payment`, go to checkout, and become reviewable (`pending`) when the order is paid. Unpaid applications expire after 7 days; "Mark as Paid" covers offline payments. No fee product = direct submission.
- **Trial numbers**: per-season sequential number assigned at submission, shown persistently to the player (status panel on the form page) and throughout admin/coach views — players can write it on themselves at busy trials.
- Optional **training-info page URL** linked at the top of the form so players pick teams by training venue/day.

### Coach portal

`[team_coach_portal]` — visible only to logged-in users with an active coach/assistant-coach assignment; each coach sees only their own teams (server-enforced). Mobile-friendly card layout.

- **Team switcher** for multi-team coaches; **Coaching Staff** table + **Players** cards (confirmed members plus selection-board players, tinted by verdict, over-age players flagged with DOB and "VV exemption required"). **All cards share one format**: trial number, registration-status chip, and the expander row (emergency contact / application details / shared notes with add-note) appear on confirmed players and selection-roster players too — a confirmed player keeps their full trial context. Players without an application (added manually) get the plain card.
- **Selection board**: per-team verdicts — Tentative / Selected / Training Only / Rejected — via a dropdown on each applicant card. Verdicts are per team, never global: a player can be Selected by multiple teams (e.g. YSL + JPL), and every coach sees every team's verdicts. "Unclaimed" = no verdicts anywhere.
- Applicant pools are **competition-wide** (all men's or women's applicants, not just those who picked the team — players get redirected between trials and VV grants age exemptions), sectioned: *awaiting your verdict* first, then *verdict recorded*, then *other applicants*. Search + only-my-verdicts filter.
- **Shared notes** on applications (author + date, visible to all coaches and admins).
- **Registration-status chip** on every applicant and selection-roster card, derived automatically at submission: **New** (first VVL season), **Returning** (Renegades history, no other club since), **FA: [club]** (free agent — skipped a season or more, no transfer needed), **⇄ [club]** (club transfer required — played elsewhere as recently as last season), **ITC** (registered with an overseas federation *and* trialling for a Premier League 1 team — ITC only applies at P1). Colour-coded, truncated for long club names, full explanation on hover. The "season last played" input is a structured dropdown so the Free Agent / Transfer split is computed reliably.
- **Emergency contacts dropdown** on every player and applicant card — both profile contacts when recorded (primary + the second-contact fields), each with name, relationship and a tap-to-call number (AU numbers normalised, stripped leading zeros restored) — and a nudge when a member has none recorded.
- **Roster CSV export** (with positions and selection status).
- Coaches never trigger fees: converting Selected/Training-Only verdicts into real assignments + invoices is the admin **"Finalise Coach Selections"** button on Trial Applications (idempotent; Training Only finalises as the `training_only` role and rate).

### Fees & Payment Management

Fees are a **balance against a season timeline**, not an invoice event:

- **Season dates** (Configuration, per season) drive everything. No dates = full fees, nothing overdue.
- **Fee matrix** (Configuration, per season): rate per fee class × team role. The **minimum-fee rule** applies: a member pays the cheapest rate across their active roles (so coach/manager rows at $0 exempt playing coaches).
- **Fee segments** (`team_fee_segments`): a dated history of each member's fee role per season. Every assignment change (accept, finalise, role edit, delete, deactivate) checkpoints it; the season fee is the sum of each segment's rate × its share of the season window. So joining mid-season pro-rates, upgrading training-only → playing mid-season charges each period at its own rate, becoming a coach mid-season applies the coach rate from that date, and leaving all teams freezes the fee at the accrued amount. Segments clamp to the season window, so anything effective before the season start charges from the start. Payments already made always carry forward (outstanding = new fee − paid).
- **Payment schedule**: fees fall due linearly between the season dates. Overdue = expected-by-today − paid. Members see a breakdown (season fee / paid / remaining / overdue), a "Next payment due" date when on-track, and a progress bar (paid % vs season % elapsed). **Past-season debts roll forward**: once a season's year is over, whatever remains outstanding counts as fully overdue (season dates or not) everywhere overdue is shown, and payments always clear the oldest season first.
- **The payments ledger is the single source of truth for money.** Paid = the sum of `team_invoice_payments` rows for the invoice; outstanding = fee − paid, always computed, never edited. Every dollar — online, cash, EFT, corrections — is a ledger row with a date, source, and note. (A one-time migration backfilled `reconciliation` rows for payments recorded before this model.)
- **Pay-any-amount**: `[member_fees]` (and the Ready to Play fees step) let members pay whatever they choose whenever they like via a dedicated payment product whose price is overridden to the entered amount. Paid orders apply oldest season first, each application recorded as an `online` ledger row.
- **Payment Management** (admin): one row per member per season — fee, paid (from the ledger), outstanding, overdue, payment count (hover for the ledger detail). **Edit Fee** reprices the invoice (payments are untouched; outstanding recomputes); **Record Payment** adds a `manual` ledger row for cash/EFT/corrections (negative amounts allowed for refunds), stamped with who recorded it. Outstanding itself is not editable — by design.
- **Overdue reminder emails**: optional (off by default), managed on **VVL Oversight → Emails**: editable subject/body templates with placeholders (`{first_name}` `{name}` `{overdue}` `{outstanding}` `{link}`), configurable From name/address + Reply-To (the From address must be one the web server may send as — the WooCommerce order-email address is the proven-safe choice; Reply-To can be any real mailbox), a live preview, "Send test email to me", the automatic-send switch + per-person interval (default 7 days), "Preview recipients" (lists who would be emailed without sending) and "Send reminders now". The daily check covers rolled-over past-season debts; only people resolvable to a WP account are emailed (trials are account-gated, so that's everyone with a fee).

### Ready to Play

`[ready_to_play]` — shows the checklist for people selected into a team (confirmed assignment or a coach Selected/Training-Only verdict) for the current or next season. Anyone logged in who *isn't* selected sees the fee panel instead — so ex-players carrying a debt can still see and pay it, and paid-up members get a "nothing owing" confirmation (logged-out visitors see nothing). Team managers count as players (their role only exists for the fee discount). Coaches/assistant coaches who aren't also playing get a reduced "Get Ready to Coach" checklist: VV registration + fees only, no kit steps. Player steps in priority order:

1. **VV membership** — external registration link + manual tick.
2. **Playing shirt payment** — the club issues the shirt; the payment is once-ever, so **all-time** purchases of the configured shirt products count, no self-tick, and admins can record a **credit** (with note) for shirts paid under a different account. Quantity from the team config.
3. **Shorts & socks** — regular products: this-season purchases auto-complete, manual tick for older kit, shop link always available for re-orders.
4. **Fees** — breakdown across **all seasons** (carried-over debts surface here as overdue), schedule status for the panel's season, and the pay box (live when the payment product is configured).

Admin: VVL Oversight → **Player Readiness** — every selected player's VV/shirt/kit/fees status and a Ready flag, plus the settings (URLs and kit product IDs) and per-player shirt credits. Each player appears once, evaluated for the **latest season they're in** (current year or later) — so end-of-year selections for next season show next-season readiness immediately.

### Logs

One append-only activity log, two windows onto it (filter by event type, search by name/email/message; entries kept two years; each row stamped with when, who it was about, and which admin — or "System" for cron — did it):

- **VVL Oversight → Logs**: online and manual payments (amounts, notes, order refs), reminder emails sent, fee edits (old → new amount). Bulk reconciliation backfills deliberately stay out.
- **Club Membership → Logs**: the membership lifecycle — **Granted** (first membership, or one that doesn't change current status), **Extended** (same tier, new end date), **Upgraded** (e.g. Associate → Full, message shows the transition), **Expired** (logged once by the daily role sync when the last grant lapses), **Revoked** (admin action, shows the tier held). Past-dated grants from seeding log as "recorded".

---

## Club Programs

Casual programs (Mixed Development and similar) sell one WooCommerce product per program with a **variation per session date**, so "who is coming on Monday?" is already in the orders. This area reads it live — no separate attendance database.

- **Programs** are configured in Club Programs → Settings as `Name | product ID` (Mixed Development is auto-detected on first use). Each configured program gets its own admin sub-page listing its sessions.
- **Session roster**: a session picker defaulting to today (or the next upcoming session), a booked count, and one card per paying attendee — name, email, tap-to-call mobile, order number, and the same **emergency contact dropdown** the coach portal uses.
- **Guests**: someone who books multiple spots shows a `×3 (2 guests)` chip — the extra spots are real attendees whose names the club doesn't have. Orders placed without a club account show a **No account** chip (no profile, so no emergency contact).
- **Supervisor access**: `[session_attendance]` on a page shows the roster to administrators and to the supervisors listed (by email) in Club Programs → Settings; everyone else gets a polite notice. Lock a page to one program with `[session_attendance program="mixed-dev"]`.

### Data imports/exports

- **RevSport CSV import** (accreditations: VA ID, payment status, coach/referee accreditation).
- **Team Lists** and **MUS Membership Report** CSV exports. All CSVs are UTF-8 with BOM (Excel-safe).

---

## Shortcodes

| Shortcode | Audience | Purpose |
|-----------|----------|---------|
| `[team_trial_form]` | Logged-in members | Trial application (+ payment) |
| `[team_coach_portal]` | Coaches | Selections, notes, rosters |
| `[member_fees]` | Logged-in members | Fee balance + pay any amount |
| `[player_fees]` | Members with overdue fees | Compact overdue-fees flag linking to the Player Checklist page (`url` attribute overrides the default `/player-checklist/`); renders nothing at all unless the viewer has overdue fees — safe on any page |
| `[ready_to_play]` | Selected players | Pre-season checklist |
| `[murvc_member_role]` | Profile pages | Membership tier badge |
| `[session_attendance]` | Program supervisors | Club program session roster + emergency contacts |

## Options reference

| Option | Set from |
|--------|----------|
| `team_oversight_teams` / `team_oversight_team_meta` | Configuration → Team Management |
| `team_oversight_season_dates` | Configuration → Season Dates |
| `team_oversight_trial_fee_product`, `team_oversight_training_info_url`, `team_oversight_trial_open_seasons` | Trial Applications → settings |
| `team_oversight_created_seasons` | Configuration → Create season |
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
- `fee_matrix`, `fee_matrix_versions` — season fee rates

## Season rollover checklist

1. Configuration: select the new season → set **Season Dates**, import/adjust the **Fee Matrix** (include $0 rows for exempt roles: Coach, Assistant Coach, Team Manager), review teams (age rules shift automatically).
2. Trial Applications: set the season's **trial fee product**; update the training-info page.
3. Verify the payment product and Ready to Play settings still point at the right products/pages.
4. After trials: coaches record verdicts → admin **Finalise Coach Selections** → fees generate automatically.

## Development notes

- **Conventions**: all POST/AJAX handlers verify a nonce and (admin actions) `manage_options`; front-end forms use Post/Redirect/Get via `template_redirect` (never process POSTs during shortcode render — refresh would resubmit); all SQL through `$wpdb->prepare`; people are keyed by `user_id` with email kept as a display/import snapshot; roles are handled role-aware (staff assignments are never collateral damage of player operations).
- **Identity**: the fees engine resolves people via `TeamOversight_Fees::resolve_user_id()` and matches all rows with `(user_id = X OR email = Y)`, so one person's assignments/segments/invoices stay unified across account email changes.
- **Integrity guards**: `order_item_id` carries a UNIQUE index on both the payments ledger and membership grants (concurrent order hooks physically cannot double-apply); invoices with recorded payments cannot be deleted (zero the fee instead); overpayments (fee reduced below ledger paid) surface as "in credit" on the member panel and Payment Management.
- **Order storage**: all order queries are storage-agnostic as of 1.23.0 (`wc_get_orders` and WooCommerce analytics tables — no direct `wp_posts` order queries), so the plugin works under both legacy and HPOS order storage. Verify on staging before flipping HPOS on live, but it is no longer blocked by this plugin.
- **Known limitations**: read-path queries in some legacy admin *display* screens still join by email (write paths all record `user_id`); strings are not internationalised (club-internal plugin); no uninstall routine — deleting the plugin never deletes data. Overdue reminders (like all crons here) depend on WP-cron, which fires on site traffic. Multi-step money operations are not wrapped in DB transactions (the ledger + recompute design makes partial failures recoverable rather than corrupting).
- **Local dev**: Docker copy of the site; lint against PHP 7.4 (`php -l`); deploy by copying the plugin folder into the container. Deployed to production from this repo via Deployer for Git (`main`).
