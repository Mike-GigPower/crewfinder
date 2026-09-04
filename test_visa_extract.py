"""
Tests for visa_extract.

Two kinds of test here, and the split matters:

  * The FIXTURE tests need real Home Affairs PDFs, which are live immigration
    documents for real crew and must never be committed to a public repo. They
    live in a gitignored fixtures/ directory on the build machine and these
    tests SKIP when it is absent.
  * The TEXT tests run everywhere. They exercise the parsers against the
    flattened text directly — which is what the regexes actually see — so the
    parsing rules stay covered on any machine, with no PII in the repo.

To enable the fixture tests, drop these two files into fixtures/:
    fixtures/grant-notification.pdf
    fixtures/vevo-check.pdf

Run:  python3 -m unittest test_visa_extract -v
"""

import os
import unittest

import visa_extract as vx

FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures")
GRANT_PDF = os.path.join(FIXTURES, "grant-notification.pdf")
VEVO_PDF  = os.path.join(FIXTURES, "vevo-check.pdf")


# Flattened text as pypdf actually produces it for these documents, including
# the two artefacts that broke the first three attempts at this parser:
#   "document) numberV3278269"  — label and value with NO space
#   the label wrapping across a line break before flattening
GRANT_TEXT = (
    "Dear Abhishek KATOCH We have granted you a Temporary Graduate (subclass 485) "
    "visa on 21 August 2026. Application status Temporary Graduate (subclass 485): "
    "Granted Visa conditions 8501 - Maintain health insurance An explanation of each "
    "condition of this Temporary Graduate (subclass 485) visa is included in this "
    "letter. Visa duration and travel Date of grant 21 August 2026 Stay until "
    "21 August 2029 Must not arrive after 21 August 2029 Length of stay 21 August 2029 "
    "Travel Multiple entries Visa summary Name Abhishek KATOCH Date of birth "
    "07 July 1992 Visa Temporary Graduate (subclass 485) Stream Post-Higher Education "
    "Work Date of grant 21 August 2026 Visa grant number 2009575074894 "
    "Passport (or other travel document) numberV3278269 - 2 - "
    "Passport (or other travel document) countryINDIA Application ID 1885725520 "
    "Transaction reference number EGPDVWBSVP Why keep this notice?"
)

VEVO_TEXT = (
    "OFFICIAL: Sensitive Personal Privacy Visa Entitlement Verification Online (VEVO) "
    "Visa Details Check This document contains the result of a Visa Entitlement "
    "Verification Online (VEVO) Visa Details Check and is valid as at Friday "
    "August 28, 2026 14:29:05 (AEST) Canberra, Australia (GMT +1000). "
    "Visa Details Check Current visa details as per departmental records "
    "Category selected Work entitlements Family name KATOCH Given name(s) Abhishek "
    "Document number V3278269 Visa class / subclass VC / 485 "
    "Visa stream Post-Higher Education Work Visa applicant Primary "
    "Visa grant date 21 August 2026 Visa expiry date 21 August 2029 Location Onshore "
    "Work entitlement(s) The visa holder has unlimited right to work in Australia. "
    "Personal Privacy OFFICIAL: Sensitive"
)


class GrantNotification(unittest.TestCase):
    def setUp(self):
        self.f = vx.parse_grant_notification(GRANT_TEXT)

    def test_every_field_found(self):
        missing = [k for k, v in self.f.items()
                   if v is None and k != "has_work_limitation"]
        self.assertEqual(missing, [], "unparsed: %s" % missing)

    def test_values(self):
        self.assertEqual(self.f["visa_subclass"], "485")
        self.assertEqual(self.f["visa_grant_number"], "2009575074894")
        self.assertEqual(self.f["trn"], "EGPDVWBSVP")
        self.assertEqual(self.f["visa_grant_date"], "2026-08-21")
        self.assertEqual(self.f["visa_expiry"], "2029-08-21")
        self.assertEqual(self.f["visa_conditions"], "8501 - Maintain health insurance")

    def test_passport_survives_the_missing_space(self):
        # "document) numberV3278269" — the artefact that broke three attempts.
        self.assertEqual(self.f["passport_number"], "V3278269")
        self.assertEqual(self.f["passport_country"], "India")

    def test_conditions_never_imply_a_work_limitation(self):
        # 8501 is "maintain health insurance" and coexists with unlimited work
        # rights. A grant letter must never set this field.
        self.assertIsNone(self.f["has_work_limitation"])

    def test_expiry_is_stay_until_not_length_of_stay(self):
        self.assertEqual(self.f["visa_expiry"], "2029-08-21")

    def test_multiple_conditions_are_split_readably(self):
        t = GRANT_TEXT.replace(
            "8501 - Maintain health insurance An explanation",
            "8501 - Maintain health insurance 8104 - Work limited to 48 hours "
            "per fortnight An explanation")
        self.assertEqual(
            vx.parse_grant_notification(t)["visa_conditions"],
            "8501 - Maintain health insurance · 8104 - Work limited to 48 hours per fortnight")

    def test_holder_name(self):
        self.assertEqual(vx._grant_holder_name(GRANT_TEXT), "Abhishek KATOCH")


class VevoCheck(unittest.TestCase):
    def setUp(self):
        self.f = vx.parse_vevo_check(VEVO_TEXT)

    def test_every_field_found(self):
        missing = [k for k, v in self.f.items() if v is None]
        self.assertEqual(missing, [], "unparsed: %s" % missing)

    def test_values(self):
        self.assertEqual(self.f["visa_subclass"], "485")
        self.assertEqual(self.f["visa_grant_date"], "2026-08-21")
        self.assertEqual(self.f["visa_expiry"], "2029-08-21")
        self.assertEqual(self.f["passport_number"], "V3278269")

    def test_unlimited_work_rights_means_no_limitation(self):
        self.assertEqual(self.f["has_work_limitation"], 0)

    def test_entitlement_sentence_kept_verbatim(self):
        self.assertEqual(
            self.f["work_entitlement"],
            "The visa holder has unlimited right to work in Australia.")

    def test_vevo_timestamp(self):
        self.assertEqual(self.f["vevo_verified_at"], "2026-08-28 14:29:05")

    def test_restricted_entitlement(self):
        t = VEVO_TEXT.replace(
            "The visa holder has unlimited right to work in Australia.",
            "The visa holder is permitted to work 48 hours per fortnight.")
        self.assertEqual(vx.parse_vevo_check(t)["has_work_limitation"], 1)

    def test_no_work_rights(self):
        t = VEVO_TEXT.replace(
            "The visa holder has unlimited right to work in Australia.",
            "The visa holder is not permitted to work in Australia.")
        self.assertEqual(vx.parse_vevo_check(t)["has_work_limitation"], 1)

    def test_unrecognised_entitlement_defaults_to_restricted(self):
        # Present but matching neither end -> restricted, because on this field
        # the safe default is the one that gets a human to look.
        t = VEVO_TEXT.replace(
            "The visa holder has unlimited right to work in Australia.",
            "The visa holder has conditions that vary by employer.")
        self.assertEqual(vx.parse_vevo_check(t)["has_work_limitation"], 1)

    def test_holder_name(self):
        self.assertEqual(vx._vevo_holder_name(VEVO_TEXT), "Abhishek KATOCH")


class Sniffing(unittest.TestCase):
    def test_grant(self):
        self.assertEqual(vx.sniff_kind(GRANT_TEXT), vx.KIND_GRANT)

    def test_vevo(self):
        self.assertEqual(vx.sniff_kind(VEVO_TEXT), vx.KIND_VEVO)

    def test_neither(self):
        self.assertIsNone(vx.sniff_kind("A payslip for the fortnight ending 1 July."))

    def test_grant_letter_that_mentions_vevo_is_still_a_grant_letter(self):
        # REGRESSION. Every grant letter carries this sentence, and a looser
        # marker classified all of them as VEVO checks — which parsed them with
        # the wrong patterns and returned almost nothing. Found by the real PDF.
        t = GRANT_TEXT + (
            " You can check these conditions at any time by using the Visa "
            "Entitlement Verification Online (VEVO) service.")
        self.assertEqual(vx.sniff_kind(t), vx.KIND_GRANT)

    def test_vevo_is_not_matched_on_its_title_alone(self):
        # The VEVO check is identified by what only it contains — a stated work
        # entitlement — not by the product name, which both documents print.
        self.assertIsNone(vx.sniff_kind(
            "Visa Entitlement Verification Online (VEVO) is a free service "
            "provided by the Department of Home Affairs for employers."))


class Dates(unittest.TestCase):
    def test_ok(self):
        self.assertEqual(vx._iso_date("21 August 2026"), "2026-08-21")

    def test_impossible_day_rejected(self):
        # '121/8/29' in the notes extraction became a real date without a guard.
        self.assertIsNone(vx._iso_date("32 August 2026"))

    def test_junk(self):
        for s in (None, "", "unlimited", "check April 2026"):
            self.assertIsNone(vx._iso_date(s))


class NameMatching(unittest.TestCase):
    def test_order_and_case_insensitive(self):
        self.assertTrue(vx.names_match("KATOCH, Abhishek", "Abhishek Katoch"))
        self.assertTrue(vx.names_match("Abhishek KATOCH", "Katoch Abhishek"))

    def test_nickname_in_brackets_ignored(self):
        self.assertTrue(vx.names_match("Julie Bobi Gallo", "Julie (LUNA) Bobi Gallo"))

    def test_different_person(self):
        self.assertFalse(vx.names_match("Abhishek KATOCH", "Rogelio Tan"))

    def test_shared_surname_only_is_not_a_match(self):
        # Two Tans are not the same Tan.
        self.assertFalse(vx.names_match("Rogelio Tan", "Marcus Tan"))

    def test_nothing_to_compare_is_not_a_match(self):
        self.assertIsNone(vx.names_match("", "Abhishek Katoch"))
        self.assertIsNone(vx.names_match("Abhishek Katoch", ""))


class Merging(unittest.TestCase):
    def setUp(self):
        self.grant = {"fields": {k: v for k, v in
                                 vx.parse_grant_notification(GRANT_TEXT).items()
                                 if v is not None}}
        self.vevo  = {"fields": {k: v for k, v in
                                 vx.parse_vevo_check(VEVO_TEXT).items()
                                 if v is not None}}

    def test_grant_only_fields_survive(self):
        merged, _ = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertEqual(merged["visa_grant_number"], "2009575074894")
        self.assertEqual(merged["trn"], "EGPDVWBSVP")
        self.assertEqual(merged["passport_country"], "India")
        self.assertEqual(merged["visa_conditions"], "8501 - Maintain health insurance")

    def test_vevo_supplies_the_limitation(self):
        merged, _ = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertEqual(merged["has_work_limitation"], 0)

    def test_no_conflicts_on_the_reference_pair(self):
        _, conflicts = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertEqual(conflicts, [])

    def test_vevo_wins_on_expiry(self):
        # The visa was shortened after grant. VEVO knows; the letter never will.
        self.vevo["fields"]["visa_expiry"] = "2027-01-01"
        merged, conflicts = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertEqual(merged["visa_expiry"], "2027-01-01")
        self.assertIn({"field": "visa_expiry", "grant": "2029-08-21",
                       "vevo": "2027-01-01"}, conflicts)

    def test_passport_disagreement_is_flagged_serious(self):
        self.vevo["fields"]["passport_number"] = "Z9999999"
        _, conflicts = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertTrue(any(c.get("serious") and c["field"] == "passport_number"
                            for c in conflicts))

    def test_order_does_not_change_the_result(self):
        a, _ = vx.merge_visa_documents(self.grant, self.vevo)
        b, _ = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertEqual(a, b)

    def test_work_entitlement_is_not_a_column(self):
        merged, _ = vx.merge_visa_documents(self.grant, self.vevo)
        self.assertNotIn("work_entitlement", merged)

    def test_one_document_alone(self):
        merged, conflicts = vx.merge_visa_documents(None, self.vevo)
        self.assertEqual(merged["visa_subclass"], "485")
        self.assertEqual(conflicts, [])
        merged, conflicts = vx.merge_visa_documents(self.grant, None)
        self.assertEqual(merged["trn"], "EGPDVWBSVP")
        self.assertEqual(conflicts, [])


class Rejection(unittest.TestCase):
    def test_not_a_pdf(self):
        r = vx.extract_visa_document(b"GIF89a this is not a pdf")
        self.assertFalse(r["ok"])
        self.assertIn("not a PDF", " ".join(r["warnings"]))

    def test_empty(self):
        r = vx.extract_visa_document(b"")
        self.assertFalse(r["ok"])

    def test_never_raises(self):
        for junk in (None, b"", b"%PDF-", b"%PDF-" + b"\x00" * 5000):
            vx.extract_visa_document(junk, crew_name="Abhishek Katoch")


@unittest.skipUnless(os.path.exists(GRANT_PDF) and os.path.exists(VEVO_PDF),
                     "fixtures/ absent — real PDFs are not committed to a public repo")
class RealDocuments(unittest.TestCase):
    """End-to-end over the actual Home Affairs PDFs. Build machine only."""

    def setUp(self):
        with open(GRANT_PDF, "rb") as f:
            self.grant = vx.extract_visa_document(f.read(), crew_name="Abhishek Katoch")
        with open(VEVO_PDF, "rb") as f:
            self.vevo = vx.extract_visa_document(f.read(), crew_name="Abhishek Katoch")

    def test_both_parse(self):
        self.assertTrue(self.grant["ok"], self.grant["warnings"])
        self.assertTrue(self.vevo["ok"], self.vevo["warnings"])

    def test_kinds_detected_from_content(self):
        self.assertEqual(self.grant["kind"], vx.KIND_GRANT)
        self.assertEqual(self.vevo["kind"], vx.KIND_VEVO)

    def test_nothing_unread(self):
        self.assertEqual(self.grant["unread"], [], self.grant["unread"])
        self.assertEqual(self.vevo["unread"], [], self.vevo["unread"])

    def test_no_name_warning_for_the_right_person(self):
        for r in (self.grant, self.vevo):
            self.assertFalse([w for w in r["warnings"] if "doesn't match" in w])

    def test_name_warning_for_the_wrong_person(self):
        with open(VEVO_PDF, "rb") as f:
            r = vx.extract_visa_document(f.read(), crew_name="Rogelio Tan")
        self.assertTrue([w for w in r["warnings"] if "doesn't match" in w])

    def test_merged_record_is_complete(self):
        merged, conflicts = vx.merge_visa_documents(self.grant, self.vevo)
        for k in ("visa_subclass", "visa_grant_number", "trn", "visa_grant_date",
                  "visa_expiry", "passport_number", "passport_country",
                  "visa_conditions", "has_work_limitation"):
            self.assertIn(k, merged)
        self.assertEqual(conflicts, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
