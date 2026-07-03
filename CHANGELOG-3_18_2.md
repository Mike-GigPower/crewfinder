# THE GOAT — v3.18.1 → v3.18.2

Two small additions on top of the Backup feature: a **green Confirmed** status
pill, and a **promote push trigger** — when an admin promotes a backup into a
confirmed spot, THE GOAT fires a "you're booked" push to the Crew Hub. Also
bundles PHP + app changes, so it's a full build.

## Green Confirmed

The call dialog's status **Confirmed** pill was using the amber/gold `--available`
colour, which read the same as the app's gold theme. It's now the same green
(`#22c55e`) used for booked bars/pills elsewhere, so Confirmed reads clearly as
"go". One line in `crewStatusColors` (`templates/index.html`). Backup stays teal,
declined red, pending/unconfirmed amber.

## Promote push (GOAT-side trigger)

When a backup (`call_crew_map.status = 7`) is promoted to confirmed (5) — via the
green **↑ Promote** button or by setting the dialog dropdown to Confirmed — THE
GOAT now fires a fire-and-forget push so the crew member learns they're booked.

Mirrors the existing offer-push design (v3.16.0): the push *engine* lives entirely
in the Crew Hub portal; GOAT holds only the trigger.

### The "7 → 5 only" wrinkle

The push must fire **only** when a *backup* becomes confirmed — not on every
ordinary confirm. So the promotion is detected where the old status is known:

- **`smartstaff/update-crew-status.php`** — now reads the row's status *before*
  updating (`selectFirst('userID, status', ...)`) and returns
  `promoted: true` when the transition is exactly `7 → 5`. Additive field; nothing
  else changes. **(PHP — deploy test → prod first.)**
- **`app.py`** — `api_call_crew_status` checks `result["promoted"]`; when true it
  fetches the call's name/venue (via `fetch_booking_bulk`) and calls a new
  `gp_notify_promotion()` helper. The helper is a copy of `gp_notify_offer`: same
  `GP_PUSH_SECRET`, same 15-min per-`(crew, call)` dedup (separate map), same
  fire-and-forget/never-raises contract — posted to a **separate**
  `GP_PROMOTE_URL` (`/api/push/promote`) so the portal can word it "you're
  booked".

### Cross-project dependency (handover)

Delivery needs a matching **`/api/push/promote` webhook in the Crew Hub portal**
(spec in `GOAT-CrewHub-Promote-Push-Handover.md`). Until that exists, GOAT's
promote push 404s/401s and is silently swallowed — **safe to ship now**;
promotions start delivering once the portal webhook is live, with no further GOAT
change. Same pattern as the original offer push.

### Secret

Uses the existing `GP_PUSH_SECRET` (already in `build_secrets.json` /
`config.json`) — no new secret. Must equal the portal's `PUSH_WEBHOOK_SECRET`.

## Files

- `templates/index.html` — Confirmed pill green (`crewStatusColors`)
- `smartstaff/update-crew-status.php` — returns `promoted` on a 7→5 change (**PHP**)
- `app.py` — `GP_PROMOTE_URL` + `gp_notify_promotion()` + promote hook in
  `api_call_crew_status`; `APP_VERSION` → 3.18.2
- `CHANGELOG-3_18_2.md`
- *(handover for the other repo, uncommitted: `GOAT-CrewHub-Promote-Push-Handover.md`)*

## On the horizon

- **Crew Hub:** build `/api/push/promote` (this release's handover) and the
  standby UI (earlier Phase 3 handover). Those complete the crew-facing side.
- Withdraw-from-standby still deferred.

## Deployment (PHP this release — full order applies)

1. Deploy **`update-crew-status.php`** to **test → prod** (cPanel). Verify a
   promote still returns normally (now with a `promoted` field).
2. Confirm `gp_push_secret` is present in `build_secrets.json` on the iMac (the
   promote push reuses it; a missing secret makes every push silently 401).
3. Stage `templates/index.html`, `smartstaff/update-crew-status.php`, `app.py`,
   and this changelog **individually**; keep untracked docs/handovers out.
4. `git pull --rebase`, push to `main`.
5. Build + notarize the DMG on the **iMac**; restore `The GOAT.spec` after.
6. Publish release `v3.18.2`; verify via `get_release_by_tag`.
7. Flip `version.json` to **3.18.2 last**.

(The Crew Hub `/api/push/promote` webhook deploys separately, on its own schedule
— GOAT's trigger no-ops until it's live.)
