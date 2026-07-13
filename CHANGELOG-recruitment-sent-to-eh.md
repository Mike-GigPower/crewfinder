# CHANGELOG — Recruitment: Sent to EH stage (Employment Hero handoff)

**Commit (THE GOAT):** `0bc62db` (on `main`, not yet cut as its own release)
**Repos:** `Mike-GigPower/crewfinder` (THE GOAT) + `Gigpower-apply` (applicant web app + Supabase edge functions / database)
**Scope:** A new `sent_to_eh` candidate status for when an operator has created the candidate's Employment Hero entry, stamped with `eh_invited_at`; a server-side form lock in the apply app that keys off the status; `eh_invited_at` added to the `recruitment-candidates` feed; and a **Sent to EH** pile, a **Mark Sent to EH** button, and a "Sent to EH" date line in THE GOAT. One-line `app.py` change plus `templates/index.html`. No DMG rebuilt.

---

## Highlights

— **A stage for "we've put them into Employment Hero".** Once a candidate has been through induction and submitted their details, the operator creates their Employment Hero (EH) entry by hand, then marks them **Sent to EH** in THE GOAT. It's the near-final step before they become active crew.
— **When, not just whether.** Moving a candidate to Sent to EH stamps `eh_invited_at`, and the Recruitment tab shows **Sent to EH: <date>** in the expanded detail — so ops can see who was handed off and when.
— **The apply app locks the form at this point.** A candidate who is `sent_to_eh` has their details form locked server-side, so they can't edit the record out from under an EH entry that's already been created. The lock keys off the current status.
— **Reversible mis-click.** A Sent to EH candidate still offers **Back to New**. Moving them back off `sent_to_eh` naturally unlocks the form again (the lock is just a function of current status — nothing to un-set by hand).

---

## What shipped

**Apply app + edge functions + database (`Gigpower-apply`)**
- **`sent_to_eh` status** added as a valid candidate state, set when an operator has created the candidate's Employment Hero entry.
- **`eh_invited_at`** (timestamptz) column stamped by **`recruitment-set-status`** when a candidate is moved to `sent_to_eh` — the "when were they handed off" timestamp.
- **Server-side form lock.** The candidate details form is locked for a `sent_to_eh` candidate, enforced server-side (not just hidden in the UI), so the record can't change after the EH entry exists. The lock is derived from the current status, so reversing off `sent_to_eh` unlocks it with no extra step.
- **`recruitment-candidates` feed** now includes **`eh_invited_at`** in its allowlist (alongside `status_changed_at` / `details_submitted_at`), so THE GOAT can display the handoff date. It's a plain timestamp — the same non-sensitive category as the other `*_at` fields — and carries no Employment Hero contents.

**THE GOAT (`crewfinder`)**
- **Sent to EH pile + live count.** A "Sent to EH" filter chip sits after Attended (New / Invited / Booked / Attended / **Sent to EH** / Hold / Not suitable / Withdrawn / All). "All" includes `sent_to_eh` candidates too. The chip and its count are driven off the shared status order/labels, so both appeared from the one addition.
- **Distinct status pill.** A teal `.st-sent_to_eh` pill, chosen so it doesn't read as Invited (green) or Attended (blue).
- **"Mark Sent to EH" button.** Offered **only** on a candidate who is currently `attended` (in practice you send them to EH after induction + details), reusing the existing `POST /api/recruitment/set-status` route the other status buttons use — no new backend route.
- **Back to New stays available.** A `sent_to_eh` candidate still gets a single **Back to New** action (the existing generic move button), so a mis-click is reversible.
- **Sent to EH date in detail.** Expanding a candidate shows **Sent to EH: <eh_invited_at date>** when the stamp is present, mirroring the existing "Details submitted" line. Missing → the line is simply omitted.

---

## Code changes

- `app.py` — added `"sent_to_eh"` to `RECRUITMENT_VALID_STATUSES` so THE GOAT's own `/api/recruitment/set-status` proxy accepts the new status and forwards it (the proxy validates the status before calling the edge function). One line; no new route.
- `templates/index.html` — added `sent_to_eh` to the status labels + order (creates the pile, count, and "All" inclusion), a `Mark Sent to EH` move label, the teal `.recruit-pill.st-sent_to_eh` colour, a one-line gate so the button only shows on `attended` candidates, and the `eh_invited_at` date line in the expanded detail grid. Reversal to New needs no new code — the existing action-button loop already offers "Back to New" for any non-`applied` candidate. Access is inherited from the Recruitment tab (admin/operations only).
- `recruitment-candidates` edge function (`Gigpower-apply`) — one field (`eh_invited_at`) appended to the `FIELDS` allowlist; deployed as a new version. Adding a field only adds data to each row, so existing consumers are unaffected.

---

## Notes

- **Secret stays local.** `goat_recruitment_key` lives only in the gitignored `config.json`, read server-side; it never appears in the committed frontend.
- **Aging.** `sent_to_eh` is intentionally not in the amber "time in stage" thresholds — it's a near-final state, not a waiting stage that needs chasing.
- **Not yet released.** Sits on `main`; folds into the next signed DMG release.
