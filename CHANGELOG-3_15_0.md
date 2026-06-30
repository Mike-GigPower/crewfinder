# THE GOAT — v3.15.0

Housekeeping release ahead of the Crew Portal PWA work. App-logic only — **no
PHP endpoint or database/schema changes**, so no test→prod deploy step.

## Remove a crew member from a call
- New **✕** control on each booked-crew row in the call dialog (admin only).
  Removes the person from the call **entirely** — a true delete, not a Decline —
  for fixing wrong-person assignments (e.g. the wrong "John Smith").
- Confirms first, then proxies SmartStaff's native
  `add-call.php?action=remove&id=<call>&crewSelectList=<userID>` via a new
  `DELETE /api/call/<booking>/<call>/crew/<user>` route, so the `call_crew_map`
  row **and** any calendar entry are cleaned up by SmartStaff's own code path.
  The dialog reloads its crew list on success.

## Vaccination removed from monitored inductions
- "Vaccination Status" is no longer treated as a monitored induction anywhere in
  THE GOAT — Induction Checker (status + venue filter), the crew self-view, the
  Crew Finder filter/search, and the GOAT assistant. A single `INDUCTION_EXCLUDE`
  set, applied at ingestion (`_filter_inductions`) and in
  `_compute_induction_status`/`all_venues`, drops it everywhere.
- "Stop monitoring", **not delete** — SmartStaff's records are untouched.
- Crew Portal needed no change: the crew-facing endpoints
  (`my-induction-venues.php`, `my-inductions.php`) never returned vaccination;
  it only ever appeared in the admin bulk feed.

## ASK THE GOAT — answers induction-assignment questions
- Fixed the client-side "off-topic" gate that was short-circuiting legitimate
  questions to the **REALLY?** goat GIF. The old whitelist matched only singular
  word-stems, so natural plurals ("calls", "inductions", "assigned") fell
  through. It now forwards anything phrased as a question or mentioning a
  business term; the easter egg still fires for genuine nonsense.
- New `check_assignment_inductions` tool: joins call rosters to induction status
  server-side and returns the **confirmed** crew booked at a venue who are
  Expired / Expiring Soon / never inducted. Answers e.g. "anyone with expired
  inductions assigned to calls at RLA?" — the assistant had no tool for this
  join before (it knew inductions and calls separately, but not who was on which
  call).

## Create Booking — 24-hour, flexible time entry
- The manual-booking call **Start time** is now a validated text field instead
  of `<input type="time">` (which renders AM/PM under macOS WebKit). It accepts
  `8.30pm`, `10:30`, `1030`, `8pm`, `2030`, etc., normalises to 24-hour on blur,
  and blocks Create on an unparseable time.

## All Bookings — Active + date-range filters
- New filter bar: an **● Active** toggle (booking status Active) and **All time /
  30d / 60d / 90d** chips.
- Because the list is server-paginated newest-first across ~11k bookings, a date
  window auto-loads pages until it passes the cutoff, giving the complete set for
  that window without loading the whole table. "Last N days" means the **past** N
  days (future-dated bookings stay under "All time" only). Filters combine.
