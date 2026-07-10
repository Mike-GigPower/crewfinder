# CHANGELOG — Recruitment: Attended stage + batch multi-select

**Commit:** `a9543d6` (on `main`, not yet cut as its own release)
**Repo:** `Mike-GigPower/crewfinder` (THE GOAT)
**Scope:** Frontend + one server-side line. No PHP, no database schema change, no DMG rebuilt.

---

## Highlights

— **New "Attended" stage.** The Recruitment tab now has an Attended pile, an "Attended" status pill, and a **Mark attended** button on Invited candidates — the manual half of induction attendance (the QR self-check-in is the automated half, still to come).
— **Batch multi-select.** Row checkboxes, a header select-all (with an indeterminate "dash" state), and a batch action bar to **Invite / Hold / Not suitable** many candidates at once — the capability that makes a 30–50-person intake manageable.
— **Safe by design.** Selection is per-pile (switching filters clears the ticks, so a batch action can't land on the wrong pile); batch buttons disable while running; the list refetches afterward.

---

## What shipped

**Attended stage**
- `attended` added to the allowed-status list in *both* layers: the `recruitment-set-status` edge function (done earlier, in `Gigpower-apply`) and THE GOAT's Flask route, which re-checks the value before forwarding.
- Attended pile/filter with a live count, a distinct-coloured "Attended" pill, and a **Mark attended** button shown only on `invited_to_induction` candidates. Reuses the existing `POST /api/recruitment/set-status` route (status `attended`) — no new backend route.
- **Back to New** stays available on an Attended candidate, so a mis-click is reversible.

**Batch multi-select**
- Per-row checkbox (click selects without expanding the detail panel) + header select-all with indeterminate state.
- A batch action bar appears once ≥1 row is ticked, with a live "N selected" count and Clear.
- **Invite to induction** (batch) reuses the emailing invite route — one email per person, sent sequentially to avoid hammering Resend — confirms once for the whole batch, and reports a summary ("Invites sent: 8 of 10") flagging any failures. **Hold / Not suitable** (batch) reuse `set-status` and send no emails.
- Selection is per-pile and clears on filter switch; nothing is fetched or changed until an action is clicked.

---

## Code changes

- `app.py` — add `"attended"` to the recruitment route's allowed-status list (one line).
- `templates/index.html` — the Attended pill/filter/Mark-attended button, plus the batch feature: row checkboxes, header select-all, batch bar, and the JS driving them (`recruitToggleSelect`, `recruitToggleAll`, `recruitSyncHeaderCheck`, `recruitUpdateBatchBar`, `recruitClearSelection`, `recruitBatchSetStatus`, `recruitBatchInvite`). Row detail `colspan` bumped 5 → 6 for the new checkbox column.

---

## Verified

- `Mark attended` flips an Invited candidate to `attended` in the database (confirmed against `GPX-260706-003`).
- Batch Hold flips multiple candidates to `on_hold` in one action (confirmed: two references stamped within the same second).

---

## Deferred / notes

- **Not yet released.** This sits on `main`; it will fold into the next signed DMG release. When it does, the release also carries the `goat_recruitment_key` requirement in `build_secrets.json` (already handled for v3.18.3).
- **Batch invite is sequential** by design (paced sends). Parallel is possible if speed ever matters — deliberately not done.
- **The Attended stage's automated half** — the QR self-check-in — is specified in `GigPower-Crew-Onboarding-Pipeline-Design-v0_4.md` §6, still to build. The Mark-attended button is its manual fallback.
