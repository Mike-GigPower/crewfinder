# CHANGELOG — v3.14.0 · Live Google Sheets timesheet import

Import a booking's actual times by reading the **live Google Sheet** THE GOAT
generated, instead of exporting it to `.xlsx` and uploading. Tab→call mapping is now
exact (the Call ID stamped in B1), and the `.xlsx` upload stays as the offline
fallback. Generated sheets also move to a configurable Drive folder owned by the
company account. No new SmartStaff endpoints, no schema change — rides on the v3.11.0
PHP + `goat_note` column.

## How it works

- **Generation** (since 3.13) stamps each call tab's Call ID in **B1** (A1 holds the
  label `GOAT Call ID`) and pre-fills confirmed crew. 3.14 now also **saves a
  booking→spreadsheet link** (`timesheet_links.json`, machine-local) when a sheet is
  generated, and tags the file name `Timesheet — <name> [#<bookingId>]`.
- **Live import:** Ops opens a booking → **📥 Import Times → 📊 Import from the Google
  Sheet**. The app finds the sheet (saved link, else Drive name-search on the `[#id]`
  tag), reads it via the Sheets API (`FORMATTED_VALUE` → the displayed `HH:MM`, so no
  cached-value dependency and no CEILING float-dust), maps each tab to its call by the
  **B1 Call ID** (exact), and shows the **same review screen** as the file path. The
  write reuses `/api/call/<b>/<c>/times` (idempotent).
- **Round-trip guard:** a tab whose stamped Call ID isn't a call on this booking is
  flagged (`foreign_tabs`) and never written.
- **Shared core:** `timesheet_common.py` holds the column map + per-cell parsing,
  shared by the `.xlsx` parser and the live reader so the two sources can't drift.
- **Destination folder:** generation now creates the sheet inside
  `gsheet_dest_folder_id` (a My Drive folder or a Shared Drive), so the company
  account owns it rather than an individual.

## Files

| File | Change |
|---|---|
| `timesheet_common.py` | **NEW** — shared column map + cell parsing (single source of truth) |
| `timesheet_gsheet_read.py` | **NEW** — live Sheets reader → same `{tabs, skipped_tabs}` shape as the file parser |
| `timesheet_import.py` | refactored onto the shared core (behaviour unchanged) |
| `timesheet_gsheet.py` | `generate_timesheet_gsheet(… booking_id, dest_folder_id)`; name tag + folder placement |
| `app.py` | link helpers, `_build_import_preview`, `/import-times/preview-live`, `gsheet_dest_folder_id`; `APP_VERSION = 3.14.0` |
| `templates/index.html` | Import chooser (live → file); review shows "matched by Call ID" + the foreign-tab warning |
| `.gitignore` | add `timesheet_links.json` |

## Deployment

- **No new PHP / no schema.** Rides on the v3.11.0 bundle (`get-call-times.php`,
  `update-call-times.php`, `get-bookings-bulk.php`, `get-calls-bulk.php`) + the
  `goat_note` column — **which must be on PROD before this ships**.
- **Build:** add hidden-imports `timesheet_common` and `timesheet_gsheet_read`
  alongside the existing `timesheet_*` modules and the Google libs.
- **Google:** authorise as `gigpower@gmail.com`; set `gsheet_dest_folder_id` and the
  updated `crew_master_template_id` in `config.json`. The OAuth token is per-machine.

## Notes / not yet done

- **Paste-a-URL** import isn't implemented (would need a small `link` route); live +
  file only.
- Break **Day/Night** mapping (Break 1 → Day, Break 2 → Night) still provisional.
- File-path tabs from a generated-then-exported `.xlsx` still map by EIN overlap; they
  could read A1/B1 for exact mapping too — a small follow-on, not in 3.14.0.
