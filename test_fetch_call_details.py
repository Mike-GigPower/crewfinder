"""fetch_call_details reads get-booking.php, not the callsheet HTML.
Regression for call #39519 (9 Nov 2026): tooltip said MCEC, booking is AAMI Park."""
import unittest
from datetime import datetime
from unittest import mock
import app as A

MIDNIGHT_13_NOV = int(datetime(2026, 11, 13).timestamp())   # local midnight epoch

BOOKING = {
    "name": "Iron Maiden - AAMI",
    "venue": {"id": 7, "name": "AAMI Park"},
    "calls": [
        {"call_id": 39518, "call_name": "Fork", "start_date": MIDNIGHT_13_NOV,
         "start_time": "22:30:00", "est_length": "5", "required": 5},
        {"call_id": 39519, "call_name": "Load Out", "start_date": MIDNIGHT_13_NOV,
         "start_time": "22:30:00", "est_length": "4", "required": 118,
         "notes": "Ex MCEC crew — Melbourne Convention Centre"},
    ],
}


class FetchCallDetails(unittest.TestCase):
    def run_with(self, payload, err=None, cid="39519"):
        with mock.patch.object(A, "fetch_booking_bulk", lambda ss, bid: (payload, err)):
            return A.fetch_call_details(object(), "12249", cid)

    def test_venue_is_booking_venue_name(self):
        d, err = self.run_with(BOOKING)
        self.assertIsNone(err)
        self.assertEqual(d["venue"], "AAMI Park")   # not MCEC from the notes

    def test_times_and_shape(self):
        d, _ = self.run_with(BOOKING)
        self.assertEqual(d["call_name"], "Load Out")
        self.assertEqual(d["start_dt"], "2026-11-13T22:30:00")
        self.assertEqual(d["end_dt"], "2026-11-14T02:30:00")
        self.assertEqual(d["length_hrs"], 4.0)
        self.assertEqual(d["crew_required"], 118)
        self.assertEqual((d["start_str"], d["end_str"]), ("22:30", "02:30"))
        self.assertEqual(d["date_str"], "Friday 13 November 2026")

    def test_venue_name_feeds_induction_gate(self):
        d, _ = self.run_with(BOOKING)
        self.assertEqual(A._induction_code_for_venue_name(d["venue"]), "AAMI")

    def test_unknown_call(self):
        d, err = self.run_with(BOOKING, cid="99999")
        self.assertIsNone(d)
        self.assertIn("not found", err)

    def test_booking_error(self):
        d, err = self.run_with(None, err="HTTP 500")
        self.assertIsNone(d)
        self.assertIn("HTTP 500", err)

    def test_entities_decoded(self):
        b = dict(BOOKING, venue={"id": 1, "name": "Her Majesty&#039;s"})
        d, _ = self.run_with(b)
        self.assertEqual(d["venue"], "Her Majesty's")


if __name__ == "__main__":
    unittest.main()
