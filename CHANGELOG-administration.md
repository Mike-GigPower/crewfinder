# THE GOAT — Administration tab

_Status: in progress. This changelog spans the multi-commit Administration feature._

## Commit 1 — the Administration tab (UI only, no backend change)

Replaces the **⚙ Admin Access** nav button with a dedicated **⚙ Administration**
tab, admin-only and gated exactly like Crew Finder and Create Booking. The tab is
a hub for maintaining GOAT / SmartStaff records and access.

### What's wired now (reuses existing, working flows)
- **Admin Access** card → `openElevatorsModal()` (the `goat_elevators` step-up
  list — unchanged). The old nav button is removed; this is its new home.
- **Add Customer** card → `openAddEntity('customer')`.
- **Add Venue** card → `openAddEntity('venue')`.

### Previewed (cards shown with a "Soon" badge; land in later commits)
- **Add User** — new crew member. Needs the `add-crew.php` AJAX branch, a
  lookups source (paygrades / tax scales / crew groups / next EIN), an `/api/`
  proxy, and a form. (Commit 2.)
- **Add Contact** — `openAddEntity('contact')` can't be reused as-is: it reads the
  booking form's `mb-customer` select to link the contact, so the tab needs its
  own customer picker. (Commit 3.)
- **Manage Cohorts** — assign Operations / Leadership / Crew. Needs a new
  admin-gated `manage-cohort.php` (guarded `UPDATE users SET cohort = ?`, never
  `admin` via the column) + `/api/cohort` proxy + UI. (Phase 2.)

### Code changes (templates/index.html only)
1. Hub CSS added to the main stylesheet (`.admin-hub`, `.admin-card`, …).
2. New `tab-btn-administration` button in the tab bar (after Create Booking).
3. Removed the redundant `btn-manage-access` nav button…
4. …and its visibility logic in `applyCohort`.
5. `hide('tab-btn-administration')` added to the read-only-cohort branch, so
   leadership/operations don't see it (crew already get no tab bar; admin returns
   early and keeps it). Net: admin-only.
6. New `#tab-administration` panel (the hub markup), inserted after My Status.
7. New `adminComingSoon()` / `adminAddUser()` / `adminAddContact()` /
   `adminManageCohorts()` placeholder handlers.

No `app.py` change, no PHP change, no new routes. `APP_VERSION` not bumped yet —
flip it when the feature ships as a release.

### Verify locally
Quit the packaged dock app (it holds port 5001), run Flask from source, then
hard-refresh (Cmd-Shift-R — `debug=False` caches templates). Log in as admin →
the ⚙ Administration tab appears; Admin Access / Add Customer / Add Venue work;
the three "Soon" cards show the inline note. Log in as a non-admin EIN → no tab.

### Known cosmetic note
Adding a customer/venue from the tab also pre-populates the hidden Create Booking
dropdowns (a harmless side effect of reusing `submitAddEntity`). Commit 3 can give
these their own standalone path if you'd rather fully decouple them.

---

## Commit 2 — Add User (crew), text fields

Wires the **Add User** card to a real form that creates a new crew member
(`usergroupID = 3`) in SmartStaff, through THE GOAT's server-side admin session —
no browser popup, same pattern as the customer/venue quick-adds.

### Decisions baked in
- **Username = the auto-assigned EIN**; **temp password = `12345`** (server-set, so
  the record is portal-ready and the crew member changes it on first login —
  `add-crew.php` sets `new_employee = TRUE`, which can drive that prompt later).
  Setting username = EIN is guaranteed unique (the new EIN is higher than every
  existing one) and sidesteps `add-crew.php`'s blank-username uniqueness trap.
- **EIN is fetched server-side at submit**, not trusted from the client, so two
  operators adding at once can't collide on a stale prefill.
- Profile picture is **deferred to Commit 2b** (it needs a multipart upload path).

### Form fields
First/Last name (required), Mobile, Email, Date of birth, Street address, Suburb,
State, Postcode, Emergency contact name/phone, Crew groups (checkboxes), Notes.
`usergroupID`, `active`, `rating`, `start_date`, `new_employee` are set by the add
path; the temp password is shown after creation so you can pass it on.

### New / changed files
- **`smartstaff/crew-lookups.php`** _(new, admin-gated)_ — returns
  `{crew_groups:[{id,name}], next_ein}` for the form. Modelled on
  `import-lookups-bulk.php` (raw `mysql_*`, PHP 5.x-safe, 403 for non-admins).
- **`crew/add` (`add-crew.php`)** — adds the AJAX branch the other add pages
  already have: on success returns the bare new user id; on a validation error
  (e.g. username/EIN clash) returns `ERROR: …` with HTTP 409, instead of
  re-rendering the page. **Backward-compatible** — only fires when `ajax` is
  posted, so SmartStaff's own crew form is unchanged.
- **`app.py`** — `NEW_CREW_TEMP_PASSWORD` constant; `ss_create_crew()` and
  `ss_crew_lookups()` helpers; routes `GET /api/admin/crew-lookups` and
  `POST /api/admin/add-user` (both `@require_cohort("admin")`). The temp password
  is surfaced via the API so it isn't duplicated into the (public) `index.html`.
- **`templates/index.html`** — `adminAddUser()` / `submitAddUser()` /
  `auGroupsHtml()` replace the placeholder; the Add User card loses its "Soon"
  badge.

### Deploy order (your standard PHP-before-binary discipline)
1. `crew-lookups.php` → test `/ajax/crew/`, confirm 403 unauthenticated and JSON
   as admin; then prod.
2. patched `add-crew.php` → test, confirm an `ajax=1` add returns a bare id and the
   normal form still redirects; then prod.
3. `app.py` + `index.html` → run from source to test locally (quit the dock app
   first; hard-refresh). Bump `APP_VERSION` / rebuild the DMG only when you cut the
   release.

### Notes / small risks
- A shared `12345` lets anyone who knows a new crew member's EIN log in as *that one
  person* (own self-view only) until they change it. Fine for an internal tool;
  if you'd rather it not sit in (public) source, read it from `config.json` instead
  — one-line change, noted in `app.py`.
- `add-crew.php` inserts `mobile`/`phone` without escaping (pre-existing SmartStaff
  behaviour). Operator-typed phone numbers won't contain quotes, so this is
  cosmetic, but worth knowing.

---

## Commit 2b — Profile picture for Add Crew Member

Completes the Add Crew Member feature: an optional **Profile picture** on the form.
The image rides along to SmartStaff's `crew/add`, whose existing phpThumb step
crops it to `crewimg_<id>.jpg` (125×138).

### Why no PHP change
The AJAX id-return added to `add-crew.php` back in Commit 2 sits *after* the
phpThumb block, so the photo is already processed before the id comes back. 2b is
purely `app.py` + `index.html`.

### Changes
- **`app.py`**
  - `ss_create_crew(..., photo=None)` — when a photo is supplied it's forwarded as
    a genuine multipart file part (so PHP's `is_uploaded_file()` passes and the
    thumbnail is generated). With no photo, `files=None` and requests falls back to
    urlencoded — the no-photo path is byte-for-byte the old behaviour.
  - `POST /api/admin/add-user` now reads `request.form` + `request.files` instead
    of JSON. The picture is validated server-side: must be `image/*` and under
    10 MB, else a clear 400.
- **`templates/index.html`**
  - A **Profile picture** file input (`accept="image/*"`, optional) on the form.
  - `submitAddUser()` now sends a `FormData` (multipart) rather than JSON, so the
    file is included. No `Content-Type` header is set — the browser supplies the
    multipart boundary.

### Deploy
`app.py` + `index.html` only — **no PHP redeploy**. Run from source (venv active),
restart, hard-refresh. Test: add a crew member with a photo, then confirm
`crewimg_<newid>.jpg` exists on the server / shows on their SmartStaff profile; and
add one *without* a photo to confirm that path still works.

---

## Commit 3 — Add Contact (with its own customer picker)

Wires the **Add Contact** card to a real form. The backend already existed
(`/api/booking/contact` → `ss_create_contact`); the only blocker was that the
booking-form path read the booking dropdown for the customer link. This adds a
standalone form with its own customer picker. **Frontend-only — no `app.py` or PHP
change.**

### Changes (templates/index.html)
- `adminAddContact()` / `submitAddContactAdmin()` replace the placeholder. The form
  loads customers via `ensureBookingLookups()`, offers a **Customer** dropdown
  (required) plus First/Last name, Username (required, with the same
  firstname.lastname auto-suggest the booking contact form uses), Mobile, Email,
  and posts to `/api/booking/contact`.
- The Add Contact card loses its "Soon" badge (only Manage Cohorts remains
  previewed).
- Admin **Add Customer / Add Venue** now also refresh the *cached* lookup list on
  success (so a customer you just added shows up in the Add Contact picker in the
  same session) — without touching the hidden booking dropdowns.

### Deploy
`index.html` only. Swap it in, restart, hard-refresh. Test: Add Contact → pick a
customer → create → confirm the contact appears under that customer in SmartStaff;
a duplicate username should surface the "username may already be in use" error.

---

## Phase 2 — Manage Cohorts

Wires the last card. A **Manage Cohorts** modal to assign crew to
Operations / Leadership / Crew and see who's currently in a non-default cohort.
This completes the Administration hub — no more "Soon" cards.

### Design
- **Assign** row: search crew by name (datalist from the existing
  `/api/crew-roster`, which carries the EIN) → choose a cohort → Assign.
- **Current** list: everyone currently in Operations or Leadership, each with a
  dropdown to change them. Setting someone to **Crew** drops them back to
  personal-view-only (removes them from the list).
- **Admin is not assignable here** — that's a `usergroupID = 1` promotion. The
  modal says so, and the endpoint rejects `cohort=admin`.
- The modal notes that changes take effect on the person's **next login** (cohort
  is captured at login) and that cohort is **shared with the crew portal**.

### New / changed files
- **`smartstaff/manage-cohort.php`** _(new, admin-gated)_ — `list` (crew in
  operations/leadership, with names) and `set` (whitelisted to
  {operations, leadership, crew}; scoped to `usergroupID = 3`; success gated on
  `mysql_error()` not affected_rows so a no-op re-save isn't a false failure).
  Modelled on `manage-elevators.php`.
- **`app.py`** — `GET/POST /api/cohort` proxy (`@require_cohort("admin")`),
  beside `/api/elevators`.
- **`templates/index.html`** — `openCohortModal()` + assign/apply handlers replace
  the placeholder; the Manage Cohorts card loses its "Soon" badge.

### Interactions worth knowing
- Changing a cohort does **not** touch Admin Access (the elevate allow-list). If
  someone on that list is set to Crew, they keep the step-up button but their base
  view becomes Crew — manage step-up access separately under Admin Access.

### Deploy (PHP before binary)
1. `manage-cohort.php` → test `/ajax/crew/`, confirm 403 unauthenticated and JSON
   as admin; then prod.
2. `app.py` + `index.html` from source locally; ship with the next DMG.

---

## Feature complete

All six Administration actions are now live: Add Crew Member (+ photo), Add
Customer, Add Venue, Add Contact, Manage Cohorts, and Admin Access. To release:
deploy the two outstanding PHP files (`crew-lookups.php` if not already, and
`manage-cohort.php`) to prod, bump `APP_VERSION` + `version.json`, commit/push,
build the DMG, and flip `version.json` last per the standard sequence.

---

## Follow-up — Operations cohort grants step-up; Admin Access section removed

Simplifies the step-up model. The 🔑 Admin button (and the `/api/elevate` guard)
now key on **cohort == operations** instead of a separate `goat_elevators`
allow-list. Because elevation still requires authenticating a real
`usergroupID = 1` account, showing the button to all Operations users grants
nothing on its own — the real gate is unchanged. Net effect: **Manage Cohorts is
now the single place to grant step-up** (set someone to Operations → they get the
button), and the redundant Admin Access list is gone.

### Changes
- **`smartstaff/whoami.php`** — `can_elevate` is now `($cohort === 'operations')`;
  the `goat_elevators` lookup is removed. This is the single source — `app.py`
  captures it at login, so the `/api/whoami` response and the `/api/elevate`
  server-side guard both pick it up with no app.py change.
- **`templates/index.html`** — removed the Admin Access card, its modal, and its
  JS (`openElevatorsModal` / `loadElevators` / `addElevator` / `removeElevator`).
  The step-up flow itself (🔑 Admin button, credential modal, Exit Admin) is
  untouched, and the button-visibility logic already keyed on `can_elevate`.

### Now vestigial (left in place, safe to remove later)
`/api/elevators` (app.py), `smartstaff/manage-elevators.php`, and the
`goat_elevators` table are no longer used by anything. Harmless to leave; can be
decommissioned in a later cleanup.

### Cross-repo note
`whoami.php` is also read by the Gig Power website's verify-then-mint flow, but
that reads `cohort` (and identity), not `can_elevate` — so this change has no
website impact. Still deploy test → prod as usual.

### Deploy
1. `whoami.php` → test `/ajax/crew/`, confirm an Operations EIN gets
   `"can_elevate": true` and a plain crew EIN gets `false`; then prod.
2. `index.html` from source / next DMG.

### Verify
An Operations user sees 🔑 Admin and can step up by entering a real admin login;
a plain Crew user does not. The Administration tab no longer shows an Admin Access
card.
