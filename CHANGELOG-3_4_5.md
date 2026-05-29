# THE GOAT — v3.4.5

_Release date: 2026-05-29_

## Highlights

This release completes the bulk-endpoint migration started in 3.4.3: every
read of SmartStaff data — crew, shifts, and now unavailabilities — comes
through a single bulk endpoint instead of per-crew HTML scraping.

Unavailability data is now hour-accurate, fixing a long-standing limitation
where partial-day periods were collapsed to whole days and short same-day
entries were dropped from the forecast grid entirely.

The forecast and unavailability disk caches have been removed. Both data sets
are now read live on every view — sub-second via the bulk endpoints, with no
staleness, no preload races, and no in-memory cache invalidation logic.

## Option Y — bulk unavailabilities endpoint

- **New SmartStaff endpoint `get-unavailabilities-bulk.php`** returns every
  type=1 calendars row in a window in a single query. Admin-only guard, 120-day
  window cap. Replaces the per-crew impersonation scrape that was used by the
  4-hourly bulk rebuild.

- **Hour-accurate data restored.** Periods like "Lectures 10 June 07:00–10:00"
  now render correctly as part-day diagonal cells on the Crew Utilization
  forecast grid. Previously the HTML scraper collapsed every entry to
  `00:00–23:59:59` and dropped same-day entries shorter than a day entirely.

- **Crew Finder availability** also benefits — declined-shift conflict checks
  and venue gaps now operate on hour-level precision for unavailability
  periods, matching the behaviour shifts have had since 3.4.3.

## Caches removed

- **Forecast cache (`forecast_cache.json`) deleted.** The 28-day grid is now
  computed at request time, ~1 second per view. The `force=true` query
  parameter is retained for backwards compatibility but is now a no-op.

- **Unavailability cache (`unavail_cache.json`) deleted.** Live reads via the
  new bulk endpoint, ~0.5 seconds.

- **Auto-refresh background thread retired.** Previously polled every 30
  minutes to keep caches warm; no caches to warm anymore. Login is noticeably
  faster as a result.

- **Crew roster cache (`crew_cache.json`) retained** as the only persistent
  cache. It protects against the only genuinely expensive operation left
  (`list-crew-bulk.php` with all its joins) and the data is slow-changing
  (groups, ratings, inductions, phone numbers).

- **`/api/forecast/preload-status` and `/api/forecast/preload`** are retained
  as no-op compatibility shims so existing clients don't error. They will be
  removed in a future release once front-end usage is fully updated.

## UI refinements

- Header indicator renamed from "Cache: X profiles" to "Roster: X" — clarifies
  that it now refers only to the crew roster cache.
- Crew Utilization tab's per-view freshness indicator simplified to "● live"
  since every grid view is now a fresh read.

## Notes

- **Production deployment dependency:** the SmartStaff endpoint
  `get-unavailabilities-bulk.php` must be in place on the production
  SmartStaff at `/ajax/crew/` *before* this release is distributed.
  Without it the app falls back to the per-crew HTML scrape — same
  behaviour as before, no regression but no improvement.
- The legacy `get_crew_unavailabilities` HTML scraper is retained as the
  fallback path inside `_get_unavails_for_window`. Marked for removal in a
  future release once the bulk path has proven stable in production.
- 151 lines of cache-management code removed from `app.py`.
