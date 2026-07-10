# THE GOAT — v3.19.2 -> v3.21.0

Crew Finder hover card now shows a **Reliability** block — each crew member's
**Late** and **No-show** tallies, surfaced from SmartStaff so ops don't have to
open the profile.

- Late  = call_crew_map.late = '1';  No-show = call_crew_map.status = 8.
- Crew with history older than 12 months get two lines — last 12 months and
  all-time; everyone else gets a single all-time line.
- Counts ride along on each crew row from list-crew-bulk.php (one GROUP BY
  aggregate), so the card renders with no extra per-hover request — deliberately
  avoiding SmartStaff session-lock contention on rapid hover.

### The fix
- smartstaff/list-crew-bulk.php — new tallies query (#4): SUM(CASE ...) over
  call_crew_map LEFT JOIN calls, all-time + 12-month windows, plus MIN(start_date)
  to decide the 12-month split. Emitted as a `stats` object per crew row.
- app.py — fetch_crew_bulk carries `stats`; APP_VERSION -> 3.21.0.
- templates/index.html — crewStatsHtml() + Reliability block in showCrewCard; card CSS.

### Code changes
- smartstaff/list-crew-bulk.php, app.py, templates/index.html, version.json (last).
