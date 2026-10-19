"""Every pricing rule, read live from the Estimator (D32).

One source of truth: the Estimator's `rate-cards` edge function returns the
settings, public holidays, D31 windows, every rate card set with its rates,
call names and items, and the shared test cases (D33). THE GOAT keeps NO copy
of any rule. The disk file below is a cache of the last good read: it is never
edited and never shipped.

Modelled line for line on public_holidays.py. Lazy by design: importing this
module, or configure(), does no network work. The first load_rules() call does
the fetch, so a slow or down Estimator can never delay THE GOAT opening.

Never raises to a caller. The fallbacks, in order:
  1. in-memory copy, good for 12 hours (force=True skips it)
  2. a fresh fetch, which also rewrites the disk copy
  3. the last good disk copy                 -> meta["stale"] = True
  4. nothing at all -> rules None, meta["unavailable"] = True, and every
     caller prices NOTHING. Never silently old rates, never a default.

THE SECRET. RATE_CARDS_SECRET is sent only as the X-GOAT-Secret header, never
logged, never sent to the browser. It ships inside every DMG (the same trust
model as the Anthropic key and GP_PUSH_SECRET), so anyone with the app can read
the customer cards. That is acceptable because only staff have the app, but it
is the boundary: C1 is enforced against the public, not against staff.
"""
import json
import os
import threading
import time
from datetime import datetime

import estimator_calc as ec

RATE_CARDS_URL = "https://jtsxnqfudaqxuqinhkut.supabase.co/functions/v1/rate-cards"
SCHEMA = "rules-1"

MEMORY_TTL_SECS = 12 * 3600
RETRY_AFTER_FAIL_SECS = 300   # a down Estimator costs one timeout per 5 min, not one per request
FETCH_TIMEOUT_SECS = 15

STANDARD = "standard"
MOPT = "mopt"
MOPT_AO = "mopt-ao"
SET_CODES = (STANDARD, MOPT, MOPT_AO)

# The labels the Estimator prints (rateCards.ts rateCardLabel), plus Standard.
SET_LABELS = {STANDARD: "Standard", MOPT: "MOPT — Year Round", MOPT_AO: "MOPT — Australian Open"}

_cfg = {"url": RATE_CARDS_URL, "secret": "", "cache_file": ""}
_lock = threading.Lock()
_state = {
    "rules": None,       # the validated rules-1 body, or None before the first load
    "meta": None,
    "loaded_at": 0.0,    # monotonic; when rules were last set
    "fail_at": 0.0,      # monotonic; last failed fetch, for the retry backoff
}


def configure(secret, cache_file, url=None):
    """Set the secret and the disk-cache path. No I/O. A missing secret is
    allowed: loads then return the disk copy or 'unavailable'."""
    with _lock:
        _cfg["url"] = (url or RATE_CARDS_URL).strip().rstrip("/")
        _cfg["secret"] = (secret or "").strip()
        _cfg["cache_file"] = cache_file or ""
        _state.update(rules=None, meta=None, loaded_at=0.0, fail_at=0.0)


def _log(msg):
    print(f"[pricing-rules] {msg}")


# ─── validation (§3.2) ───────────────────────────────────────────────────────

def validate(rules):
    """None if the response can be trusted, else the first reason it can't.

    A response we can't trust is a failure, whole: NO gap is ever filled with a
    default, because every default moves money (the reasoning behind the old
    load_forward_rate_card). Unknown keys are ignored, so a new column on
    rate_items (replaces_code, D34) or anywhere else never fails a read."""
    if not isinstance(rules, dict):
        return "response is not an object"
    if rules.get("schema") != SCHEMA:
        return f"schema is {rules.get('schema')!r}, expected {SCHEMA!r}"

    s = rules.get("settings")
    if not isinstance(s, dict):
        return "settings missing"
    missing = [k for k in ("dayStart", "nightStart", "minBillableHours")
               if s.get(k) in (None, "")]
    if missing:
        return "settings missing: " + ", ".join(missing)
    try:
        float(s["minBillableHours"])
    except (TypeError, ValueError):
        return "settings.minBillableHours is not a number"

    sets = rules.get("sets")
    if not isinstance(sets, list):
        return "sets missing"
    for st in sets:
        if not isinstance(st, dict) or not st.get("code") or not isinstance(st.get("rates"), list):
            return "a set has no code or no rates list"
    std = [st for st in sets if st.get("code") == STANDARD]
    if not std or not std[0]["rates"]:
        return "no standard set with at least one rate"

    for key in ("public_holidays", "double_penalty_windows", "test_cases"):
        if not isinstance(rules.get(key), list):
            return f"{key} is not a list"
    for c in rules["test_cases"]:
        if not isinstance(c, dict) or any(c.get(k) in (None, "") for k in
                                          ("code", "scope", "input", "expected")):
            return "a test case is missing code, scope, input or expected"
    return None


# ─── fetch / disk ────────────────────────────────────────────────────────────

def _fetch_remote():
    """(rules, error). The secret is only ever sent as a header; errors carry
    the HTTP status or exception type, never the request, so it cannot leak."""
    if not _cfg["url"] or not _cfg["secret"]:
        return None, "not configured (no RATE_CARDS_SECRET)"
    try:
        import requests as http   # lazy: the maths and the tests need no network stack
        r = http.get(_cfg["url"], headers={"X-GOAT-Secret": _cfg["secret"]},
                     timeout=FETCH_TIMEOUT_SECS)
    except Exception as e:
        return None, f"request failed: {type(e).__name__}"
    if r.status_code == 401:
        return None, "HTTP 401 (RATE_CARDS_SECRET rejected)"
    if r.status_code != 200:
        return None, f"HTTP {r.status_code}"
    try:
        rules = r.json()
    except Exception:
        return None, "bad JSON"
    err = validate(rules)
    if err:
        return None, f"untrusted response: {err}"
    if not rules["public_holidays"] or not rules["double_penalty_windows"]:
        # Valid (it's data, not a fault), but both empty is suspicious.
        _log("warning: public_holidays has %d rows, double_penalty_windows %d"
             % (len(rules["public_holidays"]), len(rules["double_penalty_windows"])))
    return rules, None


def _read_disk():
    """(rules, saved_at_iso) or (None, None). The disk copy is validated with
    the same rules as a fresh read: a cache we can't trust is no cache."""
    path = _cfg["cache_file"]
    if not path:
        return None, None
    try:
        with open(path) as f:
            d = json.load(f)
        rules = d.get("rules")
        if validate(rules):
            return None, None
        return rules, d.get("saved_at")
    except Exception:
        return None, None


def _write_disk(rules, saved_at):
    """Temp file + replace, so a crash mid-write can't destroy the last good
    copy that the stale fallback depends on."""
    path = _cfg["cache_file"]
    if not path:
        return
    tmp = path + ".tmp"
    try:
        with open(tmp, "w") as f:
            json.dump({"saved_at": saved_at, "rules": rules}, f, indent=1)
        os.replace(tmp, path)
    except Exception:
        try:
            os.remove(tmp)
        except Exception:
            pass


def _meta(rules, fetched_at, stale, err):
    return {"source": "Estimator", "fetched_at": fetched_at,
            "last_changed": (rules or {}).get("last_changed"),
            "stale": stale, "unavailable": rules is None, "error": err}


# ─── public API ──────────────────────────────────────────────────────────────

def load_rules(force=False):
    """(rules, meta). rules is the validated rules-1 body, or None.
    meta = {source, fetched_at, last_changed, stale, unavailable, error}.
    Never raises."""
    try:
        with _lock:
            now = time.monotonic()
            have = _state["rules"] is not None
            fresh = have and (now - _state["loaded_at"]) < MEMORY_TTL_SECS \
                and not _state["meta"].get("stale")
            backing_off = (now - _state["fail_at"]) < RETRY_AFTER_FAIL_SECS \
                and _state["fail_at"] > 0
            if have and not force and (fresh or backing_off):
                return _state["rules"], dict(_state["meta"])
            if not have and not force and backing_off:
                return None, _meta(None, None, False,
                                   _state["meta"] and _state["meta"].get("error"))

            rules, err = _fetch_remote()
            if rules is not None:
                fetched_at = datetime.now().isoformat(timespec="seconds")
                _write_disk(rules, fetched_at)
                meta = _meta(rules, fetched_at, False, None)
                _state.update(rules=rules, meta=meta, loaded_at=now, fail_at=0.0)
                return rules, dict(meta)

            _log(f"read failed: {err}")
            _state["fail_at"] = now
            disk, saved_at = _read_disk()
            if disk:
                meta = _meta(disk, saved_at, True, err)
                _state.update(rules=disk, meta=meta, loaded_at=now)
                return disk, dict(meta)
            meta = _meta(None, None, False, err)
            _state.update(rules=None, meta=meta)
            return None, dict(meta)
    except Exception as e:
        return None, _meta(None, None, False, f"internal: {type(e).__name__}")


def _rate_row(r):
    """One rate-cards row -> the RateRow shape calc_line reads. A null stays
    None: ot_8_day_rate is null on every MOPT row and _to_number(None) -> 0
    already means "no tier". ot_8_night_rate / ot_10_night_rate are ignored,
    as calc.ts ignores them — parity, not a bug to fix here."""
    return {
        "role":             r.get("role_name"),
        "day":              r.get("day_rate"),
        "night":            r.get("night_rate"),
        "sunday":           r.get("sunday_rate"),
        "publicHoliday":    r.get("public_holiday_rate"),
        "over8":            r.get("ot_8_day_rate"),
        "over10":           r.get("ot_10_day_rate"),
        "effectiveFrom":    r.get("effective_from"),
        "sortOrder":        r.get("sort_order"),
        "minBillableHours": r.get("min_billable_hours"),
        "callNames":        list(r.get("call_names") or []),
    }


def find_set(rules, set_code):
    for st in ((rules or {}).get("sets") or []):
        if st.get("code") == set_code:
            return st
    return None


def cfg_for_set(rules, set_code):
    """An estimator_calc config for one card, or None for an unknown set_code
    (the caller reports it unpriced).

    A customer set's cfg holds ONLY that set's rates (NOTES §2): no Standard
    fallback, so a Standard call name on a MOPT booking resolves to nothing."""
    st = find_set(rules, set_code)
    if st is None:
        return None
    s = rules["settings"]
    return {
        "rates":                [_rate_row(r) for r in st.get("rates") or []],
        "dayStart":             s["dayStart"],
        "nightStart":           s["nightStart"],
        "minBillableHours":     float(s["minBillableHours"]),
        "gstRate":              s.get("gstRate"),
        "publicHolidays":       [{"date": h.get("date")} for h in rules.get("public_holidays") or []],
        "doublePenaltyWindows": list(rules.get("double_penalty_windows") or []),
    }


def set_for_booking(answers):
    """The set code a booking's two answers select (C7, C8).

    No answers (no row) -> Standard. MOPT with Australian Open unanswered can't
    be stored (the edge function refuses it with a 422), so if it ever arrives
    it returns None and the caller prices nothing rather than guess."""
    if not answers:
        return STANDARD
    if not answers.get("mopt"):
        return STANDARD
    ao = answers.get("australian_open")
    if ao is True:
        return MOPT_AO
    if ao is False:
        return MOPT
    return None


def test_cases(rules):
    return list((rules or {}).get("test_cases") or [])


def summary(rules):
    """Counts for Administration › Reference data."""
    sets = (rules or {}).get("sets") or []
    return {
        "sets":       len(sets),
        "rates":      sum(len(s.get("rates") or []) for s in sets),
        "items":      sum(len(s.get("items") or []) for s in sets),
        "windows":    len((rules or {}).get("double_penalty_windows") or []),
        "holidays":   len((rules or {}).get("public_holidays") or []),
        "test_cases": len(test_cases(rules)),
    }


def call_names_for_set(rules, set_code):
    """Every call name on one card, for the 1.2 import tripwire (§7.3)."""
    st = find_set(rules, set_code)
    names = []
    for r in (st or {}).get("rates") or []:
        for n in r.get("call_names") or []:
            if n not in names:
                names.append(n)
    return names


def customer_ids_by_set(rules):
    """{set_code: set(customer ids)} for the §7.5 cross-check. Empty today."""
    out = {}
    for st in ((rules or {}).get("sets") or []):
        ids = set()
        for i in st.get("smartstaff_customer_ids") or []:
            try:
                ids.add(int(i))
            except (TypeError, ValueError):
                pass
        out[st.get("code")] = ids
    return out


# ─── shared test cases (D33) ─────────────────────────────────────────────────
# One runner, used by test_pricing_cases.py (the build gate) and by
# Administration's "Run shared test cases" button, so a user's installed copy
# is checked by exactly the code the build machine ran. Mirrors the
# Estimator's pricingCases.ts runPricingCase.

CASE_ROLE = "T"
_SEG_FIELDS_EXACT = ("tier", "period", "dateISO")
_SEG_FIELDS_CENTS = ("hours", "rate", "costExGst")


def _cents(n):
    return ec._js_round(float(n) * 100)


def _same_to_the_cent(a, b):
    try:
        return _cents(a) == _cents(b)
    except (TypeError, ValueError):
        return False


def cfg_for_case(inp):
    """The cfg a case is priced under, built from the case ALONE: one rate row
    named "T", the case's settings, holidays (bare strings in the case,
    {"date": ...} dicts for _is_public_holiday) and windows."""
    rate = dict(inp.get("rate") or {})
    s = inp.get("settings") or {}
    return {
        "rates": [dict(rate, role=CASE_ROLE)],
        "dayStart": s.get("dayStart"),
        "nightStart": s.get("nightStart"),
        "minBillableHours": s.get("minBillableHours"),
        "publicHolidays": [{"date": d} for d in (inp.get("publicHolidays") or [])],
        "doublePenaltyWindows": list(inp.get("doublePenaltyWindows") or []),
    }


def run_case(case):
    """{code, title, rule, status: pass|fail|skipped, expected, got, problems}."""
    code = str(case.get("code") or "(no code)")
    out = {"code": code, "title": case.get("title") or "", "rule": case.get("rule") or "",
           "status": "fail", "expected": None, "got": None, "problems": []}
    try:
        inp, exp = case["input"], case["expected"]
        out["expected"] = exp.get("costExGst")
        if case.get("scope") == "goat":
            out["status"] = "skipped"      # Stage 4 adds these and their runner
            return out
        if case.get("scope") != "both":
            out["problems"].append(f"unknown scope {case.get('scope')!r}")
            return out
        r = ec.calc_line(dict(inp["line"], id=code, role=CASE_ROLE),
                         cfg_for_case(inp), ec.MODE_DURATION)   # calc.ts's clock
    except Exception as e:
        out["problems"].append(f"could not run: {type(e).__name__}: {e}")
        return out

    out["got"] = r["costExGst"]
    probs = list(r["errors"])
    if not _same_to_the_cent(r["costExGst"], exp.get("costExGst")):
        probs.append(f"costExGst {r['costExGst']:.2f}, expected {exp.get('costExGst')}")
    if exp.get("billableHours") is not None and \
            not _same_to_the_cent(r["billableHours"], exp["billableHours"]):
        probs.append(f"billableHours {r['billableHours']}, expected {exp['billableHours']}")
    want = exp.get("segments")
    if want is not None:
        got = r["segments"]
        if len(got) != len(want):
            probs.append(f"{len(got)} segments, expected {len(want)}")
        else:
            for i, (g, w) in enumerate(zip(got, want), 1):
                for k in _SEG_FIELDS_EXACT:
                    if g.get(k) != w.get(k):
                        probs.append(f"segment {i} {k} {g.get(k)!r}, expected {w.get(k)!r}")
                for k in _SEG_FIELDS_CENTS:
                    if not _same_to_the_cent(g.get(k), w.get(k)):
                        probs.append(f"segment {i} {k} {g.get(k)}, expected {w.get(k)}")
                # calc.ts sets doublePenalty only inside a window; absent is False.
                if "doublePenalty" in w and bool(g.get("doublePenalty")) != bool(w["doublePenalty"]):
                    probs.append(f"segment {i} doublePenalty {bool(g.get('doublePenalty'))}, "
                                 f"expected {w['doublePenalty']}")
    out["problems"] = probs
    out["status"] = "fail" if probs else "pass"
    return out


def run_shared_cases(cases):
    """{results, passed, failed, skipped, ran, ok}. ok needs at least one case
    run and none failed: a gate that checked nothing has not passed."""
    results = [run_case(c) for c in cases]
    passed = sum(1 for r in results if r["status"] == "pass")
    failed = sum(1 for r in results if r["status"] == "fail")
    skipped = sum(1 for r in results if r["status"] == "skipped")
    ran = passed + failed
    return {"results": results, "passed": passed, "failed": failed,
            "skipped": skipped, "ran": ran, "ok": ran > 0 and failed == 0}
