# THE GOAT — v3.18.2 → v3.18.3

Bug-fix release. The **Create Booking → From Estimate** import was silently
failing: dropping a valid estimate JSON showed the loading goat for a moment,
then bounced straight back to the empty "Drop JSON file here" state with no
preview and no obvious error — the estimate looked "not recognised". Frontend
only; no PHP, no backend changes.

## The bug — a function-name collision

The Estimate Import and the (more recently added) Timesheet Import each defined a
**global function named `renderImportPreview`** in `templates/index.html`:

- `renderImportPreview(data)` — renders the estimate booking preview.
- `renderImportPreview()` — renders the timesheet preview from the global
  `_tiState`.

JavaScript keeps only the **last** declaration of a duplicated function name, so
the timesheet version silently overwrote the estimate one. Dropping an estimate
therefore ran the *timesheet* renderer, which read `_tiState.data` — but
`_tiState` is `null` unless a timesheet has been loaded — and threw
`TypeError: Cannot read properties of null (reading 'data')`.

`handleImportFile` caught that error, surfaced it as a misleading "Error
communicating with server…" message, and reset the panel to idle — which is why
it read as a silent, serverless failure even though the server had returned a
perfectly valid `200` with full preview data.

The estimate file, the `/api/import/validate` endpoint, and `validate_payload`
(which already accepts `schema_version` `1.0`/`1.1` and does **not** whitelist
`crew_type`) were never at fault.

## The fix

Rename the timesheet renderer and its three callers so the two features no longer
share a global name:

- `function renderImportPreview()` → `function renderTimesheetImportPreview()`
- callers in `tiLoadLive`, `tiHandleFile`, and `tiToggleCall` updated to match.

The estimate `renderImportPreview(data)` is now the only function of that name and
resolves correctly. Verified end-to-end against `GP-000125` (schema 1.1, incl. a
`Show Crew` line): the preview now renders all six labour calls with no error.

## Files

- `templates/index.html` — rename timesheet `renderImportPreview()` →
  `renderTimesheetImportPreview()` (definition + 3 callers)
- `app.py` — `APP_VERSION` → 3.18.3
- `CHANGELOG-3_18_3.md`

## Deployment (frontend only — simple order)

1. Stage `templates/index.html`, `app.py`, and this changelog individually; keep
   untracked docs out.
2. `git pull --rebase`, push to `main`.
3. Build + notarize the DMG on the **iMac**; restore `The GOAT.spec` after.
4. Publish release `v3.18.3`; verify via `get_release_by_tag`.
5. Flip `version.json` to **3.18.3 last**.

No PHP endpoints and no secrets change this release.
