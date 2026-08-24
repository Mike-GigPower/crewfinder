"""
estimator_calc.py — the Estimator's labour pricing engine, ported to Python.

Ported from gigpower-estimatorv2 `src/lib/estimator/calc.ts` (read from the live
repo, 24 Aug 2026), with `src/lib/types.ts` for the call-name map and
`src/lib/config.ts` / `src/lib/useAppConfig.ts` for the config contract.

This module has NO Flask import and NO network access. It is pure arithmetic
over dicts, so it can be exercised directly:

    python3 test_estimator_calc.py

PUBLIC SURFACE
    split_day_night(start, end, cfg, mode)   — the 08:00/20:00 splitter
    get_rate_row(role, shift_date, cfg)      — effective-dated rate lookup
    calc_line(line, cfg, mode, rate_row)     — one labour line
    calc_quote_totals(quote, cfg, mode)      — every line plus non-labour
    role_for_call_name(call_name)            — call name -> rate-card role

TWO CLOCKS, ONE ALGORITHM  (BRIEF Decision 1)

  calculateLabourLine builds its start with a LOCAL WALL-CLOCK constructor and
  then advances by ABSOLUTE milliseconds:

      const startDT = new Date(yy, mm - 1, dd, tt.h, tt.m, 0, 0);
      const endDT   = new Date(startDT.getTime() + billableHours * 3600000);

  Python's naive `datetime + timedelta` advances the WALL CLOCK instead, so the
  two disagree by an hour twice a year. Neither is a bug; they serve different
  consumers:

    mode="duration"   absolute-time arithmetic in Australia/Melbourne. Matches
                      the Estimator exactly. Correct for FORWARD PRICING, where
                      durationHours is an intended duration — twelve hours of
                      work is twelve hours of pay whatever the clocks do.

    mode="wallclock"  naive arithmetic, no timezone at all. Correct for
                      SmartStaff's call_crew_map, where `on` and `off` are bare
                      wall-clock times written by a crew boss reading a watch.
                      A boss who wrote "on 22:00, off 09:00" on the October
                      transition night recorded eleven wall-clock hours and ten
                      real ones, and the business bills and pays from the sheet.

  There is NO DEFAULT. A caller that has not thought about which clock it wants
  raises ValueError rather than quietly pricing on the wrong one.

  The two modes share one code path. Every instant is an epoch second; the mode
  chooses only the zone used to convert between an instant and a local
  wall-clock reading. With the zone set to UTC, "absolute" and "wall clock" are
  the same thing, which is exactly what wallclock mode wants.

DIVERGENCES FROM calc.ts — deliberate, and each one is marked in place:

  1. The 96-iteration guard RAISES (EstimatorError) instead of breaking
     silently. A silent break under-prices, and nothing downstream would notice.
  2. role_for_call_name() returns None for an unmapped call name instead of
     falling back to "Standard Crew" (BRIEF Decision 4). The TS fallback quotes
     GC Spot — a Show Rate call, live at 84.50 — at 63.00, silently.
  3. calc_line() carries the below-minimum notice in a separate `advisories`
     key. calc.ts drops it: it is filtered out of `fatalErrors`, but the ok:true
     return path hardcodes `errors: []`, so the caller never sees it. `errors`
     here matches calc.ts exactly; `advisories` is additive.

CONFIG IS RUNTIME DATA, NEVER A CONSTANT
  dayStart, nightStart, minBillableHours and gstRate come from Supabase
  `app_settings`; rates come from `rate_cards`; holidays from `public_holidays`.
  Nothing here hardcodes 08:00/20:00/4 as anything but a last-resort fallback,
  and NOTHING here carries a rate. config.ts's `defaultConfig` rates are a
  pre-load placeholder (Standard Crew at 60.10/76.20/93.60) that Supabase
  overwrites with the live card (63.00/79.90/99.95); a port that fell back to
  them would price at the old card and say nothing.
"""

import math
import re

from datetime import date, datetime, timezone

try:
    from zoneinfo import ZoneInfo
except ImportError:                     # pragma: no cover - Python < 3.9
    ZoneInfo = None


MODE_DURATION  = "duration"
MODE_WALLCLOCK = "wallclock"

TZ_NAME = "Australia/Melbourne"

# Number.EPSILON. round2() reproduces calc.ts's rounding bit for bit, so the
# port and the Estimator agree on the half-cent cases too.
_JS_EPSILON = 2.220446049250313e-16

_HHMM_RE = re.compile(r"^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$")
_ISO_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
_DIGIT_RE = re.compile(r"\d")


class EstimatorError(Exception):
    """A shift the splitter cannot cut — see the 96-iteration guard."""


# ──────────────────────────────────────────────────────────────────────────────
# JavaScript arithmetic primitives
#
# These exist because Python's versions differ from JS's in ways that move
# money. Each is a one-liner, and each has bitten a port somewhere.
# ──────────────────────────────────────────────────────────────────────────────

def _js_round(x):
    """Math.round — half away from zero towards +Infinity, not banker's."""
    return math.floor(x + 0.5)


def _js_mod(a, b):
    """The % operator — sign follows the DIVIDEND. Python's follows the divisor."""
    return math.fmod(a, b)


def _js_number(raw):
    """
    Number(string). Returns None where JS would return NaN.

    Python's float() is more permissive in three places that matter: it accepts
    underscores ("1_000"), it accepts "nan"/"inf"/"infinity" as words, and it
    rejects the 0x/0o/0b literals JS accepts.
    """
    s = raw.strip()

    if s == "":
        return 0.0                      # Number("") === 0
    if "_" in s:
        return None

    body = s[1:] if s[:1] in ("+", "-") else s
    sign = -1.0 if s[:1] == "-" else 1.0

    if body[:2].lower() in ("0x", "0o", "0b"):
        if s[:1] in ("+", "-"):
            return None                 # Number("-0x10") is NaN
        base = {"x": 16, "o": 8, "b": 2}[body[1].lower()]
        try:
            return float(int(body[2:], base))
        except ValueError:
            return None

    if body == "Infinity":
        return sign * math.inf
    if body.lower() in ("nan", "inf", "infinity"):
        return None                     # words Python accepts and JS does not

    try:
        return float(s)
    except ValueError:
        return None


def _js_num_str(n):
    """String(number) for the small integers/decimals that reach error text."""
    f = float(n)
    if f == int(f) and abs(f) < 1e21:
        return str(int(f))
    return repr(f)


def _is_finite_number(x):
    """Number.isFinite — false for strings, bools, None, NaN and +-Infinity."""
    if isinstance(x, bool) or not isinstance(x, (int, float)):
        return False
    return math.isfinite(float(x))


def _to_number(x, default=0.0):
    """
    Number(x) over the JSON types that reach this module.

    Supabase's REST layer returns numeric columns as STRINGS ("63.00"), where
    the JS client hands the Estimator numbers. Every rate and every config
    scalar therefore goes through here before any arithmetic.
    """
    if x is None:
        return default
    if isinstance(x, bool):
        return 1.0 if x else 0.0
    if isinstance(x, (int, float)):
        return float(x)
    n = _js_number(str(x))
    return default if n is None else n


def round2(n):
    """calc.ts round2: Math.round((n + Number.EPSILON) * 100) / 100."""
    return _js_round((n + _JS_EPSILON) * 100) / 100.0


# ──────────────────────────────────────────────────────────────────────────────
# The clock
# ──────────────────────────────────────────────────────────────────────────────

class _Clock(object):
    """
    Converts between an instant (epoch seconds, the port's `Date.getTime()`)
    and a local wall-clock reading, under whichever mode the caller chose.

    duration  -> Australia/Melbourne, so a wall-clock reading and an instant can
                 differ by an hour across a transition. This is what the browser
                 does on a Melbourne machine, which is what the Estimator is.
    wallclock -> UTC, which has no transitions, so a wall-clock reading and an
                 instant never diverge. Naive arithmetic, expressed in the same
                 code.
    """

    def __init__(self, mode):
        if mode == MODE_DURATION:
            if ZoneInfo is None:
                raise EstimatorError("duration mode needs zoneinfo (Python 3.9+)")
            self.tz = ZoneInfo(TZ_NAME)
        elif mode == MODE_WALLCLOCK:
            self.tz = timezone.utc
        else:
            raise ValueError(
                'mode must be "duration" or "wallclock" — there is no default; '
                "see BRIEF Decision 1"
            )
        self.mode = mode

    def at(self, y, mo, d, h=0, mi=0):
        """
        The instant of a local wall-clock reading — `new Date(y, m, d, h, mi)`.

        fold=0 (the default) picks the FIRST occurrence of an ambiguous local
        time and reads a nonexistent one with the pre-transition offset, which
        is what ECMAScript specifies. Melbourne moves at 2am/3am and every
        boundary this module builds is 00:00, dayStart or nightStart, so the
        ambiguous cases are unreachable unless someone sets dayStart to 02:30.
        """
        return datetime(y, mo, d, h, mi, tzinfo=self.tz).timestamp()

    def at_dt(self, naive_dt):
        return naive_dt.replace(tzinfo=self.tz).timestamp()

    def local(self, ts):
        """The local wall-clock reading of an instant, as a naive datetime."""
        return datetime.fromtimestamp(ts, self.tz).replace(tzinfo=None)


def _iso_date(dt):
    return "%04d-%02d-%02d" % (dt.year, dt.month, dt.day)


# ──────────────────────────────────────────────────────────────────────────────
# Time parsing
# ──────────────────────────────────────────────────────────────────────────────

def parse_time_hhmm(t):
    """
    calc.ts parseTimeHHMM. Returns (hour, minute) or None.

    Two behaviours worth knowing before calling it:

      * the regex requires a TWO-DIGIT hour, so "8:00" does not match it and
        falls through to the numeric branch, where Number("8:00") is NaN — the
        Estimator rejects "8:00" as a start time. normaliseHHMM(), used by the
        UI on the way in, accepts it and pads it; this function is what pricing
        sees, and it does not.

      * the numeric fallback reads a decimal fraction of a DAY, so "0.5" is
        12:00 and a bare "8" is 00:00 (8 % 1 === 0), not 08:00. Harmless, but
        reproduced, or the port would reject input the Estimator prices.
    """
    raw = ("" if t is None else str(t)).strip()

    m1 = _HHMM_RE.match(raw)
    if m1:
        return (int(m1.group(1)), int(m1.group(2)))

    # Number("") and Number("   ") are 0, which would price a line with no start
    # time as midnight instead of flagging it. Require a digit first.
    if not _DIGIT_RE.search(raw):
        return None

    as_num = _js_number(raw)
    if as_num is None or not math.isfinite(as_num):
        return None

    frac = as_num if (0 <= as_num < 1) else _js_mod(as_num, 1.0)
    total_minutes = _js_round(frac * 24 * 60)
    h = _js_mod(math.floor(total_minutes / 60.0), 24)
    m = _js_mod(total_minutes, 60)

    if 0 <= h <= 23 and 0 <= m <= 59:
        return (int(h), int(m))

    return None


def _is_sunday(date_iso):
    """calc.ts isSunday. An unparseable date is NaN in JS, hence False here."""
    try:
        y, mo, d = (int(p) for p in date_iso.split("-"))
        return date(y, mo, d).weekday() == 6
    except (ValueError, TypeError):
        return False


def _is_public_holiday(date_iso, cfg):
    for h in (cfg.get("publicHolidays") or []):
        if h.get("date") == date_iso:
            return True
    return False


# ──────────────────────────────────────────────────────────────────────────────
# Rates
# ──────────────────────────────────────────────────────────────────────────────

def get_rate_row(role, shift_date, cfg):
    """
    calc.ts getRateRow — the rate VERSION that applies to a shift date: the one
    whose effectiveFrom is the latest still on or before it. A shift before
    every version falls back to the earliest, so there are never gaps. ISO
    dates compare correctly as plain strings.

    Every live row currently carries the 2000-01-01 sentinel, so this is a
    straight lookup today; the mechanism is there for the first real re-card.
    """
    candidates = [r for r in (cfg.get("rates") or []) if r.get("role") == role]
    if not candidates:
        return None

    applicable = [
        r for r in candidates
        if not r.get("effectiveFrom") or r.get("effectiveFrom") <= shift_date
    ]

    if applicable:
        # Descending by effectiveFrom. Both languages sort stably, so equal
        # effective dates keep rate_cards' sort_order in both.
        return sorted(applicable, key=lambda r: r.get("effectiveFrom") or "",
                      reverse=True)[0]

    return sorted(candidates, key=lambda r: r.get("effectiveFrom") or "")[0]


def _rate(rate_row, key):
    return _to_number(rate_row.get(key), 0.0)


# ──────────────────────────────────────────────────────────────────────────────
# The splitter
# ──────────────────────────────────────────────────────────────────────────────

def _split_epoch(s, e, cfg, clock):
    """
    calc.ts splitIntoDayNightByDate, over instants.

    Cuts [s, e) at every dayStart, nightStart and midnight, and labels each
    slice from ITS OWN START:

        isDay = cursor >= dayStart && cursor < nightStart

    Because the loop cuts at every boundary a slice never straddles one, so
    deciding at the start is unambiguous — but it does have to be the start.

    Boundary candidates are STRICTLY GREATER than the cursor. A shift starting
    exactly at 08:00 does not treat 08:00 as its next boundary; the next cut is
    20:00. Using >= would spin on a zero-length slice until the guard fired.
    """
    if not (math.isfinite(s) and math.isfinite(e)) or e <= s:
        return []

    day_start = parse_time_hhmm(cfg.get("dayStart")) or (8, 0)
    night_start = parse_time_hhmm(cfg.get("nightStart")) or (20, 0)

    out = []
    guard = 0
    cursor = s

    while cursor < e:
        guard += 1
        if guard > 96:
            # calc.ts breaks here and returns what it has, which under-prices
            # the tail of the shift and returns ok:true while doing it. At up to
            # three boundaries a day, 96 slices is about 32 days — unreachable
            # for real work, so if we are here the input is wrong, and saying so
            # beats quietly billing less.
            raise EstimatorError(
                "day/night split exceeded 96 segments between %s and %s"
                % (clock.local(s), clock.local(e))
            )

        cur = clock.local(cursor)

        day_start_ts = clock.at(cur.year, cur.month, cur.day, day_start[0], day_start[1])
        night_start_ts = clock.at(cur.year, cur.month, cur.day, night_start[0], night_start[1])

        tomorrow = date(cur.year, cur.month, cur.day).toordinal() + 1
        nxt = date.fromordinal(tomorrow)
        next_midnight_ts = clock.at(nxt.year, nxt.month, nxt.day, 0, 0)

        candidates = [t for t in (day_start_ts, night_start_ts, next_midnight_ts)
                      if t > cursor]
        next_boundary = min(candidates) if candidates else next_midnight_ts
        seg_end = min(e, next_boundary)

        if not math.isfinite(seg_end) or seg_end <= cursor:
            # Defensive in calc.ts and unreachable given the strictly-greater
            # filter above: next_midnight is always past the cursor. Ported so
            # the two implementations stay line-for-line comparable.
            forced = min(e, cursor + 60)
            if forced <= cursor:
                break
            cursor = forced
            continue

        seg_hours = (seg_end - cursor) / 3600.0
        is_day = (day_start_ts <= cursor < night_start_ts)

        out.append({
            "dateISO": _iso_date(cur),
            "dayHrs": seg_hours if is_day else 0.0,
            "nightHrs": 0.0 if is_day else seg_hours,
        })

        cursor = seg_end

    return out


def split_day_night(start, end, cfg, mode):
    """
    Public splitter. `start` and `end` are naive datetimes read as local wall
    clock; `mode` is "duration" or "wallclock".

    In duration mode the SEGMENT HOURS are absolute, so across a transition
    they will not sum to the wall-clock difference between start and end —
    00:00 to 08:00 on the October Sunday is seven hours, not eight. That is the
    point of the mode, not a defect in it.

    calc_line() does not call this: it works in instants throughout, because
    round-tripping an instant through a local wall-clock reading is lossy on
    the April transition, when 02:30 happens twice.
    """
    clock = _Clock(mode)
    return _split_epoch(clock.at_dt(start), clock.at_dt(end), cfg, clock)


# ──────────────────────────────────────────────────────────────────────────────
# Call names
# ──────────────────────────────────────────────────────────────────────────────

# types.ts CALL_NAME_TO_ROLE, verbatim. "Other" is an explicit entry, not a
# fallback: it is hidden in both UIs and only reaches here from legacy data.
CALL_NAME_TO_ROLE = {
    "Load In":    "Standard Crew",
    "Load Out":   "Standard Crew",
    "LX":         "Standard Crew",
    "SX":         "Standard Crew",
    "VX":         "Standard Crew",
    "Backline":   "Standard Crew",
    "Show Call":  "Show Crew",
    "FOH Spot":   "Show Crew",
    "Truss Spot": "Show Crew",
    "Wardrobe":   "Seamstress",
    "Steel":      "Steel Hand",
    "Fork":       "Fork/Truck/EWP",
    "Truck":      "Fork/Truck/EWP",
    "EWP":        "Fork/Truck/EWP",
    "Crown Hand": "Crown Hand",
    "Crew Boss":  "Crew Boss",
    "Site":       "Standard Crew",
    "Utility":    "Standard Crew",
    "General":    "Standard Crew",
    "Other":      "Standard Crew",
}


def role_for_call_name(call_name):
    """
    Resolve a Call Name to its rate-card role, or None.

    DIVERGES FROM types.ts roleForCallName, which returns "Standard Crew" for
    anything unmapped — silently. Production invoice descriptions carry at
    least two names the twenty-entry map does not have: "GC Spot", which bills
    at 84.50 (the live Show Crew day rate), and "Traffic". Quoting either at
    Standard Crew's 63.00 is a 25% under-quote with no error raised.

    An unmapped name is therefore UNPRICED here, and the caller reports it,
    consistent with v5.14.0 Decision 4. Do not add a fallback; add the mapping
    upstream once the §4 probe says what the missing names should map to.
    """
    if not call_name:
        return None
    return CALL_NAME_TO_ROLE.get(call_name)


# ──────────────────────────────────────────────────────────────────────────────
# One labour line
# ──────────────────────────────────────────────────────────────────────────────

_ZERO_BREAKDOWN = {
    "baseDayHrs": 0.0, "baseNightHrs": 0.0,
    "ot8DayHrs": 0.0, "ot8NightHrs": 0.0,
    "ot10DayHrs": 0.0, "ot10NightHrs": 0.0,
}


def _failed(errors):
    return {
        "ok": False,
        "errors": errors,
        "advisories": [],
        "billableHours": 0.0,
        "costExGst": 0.0,
        "breakdown": dict(_ZERO_BREAKDOWN),
        "segments": [],
    }


def calc_line(line, cfg, mode, rate_row=None):
    """
    calc.ts calculateLabourLine.

    `mode` is required and has no default (Decision 1). `rate_row` may be passed
    to price against a rate the caller already resolved — Release 2's learned
    ladder will — and is otherwise looked up with get_rate_row().

    The tiers are cut from the START of the shift, not per calendar day:
    base is the first 8 hours, ot8 the next 2, ot10 everything after 10.
    """
    clock = _Clock(mode)

    errors = []
    line_id = line.get("id")

    role = line.get("role")
    shift_date = line.get("shiftDate") or ""
    qty = line.get("qty")
    duration = line.get("durationHours")

    rr = rate_row if rate_row is not None else get_rate_row(role, shift_date, cfg)

    if not role or rr is None:
        errors.append("Role is invalid for line %s." % line_id)
    if not _is_finite_number(qty) or qty <= 0:
        errors.append("Crew qty must be > 0 for line %s." % line_id)
    if not _ISO_DATE_RE.match(shift_date):
        errors.append("Shift date is invalid for line %s." % line_id)

    t = parse_time_hhmm(line.get("startTime"))
    if t is None:
        errors.append("Start time must be HH:MM for line %s." % line_id)

    min_billable = _to_number(cfg.get("minBillableHours"), 0.0)

    if not _is_finite_number(duration) or duration <= 0:
        errors.append("Duration must be > 0 for line %s." % line_id)

    if _is_finite_number(duration) and duration < min_billable:
        errors.append(
            "Duration is below minimum (%sh) for line %s. (Will bill minimum)"
            % (_js_num_str(min_billable), line_id)
        )

    # Below-minimum is ADVISORY, not a validation failure: the line prices at
    # the minimum and still returns ok. Everything else is fatal.
    advisories = [e for e in errors if "below minimum" in e]
    fatal_errors = [e for e in errors if "below minimum" not in e]

    if rr is None or fatal_errors:
        return _failed(fatal_errors if fatal_errors else errors)

    billable_hours = max(_to_number(duration or 0, 0.0), min_billable)

    parts = shift_date.split("-")
    try:
        yy, mm, dd = int(parts[0]), int(parts[1]), int(parts[2])
    except (IndexError, ValueError):
        return _failed(["Invalid date/time for line %s." % line_id])

    start_ts = clock.at(yy, mm, dd, t[0], t[1])
    end_ts = start_ts + billable_hours * 3600.0

    base_end = min(end_ts, start_ts + 8 * 3600.0)
    ot8_end = min(end_ts, start_ts + 10 * 3600.0)

    base_parts = _split_epoch(start_ts, base_end, cfg, clock)
    ot8_parts = _split_epoch(base_end, ot8_end, cfg, clock)
    ot10_parts = _split_epoch(ot8_end, end_ts, cfg, clock)

    r_day = _rate(rr, "day")
    r_night = _rate(rr, "night")
    r_sunday = _rate(rr, "sunday")
    r_ph = _rate(rr, "publicHoliday")
    r_over8 = _rate(rr, "over8")
    r_over10 = _rate(rr, "over10")

    def penalties(date_iso):
        """Sunday and public-holiday rates, or 0 where the day is neither."""
        return (
            r_sunday if _is_sunday(date_iso) else 0.0,
            r_ph if _is_public_holiday(date_iso, cfg) else 0.0,
        )

    # Every rate is a MAX-OF, never a sum: a Sunday night at overtime is the
    # single highest applicable rate, not night plus penalty plus overtime.
    def max_day(d):     s, p = penalties(d); return max(r_day, s, p)
    def max_night(d):   s, p = penalties(d); return max(r_night, s, p)
    def max_ot8_day(d): s, p = penalties(d); return max(r_over8, r_day, s, p)
    def max_ot8_night(d): s, p = penalties(d); return max(r_over8, r_night, s, p)
    def max_ot10_day(d): s, p = penalties(d); return max(r_over10, r_day, s, p)
    def max_ot10_night(d): s, p = penalties(d); return max(r_over10, r_night, s, p)

    def cost_parts(parts_, day_fn, night_fn):
        acc = 0.0
        for p in parts_:
            acc += p["dayHrs"] * day_fn(p["dateISO"]) + p["nightHrs"] * night_fn(p["dateISO"])
        return acc

    cost_base = cost_parts(base_parts, max_day, max_night)
    cost_ot8 = cost_parts(ot8_parts, max_ot8_day, max_ot8_night)
    cost_ot10 = cost_parts(ot10_parts, max_ot10_day, max_ot10_night)

    # Rounding happens ONCE, at the line, with qty already applied.
    cost_ex_gst = round2(_to_number(qty, 0.0) * (cost_base + cost_ot8 + cost_ot10))

    def build_segments(tier, parts_, day_fn, night_fn):
        """
        The per-crew breakdown behind the Cost column's tooltip. Segment costs
        are hours x rate PER CREW (no qty) and are rounded INDEPENDENTLY, so
        summing segments can differ from costExGst by a cent or two. That is
        calc.ts's behaviour and it is reproduced rather than fixed: the display
        and the line total are allowed to round differently, and "fixing" it
        here would put the port a cent away from the quote the customer holds.
        """
        out = []
        for p in parts_:
            if p["dayHrs"] > 0:
                rate = day_fn(p["dateISO"])
                out.append({
                    "tier": tier, "period": "day", "dateISO": p["dateISO"],
                    "hours": round2(p["dayHrs"]), "rate": rate,
                    "costExGst": round2(p["dayHrs"] * rate),
                })
            if p["nightHrs"] > 0:
                rate = night_fn(p["dateISO"])
                out.append({
                    "tier": tier, "period": "night", "dateISO": p["dateISO"],
                    "hours": round2(p["nightHrs"]), "rate": rate,
                    "costExGst": round2(p["nightHrs"] * rate),
                })
        return out

    segments = (
        build_segments("base", base_parts, max_day, max_night)
        + build_segments("ot8", ot8_parts, max_ot8_day, max_ot8_night)
        + build_segments("ot10", ot10_parts, max_ot10_day, max_ot10_night)
    )

    def sum_parts(parts_):
        return (
            sum(p["dayHrs"] for p in parts_),
            sum(p["nightHrs"] for p in parts_),
        )

    base_day, base_night = sum_parts(base_parts)
    ot8_day, ot8_night = sum_parts(ot8_parts)
    ot10_day, ot10_night = sum_parts(ot10_parts)

    return {
        "ok": True,
        "errors": [],                   # calc.ts parity — see module docstring
        "advisories": advisories,
        "billableHours": billable_hours,
        "costExGst": cost_ex_gst,
        "breakdown": {
            "baseDayHrs": round2(base_day),
            "baseNightHrs": round2(base_night),
            "ot8DayHrs": round2(ot8_day),
            "ot8NightHrs": round2(ot8_night),
            "ot10DayHrs": round2(ot10_day),
            "ot10NightHrs": round2(ot10_night),
        },
        "segments": segments,
    }


# ──────────────────────────────────────────────────────────────────────────────
# A whole quote
# ──────────────────────────────────────────────────────────────────────────────

def calc_quote_totals(quote, cfg, mode):
    """calc.ts calculateQuoteTotals."""
    gst_rate = _to_number(cfg.get("gstRate"), 0.0)

    evaluations = [(ln, calc_line(ln, cfg, mode)) for ln in (quote.get("labour") or [])]

    labour_lines = []
    for ln, r in evaluations:
        labour_lines.append({
            "id": ln.get("id"),
            "role": ln.get("role"),
            "qty": ln.get("qty"),
            "shiftDate": ln.get("shiftDate"),
            "startTime": ln.get("startTime"),
            "durationHours": ln.get("durationHours"),
            "billableHours": r["billableHours"],
            "costExGst": r["costExGst"],
            "gst": round2(r["costExGst"] * gst_rate),
            "totalIncGst": round2(r["costExGst"] * (1 + gst_rate)),
            "breakdown": r["breakdown"],
            "segments": r["segments"],
            "advisories": r["advisories"],
        })

    validation_errors = []
    for _, r in evaluations:
        if not r["ok"]:
            validation_errors.extend(r["errors"])

    non_labour_lines = []
    for x in (quote.get("nonLabour") or []):
        title = (x.get("title") or "").strip()
        description = x.get("description") or ""
        amount = _to_number(x.get("amountExGst"), 0.0)
        raw_qty = x.get("qty")

        if not (title != "" or description.strip() != "" or amount != 0 or raw_qty != 1):
            continue

        qty = raw_qty if (_is_finite_number(raw_qty) and raw_qty > 0) else 1
        unit = round2(amount)
        amt = round2(unit * qty)

        non_labour_lines.append({
            "id": x.get("id"),
            "title": title,
            "description": description.strip() or "Non-labour item",
            "qty": qty,
            "unitAmountExGst": unit,
            "lineAmountExGst": amt,
            "gst": round2(amt * gst_rate),
            "totalIncGst": round2(amt * (1 + gst_rate)),
        })

    labour_ex_gst = round2(sum(x["costExGst"] for x in labour_lines))
    non_labour_ex_gst = round2(sum(x["lineAmountExGst"] for x in non_labour_lines))
    sub_total_ex_gst = round2(labour_ex_gst + non_labour_ex_gst)
    gst = round2(sub_total_ex_gst * gst_rate)

    return {
        "isValid": len(validation_errors) == 0,
        "validationErrors": validation_errors,
        "labourLines": labour_lines,
        "nonLabourLines": non_labour_lines,
        "totals": {
            "labourExGst": labour_ex_gst,
            "nonLabourExGst": non_labour_ex_gst,
            "subTotalExGst": sub_total_ex_gst,
            "gst": gst,
            "grandTotalIncGst": round2(sub_total_ex_gst + gst),
        },
    }
