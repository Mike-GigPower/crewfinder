"""
Deterministic extraction of visa facts from Home Affairs documents.

Two document types, both machine-generated with stable labels:

  * IMMI Grant Notification — the grant letter. Carries the grant number, TRN,
    passport country and the visa conditions. POINT IN TIME: it records what was
    granted and will never say the visa was later varied or cancelled.
  * VEVO Visa Details Check — carries the authoritative work entitlement and the
    timestamp of the check. CURRENT as at the moment it was run.

NEITHER DOCUMENT IS SUFFICIENT ALONE, and where they overlap VEVO wins — see
merge_visa_documents(). That is not a preference, it is the difference between a
record of what was granted and a record of what is true now.

WHY THIS IS REGEX AND NOT A LANGUAGE MODEL
------------------------------------------
The app ships the anthropic SDK and calls Claude elsewhere, so routing these
through the API would have been less code. It is deliberately not done:

  1. It would send passport numbers and immigration status to a third-party API.
     5.23.0 kept passport and TRN off the register's own list endpoint on the
     grounds that immigration PII belongs behind a deliberate click; sending it
     off the machine is a much larger step than that decision anticipated.
  2. These documents are not unstructured. The case for a model is unstructured
     input.
  3. A hallucinated expiry is a compliance failure. A regex that fails returns
     None and the operator types the value; a model that fails returns a
     plausible date and the operator confirms it. Those are not the same failure.

WHY THE WHITESPACE IS COLLAPSED FIRST
-------------------------------------
The text layer is not laid out the way the page is, and it differs by extractor.
Observed on the reference documents:

  * pypdf emits "document) numberV3278269" — label and value with NO space
  * pdfplumber puts that same value on the line ABOVE its own label
  * the label "Passport (or other travel document) number" wraps across two lines

So: collapse all whitespace to single spaces, then match label-anchored patterns
with \\s* (never \\s+) between label and value. Robust against the wrapping and
against both extractors. NOT robust against Home Affairs restyling the letter,
which is why every field is independently optional and anything not found is
reported in `unread` rather than guessed.

DATE OF BIRTH IS DELIBERATELY NOT EXTRACTED. The grant letter carries it, there
is no user_visa column for it, and there is no reason to move it around.
"""

import re

__all__ = [
    "extract_visa_document", "merge_visa_documents",
    "parse_grant_notification", "parse_vevo_check", "sniff_kind",
    "KIND_GRANT", "KIND_VEVO",
]

KIND_GRANT = "grant"
KIND_VEVO  = "vevo"

_MONTHS = {m: i for i, m in enumerate(
    "January February March April May June July August September October "
    "November December".split(), 1)}


# ── text ─────────────────────────────────────────────────────────────────────

def _pdf_text(pdf_bytes):
    """Flattened text of a PDF, or None if it cannot be read.

    pypdf is imported HERE rather than at module scope on purpose: if the
    dependency is missing from a build, the app must still start and this
    feature must degrade to "couldn't read this document", not take the process
    down. A bundling failure is invisible from source (3.4.x shipped distance
    search dead the same way), so it has to fail soft at the point of use.
    """
    try:
        import io
        import logging
        from pypdf import PdfReader
    except Exception:
        return None
    # pypdf logs structural complaints ("EOF marker not found") straight out on
    # every slightly-malformed file. They are not actionable here — the return
    # value already says whether the text was usable — and they would otherwise
    # fill gigpower.log every time someone uploads a document a printer made.
    logging.getLogger("pypdf").setLevel(logging.ERROR)
    try:
        reader = PdfReader(io.BytesIO(pdf_bytes))
        raw = "\n".join((page.extract_text() or "") for page in reader.pages)
    except Exception:
        return None
    flat = re.sub(r"\s+", " ", raw).strip()
    # An image-only PDF (a scan or a phone photo) parses fine and yields almost
    # nothing. Treat that as unreadable rather than as a document with every
    # field missing — the operator needs "I can't read this", not 9 blank rows.
    return flat if len(flat) >= 200 else None


def _grab(flat, pattern, group=1):
    m = re.search(pattern, flat)
    return m.group(group).strip() if m else None


def _iso_date(s):
    """'21 August 2026' -> '2026-08-21'. None on anything else.

    The day is range-checked because the notes extraction met '121/8/29' and,
    without a guard, a bad day silently becomes a real date.
    """
    if not s:
        return None
    m = re.search(r"(\d{1,2}) ([A-Z][a-z]+) (\d{4})", s)
    if not m or m.group(2) not in _MONTHS:
        return None
    day, year = int(m.group(1)), int(m.group(3))
    if not (1 <= day <= 31) or not (1900 <= year <= 2100):
        return None
    return "%04d-%02d-%02d" % (year, _MONTHS[m.group(2)], day)


# ── kind ─────────────────────────────────────────────────────────────────────

def sniff_kind(flat):
    """Which document this actually is, read from the document itself.

    The caller says which slot the file was dropped into; this says what it is.
    Trusting the caller means a VEVO check dropped into the grant slot is parsed
    with the wrong patterns and silently yields almost nothing.

    GRANT IS TESTED FIRST, AND THE VEVO MARKERS ARE NARROW. The grant letter
    tells the holder to "check these conditions at any time by using the Visa
    Entitlement Verification Online (VEVO) service" — so the obvious VEVO
    marker appears in BOTH documents, and a looser test classified every grant
    letter as a VEVO check. Caught by the real PDF; the hand-written test text
    had omitted that sentence, which is precisely the sort of thing a fixture
    catches and a fixture-free test never will.

    The markers below appear in one document each: a grant letter has a grant
    number and never states a work entitlement; a VEVO check states one and has
    no grant number.
    """
    if not flat:
        return None
    if "We have granted you" in flat or "Visa grant number" in flat:
        return KIND_GRANT
    if ("Work entitlement(s)" in flat
            or "Current visa details as per departmental records" in flat):
        return KIND_VEVO
    return None


# ── grant notification ───────────────────────────────────────────────────────

def parse_grant_notification(flat):
    conditions = _grab(flat, r"Visa conditions (\d{4}\s*-\s*.+?) An explanation")
    if conditions:
        # Several conditions run together as "8501 - X 8104 - Y". Split on the
        # code boundary so the stored string is readable in the register.
        parts = re.split(r"\s+(?=\d{4}\s*-\s)", conditions)
        conditions = " · ".join(p.strip() for p in parts if p.strip())

    country = _grab(flat, r"document\)\s*country\s*([A-Z][A-Z ]*?)\s*Application ID")

    return {
        "visa_subclass":     _grab(flat, r"\(subclass\s*(\d{3})\)"),
        "visa_grant_number": _grab(flat, r"Visa grant number\s*(\d+)"),
        "trn":               _grab(flat, r"Transaction reference number\s*([A-Z0-9]+)"),
        "visa_grant_date":   _iso_date(_grab(flat, r"Date of grant\s*(\d{1,2} \w+ \d{4})")),
        # "Stay until" is the date work rights end. "Must not arrive after" and
        # "Length of stay" repeat it on these letters but mean different things,
        # so they are not used as fallbacks.
        "visa_expiry":       _iso_date(_grab(flat, r"Stay until\s*(\d{1,2} \w+ \d{4})")),
        "passport_number":   _grab(flat, r"document\)\s*number\s*([A-Z0-9]+)"),
        "passport_country":  country.title() if country else None,
        "visa_conditions":   conditions,
        # NOT SET HERE, EVER. A visa condition is not a work limitation: 8501 is
        # "maintain health insurance" and sits happily alongside unlimited work
        # rights. Inferring a limitation from the presence of conditions marks
        # people as restricted who are not, and on this surface that is a reason
        # somebody does not get booked. Only VEVO sets it.
        "has_work_limitation": None,
    }


def _grant_holder_name(flat):
    n = _grab(flat, r"Visa summary Name\s*(.+?)\s*Date of birth")
    return n or _grab(flat, r"Dear\s+(.+?)\s+We have granted")


# ── VEVO check ───────────────────────────────────────────────────────────────

# VEVO states work rights as a sentence. These are the two ends of it; anything
# else that is present but matches neither is treated as a restriction, because
# on a compliance field the safe default is the one that gets a human to look.
_UNLIMITED_RE = re.compile(r"unlimited right to work", re.I)
_NO_WORK_RE   = re.compile(
    r"(?:no work rights|not permitted to work|does not have permission to work|"
    r"nil work rights|no permission to work)", re.I)


def parse_vevo_check(flat):
    entitlement = _grab(
        flat,
        r"Work entitlement\(s\)\s*(.+?)\s*(?:Personal Privacy|OFFICIAL|Valid as at|$)")

    if not entitlement:
        limitation = None
    elif _NO_WORK_RE.search(entitlement):
        limitation = 1
    elif _UNLIMITED_RE.search(entitlement):
        limitation = 0
    else:
        limitation = 1

    return {
        "visa_subclass":       _grab(flat, r"Visa class\s*/\s*subclass\s*\S+\s*/\s*(\d{3})"),
        "visa_grant_date":     _iso_date(_grab(flat, r"Visa grant date\s*(\d{1,2} \w+ \d{4})")),
        "visa_expiry":         _iso_date(_grab(flat, r"Visa expiry date\s*(\d{1,2} \w+ \d{4})")),
        "passport_number":     _grab(flat, r"Document number\s*([A-Z0-9]+)"),
        "has_work_limitation": limitation,
        # Kept verbatim and shown to the operator beside the derived boolean. A
        # true/false read off a sentence should show its working.
        "work_entitlement":    entitlement,
        "vevo_verified_at":    _vevo_checked_at(flat),
    }


def _vevo_checked_at(flat):
    """The 'valid as at' stamp -> 'YYYY-MM-DD HH:MM:SS'.

    This is the ONLY honest source for vevo_verified_at on this path. 5.23.0's
    rule was that a browser-supplied verifier is not a record of anything; a
    timestamp printed on the document by the system of record, stored alongside
    the document itself, is the strongest form of that rule rather than an
    exception to it.
    """
    m = re.search(
        r"valid as at\s+\w+\s+(\w+)\s+(\d{1,2}),\s*(\d{4})\s+(\d{2}:\d{2}:\d{2})",
        flat, re.I)
    if not m or m.group(1) not in _MONTHS:
        return None
    return "%s-%02d-%02d %s" % (m.group(3), _MONTHS[m.group(1)], int(m.group(2)), m.group(4))


def _vevo_holder_name(flat):
    family = _grab(flat, r"Family name\s*(.+?)\s*Given name")
    given  = _grab(flat, r"Given name\(s\)\s*(.+?)\s*(?:Document number|Visa class)")
    if family and given:
        return "%s %s" % (given, family)
    return family or given


# ── name matching ────────────────────────────────────────────────────────────

def names_match(doc_name, crew_name):
    """Loose comparison of a document name against a rostered name.

    Deliberately forgiving: order-insensitive, case-insensitive, punctuation and
    middle names ignored, and a match on surname plus ONE given name is enough.
    "KATOCH, Abhishek" and "Abhishek Katoch" are the same person; so are
    "Julie (LUNA) Bobi Gallo" and "Julie Gallo".

    Returns True / False, or None when there is not enough to compare — which is
    not a match and must not be reported as one.
    """
    def toks(s):
        s = re.sub(r"\([^)]*\)", " ", str(s or ""))
        return {t for t in re.split(r"[^A-Za-z]+", s.lower()) if len(t) > 1}
    a, b = toks(doc_name), toks(crew_name)
    if not a or not b:
        return None
    return len(a & b) >= min(2, len(a), len(b))


# ── the one entry point ──────────────────────────────────────────────────────

def extract_visa_document(pdf_bytes, crew_name=None):
    """Parse one uploaded PDF.

    Returns {ok, kind, fields, unread, warnings, holder_name}. NEVER raises, and
    NEVER writes anything — persistence is the existing form POST, after the
    operator has read this. Extraction and saving must not be one click.

    `unread` names the fields this could not find, so the operator can tell
    "the document doesn't say" from "the parser missed it".
    """
    result = {"ok": False, "kind": None, "fields": {}, "unread": [],
              "warnings": [], "holder_name": None}

    if not pdf_bytes:
        result["warnings"].append("No file received.")
        return result
    if pdf_bytes[:5] != b"%PDF-":
        result["warnings"].append("That file is not a PDF.")
        return result

    flat = _pdf_text(pdf_bytes)
    if not flat:
        result["warnings"].append(
            "Couldn't read any text from this PDF — it may be a scan or a photo. "
            "Enter the details manually.")
        return result

    kind = sniff_kind(flat)
    if kind is None:
        result["warnings"].append(
            "This doesn't look like a grant notification or a VEVO check. "
            "Enter the details manually.")
        return result

    if kind == KIND_GRANT:
        fields = parse_grant_notification(flat)
        holder = _grant_holder_name(flat)
    else:
        fields = parse_vevo_check(flat)
        holder = _vevo_holder_name(flat)

    result["ok"]          = True
    result["kind"]        = kind
    result["holder_name"] = holder
    # has_work_limitation is legitimately None on a grant letter, so it is not
    # reported as unread there — absent by design is not the same as missed.
    result["unread"] = sorted(
        k for k, v in fields.items()
        if v is None and not (kind == KIND_GRANT and k == "has_work_limitation"))
    result["fields"] = {k: v for k, v in fields.items() if v is not None}

    if crew_name and holder:
        if names_match(holder, crew_name) is False:
            result["warnings"].append(
                "The name on this document is “%s”, which doesn't match "
                "this crew member. Check you have the right person before saving."
                % holder)

    expiry = fields.get("visa_expiry")
    if expiry:
        import datetime
        try:
            if datetime.date.fromisoformat(expiry) < datetime.date.today():
                result["warnings"].append(
                    "This document shows work rights ending %s, which has already passed."
                    % expiry)
        except Exception:
            pass

    if kind == KIND_VEVO and fields.get("has_work_limitation") == 1 \
            and fields.get("work_entitlement") \
            and _NO_WORK_RE.search(fields["work_entitlement"]):
        result["warnings"].append(
            "VEVO reports NO right to work for this person.")

    return result


def merge_visa_documents(grant, vevo):
    """Merge two extract_visa_document() results into one set of field values.

    VEVO WINS ON EVERY OVERLAPPING FIELD. A grant letter records what was granted
    and cannot know about a later variation or cancellation; VEVO is current as
    at the moment it was run. Grant-only fields (grant number, TRN, passport
    country, conditions) survive untouched.

    Returns (fields, conflicts) where `conflicts` describes each field the two
    documents disagreed about, so the disagreement is shown rather than silently
    resolved.
    """
    g = dict((grant or {}).get("fields") or {})
    v = dict((vevo  or {}).get("fields") or {})
    v.pop("work_entitlement", None)      # display only; no column for it

    conflicts = []
    for key, vevo_value in v.items():
        if key in g and g[key] != vevo_value:
            conflicts.append({"field": key, "grant": g[key], "vevo": vevo_value})

    merged = dict(g)
    merged.update(v)                     # VEVO last, so VEVO wins

    # Two documents that name different passports are either two people or a
    # renewed passport. Either way a human decides, not this function.
    gp, vp = g.get("passport_number"), v.get("passport_number")
    if gp and vp and gp != vp:
        conflicts.append({"field": "passport_number", "grant": gp, "vevo": vp,
                          "serious": True})

    return merged, conflicts
