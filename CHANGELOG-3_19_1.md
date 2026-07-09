# THE GOAT — v3.19.0 -> v3.19.1

Frontend only. No PHP, no backend changes.

## Change

The Google Sheet button on a booking now shows a persistent popup confirming the
sheet was created, with a clickable link to open it. Previously it used a 2.5s
toast plus an auto-opened tab that browsers could silently pop-up-block.

- Success: popup titled "Google Sheet created" with a link to open the sheet.
- Failure: the server error now shows in the same persistent popup.
- Removed the post-await window.open(...) that pop-up blockers silently blocked.

Reuses the existing entity-modal, escapeHtml, and openEntityModal/closeEntityModal
in templates/index.html. No new elements, helpers, or CSS.

## Files

- templates/index.html — replace generateGoogleSheet
- app.py — APP_VERSION -> 3.19.1
- CHANGELOG-3_19_1.md
