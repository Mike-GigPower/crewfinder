"""Stage 2 Call Types (BRIEF-goat-call-types-stage2 §4–§6). Pure logic only.

Runs against a small hand-built rules object shaped like the live Estimator
export, so it needs no network and no cache file."""

import unittest

import call_types as ct


def _rate(role, order, names, active=None, eff="2000-01-01"):
    return {"role_name": role, "sort_order": order, "call_names": names, "is_active": active,
            "effective_from": eff, "day_rate": 50, "night_rate": 60, "sunday_rate": 70,
            "public_holiday_rate": 80, "ot_8_day_rate": None, "ot_10_day_rate": None,
            "min_billable_hours": 4}


RULES = {
    "settings": {"dayStart": "06:00", "nightStart": "18:00", "minBillableHours": 4, "gstRate": 0.1},
    "public_holidays": [], "double_penalty_windows": [], "test_cases": [],
    "sets": [
        {"code": "standard", "rates": [
            _rate("Show Crew", 20, ["Show Call", "FOH Spot", "Robo Spot"]),
            _rate("Standard Crew", 10, ["Load In", "Load Out", "Other"]),
            _rate("Standard Crew", 10, ["Load In", "Load Out", "Other"], eff="2026-07-01"),
            _rate("Crew Boss", 30, ["Crew Boss"]),
            _rate("Broadcast Rate", 90, ["Broadcast"], active=False),
        ]},
        {"code": "mopt", "rates": [
            _rate("Event Operations", 10, ["Event Operations"]),
            _rate("Forklift Driver", 20, ["Forklift Driver"]),
        ]},
        {"code": "mopt-ao", "rates": [
            _rate("Australian Open Event Operations", 10, ["Australian Open Event Operations"]),
        ]},
    ],
}


class Roles(unittest.TestCase):
    def test_card_roles_in_sort_order_once_active_only(self):
        self.assertEqual(ct.roles_for_set(RULES, "standard"),
                         ["Standard Crew", "Show Crew", "Crew Boss"])

    def test_mopt_card_has_only_its_roles(self):
        self.assertEqual(ct.roles_for_set(RULES, "mopt"), ["Event Operations", "Forklift Driver"])

    def test_unknown_card_is_empty(self):
        self.assertEqual(ct.roles_for_set(RULES, "nope"), [])

    def test_override_roles_add_standard_on_a_customer_card(self):
        self.assertEqual(ct.override_roles(RULES, "mopt"),
                         ["Event Operations", "Forklift Driver", "Standard Crew", "Show Crew", "Crew Boss"])
        self.assertEqual(ct.override_roles(RULES, "standard"), ct.roles_for_set(RULES, "standard"))

    def test_call_names_follow_the_card(self):
        # Suggestions only; "Other" appears where the card lists it, once.
        self.assertEqual(ct.call_names_for_set(RULES, "standard"),
                         ["Load In", "Load Out", "Other", "Show Call", "FOH Spot", "Robo Spot", "Crew Boss"])
        self.assertEqual(ct.call_names_for_set(RULES, "mopt"),
                         ["Event Operations", "Forklift Driver"])

    def test_name_roles_preselect(self):
        nr = ct.call_name_roles(RULES, "standard")
        self.assertEqual(nr["FOH Spot"], "Show Crew")
        self.assertNotIn("Broadcast", nr)


class Effective(unittest.TestCase):
    def stored(self, role, source="add_call"):
        return {"role_name": role, "source": source, "set_by_user_id": 1, "set_at": "2026-10-21T00:00:00Z"}

    def test_stored_on_card_is_ok(self):
        e = ct.effective_call_type("Load In", "standard", RULES, self.stored("Show Crew"))
        self.assertEqual((e["role"], e["source"], e["status"]), ("Show Crew", "add_call", "ok"))

    def test_stored_off_card_after_the_card_changed(self):
        e = ct.effective_call_type("Load In", "mopt", RULES, self.stored("Standard Crew"))
        self.assertEqual((e["role"], e["status"]), ("Standard Crew", "off_card"))

    def test_derived_exact_and_prefix(self):
        e = ct.effective_call_type("Load In", "standard", RULES, None)
        self.assertEqual((e["role"], e["source"], e["status"]), ("Standard Crew", "derived", "ok"))
        e = ct.effective_call_type("Show Call - Matinee", "standard", RULES, None)
        self.assertEqual(e["role"], "Show Crew")

    def test_unmatched_is_not_set_never_standard(self):
        e = ct.effective_call_type("Robo Spot Op", "standard", RULES, None)
        self.assertEqual((e["role"], e["status"]), (None, "not_set"))
        e = ct.effective_call_type("Load In", "mopt", RULES, None)
        self.assertEqual((e["role"], e["status"]), (None, "not_set"))

    def test_rules_unavailable(self):
        e = ct.effective_call_type("Load In", "standard", None, self.stored("Show Crew"))
        self.assertEqual(e["status"], "ok")
        e = ct.effective_call_type("Load In", "standard", None, None)
        self.assertEqual(e["status"], "not_set")

    def test_incomplete_card(self):
        e = ct.effective_call_type("Event Operations", None, RULES, None)
        self.assertEqual(e["status"], "not_set")


class Import(unittest.TestCase):
    def test_crew_types_on_card_pass(self):
        lines = [{"line_id": "ll_001", "crew_type": "Crew Boss"}, {"line_id": "ll_002", "crew_type": "Show Crew"}]
        self.assertEqual(ct.crew_type_errors(lines, RULES, "standard", "Standard"), ([], None))

    def test_off_card_crew_type_names_the_line(self):
        lines = [{"line_id": "ll_001", "crew_type": "Show Crew"}]
        errs, _ = ct.crew_type_errors(lines, RULES, "mopt-ao", "MOPT — Australian Open")
        self.assertEqual(len(errs), 1)
        self.assertIn("ll_001", errs[0])
        self.assertIn("Show Crew", errs[0])

    def test_blank_crew_type_is_an_error_on_file_and_added_lines(self):
        lines = [{"line_id": "ll_001", "crew_type": ""},
                 {"line_id": "new-1", "crew_type": "", "call_name": "Spots"}]
        errs, _ = ct.crew_type_errors(lines, RULES, "standard", "Standard")
        self.assertEqual(len(errs), 2)
        self.assertIn("ll_001", errs[0])
        self.assertIn("Spots", errs[1])

    def test_added_line_type_must_be_on_the_card(self):
        ok = [{"line_id": "new-1", "crew_type": "Show Crew", "call_name": "Spots"}]
        self.assertEqual(ct.crew_type_errors(ok, RULES, "standard", "Standard"), ([], None))
        bad = [{"line_id": "new-1", "crew_type": "Show Crew", "call_name": "Spots"}]
        self.assertEqual(len(ct.crew_type_errors(bad, RULES, "mopt", "MOPT")[0]), 1)

    def test_rules_unavailable_warns(self):
        errs, warn = ct.crew_type_errors([{"line_id": "ll_001", "crew_type": "X"}], None, "standard", "Standard")
        self.assertEqual(errs, [])
        self.assertTrue(warn)


class CrewBoss(unittest.TestCase):
    def test_grade_found_by_name(self):
        g, err = ct.find_crew_boss_grade([{"id": 7, "day_desc": "Standard Crew", "rate": "40"},
                                          {"id": 20, "day_desc": " crew boss ", "rate": "42"}])
        self.assertIsNone(err)
        self.assertEqual(g["id"], 20)

    def test_grade_missing_or_duplicated(self):
        self.assertEqual(ct.find_crew_boss_grade([{"id": 7, "day_desc": "Crew Boss OT"}])[1],
                         "No Crew Boss pay grade in SmartStaff")
        self.assertIsNotNone(ct.find_crew_boss_grade([{"id": 1, "day_desc": "Crew Boss"},
                                                      {"id": 2, "day_desc": "Crew Boss"}])[1])

    def test_not_on_mopt(self):
        self.assertTrue(ct.crew_boss_allowed("standard"))
        self.assertFalse(ct.crew_boss_allowed("mopt"))
        self.assertFalse(ct.crew_boss_allowed("mopt-ao"))
        self.assertFalse(ct.crew_boss_allowed(None))


class Helpers(unittest.TestCase):
    def test_created_call_ids_skip_failures(self):
        res = {"call_ids": [101, 103], "call_errors": [{"index": 1, "detail": "x"}]}
        self.assertEqual(ct.created_call_ids(3, res), [101, None, 103])

    def test_as_call_id(self):
        self.assertEqual(ct.as_call_id("123"), 123)
        self.assertIsNone(ct.as_call_id("created"))
        self.assertIsNone(ct.as_call_id(None))
        self.assertIsNone(ct.as_call_id(0))


if __name__ == "__main__":
    unittest.main()
