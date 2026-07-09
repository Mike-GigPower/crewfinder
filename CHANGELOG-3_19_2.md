# THE GOAT — v3.19.1 -> v3.19.2

Frontend only. No PHP, no backend changes.

## Change

Fix the in-app "Download update" banner link. It used target="_blank", so clicking
it opened a new blank tab and Chrome silently blocked the DMG download. Removing
target="_blank" makes the link download in place, matching the address-bar behaviour
that works.

Note: takes effect only once a user is on 3.19.2+. Updating FROM an older build still
needs the one-time workaround: right-click "Download update" -> "Save Link As...".

## Files

- templates/index.html — remove target="_blank" from #update-link
- app.py — APP_VERSION -> 3.19.2
- CHANGELOG-3_19_2.md
