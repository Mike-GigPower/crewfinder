"""Expected invoice for a booking — the October trial method, on live rules.

BRIEF-booking-documents-figures.md §5 (v0.2), with the §14 amendments A2-A7.

WHAT THIS IS. SmartStaff invoices a booking some time after the calls are
locked. This module says what that invoice SHOULD come to, from the crew rows'
keyed times and the Estimator's live rate card, so the Figures tab can show a
figure before SmartStaff has invoiced, and flag a difference once it has.

It reproduces SCRIPT-invoice-backtest.py in MODE="paygrade", which matched
SmartStaff to the cent on 448 of 462 non-M&OP calls in October. It ports that
script's BEHAVIOUR, never its hard-coded card: every rate, cut time, holiday
and penalty window is read from the live rules.

PURE. No I/O, no network, no clock. `figures` is get-booking-figures.php's JSON
and `rules` is pricing_rules.load_rules()[0]. That is what makes it testable
against a fixture to the cent.

THE TWO THINGS MOST EASILY GOT WRONG
------------------------------------
1. ROUNDING (§5.2). The invoice total is the EXACT, unrounded sum of
   qty x hours x rate, rounded ONCE. The PDF's per-line totals are display
   rounding and do not add up to it. So this module accumulates in Decimal with
   no rounding along the way, and rounds once per call and once per booking,
   ROUND_HALF_UP. Rounding per row — which the script did — put #12220 three
   cents high.

2. COST IS NEVER DERIVED (§5.5). Crew are paid by the PERSON's grade, not the
   call's: probation crew on paygrade 10 and promoted crew on 25/26 are paid
   differently and charged identically. This module computes the CHARGE only.
   Cost comes from each row's own accounting.calculated buckets, which
   get-booking-figures.php reads. Never multiply a role by a card rate and call
   it pay.
"""

from decimal import Decimal, ROUND_HALF_UP
from datetime import date, datetime, timedelta

import estimator_calc as ec
import pricing_rules


# ──────────────────────────────────────────────────────────────────────────────
# The paygrade map
# ──────────────────────────────────────────────────────────────────────────────

# SmartStaff callpaygradeID -> the role name on the Estimator card.
#
# Checked against the live standard card on 11 Nov 2026 (§11.2) and against
# every paygrade in use on status-5 rows in the 12 months to then (§11.5):
# all 18 grades in use are covered here.
#
# The grades come in families: a base grade plus its Sunday/public-holiday and
# /SPH variants, which the card charges IDENTICALLY because the customer is
# billed for the ROLE, not for the person or the penalty the person earns.
# That is why 10/25/26 and 38/39/40 all map to Standard Crew: #12220's calls
# 39358, 39359 and 39363 mix grades 38 and 39 in a single invoice line.
#
# A3 (§14): 14 "Steel Hand" and 43 "Steel Hand/SPH" map to Steel Hand, NOT to
# Fork/Truck/EWP. The two roles carry identical rates on today's card, so this
# changes no figure now; it keeps the mapping true if the card ever splits
# them, which a Fork mapping would silently get wrong on 371 rows.
PAYGRADE_ROLE = {
    10: "Standard Crew",        # T1 / T2            (probation)
    25: "Standard Crew",        # T1A / T2A          (promoted)
    26: "Standard Crew",        # T1B / T2B          (promoted)
    38: "Standard Crew",        # Sun/Pub Hol
    39: "Standard Crew",        # Sun/Pub HolB
    40: "Standard Crew",        # Sun/Pub HolA
    20: "Crew Boss",
    42: "Crew Boss",            # Crew Boss/SPH
    33: "Show Crew",            # Show Rate
    12: "Fork/Truck/EWP",       # Fork/EWP/Truck
    41: "Fork/Truck/EWP",       # Fork/EWP/Truck/SPH
    14: "Steel Hand",           # A3
    43: "Steel Hand",           # A3  Steel Hand/SPH
    24: "Crown Hand",
    35: "Seamstress",
    37: "Seamstress",           # Seamstress/SPH
    18: "Tele/Heavy Plant/SPH",
    22: "Tele/Heavy Plant/SPH",
}

# A4 (§14): Harness Hire is a CARD ITEM, not an hourly role. `harness_hire` is
# a flat_item on the card ("each"), so a harness row is charged once per row,
# not by the hours worked. SmartStaff bills it through an hourly paygrade, so a
# call carrying one will usually show an amber diff — that is a real finding,
# not noise, and the call's note says so.
PAYGRADE_ITEM = {
    36: "harness_hire",
}

# A5 (§14): Broadcast is a per_person_per_event card item. This is a
# BOOKING-level figure and cannot honestly spread an event-level allowance
# across calls, so the row is excluded and the rest of the call is priced.
PAYGRADE_EXCLUDED = {
    34: "broadcast: per person per event",
}

# ──────────────────────────────────────────────────────────────────────────────
# When the live card started being the card that was actually charged
# ──────────────────────────────────────────────────────────────────────────────

# The Estimator's rate rows carry an effective_from. Every row on the MOPT and
# MOPT-AO cards says 2026-07-01; every row on the STANDARD card says
# 2000-01-01, which is the "always" sentinel the Estimator seeds rows with
# rather than a real start date (pricing_rules.get_rate_row's own comment says
# so). So the standard card cannot tell us when its rates began.
#
# It matters because the rates DID change. Test booking 11763 (Sunday 24 May
# 2026) was charged 93.60/h where today's card says 99.95. Pricing a May call
# on today's card would invent a difference that was never real and put an
# amber flag on a correctly invoiced booking.
#
# So: a call dated before the cut-over is NOT priced. It reports status
# "before_rates", and the tab shows "— (before current rates)".
_SENTINEL_EFFECTIVE_FROM = "2000-01-01"
CURRENT_RATES_FROM = "2026-07-01"


def rates_from(rules, set_code):
    """The ISO date from which this card's rates are the ones charged.

    Read from the rules when they say: if every active rate row on the card
    shares one non-sentinel effective_from, that IS the card's start date
    (today: both MOPT cards, 2026-07-01). Otherwise fall back to the constant.

    A card whose rows carry SEVERAL real dates is a partial re-card — one role
    re-rated in September says nothing about when the others began — so it
    falls back too rather than blanking every call before the latest change.
    """
    st = pricing_rules.find_set(rules, set_code) or {}
    dates = set()
    for r in st.get("rates") or []:
        if r.get("is_active") is False:
            continue
        eff = r.get("effective_from")
        if eff and eff != _SENTINEL_EFFECTIVE_FROM:
            dates.add(eff)
    if len(dates) == 1:
        return dates.pop()
    return CURRENT_RATES_FROM


# ──────────────────────────────────────────────────────────────────────────────
# Small readers for get-booking-figures.php's JSON
# ──────────────────────────────────────────────────────────────────────────────

def _minutes(hhmm):
    """"HH:MM" or "HH:MM:SS" -> minutes. A BLANK OR MISSING VALUE IS 00:00.

    Test booking 11763 returns "" for break and break_night on rows that have
    never had a break keyed. Treating that as anything but zero would either
    crash or, worse, silently subtract nothing while reporting an error.
    """
    if hhmm is None:
        return 0
    s = str(hhmm).strip()
    if not s:
        return 0
    parts = s.split(":")
    try:
        return int(parts[0]) * 60 + int(parts[1])
    except (ValueError, IndexError):
        return 0


def _has_times(row):
    """A row is priceable only with both ends keyed and not equal (§5.1)."""
    on = str(row.get("on") or "").strip()
    off = str(row.get("off") or "").strip()
    if not on or not off:
        return False
    if _minutes(on) == _minutes(off):
        return False
    return True


def has_pay(figures, call_id):
    """Does this call have crew pay calculated?

    FROM THE PAY MAP, NEVER FROM call_locked. On test booking 11763, calls
    37675, 37679 and 37683 are unlocked and yet carry accounting rows with
    pay. Reading call_locked would report "no pay yet" over real money.
    """
    entry = (figures.get("pay") or {}).get(str(call_id))
    if not entry:
        return False
    return bool(entry.get("rows"))


def _call_date(call):
    """The call's local date, from calls.start_date (a unix day).

    Read in Australia/Melbourne. SmartStaff stores the day, and reading it as
    local is right whether the stored instant is local midnight (-> that date)
    or UTC midnight (-> 11:00 the same date). Reading it as UTC would be a day
    early under the first convention.
    """
    ts = call.get("start_date")
    if ts is None:
        return None
    try:
        clock = ec._Clock(ec.MODE_DURATION)
        return clock.local(float(ts)).date()
    except Exception:
        return None


# ──────────────────────────────────────────────────────────────────────────────
# Rates
# ──────────────────────────────────────────────────────────────────────────────

def _rate_rows(rules, set_code):
    """{role_name: [row, ...]} straight off the card.

    Read from the raw set rather than pricing_rules.cfg_for_set because cfg
    DROPS the night overtime tiers (its _rate_row keeps ot_8_day_rate and
    ot_10_day_rate only, for parity with the Estimator's calc.ts). §14 says to
    use the tier that matches the minute, so this module needs all four.

    NOTE THE DIVERGENCE: no role on any live card sets a night OT rate that
    differs from its day one, so this produces identical figures to the
    Estimator today. If a card ever splits them, Expected will follow the
    minute and the Estimator's own quote will not. That is deliberate (§14),
    and worth revisiting if it ever bites.
    """
    st = pricing_rules.find_set(rules, set_code) or {}
    out = {}
    for r in st.get("rates") or []:
        if r.get("is_active") is False:
            continue
        name = str(r.get("role_name") or "").strip()
        if name:
            out.setdefault(name, []).append(r)
    return out


def _row_for_date(rows, iso_date):
    """The rate version applying to a date — latest effective_from on or before
    it, else the earliest. Same rule as estimator_calc.get_rate_row."""
    if not rows:
        return None
    applicable = [r for r in rows
                  if not r.get("effective_from") or r.get("effective_from") <= iso_date]
    if applicable:
        return sorted(applicable, key=lambda r: r.get("effective_from") or "", reverse=True)[0]
    return sorted(rows, key=lambda r: r.get("effective_from") or "")[0]


def _num(v):
    """A rate as a float, or None. NULL MEANS "NO TIER", NEVER ZERO: every MOPT
    role has a null ot_8_day_rate, and reading that as 0.0 would floor an
    overtime minute at nothing instead of leaving the base rate alone."""
    if v is None:
        return None
    try:
        f = float(v)
    except (TypeError, ValueError):
        return None
    return f


# ──────────────────────────────────────────────────────────────────────────────
# The D31 double-penalty windows, as local wall clock
# ──────────────────────────────────────────────────────────────────────────────

def _windows_local(cfg, years):
    """estimator_calc's own window helper, converted once to naive local times.

    Built with ec._windows_around so the window table is read exactly as the
    Estimator reads it (§5.1: "using the existing helpers"), then mapped to
    wall clock so the per-minute loop below can compare naive datetimes
    instead of doing timezone arithmetic 600 times a row.
    """
    clock = ec._Clock(ec.MODE_DURATION)
    out = []
    seen = set()
    for y in years:
        for w in ec._windows_around(y, cfg, clock):
            key = (w["start"], w["end"], w.get("name"))
            if key in seen:
                continue
            seen.add(key)
            out.append({
                "start": clock.local(w["start"]),
                "end": clock.local(w["end"]),
                "multiplier": w["multiplier"],
                "name": w.get("name"),
            })
    return out


# ──────────────────────────────────────────────────────────────────────────────
# Pricing one worker
# ──────────────────────────────────────────────────────────────────────────────

def _price_worker(call_day, on, off, brk, brkn, rate_row, cfg, windows):
    """One person's shift -> (worked_minutes, {rate: minutes}).

    A faithful port of SCRIPT-invoice-backtest.py's price_row, except that it
    returns BUCKETS instead of a rounded figure, so §5.2 can accumulate the
    group exactly.

    Minutes are WALL CLOCK, which is what SmartStaff pays: "06:30 to 11:30" is
    five hours whatever the clocks did. Times are taken AS KEYED, with no
    4-hour top-up — `off` already carries any minimum
    (DESIGN-invoicing-v0_2 §3.3). off <= on means the shift ends next day.
    """
    day_start = ec.parse_time_hhmm(cfg.get("dayStart")) or (8, 0)
    night_start = ec.parse_time_hhmm(cfg.get("nightStart")) or (20, 0)
    day_start_m = day_start[0] * 60 + day_start[1]
    night_start_m = night_start[0] * 60 + night_start[1]

    start = datetime.combine(call_day, datetime.min.time()) + timedelta(minutes=_minutes(on))
    end = datetime.combine(call_day, datetime.min.time()) + timedelta(minutes=_minutes(off))
    if end <= start:
        end += timedelta(days=1)

    total = int((end - start).total_seconds() // 60)
    if total <= 0:
        return 0, {}

    mins = [start + timedelta(minutes=i) for i in range(total)]

    def is_night(t):
        m = t.hour * 60 + t.minute
        return not (day_start_m <= m < night_start_m)

    # Breaks come out of the MIDDLE of the shift: day minutes for `break`,
    # night minutes for `break_night`, each taken nearest the midpoint. If a
    # kind runs out, the nearest minutes of either kind go instead. Exactly the
    # script's removal order, which is what makes the fixture match.
    mid = len(mins) // 2
    keep = [True] * len(mins)
    order = sorted(range(len(mins)), key=lambda i: abs(i - mid))
    for need, pred in ((_minutes(brk), lambda t: not is_night(t)),
                       (_minutes(brkn), is_night)):
        for i in order:
            if need <= 0:
                break
            if keep[i] and pred(mins[i]):
                keep[i] = False
                need -= 1
        for i in order:
            if need <= 0:
                break
            if keep[i]:
                keep[i] = False
                need -= 1

    holidays = set()
    for h in (cfg.get("publicHolidays") or []):
        if h.get("date"):
            holidays.add(h["date"])

    day_r = _num(rate_row.get("day_rate"))
    night_r = _num(rate_row.get("night_rate"))
    sun_r = _num(rate_row.get("sunday_rate"))
    ph_r = _num(rate_row.get("public_holiday_rate"))
    ot8_day = _num(rate_row.get("ot_8_day_rate"))
    ot8_night = _num(rate_row.get("ot_8_night_rate"))
    ot10_day = _num(rate_row.get("ot_10_day_rate"))
    ot10_night = _num(rate_row.get("ot_10_night_rate"))

    buckets = {}
    worked = 0
    for i, t in enumerate(mins):
        if not keep[i]:
            continue
        worked += 1

        night = is_night(t)
        base = night_r if night else day_r
        rate = base if base is not None else 0.0

        # Penalties are decided per MINUTE on the minute's OWN date, so a
        # Sunday shift running into Monday stops being a Sunday at midnight.
        iso = "%04d-%02d-%02d" % (t.year, t.month, t.day)
        if t.weekday() == 6 and sun_r is not None:
            rate = max(rate, sun_r)
        if iso in holidays and ph_r is not None:
            rate = max(rate, ph_r)

        # Overtime is a FLOOR, not a replacement: max-of, like every other
        # penalty. The tier follows the minute's own day/night (§14); a null
        # tier means there is no floor, not a floor of zero.
        if worked > 600:
            ot = ot10_night if night else ot10_day
            if ot is None:
                ot = ot10_day if night else ot10_night
            if ot is not None:
                rate = max(rate, ot)
        elif worked > 480:
            ot = ot8_night if night else ot8_day
            if ot is None:
                ot = ot8_day if night else ot8_night
            if ot is not None:
                rate = max(rate, ot)

        # A D31 window multiplies whatever the minute had earned.
        best = 1.0
        for w in windows:
            if w["start"] <= t < w["end"] and w["multiplier"] > best:
                best = w["multiplier"]
        if best > 1.0:
            rate = rate * best

        key = round(rate, 6)
        buckets[key] = buckets.get(key, 0) + 1

    return worked, buckets


# ──────────────────────────────────────────────────────────────────────────────
# The public call
# ──────────────────────────────────────────────────────────────────────────────

_CENT = Decimal("0.01")


def _money(d):
    """Round once, half-up, as the invoice does (§5.2)."""
    return d.quantize(_CENT, rounding=ROUND_HALF_UP)


def expected_for_booking(figures, rules, set_code):
    """figures = get-booking-figures.php JSON. Pure: no I/O.

    Returns {calls: {call_id: {expected, hours, groups, status, reason,
                               notes, excluded_rows}},
             total, unpriced_calls, rules_as_of, rates_from}

    status is one of:
      ok           every row with times was priced
      partial      some rows priced, some excluded (A6)
      unpriced     rows have times but none could be priced
      no_times     no row on the call has both ends keyed
      before_rates the call predates the current card, so it is not priced
    """
    out_calls = {}
    booking_total = Decimal("0")
    unpriced = 0
    priced = 0          # calls that produced a figure, NOT calls seen

    cfg = pricing_rules.cfg_for_set(rules, set_code)
    if cfg is None:
        # An unknown card prices nothing. Never a stale guess (§5.1).
        for call in (figures.get("calls") or []):
            out_calls[int(call["call_id"])] = {
                "expected": None, "hours": 0.0, "groups": [],
                "status": "unpriced", "reason": "rules unavailable",
                "notes": [], "excluded_rows": 0,
            }
        return {"calls": out_calls, "total": None,
                "unpriced_calls": len(out_calls), "rules_as_of": None,
                "rates_from": None, "priced_calls": 0,
                "total_calls": len(out_calls),
                "total_reason": "rules unavailable"}

    rates = _rate_rows(rules, set_code)
    cutover = rates_from(rules, set_code)

    # The card's non-labour items, for A4.
    st = pricing_rules.find_set(rules, set_code) or {}
    items = {}
    for it in st.get("items") or []:
        if it.get("is_active") is False:
            continue
        items[str(it.get("code") or "")] = it

    # Crew rows by call.
    by_call = {}
    for row in (figures.get("crew") or []):
        by_call.setdefault(int(row.get("call_id")), []).append(row)

    years = set()
    for call in (figures.get("calls") or []):
        d = _call_date(call)
        if d:
            years.add(d.year)
            years.add(d.year + 1)
    windows = _windows_local(cfg, sorted(years)) if years else []

    for call in (figures.get("calls") or []):
        call_id = int(call["call_id"])
        rows = by_call.get(call_id, [])
        day = _call_date(call)

        entry = {"expected": None, "hours": 0.0, "groups": [],
                 "status": "no_times", "reason": "", "notes": [],
                 "excluded_rows": 0}

        if day is None:
            entry["status"] = "unpriced"
            entry["reason"] = "call has no date"
            out_calls[call_id] = entry
            unpriced += 1
            continue

        iso = day.isoformat()

        # A row is charged when it is confirmed, or when it was cancelled and
        # the CUSTOMER was still charged (A7: cancel_charge, not cancel_pay —
        # cancel_pay is what the crew member is paid, which drives the hours
        # table, not this figure).
        chargeable = [r for r in rows
                      if int(r.get("status") or 0) == 5
                      or int(r.get("cancel_charge") or 0) == 1]

        timed = [r for r in chargeable if _has_times(r)]

        if not timed:
            entry["status"] = "no_times"
            entry["reason"] = "no times keyed"
            out_calls[call_id] = entry
            continue

        # Calls before the cut-over are not priced on today's card.
        if iso < cutover:
            entry["status"] = "before_rates"
            entry["reason"] = "before current rates (%s)" % cutover
            entry["hours"] = float(sum(
                _price_worker(day, r.get("on"), r.get("off"), r.get("break"),
                              r.get("break_night"),
                              {"day_rate": 0}, cfg, [])[0] for r in timed) / 60.0)
            out_calls[call_id] = entry
            continue

        # §5.2 step 1: group by call + on + off + break + break_night + role,
        # so rows on different paygrades sharing a card role share a line,
        # exactly as the invoice groups them.
        groups = {}
        excluded = 0
        notes = []
        harness_rows = 0
        total_minutes = 0

        for r in timed:
            pg = int(r.get("paygrade_id") or 0)

            if pg in PAYGRADE_ITEM:                       # A4
                harness_rows += 1
                worked, _ = _price_worker(day, r.get("on"), r.get("off"),
                                          r.get("break"), r.get("break_night"),
                                          {"day_rate": 0}, cfg, [])
                total_minutes += worked
                continue

            if pg in PAYGRADE_EXCLUDED:                   # A5
                excluded += 1
                note = "1 row not priced (%s)" % PAYGRADE_EXCLUDED[pg]
                if note not in notes:
                    notes.append(note)
                worked, _ = _price_worker(day, r.get("on"), r.get("off"),
                                          r.get("break"), r.get("break_night"),
                                          {"day_rate": 0}, cfg, [])
                total_minutes += worked
                continue

            role = PAYGRADE_ROLE.get(pg)
            if role is None:
                excluded += 1
                note = "unknown paygrade %d" % pg
                if note not in notes:
                    notes.append(note)
                continue
            if role not in rates:
                excluded += 1
                note = "%s is not on this booking's card" % role
                if note not in notes:
                    notes.append(note)
                continue

            key = (str(r.get("on")), str(r.get("off")), str(r.get("break") or ""),
                   str(r.get("break_night") or ""), role)
            groups[key] = groups.get(key, 0) + 1

        call_total = Decimal("0")
        group_out = []

        for (on, off, brk, brkn, role), qty in sorted(groups.items()):
            rate_row = _row_for_date(rates.get(role), iso)
            if rate_row is None:
                excluded += qty
                continue

            # §5.2 step 2: price ONE worker, into (rate, minutes) buckets.
            worked, buckets = _price_worker(day, on, off, brk, brkn,
                                            rate_row, cfg, windows)
            total_minutes += worked * qty

            # §5.2 step 3: accumulate qty x minutes/60 x rate exactly. Decimal
            # throughout, no rounding until the call is done.
            amount = Decimal("0")
            for rate, minutes in buckets.items():
                amount += (Decimal(str(qty)) * Decimal(minutes) / Decimal(60)
                           * Decimal(str(rate)))
            call_total += amount

            group_out.append({
                "role": role, "on": on, "off": off, "break": brk,
                "break_night": brkn, "qty": qty,
                "hours": round(worked / 60.0, 4),
                "amount": float(_money(amount)),
            })

        # A4: the harness rows are charged once each, off the card's item.
        if harness_rows:
            item = items.get(PAYGRADE_ITEM[36])
            charge = _num((item or {}).get("charge_ex_gst"))
            if charge is None:
                excluded += harness_rows
                notes.append("%d harness row(s) not priced (no harness_hire item on this card)"
                             % harness_rows)
            else:
                call_total += Decimal(str(harness_rows)) * Decimal(str(charge))
                notes.append("includes harness hire (card: per harness)")
                group_out.append({
                    "role": "Harness Hire", "on": "", "off": "", "break": "",
                    "break_night": "", "qty": harness_rows, "hours": 0.0,
                    "amount": float(_money(Decimal(str(harness_rows)) * Decimal(str(charge)))),
                })

        entry["hours"] = round(total_minutes / 60.0, 2)
        entry["groups"] = group_out
        entry["excluded_rows"] = excluded
        entry["notes"] = notes

        if not group_out:
            # A6: a call with NO priceable row is still unpriced.
            entry["status"] = "unpriced"
            entry["reason"] = notes[0] if notes else "nothing priceable"
            entry["expected"] = None
            unpriced += 1
        else:
            # §5.2 step 4: round the call once; keep the booking exact.
            entry["expected"] = float(_money(call_total))
            booking_total += call_total
            priced += 1
            # A6: an excluded row no longer blanks its call.
            entry["status"] = "partial" if excluded else "ok"
            if excluded and not entry["reason"]:
                entry["reason"] = "%d row(s) not priced" % excluded

        out_calls[call_id] = entry

    meta = (rules or {}).get("last_changed")

    # A BOOKING WHERE NOTHING WAS PRICED HAS NO TOTAL — it does not have a
    # total of zero. Returning 0.0 here is what put "Expected invoice $0.00" on
    # #11770 and #11760, whose calls all predate the current card: every call
    # row correctly showed "—" while the money block above them read $0.00,
    # which is the silent-wrong-number this whole tab exists to prevent.
    #
    # `total_reason` carries WHY, so the tab can say "— (before current rates)"
    # rather than a bare dash. When every call agrees on a status the reason is
    # that status's own; a mixed booking gets the general one.
    total = None
    total_reason = ""
    if priced:
        total = float(_money(booking_total))
    else:
        statuses = set(e["status"] for e in out_calls.values())
        if len(statuses) == 1:
            only = statuses.pop()
            reasons = [e["reason"] for e in out_calls.values() if e["reason"]]
            total_reason = reasons[0] if reasons else only.replace("_", " ")
        elif out_calls:
            total_reason = "no calls priced"
        else:
            total_reason = "no calls"

    return {
        "calls": out_calls,
        "total": total,
        "total_reason": total_reason,
        "priced_calls": priced,
        "total_calls": len(out_calls),
        "unpriced_calls": unpriced,
        "rules_as_of": meta,
        "rates_from": cutover,
    }
