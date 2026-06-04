# THE GOAT — v3.5.1

_Release date: 2026-06-04_

## Highlights

Crew Finder timeline polish: shift bars are now coloured by their *relationship
to the requested call* rather than by raw confirmation status, fixing the
"AVAILABLE row with a red bar" inconsistency (a confirmed-but-non-conflicting
shift used to paint red). Shift bars and crew names also gained hover detail.

## Crew Finder — timeline colour rules

Each shift bar is coloured by what it means for the call being filled:

**Shifts on the requested call itself**
- Confirmed → **green**, Availability **BOOKED**
- Declined → **red**, Availability **DECLINED**
- Assigned but not yet confirmed → **grey**, Availability **WAITING**

The requested-call relationship is taken from the booked-crew list (the same
source as the ALREADY BOOKED chips: confirmed / unconfirmed / waiting /
declined), matched to rows by an order-independent normalised name — because a
waiting/declined assignment has no calendar shift row to read. It's drawn as a
synthetic bar over the requested window, so waiting/declined crew now get a bar
even with no shift on file. Multi-call searches (no booked-crew fetch) fall back
to the crew's own `is_target` shift status.

**Other shifts**
- Confirmed shift that conflicts (Rule 1–4) → **red**
- Unconfirmed shift that *would* conflict → **amber**
- Declined shift that overlaps the requested window → **amber**
- Everything else (no clash) → **blue** (informational)

The conflict test for bar colouring reuses `check_conflict` server-side
(single source of truth) via a new `_tag_shift_for_timeline()` that tags each
nearby shift with `is_target` / `conflicts_target` / `overlaps_target`. The
frontend colours purely from those tags — no duplicated gap/overlap maths in JS.

Note: a row can be CONFLICTED on a cumulative Rule 4 (>16h/24h) basis where no
single shift trips the rule alone; in that case the bars stay blue and the
reason is in the detail column. Overlap/gap/venue rules are per-shift and always
highlight.

## Crew Finder — Ask the GOAT (natural-language filter/sort)

An in-page **Ask the GOAT** bar over the results: type a plain request and the
on-screen crew list is filtered/sorted to match.

- "experience with ProStage", "I need VX experience" → filters to crew whose
  skills/groups, induction venues or notes mention the term.
- "lots of RLA experience" → matches RLA and ranks by how much it appears.
- "highest rated", "rating over 8", "closest" → sort / threshold.
- "sort by hours worked at Marvel" → hours worked aren't in the Crew Finder
  data, so it matches Marvel experience and says so (the assistant notes the
  limitation rather than silently guessing).

A new admin-only `/api/crew-finder/ask` sends just the request plus the
VOCABULARY of skills/venues present in the current results to Claude (Haiku),
which returns a small JSON filter/sort spec. The frontend applies it to the
already-loaded crew (matching against groups / inductions / notes) — so it's
token-light, fast, and never re-queries SmartStaff. A banner shows what was
applied ("showing N of M") with a one-click Clear; a fresh search resets it.

A **domain glossary** teaches both the crew filter and the main GOAT the
abbreviations used in call descriptions — VX=Video, SX=Sound/Audio, LX=Lighting/
Lights — so "VX experience" maps onto the "Video" skill group. Built-in defaults
live in `app.py` (`GOAT_GLOSSARY_DEFAULT`); a `goat_glossary` object in
`config.json` extends/overrides them and is read fresh per request, so terms can
be added live with no code change, rebuild, or restart. Example:
`"goat_glossary": { "FX": "Special Effects, Pyro", "RX": "Rigging" }`.

## Crew Finder — hover detail

- **Hover a shift bar** → multi-line tooltip with booking name, call name, time,
  venue, and the shift's state.
- **Hover a crew name** → a card with the crew member's **profile photo** and
  **notes**. Notes come from `users.notes` (surfaced via `list-crew-bulk.php`)
  and ride along on each crew row, so the card renders instantly; the photo is
  streamed through an authenticated proxy.

## App (app.py)

- `_tag_shift_for_timeline()` (new) — annotates nearby shifts for timeline
  colouring; tags computed against the searched target call(s).
- `/api/crew-photo/<crew_id>` (new, admin+leadership) — authenticated image proxy
  (the SmartStaff photo sits behind the session; the browser can't fetch it
  directly). Fast path is the deterministic `images/crewpics/crewimg_<id>.jpg`;
  falls back to scraping the profile page for a non-standard filename and caches
  the result. Missing photo / `nophoto.png` redirect / login redirect all resolve
  to 404 → "No photo on file". Only SmartStaff-hosted images are served (no SSRF).
- `/api/crew-card/<crew_id>` (new, admin+leadership) — photo probe; `?debug=1`
  dumps image candidates + profile tabs for confirming the headshot path.
- `fetch_crew_bulk` now carries `notes` (from `users.notes`) on each crew object.
- `/api/crew-finder/ask` (new, admin) — NL request → JSON filter/sort spec via
  Claude Haiku over the current results' skill/venue vocabulary.
- `APP_VERSION` → 3.5.1.

## UI (index.html)

- New bar classes `bar-warn` / `bar-booked` / `bar-assigned`; BOOKED / WAITING /
  DECLINED availability labels. Shift tooltip is now a floating fixed-position
  element so the scrollable timeline cell's overflow can't clip it.
- `targetRelationship()` (booked-crew driven), `shiftTip()`, and the crew-card
  hover popover (notes from `crew.notes`, photo via the proxy with an onerror
  placeholder).

## SmartStaff (PHP)

- **`list-crew-bulk.php`** — add `u.notes` to the SELECT and emit it on each crew
  object (one-line, additive). This is the only PHP change, and it must be
  deployed for the name-hover **notes** to appear (until then notes show "No
  notes on file"; everything else works without it).
- Conflict colouring and shift tooltips need **no PHP change** —
  `get-shifts-bulk.php` already emits the per-shift `status` (3.5.0) plus
  `call_name` / `booking_name` / `venue`.

## To verify before release

- Deploy the one-line `list-crew-bulk.php` change (adds `u.notes`) so the
  name-hover **notes** populate; until then notes show "No notes on file".
- Photo path confirmed (`images/crewpics/crewimg_<id>.jpg`). If any crew store a
  non-`.jpg` headshot the proxy falls back to the profile-page scrape, so those
  still resolve.
