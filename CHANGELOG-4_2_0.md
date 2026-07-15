# THE GOAT — v4.2.0 — View induction certificates in Manage Crew

**Scope:** THE GOAT (`Mike-GigPower/crewfinder`). `app.py` (one new admin route)
and `templates/index.html` (a `View` link in both induction renderers).
**No new PHP, no schema change** — reuses the crew-portal endpoints already live in
prod (`my-induction-venues.php`, `get-induction-cert.php`).

---

## Highlights

— **See the certificate, not just the tick.** In **Manage Crew → Inductions**, a
completed venue now shows a **View** link that opens the uploaded certificate PDF —
the same read-only view licences already have. Previously the modal showed status
and completion date but gave no way to open the actual document.

— **The data was already there.** The induction list already carried the
certificate filename per venue; the old UI simply dropped it. This release surfaces
it — no new backend, no new upload flow.

— **Ownership enforced by impersonation.** The cert streams over the crew member's
own (impersonated) session, and `get-induction-cert.php` confirms the certificate
belongs to that crew member before serving it. An admin can only open a cert that
genuinely belongs to the person they're viewing, and no service key ships in the
DMG.

— **Both induction views match.** The link appears in the Manage Crew edit-modal
tab and the roster-browser induction view, styled identically to the licence
`View` link.

---

## How it works

- **Read (unchanged):** `GET /api/admin/crew/<id>/inductions` →
  `fetch_crew_inductions()` already returns each venue verbatim from
  `my-induction-venues.php`, including `file` (the cert filename, or null).
- **New — stream:** `GET /api/admin/crew/<id>/induction-cert?file=<name>`
  (`api_admin_crew_induction_cert`, admin-gated). Whitelists the filename
  (`^\d+_\d+\.pdf$`, no traversal), then proxies `get-induction-cert.php?file=…`
  over an impersonated crew session (lock → `_in_impersonated_session` → `_release`
  in `finally`), forwarding the upstream content-type inline. Mirrors the licence
  streamer's response handling; differs only in using impersonation (not the admin
  session) because the induction endpoint gates on `goat_acting_user_id()`.
- **UI:** `openCrewInductions()` and `ceLoadInductions()` render a `View` anchor per
  venue when `v.file` is present, with `onclick="event.stopPropagation()"` so the
  link doesn't toggle the venue checkbox, and `encodeURIComponent(v.file)` in the
  query string.

---

## Files

- `app.py` — new `api_admin_crew_induction_cert` route; `APP_VERSION = "4.2.0"`.
- `templates/index.html` — `View` link added to `openCrewInductions()` and
  `ceLoadInductions()`.

---

## Key decisions / learnings

- **Impersonation, not the admin session.** Licences stream via
  `admin-get-license-file.php?id=` on the admin's own session; inductions must use
  `get-induction-cert.php?file=` over impersonation because that endpoint scopes to
  the acting session and checks `crew_venue_induction` ownership. Reuses the exact
  path `fetch_crew_inductions()` already drives, so the acting id is always correct.
- **Zero backend change.** The portal endpoints already validate, store and gate;
  THE GOAT just adds the view half it was missing.
- **`stopPropagation` is load-bearing.** The venue rows are `<label>`s wrapping the
  checkbox; without it, clicking `View` would toggle selection instead of opening
  the PDF.
