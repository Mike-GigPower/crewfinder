"""Add call on an existing booking: SmartStaff's callsheet form often answers
by redirecting to /bookings/{id}, naming no call. ss_create_call then returned
"created", and the new call's Call Type couldn't be saved (booking 12208,
"Some Ops stuff", 21 Oct 2026). It now reads the booking's calls before and
after, and returns the one that is new. SmartStaff is stubbed; no network."""

import unittest

import app as A

BOOKING = 12208


class _Resp:
    def __init__(self, url, text="", status=200):
        self.url, self.text, self.status_code = url, text, status


class _SS:
    """The callsheet form GET, its POST redirecting to the booking, and
    get-booking.php, which shows the new call only after the POST."""

    def __init__(self, before, added):
        self.before, self.added, self.posted = dict(before), dict(added), False

    def get(self, url, params=None, **kw):
        if url.endswith("/get-booking.php"):
            calls = {**self.before, **(self.added if self.posted else {})}
            import json
            return _Resp(url, json.dumps({"calls": [{"call_id": k, "call_name": v} for k, v in calls.items()]}))
        return _Resp(url, "<form></form>")

    def post(self, url, data=None, **kw):
        self.posted = True
        return _Resp(f"{A.BASE_URL}/bookings/{BOOKING}")


CALL = {"call_name": "Some Ops stuff", "call_name_free": "Some Ops stuff",
        "start_date": "October 22, 2026", "start_time": "08:00:00",
        "duration_hours": 4, "crew_required": 1}


class AddCallId(unittest.TestCase):
    def test_the_new_call_is_found_when_smartstaff_names_none(self):
        ss = _SS({39320: "Show Call", 39321: "Load In"}, {39322: "Some Ops stuff"})
        self.assertEqual(A.ss_create_call(ss, BOOKING, CALL), ("39322", None))

    def test_two_new_calls_are_told_apart_by_name(self):
        ss = _SS({39320: "Show Call"}, {39322: "Some Ops stuff", 39323: "Load Out"})
        self.assertEqual(A.ss_create_call(ss, BOOKING, CALL), ("39322", None))

    def test_no_way_to_tell_still_says_created(self):
        ss = _SS({39320: "Show Call"}, {})
        self.assertEqual(A.ss_create_call(ss, BOOKING, CALL), ("created", None))

    def test_find_new_call_id(self):
        self.assertEqual(A.find_new_call_id({1: "a"}, {1: "a", 2: "b"}, "x"), 2)
        self.assertEqual(A.find_new_call_id({1: "a"}, {1: "a", 2: "x", 3: "x"}, "x"), None)
        self.assertEqual(A.find_new_call_id({1: "a"}, {1: "a", 2: "Rock &amp; Roll", 3: "b"}, "Rock & Roll"), 2)
        self.assertIsNone(A.find_new_call_id(None, {2: "b"}, "b"))


if __name__ == "__main__":
    unittest.main()
