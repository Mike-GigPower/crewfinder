# THE GOAT — v3.21.0 -> v3.22.0

**All Bookings — Create Crew Lists.** The unused *Excel* button on each booking
is now **📋 Crew Lists** — SmartStaff-style Call Lists and Door Lists, one page
per call, printed or saved as PDF. A single dialog picks the optional columns
(EIN, Phone, Email); Name and the write-in On / Break / Off columns are always
there. Ticking *Phone* gives the Call List; leaving it off gives the Door List.
"Who to include" defaults to **All rostered** (everyone assigned except declined
/ no-shows / backups); **Confirmed only** is the alternative.

### The fix / how it works
- The list is built client-side from the booking's existing `/api/booking/<id>`
  payload and opened in a new tab with the print dialog auto-popped. No `app.py`
  change and no SQL migration — the Flask route already passes the SmartStaff
  JSON straight through.
- `smartstaff/get-booking.php` now carries `ein` + `email` (and entity-decoded
  `firstname` / `lastname`) on each crew member — a widened per-call crew SELECT
  plus four new fields on each crew row.
- Crew are rendered "Surname, Firstname" with the surname bold, one page per
  call (page-break between calls); the on-screen 🖨 button is hidden in print.

### Code changes
- smartstaff/get-booking.php — crew SELECT gains `users.ein, users.email`; each
  crew row gains `firstname`, `lastname` (both `html_entity_decode(…, ENT_QUOTES)`),
  `ein`, `email`.
- templates/index.html — Excel button → 📋 Crew Lists; new `openCrewLists` /
  `crewListGenerate` / `_clBuildHtml` + helpers (`_clCheckbox`, `_clKeepCrew`,
  `_clWhen`, `_clTime`, `_clName`, `_clHdrRow`). The old `generateTimesheet`
  path is left in place but unreferenced.
- version.json (last).
