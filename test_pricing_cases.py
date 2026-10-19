"""
test_pricing_cases.py — the shared pricing cases (D33). The build gate.

    python3 test_pricing_cases.py

Fetches the Estimator's `scope = 'both'` cases through the rate-cards edge
function and prices each one with THE GOAT's engine (estimator_calc.calc_line,
duration mode: the clock calc.ts runs on). Every case carries its own rates,
settings, holidays and windows, so a rate change in the Estimator never breaks
this. Only an engine change can, which is the point.

Exits non-zero on any failure, if the rules can't be fetched FRESH, or if no
case ran. A stale disk copy does not count: a gate that passes on yesterday's
cases isn't a gate.

  RATE_CARDS_SECRET        env, then config.json `rate_cards_secret`, then
                           build_secrets.json `rate_cards_secret`
  PRICING_TESTS_OFFLINE=1  local development only: runs the disk cache and says
                           so loudly. build_dmg.sh never sets it.

The runner itself is pricing_rules.run_shared_cases, which Administration's
"Run shared test cases" button also calls.
"""

import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import pricing_rules   # noqa: E402

OFFLINE_BANNER = "*** OFFLINE — NOT A RELEASE CHECK ***"


def _secret():
    v = os.environ.get("RATE_CARDS_SECRET")
    if v and v.strip():
        return v.strip()
    for name in ("config.json", "build_secrets.json"):
        try:
            with open(os.path.join(HERE, name)) as f:
                v = json.load(f).get("rate_cards_secret")
            if v and str(v).strip():
                return str(v).strip()
        except Exception:
            pass
    return ""


def main():
    offline = os.environ.get("PRICING_TESTS_OFFLINE") == "1"
    cache = os.environ.get("PRICING_RULES_CACHE_FILE") or \
        os.path.join(HERE, "pricing_rules_cache.json")

    if offline:
        print(OFFLINE_BANNER)
        # No secret, so the fetch fails and load_rules falls back to the disk copy.
        pricing_rules.configure("", cache)
    else:
        secret = _secret()
        if not secret:
            print("✗ RATE_CARDS_SECRET isn't set (env, config.json or build_secrets.json). "
                  "The shared cases can't be fetched.")
            return 1
        pricing_rules.configure(secret, cache)

    rules, meta = pricing_rules.load_rules(force=True)
    if rules is None:
        print(f"✗ Pricing rules unavailable: {meta.get('error')}")
        return 1
    if meta.get("stale") and not offline:
        print(f"✗ Couldn't fetch the rules fresh ({meta.get('error')}). "
              "The disk copy doesn't count for this check.")
        return 1

    print(f"Pricing rules: Estimator, rates as of {meta.get('last_changed')}, "
          f"fetched {meta.get('fetched_at')}{' (DISK COPY)' if meta.get('stale') else ''}")

    res = pricing_rules.run_shared_cases(pricing_rules.test_cases(rules))
    money = lambda n: "" if n is None else f"{float(n):.2f}"
    rows = [(r["code"], r["rule"], money(r["expected"]), money(r["got"]),
             {"pass": "PASS", "fail": "FAIL", "skipped": "skip"}[r["status"]])
            for r in res["results"]]
    head = ("Case", "Rule", "Expected", "Got", "Result")
    widths = [max([len(h)] + [len(row[i]) for row in rows]) for i, h in enumerate(head)]

    def fmt(cells):
        return "  ".join(c.rjust(widths[i]) if i in (2, 3) else c.ljust(widths[i])
                         for i, c in enumerate(cells))

    print(fmt(head))
    print("  ".join("-" * w for w in widths))
    for row in rows:
        print(fmt(row))
    for r in res["results"]:
        if r["status"] == "fail":
            print(f"\n{r['code']} {r['title']}")
            for p in r["problems"]:
                print(f"  - {p}")

    print(f"\nShared pricing cases: {res['passed']} of {res['ran']} passed"
          + (f", {res['skipped']} skipped (THE GOAT only)" if res["skipped"] else "") + ".")
    if res["ran"] == 0:
        print("✗ No shared cases ran. Nothing was checked.")
    if offline:
        print(OFFLINE_BANNER)
    return 0 if res["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
