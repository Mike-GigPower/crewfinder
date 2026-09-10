"""
Gig Power timesheet — shared tab classification, column map + cell parsing
(single source of truth).

Both timesheet importers read the SAME crew-master layout (header on row 16, crew
from row 17 down, EIN the match key), so the column understanding lives here once
and neither path can drift from the other:

  - timesheet_import.py      reads an uploaded .xlsx via openpyxl  -> cells arrive
                             as datetime / time / number objects.
  - timesheet_gsheet_read.py reads the live Google Sheet via the Sheets API ->
                             cells arrive as displayed STRINGS ("23:00", "00:30").

The converters below accept BOTH shapes, so parse_crew_row() is identical for each
source; only how a cell is fetched differs (the caller passes a get_cell accessor).

classify_tab() decides what a tab IS — call / no_id / support — from its A1/B1
stamp and its row-16 header. It lives here for the same reason: until 5.40.0 the
two readers each had their own tab test and they disagreed, which is how a call
tab full of times could be visible to one path and invisible to the other.

Column -> SmartStaff field:
  EIN          -> match key (users.ein)
  Start Time   -> on
  Time Off     -> off
  Break 1 Time -> break        (Day Break)
  Break 2 Time -> break_night  (Night Break)
  STATUS       -> late (=Late) / skip (=No Show)
  NOTES        -> goat_note
"""

import re
from datetime import datetime, time, timedelta

HEADER_ROW = 16
FIRST_DATA_ROW = 17

# row-16 header labels we look for (lowercased, stripped) -> internal field name
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

_HHMM = re.compile(r"^(\d{1,2}):(\d{2})")

# The stamp generation writes into A1/B1 of every call tab (timesheet_gsheet.py
# step 4): A1 = this literal label, B1 = the numeric Call ID.
CALLID_LABEL = "GOAT Call ID"

# The row-16 labels that identify the crew-master layout. Deliberately a SUBSET of
# _LABELS: these three have never moved across event templates, so a tab carrying
# all three is a call tab even when its stamp is gone.
_CALL_TAB_SIGNATURE = {"ein", "start time", "last name"}


def is_call_tab(header_cells):
    """True if this tab's row-16 header carries the crew-master signature.

    header_cells: the header row's values as a list, column A first — the same
    shape header_map() takes, so callers pass the identical list to both.

    This is the SECOND opinion on a tab, used when the A1/B1 stamp is missing or
    unusable. Before 5.40.0 the live Google reader had no second opinion at all:
    booking 11952's "Tue 1300" tab (79 crew, all with times) was reported as an
    ignored support tab because someone had deleted the A1 label, even though B1
    still held its Call ID.
    """
    labels = set()
    for v in header_cells:
        if isinstance(v, str):
            labels.add(v.strip().lower())
    return _CALL_TAB_SIGNATURE.issubset(labels)


def classify_tab(a1, b1, header_cells, crew_rows):
    """Decide what a tab IS, from its stamp, its header and its crew.

    Returns (kind, call_id, id_source):

      kind        'call'    — import it. call_id is set.
                  'no_id'   — looks like a call tab, has crew, but no usable Call
                              ID. Must be surfaced for a manual call choice and
                              NEVER filed under skipped/support.
                  'support' — genuinely not a call tab, or the empty Master
                              template.

      call_id     int, or None for 'no_id' / 'support'.

      id_source   'stamp'   — A1 label present and B1 parsed. The normal case.
                  'b1_only' — A1 blank or wrong, B1 parsed anyway. RECOVERED; the
                              caller must tell the operator, because it means a
                              human has edited row 1 of that tab.
                  None      — no id.

    B1 is tested BEFORE the header so a stamped tab is still accepted if a future
    template renames a header column.

    An empty-but-headed tab is 'support' on purpose: that is the leftover Master
    template every generated sheet carries. Classifying it 'no_id' would prompt
    the operator to map the template onto a real call on every single import.

    Shared by both readers — timesheet_import (openpyxl) and timesheet_gsheet_read
    (Sheets API) — so the two can never again disagree about what a call tab is.
    """
    call_id = coerce_ein(b1)
    if call_id is not None:
        return "call", call_id, ("stamp" if a1 == CALLID_LABEL else "b1_only")
    if crew_rows and is_call_tab(header_cells):
        return "no_id", None, None
    return "support", None, None


def header_map(header_cells):
    """header_cells: the header row's values as a list, column A first.
    Returns {field_name: column_index} with 1-based column numbers (so "EIN" in
    column K -> 11). Matched by label, not fixed position, so a small column shift
    between events doesn't break parsing."""
    out = {}
    for i, val in enumerate(header_cells, start=1):
        if isinstance(val, str):
            key = _LABELS.get(val.strip().lower())
            if key and key not in out:
                out[key] = i
    return out


def to_clock(v):
    """A clock cell -> 'HH:MM' (rounded to the nearest minute), or None if blank.

    Accepts:
      - datetime / time  (openpyxl): rounded to the minute, killing the CEILING
        float dust (e.g. 22:59:59.712 -> 23:00).
      - str like '23:00' (Sheets FORMATTED_VALUE): already display-rounded, taken
        as-is.
      - number (UNFORMATTED fallback): serial fraction-of-day -> time of day.
    """
    if v is None:
        return None
    if isinstance(v, datetime):
        dt = v
    elif isinstance(v, time):
        dt = datetime(2000, 1, 1, v.hour, v.minute, v.second, v.microsecond)
    elif isinstance(v, str):
        s = v.strip()
        m = _HHMM.match(s)
        if not m:
            return None
        return "%02d:%02d" % (int(m.group(1)), int(m.group(2)))
    elif isinstance(v, bool):
        return None
    elif isinstance(v, (int, float)):
        total = int(round((float(v) % 1.0) * 24 * 60))
        return "%02d:%02d" % ((total // 60) % 24, total % 60)
    else:
        return None
    dt = (dt + timedelta(seconds=30)).replace(second=0, microsecond=0)
    return "%02d:%02d" % (dt.hour, dt.minute)


def to_duration(v):
    """A break-duration cell -> 'HH:MM'. Blank -> '00:00'.

    Accepts datetime / time (openpyxl), 'H:MM' string (Sheets), or a fraction-of-day
    number."""
    if isinstance(v, (datetime, time)):
        return "%02d:%02d" % (v.hour, v.minute)
    if isinstance(v, str):
        s = v.strip()
        if not s:
            return "00:00"
        m = _HHMM.match(s)
        if m:
            return "%02d:%02d" % (int(m.group(1)), int(m.group(2)))
        try:
            return _frac_to_hhmm(float(s))
        except ValueError:
            return "00:00"
    if isinstance(v, bool):
        return "00:00"
    if isinstance(v, (int, float)):
        return _frac_to_hhmm(float(v))
    return "00:00"


def _frac_to_hhmm(f):
    total = int(round(f * 24 * 60))
    return "%02d:%02d" % (total // 60, total % 60)


def coerce_ein(v):
    """EIN cell -> int, or None. Handles openpyxl ints/floats (5925, 5925.0) and
    Sheets strings ('5925', '5925.0')."""
    if v is None or isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        try:
            return int(v)
        except (ValueError, OverflowError):
            return None
    s = str(v).strip()
    if not s:
        return None
    try:
        return int(s)
    except ValueError:
        try:
            return int(float(s))
        except ValueError:
            return None


def parse_crew_row(get_cell, col):
    """Parse one crew row into the normalised dict the preview/write path expects,
    or return None if the row is past the crew block (blank last name AND EIN).

    get_cell(column_index_1based) -> the raw cell value (None if empty/out of range).
    col -> the header_map() for this tab.

    The returned dict shape is unchanged from the original .xlsx parser:
      {ein, lastname, firstname, status, on, off, break, break_night, late, note,
       no_show}
    """
    last = get_cell(col["lastname"]) if "lastname" in col else None
    ein_raw = get_cell(col["ein"]) if "ein" in col else None

    last_blank = last is None or str(last).strip() == ""
    ein_blank = ein_raw is None or str(ein_raw).strip() == ""
    if last_blank and ein_blank:
        return None  # past the crew block

    ein = coerce_ein(ein_raw)

    status = get_cell(col["status"]) if "status" in col else ""
    status = str(status).strip() if status is not None else ""
    no_show = status.lower().replace(" ", "") == "noshow"

    note = get_cell(col["note"]) if "note" in col else ""
    note = str(note).strip() if note is not None else ""

    first = get_cell(col["firstname"]) if "firstname" in col else None

    return {
        "ein":         ein,
        "lastname":    str(last).strip() if last is not None else "",
        "firstname":   str(first).strip() if first is not None else "",
        "status":      status,
        "on":          (None if no_show else to_clock(get_cell(col["on"]))) if "on" in col else None,
        "off":         (None if no_show else to_clock(get_cell(col["off"]))) if "off" in col else None,
        "break":       to_duration(get_cell(col["break"])) if "break" in col else "00:00",
        "break_night": to_duration(get_cell(col["break_night"])) if "break_night" in col else "00:00",
        "late":        1 if status.lower() == "late" else 0,
        "note":        note,
        "no_show":     no_show,
    }
