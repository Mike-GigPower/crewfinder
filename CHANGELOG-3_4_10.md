# THE GOAT — v3.4.10

_Release date: 2026-06-03_

## Highlights

The Crew Utilization page shows real EINs again. The EIN column had regressed to
displaying each crew member's userID — a return of an old bug, reintroduced when
the roster moved onto the bulk endpoint.

## The fix

- **`fetch_crew_bulk` now consumes the endpoint's `ein` field.** The bulk-roster
  fetcher had been stubbing `ein` to the crew `id` (manage_id / userID) behind a
  comment claiming the endpoint didn't expose EIN — but `list-crew-bulk.php` does
  emit an `ein` field. The mapping is now `c.get("ein") or c.get("id")`: the real
  EIN when present, userID only as a last-resort fallback.
- **Why it surfaced only on the bulk path.** Every other EIN site already reads
  `crew.get("ein", <id fallback>)`, so a *missing* ein degrades gracefully. This one
  line filled `ein` *with* the id, defeating all the downstream fallbacks. The legacy
  per-crew scrape still extracts a real EIN, so the column was correct whenever the
  bulk endpoints were off — which is why the regression rode in with the bulk-roster
  default rather than showing up as a fresh bug.

## Upgrade note

Existing installs have `ein = userID` baked into every `crew_cache.json` row, so the
fix only takes effect once the roster cache rebuilds. It refreshes automatically on
login and every ~15 minutes (3.4.7); a manual roster refresh forces it immediately.
Until then the column will still show userIDs even on a patched build.

## Code changes

- `app.py`: `fetch_crew_bulk` — `ein` now `c.get("ein") or c.get("id")`; stale
  "doesn't expose EIN" comment removed; `APP_VERSION` -> 3.4.10.
- `version.json`: bumped to 3.4.10.
