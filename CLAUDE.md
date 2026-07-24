# Team Oversight — AI maintainer guide

Read this before changing anything. The README covers *what the plugin does*;
this file covers what you need to know to work on it safely.

## What this is

Custom WordPress plugin running MURVC (Melbourne University Renegades
Volleyball Club) on **members.renegades.com.au**. It manages memberships,
VVL competition trials, coach selections, team assignments, fees/payments,
and pre-season readiness. It integrates:

- **Ultimate Member (UM)** — accounts, profiles, login
- **WooCommerce + Square** — all payments (memberships, trial fees, club fees, kit)

Pushing to `main` deploys to the LIVE site via Deployer for Git. Treat every
push as a production deploy: lint and test first (see Workflow below).

## File map

- `team-oversight.php` — bootstrap: requires classes, `create_tables`, cron teardown. Version lives here TWICE (header + `TEAM_OVERSIGHT_VERSION`) — bump both.
- `includes/class-database.php` — table creation + `migrate_database` (additive, idempotent, runs on every load), team config (`get_teams_config`), age rules / DOB cutoffs.
- `includes/class-admin.php` — all VVL Oversight admin pages (trials, assignments, payment management, configuration, readiness) + menu registration.
- `includes/class-members-page.php` — Club Membership admin: Members list, Membership History, MUS Matrix, VV Report, seeding, order re-scan.
- `includes/class-stats-page.php` — Club Membership → Stats: data-quality snapshots/trends and postcode distribution + watchlist, scoped to current ledger members.
- `includes/class-memberships.php` — membership tier engine: grants ledger, product/category → tier mapping, order hooks, role sync, cron.
- `includes/class-fees.php` — fee matrix, season dates, pro-rata, fee segments (dated fee-role history), invoice generation.
- `includes/class-payments.php` — payment schedule/overdue, `[member_fees]`, pay-any-amount checkout flow, payment ledger.
- `includes/class-trials.php` — `[team_trial_form]`, trial fee payment flow, trial numbers, eligibility.
- `includes/class-coach-portal.php` — `[team_coach_portal]`: selection board, verdicts, notes, roster export.
- `includes/class-readiness.php` — `[ready_to_play]` checklist + Player Readiness admin.
- `includes/class-imports.php` / `class-exports.php` — RevSport CSV import; team list / MUS CSV exports.

## Domain glossary

- **Season** = calendar year string ('2026'). Season dates (start/end) are configured per season and drive all pro-rata and overdue maths.
- **Membership tier** = time-bound grant in `team_memberships` (life/full/associate). Highest unexpired grant wins. End date >= 2099-01-01 means "never expires" (life). Grants come from purchases (product meta or category rules), manual admin grants, or seeding.
- **Assignment** = a person on a team for a season with a role: `playing_member`, `training_only`, `coach`, `assistant_coach`, `team_manager`. One person can hold several (incl. coach + player on the same team).
- **Verdict** = a coach's per-team call on a trial applicant: tentative / selected / training_only / rejected. Verdicts are NOT assignments; the admin "Finalise Coach Selections" converts selected/training_only verdicts into assignments + invoices.
- **Fee segment** = dated span of a member's cheapest fee role in a season (`team_fee_segments`). Season fee = Σ segment rate × share of season. Role changes checkpoint segments; payments are never re-charged.
- **MUS** = Melbourne University Sport (the club reports member matrices to them). **VV** = Volleyball Victoria. **VVL** = Victorian Volleyball League.
- **Trial number** = per-season sequential number given at application, players write it on themselves at trials.

## Hard-won gotchas (do not rediscover these)

- **Target PHP 7.4.** No PHP 8 syntax: no `str_contains`, no null-safe `?->`, etc. Lint with `php -l` under 7.4 before every push.
- **UM meta formats:** `gender` is a serialized array `a:1:{i:0;s:4:"Male";}` (use `maybe_unserialize` + `reset`); `birth_date` is `YYYY/MM/DD` (convert `/`→`-` before `strtotime`). Many older accounts only have the legacy plain-string `gender_dropdown` key — reporting queries COALESCE to it.
- **People are keyed by `user_id`;** email is a snapshot for display/CSV matching. Some old admin read paths still join by email — new code must write user_id and prefer it on reads.
- **Orders are legacy CPT storage (wp_posts), NOT HPOS.** Order queries hit `wp_posts` / `wc_order_product_lookup`. Membership grants fire on order status → processing/completed only, and only for orders linked to a WP account (guests grant nothing).
- **Role-aware operations:** never let player-row operations delete coach/manager assignments (this bug happened twice). Any DELETE/UPDATE on assignments must filter by role.
- **Front-end forms use Post/Redirect/Get** via `template_redirect` + flash transients. Never process POST during shortcode render (refresh resubmits).
- **Every admin POST/AJAX handler**: nonce + `current_user_can('manage_options')`. Coach portal: server-side check that the user coaches that team.
- **Migrations are additive and idempotent** (`migrate_database` runs on every load). Never rename/drop columns; add and backfill.
- **The live site also runs WPCode snippets outside this repo** (annual profile-update wall, admin tools). If behaviour on live isn't explained by this codebase, check WPCode. Long-term, prefer moving snippet logic into the plugin.
- **The DB table prefix is not the WordPress default** — always use `$wpdb->prefix`, never hardcode a prefix.
- **Commit messages must not contain double quotes** (PowerShell + git arg-passing mangles them on the maintainer's machine).

## Development workflow

There is a **local Docker mirror** of the site (containers `murvc_wp`,
`murvc_cli`, `murvc_db`; site at `localhost:8080`; WP root
`/var/www/html`), seeded from a database dump. The loop that works:

1. Edit code in this repo.
2. Lint: `docker run --rm -v <repo>:/code php:7.4-cli php -l /code/includes/<file>.php`
3. Deploy to local: `docker cp <repo>/. murvc_wp:/var/www/html/wp-content/plugins/team-oversight-migration/`
4. Test end-to-end with a PHP script run via `docker cp script.php murvc_cli:/tmp/ && docker exec murvc_cli wp eval-file /tmp/script.php --path=/var/www/html`. Pattern: create throwaway users/orders/grants, exercise the real code path (not a reimplementation), echo OK/FAIL lines, clean up. Use far-past seasons (e.g. '1990', '2098') to avoid colliding with real data. Use reflection for private methods.
5. Bump the version (both places), update README if behaviour changed, commit, push — **push = live deploy**.

## This repo is PUBLIC — privacy rules

The repo must never contain member or club-sensitive data. Concretely:

- Never commit database dumps, CSV exports, screenshots of admin pages, or
  test fixtures containing real names, emails, phone numbers, or DOBs.
  Test scripts must generate throwaway users (`@example.test` emails) and
  delete them.
- No credentials, API keys, security-plugin details, or infrastructure
  specifics (server paths, DB names/prefixes, hosting) in code or docs.
- The local Docker mirror contains real member data — it stays on the
  maintainer's machine, never in this repo, and its dumps are confidential.
- When documenting a data issue, describe the shape, not the people
  ("older accounts use a legacy field", not counts of identifiable groups
  or examples with real values).

## Invariants to preserve

- Deleting the plugin must never delete data (no uninstall routine — intentional).
- Payments/ledger rows are never mutated by fee recalculation; outstanding = fee − paid, always.
- Dedupe rules: membership grants by `order_item_id`; payment applications by order item; re-running any seeding/re-scan/finalise tool must be a no-op the second time.
- Season rollover requires zero code changes (age cutoffs, fees, teams all derive from config + season year).
