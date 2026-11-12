"""Estimate import, schema 1.3 — the quoted figure (BRIEF §16).

    venv/bin/python3 -m unittest test_import_schema_13 -v

Every payload here is BUILT INLINE and synthetic. The real 1.3 exports live in
the gitignored test_fixtures/ and neither they nor their figures go into a
tracked file — this repo is public, and those carry real customer money.

These are pure-function tests: validation, the §16.3 mapping and the preview
block. The two things they deliberately do not cover are the ones that need a
booking to exist — a failed quote_import leaving the import intact (§16.6 #6)
and the duplicate refusal across a real import log — and those are checked
end-to-end against the test SmartStaff box instead.
"""

import unittest

import app as A


# ─── payload builders ────────────────────────────────────────────────────────

def labour_line(line_id="ll_001", billable_hours=4, **over):
    line = {
        "line_id": line_id,
        "crew_type": "Standard Crew",
        "call_name": "Load In",
        "quantity": 3,
        "date": "2026-11-15",
        "start_time": "08:00",
        "duration_hours": 4,
        "billable_hours": billable_hours,
    }
    line.update(over)
    return line


def payload(schema="1.3", totals="default", minimums=True, lines=None,
            rate_card="default", **est_over):
    """A valid payload for `schema`, with one knob per test."""
    est = {
        "estimate_id": "aaaaaaaa-0000-4000-8000-000000000001",
        "quote_number": "GP-000123",
        "version": 2,
        "status": "Approved",
    }
    if schema == "1.3":
        est["minimums_applied"] = minimums
        if totals == "default":
            est["totals"] = {
                "labour_ex_gst": 1000.00, "non_labour_ex_gst": 200.00,
                "subtotal_ex_gst": 1200.00, "gst": 120.00,
                "total_inc_gst": 1320.00, "gst_rate": 0.1,
            }
        elif totals is not None:
            est["totals"] = totals
    est.update(est_over)

    p = {
        "schema_version": schema,
        "estimate": est,
        "customer": {"company_name": "Test Customer Pty Ltd"},
        "event": {"event_name": "Test event", "event_date": "2026-11-15"},
        "labour_lines": [labour_line()] if lines is None else lines,
        "non_labour_lines": [],
    }
    if rate_card == "default":
        p["rate_card"] = {"set_code": "standard", "mopt": False,
                          "australian_open": None}
    elif rate_card == "omit":
        pass                      # the 1.0 / 1.1 shape: no key at all
    else:
        p["rate_card"] = rate_card    # present, possibly null
    return p


def errors_for(p):
    return A.validate_payload(p)


# ─── 1. a 1.3 payload validates, and maps as §16.3 says ──────────────────────

class ValidPayload(unittest.TestCase):

    def test_a_13_payload_validates(self):
        self.assertEqual(errors_for(payload()), [])

    def test_the_mapping_is_16_3(self):
        q = A.quote_import_fields(payload(), 10261)
        self.assertEqual(q["total_ex_gst"], 1200.00)      # D-2: the EX GST subtotal
        self.assertEqual(q["labour_ex_gst"], 1000.00)
        self.assertEqual(q["non_labour_ex_gst"], 200.00)
        self.assertEqual(q["estimate_version"], 2)        # from estimate.version
        self.assertEqual(q["quote_number"], "GP-000123")
        self.assertIs(q["minimums_applied"], True)
        self.assertEqual(q["user_id"], 10261)

    def test_gst_fields_are_accepted_but_never_stored(self):
        """D-2: Financials is ex GST throughout. A second total in another
        basis sitting in the same row is a mistake waiting to be read off."""
        q = A.quote_import_fields(payload(), 1)
        for absent in ("gst", "total_inc_gst", "gst_rate", "subtotal_ex_gst"):
            self.assertNotIn(absent, q)

    def test_billable_hours_is_built_from_the_labour_lines(self):
        p = payload(lines=[
            labour_line("ll_001", billable_hours=4, quantity=34),
            labour_line("ll_002", billable_hours=6, quantity=1,
                        crew_type="Crew Boss", call_name="Crew Boss"),
        ])
        q = A.quote_import_fields(p, 1)
        self.assertEqual(q["billable_hours"], [
            {"call_name": "Load In", "crew_type": "Standard Crew",
             "qty": 34, "billable_hours": 4},
            {"call_name": "Crew Boss", "crew_type": "Crew Boss",
             "qty": 1, "billable_hours": 6},
        ])

    def test_the_preview_carries_the_quoted_figure(self):
        q = A.quote_preview(payload())
        self.assertEqual(q["total_ex_gst"], 1200.00)
        self.assertEqual(q["quote_number"], "GP-000123")
        self.assertEqual(q["version"], 2)
        self.assertIs(q["minimums_applied"], True)
        self.assertIsNone(q["warning"])


# ─── 2-4, and §16.6 #5: the 1.3 rules are errors ─────────────────────────────

class ValidationErrors(unittest.TestCase):

    def _only_error_mentions(self, p, needle):
        errs = errors_for(p)
        self.assertTrue(errs, "expected an error")
        self.assertTrue(any(needle in e for e in errs),
                        "no error mentioned %r: %r" % (needle, errs))

    def test_13_without_totals_is_an_error(self):
        self._only_error_mentions(payload(totals=None), "estimate.totals")

    def test_a_missing_money_field_is_an_error(self):
        t = {"labour_ex_gst": 1000.0, "non_labour_ex_gst": 200.0,
             "subtotal_ex_gst": 1200.0, "gst": 120.0}        # no total_inc_gst
        self._only_error_mentions(payload(totals=t), "total_inc_gst")

    def test_a_non_numeric_money_field_is_an_error(self):
        t = {"labour_ex_gst": "1000.00", "non_labour_ex_gst": 200.0,
             "subtotal_ex_gst": 1200.0, "gst": 120.0, "total_inc_gst": 1320.0}
        self._only_error_mentions(payload(totals=t), "labour_ex_gst")

    def test_a_negative_money_field_is_an_error(self):
        t = {"labour_ex_gst": -1.0, "non_labour_ex_gst": 200.0,
             "subtotal_ex_gst": 1200.0, "gst": 120.0, "total_inc_gst": 1320.0}
        self._only_error_mentions(payload(totals=t), "labour_ex_gst")

    def test_a_boolean_is_not_a_money_field(self):
        """bool is an int subclass, so True would otherwise store $1.00."""
        t = {"labour_ex_gst": True, "non_labour_ex_gst": 200.0,
             "subtotal_ex_gst": 1200.0, "gst": 120.0, "total_inc_gst": 1320.0}
        self._only_error_mentions(payload(totals=t), "labour_ex_gst")

    def test_minimums_applied_must_be_a_bool(self):
        self._only_error_mentions(payload(minimums="yes"), "minimums_applied")
        self._only_error_mentions(payload(minimums=None), "minimums_applied")

    def test_a_line_without_numeric_billable_hours_is_an_error(self):
        self._only_error_mentions(
            payload(lines=[labour_line(billable_hours=None)]), "billable_hours")
        self._only_error_mentions(
            payload(lines=[labour_line(billable_hours="4")]), "billable_hours")

    def test_negative_billable_hours_is_an_error(self):
        self._only_error_mentions(
            payload(lines=[labour_line(billable_hours=-1)]), "billable_hours")

    def test_zero_billable_hours_is_allowed(self):
        self.assertEqual(errors_for(payload(lines=[labour_line(billable_hours=0)])), [])

    def test_13_with_a_null_rate_card_is_an_error(self):
        """§16.4: 1.3 requires the block exactly as 1.2 does."""
        self._only_error_mentions(payload(rate_card=None), "rate_card")

    def test_13_with_no_rate_card_key_at_all_is_an_error(self):
        """THE 1.3 CASE. Missing and null are different shapes in the payload
        and both are refused for 1.3 — a 1.3 export always carries the block,
        so either one means the file is wrong rather than old."""
        self._only_error_mentions(payload(rate_card="omit"), "rate_card")


# ─── the sanity check is a WARNING, never an error ───────────────────────────

class SubtotalSanity(unittest.TestCase):

    BAD = {"labour_ex_gst": 1000.0, "non_labour_ex_gst": 200.0,
           "subtotal_ex_gst": 1500.0,          # 300 out
           "gst": 150.0, "total_inc_gst": 1650.0}

    def test_a_wrong_subtotal_does_not_block_the_import(self):
        self.assertEqual(errors_for(payload(totals=self.BAD)), [])

    def test_a_wrong_subtotal_warns(self):
        w = A.quote_payload_warning(payload(totals=self.BAD))
        self.assertIsNotNone(w)
        self.assertIn("subtotal", w.lower())

    def test_the_warning_reaches_the_preview(self):
        self.assertIsNotNone(A.quote_preview(payload(totals=self.BAD))["warning"])

    def test_a_cent_of_rounding_does_not_warn(self):
        """The Estimator rounds each total to cents at export, so a cent of
        drift is normal and must not cry wolf."""
        t = dict(self.BAD, subtotal_ex_gst=1200.01, gst=120.0, total_inc_gst=1320.01)
        self.assertIsNone(A.quote_payload_warning(payload(totals=t)))

    def test_two_cents_does_warn(self):
        t = dict(self.BAD, subtotal_ex_gst=1200.02, gst=120.0, total_inc_gst=1320.02)
        self.assertIsNotNone(A.quote_payload_warning(payload(totals=t)))


# ─── §16.6 #4: 1.0-1.2 are untouched, and create no quote row ────────────────

class OlderSchemasUnchanged(unittest.TestCase):

    def test_10_11_12_still_validate(self):
        for schema in ("1.0", "1.1", "1.2"):
            p = payload(schema=schema)
            self.assertEqual(errors_for(p), [], schema)

    def test_an_unknown_schema_is_still_rejected(self):
        errs = errors_for(payload(schema="1.4"))
        self.assertTrue(any("Unsupported schema version" in e for e in errs))
        self.assertTrue(any("'1.3'" in e for e in errs),
                        "the error text should now offer 1.3")

    def test_a_12_file_sends_no_quote_and_shows_none(self):
        """§16.6 #4: 1.2 imports exactly as before — no booking_quote row."""
        p = payload(schema="1.2")
        self.assertIsNone(A.quote_import_fields(p, 1))
        self.assertIsNone(A.quote_preview(p))

    def test_a_12_file_with_totals_still_sends_no_quote(self):
        """Totals on a 1.2 file are ignored: the schema version is what says
        whether a quote was exported, not the presence of a key."""
        p = payload(schema="1.2")
        p["estimate"]["totals"] = {"subtotal_ex_gst": 999.0}
        self.assertIsNone(A.quote_import_fields(p, 1))

    def test_12_with_a_null_rate_card_is_still_an_error(self):
        """Unchanged from before slice D."""
        errs = errors_for(payload(schema="1.2", rate_card=None))
        self.assertTrue(any("rate_card" in e for e in errs))

    def test_12_with_no_rate_card_key_still_imports_as_standard(self):
        """THE 1.2 CASE. §16.6 #4: a 1.2 file imports exactly as before, and
        before slice D a missing key returned Standard for every version. 1.3
        tightens that for itself alone — 1.2 is a live format and this release
        has no business changing what it accepts."""
        p = payload(schema="1.2", rate_card="omit")
        self.assertEqual(errors_for(p), [])
        self.assertEqual(A.payload_rate_card(p)[1], A.pricing_rules.STANDARD)

    def test_10_and_11_still_import_as_standard_with_no_block(self):
        """The early return that absent-key handling exists for. A 1.0 file
        has no rate_card and must stay importable."""
        for schema in ("1.0", "1.1"):
            p = payload(schema=schema, rate_card="omit")
            self.assertEqual(errors_for(p), [], schema)
            self.assertEqual(A.payload_rate_card(p)[1], A.pricing_rules.STANDARD)


# ─── minimums_applied false ──────────────────────────────────────────────────

class NoMinimums(unittest.TestCase):

    def test_false_survives_the_mapping_and_the_preview(self):
        """§16.6 #3. `is False`, not falsy: None would also be falsy and means
        something different — nobody said."""
        p = payload(minimums=False)
        self.assertIs(A.quote_import_fields(p, 1)["minimums_applied"], False)
        self.assertIs(A.quote_preview(p)["minimums_applied"], False)


# ─── estimate_id: defensive only, per §16.1 ──────────────────────────────────

class EstimateId(unittest.TestCase):

    def test_a_real_uuid_is_passed_through(self):
        q = A.quote_import_fields(payload(), 1)
        self.assertEqual(q["estimate_id"], "aaaaaaaa-0000-4000-8000-000000000001")

    def test_an_unsaved_est_id_is_stored_as_null(self):
        """§16.1 says export now saves first so `est_` can no longer occur.
        Kept because storing it would write a key matching no quote."""
        q = A.quote_import_fields(payload(estimate_id="est_1762900000000"), 1)
        self.assertIsNone(q["estimate_id"])


if __name__ == "__main__":
    unittest.main()


# ─── §16.6 #7: the duplicate refusal is unchanged, and version-blind ─────────

class DuplicateImport(unittest.TestCase):
    """D-4: a revised quote does NOT update THE GOAT in 1.3. Re-importing the
    same estimate_id is refused whatever its version — that is the behaviour
    §16.8 says a later piece of work will revisit, so it must not drift now.
    """

    LOG = [{
        "estimate_id": "aaaaaaaa-0000-4000-8000-000000000001",
        "quote_number": "GP-000123",
        "version": 2,
        "imported_at": "2026-11-12T09:00:00",
        "smartstaff_booking_id": 12345,
    }]

    def setUp(self):
        self._orig = A.load_import_log
        A.load_import_log = lambda: list(self.LOG)

    def tearDown(self):
        A.load_import_log = self._orig

    def test_the_same_estimate_id_is_found(self):
        hit = A.find_import_log_entry("aaaaaaaa-0000-4000-8000-000000000001")
        self.assertIsNotNone(hit)
        self.assertEqual(hit["smartstaff_booking_id"], 12345)

    def test_a_newer_version_of_the_same_estimate_is_still_a_duplicate(self):
        """The log is keyed on estimate_id alone, so v3 of a quote already
        imported as v2 is refused. That is D-4, not an oversight."""
        p = payload(version=3)
        self.assertIsNotNone(A.find_import_log_entry(p["estimate"]["estimate_id"]))

    def test_a_different_estimate_is_not_a_duplicate(self):
        self.assertIsNone(A.find_import_log_entry("bbbbbbbb-0000-4000-8000-000000000002"))


# ─── §16.6 #1: the log records whether the quote was saved ───────────────────

class QuoteSavedFlag(unittest.TestCase):
    """`quote_saved` sits beside `rate_card_saved` in the import-log entry.
    None for 1.0-1.2 — no quote was sent, which is a different thing from one
    that was sent and failed."""

    def test_none_for_a_12_import(self):
        self.assertIsNone(A.quote_import_fields(payload(schema="1.2"), 1))

    def test_a_13_import_has_fields_to_send(self):
        self.assertIsNotNone(A.quote_import_fields(payload(), 1))
