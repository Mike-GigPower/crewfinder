"""
Gig Power timesheet — live Google Sheets reader (online import path).

The inverse of timesheet_gsheet.py's generation: instead of the Crew Boss exporting
the generated sheet to .xlsx for Ops to upload, THE GOAT reads the LIVE Google Sheet
it created, straight through the Sheets API it already holds auth for.

Why this is simpler than consuming an .xls:
  - No export step, no file picker, no openpyxl.
  - FORMATTED_VALUE returns the displayed 'HH:MM' the boss sees — already rounded by
    the sheet's own gated/rounded Start Time / Time Off columns, so there's no
    cached-value dependency and no CEILING float dust to undo.
  - Each generated tab carries the Call ID stamped in B1 (A1 holds the literal label
    "GOAT Call ID"), so tab -> call is an EXACT lookup, not EIN-overlap guessing.

This module produces the SAME {tabs, skipped_tabs} shape as
timesheet_import.parse_timesheet_workbook (plus a per-tab `call_id`), so the shared
preview/match/write path consumes either source unchanged. Column understanding and
per-cell parsing come from timesheet_common — the single source of truth both
importers share.

Auth + token handling are reused from timesheet_gsheet (_user_creds), so there's no
second auth story to maintain and no new dependency.
"""

from timesheet_common import (
    HEADER_ROW, FIRST_DATA_ROW, header_map, parse_crew_row, coerce_ein,
)

# Generation (timesheet_gsheet.py) writes ["GOAT Call ID", call_id] into A1, so
# A1 = this label and B1 = the numeric Call ID. The label in A1 is also our tab
# gate: a tab with it is a generated call tab, everything else (the leftover Master
# template, DATA LIST, Schedule, …) is skipped — no header sniffing needed.
CALLID_LABEL = "GOAT Call ID"
CALLID_RC    = (0, 1)        # (row 0, col 1) -> B1

READ_RANGE = "A1:Z500"       # A..Z covers STATUS(A) … NOTES(Z); 500 rows is ample
                             # for one call's crew (a single call never has 480+).


def read_timesheet(spreadsheet_id, token_path):
    """Network entry point. Fetch the sheet's tabs + displayed values in two API
    calls (one metadata get, one batched values get), then parse.

    Returns the same structure as timesheet_import.parse_timesheet_workbook:
      {
        "tabs": [ {tab_name, call_time(=None), call_id(int|None),
                   rows:[ <parse_crew_row dicts> ]} ],
        "skipped_tabs": [name, ...]
      }
    """
    from googleapiclient.discovery import build
    from timesheet_gsheet import _user_creds   # reuse the authorised creds + refresh

    svc = build("sheets", "v4", credentials=_user_creds(token_path),
                cache_discovery=False)

    # 1) enumerate tab titles (cheap; no cell data)
    meta = svc.spreadsheets().get(
        spreadsheetId=spreadsheet_id,
        fields="sheets.properties.title").execute()
    titles = [s["properties"]["title"] for s in meta.get("sheets", [])]
    if not titles:
        return {"tabs": [], "skipped_tabs": []}

    # 2) one batched read of every tab's displayed values
    ranges = ["'%s'!%s" % (t.replace("'", "''"), READ_RANGE) for t in titles]
    resp = svc.spreadsheets().values().batchGet(
        spreadsheetId=spreadsheet_id,
        ranges=ranges,
        valueRenderOption="FORMATTED_VALUE").execute()

    return _parse_grid(titles, resp.get("valueRanges", []))


def _parse_grid(titles, value_ranges):
    """Pure parse: (tab titles, the batchGet `valueRanges`) -> {tabs, skipped_tabs}.

    No network here, so it's unit-testable with a synthetic API response. A tab is a
    call tab iff A1 == "GOAT Call ID"; its Call ID is B1, crew run from row 17 down
    until the block ends (parse_crew_row returns None past the crew)."""
    tabs, skipped = [], []

    for title, vr in zip(titles, value_ranges):
        rows = vr.get("values", [])            # list of rows; each row a list of strings

        if _at(rows, 0, 0) != CALLID_LABEL:
            skipped.append(title)
            continue

        call_id = coerce_ein(_at(rows, *CALLID_RC))           # B1 -> int (or None)
        header  = rows[HEADER_ROW - 1] if len(rows) >= HEADER_ROW else []
        col     = header_map(header)

        crew = []
        for r in rows[FIRST_DATA_ROW - 1:]:
            row = parse_crew_row(lambda c, _r=r: _in_row(_r, c), col)
            if row is None:
                continue                                      # past the crew block
            crew.append(row)

        tabs.append({
            "tab_name":  title,
            "call_time": None,        # live path maps by call_id; J2 not needed
            "call_id":   call_id,
            "rows":      crew,
        })

    return {"tabs": tabs, "skipped_tabs": skipped}


def _at(rows, r, c):
    """rows[r][c] or None — tolerant of short/empty rows the API returns for blanks."""
    if 0 <= r < len(rows):
        row = rows[r]
        if 0 <= c < len(row):
            return row[c]
    return None


def _in_row(row, c_1based):
    """The 1-based-column accessor parse_crew_row expects, over a list row."""
    i = c_1based - 1
    return row[i] if 0 <= i < len(row) else None
