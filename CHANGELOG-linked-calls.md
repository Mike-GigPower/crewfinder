# THE GOAT — Linked calls

Lets the same crew answer multiple calls in a booking as **one unit** — offered
together, accepted or declined together, never split. Built for cases like a
Forum Load In + Load Out worked by the same small crew.

Shipped in two phases.

---

## Phase 1 — schema + enforcement (SmartStaff PHP, already deployed)

Backend only; no app change. Deployed test → prod and committed to `main`.

- **`MIGRATION-linked-calls.sql`** — nullable, indexed `link_group` column on
  `calls`, plus a `call_link_seq` AUTO_INCREMENT table that mints group ids.
  Calls sharing a non-null `link_group` are one linked set.
- **`respond-to-call.php`** — the cascade. On confirm/decline it looks up the
  call's `link_group`; if set, it applies the same status to every call in the
  group (per the crew member's still-offered rows, `addToCalendar` on each
  confirm). Returns `changed_calls` and a `linked` flag. Inert for unlinked
  calls — falls straight to the single-call path, so nothing changed until a call
  is actually linked.
- **`link-calls.php`** (new, admin-only) — `{action:"link", call_ids:[>=2]}`
  requires calls in the same booking, all unlinked; mints a group and stamps it.
  `{action:"unlink", call_ids:[…]}` clears them and dissolves any group left with
  a single call.
- **`my-call-offers.php` / `get-booking.php`** — each call now carries
  `link_group` so the PWA can group offers and GOAT can draw the chain indicator.

Enforcement note: the response cascade lives at the write layer
(`respond-to-call.php`), which the Crew Hub PWA uses. The native SmartStaff
dashboard responds through SmartStaff's own `dash.php`, which is **not** covered
by this cascade — a deliberate decision to enforce PWA-side now and close the
native gap when that dashboard is retired.

---

## Phase 2 — the GOAT UI (this release, v3.17.0)

App source only (`app.py`, `templates/index.html`). Uses the Phase 1
`link-calls.php` already on prod, so **no PHP deploy**.

### Link / unlink in the booking dialog
- The Calls list gains a **🔗 Link calls** toggle (admin, full booking loaded).
  In link mode each call shows a checkbox; tick two or more unlinked calls in the
  booking and **🔗 Link selected** groups them. **Done** exits the mode.
- Linked calls show an inline **unlink** control in link mode; unlinking a call
  clears it (and the endpoint dissolves a group left with one member).
- On success the dialog re-fetches so the grouping shows immediately.

### Chain indicator
- Every linked call carries a small coloured **🔗 A / 🔗 B** chip (a stable
  per-group letter + colour within the booking) so ops can see at a glance which
  calls move together — even though calls stay sorted by time and a group's
  members may not be adjacent.

### Routes / helper (`app.py`)
- `POST /api/calls/link` and `POST /api/calls/unlink` (both `@require_cohort
  ("admin")`) proxy `link-calls.php` via a new `ss_link_calls_bulk` helper,
  modelled on `ss_update_call_bulk`. `APP_VERSION` → 3.17.0.

---

## Not included yet — offer-side group expansion (Phase 2b)

Offering **one** call of a linked set does not yet auto-offer the rest. Today the
cascade still works whenever the crew member holds offered rows on both calls —
which they will if ops select both linked calls in Crew Finder (the natural
"available for both" flow). Auto-expanding a one-call offer to its whole group is
a follow-up, gated on a UX decision: silently expand the offer, or warn ops that
the call is linked. It changes the crew-offer hot path, so it's being done
deliberately as its own step.

---

## Deployment (Phase 2)

No PHP — `link-calls.php` is already on prod from Phase 1.

1. Stage `app.py`, `templates/index.html`, and this changelog individually; docs
   kept out.
2. `git pull --rebase`, push to `main`.
3. Build + notarize the DMG on the iMac from `~/dev/gigpower` (asset
   `TheGOAT.dmg`).
4. Smoke-test (hard-refresh after launch): open a booking with ≥2 calls →
   **🔗 Link calls** → tick two → **Link selected**; confirm the 🔗 chips appear.
   Unlink one and confirm the group dissolves. Then in Crew Finder offer a crew
   member to both linked calls and, in the Crew Hub, confirm one → both move
   (the Phase 1 cascade).
5. Publish the GitHub release; confirm the asset via `get_release_by_tag`.
6. Flip `version.json` to 3.17.0 last.
