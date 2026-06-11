# THE GOAT — v3.5.6

Venue geo for Crew Finder distance search now comes from real SmartStaff venue
data instead of a hand-maintained postcode table. This both widens coverage
(from ~22 venues to ~930) and corrects several wrong postcodes that were
standing in as placeholders.

## Changed — venue locations from SmartStaff, not a hand table

Crew Finder's "use venue" radius search resolved a call's venue to coordinates
through `VENUE_POSTCODES` — a hand-maintained dict of ~22 venue codes, 12 of them
flagged `# VERIFY`. Anything not in that table (every ad-hoc and regional venue)
resolved to "location unknown", and several of the guesses were simply wrong
(AAMI Park sat on `3000`; it's actually 3121 Richmond).

The `venues` table already carries `postcode`, `suburb` and `state`. Venue geo
now reads those via a new endpoint, cached and resolved deterministically by
venue name.

## New — list-venues-bulk.php + venue geo cache

- **`list-venues-bulk.php`** (`/ajax/crew/`, `goat_can_read_all`) returns every
  active venue with `{id, name, postcode, suburb, state, has_induction}`. Venue
  location isn't PII, so it's read-all (admin / leadership / operations), unlike
  the admin-only import lookups.
- **`venue_cache.json`** is built name-keyed on the same rebuild as the crew
  cache (single cheap call; never fails the crew rebuild), generated/gitignored
  like `crew_cache.json`.

## How resolution works

`venue_to_coords()` resolves entirely from real data, in order:

1. **Exact venue-name match** (the call's venue from `get-calls-bulk` is the
   literal `venues.venue` value, so this is deterministic — no fuzzy matching).
   Prefer the real **postcode**; if blank, fall back to a **suburb+state
   centroid**.
2. **Short code / keyword** ("Forum", "JCA", a `detect_venue` code) → routed
   through `INDUCTION_VENUE_MAP` to the matching cached venue, then its real geo.
3. **Legacy `VENUE_POSTCODES`** — last resort only, for the cold-start window
   before the cache has built. No longer the source of truth; retirable once the
   cache path has proven out in practice.

The suburb fallback (phase 2) is built from a reverse index over the bundled
`au_postcodes.json` — group by suburb+state, average the postcode centroids —
so marquee venues with a blank postcode in SmartStaff (Rod Laver, John Cain,
Palais, Festival Hall, Hanging Rock) now resolve on their real suburb rather
than a hand guess.

## Coverage

~640 active venues resolve by real postcode, ~930 once the suburb fallback is
included — versus ~22 before. Venues with neither a postcode nor a table-known
suburb remain "location unknown" (surfaced, never silently dropped).

## Code changes

- `app.py` — `USE_BULK_VENUES_ENDPOINT` flag + config override; `VENUE_CACHE_FILE`;
  `fetch_venues_bulk`, venue-cache load/save, `build_venue_cache` (hooked into
  `_do_cache_refresh`); suburb reverse index (`_build_suburb_index`,
  `suburb_to_coords`, state/suburb normalisers); `_venue_record_to_coords` and
  `_cache_venue_for_code`; rewritten `venue_to_coords`. `VENUE_POSTCODES`
  demoted to last-resort fallback. `APP_VERSION` → 3.5.6.

## Files

- `list-venues-bulk.php` (new SmartStaff endpoint, `/ajax/crew/`).
- `app.py` (above).
- `venue_cache.json` — generated on rebuild, gitignored (covered by the existing
  `*_cache.json` ignore).

## Deployment

`list-venues-bulk.php` must be on **production** SmartStaff before the app
release. The fallback (legacy `VENUE_POSTCODES`) means a missed deploy degrades
gracefully rather than breaking.

Sequence: deploy endpoint to prod → commit + push `app.py` + changelog → build
DMG → smoke-test → publish GitHub release (asset `TheGOAT.dmg`) → flip
`version.json` to 3.5.6 last.
