"""
Gig Power post-show timesheet importer — workbook (.xlsx) parsing.

A booking's timesheet is one .xlsx workbook: each CALL is its own tab (e.g.
"Fri 1800 (Show Crew)"), plus support tabs we ignore (DATA LIST, Schedule, …).
Every call tab has a header on row 16 and crew from row 17 down, keyed by EIN
(column K) — which matches SmartStaff users.ein exactly, so no fuzzy matching.

We read the workbook's CACHED computed values (Google formulas don't survive the
xlsx export, but their last-computed values do). Clock times come through as
datetimes with sub-second float dust from the sheet's CEILING rounding (e.g.
22:59:59.712 means 23:00), so on/off are rounded to the nearest minute.

The column map, per-cell parsing and tab classification now live in
timesheet_common, shared with the live Google Sheets reader so the two import
sources can't drift. This module keeps only the openpyxl-specific bits: reading
the header row, the A1/B1 stamp, and J2 for the tab->call auto-map tie-break.

5.40.0: this reader now reads the A1/B1 Call ID stamp, which it never did before.
The route's docstring had claimed since 3.14.0 that an exported generated sheet
"maps exactly" — but parse_timesheet_workbook never set call_id, so every upload
fell through to EIN-overlap matching and the round-trip foreign-tab guard could
never fire on this path at all.
"""

from io import BytesIO

from timesheet_common import (
    HEADER_ROW, FIRST_DATA_ROW, _LABELS, header_map, parse_crew_row, classify_tab,
)


def _header_cells(ws):
    """The header row as a plain list (column A first), for timesheet_common.header_map."""
    return [ws.cell(HEADER_ROW, c).value for c in range(1, ws.max_column + 1)]


def _call_time_iso(ws):
    """The scheduled GIG Call Time in J2 (for auto-mapping tab -> call)."""
    from datetime import datetime
    v = ws.cell(2, 10).value  # J2
    if isinstance(v, datetime):
        return v.isoformat()
    return None


def parse_timesheet_workbook(source):
    """Parse a timesheet workbook (path or bytes) into per-call-tab time rows.

    Returns the SAME shape as timesheet_gsheet_read.read_timesheet:
      {
        "tabs": [
          { "tab_name", "call_time" (iso|None), "call_id" (int|None),
            "id_source" ('stamp'|'b1_only'|None),
            "rows": [ {ein, lastname, firstname, status, on, off,
                       break, break_night, late, note, no_show} ] }
        ],
        "skipped_tabs": [name, ...]   # non-call tabs
      }
    """
    import openpyxl  # imported lazily so the rest of the app doesn't need it

    if isinstance(source, (bytes, bytearray)):
        wb = openpyxl.load_workbook(BytesIO(source), data_only=True)
    else:
        wb = openpyxl.load_workbook(source, data_only=True)

    tabs, skipped = [], []

    for ws in wb.worksheets:
        header = _header_cells(ws)
        col = header_map(header)
        rows = []

        for r in range(FIRST_DATA_ROW, ws.max_row + 1):
            def get_cell(c, _r=r):
                return ws.cell(_r, c).value
            row = parse_crew_row(get_cell, col)
            if row is None:
                continue  # past the crew block
            rows.append(row)

        # Crew are parsed before classification because the empty-Master rule needs
        # the crew count. A1 = the label, B1 = the Call ID (timesheet_gsheet.py).
        kind, call_id, id_source = classify_tab(
            ws.cell(1, 1).value, ws.cell(1, 2).value, header, rows)

        if kind == "support":
            skipped.append(ws.title)
            continue

        tabs.append({
            "tab_name":  ws.title,
            "call_time": _call_time_iso(ws),
            "call_id":   call_id,     # None when kind == "no_id"
            "id_source": id_source,
            "rows":      rows,
        })

    wb.close()
    return {"tabs": tabs, "skipped_tabs": skipped}
