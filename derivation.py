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

# CORRECTION-3. The minimum engagement. Applied per call, except across a
# continuous block, which is one engagement and gets one minimum.
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
        sibling_calls  the same person's OTHER calls that day, same shape, each
                       optionally carrying `actual_hours` (see below)
        feed_edges     call_feeds edges among {call} + sibling_calls, each
                       {source_call, target_call, mode}; non-locked are ignored
        cfg            dayStart / nightStart, as estimator_calc expects

    There is no `mode` parameter. Payroll is always "duration" — see the module
    docstring.

    SIBLING HOURS. The minimum is applied across a continuous block, so the
    block's total has to be known. This function holds submitted times for ONE
    call only, so each sibling may carry `actual_hours` (its own derived span).
    When a sibling does not, its SCHEDULED est_length is used instead and
    `sibling_hours_estimated` comes back True. That flag matters: a top-up
    computed from scheduled hours can be wrong once the real times arrive, and
    a caller that ignores it will not notice.

    Returns a dict:
        actual_day / actual_night          worked, after breaks
        billable_day / billable_night      worked plus any minimum top-up,
                                           after breaks
        break_day / break_night            deducted
        clocked_on / clocked_off           rounded, as datetimes
        late                               arrived after the scheduled start
        minimum_applied                    a top-up extended the finish
        billable_end                       the notional finish after top-up
        block_call_ids                     the engagement this call belongs to
        sibling_hours_estimated            see above
    """
    on, off, late = _worked_span(call, submission)

    worked_day, worked_night = _split_totals(on, off, cfg)
    break_day, break_night = _break_totals(breaks, call, on, off, cfg)

    actual_day = worked_day - break_day
    actual_night = worked_night - break_night

    # ---- the engagement ----

    block = continuous_block(call, sibling_calls, feed_edges)
    block_ids = [int(c["id"]) for c in block]

    # REAL hours, not (off - on). A naive subtraction is a wall-clock reading:
    # 00:00 to 04:00 across the October jump subtracts to four hours but is
    # three, and the minimum would not fire on a shift that is an hour short.
    # Gross of breaks, because the minimum is measured on the engagement span
    # and the breaks come off afterwards.
    this_span_hours = worked_day + worked_night

    estimated = False
    block_hours = 0.0

    for member in block:
        if int(member["id"]) == int(call["id"]):
            block_hours += this_span_hours
            continue

        if "actual_hours" in member and member["actual_hours"] is not None:
            block_hours += float(member["actual_hours"])
        else:
            block_hours += float(member.get("est_length") or 0.0)
            estimated = True

    # ---- the minimum ----
    #
    # CORRECTION-3. One minimum per engagement, and Q32 puts it on the LAST call
    # in the block — so a call that is not last never tops up, however short.
    #
    # Q31: the top-up EXTENDS THE FINISH and the clock sets the rate. A 22:00 to
    # 00:00 call topped to four notionally runs to 02:00, and those hours are
    # night because split_day_night() says so, not because anything here decides
    # it.
    #
    # THE GAP BETWEEN CONTINUOUS CALLS IS NEITHER PAID NOR DEDUCTED (§6.5). It
    # is the reason the minimum is not applied twice; it is not a break. A
    # 2-hour show and a 4-hour load out an hour apart bills SIX — the sum of
    # actuals, no top-up and no deduction. Deducting the gap would double-count
    # the same reasoning, and it is the single easiest mistake to make here.

    is_last = block_ids and int(call["id"]) == int(block[-1]["id"])
    deficit = MIN_ENGAGEMENT_HOURS - block_hours

    minimum_applied = False
    billable_end = off

    if is_last and deficit > 1e-9:
        billable_end = _advance_real_hours(off, deficit, cfg)
        minimum_applied = True

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
        "block_call_ids": block_ids,
        "sibling_hours_estimated": estimated,
    }
