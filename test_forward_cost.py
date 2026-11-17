"""Tests for forward_cost.py (Performance slice D). Pure, no network.

    python3 -m pytest test_forward_cost.py -q
"""

import json
import os
import tempfile

import pytest

import forward_cost as fc

CFG = {"dayStart": "08:00", "nightStart": "20:00",
       "publicHolidays": [{"date": "2026-11-03"}],   # Melbourne Cup, a Tuesday
       "doublePenaltyWindows": []}


def call(cid, date_iso, start, length, name, crew, half="older", required=None):
    c = {"id": cid, "date_iso": date_iso, "start_time": start, "est_length": length,
         "name": name, "booking_id": 1, "half": half, "crew": crew}
    if required is not None:
        c["required"] = required
    return c


def paid(i, **codes):
    return {"i": i, "pay": {k: list(v) for k, v in codes.items()}}


# ── categories and names ────────────────────────────────────────────────────

def test_parse_code_known_and_unknown():
    assert fc.parse_code("GT1B") == ("std", "day", "B")
    assert fc.parse_code("gt2a") == ("std", "night", "A")
    assert fc.parse_code("GTSPH") == ("std", "sph", "1")
    assert fc.parse_code("GSHOW") == ("show", "any", None)
    assert fc.parse_code("GBOSSN") == ("boss", "night", None)
    assert fc.parse_code("GPFTESPH") == ("fork", "sph", None)
    assert fc.parse_code("GCUST1") == ("other", "any", None)
    assert not fc.code_known("GCUST1") and fc.code_known("GSEAM")


@pytest.mark.parametrize("name,head", [
    ("Show Call / Load Out", "show call"),
    ("Show Call - Spots", "show call"),
    ("  Load  In - Loaders/Pushers", "load in"),
    ("Crew Boss", "crew boss"),
    (None, ""),
])
def test_name_head(name, head):
    assert fc.name_head(name) == head


def test_family_from_name():
    assert fc.family_from_name("Crew Boss") == "boss"
    assert fc.family_from_name("Forklift Operator") == "fork"
    assert fc.family_from_name("Seamstress") == "seam"
    assert fc.family_from_name("Wardrobe") == "show"
    assert fc.family_from_name("Show Call") == "show"
    assert fc.family_from_name("Load In") == "std"


# ── hours by band ───────────────────────────────────────────────────────────

def test_band_hours_day_only():
    h, ok = fc.band_hours("2026-11-18", "09:00:00", 5, CFG)   # Wednesday
    assert ok and h == {"day": 5.0, "night": 0.0, "sph": 0.0}


def test_band_hours_crosses_night_and_midnight():
    # Sat 21 Nov 18:00 for 8h: 2 day, 4 night Saturday, then 2h on SUNDAY -> sph
    h, ok = fc.band_hours("2026-11-21", "18:00", 8, CFG)
    assert ok
    assert h["day"] == pytest.approx(2.0)
    assert h["night"] == pytest.approx(4.0)
    assert h["sph"] == pytest.approx(2.0)


def test_band_hours_public_holiday_is_sph():
    h, ok = fc.band_hours("2026-11-03", "10:00", 4, CFG)
    assert h == {"day": 0.0, "night": 0.0, "sph": 4.0}


def test_band_hours_early_morning_is_night():
    h, _ = fc.band_hours("2026-11-18", "05:00", 4, CFG)   # 05-08 night, 08-09 day
    assert h["night"] == pytest.approx(3.0) and h["day"] == pytest.approx(1.0)


def test_band_hours_untimed_and_zero():
    h, ok = fc.band_hours("2026-11-22", "00:00:00", 6, CFG)  # Sunday, no time
    assert not ok and h["sph"] == 6.0
    h, ok = fc.band_hours("2026-11-18", "", 6, CFG)
    assert not ok and h["day"] == 6.0
    h, ok = fc.band_hours("2026-11-18", "09:00", 0, CFG)
    assert ok and sum(h.values()) == 0


def test_week_key_is_monday():
    assert fc.week_key("2026-11-22") == "2026-11-16"
    assert fc.week_key("2026-11-16") == "2026-11-16"


# ── learning ────────────────────────────────────────────────────────────────

def _training():
    return [
        # Load In, Wed day, 2 people, booked 5h, each paid 4.5h
        call(1, "2026-08-19", "09:00", 5, "Load In - Pushers",
             [paid(0, GT1=(4.5, 4.5 * 32.33)), paid(1, GT1B=(4.5, 4.5 * 35.90))]),
        # Show call Wed night: GSHOW is band-agnostic
        call(2, "2026-08-19", "19:00", 4, "Show Call / Spots",
             [paid(2, GSHOW=(4, 4 * 49.44))]),
        # Show call on a Sunday pays GTSPH at the person's level
        call(3, "2026-08-23", "10:00", 4, "Show Call",
             [paid(2, GTSPHA=(4, 4 * 66.14))]),
        # Crew boss night
        call(4, "2026-08-20", "21:00", 4, "Crew Boss",
             [paid(3, GBOSSN=(4, 4 * 59.52))]),
    ]


def test_learn_hours_factor_and_rates():
    m = fc.learn(_training())
    # paid 9+4+4+4 = 21 over booked 10+4+4+4 = 22
    assert m["hours_factor"] == pytest.approx(21 / 22)
    assert m["own"][(0, "std", "day")] == pytest.approx(32.33)
    assert m["own"][(2, "show", "day")] == pytest.approx(49.44)
    assert m["own"][(2, "show", "night")] == pytest.approx(49.44)
    assert (2, "show", "sph") not in m["own"]
    assert m["own"][(2, "std", "sph")] == pytest.approx(66.14)
    assert m["level"][("day", "B")] == pytest.approx(35.90)
    assert m["person_level"][1] == "B"
    assert m["person_level"][2] == "A"
    assert m["role"][("load in", "day")] == pytest.approx((32.33 + 35.90) / 2)
    # GTSPH on a show call is not a vote for "std"
    assert m["family_of"]["show call"] == "show"
    assert m["family_of"]["crew boss"] == "boss"


def test_learn_counts_unknown_codes_but_keeps_their_pay():
    m = fc.learn([call(9, "2026-08-19", "09:00", 4, "Harness",
                       [paid(5, GCUST1=(4, 4 * 177.95))])])
    assert m["unknown_codes"] == {"GCUST1": 4.0}
    assert m["global"][("day",)] == pytest.approx(177.95)


def test_learn_ignores_zero_hours_lines():
    m = fc.learn([call(9, "2026-08-19", "09:00", 4, "Load In",
                       [paid(5, GT1=(0, 0), GT1B=(4, 4 * 35.9))])])
    assert m["person_level"][5] == "B"


# ── seat rates: the fallback chain ──────────────────────────────────────────

def test_seat_rate_chain():
    m = fc.learn(_training())
    assert fc.seat_rate(m, "load in", "std", "day", 0) == (pytest.approx(32.33), "own")
    # person 1 has no night pay, but is level B; no level-B night history -> role
    r, src = fc.seat_rate(m, "load in", "std", "night", 1)
    assert src in ("role", "family", "global")
    # person 2 (level A from GTSPHA) on a day Load In: no own std day -> level A day? none -> role
    assert fc.seat_rate(m, "load in", "std", "day", 2)[1] == "role"
    # show crew on Sunday priced as std sph -> own rate
    assert fc.seat_rate(m, "show call", "show", "sph", 2) == (pytest.approx(66.14), "own")
    # unfilled seat on an unseen role falls to family, then global
    assert fc.seat_rate(m, "rigging", "boss", "night", None) == (pytest.approx(59.52), "family")
    assert fc.seat_rate(m, "rigging", "fork", "day", None)[1] == "global"


def test_seat_rate_level_rule():
    m = fc.learn(_training() + [
        call(5, "2026-08-21", "21:00", 4, "Load Out", [paid(7, GT2B=(4, 4 * 49.44))])])
    # person 1 is level B with no own night pay; level B night now exists
    assert fc.seat_rate(m, "load in", "std", "night", 1) == (pytest.approx(49.44), "level")


def test_seat_rate_nothing_learned():
    m = fc.learn([])
    assert m["hours_factor"] == 1.0
    assert fc.seat_rate(m, "x", "std", "day", None) == (0.0, "none")


# ── pricing calls ───────────────────────────────────────────────────────────

def test_price_call_uses_factor_and_counts_sources():
    m = fc.learn(_training())
    c = call(10, "2026-11-18", "09:00", 5, "Load In", [], half=None)
    res = fc.price_call(m, c, CFG, [0, None])
    f = 21 / 22
    expect = 5 * f * 32.33 + 5 * f * (32.33 + 35.90) / 2
    assert res["cost"] == pytest.approx(expect)
    assert res["hours"] == pytest.approx(10 * f)
    assert res["seats_by_source"] == {"own": 1, "role": 1}


def test_estimate_forward_levels_and_weeks():
    m = fc.learn(_training())
    fwd = [call(10, "2026-11-18", "09:00", 5, "Load In", [0], required=3),
           call(11, "2026-11-25", "09:00", 5, "Load In", [], required=2),
           call(12, "2026-11-25", "09:00", 0, "Load In", [], required=2)]
    out = fc.estimate_forward(m, fwd, CFG)
    w1, w2 = out["weeks"]["2026-11-16"], out["weeks"]["2026-11-23"]
    assert w1["required_cost"] > w1["secured_cost"] > 0
    assert w2["secured_cost"] == 0 and w2["required_cost"] > 0
    assert out["counts"]["zero_length_calls"] == 1
    assert out["counts"]["seats_by_source"]["own"] == 1
    assert out["counts"]["seats_by_source"]["role"] == 4


def test_estimate_forward_confirmed_above_required_is_not_negative():
    m = fc.learn(_training())
    out = fc.estimate_forward(m, [call(10, "2026-11-18", "09:00", 5, "Load In", [0, 1], required=1)], CFG)
    w = out["weeks"]["2026-11-16"]
    assert w["required_cost"] == pytest.approx(w["secured_cost"])


# ── backtest ────────────────────────────────────────────────────────────────

def _steady(half, start_day, weeks=4):
    """Same work every week: perfect history should predict itself."""
    from datetime import date, timedelta
    out = []
    d0 = date.fromisoformat(start_day)
    for w in range(weeks):
        d = (d0 + timedelta(days=7 * w)).isoformat()
        out.append(call(100 + w, d, "09:00", 5, "Load In",
                        [paid(0, GT1=(5, 5 * 32.33)), paid(1, GT1B=(5, 5 * 35.90))], half=half))
    return out


def test_backtest_perfect_history_passes():
    res = fc.backtest(_steady("older", "2026-08-19"), _steady("newer", "2026-09-16"), CFG)
    assert res["people"]["total_pct"] == pytest.approx(0.0)
    assert res["roles"]["total_pct"] == pytest.approx(0.0)
    assert res["passed"]
    assert len(res["people"]["weeks"]) == 4


def test_backtest_detects_a_pay_rise():
    test = _steady("newer", "2026-09-16")
    for c in test:
        for p in c["crew"]:
            for k in p["pay"]:
                p["pay"][k][1] *= 1.10
    res = fc.backtest(_steady("older", "2026-08-19"), test, CFG)
    assert res["people"]["total_pct"] == pytest.approx(-100 / 11, abs=0.01)
    assert not res["passed"]


def test_backtest_skips_unestimable_calls_on_both_sides():
    test = _steady("newer", "2026-09-16")
    test.append(call(999, "2026-09-16", "09:00", 0, "Load In",
                     [paid(0, GT1=(5, 1000.0))], half="newer"))
    res = fc.backtest(_steady("older", "2026-08-19"), test, CFG)
    assert res["skipped"] == {"calls": 1, "pay": 1000.0}
    assert res["people"]["total_pct"] == pytest.approx(0.0)


def test_backtest_band_check():
    res = fc.backtest(_steady("older", "2026-08-19"), _steady("newer", "2026-09-16"), CFG)
    assert res["band_check"] == {"booked_night_share": 0.0, "paid_night_share": 0.0}


def test_split_history():
    older, newer = fc.split_history({"history": {"calls": _steady("older", "2026-08-19")
                                                 + _steady("newer", "2026-09-16", 2)}})
    assert len(older) == 4 and len(newer) == 2


# ── revenue calibration ─────────────────────────────────────────────────────

def _inv(cid, d, invoiced, invoice=1, half="older"):
    return {"id": cid, "date_iso": d, "invoice": invoice, "invoiced": invoiced, "half": half}


def test_revenue_ratio_counts_only_priced_invoiced_calls():
    calls = [_inv(1, "2026-08-19", 90), _inv(2, "2026-08-19", 45),
             _inv(3, "2026-08-19", 50, invoice=0),     # not invoiced yet
             _inv(4, "2026-08-19", 70),                # no rate-card price
             _inv(5, "2026-08-19", 0)]                 # invoiced nothing
    ratio, n, inv, pri = fc.revenue_ratio(calls, {1: 100.0, 2: 50.0, 3: 60.0, 5: 10.0})
    assert (n, inv, pri) == (2, 135.0, 150.0)
    assert ratio == pytest.approx(0.9)


def test_revenue_ratio_with_no_evidence_is_one():
    assert fc.revenue_ratio([], {})[0] == 1.0


def test_revenue_backtest_applies_the_learned_ratio():
    train = [_inv(1, "2026-08-19", 90)]
    test = [_inv(2, "2026-09-16", 180, half="newer"), _inv(3, "2026-09-23", 99, half="newer")]
    r = fc.revenue_backtest(train, test, {1: 100.0, 2: 200.0, 3: 100.0})
    assert r["ratio"] == 0.9 and r["calls"] == 2
    assert r["weeks"][0]["pct"] == 0.0
    assert r["weeks"][1]["pct"] == pytest.approx(-9.09, abs=0.01)
    assert r["passed"]


def test_revenue_backtest_with_nothing_to_compare_fails():
    assert not fc.revenue_backtest([], [], {})["passed"]


def test_combine_forward_scales_revenue_not_cost():
    est = {"weeks": {"2026-W48": {"required_cost": 300.0, "secured_cost": 150.0, "calls": 1}}}
    rows, tot = fc.combine_forward(["2026-W48"], [{"key": "2026-W48", "revenue": 1000.0,
                                                   "secured_revenue": 500.0}], est, revenue_factor=0.9)
    assert rows[0]["rate_card_revenue"] == 1000.0
    assert rows[0]["revenue"] == 900.0 and rows[0]["secured_revenue"] == 450.0
    assert rows[0]["required_cost"] == 300.0
    assert tot["required_margin_pct"] == pytest.approx(100 * 600 / 900, abs=0.01)


def test_iso_and_dense_week_keys():
    assert fc.iso_week_key("2026-11-17") == "2026-W47"
    assert fc.iso_week_key("2027-01-01") == "2026-W53"
    assert fc.dense_week_keys("2026-12-30", "2027-01-15") == ["2026-W53", "2027-W01", "2027-W02"]


# ── the command-line runner ─────────────────────────────────────────────────

def test_cli_runs_on_a_saved_file(capsys):
    import backtest_forward_cost as cli
    import pricing_rules
    saved = {"forward_cost_inputs": {"history": {"calls": _steady("older", "2026-08-19")
                                                 + _steady("newer", "2026-09-16")}}}
    with tempfile.TemporaryDirectory() as d:
        p = os.path.join(d, "saved.json")
        with open(p, "w") as f:
            f.write("junk before " + json.dumps(saved))
        rules_path = os.path.join(d, "rules.json")
        with open(rules_path, "w") as f:
            json.dump({"rules": {"x": 1}}, f)
        orig_validate, orig_cfg = pricing_rules.validate, pricing_rules.cfg_for_set
        pricing_rules.validate = lambda r: []
        pricing_rules.cfg_for_set = lambda r, s: CFG
        try:
            rc = cli.main([p, "--rules", rules_path])
        finally:
            pricing_rules.validate, pricing_rules.cfg_for_set = orig_validate, orig_cfg
    out = capsys.readouterr().out
    assert rc == 0
    assert "OVERALL: PASS" in out
