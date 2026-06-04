# THE GOAT — v3.5.0

_Release date: 2026-06-04_

## Highlights

THE GOAT now has a concept of **who you are**. Until now, anyone who could log
in to SmartStaff got the full operator UI, and every API route assumed the
caller was an admin. 3.5.0 introduces a per-user **cohort** that gates both the
interface and the backend, so a crew member logging in with their EIN sees only
their own status, while the internal team keep full access.

Three cohorts:

- **Admin** — full access, unchanged from 3.4.x. Resolved from the shared
  SmartStaff admin login (`usergroupID == 1`).
- **Leadership** — read-only across the all-crew views (Schedule, Crew
  Utilization, Induction Checker) plus a read-only Ask THE GOAT. Crew Finder and
  Estimate Import are hidden. Resolved from a personal EIN login flagged
  `leadership`.
- **Crew** — a personal "My Status" dashboard only: own inductions, own
  utilization, own schedule (read-only), and add/remove own unavailability.

## How cohort resolves

A new `cohort` column on the SmartStaff `users` table, surfaced by a new
`whoami.php` endpoint. Resolution rule (also enforced in `cohort.php`):

- `usergroupID == 1` → always **admin** (never read from the column).
- otherwise → `users.cohort`, restricted to **leadership** or **crew**,
  defaulting to **crew**.

The column can grant Leadership but **never** Admin — a `usergroupID == 3`
account cannot escalate to full access through the column. Admin stays tied to
the admin login.

## SmartStaff (PHP)

- **New `whoami.php`** — self endpoint returning the logged-in user's identity
  and resolved cohort. Tolerates the `cohort` column being absent (degrades to
  crew) so it can deploy before the migration.
- **New `my-inductions.php`, `my-shifts.php`** — self endpoints returning the
  logged-in user's own inductions and own shifts/unavailabilities. Keyed on
  `$_SESSION userID`, no admin gate — a crew member can only ever read their own
  records.
- **New shared `cohort.php`** helper (`goat_user_cohort()`, `goat_can_read_all()`).
- **Bulk read endpoints now honour Leadership.** `list-crew-bulk.php`,
  `get-shifts-bulk.php`, `get-booked-crew-bulk.php`, and
  `get-unavailabilities-bulk.php` gate on `goat_can_read_all()` (admin OR
  leadership) instead of `usergroupID == 1`, so a Leadership EIN session can
  actually load the all-crew read views. **Write** endpoints (add/delete/resize/
  relocate calendar events, add-to-call, SMS) remain strict admin-only.
- **`get-shifts-bulk.php` emits the real per-shift `status`.** It now `LEFT JOIN`s
  `call_crew_map` and returns `(int) ccm.status` (5=confirmed, 1=pending,
  6=declined, 8=noshow, 0/none=unset) per shift. This closes the status saga —
  see "Shift status contract" below.
- **Migration**: `ALTER TABLE users ADD COLUMN cohort VARCHAR(16) NULL`, then
  seed `leadership` for the internal team's EINs.

## App (app.py)

- **Identity captured at login** from `whoami.php`, held server-side per session
  (`_ss_identity`). Cohort is the single source of truth for gating and is never
  read from the client. Fails **closed** to `crew` if `whoami` is unreachable.
- **`@require_cohort(...)` guard** on 26 routes:
  - *admin only*: calls, call details, availability, GOAT add-crew / send-SMS,
    the admin `/api/unavailability/*` manager, and all `/api/import/*`.
  - *admin + leadership*: inductions, forecast (+preload), schedule, booked-crew,
    call-status, groups, cache status/refresh/progress, and the GOAT chat/history
    routes (Leadership gets a read-only GOAT — its write actions are admin-gated
    at the endpoint).
- **New self routes** (any cohort, own data only): `/api/me/inductions`,
  `/api/me/shifts` (feeds My Utilization and My Schedule),
  `/api/me/unavailability` GET/add/delete (own session, no impersonation —
  delete is ownership-scoped by SmartStaff), and `/api/whoami` for the frontend.
- **Bug fix — `remember` no longer clobbers the admin credentials.** Only an
  admin login persists creds to `config.json` (those double as the
  `_make_admin_ss` impersonation account). Per-session reauth now uses in-memory
  `_ss_creds`, so an expired crew/leadership session re-auths as **itself** —
  fixing a latent path where a non-admin session could silently re-auth as
  admin.
- Login-time crew-cache refresh now runs only for admin/leadership (crew neither
  use nor can build it).
- Induction expiry logic extracted to `_compute_induction_status()`, shared by
  the admin Induction Checker and the crew self-view so they can't drift.
- `_coerce_status()` at the `fetch_shifts_bulk` parse boundary coerces a shift
  status to int (absorbing a `"5"`-as-string), and the fallback HTML scraper now
  tags its confirmed-only shifts `status: 5`. Belt-and-braces around the wire
  `status` so the client's `== 5` / `=== 5` checks hold regardless of source.

## UI (index.html)

- **Cohort-aware navigation**, driven by `/api/whoami` at load:
  - Crew → tab bar, Ask THE GOAT, and cache badge hidden; a single **My Status**
    dashboard (Inductions / Utilization / Schedule / Unavailability).
  - Leadership → Crew Finder and Estimate Import tabs hidden; unavailability
    editing in the Utilization manager hidden (read-only).
  - Admin → unchanged.
- Initial data loads are now cohort-scoped, so non-admins don't fire requests
  they'd be 403'd on.

## Shift status contract (regression fix)

The per-shift `status` field is consumed in four places across two languages —
the conflict filter, forecast hours, the GOAT `search_availability`, and the
timeline JS (`s.status === 5 ? red : blue`). During the cohort work it went
through three states:

1. **Missing** — the calendars-based rewrite of `get-shifts-bulk.php` dropped the
   `call_crew_map` join, so no shift carried `status`. Every shift read as
   non-confirmed: zero utilization hours, no conflicts, an all-blue timeline.
2. **Constant** — a fix re-emitted a hard-coded `status = 5` for every
   `cal.type = 2` row. But type-2 calendar rows also exist for non-confirmed
   assignments, so this flipped the failure: every shift read confirmed and the
   timeline went all-red ("for information" shifts shown as conflicts).
3. **Real** (this release) — `get-shifts-bulk.php` joins `call_crew_map` and
   emits the true `(int) ccm.status`. Confirmed (5) → red and counted; declined
   /pending/noshow → blue, no conflict, no hours.

The lesson: the PHP↔client `status` contract is load-bearing and was undocumented.
This release pins it down and guards it (below).

## Tests

- **New `smoke_endpoints.py`** — a contract smoke test. Reuses `app.py`'s real
  login + `BASE_URL` (no drift), GETs the PHP endpoints directly (raw wire
  values, so a missing `(int)` cast isn't masked by `_coerce_status`), and
  asserts: `whoami` cohort enum + identity keys; `list-crew-bulk` rows carry
  `ein`/`postcode`/`rating`(int)/`groups`/`inductions`; shifts carry an int
  `status` in `{0,1,5,6,8}`; and a **self-validating status check** —
  `get-booked-crew-bulk` (confirmed-only) must exactly match the `status==5`
  shifts in `get-shifts-bulk`, catching both the *missing* and *constant-5*
  regressions from live data with no hand-maintained fixtures. Run
  `python3 smoke_endpoints.py`; exits 0/1/2 for CI.



- The four widened bulk endpoints + `cohort.php`, `whoami.php`,
  `my-inductions.php`, `my-shifts.php` must be deployed to `/ajax/crew/`
  alongside this app build, and the `cohort` migration run. Because the internal
  team currently logs in via the shared admin account, the SmartStaff widening
  is zero-regression (admins hit the admin branch).
- `whoami` failing → everyone resolves to crew (fail-closed). Keep it deployed.

## Code changes

- `app.py`: identity/cohort capture, `require_cohort`, 26 route guards, `/api/me/*`
  + `/api/whoami`, self-scoped unavailability helpers, `_compute_induction_status`,
  per-session reauth creds, `remember` admin-only persistence, `_coerce_status`
  + fallback-scraper status tag; `APP_VERSION` → 3.5.0.
- `index.html`: cohort-aware nav, My Status dashboard, leadership read-only hiding.
- `smartstaff/`: `whoami.php`, `my-inductions.php`, `my-shifts.php`, `cohort.php`
  (new); `list-crew-bulk.php`, `get-booked-crew-bulk.php`,
  `get-unavailabilities-bulk.php` (cohort gate); `get-shifts-bulk.php` (cohort
  gate **and** real `call_crew_map.status`); `MIGRATION-cohort.sql`.
- `smoke_endpoints.py` (new): endpoint contract smoke test (status regression guard).
- `version.json`: bump to 3.5.0 at release (with the built DMG url + date).
