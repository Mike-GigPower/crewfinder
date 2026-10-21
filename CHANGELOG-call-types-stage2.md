# CHANGELOG — Call Types on calls (Stage 2, steps 2–4)

**Brief:** `BRIEF-goat-call-types-stage2.md` v0.3 (20 Oct 2026). Step 1 (migration + `call-types` edge function v2) was already live and tested.
**Version (THE GOAT):** not bumped here. Step 5 (release) goes through goat-release.
**Repo:** `Mike-GigPower/crewfinder`: `call_types.py` (new), `test_call_types.py` (new), `app.py`, `templates/index.html`, `build_dmg.sh` (`--hidden-import call_types`). No PHP, no SmartStaff schema change.

**Nothing is priced from any of this.** SmartStaff still bills and pays from its RATE column until cutover. The GM forward book is untouched and still resolves from call names (§7).

---

## Step 2: reading a call's type

- `call_types.effective_call_type()` (§4.1): a stored row is `ok`, or `off_card` if the booking's card has since changed. With no row, the type is derived from the call name (exact, then the prefix rule), or `not_set`. It never falls back to Standard, and a derived type is never written back.
- **Booking dialog:** a chip after each call name. A stored type is plain, a derived one is greyed with "· from call name", and `not set` / `off card` are amber. Each open makes one `POST /api/booking/<id>/call-types` and one `call-types` GET.
- **Call dialog:** a Call Type row showing the source, who set it and when, with **Change…** for Admins.

## Step 3: setting a call's type

- **Call Names are free text everywhere a call is set up.** The Call Type carries the pricing, so the name can be anything. In the import preview, Add call and the manual Booking form, the Name is a text field, with the card's call names as type-ahead suggestions only. The call edit form was already free text.
- **Estimate import:** each file line's `crew_type` must be on the booking's card. If it isn't, the preview names the line (test 4). The file line's type isn't editable in the preview, because that's how it was quoted. A call the operator adds in the preview needs a Call Type from the card. Once the calls exist, every call gets a stored Call Type with `source = estimate_import`. If that write fails, the import still completes and a red banner says so.
- **Dropped:** the 5.76 call-name check (`rate_card_line_errors`, C9 §7.3), which refused a MOPT import whose names weren't the card's, and the server's refusal of operator-typed names outside SmartStaff's 21. `crew_type` now does that job. Names off SmartStaff's dropdown travel as free names, as "Other" always has.
- **Add call:** the Call Type is required, and typing a name that's on the card pre-selects its role. `source = add_call`.
- **Manual booking:** the same, per call row. The suggestions and the Call Type list follow the card picker. `source = manual_booking`.
- **Change…:** shows "This changes the charge rate for every crew row on this call." and writes `source = admin`. Changing a booking's card never rewrites its calls' types.

## Step 4: row overrides and Crew Boss

- **Rate** (Admin, per crew row): **Charge as** offers the call's type, the card's roles, and (on a customer card) the Standard card's roles under "manual override". **Pay as** offers "their own grade" or a grade from SmartStaff's list. Save writes `kind = manual`, and Clear removes it. An `override` tag shows who set it and when on hover.
- **Team Lead:** the SmartStaff `is_call_boss` tick now reads **TEAM LEAD** everywhere in THE GOAT. Only the label changed: same `makeboss` call, same Crew Hub contact, no pay or charge effect.
- **Make Crew Boss:** writes `kind = crew_boss`, charged at the Standard card's Crew Boss role and paid at SmartStaff's **Crew Boss** pay grade, which is found by name (`paygrades.day_desc`), never by a fixed ID. If there's no such grade, the button is disabled with "No Crew Boss pay grade in SmartStaff". It's hidden on M&OP bookings, and it shows a **CREW BOSS** chip that can sit alongside TEAM LEAD.
- Every place an Admin sets a type or an override carries the grey cutover line.

## Fix carried in: the import's rate card save (5.76.0 → 5.77.1)

`run_import` runs in a background thread, and `save_booking_rate_card` read the user from Flask's `session` there. That raises "Working outside of request context". So a 5.76+ estimate import ended with **"Unexpected error"** after creating the booking and calls, before saving the rate card and **before writing `import_log.json`** (which the duplicate check relies on). The route now takes `current_user_id()` before starting the thread and passes it as `set_by`. The Call Type writes use the same value.

## New routes

| Route | Who | Does |
|---|---|---|
| `POST /api/booking/<b>/call-types` | all read cohorts | Effective types for the chips (a read, sent as a POST because it carries the call names) |
| `GET /api/call-types/options?booking_id=` / `?set_code=` | admin | The card's roles, call names and name→role pre-selects |
| `GET /api/call/<b>/<c>/pricing` | admin | The call dialog's type, overrides, roles, pay grades and Crew Boss availability |
| `POST /api/call/<b>/<c>/call-type` | admin | Change… |
| `POST /api/call/<b>/<c>/override/<u>` | admin | Manual override |
| `POST /api/call/<b>/<c>/override/<u>/clear` | admin | Clear (manual or Crew Boss) |
| `POST /api/call/<b>/<c>/crew-boss/<u>` | admin | Make Crew Boss |

## Checked

- `test_call_types.py` (21 tests) passes. The existing `test_booking_rate_card`, `test_estimator_calc` and `test_derivation` still pass.
- Every route was run through the Flask test client with SmartStaff and Supabase stubbed. Import of both fixtures matches acceptance tests 2 and 3, and the edited MOPT fixture gives test 4's preview error. The admin-only routes return 403 for the operations cohort.
- **Not yet checked against live SmartStaff, Supabase or a browser.** That needs Mike's acceptance run (§8). Test 14 will show which pay grade SmartStaff actually calls "Crew Boss" (the design notes say grade 20).
