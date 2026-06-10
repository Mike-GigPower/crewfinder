# THE GOAT — v3.5.3

_Release date: 2026-06-10_

Two feature sets in one release: **Crew Finder UI improvements** and the
**Operations cohort**. Unlike a front-end-only release, this one includes
SmartStaff PHP changes (`cohort.php`, `whoami.php`) and a column seed, so follow
the deploy order at the bottom — PHP first, then the binary, then the seed.

---

## Crew Finder UI

### Added — collapsible ALREADY BOOKED panel
The "⚡ ALREADY BOOKED" panel (confirmed / unconfirmed / waiting / declined chips)
collapses by clicking its header, leaving just the title line and total count; the
results table/timeline below grows to fill the reclaimed space. A chevron shows
state; collapse is session-scoped (not persisted across restarts).

### Added — distance column in the timeline view
The timeline view now shows the same **Dist** column the table view has had since
3.4.7, populated only when "Filter by distance" is on, sitting between Availability
and Phone, with the "Within N km of <origin>" caption above the grid. Crew with no
usable postcode show an em-dash. The Timeline column index is now derived
dynamically, so adding Dist doesn't disturb the 3.5.2 single-scroller — Safari
horizontal scrolling is unaffected.

### Added — click-to-sort column headers
Click a header (Name, Availability, Dist when the filter is on, Rating) to sort in
either view. Each header cycles ascending → descending → off; the active column
shows the accent colour and a ▲/▼. Sorting is within each section (Available /
Partial / Conflicts / Skipped) and applied once in `renderResults`, so table and
timeline stay in sync and it composes on top of an active Ask-the-GOAT filter. The
ordering logic is now a single `sortCrewArray()` shared by the headers and the
Ask-the-GOAT NL sort. (Availability sort keys off `avail_count`, so it's only
meaningful on multi-call searches.)

---

## Operations cohort

Adds a fourth cohort value, **`operations`**, to the v3.5.0 role model
(`admin` / `leadership` / `crew`). In THE GOAT, `operations` has **identical access
to `leadership`** — read-only across all-crew views, no Crew Finder / Estimate
Import / writes. It is a distinct persisted value (not a leadership alias) because
the `cohort` field is shared with the Gig Power website, where Operations will have
its own privileges. Cohort is an identity label; each system maps it to its own
capabilities.

### SmartStaff (PHP)
- **`cohort.php`** — allow-list extended to `{leadership, operations}`;
  `goat_can_read_all()` is now `admin || leadership || operations`. This single
  helper gates every bulk read endpoint, so they honour operations automatically.
  Resolution still lives only here (`goat_user_cohort()`): `usergroupID == 1` →
  `admin` (never from the column); otherwise `users.cohort` ∈
  `{leadership, operations, crew}`, default `crew`.
- **`whoami.php`** — unified onto `goat_user_cohort()`: it now `include`s
  `cohort.php` instead of duplicating the resolution/allow-list inline, and no
  longer reads the `cohort` column in its own SELECT. This matters because the Gig
  Power website's verify-then-mint flow reads `whoami.php` — a second drifting copy
  of the allow-list would have been a cross-repo bug.

### App (`app.py`)
- Single-source cohort constants: `LEADERSHIP_COHORTS = ("leadership","operations")`,
  `READ_ALL_COHORTS = ("admin",)+LEADERSHIP_COHORTS`,
  `KNOWN_COHORTS = READ_ALL_COHORTS+("crew",)`.
- Consumers: `fetch_whoami` validation (`KNOWN_COHORTS`), login roster-cache gate
  (`READ_ALL_COHORTS`), the `/api/schedule` non-admin fallback (`LEADERSHIP_COHORTS`
  — operations reads calls from the bulk endpoint, not the admin pages), and all 16
  read-route decorators (`@require_cohort(*READ_ALL_COHORTS)`). The 11 admin/write
  routes stay strict, and creds are never persisted for non-admin cohorts.

### UI (`index.html`)
- `window.isReadOnlyCohort(c)` + a `cohort-readonly` body class are the single
  source for "leadership-equivalent." Four sites key off it (read-only CSS on the
  Schedule and admin manager, the nav block hiding Crew Finder + Estimate Import,
  and the unavailability delete button).

### Migration
No schema change — `cohort` is already `VARCHAR(16)`. Seed per user (lowercase):
`UPDATE users SET cohort = 'operations' WHERE …` (see `smartstaff/MIGRATION-cohort.sql`).
Granting full admin is still via `usergroupID = 1`, not the column (a column value
of `admin` resolves to `crew`); NULL any stale `cohort = 'admin'` on promoted rows.
Re-login required — identity is captured at login, held per session.

---

## Files
- `templates/index.html` — Crew Finder UI (collapsible panel, timeline Dist column,
  click-to-sort headers) + operations `isReadOnlyCohort` helper.
- `app.py` — operations cohort constants/decorators + `APP_VERSION` → 3.5.3.
- `smartstaff/cohort.php`, `smartstaff/whoami.php` — operations allow-list + whoami
  unification.
- `smartstaff/MIGRATION-cohort.sql` — operations seed reference.

## Deploy order
1. **PHP resolver** (`cohort.php`, `whoami.php`) to `/ajax/crew/` on production,
   verified on `test.smartstaffsolutions.com` first — it feeds both THE GOAT and
   the website.
2. **THE GOAT binary** (app.py + index.html are in the binary → DMG rebuild):
   commit + push source → build DMG → publish GitHub release → flip `version.json`
   last.
3. **Seed** the `operations` column values.

## Still outstanding
- `smoke_endpoints.py` — add `operations` to the whoami cohort-enum assertion.
- Website privilege mapping for `operations` (separate repo; not blocking this
  release, but the session mint should fail-closed on an unknown cohort).
