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

THE 4-HOUR MINIMUM (BRIEF-import-apply-4h-minimum rev 5; decisions 24 and 23,
Mike, 22 Nov 2026). The Google Sheet applied the minimum by hand: whoever keyed
the times typed the topped-up finish. The import has to do the same, so
apply_minimum() decides each worked row and convert_row() writes the topped-up
finish. The RULE is not here: it is derivation.minimum_top_up(), and this
module only hands it the feed's paid times and the call's BOOKED length and
scheduled start. A call booked under 4 hours is paid as worked; a call booked
for 4 or more is written to its scheduled start + 4 hours (or the person's own
later finish); a call with no booked length is held for a person to decide.
There is no continuity: other calls the person worked do not matter. The
feed's own `under_4h` is not used to decide anything.

ADDED ON THE DAY (Mike, 25-26 Nov 2026; claude/PLAN-added-crew-on-the-day.md,
slice 3). A Crew Boss can add someone to a call on the check-in sheet — MOVED
from another call of the booking, or CALLED IN. The feed sends them with
`unbooked: true` and an `added` object; a moved person also comes through on
the call they left as `state: "moved"`. They are not on SmartStaff's roster, so
_build_import_preview leaves them unmatched; attach_flags() lifts them out into
`card.checkin_added` (UNTICKED by default — ticking books them on, an explicit
act, never a side effect of reviewing) and `card.checkin_moved`. The booking is
done by app.checkin_add_crew(), one person at a time.

LEFT EARLY (Mike, 25 Nov 2026). A row whose feed `left_early` is set was
marked by the Crew Boss as having left early (injured, always with a Mitta
reference; sick; sent home; personal; other). It is paid as worked: no
minimum, whatever the booked length. It stays TICKED, with an info note
giving the reason. The rule is still minimum_top_up(paid_as_worked=True).
"""

from datetime import datetime, timedelta

from derivation import DerivationError, _split_totals, minimum_top_up

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
    """One feed row -> the parse_crew_row dict shape. When apply_minimum() has
    topped the row up, `off` is the topped-up finish: that is what gets written,
    as it was when the times were keyed, and what the invoice bills."""
    status, late = status_and_late(row)
    no_show = row.get("state") == "no_show"
    brk, brk_n = split_breaks(row.get("breaks"))
    off = None if no_show else melb_hhmm(row.get("paid_off"))
    mn = row.get(MIN_KEY) or {}
    if mn.get("applied") and off is not None:
        off = mn["off"]
    return {
        "ein":         row.get("ein"),
        "lastname":    row.get("lastname") or "",
        "firstname":   row.get("firstname") or "",
        "status":      status,
        "on":          None if no_show else melb_hhmm(row.get("paid_on")),
        "off":         off,
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


# ── the 4-hour minimum ──────────────────────────────────────────────────────

# apply_minimum() leaves its answer on each feed row under this key. The feed is
# never sent to the browser; attach_flags() copies what the review needs.
MIN_KEY = "_minimum"


def _int_or_none(v):
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def _booked_calls(booking_calls):
    """{call_id: (booked length as SmartStaff sent it, naive Melbourne start or
    None)} from get-booking.php's `calls`. A cancelled call is left out, so its
    rows are treated as having no booked length."""
    out = {}
    for c in booking_calls or []:
        cid = _int_or_none(c.get("call_id"))
        if cid is None or c.get("cancelled") or c.get("cancelled_at"):
            continue
        try:
            day = datetime.fromtimestamp(int(c.get("start_date")), MELB).date()
            hh, mm = [int(x) for x in str(c.get("start_time") or "").split(":")[:2]]
            start = datetime(day.year, day.month, day.day, hh, mm)
        except (TypeError, ValueError, OverflowError, OSError):
            start = None
        out[cid] = (c.get("est_length"), start)
    return out


def _worked_span(row):
    """(on, off) naive Melbourne datetimes when the row is WORKED — signed off,
    with both paid times, off after on — else None. A sign-on with no sign-off,
    a no-show and a not-on are not worked and get no minimum."""
    if row.get("state") != "off":
        return None
    on, off = parse_iso(row.get("paid_on")), parse_iso(row.get("paid_off"))
    if on is None or off is None or off <= on:
        return None
    return melb_wall(on), melb_wall(off)


def left_early_text(le):
    """The preview note for a Left early row, e.g.
    'left early (Injured, Mitta M-1234; rolled ankle) \u2014 paid as worked,
    no 4-hour minimum'. The reference and note are left out when absent."""
    le = le or {}
    reason = str(le.get("reason_label") or le.get("reason") or "").strip()
    ref = str(le.get("mitta_ref") or "").strip()
    note = str(le.get("note") or "").strip()
    head = ", ".join([p for p in (reason, ("Mitta " + ref) if ref else "") if p])
    inner = "; ".join([p for p in (head, note) if p])
    return (("left early (%s)" % inner) if inner else "left early") + \
        " \u2014 paid as worked, no 4-hour minimum"


def _hours_text(v):
    """Booked length for a note: '3 h', '3.5 h'."""
    f = float(v)
    return ("%d h" % f) if f == int(f) else ("%s h" % (("%.2f" % f).rstrip("0").rstrip(".")))


def apply_minimum(feed, booking_calls):
    """Decide the 4-hour minimum for every worked row of the feed and leave the
    answer on the row under MIN_KEY. Returns the feed.

    `booking_calls` is get-booking.php's `calls` list; only call_id, est_length
    (the BOOKED length), start_date, start_time and cancelled are read. The
    scheduled start is the feed's own scheduled_start where it has one — the
    same instant its paid times were worked out against — else SmartStaff's.

    Per worked row:
      applied      True when the finish was extended
      off          'HH:MM' finish to write (the topped-up one when applied)
      worked_off   'HH:MM' paid finish, before any top-up
      reason       derivation.minimum_top_up's reason, or 'error'
      flags        review flags, in attach_flags' {level, code, text} shape;
                   only on rows paid under 4 hours
    The feed's paid times are used as they are: Q51 and CORRECTION-2 are already
    in them, and rounding them again would be a second copy of that rule."""
    booked = _booked_calls(booking_calls)

    for c in feed.get("calls") or []:
        cid = _int_or_none(c.get("call_id"))
        length, start = booked.get(cid, (None, None))
        sched = parse_iso(c.get("scheduled_start"))
        if sched is not None:
            start = melb_wall(sched)

        for r in c.get("rows") or []:
            span = _worked_span(r)
            if span is None:
                continue
            on, off = span
            worked_off = off.strftime("%H:%M")
            entry = {"applied": False, "off": worked_off, "worked_off": worked_off,
                     "reason": None, "flags": []}
            r[MIN_KEY] = entry

            # derivation RAISES rather than guess (a top-up with no scheduled
            # start, for one). One row it cannot decide must not take the whole
            # preview down: that row is held with a warning, nothing is applied
            # to it, and everyone else's rows load as normal.
            try:
                top = minimum_top_up(on, off, length, start, bool(r.get("late")), SPLIT_CFG,
                                     paid_as_worked=bool(r.get("left_early")))
            except (DerivationError, TypeError, ValueError) as e:
                entry["reason"] = "error"
                entry["flags"].append(_warn(
                    "min_unknown",
                    "under 4 hours, and the 4-hour minimum couldn't be worked "
                    "out (%s) \u2014 not applied" % e))
                continue

            reason = entry["reason"] = top["reason"]

            if reason == "topped":
                entry["applied"] = True
                entry["off"] = top["billable_end"].strftime("%H:%M")
                if top["late_start"]:
                    entry["flags"].append(_info(
                        "late_min",
                        "late start \u2014 topped to the call's finish (%s), "
                        "no 4-hour minimum" % entry["off"]))
                else:
                    entry["flags"].append(_info(
                        "min_applied",
                        "4-hour minimum applied: finish %s \u2192 %s"
                        % (worked_off, entry["off"])))
            elif reason == "left_early":
                entry["flags"].append(_info("left_early", left_early_text(r.get("left_early"))))
            elif reason == "own_finish_later":
                entry["flags"].append(_info(
                    "late_min", "late start \u2014 paid as worked, no 4-hour minimum"))
            elif reason == "booked_short":
                entry["flags"].append(_info(
                    "booked_short",
                    "booked for %s \u2014 paid as worked, no 4-hour minimum"
                    % _hours_text(length)))
            elif reason == "no_booked_length":
                entry["flags"].append(_warn(
                    "no_booked_length",
                    "no booked length \u2014 4-hour minimum not worked out"))
            # four_hours_or_more: nothing to say.
    return feed


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
    if row.get("on_roster") is False and not row.get("unbooked"):
        # An ADDED person is never on SmartStaff's roster — that is the point.
        # They get their own group (checkin_added), not this warning.
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

    mn = row.get(MIN_KEY)
    if mn is not None:
        # apply_minimum() has decided (decision 24). The feed's under_4h is per
        # call and net of breaks, and is not used.
        out.extend(mn.get("flags") or [])
    elif row.get("under_4h"):
        # Minimum not worked out for this row (no apply_minimum, or the row
        # isn't a worked one): say so rather than stay silent.
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


def added_and_moved(fc, call_names, sched, now):
    """(added, moved) for one feed call — see ADDED ON THE DAY above.

    added: one entry per `unbooked` row, with the times convert_row() writes
    (the 4-hour minimum and Left early already applied), who added them and
    why, its flags, and `writable` — a sign-on AND a sign-off, so there are
    times to write. Never pre-ticked.
    moved: one entry per row with state "moved": they were moved off THIS
    call; their times are on to_call_id."""
    added, moved = [], []
    for r in fc.get("rows") or []:
        if r.get("state") == "moved":
            to = r.get("moved_to_call_id")
            moved.append({"user_id": r.get("user_id"), "ein": r.get("ein"), "name": _name(r),
                          "to_call_id": to,
                          "to_call_name": call_names.get(_int_or_none(to), "") if to is not None else ""})
            continue
        if not r.get("unbooked"):
            continue
        a = r.get("added") or {}
        conv = convert_row(r)
        mn = r.get(MIN_KEY) or {}
        from_id = a.get("moved_from_call_id") if a.get("reason") == "moved" else None
        added.append({
            "user_id": r.get("user_id"),
            "ein": r.get("ein"),
            "name": _name(r),
            "reason": a.get("reason") or "called_in",
            "moved_from_call_id": from_id,
            "moved_from_call_name": call_names.get(_int_or_none(from_id), "") if from_id is not None else "",
            "covering_for_name": a.get("covering_for_name") or "",
            "by_name": a.get("by_name") or "",
            "state": r.get("state"),
            "on": conv["on"], "off": conv["off"],
            "break": conv["break"], "break_night": conv["break_night"],
            "late": conv["late"], "note": conv["note"],
            "worked_off": mn.get("worked_off") if mn.get("applied") else None,
            "flags": row_flags(r, sched, now),
            "writable": bool(r.get("state") == "off" and conv["on"] and conv["off"]
                             and r.get("ein") is not None),
        })
    return added, moved


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
      matched.checkin_worked_off
                           the paid finish BEFORE the 4-hour minimum, only on a
                           row apply_minimum() topped up; matched.off is then
                           the topped-up finish, so the review can show both
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

    call_names = {}
    for c in feed.get("calls") or []:
        try:
            call_names[int(c.get("call_id"))] = c.get("call_name") or ""
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
        notes, flags_by_ein, topped = [], {}, {}
        added, moved = added_and_moved(fc, call_names, sched, now)
        lifted = set(a["ein"] for a in added if a["ein"] is not None)
        moved_eins = set(m["ein"] for m in moved if m["ein"] is not None)
        card["checkin_added"] = added
        card["checkin_moved"] = moved
        # They have their own groups now: not "on check-in, not booked on this
        # call", and a moved person is not "no times".
        card["unmatched"] = [u for u in (card.get("unmatched") or []) if u.get("ein") not in lifted]
        card["skipped"] = [k for k in (card.get("skipped") or []) if k.get("ein") not in moved_eins]
        for r in fc.get("rows") or []:
            if r.get("unbooked") or r.get("state") == "moved":
                continue
            mn = r.get(MIN_KEY) or {}
            if mn.get("applied") and r.get("ein") is not None:
                topped[r.get("ein")] = mn.get("worked_off")
            fl = row_flags(r, sched, now)
            if not fl:
                continue
            if r.get("ein") is not None:
                flags_by_ein.setdefault(r.get("ein"), []).extend(fl)
            for f in fl:
                notes.append({"name": _name(r), "ein": r.get("ein"),
                              "level": f["level"], "code": f["code"], "text": f["text"]})
        for m in card.get("matched") or []:
            if m.get("ein") in topped:
                m["checkin_worked_off"] = topped[m.get("ein")]
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
