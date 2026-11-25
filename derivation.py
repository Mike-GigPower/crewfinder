"""
Boss time entry — derivation of BILLABLE HOURS from submitted times.

Phase 2a slice 3. Pure functions: no database, no SmartStaff calls, no Flask
route, no I/O of any kind. Inputs are passed in, outputs are returned. Wiring
belongs to slices 4 and 7.

That is deliberate. This is where payroll correctness lives, and a pure function
is exhaustively testable without a database, a session or a fixture booking.

────────────────────────────────────────────────────────────────────────────
WHERE THIS STOPS — READ BEFORE ADDING ANYTHING
────────────────────────────────────────────────────────────────────────────

generateCallData() in classes/class.accounting.php is a THIRD calculation
engine (found 26 Aug 2026). It does its own day/night split via getHours(),
plus public holidays, is_pubhol_tomorrow, Sunday loading and T1/T2/T3 rate
bands.

THE TWO ARE SEQUENTIAL, NOT COMPETING:

    derivation.py    produces HOURS
    generateCallData turns hours into MONEY

So this module MUST NOT compute public holidays, Sunday loading, rate bands, or
anything with a dollar in it. Someone will be tempted, because by the time you
are here the data is all in scope. Do not. Two engines disagreeing about money
is how an invoice and a payslip stop matching.

STILL OPEN, for slice 7 and not for this module: the Ops review will show a
day/night split from estimator_calc's dayStart/nightStart, while the invoice
uses getHours(). If those two rules disagree, Ops see one split and the invoice
shows another. Worth comparing before the review surface ships.

────────────────────────────────────────────────────────────────────────────
WHY THE SPLITTER IS IMPORTED AND NOT REIMPLEMENTED
────────────────────────────────────────────────────────────────────────────

split_day_night() lives in estimator_calc.py. Its own docstrings record a
transition-night bug that logged eleven wall-clock hours as ten, and a midnight
over-quote of about 27%. A second copy of that logic would be worse than one,
so this module imports it and never re-derives the rule.

MODE IS ALWAYS "duration", PASSED EXPLICITLY (Q36, closed 26 Aug 2026).

A crew member working 00:00 to 08:00 across the October changeover is PAID
SEVEN HOURS. They were physically present for seven; the clock skipped one. In
duration mode the segment hours are absolute and deliberately do not sum to the
wall-clock difference across a transition — that is the point of the mode, not
a defect. It also follows from Rich's rule that hours are paid at the rate
applicable to the times as if they were actually worked: you pay for time
worked, not for time the clock displayed.

There is NO mode parameter on derive_times(). Payroll has one correct answer
here and this function does not offer a caller the chance to get it wrong.

The April transition is the mirror case: 02:30 happens twice and eight
wall-clock hours are nine real ones. Both are tested.
"""

import math

from datetime import date, datetime, timedelta

from estimator_calc import MODE_DURATION, split_day_night


# ──────────────────────────────────────────────────────────────────────────────
# Constants
# ──────────────────────────────────────────────────────────────────────────────

# CORRECTION-2 §1. Times are rounded to a quarter hour, with a five minute
# grace either side of the boundary. GRACE IS INCLUSIVE: 11:05:00 rounds back,
# 11:05:01 rounds forward. A suite written in whole minutes passes whether the
# comparison is < or <=, and that off-by-one is fifteen minutes of someone's
# pay — so the tests assert at the second.
QUARTER_MINS = 15
GRACE_MINS = 5

# Decision 17 / §6.5. Two calls joined by a LOCKED feed edge are one engagement
# when the scheduled gap between them is at most this. 60 is inclusive.
MAX_ROLL_GAP_MINS = 60

# The minimum, in hours.
#
# DECISION 24 (Mike, 22 Nov 2026): THE MINIMUM FOLLOWS THE CALL'S BOOKED LENGTH
# (SmartStaff est_length, set by Ops when the booking is built). It replaces
# CORRECTION-3's "one minimum per engagement, measured on the block total", and
# decisions 21 and 22 that briefly followed it. Mike: "in cases where the
# expected duration is set as less than 4 hours, the 4 hour minimum for salary
# and invoicing does not apply ... Booked Length only is the best approach and
# effectively reflects the intent of the Ops team when constructing the calls."
#
#   - booked under this many hours  -> no minimum; paid, and invoiced, as worked
#   - booked this many or more, and the paid span is shorter -> topped up. The
#     finish becomes the call's SCHEDULED START plus the minimum, or the
#     person's own finish when that is later
#   - no booked length (0 or missing) -> no top-up; the caller holds the row
#   - NO CONTINUITY OF ANY KIND. What the person worked before or after does
#     not matter: no call_feeds, no worked-both test, no gap.
#
# DECISION 23 (Mike, 22 Nov 2026): a late start is topped only to the finish
# on-time crew get. Under decision 24 that is the same formula for everyone.
#
# LEFT EARLY (Mike, 25 Nov 2026): someone the Crew Boss marked as having left
# early on the check-in sheet (injured, sick, sent home, personal, other) is
# paid as worked, whatever the call was booked for. "Sent home by Gig Power or
# leaving by choice would both be paid worked hours only." minimum_top_up()
# takes this as paid_as_worked=True; only the check-in import passes it.
#
# The rule is minimum_top_up(), below. It is the only copy: derive_times() and
# the check-in import (checkin_import.apply_minimum) both call it.
MIN_ENGAGEMENT_HOURS = 4.0


class DerivationError(Exception):
    """Raised when the input cannot be derived from, rather than guessed at."""


# ──────────────────────────────────────────────────────────────────────────────
# Time helpers
# ──────────────────────────────────────────────────────────────────────────────

def _parse_hhmm(value):
    """'HH:MM' or 'HH:MM:SS' -> (h, m, s). Raises rather than guessing."""
    if isinstance(value, (list, tuple)) and len(value) >= 2:
        return (int(value[0]), int(value[1]), int(value[2]) if len(value) > 2 else 0)

    if not isinstance(value, str):
        raise DerivationError("time must be a string, got %r" % (value,))

    parts = value.strip().split(":")

    if len(parts) < 2 or len(parts) > 3:
        raise DerivationError("time must be HH:MM or HH:MM:SS, got %r" % (value,))

    try:
        h = int(parts[0])
        m = int(parts[1])
        s = int(parts[2]) if len(parts) == 3 else 0
    except ValueError:
        raise DerivationError("time must be numeric, got %r" % (value,))

    if not (0 <= h <= 23 and 0 <= m <= 59 and 0 <= s <= 59):
        raise DerivationError("time out of range: %r" % (value,))

    return (h, m, s)


def _as_date(value):
    """
    Accepts a date, a datetime, an ISO 'YYYY-MM-DD' string, or a unix timestamp
    at local midnight (the SmartStaff calls.start_date convention).

    A unix timestamp is resolved with fromtimestamp(), which reads the MACHINE's
    zone. That is correct on a Melbourne box and is what SmartStaff means by the
    column — but it makes the function machine-dependent, so tests pass dates or
    ISO strings and never integers.
    """
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    if isinstance(value, str):
        parts = value.strip().split("-")
        if len(parts) != 3:
            raise DerivationError("date must be YYYY-MM-DD, got %r" % (value,))
        return date(int(parts[0]), int(parts[1]), int(parts[2]))
    if isinstance(value, (int, float)):
        return datetime.fromtimestamp(value).date()

    raise DerivationError("unsupported date value %r" % (value,))


def _dt(day, hhmm, next_day=False):
    """A naive local datetime from a date, a clock reading and a next-day flag."""
    h, m, s = _parse_hhmm(hhmm)
    base = datetime(day.year, day.month, day.day, h, m, s)
    return base + timedelta(days=1) if next_day else base


def round_to_quarter(dt):
    """
    CORRECTION-2 §1. Floor to the previous quarter; return that quarter when the
    remainder is within GRACE, otherwise the next one.

    Works in seconds so the inclusive boundary is testable at the second.
    """
    q_secs = QUARTER_MINS * 60
    grace_secs = GRACE_MINS * 60

    midnight = datetime(dt.year, dt.month, dt.day)
    since = int((dt - midnight).total_seconds())

    floored = (since // q_secs) * q_secs
    remainder = since - floored

    target = floored if remainder <= grace_secs else floored + q_secs

    return midnight + timedelta(seconds=target)


def clock_on(actual, scheduled_start):
    """
    Arriving early is not paid early: the clock starts at the scheduled start.
    Arriving after it rounds to the quarter, with the grace.
    """
    if actual <= scheduled_start:
        return scheduled_start
    return round_to_quarter(actual)


def clock_off(actual):
    """Finishing always rounds to the quarter, with the grace."""
    return round_to_quarter(actual)


# ──────────────────────────────────────────────────────────────────────────────
# Splitting
# ──────────────────────────────────────────────────────────────────────────────

def _split_totals(start, end, cfg):
    """
    (day_hours, night_hours) between two naive datetimes.

    ALWAYS mode="duration", passed explicitly — see the module docstring. In
    duration mode these will not sum to the wall-clock difference across a DST
    transition, and that is the correct answer, not a rounding artefact.
    """
    if end <= start:
        return (0.0, 0.0)

    segments = split_day_night(start, end, cfg, MODE_DURATION)

    day = math.fsum(seg["dayHrs"] for seg in segments)
    night = math.fsum(seg["nightHrs"] for seg in segments)

    return (day, night)


def _advance_real_hours(start, hours, cfg):
    """
    The wall-clock instant `hours` of REAL time after `start`.

    Naive addition is wrong across a DST transition, and wrong in a way that
    pays the wrong amount: adding two hours to 01:00 on the October Sunday
    gives a clock reading of 03:00, which is only ONE real hour later. Because
    the whole point of duration mode is that clock readings and elapsed time
    diverge, the top-up has to be measured the same way the hours are.

    Solved by correction rather than algebra: guess, measure with the same
    splitter, adjust by the difference. Converges in one step off a transition
    and two on one; the loop bound is a guard, not an expectation.
    """
    if hours <= 0:
        return start

    end = start + timedelta(hours=hours)

    for _ in range(6):
        day, night = _split_totals(start, end, cfg)
        diff = hours - (day + night)

        if abs(diff) < 1e-9:
            break

        end = end + timedelta(hours=diff)

    return end


# ──────────────────────────────────────────────────────────────────────────────
# Continuity
# ──────────────────────────────────────────────────────────────────────────────

def _scheduled_window(call):
    """(scheduled_start, scheduled_end) for a call, from its scheduled fields."""
    day = _as_date(call["start_date"])
    start = _dt(day, call["start_time"])
    return (start, start + timedelta(hours=float(call.get("est_length") or 0.0)))


def _locked_pairs(feed_edges):
    """
    The set of {a, b} call-id pairs joined by a LOCKED feed edge.

    THE MODE IS FILTERED HERE even though the parameter is documented as
    already-locked edges. Decision 20: a `recommended` edge is an offer
    suggestion nobody may have acted on, and treating it as continuity would
    cost someone a four-hour minimum on the strength of a suggestion. The filter
    is cheap; trusting the caller to have done it is not.

    An edge with no mode is treated as NOT locked. Defaulting the other way
    would make a malformed edge silently grant continuity.
    """
    pairs = set()

    for edge in feed_edges or []:
        if str(edge.get("mode", "")).strip().lower() != "locked":
            continue

        a = int(edge.get("source_call") or 0)
        b = int(edge.get("target_call") or 0)

        if a > 0 and b > 0:
            pairs.add(frozenset((a, b)))

    return pairs


def is_continuous(call_a, call_b, feed_edges):
    """
    Decision 17. BOTH conditions, measured on SCHEDULED times (decision 18):

        a LOCKED call_feeds edge joins them, AND
        the gap between them is at most MAX_ROLL_GAP_MINS

    Scheduled rather than worked because it is stable and computable before any
    times are submitted, which slice 6's notification needs.

    The gap is measured between the pair in whichever order they actually run,
    so the caller does not have to sort first.
    """
    if int(call_a["id"]) == int(call_b["id"]):
        return False

    if frozenset((int(call_a["id"]), int(call_b["id"]))) not in _locked_pairs(feed_edges):
        return False

    a_start, a_end = _scheduled_window(call_a)
    b_start, b_end = _scheduled_window(call_b)

    first_end, second_start = (a_end, b_start) if a_start <= b_start else (b_end, a_start)

    gap_mins = (second_start - first_end).total_seconds() / 60.0

    # A negative gap is an overlap, which is continuous by any reading.
    return gap_mins <= MAX_ROLL_GAP_MINS


def continuous_block(call, sibling_calls, feed_edges):
    """
    Every call in the same engagement as `call`, ordered by scheduled start.

    Transitive: A-B and B-C put all three in one block even with no A-C edge,
    because that is what rolling through three calls actually is.
    """
    everything = [call] + list(sibling_calls or [])
    by_id = {}

    for c in everything:
        by_id[int(c["id"])] = c

    block = {int(call["id"])}
    changed = True

    while changed:
        changed = False

        for cid, candidate in by_id.items():
            if cid in block:
                continue

            for member_id in list(block):
                if is_continuous(by_id[member_id], candidate, feed_edges):
                    block.add(cid)
                    changed = True
                    break

    ordered = [by_id[cid] for cid in block]
    ordered.sort(key=lambda c: _scheduled_window(c)[0])

    return ordered


# ──────────────────────────────────────────────────────────────────────────────
# The minimum
# ──────────────────────────────────────────────────────────────────────────────

def _booked_hours(value):
    """est_length -> hours, or None when it is missing, unparseable or not
    positive. None means Ops did not say how long the call was booked for."""
    try:
        hours = float(value)
    except (TypeError, ValueError):
        return None
    return hours if hours > 0 else None


def minimum_top_up(on, off, booked_hours, scheduled_start, late, cfg, paid_as_worked=False):
    """
    DECISIONS 24 AND 23 (Mike, 22 Nov 2026). THE ONE COPY OF THE MINIMUM RULE.

        on, off          the call's paid span, naive local datetimes, GROSS of
                         breaks (Q1: the minimum is measured gross and the
                         breaks come off afterwards)
        booked_hours     the call's BOOKED length (SmartStaff est_length);
                         0, None or unparseable means "no booked length"
        scheduled_start  the call's scheduled start, naive local
        late             the person is late FOR PAY on this call (a Late mark,
                         or a typed start after the call time)
        paid_as_worked   the Crew Boss marked them as having LEFT EARLY (Mike,
                         25 Nov 2026): no minimum, whatever the booked length.
                         Only the check-in import sets it; derive_times never

    Returns {span_hours, minimum_applies, reason, minimum_applied, late_start,
    billable_end}. `reason` is one of:

        four_hours_or_more  the paid span already meets the minimum. Nothing to
                            decide, whatever the call was booked for.
        left_early          marked as having left early: paid as worked. Wins
                            over every reason below, a missing booked length
                            included: there is nothing left for Ops to decide.
        booked_short        booked under the minimum: paid as worked.
        no_booked_length    no booked length: nothing is topped up, and the
                            caller should hold the row for a person to decide.
        topped              booked for the minimum or more and paid under it:
                            the finish is extended to the scheduled start plus
                            the minimum.
        own_finish_later    as `topped`, but the person's own finish is already
                            past that point, so it stands. Only a late start
                            gets here.

    `minimum_applies` is True when the CALL is one that gets the minimum
    (booked for it or more); `minimum_applied` is True when this row's finish
    was actually extended. `billable_end` is `off` when it was not.

    DECISION 23. The finish is max(own finish, scheduled start + minimum) for
    everyone. On-time crew are paid from the scheduled start, so that is
    exactly the minimum; a late starter gets the same FINISH, not the minimum
    from their own start. On a 22:00 call that ran to 00:30: on time is written
    22:00 to 02:00 (4 h), paid from 22:15 is 22:15 to 02:00 (3.75 h), paid
    from 23:00 is 23:00 to 02:00 (3 h). `late` does not change the sum; it is
    reported back as `late_start` so the caller can say why.

    Q31: the top-up EXTENDS THE FINISH and the clock sets the rate. Those
    hours are night or day because split_day_night() says so, not because
    anything here decides it.

    REAL hours throughout, not clock subtraction: 00:00 to 04:00 across the
    October jump subtracts to four hours but is three, and scheduled start
    plus four real hours on that day is 05:00 by the clock.

    Raises DerivationError when a top-up is due and there is no scheduled
    start to measure it from, rather than guess.
    """
    day, night = _split_totals(on, off, cfg)
    span_hours = day + night
    booked = _booked_hours(booked_hours)

    result = {
        "span_hours": span_hours,
        "minimum_applies": booked is not None and booked >= MIN_ENGAGEMENT_HOURS - 1e-9,
        "reason": None,
        "minimum_applied": False,
        "late_start": False,
        "billable_end": off,
    }

    if span_hours >= MIN_ENGAGEMENT_HOURS - 1e-9:
        result["reason"] = "four_hours_or_more"
        return result

    if paid_as_worked:
        result["reason"] = "left_early"
        return result

    if booked is None:
        result["reason"] = "no_booked_length"
        return result

    if not result["minimum_applies"]:
        result["reason"] = "booked_short"
        return result

    if scheduled_start is None:
        raise DerivationError("the minimum needs the call's scheduled start")

    call_finish = _advance_real_hours(scheduled_start, MIN_ENGAGEMENT_HOURS, cfg)

    result["late_start"] = bool(late)

    if call_finish > off:
        result["reason"] = "topped"
        result["minimum_applied"] = True
        result["billable_end"] = call_finish
    else:
        result["reason"] = "own_finish_later"

    return result


# ──────────────────────────────────────────────────────────────────────────────
# The derivation
# ──────────────────────────────────────────────────────────────────────────────

def _worked_span(call, submission):
    """
    (clocked_on, clocked_off, late) for one call's submitted times.

    Rounding is applied here and nowhere else, so there is one place to look
    when a figure is a quarter hour out.
    """
    day = _as_date(call["start_date"])
    sched_start = _dt(day, call["start_time"])

    actual_on = _dt(day, submission["on_time"])
    actual_off = _dt(day, submission["off_time"],
                     bool(submission.get("off_next_day")))

    on = clock_on(actual_on, sched_start)
    off = clock_off(actual_off)

    if off <= on:
        raise DerivationError(
            "off_time is not after on_time for call %s (%s -> %s)"
            % (call.get("id"), on, off)
        )

    return (on, off, on > sched_start)


def _break_totals(breaks, call, on, off, cfg):
    """
    (day_hours, night_hours) of break time, each break split through THE SAME
    SPLITTER as the worked hours.

    A break beginning 19:50 for 30 minutes spans nightStart: ten minutes come
    off day and twenty off night. Classifying a whole break by its start time
    would be simpler and would disagree with how the surrounding hours are
    computed — the same clock giving two answers.

    A break outside [on, off] RAISES (Q37, closed 26 Aug 2026). The endpoint
    rejects it at submission with a message naming the break; by the time a
    value reaches here, one is a bug and not a case to handle. Silently
    ignoring it would deduct nothing and look identical to a correct run;
    silently deducting it would take the hours away twice.
    """
    day_total = 0.0
    night_total = 0.0
    call_day = _as_date(call["start_date"])

    for index, brk in enumerate(breaks or []):
        mins = int(brk.get("duration_mins") or 0)

        if mins <= 0:
            raise DerivationError("break %d has no duration" % index)

        b_start = _dt(call_day, brk["start_time"], bool(brk.get("start_next_day")))
        b_end = b_start + timedelta(minutes=mins)

        if b_start < on or b_end > off:
            raise DerivationError(
                "break %d (%s to %s) falls outside the shift %s to %s"
                % (index, b_start, b_end, on, off)
            )

        d, n = _split_totals(b_start, b_end, cfg)
        day_total += d
        night_total += n

    return (day_total, night_total)


def derive_times(submission, breaks, call, sibling_calls, feed_edges, cfg):
    """
    Derive actual and billable hours for one crew member on one call.

        submission     dict: on_time, off_time, off_next_day
        breaks         list of dicts: start_time, start_next_day, duration_mins
        call           dict: id, start_date, start_time, est_length
        sibling_calls  the same person's OTHER calls that day, same shape
        feed_edges     call_feeds edges among {call} + sibling_calls, each
                       {source_call, target_call, mode}; non-locked are ignored
        cfg            dayStart / nightStart, as estimator_calc expects

    There is no `mode` parameter. Payroll is always "duration" — see the module
    docstring.

    THE MINIMUM FOLLOWS THE CALL'S BOOKED LENGTH (decision 24, Mike, 22 Nov
    2026): `call["est_length"]`. Siblings and feed edges no longer bear on it,
    so the old `actual_hours` input and `sibling_hours_estimated` output are
    gone. `sibling_calls` and `feed_edges` are still taken, and still give
    `block_call_ids` (decisions 17 to 20), which other work may want.

    Returns a dict:
        actual_day / actual_night          worked, after breaks
        billable_day / billable_night      worked plus any minimum top-up,
                                           after breaks
        break_day / break_night            deducted
        clocked_on / clocked_off           rounded, as datetimes
        late                               arrived after the scheduled start
        minimum_applied                    a top-up extended the finish
        minimum_reason                     why, or why not: see minimum_top_up
        billable_end                       the notional finish after top-up
        block_call_ids                     the engagement this call belongs to
    """
    on, off, late = _worked_span(call, submission)

    worked_day, worked_night = _split_totals(on, off, cfg)
    break_day, break_night = _break_totals(breaks, call, on, off, cfg)

    actual_day = worked_day - break_day
    actual_night = worked_night - break_night

    # ---- the engagement ----
    #
    # Informational only since decision 24: it no longer decides the minimum.

    block = continuous_block(call, sibling_calls, feed_edges)
    block_ids = [int(c["id"]) for c in block]

    # ---- the minimum ----
    #
    # Decision 24: by the call's BOOKED length, and nothing else about the
    # person's day. See minimum_top_up(), which is the only copy of the rule.
    # A 3-hour show then a 4-hour load out that ran 2.5 bills SEVEN: the show
    # is booked short and paid as worked (3); the load out is booked for four
    # and topped to four. No continuity is involved.

    sched_start = _dt(_as_date(call["start_date"]), call["start_time"])

    top = minimum_top_up(on, off, call.get("est_length"), sched_start, late, cfg)

    minimum_applied = top["minimum_applied"]
    billable_end = top["billable_end"]

    billable_day_gross, billable_night_gross = _split_totals(on, billable_end, cfg)

    billable_day = billable_day_gross - break_day
    billable_night = billable_night_gross - break_night

    return {
        "call_id": int(call["id"]),
        "clocked_on": on,
        "clocked_off": off,
        "late": late,
        "actual_day": actual_day,
        "actual_night": actual_night,
        "actual_total": actual_day + actual_night,
        "break_day": break_day,
        "break_night": break_night,
        "billable_day": billable_day,
        "billable_night": billable_night,
        "billable_total": billable_day + billable_night,
        "billable_end": billable_end,
        "minimum_applied": minimum_applied,
        "minimum_reason": top["reason"],
        "block_call_ids": block_ids,
    }
