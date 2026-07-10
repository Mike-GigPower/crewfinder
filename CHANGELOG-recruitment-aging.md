# CHANGELOG — Recruitment: time-in-stage + aging highlight

**Commit (THE GOAT):** `9a165b2` (on `main`, not yet cut as its own release)
**Repos:** `Mike-GigPower/crewfinder` (THE GOAT) + `Gigpower-apply` (Supabase edge functions / database)
**Scope:** A `status_changed_at` timestamp on every candidate (set whenever their stage changes), surfaced as a "time in current stage" label in THE GOAT with a gentle amber flag for aging "waiting" candidates. Frontend only in THE GOAT (`templates/index.html`) — no `app.py` change, no DMG rebuilt.

---

## Highlights

— **See how long someone's been stuck.** Each candidate in the Recruitment tab now shows a small muted "time in current stage" label (e.g. *"3 days in stage"*, *"Today"*, *"1 day in stage"*), under the status pill on the row and in the expanded detail.
— **Gentle aging flag.** Candidates sitting too long in a "waiting" stage turn the label **amber**, so ops can chase them before they go cold — Invited ≥ 7 days, Booked ≥ 14 days, Attended ≥ 7 days. Other stages (New / Hold / Not suitable / Withdrawn) just show the plain count, no amber.
— **Display only.** Nothing about status, filters, buttons, piles, or ordering changed — this only *shows* the age; a full aging sort/view can come later.

---

## What shipped

**Database + edge functions (`Gigpower-apply`)**
- **`status_changed_at` (timestamptz)** on the candidate record — stamped whenever the candidate's *current* status begins, so it always reflects the start of the stage they're in now (not their original application date).
- **`recruitment-candidates` feed** returns `status_changed_at` in its allowlist, alongside the existing fields. The allowlist stays a deliberate privacy boundary — no sensitive columns were added.

**THE GOAT (`crewfinder`, `templates/index.html`)**
- **Day count.** `recruitDaysSince()` computes whole days as `floor((now − status_changed_at) / 86,400,000)` — a difference between two absolute instants, so it can't off-by-one across timezones (`status_changed_at` is UTC). Missing/unparseable → shows nothing (never "NaN"); a future timestamp (clock skew) clamps to 0.
- **Label.** `recruitStageAge()` builds the muted label with correct singular/plural and "Today" for 0 days, and adds the amber class + a tooltip when aging.
- **Named thresholds.** `RECRUIT_AGING_THRESHOLDS` (Invited 7 / Booked 14 / Attended 7) sits near the top of the recruitment JS, easy to tweak in one place. Comparison is `days >= threshold`, so a candidate ambers *at* the threshold day (e.g. day 7 for Invited), not the day after.
- **Styling.** A `.recruit-stage-age` class consistent with the tab; amber reuses the existing `var(--warn)` (same amber as the Hold pill).

---

## Code changes

- `templates/index.html` — `RECRUIT_AGING_THRESHOLDS` constants; the `recruitDaysSince` / `recruitStageAge` helpers; the label rendered under the status pill on each row and as a "Time in stage" entry in the detail grid; and the `.recruit-stage-age` / `.aging` styles. Access is inherited from the Recruitment tab (admin/operations only).
- No `app.py` change. The feed already carries `status_changed_at`, and THE GOAT's read route forwards the feed response untouched — this is a pure display addition.

---

## Notes

- **Secret stays local.** `goat_recruitment_key` lives only in the gitignored `config.json`, read server-side; it never appears in the committed frontend.
- **Not yet released.** Sits on `main`; folds into the next signed DMG release.
