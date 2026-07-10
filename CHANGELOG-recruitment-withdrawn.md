# CHANGELOG — Recruitment: Withdrawn stage (candidate opt-out)

**Commit (THE GOAT):** `c1cbc55` (on `main`, not yet cut as its own release)
**Repos:** `Mike-GigPower/crewfinder` (THE GOAT) + `Gigpower-apply` (applicant web app + Supabase edge functions)
**Scope:** New `/withdraw` page and withdraw links in the apply app, a `withdrawn` status + `withdraw_reason` / `withdrawn_at` fields across the edge functions and database, and a read-only **Withdrawn** pile in THE GOAT. Frontend only in THE GOAT (`templates/index.html`) — no `app.py` change, no DMG rebuilt.

---

## Highlights

— **Candidates can bow out themselves.** A new `/withdraw` page lets an applicant opt out of the recruitment process, optionally leaving a reason. No operator action needed — they remove themselves cleanly instead of going silent.
— **One-click withdraw from the emails.** The induction **invite** and **attended** emails now carry a withdraw link, so a candidate who's changed their mind can step out from the message they're already reading.
— **New "Withdrawn" stage in THE GOAT.** The Recruitment tab now has a Withdrawn pile (with a live count), a muted-grey "Withdrawn" status pill, and — in the expanded detail — the candidate's **reason for withdrawing** and **when** they withdrew. Reading why people drop off is the point.
— **Read-only, because they opted out.** Withdrawn candidates get no triage buttons and can't be batch-selected; the only action offered is a single **Back to New**, in case someone withdrew by mistake.

---

## What shipped

**Apply app (`Gigpower-apply`)**
- **`/withdraw` page.** A candidate-facing page to opt out of recruitment, with an optional free-text reason. On submit it sets the candidate's status to `withdrawn` and stamps the reason and timestamp.
- **Withdraw links in the emails.** The induction **invite** email and the **attended** email now include a withdraw link pointing at `/withdraw`, so a candidate can step out directly from either message.

**Edge functions + database (`Gigpower-apply`)**
- **`withdrawn` status** added as a valid candidate state.
- **`withdraw_reason`** (text) and **`withdrawn_at`** (timestamp) columns added to the candidate record, written by the `/withdraw` flow.
- **`recruitment-candidates` feed** returns withdrawn candidates and now includes the `withdraw_reason` and `withdrawn_at` fields, so THE GOAT can display them.

**THE GOAT (`crewfinder`, `templates/index.html`)**
- **Withdrawn pile + live count.** A "Withdrawn" filter chip sits at the end of the row (New / Invited / Booked / Attended / Hold / Not suitable / **Withdrawn** / All). "All" includes withdrawn candidates too.
- **Withdrawn status pill.** A deliberately muted grey pill, visually distinct from every active pile, so a withdrawn candidate reads as "out".
- **Reason + date in detail.** Expanding a withdrawn candidate shows **Withdrew on** (`withdrawn_at`) and **Reason for withdrawing** (`withdraw_reason`), set off with a grey rule and placed above the pitch so it reads first. If a field is missing it shows a clear placeholder rather than breaking.
- **Read-only by design.** No candidate can be moved *into* Withdrawn from THE GOAT (it's candidate-driven only); a withdrawn candidate shows no normal triage buttons, only a single **Back to New**; and their row checkbox is disabled so they can't be swept into a batch Invite/Hold/Not-suitable action (including via select-all on the All pile).

---

## Code changes

- `templates/index.html` — added `withdrawn` to the status labels + order (creates the pile, count, and "All" inclusion); a muted `.st-withdrawn` pill colour and a grey-ruled `.recruit-withdrawn` detail style; the withdrawn reason/date block in the expanded detail; read-only handling in the action-button loop (only "Back to New"); and a disabled checkbox + a select-all guard so withdrawn rows can't be batch-selected. Access is inherited from the Recruitment tab (admin/operations only).
- No `app.py` change. The only move THE GOAT offers on a withdrawn candidate is "Back to New" (→ `applied`), which is already in the backend's allowed-status set; `withdrawn` is never *set* from THE GOAT, so it isn't added to `RECRUITMENT_VALID_STATUSES`.

---

## Notes

- **Secret stays local.** `goat_recruitment_key` lives only in the gitignored `config.json`, read server-side; it never appears in the committed frontend.
- **Not yet released.** Sits on `main`; folds into the next signed DMG release.
