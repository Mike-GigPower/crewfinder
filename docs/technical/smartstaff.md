---
title: SmartStaff integration
summary: How THE GOAT talks to SmartStaff — the session model, the endpoints, the constraints, and how failures surface.
tags: smartstaff, ajax, php, endpoint, session, timeout, TheGOAT, user agent, binary, entities, 502, lock, call_crew_map, is_call_boss, paygradeID, aquire-id, decodeEntities, escapeHtml, base_url, direct-login-guard
order: 20
updated: 2026-09-12
---

# SmartStaff integration

SmartStaff is the system of record. THE GOAT holds an authenticated session against it and proxies everything — **the browser never reaches SmartStaff directly.**

## Base URL

Production is `https://smartstaffsolutions.com`, hardcoded. Setting `base_url` in `config.json` points the app at a staging copy instead.

That value is **read once at import time**, not per request. An empty or missing value leaves production in place, and any error reading the file is swallowed — the app will always come up, and it will always come up on production unless told otherwise.

Whenever the base URL is not production, an **amber TEST strip** is rendered across the top, server-side, naming the server. It is server-rendered rather than fetched on purpose: a strip that appears via JavaScript disappears exactly when something is already wrong. It cannot be dismissed, for the same reason — a strip that can be dismissed will be, and the next screenshot is untrustworthy again.

## The session

A plain `requests.Session`, created at login by posting to `/login` and inferring success from the landing URL rather than from a status code.

### The `(TheGOAT)` User-Agent marker

```python
ss.headers.update({"User-Agent": "Mozilla/5.0 (TheGOAT)"})
```

This is **load-bearing and cross-repo**. SmartStaff runs a direct-login guard that blocks crew (usergroup 3) from signing into the SmartStaff web UI, because they are meant to use Crew Hub. The guard exempts anything whose User-Agent contains `TheGOAT`.

Login follows a redirect to a non-`/ajax/` page, so without this marker a crew or leadership login **through THE GOAT** would be blocked by SmartStaff's own guard.

> Changing this string in `app.py` without changing `direct-login-guard.php` locks out every crew and leadership user. Admins are unaffected, because the guard passes non-crew usergroups anyway — which makes it exactly the kind of breakage that survives an admin's smoke test.

### Timeouts

`get` and `post` are monkey-patched with a **10-second default**. There is also a `ss._default_timeout = 30` with a comment claiming 30 seconds is the default — **that attribute is never read**. The real default is 10. Call sites that need longer pass it explicitly: 30s for the bulk crew and venue fetches, 15s for `my-inductions.php`.

### One request at a time

**`/ajax/crew/` endpoints hold a per-session PHP file lock.** A second concurrent call on the same session does not fail — it hangs. There is no thread pool and no parallel fetch anywhere against a single session, and the ops lanes are deliberately ordered so the slowest one goes last.

The crew cache rebuild does use a ten-worker pool, but only because the bulk endpoint has already put everything in memory and the workers are just iterating a list. The parallelism is vestigial from before the bulk endpoints existed.

### Impersonation

Unavailability is written by impersonating the crew member — `/aquire-id/<uid>`, act, `/release-id`. Note the SmartStaff endpoint is spelled `aquire-id`, and must be called that way.

Impersonation runs on a **dedicated throwaway admin session** so the operator's own session is never re-identified, and is serialised behind a lock.

## The endpoints

Around 88 endpoints under `/ajax/crew/`, plus three under `/ajax/calendar/`. Grouped by what they do:

**Identity** — `whoami.php` (user id, EIN, name, usergroup, cohort, can_elevate), `manage-cohort.php`, `manage-elevators.php`, `manage-direct-login.php`.

**Bulk reads — the performance backbone.** One call each, covering the whole roster or a window: `list-crew-bulk.php`, `list-venues-bulk.php`, `get-calls-bulk.php`, `get-shifts-bulk.php`, `get-bookings-bulk.php`, `get-booked-crew-bulk.php`, `get-unavailabilities-bulk.php`, `get-open-offers-bulk.php`, `get-pending-acks-bulk.php`, `get-induction-exceptions-bulk.php`.

**Bookings and calls** — `create-booking.php`, `update-booking.php`, `get-booking.php`, `update-call.php`, `cancel-call.php`, `uncancel-call.php`, `get-call-responses.php`, `get-call-times.php`, `update-call-times.php`, `accept-call-times.php`, `call-feeds.php`, `call-supervision.php`, `ops-times-outstanding.php`.

**Crew records** — `get-crew.php`, `update-crew.php`, `update-crew-status.php`, `list-groups.php`, `get-crew-shifts.php`, `get-crew-offer-stats.php`.

**Inductions** — `get-induction-catalogue.php`, `save-induction-catalogue.php`, `delete-induction-catalogue.php`, `get-induction-cert.php`, plus the self endpoints `my-inductions.php`, `my-induction-venues.php`, `add-my-induction.php`.

**Licences and compliance** — the licence catalogue pair, `list-licences.php`, the `admin-*-license.php` set, and the triage pair.

**Visa and documents** — `admin-set-visa.php`, `admin-get-visa.php`, the file getters, `list-visa-workers.php`, `admin-get-documents.php`.

**Reference data** — venues, customers and contacts, each with list / get / update / delete.

**Self views** (any cohort, own data) — `my-shifts.php`, `my-call-times.php`, `my-workslips.php`, `get-workslip.php`.

**Calendar** — `add-event.php`, `delete-event.php`, `get-unavailabilities.php`, all via impersonation.

> Several PHP files in the same deploy folder are **not** called by THE GOAT — the SMS and email crons, the workslip crons, and various Crew Hub endpoints. Sharing a directory is not the same as being an integration point.

## Constraints

**PHP 5.6.** `mysql_*` only — no mysqli, no PDO except where explicitly introduced. `array()` not `[]`. No null-coalescing. Tabs for indentation. Reserved words like `default` and `rows` must be backtick-quoted; `rows` in particular is reserved in MariaDB 10.6 and must be aliased.

**Names are stored HTML-encoded.** SmartStaff emits things like `O&#39;Brien`. Python calls `html.unescape`. The frontend must call **`decodeEntities()` then `escapeHtml()`, in that order** — the reverse renders apostrophes as `&#039;` on screen.

**`binary(50)` flag columns.** `call_crew_map.is_call_boss` and `late` are `binary(50)`, where `= 1` does not match in SQL. Test the first byte in PHP instead — correct for tinyint, varchar and binary alike. Never filter on these in SQL.

**`users.paygradeID` has no column default.** It is `int NOT NULL`, so anything not sent is stored as `0` — a crew member with no rate, which the UI then hides by rendering the first option in the dropdown. Every crew member created by the conversion before 9 September 2026 landed that way. New crew are now created on T1 explicitly.

**PHP encodes an empty array as a JSON list.** Crew with no inductions come back as `[]`, not `{}`. Ingestion normalises it, but caches written by older versions still hold `[]`, so readers guard for both.

**Absent is not the same as empty — on licences it is critical.** `licences` rides along only when the endpoint actually ran its licence query. An absent key means *no licence data at all*; an empty list means *this person holds none*. The Crew Finder licence filter refuses to run on the first and treats the second as a real result. Defaulting the key to `[]` would turn a failed query into a silently empty search — the one failure mode a compliance filter must not have.

**Two crew can share a display name.** Anything joining by display name is a latent mis-attribution; joins that matter re-key by user id.

## How failures surface

The fetch helpers return a `(data, error)` tuple and classify four shapes: request failed, non-200, bad JSON, and a JSON body carrying an `error` key.

From there, three routes to the user:

1. **Silent fallback to the legacy scraper.** Where a bulk endpoint has a scraper equivalent, a failure logs and falls back. The user sees nothing. This is what let the bulk endpoints be deployed incrementally.
2. **HTTP 502 carrying the upstream message.** The dominant pattern. The recruitment routes deliberately use three distinguishable messages — service unavailable, service error, bad response — so the panel can tell a transport failure from a rejection.
3. **An interpreted refusal** where the raw error would mislead. `"SmartStaff refused this call — check for a calendar clash"` rather than whatever SmartStaff actually said.

**Session expiry is handled before it becomes an error** — every session fetch validates and silently re-authenticates, and the UI shows *⚠ SmartStaff session expired — reconnecting…* then *✓ Reconnected*.

> **One known bad surface.** An exception escaping as Flask's HTML 500 renders in the ASK THE GOAT drawer as a bare **"Comms issue — 500"** with nothing diagnosable, because the drawer reads an error field from a JSON body that is not there. Any new route should return JSON on its failure paths rather than letting an exception escape.
