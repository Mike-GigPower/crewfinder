#!/usr/bin/env python3
"""Tests for the induction gate by venue id (DESIGN-induction-gate-by-venue-id-v0_1).

Crew Finder and Ask the GOAT used to decide whether a call is induction-controlled
by matching the venue NAME against INDUCTION_VENUE_MAP. Renaming "Crown Melbourne -
Palms" to "Crown Live" in SmartStaff switched the check off for that venue with no
error (booking 12331, 20 Nov 2026). The gate now resolves name -> venue id (venue
cache) -> catalogue row (venue_ids).

Run (no network, no login):
    python3 test_induction_gate_by_id.py

Venue ids 2, 17, 1131, 142, 64, 52 are the real SmartStaff ids as of 20 Nov 2026.
Melbourne Park ids are the real catalogue set; which arena holds which id is
arbitrary here and doesn't matter to the tests. No crew data is used.
"""
import unittest
from datetime import datetime, timedelta

import app as A


def _venue(vid, name):
    return {"id": vid, "name": name, "postcode": "", "suburb": "", "state": "Vic",
            "has_induction": 1}


VENUES = [
    _venue(2, "Crown Melbourne"), _venue(17, "Crown Live"), _venue(1131, "Crown Palladium"),
    _venue(921, "Crown Metropol"), _venue(790, "Crown Riverwalk"),
    _venue(142, "Arts Centre"), _venue(52, "Hamer Hall"),
    _venue(64, "Royal Exhibition Building"),
    _venue(5, "Rod Laver Arena"), _venue(51, "John Cain Arena"),
    _venue(201, "Margaret Court Arena"), _venue(490, "Centrepiece"), _venue(863, "AAMI Park"),
    _venue(300, "Hanging Rock Reserve"),
    _venue(400, "Frankston Arts Centre"),
]


def _row(code, ids, kws, months=12, published=True, order=0):
    return {"code": code, "validity_months": months, "warn_days": 14,
            "match_keywords": kws, "sort_order": order, "show_in_checker": True,
            "published": published, "venue_ids": A._catalogue_venue_ids(ids)}


def catalogue(crown_kws=("crown melbourne",)):
    return {"warn_days_default": 14, "rows": [
        _row("ACM", [142, 52], ["arts centre", "hamer hall"], order=1),
        _row("MV", [64], ["royal exhibition building"], order=2),
        _row("MELB_PARK", [5, 51, 201, 490, 863],
             ["rod laver arena", "john cain arena", "margaret court arena",
              "centrepiece", "aami park"], order=3),
        _row("Crown", [2, 17, 1131], list(crown_kws), months=24, order=4),
        _row("Hanging Rock", [300], ["hanging rock reserve"], published=False, order=5),
    ]}


def done(months_ago):
    d = datetime.now() - timedelta(days=round(months_ago / 12 * 365))
    return {"status": "Complete", "completed": d.strftime("%d %b %Y")}


class GateById(unittest.TestCase):
    def setUp(self):
        self._saved_cache = A._venue_cache
        self._saved_loader = A.load_induction_catalogue
        A._venue_cache = {A._norm_venue_key(v["name"]): v for v in VENUES}
        self.cat = catalogue()
        A.load_induction_catalogue = lambda *a, **k: self.cat

    def tearDown(self):
        A._venue_cache = self._saved_cache
        A.load_induction_catalogue = self._saved_loader

    def code(self, venue):
        g = A._induction_gate_for_venue_name(venue)
        if isinstance(g, dict):
            return g["row"]["code"]
        return g

    # ── the gate ──────────────────────────────────────────────────────────────
    def test_renamed_crown_venues_are_gated_without_their_names_as_keywords(self):
        # Keywords say only "crown melbourne" — the id route doesn't need more.
        for v in ("Crown Melbourne", "Crown Live", "Crown Palladium"):
            self.assertEqual(self.code(v), "Crown", v)

    def test_crown_venues_outside_the_induction_stay_ungated(self):
        self.assertIsNone(self.code("Crown Metropol"))
        self.assertIsNone(self.code("Crown Riverwalk"))

    def test_venues_the_name_map_never_knew_are_now_gated(self):
        self.assertEqual(self.code("Arts Centre"), "ACM")
        self.assertEqual(self.code("Royal Exhibition Building"), "MV")

    def test_lookalike_name_is_not_gated(self):
        # "arts centre" keyword would have matched this by containment.
        self.assertIsNone(self.code("Frankston Arts Centre"))

    def test_unpublished_induction_is_ungated(self):
        self.assertIsNone(self.code("Hanging Rock Reserve"))

    def test_blank_venue(self):
        self.assertIsNone(A._induction_gate_for_venue_name(""))
        self.assertIsNone(A._induction_gate_for_venue_name(None))

    def test_venue_not_in_cache_falls_back_to_name_map(self):
        # Renamed since the last crew-cache rebuild: id route has no answer.
        A._venue_cache.pop(A._norm_venue_key("Crown Live"))
        self.assertEqual(A._induction_gate_for_venue_name("Crown Live"), "Crown")
        self.assertEqual(A._induction_gate_for_venue_name("Marvel Stadium"), "Marvel")

    def test_catalogue_unreachable_falls_back_to_name_map(self):
        self.cat = None
        self.assertEqual(A._induction_gate_for_venue_name("Crown Melbourne"), "Crown")
        self.assertEqual(A._induction_gate_for_venue_name("Rod Laver Arena"), "RLA")

    # ── which records count, and their validity ──────────────────────────────
    def status(self, venue, inductions):
        return A.induction_status_for_venue(
            inductions, A._induction_gate_for_venue_name(venue))[0]

    def test_crown_live_record_gets_crown_24_month_validity_by_id(self):
        crew = {"Crown Live": done(18)}
        self.assertEqual(self.status("Crown Live", crew), "Complete")
        self.assertEqual(self.status("Crown Melbourne", crew), "Complete")
        self.assertEqual(A._induction_validity_for_label("Crown Live"), (24, 14))

    def test_crown_record_past_24_months_is_expired(self):
        self.assertEqual(self.status("Crown Live", {"Crown Melbourne": done(25)}), "Expired")

    def test_best_status_wins_across_covered_venues(self):
        crew = {"Crown Melbourne": done(30), "Crown Live": done(3)}
        self.assertEqual(self.status("Crown Palladium", crew), "Complete")

    def test_melbourne_park_rollup(self):
        # D2: one Melbourne Park induction covers all five arenas.
        self.assertEqual(self.status("Rod Laver Arena", {"John Cain Arena": done(3)}), "Complete")

    def test_arts_centre_and_hamer_hall_count_for_each_other(self):
        self.assertEqual(self.status("Hamer Hall", {"Arts Centre": done(3)}), "Complete")

    def test_record_at_uncovered_venue_does_not_count(self):
        self.assertIsNone(self.status("Crown Live", {"Crown Metropol": done(1)}))
        self.assertIsNone(self.status("Arts Centre", {"Frankston Arts Centre": done(1)}))

    def test_record_venue_missing_from_cache_falls_back_to_row_keywords(self):
        crew = {"Crown Melbourne (old site)": done(3)}
        self.assertEqual(self.status("Crown Live", crew), "Complete")

    def test_no_record_and_incomplete(self):
        self.assertIsNone(self.status("Crown Live", {}))
        self.assertEqual(self.status("Crown Live", {"Crown Live": {"status": "Incomplete",
                                                                   "completed": ""}}),
                         "Incomplete")

    def test_legacy_code_still_works(self):
        self.assertEqual(A.induction_status_for_venue({"Crown Melbourne": done(3)}, "Crown")[0],
                         "Complete")
        self.assertEqual(A.induction_status_for_venue({}, "Nope"), (None, ""))

    def test_list_shaped_inductions_guard_kept(self):
        g = A._induction_gate_for_venue_name("Crown Live")
        self.assertEqual(A.induction_status_for_venue([], g), (None, ""))


class IntIds(unittest.TestCase):
    def test_shapes(self):
        self.assertEqual(A._catalogue_venue_ids([2, "17", " 1131 "]), frozenset({2, 17, 1131}))
        self.assertEqual(A._catalogue_venue_ids("2,17"), frozenset({2, 17}))
        self.assertEqual(A._catalogue_venue_ids(None), frozenset())
        self.assertEqual(A._catalogue_venue_ids(["x", None, 5]), frozenset({5}))


if __name__ == "__main__":
    unittest.main(verbosity=2)
