"""Import Times from a scanned signed sheet — the converter.

BRIEF-timesheet-scan-slice3b.md Part 2, as amended by
BRIEF-timesheet-scan-slice3b-addendum-1.md (the addendum wins where they
differ). The fourth Import Times source, built exactly as check-in was: a Crew
Hub route THE GOAT calls server-to-server, a converter into the sheet-reader
{tabs, skipped_tabs} shape, and _build_import_preview UNCHANGED.

This module does not hold a time rule of its own. Crew Hub's engine reads the
paper and parses the cells; everything here is reshaping and deciding what a
reviewer must look at. Specifically it only:

  1. reshapes the route's per-call reply into {tabs, skipped_tabs} — one tab
     per call, one parse_crew_row-shaped row per person, `call_id` set so
     _build_import_preview maps exactly; and
  2. works out the review flags (which rows start unticked and which are
     locked), which the route attaches to the finished preview as extra
     fields. Nothing here reaches the write.

Pure: no Flask, no SmartStaff, no network. Wiring is in app.py. The one
exception is the HEIC section at the bottom, which shells out to /usr/bin/sips
and says so.

THE TWO SPLITTERS, AND WHY NEITHER IS A SECOND COPY OF A RULE

  New layout (a break with a written start): the minutes are split into day and
  night by their REAL time with derivation._split_totals — the same function
  and the same 08:00 / 20:00 bands as worked hours and as the check-in import.

  Old layout (T9, no start written): all the minutes go in the band holding the
  MIDPOINT of on -> off, and the row starts unticked because that is a guess
  about a pay band. The band is not decided by comparing against 20:00 here;
  it is decided by asking _split_totals which band a one-minute probe at the
  midpoint falls in. So the boundary lives in exactly one place, and a midpoint
  of exactly 20:00 lands wherever the real splitter puts 20:00 (Night), rather
  than wherever a second copy of the comparison happened to put it.

WHAT THE ENGINE DOES NOT SEND, AND WE MUST NOT INVENT

  break_night is not in the reply, and must not be: the day/night split is
  THE GOAT's, beside derivation._split_totals, and a test in Crew Hub fails if
  a break_day / break_night key ever appears in the contract.

  Nothing in the reply says which rows are ticked. Which flags are routine is
  §2.3's rule and therefore ours; the reply carries flag CODES and stops there.
"""

import os
import re
import subprocess
import tempfile
from datetime import datetime, timedelta

from derivation import _split_totals

# Same day/night bands as worked hours (estimator_calc defaults, derivation
# cfg) and as checkin_import.SPLIT_CFG. One set of bands for the whole app.
SPLIT_CFG = {"dayStart": "08:00", "nightStart": "20:00"}

# A scan gives clock times and no date, so the breaks are placed on a fixed
# anchor day. That is not a shortcut: the bands are clock-based, so the only
# case a real date could change is a break spanning the DST instant — and both
# sides of that instant are Night, so the answer is the same either way. A
# Tuesday well clear of any transition, so nothing here depends on the weekday.
_ANCHOR = datetime(2026, 2, 3)


# ---- time helpers ---------------------------------------------------------

def _hhmm_mins(mins):
    """Minutes -> 'HH:MM', the shape parse_crew_row's break fields use."""
    mins = int(mins or 0)
    return "%02d:%02d" % (mins // 60, mins % 60)


def parse_hhmm(s):
    """'HH:MM' -> (hour, minute), or None for anything else. Deliberately
    strict: the engine emits HH:MM or null, so a surprise here is a contract
    change and should fall through to 'no value', never to a guess."""
    m = re.match(r"^(\d{1,2}):(\d{2})$", str(s or "").strip())
    if not m:
        return None
    h, mi = int(m.group(1)), int(m.group(2))
    if h > 23 or mi > 59:
        return None
    return h, mi


def _at(hhmm, next_day=False):
    """'HH:MM' -> a naive datetime on the anchor day, or None."""
    hm = parse_hhmm(hhmm)
    if hm is None:
        return None
    return _ANCHOR + timedelta(days=1 if next_day else 0, hours=hm[0], minutes=hm[1])


def band_of(dt):
    """'day' or 'night' for one instant, asked of the real splitter rather
    than decided here. A one-minute probe is enough: _split_totals reports
    which band those 60 seconds fell in, so the 08:00 / 20:00 boundary stays
    in derivation and this function has no opinion of its own.

    At exactly 20:00 the probe minute 20:00-20:01 is night, so 20:00 is Night.
    That is the splitter's answer, not a tie-break invented here."""
    day, _night = _split_totals(dt, dt + timedelta(minutes=1), SPLIT_CFG)
    return "day" if day > 0 else "night"


def shift_span(on, off):
    """(on_dt, off_dt) for a row's clock times, or None if either is missing.

    off <= on means the shift ran past midnight, which is how the sheet path
    expects an after-midnight finish to be written (brief 2.2) — so the finish
    moves to the next day here rather than the times being 'corrected'."""
    on_dt = _at(on)
    if on_dt is None:
        return None
    off_hm = parse_hhmm(off)
    if off_hm is None:
        return None
    off_dt = _at(off, next_day=(off_hm <= (on_dt.hour, on_dt.minute)))
    return on_dt, off_dt


def t9_band(on, off):
    """T9: the band holding the midpoint of the worked shift, or None when the
    shift can't be measured. Kept as one small function, as the brief asks,
    and it decides nothing about the boundary itself — band_of does that."""
    span = shift_span(on, off)
    if span is None:
        return None
    on_dt, off_dt = span
    if off_dt <= on_dt:
        return None
    return band_of(on_dt + (off_dt - on_dt) / 2)


def split_break_at(on, off, break_start, mins):
    """(day_mins, night_mins) for a break with a written start, split by its
    REAL time with derivation._split_totals — the same call checkin_import
    makes for the same reason.

    The start is placed inside the shift: a break written as 00:30 on a 21:30
    start belongs to the following morning, not to half past midnight twelve
    hours before the person arrived."""
    mins = int(mins or 0)
    if mins <= 0:
        return 0, 0
    span = shift_span(on, off)
    bs_hm = parse_hhmm(break_start)
    if bs_hm is None:
        return 0, 0
    if span is None:
        start = _at(break_start)
    else:
        on_dt, off_dt = span
        start = _at(break_start, next_day=(bs_hm < (on_dt.hour, on_dt.minute)))
        # A break that lands outside the shift after that is left where the
        # paper put it: the engine flags the row, and moving it to make it fit
        # would be this module inventing a time.
        if start > off_dt:
            start = _at(break_start)
    day_h, _night_h = _split_totals(start, start + timedelta(minutes=mins), SPLIT_CFG)
    day_m = int(round(day_h * 60))
    day_m = max(0, min(mins, day_m))
    return day_m, mins - day_m          # the two bands always sum to the break


def place_break(row):
    """(break 'HH:MM', break_night 'HH:MM', t9_band or None) for one reply row.

    t9_band is set only when T9 decided the placement — no written start and
    minutes to place — so the caller knows to name the band in the note and
    start the row unticked."""
    mins = row.get("break_mins")
    try:
        mins = int(mins or 0)
    except (TypeError, ValueError):
        mins = 0
    if mins <= 0:
        return "00:00", "00:00", None

    if parse_hhmm(row.get("break_start")) is not None:
        day_m, night_m = split_break_at(row.get("on"), row.get("off"),
                                        row.get("break_start"), mins)
        return _hhmm_mins(day_m), _hhmm_mins(night_m), None

    # T9: no start on the paper. Everything in the band holding the midpoint.
    band = t9_band(row.get("on"), row.get("off"))
    if band == "day":
        return _hhmm_mins(mins), "00:00", "day"
    # No measurable shift either: Night is the safe placement, and the row is
    # held and named, so nobody accepts it unseen.
    return "00:00", _hhmm_mins(mins), band or "night"


# ---- flags ---------------------------------------------------------------

# Crew Hub's FlagCode union (lib/timesheet-scan-assemble.ts), in operator
# words. Read from the engine rather than guessed, so a code cannot be
# described here as something it is not.
FLAG_TEXT = {
    "on_missing":            "start time not readable",
    "off_missing":           "finish time not readable",
    "time_unreadable":       "a time on this row is not readable",
    "crossed_out":           "a cell is crossed out",
    "struck_name":           "the printed name is struck through",
    "note_present":          "a note is written on this row",
    "times_outside_table":   "times are written outside the table",
    "unmatched_person":      "not matched to anyone confirmed on this call",
    "other_call":            "written against another call",
    "break_blank":           "the break cell is blank",
    "break_looks_like_time": "a time is written in the Break column, not a length",
    "break_not_submittable": "break length is not a 15-minute step",
    "header_time_differs":   "the printed header's time differs from the call's",
    "block_unassigned":      "this block matched no call on the booking",
    "no_break_written":      "no break written — read as none",
    # break_start_missing carries the T9 band, so its text is built per row.
}

# Routine, per brief 2.3 and T7: informational, the row stays ticked.
# Deliberately ONE code. break_start_missing is informational too, but holds
# the row, because where the minutes landed is a guess about a pay band.
ROUTINE_FLAGS = {"no_break_written"}

# The engine could not give the value at all, so there is nothing to write and
# nothing for a tick to mean. These lock the row rather than merely holding it.
LOCKING_FLAGS = {"on_missing", "off_missing"}


def _t9_text(band):
    """The T9 note, naming the band the minutes actually went in."""
    return ("break start not written — placed in %s; check"
            % ("Day" if band == "day" else "Night"))


def row_flags(row, t9_placed=None):
    """[{level, code, text}] for one reply row, in checkin_import's flag shape
    so the review screen renders them with the mechanism already there.

    level 'warn' is amber; 'info' is a grey note. Holding and locking are
    decided separately (row_hold / row_locked) because break_start_missing is
    an info note that still has to start unticked."""
    out = []
    for code in row.get("flags") or []:
        code = str(code)
        if code == "break_start_missing":
            out.append({"level": "info", "code": code,
                        "text": _t9_text(t9_placed or "night")})
            continue
        text = FLAG_TEXT.get(code)
        if text is None:
            # An unknown code is treated as something a person must look at,
            # never as routine: a flag this build has not been taught about is
            # exactly the case T7's headline is measuring.
            out.append({"level": "warn", "code": code,
                        "text": "flagged by the reader (%s)" % code})
            continue
        level = "info" if code in ROUTINE_FLAGS else "warn"
        out.append({"level": level, "code": code, "text": text})
    return out


def row_locked(row):
    """(True, reason) when the row cannot be written at all, else (False, None).

    Two cases, and both would otherwise reach update-call-times.php as a real
    write of a value nobody has:

      a value the engine refused — brief 2.3 says it comes back empty, not
      00:00, and an empty On or Off must not be writable;

      off_next_day with a finish that is not before the start, which no sheet
      row can express (the sheet path reads off < on AS the next day). Rather
      than write a shift the paper does not say, the row is locked and named.

    Ops enter these by hand in Enter Times, as they do today. THE GOAT has no
    place to type a time into the import review, so 'left out' is the honest
    half of the brief's 'until Ops type it (or it's left out)'."""
    codes = set(str(c) for c in (row.get("flags") or []))
    if codes & LOCKING_FLAGS:
        return True, "the reader couldn't give both times"
    on, off = parse_hhmm(row.get("on")), parse_hhmm(row.get("off"))
    if on is None or off is None:
        return True, "no start or finish time was read"
    if row.get("off_next_day") and off >= on:
        return True, ("finish %s is marked as the next day but is after the "
                      "start — enter this one by hand"
                      % str(row.get("off")))
    return False, None


def row_hold(row, flags):
    """True when the row starts UNTICKED. Every non-routine flag holds the row
    (5c: a flagged row must be impossible to accept without seeing it), and so
    does break_start_missing, routine or not, because T9 guessed its band."""
    for f in flags:
        if f["code"] == "break_start_missing":
            return True
        if f["code"] not in ROUTINE_FLAGS:
            return True
    return False


# ---- reply -> {tabs, skipped_tabs} ---------------------------------------

def split_printed_name(printed):
    """'Alpha, Ann' -> ('Alpha', 'Ann'). The printed crew list prints
    'Last, First', and only the comma is relied on; a name with no comma
    becomes a lastname, which is what the preview shows for an unmatched row.

    Note this is display only. _build_import_preview matches on EIN, so a name
    split the wrong way can never put a row on the wrong person."""
    s = str(printed or "").strip()
    if not s:
        return "", ""
    if "," not in s:
        return s, ""
    last, _, first = s.partition(",")
    return last.strip(), first.strip()


def _raw_bits(row):
    """The raw writing, cell by cell, for the note on a flagged row: 'as
    written: on 2130 / off 2am / break 30'. §5c wants the writing itself on the
    row, not only our reading of it."""
    raw = row.get("raw") or {}
    pairs = [("on", "on"), ("off", "off"), ("break start", "break_start"),
             ("break", "break_mins"), ("notes", "notes"), ("margin", "margin")]
    bits = []
    for label, key in pairs:
        v = str(raw.get(key) or "").strip()
        if v:
            bits.append("%s %s" % (label, v))
    return " / ".join(bits)


def row_note(row, flags):
    """The engine's note plus the raw writing when anything is flagged
    (brief 2.2's `note` row). One string, as parse_crew_row expects."""
    parts = []
    note = str(row.get("note") or "").strip()
    if note:
        parts.append(note)
    if flags:
        raw = _raw_bits(row)
        if raw:
            parts.append("as written: %s" % raw)
    return " · ".join(parts)


def _ein(v):
    """The EIN as an int, or None. _build_import_preview matches on an int EIN
    from the SmartStaff roster, so a string here would mean every row came back
    unmatched and Ops were shown a sheet of people who 'aren't on this call' —
    the loudest possible failure for the quietest possible contract change."""
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def convert_row(row):
    """One reply row -> the parse_crew_row dict shape.

    `no_show` is NEVER set from a scan (brief 2.2): a struck or blank row is a
    flag for someone to look at, not a declaration that the person did not
    turn up. `late` is never set either — nothing on the paper says it."""
    brk, brk_n, t9 = place_break(row)
    last, first = split_printed_name(row.get("printed_name"))
    flags = row_flags(row, t9)
    locked, _why = row_locked(row)
    return {
        "ein":         _ein(row.get("ein")),
        "lastname":    last,
        "firstname":   first,
        "status":      "",
        # A locked row must not reach the writable rows at all. Blanking `on`
        # here is what puts it in _build_import_preview's `skipped` bucket,
        # using the matcher already there rather than a second rule.
        "on":          None if locked else row.get("on"),
        "off":         None if locked else row.get("off"),
        "break":       brk,
        "break_night": brk_n,
        "late":        0,
        "note":        row_note(row, flags),
        "no_show":     False,
    }


def convert_scan(result):
    """The route's reply -> {tabs, skipped_tabs}, one tab per call.

    `tab_name` is the call name and `call_id` is set, so _build_import_preview
    maps exactly (map_by 'call_id') and never falls back to EIN overlap. The
    engine has already merged a call's blocks across pages, so one call is one
    tab even when its crew list was printed over two sheets."""
    tabs = []
    for c in result.get("calls") or []:
        if c.get("call_id") is None:
            continue
        tabs.append({
            "tab_name":  c.get("call_name") or ("Call %s" % c.get("call_id")),
            "call_id":   c.get("call_id"),
            "id_source": "scan",
            "call_time": None,
            "rows":      [convert_row(r) for r in (c.get("rows") or [])],
        })
    return {"tabs": tabs, "skipped_tabs": []}


# ---- review fields on the finished preview -------------------------------

def _person_name(p):
    last = str((p or {}).get("lastname") or "").strip()
    first = str((p or {}).get("firstname") or "").strip()
    return (last + ", " + first).strip(", ")


def _is_loose_times(entry):
    """True for a bare-times line written under a table — '2200 - 0130' with
    nobody's name on it.

    These used to arrive carrying `unmatched_person` and were listed as two
    people for Ops to add to the call (Crew Hub findings, 25 Nov). They belong
    to a row of the table, so they are shown as loose writing to check, never
    in 'On the sheet, not on this call'. An entry that names somebody, or that
    the engine guessed a person for, is a walk-on and stays in that list."""
    codes = set(str(c) for c in (entry.get("flags") or []))
    if entry.get("guessed_person"):
        return False
    if "unmatched_person" in codes:
        return False
    return "times_outside_table" in codes


def attach_flags(preview, result, candidates=None):
    """Add the scan review fields to a finished _build_import_preview result.

    Additive only — no existing key is changed, so the write path and the other
    three import sources never see a difference:

      matched.scan_flags / scan_hold   a non-routine flag (or T9) holds the row:
                                       amber, starts unticked (5c)
      matched.scan_raw                 the writing as it is on the paper
      card.scan_skipped                rows that cannot be written, with the
                                       flag text and the raw writing, so a
                                       refused value is visible rather than
                                       silently absent
      card.scan_extras                 'On the sheet, not on this call' —
                                       walk-ons and other-call entries, verbatim
      card.scan_loose_times            bare times written outside a table
      card.scan_summary                the engine's per-call summary line
      preview.unassigned_blocks        blocks that matched no call, with their
                                       candidates for the pick buttons
      preview.scan_candidates          {call_id: {call_name, start, length}} for
                                       labelling those buttons
      preview.source                   {kind: 'scan', scan_ref, pages_read,
                                        summary}

    `candidates` is {call_id: {call_name, start, length}} from the booking the
    route has already fetched; the route passes it so no second SmartStaff
    request is made for one button label."""
    by_call = {}
    for c in result.get("calls") or []:
        try:
            by_call[int(c.get("call_id"))] = c
        except (TypeError, ValueError):
            continue

    for card in preview.get("calls") or []:
        try:
            cid = int(card.get("call_id"))
        except (TypeError, ValueError):
            cid = None
        rc = by_call.get(cid)
        if rc is None:
            continue

        card["scan_summary"] = rc.get("summary") or ""

        # Index the reply's rows by EIN, and by printed name for a row with no
        # EIN (an unmatched person has no EIN to join on).
        by_ein, by_name = {}, {}
        for r in rc.get("rows") or []:
            if _ein(r.get("ein")) is not None:
                by_ein.setdefault(_ein(r.get("ein")), r)
            last, first = split_printed_name(r.get("printed_name"))
            by_name.setdefault((last + ", " + first).strip(", "), r)

        for m in card.get("matched") or []:
            r = by_ein.get(_ein(m.get("ein")))
            if r is None:
                continue
            brk, _bn, t9 = place_break(r)
            fl = row_flags(r, t9)
            if fl:
                m["scan_flags"] = fl
                m["scan_hold"] = row_hold(r, fl)
            raw = _raw_bits(r)
            if raw:
                m["scan_raw"] = raw

        # A row the engine refused is in `skipped` (convert_row blanked its
        # `on`), where the shared matcher put it. Say why, in the paper's own
        # words, rather than leaving the preview's generic "no times in sheet".
        skipped = []
        for s in card.get("skipped") or []:
            r = by_ein.get(_ein(s.get("ein"))) if s.get("ein") is not None else None
            if r is None:
                r = by_name.get(s.get("name"))
            if r is None:
                continue
            brk, _bn, t9 = place_break(r)
            locked, why = row_locked(r)
            skipped.append({
                "ein":   s.get("ein"),
                "name":  s.get("name"),
                "why":   why or "no times read",
                "flags": row_flags(r, t9),
                "raw":   _raw_bits(r),
            })
        card["scan_skipped"] = skipped

        extras, loose = [], []
        for e in rc.get("extra_entries") or []:
            item = {
                "text":  str(e.get("text") or ""),
                "flags": row_flags(e),
                "guessed_person": e.get("guessed_person"),
                "guessed_name": _person_name(e.get("guessed_person")),
            }
            (loose if _is_loose_times(e) else extras).append(item)
        card["scan_extras"] = extras
        card["scan_loose_times"] = loose

    blocks = []
    for b in result.get("unassigned_blocks") or []:
        blocks.append({
            "page":            b.get("page"),
            "block":           b.get("block"),
            "file":            b.get("file"),
            "printed_header":  str(b.get("printed_header") or ""),
            "rows_on_block":   b.get("rows_on_block"),
            "reason":          b.get("reason"),
            "qr_call_id":      b.get("qr_call_id"),
            "candidate_call_ids": list(b.get("candidate_call_ids") or []),
        })
    preview["unassigned_blocks"] = blocks
    preview["scan_candidates"] = candidates or {}
    preview["source"] = {
        "kind":       "scan",
        # An ARRAY: Ops can send several files in one go and each is its own
        # object in the bucket. A pick button re-runs on exactly this list.
        "scan_ref":   list(result.get("scan_ref") or []),
        "pages_read": result.get("pages_read"),
        "summary":    result.get("summary") or "",
    }
    return preview


def rerun_call_ids(all_call_ids, candidate_ids, picked_id):
    """The `call_ids` a pick button re-runs with: every call on the booking
    MINUS the candidates that were not picked (Mike, 25 Nov; addendum E).

    NOT just the picked call. The other blocks on the same page are assigned in
    the same request, and narrowing to one call would orphan them — Crew Hub's
    own note on this says the same. Dropping only the losing candidates is
    enough: the tie resolves and every other block re-assigns as before."""
    try:
        picked = int(picked_id)
    except (TypeError, ValueError):
        return []
    losers = set()
    for c in candidate_ids or []:
        try:
            c = int(c)
        except (TypeError, ValueError):
            continue
        if c != picked:
            losers.add(c)
    out = []
    for c in all_call_ids or []:
        try:
            c = int(c)
        except (TypeError, ValueError):
            continue
        if c not in losers:
            out.append(c)
    return out


# ---- upstream errors -----------------------------------------------------

def scan_error_message(status, upstream=""):
    """A specific operator message for each status the scan routes answer with.

    Crew Hub's own text is used where it is already the right thing to say to
    an operator — the 403 allow-list refusal is worded for Ops in the brief, so
    repeating it here in different words would be a second copy that can drift.
    """
    u = (upstream or "").strip()
    if status == 400:
        return "Crew Hub refused the scan: %s" % (u or "bad request")
    if status == 401:
        return "Crew Hub says THE GOAT sent no service key."
    if status == 403:
        # Either not allow-listed (T8) or a scan path that isn't this
        # booking's. Crew Hub says which, in words meant for Ops.
        return u or "Crew Hub refused the scan for this user."
    if status == 404:
        return "The upload didn't finish — send the file again."
    if status == 413:
        return u or "That's too big — send fewer pages, or smaller files."
    if status == 422:
        return u or "Nothing readable on that sheet."
    if status == 502:
        return "Crew Hub couldn't load this booking from SmartStaff. Try again in a minute."
    if status == 503:
        return "Scanning is unavailable (503)%s" % ((": " + u) if u else "")
    if status == 504:
        return u or "Reading the sheet took too long — try one sheet at a time."
    return "Crew Hub's scan route returned HTTP %s%s." % (status, (": " + u) if u else "")


# ---- HEIC: the one place this module leaves the process ------------------
#
# Crew Hub cannot read HEIC and cannot be made to: the page loader refuses
# .heic/.heif by name, and the Linux sharp build on Vercel has no HEVC decoder
# (Crew Hub's own findings, 25 Nov, checked at the symbol level). The upload-url
# step refuses a .heic before any bytes are sent. So THE GOAT converts, with
# sips, which is part of macOS.
#
# Checked on this machine before relying on it (brief 2.3 / addendum B):
# /usr/bin/sips is present and Apple-signed; THE GOAT's bundle is hardened
# runtime but NOT sandboxed (entitlements.plist has no app-sandbox key), so it
# may spawn it; a real HEVC .heic converted to JPEG with the bundle's own
# DYLD_* variables set, which is the one trap a frozen app brings — dyld purges
# those for a SIP-protected binary. The shipped app already spawns /usr/bin/open
# this way for the health log folder.
#
# Even so, nothing here assumes it: sips_available() is a real probe and
# convert_heic_files falls back to the brief's refusal wording, so a machine
# where sips has gone missing says "Save it as JPEG or PDF" instead of failing
# somewhere further down.

SIPS = "/usr/bin/sips"

HEIC_EXTS = (".heic", ".heif")

HEIC_REFUSAL = "Save it as JPEG or PDF"


def is_heic(filename):
    return str(filename or "").lower().endswith(HEIC_EXTS)


def sips_available():
    """True when sips is on this machine and runs. Cheap (--help, no file) and
    never raises, so a caller can ask before promising a conversion."""
    if not os.path.isfile(SIPS) or not os.access(SIPS, os.X_OK):
        return False
    try:
        p = subprocess.run([SIPS, "--help"], stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, timeout=10)
    except Exception:
        return False
    return p.returncode == 0


def heic_to_jpeg(data, timeout=60):
    """HEIC bytes -> JPEG bytes. Raises RuntimeError with the operator wording
    if sips isn't there or can't read the file.

    Via temp files because sips takes paths, not a stream. A fixed argv list,
    never a shell, and the only paths involved are ones made here — nothing a
    filename from the file picker could reach into."""
    if not os.path.isfile(SIPS):
        raise RuntimeError(HEIC_REFUSAL)
    tmp = tempfile.mkdtemp(prefix="goat-scan-")
    src = os.path.join(tmp, "in.heic")
    dst = os.path.join(tmp, "out.jpg")
    try:
        with open(src, "wb") as f:
            f.write(data)
        try:
            p = subprocess.run([SIPS, "-s", "format", "jpeg", src, "--out", dst],
                               stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
                               timeout=timeout)
        except FileNotFoundError:
            raise RuntimeError(HEIC_REFUSAL)
        except subprocess.TimeoutExpired:
            raise RuntimeError("That photo took too long to convert. " + HEIC_REFUSAL)
        if p.returncode != 0 or not os.path.exists(dst):
            raise RuntimeError(HEIC_REFUSAL)
        with open(dst, "rb") as f:
            out = f.read()
        if not out:
            raise RuntimeError(HEIC_REFUSAL)
        return out
    finally:
        for path in (src, dst):
            try:
                os.remove(path)
            except OSError:
                pass
        try:
            os.rmdir(tmp)
        except OSError:
            pass


def jpeg_name(filename):
    """'sheet.HEIC' -> 'sheet.jpg'. Only the extension changes: the upload-url
    step reads nothing else from the name."""
    base = os.path.basename(str(filename or "scan"))
    root, _ext = os.path.splitext(base)
    return (root or "scan") + ".jpg"


def convert_heic_files(files):
    """[(filename, bytes)] -> ([(filename, bytes)], [converted names]).

    Every HEIC becomes a JPEG before the upload-url step, because Crew Hub
    refuses the extension there — deliberately, so a phone photo is turned away
    before megabytes travel. Anything else is passed through untouched."""
    out, converted = [], []
    for name, data in files:
        if not is_heic(name):
            out.append((name, data))
            continue
        out.append((jpeg_name(name), heic_to_jpeg(data)))
        converted.append(os.path.basename(str(name)))
    return out, converted
