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

# Team Oversight Plugin

MURVC's club management system for WordPress, integrating Ultimate Member (accounts/profiles) and WooCommerce (payments via Square). The admin splits into two areas:

- **Club Membership** — club-wide member list, time-bound membership tiers, membership history reporting
- **VVL Oversight** — competition machinery: teams, trials, coach selections, team assignments, fees and payments, player readiness

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
| Full Member | `full-member` | Purchase or manual | Term from purchase date |
| Associate Member | `associate-member` | Purchase or manual | Term from purchase date |

- **From purchases**: set "Membership tier granted" + "Membership term (months)" on a WooCommerce product (Product → Edit → General), or configure product-category rules on the Members page. Only explicitly configured products grant anything. Terms run from the purchase date; overlapping grants simply overlap (highest active tier wins).
- **Role sync**: the `full-member`/`associate-member`/`life-member` WP roles are kept in sync on every grant and by a daily cron (`team_oversight_membership_sync`), including automatic demotion when the last grant expires. Users with roles but **no** ledger rows are never touched (protects pre-ledger role assignments until seeding runs).
- **Seeding**: Members page → "Seed memberships from this year's purchases" converts the year's qualifying purchases into dated grants (dry-run available, idempotent).
- **Order re-scan**: Members page → "Re-scan this year's paid orders" replays every paid order through the grant logic with the *current* product/category configuration — run it after adding membership attributes to a product, since orders paid before the attributes were set granted nothing. Idempotent; backfilled grants are dated from the order's paid date. (Grants require the order to reach Processing/Completed *and* be linked to a WP account — guest orders never grant.)
- The `[murvc_member_role]` shortcode (registered by the plugin) shows the profile owner's tier badge on Ultimate Member profiles.

### Members page (Club Membership menu)

One row per person: membership status + expiry, age, gender, MUS category, VVL teams for the season, fees owing, VA accreditation, profile-confirmation status. Filter/search/sort, CSV export, manual grant (with email autocomplete) and revoke.

### Membership History

Club Membership → Membership History: pick a date range and see everyone whose membership overlapped it — highest tier held, every membership period with dates and source, age/gender/MUS, current status. CSV export. History exists from the moment grants exist (run seeding to backfill).

---

## VVL Oversight

### Teams (Configuration page)

Teams are configured with a **code** (stable internal ID — never shown to players), **name** (what players see), **gender** (men's/women's/mixed), **age eligibility rule** and **playing-shirt count**:

- Age rules follow the VVL By-Laws and compute their DOB cutoff from the season year automatically (nothing to update each season): **U19** = no 19th birthday during the season year; **U17**/**U15** (YSL) = 16/14 or younger as of 31 August.
- Shirts: how many playing shirts a player must pay for on this team (Premier 2, YSL 0 — supplied, default 1).
- "Load default club team list" resets to the current club teams.

### Trials

Front-end form via `[team_trial_form]` (login required; prompts to log in / create an account with an explanation):

- **Prefilled, read-only account details** (name, email, phone, DOB, gender, institution) — edited via the profile, never the form. Profile-completeness (incl. MUS fields, contact number, gender) gates submission.
- Competition (men's/women's) derives from profile gender; the question is only asked when the profile can't answer (unset/non-binary).
- Questionnaire mirrors the club's VVL trials form: VVL history (with conditional returning-player and club-transfer sections), international player details, team selection (real teams, grouped by gender, with ineligible teams greyed out live by competition and DOB cutoff — enforced server-side too), positions, venue availability, trial-date availability, experience. Answers stored as JSON (`form_data`).
- **Trial fee**: configure a product in the Trial Applications settings box. Submissions save as `awaiting_payment`, go to checkout, and become reviewable (`pending`) when the order is paid. Unpaid applications expire after 7 days; "Mark as Paid" covers offline payments. No fee product = direct submission.
- **Trial numbers**: per-season sequential number assigned at submission, shown persistently to the player (status panel on the form page) and throughout admin/coach views — players can write it on themselves at busy trials.
- Optional **training-info page URL** linked at the top of the form so players pick teams by training venue/day.

### Coach portal

`[team_coach_portal]` — visible only to logged-in users with an active coach/assistant-coach assignment; each coach sees only their own teams (server-enforced). Mobile-friendly card layout.

- **Team switcher** for multi-team coaches; **Coaching Staff** table + **Players** cards (confirmed members plus selection-board players, tinted by verdict, over-age players flagged with DOB and "VV exemption required").
- **Selection board**: per-team verdicts — Tentative / Selected / Training Only / Rejected — via a dropdown on each applicant card. Verdicts are per team, never global: a player can be Selected by multiple teams (e.g. YSL + JPL), and every coach sees every team's verdicts. "Unclaimed" = no verdicts anywhere.
- Applicant pools are **competition-wide** (all men's or women's applicants, not just those who picked the team — players get redirected between trials and VV grants age exemptions), sectioned: *awaiting your verdict* first, then *verdict recorded*, then *other applicants*. Search + only-my-verdicts filter.
- **Shared notes** on applications (author + date, visible to all coaches and admins).
- **Roster CSV export** (with positions and selection status).
- Coaches never trigger fees: converting Selected/Training-Only verdicts into real assignments + invoices is the admin **"Finalise Coach Selections"** button on Trial Applications (idempotent; Training Only finalises as the `training_only` role and rate).

### Fees & Payment Management

Fees are a **balance against a season timeline**, not an invoice event:

- **Season dates** (Configuration, per season) drive everything. No dates = full fees, nothing overdue.
- **Fee matrix** (Configuration, per season): rate per fee class × team role. The **minimum-fee rule** applies: a member pays the cheapest rate across their active roles (so coach/manager rows at $0 exempt playing coaches).
- **Fee segments** (`team_fee_segments`): a dated history of each member's fee role per season. Every assignment change (accept, finalise, role edit, delete, deactivate) checkpoints it; the season fee is the sum of each segment's rate × its share of the season window. So joining mid-season pro-rates, upgrading training-only → playing mid-season charges each period at its own rate, becoming a coach mid-season applies the coach rate from that date, and leaving all teams freezes the fee at the accrued amount. Segments clamp to the season window, so anything effective before the season start charges from the start. Payments already made always carry forward (outstanding = new fee − paid).
- **Payment schedule**: fees fall due linearly between the season dates. Overdue = expected-by-today − paid. Members see a breakdown (season fee / paid / remaining / overdue), a "Next payment due" date when on-track, and a progress bar (paid % vs season % elapsed).
- **Pay-any-amount**: `[member_fees]` (and the Ready to Play fees step) let members pay whatever they choose whenever they like via a dedicated payment product whose price is overridden to the entered amount. Paid orders reduce outstanding (oldest season first) and are recorded in the `team_invoice_payments` ledger.
- **Payment Management** (admin): one row per member per season — fee, paid, outstanding, overdue, payment count (hover for ledger) — with inline amount overrides, the payment-product setting, and a season-dates status hint.

### Ready to Play

`[ready_to_play]` — renders only for players selected into a team (confirmed playing/training assignment or a coach Selected/Training-Only verdict) for the current or next season; safe to place on profile pages. Steps in priority order:

1. **VV membership** — external registration link + manual tick.
2. **Playing shirt payment** — the club issues the shirt; the payment is once-ever, so **all-time** purchases of the configured shirt products count, no self-tick, and admins can record a **credit** (with note) for shirts paid under a different account. Quantity from the team config.
3. **Shorts & socks** — regular products: this-season purchases auto-complete, manual tick for older kit, shop link always available for re-orders.
4. **Fees** — breakdown, schedule status, and the pay box (live when the payment product is configured).

Admin: VVL Oversight → **Player Readiness** — every selected player's VV/shirt/kit/fees status and a Ready flag, plus the settings (URLs and kit product IDs) and per-player shirt credits.

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
| `[ready_to_play]` | Selected players | Pre-season checklist |
| `[murvc_member_role]` | Profile pages | Membership tier badge |

## Options reference

| Option | Set from |
|--------|----------|
| `team_oversight_teams` / `team_oversight_team_meta` | Configuration → Team Management |
| `team_oversight_season_dates` | Configuration → Season Dates |
| `team_oversight_trial_fee_product`, `team_oversight_training_info_url` | Trial Applications → settings |
| `team_oversight_payment_product` | Payment Management → settings |
| `team_oversight_vv_reg_url`, `team_oversight_kit_shop_url`, `team_oversight_fees_page_url`, `team_oversight_kit_products` | Player Readiness → settings |
| `team_oversight_membership_category_rules` | Club Membership → category rules |

## Database tables

All created/migrated automatically on load (`TeamOversight_Database::migrate_database`); migrations are additive and idempotent.

- `team_assignments` — team/role assignments (user-ID keyed with email snapshot)
- `team_invoices` — season fee balances (user-ID keyed)
- `team_invoice_payments` — payment ledger (order-item deduped)
- `team_fee_segments` — dated fee-role history per member per season
- `team_memberships` — membership tier grants
- `trial_applications` — applications (trial number, questionnaire JSON, fee order)
- `team_trial_selections` — per-team coach verdicts
- `team_trial_notes` — shared coach notes
- `team_accreditations` — RevSport data (email keyed — CSV matches by email)
- `fee_matrix`, `fee_matrix_versions` — season fee rates

## Season rollover checklist

1. Configuration: select the new season → set **Season Dates**, import/adjust the **Fee Matrix** (include $0 rows for exempt roles: Coach, Assistant Coach, Team Manager), review teams (age rules shift automatically).
2. Trial Applications: set the season's **trial fee product**; update the training-info page.
3. Verify the payment product and Ready to Play settings still point at the right products/pages.
4. After trials: coaches record verdicts → admin **Finalise Coach Selections** → fees generate automatically.

## Development notes

- **Conventions**: all POST/AJAX handlers verify a nonce and (admin actions) `manage_options`; front-end forms use Post/Redirect/Get via `template_redirect` (never process POSTs during shortcode render — refresh would resubmit); all SQL through `$wpdb->prepare`; people are keyed by `user_id` with email kept as a display/import snapshot; roles are handled role-aware (staff assignments are never collateral damage of player operations).
- **Known limitations**: read-path queries in some legacy admin screens still join by email (write paths all record `user_id`); kit-purchase detection reads legacy CPT order storage (not HPOS); strings are not internationalised (club-internal plugin); no uninstall routine — deleting the plugin never deletes data.
- **Local dev**: Docker copy of the site; lint against PHP 7.4 (`php -l`); deploy by copying the plugin folder into the container. Deployed to production from this repo via Deployer for Git (`main`).
