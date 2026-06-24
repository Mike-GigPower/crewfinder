# Crew Inductions — Self-Service Status, Certificate Upload & Dashboard Alerts

**Scope:** Gig Power crew portal (`Mike-GigPower/website`, Next 16) + SmartStaff service endpoints (`/ajax/crew/`).
**Shipped:** commit `d323c00` (portal) + three new prod SmartStaff PHP endpoints.
**Status:** Live. Trust-based (no approval step yet).

---

## What shipped (crew-facing)

- **`/my-inductions` — full induction status.** Every active induction venue is listed with its true status — **Complete / Expiring Soon / Expired / Incomplete** — and completion date, read live from SmartStaff. Split into a **To complete** section (attention-first ordering: expired → expiring → incomplete, each with register links) and a **Complete** section (date + certificate link).
- **Certificate upload.** Each incomplete venue has an *Add certificate* form: date + PDF + confirmation → the venue flips to Complete. Trust-based, mirroring SmartStaff's own "Add New".
- **Melbourne Park grouping.** Rod Laver, Margaret Court & John Cain Arenas, Centrepiece and AAMI Park are recorded as five separate SmartStaff rows but shown as **one "Melbourne Park" entry**. A single upload fans the one certificate out to all five.
- **View certificate.** Completed venues show a *View certificate* link that streams the PDF through a gated portal proxy (the files are session-gated on SmartStaff, never hotlinked).
- **Home dashboard alerts.** The **Your Inductions** tile pulses red when any induction is expired/expiring; the **Your Shifts** tile pulses amber when there's a shift in the next 7 days. Tile sub-text becomes dynamic ("1 expired · 2 expiring soon", "3 shifts in the next 7 days"). Reduced-motion users get a static ring.
- **Crew Hub reorganised** into **Your Information** (the four personal tiles) and **General Crew Information** (the rest); Operations Functions unchanged.

---

## SmartStaff endpoints (`/ajax/crew/`, service-key dual-trust)

All gated by `X-Goat-Service-Key`, self-scoped via `goat_acting_user_id()`, PHP 5.x / `mysql_*` house rules.

- **`my-induction-venues.php`** — full list read. `venues` (`active=1 AND has_induction=1`) `LEFT JOIN crew_venue_induction` for the acting crew_id. Computes status with SmartStaff's own 12-month policy. Returns `{venues:[{venue_id, venue, status, completed, complete_ts, file}]}`.
- **`add-my-induction.php`** — certificate upload write. Multipart; mirrors `add-induction.php`: validates PDF (extension + `%PDF-` magic bytes, 10 MB cap), saves once to `user_uploads/{crew_id}_{time()}.pdf`, then per venue inserts a `user_licenses` row and does delete-then-insert into `crew_venue_induction`. Accepts comma-separated `venue_ids` so one cert fans out across a group. Trust-based (requires `confirmation` + a cert).
- **`get-induction-cert.php`** — gated cert streamer. Whitelists the filename (`basename` + `^\d+_\d+\.pdf$`, no traversal), confirms a `crew_venue_induction` row exists for this crew_id + file, then streams the PDF.
- **Left untouched: `my-inductions.php`** — stays completed-only (`INNER JOIN`). THE GOAT's MY STATUS / bulk consumers depend on "present row = Complete"; the full list is a *separate* endpoint to avoid regressing them.

---

## Portal files (website repo)

- `lib/crew-data.ts` — `getInductionVenues`, `addInduction`, `getInductionCert`.
- `lib/induction-groups.ts` — group config (Melbourne Park), matched by **venue name** against the live list (no hard-coded ids).
- `app/my-inductions/page.tsx` — full list, grouping collapse, status pills, register links, cert links, per-venue/group upload.
- `app/my-inductions/actions.ts` — `addInductionAction` server action.
- `app/my-inductions/upload-form.tsx` — client cert-upload form (date + PDF + confirm).
- `app/my-inductions/cert/route.ts` — gated proxy route (`/my-inductions/cert?file=…`).
- `app/page.tsx` — dashboard tile alerts + Crew Hub reorg.
- `app/globals.css` — `.tile-alert` / `.tile-upcoming` pulse keyframes + reduced-motion.

---

## Data model reference

- **`crew_venue_induction`**: `id`, `crew_id` (internal SmartStaff userId), `venue_id`, `complete_date` (unix ts), `file` (cert filename, or NULL). One row per crew+venue (the write deletes then inserts — no history).
- **`venues`**: `id`, `venue`, `address`, `suburb`, `state`, `postcode`, `active` (default 1), `has_induction` (default 0). ~1044 rows; induction list = `active=1 AND has_induction=1`.
- **Cert files**: web-root `user_uploads/{crew_id}_{time()}.pdf`; filename uses upload time, not the completion date; **session-gated** (not public).
- **Status policy** (from `venue-inductions.php`): 30-day months; `≥12mo` Expired, `≥11mo` Expiring Soon, else Complete, NULL Incomplete (≈12-month induction validity).
- **SmartStaff Add form contract**: `POST /crew/manage/{uid}/induction/{vid}/add`, `multipart/form-data`, fields `confirmation` (checkbox), `complete_date` (`dd-M-yy`, parsed via `strtotime`), `license-img` (file), `action=add`.

---

## Key decisions

- **Separate full-list endpoint**, never change `my-inductions.php` — protects THE GOAT consumers.
- **Mirror SmartStaff's own handler** (storage path, columns, delete-then-insert, `user_licenses` row) rather than invent storage.
- **Grouping is app-layer fan-out** over the per-venue write; config by venue name. Relocating it into `venue_inductions` (a "covers" list) + admin UI is a future step.
- **Trust-based**, matching SmartStaff's existing form; an ops-approval workflow is deferred.
- **Certs proxied server-side** with the service key + ownership check; never exposed publicly.

---

## Deployment notes

- PHP promoted to prod SmartStaff `/ajax/crew/`. `user_uploads/` already exists/writable on prod (SmartStaff's own uploads use it); **test** needed it created manually.
- A **fresh prod service key** lives in two places that must match exactly: prod `goat-service-key.php` and Vercel `GOAT_SERVICE_KEY` (Production, marked Sensitive). A mismatch → 403 on every data call → screens show "unavailable", tiles don't flash.
- `.env.local` stays pointed at **test** (local dev never touches prod data); prod config lives only in Vercel. In the newer Vercel UI, env vars are under **Settings → Environments**.
- Env-var changes require a redeploy to take effect (set first, then push).

---

## Pending / backlog

- **Approval workflow** (submitted → ops verify → Complete) — future phase.
- **Move grouping into `venue_inductions`** ("covers" list) + admin UI, so ops can edit precinct groupings without a code change.
- **Verify the prod cert endpoint** with a real filename + the *internal* userId (`9734`), not the EIN — the first prod curl used `file=test.pdf` (fails the whitelist) and `userID=5925` (EIN, not internal), so it didn't actually pass.
- Unrelated, still open: THE GOAT "induction alerts" tool integrity bug (reported 0 expired vs Induction Checker's 1068+).

---

## Learnings worth keeping

- **Substring collision:** `"incomplete".includes("complete") === true` — classify status with an exact match, not `includes`. (Same class as the `unconfirm`/`confirm` rule.)
- **Paste fragility:** a bare `<a` alone on a line can get dropped in transfer; fold the tag and its first attribute onto one line (`<a key={…}`).
- **`/user_uploads/` is session-gated**, so cert viewing must proxy through a service endpoint with an ownership check — not a direct link.
- **Cert filename uses `time()`**, so a grouped induction saves the file once and points every venue row at the same `file`.
