"""Crew Finder: crew booked on SOME of several searched calls (5.96.1).

Regression for Sage Novak on #39506 Load In + #39519 Load Out: booked on the
load out only, yet the timeline drew a green "Booked" bar over both windows,
both tooltips said "Load Out 14:00 – 19:00", and the row said BOOKED.

Runs the page's own functions under node (js_harness). Skips without node."""

import re
import unittest

import js_harness as H


def _var(name):
    """A top-level `var NAME = …;` line from the page (extract() takes functions only)."""
    m = re.search(r"^var " + re.escape(name) + r"\b.*$", H.page(), re.M)
    if not m:
        raise LookupError(f"var {name} not found in index.html")
    return m.group(0)


# relFromStatusWord calls crewStatusIsWithdrawn, which reads CREW_WITHDRAWN_WORDS
# (offer reminders slice 4, Q18).
FUNCS = (_var("CREW_WITHDRAWN_WORDS") + "\n\n" + H.extract(
    "crewStatusIsWithdrawn",
    "relFromStatusWord", "buildBookedRel", "_normName", "targetRelationship",
    "targetBookedCount", "tlTargetPlan", "statusLabel", "_rankLine",
)) if H.NODE else ""

SETUP = r"""
var _bookedRel = new Map(), _softCallIds = new Set(), _showRanking = false;
var T = [{call_id:39506, call_num:39506, call_name:'Load In'},
         {call_id:39519, call_num:39519, call_name:'Load Out'}];
var W = [{callId:39506, soft:false}, {callId:39519, soft:false}];
var loadIn  = {call_id:'39506', is_target:true, status:5, call_name:'Load In'};
var loadOut = {call_id:'39519', is_target:true, status:5, call_name:'Load Out'};
var other   = {call_id:'40000', is_target:false, status:5};
function lbl(c, t){ return statusLabel(c, t, 'available').replace(/<[^>]+>/g,''); }
"""


class MultiCallBookedTests(unittest.TestCase):
    @unittest.skipUnless(H.NODE, "node not installed")
    def test_booked_on_one_of_two(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + SETUP + r"""
var sage = {name:'Novak, Sage', shifts:[loadOut, other]};
var rel = targetRelationship(sage);
var p = tlTargetPlan(sage, rel, W);
console.log(JSON.stringify({rel:rel, k:targetBookedCount(sage,T), label:lbl(sage,T),
  bars:p.bars.length, skipOut:p.skip(loadOut)}));
""")
        self.assertEqual(out["rel"], "booked")
        self.assertEqual(out["k"], 1)
        self.assertEqual(out["label"], "BOOKED 1/2")
        self.assertEqual(out["bars"], 0)          # no synthetic bar over the load in
        self.assertFalse(out["skipOut"])          # load out drawn as its own shift

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_booked_on_all_and_unconfirmed_promo(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + SETUP + r"""
var both  = {name:'A, B', shifts:[loadIn, loadOut]};
var promo = {name:'C, D', shifts:[loadIn, Object.assign({}, loadOut, {promo_pending:true})]};
console.log(JSON.stringify([lbl(both,T), lbl(promo,T)]));
""")
        self.assertEqual(out, ["BOOKED", "BOOKED 1/2"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_single_call_unchanged(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + SETUP + r"""
var T1 = [T[1]], W1 = [W[1], {callId:777, soft:true}];
_bookedRel = buildBookedRel([{name:'Sage Novak', status:'confirmed'}, {name:'Conan X', status:'declined'}]);
var sage  = {name:'Novak, Sage', shifts:[loadOut]};
var conan = {name:'X, Conan', shifts:[]};
var ps = tlTargetPlan(sage, targetRelationship(sage), W1);
var pc = tlTargetPlan(conan, targetRelationship(conan), W1);
console.log(JSON.stringify({ls:lbl(sage,T1), lc:lbl(conan,T1),
  sBars:ps.bars.length, sSoft:ps.bars[0].tw.soft, sTip:ps.bars[0].tgtShift.call_name, sSkip:ps.skip(loadOut),
  cBars:pc.bars.length, cTip:pc.bars[0].tgtShift}));
""")
        self.assertEqual(out["ls"], "BOOKED")
        self.assertEqual(out["lc"], "DECLINED")
        self.assertEqual(out["sBars"], 1)         # hard window only, not the soft one
        self.assertFalse(out["sSoft"])
        self.assertEqual(out["sTip"], "Load Out")
        self.assertTrue(out["sSkip"])
        self.assertEqual(out["cBars"], 1)         # no calendar row: synthetic bar still drawn
        self.assertIsNone(out["cTip"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_soft_call_booking_is_not_a_target_relationship(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + SETUP + r"""
_softCallIds = new Set(['39519']);
var sage = {name:'Novak, Sage', shifts:[loadOut]};
console.log(JSON.stringify(targetRelationship(sage)));
""")
        self.assertIsNone(out)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_offer_withdrawn_statuses_map_to_declined(self):
        # Offer reminders Q18: an offer withdrawn by the reminder cron is settled,
        # not coming. Existing words keep their mapping.
        out = H.run(H.DOM_PRELUDE + FUNCS + SETUP + r"""
var words = ['noresponse','notdelivered','withdrawn','NoResponse',
             'declined','confirmed','backup','unconfirmed','sent','noshow','cancelled',''];
var m = {};
words.forEach(function(w){ m[w] = relFromStatusWord(w); });
_bookedRel = buildBookedRel([{name:'Sage Novak', status:'noresponse'}]);
m.panel = targetRelationship({name:'Novak, Sage', shifts:[]});
console.log(JSON.stringify(m));
""")
        self.assertEqual(out["noresponse"], "declined")
        self.assertEqual(out["notdelivered"], "declined")
        self.assertEqual(out["withdrawn"], "declined")
        self.assertEqual(out["panel"], "declined")      # ALREADY BOOKED panel
        self.assertEqual(out["declined"], "declined")
        self.assertEqual(out["confirmed"], "booked")
        self.assertEqual(out["backup"], "backup")
        for w in ("unconfirmed", "sent", "noshow", "cancelled", ""):
            self.assertEqual(out[w], "waiting", w)


if __name__ == "__main__":
    unittest.main()
