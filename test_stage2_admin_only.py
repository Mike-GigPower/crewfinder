"""Every Stage 2 write route refuses a non-Admin session with 403 and sends
nothing to Supabase: /type (Change...), /override, /override/clear and Make
Crew Boss. The key ships in the DMG, so the server is the only real guard.

Every HTTP call THE GOAT could make (requests' get/post, which the
booking-rate-card and call-types helpers use) is recorded, as is anything
reaching SmartStaff. A control run as Admin shows the recorder does see a
write, so an empty record for the other cohorts means something."""

import unittest

import app as A

BOOKING, CALL, CREW = 12208, 39322, 77
SUPABASE = "ihyvwhquycsxhmhulzmu.supabase.co"

WRITES = [
    ("Change... (/type)", f"/api/call/{BOOKING}/{CALL}/call-type", {"role_name": "Event Operations"}),
    ("/override", f"/api/call/{BOOKING}/{CALL}/override/{CREW}", {"charge_role": "Show Crew"}),
    ("/override/clear", f"/api/call/{BOOKING}/{CALL}/override/{CREW}/clear", {}),
    ("Make Crew Boss", f"/api/call/{BOOKING}/{CALL}/crew-boss/{CREW}", {}),
]


class _Resp:
    status_code = 200

    @staticmethod
    def json():
        return {"cleared": True, "logged": True, "rows": [], "call_types": [], "overrides": []}


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


class Stage2AdminOnly(unittest.TestCase):
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
            s["sid"] = "stage2-sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        A.http.get, A.http.post = self._saved_http
        A._ss_identity.pop("stage2-sid", None)

    def as_cohort(self, cohort):
        A._ss_identity["stage2-sid"] = {"cohort": cohort, "user_id": "555"}

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

    def test_control_an_admin_write_is_seen_by_the_recorder(self):
        self.as_cohort("admin")
        r = self.client.post(WRITES[2][1], json={})       # /override/clear needs no SmartStaff
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertTrue(any(SUPABASE in url and method == "POST" for method, url in self.sent))


if __name__ == "__main__":
    unittest.main()
