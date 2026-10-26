#!/usr/bin/env python3
"""Tests for staffing plans stage C: role-aware plans (BRIEF-staffing-plans-c
§5, C1-C10, B3).

The stage B fakes (test_booking_plan.Fake) with what stage C reads added:
each call's Notes, its PHP call_depts / call_specialist, each person's
licences, groups and dept_3y, and the Call Type read. All crew are invented
(the repo is public). B1 is the golden test:

    python3 tests/fixtures/plan_golden/run_golden.py
    python3 test_staffing_plans_c.py
"""
import json
import re
import unittest
from unittest import mock

import app as A
from test_booking_plan import Fake, call, picks, VENUE

VALID   = "2030-01-01"
EXPIRED = "2020-01-01"


def crew(uid, name, rating=4, new=False, all_h=500.0, lic=(), groups=(), dept=None, free=None):
    """lic: [(code, expiry)]."""
    return {"uid": uid, "name": name, "rating": rating, "new": new, "all_h": all_h,
            "free": None if free is None else set(str(f) for f in free),
            "warn": {}, "shifts": [], "boss": 0,
            "lic": [{"code": c, "expiry": x} for c, x in lic],
            "groups": list(groups), "dept": dept}


class FakeC(Fake):
    """calls carry "notes", "depts", "specialist"; people carry lic, groups,
    dept. old_php: the 5.87.0 list-crew-experience.php reply (no dept_3y,
    call_depts or call_specialist, C9)."""

    def __init__(self, calls, people, boss_calls=(), old_php=False, call_types=None, ct_err=None,
                 live_groups="people"):
        Fake.__init__(self, calls, people, boss_calls)
        self.old_php = old_php
        # list-groups.php: by default every group someone in `people` is in;
        # a list to say exactly which exist; None for "couldn't be read".
        self.live = live_groups
        self.types = call_types or {}
        self.ct_err = ct_err

    def booking(self, ss, bid):
        data, err = Fake.booking(self, ss, bid)
        for bc, c in zip(data["calls"], self.calls):
            bc["notes"] = c.get("notes", "")
        return data, err

    def assess(self, ss, data):
        out, err = Fake.assess(self, ss, data)
        by = dict((str(p["uid"]), p) for p in self.people)
        for e in out["available"]:
            p = by[e["id"]]
            e["licences"] = [dict(r) for r in p.get("lic") or []]
            e["groups"] = list(p.get("groups") or [])
        return out, err

    def experience(self, ss, ids, cid, force=False):
        out, err = Fake.experience(self, ss, ids, cid, force)
        c = next(c for c in self.calls if c["cid"] == str(cid))
        if not self.old_php:
            for p in self.people:
                out["users"][str(p["uid"])]["dept_3y"] = dict(p.get("dept") or {})
            out["call"]["call_depts"] = list(c.get("depts") or [])
            out["call"]["call_specialist"] = list(c.get("specialist") or [])
        return out, err

    def call_types(self, bid, calls):
        if self.ct_err:
            return {}, self.ct_err
        return dict((str(cid), self.types.get(str(cid))) for cid, _n in calls), None

    def live_groups(self, ss):
        if self.live is None:
            return None
        names = (set(g for p in self.people for g in p.get("groups") or [])
                 if self.live == "people" else set(self.live))
        return set(str(n).lower() for n in names)

    def patches(self):
        return [mock.patch.object(A, "_plan_live_groups", self.live_groups),
                mock.patch.object(A, "_finder_assess", self.assess),
                mock.patch.object(A, "fetch_booking_bulk", self.booking),
                mock.patch.object(A, "ss_list_crew_experience", self.experience),
                mock.patch.object(A, "scrape_call_details", self.scrape),
                mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None),
                mock.patch.object(A, "load_induction_catalogue", lambda ss=None: None),
                mock.patch.object(A, "_plan_call_types", self.call_types)]

    def _with(self, fn):
        ps = self.patches()
        for p in ps:
            p.start()
        try:
            return fn()
        finally:
            for p in ps:
                p.stop()

    def run(self, cids, plan=None):
        return self._with(lambda: A.build_booking_plan(object(), self.body(cids, plan)))

    def single(self, cid, plan=None):
        return self._with(lambda: A.build_staffing_plan(object(), self.body([cid], plan)))

    def goat(self, ti, read_back_id=None, answers=None, last_user="yes", split_user=None):
        return self._with(lambda: A._goat_plan_staffing(object(), ti, read_back_id, last_user, answers,
                                                        split_user))


def one(cid, name, places, notes="", depts=(), specialist=(), start="2027-03-10T09:00:00",
        end="2027-03-10T13:00:00"):
    c = call(cid, name, start, end, places)
    c.update(notes=notes, depts=list(depts), specialist=list(specialist))
    return c


def names(plan):
    return [p["name"] for p in plan["picks"]]


def req_of(plan, cid=None):
    if cid is None:
        return plan["requirements"]
    return next(c for c in plan["calls"] if c["call_id"] == int(cid))["requirements"]


class RequirementTests(unittest.TestCase):

    def test_c1_notes_forklift_applied(self):
        f = FakeC([one(1, "Fork", 2, notes="Forklift")],
                  [crew(10, "A Holder", rating=3, lic=[("LF", VALID)]),
                   crew(11, "B NoTicket", rating=5),
                   crew(12, "C Expired", rating=5, lic=[("LF", EXPIRED)])])
        r, err = f.single(1)
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(names(p), ["A Holder"])
        req = p["requirements"]
        self.assertEqual([(e["code"], e["scope"], e["source"]) for e in req["licences"]],
                         [("LF", "all", "notes")])
        self.assertIn("Requires Forklift truck (from Notes)", req["lines"])
        self.assertEqual(req["questions"], [])               # the name's clue is already required
        self.assertIn("1 place left empty — not enough available crew holding Forklift truck.", p["notes"])
        self.assertEqual(req["review"], ["Requires Forklift truck · 1 of 2 places held"])

    def test_c2_fork_call_no_notes_asks(self):
        people = [crew(10, "A Holder", rating=3, lic=[("LF", VALID)]), crew(11, "B Plain", rating=5)]
        f = FakeC([one(1, "Fork", 1)], people)
        r, err = f.single(1)
        self.assertIsNone(err)
        self.assertTrue(r["needs_answers"])
        self.assertNotIn("plan", r)
        q = r["requirements"]["1"]["questions"]
        self.assertEqual([x["id"] for x in q], ["1:LF"])
        self.assertEqual([o["value"] for o in q[0]["options"]], ["yes", "no"])
        self.assertEqual(q[0]["counts"], {"LF": 1})          # pre-check before the build
        yes, _ = f.single(1, {"objective": "rating", "requirement_answers": {"1:LF": "yes"}})
        self.assertEqual(names(yes["plan"]), ["A Holder"])
        self.assertEqual(yes["plan"]["requirements"]["licences"][0]["source"], "answer")
        no, _ = f.single(1, {"objective": "rating", "requirement_answers": {"1:LF": "no"}})
        self.assertEqual(names(no["plan"]), ["B Plain"])
        self.assertEqual(no["plan"]["requirements"]["licences"], [])

    def test_c2_route_returns_needs_answers(self):
        f = FakeC([one(1, "Fork", 1)], [crew(10, "A", lic=[("LF", VALID)])])
        client = A.app.test_client()
        with client.session_transaction() as s:
            s["sid"] = "plan-c-sid"
        A._ss_identity["plan-c-sid"] = {"cohort": "admin", "user_id": "555"}
        try:
            with mock.patch.object(A, "get_ss_session", lambda: object()):
                rv = f._with(lambda: client.post("/api/staffing/plan", json=f.body([1])))
        finally:
            A._ss_identity.pop("plan-c-sid", None)
        self.assertEqual(rv.status_code, 200)
        j = rv.get_json()
        self.assertTrue(j["needs_answers"])
        self.assertNotIn("plan", j)
        self.assertEqual(j["requirements"]["1"]["open"], 1)

    def test_c3_count_from_notes(self):
        people = [crew(10, "A Holder", rating=3, lic=[("LF", VALID)])] + \
                 [crew(20 + k, "G%d General" % k, rating=4) for k in range(4)]
        f = FakeC([one(1, "Show", 4, notes="2x Forklift")], people)
        r, err = f.single(1)
        self.assertIsNone(err)
        p = r["plan"]
        req = p["requirements"]
        self.assertEqual([(e["code"], e["scope"], e["n"]) for e in req["licences"]], [("LF", "min", 2)])
        self.assertEqual([x["text"] for x in req["precheck"]],
                         ["Requires 2 with Forklift truck (from Notes): 1 available for 2 places"])
        self.assertIn("Asked for 2 with Forklift truck, found 1", p["notes"])
        self.assertEqual(len(p["picks"]), 4)
        self.assertEqual(p["picks"][0]["name"], "A Holder")  # minimums before the fill
        self.assertEqual(sorted(names(p)[1:]), ["G0 General", "G1 General", "G2 General"])

    def test_count_forms(self):
        P = {"cat": A.licence_catalogue(), "licence_mins": [], "required_licences": []}
        with mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None):
            P["cat"] = A.licence_catalogue()
            for text in ("2x Forklift", "2 x LF", "Forklift x2", "2 forklift", "need 2 forklifts"):
                req = A._plan_call_requirements("1", "Show", text, None, None, {}, P, {})
                self.assertEqual([(e["code"], e["scope"], e["n"]) for e in req["licences"]],
                                 [("LF", "min", 2)], text)
            req = A._plan_call_requirements("1", "Show", "Forklift on site", None, None, {}, P, {})
            self.assertEqual([(e["scope"], e["n"]) for e in req["licences"]], [("all", None)])
            # A code that is also an English word, matched by the code test alone: a question.
            req = A._plan_call_requirements("1", "Show", "ALL CREW TO WEAR BLACK", None, None, {}, P, {})
            self.assertEqual(req["licences"], [])
            self.assertEqual([q["id"] for q in req["questions"]], ["1:TO"])
            # First Aid from the Notes is always a question, by name, keyword or code.
            for text in ("First aid kit on site", "FA required", "HLTAID011 cert", "2x first aid"):
                req = A._plan_call_requirements("1", "Show", text, None, None, {}, P, {})
                self.assertEqual(req["licences"], [], text)
                self.assertEqual([(q["id"], [o["value"] for o in q["options"]]) for q in req["questions"]],
                                 [("1:FA", ["yes", "no"])], text)
            req = A._plan_call_requirements("1", "Show", "2x first aid", None, None, {}, P, {"1:FA": "yes"})
            self.assertEqual([(e["code"], e["scope"], e["n"], e["source"]) for e in req["licences"]],
                             [("FA", "min", 2, "answer")])

    def test_c4_department_history_first(self):
        people = [crew(10, "A New", new=True, rating=4),
                  crew(11, "B New LX", new=True, rating=4, dept={"LX": 5}),
                  crew(12, "C Exp", rating=4),
                  crew(13, "D Exp LX", rating=4, dept={"LX": 9, "VX": 2})]
        f = FakeC([one(1, "LX Load In", 2, depts=["LX"])], people)
        r, err = f.single(1, {"objective": "mix", "new_pct": 50})
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(sorted(names(p)), ["B New LX", "D Exp LX"])
        why = dict((x["name"], x["why"]) for x in p["picks"])
        self.assertIn("LX ×5 in 3 years", why["B New LX"])
        self.assertNotIn("VX", why["D Exp LX"])               # the call's tags only
        self.assertEqual(p["requirements"]["review"], ["Prefers LX experience"])
        # No tag on the call: plain rating order, no history in `why` (the
        # name breaks the tie, as in stage A).
        f2 = FakeC([one(1, "Show", 2)], people)
        r2, _ = f2.single(1, {"objective": "mix", "new_pct": 50})
        self.assertEqual(sorted(names(r2["plan"])), ["A New", "C Exp"])

    def test_c5_wardrobe_only_held_back(self):
        w = crew(30, "W Wardrobe", rating=5, groups=["Wardrobe"])
        f = FakeC([one(1, "Load In", 1)], [w, crew(31, "G General", rating=3, groups=["Audio"])])
        r, _ = f.single(1)
        self.assertEqual(names(r["plan"]), ["G General"])
        f = FakeC([one(1, "Load In", 2)], [w, crew(31, "G General", rating=3)])
        r, _ = f.single(1)
        p = r["plan"]
        self.assertEqual(names(p), ["G General", "W Wardrobe"])
        self.assertIn("Used 1 Wardrobe/Seamstress crew: nobody else available", p["notes"])
        self.assertFalse(any("left empty" in n for n in p["notes"]), p["notes"])
        # Wardrobe AND another group: not specialist-only, picked as usual.
        both = crew(32, "X Both", rating=5, groups=["Wardrobe", "Lights"])
        r, _ = FakeC([one(1, "Load In", 1)], [both, crew(31, "G General", rating=3)]).single(1)
        self.assertEqual(names(r["plan"]), ["X Both"])

    def test_c5_booking_second_pass(self):
        calls = [one(1, "Load In", 2, start="2027-03-10T08:00:00", end="2027-03-10T12:00:00"),
                 one(2, "Show", 1, start="2027-03-10T13:00:00", end="2027-03-10T17:00:00")]
        f = FakeC(calls, [crew(30, "W Wardrobe", rating=5, groups=["Wardrobe"]),
                          crew(31, "G General", rating=3)])
        r, err = f.run([1, 2])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(picks(p, 2), ["G General"])
        self.assertEqual(sorted(picks(p, 1)), ["G General", "W Wardrobe"])
        load_in = next(c for c in p["calls"] if c["call_id"] == 1)
        self.assertIn("Used 1 Wardrobe/Seamstress crew: nobody else available", load_in["notes"])

    def test_d45_skill_groups_only(self):
        gen = crew(31, "G General", rating=3)
        cases = [(["Wardrobe", "Own Car", "MCG"], ["G General"]),     # Wardrobe-only: held back
                 (["wardrobe", "PT Only", "Fork", "PhD."], ["G General"]),
                 (["Wardrobe", "Lights"], ["W Person"]),               # a second skill: not
                 (["Own Car", "MCEC"], ["W Person"]),                  # no skill at all: not
                 (["Wardrobe", "Some New Group"], ["W Person"])]       # unknown groups are skills
        for groups, want in cases:
            w = crew(30, "W Person", rating=5, groups=groups)
            r, _ = FakeC([one(1, "Load In", 1)], [w, gen]).single(1)
            self.assertEqual(names(r["plan"]), want, groups)
        self.assertEqual(sorted(g for gs in A._PLAN_NON_SKILL_GROUPS.values() for g in gs),
                         sorted(["mcec", "mcg", "mopt", "own car", "pt only", "joat", "phd.",
                                 "ci card", "ewp", "fork", "truck"]))

    def test_d46_no_seamstress_group(self):
        people = [crew(40, "W One", rating=5, groups=["Wardrobe"]), crew(41, "G One", rating=5)]
        live = ["Wardrobe", "Audio", "Lights"]                     # SmartStaff has no Seamstress
        r, err = FakeC([one(1, "Seamstress", 2, specialist=["SEAMSTRESS"])], people, live_groups=live).single(1)
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(names(p), [])                             # never filled from Wardrobe
        self.assertEqual(p["unfilled"], 2)
        msg = "No Seamstress group in SmartStaff: create it and add crew"
        self.assertEqual([n for n in p["notes"] if "left empty" in n or "SmartStaff" in n], [msg])
        self.assertIn(msg, p["requirements"]["lines"])
        # Booking plan: the same.
        calls = [one(1, "Seamstress", 2, specialist=["SEAMSTRESS"]),
                 one(2, "Show", 1, start="2027-03-10T14:00:00", end="2027-03-10T18:00:00")]
        r, err = FakeC(calls, people, live_groups=live).run([1, 2])
        self.assertIsNone(err)
        c1 = next(c for c in r["plan"]["calls"] if c["call_id"] == 1)
        self.assertEqual((c1["picks"], c1["notes"]), ([], [msg]))
        # Both words: Wardrobe still qualifies, and the missing group is said.
        r, _ = FakeC([one(1, "Wardrobe & Seamstress", 2, specialist=["WARDROBE", "SEAMSTRESS"])],
                     people, live_groups=live).single(1)
        self.assertEqual(names(r["plan"]), ["W One"])
        self.assertIn(msg, r["plan"]["requirements"]["lines"])
        self.assertIn("1 place left empty: not enough Wardrobe or Seamstress crew available", r["plan"]["notes"])
        # A Seamstress group created later just works, any case.
        later = people + [crew(42, "S New", groups=["SEAMSTRESS"])]
        r, _ = FakeC([one(1, "Seamstress", 2, specialist=["SEAMSTRESS"])], later,
                     live_groups=live + ["SEAMSTRESS"]).single(1)
        self.assertEqual(names(r["plan"]), ["S New"])
        self.assertNotIn(msg, r["plan"]["requirements"]["lines"])
        # list-groups unreadable: nothing claimed missing, still never back-filled.
        r, _ = FakeC([one(1, "Seamstress", 2, specialist=["SEAMSTRESS"])], people, live_groups=None).single(1)
        self.assertEqual(names(r["plan"]), [])
        self.assertNotIn(msg, r["plan"]["notes"])
        self.assertIn("2 places left empty: not enough Seamstress crew available", r["plan"]["notes"])

    def test_call_type_bundle_with_name_or_notes(self):
        # #11832: Call Type "Fork/Truck/EWP" on a Fork call raised EWP and
        # Truck questions. The name points to one of the bundle's families,
        # so the Call Type asks nothing: only the name's own question.
        bundle = "Fork/Truck/EWP"
        people = [crew(10, "A Fork", lic=[("LF", VALID)])]
        r, _ = FakeC([one(1, "Fork", 1)], people, call_types={"1": bundle}).single(1)
        self.assertEqual([q["id"] for q in r["requirements"]["1"]["questions"]], ["1:LF"])
        # Notes Forklift: required, so no question at all.
        r, _ = FakeC([one(1, "Fork", 1, notes="Forklift")], people, call_types={"1": bundle}).single(1)
        self.assertEqual(r["plan"]["requirements"]["questions"], [])
        # A name with no clue, but Notes on one of the families: no bundle question either.
        r, _ = FakeC([one(1, "Load In", 1, notes="EWP ticket")], people, call_types={"1": bundle}).single(1)
        self.assertEqual([q["id"] for q in r["requirements"]["1"]["questions"]], ["1:EWPSOA/WP"])
        # Both Fork calls of a booking: the name's question each, nothing more.
        calls = [one(1, "Fork", 1), one(2, "Fork", 1, start="2027-03-10T14:00:00", end="2027-03-10T18:00:00")]
        r, _ = FakeC(calls, people, call_types={"1": bundle, "2": bundle}).run([1, 2])
        self.assertTrue(r["needs_answers"])
        self.assertEqual(sorted(q["id"] for c in r["requirements"].values() for q in c["questions"]),
                         ["1:LF", "2:LF"])

    def test_call_type_bundle_alone_one_question(self):
        people = [crew(10, "A Scissor", lic=[("EWPSOA", VALID)]), crew(11, "B Truck", rating=5, lic=[("HR", VALID)]),
                  crew(12, "C None", rating=5)]
        f = FakeC([one(1, "Load In", 1)], people, call_types={"1": "Fork/Truck/EWP"})
        r, _ = f.single(1)
        qs = r["requirements"]["1"]["questions"]
        self.assertEqual(len(qs), 1)
        q = qs[0]
        self.assertEqual(q["id"], "1:type:LF/LR/MR/HR/HC/MC/WP/EWPSOA")
        self.assertEqual([o["value"] for o in q["options"]], ["Forklift", "Truck licence", "EWP", "none"])
        self.assertEqual(q["text"], 'The Call Type "Fork/Truck/EWP" covers several licences: '
                                    'which does this call need: Forklift, Truck licence or EWP?')
        self.assertEqual((q["counts"]["Forklift"], q["counts"]["Truck licence"], q["counts"]["EWP"]), (0, 1, 1))
        r, _ = f.single(1, {"objective": "rating", "requirement_answers": {q["id"]: "EWP"}})
        self.assertEqual(names(r["plan"]), ["A Scissor"])
        self.assertEqual([(e["codes"], e["scope"], e["source"]) for e in r["plan"]["requirements"]["licences"]],
                         [(["EWPSOA", "WP"], "all", "answer")])
        r, _ = f.single(1, {"objective": "rating", "requirement_answers": {q["id"]: "Truck licence"}})
        self.assertEqual(names(r["plan"]), ["B Truck"])                  # HR covers a truck licence
        r, _ = f.single(1, {"objective": "rating", "requirement_answers": {q["id"]: "none"}})
        self.assertEqual(r["plan"]["requirements"]["licences"], [])
        self.assertEqual(names(r["plan"]), ["B Truck"])                  # best rated, no licence rule

    def test_d46_line_while_questions_are_open(self):
        # #37904: the Seamstress call's missing-group line was absent because
        # a Fork call's open question returned before D46 ran.
        msg = "No Seamstress group in SmartStaff: create it and add crew"
        calls = [one(1, "Fork", 1), one(2, "Seamstress", 2, specialist=["SEAMSTRESS"],
                                       start="2027-03-10T14:00:00", end="2027-03-10T18:00:00")]
        f = FakeC(calls, [crew(10, "A", lic=[("LF", VALID)])], live_groups=["Wardrobe"])
        r, _ = f.run([1, 2])
        self.assertTrue(r["needs_answers"])
        self.assertIn(msg, r["requirements"]["2"]["lines"])
        r, _ = FakeC([one(1, "Fork Seamstress", 1, specialist=["SEAMSTRESS"])], [crew(10, "A")],
                     live_groups=["Wardrobe"]).single(1)
        self.assertTrue(r["needs_answers"])
        self.assertIn(msg, r["requirements"]["1"]["lines"])
        # The drawer's read-back card says it too.
        ti = {"mode": "read_back", "booking_id": 700, "call_ids": [1, 2], "objective": "rating"}
        text = f.goat(ti)["plan_read_back"]["text"]
        self.assertIn("    " + msg, text)

    def test_live_groups_logs_every_failure(self):
        class R(object):
            def __init__(self, code, text):
                self.status_code, self.text = code, text

        class SS(object):
            def __init__(self, r):
                self.r = r

            def get(self, *a, **k):
                if isinstance(self.r, Exception):
                    raise self.r
                return self.r
        cases = [(R(403, '{"error":"forbidden"}'), "HTTP 403"), (R(200, "<html>login</html>"), "not JSON"),
                 (R(200, '{"groups": []}'), "no groups"), (IOError("down"), "OSError: down")]
        for r, want in cases:
            with mock.patch("builtins.print") as log:
                self.assertIsNone(A._plan_live_groups(SS(r)))
            self.assertTrue(any(want in str(c) for c in log.call_args_list), (want, log.call_args_list))
        ok = R(200, '{"groups": [{"id": 1, "name": "Wardrobe"}, {"id": 2, "name": "Set&#47;Stg"}]}')
        self.assertEqual(A._plan_live_groups(SS(ok)), {"wardrobe", "set/stg"})

    def test_d47_scarce_holder_goes_to_scope_all_call(self):
        # #11832: the sole LF holder went to #37901 Load In (min 2) and rolled
        # to #37900, leaving #37899 Fork (every place needs LF) empty.
        calls = [one(37899, "Fork", 1, notes="Forklift", start="2027-03-10T09:00:00", end="2027-03-10T13:00:00"),
                 one(37900, "Show", 1, start="2027-03-10T14:00:00", end="2027-03-10T18:00:00"),
                 one(37901, "Load In", 2, notes="2x Forklift", start="2027-03-10T09:00:00", end="2027-03-10T13:00:00")]
        people = [crew(10, "H Holder", rating=3, lic=[("LF", VALID)])] + \
                 [crew(20 + k, "G%d General" % k, rating=5) for k in range(3)]
        r, err = FakeC(calls, people).run([37899, 37900, 37901])
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(picks(p, 37899), ["H Holder"])
        self.assertIn("H Holder", picks(p, 37900))                      # still rolls on at 14:00
        self.assertNotIn("H Holder", picks(p, 37901))
        load_in = next(c for c in p["calls"] if c["call_id"] == 37901)
        self.assertIn("Forklift truck: 1 holder, planned on #37899 Fork and #37900 Show", load_in["notes"])
        self.assertEqual(load_in["requirements"]["review"],
                         ["Requires Forklift truck · 0 of 2 places held · 1 holder planned on #37899, #37900"])
        fork = next(c for c in p["calls"] if c["call_id"] == 37899)
        self.assertEqual(fork["requirements"]["review"], ["Requires Forklift truck · 1 of 1 place held"])
        self.assertFalse(any("holder, planned on" in n for n in fork["notes"]))
        # Ties: the earlier call, then the call id.
        calls2 = [one(2, "Fork B", 1, notes="Forklift", start="2027-03-10T09:00:00", end="2027-03-10T13:00:00"),
                  one(1, "Fork A", 1, notes="Forklift", start="2027-03-10T09:00:00", end="2027-03-10T13:00:00")]
        r, _ = FakeC(calls2, people[:1]).run([1, 2])
        self.assertEqual((picks(r["plan"], 1), picks(r["plan"], 2)), (["H Holder"], []))
        b = next(c for c in r["plan"]["calls"] if c["call_id"] == 2)
        self.assertIn("Forklift truck: 1 holder, planned on #1 Fork A", b["notes"])

    def test_no_found_0_notes_on_emptied_calls(self):
        mix = {"objective": "mix", "new_pct": 50}
        # an overlap empties the second call
        calls = [one(1, "Stage A", 1), one(2, "Stage B", 2)]
        r, _ = FakeC(calls, [crew(10, "A", new=True)]).run([1, 2], mix)
        b = next(c for c in r["plan"]["calls"] if c["call_id"] == 2)
        self.assertEqual(b["picks"], [])
        self.assertFalse(any(n.startswith("Asked for") for n in b["notes"]), b["notes"])
        # a missing group empties a Seamstress call
        r, _ = FakeC([one(1, "Seamstress", 2, specialist=["SEAMSTRESS"])], [crew(10, "A", new=True)],
                     live_groups=["Wardrobe"]).single(1, mix)
        self.assertFalse(any(n.startswith("Asked for") for n in r["plan"]["notes"]), r["plan"]["notes"])
        # a call that got someone keeps its shortfall notes
        r, _ = FakeC([one(1, "Show", 2)], [crew(10, "A", new=True), crew(11, "B", new=True)]).single(1, mix)
        self.assertTrue(any(n.startswith("Asked for 1 experienced, found 0") for n in r["plan"]["notes"]))

    def test_explained_licence_gap_drops_mix_note(self):
        # #37899 / #37900: a partly filled licence call showed the mix's
        # "Asked for 1 new, found 0" beside the licence note that explains it.
        mix = {"objective": "mix", "new_pct": 50}
        people = [crew(10, "H Exp", lic=[("LF", VALID)]), crew(11, "N New", new=True)]
        r, _ = FakeC([one(1, "Fork", 2, notes="Forklift")], people).single(1, mix)
        p = r["plan"]
        self.assertEqual(names(p), ["H Exp"])
        self.assertIn("1 place left empty — not enough available crew holding Forklift truck.", p["notes"])
        self.assertFalse(any(re.match(r"Asked for \d+ (new|experienced), found 0", n) for n in p["notes"]), p["notes"])
        # Booking: the D47 holder note explains it on the call left short.
        calls = [one(1, "Fork", 1, notes="Forklift"),
                 one(2, "Load In", 2, notes="2x Forklift"),
                 one(3, "Show", 1, start="2027-03-10T14:00:00", end="2027-03-10T18:00:00")]
        r, _ = FakeC(calls, [people[0], crew(12, "E Exp")]).run([1, 2, 3], mix)
        c2 = next(c for c in r["plan"]["calls"] if c["call_id"] == 2)
        self.assertEqual([x["name"] for x in c2["picks"]], ["E Exp"])     # partly filled
        self.assertTrue(any(": 1 holder, planned on " in n for n in c2["notes"]), c2["notes"])
        self.assertFalse(any(re.match(r"Asked for \d+ (new|experienced), found 0", n) for n in c2["notes"]), c2["notes"])
        # No licence on the call: the mix note stays (it is the only explanation).
        r, _ = FakeC([one(1, "Show", 2)], [crew(10, "A", new=True), crew(11, "B", new=True)]).single(1, mix)
        self.assertTrue(any(n.startswith("Asked for 1 experienced, found 0") for n in r["plan"]["notes"]))

    def test_carried_note_names_the_source_call(self):
        # #11832: "General is carried from General — crew placed there by the
        # general." Repeated names need the call id and the day.
        calls = [one(39268, "General", 1, start="2026-10-31T08:00:00", end="2026-10-31T12:00:00"),
                 one(39269, "General", 1, start="2026-10-31T14:00:00", end="2026-10-31T18:00:00")]
        calls[0]["feeds"] = ["39269"]
        r, err = FakeC(calls, [crew(10, "A")]).run([39268, 39269])
        self.assertIsNone(err)
        out = next(c for c in r["plan"]["calls"] if c["call_id"] == 39269)
        self.assertTrue(out["carried"])
        self.assertIn("Carried from #39268 General (Sat 31 Oct) — crew placed there by it.", out["notes"])

    def test_missing_group_said_once(self):
        msg = "No Seamstress group in SmartStaff: create it and add crew"
        people = [crew(40, "W One", groups=["Wardrobe"])]

        def said(plan):
            req = plan["requirements"]
            return sum(x.count(msg) for x in [plan["notes"], req["notes"], req["review"]])
        for spec, nm in ((["SEAMSTRESS"], "Seamstress"), (["WARDROBE", "SEAMSTRESS"], "Wardrobe & Seamstress")):
            r, _ = FakeC([one(1, nm, 2, specialist=spec)], people, live_groups=["Wardrobe"]).single(1)
            self.assertEqual(said(r["plan"]), 1, spec)
            calls = [one(1, nm, 2, specialist=spec), one(2, "Show", 1, start="2027-03-10T14:00:00",
                                                        end="2027-03-10T18:00:00")]
            r, _ = FakeC(calls, people + [crew(41, "G")], live_groups=["Wardrobe"]).run([1, 2])
            c1 = next(c for c in r["plan"]["calls"] if c["call_id"] == 1)
            self.assertEqual(sum(x.count(msg) for x in [c1["notes"], c1["requirements"]["notes"],
                                                         c1["requirements"]["review"]]), 1, spec)

    def test_licence_emptied_pool_wording(self):
        f = FakeC([one(1, "Show", 2, notes="Forklift")], [crew(10, "A", new=True), crew(11, "B")])
        r, _ = f.single(1, {"objective": "mix", "new_pct": 50})
        p = r["plan"]
        self.assertFalse(any(n.startswith("Asked for") for n in p["notes"]), p["notes"])
        self.assertEqual(p["notes"], ["2 places left empty — not enough available crew holding Forklift truck."])
        self.assertEqual(p["requirements"]["review"], ["Requires Forklift truck · 0 of 2 places held · none available"])

    def test_c5b_seamstress_call_leaves_places_empty(self):
        people = [crew(40, "S One", groups=["Seamstress"]), crew(41, "S Two", groups=["seamstress"]),
                  crew(42, "G One", rating=5), crew(43, "G Two", rating=5), crew(44, "W One", groups=["Wardrobe"])]
        f = FakeC([one(1, "Seamstress", 3, specialist=["SEAMSTRESS"])], people)
        r, err = f.single(1)
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(sorted(names(p)), ["S One", "S Two"])
        self.assertEqual(p["unfilled"], 1)
        self.assertIn("1 place left empty: not enough Seamstress crew available", p["notes"])
        self.assertIn("Seamstress crew only", p["requirements"]["lines"])
        # Both words: either group qualifies (D44).
        f = FakeC([one(1, "Wardrobe & Seamstress", 3, specialist=["WARDROBE", "SEAMSTRESS"])], people)
        r, _ = f.single(1)
        self.assertEqual(sorted(names(r["plan"])), ["S One", "S Two", "W One"])
        # In a booking plan too, never back-filled.
        calls = [one(1, "Seamstress", 3, specialist=["SEAMSTRESS"]),
                 one(2, "Show", 1, start="2027-03-10T14:00:00", end="2027-03-10T18:00:00")]
        r, err = FakeC(calls, people).run([1, 2])
        self.assertIsNone(err)
        c1 = next(c for c in r["plan"]["calls"] if c["call_id"] == 1)
        self.assertEqual(sorted(x["name"] for x in c1["picks"]), ["S One", "S Two"])
        self.assertIn("1 place left empty: not enough Seamstress crew available", c1["notes"])

    def test_c6_final_overlap_check(self):
        calls = [one(1, "Load In", 1), one(2, "Fork", 1, notes="Forklift")]
        f = FakeC(calls, [crew(10, "A Both", lic=[("LF", VALID)])])
        # Force the run logic to propose one person on both 09:00 calls: the
        # pair and Rule 4 tests say yes. The final check uses Rule 1 itself.
        with mock.patch.object(A, "_plan_pair_ok", lambda a, b: True), \
             mock.patch.object(A, "_plan_r4_ok", lambda base, wins: True), \
             mock.patch("builtins.print") as log:
            r, err = f.run([1, 2])
        self.assertTrue(any("D40 fired" in str(c) for c in log.call_args_list))
        self.assertIsNone(err)
        p = r["plan"]
        self.assertEqual(sum(1 for c in p["calls"] for x in c["picks"] if x["name"] == "A Both"), 1)
        fork = next(c for c in p["calls"] if c["call_id"] == 2)
        self.assertIn("Removed A Both from #2: overlapped #1", fork["notes"])
        self.assertEqual(next(x for x in p["people"] if x["name"] == "A Both")["calls"], [1])

    def test_c7_ewp_question_and_unmatched(self):
        people = [crew(10, "A Scissor", lic=[("EWPSOA", VALID)]), crew(11, "B Boom", rating=5, lic=[("WP", VALID)]),
                  crew(12, "C None", rating=5)]
        f = FakeC([one(1, "Show", 2, notes="EWP ticket required")], people)
        r, _ = f.single(1)
        self.assertTrue(r["needs_answers"])
        q = r["requirements"]["1"]["questions"][0]
        self.assertEqual(q["id"], "1:EWPSOA/WP")
        self.assertEqual([o["value"] for o in q["options"]], ["WP", "EWPSOA", "either"])   # catalogue order
        self.assertEqual(q["text"], "Notes say EWP: which licence: WP (Boom-type elevating work platform, "
                                    "over 11m) or EWPSOA (EWP statement of attainment, under 11m)?")
        self.assertEqual(r["requirements"]["1"]["unmatched_notes"], [])
        r, _ = f.single(1, {"objective": "rating", "requirement_answers": {"1:EWPSOA/WP": "either"}})
        self.assertEqual(sorted(names(r["plan"])), ["A Scissor", "B Boom"])
        r, _ = f.single(1, {"objective": "rating", "requirement_answers": {"1:EWPSOA/WP": "WP"}})
        self.assertEqual(names(r["plan"]), ["B Boom"])
        # A licence line that names no code: shown, never guessed.
        r, _ = FakeC([one(1, "Show", 1, notes="Special licence needed. Bring PPE.")], people).single(1)
        req = r["plan"]["requirements"]
        self.assertEqual(req["unmatched_notes"], ["Special licence needed"])
        self.assertEqual((req["licences"], req["questions"]), ([], []))
        self.assertIn('Notes mention a licence the plan can\'t match: "Special licence needed"', req["lines"])

    def test_c8_department_counts_are_not_licences(self):
        f = FakeC([one(1, "LX / VX", 2, notes="8x LX, 8xVX", depts=["LX", "VX"])],
                  [crew(10, "A", dept={"VX": 3}), crew(11, "B", rating=5)])
        r, err = f.single(1)
        self.assertIsNone(err)
        req = r["plan"]["requirements"]
        self.assertEqual((req["licences"], req["questions"], req["unmatched_notes"]), ([], [], []))
        self.assertEqual(req["depts"], ["LX", "VX"])
        self.assertEqual(names(r["plan"])[0], "A")            # VX history beats a higher rating

    def test_c9_old_php_plans_as_before(self):
        people = [crew(10 + k, "Crew %d" % k, rating=3 + k % 3, new=k % 2 == 0) for k in range(8)]
        calls = [one(1, "Load In", 3, start="2027-03-10T08:00:00", end="2027-03-10T12:00:00"),
                 one(2, "Show", 3, start="2027-03-10T13:00:00", end="2027-03-10T17:00:00")]
        old, e1 = FakeC(calls, people, old_php=True).run([1, 2], {"objective": "mix", "new_pct": 50})
        new, e2 = FakeC(calls, people).run([1, 2], {"objective": "mix", "new_pct": 50})
        self.assertIsNone(e1)
        self.assertEqual(json.dumps(old["plan"], sort_keys=True), json.dumps(new["plan"], sort_keys=True))
        for c in old["plan"]["calls"]:
            req = c["requirements"]
            self.assertEqual((req["licences"], req["questions"], req["depts"], req["specialist"]),
                             ([], [], [], []))
        s_old, _ = FakeC(calls[:1], people, old_php=True).single(1)
        self.assertEqual(s_old["plan"]["requirements"]["lines"], [])

    def test_call_type_clues_and_failure(self):
        people = [crew(10, "A Rigger", lic=[("RI", VALID)])]
        f = FakeC([one(1, "Show", 1)], people, call_types={"1": "Rigger"})
        r, _ = f.single(1)
        q = r["requirements"]["1"]["questions"][0]
        self.assertEqual(q["id"], "1:RA/RB/RI")
        self.assertEqual(q["source"], "The Call Type")
        self.assertEqual([o["value"] for o in q["options"]], ["RB", "RI", "RA", "either", "no"])
        f = FakeC([one(1, "Show", 1)], people, call_types={"1": "Rigger"}, ct_err="HTTP 500")
        r, _ = f.single(1)
        self.assertEqual(r["plan"]["requirements"]["notes"], ["Call Type couldn't be read"])
        self.assertEqual(r["plan"]["requirements"]["questions"], [])


class DrawerTests(unittest.TestCase):

    def fake(self):
        return FakeC([one(1, "Fork", 1)], [crew(10, "A Holder", lic=[("LF", VALID)]), crew(11, "B Plain", rating=5)])

    def test_c10_card_id_follows_answers(self):
        f = self.fake()
        ti = {"mode": "read_back", "booking_id": 700, "call_id": 1, "objective": "rating"}
        rb0 = f.goat(ti)["plan_read_back"]
        self.assertEqual(rb0["open"], 1)
        self.assertEqual([q["id"] for q in rb0["questions"]], ["1:LF"])
        self.assertTrue(rb0["text"].endswith("Answer the question to build this plan."), rb0["text"])
        self.assertNotRegex(rb0["text"], r"\b(above|below)\b")             # never a position
        rb_yes = f.goat(ti, answers={"1:LF": "yes"})["plan_read_back"]
        rb_no = f.goat(ti, answers={"1:LF": "no"})["plan_read_back"]
        self.assertEqual(len({rb0["id"], rb_yes["id"], rb_no["id"]}), 3)
        self.assertIn("Answered: Yes.", rb_yes["text"])
        self.assertTrue(rb_yes["text"].endswith("Build this plan?"))
        plan_ti = dict(ti, mode="plan")
        nothing = mock.Mock(side_effect=AssertionError("must not plan"))
        # A yes to the unanswered card can't plan, even with its own id.
        with mock.patch.object(A, "build_staffing_plan", nothing):
            res = f.goat(plan_ti, rb0["id"])
            self.assertTrue(res["read_back_shown"])
            self.assertIn("only the operator", res["note"])
            # A stale yes: the card was answered "no", the id says "yes".
            res = f.goat(plan_ti, rb_yes["id"], answers={"1:LF": "no"})
            self.assertTrue(res["read_back_shown"])
            self.assertEqual(res["plan_read_back"]["id"], rb_no["id"])
            # The model answering in its own arguments counts for nothing.
            res = f.goat(dict(plan_ti, requirement_answers={"1:LF": "yes"}), rb0["id"])
            self.assertTrue(res["read_back_shown"])
        res = f.goat(plan_ti, rb_yes["id"], answers={"1:LF": "yes"})
        self.assertEqual([p["name"] for p in res["picks"]], ["A Holder"])
        self.assertEqual(res["open_in_finder"]["plan"]["requirement_answers"], {"1:LF": "yes"})
        self.assertEqual(res["requirements"]["applied"], ["Requires Forklift truck (your answer)"])

    def test_mix_split_survives_the_answers_turn(self):
        # The answers message is the drawer's, not the operator's: the split
        # is checked against the operator's own last message instead.
        f = self.fake()
        typed = "plan call 1, 80/20 new to old"
        ti = {"mode": "read_back", "booking_id": 700, "call_id": 1, "objective": "mix",
              "split": "80/20 new to old"}
        rb0 = f.goat(ti, last_user=typed)["plan_read_back"]
        said = "Requirement answers: #1 LF: Yes"
        res = f.goat(ti, rb0["id"], {"1:LF": "yes"}, last_user=said, split_user=typed)
        self.assertIn("plan_read_back", res)
        self.assertIn("80% new", res["plan_read_back"]["text"])
        # A split the operator never typed is still refused on that turn.
        res = f.goat(dict(ti, split="20/80 new to old"), rb0["id"], {"1:LF": "yes"},
                     last_user=said, split_user=typed)
        self.assertIn("ask", res)
        # No card id: answers alone don't lift the rule.
        res = f.goat(ti, None, {"1:LF": "yes"}, last_user=said, split_user=typed)
        self.assertIn("ask", res)

    def test_tool_schema_and_prompt(self):
        tool = next(t for t in A.GOAT_TOOLS if t["name"] == "plan_staffing")
        self.assertIn("requirement_answers", tool["input_schema"]["properties"])
        self.assertIn("Never answer one", A.GOAT_SYSTEM)
        self.assertNotIn("notes_licence_hints is non-empty", A.GOAT_SYSTEM)


class DeterminismTests(unittest.TestCase):

    def test_b3_with_stage_c_rules(self):
        people = [crew(100 + k, "Crew %02d" % k, rating=3 + k % 3, new=k % 2 == 0,
                       lic=[("LF", VALID)] if k % 3 == 0 else [],
                       groups=["Wardrobe"] if k == 5 else [],
                       dept={"LX": k % 4}) for k in range(14)]
        calls = [one(1, "LX Load In", 3, notes="2x Forklift", depts=["LX"],
                     start="2027-03-10T08:00:00", end="2027-03-10T12:00:00"),
                 one(2, "Show", 3, start="2027-03-10T13:00:00", end="2027-03-10T17:00:00"),
                 one(3, "Load Out", 3, notes="Forklift", start="2027-03-10T18:00:00", end="2027-03-10T22:00:00")]
        f = FakeC(calls, people)
        r1, e1 = f.run([1, 2, 3], {"objective": "mix", "new_pct": 50})
        r2, e2 = f.run([1, 2, 3], {"objective": "mix", "new_pct": 50})
        self.assertIsNone(e1)
        self.assertEqual(json.dumps(r1["plan"], sort_keys=True), json.dumps(r2["plan"], sort_keys=True))
        out = next(c for c in r1["plan"]["calls"] if c["call_id"] == 3)
        holders = set("Crew %02d" % k for k in range(14) if k % 3 == 0)
        self.assertTrue(set(x["name"] for x in out["picks"]) <= holders)    # scope all, runs included


if __name__ == "__main__":
    unittest.main()
