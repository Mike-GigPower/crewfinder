# THE GOAT v3.18.3

**Fixes the "Create Booking → From Estimate" import.**

## What was wrong

Dropping a valid estimate JSON into the Estimate Import would show the loading
animation for a moment and then bounce back to the empty "Drop JSON file here"
screen — no preview, no clear error. It looked like the file wasn't recognised.

## What caused it

A JavaScript function-name collision. The Timesheet Import feature (added in the
3.18 line) introduced a second function with the same name as the Estimate
Import's preview renderer. Because JavaScript only keeps the last function of a
given name, estimate imports were accidentally running the timesheet code, which
crashed on data it didn't have.

The estimate files themselves, the validation rules, and the server were never at
fault — a correct file was being rejected purely by this front-end crash.

## The fix

Renamed the timesheet renderer so the two imports no longer share a name. Estimate
Import now renders the booking preview correctly. Verified end-to-end with a real
schema 1.1 estimate (quote GP-000125), including a "Show Crew" line — all six
labour calls render with no error.

## Notes

- Front-end only — no server, PHP, or database changes.
- No action needed beyond updating the app.

**Full technical details:** see `CHANGELOG-3_18_3.md`.

---

## Release checklist (maintainer)

Commits on `main` (`Mike-GigPower/crewfinder`):

- `771c183` — the fix (rename `renderImportPreview()` → `renderTimesheetImportPreview()`)
- `4da839f` — `APP_VERSION` → 3.18.3 + `CHANGELOG-3_18_3.md`

Still to do:

1. Build + notarize the DMG on the **iMac** (restore `The GOAT.spec` after).
2. Publish the GitHub release tagged **`v3.18.3`**.
3. Flip `version.json` to **3.18.3 last** — this is what triggers the in-app
   update prompt for existing users.
