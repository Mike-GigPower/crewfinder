# THE GOAT — v3.5.9

The booking and call dialogs from 3.5.8 were read-only with a disabled **Edit**
button. This release turns that button on: **admin users can edit a booking's or
a call's details in-place**, prefilled from the same data the view shows and saved
through two new write endpoints. No more bouncing out to SmartStaff to change a
call time or fix a reference — and a call-time edit re-syncs the calendars of any
crew already booked on it, exactly as a SmartStaff edit does.

## New — edit mode in the dialogs (admin only)

- **Edit a booking** (✎ Edit in the booking dialog) — name, date, customer,
  contact, on-site contact, venue (all four as searchable dropdowns from the same
  lists the manual Booking form uses), invoice reference, and notes.
- **Edit a call** (✎ Edit in the call dialog) — name, date, time, length,
  required, and notes. Time is a **24-hour** field (`HH:MM`) so it reads the same
  on every machine regardless of the OS clock setting.

Each form prefills from the booking/call you're looking at, validates the required
fields, disables Save while it's writing, and surfaces any endpoint error inline.
Save re-fetches and re-renders the view so you see the committed result; Cancel
drops back to the view unchanged.

## New — update-booking.php / update-call.php (write endpoints)

Both live at `/ajax/crew/`, are **admin-only** (same gate as `create-booking.php`),
and reuse its boilerplate (`P()` / `to_unix()` / `send_status()`, JSON envelope,
FK validation up front).

- **`update-booking.php`** — a plain `UPDATE bookings` of the detail columns
  (name, creation_date, customerID, userID = contact, onsiteUserID, venueID,
  notes, reference). It **never writes `status`**, so the close/invoice/lock
  cascade that `add-booking.php` runs when a booking is set to Closed can't be
  triggered from an edit. Closing a booking stays a SmartStaff action.
- **`update-call.php`** — `UPDATE calls` of the editable subset (call_name,
  start_date, start_time, est_length, required, notes), then loops
  `$sss->addToCalendar($callID, $userID)` over every assigned crew member —
  precisely what `add-call.php`'s edit path does — so booked crew don't keep stale
  calendar times after a time change. It **never writes `call_locked`**, so the
  accounting cascade can't fire either. The response reports `crew_synced`.

## How it works

`POST /api/booking/<id>` and `POST /api/call/<bid>/<cid>` proxy the two endpoints
via `ss_update_booking_bulk` / `ss_update_call_bulk`. The POST routes share their
paths with the existing GET view routes — Flask dispatches by method, and the
static `/api/booking/create` rule still wins over the dynamic `<id>` one, so
there's no collision.

One correctness note for edits: a MySQL `UPDATE` reports **changed** rows, not
matched ones, so `affected_rows` is `0` when you save without altering anything.
The endpoints therefore confirm the row exists up front and gate success on
`mysql_error()` rather than on `affected_rows`, so a no-op save isn't mistaken for
a failure.

`get-booking.php` gained a per-call `notes` field (additive) so the call-edit form
can prefill notes from the same source the dialog already loads.

## Code changes

- **`update-booking.php`** — new admin-only write endpoint (above); detail-only
  update, never sets `status`.
- **`update-call.php`** — new admin-only write endpoint (above); update + crew
  calendar re-sync, never sets `call_locked`.
- **`get-booking.php`** — added `notes` to the per-call SELECT + output.
- **`app.py`** — `ss_update_booking_bulk` / `ss_update_call_bulk` wrappers (POST
  with `?id=`); `POST /api/booking/<id>` and `POST /api/call/<bid>/<cid>` routes,
  both `@require_cohort("admin")`. `APP_VERSION` → 3.5.9.
- **`templates/index.html`** — edit-mode forms and save handlers
  (`openBookingEditForm` / `saveBookingEdit`, `openCallEditForm` /
  `saveCallEdit`), prefilled from the view data; the dialogs' Edit buttons enabled
  for the in-GOAT data; `entityBtn` gained an optional `id`; 24-hour call-time
  field with validation.

## Files

- `update-booking.php` (new SmartStaff endpoint, `/ajax/crew/`).
- `update-call.php` (new SmartStaff endpoint, `/ajax/crew/`).
- `get-booking.php` (re-deploy — per-call `notes` added).
- `app.py` (above).
- `templates/index.html` (above).

## Deployment

All three PHP files must be on **production** before the app release: the two new
write endpoints, plus the updated `get-booking.php`.

Sequence: deploy `update-booking.php`, `update-call.php`, and the updated
`get-booking.php` to test → prod → commit `app.py` + `index.html` + the three
endpoints + changelog → build DMG → smoke-test (open a booking, edit a field,
save; open a call, change the time, save, and confirm booked crew calendars
re-sync) → publish GitHub release (asset `TheGOAT.dmg`, confirm HTTP 200) → flip
`version.json` to 3.5.9 last.
