# RELEASE — v3.8.0 · Manage Crew, Crew Finder filters, on-behalf Inductions

_Everything new since the 3.7.1 DMG, in one release. This build is **source-only**
— all the PHP it relies on is already on production. Follow the order;
`version.json` is flipped **last**._

## What's in this release

Three features, all admin-facing in THE GOAT:

- **Manage Crew** — open any crew member from the Manage Crew list and edit their
  record: name, contact, DOB (Melbourne-correct), address, rating, active flag,
  group membership, and a password reset.
- **Crew Finder filter overhaul** — filters moved into a single **⚙ Filters**
  toolbar above the results, removable filter chips, a slimmed sidebar, the
  public-transport exclusion filter, the live (DB-driven) group list, and
  **reactive search** (changing/removing a filter re-runs the search).
- **Crew Inductions (on-behalf)** — from Manage Crew → **Inductions**, view a
  crew member's full induction status and upload a certificate on their behalf
  (one PDF fans across the ticked venues, e.g. Melbourne Park).

Per-feature detail: `CHANGELOG-manage-crew.md`, `CHANGELOG-crew-finder-filters.md`,
`CHANGELOG-crew-inductions-goat.md`.

## Files

### SmartStaff PHP — already on production (no deploy in this release)
| File | Where | Status |
|---|---|---|
| `get-crew.php`, `update-crew.php` | `/ajax/crew/` | deployed (Manage Crew) |
| `list-groups.php` | `/ajax/crew/` | deployed test + prod (live group list) |
| `my-induction-venues.php`, `add-my-induction.php` | `/ajax/crew/` | already live (reused by inductions) |

> Inductions add **no** PHP — THE GOAT drives the existing crew-portal endpoints
> through admin impersonation.

### crewfinder repo — committed source (baked into the binary)
| File | Change |
|---|---|
| `app.py` | Manage Crew routes, Crew Finder `exclude_pt` + live `api_groups`, induction read/upload routes; `APP_VERSION = 3.8.0` |
| `templates/index.html` | Manage Crew modal, filter toolbar + chips + reactive, Inductions view |
| `CHANGELOG-crew-inductions-goat.md`, `RELEASE-3_8_0.md` | docs (this commit) |

> The Manage Crew + filter source and their PHP mirrors were already committed
> (`952e436`); this release adds the induction source, the `APP_VERSION` bump and
> these docs, then packages the lot.

## Ship sequence

**1 — PHP to production.** Nothing to do — all endpoints already live. (Optional
sanity check: `…/ajax/crew/list-groups.php` returns the group list for an admin.)

**2 — Verify source.** `python3 -m py_compile app.py` · `node --check` on the
extracted `index.html` scripts. (Green as built.)

**3 — Commit + push source to `main`** (before building). `git pull --rebase`
first; stage per-file (see commands below).

**4 — Build the DMG.** `./build_dmg.sh` from `~/dev/gigpower` (venv active).
Confirm it strips the `test.smartstaffsolutions.com` override and bakes prod
creds before packaging. Signs + notarizes automatically.

**5 — Publish the GitHub release** tagged `v3.8.0`, asset named exactly
`TheGOAT.dmg`. Confirm HTTP 200 via `get_release_by_tag` (`state: "uploaded"`,
`draft: false`) — GitHub release downloads 302 by design, so use the API, not curl.

**6 — Flip `version.json` LAST** (only after the asset is confirmed live):
`version 3.8.0`, new `dmg_url`, `release_notes`, `release_date`. Flipping early
causes update-polling 404s.

## Smoke test (prod build)
- **Manage Crew:** open a member, edit a field + save; DOB shows the right day;
  rename with an accent/apostrophe; toggle a group.
- **Crew Finder:** Filters dropdown + chips; remove a chip and watch the results
  re-run; exclude-PT works; the group list shows Own Car / PT Only.
- **Inductions:** open a member → Inductions loads their status; upload a PDF to
  one venue (flips to Complete); a Melbourne Park multi-select fans one cert
  across the arenas.

## Rollback notes
- Source-only release; reverting to the `v3.7.1` build restores prior behaviour.
- No DB or PHP changes were made in this release, so there is nothing to roll back
  server-side.
