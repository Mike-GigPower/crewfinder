"""Stage C requirements in the page (BRIEF-staffing-plans-c §4): the
Requirements block's questions and the open count that keeps Plan disabled,
the review line per call, and a stale card's buttons.

Runs the page's own functions under node (js_harness). Skips when node isn't
installed."""

import unittest

import js_harness as H

FUNCS = H.extract(
    "_planEsc", "_planPlacesText", "_planCallKey", "_planClearTicks", "_planReqBlockHtml", "_planReqOpen",
    "_planReqReviewHtml", "renderBookingPlan", "_bpCall", "_bookingPlanPeople", "_bookingPlanRender",
) if H.NODE else ""

REQS = r"""
var selectedCalls = new Map([[1,{}],[2,{}]]);
var selected = new Set(); function updateSelCount(){}
var _activePlan = null;
var reqs = {
  '2': {licences:[], lines:['Prefers LX experience'], precheck:[], questions:[]},
  '1': {licences:[{key:'LF'}], lines:['Requires Forklift truck (from Notes)', 'Call Type couldn\'t be read'],
        precheck:[{text:'Requires Forklift truck (from Notes): 1 available for 2 places'}],
        questions:[{id:'1:EWPSOA/WP', text:'Notes say <EWP>: which licence?',
                    options:[{value:'WP',label:'WP'},{value:'EWPSOA',label:'EWPSOA'},{value:'either',label:'Either'}],
                    counts:{WP:2, EWPSOA:0}}]}};
"""


class RequirementsBlockTests(unittest.TestCase):
    @unittest.skipUnless(H.NODE, "node not installed")
    def test_block_and_open_count(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + REQS + r"""
var h = _planReqBlockHtml(reqs, {}, {'1':'Fork', '2':'LX Load In'});
var h2 = _planReqBlockHtml(reqs, {'1:EWPSOA/WP':'WP'}, {});
console.log(JSON.stringify({
  order: h.indexOf('#1 Fork') < h.indexOf('#2 LX Load In'),
  precheckNotPlain: h.indexOf('1 available for 2 places') >= 0 && h.indexOf('(from Notes)</div>') < 0,
  other: h.indexOf('Call Type couldn&#39;t be read') >= 0 && h.indexOf('Prefers LX experience') >= 0,
  escaped: h.indexOf('&lt;EWP&gt;') >= 0,
  counts: h.indexOf('WP · 2 available') >= 0 && h.indexOf('Either</button>') >= 0,
  chosen: h2.split('var(--accent)').length - 1,
  open0: _planReqOpen(reqs, {}), open1: _planReqOpen(reqs, {'1:EWPSOA/WP':'either'})}));""")
        self.assertTrue(out["order"])
        self.assertTrue(out["precheckNotPlain"])
        self.assertTrue(out["other"])
        self.assertTrue(out["escaped"])
        self.assertTrue(out["counts"])
        self.assertEqual(out["chosen"], 2)          # border and text of the one chosen button
        self.assertEqual((out["open0"], out["open1"]), (1, 0))

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_review_line_in_booking_panel(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + REQS + r"""
_el('plan-strip');
var plan = {kind:'booking', booking_id:700, read_back:'RB', notes:[], experience_as_at:'x', summary:{places_total:2},
  calls:[{call_id:1, call_name:'Fork', start_dt:'2027-03-10T09:00:00', carries:[], places:1, places_breakdown:null,
          picks:[{user_id:10,name:'Ann',why:'w',flags:[]}], notes:['Removed Bo from #1: overlapped #2'],
          requirements:{review:['Requires Forklift truck · 1 of 1 hold it'], unmatched_notes:['special licence'], notes:[]}},
         {call_id:2, call_name:'Show', start_dt:'2027-03-10T13:00:00', carries:[], places:1, places_breakdown:null,
          picks:[], notes:[]}],
  people:[{user_id:10,name:'Ann',calls:[1],root_calls:[1]}]};
renderBookingPlan({plan:plan}, {});
var h = _el('plan-strip').innerHTML;
console.log(JSON.stringify({review: h.indexOf('Requires Forklift truck · 1 of 1 hold it') >= 0,
  unmatched: h.indexOf('special licence') >= 0,
  removedAmber: /color:var\(--warn\)">Removed Bo/.test(h),
  noReqOk: h.indexOf('#2 Show') >= 0}));""")
        self.assertEqual(out, {"review": True, "unmatched": True, "removedAmber": True, "noReqOk": True})


if __name__ == "__main__":
    unittest.main()
