# THE GOAT — Crew Finder filtering overhaul

**Scope:** THE GOAT (`Mike-GigPower/crewfinder`). `app.py` (`api_availability`,
`api_groups`), `templates/index.html` (filter UI), and new
`smartstaff/list-groups.php`. Covers four related pieces of work: the
public-transport exclusion filter, the live group list, the consolidated filter
toolbar, and reactive re-search.

**Deploy state:** `list-groups.php` is **not yet deployed** — deploy it to
test → prod before relying on the live group list (`api_groups` falls back to a
hardcoded list until then, so nothing breaks if it's missing). `app.py` +
`index.html` reach the team on the next DMG build.

---

## What shipped (in-app)

- **Exclude public-transport-only crew.** Crew in the new **PT Only** group can be
  excluded from a Crew Finder search with one checkbox — useful when a call needs
  people who can drive themselves. Excluded crew drop into the *skipped* list with
  a reason, rather than vanishing silently.
- **The group filter list is now live from the database.** It previously showed a
  hardcoded set, so newly-created groups (Own Car, PT Only) never appeared. It now
  reflects `crew_groups` as it actually is.
- **Filters consolidated into a toolbar above the results.** The group grid,
  rating, exclude-PT and distance controls moved out of the tall left rail into a
  single **⚙ Filters** dropdown at the top of the results panel; the rail is
  slimmed to Spot-check → SEARCH → Unfilled Calls. The toolbar reads
  **name → Filters → Ask the GOAT**.
- **Active filters show as removable chips** (e.g. *Group MOPT*, *Min rating 3*,
  *Exclude PT-only*, *≤25km from venue*) with a ✕ each and a **clear all**, plus a
  count badge on the Filters button.
- **Reactive search.** Once results are showing, changing or removing a filter
  re-runs the search automatically, so the list always matches the chips.

---

## How it works

### Exclude-PT (`api_availability` + `index.html`)
`api_availability` reads `exclude_pt`; in the candidate loop, any crew whose
groups contain the literal `pt only` (case-insensitive) is pushed to *skipped*
with a "PT-only (excluded)" reason. The UI sends `exclude_pt` from the dropdown
checkbox. The group itself was created directly in `crew_groups`
(`INSERT … VALUES ('PT Only')`); SmartStaff has no group-admin UI.

### Live group list (`list-groups.php` + `api_groups`)
- **`list-groups.php` (new, read-all gated)** — `goat_can_read_all()` then
  `SELECT id, group_name FROM crew_groups ORDER BY group_name`.
- **`api_groups`** now fetches that endpoint and returns the live names, with the
  old hardcoded list kept as a graceful fallback — so there's no deploy-order
  dependency (works before *or* after `list-groups.php` is live).

### Consolidated toolbar (`index.html`)
`.cf-toolbar` sits at the top of the results panel and is **always visible**
(filters are pre-search criteria). `⚙ Filters` toggles `.cf-dropdown`, which holds
the same `#groups-grid`, `#rating-slider`, `#exclude-pt`, `#geo-enable` /
`#geo-body` elements that were in the sidebar — **same IDs**, so `runSearch()`
posts an identical body. Group selection itself is now the enable (the old
on/off checkbox is gone). `cfRenderChips()` rebuilds the chip row from live
control state; each ✕ / clear-all is handled by one delegated `data-act` listener.

### Reactive re-search (`index.html`)
A single `cfReSearchIfActive()` is attached to the commit points (group toggle,
the chip-removal helpers, exclude-PT, distance on/off, and the rating/radius
sliders). It only fires when results are already shown and a call is selected, is
debounced ~300 ms so rapid changes coalesce into one search, and is wired to the
sliders via `onchange` (release) not `oninput` (drag). Switching the distance
origin between Venue and Postcode stays manual (commits on SEARCH) to avoid
searching against a half-typed postcode.

---

## Files

- `app.py` — `api_availability` (`exclude_pt`), `api_groups` (live fetch +
  fallback).
- `smartstaff/list-groups.php` — **new.** Read-all group list. *(pending deploy)*
- `templates/index.html` — `.cf-toolbar` / `.cf-dropdown` / `.cf-chips` + their
  CSS and `cf*` JS (toolbar, chips, reactive); relocated group/rating/exclude-PT/
  distance controls; removed the old sidebar filter block, in-header name filter,
  `goat-ask-bar`, and the `group-enable` / `toggleGroupFilter` on-off mechanism;
  SEARCH-button spacing fix.

---

## Key decisions / learnings

- **Filters are pre-search, results are post-search** — the toolbar is always
  visible while the stats header stays gated, and the "Within Nkm of X" caption is
  bound to the *executed* search's `origin_label`, not the live chips. Reactive
  re-search keeps the two in sync (it's what fixed the stale-caption case).
- **All control IDs were preserved on the move**, so the `/api/availability` body
  is byte-for-byte unchanged.
- **Reactive needs guards** — fire on discrete commits only, debounce, search on
  slider *release*, never auto-search before the first manual SEARCH.
- **`api_groups` fallback removes deploy-order risk** — the app shows the live
  list once `list-groups.php` is up, and the hardcoded list until then.
- **The Crew Finder roster cache** holds per-crew groups; after assigning a crew
  member to a new group, a roster refresh is needed before the filter sees it.
