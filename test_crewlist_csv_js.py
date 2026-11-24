"""Crew list as CSV, and the on-site contact rows (BRIEF-crewlist-export §§1-3).

Runs the page's own _clBuildModel / _clBuildCsvRows under node (js_harness) on
made-up data only: booking and call 90001, crew 90001+, invented phones. Skips
when node isn't installed.

THE IMPORTANT TEST IS PrintedListUnchanged: the CSV work split _clBuildHtml into
a model and two renderers, and the printed list had to come out byte-identical
apart from the two new header rows. That is checked against golden HTML recorded
from 5.120.0 before any of this was written — see
tests/fixtures/crewlist_golden/run_golden.py.

decodeEntities is stubbed here with a REAL decoder rather than js_harness's
identity stub: the browser's version uses a textarea, which node has no DOM for,
and a CSV cell reading "Clark &amp; Sons" instead of "Clark & Sons" is exactly
the bug these tests are for.
"""

import json
import os
import re
import sys
import unittest

import js_harness as H

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "tests", "fixtures", "crewlist_golden"))
import run_golden as G                               # noqa: E402

CASES = os.path.join(HERE, "tests", "fixtures", "crewlist_golden", "cases")

# What the browser's textarea trick does, for the entities SmartStaff sends.
DECODE_STUB = r"""
function decodeEntities(s){
  if(s == null) return '';
  return String(s).replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"')
                  .replace(/&#39;/g,"'").replace(/&amp;/g,'&');
}
"""

OFFICE_PHONE = "03 9376 9500"
# "<customer> / <booking> / <call>" — the one-cell row that opens each call.
# Matched on the customer, because the hint line and the When value also carry
# a " / ".
TITLE_PREFIX = "Test Customer / Booking 90001 / "
START_DATE = 1795093200          # midnight 20 Nov 2026, Melbourne (AEDT)


def _qr_lib():
    with open(os.path.join(HERE, "static", "qrcode-generator.js"), encoding="utf-8") as fh:
        return fh.read()


def csv_rows(data, opts):
    """_clBuildCsvRows(_clBuildModel(data, opts)) under node."""
    js = "\n".join([
        "process.env.TZ = 'Australia/Melbourne';",
        H.DOM_PRELUDE,
        DECODE_STUB,                                  # after the prelude: overrides it
        _qr_lib(),
        "\n".join(G._decl(k, n) for k, n in G.DECLS),
        G._funcs(),
        "var opts = %s;" % json.dumps(opts),
        "var rows = _clBuildCsvRows(_clBuildModel(%s, opts));" % json.dumps(data),
        "console.log(JSON.stringify({rows: rows}));",
    ])
    return H.run(js)["rows"]


def load_case(name):
    with open(os.path.join(CASES, name + ".json"), encoding="utf-8") as fh:
        return json.load(fh)


def crew(n, **over):
    out = []
    for i in range(n):
        c = {"id": 90001 + i, "firstname": "Test%d" % i, "lastname": "Person",
             "status": "confirmed", "mobile": "0412 000 %03d" % i}
        c.update(over)
        out.append(c)
    return out


def booking(calls, onsite=None):
    d = {
        "name": "Booking 90001",
        "customer": {"name": "Test Customer"},
        "venue": {"name": "Test Venue"},
        "contact": {"id": 70001, "name": "Test Contact", "mobile": "0400 000 001"},
        "calls": calls,
    }
    if onsite is not None:
        d["onsite"] = onsite
    return d


def call(call_id=90001, name="Load In", members=None, **over):
    c = {"call_id": call_id, "call_name": name, "start_date": START_DATE,
         "start_time": "22:00:00", "est_length": "8",
         "required": len(members or []), "confirmed": len(members or []),
         "notes": "", "crew": members or []}
    c.update(over)
    return c


def header_value(rows, label):
    """The value beside a header-block label, for every call in the file."""
    return [r[1] for r in rows if r and r[0] == label]


def column_row(rows):
    for r in rows:
        if r and r[0] == "Name":
            return [c for c in r if c]
    raise AssertionError("no column row in the CSV")


def is_blank(row):
    return all(c == "" for c in row)


@unittest.skipUnless(H.NODE, "node not installed")
class CsvLayout(unittest.TestCase):

    def test_one_call_has_the_header_block_in_print_order(self):
        rows = csv_rows(*(lambda c: (c["data"], c["opts"]))(load_case("one_call_plain")))
        labels = [r[0] for r in rows if r and r[1] and r[0] != "Name"]
        self.assertEqual(labels, ["Call #", "When", "Contact", "Phone", "On-site",
                                  "On-site phone", "Venue", "Est. Length",
                                  "Required", "Booked"])
        # Notes is empty in this fixture, so it is not in the list above — but
        # the row must still be there, in its place after Booked.
        all_labels = [r[0] for r in rows if r and r[0]]
        self.assertEqual(all_labels[all_labels.index("Booked") + 1], "Notes")

    def test_every_row_is_padded_to_the_widest_table(self):
        case = load_case("two_calls_cols")
        rows = csv_rows(case["data"], case["opts"])
        width = len(column_row(rows))
        self.assertEqual(width, 9)          # Name, EIN, Phone, Email + 5 write-ins
        for i, r in enumerate(rows):
            self.assertEqual(len(r), width, "row %d is %d wide" % (i, len(r)))

    def test_two_calls_are_separated_by_two_blank_rows(self):
        case = load_case("two_calls_cols")
        rows = csv_rows(case["data"], case["opts"])
        titles = [i for i, r in enumerate(rows) if r and r[0].startswith(TITLE_PREFIX)]
        self.assertEqual(len(titles), 2, "one title row per call")
        gap = rows[titles[1] - 2:titles[1]]
        self.assertTrue(all(is_blank(r) for r in gap), "two blank rows before call 2")
        self.assertFalse(is_blank(rows[titles[1] - 3]), "and not a third")
        # Nothing trails the last call.
        self.assertFalse(is_blank(rows[-1]), "no blank rows at the end of the file")

    def test_one_call_has_no_separator_rows(self):
        rows = csv_rows(booking([call(members=crew(2))]), {"who": "confirmed"})
        self.assertTrue(rows[0][0].startswith(TITLE_PREFIX), "the title is the first row")
        self.assertFalse(is_blank(rows[-1]))

    def test_optional_columns_on(self):
        rows = csv_rows(booking([call(members=crew(1, ein="01234",
                                                  email="t@example.com"))]),
                        {"ein": True, "phone": True, "email": True, "who": "confirmed"})
        self.assertEqual(column_row(rows),
                         ["Name", "EIN", "Phone", "Email", "On", "Brk start",
                          "Brk mins", "Off", "Notes"])

    def test_optional_columns_off(self):
        rows = csv_rows(booking([call(members=crew(1))]), {"who": "confirmed"})
        self.assertEqual(column_row(rows),
                         ["Name", "On", "Brk start", "Brk mins", "Off", "Notes"])

    def test_write_in_cells_are_empty(self):
        rows = csv_rows(booking([call(members=crew(2))]),
                        {"phone": True, "who": "confirmed"})
        body = [r for r in rows if r and r[0].startswith("Person, Test")]
        self.assertEqual(len(body), 2)
        for r in body:
            self.assertNotEqual(r[0], "")
            self.assertNotEqual(r[1], "")                  # Phone
            self.assertEqual(r[2:], ["", "", "", "", ""])   # the five write-ins

    def test_no_crew_says_so_where_the_rows_would_be(self):
        rows = csv_rows(booking([call(members=[])]), {"who": "confirmed"})
        flat = [r[0] for r in rows]
        self.assertIn("No crew to list for this call.", flat)
        self.assertEqual(flat.index("No crew to list for this call."),
                         flat.index("Name") + 1, "it sits straight after the column row")

    def test_the_name_cell_is_last_comma_first_as_plain_text(self):
        rows = csv_rows(booking([call(members=crew(1))]), {"who": "confirmed"})
        names = [r[0] for r in rows if r[0].startswith("Person")]
        self.assertEqual(names, ["Person, Test0"])
        for r in rows:
            for cell in r:
                self.assertNotIn("<", cell, "no markup may reach a CSV cell")
                self.assertNotIn("&amp;", cell, "entities must be decoded")

    def test_an_escaped_ampersand_arrives_decoded(self):
        """SmartStaff sends "Clark &amp; Sons"; the cell must read "Clark & Sons"."""
        members = crew(1)
        members[0]["lastname"] = "Clark &amp; Sons"
        members[0]["firstname"] = "Carol"
        rows = csv_rows(booking([call(members=members)]), {"who": "confirmed"})
        names = [r[0] for r in rows if "Clark" in r[0]]
        self.assertEqual(names, ["Clark & Sons, Carol"])

    def test_notes_and_venue_are_decoded_too(self):
        data = booking([call(members=crew(1), notes="Dock 3 &amp; 4")])
        data["venue"] = {"name": "Rod Laver &amp; Co"}
        rows = csv_rows(data, {"who": "confirmed"})
        self.assertEqual(header_value(rows, "Notes"), ["Dock 3 & 4"])
        self.assertEqual(header_value(rows, "Venue"), ["Rod Laver & Co"])

    def test_the_qr_is_not_in_the_csv(self):
        rows = csv_rows(booking([call(members=crew(1))]), {"who": "confirmed"})
        for r in rows:
            for cell in r:
                self.assertNotIn("svg", cell.lower())
        # The Call # row stands in for it.
        self.assertEqual(header_value(rows, "Call #"), ["90001"])

    def test_the_hint_line_is_one_cell(self):
        rows = csv_rows(booking([call(members=crew(1))]), {"who": "confirmed"})
        hints = [r for r in rows if r[0].startswith("Times in 24-hour")]
        self.assertEqual(len(hints), 1)
        self.assertIn("A second break: write it in Notes.", hints[0][0])
        self.assertEqual(hints[0][1:], [""] * (len(hints[0]) - 1))

    def test_a_booking_with_no_calls_says_so(self):
        rows = csv_rows(booking([]), {"who": "confirmed"})
        self.assertEqual(rows[0][0], "This booking has no calls.")


@unittest.skipUnless(H.NODE, "node not installed")
class OnSiteContact(unittest.TestCase):
    """D5. The venue must never be left without someone to ring."""

    def _onsite(self, onsite):
        rows = csv_rows(booking([call(members=crew(1))], onsite=onsite),
                        {"who": "confirmed"})
        return header_value(rows, "On-site")[0], header_value(rows, "On-site phone")[0]

    def test_present_with_a_mobile(self):
        self.assertEqual(
            self._onsite({"id": 70002, "name": "On Site Person",
                          "phone": "03 9000 0002", "mobile": "0400 000 002"}),
            ("On Site Person", "0400 000 002"))

    def test_present_with_only_a_landline(self):
        self.assertEqual(
            self._onsite({"id": 70002, "name": "On Site Person",
                          "phone": "03 9000 0002", "mobile": ""}),
            ("On Site Person", "03 9000 0002"))

    def test_present_with_no_phone_keeps_the_name_and_an_empty_phone(self):
        """NOT the office number beside their name: that would send whoever
        reads it to the wrong person."""
        self.assertEqual(
            self._onsite({"id": 70003, "name": "Phoneless Person",
                          "phone": "", "mobile": ""}),
            ("Phoneless Person", ""))

    def test_absent_falls_back_to_gig_power_and_the_office(self):
        for onsite in ({"id": 0, "name": "", "phone": "", "mobile": ""},
                       {"id": 0, "name": "", "phone": "", "mobile": "0400 000 002"},
                       {"id": 70002, "name": "   ", "phone": "", "mobile": ""},
                       None):
            with self.subTest(onsite=onsite):
                self.assertEqual(self._onsite(onsite), ("Gig Power", OFFICE_PHONE))

    def test_the_office_number_is_printed_exactly_as_given(self):
        name, phone = self._onsite(None)
        self.assertEqual(phone, "03 9376 9500")
        self.assertRegex(H.page(), r"const CL_GP_FALLBACK = \{name: 'Gig Power', phone: '03 9376 9500'\}")


@unittest.skipUnless(H.NODE, "node not installed")
class ExcelLeadingZero(unittest.TestCase):
    """Measured in Excel 2026 on this Mac: a cell of bare digits is read as a
    number, so "01234" arrives as 1234 and "0412000001" as 412000001. A leading
    tab keeps the cell text. Values with spaces ("0412 000 001") are already
    text to Excel and must be left exactly as SmartStaff sent them, so the file
    stays clean to edit (D2)."""

    def test_a_digits_only_ein_is_guarded(self):
        rows = csv_rows(booking([call(members=crew(1, ein="01234"))]),
                        {"ein": True, "who": "confirmed"})
        row = [r for r in rows if r[0].startswith("Person")][0]
        self.assertEqual(row[1], "\t01234")

    def test_a_digits_only_phone_is_guarded(self):
        members = crew(1)
        members[0]["mobile"] = "0412000001"
        rows = csv_rows(booking([call(members=members)]),
                        {"phone": True, "who": "confirmed"})
        row = [r for r in rows if r[0].startswith("Person")][0]
        self.assertEqual(row[1], "\t0412000001")

    def test_a_spaced_phone_is_left_alone(self):
        rows = csv_rows(booking([call(members=crew(1))]),
                        {"phone": True, "who": "confirmed"})
        row = [r for r in rows if r[0].startswith("Person")][0]
        self.assertEqual(row[1], "0412 000 000")

    def test_the_office_number_needs_no_guard(self):
        rows = csv_rows(booking([call(members=crew(1))], onsite=None),
                        {"who": "confirmed"})
        self.assertEqual(header_value(rows, "On-site phone"), [OFFICE_PHONE])

    def test_a_number_without_a_leading_zero_is_left_alone(self):
        rows = csv_rows(booking([call(members=crew(1, ein="12345"))]),
                        {"ein": True, "who": "confirmed"})
        row = [r for r in rows if r[0].startswith("Person")][0]
        self.assertEqual(row[1], "12345")


@unittest.skipUnless(H.NODE, "node not installed")
class PrintedListUnchanged(unittest.TestCase):
    """The printed list must be the 5.120.0 bytes apart from the On-site rows."""

    def test_golden_cases_match_5_120_0(self):
        for path in G.case_paths():
            name = os.path.basename(path)[:-5]
            with self.subTest(case=name):
                with open(path, encoding="utf-8") as fh:
                    case = json.load(fh)
                with open(G.golden_path(path), encoding="utf-8") as fh:
                    want = fh.read()
                self.assertEqual(G.strip_onsite(G.render(case)), want)

    def test_the_golden_files_predate_the_on_site_rows(self):
        """A golden quietly re-recorded after the change would make the test
        above vacuous."""
        for path in G.case_paths():
            with open(G.golden_path(path), encoding="utf-8") as fh:
                self.assertNotIn("On-site", fh.read(), G.golden_path(path))

    def test_todays_printed_list_does_carry_the_two_rows(self):
        for path in G.case_paths():
            with open(path, encoding="utf-8") as fh:
                html = G.render(json.load(fh))
            self.assertIn("<tr><th>On-site:</th>", html)
            self.assertIn("<tr><th>On-site phone:</th>", html)


class DialogWiring(unittest.TestCase):
    """Read out of index.html: the parts that need a browser to run, but whose
    wiring is worth holding still."""

    @classmethod
    def setUpClass(cls):
        src = H.page()
        i = src.index("async function crewListGenerate(")
        cls.gen = src[i:src.index("\n}", i)]
        j = src.index("function openCrewLists(")
        cls.open_dialog = src[j:src.index("\n}", j)]
        k = src.index("async function _clLoadChecklist(")
        cls.checklist = src[k:src.index("\n}", k)]

    def test_generate_takes_a_format(self):
        self.assertIn("async function crewListGenerate(bookingId, fmt)", self.gen)
        self.assertIn("const csv = (fmt === 'csv')", self.gen)

    def test_only_the_print_path_opens_a_tab(self):
        m = re.search(r"if\(!csv\)\{\s*\n\s*win = window\.open\('', '_blank'\)", self.gen)
        self.assertIsNotNone(m, "window.open must be inside if(!csv)")
        self.assertEqual(self.gen.count("window.open("), 1)

    def test_the_csv_path_downloads_rows_and_builds_the_same_model(self):
        self.assertIn("goatExportCSVRows(", self.gen)
        self.assertIn("_clBuildCsvRows(_clBuildModel(data, opts))", self.gen)

    def test_mark_as_sent_is_offered_after_a_csv_too(self):
        self.assertIn("_clAskMarkSent(bookingId, snap, done)", self.gen)
        self.assertIn("const done = csv ? 'Crew list CSV downloaded.'", self.gen)

    def test_the_prompt_says_what_actually_happened(self):
        src = H.page()
        i = src.index("function _clAskMarkSent(")
        body = src[i:src.index("\n}", i)]
        self.assertIn("function _clAskMarkSent(bookingId, snap, title)", body)
        self.assertIn("textContent = title ||", body)

    def test_both_buttons_are_offered(self):
        self.assertIn("entityBtn('\U0001f4c4 PDF / Print'", self.open_dialog)
        self.assertIn("entityBtn('\U0001f4ca CSV'", self.open_dialog)
        self.assertIn("crewListGenerate('\"+bookingId+\"', 'csv')", self.open_dialog)
        self.assertIn("CSV downloads the same list as a spreadsheet", self.open_dialog)

    def test_select_all_and_clear_are_rendered_with_the_loaded_checklist(self):
        self.assertIn("_clPickLink('Select all', 1)", self.checklist)
        self.assertIn("_clPickLink('Clear', 0)", self.checklist)
        # Only in the branch that has calls to tick: after the no-data and
        # no-calls returns.
        self.assertLess(self.checklist.index("This booking has no calls."),
                        self.checklist.index("_clPickLink('Select all', 1)"))

    def test_cancelled_calls_are_marked_for_select_all(self):
        self.assertIn("(cx?' data-cx=\"1\"':'')", self.checklist)
        src = H.page()
        i = src.index("function _clPickAll(")
        body = src[i:src.index("\n}", i)]
        self.assertIn("if(!on){ el.checked = false; return; }", body)
        self.assertIn("if(el.getAttribute('data-cx') !== '1') el.checked = true;", body)

    def test_the_csv_escaping_is_not_copied(self):
        """One quoting rule, one BOM, one dated file name (§2)."""
        src = H.page()
        self.assertEqual(src.count("replace(/\"/g, '\"\"')"), 1)
        self.assertIn("function goatExportCSVRows(filenameBase, rows){\n  _goatCsvDownload(filenameBase, rows);",
                      src)
        self.assertIn("function goatExportCSV(filenameBase, headers, rows){\n  _goatCsvDownload(filenameBase, [headers].concat(rows));",
                      src)


if __name__ == "__main__":
    unittest.main()
