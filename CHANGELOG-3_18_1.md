# THE GOAT — v3.18.1

Backup crew, **Phase 2 of 3** — *enforce + promote*. Completes the write side of
the Backup feature: the PWA can no longer over-fill a call, admins can promote a
backup into a gap in one click, and the call dialog's status control now
understands Backup. Also fixes a pre-existing bug in the live backup SMS text.

Phase 1 (v3.18.0) made Backup *visible*. Phase 2 makes it *work*.

## The three slices

Phase 2 shipped as three independent, separately-tested slices. Two were
**server-side PHP only** (deployed to test → prod and committed, with no app
build or version bump); this **v3.18.1 build** carries the third (the promote
UI) plus its paired endpoint change. All three are recorded here for the
complete Phase 2 picture.

### Slice C — backup/confirm SMS reply codes (server-side, already live)

`sms-cron.php` built its `{confirm}` / `{cancel}` reply codes from `$callID`, a
variable **never assigned** in that file — so the codes went out as bare `y` /
`n` with no call number, and a crew member replying to them couldn't be matched.
Fixed to use `$callInfo->callID` (the value the file already uses elsewhere).
Affects both the confirmation SMS and the "too full / backup" SMS a backed-up
crew member receives.

- `smartstaff/sms-cron.php` — four `$callID` → `$callInfo->callID`. **No app change.**

### Slice A — capacity enforcement on the PWA path (server-side, already live)

`respond-to-call.php` (the Crew Hub PWA confirm/decline endpoint) had **no
capacity check** — so someone tapping Confirm in the app could over-fill a call
that was already full, inconsistent with the SMS path. It now counts confirmed
(status 5) against `required` and, if full, writes **Backup (7)** instead of
Confirmed (5) — with no calendar row — mirroring `sms-cron.php` exactly.

- **Linked calls**: answered as one unit, so **all-or-nothing** — if ANY call in
  a linked group is full, the whole response becomes Backup, keeping the group
  on a single shared status.
- **Response**: `status` is preserved (what the crew requested) for
  back-compat; two additive fields — `result_status` (5/6/7, what was actually
  written) and `backup` (bool) — are the hook for Phase 3's PWA messaging. The
  current PWA ignores the new fields, so nothing breaks; a crew member who lands
  on backup simply has the offer drop off their list until Phase 3 adds the
  "you're on standby" message.
- `smartstaff/respond-to-call.php`. **No app change.**

### Slice B — promote + smarter dialog control (THIS build)

The operational payoff: turning a backup into a confirmed booking when a gap
opens.

- **Promote button** — a green **↑ Promote** appears next to a Backup (status 7)
  crew member in the call dialog, admin-only. One click confirms them (status 5),
  which fires `addToCalendar` (a real booking), then re-renders the dialog so
  they show Confirmed and the button drops away. Reuses the exact endpoint and
  reload pattern as the existing crew Remove (✕) control.
- **Smarter status dropdown** — Backup is now a proper option (after Confirmed),
  a backup person's dropdown shows **Backup** in teal instead of the misleading
  "Unconfirmed", and admins can set Backup by hand.
- **Endpoint** — `update-crew-status.php`'s allow-list gains `7`
  (was `0,1,5,6,8`). Setting 7 writes the status but does **not** add a calendar
  row (only status 5 does), so a manually-set backup behaves correctly.
- `smartstaff/update-crew-status.php` (**PHP**) + `templates/index.html`.

## Known issues from v3.18.0 — now resolved

- ~~The PWA path can still over-fill a full call~~ → fixed in slice A.
- ~~The call dialog dropdown shows a backup as "Unconfirmed" and blind-saving
  demotes them~~ → fixed in slice B (dropdown now shows Backup in teal).
- ~~`sms-cron.php` sends the backup SMS with incomplete reply codes~~ → fixed in
  slice C.

## On the horizon — Phase 3 (Crew Hub PWA)

- **"You're on standby" messaging** in the Crew Hub PWA, reading the new
  `backup` / `result_status` fields from `respond-to-call.php`, so a crew member
  who accepts a full call sees a clear standby state instead of the offer
  silently vanishing.
- Optionally, a **push notification when a backup is promoted**, so they know
  they've been pulled in.

## Files

- `smartstaff/update-crew-status.php` — allow-list gains `7` (**PHP**)
- `templates/index.html` — promote button + Backup-aware status dropdown
- `app.py` — `APP_VERSION` → 3.18.1 (no logic change this build)
- `CHANGELOG-3_18_1.md`
- *(already committed + deployed as server-side PHP since 3.18.0, no app build:
  `smartstaff/sms-cron.php`, `smartstaff/respond-to-call.php`)*

## Deployment (PHP this release — full order applies)

1. Deploy **`update-crew-status.php`** to **test → prod** (cPanel).
2. On **test**, verify slice B before building: open a call that has a backup on
   it → the green **↑ Promote** button shows next to them; clicking it flips them
   to Confirmed with a calendar row. Confirm the dropdown shows **Backup** (teal)
   for a backup person, not "Unconfirmed".
3. Stage `smartstaff/update-crew-status.php`, `templates/index.html`, `app.py`,
   and this changelog **individually** (never `git add .`); keep untracked
   docs/tools out.
4. `git pull --rebase`, then push to `main`.
5. Build + notarize the DMG on the **iMac** from `~/dev/gigpower`
   (`./build_dmg.sh`, asset named exactly `TheGOAT.dmg`). Restore
   `The GOAT.spec` after.
6. Publish the GitHub release `v3.18.1`; confirm via `get_release_by_tag`
   (`state: "uploaded"`, `draft: false`, `prerelease: false`).
7. Flip `version.json` to **3.18.1 last**.
