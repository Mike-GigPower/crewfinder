---
title: Architecture
summary: How THE GOAT runs, how it authenticates, where its state lives, and what it talks to.
tags: architecture, flask, pyinstaller, dmg, port, 5001, cohort, elevation, sudo, config.json, version.json, APP_VERSION, BASE_DIR, pref_key, whoami, require_cohort, update, supabase, anthropic, dock_launcher
order: 10
updated: 2026-09-12
---

# Architecture

THE GOAT is a **Flask application in Python, packaged as a signed macOS app**, that runs entirely on the operator's own machine and talks to SmartStaff on their behalf.

## How it runs

The entry point is `dock_launcher.py`. It starts Flask in a separate process, then turns the main process into a Cocoa application so the app gets a real Dock icon, and opens the UI in the user's default browser.

- **Port 5001, bound to 127.0.0.1 only.** Nothing outside the machine can reach it.
- There is **no embedded browser window.** The UI is `templates/index.html` served to Safari or Chrome. Clicking the Dock icon again opens another tab.
- Quitting from the Dock terminates the Flask child process.

`menubar.py` is an older launcher that put an icon in the menu bar using `rumps`. `dock_launcher` imports two functions from it but never instantiates the menu-bar app, so that path is effectively dead code — `rumps` is still pinned in `requirements.txt`.

The build is PyInstaller `--windowed --onedir`, signed with the Gig Power Developer ID under the hardened runtime, wrapped by `create-dmg`, then notarised and stapled by Apple. `build_dmg.sh` is the single source of truth for what goes into the bundle.

## Authentication

**There is no separate GOAT account.** You sign in with SmartStaff credentials and THE GOAT holds a live SmartStaff session on your behalf, server-side. The browser never talks to SmartStaff directly.

A `uuid4` becomes the session id in the Flask cookie; four server-side dictionaries hang off it — the live `requests.Session`, the identity, the credentials (for silent re-auth), and any stashed pre-elevation state.

Sessions are kept alive by a background worker every eight minutes, and the browser pings every nine. If SmartStaff has expired the session, THE GOAT re-authenticates silently and shows a toast rather than an error.

### Cohorts

Cohort is the single gate for everything. It comes from SmartStaff's `whoami.php` and is resolved server-side.

| Cohort | Meaning |
|---|---|
| `admin` | SmartStaff usergroup 1. Full access. |
| `operations` | Read-only across all-crew views; no Crew Finder, no Create Booking, no writes. |
| `leadership` | Same access as operations in THE GOAT. The Gig Power website maps the two differently. |
| `crew` | Own data only. |

Two rules worth knowing:

- **The `users.cohort` column can grant Leadership or Operations but never Admin.** Admin comes only from `usergroupID == 1`.
- **`require_cohort` is server-side and trusts nothing from the client.** Hiding a tab in `applyCohort` is presentation; the route is the gate.

Identity capture fails **closed** — if `whoami` cannot be read or does not carry a recognised cohort, the session is treated as `crew`.

### Elevation

An Operations user can step up to admin for the session. `can_elevate` in the whoami response is a UI hint and grants nothing; the real gate is authenticating a **separate** SmartStaff session with admin credentials and confirming that session's cohort is `admin`. The original session is stashed and restored on exit. Elevated credentials are never written to disk.

Only admin logins may persist credentials to `config.json`, because those credentials double as the impersonation account.

### `pref_key`

An opaque per-person id for UI preferences, exposed by `/api/whoami`. It is **not the EIN** — a live admin whoami returns EIN `"0"`, so keying on it would put every admin into one shared preferences bucket. It is an unsalted SHA-256 prefix of the user id, unsalted on purpose: the same person must get the same key on every login and every rebuild, forever, or their saved preferences orphan. It is an identifier, not a credential.

## Where state lives

When frozen, `BASE_DIR` is the directory of the executable — `The GOAT.app/Contents/MacOS/`. Everything below lives there, inside the app bundle, not in `~/Library`.

| File | What it is | Ships in the DMG? |
|---|---|---|
| `config.json` | Credentials, Anthropic key, push secret, recruitment key, `base_url` override, feature flags | **Yes** — composed at build time from `config.template.json` plus the gitignored `build_secrets.json` |
| `crew_cache.json` | The roster cache. See the Crew Cache guide | No |
| `venue_cache.json` | Venue geo — postcode, suburb, state, induction flag | No |
| `unavail_times.json` | Hour granularity for unavailability, which the bulk scrape flattens to whole days | No |
| `import_log.json` | Estimator import history; also what blocks a duplicate import | No |
| `timesheet_links.json` | Booking id → the Google Sheet THE GOAT generated. Machine-local, which is why a sheet made on another Mac needs finding by name | No |
| `google_token.json` | Shared Google OAuth token as gigpower@gmail.com, refreshed in place | **Yes** |
| `au_postcodes.json` | Postcode centroids for radius search | **Yes** |
| `estimator_rate_card.json` | Rate card the forward revenue view prices from | **Yes** |
| `crew_master_template.xlsx` | Template the Excel timesheet generator clones | **Yes** |

The bundled files are copied in **after** PyInstaller runs and **before** codesigning — signing seals the bundle, so anything added afterwards breaks the signature.

Note that `templates/`, `static/` and `docs/` are different: they go *inside* the PyInstaller archive via `--add-data`, and are resolved through `app.root_path`, not `BASE_DIR`. The two locations are not interchangeable.

## Updates

`APP_VERSION` is hardcoded in `app.py` and must be bumped by hand. On startup the app fetches `version.json` from the `main` branch of the public `crewfinder` repo on GitHub.

The comparison is a **strict semver tuple greater-than** — a downgrade published to `version.json` will not raise the banner. A network failure means "no update" silently.

The banner links to the DMG URL from `version.json`. **There is no in-place auto-updater**: the user downloads and reinstalls. This is why `version.json` must never be flipped before the release asset is confirmed uploaded — every running install polls it and would be sent to a 404.

## What it talks to

| Service | For |
|---|---|
| **SmartStaff** | The system of record. Identity, roster, bookings, calls, shifts, offers, inductions, licences, venues, customers, unavailability. |
| **Crew Hub** (`crew.gigpower.com`) | Push notifications to crew — offers, promotions, changes, cancellations — and the list of EINs with a live push subscription. All fire-and-forget with a 4-second timeout: a push must never break the offer loop. |
| **Supabase** | Recruitment and onboarding edge functions, plus KeyPay setup, induction sessions and the audit log. Authenticated with a service key that stays in Python and is never seen by the browser. |
| **Anthropic** | ASK THE GOAT, and Crew Finder's natural-language filter. |
| **Google Sheets / Drive** | Generating and reading back timesheet spreadsheets. |
| **GitHub raw** | The update check, and nothing else. |
