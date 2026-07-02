# THE GOAT — v3.17.2

Push offer, Phase 2.1: the offer push now forwards the shift's **end time**, so
the crew-facing push shows the estimated duration in line with the SmartStaff
SMS. Builds on Phase 2 (which added `venue` and `start_dt`).

The push changes from:

> New shift offer
> Load Out · Fri 3 Jul 06:00 · Pro Stage Factory

to:

> New shift offer
> Load Out · Fri 3 Jul 06:00 · Est.4hrs · Pro Stage Factory

## The change (payload widening only)

`end_dt` was already on each availability target — it was simply being dropped
from the offer payload. Phase 2.1 stops dropping it and lets the portal compute
the duration from start and end.

- **`templates/index.html`** — both offer payload builders (`addSelected()` for
  Add / Add & Confirm, and `sendSMS()` for Send SMS) now forward `end_dt` in the
  `calls: targets.map(...)` object, immediately after `start_dt`:
  `start_dt:t.start_dt||'',end_dt:t.end_dt||''`.
- **`app.py`** — `gp_notify_offer` posts it to the portal as `end`
  (`"end": call.get("end_dt", "")`), immediately after the `"start"` line —
  renaming `end_dt`→`end` the same way it renames `start_dt`→`start`.

## The field-name contract (do not rename)

- Frontend sends `end_dt` on each `calls` object.
- `gp_notify_offer` reads `call.get("end_dt")` and posts it as `end`.
- The portal (`/api/push/offer`) reads `end`, pairs it with `start`, computes
  `Est.4hrs`.

Rename either side and the push silently loses its duration (it still shows time
and venue — fails quietly, not loudly).

## Fails safe

- `gp_notify_offer` still never raises: all exceptions swallowed, 4s timeout. A
  bad or missing `end_dt` cannot break the offer or SMS loop.
- The portal guards a bad duration: if `end` is missing, empty, or not after
  `start`, it drops the duration segment — never shows "Est.0hrs" or a negative.

## Deliberate gap (not a bug)

ASK THE GOAT (the AI card) is **not** widened. `executeGoatAction()` posts its
payload straight through with no `.map()`, and that AI-built payload does not
reliably carry `end_dt`, so AI-initiated offers still push with the call name
only. The manual Crew Finder buttons carry the volume. Same call as Phase 2.

## Files

- `templates/index.html` — `end_dt` forwarded in `addSelected()` and `sendSMS()`
- `app.py` — `gp_notify_offer` posts `end`; `APP_VERSION` → 3.17.2

## Website side (already shipped)

The paired `/api/push/offer/route.ts` change (accept `end`, compute the
duration label, add the segment) is committed and deploys on Vercel — do the
portal first so it understands `end` before the GOAT sends it, though neither
order breaks anything.

## Deployment (app-only — no PHP this release)

1. Commit `app.py`, `templates/index.html`, this changelog to `main` (staged
   individually; the untracked doc dumps kept out).
2. Build + notarize the DMG on the iMac from `~/dev/gigpower` (asset must be
   named exactly `TheGOAT.dmg`).
3. Publish the GitHub release; confirm the asset is fully uploaded
   (`state:"uploaded"`, `draft:false`).
4. Smoke-test (hard-refresh): offer **yourself** on test SmartStaff, watch the
   push, confirm it shows `Est.Nhrs` between the start time and the venue. Never
   bulk-offer other crew as a test — Send SMS fires SmartStaff's real gateway.
5. Flip `version.json` to 3.17.2 **last**.
