"""Performance slice D: estimated crew cost for calls that have not happened yet.

PURE. No Flask, no network, no database. The inputs are the
`forward_cost_inputs` block that get-performance-cost.php returns to Mike and
Joe only, and an Estimator cfg (for the day/night boundaries and the public
holidays). Everything here can be run from a test or from the command line.

THE IDEA (DESIGN-performance-d-v0_2.md)
  Price the future with the pay SmartStaff has actually been producing, not
  with paygrade tables. Rates are LEARNED from a "training" window of paid
  calls, then applied to calls that have not been paid yet.

  hours  The call's booked length is split into day / night / Sun-PH hours
         with the Estimator's own splitter, then multiplied by a factor
         learned from training (paid hours / booked hours; P8c found ~0.93).
  rate   Per seat, per band, the first of these that exists:
           1. confirmed person, their OWN rate for this kind of work and band
           2. confirmed Standard-crew person, the rate for their LEVEL
              (1 / A / B, from their latest GT code) in this band
           3. ROLE average: everyone paid on calls with this call-name head
           4. FAMILY average: every call of this pay family
           5. GLOBAL band average  (last resort, counted)
         Unfilled seats start at 3.
  Every seat records which rule priced it, so the fold can say how much of the
  estimate rests on each.

THE BACKTEST
  backtest() trains on the OLDER 13 weeks and prices the NEWER 13 weeks as if
  they had not happened, with the people actually paid treated as confirmed.
  It then compares with what was actually paid. Mike's pass mark (17 Nov):
  within 5% over the 13 weeks and within 15% in every week.
"""

from collections import OrderedDict
from datetime import date, datetime, timedelta

from estimator_calc import MODE_DURATION, parse_time_hhmm, split_day_night

BANDS = ("day", "night", "sph")

# Mike's pass mark, 17 Nov 2026 (D1).
PASS_TOTAL_PCT = 5.0
PASS_WEEK_PCT  = 15.0

# Which rule priced a seat. Order matters: it is the fallback chain.
SOURCES = ("own", "level", "role", "family", "global", "none")


# ──────────────────────────────────────────────────────────────────────────────
# Pay categories (P8a: 21 categories, one rate each, no overtime categories)
# ──────────────────────────────────────────────────────────────────────────────

# code -> (family, band, level). band "any" = paid the same day or night.
# level is only meaningful for Standard crew: "1", "A" or "B".
_KNOWN_CODES = {
    "GT1":    ("std", "day",   "1"), "GT1A":   ("std", "day",   "A"), "GT1B":   ("std", "day",   "B"),
    "GT2":    ("std", "night", "1"), "GT2A":   ("std", "night", "A"), "GT2B":   ("std", "night", "B"),
    "GTSPH":  ("std", "sph",   "1"), "GTSPHA": ("std", "sph",   "A"), "GTSPHB": ("std", "sph",   "B"),
    "GSHOW":  ("show", "any",  None),
    "GBOSS":  ("boss", "day",  None), "GBOSSN": ("boss", "night", None), "GBOSSSPH": ("boss", "sph", None),
    "GPFTE":  ("fork", "day",  None), "GPFTEN": ("fork", "night", None), "GPFTESPH": ("fork", "sph", None),
    "GSEAM":  ("seam", "any",  None), "GSEAMSPH": ("seam", "sph", None),
}


def parse_code(code):
    """(family, band, level) for a pay category. A code nobody has listed is
    family "other", band "any": its pay still counts, it just can't be placed
    in a band. Unknown codes are counted by the caller, never dropped."""
    c = str(code or "").strip().upper()
    if c in _KNOWN_CODES:
        return _KNOWN_CODES[c]
    return ("other", "any", None)


def code_known(code):
    return str(code or "").strip().upper() in _KNOWN_CODES


# ──────────────────────────────────────────────────────────────────────────────
# Call names
# ──────────────────────────────────────────────────────────────────────────────

def name_head(call_name):
    """The text before the first "-" or "/", trimmed and lower-cased, spaces
    collapsed. The same cut the forward book uses to find a role, so "Show
    Call / Load Out" and "Show Call - Spots" share one role average."""
    s = str(call_name or "")
    cuts = [i for i in (s.find("-"), s.find("/")) if i > 0]
    if cuts:
        s = s[:min(cuts)]
    return " ".join(s.lower().split())


def family_from_name(call_name):
    """A guess at the pay family from the name alone, for a call-name head
    with no history. Training data overrides it (learned_family)."""
    n = str(call_name or "").lower()
    if "boss" in n:
        return "boss"
    if "fork" in n:
        return "fork"
    if "seam" in n or "wardrobe" in n:
        return "seam" if "seam" in n else "show"
    if "show" in n or "spot" in n:
        return "show"
    return "std"


# ──────────────────────────────────────────────────────────────────────────────
# Hours, by band
# ──────────────────────────────────────────────────────────────────────────────

def _is_sunday(date_iso):
    try:
        return date.fromisoformat(date_iso).weekday() == 6
    except (TypeError, ValueError):
        return False


def holiday_set(cfg):
    return {h.get("date") for h in (cfg.get("publicHolidays") or []) if h.get("date")}


def band_hours(date_iso, start_time, est_length, cfg, holidays=None):
    """({day, night, sph}, ok). The booked shift split with the Estimator's own
    splitter (dayStart / nightStart / midnight), each piece placed in ONE band:
    Sun-PH if its date is a Sunday or public holiday, else day or night.

    No usable start time: every hour goes to the start date's band (Sun-PH or
    day) and ok is False so the caller can count it."""
    if holidays is None:
        holidays = holiday_set(cfg)
    out = {"day": 0.0, "night": 0.0, "sph": 0.0}
    try:
        length = float(est_length or 0.0)
    except (TypeError, ValueError):
        length = 0.0
    if length <= 0:
        return out, True

    hm = parse_time_hhmm(start_time) if start_time else None
    if hm is None or str(start_time).strip() in ("", "00:00:00"):
        b = "sph" if (_is_sunday(date_iso) or date_iso in holidays) else "day"
        out[b] += length
        return out, False

    try:
        d = date.fromisoformat(date_iso)
    except (TypeError, ValueError):
        out["day"] += length
        return out, False
    start = datetime(d.year, d.month, d.day, hm[0], hm[1])
    end = start + timedelta(hours=length)
    for p in split_day_night(start, end, cfg, MODE_DURATION):
        hrs = p["dayHrs"] + p["nightHrs"]
        if _is_sunday(p["dateISO"]) or p["dateISO"] in holidays:
            out["sph"] += hrs
        elif p["nightHrs"] > 0:
            out["night"] += hrs
        else:
            out["day"] += hrs
    return out, True


def week_key(date_iso):
    """Monday of the call's start date, as YYYY-MM-DD."""
    d = date.fromisoformat(date_iso)
    return (d - timedelta(days=d.weekday())).isoformat()


# ──────────────────────────────────────────────────────────────────────────────
# Learning rates from paid calls
# ──────────────────────────────────────────────────────────────────────────────

class _Pool:
    """Σhours and Σpay. rate() is pay per hour, or None with no hours."""
    __slots__ = ("h", "p")

    def __init__(self):
        self.h = 0.0
        self.p = 0.0

    def add(self, h, p):
        self.h += h
        self.p += p

    def rate(self):
        return self.p / self.h if self.h > 0 else None


def _pool(d, key):
    if key not in d:
        d[key] = _Pool()
    return d[key]


def _add_band(d, key_prefix, band, h, p):
    """A band-agnostic code ("any") counts toward day AND night, never Sun-PH:
    on a Sunday, Show crew are paid GTSPH, not GSHOW (P8b)."""
    bands = ("day", "night") if band == "any" else (band,)
    for b in bands:
        _pool(d, key_prefix + (b,)).add(h, p)


def learn(calls):
    """Rates from a list of PAID history calls (the inputs' history.calls
    shape). Returns a model dict that price_call() reads."""
    own, level_pay, role, family, glob = {}, {}, {}, {}, {}
    fam_votes = {}
    person_level = {}           # crew index -> (date_iso, level)
    paid_hours = 0.0
    booked_hours = 0.0
    unknown_codes = {}

    for c in calls:
        head = name_head(c.get("name"))
        crew = c.get("crew") or []
        call_paid_hours = 0.0
        for person in crew:
            idx = person.get("i")
            for code, hp in (person.get("pay") or {}).items():
                try:
                    h, p = float(hp[0]), float(hp[1])
                except (TypeError, ValueError, IndexError):
                    continue
                if h == 0:
                    continue
                if not code_known(code):
                    unknown_codes[code] = unknown_codes.get(code, 0.0) + h
                fam, band, lvl = parse_code(code)
                call_paid_hours += h
                _add_band(own, (idx, fam), band, h, p)
                _add_band(role, (head,), band, h, p)
                _add_band(family, (fam,), band, h, p)
                _add_band(glob, (), band, h, p)
                if fam == "std":
                    _pool(level_pay, (band, lvl)).add(h, p)
                    seen = person_level.get(idx)
                    if seen is None or str(c.get("date_iso")) >= seen[0]:
                        person_level[idx] = (str(c.get("date_iso")), lvl)
                if not (fam == "std" and band == "sph"):
                    fv = fam_votes.setdefault(head, {})
                    fv[fam] = fv.get(fam, 0.0) + h
        try:
            est = float(c.get("est_length") or 0.0)
        except (TypeError, ValueError):
            est = 0.0
        if est > 0 and crew and call_paid_hours > 0:
            paid_hours += call_paid_hours
            booked_hours += est * len(crew)

    learned_family = {h: max(v.items(), key=lambda kv: kv[1])[0]
                      for h, v in fam_votes.items() if v}

    def rates(d):
        return {k: v.rate() for k, v in d.items() if v.rate() is not None}

    return {
        "own":       rates(own),          # (idx, family, band) -> rate
        "level":     rates(level_pay),    # (band, level) -> rate
        "role":      rates(role),         # (head, band) -> rate
        "family":    rates(family),       # (family, band) -> rate
        "global":    rates(glob),         # (band,) -> rate
        "person_level": {k: v[1] for k, v in person_level.items()},
        "family_of": learned_family,      # head -> family
        "hours_factor": (paid_hours / booked_hours) if booked_hours > 0 else 1.0,
        "paid_hours": paid_hours,
        "booked_hours": booked_hours,
        "unknown_codes": unknown_codes,
        "calls": len(calls),
    }


# ──────────────────────────────────────────────────────────────────────────────
# Pricing one call
# ──────────────────────────────────────────────────────────────────────────────

def seat_rate(model, head, fam, band, person=None):
    """(rate, source) for one seat in one band. person None = unfilled."""
    m = model
    # Show crew on a Sunday or public holiday are paid Standard Sun-PH at
    # their own level (P8b), so they are priced as Standard there.
    eff_fam = "std" if (fam == "show" and band == "sph") else fam

    if person is not None:
        r = m["own"].get((person, eff_fam, band))
        if r is not None:
            return r, "own"
        if eff_fam == "std":
            lvl = m["person_level"].get(person)
            if lvl is not None:
                r = m["level"].get((band, lvl))
                if r is not None:
                    return r, "level"
    r = m["role"].get((head, band))
    if r is not None:
        return r, "role"
    r = m["family"].get((eff_fam, band))
    if r is not None:
        return r, "family"
    r = m["global"].get((band,))
    if r is not None:
        return r, "global"
    return 0.0, "none"


def price_call(model, call, cfg, seats, holidays=None):
    """Estimated crew pay for one call.

    seats is a list: a crew index for a confirmed person, None for an unfilled
    seat. Returns {cost, hours, by_source: {source: cost}, seats_by_source:
    {source: n}, timed: bool}. A seat's source is the rule that priced its
    LARGEST band (the fold reports seats, not band-hours)."""
    hrs, timed = band_hours(call.get("date_iso"), call.get("start_time"),
                            call.get("est_length"), cfg, holidays)
    factor = model["hours_factor"]
    head = name_head(call.get("name"))
    fam = model["family_of"].get(head) or family_from_name(call.get("name"))

    cost = 0.0
    total_hours = 0.0
    by_source = {}
    seats_by_source = {}
    for person in seats:
        seat_cost = 0.0
        main_band, main_h, main_src = None, -1.0, "none"
        for band in BANDS:
            h = hrs[band] * factor
            if h <= 0:
                continue
            r, src = seat_rate(model, head, fam, band, person)
            seat_cost += h * r
            by_source[src] = by_source.get(src, 0.0) + h * r
            total_hours += h
            if h > main_h:
                main_band, main_h, main_src = band, h, src
        cost += seat_cost
        seats_by_source[main_src] = seats_by_source.get(main_src, 0) + 1
    return {"cost": cost, "hours": total_hours, "by_source": by_source,
            "seats_by_source": seats_by_source, "timed": timed}


def call_actual_pay(call):
    total = 0.0
    for person in call.get("crew") or []:
        for hp in (person.get("pay") or {}).values():
            try:
                total += float(hp[1])
            except (TypeError, ValueError, IndexError):
                pass
    return total


# ──────────────────────────────────────────────────────────────────────────────
# Forward estimate (what the card draws)
# ──────────────────────────────────────────────────────────────────────────────

def estimate_forward(model, forward_calls, cfg):
    """Per week, estimated crew pay at two levels (D2 = both):

      secured   confirmed seats only, each at their own rate where known
      required  confirmed seats + unfilled seats at the role average

    Calls with no booked length are skipped and counted (they have no hours
    to price), as the revenue side already does."""
    holidays = holiday_set(cfg)
    weeks = OrderedDict()
    counts = {"calls": 0, "zero_length_calls": 0, "untimed_calls": 0,
              "seats_by_source": {s: 0 for s in SOURCES},
              "cost_by_source": {s: 0.0 for s in SOURCES}}
    for c in sorted(forward_calls, key=lambda x: (str(x.get("date_iso")), str(x.get("start_time")))):
        try:
            est = float(c.get("est_length") or 0.0)
        except (TypeError, ValueError):
            est = 0.0
        if est <= 0:
            counts["zero_length_calls"] += 1
            continue
        confirmed = list(c.get("crew") or [])
        required = max(int(c.get("required") or 0), len(confirmed))
        sec = price_call(model, c, cfg, confirmed, holidays)
        unf = price_call(model, c, cfg, [None] * (required - len(confirmed)), holidays)
        # PHP's own week key when it sent one ("2026-W47"), so the cost lands
        # in the same bucket as the forward revenue; else the Monday date.
        wk = c.get("week_key") or week_key(c["date_iso"])
        w = weeks.setdefault(wk, {"secured_cost": 0.0, "required_cost": 0.0,
                                  "secured_hours": 0.0, "required_hours": 0.0, "calls": 0})
        w["secured_cost"] += sec["cost"]
        w["secured_hours"] += sec["hours"]
        w["required_cost"] += sec["cost"] + unf["cost"]
        w["required_hours"] += sec["hours"] + unf["hours"]
        w["calls"] += 1
        counts["calls"] += 1
        if not sec["timed"]:
            counts["untimed_calls"] += 1
        for part in (sec, unf):
            for s, n in part["seats_by_source"].items():
                counts["seats_by_source"][s] += n
            for s, v in part["by_source"].items():
                counts["cost_by_source"][s] += v
    for w in weeks.values():
        for k in ("secured_cost", "required_cost", "secured_hours", "required_hours"):
            w[k] = round(w[k], 2)
    for s in SOURCES:
        counts["cost_by_source"][s] = round(counts["cost_by_source"][s], 2)
    return {"weeks": weeks, "counts": counts,
            "hours_factor": round(model["hours_factor"], 4)}


# ──────────────────────────────────────────────────────────────────────────────
# Backtest
# ──────────────────────────────────────────────────────────────────────────────

def _pct(est, act):
    return (est - act) / act * 100.0 if act else None


def backtest(train_calls, test_calls, cfg, total_pct=PASS_TOTAL_PCT, week_pct=PASS_WEEK_PCT):
    """Train on train_calls, price test_calls as if they had not happened, and
    compare with what was actually paid.

    Two runs, because they test different things:
      people  each paid person treated as a CONFIRMED seat (own rate first) —
              this is how the secured level is priced
      roles   every seat treated as UNFILLED (role average first) — this is
              how the gap between secured and required is priced

    Calls with no booked length can't be estimated. Their pay is left out of
    BOTH sides and counted, so it can't flatter or sink the result."""
    model = learn(train_calls)
    holidays = holiday_set(cfg)
    runs = {"people": OrderedDict(), "roles": OrderedDict()}
    skipped = {"calls": 0, "pay": 0.0}
    untimed = 0
    band_check = {"booked_night_share": None, "paid_night_share": None}
    booked_bands = {"day": 0.0, "night": 0.0, "sph": 0.0}
    paid_bands = {"day": 0.0, "night": 0.0, "sph": 0.0, "any": 0.0}

    for c in test_calls:
        actual = call_actual_pay(c)
        try:
            est_len = float(c.get("est_length") or 0.0)
        except (TypeError, ValueError):
            est_len = 0.0
        crew = c.get("crew") or []
        if est_len <= 0 or not crew:
            skipped["calls"] += 1
            skipped["pay"] += actual
            continue
        people = [p.get("i") for p in crew]
        a = price_call(model, c, cfg, people, holidays)
        b = price_call(model, c, cfg, [None] * len(people), holidays)
        if not a["timed"]:
            untimed += 1
        wk = week_key(c["date_iso"])
        for name, res in (("people", a), ("roles", b)):
            w = runs[name].setdefault(wk, {"estimated": 0.0, "actual": 0.0, "calls": 0})
            w["estimated"] += res["cost"]
            w["actual"] += actual
            w["calls"] += 1

        hrs, _ = band_hours(c.get("date_iso"), c.get("start_time"), est_len, cfg, holidays)
        for k in booked_bands:
            booked_bands[k] += hrs[k] * len(crew)
        for person in crew:
            for code, hp in (person.get("pay") or {}).items():
                try:
                    paid_bands[parse_code(code)[1]] += float(hp[0])
                except (TypeError, ValueError, IndexError):
                    pass

    # Night share outside Sun-PH: does pay's day/night line sit where the
    # Estimator's does? Band-agnostic pay ("any") is left out of the paid side.
    bn = booked_bands["day"] + booked_bands["night"]
    pn = paid_bands["day"] + paid_bands["night"]
    if bn > 0:
        band_check["booked_night_share"] = round(booked_bands["night"] / bn, 4)
    if pn > 0:
        band_check["paid_night_share"] = round(paid_bands["night"] / pn, 4)

    out = {"model": {"train_calls": model["calls"],
                     "hours_factor": round(model["hours_factor"], 4),
                     "unknown_codes": model["unknown_codes"]},
           "skipped": {"calls": skipped["calls"], "pay": round(skipped["pay"], 2)},
           "untimed_calls": untimed,
           "band_check": band_check,
           "pass_mark": {"total_pct": total_pct, "week_pct": week_pct}}
    for name, weeks in runs.items():
        est = sum(w["estimated"] for w in weeks.values())
        act = sum(w["actual"] for w in weeks.values())
        rows = []
        worst = 0.0
        for wk in sorted(weeks):
            w = weeks[wk]
            p = _pct(w["estimated"], w["actual"])
            if p is not None:
                worst = max(worst, abs(p))
            rows.append({"week": wk, "calls": w["calls"],
                         "estimated": round(w["estimated"], 2),
                         "actual": round(w["actual"], 2),
                         "pct": None if p is None else round(p, 2)})
        tp = _pct(est, act)
        passed = (tp is not None and abs(tp) <= total_pct and worst <= week_pct
                  and all(r["pct"] is not None for r in rows))
        out[name] = {"estimated": round(est, 2), "actual": round(act, 2),
                     "total_pct": None if tp is None else round(tp, 2),
                     "worst_week_pct": round(worst, 2),
                     "weeks": rows, "passed": passed}
    out["passed"] = out["people"]["passed"] and out["roles"]["passed"]
    return out


def split_history(inputs):
    """(older_calls, newer_calls) from the inputs' history block."""
    calls = (inputs.get("history") or {}).get("calls") or []
    older = [c for c in calls if c.get("half") == "older"]
    newer = [c for c in calls if c.get("half") == "newer"]
    return older, newer
