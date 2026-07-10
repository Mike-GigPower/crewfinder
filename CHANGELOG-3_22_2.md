# THE GOAT — v3.22.1 -> v3.22.2

Hotfix: the **Schedule booking popup hung on "Loading booking…"**. Adding the
Crew Lists button in 3.22.1 accidentally dropped the line that writes the booking
detail into the dialog body — so the action buttons rendered but the content
never did. Restored.

### The fix
- templates/index.html — `renderBookingDialog()` now sets `entity-modal-body`
  (the booking rows + the `bk-calls-wrap` calls section) before the action row,
  as it did pre-3.22.1. The Crew Lists button is unchanged.
- app.py — APP_VERSION -> 3.22.2.

### Code changes
- templates/index.html, app.py, version.json (last).
