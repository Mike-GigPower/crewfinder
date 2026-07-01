# THE GOAT — v3.16.1

Adds the ability to **add a call to an existing booking** from the booking view
dialog. Source-only release — **two files** (`app.py`, `templates/index.html`),
**no PHP endpoint**, nothing to deploy to SmartStaff.

## New — Add call (booking view dialog)

A dashed **+ Add call** control now sits under the Calls list in the booking
dialog (shown once the full booking has loaded). It opens a short form:

- **Name** — the same dropdown as the manual Booking form (`MB_CALL_NAMES`:
  Load In, Load Out, LX, SX, VX, … Crew Boss, Other). Choosing **Other** reveals
  a free-text name field.
- **Date** (native date picker), **Time** (optional, validated **24-hour**
  `HH:MM` text field — never a native time input, so it reads the same on every
  machine), **Length (h)**, **Required**, **Notes**.

On success the dialog re-fetches (`openBookingDialog`) so the new call appears in
the list immediately; the cached booking index entry is dropped so the stale
call list is rebuilt.

## How it works — reuses SmartStaff's own callsheet form

The new call is created through **`ss_create_call`**, which POSTs to SmartStaff's
own `/bookings/{id}/callsheet/add` form — so an added call is byte-identical to
one created in SmartStaff, with no new write endpoint to build or deploy.

- **`app.py`** — new route `POST /api/booking/<booking_id>/call`
  (`@require_cohort("admin")`). Validates name + date, defaults a blank time to
  `00:00:00`, formats the date with `format_ss_date` (`YYYY-MM-DD` →
  `'Month D, YYYY'`), always sends `duration_hours` / `crew_required` (0 default,
  since `ss_create_call` reads them with `[]`), and passes `call_name_free`
  through for Other calls. Shares the `/api/booking/<id>` path family but the
  extra `/call` segment means no route collision.
- **`templates/index.html`** — the **+ Add call** control in
  `renderBookingDialog`, plus `openAddCallForm` / `saveAddCall` (modelled on the
  existing call-edit form) and the `acCallNameSelect` / `acToggleOther` helpers.

## Why this is low-risk

Creating a call touches only the `calls` table. The crew-side rows (`calendars`,
`call_crew_map`) are written only when crew are **assigned / confirmed** — a
separate workflow — so a new call starts empty and there are no side-effects to
reproduce.

## Not included — delete/cancel call

SmartStaff has **no delete-call operation**, so removing a call is deliberately
out of scope here. A cancel-call flow (status → cancelled, notify + remove any
confirmed crew) is under discussion with the Operations team — see
`BACKLOG-cancel-call.md`.

## Files

- `app.py` (new route; `APP_VERSION` → 3.16.1)
- `templates/index.html` (+ Add call control + form)

## Deployment

No PHP, so the usual "endpoint to test → prod first" step does **not** apply.

1. Stage `app.py`, `templates/index.html`, and this changelog **individually**
   (never `git add .`); keep the untracked doc files out.
2. `git pull --rebase`, then push to `main`.
3. Build + notarize the DMG on the iMac from `~/dev/gigpower` (asset name exactly
   `TheGOAT.dmg`).
4. Smoke-test: open a booking → **+ Add call** → add a standard call and an
   **Other** call; confirm both appear in the list and in SmartStaff. Hard-refresh
   after launch (template caching).
5. Publish the GitHub release; confirm the asset via `get_release_by_tag`
   (`state: "uploaded"`, `draft: false`).
6. Flip `version.json` to 3.16.1 **last**.
