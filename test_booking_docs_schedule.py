"""The "Schedule" booking-document category (BRIEF-crewlist-export D6, §4).

The category list lives in FOUR places that must agree, or an operator meets a
constraint error nobody can read:

    supabase/migrations/20261124231500_booking_documents_schedule.sql  the CHECK
    supabase/functions/goat-booking-docs/index.ts                      CATEGORIES
    app.py                                                            BOOKING_DOC_CATEGORIES
    templates/index.html                                              BK_DOC_CATEGORIES

So the last test here reads all four and compares them. It is the one that
catches the next person who adds a category in three places out of four.

The edge function is mocked: these tests never reach Supabase.
"""

import re
import unittest
from unittest import mock

import app as A

WANT = ("quote", "invoice", "requirements", "schedule",
        "special_conditions", "site_info", "other")

MIGRATION = "supabase/migrations/20261124231500_booking_documents_schedule.sql"
EDGE_FN = "supabase/functions/goat-booking-docs/index.ts"


def _read(path):
    with open(path, encoding="utf-8") as fh:
        return fh.read()


class CategoryList(unittest.TestCase):

    def test_flask_has_schedule_after_requirements(self):
        self.assertIn("schedule", A.BOOKING_DOC_CATEGORIES)
        cats = list(A.BOOKING_DOC_CATEGORIES)
        self.assertEqual(cats[cats.index("schedule") - 1], "requirements")

    def test_flask_list_is_exactly_the_seven(self):
        self.assertEqual(tuple(A.BOOKING_DOC_CATEGORIES), WANT)

    def test_all_four_places_agree(self):
        """The whole point of D6: four lists, one order, no drift."""
        sql = _read(MIGRATION)
        m = re.search(r"check \(category in \((.*?)\)\)", sql, re.S)
        self.assertIsNotNone(m, "no category CHECK in the migration")
        migration = tuple(re.findall(r"'([a-z_]+)'", m.group(1)))

        ts = _read(EDGE_FN)
        m = re.search(r"const CATEGORIES = new Set\(\[(.*?)\]\)", ts, re.S)
        self.assertIsNotNone(m, "no CATEGORIES in the edge function")
        edge = tuple(re.findall(r'"([a-z_]+)"', m.group(1)))

        html = _read("templates/index.html")
        m = re.search(r"var BK_DOC_CATEGORIES = \[(.*?)\n\];", html, re.S)
        self.assertIsNotNone(m, "no BK_DOC_CATEGORIES in index.html")
        ui = tuple(re.findall(r"\['([a-z_]+)',", m.group(1)))

        self.assertEqual(migration, WANT, "the migration's CHECK")
        self.assertEqual(edge, WANT, "the edge function's CATEGORIES")
        self.assertEqual(ui, WANT, "index.html's BK_DOC_CATEGORIES")
        self.assertEqual(tuple(A.BOOKING_DOC_CATEGORIES), WANT, "app.py's tuple")

    def test_the_ui_labels_schedule(self):
        html = _read("templates/index.html")
        m = re.search(r"var BK_DOC_CATEGORIES = \[(.*?)\n\];", html, re.S)
        self.assertRegex(m.group(1), r"\['schedule',\s*'Schedule'\]")


class AddLinkRoute(unittest.TestCase):
    """The route's own category check, with the edge function mocked out."""

    def setUp(self):
        # require_cohort wants a session id AND a live SmartStaff session; the
        # route itself never touches SmartStaff, so a stand-in object is enough.
        self._saved_ss = A.get_ss_session
        A.get_ss_session = lambda: object()
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "docsched-sid"
        A._ss_identity["docsched-sid"] = {"cohort": "admin", "user_id": "555"}

    def tearDown(self):
        A.get_ss_session = self._saved_ss
        A._ss_identity.pop("docsched-sid", None)

    def _post(self, category):
        calls = []

        def fake(mode, payload):
            calls.append((mode, payload))
            return {"document": {"id": "x", "category": payload.get("category")}}, None, 200

        with mock.patch.object(A, "_booking_docs_call", fake):
            r = self.client.post("/api/booking/12345/documents/link",
                                 json={"category": category, "title": "Run sheet",
                                       "url": "https://example.com/run-sheet.pdf"})
        return r, calls

    def test_schedule_passes_the_route_check(self):
        r, calls = self._post("schedule")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(len(calls), 1, "the edge function should have been called once")
        mode, payload = calls[0]
        self.assertEqual(mode, "add_link")
        # The category must reach the edge function unchanged — if the route
        # quietly rewrote it, the database would store the wrong thing.
        self.assertEqual(payload["category"], "schedule")

    def test_every_listed_category_passes(self):
        for c in A.BOOKING_DOC_CATEGORIES:
            with self.subTest(category=c):
                r, calls = self._post(c)
                self.assertEqual(r.status_code, 200)
                self.assertEqual(calls[0][1]["category"], c)

    def test_an_unlisted_category_is_still_refused_and_sends_nothing(self):
        for c in ("schedules", "Schedule", "timetable", ""):
            with self.subTest(category=c):
                r, calls = self._post(c)
                self.assertEqual(r.status_code, 400)
                self.assertEqual(r.get_json(), {"error": "Choose a category"})
                self.assertEqual(calls, [], "nothing may reach the edge function")


if __name__ == "__main__":
    unittest.main()
