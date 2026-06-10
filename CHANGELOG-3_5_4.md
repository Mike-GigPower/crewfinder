# THE GOAT — v3.5.4

A data-layer change on top of 3.5.3. The Crew Finder "Unfilled Calls" list is the
last significant read that was still scraping SmartStaff HTML; this release moves
it onto the existing `get-calls-bulk.php` endpoint, completing the bulk-endpoint
migration begun in 3.4.3.

## Changed — "Unfilled Calls" reads JSON, no longer scrapes the dashboard

Until now the Crew Finder calls panel was built by `scrape_calls(ss, /dash)`,
parsing the SmartStaff dashboard HTML row by row — booked/required from a
`<b>N / M</b>` regex, call name from a fixed `<td>` index, venue and booking name
by walking sibling DOM nodes. Any dashboard layout change could silently corrupt
one of those, and a wrong booked/required would feed straight into the unfilled
filter.

The list now comes from `get-calls-bulk.php` — the same DB-backed, cohort-gated,
windowed endpoint the Schedule already uses — as structured JSON.

**`booked` definition (verified).** The endpoint counts confirmed crew only
(`call_crew_map.status = 5`), which matches what operators see on SmartStaff.
Confirmed against a live call (#37946, Aura Drone Show – MCG): the callsheet shows
2 confirmed + 1 waiting, the endpoint reports `booked: 2`, and the Unfilled Calls
panel shows `2/5` — all three agree. Waiting/awaiting crew are correctly excluded.

**Zero-booked calls now appear.** Calls with no confirmed crew (e.g. a `0/10`
Load Out) were impossible to surface from an assignment list; they come through
the endpoint correctly and show as fully unfilled.

## How it works

- New `fetch_unfilled_calls(ss)` prefers `get-calls-bulk.php` and maps each row
  back into the exact `scrape_calls()` dict shape, so `/api/calls`, `loadCalls`,
  and the GOAT `get_calls` tool are unchanged. `unfilled = booked < required` is
  computed app-side exactly as before.
- **Graceful degradation.** On any endpoint failure (or with the bulk path
  disabled), it falls back to the existing dashboard scrape — identical pattern to
  the other bulk reads. `scrape_calls` is retained as that fallback.
- **Feature flag.** `use_bulk_calls_endpoint` in `config.json` (default `true`);
  the master `use_bulk_endpoints` switch also gates it. Set either to `false` to
  force the legacy scrape for A/B comparison.
- **Window.** Today through +90 days (capped at the endpoint's 120-day limit).
  The endpoint excludes Completed and hidden bookings, matching the admin
  `/bookings` view.

## Parity notes

- `call_num`: the DB has no per-booking call sequence number, so it maps to
  `call_id`. `scrape_calls` already fell back to `call_id` when the dashboard
  `#num` was absent, and `/api/availability` defaults `call_num` → `call_id`, so
  behaviour is unchanged.
- `venue`: the endpoint returns the full venue name (e.g. "Forum Melbourne") where
  the scrape returned a detected short code or empty string. Both resolve the same
  way through `venue_to_coords` and the induction map.

## Files

- `app.py` — `USE_BULK_CALLS_ENDPOINT` flag + config override;
  `_bulk_call_to_scrape_shape` and `fetch_unfilled_calls` (new); `/api/calls` and
  the GOAT `get_calls` tool repointed onto `fetch_unfilled_calls`; `APP_VERSION`
  → 3.5.4.

## Deployment

No new SmartStaff deployment required — `get-calls-bulk.php` is already on
production (it serves the Schedule view). This is a pure app release.

Usual sequence: commit + push `app.py` first, then build the DMG, then publish the
GitHub release, then flip `version.json` to 3.5.4 last.
