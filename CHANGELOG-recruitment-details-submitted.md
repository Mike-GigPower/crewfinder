# CHANGELOG — Recruitment: Details Submitted stage (form-done pipeline pile)

**Commit (THE GOAT):** _pending_ (on `main`, not yet cut as its own release)
**Repos:** `Mike-GigPower/crewfinder` (THE GOAT) + `Gigpower-apply` (applicant web app + Supabase edge functions / database)
**Scope:** A new `details_submitted` candidate status for "attended, completed and submitted their /details form, ready to send to EH" — set **automatically** when the form is submitted. Slots between `attended` and `sent_to_eh`. Adds a two-line conditional write in the apply app's submit handler, allow-lists the value in the `recruitment-set-status` edge function, and adds a **Details Submitted** pile, pill, aging threshold, and forward action in THE GOAT. One-line `app.py` change plus `templates/index.html`. No DMG rebuilt.

---

## Highlights

— **A stage for "form done, waiting on you".** Previously, a candidate who attended their induction and finished their details form still read **Attended** — indistinguishable in the pipeline from someone who attended but hasn't touched the form. Now, submitting the form moves them into their own **Details Submitted** pile, so ready candidates surface themselves instead of being checked one by one.
— **Automatic, not a manual triage step.** The status advances on `/details` submit, in the same code path that stamps `details_submitted_at`. There's no "Mark Details Submitted" button — the candidate's own submission is what moves them.
— **Operator decisions win.** The advance is conditional: only a candidate still sitting in `attended` moves. Someone parked in `on_hold` / `not_suitable` who submits the form keeps that status (they still get `details_submitted_at` stamped) — a form submit never overrides an operator's call.
— **Ages fast on purpose.** Details Submitted ambers after just **3 days** — a low threshold because this pile *is* the "waiting on you to send to EH" queue.

---

## What shipped

**Apply app + edge functions (`Gigpower-apply`)**
- **Automatic status advance on submit.** `POST /api/details/submit` (the final-submit handler, not the partial Save path) now, after always stamping `details_submitted_at`, does a second, **conditional** write: `status → details_submitted` guarded by `.eq("status", "attended")`. The guard is the whole design — a row in `on_hold` / `not_suitable` / `booked` / `sent_to_eh` / etc. simply doesn't match, and a re-submit while already `details_submitted` matches nothing either (no redundant status churn / history noise). Two writes because the timestamp is unconditional while the status move is conditional. Best-effort: the submission is already recorded, so a failure on the status write is logged but doesn't fail the request.
- **`trg_track_status_change`** fires on the status change and records the transition to `status_history` + stamps `status_changed_at` automatically — no new trigger work.
- **`recruitment-set-status`** allow-lists `details_submitted` (between `attended` and `sent_to_eh`). No transition side-effects (no email, no extra stamp). The automatic write above goes direct via service role and doesn't depend on this — the allow-list is what lets THE GOAT *also* move the value through the existing doorway (e.g. rare manual correction).
- **`recruitment-candidates` feed** already returns `status`, so rows populate the new pile with no feed change.

**THE GOAT (`crewfinder`)**
- **Details Submitted pile + live count.** A "Details Submitted" filter chip sits after Attended (New / Invited / Booked / Attended / **Details Submitted** / Sent to EH / Active crew / Hold / Not suitable / Withdrawn / All). Chip, count, and "All" inclusion all fall out of the shared status order/labels — one addition.
- **Distinct status pill.** A pink `.st-details_submitted` pill, chosen to stand out as "waiting on you", clearly apart from Attended (blue) and Sent to EH (teal) on either side.
- **Aging.** `details_submitted` added to the amber "time in stage" thresholds at **3 days**, inheriting the existing amber pattern.
- **Forward action = Send to EH.** "Mark Sent to EH" is now offered from a `details_submitted` candidate as well as from `attended` (the normal path is attended → details_submitted (auto) → sent_to_eh, but sending straight from Attended stays available for the case where an operator wants to push someone whose form isn't in yet). Reuses the existing `POST /api/recruitment/set-status` route.
- **No manual "into" button.** Like `active_crew` (reached only via Convert), `details_submitted` is set automatically — no generic button ever moves anyone into it.

---

## Code changes

- `src/app/api/details/submit/route.ts` (`Gigpower-apply`) — added the conditional `status → details_submitted` write after the existing `details_submitted_at` stamp, guarded by `.eq("status", "attended")`; updated the file header comment (it previously said "we do NOT touch status").
- `supabase/functions/recruitment-set-status/index.ts` (`Gigpower-apply`) — added `"details_submitted"` to `ALLOWED_STATUSES` between `attended` and `sent_to_eh`. Redeploy required.
- `app.py` — added `"details_submitted"` to `RECRUITMENT_VALID_STATUSES` so THE GOAT's `/api/recruitment/set-status` proxy accepts and forwards the value. One line; no new route.
- `templates/index.html` — added `details_submitted` to the status labels + order (creates the pile, count, and "All" inclusion), the `3`-day aging threshold, the pink `.recruit-pill.st-details_submitted` colour, a one-line suppression so no generic button moves anyone *into* it, and a one-line gate change so "Mark Sent to EH" also shows on `details_submitted` candidates. Access is inherited from the Recruitment tab (admin/operations only).

---

## Deploy order (ship so no candidate ever sits in a status THE GOAT can't render)

1. **Edge function** (`recruitment-set-status` allow-list) — harmless; widens allowed values only, nothing sets it yet.
2. **THE GOAT** (renders the pile) — so the column exists before anything populates it. Mike's testing = a restart; Rich/Monty = next DMG (cut around when the apply deploy goes live).
3. **Apply app** (writes the status on submit) — Vercel deploy; from here new submits flip to `details_submitted`.
4. **One-time backfill** (prod, after 1–3):
   ```sql
   UPDATE candidates SET status = 'details_submitted'
   WHERE status = 'attended' AND details_submitted_at IS NOT NULL;
   ```
   Matches **zero** rows in current test data, so it's a prod-only concern — but run it there so the pipeline is immediately accurate. Run on the test project first.

---

## Notes

- `details_submitted_at` remains the precise "when"; the status is the pipeline position. Both coexist — the existing "Details submitted: <date>" detail line and "Submitted ✓" badge are unchanged.
- No SmartStaff involvement — entirely candidates-table + pipeline; SmartStaff is still only touched at convert.
- **Not yet released.** Sits on `main`; folds into the next signed DMG release.
