# THE GOAT — v3.18.0

Backup crew, **Phase 1 of 3** — *surfacing*. Makes the existing but invisible
**Backup** state (`call_crew_map.status = 7`) visible across the Crew Finder and
the booking/call dialog. Also carries a small **bug fix** to the call dialog's
crew-required count. **Display-only** this release — no new write path; the
capacity-enforcement and promote-a-backup phases follow (see *On the horizon*).

## Background — status 7 already exists

`sms-cron.php` has always written **status 7** in its "too full" branch: when a
crew member SMS-replies `y` to a call whose confirmed count (`status = 5`) has
already reached `required`, they are *not* rejected — they're tagged 7 and sent
the too-full template. So a real standby pool has been accumulating in
`call_crew_map` unlabelled. A `status = 7` count on prod confirmed live rows
already exist (a mix with and without a `calendars` row).

Nothing in THE GOAT knew the number 7, so those people displayed as
**"unconfirmed"** (booking dialog) or **"waiting"** (Crew Finder). Phase 1 gives
7 a name — **backup** — and a colour everywhere it surfaces. No schema change:
this reuses the existing `status` column, unlike the linked-calls `link_group`.

## What shipped (display)

- **Crew Finder timeline** — status-7 shifts draw a **teal Backup bar** (was a
  mislabelled blue "Unconfirmed" bar for the backups that have a calendar row),
  with a matching **Backup** swatch in the Shift-bars legend.
- **Crew Finder — ALREADY BOOKED panel** — backups get their own **Backup**
  group, placed **after Confirmed** (Confirmed → Backup → Unconfirmed → Waiting →
  Declined).
- **Crew Finder — results row** — a crew member who is a backup on the searched
  call shows a teal **BACKUP** availability pill (was WAITING).
- **Booking / call dialog roster** — the status badge renders **backup** in teal
  instead of a grey fallback.

Colour: teal `#14b8a6` (border `#0d9488`) — deliberately distinct from booked
(green), waiting (grey), conflict/declined (red), potential-conflict (amber),
info (blue), unavailable (purple).

## How it works — one label, read from the DB everywhere

The status **number → word** translation lives in `get-booking.php`; every
GOAT-side display then reacts to the word `backup`.

- **`smartstaff/get-booking.php`** — added `7 => 'backup'` to `$crewStatusMap`.
  One line. This is what lets everything downstream tell a backup from an
  unconfirmed. **PHP — deploy test → prod before the build.**
- **`app.py` — `api_booked_crew`** — the Crew Finder's ALREADY BOOKED panel
  (`/api/booked-crew/<b>/<c>`) previously **scraped the SmartStaff callsheet
  page** and bucketed any unrecognised status text as `waiting` — so 7 never
  showed. It now reads the roster from the DB-backed `get-booking.php` (via
  `fetch_booking_bulk`), which already returns `backup`. The legacy scrape is
  kept as a **fallback** (only runs if the DB endpoint fails), so reliability
  only goes up.
- **`templates/index.html`** — reacts to `backup` in the three places the
  frontend renders status:
  - `buildBookedRel` — maps `status === 'backup'` → rel `backup`.
  - `targetRelationship` — multi-call fallback recognises shift `status === 7`.
  - `statusLabel` — teal **BACKUP** pill for rel `backup`.
  - top-panel `statusConfig` + `grouped` — a `backup` group after `confirmed`.
  - timeline bars — `status === 7` → `bar-backup` in the `is_target`,
    informational, and synthetic-target branches.
  - `crewStatusBadge` — teal case for the dialog roster.
  - CSS — `.tl-bar.bar-backup` and `.status-backup`; legend swatch after Booked.

### Data note (learned this cycle)

The ALREADY BOOKED panel is fed by `/api/booked-crew`, **not** `/api/booking`.
Before this release those were two different code paths reading two different
sources (callsheet scrape vs `get-booking.php`); Phase 1 points them at the same
DB-backed source. The `calendars`-row presence for a status-7 row is
**inconsistent** (some have one, some don't), so backups are read from
`call_crew_map.status` — never inferred from a calendar row.

## Bug fix (bundled) — call dialog "0 required"

The call dialog's Crew line read `crew_required` off the **scraped** `/api/call`
response, where it was unreliable and fell back to `0` (so a call needing 2 showed
"0 required"). It now prefers the **DB-backed** value already fetched from
`/api/booking` (`editSrc.required`), falling back to the scrape only if absent.
`templates/index.html`, one line in `renderCallDialog`.

## Testing — `smstest.php` (test bench only, not shipped)

A dry-run-by-default, admin-gated **SMS reply simulator** was written to verify
the backup decision without the MessageMedia gateway or the cron. It reproduces
`sms-cron.php`'s decision (confirm vs backup) and, with `&commit=1`, writes the
status. Verified on test: first `y` on an open call → **5 (Confirmed)** + calendar
row; second `y` once the call is full → **7 (Backup)**, **no** calendar row —
exactly the intended split.

> `smstest.php` is a **test-only bench tool**: deploy to test, keep admin-gated,
> and **do not commit it to `main` or ship it in the DMG**. Remove from test once
> trialling is done. (Kept out like the other untracked doc/tool files.)

## On the horizon — Phases 2 & 3 (not in this release)

- **Phase 2 — enforce + promote.** Add the "call full → write 7" capacity check
  to `respond-to-call.php` (the **PWA path currently has none**, so the app can
  still over-fill a call). Add a **promote-to-confirmed** action, which also
  means teaching the call dialog's status dropdown (`CREW_STATUS_OPTS`) and
  `update-crew-status.php`'s allow-list about 7 (currently `0,1,5,6,8`).
- **Phase 3 — CrewHub.** "You're on standby" messaging in the Crew Hub PWA, and
  optionally a push when a backup is promoted.
- **`sms-cron.php` message bug** (separate, pre-existing): the too-full branch
  builds its confirm/cancel codes from `$callID`, which is never set in that file
  (the id lives in `$callInfo->callID` / `$msgParts[1]`) — so the live backup SMS
  goes out with incomplete codes. Does **not** affect the status write; fix
  alongside Phase 2.

## Known interaction (not a regression)

The call dialog's editable status **dropdown** doesn't list Backup yet, so it
shows a status-7 person as "Unconfirmed" and — if blind-saved — would write 0 and
demote them. This predates 3.18.0 (7 previously read as "unconfirmed" there too);
Phase 2 fixes it properly. Until then: don't save that dropdown on a backup.

## Files

- `smartstaff/get-booking.php` — `7 => 'backup'` in `$crewStatusMap` (**PHP**)
- `app.py` — `api_booked_crew` reads DB-first; `APP_VERSION` → 3.18.0
- `templates/index.html` — backup styling / grouping / pill + required-count fix
- `CHANGELOG-3_18_0.md`
- *(test bench, uncommitted: `smstest.php`)*

## Deployment (PHP this release — full order applies)

1. Deploy **`get-booking.php`** to **test → prod** (cPanel). Verify with an
   unauthenticated-then-admin GET of
   `/ajax/crew/get-booking.php?id=<booking>` — a status-7 crew member's `status`
   should now read `"backup"`.
2. Stage `app.py`, `templates/index.html`, and this changelog **individually**
   (never `git add .`); keep untracked docs/tools (incl. `smstest.php`) out.
3. `git pull --rebase`, then push to `main`.
4. Build + notarize the DMG on the **iMac** from `~/dev/gigpower` (`./build_dmg.sh`,
   asset named exactly `TheGOAT.dmg`).
5. Publish the GitHub release; confirm via `get_release_by_tag`
   (`state: "uploaded"`, `draft: false`, `prerelease: false`).
6. Smoke-test (hard-refresh): on test, use `smstest.php` to set a crew member to
   backup on a full call, then search that call in the Crew Finder → confirm a
   teal **BACKUP** group in ALREADY BOOKED and a **BACKUP** pill on the row; open
   the booking dialog → confirm the Crew line shows the correct **required**
   count (the bundled fix).
7. Flip `version.json` to **3.18.0 last**.
