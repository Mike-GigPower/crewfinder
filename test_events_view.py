"""The Events view (BRIEF-events-view §4.3): the Event dialog's read,
GET /api/events/<id>, and the Events list's archived passthrough.

The edge function, SmartStaff and the crew cache are stubbed; every HTTP call
is recorded. The fill rule is checked twice against ONE fixture: in Python
(event_call_fill, the route) and in the page (evCallFill, under node via
js_harness), so the two can't drift apart."""

import json
import unittest
from datetime import datetime
from zoneinfo import ZoneInfo

import app as A
import js_harness as H

EVENT = "0b6f6a3e-2c1d-4c55-9a43-6f1e2d3c4b5a"
B1, B2, B3 = 12215, 12216, 12217
MELB = ZoneInfo("Australia/Melbourne")


def melb_midnight(y, m, d):
    """SmartStaff's start_date: midnight Melbourne as a Unix timestamp. Read in
    UTC it's the day before, so a UTC conversion fails these tests."""
    return int(datetime(y, m, d, tzinfo=MELB).timestamp())


def crew(uid, status, first="Crew", last=None, **kw):
    return dict({"id": uid, "firstname": first, "lastname": last or f"Member{uid}",
                 "ein": str(5000 + uid), "status": status, "is_call_boss": 0,
                 "promo_pending": False}, **kw)


OLACK = dict(first="Mike", last="O'Lack")

# Booking 1: one live call with every crew status, and a cancelled call.
CALL_501 = {"call_id": 501, "call_name": "Load In", "start_date": melb_midnight(2027, 2, 5),
            "required": 5, "cancelled_at": None, "cancelled": 0, "crew": [
                crew(10, "confirmed", is_call_boss=1, **OLACK),
                crew(11, "confirmed", promo_pending=True),
                crew(12, "unconfirmed"),
                crew(13, "declined"),
                crew(14, "backup"),
                crew(15, "sent"),
                crew(16, "noshow"),
                crew(17, "cancelled"),
            ]}
CALL_502 = {"call_id": 502, "call_name": "Rig", "start_date": melb_midnight(2027, 2, 4),
            "required": 3, "cancelled_at": 1790000000, "cancelled": 0,
            "crew": [crew(18, "confirmed")]}
# Booking 2: O'Lack again on another day, plus two calls cancelled the other ways.
CALL_601 = {"call_id": 601, "call_name": "Show", "start_date": melb_midnight(2027, 2, 7),
            "required": 2, "cancelled_at": None, "cancelled": 0, "crew": [
                crew(10, "confirmed", **OLACK),
                crew(12, "confirmed", promo_pending=True),
            ]}
CALL_602 = {"call_id": 602, "call_name": "CANCELLED - Load Out", "start_date": melb_midnight(2027, 2, 9),
            "required": 4, "cancelled_at": None, "cancelled": 0, "crew": [crew(19, "confirmed")]}
CALL_603 = {"call_id": 603, "call_name": "Bump", "start_date": melb_midnight(2027, 2, 10),
            "required": 1, "cancelled_at": None, "cancelled": True, "crew": [crew(19, "confirmed")]}

FILL = [  # (call, required, filled): the call dialog's Crew row
    (CALL_501, 5, 1),     # 2 confirmed, 1 of them promo_pending; unconfirmed and the rest don't count
    (CALL_502, 0, 0),     # cancelled_at
    (CALL_601, 2, 1),
    (CALL_602, 0, 0),     # name "CANCELLED -"
    (CALL_603, 0, 0),     # cancelled true
]

BOOKINGS = {
    B1: {"booking_id": B1, "name": "Fest &amp; Co Day 1", "status": "Active",
         "venue": {"id": 431, "name": "Flemington"}, "calls": [CALL_501, CALL_502]},
    B2: {"booking_id": B2, "name": "Fest Day 2", "status": "Active",
         "venue": {"id": 431, "name": "Flemington"}, "calls": [CALL_601, CALL_602, CALL_603]},
}


class _Resp:
    def __init__(self, body, status=200):
        self.status_code = status
        self._body = body

    def json(self):
        return self._body


class EventsView(unittest.TestCase):
    def setUp(self):
        names = ("get_ss_session", "fetch_booking_bulk", "user_names_for", "GOAT_RATE_CARD_KEY")
        self._saved = {n: getattr(A, n) for n in names}
        self._saved_http = (A.http.get, A.http.post)
        A.GOAT_RATE_CARD_KEY = "test-key"
        A.get_ss_session = lambda: object()
        A.user_names_for = lambda ids: {7: "Mike Lack"}
        self.fetched = []
        self.bookings = dict(BOOKINGS)

        def fake_booking(ss, bid):
            self.fetched.append(bid)
            data = self.bookings.get(int(bid))
            return (data, None) if data else (None, "HTTP 500")

        A.fetch_booking_bulk = fake_booking
        self.event_reply = _Resp({
            "event": {"id": EVENT, "name": "ZZ TEST Fest", "notes": "Gates 6pm", "archived": False,
                      "created_by_user_id": 7, "created_at": "2026-10-28T01:00:00Z"},
            "bookings": [{"booking_id": B2, "event_id": EVENT, "set_by_user_id": 7, "set_at": "2026-10-28T02:00:00Z"},
                         {"booking_id": B3, "event_id": EVENT, "set_by_user_id": 7, "set_at": "2026-10-28T02:30:00Z"},
                         {"booking_id": B1, "event_id": EVENT, "set_by_user_id": 7, "set_at": "2026-10-28T03:00:00Z"}]})
        self.ctx_reply = _Resp({"call_flags": [
            {"call_id": 501, "booking_id": B1, "attracts_broadcast": True, "travel_eligible": True},
            {"call_id": 502, "booking_id": B1, "attracts_broadcast": True, "travel_eligible": False},
            {"call_id": 601, "booking_id": B2, "attracts_broadcast": False, "travel_eligible": True}],
            "booking_event": [], "events": [], "venue_travel": []})
        self.post_reply = _Resp({"logged": True})
        self.gets, self.posts = [], []

        def fake_get(url, params=None, **kw):
            self.gets.append((url, params))
            if url == A.PRICING_CONTEXT_URL + "/events":
                return _Resp({"events": [{"id": EVENT, "name": "ZZ TEST Fest", "booking_count": 0,
                                          "archived": True, "extra": "dropped"}]})
            if url.startswith(A.PRICING_CONTEXT_URL + "/events/"):
                return self.event_reply
            return self.ctx_reply

        def fake_post(url, json=None, **kw):
            self.posts.append((url, json))
            return self.post_reply

        A.http.get, A.http.post = fake_get, fake_post
        A._ss_identity["ev-sid"] = {"cohort": "admin", "user_id": "42"}
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "ev-sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        A.http.get, A.http.post = self._saved_http
        A._ss_identity.pop("ev-sid", None)

    def detail(self):
        r = self.client.get(f"/api/events/{EVENT}")
        self.assertEqual(r.status_code, 200, r.get_json())
        return r.get_json()

    # ── access and errors ────────────────────────────────────────────────────

    def test_non_admins_get_403_and_nothing_is_sent(self):
        for cohort in ("operations", "leadership", "crew"):
            with self.subTest(cohort=cohort):
                A._ss_identity["ev-sid"] = {"cohort": cohort, "user_id": "42"}
                r = self.client.get(f"/api/events/{EVENT}")
                self.assertEqual(r.status_code, 403)
                self.assertEqual((self.gets, self.posts, self.fetched), ([], [], []))

    def test_a_bad_uuid_is_400_and_sends_nothing(self):
        r = self.client.get("/api/events/not-a-uuid")
        self.assertEqual(r.status_code, 400)
        self.assertEqual(self.gets, [])

    def test_an_unknown_event_is_404_with_the_functions_words(self):
        self.event_reply = _Resp({"error": "No such event"}, 404)
        r = self.client.get(f"/api/events/{EVENT.upper()}")
        self.assertEqual(r.status_code, 404)
        self.assertEqual(r.get_json()["error"], "No such event")
        self.assertEqual(self.gets[0][0], A.PRICING_CONTEXT_URL + "/events/" + EVENT)
        self.assertEqual(self.fetched, [])

    def test_a_missing_key_says_so_and_sends_nothing(self):
        A.GOAT_RATE_CARD_KEY = ""
        r = self.client.get(f"/api/events/{EVENT}")
        self.assertEqual(r.status_code, 503)
        self.assertIn("no rate card key", r.get_json()["error"])
        self.assertEqual(self.gets, [])

    # ── the read ─────────────────────────────────────────────────────────────

    def test_the_whole_read(self):
        d = self.detail()
        self.assertEqual(d["event"], {"id": EVENT, "name": "ZZ TEST Fest", "notes": "Gates 6pm", "archived": False,
                                      "created_at": "2026-10-28T01:00:00Z", "created_by_name": "Mike Lack"})
        self.assertTrue(d["flags_ok"])
        self.assertTrue(d["can_change"])
        self.assertEqual(d["honesty"], A.PRICING_CONTEXT_HONESTY)
        b1 = next(b for b in d["bookings"] if b["booking_id"] == B1)
        self.assertEqual(b1, {
            "booking_id": B1, "ok": True, "name": "Fest &amp; Co Day 1", "status": "Active",
            "venue_name": "Flemington", "first_date": "2027-02-05", "last_date": "2027-02-05",
            "live_calls": 1, "required": 5, "filled": 1,
            "broadcast_calls": 1, "travel_calls": 1,   # 502's Broadcast is on a cancelled call
            "added_at": "2026-10-28T03:00:00Z"})
        b2 = next(b for b in d["bookings"] if b["booking_id"] == B2)
        self.assertEqual((b2["first_date"], b2["last_date"], b2["live_calls"]), ("2027-02-07", "2027-02-07", 1))
        self.assertEqual((b2["broadcast_calls"], b2["travel_calls"]), (0, 1))
        self.assertEqual(d["totals"], {"bookings": 3, "first_date": "2027-02-05", "last_date": "2027-02-07",
                                       "required": 7, "filled": 2})
        # One pricing-context read for all of them, bookings fetched once each.
        ctx_gets = [p for u, p in self.gets if u == A.PRICING_CONTEXT_URL]
        self.assertEqual(len(ctx_gets), 1)
        self.assertEqual(sorted(ctx_gets[0]["booking_ids"].split(",")), sorted(str(b) for b in (B1, B2, B3)))
        self.assertEqual(sorted(int(b) for b in self.fetched), [B1, B2, B3])

    def test_a_booking_that_fails_comes_back_ok_false_and_last(self):
        d = self.detail()
        self.assertEqual([b["booking_id"] for b in d["bookings"]], [B1, B2, B3])   # by first_date, failed last
        self.assertEqual(d["bookings"][-1], {"booking_id": B3, "ok": False, "error": "HTTP 500",
                                             "added_at": "2026-10-28T02:30:00Z"})
        self.assertTrue(all(b["ok"] for b in d["bookings"][:2]))

    def test_pricing_context_failing_hides_markers_only(self):
        self.ctx_reply = _Resp({}, 500)
        d = self.detail()
        self.assertFalse(d["flags_ok"])
        self.assertEqual(len(d["bookings"]), 3)
        self.assertEqual(d["totals"]["filled"], 2)
        for b in d["bookings"]:
            if b["ok"]:
                self.assertEqual((b["broadcast_calls"], b["travel_calls"]), (0, 0))
        self.assertFalse(any(p["broadcast"] for p in d["crew"]))

    def test_an_empty_event_fetches_no_bookings(self):
        self.event_reply = _Resp({"event": {"id": EVENT, "name": "Empty", "archived": True}, "bookings": []})
        d = self.detail()
        self.assertEqual(self.fetched, [])
        self.assertEqual([u for u, _ in self.gets], [A.PRICING_CONTEXT_URL + "/events/" + EVENT])
        self.assertEqual((d["bookings"], d["crew"]), ([], []))
        self.assertEqual(d["totals"], {"bookings": 0, "first_date": None, "last_date": None, "required": 0, "filled": 0})
        self.assertTrue(d["event"]["archived"])

    # ── the crew roll-up (§6.4) ──────────────────────────────────────────────

    def test_crew_rollup(self):
        crew_rows = {p["user_id"]: p for p in self.detail()["crew"]}
        # Only confirmed and unconfirmed, on live calls.
        self.assertEqual(sorted(crew_rows), [10, 11, 12])
        olack = crew_rows[10]
        self.assertEqual(olack, {"user_id": 10, "name": "Mike O'Lack", "ein": "5010", "calls": 2, "days": 2,
                                 "unconfirmed_only": False, "is_boss": True, "broadcast": True})
        self.assertTrue(crew_rows[11]["unconfirmed_only"])      # confirmed but promotion unanswered
        self.assertTrue(crew_rows[12]["unconfirmed_only"])      # unconfirmed + promo-pending
        self.assertEqual((crew_rows[12]["calls"], crew_rows[12]["days"]), (2, 2))
        self.assertTrue(crew_rows[12]["broadcast"])             # on 501, which attracts Broadcast
        self.assertFalse(crew_rows[12]["is_boss"])

    def test_crew_sort_days_then_surname(self):
        rows = A.event_crew_rollup([BOOKINGS[B1], BOOKINGS[B2]], {})
        self.assertEqual([r["user_id"] for r in rows], [12, 10, 11])   # Member12 (2 days), O'Lack (2), Member11 (1)

    def test_the_same_call_twice_counts_once(self):
        b = {"calls": [CALL_601, dict(CALL_601)]}
        rows = A.event_crew_rollup([b], {})
        self.assertEqual(next(r for r in rows if r["user_id"] == 10)["calls"], 1)

    # ── the helpers ──────────────────────────────────────────────────────────

    def test_live_call_helper_each_cancelled_signal(self):
        self.assertTrue(A.event_call_is_live(CALL_501))
        self.assertFalse(A.event_call_is_live(CALL_502))   # cancelled_at
        self.assertFalse(A.event_call_is_live(CALL_603))   # cancelled true
        self.assertFalse(A.event_call_is_live(CALL_602))   # "CANCELLED -"
        self.assertFalse(A.event_call_is_live({"call_name": "  cancelled - x"}))
        self.assertTrue(A.event_call_is_live({"call_name": "Cancelled show rebook", "cancelled": 3}))

    def test_fill_rule_in_python(self):
        for call, req, filled in FILL:
            with self.subTest(call=call["call_id"]):
                self.assertEqual(A.event_call_fill(call), (req, filled))

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_fill_rule_in_the_page_matches(self):
        js = H.extract("_clIsCancelled", "evCallFill") + "\nvar calls = " + json.dumps([c for c, _, _ in FILL]) + r""";
console.log(JSON.stringify(calls.map(function(c){ var f = evCallFill(c); return [f.required, f.filled]; })));"""
        out = H.run(js)
        self.assertEqual(out, [[req, filled] for _, req, filled in FILL])
        self.assertEqual(out, [list(A.event_call_fill(c)) for c, _, _ in FILL])

    # ── the dialog head's fill figure (§8.3 F-1) ─────────────────────────────

    def _head_fill(self, cases):
        js = H.extract("evHeadFillHtml") + "\nvar cases = " + json.dumps(cases) + r""";
console.log(JSON.stringify(cases.map(function(c){ return evHeadFillHtml(c[0], c[1]); })));"""
        return H.run(js)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_head_fill_with_a_failed_booking_is_never_green(self):
        ok, bad = {"booking_id": 1, "ok": True}, {"booking_id": 2, "ok": False}
        full, short = self._head_fill([
            [{"required": 10, "filled": 10}, [ok, bad, dict(bad, booking_id=3)]],
            [{"required": 10, "filled": 7}, [ok, bad]],
        ])
        self.assertNotIn("--ok", full)
        self.assertIn("<span>10 of 10 filled</span>", full)
        self.assertIn('<span style="color:var(--conflict)">2 couldn\'t load</span>', full)
        self.assertIn("#f59e0b", short)                     # still short, still amber
        self.assertIn("1 couldn't load", short)
        # every booking loaded and full: green, no failure note
        clean, = self._head_fill([[{"required": 10, "filled": 10}, [ok]]])
        self.assertIn("var(--ok,#10b981)", clean)
        self.assertNotIn("couldn't load", clean)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_head_fill_with_nothing_required_is_uncoloured(self):
        out = self._head_fill([
            [{"required": 0, "filled": 0}, []],
            [{}, [{"booking_id": 1, "ok": True}]],
            [{"required": 0, "filled": 0}, [{"booking_id": 2, "ok": False}]],
        ])
        self.assertEqual(out[0], "<span>0 of 0 filled</span>")
        self.assertEqual(out[1], "<span>0 of 0 filled</span>")
        self.assertTrue(out[2].startswith("<span>0 of 0 filled</span>"))
        self.assertIn("1 couldn't load", out[2])

    def test_melbourne_dates_not_utc(self):
        self.assertEqual(A._event_melb_date(melb_midnight(2027, 2, 5)), "2027-02-05")
        self.assertEqual(A._event_melb_date(melb_midnight(2026, 7, 1)), "2026-07-01")   # AEST as well as AEDT
        self.assertIsNone(A._event_melb_date(0))
        self.assertIsNone(A._event_melb_date("x"))

    # ── the Events list and the unarchive message ────────────────────────────

    def test_event_search_passes_archived_through(self):
        self.client.get("/api/events?q=zz")
        self.assertEqual(self.gets[-1], (A.PRICING_CONTEXT_URL + "/events", {"q": "zz"}))
        r = self.client.get("/api/events?q=zz&archived=1")
        self.assertEqual(self.gets[-1], (A.PRICING_CONTEXT_URL + "/events", {"q": "zz", "archived": "1"}))
        self.assertEqual(r.get_json()["events"], [{"id": EVENT, "name": "ZZ TEST Fest", "notes": None,
                                                   "created_at": None, "booking_count": 0, "archived": True}])
        self.client.get("/api/events?q=zz&archived=true")
        self.assertEqual(self.gets[-1][1], {"q": "zz"})

    def test_unarchive_409_gets_a_readable_message(self):
        self.post_reply = _Resp({"error": 'An event called "undefined" already exists'}, 409)
        r = self.client.post(f"/api/events/{EVENT}", json={"archived": False})
        self.assertEqual(r.status_code, 409)
        self.assertEqual(r.get_json()["error"], "A live event already has this name. Rename one of them first.")

    def test_a_rename_409_keeps_the_functions_words(self):
        self.post_reply = _Resp({"error": 'An event called "zz test fest" already exists'}, 409)
        r = self.client.post(f"/api/events/{EVENT}", json={"name": "zz test fest"})
        self.assertEqual(r.status_code, 409)
        self.assertIn('"zz test fest" already exists', r.get_json()["error"])


if __name__ == "__main__":
    unittest.main()
