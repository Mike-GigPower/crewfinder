# CHANGELOG — v3.12.0 · Timesheet import

Drop an event's completed timesheet workbook on a booking and write every call's
actual times in one pass, instead of re-keying them by hand. Builds entirely on
the v3.11.0 post-show times work — no new SmartStaff endpoints, no schema change.
One new Python module (`timesheet_import.py`) and one new dependency (`openpyxl`);
the rest is `app.py` + `templates/index.html`.

## How it works

A Crew Boss's timesheet is one `.xlsx` workbook per event: each call is its own
tab (`Thu 1000`, `LOAD OUT`, …) plus support tabs we ignore (`DATA LIST`,
`Schedule`, …). Every call tab is keyed by **EIN** (column K), which matches
`users.ein` exactly — so crew match without any fuzzy name logic.

The flow: **All Bookings → expand a booking → 📥 Import times from timesheet →**
pick the file. THE GOAT parses it, shows a per-call review, and writes only what
you confirm.

- **Parsing** (`timesheet_import.py`) reads the workbook's cached computed values
  (Google formulas don't survive the xlsx export, but their last-computed values
  do). On/Off come from the sheet's gated/rounded `Start Time` / `Time Off`
  columns; the CEILING rounding leaves sub-second float dust (`22:59:59.712` =
  23:00), so clock times are **rounded to the nearest minute**. Break 1 → Day
  Break, Break 2 → Night Break. `STATUS` drives the late flag (`Late` → `late=1`)
  and skips no-shows; `NOTES` → `goat_note`. Header columns are matched by label,
  so a small column shift between events won't break it.

- **Tab → call mapping** is by **EIN overlap**, not time. Several calls can share
  a start time (a Fork call and a Load Out call both at 22:30), so time alone maps
  the wrong one; instead each tab maps to the call whose roster shares the most
  EINs with it (time as a tie-break). This also resolves renamed calls for free —
  the boss's `Thu 1000` tab finds SmartStaff's `LX/SX/VX` call by its crew.

- **The preview** (`/api/booking/<id>/import-times/preview`, admin-only,
  read-only) returns per call: **matched** rows ready to write, **unmatched** (a
  sheet EIN not booked on that call), **skipped** (no-show / no times), and
  **roster_only** (booked but absent from the sheet) — plus an EIN-overlap
  confidence per mapping. It resolves each crew member's paygrade (existing
  `callpaygradeID`, else their default) into the matched rows so the write
  populates the rate columns exactly like the manual grid — no $0-payroll trap
  from a times-only write.

- **The write** reuses the proven `update-call-times.php` path: each included call
  posts to `/api/call/<b>/<c>/times`. Idempotent (UPDATE by call+user), so
  re-importing the same sheet is safe.

## UI

- **📥 Import times from timesheet** in the expanded booking row in All Bookings
  (the path for finished events), and on the booking dialog's action bar.
- A review screen with one card per call: the tab→call mapping with its overlap
  badge, the crew/times to be written (late flagged), and counts of
  unmatched / skipped / booked-not-in-sheet. An include checkbox per call (calls
  with nothing to write are auto-unchecked), then **Write N times**.

## Files
| File | Change |
|---|---|
| `timesheet_import.py` | **NEW** — workbook parser (EIN-keyed, value-cached, minute-rounded) |
| `requirements.txt` | **NEW DEP** — `openpyxl` (lazy-imported; needs bundling at build) |
| `app.py` | `import-times/preview` route + `_call_sched_dt` mapping; `APP_VERSION = 3.12.0` |
| `templates/index.html` | import module (upload → review → write); entry points on the booking dialog and the All Bookings expanded row |

## Deployment
No SmartStaff endpoints or schema this release — it rides on the v3.11.0 PHP
(which must already be on prod). Otherwise the usual order:

1. `python3 -m pip install openpyxl` in the venv; add `openpyxl` to
   `requirements.txt`.
2. Commit `app.py` + `templates/index.html` + `timesheet_import.py` +
   `requirements.txt` + this changelog to `main`.
3. Build the DMG — **confirm `openpyxl` bundles** (PyInstaller usually auto-detects
   it; add `--hidden-import openpyxl` to the spec if the build warns). Publish the
   release (asset `TheGOAT.dmg`, `state:"uploaded"`).
4. Flip `version.json` to `3.12.0` last.

## Notes / not yet exercised
- **No Show** rows skip cleanly in logic but weren't seen on real test data (the
  two sample events had only Confirmed / Late / Declined).
- Day-vs-night break assignment is provisional (Break 1 → Day, Break 2 → Night)
  pending clarification of how the sheet decides which is which.
- Per-call timesheet **generation** (the inverse — emit a pre-filled EIN-keyed
  sheet) is parked as a low-value nicety, since the boss's sheet already carries
  EIN.
