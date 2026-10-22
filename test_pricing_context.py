"""Stage 3 (BRIEF-goat-events-flags-stage3): the rules THE GOAT enforces in
front of the pricing-context edge function, which checks shape only.

  - no Travel flag on an M&OP booking (T3, acceptance test 6), but one set
    before the card changed is kept, not rewritten (test 7)
  - a flags save that changes nothing sends no write (test 12)
  - set_by_user_id is the server-side identity, never the body's
  - the flags' booking is SmartStaff's, never the URL's
  - travel time in 15-minute steps; null clears
  - the edge function's 409 and 422 reach the browser in its own words
  - the booking read names the event's OTHER bookings and the venue's travel

The HTTP layer is recorded and SmartStaff is stubbed: no network."""

import unittest

import app as A

BOOKING, CALL, VENUE = 12208, 39322, 431
EVENT = "0b6f6a3e-2c1d-4c55-9a43-6f1e2d3c4b5a"


class _Resp:
    def __init__(self, body, status=200):
        self.status_code = status
        self._body = body

    def json(self):
        return self._body


class PricingContext(unittest.TestCase):
    def setUp(self):
        self._saved = {n: getattr(A, n) for n in ("get_ss_session", "ss_get_call_times",
                                                  "GOAT_RATE_CARD_KEY")}
        self._saved_http = (A.http.get, A.http.post)
        A.GOAT_RATE_CARD_KEY = "test-key"
        A.get_ss_session = lambda: object()
        A.ss_get_call_times = lambda ss, call_id: ({"booking_id": BOOKING, "call_name": "Load In",
                                                    "crew": [], "paygrades": []}, None)
        self.card = None            # booking_rate_card row; None is Standard
        self.ctx = {"call_flags": [], "booking_event": [], "events": [], "venue_travel": []}
        self.post_reply = _Resp({"logged": True})
        self.gets, self.posts = [], []

        def fake_get(url, params=None, **kw):
            self.gets.append((url, params))
            if url == A.BOOKING_RATE_CARD_URL:
                return _Resp({"rows": [dict(self.card, booking_id=BOOKING)] if self.card else []})
            if url == A.PRICING_CONTEXT_URL + "/events":
                return _Resp({"events": [{"id": EVENT, "name": "ZZ TEST Fest", "booking_count": 2}]})
            return _Resp(self.ctx)

        def fake_post(url, json=None, **kw):
            self.posts.append((url, json))
            return self.post_reply

        A.http.get, A.http.post = fake_get, fake_post
        A._ss_identity["pc-sid"] = {"cohort": "admin", "user_id": "42"}
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "pc-sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        A.http.get, A.http.post = self._saved_http
        A._ss_identity.pop("pc-sid", None)

    def mopt(self):
        self.card = {"mopt": True, "australian_open": False, "set_code": "mopt"}

    def flags(self, b, t):
        return self.client.post(f"/api/call/{BOOKING}/{CALL}/flags",
                                json={"attracts_broadcast": b, "travel_eligible": t})

    # ── flags ────────────────────────────────────────────────────────────────

    def test_standard_flags_are_sent_with_the_server_identity(self):
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/flags",
                             json={"attracts_broadcast": True, "travel_eligible": True,
                                   "set_by_user_id": 999})
        self.assertEqual(r.status_code, 200, r.get_json())
        url, body = self.posts[-1]
        self.assertEqual(url, A.PRICING_CONTEXT_URL + "/flags")
        self.assertEqual(body, {"call_id": CALL, "booking_id": BOOKING, "attracts_broadcast": True,
                                "travel_eligible": True, "set_by_user_id": 42})

    def test_the_booking_is_smartstaffs_not_the_urls(self):
        r = self.client.post(f"/api/call/1/{CALL}/flags",
                             json={"attracts_broadcast": True, "travel_eligible": False})
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertEqual(self.posts[-1][1]["booking_id"], BOOKING)

    def test_travel_on_mopt_is_refused_and_nothing_is_sent(self):
        for card in ({"mopt": True, "australian_open": False}, {"mopt": True, "australian_open": True},
                     {"mopt": True, "australian_open": None}):
            with self.subTest(card=card):
                self.card = card
                self.posts.clear()
                r = self.flags(False, True)
                self.assertEqual(r.status_code, 422)
                self.assertIn("M&OP", r.get_json()["error"])
                self.assertEqual(self.posts, [])

    def test_broadcast_on_mopt_is_allowed(self):
        self.mopt()
        r = self.flags(True, False)
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertEqual(len(self.posts), 1)

    def test_travel_kept_after_the_card_changed_to_mopt(self):
        """Test 7: a flag set while Standard isn't rewritten. Turning Broadcast
        on beside it is allowed, and so is turning Travel off."""
        self.mopt()
        self.ctx["call_flags"] = [{"call_id": CALL, "booking_id": BOOKING,
                                   "attracts_broadcast": False, "travel_eligible": True}]
        self.assertEqual(self.flags(True, True).status_code, 200)
        self.assertEqual(self.flags(False, False).status_code, 200)
        self.assertEqual(len(self.posts), 2)

    def test_an_unchanged_save_sends_no_write(self):
        self.ctx["call_flags"] = [{"call_id": CALL, "booking_id": BOOKING,
                                   "attracts_broadcast": True, "travel_eligible": False}]
        r = self.flags(True, False)
        self.assertEqual(r.status_code, 200)
        self.assertTrue(r.get_json()["unchanged"])
        self.assertEqual(self.posts, [])

    def test_both_off_with_no_row_is_unchanged(self):
        r = self.flags(False, False)
        self.assertTrue(r.get_json()["unchanged"])
        self.assertEqual(self.posts, [])

    def test_flags_must_be_booleans(self):
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/flags",
                             json={"attracts_broadcast": "yes", "travel_eligible": False})
        self.assertEqual(r.status_code, 400)
        self.assertEqual(self.posts, [])

    def test_unlogged_save_warns(self):
        self.post_reply = _Resp({"logged": False}, 500)
        r = self.flags(True, False)
        self.assertEqual(r.status_code, 200)
        self.assertIn("wasn't logged", r.get_json()["warning"])

    # ── venue travel ─────────────────────────────────────────────────────────

    def test_travel_minutes_are_15_minute_steps(self):
        for bad in (0, -15, 100, 2.5, "150", True):
            with self.subTest(minutes=bad):
                r = self.client.post(f"/api/venue/{VENUE}/travel",
                                     json={"venue_name": "ZZ Venue", "return_minutes": bad})
                self.assertEqual(r.status_code, 422)
        self.assertEqual(self.posts, [])
        self.post_reply = _Resp({"venue_id": VENUE, "return_minutes": 150, "logged": True})
        r = self.client.post(f"/api/venue/{VENUE}/travel",
                             json={"venue_name": "ZZ Venue", "return_minutes": 150})
        self.assertEqual(r.get_json()["return_minutes"], 150)
        self.assertEqual(self.posts[-1][1], {"venue_id": VENUE, "venue_name": "ZZ Venue",
                                             "return_minutes": 150, "set_by_user_id": 42})

    def test_null_clears_the_travel_time(self):
        self.post_reply = _Resp({"venue_id": VENUE, "return_minutes": None, "logged": True})
        r = self.client.post(f"/api/venue/{VENUE}/travel", json={"return_minutes": None})
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertIsNone(self.posts[-1][1]["return_minutes"])

    # ── events ───────────────────────────────────────────────────────────────

    def test_duplicate_event_name_keeps_the_409(self):
        self.post_reply = _Resp({"error": 'An event called "zz test fest" already exists'}, 409)
        r = self.client.post("/api/events", json={"name": "zz test fest"})
        self.assertEqual(r.status_code, 409)
        self.assertIn("already exists", r.get_json()["error"])

    def test_archive_with_bookings_keeps_the_422(self):
        self.post_reply = _Resp({"error": "This event still has 2 booking(s); take them out first"}, 422)
        r = self.client.post(f"/api/events/{EVENT}", json={"archived": True})
        self.assertEqual(r.status_code, 422)
        self.assertIn("take them out", r.get_json()["error"])
        self.assertEqual(self.posts[-1][1], {"archived": True, "set_by_user_id": 42})

    def test_bad_event_ids_send_nothing(self):
        self.assertEqual(self.client.post("/api/events/not-a-uuid", json={"archived": True}).status_code, 400)
        r = self.client.post(f"/api/booking/{BOOKING}/event", json={"event_id": "nope"})
        self.assertEqual(r.status_code, 400)
        self.assertEqual(self.client.post(f"/api/booking/{BOOKING}/event", json={}).status_code, 400)
        self.assertEqual(self.posts, [])

    def test_booking_into_and_out_of_an_event(self):
        self.post_reply = _Resp({"booking_id": BOOKING, "event_id": EVENT, "logged": True})
        r = self.client.post(f"/api/booking/{BOOKING}/event", json={"event_id": EVENT.upper()})
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertEqual(self.posts[-1][1], {"booking_id": BOOKING, "event_id": EVENT, "set_by_user_id": 42})
        self.post_reply = _Resp({"booking_id": BOOKING, "event_id": None, "logged": True,
                                 "previous": {"event_id": EVENT}})
        r = self.client.post(f"/api/booking/{BOOKING}/event", json={"event_id": None})
        self.assertEqual(r.get_json()["previous_event_id"], EVENT)
        self.assertIsNone(self.posts[-1][1]["event_id"])

    def test_event_search(self):
        r = self.client.get("/api/events?q=zz")
        self.assertEqual(r.get_json()["events"][0]["booking_count"], 2)

    # ── reads ────────────────────────────────────────────────────────────────

    def test_booking_read_names_the_other_bookings_and_the_venue_travel(self):
        self.ctx = {
            "call_flags": [{"call_id": CALL, "booking_id": BOOKING, "attracts_broadcast": True,
                            "travel_eligible": False, "set_by_user_id": 42}],
            "booking_event": [{"booking_id": BOOKING, "event_id": EVENT}],
            "events": [{"id": EVENT, "name": "ZZ TEST Fest", "booking_ids": [BOOKING, 12300]}],
            "venue_travel": [{"venue_id": VENUE, "return_minutes": 150}],
        }
        j = self.client.get(f"/api/booking/{BOOKING}/pricing-context?venue_id={VENUE}").get_json()
        self.assertEqual(j["event"]["name"], "ZZ TEST Fest")
        self.assertEqual(j["event"]["other_booking_ids"], [12300])
        self.assertEqual(j["venue_travel"]["return_minutes"], 150)
        self.assertTrue(j["call_flags"][str(CALL)]["attracts_broadcast"])
        self.assertTrue(j["travel_allowed"])
        params = [p for u, p in self.gets if u == A.PRICING_CONTEXT_URL][0]
        self.assertEqual(params, {"booking_ids": str(BOOKING), "venue_ids": str(VENUE)})

    def test_call_read_on_mopt_says_travel_isnt_allowed(self):
        self.mopt()
        self.ctx["call_flags"] = [{"call_id": CALL, "booking_id": BOOKING,
                                   "attracts_broadcast": False, "travel_eligible": True}]
        j = self.client.get(f"/api/call/{BOOKING}/{CALL}/flags?venue_id={VENUE}").get_json()
        self.assertTrue(j["travel_eligible"])
        self.assertFalse(j["travel_allowed"])
        self.assertIsNone(j["venue_travel"]["return_minutes"])

    def test_reads_are_open_to_leadership_with_can_change_false(self):
        A._ss_identity["pc-sid"] = {"cohort": "leadership", "user_id": "7"}
        j = self.client.get(f"/api/call/{BOOKING}/{CALL}/flags").get_json()
        self.assertFalse(j["can_change"])
        j = self.client.get(f"/api/booking/{BOOKING}/pricing-context").get_json()
        self.assertFalse(j["can_change"])

    def test_a_missing_key_sends_nothing(self):
        A.GOAT_RATE_CARD_KEY = ""
        r = self.client.get(f"/api/booking/{BOOKING}/pricing-context")
        self.assertEqual(r.status_code, 503)
        self.assertIn("no rate card key", r.get_json()["error"])
        self.assertEqual(self.gets, [])


if __name__ == "__main__":
    unittest.main()
