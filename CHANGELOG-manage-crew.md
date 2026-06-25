# THE GOAT — Manage Crew (admin edit of existing crew records)

**Scope:** THE GOAT (`Mike-GigPower/crewfinder`). New SmartStaff endpoints
`smartstaff/get-crew.php` and `smartstaff/update-crew.php`; new `app.py` admin
routes; `templates/index.html` Manage Crew card + edit modal. Builds on the
Administration tab. Admin-only throughout.

**Deploy state:** `get-crew.php` and `update-crew.php` are already deployed to
test **and** production. `app.py` + `index.html` reach Joe / Rich / Monty on the
next DMG build.

---

## What shipped (admin-facing)

- **Manage Crew** (the old "Crew List" card, renamed) opens a searchable list of
  crew; clicking a row opens an edit form for that person.
- **Editable fields:** first/last name, mobile, phone, email, street address,
  suburb, state, postcode, date of birth, rating, active flag — plus a
  **password reset** and **group membership**.
- **Date of birth now shows and saves the correct day.** DOB is stored as a Unix
  timestamp (Melbourne midnight); reading it back in UTC was rendering the day
  before. It now round-trips in `Australia/Melbourne` so what you see is what
  you save.
- **Names with accents/apostrophes edit cleanly** (e.g. *Gráinne*) — no mangling
  on save or reload.
- **Group membership is edited inline** via a checkbox grid of all crew groups,
  reconciled in one save.

---

## How it works

- **`get-crew.php` (new, admin-gated).** `include('cohort.php')` then refuses
  anything where `goat_user_cohort() !== 'admin'` (403). Selects the editable
  columns for one `id`, plus `all_groups` (`SELECT id, group_name FROM
  crew_groups`) and the member's `group_ids` (`crew_groups_map`). DOB is
  converted from the stored timestamp with
  `new DateTime('@'.$ts)` → `setTimezone('Australia/Melbourne')` → `Y-m-d`, and
  names are `html_entity_decode(…, ENT_QUOTES)` on the way out.
- **`update-crew.php` (new, admin-gated).** POST-only (405 otherwise), and it
  first confirms the target is a **crew record (`usergroupID = 3`)** — it refuses
  to edit staff/admin rows (403). It writes only the whitelisted text fields
  that are actually present (`firstname, lastname, mobile, phone, address,
  suburb, state, …`), so a partial form does a partial update. DOB is parsed
  `Y-m-d` → `DateTime(… , 'Australia/Melbourne')` → timestamp (blank → `dob = 0`).
  Password, only when non-blank, is rewritten as `sha1($pw . $salt)` with a
  freshly generated salt. Group membership is a full-replace of
  `crew_groups_map`. Success is gated on **`mysql_error()`**, not
  `affected_rows` (a no-op save legitimately affects 0 rows), and the `UPDATE` is
  guarded so a groups-only change still saves.
- **`app.py` proxy routes (admin-gated):** `/api/admin/crew-list`,
  `GET /api/admin/crew/<crew_id>` and `POST /api/admin/crew/<crew_id>`, the last
  with an `allowed` field whitelist mirroring the PHP (including `groups`).
- **`index.html`:** the Manage Crew card → modal (searchable list, click-to-edit
  rows) and the edit form (First/Last name fields, `type="date"` DOB, a Groups
  checkbox grid, password-reset field). SmartStaff branding removed from the
  crew-admin copy only.

---

## Files

- `smartstaff/get-crew.php` — **new.** Admin-gated single-crew read (+ groups).
- `smartstaff/update-crew.php` — **new.** Admin-gated single-crew write
  (fields, DOB, password, groups).
- `app.py` — three `/api/admin/crew*` routes.
- `templates/index.html` — Manage Crew card, modal, edit form.

---

## Key decisions / learnings

- **DOB is a Unix timestamp at Melbourne midnight.** Both read and write must
  pin `Australia/Melbourne` or the day drifts by one across the UTC boundary.
- **Crew-only guard.** `update-crew.php` checks `usergroupID = 3` so the tool can
  never quietly edit a staff/admin account.
- **Gate writes on `mysql_error()`, not `affected_rows`** — a save that changes
  nothing affects 0 rows but is not a failure; and the `UPDATE` is skipped
  entirely when only group membership changed.
- **Partial updates by construction** — only POSTed fields are written, so the
  same endpoint serves "change one field" and "change everything."
