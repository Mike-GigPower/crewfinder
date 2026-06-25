# THE GOAT — Crew inductions (on-behalf view + upload)

**Scope:** THE GOAT (`Mike-GigPower/crewfinder`). `app.py` (two helpers + two
routes) and `templates/index.html` (the Inductions view in the Manage Crew
modal). **No new PHP and no schema change** — it reuses the SmartStaff crew
endpoints already deployed for the crew portal.

---

## What shipped (admin-facing)

- **See any crew member's induction status from THE GOAT.** Open someone in
  Manage Crew → **Inductions**: every active induction venue with its status
  (Expired / Expiring Soon / Incomplete / Complete), completion date, ordered
  attention-first, with a count summary.
- **Upload a certificate on their behalf.** Tick one or more venues, set the
  completion date, attach a PDF, confirm, and upload — the venue(s) flip to
  Complete. Ops doing for the crew member what the crew portal lets them do for
  themselves.
- **One PDF covers a precinct.** Because the upload fans the single certificate
  across every ticked venue, a Melbourne Park induction is done by ticking its
  arenas and uploading once.

---

## How it works

The crew portal's induction endpoints self-scope via `goat_acting_user_id()`,
which honours a **logged-in session**. THE GOAT already impersonates crew members
for the unavailability flow (throwaway admin session → `/aquire-id/<id>` → act →
`/release-id`). Induction reuses that exact path, so the endpoints act on the
acquired crew member with **no service key shipped in the DMG** — it leans on the
operator's own saved admin credentials.

- **Read** — `GET /api/admin/crew/<id>/inductions` → `fetch_crew_inductions()`
  impersonates and GETs `my-induction-venues.php`, returning the full venue list
  with status.
- **Write** — `POST /api/admin/crew/<id>/inductions` → `add_crew_induction()`
  impersonates and multipart-POSTs to `add-my-induction.php`
  (`confirmation`, `complete_date`, comma-separated `venue_ids`, `certificate`
  PDF). The endpoint stores the PDF once and writes a `crew_venue_induction`
  (+ `user_licenses`) row per venue. Both writes run under the existing
  impersonation lock.
- **UI** — an **Inductions** button on the Manage Crew edit modal opens
  `openCrewInductions(id)`: status pills (colour-coded), per-venue checkboxes,
  and a "Record an induction" form (date + PDF + an explicit on-behalf
  confirmation). `submitCrewInduction(id)` validates, posts `FormData`, and
  reloads the list on success.

---

## Files

- `app.py` — `fetch_crew_inductions()`, `add_crew_induction()`, and the GET/POST
  `/api/admin/crew/<id>/inductions` routes (admin-gated). `APP_VERSION = 3.8.0`.
- `templates/index.html` — `openCrewInductions()` (read + upload view),
  `submitCrewInduction()`, and the Inductions button in the edit modal.

---

## Key decisions / learnings

- **Impersonation, not the service key.** `add-my-induction.php` /
  `my-induction-venues.php` gate on `goat_acting_user_id()`, which accepts a
  logged-in session — so an acquired session writes for that crew member without
  the service key ever being baked into the distributed app.
- **Zero backend change.** The portal endpoints already do the validation, PDF
  storage and per-venue fan-out, so THE GOAT just drives them through
  impersonation — nothing new to deploy.
- **Fan-out is built in.** `venue_ids` is comma-separated, so precinct grouping
  (Melbourne Park) is just multi-select; a dedicated "covers" table + admin UI
  remains the optional future step.
- **Trust-based, matching the crew flow.** The on-behalf confirmation is the
  operator's attestation; the submitted → ops-verify approval phase is still
  deferred.
