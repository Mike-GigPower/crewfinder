"""
test_booking_rate_card.py — the 1.2 rate_card block, the card tripwire, and
pricing an import on its stored card (BRIEF Stage 1 §7.3, §9 tests 13–15, 20).

    python3 test_booking_rate_card.py

Pricing reads the live rules the same way test_pricing_cases.py does
(RATE_CARDS_SECRET from env, config.json or build_secrets.json), and
PRICING_TESTS_OFFLINE=1 runs on the disk copy for local development. The
validation tests need no rules at all.

The two export fixtures live in fixtures/ (the SCRUBBED copies; the originals
carry a real contact's details and this repo is public). A missing fixture is
reported and skipped, never passed.
"""

import copy
import json
import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import app                      # noqa: E402
import call_types               # noqa: E402
import estimator_calc as ec     # noqa: E402
import pricing_rules            # noqa: E402
import test_pricing_cases       # noqa: E402

FIXTURES = os.environ.get("ESTIMATE_FIXTURES_DIR") or os.path.join(HERE, "fixtures")
STANDARD_FIXTURE = os.path.join(FIXTURES, "estimate-1.2-standard.json")
MOPT_AO_FIXTURE = os.path.join(FIXTURES, "estimate-1.2-mopt-ao.json")


def _payload(rate_card, call_names=("Australian Open Event Operations",)):
    """A minimal valid 1.2 payload."""
    return {
        "schema_version": "1.2",
        "rate_card": rate_card,
        "estimate": {"estimate_id": "e1", "quote_number": "GP-TEST", "status": "Approved"},
        "customer": {"company_name": "Test Co"},
        "event": {"event_name": "Test"},
        "labour_lines": [
            {"line_id": "ll_%03d" % i, "call_name": n, "crew_type": n, "quantity": 1,
             "date": "2026-10-19", "start_time": "08:00", "duration_hours": 4}
            for i, n in enumerate(call_names, 1)],
    }


AO = {"set_code": "mopt-ao", "mopt": True, "australian_open": True}
STD = {"set_code": "standard", "mopt": False, "australian_open": None}

_RULES = {}


def rules():
    """The live rules (or the disk copy offline), loaded once."""
    if "r" not in _RULES:
        offline = os.environ.get("PRICING_TESTS_OFFLINE") == "1"
        cache = os.environ.get("PRICING_RULES_CACHE_FILE") or \
            os.path.join(HERE, "pricing_rules_cache.json")
        pricing_rules.configure("" if offline else test_pricing_cases._secret(), cache)
        _RULES["r"], _RULES["meta"] = pricing_rules.load_rules(force=True)
    return _RULES["r"]


def price_on_stored_card(payload):
    """What an import then the forward book do: the card from the file, each
    line's role resolved on THAT card, priced in duration mode. (total, unpriced)."""
    _, set_code, errs = app.payload_rate_card(payload)
    assert not errs, errs
    cfg = pricing_rules.cfg_for_set(rules(), set_code)
    total, unpriced = 0.0, []
    for ll in payload["labour_lines"]:
        role = ec.resolve_role(ll["call_name"], cfg)["role"]
        if role is None:
            unpriced.append(ll["call_name"])
            continue
        r = ec.calc_line({"id": ll["line_id"], "role": role, "qty": ll["quantity"],
                          "shiftDate": ll["date"], "startTime": ll["start_time"],
                          "durationHours": ll["duration_hours"]}, cfg, ec.MODE_DURATION)
        assert r["ok"], r["errors"]
        total += r["costExGst"]
    return ec.round2(total), unpriced


class RateCardBlockTests(unittest.TestCase):

    def test_the_three_good_blocks(self):
        for rc, code in ((STD, "standard"), (AO, "mopt-ao"),
                         ({"set_code": "mopt", "mopt": True, "australian_open": False}, "mopt")):
            answers, got, errs = app.payload_rate_card(_payload(rc))
            self.assertEqual(errs, [], rc)
            self.assertEqual(got, code)

    def test_a_1_1_payload_with_no_block_is_standard(self):
        p = _payload(None)
        del p["rate_card"]
        p["schema_version"] = "1.1"
        self.assertEqual(app.payload_rate_card(p)[1:], ("standard", []))

    def test_15_mopt_with_australian_open_null_is_an_error(self):
        p = _payload({"set_code": "mopt-ao", "mopt": True, "australian_open": None})
        self.assertTrue(app.payload_rate_card(p)[2])
        self.assertTrue(any("australian_open" in e for e in app.validate_payload(p)))

    def test_ao_answered_without_mopt_is_an_error(self):
        p = _payload({"set_code": "standard", "mopt": False, "australian_open": True})
        self.assertTrue(app.payload_rate_card(p)[2])

    def test_a_set_code_that_disagrees_with_its_answers_is_an_error(self):
        p = _payload({"set_code": "mopt", "mopt": True, "australian_open": True})
        errs = app.payload_rate_card(p)[2]
        self.assertTrue(errs and "disagrees" in errs[0], errs)

    def test_an_unknown_set_code_is_an_error(self):
        p = _payload({"set_code": "mopt-xx", "mopt": True, "australian_open": True})
        self.assertTrue(app.payload_rate_card(p)[2])

    def test_answers_error(self):
        self.assertIsNone(app.rate_card_answers_error(False, None))
        self.assertIsNone(app.rate_card_answers_error(True, False))
        self.assertTrue(app.rate_card_answers_error(True, None))       # test 16
        self.assertTrue(app.rate_card_answers_error(None, None))


class CardCrewTypeTests(unittest.TestCase):
    """The 5.76 call-name tripwire is gone (Stage 2): a call may be named
    anything, and its crew_type is what has to be on the card."""

    def setUp(self):
        if rules() is None:
            self.skipTest("pricing rules unavailable: %s" % _RULES["meta"].get("error"))

    def test_a_renamed_mopt_call_passes_when_its_type_is_on_the_card(self):
        p = _payload(AO)
        p["labour_lines"][0]["call_name"] = "Load In"   # any name
        self.assertEqual(call_types.crew_type_errors(p["labour_lines"], rules(), "mopt-ao", "AO"), ([], None))

    def test_a_standard_type_on_a_mopt_import_names_the_line(self):
        p = _payload(AO, ("Australian Open Event Operations", "Load In"))
        p["labour_lines"][1]["crew_type"] = "Standard Crew"
        errs, _ = call_types.crew_type_errors(p["labour_lines"], rules(), "mopt-ao", "AO")
        self.assertEqual(len(errs), 1)
        self.assertIn("ll_002", errs[0])
        self.assertIn("Standard Crew", errs[0])


class PriceOnStoredCardTests(unittest.TestCase):
    """§9 test 20."""

    def setUp(self):
        if rules() is None:
            self.skipTest("pricing rules unavailable: %s" % _RULES["meta"].get("error"))

    def test_the_ao_line_288_56_and_unpriced_on_standard(self):
        """GP-000249 v1's one line, built here so it runs without the file."""
        self.assertEqual(price_on_stored_card(_payload(AO)), (288.56, []))
        on_std = copy.deepcopy(_payload(AO))
        on_std["rate_card"] = STD
        self.assertEqual(price_on_stored_card(on_std),
                         (0.0, ["Australian Open Event Operations"]))

    def test_fixture_standard_23449_45(self):
        if not os.path.exists(STANDARD_FIXTURE):
            self.skipTest("fixture missing: " + STANDARD_FIXTURE)
        with open(STANDARD_FIXTURE) as f:
            p = json.load(f)
        self.assertEqual(app.validate_payload(p), [])
        self.assertEqual(price_on_stored_card(p), (23449.45, []))

    def test_fixture_mopt_ao_288_56(self):
        if not os.path.exists(MOPT_AO_FIXTURE):
            self.skipTest("fixture missing: " + MOPT_AO_FIXTURE)
        with open(MOPT_AO_FIXTURE) as f:
            p = json.load(f)
        self.assertEqual(app.validate_payload(p), [])
        self.assertEqual(price_on_stored_card(p), (288.56, []))


if __name__ == "__main__":
    unittest.main(verbosity=2)
