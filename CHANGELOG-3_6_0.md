# THE GOAT — v3.6.0

_Release date: 2026-06-12_

Crew Finder gains two ways to interrogate a search — a spot-check for one named
person and a result-list name filter — plus space-saving collapse controls for
the SELECTED and group-filter panels, and a fix for day-column alignment when a
Schedule booking is expanded. No SmartStaff PHP, config, or database changes;
the new read endpoint is a Flask route that ships inside the binary.

## Added — spot-check a specific person

A new **Spot-check a person** box in the Crew Finder sidebar answers "is *this*
person free for the selected call?" directly, without trawling a full search.
Type a name (autocompleted from the roster), hit Check, and that one crew member
is assessed against the selected call(s) and returned in the normal
Available / Conflicts result, with full shift bars and conflict reasons.

Crucially it **bypasses the group / rating / distance filters** — the operator is
asking about a named individual, so a low rating or a missing group shouldn't hide
them. The backend reuses the entire `/api/availability` assessment path, so there
is no second code path to keep in sync: an optional `only_crew_ids` narrows the
roster up front and skips the filter gates for those ids (distance is still
computed for display when the geo filter is on, but never excludes).

A lightweight `GET /api/crew-roster` (admin-only) supplies the `{id, name, ein}`
list for the autocomplete `<datalist>`, sourced from the same bulk roster fetch
the search already uses.

Note: spot-checking someone already booked on that very call shows them as
Available (no self-clash) **and** in the ALREADY BOOKED panel — together that
reads as "already on it, no other conflict".

## Added — result-list name filter

A **🔍 Filter by name** box in the results header narrows the rendered results by
name substring. It composes with an active Ask-the-GOAT filter and the
clickable-header sort, and the available / conflicts / skipped counts recompute
off the filtered set so the stats track what's shown. Cleared automatically on
each fresh search or spot-check.

## Added — collapsible SELECTED panel

The bottom **SELECTED** panel can now be collapsed by clicking its header, folding
away the crew chip list (the part that grows tall on big selections) while leaving
the count and the Copy / Add / Confirm / SMS buttons visible so the operator can
still act while collapsed. A chevron indicates state; session-scoped, same pattern
as the ALREADY BOOKED panel.

## Added — group filter behind a toggle

The group chips are now gated behind a **Filter by group** checkbox, matching the
Filter by distance pattern. The grid is collapsed entirely (no space taken) until
the box is ticked, then reveals the selectable chips. When the box is off the
search sends an empty `required_groups` even if chips were previously toggled, so
a stale selection can't silently narrow results.

## Fixed — Schedule day-column alignment on expanded bookings

Expanding a booking to show its calls no longer pushes the call-row cells out of
line with the day-column headers.

**The fix:** the call-row cell loop was sizing every column by the *call's*
`isToday` (computed once per call) instead of each column's own day. The header
and booking rows make the today column 70px and all others 52px; a call not on
today therefore rendered every column at 52px (today column 18px too narrow), and
a call *on* today rendered every column at 70px — either way the row's grid drifted
relative to the header, cell by cell. The loop now derives a per-column
`colIsToday = (d === today)` for geometry, leaving the call-level `isToday` for the
row highlight and TODAY badge. The today column's gold tint now also carries down
through the call rows for a consistent column.

## Code changes

- `app.py` — `only_crew_ids` spot-check path in `/api/availability` (narrow roster,
  bypass rating/group/distance gates, distance still computed for display); new
  `GET /api/crew-roster` (admin); `APP_VERSION` → 3.6.0.
- `templates/index.html` — spot-check sidebar box + `<datalist>`, `loadCfRoster`,
  `spotCheckCrew`, shared `buildCallPayload` (used by `runSearch` too); result-list
  name filter (`applyCfNameFilter`, `_cfNameFilter` step in `renderResults`);
  collapsible SELECTED panel (`toggleSelPanel` / `applySelCollapsed`, `_selCollapsed`,
  header chevron + count in `updateSelCount`); group filter toggle (`toggleGroupFilter`,
  `#group-enable` checkbox, collapsing `#group-body`, gated `required_groups` in
  `runSearch`); Schedule call-row `colIsToday` alignment fix in `renderSchedule`.

No SmartStaff PHP, config, or database changes.
