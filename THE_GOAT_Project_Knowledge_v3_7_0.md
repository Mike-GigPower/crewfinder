# THE GOAT — Project Knowledge v3.7.0

_Rollup at the close of the Administration feature. Captures current state and
what's new since v3.6.3; prior history lives in
`THE_GOAT_Project_Knowledge_v3_6_3.md` and the per-release changelogs._

## Current Version: 3.7.0

### Key constants (`app.py`)
```python
APP_VERSION = "3.7.0"
VERSION_URL = "https://raw.githubusercontent.com/Mike-GigPower/crewfinder/main/version.json"
NEW_CREW_TEMP_PASSWORD = "12345"   # temp pw for new crew; changed on first login
BASE_URL    = "https://smartstaffsolutions.com"   # override via config.json "base_url"
```
`APP_VERSION` must equal `version.json`'s `version` or the app nags forever. The
login template reads `APP_VERSION` (server-injected), so the stamp can't drift.

---

## The Administration tab (3.7.0)

A new **admin-only** tab (`tab-btn-administration` / `#tab-administration`), gated
exactly like Crew Finder and Create Booking: admin returns early in `applyCohort`
and keeps it; the read-only branch hides it for leadership/operations; crew get no
tab bar. It's a hub of cards over two sections (Records / Access), all rendered in
the shared entity-modal (`openEntityModal` + `efRow`/`efInput`/`efSelect`/`entityBtn`).

### Records
- **Add Crew Member** — full create of a `usergroupID 3` user. Username = the
  auto-assigned EIN, temp password `NEW_CREW_TEMP_PASSWORD`. Optional profile
  picture (multipart → phpThumb → `crewimg_<id>.jpg`). Fields: first/last, mobile,
  DOB, address/suburb/state/postcode, email, emergency name/phone, crew groups, notes.
- **Add Customer / Add Venue** — reuse the v3.6.1 quick-adds (`openAddEntity`), now
  context-aware: launched from the tab they confirm-and-stop instead of feeding the
  booking form, and keep the cached lookup list fresh.
- **Add Contact** — standalone form with its own customer picker (the booking path
  read the booking dropdown); posts to the existing `/api/booking/contact`.

### Access
- **Manage Cohorts** — assign Operations / Leadership / Crew. Name-search picker
  (datalist from `/api/crew-roster`) to assign; a live list of current
  Operations/Leadership members with per-row change. Admin is **not** assignable
  (that's `usergroupID = 1`). Changes apply on next login; cohort is shared with the
  crew portal.

### New / changed endpoints
- **`smartstaff/crew-lookups.php`** _(new, admin-gated)_ — `{crew_groups:[{id,name}],
  next_ein}` (`crew_groups.id` / `group_name`; `MAX(ein)+1`).
- **`smartstaff/manage-cohort.php`** _(new, admin-gated)_ — `list` (crew in
  operations/leadership) / `set` (whitelist {operations,leadership,crew}, scoped to
  `usergroupID = 3`, success gated on `mysql_error()` not affected_rows).
- **`crew/add` (`add-crew.php`)** — AJAX branch: success → bare id, validation error
  → `ERROR: …` (409). Only fires when `ajax` is posted (backward-compatible). Placed
  after the phpThumb block, so the photo is processed before the id returns.
- **`app.py`** — `ss_create_crew(...,photo=None)` (forwards a multipart file when
  present, else urlencoded); `ss_crew_lookups`; `POST /api/admin/add-user`
  (multipart: `request.form` + `request.files`, image-only ≤10 MB, EIN fetched
  server-side at submit); `GET /api/admin/crew-lookups`; `GET/POST /api/cohort`
  (proxy to manage-cohort.php). All `@require_cohort("admin")`.

---

## Step-up model (updated 3.7.0)

The 🔑 Admin step-up lets a bookable Operations user authenticate a separate
`usergroupID = 1` admin account on demand, then drop back with ⤓ Exit Admin
(unchanged from 3.6.2). **What changed:** who sees the button.

- **`whoami.php` `can_elevate` is now `($cohort === 'operations')`** — was "EIN in
  `goat_elevators`". Operations are the trusted ops staff, so the cohort *is* the
  grant; no separate allow-list.
- The button is **visibility only** — elevation still completes only when the
  freshly-authenticated account resolves to `usergroupID == 1`. So widening
  visibility to all Operations grants nothing on its own.
- **Manage Cohorts is the single grant point**: set someone to Operations → they get
  the button (after re-login).
- **Retired / vestigial:** the Admin Access panel + modal, `openElevatorsModal` &
  friends (removed from `index.html`); `/api/elevators`, `manage-elevators.php`, and
  the `goat_elevators` table remain in place but unused — safe to decommission later.
- `whoami.php` is shared with the Gig Power website's verify-then-mint flow, which
  reads `cohort` (not `can_elevate`), so this change has no website impact.

The cohort model itself is unchanged (admin = `usergroupID 1`, never from the
column; leadership/operations are read-all in THE GOAT; crew = self-view). See
`THE_GOAT_Project_Knowledge_v3_6_3.md` for the full model.

---

## Conventions reaffirmed this cycle
- **Release discipline:** PHP to test→prod **before** the app build; source pushed
  to `main` before building; DMG asset confirmed HTTP 200 via `get_release_by_tag`;
  **`version.json` flipped last**; `build_dmg.sh` strips the test `base_url` override.
- **PHP (SmartStaff):** PHP 5.x — `mysql_*`, no `??`, no short `[]`; admin-gated GOAT
  endpoints `include('../../global.php'); include('cohort.php');` then
  `goat_user_cohort() !== 'admin'` → 403; gate update success on `mysql_error()`.
- **Verification:** `python3 -m py_compile app.py`; `node --check` on extracted
  `<script>` blocks; PHP brace-balance + forbidden-syntax check.
- **Front-end (vanilla JS):** Administration flows reuse the entity-modal helpers;
  multipart sent as `FormData` with **no** manual `Content-Type` (browser sets the
  boundary).

---

## Version History (3.6.x → 3.7.0)
| Ver | Summary |
|---|---|
| 3.6.3 | Branding refresh (see prior rollup) |
| 3.6.4 | Crew-status editing from the call dialog; Crew Finder server-backed Add/SMS; apostrophe fix |
| 3.6.5 | SMS `booking_id` bug fix |
| 3.7.0 | **Administration tab** (Add Crew Member +photo, Customer, Venue, Contact, Manage Cohorts); **Operations cohort grants step-up**; Admin Access allow-list retired |

---

## On the Horizon
- Decommission the vestigial elevators backend (`/api/elevators`,
  `manage-elevators.php`, `goat_elevators` table) in a cleanup pass.
- Consider whether the lone **Access** section (just Manage Cohorts) should merge
  into Records, now that Admin Access is gone.
- Carried over: `get-calls-bulk.php` dashboard scrape replacement;
  `checkForUpdates()` polling for running sessions; ASK THE GOAT induction-alerts
  integrity bug; Gig Power crew-portal auth (verify-then-mint) and the
  ops-approval workflow for induction uploads.
