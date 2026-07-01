# THE GOAT — v3.17.1

Completes linked calls (Phase 2b): offering crew to a linked call now offers the
**whole group**, and the Crew Finder shows which calls are linked. Builds on the
linking UI from v3.17.0 and the response cascade from Phase 1.

## Offer-side group expansion (the fix)

Previously, offering crew to one call of a linked pair created a `call_crew_map`
row on that call only — so the crew member saw a single offer and the response
cascade had no sibling row to move. Now the offer path widens to the group:

- New **`expand_linked_calls(ss, calls)`** (`app.py`) resolves each selected
  call's linked siblings from `get-booking.php` (already on prod) and adds them,
  deduped by call id. Applied in **`api_goat_add_crew`** and
  **`api_goat_send_sms`** before the add loop, so **Add**, **Add & Confirm**, and
  **Send SMS** all offer the full group. Crew Hub push fires per call, so the crew
  member is notified for each linked call.
- Resolution is server-side, so it's correct regardless of what the Finder has in
  view. On any lookup failure it returns the original calls unchanged — an offer
  is never blocked.

Because the group is resolved from the booking (not the on-screen selection),
this is robust even if a linked sibling isn't currently shown in the Finder.

## Crew Finder shows the link

- `get-calls-bulk.php` now returns `link_group` (one column, like the Phase 1
  `get-booking.php` change), passed through the bulk-call mapper.
- The Finder draws a small **🔗** chip on any linked call, tooltip: "offering
  this call also offers its linked calls." So ops see the grouping before they
  offer.

## Crew Finder selects a linked group as one

- Clicking a linked call in the Finder now **auto-selects its linked siblings**
  (and deselecting removes the whole group). Because the availability check
  already intersects across all selected calls — a crew member is only "available"
  if free for every selected call — this means finding crew for a linked call
  automatically finds crew **available for the whole group**, and offering them
  covers every linked call. Siblings are resolved from the displayed calls via
  their `link_group`. (A linked call that is already full, and so not shown in the
  Finder, is not auto-selected.)

## Files

- `smartstaff/get-calls-bulk.php` — `+ link_group` (SELECT + emit)
- `app.py` — `expand_linked_calls`; applied in the two offer routes; `link_group`
  through the bulk-call mapper; `APP_VERSION` → 3.17.1
- `templates/index.html` — 🔗 chip on linked calls in the Finder

## Still to come — Crew Hub PWA (Phase 3, separate project)

The crew member now receives an offer for **each** linked call (two cards), and
answering either moves both (Phase 1 cascade). Merging those into a single
combined Accept/Decline card is the Crew Hub PWA work — presentation on top of
behaviour that's already correct.

## Deployment (PHP first, then app)

1. Deploy **`get-calls-bulk.php`** to **test** `/ajax/crew/`, verify the Finder
   still loads calls, then deploy to **prod**. (The app tolerates the old
   endpoint — the chip just won't show — but PHP-first keeps to the rule.)
2. Commit `app.py`, `templates/index.html`, `smartstaff/get-calls-bulk.php`, this
   changelog to `main` (staged individually; docs kept out).
3. Build + notarize the DMG on the iMac (asset `TheGOAT.dmg`).
4. Smoke-test (hard-refresh): link two calls in a booking; in Crew Finder click
   **one** of them and confirm **both** highlight as selected (auto-select) and
   both show the 🔗 chip; run Find Crew and confirm results are people free for
   **both**; offer them and verify they hold offered rows on **both**
   (`call_crew_map`) and get pushed for both; confirm one in the Crew Hub → both
   move.
5. Publish the release; confirm the asset; flip `version.json` to 3.17.1 last.
