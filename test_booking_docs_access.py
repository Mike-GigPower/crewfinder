"""Who may see the booking dialog's tabs (BRIEF §6, §7.1, §15 A8/A9).

Two layers, and they are NOT the same thing:

  * the ROUTE decorators, which are the actual access control;
  * the tab list in index.html, which only decides what is drawn.

"Hiding a button is not access control" (BRIEF §6), so the route tests are the
ones that matter. The tab-list tests are here because a tab that is drawn and
then 403s is a bug of its own — the user is shown something they cannot have.
"""

import re
import unittest

import app as A


def _cohorts_for(view_name):
    """The cohorts require_cohort() was given for a route.

    require_cohort closes over `allowed`, so the tuple is in the wrapper's
    closure. Read rather than grepped, so this tests the object that actually
    runs, not the source that was meant to produce it.
    """
    view = A.app.view_functions[view_name]
    for cell in (view.__closure__ or ()):
        val = cell.cell_contents
        if isinstance(val, tuple) and val and all(isinstance(x, str) for x in val):
            return set(val)
    raise AssertionError("no cohort tuple in the closure of %s" % view_name)


class RouteGates(unittest.TestCase):

    def test_documents_read_is_open_to_every_read_all_cohort(self):
        self.assertEqual(_cohorts_for("api_booking_documents"),
                         set(A.READ_ALL_COHORTS))
        # i.e. admin AND the leadership-equivalent cohorts, not admin alone.
        self.assertIn("admin", A.READ_ALL_COHORTS)
        for c in A.LEADERSHIP_COHORTS:
            self.assertIn(c, _cohorts_for("api_booking_documents"))

    def test_opening_a_document_is_open_to_the_same_cohorts(self):
        self.assertEqual(_cohorts_for("api_booking_documents_open"),
                         set(A.READ_ALL_COHORTS))

    def test_changing_documents_is_admin_only(self):
        for view in ("api_booking_documents_add_link",
                     "api_booking_documents_add_file",
                     "api_booking_documents_remove"):
            self.assertEqual(_cohorts_for(view), {"admin"}, view)

    def test_financials_is_admin_only_to_view(self):
        """It is money (Q2). Admin to VIEW, not merely to change."""
        self.assertEqual(_cohorts_for("api_booking_figures"), {"admin"})

    def test_setting_the_quote_is_admin_only(self):
        self.assertEqual(_cohorts_for("api_booking_quote_set"), {"admin"})

    def test_read_all_cohorts_has_not_quietly_widened(self):
        """If a cohort is ever added here, the Documents tab gains it too, so
        the change should be deliberate."""
        self.assertEqual(set(A.READ_ALL_COHORTS),
                         {"admin", "leadership", "operations"})


class TabList(unittest.TestCase):
    """The booking dialog's tab order and gating, read out of index.html.

    A8 (§15): Details · Calls · Crew Notes · Call feeds · Crew bosses ·
    Documents · Financials — Documents and Financials LAST, which deliberately
    sets aside 5.79.0's P8 for this dialog.
    """

    @classmethod
    def setUpClass(cls):
        with open("templates/index.html", encoding="utf-8") as fh:
            src = fh.read()
        i = src.index("function renderBookingDialog(")
        j = src.index("window._entityTabOnShow", i)
        cls.block = src[i:j]

    def _push_order(self):
        """The tab ids in the order the dialog pushes them."""
        ids = re.findall(r"\{\s*id:\s*'([a-z]+)'", self.block)
        return ids

    def test_documents_and_financials_are_the_last_two(self):
        self.assertEqual(
            self._push_order(),
            ["details", "calls", "notes", "feeds", "bosses", "docs", "figures"])

    def test_the_financials_tab_is_labelled_financials(self):
        """A9: the label a person reads. The id stays `figures`."""
        self.assertRegex(self.block, r"id:\s*'figures',\s*label:\s*'Financials'")
        self.assertNotRegex(self.block, r"label:\s*'Figures'")

    def test_the_financials_tab_is_pushed_only_for_admins(self):
        m = re.search(r"(.{140})\{ id: 'figures'", self.block, re.S)
        self.assertIn("window._cohort === 'admin'", m.group(1))

    def test_the_crew_bosses_tab_is_pushed_only_for_admins(self):
        m = re.search(r"(.{120})\{ id: 'bosses'", self.block, re.S)
        self.assertIn("window._cohort === 'admin'", m.group(1))

    def test_the_documents_tab_is_not_gated_in_the_tab_list(self):
        """Every cohort that can open the dialog sees it; the route decides
        what they can do with it."""
        m = re.search(r"(.{90})\{ id: 'docs'", self.block, re.S)
        self.assertNotIn("_cohort === 'admin'", m.group(1))

    def test_a_saved_admin_only_tab_falls_back_for_a_read_only_cohort(self):
        """entityTabsPick remembers the last tab per booking, so someone who
        viewed Financials as an Admin and returns on a read-only cohort must
        not select a tab that is no longer in the list."""
        self.assertIn("if(tab === 'figures' && window._cohort !== 'admin') tab = 'calls';",
                      self.block)
        self.assertIn("if(tab === 'bosses'", self.block)


if __name__ == "__main__":
    unittest.main()
