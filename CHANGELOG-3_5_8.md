# THE GOAT — v3.5.8

Clicking a booking or call name now opens a read-only **detail view inside THE
GOAT** instead of navigating out to SmartStaff. In 3.5.7 those names opened the
SmartStaff record directly, which depended on the **browser** having a live
SmartStaff session — most users don't, so they hit a login / "agree to the
handbook" wall. The dialog fetches everything through THE GOAT's own server
session, so it always works, and it's backed by a new `get-booking.php` endpoint
that returns the full booking with per-call crew rosters.

## New — in-GOAT booking & call dialogs

- **Booking dialog** (click a booking name on the Schedule or Crew Finder) —
  date, status, customer, contact and on-site contact (each with tap-to-call
  phone/mobile), venue with address, invoice reference, notes, and a compact
  list of the booking's calls. Each call opens the call dialog.
- **Call dialog** (click a call name, or a call inside the booking dialog) —
  date, time, length, venue, a crew summary, and the booked-crew roster: every
  crew member with status (confirmed / sent / declined / no-show), a tap-to-call
  mobile, and a `BOSS` flag — so a crew boss can reach whoever's running late.
  Buttons: Find Crew, Open in SmartStaff, Close. Edit is present but disabled
  (arrives next release).

The names no longer leave the app; the explicit **"↗ SmartStaff"** button inside
each dialog is there when you do want the real record (and it honours `base_url`,
so a test build opens test).

## New — get-booking.php (booking detail + per-call crew)

**`get-booking.php`** (`/ajax/crew/`, `goat_can_read_all`) returns a booking's
full detail by direct query — modelled on `view-booking.php`'s joins plus the
`reference` column — rather than scraping a page:

- customer; venue with address / suburb / state
- contact **and** on-site contact, each with phone + mobile
- per call: name, time, `required`, confirmed/booked counts, and a **crew
  roster** (name, mobile, `call_crew_map.status` mapped to a label, `is_call_boss`)

Contact + crew phone/mobile are included deliberately — crew bosses (leadership)
use them to reach people when crew run late — so it's the read-all gate, like
`list-venues-bulk.php`, not admin-only.

## How it works

`GET /api/booking/<id>` calls `get-booking.php` first and falls back to
`scrape_booking_details` (a GET-only scrape of the edit-booking form) if the
endpoint is unavailable. The scrape is a deliberately limited safety net: it can
read text inputs (date, reference) and the small Status select, but **not** the
JS-populated customer / contact / venue dropdowns — which is exactly why the
endpoint exists. With it deployed, every field populates from the database.

Two details worth noting, both so the dialog agrees with the Schedule:

- **"Booked" = confirmed.** The crew summary counts confirmed crew (status 5),
  matching the Schedule's "x / y" dashboard figure — not the raw
  `call_crew_map` row count, which includes declined and pending people. The
  roster still lists everyone with their status badge; a grey note spells out the
  total assigned.
- **Venue comes from the booking.** A call inherits its booking's venue, so the
  call dialog shows the booking's venue rather than the callsheet scrape's
  `detect_venue` guess (which could misfire, e.g. "MCEC" for a Forum booking).

Crew detail lives at the **call** level only; the booking dialog's call list
stays a compact overview so it doesn't balloon on big bookings.

## Code changes

- **`get-booking.php`** — new read-all bulk endpoint (above).
- **`app.py`** — `scrape_booking_details` (edit-form fallback); `GET
  /api/booking/<id>` (read-all); `USE_BULK_BOOKING_ENDPOINT` flag + config
  override; `fetch_booking_bulk`; route goes bulk-first with the scrape as
  fallback. `APP_VERSION` → 3.5.8.
- **`templates/index.html`** — entity view dialog (modal, `openBookingDialog` /
  `openCallDialog` and renderers); booking/call names repointed from the
  SmartStaff helpers to the dialogs (helpers retained for the in-dialog
  "↗ SmartStaff" button); call dialog sources crew (with mobiles) and venue from
  the booking endpoint; "booked = confirmed" summary; tap-to-call `tel:` links.

## Files

- `get-booking.php` (new SmartStaff endpoint, `/ajax/crew/`).
- `app.py` (above).
- `templates/index.html` (above).

## Deployment

`get-booking.php` must be on **production** before the app release. The bulk flag
+ scrape fallback means a missed deploy degrades to the limited edit-form scrape
(blank dropdown fields) rather than breaking.

Sequence: deploy `get-booking.php` to test → prod (confirm it returns JSON on
both) → commit `app.py` + `index.html` + changelog → build DMG → smoke-test
(open a booking and a call; confirm fields, crew + mobiles, venue, and "booked"
match the Schedule) → publish GitHub release (asset `TheGOAT.dmg`, confirm
HTTP 200) → flip `version.json` to 3.5.8 last.
