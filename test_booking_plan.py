#!/usr/bin/env python3
"""Tests for the booking planner (BRIEF-staffing-plans-b §2.3, §5 B3-B9).

SmartStaff is faked: the Finder assessment, get-booking.php,
list-crew-experience.php and the callsheet scrape are patched per test, so
build_booking_plan runs for real with no network and no login. All crew are
invented (the repo is public).

    python3 test_booking_plan.py
"""
import json
import unittest
from unittest import mock

import app as A

VENUE = "Test Arena"


def call(cid, name, start, end, required, committed=0, feeds=(), venue=VENUE, crew=()):
    return {"cid": str(cid), "name": name, "start": start, "end": end, "venue": venue,
            "required": required, "committed": committed, "feeds": [str(f) for f in feeds],
            "crew": list(crew)}


def person(uid, name, rating=4, new=False, all_h=500.0, free=None, warn=None, shifts=(), boss=0):
    """free: call ids they're available for (None = every call)."""
    return {"uid": uid, "name": name, "rating": rating, "new": new, "all_h": all_h,
            "free": None if free is None else set(str(f) for f in free),
            "warn": dict((str(k), v) for k, v in (warn or {}).items()),
            "shifts": list(shifts), "boss": boss}


class Fake(object):
    def __init__(self, calls, people, boss_calls=()):
        self.calls  = calls
        self.people = people
        self.boss   = set(str(c) for c in boss_calls)

    def booking(self, ss, bid):
        return {"calls": [{
            "call_id": int(c["cid"]), "call_name": c["name"], "required": c["required"],
            "committed": c["committed"], "confirmed": c["committed"],
            "feeds": c["feeds"], "feeds_recommended": [], "cancelled_at": None,
            "start_date": 0, "start_time": "", "notes": "", "crew": c["crew"],
        } for c in self.calls]}, None

    def rows(self, uid):
        """call id -> their get-booking status label on it."""
        return dict((c["cid"], r["status"]) for c in self.calls for r in c["crew"]
                    if str(r["id"]) == str(uid))

    def assess(self, ss, data):
        """Like _finder_assess: rel from the booking's rows, and open offers
        ('sent' rows) as pass 3 sees them — offers on the searched calls are
        left out, which is how the real search loses them."""
        from datetime import datetime
        tids = [str(c["call_id"]) for c in data["calls"]]
        avail = []
        for p in self.people:
            rows = self.rows(p["uid"])
            offers = [{"call_id": int(c["cid"]), "start": c["start"], "end": c["end"], "venue": c["venue"],
                       "status": 1, "is_offer": True}
                      for c in self.calls if rows.get(c["cid"]) == "sent" and c["cid"] not in tids]
            crs = []
            for t in tids:
                ok = p["free"] is None or t in p["free"]
                warn = p["warn"].get(t, "") if ok else ""
                clash = ""
                if ok and not warn and offers:
                    c = next(c for c in self.calls if c["cid"] == t)
                    tgt = {"call_id": t, "start": datetime.fromisoformat(c["start"]),
                           "end": datetime.fromisoformat(c["end"]), "venue": c["venue"]}
                    confirmed = [x for x in p["shifts"] if x.get("status") == 5 and str(x["call_id"]) != t]
                    clash = A._offer_clash_pass(confirmed, offers, tgt)[0]
                crs.append({"call_id": int(t), "available": ok, "rel": rows.get(t),
                            "induction_warning": "", "induction_current": "",
                            "offer_clash": clash, "conflict_warning": warn,
                            "licence_status": {}})
            if not any(r["available"] for r in crs):
                continue
            avail.append({"id": str(p["uid"]), "name": p["name"], "ein": "T%d" % p["uid"],
                          "rating": p["rating"], "licences": [], "shifts": p["shifts"] + offers,
                          "conflict_warnings": sorted(set(v for v in p["warn"].values())),
                          "call_results": crs})
        return {"available": avail, "conflicts": [], "skipped": [], "location_unknown": [],
                "targets": []}, None

    def experience(self, ss, ids, cid, force=False):
        users = {}
        for p in self.people:
            users[str(p["uid"])] = {"is_new": p["new"], "current_run_h": 50.0 if p["new"] else 300.0,
                                    "all_time_h": p["all_h"], "past_boss_3y": p["boss"],
                                    "excluded": False}
        for c in self.calls:
            for r in c["crew"]:
                users.setdefault(str(r["id"]), {"is_new": False, "current_run_h": 300.0,
                                                "all_time_h": 900.0, "past_boss_3y": 0})
        return {"users": users, "call": {"is_boss_call": str(cid) in self.boss},
                "constants": {"probation_hours": 144}, "as_at": "2027-03-01T00:00:00"}, None

    def call_types(self, bid, calls):
        """_plan_call_types: no Call Type stored or derivable (the network
        read, stubbed)."""
        return {}, None

    def scrape(self, ss, bid, cid):
        c = next(c for c in self.calls if c["cid"] == str(cid))
        return {"call_name": c["name"], "start_dt": c["start"], "end_dt": c["end"],
                "venue": c["venue"], "crew_required": c["required"]}, None

    def body(self, cids, plan=None, bid=700):
        out = []
        for cid in cids:
            c = next(c for c in self.calls if c["cid"] == str(cid))
            out.append({"booking_id": bid, "call_id": int(c["cid"]), "call_name": c["name"],
                        "start_dt": c["start"], "end_dt": c["end"], "venue": c["venue"]})
        return {"calls": out, "min_rating": 3, "plan": plan or {"objective": "rating"}}

    def run(self, cids, plan=None):
        with mock.patch.object(A, "_finder_assess", self.assess), \
             mock.patch.object(A, "fetch_booking_bulk", self.booking), \
             mock.patch.object(A, "ss_list_crew_experience", self.experience), \
             mock.patch.object(A, "scrape_call_details", self.scrape), \
             mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None), \
             mock.patch.object(A, "_plan_call_types", self.call_types):
            return A.build_booking_plan(object(), self.body(cids, plan))


def picks(plan, cid):
    c = next(c for c in plan["calls"] if c["call_id"] == int(cid))
    return [p["name"] for p in c["picks"]]


def calls_of(plan, name):
    return next(p["calls"] for p in plan["people"] if p["name"] == name)


class BookingPlanTests(unittest.TestCase):

    def three_calls(self, places=(1, 1, 1)):
        return [call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", places[0]),
                call(2, "Show",    "2027-03-10T13:00:00", "2027-03-10T17:00:00", places[1]),
                call(3, "Load Out", "2027-03-10T18:00:00", "2027-03-10T22:00:00", places[2])]

    def test_b3_deterministic(self):
        f = Fake(self.three_calls((3, 3, 3)),
                 [person(100 + i, "Crew %02d" % i, rating=3 + i % 3, new=i % 2 == 0,
                         free=None if i % 3 else [1, 2]) for i in range(12)])
        r1, e1 = f.run([1, 2, 3], {"objective": "mix", "new_pct": 50})
        r2, e2 = f.run([1, 2, 3], {"objective": "mix", "new_pct": 50})
        self.assertIsNone(e1)
        self.assertEqual(json.dumps(r1["plan"], sort_keys=True), json.dumps(r2["plan"], sort_keys=True))

    def test_b4_continuity_beats_rating(self):
        f = Fake(self.three_calls((2, 1, 1)),
                 [person(1, "A Steady", rating=3, free=[1, 2, 3]),
                  person(2, "B Star", rating=5, free=[1])])
        r, err = f.run([1, 2, 3])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(calls_of(p, "A Steady"), [1, 2, 3])
        self.assertEqual(calls_of(p, "B Star"), [1])     # the place A left on call 1
        self.assertEqual(p["summary"]["continuity_crew"], 1)
        self.assertEqual(p["people"][0]["root_calls"], [1, 2, 3])

    def test_b5_locked_feed_carried(self):
        calls = [call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 2, feeds=[3]),
                 call(2, "Show",    "2027-03-10T13:00:00", "2027-03-10T17:00:00", 1),
                 call(3, "Load Out", "2027-03-10T18:00:00", "2027-03-10T22:00:00", 3)]
        f = Fake(calls, [person(1, "C Both", free=[1, 3]),
                         person(2, "D InOnly", rating=5, free=[1]),
                         person(3, "E Free", rating=3),
                         person(4, "F OutOnly", free=[3])])
        r, err = f.run([1, 2])                            # load out not selected
        self.assertIsNone(err)
        p = r["plan"]
        out = next(c for c in p["calls"] if c["call_id"] == 3)
        self.assertTrue(out["carried"])
        self.assertIn("carried", p["read_back"])
        self.assertNotIn("D InOnly", picks(p, 1))         # can't work the load out
        for name in picks(p, 1):
            self.assertIn(name, picks(p, 3))              # load-in picks carried
        c_both = next(x for x in p["people"] if x["name"] == "C Both")
        self.assertEqual(c_both["root_calls"], [1])       # server carries the load out
        self.assertIn("Carried from #1 Load In (Wed 10 Mar) — crew placed there by it.", out["notes"])

        # The Finder selects the load out along with the load in: still carried.
        r2, err = f.run([1, 3])
        self.assertIsNone(err)
        self.assertEqual([c["carried"] for c in r2["plan"]["calls"]], [False, True])
        r3, err = f.run([1, 2, 3])
        self.assertEqual(json.dumps(r3["plan"]["calls"], sort_keys=True),
                         json.dumps(r["plan"]["calls"], sort_keys=True))

    def test_b6_no_double_booking_overlap(self):
        calls = [call(1, "Stage A", "2027-03-10T08:00:00", "2027-03-10T14:00:00", 3),
                 call(2, "Stage B", "2027-03-10T12:00:00", "2027-03-10T18:00:00", 3)]
        f = Fake(calls, [person(10 + i, "Crew %d" % i) for i in range(4)])
        r, err = f.run([1, 2])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertFalse(set(picks(p, 1)) & set(picks(p, 2)))
        self.assertEqual(p["summary"]["continuity_crew"], 0)
        self.assertEqual(len(picks(p, 1)) + len(picks(p, 2)), 4)

    def test_b7_roll_through_same_venue(self):
        calls = [call(1, "Build", "2027-03-10T08:00:00", "2027-03-10T18:00:00", 1),
                 call(2, "Show",  "2027-03-10T18:00:00", "2027-03-10T22:00:00", 1)]
        f = Fake(calls, [person(1, "G Roller")])
        r, err = f.run([1, 2])
        self.assertIsNone(err)
        self.assertEqual(calls_of(r["plan"], "G Roller"), [1, 2])

    def test_b7_no_run_over_16_in_24(self):
        calls = [call(1, "Build", "2027-03-10T06:00:00", "2027-03-10T16:00:00", 1),
                 call(2, "Show",  "2027-03-10T16:00:00", "2027-03-11T00:00:00", 1)]
        f = Fake(calls, [person(1, "H Long")])
        r, err = f.run([1, 2])
        self.assertIsNone(err)
        self.assertEqual(len(calls_of(r["plan"], "H Long")), 1)

    def test_b7_rule2_other_venue(self):
        calls = [call(1, "Build", "2027-03-10T08:00:00", "2027-03-10T18:00:00", 1),
                 call(2, "Show",  "2027-03-10T19:00:00", "2027-03-10T22:00:00", 1, venue="Other Hall")]
        f = Fake(calls, [person(1, "I Mover")])
        r, err = f.run([1, 2])
        self.assertIsNone(err)
        self.assertEqual(len(calls_of(r["plan"], "I Mover")), 1)

    def test_b8_boss_only_on_boss_call(self):
        calls = [call(1, "Crew Boss", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 1),
                 call(2, "Load In",   "2027-03-10T08:00:00", "2027-03-10T12:00:00", 2),
                 call(3, "Load Out",  "2027-03-10T18:00:00", "2027-03-10T22:00:00", 2)]
        f = Fake(calls, [person(1, "J Boss", rating=5, all_h=3000, boss=4)]
                 + [person(10 + i, "Crew %d" % i) for i in range(4)], boss_calls=[1])
        r, err = f.run([1, 2, 3])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(picks(p, 1), ["J Boss"])
        self.assertNotIn("J Boss", picks(p, 2) + picks(p, 3))
        self.assertTrue(next(c for c in p["calls"] if c["call_id"] == 1)["is_boss_call"])

    def test_b9_one_booking(self):
        f = Fake(self.three_calls(), [person(1, "K")])
        body = f.body([1, 2])
        body["calls"][1]["booking_id"] = 701
        with mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None):
            r, err = A.build_booking_plan(object(), body)
        self.assertEqual(err, ("Plan one booking at a time.", 400))

    def test_warned_never_picked(self):
        f = Fake(self.three_calls(), [person(1, "L Warned", rating=5, warn={2: "Rule 4 - Would work 17.0hrs"}),
                                      person(2, "M Plain", rating=3, free=[2])])
        r, err = f.run([1, 2, 3])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertNotIn("L Warned", picks(p, 2))
        show = next(c for c in p["calls"] if c["call_id"] == 2)
        self.assertEqual([w["name"] for w in show["could_fill_warned"]], ["L Warned"])

    def test_confirmed_shift_blocks_run(self):
        # 10 h confirmed elsewhere ending 07:00: Rule 4 lets them work one
        # 4 h call (14 h) but not two (18 h).
        other = [{"call_id": 999, "start": "2027-03-09T21:00:00", "end": "2027-03-10T07:00:00",
                  "venue": VENUE, "status": 5}]
        calls = [call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 1),
                 call(2, "Show",    "2027-03-10T13:00:00", "2027-03-10T17:00:00", 1)]
        f = Fake(calls, [person(1, "N Tired", shifts=other)])
        r, err = f.run([1, 2])
        self.assertIsNone(err)
        self.assertEqual(len(calls_of(r["plan"], "N Tired")), 1)

    def test_mix_quota_per_call(self):
        f = Fake(self.three_calls((2, 2, 2)),
                 [person(1, "O New", new=True), person(2, "P New", new=True),
                  person(3, "Q Old"), person(4, "R Old")])
        r, err = f.run([1, 2, 3], {"objective": "mix", "new_pct": 50})
        self.assertIsNone(err)
        for c in r["plan"]["calls"]:
            self.assertEqual(c["mix"]["planned"], {"new": 1, "experienced": 1})

    def test_places_breakdown(self):
        crew = [{"id": 900, "status": "confirmed", "promo_pending": False},
                {"id": 901, "status": "confirmed", "promo_pending": True},
                {"id": 902, "status": "sent"}, {"id": 903, "status": "declined"}]
        calls = self.three_calls()
        calls[0]["committed"] = 3
        calls[0]["required"] = 3
        calls[0]["crew"] = crew
        f = Fake(calls, [person(1, "S")])
        r, err = f.run([1, 2, 3])
        self.assertIsNone(err)
        c1 = r["plan"]["calls"][0]
        self.assertEqual(c1["places"], 0)
        self.assertEqual(c1["places_breakdown"], {"confirmed": 1, "promo_pending": 1, "awaiting": 1})

    def test_notes_and_read_back_cosmetics(self):
        # Load In feeds a FULL Load Out: nothing is carried, so no "carried
        # from" note; the Show has 0 places and more experienced than the mix
        # wants, so no "already has" note; the boss sentence isn't first.
        held = [{"id": 900 + k, "status": "confirmed"} for k in range(2)]
        calls = [call(1, "Crew Boss", "2027-03-10T07:00:00", "2027-03-10T12:00:00", 1),
                 call(2, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 2, feeds=[4]),
                 call(3, "Show", "2027-03-10T13:00:00", "2027-03-10T17:00:00", 2, committed=2, crew=held),
                 call(4, "Load Out", "2027-03-10T18:00:00", "2027-03-10T22:00:00", 1, committed=1)]
        f = Fake(calls, [person(1, "T Boss", all_h=3000, boss=2), person(2, "U Crew")], boss_calls=[1])
        r, err = f.run([1, 2, 3, 4], {"objective": "mix", "new_pct": 80})
        self.assertIsNone(err)
        p = r["plan"]
        notes = [n for c in p["calls"] for n in c["notes"]]
        self.assertFalse(any("carried from" in n for n in notes), notes)
        self.assertFalse(any("already has" in n for n in notes), notes)
        self.assertTrue(p["read_back"].startswith("80% new"), p["read_back"])
        self.assertIn("Crew Boss call #1", p["read_back"])
        load_in = next(c for c in p["calls"] if c["call_id"] == 2)
        self.assertTrue(any("2 places left empty — Load Out is full" in n for n in load_in["notes"]),
                        load_in["notes"])
        self.assertFalse(any("not enough available crew" in n for n in load_in["notes"]))

    def goat_fake(self):
        held = [{"id": 900, "status": "sent"}]
        calls = [call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 1, committed=1, crew=held),
                 call(2, "Show", "2027-03-10T13:00:00", "2027-03-10T17:00:00", 2, feeds=[3]),
                 call(3, "Load Out", "2027-03-10T18:00:00", "2027-03-10T22:00:00", 3,
                      committed=1, crew=[{"id": 901, "status": "sent"}])]
        return Fake(calls, [person(1, "V"), person(2, "W")])

    def goat(self, f, ti, read_back_id=None, assess=None, last_user="__split__"):
        """last_user: the operator's latest message; by default they typed the split."""
        if last_user == "__split__":
            last_user = ti.get("split")
        with mock.patch.object(A, "_finder_assess", assess or f.assess), \
             mock.patch.object(A, "fetch_booking_bulk", f.booking), \
             mock.patch.object(A, "ss_list_crew_experience", f.experience), \
             mock.patch.object(A, "scrape_call_details", f.scrape), \
             mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None), \
             mock.patch.object(A, "load_induction_catalogue", lambda ss=None: None), \
             mock.patch.object(A, "_plan_call_types", f.call_types):
            return A._goat_plan_staffing(object(), ti, read_back_id, last_user)

    def test_goat_read_back_is_code_built(self):
        f = self.goat_fake()
        ti = {"mode": "read_back", "booking_id": 700, "all_calls": True, "objective": "mix",
              "split": "80/20 new to old"}
        res = self.goat(f, ti, assess=mock.Mock(side_effect=AssertionError("read-back must not search")))
        text = res["plan_read_back"]["text"]
        self.assertIn("#2 Show", text)
        self.assertIn(": 2 places", text)
        self.assertIn("#3 Load Out", text)
        self.assertIn("2 places (1 held by offers awaiting reply) — carried from Show", text)
        self.assertIn("Not planned: #1 Load In: 0 places \u2014 1 offer awaiting reply.", text)
        self.assertNotIn("Total", text)
        self.assertTrue(text.endswith("Build this plan?"))
        self.assertTrue(text.split("\n")[1].startswith("80% new"))

    def test_goat_plan_needs_the_read_back(self):
        f = self.goat_fake()
        ti = {"mode": "read_back", "booking_id": 700, "all_calls": True, "objective": "rating"}
        rid = self.goat(f, ti)["plan_read_back"]["id"]
        no_search = mock.Mock(side_effect=AssertionError("must not plan"))
        plan_ti = dict(ti, mode="plan")
        # no read-back shown, or a different one: nothing planned, read back again
        for bad in (None, "0000"):
            res = self.goat(f, plan_ti, bad, assess=no_search)
            self.assertTrue(res["read_back_shown"])
            self.assertEqual(res["plan_read_back"]["id"], rid)
            self.assertIn("Nothing was planned", res["note"])
        # a changed spec doesn't match
        res = self.goat(f, dict(plan_ti, objective="experience"), rid, assess=no_search)
        self.assertTrue(res["read_back_shown"])
        # the same calls in another order match; all_calls's read-back has a
        # "Not planned" line, so it is a different read-back from call_ids
        by_ids = {"mode": "read_back", "booking_id": 700, "call_ids": [2, 3], "objective": "rating"}
        rid2 = self.goat(f, by_ids)["plan_read_back"]["id"]
        self.assertNotEqual(rid2, rid)
        res = self.goat(f, dict(by_ids, mode="plan", call_ids=[3, 2]), rid2)
        self.assertEqual([c["call_id"] for c in res["calls"]], [2, 3])
        self.assertEqual(res["calls"][0]["places_text"], "2 places")
        self.assertEqual([k["also_on"] for k in res["calls"][0]["picks"]],
                         [["#3 Load Out"], ["#3 Load Out"]])       # names, for "also on"
        # all_calls: the 0-place call is reported, not planned
        res = self.goat(f, plan_ti, rid)
        self.assertEqual(res["not_planned"],
                         ["#1 Load In: 0 places \u2014 1 offer awaiting reply, not planned"])
        self.assertEqual(res["open_in_finder"]["call_ids"], [2, 3])

    def test_goat_figures_changed_reads_back_again(self):
        f = self.goat_fake()
        ti = {"mode": "read_back", "booking_id": 700, "call_ids": [2, 3], "objective": "rating"}
        rid = self.goat(f, ti)["plan_read_back"]["id"]
        f.calls[1]["committed"] = 1                      # someone took a Show place meanwhile
        res = self.goat(f, dict(ti, mode="plan"), rid,
                        assess=mock.Mock(side_effect=AssertionError("must not plan")))
        self.assertTrue(res["read_back_shown"])
        self.assertIn("1 place", res["plan_read_back"]["text"])
        self.assertIn("changed", res["note"])

    def test_feeder_places_reserved_on_the_fed_call(self):
        # Re-test 23 Oct, booking 11831: Show + Load Out runs took every Load
        # Out place, so the Load In (which locked-feeds the Load Out) got 0 of
        # 4 "not enough available crew" with plenty available. The Load Out's
        # places are held for the Load In's carry: Load In 4 carried into the
        # Load Out, plus 1 more on the Load Out — every place filled.
        calls = [call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 4, feeds=[3]),
                 call(2, "Show",    "2027-03-10T10:00:00", "2027-03-10T16:00:00", 4),
                 call(3, "Load Out", "2027-03-10T18:00:00", "2027-03-10T22:00:00", 5)]
        people = ([person(100 + k, "A%02d Showrunner" % k, rating=5, free=[2, 3]) for k in range(8)]
                  + [person(200 + k, "B%02d Loader" % k, rating=3) for k in range(8)])
        f = Fake(calls, people)
        r, err = f.run([1, 2, 3])
        self.assertIsNone(err)
        p = r["plan"]
        got = {c["call_name"]: (len(c["picks"]), c["places"]) for c in p["calls"]}
        self.assertEqual(got, {"Load In": (4, 4), "Show": (4, 4), "Load Out": (5, 5)})
        self.assertEqual(p["summary"]["places_filled"], p["summary"]["places_total"])
        for name in picks(p, 1):
            self.assertIn(name, picks(p, 3))              # all four carried
        self.assertFalse(any("left empty" in n for c in p["calls"] for n in c["notes"]))

    def test_reserve_released_when_feeder_cant_fill(self):
        # Nobody can work the Load In: its held places go back to the Load
        # Out once it has been filled, and the note says why it's empty.
        calls = [call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 2, feeds=[3]),
                 call(3, "Load Out", "2027-03-10T18:00:00", "2027-03-10T22:00:00", 3)]
        f = Fake(calls, [person(1, "C One", free=[3]), person(2, "D Two", free=[3]),
                         person(3, "E Three", free=[3])])
        r, err = f.run([1, 3])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(len(picks(p, 3)), 3)
        load_in = p["calls"][0]
        self.assertEqual(load_in["unfilled"], 2)
        self.assertIn("2 places left empty — not enough available crew.", load_in["notes"])

    def olack(self, load_in, show, show_places):
        """#11831's shape: O'Lack (9734, best rated) holds an offer awaiting
        reply on the Load In; two plain crew are free for everything."""
        calls = [call(37895, "Load In", load_in[0], load_in[1], 2, committed=1,
                      crew=[{"id": 9734, "status": "sent"}]),
                 call(37896, "Show Call", show[0], show[1], show_places)]
        people = [person(9734, "Mike O'Lack", rating=5),
                  person(201, "P Plain", rating=3), person(202, "Q Plain", rating=3)]
        return Fake(calls, people)

    def stage_a(self, f, cid):
        with mock.patch.object(A, "_finder_assess", f.assess), \
             mock.patch.object(A, "fetch_booking_bulk", f.booking), \
             mock.patch.object(A, "ss_list_crew_experience", f.experience), \
             mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None), \
             mock.patch.object(A, "_plan_call_types", f.call_types):
            r, err = A.build_staffing_plan(object(), dict(f.body([cid]), plan={"objective": "rating"}))
        self.assertIsNone(err)
        return r["plan"]

    def test_offer_on_booking_call_overlap_blocks(self):
        # Re-test 23 Oct: offer on Load In 13-17, Show 12-21. The booking
        # search drops that offer (Load In is a searched call), so he read as
        # tier 0 with no flags and was planned on the Show.
        f = self.olack(("2027-03-10T13:00:00", "2027-03-10T17:00:00"),
                       ("2027-03-10T12:00:00", "2027-03-10T21:00:00"), 3)
        a = self.stage_a(f, 37896)                          # single-call: unchanged
        mine = next(p for p in a["picks"] if p["user_id"] == 9734)
        self.assertEqual(mine["tier"], 2)
        self.assertTrue(mine["flags"][0].startswith("\u2709 Rule 1 - Overlap"), mine["flags"])
        self.assertEqual([p["user_id"] for p in a["picks"]][-1], 9734)   # picked last
        r, err = f.run([37895, 37896])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertNotIn("Mike O'Lack", picks(p, 37895) + picks(p, 37896))
        show = next(c for c in p["calls"] if c["call_id"] == 37896)
        self.assertEqual(show["unfilled"], 1)
        self.assertTrue(any("none fits beside" in n for n in show["notes"]), show["notes"])

    def test_offer_on_booking_call_same_tier_and_flag(self):
        # A Rule 2 offer clash (9 h Load In offer ending 17:00, Show at 19:00)
        # leaves him pickable: the booking plan gives the same tier and flag
        # as the single-call plan.
        f = self.olack(("2027-03-10T08:00:00", "2027-03-10T17:00:00"),
                       ("2027-03-10T19:00:00", "2027-03-10T23:00:00"), 3)
        single = next(p for p in self.stage_a(f, 37896)["picks"] if p["user_id"] == 9734)
        r, err = f.run([37895, 37896])
        self.assertIsNone(err)
        show = next(c for c in r["plan"]["calls"] if c["call_id"] == 37896)
        booking = next(p for p in show["picks"] if p["user_id"] == 9734)
        self.assertEqual(booking["tier"], 2)
        self.assertEqual((booking["tier"], booking["flags"], booking["why"]),
                         (single["tier"], single["flags"], single["why"]))
        self.assertTrue(booking["flags"][0].startswith("\u2709 Rule 2"), booking["flags"])
        self.assertEqual(show["picks"][-1]["user_id"], 9734)              # tier 2 goes last

    def test_split_parsed_in_code(self):
        for text, want in [("80/20 new to old", 80), ("20/80 old to new", 80), ("80% new", 80),
                           ("20% experienced", 80), ("plan booking 11831, all calls, 20/80 old to new", 80),
                           ("20/80 new to old", 20), ("80% new, 20% experienced", 80), ("30 percent old", 70)]:
            self.assertEqual(A._plan_parse_split(text), (want, None), text)
        for text in ("80/20", "60/30 new to old", "80/20 new to new", "", "mostly new"):
            pct, err = A._plan_parse_split(text)
            self.assertIsNone(pct, text)
            self.assertTrue(err, text)

    def test_goat_split_overrides_new_pct(self):
        f = self.goat_fake()
        base = {"mode": "read_back", "booking_id": 700, "call_ids": [2, 3], "objective": "mix"}
        a = self.goat(f, dict(base, split="20/80 old to new", new_pct=20))     # model's swap ignored
        self.assertIn("80% new (under 144 h), 20% experienced", a["plan_read_back"]["text"])
        b = self.goat(f, dict(base, split="80% new"))
        self.assertEqual(a["plan_read_back"]["id"], b["plan_read_back"]["id"])  # same plan, same id
        c = self.goat(f, dict(base, objective="rating", split="20% experienced"))
        self.assertIn("80% new", c["plan_read_back"]["text"])                  # a split is a mix
        self.assertIn("ask", self.goat(f, base))                               # mix needs the words
        self.assertIn("ask", self.goat(f, dict(base, split="80/20")))          # sides unnamed

    def test_split_must_be_typed_by_the_operator(self):
        f = self.goat_fake()
        base = {"mode": "read_back", "booking_id": 700, "call_ids": [2, 3], "objective": "mix"}
        nothing = mock.Mock(side_effect=AssertionError("no read-back, no plan"))
        q = "Which side is new in 80/20 \u2014 80% new and 20% experienced, or 20% new and 80% experienced?"
        # bare "80/20", as typed: refused with the fixed question, no card
        res = self.goat(f, dict(base, split="80/20"), assess=nothing, last_user="plan call 37893, 80/20")
        self.assertEqual(res["ask"], q)
        self.assertNotIn("plan_read_back", res)
        # the model added "new to old" to the operator's "80/20": refused the same way
        with mock.patch.object(A, "_goat_plan_read_back", nothing):
            res = self.goat(f, dict(base, split="80/20 new to old"), last_user="plan call 37893, 80/20")
        self.assertEqual(res["ask"], q)
        self.assertNotIn("plan_read_back", res)
        # the operator's own words, case and spacing aside: accepted
        res = self.goat(f, dict(base, split="80/20 new to old"),
                        last_user="Plan booking 700, calls 2 and 3, 80 / 20 New to Old")
        rid = res["plan_read_back"]["id"]
        self.assertIn("80% new (under 144 h), 20% experienced", res["plan_read_back"]["text"])
        # their yes: the split isn't in "yes", but the plan matches that read-back
        res = self.goat(f, dict(base, mode="plan", split="80/20 new to old"), rid, last_user="yes")
        self.assertEqual([c["call_id"] for c in res["calls"]], [2, 3])
        # a plan call with no matching read-back still needs the operator's words
        res = self.goat(f, dict(base, mode="plan", split="80/20 new to old"), "stale", last_user="yes",
                        assess=nothing)
        self.assertEqual(res["ask"], q)

    def test_goat_no_cancels(self):
        f = self.goat_fake()
        ti = {"mode": "read_back", "booking_id": 700, "call_ids": [2, 3], "objective": "rating"}
        boom = mock.Mock(side_effect=AssertionError("must not read back or plan"))
        with mock.patch.object(A, "_goat_plan_read_back", boom):
            for text in ("no", "No.", "cancel", "never mind"):
                res = A._goat_plan_staffing(object(), ti, None, text)
                self.assertTrue(res["cancelled"], text)
        res = self.goat(f, ti)                                                 # a change isn't a cancel
        self.assertIn("plan_read_back", res)
        with mock.patch.object(A, "fetch_booking_bulk", f.booking), \
             mock.patch.object(A, "ss_list_crew_experience", f.experience), \
             mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None), \
             mock.patch.object(A, "_plan_call_types", f.call_types):
            res = A._goat_plan_staffing(object(), ti, None, "no, 70% new instead")
        self.assertIn("plan_read_back", res)


class PlanRouteTests(unittest.TestCase):
    """/api/staffing/plan through the Flask test client (§2.4, B9, B16)."""

    def setUp(self):
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "plan-b-sid"
        self.patches = [mock.patch.object(A, "get_ss_session", lambda: object()),
                        mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None),
                        mock.patch.object(A, "_plan_call_types", lambda bid, calls: ({}, None))]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()
        A._ss_identity.pop("plan-b-sid", None)

    def as_cohort(self, cohort):
        A._ss_identity["plan-b-sid"] = {"cohort": cohort, "user_id": "555"}

    def body(self):
        f = Fake([call(1, "Load In", "2027-03-10T08:00:00", "2027-03-10T12:00:00", 1),
                  call(2, "Show", "2027-03-10T13:00:00", "2027-03-10T17:00:00", 1)], [person(1, "K")])
        b = f.body([1, 2])
        b["calls"][1]["booking_id"] = 701
        return f, b

    def test_b9_two_bookings_400(self):
        self.as_cohort("admin")
        f, body = self.body()
        with mock.patch.object(A, "_finder_assess", side_effect=AssertionError("no search")):
            r = self.client.post("/api/staffing/plan", json=body)
        self.assertEqual(r.status_code, 400)
        self.assertEqual(r.get_json(), {"error": "Plan one booking at a time."})

    def test_one_booking_goes_to_booking_planner(self):
        self.as_cohort("admin")
        f, body = self.body()
        body["calls"][1]["booking_id"] = 700
        with mock.patch.object(A, "_finder_assess", f.assess), \
             mock.patch.object(A, "fetch_booking_bulk", f.booking), \
             mock.patch.object(A, "ss_list_crew_experience", f.experience):
            r = self.client.post("/api/staffing/plan", json=body)
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertEqual(r.get_json()["plan"]["kind"], "booking")

    def goat_stream(self, plan_result, model_text):
        """POST /api/goat with a fake model: one plan_staffing call, then text."""
        import sys, types
        tool = types.SimpleNamespace(type="tool_use", name="plan_staffing", id="tu1",
                                     input={"mode": "read_back", "booking_id": 700, "all_calls": True,
                                            "objective": "mix", "split": "20/80 old to new"})
        text = types.SimpleNamespace(type="text", text=model_text)
        replies = [types.SimpleNamespace(content=[tool]), types.SimpleNamespace(content=[text])]

        class Client(object):
            def __init__(self, **kw):
                self.messages = types.SimpleNamespace(create=lambda **kw: replies.pop(0))
        fake = types.ModuleType("anthropic")
        fake.Anthropic = Client
        with mock.patch.dict(sys.modules, {"anthropic": fake}), \
             mock.patch.object(A, "_anthropic_api_key", lambda: "k"), \
             mock.patch.object(A, "_goat_plan_staffing", lambda *a: plan_result):
            r = self.client.post("/api/goat", json={"messages": [{"role": "user", "content": "plan 11831"}]})
            body = r.get_data(as_text=True)
        return [json.loads(l[6:]) for l in body.splitlines() if l.startswith("data: ")]

    def test_read_back_turn_shows_no_model_text(self):
        self.as_cohort("admin")
        ev = self.goat_stream({"read_back_shown": True, "plan_read_back": {"id": "x", "text": "RB"}},
                              "See the card above — ready to build?")
        kinds = [e["type"] for e in ev]
        self.assertNotIn("text", kinds)
        self.assertIn("plan_read_back", kinds)
        hist = next(e["text"] for e in ev if e["type"] == "history")
        self.assertIn('"split": "20/80 old to new"', hist)
        self.assertNotIn("above", hist)
        self.assertEqual(kinds[-1], "done")

    def test_split_question_replaces_model_text(self):
        self.as_cohort("admin")
        q = "Which side is new in 80/20 \u2014 80% new and 20% experienced, or 20% new and 80% experienced?"
        ev = self.goat_stream({"ask": q, "note": "n"}, "Sure — 80% new it is!")
        self.assertEqual([e for e in ev if e["type"] == "text"], [{"type": "text", "text": q}])
        self.assertNotIn("plan_read_back", [e["type"] for e in ev])
        self.assertEqual(ev[-1]["type"], "done")

    def test_plan_turn_keeps_model_text(self):
        self.as_cohort("admin")
        ev = self.goat_stream({"calls": [], "open_in_finder": {"call_ids": [1]}}, "Here's the plan.")
        self.assertEqual("".join(e["text"] for e in ev if e["type"] == "text"), "Here's the plan.")
        self.assertNotIn("history", [e["type"] for e in ev])

    def test_b16_operations_403(self):
        self.as_cohort("operations")
        f, body = self.body()
        r = self.client.post("/api/staffing/plan", json=body)
        self.assertEqual(r.status_code, 403)


if __name__ == "__main__":
    unittest.main()
