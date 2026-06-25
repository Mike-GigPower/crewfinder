# RELEASE — v3.9.0 · Edit Crew Member tabs + Calls history

Everything new since the 3.8.0 DMG. Unlike 3.8.0, this release **adds one PHP
endpoint** (`get-crew-shifts.php`), so PHP deploys to test → prod **before** the
build. `version.json` is flipped **last**.

## What's in this release
- **Edit Crew Member → four tabs** (Details · Inductions · Calls ·
  Unavailability), with the crew member's name in the modal header and
  Cancel / Save changes pinned at the bottom.
- **Calls tab (new)** — every call ever assigned, newest-first, with status
  pills (Confirmed / Declined / Offered) and a CALL BOSS badge. Tab is labelled
  **Calls**; the endpoint/route keep the `shifts` name internally. Backed by the
  new `get-crew-shifts.php`.
- **"All day"** checkbox on both unavailability add forms (admin dialog + crew
  My Status).

Detail: `CHANGELOG-3_9_0.md`.

## Files

### SmartStaff PHP — deploy this release
| File | Where | Status |
|---|---|---|
| `get-crew-shifts.php` | `/ajax/crew/` | **NEW — deploy test → prod before build** |

> Admin-gated, `?id=<userID>`, reads `call_crew_map` + `calls`/`bookings`/`venues`.
> No service key — called with the admin's own session, like `get-crew.php`.

### crewfinder repo — committed source (baked into the binary)
| File | Change |
|---|---|
| `app.py` | `ss_get_crew_shifts` helper + `/api/admin/crew/<id>/shifts` route; `APP_VERSION = 3.9.0` |
| `templates/index.html` | tabbed Edit Crew Member, Calls tab, embedded Unavailability tab, All-day toggles |
| `smartstaff/get-crew-shifts.php` | source copy of the new endpoint |
| `CHANGELOG-3_9_0.md`, `RELEASE-3_9_0.md` | docs (this commit) |

## Ship sequence

**1 — PHP to test, then production.** Deploy `get-crew-shifts.php` to
`/ajax/crew/` on `test.smartstaffsolutions.com`, verify the Calls tab live
locally (dev BASE_URL points at test), then deploy the identical file to
`smartstaffsolutions.com`. Sanity check: `…/ajax/crew/get-crew-shifts.php?id=9734`
returns a `{"shifts":[…]}` JSON for an admin session.

**2 — Verify source.** `python3 -m py_compile app.py` · `node --check` on the
extracted `index.html` scripts. (Green as built; `APP_VERSION` already 3.9.0.)

**3 — Commit + push source to `main`** (before building). `git pull --rebase`
first; stage per-file (see commands below).

**4 — Build the DMG.** `./build_dmg.sh` from `~/dev/gigpower` (venv active).
Confirm it strips the `test.smartstaffsolutions.com` override and bakes prod
creds before packaging. Signs + notarizes automatically.

**5 — Publish the GitHub release** tagged `v3.9.0`, asset named exactly
`TheGOAT.dmg`. Confirm via `get_release_by_tag` (`state: "uploaded"`,
`draft: false`) — release downloads 302 by design, so use the API, not curl.

**6 — Flip `version.json` LAST** (only after the asset is confirmed live):
`version 3.9.0`, new `dmg_url`, `release_notes`, `release_date`. Flipping early
causes update-polling 404s.

## Smoke test (prod build)
- **Tabs:** open a crew member — header shows "Lastname, Firstname · EIN · ID";
  the four tabs switch; edit a Details field, switch to Calls and back, Save —
  the edit persisted.
- **Calls:** newest-first; a confirmed call is green, a declined one red, an
  offered one amber; status counts in the summary line look right; a call-boss
  call shows the badge.
- **Inductions:** still loads in-tab; an upload still flips a venue to Complete.
- **Unavailability:** tab lists periods; All-day add lands as a full day; Remove
  works — and the separate Crew Utilization dialog still works independently.

## Rollback notes
Source-side rollback is the previous DMG + reverting `version.json`. The new
`get-crew-shifts.php` is purely additive (a new read endpoint); leaving it on the
server is harmless if you roll the binary back.
