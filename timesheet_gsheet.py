"""
Gig Power timesheet generation — native Google Sheets path (online workflow).

Ops generates a sheet, which they then share on to the crew boss. Because this is a
NATIVE Drive copy of the crew master + native Master-tab duplication (not an xlsx
export), every formula, dropdown, colour rule and validation is preserved perfectly.

Auth: OAuth as the real user (not a service account). A service account has no Drive
of its own, so on a personal Gmail account `files.copy` fails with a storage-quota
error; authorizing as the user creates the sheet in THEIR Drive instead. The one-time
browser consent is done by gsheet_authorize.py, which writes the cached token; this
module just loads/refreshes that token (no browser at request time).

NOTE: requires Google network access + a valid token, so it can't be exercised in the
build sandbox — verify on the machine where you've run gsheet_authorize.py.
"""

import os
import re
from datetime import datetime

SCOPES = [
    "https://www.googleapis.com/auth/spreadsheets",
    "https://www.googleapis.com/auth/drive",
]
MASTER_TAB    = "Master"
_SHEETS_EPOCH = datetime(1899, 12, 30)   # serial-date epoch (same as Excel)
_BAD_TAB_CHARS = re.compile(r"[:\\/\?\*\[\]]")


def _user_creds(token_path):
    """Load cached OAuth user credentials, refreshing silently if expired. Raises a
    clear error if the user hasn't authorized yet (run gsheet_authorize.py)."""
    from google.oauth2.credentials import Credentials
    from google.auth.transport.requests import Request

    if not os.path.exists(token_path):
        raise RuntimeError("Google not authorized yet — run: python3 gsheet_authorize.py")
    creds = Credentials.from_authorized_user_file(token_path, SCOPES)
    if not creds.valid:
        if creds.expired and creds.refresh_token:
            creds.refresh(Request())
            with open(token_path, "w") as f:
                f.write(creds.to_json())
        else:
            raise RuntimeError("Google authorization expired — re-run: python3 gsheet_authorize.py")
    return creds


def _safe_title(name, used):
    """A valid, unique Google Sheets tab title (<=100 chars; no : \\ / ? * [ ])."""
    t = _BAD_TAB_CHARS.sub(" ", str(name or "Call")).strip()[:90] or "Call"
    base, i, low = t, 2, t.lower()
    while low in used:
        suffix = " " + str(i)
        t = base[:90 - len(suffix)] + suffix
        low = t.lower()
        i += 1
    used.add(low)
    return t


def _serial(dt):
    """datetime -> Sheets serial number (days since 1899-12-30). Written RAW so the
    cell's inherited date/time format renders it."""
    return (dt - _SHEETS_EPOCH).total_seconds() / 86400.0


def generate_timesheet_gsheet(token_path, template_id, share_email, booking_name, calls):
    """Copy the crew master, add a Master-cloned tab per call (crew pre-filled, Call
    ID stamped), and return {'url', 'spreadsheet_id'}. The file is created in the
    authorized user's Drive (they own it); share_email is added as an editor if it
    differs from the owner.

    calls: [{call_id, call_name, call_time(datetime|None),
             crew:[{lastname, firstname, ein, phone}]}]  (confirmed crew only)
    """
    from googleapiclient.discovery import build

    creds  = _user_creds(token_path)
    drive  = build("drive",  "v3", credentials=creds, cache_discovery=False)
    sheets = build("sheets", "v4", credentials=creds, cache_discovery=False)

    # 1. native copy of the template
    copied = drive.files().copy(
        fileId=template_id,
        body={"name": "Timesheet — " + (booking_name or "Booking")},
        supportsAllDrives=True,
    ).execute()
    ss_id = copied["id"]

    # 2. find the Master tab's sheetId + existing tab names
    meta = sheets.spreadsheets().get(spreadsheetId=ss_id).execute()
    master_sheet_id = None
    used = set()
    for s in meta.get("sheets", []):
        title = s["properties"]["title"]
        used.add(title.lower())
        if title == MASTER_TAB:
            master_sheet_id = s["properties"]["sheetId"]
    if master_sheet_id is None:
        raise ValueError('Copied template has no "%s" tab' % MASTER_TAB)

    base_index = len(meta.get("sheets", []))

    # 3. duplicate the Master tab once per call (native -> keeps all formatting)
    requests = []
    titles = []
    for i, call in enumerate(calls):
        title = _safe_title(call.get("call_name") or ("Call " + str(call.get("call_id"))), used)
        titles.append(title)
        requests.append({
            "duplicateSheet": {
                "sourceSheetId":   master_sheet_id,
                "insertSheetIndex": base_index + i,
                "newSheetName":    title,
            }
        })
    if requests:
        sheets.spreadsheets().batchUpdate(spreadsheetId=ss_id, body={"requests": requests}).execute()

    # 4. fill crew + call time + Call ID. RAW so phones keep leading zeros and the
    #    J2 serial is rendered by the cell's inherited date format.
    value_ranges = []
    for call, title in zip(calls, titles):
        value_ranges.append({"range": "'%s'!A1" % title,
                             "values": [["GOAT Call ID", call.get("call_id")]]})
        if call.get("call_time") is not None:
            value_ranges.append({"range": "'%s'!J2" % title,
                                 "values": [[_serial(call["call_time"])]]})
        crew = call.get("crew", [])
        if crew:
            last_row = 16 + len(crew)
            value_ranges.append({
                "range":  "'%s'!A17:A%d" % (title, last_row),
                "values": [["Confirmed"] for _ in crew],
            })
            value_ranges.append({
                "range":  "'%s'!I17:L%d" % (title, last_row),
                "values": [[
                    (m.get("lastname") or ""),
                    (m.get("firstname") or ""),
                    ("" if m.get("ein") is None else m.get("ein")),
                    (m.get("phone") or ""),
                ] for m in crew],
            })
    if value_ranges:
        sheets.spreadsheets().values().batchUpdate(
            spreadsheetId=ss_id,
            body={"valueInputOption": "RAW", "data": value_ranges},
        ).execute()

    # 5. share with the Ops user (writer). The user already owns the file, so a
    #    self-share is a no-op/expected failure — tolerate it.
    if share_email:
        try:
            drive.permissions().create(
                fileId=ss_id,
                body={"type": "user", "role": "writer", "emailAddress": share_email},
                sendNotificationEmail=True,
                supportsAllDrives=True,
            ).execute()
        except Exception:
            pass

    return {
        "spreadsheet_id": ss_id,
        "url": "https://docs.google.com/spreadsheets/d/%s/edit" % ss_id,
    }
