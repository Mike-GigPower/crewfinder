"""Every Stage 3 write route refuses a non-Admin session with 403 and sends
nothing to Supabase: the call's allowance flags, a venue's travel time,
creating, editing or archiving an event, and putting a booking in an event.
The event search behind the Add to event picker is Admin only too. The key
ships in the DMG, so the server is the only real guard.

Every HTTP call THE GOAT could make (requests' get/post, which the
pricing-context helpers use) is recorded, as is anything reaching SmartStaff.
A control run as Admin shows the recorder does see a write, so an empty record
for the other cohorts means something."""

import unittest

import app as A

BOOKING, CALL, VENUE = 12208, 39322, 431
EVENT = "0b6f6a3e-2c1d-4c55-9a43-6f1e2d3c4b5a"
SUPABASE = "ihyvwhquycsxhmhulzmu.supabase.co"

WRITES = [
    ("call flags", f"/api/call/{BOOKING}/{CALL}/flags", {"attracts_broadcast": True, "travel_eligible": False}),
    ("venue travel", f"/api/venue/{VENUE}/travel", {"venue_name": "ZZ Venue", "return_minutes": 150}),
    ("create event", "/api/events", {"name": "ZZ TEST Fest"}),
    ("edit event", f"/api/events/{EVENT}", {"name": "ZZ TEST Fest 2"}),
    ("archive event", f"/api/events/{EVENT}", {"archived": True}),
    ("booking into event", f"/api/booking/{BOOKING}/event", {"event_id": EVENT}),
    ("booking out of event", f"/api/booking/{BOOKING}/event", {"event_id": None}),
]
ADMIN_READS = [
    ("event search", "/api/events?q=zz"),
    ("archived event search", "/api/events?q=zz&archived=1"),
    ("event dialog", f"/api/events/{EVENT}"),
]


class _Resp:
    status_code = 200

    @staticmethod
    def json():
        return {"id": EVENT, "name": "ZZ TEST Fest", "logged": True, "events": [],
                "call_flags": [], "booking_event": [], "venue_travel": [], "rows": []}


class _SmartStaff:
    """A logged-in SmartStaff session that records any use."""

    def __init__(self, sent):
        self.sent = sent

    def get(self, url, *a, **kw):
        self.sent.append(("smartstaff GET", url))
        return _Resp()

    def post(self, url, *a, **kw):
        self.sent.append(("smartstaff POST", url))
        return _Resp()


class Stage3AdminOnly(unittest.TestCase):
    def setUp(self):
        self.sent = []
        self._saved = {n: getattr(A, n) for n in ("get_ss_session", "GOAT_RATE_CARD_KEY")}
        self._saved_http = (A.http.get, A.http.post)
        ss = _SmartStaff(self.sent)
        A.get_ss_session = lambda: ss
        A.GOAT_RATE_CARD_KEY = "test-key"
        A.http.get = lambda url, *a, **kw: (self.sent.append(("GET", url)) or _Resp())
        A.http.post = lambda url, *a, **kw: (self.sent.append(("POST", url)) or _Resp())
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "stage3-sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        A.http.get, A.http.post = self._saved_http
        A._ss_identity.pop("stage3-sid", None)

    def as_cohort(self, cohort):
        A._ss_identity["stage3-sid"] = {"cohort": cohort, "user_id": "555"}

    def test_every_write_refuses_non_admins_and_sends_nothing(self):
        for cohort in ("operations", "leadership", "crew"):
            self.as_cohort(cohort)
            for label, url, body in WRITES:
                with self.subTest(cohort=cohort, route=label):
                    self.sent.clear()
                    r = self.client.post(url, json=body)
                    self.assertEqual(r.status_code, 403)
                    self.assertEqual(r.get_json(), {"error": "Forbidden for your access level"})
                    self.assertEqual(self.sent, [], "nothing may leave THE GOAT")

    def test_event_search_refuses_non_admins_and_sends_nothing(self):
        for cohort in ("operations", "leadership", "crew"):
            self.as_cohort(cohort)
            for label, url in ADMIN_READS:
                with self.subTest(cohort=cohort, route=label):
                    self.sent.clear()
                    r = self.client.get(url)
                    self.assertEqual(r.status_code, 403)
                    self.assertEqual(self.sent, [], "nothing may leave THE GOAT")

    def test_every_stage3_post_route_is_in_the_list(self):
        """A new Stage 3 write route that isn't added to WRITES fails here, so
        it can't ship without the 403 test above covering it."""
        covered = {url.replace(str(BOOKING), "<booking_id>").replace(str(CALL), "<call_id>")
                      .replace(str(VENUE), "<venue_id>").replace(EVENT, "<event_id>")
                   for _, url, _ in WRITES}
        stage3 = {"/api/call/<booking_id>/<call_id>/flags", "/api/venue/<venue_id>/travel",
                  "/api/events", "/api/events/<event_id>", "/api/booking/<booking_id>/event"}
        posts = {str(r) for r in A.app.url_map.iter_rules() if "POST" in (r.methods or ())}
        self.assertTrue(stage3 <= posts, stage3 - posts)
        self.assertEqual(stage3 - covered, set())

    def test_control_an_admin_write_is_seen_by_the_recorder(self):
        self.as_cohort("admin")
        r = self.client.post(WRITES[2][1], json=WRITES[2][2])   # create event needs no SmartStaff
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertTrue(any(SUPABASE in url and method == "POST" for method, url in self.sent))


if __name__ == "__main__":
    unittest.main()
