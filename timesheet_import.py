"""
Gig Power post-show timesheet importer — workbook parsing.

A booking's timesheet is one .xlsx workbook: each CALL is its own tab (e.g.
"Fri 1800 (Show Crew)"), plus support tabs we ignore (DATA LIST, Schedule, …).
Every call tab has a header on row 16 and crew from row 17 down, keyed by EIN
(column K) — which matches SmartStaff users.ein exactly, so no fuzzy matching.

We read the workbook's CACHED computed values (Google formulas don't survive the
xlsx export, but their last-computed values do). Clock times come through as
datetimes with sub-second float dust from the sheet's CEILING rounding (e.g.
22:59:59.712 means 23:00), so on/off are rounded to the nearest minute.

Header labels are matched by name on row 16 rather than fixed columns, so a small
column shift between events won't break the import.

Column → SmartStaff field:
  EIN          -> match key (users.ein)
  Start Time   -> on
  Time Off     -> off
  Break 1 Time -> break        (Day Break)
  Break 2 Time -> break_night  (Night Break)
  STATUS       -> late (=Late) / skip (=No Show)
  NOTES        -> goat_note
"""

from datetime import datetime, time, timedelta
from io import BytesIO

HEADER_ROW = 16
FIRST_DATA_ROW = 17

# row-16 header labels we look for (lowercased, stripped)
_LABELS = {
    "status":       "status",
    "start time":   "on",
    "time off":     "off",
    "last name":    "lastname",
    "first name":   "firstname",
    "ein":          "ein",
    "break 1 time": "break",
    "break 2 time": "break_night",
    "notes":        "note",
}


def _round_to_min(v):
    """A cached clock datetime -> 'HH:MM', rounded to the nearest minute (kills the
    CEILING float dust). Returns None for blanks/non-times."""
    if isinstance(v, datetime):
        dt = v
    elif isinstance(v, time):
        dt = datetime(2000, 1, 1, v.hour, v.minute, v.second, v.microsecond)
    else:
        return None
    dt = (dt + timedelta(seconds=30)).replace(second=0, microsecond=0)
    return "%02d:%02d" % (dt.hour, dt.minute)


def _dur_to_hhmm(v):
    """A break duration (time/datetime) -> 'HH:MM'. Blank -> '00:00'."""
    if isinstance(v, (datetime, time)):
        return "%02d:%02d" % (v.hour, v.minute)
    if isinstance(v, (int, float)):  # fraction-of-day fallback
        total = int(round(float(v) * 24 * 60))
        return "%02d:%02d" % (total // 60, total % 60)
    return "00:00"


def _is_call_tab(ws):
    """A call tab has STATUS / Start Time / EIN / LAST NAME on the header row."""
    labels = set()
    for c in range(1, ws.max_column + 1):
        val = ws.cell(HEADER_ROW, c).value
        if isinstance(val, str):
            labels.add(val.strip().lower())
    return {"ein", "start time", "last name"}.issubset(labels)


def _header_map(ws):
    """{field_name: column_index} from the header row."""
    out = {}
    for c in range(1, ws.max_column + 1):
        val = ws.cell(HEADER_ROW, c).value
        if isinstance(val, str):
            key = _LABELS.get(val.strip().lower())
            if key and key not in out:
                out[key] = c
    return out


def _call_time_iso(ws):
    """The scheduled GIG Call Time in J2 (for auto-mapping tab -> call)."""
    v = ws.cell(2, 10).value  # J2
    if isinstance(v, datetime):
        return v.isoformat()
    return None


def parse_timesheet_workbook(source):
    """Parse a timesheet workbook (path or bytes) into per-call-tab time rows.

    Returns:
      {
        "tabs": [
          { "tab_name", "call_time" (iso|None),
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
        if not _is_call_tab(ws):
            skipped.append(ws.title)
            continue

        col = _header_map(ws)
        rows = []

        for r in range(FIRST_DATA_ROW, ws.max_row + 1):
            last = ws.cell(r, col["lastname"]).value
            ein_raw = ws.cell(r, col["ein"]).value if "ein" in col else None
            if (last is None or str(last).strip() == "") and ein_raw is None:
                continue  # past the crew block

            try:
                ein = int(ein_raw) if ein_raw is not None else None
            except (TypeError, ValueError):
                ein = None

            status = ws.cell(r, col["status"]).value if "status" in col else ""
            status = (str(status).strip() if status is not None else "")
            no_show = status.lower().replace(" ", "") == "noshow"

            note = ws.cell(r, col["note"]).value if "note" in col else ""
            note = (str(note).strip() if note is not None else "")

            rows.append({
                "ein":         ein,
                "lastname":    (str(last).strip() if last is not None else ""),
                "firstname":   (str(ws.cell(r, col["firstname"]).value).strip()
                                if "firstname" in col and ws.cell(r, col["firstname"]).value is not None else ""),
                "status":      status,
                "on":          (None if no_show else _round_to_min(ws.cell(r, col["on"]).value)) if "on" in col else None,
                "off":         (None if no_show else _round_to_min(ws.cell(r, col["off"]).value)) if "off" in col else None,
                "break":       _dur_to_hhmm(ws.cell(r, col["break"]).value) if "break" in col else "00:00",
                "break_night": _dur_to_hhmm(ws.cell(r, col["break_night"]).value) if "break_night" in col else "00:00",
                "late":        1 if status.lower() == "late" else 0,
                "note":        note,
                "no_show":     no_show,
            })

        tabs.append({
            "tab_name":  ws.title,
            "call_time": _call_time_iso(ws),
            "rows":      rows,
        })

    wb.close()
    return {"tabs": tabs, "skipped_tabs": skipped}
