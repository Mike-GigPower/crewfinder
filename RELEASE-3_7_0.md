# RELEASE — v3.7.0 · Administration tab

_The Administration tab and the Operations-grants-step-up change. One runbook for
the whole feature. Follow the order — PHP to prod **before** the app build,
`version.json` flipped **last**._

## What's in this release

A new admin-only **⚙ Administration** tab (a hub for maintaining GOAT / SmartStaff
records and access):

- **Add Crew Member** (+ optional profile picture)
- **Add Customer**, **Add Venue**, **Add Contact** (Contact has its own customer picker)
- **Manage Cohorts** — assign Operations / Leadership / Crew
- **Access-model change** — anyone in the **Operations** cohort gets the 🔑 Admin
  step-up button; the separate `goat_elevators` allow-list and the Admin Access
  panel are retired (Manage Cohorts is now the single grant point).

Per-commit detail is in `CHANGELOG-administration.md`.

## Files

### SmartStaff PHP — deploy to **production** before the app build
| File | Where | Change |
|---|---|---|
| `crew-lookups.php` | `/ajax/crew/` | **new** — crew-group + next-EIN lookups for Add Crew Member |
| `manage-cohort.php` | `/ajax/crew/` | **new** — list / set cohort (operations/leadership/crew) |
| `whoami.php` | `/ajax/crew/` | **changed** — `can_elevate` now = Operations cohort (was `goat_elevators`) |
| `add-crew.php` | SmartStaff `crew/add` page | **changed** — adds the AJAX id/error return |

> `add-crew.php` is the core SmartStaff crew-add page (not a `/ajax/crew/`
> endpoint), so it deploys to the SmartStaff app, not the `smartstaff/` repo dir.
> The change is backward-compatible — the AJAX branch only fires when `ajax` is posted.

### crewfinder repo — committed source (baked into the binary)
| File | Change |
|---|---|
| `app.py` | new routes (`/api/admin/add-user`, `/api/admin/crew-lookups`, `/api/cohort`); multipart add-user; `APP_VERSION = 3.7.0` |
| `templates/index.html` | the Administration tab + all flows; Admin Access UI removed |
| `smartstaff/crew-lookups.php`, `smartstaff/manage-cohort.php`, `smartstaff/whoami.php` | mirror the deployed endpoints |
| `CHANGELOG-administration.md`, `RELEASE-3_7_0.md`, `THE_GOAT_Project_Knowledge_v3_7_0.md` | docs |

## Ship sequence

**1 — PHP to production (before any build).**
Deploy the four PHP files to prod. Verify each (logged in as admin on prod):
- `…/ajax/crew/crew-lookups.php` → JSON `{crew_groups, next_ein, …}`; logged-out/non-admin → `403 {"error":"Admin only"}`.
- `…/ajax/crew/manage-cohort.php` → JSON `{members:[…]}`; non-admin → 403.
- `…/ajax/crew/whoami.php` → an **Operations** EIN returns `"can_elevate": true`; a plain **Crew** EIN returns `false`.
- `crew/add` with `ajax=1` returns a bare id (the normal form still redirects).

**2 — Verify source.**
`python3 -m py_compile app.py` · `node --check` on the extracted `index.html` scripts ·
PHP brace-balance + no PHP-7 syntax. (All green as built.)

**3 — Commit + push source to `main`** (before building — see commit manifest below).
`git pull --rebase` first; stage per-file.

**4 — Build the DMG.** `./build_dmg.sh` from `~/dev/gigpower` (venv active). Confirm
it strips the `test.smartstaffsolutions.com` base-URL override and bakes prod creds
before packaging. Signs + notarizes automatically.

**5 — Publish the GitHub release** with the DMG asset named exactly `TheGOAT.dmg`.
Confirm HTTP 200 via `get_release_by_tag` (tag `v3.7.0`): asset `state: "uploaded"`,
`draft: false`. (GitHub release downloads 302 by design — use the API, not curl.)

**6 — Flip `version.json` LAST** (after the asset is confirmed live): `version`
`3.7.0`, `dmg_url`, `release_notes`, `release_date`. Flipping early causes
update-polling 404s.

## Smoke test (prod build)
- Administration tab visible to admin, hidden for crew/leadership/operations.
- Add Crew Member with and without a photo (confirm `crewimg_<id>.jpg`); username = EIN, temp password `12345`.
- Add Customer / Venue / Contact create and confirm; duplicate contact username surfaces the in-use message.
- Manage Cohorts: assign Operations to someone → after they re-login they see 🔑 Admin and can step up with a real admin login.

## Rollback notes
- The four PHP changes are additive / backward-compatible; reverting `whoami.php` restores the old `goat_elevators` gate.
- `/api/elevators`, `manage-elevators.php`, and the `goat_elevators` table are now vestigial — safe to leave; decommission later if desired.
