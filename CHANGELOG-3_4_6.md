# THE GOAT — v3.4.6

_Release date: 2026-05-29_

## Highlights

The schedule view now detects and surfaces crew clashes server-side, using
the same conflict rules as Crew Finder. Operators see — at a glance — which
calls have crew members double-booked elsewhere, and can drill in to see who
is clashing and why.

Verified end-to-end against production data on first deployment: the feature
identified a real, previously-invisible clash that needed resolution.

## Schedule clash detection

- **New SmartStaff endpoint `get-booked-crew-bulk.php`** returns every
  confirmed (status=5) crew-call assignment in a date window in a single
  query. Admin-only guard, 120-day window cap, joined to users +
  calls + bookings + venues so the response includes name, hour-accurate
  start/end, and venue per assignment.

- **Server-side clash detection in `/api/schedule`** — for every confirmed
  crew member with two or more assignments in the window, walks each pair
  through `check_conflict`. A clash on either call records both calls.
  Reuses the same `check_conflict` function Crew Finder uses, so the two
  views are guaranteed to agree about what's a conflict.

- **Per-call clash payload.** Each call in the schedule response now
  carries `crew` and `clashes` arrays. `clashes` entries include
  `{user_id, name, reason}` so the front-end can show *who* clashes and
  *why* (Rule 1 overlap, Rule 2 long-shift gap, Rule 3 venue change).

- **Orange "clash" cells in the schedule grid.** Clash status takes
  precedence over staffing colour — even a 4/4 fully-booked call shows
  the orange pulsing indicator if any of its crew is double-booked.

- **Clash legend swatch** added to the schedule legend alongside the
  existing Full / Partial / Unfilled markers.

- **Tooltip detail at both cell levels.** Hovering a clashing cell shows
  the count plus the first three clashing crew names; expanding the
  booking reveals the full list of clashing crew under each affected
  call, with the specific rule violation surfaced as a hover-tip on
  each name.

## Architectural notes

- Conflict detection is single-source-of-truth: `check_conflict` lives in
  one place in Python, used by Crew Finder, the GOAT search_availability
  tool, the forecast utilisation calculation, and now schedule clash
  detection. No JavaScript duplicate to drift out of sync.
- The legacy client-side `schedFindClashes` JavaScript is retained as a
  fallback path for compatibility, but in normal operation against a 3.4.6
  app it's bypassed — the server-side detection is authoritative.
- Same bulk-endpoint pattern as `get-shifts-bulk.php` (3.4.4) and
  `get-unavailabilities-bulk.php` (3.4.5). Feature-flagged via
  `use_bulk_booked_crew_endpoint` config (default true). If the endpoint
  isn't deployed, the schedule still works — just without clash detection.

## Deployment dependency

`get-booked-crew-bulk.php` must be in place on production SmartStaff at
`/ajax/crew/` *before* this release is distributed. Without it the
schedule still works but clash detection is silently skipped (no
regression, just no improvement).

## Code changes

- `app.py`: +140 lines (helper + `/api/schedule` extension)
- `templates/index.html`: +52 lines (legend swatch, cell-class logic,
  enriched tooltips, inline clash display, server-side payload routing)
