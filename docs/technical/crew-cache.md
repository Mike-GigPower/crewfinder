---
title: The crew cache
summary: What crew_cache.json holds, when it rebuilds, what the badge states mean, and exactly what breaks when it is stale or empty.
tags: cache, crew_cache, crew_cache.json, roster, badge, stale, refresh, push_ok, venue_cache, venue_cache.json, staleness, CACHE_MAX_AGE_HRS, manage_id, list-crew-bulk
order: 30
updated: 2026-09-12
---

# The crew cache

`crew_cache.json` is THE GOAT's local copy of the roster. Crew Finder, Utilization and Inductions all read it rather than hitting SmartStaff per search.

## What it holds

```json
{
  "saved_at": "2026-09-12T09:14:03.221",
  "crew": {
    "<manage_id>": {
      "name": "Lack, Michael", "phone": "...", "user_id": 9734, "ein": "5925",
      "groups": ["Rigging", "..."], "rating": 8,
      "inductions": {"Marvel Stadium": {"status": "...", "completed": "..."}},
      "postcode": "3039", "push_ok": true
    }
  }
}
```

Keyed by `manage_id`. Note that `user_id` falls back to `manage_id` and `ein` falls back through `id` to `manage_id`, so on a degraded fetch all three can collapse to the same value — different readers look up by different ones, and a helper exists precisely because the two are *usually but not always* equal.

`inductions` may legitimately be `[]` rather than `{}` — PHP encodes an empty array as a JSON list, and caches written by older versions still carry it.

## When it rebuilds

Two numbers, easy to conflate:

- **`CACHE_MAX_AGE_HRS = 24`** — the freshness threshold. Past this, the badge says stale.
- **`CACHE_AUTOREFRESH_SECONDS = 900`** — the rebuild cadence, every fifteen minutes.

A healthy install never approaches the threshold. **A cache that is actually stale means refreshes have been failing, or nobody has been signed in, for a day.**

Four triggers: login (for admin, leadership and operations only — crew sessions cannot build it, because the bulk endpoints are not open to them), admin elevation, the fifteen-minute background worker, and clicking the badge.

The background worker grabs any live session and **skips the cycle when nobody is logged in**. The app has to be open with someone signed in for the cache to stay fresh.

The rebuild itself is one HTTP call to `list-crew-bulk.php` — every crew row already carries groups, rating and inductions, so there is no per-crew fetch. It saves incrementally every 50 crew, so a crash mid-rebuild leaves a partial but valid cache. A second rebuild cannot start while one is running.

## The cache never shrinks

```python
new_cache = dict(cache)     # merge into itself
```

**The cache merges into itself on every rebuild and never drops a crew member who has since been deactivated.** Its entries are "everyone we have ever seen active", which drifts further from the truth every month.

This is the single most important thing to know about it. The count on the badge **is not the roster size**, and nothing that needs a true denominator should be built on it — which is why the ops push-gap lane reads all four of its sources live instead.

## The badge

Top right, four states:

| Reads | Means |
|---|---|
| `Roster: 391 (3.2 hours ago)` in green | Healthy |
| `Roster: 391 — no induction data, click to refresh`, **not green** | The crew list came back; the induction records did not |
| `Roster stale (26.1 hours ago) — click to refresh` | Past 24 hours |
| `Refreshing... 137/391 (35%)` | Rebuild in progress |

The middle state exists because of a specific failure: the crew count is a *crew* count, so on an empty-inductions failure it would otherwise read a reassuring green `Roster: 391` while every induction surface reported nothing. The induction-record count is the denominator that makes that visible — **and the state drops the green styling on purpose, because the visual reassurance has to go away, not just the words.**

## Stale versus empty

"Stale" is strictly `age >= 24 hours`, computed from the `saved_at` field rather than the file's modification time. A missing file, a corrupt file and an unparseable timestamp all produce the same result as an empty one.

The design principle is: **staleness is flagged, emptiness is refused.** Stale data is still data, and refusing it would break every surface each time a cache aged past the threshold.

### What actually breaks

**ASK THE GOAT's induction tool refuses to answer.** This is the most carefully guarded path in the app, and the reasoning is worth quoting: *zero problems is the good answer for a compliance tool, so a total read failure and a perfect compliance record produce identical output* — and the model then renders the failure as "you're good to go". The tool tests input volume only, never the output counts, which are legitimately zero on a clean dataset. Its error message is written at the model, not the user: *this is a data-read failure, NOT a clean compliance result — do not report it as one.*

**Utilization hard-fails** with *No crew cache. Run a cache refresh first.* It tests emptiness, not freshness.

**`push_ok` goes unknown.** Push reachability is stamped in its own try/except **only at the end of a full rebuild** — not by the incremental saves. So a rebuild that crashes partway leaves a cache with no fresh `push_ok`. A failed fetch returns `None`, meaning *unknown*, and leaves each entry's existing value alone rather than writing `False`: the Hub returns a non-200 rather than an empty list precisely so a blip cannot be read as "nobody is reachable". Readers must preserve that distinction — absent means unknown and shows no indicator, never "no push".

**Everything else degrades quietly.** Crew Finder, Inductions and the lookups all read the cache and discard the freshness flag. A stale cache shows old ratings, old group memberships and old induction dates **with no warning anywhere except the badge.**

## `venue_cache.json`

A sibling, not a child. Built by the same function on the same cadence, in its own try/except so it can never fail the crew rebuild.

- One call to `list-venues-bulk.php`. **On failure it keeps the existing cache rather than wiping it** — the opposite of the crew cache's merge.
- Keyed by **normalised venue name**, not id: lowercased with whitespace collapsed. The call's venue string is the literal `venues.venue` value, so a normalised exact match is deterministic and no fuzzy matching is needed. Last write wins on duplicate names, which ad-hoc venues do produce.
- **It has no age check at all.** `saved_at` is written but never read, and the file is memoised into a module global for the life of the process. A venue cache written by a previous run is used without any staleness test.
- If the bulk endpoint flags are off in `config.json` it is **never built**, and there is no scraper fallback for venues.

Its job is joining crew home postcodes to venue locations for the radius search, via `au_postcodes.json`. It replaced a hand-maintained venue-postcode table, which is still in the file as a transition fallback — so there are currently two sources of venue location.
