# CHANGELOG — v3.11.0 · Post-show times + All Bookings

Everything new since the 3.10.0 DMG. Three new PHP endpoints
(`update-call-times.php`, `get-call-times.php`, `get-bookings-bulk.php`) and one
schema column (`call_crew_map.goat_note`); the rest is source (`app.py`,
`templates/index.html`).

The driver: a Crew Boss / admin can now enter the **actual times worked** for a
call directly in THE GOAT, and — because finished shows fall off the future-only
Schedule — a new **All Bookings** tab makes any past booking reachable to do it.

## Enter Times (new)

A **⏱ Enter Times** button on the call dialog opens a per-crew grid matching
SmartStaff's "Crew Booked for Call" times row: **On / Day Break / Night Break /
Off**, a **Rate** (paygrade) dropdown, a **Late** toggle, and a free-text **Note**
per crew member. Times are validated 24-hour text inputs (not `<input type=time>`,
which renders AM/PM under WebKit). The grid prefills from what's stored and saves
the whole call in one request.

What a save writes, **byte-identical to a native SmartStaff times save**: `on`,
`break`, `break_night`, `off`, `callpaygradeID` and the four derived rate columns
(`callrate`, `callrate_night`, `callchargeout`, `callchargeout_night`) copied from
the paygrade row. Rate is bundled deliberately — `callrate` defaults to `0`, so a
times-only write that skipped the paygrade copy could leave a crew member un-rated
in payroll. Plus two GOAT additions: the **note** (`goat_note`) and **late**
(`late`, `'1'`/`'0'` — the same byte the native LATE button writes).

What a save **never** writes, by design:

- `times_filled` and `call_locked` — the "Times filled" tick stays the human
  review gate in SmartStaff, and the accounting lock stays a SmartStaff action, so
  `generateCallData` can never be tripped from THE GOAT.
- `user_entered_times` — that flag means the *crew member* self-reported via the
  portal (`user-times.php` sets it); the admin path never does, and neither do we.
- `status` — no-show stays on the existing `update-crew-status.php` (status 8).

The endpoint refuses to write a locked call (409) and skips any row whose crew
member isn't booked on the call (reported, never inserted). Writes are partial:
each row writes only the keys it sends, so a future times-only import can never
zero a rate or clobber a note.

### New endpoints
- **`update-call-times.php`** — `?id=<callID>`, admin-gated
  (`goat_user_cohort() !== 'admin'` → 403). Body `{rows:[{user_id, on, off,
  break, break_night, callpaygradeID, late, note}, …]}`. Per-row `UPDATE
  call_crew_map`, gated on `mysql_error()` (a no-op save reports 0 changed rows);
  no `addToCalendar` loop (actual times don't move the scheduled calendar entry).
- **`get-call-times.php`** — `?id=<callID>`, admin-gated. Returns each booked
  crew member's current times / paygrade / late / note plus the paygrade option
  list, to prefill the grid. Mirrors the native `call-times.php` SELECT, with
  `goat_note` + `late` added and each crew member's default paygrade carried so
  the dropdown never lands on `0`.
- **Schema** — `ALTER TABLE call_crew_map ADD COLUMN goat_note TEXT NULL AFTER
  sms_fail`. GOAT-only; the native SmartStaff UI doesn't render it.

PHP5-safe throughout (`mysql_*`, `array()`, no `??`, no short arrays).

## All Bookings tab (new)

The Schedule is future-only by design, so a finished show had nowhere to be
opened from — which is exactly where times get entered. The new **📚 All
Bookings** tab is a chronological list of every booking, **most recent first**, so
recently-finished shows sit at the top.

- Booking rows show name, date, venue, customer, status (Active/Closed) and call
  count. **Expand** a row to load its calls on demand (via the existing
  `get-booking.php`); each call row opens the call dialog where **⏱ Enter Times**
  lives. The find → expand → enter-times path closes the loop.
- A **booking-name search** (debounced) and a **Show 50 more** footer page the
  list 50 at a time. Admin-only (hidden for read-only cohorts).

### New endpoint — `get-bookings-bulk.php`
- `?limit=50&offset=0&q=<name>` · admin-gated. One light query: bookings ordered
  `creation_date DESC` with `customers.customer_name` / `venues.venue` joined
  (mirrors `get-booking.php`) and a `call_count` subquery. Returns `total` for the
  show-more control. Calls are **not** carried here — the front-end fetches them
  per booking on expand — keeping the list payload small.
- `app.py`: `ss_get_bookings_bulk()` + `GET /api/bookings/all`
  (`@require_cohort("admin")`).

## Schedule reads the DB, not the scrape

`api_schedule` now sources the Schedule from the DB-backed `get-calls-bulk.php`
for **everyone**. Leadership already did; admin previously scraped the
`/bookings` HTML. The brittle admin scrape is retired as the primary path and
kept only as a **fallback** that fires if the bulk endpoint returns nothing — so
it can't regress to a broken Schedule. Booking-field reads softened to `.get()` so
a missing key from either source can't 500. No new endpoint — `get-calls-bulk.php`
is already on prod.

## Files
| File | Where | Change |
|---|---|---|
| `update-call-times.php` | `/ajax/crew/` | **NEW** — deploy test → prod before build |
| `get-call-times.php` | `/ajax/crew/` | **NEW** — deploy test → prod before build |
| `get-bookings-bulk.php` | `/ajax/crew/` | **NEW** — deploy test → prod before build |
| `call_crew_map.goat_note` | DB (test + prod) | **NEW COLUMN** — run the `ALTER` on both before build |
| `smartstaff/update-call-times.php` | repo | source copy |
| `smartstaff/get-call-times.php` | repo | source copy |
| `smartstaff/get-bookings-bulk.php` | repo | source copy |
| `app.py` | repo root | call-times + bookings-list wrappers/routes; Schedule swap to bulk + fallback; `APP_VERSION = 3.11.0` |
| `templates/index.html` | repo | Enter Times grid + ⏱ button on the call dialog; 📚 All Bookings tab |

## Deployment
PHP + schema to prod first, then source, then build, then `version.json` last.

1. `ALTER TABLE call_crew_map ADD COLUMN goat_note TEXT NULL AFTER sms_fail` on
   **test** (done) and **prod**.
2. Deploy `update-call-times.php`, `get-call-times.php`, `get-bookings-bulk.php`
   to `/ajax/crew/` on **test** → **prod** (confirm each returns JSON).
3. Commit `app.py` + `templates/index.html` + the three `smartstaff/*.php` source
   copies + this changelog to `main`.
4. Build the DMG (`~/dev/gigpower`, strips the test `base_url`), publish the GitHub
   release (asset `TheGOAT.dmg`, confirm `state:"uploaded"`).
5. Flip `version.json` to `3.11.0` **last**.

Smoke test before step 4: enter times on a call (rate columns copy, note + late
persist, locked call refuses); open All Bookings (recent show on top, search,
expand → call → Enter Times, Show 50 more); open the Schedule and confirm the same
calls/counts as the SmartStaff dashboard now that admin reads the bulk endpoint.
