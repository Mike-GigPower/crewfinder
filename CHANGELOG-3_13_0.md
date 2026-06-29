# CHANGELOG — v3.13.0 · Timesheet generation

The inverse of the v3.12.0 import: build a pre-filled crew-master timesheet for a
booking in one click, instead of a Crew Boss hand-typing 100+ crew per call. Two
output paths share one data-gathering core:

- **📊 Google Sheet (online, primary)** — a *native* Google Sheets copy. Perfect
  fidelity: every formula, dropdown, colour rule and validation is preserved,
  with no export warning. Generated in the authorised user's Drive; Ops manages it
  and shares it on to the crew boss.
- **📄 Excel (offline fallback)** — a downloadable `.xlsx` for venues with no
  connectivity. Self-contained, no Google account needed.

Both stamp each call tab with its **Call ID** (A1/B1), so a completed sheet can
round-trip back through the importer by exact lookup (groundwork; the importer
still maps by EIN overlap until that change lands).

## What it does

For a booking, both paths gather each call's **confirmed crew** (call_crew_map
status 5) with **last / first / EIN / phone** — EIN + split names from
get-call-times, phone from the booking roster, joined on user id — then produce one
tab per call (cloned from the template's **Master** tab) with crew pre-filled in
I/J/K/L, the GIG Call Time in J2, and the Call ID in A1/B1.

### Google Sheets path (`timesheet_gsheet.py`)
- **Native** Drive `files.copy` of the crew master + Sheets `duplicateSheet` per
  call — so nothing is re-serialised and nothing is lost.
- **Auth: OAuth as the user**, not a service account. A service account has no
  Drive of its own, so on a personal account `files.copy` fails with a storage-quota
  error; authorising as the user creates the sheet in *their* Drive. One-time
  browser consent is handled by **`gsheet_authorize.py`** (writes `google_token.json`);
  the app then refreshes the token silently — no browser at request time.
- Shares the result with the configured Ops email (a self-share is a harmless no-op).

### Excel path (`timesheet_generate.py`)
- Clones the bundled `crew_master_template.xlsx` Master tab per call via openpyxl.
- openpyxl can't faithfully re-serialise the Google-authored workbook, so generation
  **sanitises** the output to avoid Excel's repair/“unsafe external links” prompts:
  strips conditional formatting, Excel tables, custom-view defined names, and the
  broken `#REF!` validations; rewrites the STATUS (Confirmed/Late/Moved/No Show) and
  DEPT dropdowns as working literal lists. **Trade-off:** the value-driven
  colour-coding is dropped on this path (kept perfectly on the Google Sheets path).

## UI
- **📊 Generate Google Sheet** on the booking dialog action bar (primary).
- All three actions (📄 Excel, 📊 Google Sheet, 📥 Import) in the **All Bookings**
  expanded row — the Excel offline option lives here.
- The booking dialog no longer carries the Excel button (Google Sheet is the headline).

## Files
| File | Change |
|---|---|
| `timesheet_gsheet.py` | **NEW** — native Google Sheets generation (OAuth user creds) |
| `gsheet_authorize.py` | **NEW** — one-time OAuth consent helper (writes `google_token.json`) |
| `timesheet_generate.py` | **NEW** — Excel generation (openpyxl clone + sanitise) |
| `crew_master_template.xlsx` | **NEW** — bundled Excel template (PII-free; Master tab) |
| `app.py` | `_gather_timesheet_calls` helper; `/generate-timesheet` (xlsx) and `/generate-gsheet` (Google) routes; `APP_VERSION = 3.13.0` |
| `templates/index.html` | generate buttons + `generateGoogleSheet` / `generateTimesheet` |
| `requirements.txt` | **NEW DEPS** — `openpyxl`, `google-api-python-client`, `google-auth`, `google-auth-oauthlib` |
| `.gitignore` | `google_oauth_client.json`, `google_token.json`, `google_service_account.json` |

## Config (`config.json`)
```
"crew_master_template_id": "<Google Sheets file id of the crew master>",
"gsheet_share_email":      "<Ops email to share generated sheets with>",
"google_oauth_token_file": "google_token.json"   (optional; this is the default)
```

## Machine-local (never committed)
- `google_oauth_client.json` — the OAuth *Desktop* client downloaded from Google Cloud.
- `google_token.json` — written by `gsheet_authorize.py` after consent.
- The crew master sheet must be readable by the authorising Google account.

## Deployment notes
- **No new PHP this release** — generation reuses get-booking.php + get-call-times.php.
  But those (and the v3.11.0 bundle + the `goat_note` column) **must already be on
  prod**, since both generation and import depend on get-call-times.php.
- **Build:** bundle `crew_master_template.xlsx` like `au_postcodes.json`, and ensure
  the new deps bundle into PyInstaller. The Google libraries can need explicit
  hidden-imports/datas in the spec (`googleapiclient`, `google.auth`,
  `google_auth_oauthlib`, `google.oauth2`) — test a built DMG's Google Sheet
  generation before flipping `version.json`. The Excel path needs `openpyxl`
  (`--hidden-import openpyxl`).

## Not yet / backlog
- **Close the round-trip:** switch the importer to read the stamped Call ID (A1/B1)
  for exact tab→call mapping, EIN-overlap as fallback.
- The Google Sheets STATUS dropdown uses the template's native list (not the Excel
  path's four options) — standardise in the template's DATA LIST if desired.
- Production: move to a Workspace **Shared Drive** so generated sheets are owned by
  Gig Power rather than an individual; tighten the crew master's link sharing.
