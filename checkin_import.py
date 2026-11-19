"""Import Times from check-in — the converter (BRIEF-import-times-from-checkin, Part 2).

Crew Hub's read-only settlement feed (GET /api/goat/checkin-settlement) already
carries PAID times: Q51, CORRECTION-2 rounding, Late marks and time corrections
are all applied there, by the same code as the check-in Times view and its CSV.
This module does not hold a pay rule of its own. It only:

  1. reshapes the feed into the {tabs, skipped_tabs} structure the Google Sheet
     and .xlsx readers produce (one tab per call, one parse_crew_row-shaped row
     per person), so _build_import_preview runs on it UNCHANGED; and
  2. works out the review flags the operator must see, which the route attaches
     to the finished preview as extra fields. Nothing here reaches the write.

Pure: no Flask, no SmartStaff, no network. Wiring is in app.py.

The one splitter: breaks are split into day and night by their REAL time with
derivation._split_totals — the same function and the same 08:00 / 20:00 bands
as worked hours — never by a second copy of the boundary logic.
"""

from datetime import datetime, timedelta

from derivation import _split_totals

try:
    from zoneinfo import ZoneInfo
except ImportError:                      # pragma: no cover - py<3.9
    from backports.zoneinfo import ZoneInfo

MELB = ZoneInfo("Australia/Melbourne")

# Same day/night bands as worked hours (estimator_calc defaults, derivation cfg).
SPLIT_CFG = {"dayStart": "08:00", "nightStart": "20:00"}

# A person NOT marked late whose tap is this long after the call's scheduled
# start (or on another day) is paid from the start under Q51 — correctly — but a
# gap that big is usually a mistake or a swap-in, so a person should look.
# Found on prod 19 Nov: a 6 Nov test tap paid from a 1 Nov call start.
LATE_TAP_GAP_MINS = 60


# ── time helpers ────────────────────────────────────────────────────────────

def parse_iso(s):
    """Feed ISO string (with offset) -> aware datetime, or None."""
    if not s:
        return None
    try:
        d = datetime.fromisoformat(str(s).replace("Z", "+00:00"))
    except ValueError:
        return None
    if d.tzinfo is None:
        d = d.replace(tzinfo=MELB)
    return d


def melb_wall(d):
    """Aware datetime -> naive Melbourne wall-clock datetime."""
    return d.astimezone(MELB).replace(tzinfo=None)


def melb_hhmm(s):
    """Feed ISO -> Melbourne local 24-hour 'HH:MM', or None when null."""
    d = parse_iso(s)
    if d is None:
        return None
    return melb_wall(d).strftime("%H:%M")


def _hhmm_mins(mins):
    mins = int(mins or 0)
    return "%02d:%02d" % (mins // 60, mins % 60)


def split_breaks(breaks):
    """[(start ISO, mins)] -> ('HH:MM' day, 'HH:MM' night), each break split by
    its real Melbourne time with derivation._split_totals, summed per band."""
    day = night = 0
    for b in breaks or []:
        start = parse_iso(b.get("start"))
        mins = int(b.get("mins") or 0)
        if start is None or mins <= 0:
            continue
        s = melb_wall(start)
        d_h, _n_h = _split_totals(s, s + timedelta(minutes=mins), SPLIT_CFG)
        d_m = int(round(d_h * 60))
        d_m = max(0, min(mins, d_m))
        day += d_m
        night += mins - d_m          # the two bands always sum to the break
    return _hhmm_mins(day), _hhmm_mins(night)


# ── the one Late mapping (O5, Mike 19 Nov) ──────────────────────────────────

def status_and_late(row):
    """(status, late) for the parse_crew_row shape. Status Late ONLY when the
    boss pressed Late. A typed sign-on after the start is already paid from that
    time (paid_on) but is not marked Late. One line to change if that changes."""
    if row.get("state") == "no_show":
        return "No Show", 0
    if row.get("late_source") == "mark":
        return "Late", 1
    return "", 0


# ── feed -> {tabs, skipped_tabs} ────────────────────────────────────────────

def convert_row(row):
    """One feed row -> the parse_crew_row dict shape."""
    status, late = status_and_late(row)
    no_show = row.get("state") == "no_show"
    brk, brk_n = split_breaks(row.get("breaks"))
    return {
        "ein":         row.get("ein"),
        "lastname":    row.get("lastname") or "",
        "firstname":   row.get("firstname") or "",
        "status":      status,
        "on":          None if no_show else melb_hhmm(row.get("paid_on")),
        "off":         None if no_show else melb_hhmm(row.get("paid_off")),
        "break":       brk,
        "break_night": brk_n,
        "late":        late,
        "note":        row.get("note") or "",
        "no_show":     no_show,
    }


def convert_feed(feed):
    """Settlement feed -> {tabs, skipped_tabs}, one tab per call. `tab_name` is
    the call name and `call_id` is set, so _build_import_preview maps exactly."""
    tabs = []
    for c in feed.get("calls") or []:
        sched = parse_iso(c.get("scheduled_start"))
        tabs.append({
            "tab_name":  c.get("call_name") or ("Call %s" % c.get("call_id")),
            "call_id":   c.get("call_id"),
            "id_source": "checkin",
            "call_time": melb_wall(sched).isoformat() if sched else None,
            "rows":      [convert_row(r) for r in (c.get("rows") or [])],
        })
    return {"tabs": tabs, "skipped_tabs": []}


# ── review flags ────────────────────────────────────────────────────────────

def _warn(code, text):
    return {"level": "warn", "code": code, "text": text}


def _info(code, text):
    return {"level": "info", "code": code, "text": text}


def row_flags(row, scheduled_start, now):
    """Flags for one feed row. 'warn' rows start unticked in the review; 'info'
    rows stay ticked. scheduled_start / now are aware datetimes (or None)."""
    out = []
    state = row.get("state")
    paid_on = parse_iso(row.get("paid_on"))
    paid_off = parse_iso(row.get("paid_off"))
    tapped_on = parse_iso(row.get("tapped_on"))

    if scheduled_start is not None and now is not None and scheduled_start > now \
       and state in ("on", "off"):
        out.append(_warn("future_call", "call hasn't started yet"))
    if state == "on":
        out.append(_warn("no_off", "signed on, no sign-off"))
    if state == "off" and paid_on is None:
        out.append(_warn("no_on", "sign-off with no sign-on"))
    if state == "not_on":
        out.append(_warn("not_on", "rostered, never tapped"))
    if row.get("on_roster") is False:
        out.append(_warn("off_roster", "tapped, but no longer on the confirmed roster"))
    if row.get("ein") is None:
        out.append(_warn("no_ein", "no Crew Hub record — can't be matched"))

    for b in row.get("breaks") or []:
        bs = parse_iso(b.get("start"))
        if bs is None:
            continue
        be = bs + timedelta(minutes=int(b.get("mins") or 0))
        if (paid_on is not None and bs < paid_on) or (paid_off is not None and be > paid_off):
            out.append(_warn("break_outside",
                             "break %s +%dm is outside the paid times"
                             % (melb_wall(bs).strftime("%H:%M"), int(b.get("mins") or 0))))
            break

    if row.get("under_4h"):
        out.append(_warn("under_4h", "under the 4-hour minimum (not applied)"))

    if row.get("late_source") == "typed_start" and paid_on is not None:
        out.append(_info("typed_start", "late by typed sign-on %s" % melb_wall(paid_on).strftime("%H:%M")))

    if not row.get("late") and tapped_on is not None and scheduled_start is not None \
       and state in ("on", "off"):
        gap = (tapped_on - scheduled_start).total_seconds() / 60.0
        other_day = melb_wall(tapped_on).date() != melb_wall(scheduled_start).date()
        if gap > LATE_TAP_GAP_MINS or other_day:
            tw = melb_wall(tapped_on)
            when = tw.strftime("%H:%M") if not other_day else tw.strftime("%a %d %b %H:%M")
            out.append(_warn("late_tap", "tapped %s, paid from call start" % when))
    return out


def _name(row):
    last = row.get("lastname") or ""
    first = row.get("firstname") or ""
    return (last + ", " + first).strip(", ") or ("user %s" % row.get("user_id"))


def attach_flags(preview, feed, now=None, roster_status=None):
    """Add the check-in review fields to a finished _build_import_preview result.
    Additive only — no existing key is changed, so the write path and the sheet
    imports never see a difference:

      card.checkin_notes   [{name, ein, level, text}] every flagged person on the
                           call, matched or not (not_on, no EIN, off-roster…)
      card.checkin_future  the call's scheduled start is after `now`
      matched.checkin_flags / matched.checkin_hold
                           hold = a warn flag: the row starts unticked
      roster_only.status   the person's SmartStaff status on that call, from
                           roster_status {call_id: {ein: status}} (the same
                           get-call-times.php answers the preview matched on);
                           None when it isn't known
      preview.unknown_call_ids, preview.source (kind, generated_at, mode)
    """
    rs = {}
    for k, v in (roster_status or {}).items():
        try:
            rs[int(k)] = v or {}
        except (TypeError, ValueError):
            continue
    if now is None:
        now = datetime.now(MELB)
    by_call = {}
    for c in feed.get("calls") or []:
        try:
            by_call[int(c.get("call_id"))] = c
        except (TypeError, ValueError):
            continue

    for card in preview.get("calls") or []:
        try:
            cid = int(card.get("call_id"))
        except (TypeError, ValueError):
            cid = None
        statuses = rs.get(cid) or {}
        for ro in card.get("roster_only") or []:
            ro["status"] = statuses.get(ro.get("ein"))
        fc = by_call.get(cid)
        if fc is None:
            continue
        sched = parse_iso(fc.get("scheduled_start"))
        card["checkin_future"] = bool(sched is not None and sched > now)
        notes, flags_by_ein = [], {}
        for r in fc.get("rows") or []:
            fl = row_flags(r, sched, now)
            if not fl:
                continue
            if r.get("ein") is not None:
                flags_by_ein.setdefault(r.get("ein"), []).extend(fl)
            for f in fl:
                notes.append({"name": _name(r), "ein": r.get("ein"),
                              "level": f["level"], "code": f["code"], "text": f["text"]})
        for m in card.get("matched") or []:
            fl = flags_by_ein.get(m.get("ein")) or []
            if fl:
                m["checkin_flags"] = fl
                m["checkin_hold"] = any(f["level"] == "warn" for f in fl)
        card["checkin_notes"] = notes

    preview["unknown_call_ids"] = list(feed.get("unknown_call_ids") or [])
    preview["source"] = {"kind": "checkin",
                         "generated_at": feed.get("generated_at"),
                         "mode": feed.get("mode")}
    return preview


# ── upstream errors ─────────────────────────────────────────────────────────

def feed_error_message(status, upstream_error=""):
    """A specific operator message for each status the feed answers with."""
    u = (upstream_error or "").strip()
    if status == 400:
        return "Crew Hub refused the request: %s" % (u or "bad booking or call id")
    if status == 401:
        return "Crew Hub says THE GOAT sent no check-in key."
    if status == 403:
        return "Crew Hub rejected THE GOAT's check-in key — GOAT_CHECKIN_KEY doesn't match Crew Hub's."
    if status == 502:
        return "Crew Hub couldn't load this booking from SmartStaff. Try again in a minute."
    if status == 503:
        # Not interpreted: a 503 is either the key unset on Vercel or the tap
        # log / crew list unreadable, and Crew Hub's own text says which.
        return "Check-in feed unavailable (503)%s" % ((": " + u) if u else "")
    return "Crew Hub check-in feed returned HTTP %s%s." % (status, (": " + u) if u else "")


def roster_status_from_call_times(answers):
    """{call_id: {ein: status}} from recorded get-call-times.php answers
    ({call_id: data}). Same EIN coercion as _build_import_preview's roster."""
    out = {}
    for cid, data in (answers or {}).items():
        m = {}
        for cm in (data or {}).get("crew", []) if isinstance(data, dict) else []:
            try:
                e = int(cm.get("ein"))
            except (TypeError, ValueError):
                continue
            m[e] = cm.get("status")
        try:
            out[int(cid)] = m
        except (TypeError, ValueError):
            continue
    return out
