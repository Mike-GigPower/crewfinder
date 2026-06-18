# THE GOAT — v3.6.4

Crew status is now editable straight from the call dialog, the Crew Finder's
Add / Add & Confirm / Send SMS buttons no longer pop open a SmartStaff page, and
crew names with apostrophes render correctly.

## New — edit crew status from the call dialog (admin)

The booked-crew roster in the call dialog now shows each crew member's status as
an editable, colour-coded dropdown for admins (leadership/operations keep the
read-only badge). Changing it writes straight to SmartStaff and updates the
"x booked / y required" count in place — no page reload, no leaving the app.

All five SmartStaff statuses are settable: Confirmed (5), Pending (1),
Declined (6), No-show (8), Unconfirmed (0). The change is **silent** — no SMS is
ever sent (unlike SmartStaff's own confirm flow, which can text the crew member).

**Calendar parity with SmartStaff.** The write replicates `add-call.php`'s native
handlers exactly:

- **Confirm (5)** re-syncs the crew member's calendar via SmartStaff's own
  `$sss->addToCalendar` — byte-identical to a native confirm.
- **Decline / No-show / Pending / Unconfirmed** deliberately leave any existing
  calendar entry in place. This matches native SmartStaff (only the separate
  "remove" action ever deletes a calendar row) and is the desired behaviour: a
  declined entry at a given time tells the operator the resource has said no
  then, so not to re-offer them a clashing call. THE GOAT's conflict detection
  is confirmed-only, so a retained entry never produces a false clash.

The select only appears when the crew row has a resolved user id (the
`get-booking.php` path); if a dialog ever falls back to the name-only scrape, those
rows stay read-only badges.

## New — update-crew-status.php

A new admin-only write endpoint (`/ajax/crew/`), modelled on `update-call.php`'s
boilerplate. Body `{userID, status}` with `?id=<callID>`:

- Validates the call exists and that the crew member is actually assigned to it
  (we UPDATE, never INSERT — an absent row would be a silent no-op).
- Writes `call_crew_map.status`, gating success on `mysql_error()` rather than
  `affected_rows` (a re-save of the same status changes 0 rows but is not a
  failure), capturing `affected_rows` before the calendar call.
- On status 5 only, calls `$sss->addToCalendar`; never on the others.
- Status whitelisted to {0,1,5,6,8} — the endpoint owns the authoritative set.

PHP 5.x throughout (no `??`, no short arrays, `send_status` not
`http_response_code`).

## Fixed — crew names with apostrophes

Names like "Daniel O'Brien" were rendering as `Daniel O&#39;Brien` in the call
dialog: the `users` row stores the name pre-encoded, and `escapeHtml` then
double-encoded the ampersand. A new `decodeEntities` helper decodes the source
before `escapeHtml` re-encodes once, so the apostrophe (and any other entity)
displays correctly. The decode is idempotent for already-clean names.

## Changed — Crew Finder add / confirm / SMS no longer open SmartStaff

Add, Add & Confirm, and Send SMS in the Crew Finder previously drove
`add-call.php` through a browser popup and then navigated that popup to the
call's SmartStaff callsheet — an unnecessary page that also depended on the
browser having a live SmartStaff session (and tripped the popup blocker).

They now go through the existing server endpoints `/api/goat/add-crew` and
`/api/goat/send-sms` (the same ones ASK THE GOAT uses), which perform the adds and
SMS through THE GOAT's own SmartStaff session. No popup, no callsheet page, no
"allow popups" prompt. The buttons report actual server outcomes
("Done - N added" / "SMS sent (N calls, M crew)"). Behaviour is otherwise
unchanged.

## Code changes

- **`update-crew-status.php`** — new admin-only write endpoint (above).
- **`app.py`** — `ss_update_crew_status` wrapper (POST with `?id=`); `POST
  /api/call/<booking_id>/<call_id>/crew/<user_id>/status` route,
  `@require_cohort("admin")`. `APP_VERSION` → 3.6.4.
- **`templates/index.html`** —
  - `decodeEntities` helper; crew name decoded before escaping.
  - Crew status editing block (`CREW_STATUS_OPTS`, `crewStatusInt`,
    `crewStatusColors`, `crewStatusControl`, `changeCrewStatus`,
    `refreshCallCrewSummary`); call dialog roster uses the editable control for
    admins; the confirmed-count span got an id for in-place refresh.
  - `addSelected` / `sendSMS` rewritten to call the server add-crew / send-sms
    endpoints instead of the browser popup.

## Files

- `update-crew-status.php` (new SmartStaff endpoint, `/ajax/crew/`).
- `app.py` (above).
- `templates/index.html` (above).

## Deployment

`update-crew-status.php` must be on **production** before the app release. No new
app.py routes are required beyond the crew-status route — the Crew Finder change
reuses the existing `/api/goat/add-crew` and `/api/goat/send-sms` endpoints.

Sequence: deploy `update-crew-status.php` to test → prod (confirm 403
`{"error":"Admin only"}` unauthenticated on both) → bump `APP_VERSION` to 3.6.4,
apply the `app.py` + `index.html` changes + this changelog → `py_compile` /
`node --check` → commit and push to `main` **before** building → build the DMG
from the clean tree → publish the GitHub release (asset `TheGOAT.dmg`, confirm
HTTP 200) → smoke-test (change a crew status and confirm it persists + the count
moves; check an apostrophe name; confirm Add / Add & Confirm / Send SMS no longer
open a SmartStaff page) → flip `version.json` to 3.6.4 **last**.
