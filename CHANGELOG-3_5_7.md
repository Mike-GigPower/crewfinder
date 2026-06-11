# THE GOAT — v3.5.7

Bookings can now be created **inside THE GOAT** — either typed in by hand or
imported from a GigPower Estimator file — through a single direct-insert
SmartStaff endpoint, replacing the old scrape-the-form-and-POST flow. The
estimate import is rewired onto the same endpoint, booking and call names on the
Schedule and Crew Finder open the matching SmartStaff record, and the frontend's
SmartStaff links finally honour the configured `base_url` so test builds stay on
test.

## New — Create Booking tab (manual + from estimate)

The **Estimate Import** tab becomes **📋 Create Booking**, with a mode switch at
the top:

- **✎ Manual** (default) — a full form: booking name, date, invoice reference,
  notes; customer / contact / on-site contact / venue dropdowns loaded once from
  `/api/booking/lookups`; and a Calls section with add/remove rows (call-name
  dropdown with an **"Other → free text"** option, date, start time, length,
  crew required, notes). Create posts to `/api/booking/create` and shows a result
  panel — booking number, a deep link to the new booking, and per-call ✓/✗ with
  the same styling the import uses.
- **⬆ From Estimate** — the existing drop-a-JSON flow, unchanged; the switch just
  shows or hides it.

Both modes converge on the same write path (`ss_create_booking_bulk` →
`create-booking.php`), so there's one creation operation, not two.

## New — create-booking.php write path

Creating a booking used to mean scraping SmartStaff's add-booking / add-call
forms for their hidden fields and POSTing them back — brittle, slow, and easy to
break when a form changed. It's now a single endpoint.

**`create-booking.php`** (`/ajax/crew/`, **admin-only** — `goat_user_cohort()
!== 'admin'` → 403) takes a JSON body `{booking, calls}`, validates the required
fields and the customer / contact / venue foreign keys up front, inserts the
booking, then loops the calls — collecting the created `call_ids` and any
`call_errors` as `{index, detail}` so a bad call never loses the booking. It
returns `{booking_id, call_ids, call_errors}`.

The key finding that makes a direct insert safe: creating a booking and its calls
touches only the `bookings` and `calls` tables. The crew-side rows (`calendars`,
`call_crew_map`) are written only when crew are **assigned / confirmed**, which is
a separate workflow — so there are no side effects to reproduce, and a plain
`$db->insert()` is correct. The endpoint is PHP 5.x-safe throughout (no `??`, no
short-array `[]`, no `http_response_code()` — a local `send_status()` helper
instead; `to_unix()` strtotime's date strings in Melbourne time).

Field mapping mirrors what SmartStaff's own forms write, including the two
gotchas from the form analysis: a booking's `userID` is the **contact**, not the
operator, and `onsiteUserID` falls back to the contact when left blank.

## Changed — estimate import runs on the write endpoint

`run_import` now has two paths behind the `USE_CREATE_BOOKING_ENDPOINT` flag.
With it on, the import builds one `{booking, calls}` payload
(`_build_import_payload`), makes a **single** `ss_create_booking_bulk` call, and
reconstructs the per-line progress log from the result (mapping `call_errors`
indices back to lines, skipping failed ones). With it off, the legacy scrape loop
(`ss_create_booking` / `ss_create_call`, 1.2 s pacing) runs unchanged as a
fallback. Same UI, same history, far fewer round-trips.

## New — click a booking or call name to open it in SmartStaff

On both the **Schedule** and **Crew Finder** tabs, clicking a **booking name**
opens that booking (`/bookings/{id}`) and clicking a **call name** opens the
callsheet (`/bookings/{id}/callsheet/{call}`) in a new tab. Both `stopPropagation`
so the booking name no longer toggles the Schedule row's collapse, and the call
name no longer triggers a Crew Finder jump or call-selection — those still work
from the rest of the row. Admin-gated, matching the existing per-call actions.

## Fixed — frontend SmartStaff links now honour base_url

The app's SmartStaff links were all hard-coded to production, so a build pointed
at `test.smartstaffsolutions.com` still sent the operator to **prod** for "open
in SmartStaff", Add / Confirm / Send-SMS, and the import's "View Booking" link.
`/api/whoami` now returns `ss_base`; the frontend stashes it as `window._ssBase`
and routes every SmartStaff URL through one `ssUrl(path)` helper (nine call
sites). Defaults to prod until whoami resolves, so first paint is unaffected.

## Removed — per-call SMS button on the Schedule

The Schedule's per-call **📱 SMS** button only opened the callsheet — identical
to the **↗ SmartStaff** link beside it, despite the label implying it sent a
message. It and its `schedOpenSMS` handler are gone. (The real SMS action — assign
selected crew and fire SmartStaff's confirm-request SMS — still lives in Crew
Finder's **✉ Send SMS**.)

## Code changes

- **`app.py`** — `USE_CREATE_BOOKING_ENDPOINT` flag + config override;
  `ss_create_booking_bulk`; `_build_import_payload`; `run_import` branched onto
  the endpoint with the scrape loop kept as fallback. New routes
  `GET /api/booking/lookups` and `POST /api/booking/create` (both admin, with
  scrape fallback / friendly pre-checks). `/api/whoami` now returns `ss_base`.
  `APP_VERSION` → 3.5.7.
- **`templates/index.html`** — Create Booking tab + Manual mode (form, lookups,
  dynamic call rows, result panel); From-Estimate mode toggle. Clickable
  booking/call names (`openSmartStaffBooking` / `openSmartStaffCall`). `ssUrl`
  helper + `window._ssBase` from whoami; all hard-coded prod URLs routed through
  it. Per-call Schedule SMS button + `schedOpenSMS` removed.

## Files

- `app.py` (above).
- `templates/index.html` (above).
- `create-booking.php` — already on test **and** production from the write-path
  work; **no new endpoint to deploy this release**.

## Deployment

No PHP to deploy — `create-booking.php` is already live on both. So:

commit `app.py` + `index.html` + changelog → build DMG → smoke-test (Create
Booking → Manual against test: dropdowns populate, create a booking with calls
incl. an "Other", verify in SmartStaff, delete; regression-check From Estimate)
→ publish GitHub release (asset `TheGOAT.dmg`, confirm HTTP 200) → flip
`version.json` to 3.5.7 **last**.
