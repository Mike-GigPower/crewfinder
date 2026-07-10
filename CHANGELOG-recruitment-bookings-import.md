# CHANGELOG — Recruitment: TryBooking bookings import

**Commit:** `c440cb0` (on `main`, not yet cut as its own release)
**Repo:** `Mike-GigPower/crewfinder` (THE GOAT)
**Scope:** Frontend only (`templates/index.html`). No `app.py`, no PHP, no database schema change, no new backend route, no DMG rebuilt.

---

## What shipped

An **Import bookings** button in the Recruitment tab. Drop a TryBooking *Attendee List Report* CSV; it parses in the browser, previews what will change, and only on **Confirm** marks matching candidates **Booked** by reusing `POST /api/recruitment/set-status` (one call each).

**Matching rules**
- **Reference first.** Each attendee is matched by their Gig Power reference (trimmed + uppercased). References are unique, so a hit here is exact.
- **Email fallback — unique only.** If the reference is blank or doesn't match, we look up candidates by the booking email. Exactly **one** match → booked (flagged "matched by email"). **Zero** → unmatched. **Two or more** → sent to a **"needs review — shared email"** list showing the email and every candidate it could be, and **never auto-booked** (emails are not unique in our data — one is shared by ~6 candidates).
- **Only Invited candidates get booked.** A match already past Invited (e.g. Attended) is **skipped**, shown with its current status, and never dragged backwards.
- **Dedupes ticket rows.** One person buys several tickets (several CSV rows); they collapse to a single candidate before matching.

**Safe by design**
- **Preview before confirm.** Four grouped counts — will book / skipped / needs review / unmatched — shown before anything changes. Confirm only appears when there is ≥1 to book.
- Nothing is sent or changed until Confirm; the list refetches afterward so booked candidates move to the Booked pile.

---

## Code changes

- `templates/index.html` — the Import-bookings button + modal, drop-zone/file-picker, an RFC4180 CSV parser, the parse/match/categorise logic (`bookingImportProcess`), the preview renderer (`bookingImportRenderPreview`), and the sequential apply (`bookingImportConfirm`). Access is inherited from the Recruitment tab (admin/operations only).

---

## Notes

- **Secret stays local.** `goat_recruitment_key` lives only in the gitignored `config.json`, read server-side; it never appears in the committed frontend.
- **Not yet released.** Sits on `main`; folds into the next signed DMG release.
