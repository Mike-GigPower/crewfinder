# RELEASE — v3.10.0 · Records Management (Venues · Customers · Contacts)

Everything new since the 3.9.0 DMG. This release **adds nine PHP endpoints**, so
PHP deploys to test → prod **before** the build, source is committed before
building, and `version.json` is flipped **last**.

## What's in this release
- **Manage Venues / Customers / Contacts** — three new Administration surfaces
  mirroring Manage Crew: browse list (Active/Inactive toggle, status pills, name
  search) + edit modal. Venue list now uses a real endpoint instead of an HTML
  scrape.
- **Contacts scoped to usergroup 4** (fixes SmartStaff's native page mixing in
  crew). Editable **Customer picker** writes `customer_map` non-destructively.
- **Customer → Associated contacts table** with each contact's mobile (`tel:`)
  and email (`mailto:`), DEFAULT badge, and click-through to the contact editor.
- **ASK-THE-GOAT induction counts** fixed (Expired / Expiring-Soon were always 0).
- **Autofill leak** into form fields (search boxes + edit fields like postcode)
  fixed via `readonly`-until-focus.

Detail: `CHANGELOG-3_10_0.md`.

## Files

### SmartStaff PHP — deploy this release (test → prod **before** the build)
| File | Where | Status |
|---|---|---|
| `list-venues.php` | `/ajax/crew/` | **NEW** |
| `get-venue.php` | `/ajax/crew/` | **NEW** |
| `update-venue.php` | `/ajax/crew/` | **NEW** |
| `list-customers.php` | `/ajax/crew/` | **NEW** |
| `get-customer.php` | `/ajax/crew/` | **NEW** (latest: associated-contacts incl. mobile/email) |
| `update-customer.php` | `/ajax/crew/` | **NEW** |
| `list-contacts.php` | `/ajax/crew/` | **NEW** (usergroup-4 filtered) |
| `get-contact.php` | `/ajax/crew/` | **NEW** |
| `update-contact.php` | `/ajax/crew/` | **NEW** |

> All admin-gated, called with the admin's own session (no service key in the
> DMG). `get-customer.php` here is the newest copy — the one that returns the
> contacts array with `mobile`/`email`. If an earlier `get-customer.php` is
> already on test from development, **overwrite it** with this version.

### crewfinder repo — committed source (baked into the binary)
| File | Change |
|---|---|
| `app.py` | venue/customer/contact list+get+update helpers & routes; venue-list swapped scrape → `list-venues`; ASK-THE-GOAT induction-count fix; `APP_VERSION = 3.10.0` |
| `templates/index.html` | Manage Venues/Customers/Contacts UI; customer picker; associated-contacts table with `tel:`/`mailto:` links; autofill `readonly`-on-focus (search boxes + `efInput`); Add-cards folded into Manage modals |
| `smartstaff/list-venues.php`, `get-venue.php`, `update-venue.php` | source copies |
| `smartstaff/list-customers.php`, `get-customer.php`, `update-customer.php` | source copies |
| `smartstaff/list-contacts.php`, `get-contact.php`, `update-contact.php` | source copies |
| `CHANGELOG-3_10_0.md`, `RELEASE-3_10_0.md` | docs (this commit) |

## Ship sequence

**1 — PHP to test, then production.** Deploy all nine endpoints to
`/ajax/crew/` on `test.smartstaffsolutions.com`, verify the three Manage screens
live locally (dev BASE_URL → test), then deploy the identical files to
`smartstaffsolutions.com`. Sanity checks for an admin session:
- `…/ajax/crew/list-venues.php` → `{"venues":[…]}`
- `…/ajax/crew/list-customers.php` → `{"customers":[…]}`
- `…/ajax/crew/list-contacts.php` → `{"contacts":[…]}` (usergroup-4 only — a
  known crew/admin id through `get-contact.php?id=<adminId>` must 403)

**2 — Verify source.** `python3 -m py_compile app.py` · `node --check` on the
extracted `index.html` scripts. Confirm `APP_VERSION` reads `3.10.0`. PHP:
brace-balance + forbidden-syntax pass (no `??`, no short `[]`, `mysql_*` only,
`default` backticked).

**3 — Commit + push source to `main`** (before building). `git pull --rebase`
first; stage per-file; `git diff --cached --stat` before committing.

**4 — Build the DMG.** `./build_dmg.sh` from `~/dev/gigpower` (venv active).
Confirm it strips the `test.smartstaffsolutions.com` override and bakes prod
creds before packaging. Signs + notarizes automatically. Build from a clean tree.

**5 — Publish the GitHub release** tagged `v3.10.0`, asset named exactly
`TheGOAT.dmg`. Confirm via `get_release_by_tag` (`state: "uploaded"`,
`draft: false`) — release downloads 302 by design, so use the API, not curl.

**6 — Flip `version.json` LAST** (only after the asset is confirmed live):
`version 3.10.0`, new `dmg_url`, `release_notes`, `release_date`. Flipping early
causes update-polling 404s.

## Smoke test (prod build)
- **Venues:** open a venue, edit suburb/postcode, Save — persists; the
  Active/Inactive toggle re-lists; postcode keeps a leading zero if present.
- **Customers:** open a customer; the **Associated contacts** table renders;
  for **Looney Tunes** (id 1618) Elmer Fudd shows with mobile (`tel:`) + email
  (`mailto:`); tapping the number dials without opening the editor; clicking the
  row opens the contact editor.
- **Contacts:** search a crew surname → no crew appears (usergroup-4 only); open
  a contact, change its **Customer**, Save → it appears under that customer with
  the **DEFAULT** badge, and the contact editor now pre-selects that customer.
- **Autofill:** open any edit modal — empty fields (e.g. postcode) come up blank,
  not pre-filled with "admin"; the search boxes open empty.
- **ASK-THE-GOAT:** ask for induction status — Expired / Expiring-Soon counts are
  non-zero where expected (no longer structurally 0).

## Rollback notes
Source-side rollback is the previous DMG + reverting `version.json`. All nine new
endpoints are additive reads/writes on existing tables; leaving them on the
server is harmless if you roll the binary back. No schema migrations in this
release.
