"""
Load existing crew contracts from Google Drive.

Crew who joined before the onboarding pipeline have signed agreements, but only
as PDFs in the Completed/Signed Drive folder with nothing linking them to a crew
record. This module reads that folder, pulls a name / signing date / year out of
each title, and suggests a crew member for each file. Saving is NOT done here: it
goes through app.ss_push_contract -> admin-add-contract.php, the same path
convert-to-crew uses. See claude/DESIGN-drive-contract-import-v0_2.md.

parse_contract_title, match_crew and group_files are pure and unit-tested
(test_drive_contracts.py). The Drive calls need a live token and are smoke-tested
from source.

Drive is only ever READ. Nothing here moves, renames or tags a file.
"""

import re
from datetime import datetime

from name_match import name_tokens, name_similarity

# Completed/Signed. A folder id, not a secret; overridable in config.json as
# contract_drive_folder_id.
DEFAULT_FOLDER_ID = "1PN_C_EjEFnr-RQnTP9-0JkuJ60767bZ-"

# admin-add-contract.php refuses anything bigger.
MAX_PDF_BYTES = 10 * 1024 * 1024

# TFN declarations, visa grants, IMMI letters and VEVO checks live in the same
# folder and are not contracts. Case-insensitive. "grant" is deliberately NOT a
# noise word: it skipped Grant Miller's signed agreement in the live folder, and
# every visa-grant title there also says "visa". "immi" must start a word so a
# name like Kimmi is not caught.
_NOISE_RE = re.compile(r"tfn|vevo|visa|\bimmi", re.IGNORECASE)

# Where the person's name ends. The EARLIEST of these in the title wins.
_NAME_MARKERS = (
    "casual employment",
    "employment(",
    "employment agreement",
    "signed",
    "gig power",
    "gigpower",
    "offer of",
    " contract",
)

# DD/MM/YYYY, HH:MM — also with underscores (03_10_2025, 14_21) or dashes. The
# lookbehind stops "110/12/2025" from being read as "10/12/2025": a typo must
# give no date, never a guess.
_DATE_RE = re.compile(r"(?<!\d)(\d{1,2})[/_-](\d{1,2})[/_-](\d{4}),?\s*(\d{1,2})[:_](\d{2})(?!\d)")
_YEAR_RE = re.compile(r"\b(2025|2026)\b")
_PDF_TAIL_RE = re.compile(r"\.pdf.*$", re.IGNORECASE | re.DOTALL)


def is_noise(title):
    return bool(_NOISE_RE.search(str(title or "")))


def _display_case(name):
    """Title-case words that are all one case ("samuel hinton", "SMITH") and
    leave mixed-case words alone, so "McDonald" is not turned into "Mcdonald"."""
    out = []
    for w in name.split(" "):
        if w.islower() or w.isupper():
            w = "-".join(p[:1].upper() + p[1:].lower() for p in w.split("-"))
        out.append(w)
    return " ".join(out)


def _parse_signed_at(text):
    """Last DD/MM/YYYY, HH:MM in text as 'YYYY-MM-DD HH:MM:00', or None when
    there is none or it is not a real date/time. Day first (Australian)."""
    matches = list(_DATE_RE.finditer(text))
    if not matches:
        return None
    d, mo, y, h, mi = (int(g) for g in matches[-1].groups())
    try:
        dt = datetime(y, mo, d, h, mi)
    except ValueError:
        return None
    return dt.strftime("%Y-%m-%d %H:%M:00")


def parse_contract_title(title):
    """{"name", "signed_at", "year"} from a Drive file title, or None for noise.

    name is "" when the title carries no person (e.g. "10 Dec 2025 Gig Power -
    ..."). The row still shows in the scan, as No match, for a manual pick.
    signed_at is None when the title has no valid timestamp — never the Drive
    created date, which is when the file was uploaded, not when it was signed."""
    raw = str(title or "")
    if is_noise(raw):
        return None

    base = _PDF_TAIL_RE.sub("", raw)
    low = base.lower()

    cut = len(base)
    for m in _NAME_MARKERS:
        i = low.find(m)
        if i != -1 and i < cut:
            cut = i

    name = base[:cut].rstrip(" -–_\t")
    name = re.sub(r"\s+", " ", name).strip()
    if not name or name[0].isdigit():
        name = ""
    else:
        name = _display_case(name)

    ym = _YEAR_RE.search(base, cut)
    return {
        "name":      name,
        "signed_at": _parse_signed_at(base),
        "year":      ym.group(1) if ym else None,
    }


def contract_version(year):
    """The user_documents.version for an imported contract. The prefix is the
    provenance marker: it tells an imported contract from an onboarding one
    without a schema change."""
    return ("drive-import " + year) if year else "drive-import"


def _suggestion(c):
    return {"id": str(c.get("id") or ""), "name": c.get("name", "") or "",
            "ein": str(c.get("ein") or c.get("id") or "")}


def match_crew(name, roster):
    """Suggest crew members for a parsed name.

    roster: [{id, name, ein}] with SmartStaff "Lastname, Firstname" names.
    Returns {"bucket": "exact"|"likely"|"ambiguous"|"none", "suggestions": [...]}.

      exact     — the token set equals exactly one crew member's (order-free).
                  Anyone else who merely passes the similarity rule follows it in
                  suggestions, so a manual pick can still reach them.
      likely    — no exact, and exactly one crew member passes the similarity
                  rule (middle name, typo).
      ambiguous — several crew members fit; best ratio first.
      none      — nobody fits, or the name is empty."""
    toks = name_tokens(name)
    if not toks:
        return {"bucket": "none", "suggestions": []}

    exact, passing = [], []
    for c in (roster or []):
        ctoks = name_tokens(c.get("name"))
        ok, ratio = name_similarity(toks, ctoks)
        if not ok:
            continue
        if ctoks == toks:
            exact.append((ratio, c))
        else:
            passing.append((ratio, c))

    passing.sort(key=lambda rc: -rc[0])
    if len(exact) == 1:
        return {"bucket": "exact",
                "suggestions": [_suggestion(exact[0][1])] + [_suggestion(c) for _, c in passing]}
    if len(exact) > 1:
        return {"bucket": "ambiguous",
                "suggestions": [_suggestion(c) for _, c in exact + passing]}
    if len(passing) == 1:
        return {"bucket": "likely", "suggestions": [_suggestion(passing[0][1])]}
    if passing:
        return {"bucket": "ambiguous", "suggestions": [_suggestion(c) for _, c in passing]}
    return {"bucket": "none", "suggestions": []}


def candidate_score(parsed_name, crew_name):
    """Ratio used to rank Drive files for one crew member (per-crew Load from
    Drive). 0.0 when they share nothing."""
    return name_similarity(name_tokens(parsed_name), name_tokens(crew_name))[1]


def surname_token(crew_name):
    """The surname token from a roster "Lastname, Firstname" name, or the last
    word when there is no comma."""
    s = str(crew_name or "")
    part = s.split(",", 1)[0] if "," in s else (s.split()[-1] if s.split() else "")
    toks = name_tokens(part)
    return max(toks, key=len) if toks else ""


def _latest_first(f):
    # Reverse-sorted: latest signed_at first, an undated file ("" sorts lowest)
    # last, and exact ahead of likely on a tie.
    return (f.get("signed_at") or "", f.get("bucket") == "exact")


def group_files(files):
    """Collapse files that point at the same crew member into one row.

    files: dicts carrying at least bucket, suggestions, signed_at. Exact and
    likely files group by their top suggestion; the latest-signed one is the
    primary and the rest ride along as other_copies. Ambiguous and no-match
    files are never grouped: their top suggestion is only a guess, and folding
    one under somebody else's row would hide it.

    Returns rows in input order of their first file."""
    rows, by_crew = [], {}
    for f in files:
        sug = f.get("suggestions") or []
        key = sug[0]["id"] if (f.get("bucket") in ("exact", "likely") and sug) else None
        if key is None:
            rows.append([f])
            continue
        if key not in by_crew:
            by_crew[key] = [f]
            rows.append(by_crew[key])
        else:
            by_crew[key].append(f)

    out = []
    for group in rows:
        ordered = sorted(group, key=_latest_first, reverse=True)
        primary = dict(ordered[0])
        primary["other_copies"] = [
            {"drive_file_id": g.get("drive_file_id"), "title": g.get("title"),
             "signed_at": g.get("signed_at"), "web_view_link": g.get("web_view_link")}
            for g in ordered[1:]
        ]
        out.append(primary)
    return out


# ─── Drive I/O ────────────────────────────────────────────────────────────────

class FolderAccessError(Exception):
    """The token can't see the configured folder (Drive 403/404)."""


def _drive(creds):
    from googleapiclient.discovery import build
    return build("drive", "v3", credentials=creds, cache_discovery=False)


def _http_status(e):
    resp = getattr(e, "resp", None)
    try:
        return int(getattr(resp, "status", 0) or 0)
    except Exception:
        return 0


def folder_name(creds, folder_id):
    """The folder's Drive name. Raises FolderAccessError when the token can't
    see it. Checked explicitly because a parents query on an invisible folder
    returns an empty list, which would read as 'no contracts'."""
    from googleapiclient.errors import HttpError
    try:
        meta = _drive(creds).files().get(
            fileId=folder_id, fields="id,name", supportsAllDrives=True).execute()
    except HttpError as e:
        if _http_status(e) in (403, 404):
            raise FolderAccessError(str(e))
        raise
    return meta.get("name") or ""


def list_folder_pdfs(creds, folder_id):
    """Every non-trashed PDF directly in folder_id, paged to the end. No
    subfolders (D5). Returns [{id, name, createdTime, size, webViewLink}]."""
    from googleapiclient.errors import HttpError
    drive = _drive(creds)
    q = ("'%s' in parents and mimeType = 'application/pdf' and trashed = false"
         % folder_id.replace("'", "\\'"))
    files, token = [], None
    try:
        while True:
            resp = drive.files().list(
                q=q, pageSize=1000, pageToken=token,
                fields="nextPageToken, files(id,name,createdTime,size,webViewLink)",
                supportsAllDrives=True, includeItemsFromAllDrives=True,
            ).execute()
            files.extend(resp.get("files", []))
            token = resp.get("nextPageToken")
            if not token:
                break
    except HttpError as e:
        if _http_status(e) in (403, 404):
            raise FolderAccessError(str(e))
        raise
    return files


def file_meta(creds, file_id):
    """{id, name, mimeType, parents, trashed, webViewLink} for one file."""
    return _drive(creds).files().get(
        fileId=file_id, fields="id,name,mimeType,parents,trashed,webViewLink",
        supportsAllDrives=True).execute()


def download_pdf(creds, file_id):
    """The file's bytes. Checked here against admin-add-contract.php's own rules
    (%PDF- magic, <= 10 MB) so a file SmartStaff would reject never makes the
    round trip."""
    data = _drive(creds).files().get_media(fileId=file_id, supportsAllDrives=True).execute()
    if not isinstance(data, (bytes, bytearray)) or not bytes(data[:5]) == b"%PDF-":
        raise ValueError("Drive file is not a PDF")
    if len(data) > MAX_PDF_BYTES:
        raise ValueError("PDF is larger than 10 MB")
    return bytes(data)
