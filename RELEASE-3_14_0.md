# RELEASE 3.14.0 — Live Google Sheets timesheet import · test & ship runbook

Feature: import a booking's actual times by reading the **live Google Sheet** THE
GOAT generated (mapped tab→call by the Call ID stamped in B1), with the .xlsx upload
kept as the offline fallback. Trigger: an Operations member clicks Import.

Files in this release:

| File | Change |
|---|---|
| `timesheet_common.py` | **NEW** — shared column map + cell parsing |
| `timesheet_gsheet_read.py` | **NEW** — live Sheets reader |
| `timesheet_import.py` | refactored onto the shared core (behaviour unchanged) |
| `timesheet_gsheet.py` | generation now takes `booking_id`, tags the name `[#id]` |
| `app.py` | link helpers, `_build_import_preview`, `preview-live` route, `APP_VERSION=3.14.0` |
| `templates/index.html` | Import chooser (live → file), review screen tweaks |
| `.gitignore` | add `timesheet_links.json` |
| `CHANGELOG-3_14_0.md` | **NEW** (still to write) |
| `*.spec` / `build_dmg.sh` | add two hidden-imports (Phase 4) |

---

## Phase 0 — Place the files & prep

1. Drop the six code files into `~/dev/gigpower` (repo root is flat; `index.html`
   goes in `templates/`). **Never** edit them from an iCloud path.
2. Add to `.gitignore`:
   ```
   timesheet_links.json
   ```
3. **Point dev at the TEST backend.** Confirm `config.json` targets
   `test.smartstaffsolutions.com` (DB `smartst_test`) — that backend already has the
   v3.11.0 PHP endpoints + the `goat_note` column, so the import write will land.
   (Prod doesn't have them yet — that's the ship-time blocker, Phase 6.)
4. Confirm Google is still authorised on this machine: `google_token.json` present,
   and `config.json` has `crew_master_template_id` + `gsheet_share_email`. (If a token
   prompt ever appears: `source venv/bin/activate; python3 gsheet_authorize.py`.)

## Phase 1 — Static checks (before running)

```
source venv/bin/activate
python3 -m py_compile app.py timesheet_common.py timesheet_import.py timesheet_gsheet.py timesheet_gsheet_read.py
```
Expect no output (clean). For the front end, sanity-check the inline script parses —
open `templates/index.html` in your editor; if you have node handy you can extract the
`<script>` and `node --check` it, but the compile above is the critical gate.

## Phase 2 — Dev functional test (`python3 menubar.py`)

Start the app: `source venv/bin/activate; python3 menubar.py`. Open the web UI.
After any `index.html` change, **restart the app and hard-refresh (Cmd-Shift-R)** —
templates are cached.

Pick a **test booking with confirmed crew across 2+ calls** (11815, Fog Horn Leghorn
Spectacular, is the known-good one).

### Test 1 — Generation tags the name + saves the link
- Open the booking → **📊 Generate Google Sheet** (or the 📊 button in the All
  Bookings expanded row).
- ✅ Feedback says it created the sheet and it opens in your browser.
- ✅ The sheet's **name** is `Timesheet — <booking name> [#<bookingId>]` (the `[#…]`
  tag is the new bit).
- ✅ On each call tab: **A1 = `GOAT Call ID`**, **B1 = the call's numeric id**, crew
  pre-filled in columns I/J/K/L.
- ✅ The link was saved:
  ```
  cat ~/dev/gigpower/timesheet_links.json
  ```
  shows `"<bookingId>": {"spreadsheet_id": "…", "url": "…", "created_at": …}`.

### Test 2 — Live import happy path (the main event)
- In the generated sheet, on **one** call tab, type actual times for 2–3 crew:
  **Start Time** and **Time Off**. For one crew set **STATUS = Late**; for another set
  **STATUS = No Show**. Optionally put a value in **Break 1 Time**. Let Google
  autosave (a couple of seconds).
- Back in THE GOAT: open the booking → **📥 Import Times** → **📊 Import from the
  Google Sheet**.
- ✅ Review screen shows a **📊 Source Google Sheet ↗** link up top.
- ✅ The edited call reads **"matched by Call ID · N to write"** (not "EIN overlap").
- ✅ The crew you timed appear with on–off; the **Late** one shows a red **LATE**
  badge; the **No Show** one is **not** in the write list (it's counted under
  "with no times (no-show / blank)"). A break shows as `brk HH:MM/HH:MM`.
- ✅ Untouched tabs say "Nothing to write for this call."
- Click **Write N times** → ✅ "✓ Wrote times for N crew across 1 call."
- **Verify the write landed:** open that call's **⏱ Enter Times** grid (or check
  SmartStaff). The on/off/late/break/note should match exactly what you typed in the
  sheet.

### Test 3 — Idempotent re-import
- Without changing the sheet, run Test 2's import + write **again**.
- ✅ Same values written cleanly, no error, no duplicates (write is UPDATE by
  call+user).

### Test 4 — Name-search recovery (link file missing)
- Temporarily move the link file aside:
  ```
  mv ~/dev/gigpower/timesheet_links.json ~/dev/gigpower/timesheet_links.bak
  ```
- 📥 Import → 📊 Import from the Google Sheet on the same booking.
- ✅ It still finds the sheet (via Drive search on the `[#<id>]` name tag) and shows
  the same review. This proves the `_find_sheet_by_name` fallback.
- Restore:
  ```
  mv ~/dev/gigpower/timesheet_links.bak ~/dev/gigpower/timesheet_links.json
  ```

### Test 5 — No linked sheet (graceful fallback)
- Pick a **different** booking you have **not** generated a sheet for.
- 📥 Import → 📊 Import from the Google Sheet.
- ✅ Red message: "No generated Google Sheet is linked to this booking on this
  machine. Generate one first, or upload the finished workbook below." — and the file
  picker is still there to fall back to.

### Test 6 — Foreign-tab guard (optional, contrived)
- In a generated sheet, on one tab, change **B1** to a number that is **not** a call
  on this booking (e.g. `999999`).
- Live-import that booking → ✅ review shows **"⚠ Tabs from a different booking — not
  written: <tab> (call 999999)"**, that tab is excluded, the others still map.
- Undo the B1 change.

## Phase 3 — Regression (make sure nothing old broke)

- **R1 — File upload path.** In the completed sheet: File → Download → Microsoft Excel
  (.xlsx). 📥 Import → upload that .xlsx. ✅ Review shows the **"EIN overlap X/Y"**
  label (legacy mapping) and writes correctly. (Proves the refactored
  `timesheet_import.py` + `timesheet_common.py`.)
- **R2 — Excel generation.** 📄 Excel (offline) from the All Bookings row → downloads
  `Timesheet_<name>.xlsx`. (Unchanged path; just confirm it still works.)
- **R3 — Manual times grid.** Open any call's **⏱ Enter Times**, save a row. ✅ Works
  — it's the same write the import uses.

> If all of Phase 2 + Phase 3 are green on `menubar.py`, the feature is functionally
> done. Everything below is packaging.

## Phase 4 — Build the DMG

1. Confirm the version bump is in: `app.py` line 100 reads `APP_VERSION = "3.14.0"`.
2. **Add the two new modules to the build's hidden-imports.** The existing
   `timesheet_import` / `timesheet_generate` / `timesheet_gsheet` and the Google libs
   are already there from 3.12/3.13; add **`timesheet_common`** and
   **`timesheet_gsheet_read`** to the same `hiddenimports` list in the `.spec`
   (or the `--hidden-import` flags in `build_dmg.sh`). No new data files —
   `crew_master_template.xlsx` is already bundled.
3. Build from the repo (never iCloud):
   ```
   cd ~/dev/gigpower
   ./build_dmg.sh
   ```
   Signed with `GOAT_SIGN_ID`, notarized via `GOAT-notary`. The asset must end up
   named exactly **`TheGOAT.dmg`**.

## Phase 5 — Test the INSTALLED DMG (the real proof)

Dev `menubar.py` working does **not** prove the bundle works — the Google libs are
PyInstaller-finicky. Install the freshly built DMG and run the **installed** app:

- Repeat **Test 1** (generate a sheet) → proves the Google libs bundled.
- Repeat **Test 2** (live import + write) → proves `timesheet_gsheet_read` +
  `timesheet_common` bundled.

If either throws `ModuleNotFoundError` / `ImportError`, add the missing module to
`hiddenimports`, rebuild, retest.

## Phase 6 — Ship (only when Phases 2, 3, 5 are all green)

Follow the inviolable order:

1. **Prod PHP prerequisite (the blocker).** 3.14.0 adds **no** new PHP, but it rides
   on the v3.11.0 bundle, which is still only on `smartst_test`. Before any DMG ships,
   deploy to **prod** (`smartstaffsolutions.com`, `/ajax/crew/`) and verify each
   responds:
   - `get-call-times.php`, `update-call-times.php`, `get-bookings-bulk.php`,
     `get-calls-bulk.php`
   - `ALTER TABLE call_crew_map ADD goat_note TEXT NULL;`
2. **Commit source to `main` BEFORE building for release** (building first has caused
   drift). Stage per-file, not `git add .`; `git pull --rebase` on a clean tree first:
   - `timesheet_common.py`, `timesheet_gsheet_read.py`, `timesheet_import.py`,
     `timesheet_gsheet.py`, `app.py`, `templates/index.html`, `.gitignore`,
     `CHANGELOG-3_14_0.md`, and the `.spec`/`build_dmg.sh` if you changed them.
   - **Do NOT commit** `timesheet_links.json` (gitignored) or any `google_*.json`.
3. Build the release DMG from that committed tree (Phase 4) and run Phase 5 on it.
4. Create the GitHub **release** (web UI — `gh` isn't installed), upload `TheGOAT.dmg`.
   Confirm the asset is live before proceeding — via GitHub MCP
   `get_release_by_tag` it should show `state:"uploaded"`, `draft:false`.
5. **Flip `version.json` to `3.14.0` LAST**, after the asset is confirmed. This is the
   switch that tells running clients an update exists.

---

## Quick checklist

- [ ] Files placed in `~/dev/gigpower`; `.gitignore` updated
- [ ] `config.json` points at the **test** backend for Phase 2
- [ ] Phase 1 compile clean
- [ ] T1 generate: name `[#id]`, link saved, A1/B1 stamped
- [ ] T2 live import → "matched by Call ID" → write lands in the grid
- [ ] T3 idempotent re-import
- [ ] T4 name-search recovery with link file moved aside
- [ ] T5 no-linked-sheet message on an un-generated booking
- [ ] T6 foreign-tab guard (optional)
- [ ] R1 .xlsx upload still works ("EIN overlap"); R2 Excel gen; R3 manual grid
- [ ] Phase 4: hidden-imports add `timesheet_common`, `timesheet_gsheet_read`; build
- [ ] Phase 5: installed DMG generate + live import
- [ ] Prod PHP deployed & verified
- [ ] Commit to `main` (per-file), then build, then release `TheGOAT.dmg`
- [ ] Asset confirmed `uploaded` → flip `version.json` to 3.14.0 last
