# THE GOAT — v3.6.5

Hotfix: the Crew Finder **Send SMS** button now works again. It was failing with
`Send SMS failed: Unexpected token '<'` and silently adding crew as Unconfirmed
without sending the message.

## Fixed — Send SMS in Crew Finder

`sendSMS` posts each call as `{call_id, booking_id, call_name}` to
`/api/goat/send-sms`, but the `targets` list in the `/api/availability` response
never carried `booking_id` per call — only a single legacy top-level
`booking_id` (= `targets[0]`). So `t.booking_id` was `undefined` on every target,
`JSON.stringify` dropped the key, and the POSTed call object had no `booking_id`.

In `api_goat_send_sms` that produced an asymmetric failure:

- **Step 1** (add crew) builds its URL from `call['call_id']` only, so it ran
  fine — the selected crew were added to the call as **Unconfirmed**.
- **Step 2** (send SMS) builds `...&bookingID={call['booking_id']}...` outside
  the `try`, hit `KeyError: 'booking_id'`, and Flask returned its HTML 500 page.
  The browser's `await r.json()` then failed on the leading `<`, surfacing as
  `Unexpected token '<', "<!doctype "... is not valid JSON`.

`addSelected` / `api_goat_add_crew` were unaffected because they never read
`booking_id`.

The fix adds `booking_id` to each per-target dict in the availability response
(the source of `currentResult.targets`). This is the correct layer: a Crew Finder
search can span multiple calls across **different** bookings, so each target must
carry its own `booking_id` — the legacy top-level value (`targets[0]` only) would
have been wrong for multi-booking selections regardless. With the field present,
`t.booking_id` resolves, the POST carries it, and Step 2 sends the SMS.

## Code changes

- **`app.py`** — `/api/availability` response: `booking_id` added to each item
  in the `targets` list. `APP_VERSION` → 3.6.5.

## Files

- `app.py` (above).

## Deployment

No SmartStaff PHP changes and no new routes. Pure app release.

Sequence: bump `APP_VERSION` to 3.6.5, apply the `app.py` target-dict change +
this changelog → `py_compile` → commit and push to `main` **before** building →
build the DMG from the clean tree → publish the GitHub release (asset
`TheGOAT.dmg`, confirm HTTP 200) → smoke-test (run a Crew Finder search, select
crew, hit **Send SMS**, confirm "SMS sent (N calls, M crew)" with no popup and no
`Unexpected token` alert) → flip `version.json` to 3.6.5 **last**.
