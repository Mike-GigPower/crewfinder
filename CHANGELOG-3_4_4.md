# THE GOAT — v3.4.4

_Release date: 2026-05-29_

## Highlights

This release completes the Crew Finder availability migration to the bulk
SmartStaff endpoint, replacing the last per-crew HTML scrape. Conflict detection
is now filtered to confirmed-only commitments (matching the old scraper's
behaviour), while non-confirmed shifts (declined, pending, no-show) are surfaced
on the timeline informationally so the operator can see context the old scrape
silently dropped.

## Crew Finder

- **Availability now uses `get-shifts-bulk.php` end-to-end.** Replaces the
  per-crew `get_crew_shifts` profile scrape that occasionally under-reported
  upcoming bookings. The bulk path runs one HTTP request per search instead of
  one per candidate.
- **Confirmed-only conflict detection.** Only `status=5` shifts trigger Rule 1
  (overlap), Rule 2 (long-shift gap), and Rule 3 (venue change) conflicts. This
  matches the old scraper's `if status.lower() != "confirmed": continue` filter
  exactly — declined, pending, and no-show shifts no longer impose gap or venue
  requirements for new bookings.
- **Non-confirmed shifts shown informationally.** Previously the old scrape
  dropped these entries entirely; they are now rendered as blue bars on the
  timeline with tooltips indicating the status (Declined / Pending / No Show /
  Unconfirmed). Use case: a crew member who declined a 3pm shift may also be
  inclined to decline a 4pm shift — the operator can see that context.
- **Already-booked crew no longer self-conflict.** Crew confirmed on the target
  call are excluded from the conflict-check input for that call (their existing
  booking on the call would otherwise overlap itself). They continue to appear
  in the "Already Booked" section at the top of results.
- **Availability cell labels corrected.** The label now reads CONFLICTED for
  conflict-bucket rows and SKIPPED for skipped rows, instead of always showing
  AVAILABLE regardless of bucket.

## Crew Utilization (forecast)

- **Utilisation hours now exclude non-confirmed shifts.** The forecast grid was
  silently over-counting declined and pending shifts as worked hours since 3.4.3
  (the bulk endpoint returned them; the old scraper had not). Total hours and
  daily breakdowns now reflect confirmed commitments only.

## ASK THE GOAT

- `search_availability` and `get_forecast` tools both apply the confirmed-only
  filter, matching the Crew Finder behaviour.

## SmartStaff endpoint

- **`get-shifts-bulk.php` now joins `call_crew_map`** and emits a `status`
  integer on each shift (5=confirmed, 1=pending, 6=declined, 8=noshow, 0=unset).
  The app filters on this field. Without the updated PHP, the app falls back to
  treating every shift as non-confirmed (no conflicts) — so the SmartStaff PHP
  must be deployed alongside the app update.

## Notes

- The legacy `get_crew_shifts` scrape is retained but no longer called from any
  code path. Marked deprecated in its docstring; will be removed in a future
  release once the bulk path has proven stable in production.
- Timeline shift bars are now red for confirmed shifts (previously gold for
  available rows, red for conflicted rows). Confirmed shifts are coloured
  consistently regardless of the row's overall availability — a confirmed shift
  is always a real commitment.
