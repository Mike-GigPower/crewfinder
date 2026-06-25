# CHANGELOG — v3.9.0 · Edit Crew Member tabs + Calls history

Everything new since the 3.8.0 DMG. One new PHP endpoint (`get-crew-shifts.php`);
the rest is source (`app.py`, `templates/index.html`).

## Edit Crew Member → tabbed
The admin edit modal is reorganised into four tabs, with the crew member's
**Lastname, Firstname** moved into the modal header (EIN · ID underneath it).
**Cancel** and **Save changes** stay pinned at the bottom on every tab.

- **Details** — the existing edit form (the old in-body EIN·ID line moved to the
  header). The Details panel stays in the DOM when you switch tabs, so unsaved
  edits survive tab changes and **Save changes** works from any tab.
- **Inductions** — the existing on-behalf induction view, now lazy-loaded into
  the tab with the **Upload certificate** button inside the panel.
- **Calls** — new (see below).
- **Unavailability** — the periods list + an add form (with the **All day**
  toggle) embedded directly in the tab. Uses its own `ceu-*` element ids so the
  separate Crew Utilization unavailability dialog is untouched.

Tabs are lazy-loaded once on first open and kept; switching back doesn't re-fetch.

## Calls tab (new)
The tab is labelled **Calls** (matching SmartStaff terminology). The endpoint,
route and source file keep the `shifts` name internally.
Lists **every call ever assigned** to the crew member, newest → oldest, each
with a status pill:

- green **Confirmed** (status 5)
- red **Declined** (status 6)
- amber **Unconfirmed / SMS Sent** (status 0 / 1 — offered, awaiting response)

Call-boss assignments show a **CALL BOSS** badge; a summary line at the top
counts each status. Booking/venue/call name, date, time and length are shown per
row.

### New endpoint — `get-crew-shifts.php`
- `/ajax/crew/get-crew-shifts.php?id=<userID>` · admin-gated
  (`goat_user_cohort() !== 'admin'` → 403), mirroring `get-crew.php`.
- Reads `call_crew_map` (the source of truth for assignments, incl. offered
  calls that never reach `calendars`) joined to `calls` / `bookings` / `venues`,
  `ORDER BY calls.start_date DESC, start_time DESC`. Superset of `my-shifts.php`,
  which is confirmed-only and window-capped.
- Status labels resolved server-side (0 Unconfirmed, 1 SMS Sent, 5 Confirmed,
  6 Declined; anything else → "Status N"). PHP5-safe (`mysql_*`, `array()`,
  no `??`, no short arrays).
- `app.py`: `ss_get_crew_shifts()` helper + `GET /api/admin/crew/<id>/shifts`
  route (`@require_cohort("admin")`), called with the admin's own session.

## "All day" on unavailability entry
A single **All day** checkbox added to both unavailability add forms; when ticked
it locks the times to `00:00 – 23:59`, greys the time fields, and submits a true
full day. Resets to unticked when the form reopens.

- **Admin** unavailability dialog (Crew Utilization). The GOAT's minute selects
  only offer :00/:15/:30/:45, so all-day forces `00:00 → 23:59` directly in
  submit, matching portal-created rows.
- **Crew My Status** unavailability form (`type="time"` inputs, defaults
  `00:00` / `23:59`).

## Unavailability reachable from Edit Crew Member
Previously a separate button; now the **Unavailability tab** above (the old
`openCrewUnavail` wrapper is retained but no longer wired in).

## Files
| File | Where | Change |
|---|---|---|
| `get-crew-shifts.php` | `/ajax/crew/` | **NEW** — deploy test → prod before build |
| `app.py` | repo root | `ss_get_crew_shifts` + `/api/admin/crew/<id>/shifts`; `APP_VERSION = 3.9.0` |
| `templates/index.html` | repo | tabbed Edit Crew Member, Calls tab, embedded Unavailability + All-day toggles |
| `smartstaff/get-crew-shifts.php` | repo | source copy of the new endpoint |
