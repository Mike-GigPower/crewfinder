"""Run slice D's backtest on a saved copy of the margin endpoint's JSON.

    python3 backtest_forward_cost.py SAVED.json [--rules pricing_rules_cache.json]

SAVED.json is get-performance-cost.php's response, saved from Safari while
logged in as Mike (or Joe). Only its `forward_cost_inputs` block is read.

--rules is THE GOAT's last good copy of the Estimator's rules. It supplies the
day/night boundaries and the public holidays. Default: pricing_rules_cache.json
beside this script, which exists once THE GOAT has run from this checkout.

Prints the backtest, week by week, then PASS or FAIL against Mike's mark
(within 5% over the 13 weeks, within 15% in every week). Reads files only.
"""

import argparse
import json
import os
import sys

import forward_cost
import pricing_rules


def _load_json(path):
    with open(path, encoding="utf-8") as f:
        text = f.read()
    # Safari's "Page Source" save is the raw body; strip anything before the
    # first brace in case a viewer wrapped it.
    at = text.find("{")
    return json.loads(text[at:] if at > 0 else text)


def _money(n):
    return "${:,.0f}".format(n)


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser()
    ap.add_argument("saved")
    ap.add_argument("--rules", default=os.path.join(here, "pricing_rules_cache.json"))
    args = ap.parse_args(argv)

    data = _load_json(args.saved)
    inputs = data.get("forward_cost_inputs")
    if not inputs:
        print("No forward_cost_inputs in that file. Was it saved while logged in as Mike or Joe,")
        print("from the endpoint with slice D's PHP?")
        return 2

    rules = _load_json(args.rules).get("rules")
    if not rules or pricing_rules.validate(rules):
        print("Could not read valid pricing rules from", args.rules)
        return 2
    cfg = pricing_rules.cfg_for_set(rules, pricing_rules.STANDARD)

    older, newer = forward_cost.split_history(inputs)
    print("History: %d older calls (training), %d newer calls (test)" % (len(older), len(newer)))
    res = forward_cost.backtest(older, newer, cfg)

    print("Hours factor learned (paid / booked): %.4f" % res["model"]["hours_factor"])
    if res["model"]["unknown_codes"]:
        print("Pay categories not in the list (hours):", res["model"]["unknown_codes"])
    print("Calls skipped (no booked length or no crew): %d, %s of pay left out of both sides"
          % (res["skipped"]["calls"], _money(res["skipped"]["pay"])))
    print("Calls with no start time (priced as day / Sun-PH): %d" % res["untimed_calls"])
    bc = res["band_check"]
    print("Night share of day+night hours: booked %s, paid %s"
          % (bc["booked_night_share"], bc["paid_night_share"]))

    for name, label in (("people", "PEOPLE run: paid crew as confirmed (secured level)"),
                        ("roles", "ROLES run: every seat as unfilled (role averages)")):
        r = res[name]
        print()
        print(label)
        print("  %-10s %5s %12s %12s %8s" % ("week", "calls", "estimated", "actual", "diff"))
        for w in r["weeks"]:
            flag = "" if w["pct"] is not None and abs(w["pct"]) <= res["pass_mark"]["week_pct"] else "  <-- outside 15%"
            print("  %-10s %5d %12s %12s %7s%%%s" % (w["week"], w["calls"], _money(w["estimated"]),
                                                    _money(w["actual"]), w["pct"], flag))
        print("  TOTAL      %5s %12s %12s %7s%%   worst week %s%%   %s"
              % ("", _money(r["estimated"]), _money(r["actual"]), r["total_pct"],
                 r["worst_week_pct"], "PASS" if r["passed"] else "FAIL"))

    print()
    print("OVERALL:", "PASS" if res["passed"] else "FAIL")
    return 0 if res["passed"] else 1


if __name__ == "__main__":
    sys.exit(main())
