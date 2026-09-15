"""
Gig Power timesheet generation — native Google Sheets path (online workflow).

Ops generates a sheet, which they then share on to the crew boss. Because this is a
NATIVE Drive copy of the crew master + native Master-tab duplication (not an xlsx
export), every formula, dropdown, colour rule and validation is preserved perfectly.

Auth: a SERVICE ACCOUNT writing into a Workspace Shared Drive. A service account has
no Drive of its own — `files.copy` into My Drive fails with a storage-quota error —
so `dest_folder_id` MUST point at a Shared Drive (or a folder inside one), which the
org owns rather than any individual. That is the whole point of the change: a machine
identity has no password, no consent screen and no refresh token to lapse, so the
failure class that took all four Ops installs down on 9 and 21 July 2026 cannot recur.

The legacy per-user OAuth token (gsheet_authorize.py) is still accepted by
_gsheet_creds, chosen by the credential file's own "type" field. It is kept purely as
a rollback path: swapping the file back and pointing one config key at it restores the
old behaviour without a rebuild and a release. Do not build new work on it.

NOTE: requires Google network access + valid credentials, so it can't be exercised in
the build sandbox — verify on a machine holding the service-account key.
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


def _gsheet_creds(cred_path):
    """Load Google credentials from cred_path.

    Two formats are accepted, chosen by the file's own "type" field:

      service_account  — the current path. Nothing to expire, nothing to revoke by an
                         account-level event. Requires a Shared Drive destination.
      authorized_user  — the legacy cached OAuth token, refreshed in place as before.
                         Rollback only; see the module docstring.

    Raises RuntimeError with a message an Ops user can act on, never a raw Google
    exception — `invalid_grant` in front of Rich or Monty is meaningless to them."""
    import json

    if not os.path.exists(cred_path):
        raise RuntimeError(
            "Google credentials missing (%s). They ship inside the app — reinstall the "
            "latest DMG, and if that doesn't fix it contact Mike."
            % os.path.basename(cred_path))

    try:
        with open(cred_path) as f:
            blob = json.load(f)
    except ValueError as e:
        raise RuntimeError("Google credentials file is not valid JSON (%s): %s"
                           % (os.path.basename(cred_path), e))

    if (blob.get("type") or "").strip() == "service_account":
        from google.oauth2 import service_account
        return service_account.Credentials.from_service_account_info(blob, scopes=SCOPES)

    from google.oauth2.credentials import Credentials
    from google.auth.transport.requests import Request

    creds = Credentials.from_authorized_user_info(blob, SCOPES)
    if not creds.valid:
        if creds.expired and creds.refresh_token:
            creds.refresh(Request())
            with open(cred_path, "w") as f:
                f.write(creds.to_json())
        else:
            raise RuntimeError("Google authorisation has expired — contact Mike.")
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


def generate_timesheet_gsheet(cred_path, template_id, share_email, booking_name,
                              calls, booking_id=None, dest_folder_id=None):
    """Copy the crew master, add a Master-cloned tab per call (crew pre-filled, Call
    ID stamped), and return {'url', 'spreadsheet_id'}. share_email is added as an
    editor if it differs from the owner.

    booking_id (optional): tagged into the file name as " [#<id>]" so humans can find
    the sheet and the live importer can recover it from Drive by name when no local
    link exists.

    dest_folder_id: a Drive folder (a Shared Drive, a folder inside one, or — on the
    legacy OAuth path only — a My Drive folder) to create the sheet in. The copy lands
    there, so on a Shared Drive the org owns the sheet, not any individual. Empty/None
    means the authorising account's My Drive root, which a SERVICE ACCOUNT does not
    have: on that path an empty value is a configuration error and the caller rejects
    it before we get here, rather than surfacing Google's storage-quota error.

    calls: [{call_id, call_name, call_time(datetime|None),
             crew:[{lastname, firstname, ein, phone}]}]  (confirmed crew only)
    """
    from googleapiclient.discovery import build

    creds  = _gsheet_creds(cred_path)
    drive  = build("drive",  "v3", credentials=creds, cache_discovery=False)
    sheets = build("sheets", "v4", credentials=creds, cache_discovery=False)

    # 1. native copy of the template, into dest_folder_id if configured
    name = "Timesheet — " + (booking_name or "Booking")
    if booking_id is not None:
        name += " [#%s]" % booking_id
    copy_body = {"name": name}
    if dest_folder_id:
        copy_body["parents"] = [dest_folder_id]
    copied = drive.files().copy(
        fileId=template_id,
        body=copy_body,
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

    # 5. share with the Ops user (writer), when one is configured. On a Shared Drive
    #    the Drive's own members already have access, so this is only for an address
    #    that is NOT a member. A service account cannot send Drive invitation emails —
    #    with sendNotificationEmail=True Google rejects the call — so notification is
    #    only requested on the legacy user path.
    #
    #    The share stays non-fatal: a sheet that exists but wasn't shared is far more
    #    recoverable than no sheet at all. But the outcome is REPORTED rather than
    #    swallowed, because "shared" silently meaning "not shared" is the exact class
    #    of failure this whole change exists to remove.
    shared = None
    if share_email:
        is_service_account = getattr(creds, "service_account_email", None) is not None
        try:
            drive.permissions().create(
                fileId=ss_id,
                body={"type": "user", "role": "writer", "emailAddress": share_email},
                sendNotificationEmail=(not is_service_account),
                supportsAllDrives=True,
            ).execute()
            shared = True
        except Exception:
            shared = False

    return {
        "spreadsheet_id": ss_id,
        "url": "https://docs.google.com/spreadsheets/d/%s/edit" % ss_id,
        "shared": shared,          # True / False when share_email is set, None when not
    }
