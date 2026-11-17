"""The Performance tab's "Margin — crew pay" card (BRIEF-performance-cost-slice-a.md §5.2).

Runs the card's functions from templates/index.html under node (js_harness)
against an inline payload (PAYLOAD below), which was produced by the PHP
itself (goat_perf_cost_compute over the same hand-built calls test-perf-cost.php
uses), so the card is tested against the shape the endpoint really emits.
Skips when node isn't installed.

  - weekly margin % is null (a gap, not 0%) on a week with no revenue
  - a week with calls paid but awaiting invoice is drawn hollow and says so
  - a negative-margin week is drawn below a zero line, in red, not clipped
  - the 30% target line appears in the % view only
  - exception rows decode entities, then escape; each row opens its call
  - perfMarginOn() is false unless whoami.margin is exactly true, so an ops
    login and an admin who isn't Mike or Joe get no slot at all
  - the basis strip changes for margin users only
  - slice D: no forward_margin -> the chart is drawn exactly as before; an
    estimate draws a forward zone (dashed required area, solid secured line),
    the empty tail reads "not yet booked", the summary line says ESTIMATED,
    and the fold carries the backtest; unavailable says so
"""

import json
import os
import re
import unittest

import js_harness as H

# Produced by the PHP itself: goat_perf_cost_compute() (smartstaff/perf-cost-lib.php)
# over hand-built calls -- the same ones smartstaff/test-perf-cost.php uses, minus
# call 102 (hand-billed) and 108 (inactive) -- with "today" pinned to Mon 16 Nov
# 2026 and weeks=2. Synthetic: no real call, crew or pay data. Kept inline rather
# than under tests/fixtures/, which .gitignore keeps out of this public repo.
PAYLOAD = json.dumps({'generated_at': '2026-11-16T10:00:00', 'window': {'weeks': 2, 'range_start': 1792328400, 'range_end': 1794747599, 'week_keys': ['2026-W43', '2026-W44', '2026-W45', '2026-W46']}, 'weeks': [{'key': '2026-W43', 'revenue': 0, 'pay': 0, 'margin': 0, 'margin_pct': None, 'calls_matched': 0, 'calls_active': 0, 'awaiting_invoice': {'calls': 0, 'pay': 0}, 'not_on_invoice': {'calls': 0, 'pay': 0}, 'hand_billed_unlined': {'calls': 0, 'pay': 0}, 'invoiced_no_pay': {'invoices': 0, 'revenue': 0}}, {'key': '2026-W44', 'revenue': 500, 'pay': 900, 'margin': -400, 'margin_pct': -80, 'calls_matched': 1, 'calls_active': 1, 'awaiting_invoice': {'calls': 0, 'pay': 0}, 'not_on_invoice': {'calls': 0, 'pay': 0}, 'hand_billed_unlined': {'calls': 0, 'pay': 0}, 'invoiced_no_pay': {'invoices': 0, 'revenue': 0}}, {'key': '2026-W45', 'revenue': 300, 'pay': 80, 'margin': 220, 'margin_pct': 73.3, 'calls_matched': 2, 'calls_active': 2, 'awaiting_invoice': {'calls': 0, 'pay': 0}, 'not_on_invoice': {'calls': 1, 'pay': 80}, 'hand_billed_unlined': {'calls': 0, 'pay': 0}, 'invoiced_no_pay': {'invoices': 0, 'revenue': 0}}, {'key': '2026-W46', 'revenue': 1000, 'pay': 600, 'margin': 400, 'margin_pct': 40, 'calls_matched': 1, 'calls_active': 2, 'awaiting_invoice': {'calls': 1, 'pay': 50}, 'not_on_invoice': {'calls': 0, 'pay': 0}, 'hand_billed_unlined': {'calls': 0, 'pay': 0}, 'invoiced_no_pay': {'invoices': 0, 'revenue': 0}}], 'totals': {'current': {'revenue': 1300, 'pay': 680, 'calls_matched': 3, 'calls_active': 4, 'margin_pct': 47.7, 'margin': 620}, 'prior': {'revenue': 500, 'pay': 900, 'calls_matched': 1, 'calls_active': 1, 'margin_pct': -80, 'margin': -400}}, 'exceptions': {'not_on_invoice': [{'call_id': 104, 'booking_id': 13, 'invoice_id': 2, 'call_name': 'Stage &amp; Lights', 'call_date': '2026-11-03', 'pay': 80, 'charge_on_pay': 120}], 'truncated': False}, 'data_quality': {'pay_rows': 7, 'null_calculated_rows': 0, 'lines_call_outside_span': 0, 'lines_no_call': 0, 'lines_no_call_revenue': 0}})

NAMES = (
    "perfMarginOn", "perfMarginSlotHtml", "perfMarginSeries", "perfPctFmt",
    "perfPctFmtFull", "perfSignedMoney", "perfSignedMoneyFull", "perfMarginChartSvg", "perfMarginCard", "perfNiceMax",
    "perfIsoWeekMonday", "perfWeekLabel", "perfMoney", "perfMoneyFull", "perfNum",
    "perfDeltaPct", "perfDeltaText", "perfFold", "perfBasisStrip", "renderPerformance",
    "perfHeadTile", "perfGmTileHtml", "perfSparkSvg", "perfRgba",
    "perfMarginCustomerRows", "perfMarginCustomerCard",
    "perfMarginForward", "perfMarginForwardHtml", "perfShortDate",
)
FUNCS = H.extract(*NAMES) if H.NODE else ""

PRELUDE = H.DOM_PRELUDE + r"""
var PERF_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var PERF_SIGNAL_COLORS = { good: '#10b981', watch: '#f59e0b', bad: '#e31e24', none: '#666666' };
var PERF_MARGIN_TARGET_PCT = 30;
var PERF_RANK_MIN_HOURS = 1000;
var _prefs = {};
var goatPrefs = { get: function(a, k, d){ return (a + k) in _prefs ? _prefs[a + k] : d; },
                  set: function(a, k, v){ _prefs[a + k] = v; } };
var _perfCost = null, _perfCostErr = null, _perfMarginUnit = 'dollars';
// renderPerformance's other cards are not under test here.
function perfHeadlines(){ return '[heads]'; }
function perfWaterfallCard(){ return '[wf]'; }
function perfSlopeCard(){ return '[slope]'; }
function perfYieldCard(){ return '[yield]'; }
function perfWeeklyCard(){ return '[weekly]'; }
function perfForwardCard(){ return '[fwd]'; }
function perfFyCard(){ return '[fy]'; }
var _perfData = {};
_el('perf-body');
"""


def run(body):
    return H.run(PRELUDE + FUNCS + "\nvar P = " + PAYLOAD + ";\n" + body)


@unittest.skipUnless(H.NODE, "node not installed")
class MarginCard(unittest.TestCase):

    def test_series_splits_the_span_and_pct_is_null_without_revenue(self):
        out = run(r"""
          var d = perfMarginSeries(P, 'dollars'), p = perfMarginSeries(P, 'pct');
          console.log(JSON.stringify({labels: d.labels, cur: d.cur, prev: d.prev,
                                      pprev: p.prev, inc: d.incomplete}));
        """)
        self.assertEqual(out["labels"], ["2026-W45", "2026-W46"])
        self.assertEqual(out["cur"], [220, 400])
        self.assertEqual(out["prev"], [None, -400])          # W43: no calls, no money -> a gap, not $0
        self.assertEqual(out["pprev"], [None, -80])          # W43 has no revenue -> null, not 0
        self.assertEqual(out["inc"], [False, True])          # W46 has a call awaiting invoice

    def test_incomplete_week_is_hollow_with_a_tooltip(self):
        out = run(r"""
          var svg = perfMarginChartSvg(perfMarginSeries(P, 'dollars'), '#d4af37');
          console.log(JSON.stringify({inc: (svg.match(/perf-mgn-inc/g) || []).length,
                                      tip: svg.indexOf('1 call paid, awaiting invoice') >= 0}));
        """)
        self.assertEqual(out["inc"], 1)
        self.assertTrue(out["tip"])

    def test_negative_week_is_drawn_below_zero_not_clipped(self):
        out = run(r"""
          P.weeks[2].margin = -150; P.weeks[2].margin_pct = -50;
          var svg = perfMarginChartSvg(perfMarginSeries(P, 'dollars'), '#d4af37');
          var zero = /class="perf-mgn-zero"[^>]*y1="([\d.]+)"/.exec(svg);
          var neg  = /class="perf-mgn-dot perf-mgn-neg"[^>]*cy="([\d.]+)"/.exec(svg);
          console.log(JSON.stringify({zero: zero && +zero[1], neg: neg && +neg[1],
                                      red: svg.indexOf('stroke="#e31e24"') >= 0,
                                      axisNeg: svg.indexOf('>−$') >= 0, bad: svg.indexOf('$-') >= 0}));
        """)
        self.assertIsNotNone(out["zero"])
        self.assertIsNotNone(out["neg"])
        self.assertGreater(out["neg"], out["zero"])           # SVG y grows downward: below zero
        self.assertLess(out["neg"], 16 + 248 + 0.01)          # and inside the plot, not clipped
        self.assertTrue(out["red"])
        self.assertTrue(out["axisNeg"])
        self.assertFalse(out["bad"])

    def test_no_zero_line_when_nothing_is_negative(self):
        out = run(r"""
          P.weeks[1].margin = 10; P.weeks[1].margin_pct = 10;
          var svg = perfMarginChartSvg(perfMarginSeries(P, 'dollars'), '#d4af37');
          console.log(JSON.stringify({zero: svg.indexOf('perf-mgn-zero') >= 0}));
        """)
        self.assertFalse(out["zero"])

    def test_target_line_in_pct_view_only(self):
        out = run(r"""
          var d = perfMarginChartSvg(perfMarginSeries(P, 'dollars'), '#d4af37');
          var p = perfMarginChartSvg(perfMarginSeries(P, 'pct'), '#d4af37');
          console.log(JSON.stringify({d: d.indexOf('perf-mgn-target') >= 0,
                                      p: p.indexOf('perf-mgn-target') >= 0,
                                      lbl: p.indexOf('target 30%') >= 0}));
        """)
        self.assertFalse(out["d"])
        self.assertTrue(out["p"])
        self.assertTrue(out["lbl"])

    def test_card_totals_coverage_and_exceptions(self):
        out = run(r"""
          decodeEntities = function(s){ return String(s).replace(/&amp;/g, '&'); };
          _prefs['perffold:marginExceptions'] = true;      // fold open
          var h = perfMarginCard(P);
          console.log(JSON.stringify({h: h}));
        """)
        h = out["h"]
        self.assertIn("MARGIN — CREW PAY", h)
        self.assertIn("$1,300", h)                         # matched revenue
        self.assertIn("$620", h)                           # margin $
        self.assertIn("47.7%", h)                          # margin %
        self.assertIn("pts", h)                            # % delta in points
        self.assertIn("▲ 255.0% vs last year", h)          # -$400 -> $620 is a rise, not a fall
        self.assertIn("Covers <b>3</b> of 4 calls", h)
        self.assertIn("1 paid, awaiting invoice", h)
        self.assertIn('perf-mgn-amber">1 paid, not on invoice', h)
        # decoded, then escaped exactly once
        self.assertIn("<td>Stage &amp; Lights</td>", h)
        self.assertNotIn("&amp;amp;", h)
        self.assertIn('data-perf-call="13:104"', h)
        self.assertIn("likely unbilled", h)
        self.assertNotIn("Xero before", h)

    def test_slot_states(self):
        out = run(r"""
          var a = perfMarginSlotHtml();
          _perfCostErr = 'HTTP 500';
          var b = perfMarginSlotHtml();
          _perfCostErr = null; _perfCost = {weeks: 'not a list'};
          var c = perfMarginSlotHtml();
          console.log(JSON.stringify({a: a, b: b, c: c}));
        """)
        self.assertIn("Loading margin", out["a"])
        self.assertIn("Margin is unavailable — HTTP 500", out["b"])
        self.assertIn("Margin is unavailable", out["c"])     # a bad shape costs this card only

    def test_slot_only_for_margin_users(self):
        out = run(r"""
          var r = {};
          [['none', undefined], ['ops', {cohort:'operations'}],
           ['admin', {cohort:'admin', margin:false}], ['truthy', {cohort:'admin', margin:'yes'}],
           ['mike', {cohort:'admin', margin:true}]].forEach(function(c){
            window._identity = c[1];
            renderPerformance();
            r[c[0]] = {slot: _els['perf-body'].innerHTML.indexOf('perf-margin-slot') >= 0,
                       strip: perfBasisStrip().indexOf('No margin figure') >= 0};
          });
          console.log(JSON.stringify(r));
        """)
        for who in ("none", "ops", "admin", "truthy"):
            self.assertFalse(out[who]["slot"], who)
            self.assertTrue(out[who]["strip"], who)
        self.assertTrue(out["mike"]["slot"])
        self.assertFalse(out["mike"]["strip"])


@unittest.skipUnless(H.NODE, "node not installed")
class GrossMarginTile(unittest.TestCase):
    """The headline 'gross margin' tile, filled for margin users (Mike, 16 Nov)."""

    def test_states(self):
        out = run(r"""
          var a = perfGmTileHtml();
          _perfCostErr = 'HTTP 500'; var b = perfGmTileHtml();
          _perfCostErr = null; _perfCost = P; var c = perfGmTileHtml();
          console.log(JSON.stringify({a: a, b: b, c: c}));
        """)
        self.assertIn("loading crew pay", out["a"])
        self.assertIn("perf-head--muted", out["a"])
        self.assertIn("margin unavailable", out["b"])
        c = out["c"]
        self.assertIn(">47.7%<", c)                       # the card's own current margin %
        self.assertIn("pts vs -80.0%", c)                 # points against last year
        self.assertIn("target 30%", c)
        self.assertIn("perf-spark", c)
        self.assertNotIn("perf-head--muted", c)

    def test_matches_the_card(self):
        out = run(r"""
          _perfCost = P;
          console.log(JSON.stringify({tile: perfGmTileHtml(), card: perfMarginCard(P)}));
        """)
        self.assertIn("47.7%", out["tile"])
        self.assertIn("47.7%", out["card"])


# Slice B fixture: totals.current in PAYLOAD are revenue 1300, pay 680. Split
# here across three customers, two of them in the revenue charts' top 8.
CUST = r"""
P.customers = [
  {id: 0,   current: {revenue: 0,    pay: 0,   margin: 0,   margin_pct: null, hours: 0,   calls_matched: 0},
            prior:   {revenue: 500,  pay: 900, margin: -400, margin_pct: -80, hours: 5,   calls_matched: 1}},
  {id: 11,  current: {revenue: 1000, pay: 600, margin: 400, margin_pct: 40,   hours: 1500, calls_matched: 1},
            prior:   {revenue: 0,    pay: 0,   margin: 0,   margin_pct: null, hours: 0,   calls_matched: 0}},
  {id: 22,  current: {revenue: 250,  pay: 50,  margin: 200, margin_pct: 80,   hours: 20,  calls_matched: 1},
            prior:   {revenue: 0,    pay: 0,   margin: 0,   margin_pct: null, hours: 0,   calls_matched: 0}},
  {id: 33,  current: {revenue: 50,   pay: 30,  margin: 20,  margin_pct: 40,   hours: 2,   calls_matched: 1},
            prior:   {revenue: 0,    pay: 0,   margin: 0,   margin_pct: null, hours: 0,   calls_matched: 0}}
];
var D = {customers: [{id: 22, name: 'Smaller &amp; Co'}, {id: 11, name: 'Big Touring'}, {id: 44, name: 'No Margin Yet'}]};
"""


@unittest.skipUnless(H.NODE, "node not installed")
class MarginByCustomer(unittest.TestCase):
    """Slice B: the revenue charts' top 8, in their order, plus All other."""

    def test_rows_follow_the_revenue_ranking(self):
        out = run(CUST + r"""
          var R = perfMarginCustomerRows(P, D);
          console.log(JSON.stringify({ids: R.rows.map(function(r){ return r.id; }),
                                      other: R.other, n: R.otherCount,
                                      missing: R.rows[2].cur}));
        """)
        self.assertEqual(out["ids"], ["22", "11", "44"])        # revenue order, not margin order
        self.assertEqual(out["other"]["revenue"], 50)            # 33 + 0 (customer 0 has no current)
        self.assertEqual(out["n"], 1)                            # only 33 is active in the window
        self.assertEqual(out["missing"]["revenue"], 0)           # top-8 customer with no margin data: zeros

    def test_card_reconciles_and_greys_thin_customers(self):
        out = run(CUST + r"""
          console.log(JSON.stringify({h: perfMarginCustomerCard(P, D)}));
        """)
        h = out["h"]
        self.assertIn("MARGIN BY CUSTOMER", h)
        self.assertIn("Smaller &amp;amp; Co", h)                # name escaped once (harness decodeEntities is identity)
        self.assertIn("All other", h)
        self.assertIn("(1 customer)", h)
        self.assertNotIn("do not add up", h)                    # 250 + 1000 + 0 + 50 = 1300 = totals
        self.assertIn("perf-mcb-thin", h)                       # 22 has 20 h: greyed
        self.assertIn(">40.0%<", h)                             # 11: 40%, not greyed
        self.assertIn("perf-mct-total", h)

    def test_mismatch_is_shown(self):
        out = run(CUST + r"""
          P.customers[1].current.revenue = 999;
          console.log(JSON.stringify({h: perfMarginCustomerCard(P, D)}));
        """)
        self.assertIn("do not add up", out["h"])

    def test_rounding_drift_is_not_a_mismatch(self):
        # 17 Nov, test server: 153 customers summed to 6 cents over the total
        # pay, purely from per-customer rounding. That must not warn.
        out = run(CUST + r"""
          for(var i = 0; i < 150; i++)                       // 154 customers, as on test
            P.customers.push({id: 1000 + i, current: {revenue: 0, pay: 0, margin: 0, margin_pct: null, hours: 0, calls_matched: 0},
                                             prior:   {revenue: 0, pay: 0, margin: 0, margin_pct: null, hours: 0, calls_matched: 0}});
          P.customers[1].current.pay = 600.06;
          console.log(JSON.stringify({h: perfMarginCustomerCard(P, D)}));
        """)
        self.assertNotIn("do not add up", out["h"])

    def test_no_customers_no_card(self):
        out = run(r"""
          console.log(JSON.stringify({h: perfMarginCustomerCard(P, {customers: [{id: 1, name: 'x'}]})}));
        """)
        self.assertEqual(out["h"], "")                          # 5.104.0 endpoint: card simply absent


@unittest.skipUnless(H.NODE, "node not installed")
class Wiring(unittest.TestCase):
    """The parts that are plain source facts rather than behaviour."""

    def test_load_after_revenue_render_and_not_awaited(self):
        src = H.extract("loadPerformance")
        i_render = src.index("renderPerformance();\n    // After the revenue")
        i_cost   = src.index("loadPerfCost(!!force);")
        self.assertLess(i_render, i_cost)
        self.assertNotIn("await loadPerfCost", src)

    def test_non_margin_users_keep_the_old_tile(self):
        src = H.extract("perfHeadlines")
        self.assertIn("perfMarginOn()", src)
        self.assertIn("sub: 'cost data not yet reliable'", src)
        self.assertIn('id="perf-gm-tile"', src)

    def test_slot_includes_customer_card(self):
        self.assertIn("perfMarginCustomerCard(_perfCost, _perfData)", H.extract("perfMarginSlotHtml"))

    def test_tile_redrawn_when_cost_arrives(self):
        src = H.extract("renderPerfMarginSlot")
        self.assertIn("perfGmTileHtml()", src)

    def test_delegation_handles_margin_controls(self):
        page = H.page()
        self.assertIn("[data-perf-margin-unit],[data-perf-call]", page)
        self.assertIn("perfSetMarginUnit(t.getAttribute('data-perf-margin-unit'))", page)
        self.assertIn("openCallDialog(parts[0], parts[1])", page)


# Slice D: a forward estimate as build_forward_margin returns it. Synthetic.
FWD = json.dumps({"estimated": True, "from": "2026-11-17", "to": "2027-01-15",
  "week_keys": ["2026-W47", "2026-W48", "2026-W49"],
  "weeks": [
    {"key": "2026-W47", "revenue": 1000, "secured_revenue": 600, "required_cost": 600, "secured_cost": 360,
     "unfilled_seats": 2, "calls": 3, "has_work": True, "required_margin": 400, "required_margin_pct": 40,
     "secured_margin": 240, "secured_margin_pct": 40},
    {"key": "2026-W48", "revenue": 500, "secured_revenue": 0, "required_cost": 550, "secured_cost": 0,
     "unfilled_seats": 4, "calls": 1, "has_work": True, "required_margin": -50, "required_margin_pct": -10,
     "secured_margin": 0, "secured_margin_pct": None},
    {"key": "2026-W49", "revenue": 0, "secured_revenue": 0, "required_cost": 0, "secured_cost": 0,
     "unfilled_seats": 0, "calls": 0, "has_work": False, "required_margin": 0, "required_margin_pct": None,
     "secured_margin": 0, "secured_margin_pct": None}],
  "totals": {"revenue": 1500, "secured_revenue": 600, "required_cost": 1150, "secured_cost": 360,
             "required_margin": 350, "required_margin_pct": 23.33, "secured_margin": 240, "secured_margin_pct": 40},
  "backtest": {"trained_from": "2026-05-18", "tested_from": "2026-08-17", "tested_to": "2026-11-15",
               "people": {"estimated": 101, "actual": 100, "total_pct": 1.0, "worst_week_pct": 13.4, "weeks": 13, "passed": True},
               "roles": {"estimated": 102, "actual": 100, "total_pct": 2.0, "worst_week_pct": 13.7, "weeks": 13, "passed": True},
               "revenue": {"estimated": 98, "actual": 100, "total_pct": -2.0, "worst_week_pct": 9.1, "weeks": 13, "passed": True,
                           "ratio": 0.9123, "calls": 900},
               "passed": True, "pass_mark": {"total_pct": 5.0, "week_pct": 15.0}, "hours_factor": 0.9554},
  "method": {"trained_from": "2026-08-17", "trained_to": "2026-11-15", "hours_factor": 0.93,
             "revenue_factor": 0.9123, "revenue_factor_calls": 900,
             "seats_by_source": {"own": 3, "level": 0, "role": 6, "family": 1, "global": 0, "none": 0}},
  "data_quality": {"calls_costed": 4, "not_priced_calls": 1, "not_priced_seats": 3, "zero_length_calls": 0,
                   "untimed_calls": 1, "cancelled_calls": 2}})


@unittest.skipUnless(H.NODE, "node not installed")
class ForwardZone(unittest.TestCase):

    def test_without_forward_margin_the_chart_is_unchanged(self):
        out = run(r"""
          var s = perfMarginSeries(P, 'dollars');
          var svg = perfMarginChartSvg(s, '#d4af37');
          console.log(JSON.stringify({fwd: s.fwd, zone: svg.indexOf('perf-mgn-fwd') >= 0,
                                      est: svg.indexOf('ESTIMATED') >= 0}));
        """)
        self.assertEqual(out, {"fwd": None, "zone": False, "est": False})

    def test_zone_draws_required_area_secured_line_and_tail(self):
        out = run(r"""
          P.forward_margin = """ + FWD + r""";
          var s = perfMarginSeries(P, 'dollars');
          var svg = perfMarginChartSvg(s, '#d4af37');
          console.log(JSON.stringify({drawn: s.fwd.drawn, req: s.fwd.required, sec: s.fwd.secured,
            area: (svg.match(/perf-mgn-fwd-area/g) || []).length,
            reqline: (svg.match(/perf-mgn-fwd-req/g) || []).length,
            secline: (svg.match(/perf-mgn-fwd-sec/g) || []).length,
            tail: svg.indexOf('not yet') >= 0, est: (svg.match(/ESTIMATED/g) || []).length,
            empty: svg.indexOf('nothing booked into this week yet') >= 0,
            unfilled: svg.indexOf('4 unfilled seats') >= 0}));
        """)
        self.assertEqual(out["drawn"], 2)
        self.assertEqual(out["req"], [400, -50, None])        # the empty week is not drawn at $0
        self.assertEqual(out["sec"], [240, 0, None])
        self.assertEqual(out["area"], 1)
        self.assertEqual(out["reqline"], 1)
        self.assertEqual(out["secline"], 1)
        self.assertTrue(out["tail"])
        self.assertEqual(out["est"], 3)                      # every forward week's tooltip says so
        self.assertTrue(out["empty"])
        self.assertTrue(out["unfilled"])

    def test_pct_view_breaks_the_secured_line_where_there_is_no_revenue(self):
        out = run(r"""
          P.forward_margin = """ + FWD + r""";
          var s = perfMarginSeries(P, 'pct');
          var svg = perfMarginChartSvg(s, '#d4af37');
          console.log(JSON.stringify({sec: s.fwd.secured, req: s.fwd.required,
                                      lone: (svg.match(/<circle[^>]*fill="none" stroke="#d4af37" stroke-width="1.5"\/>/g) || []).length}));
        """)
        self.assertEqual(out["sec"], [40, None, None])
        self.assertEqual(out["req"], [40, -10, None])
        self.assertGreaterEqual(out["lone"], 1)              # a one-week run is a dot, not nothing
        self.assertNotIn("perf-mgn-fwd-area", run(r"""
          P.forward_margin = """ + FWD + r""";
          console.log(JSON.stringify({svg: perfMarginChartSvg(perfMarginSeries(P, 'pct'), '#d4af37')}));
        """)["svg"])                                         # no fill in the % view

    def test_card_summary_line_and_fold(self):
        out = run(r"""
          P.forward_margin = """ + FWD + r""";
          _prefs['perffold:marginForward'] = true;
          var h = perfMarginCard(P);
          console.log(JSON.stringify({h: h}));
        """)
        h = out["h"]
        self.assertIn("Booked ahead, estimated", h)
        self.assertIn("17 Nov – 15 Jan", h)
        self.assertIn("(23.3%)", h)
        self.assertIn("confirmed seats only: $240 (40.0%)", h)
        self.assertIn("How the forward estimate works", h)
        self.assertIn("Checked against real pay", h)
        self.assertIn("(+1.0%)", h)
        self.assertIn("worst single week 13.7% out", h)
        self.assertIn("scaled by 0.912", h)
        self.assertIn("over 900 invoiced calls", h)
        self.assertIn("Revenue at the rate card × 0.912: estimated $98 against $100 invoiced (-2.0%)", h)
        self.assertIn("<b>passed</b>", h)
        self.assertIn("1 call with no rate-card price (3 seats)", h)
        self.assertIn("2 cancelled", h)
        self.assertIn("est. all seats", h)
        self.assertNotIn("treat with care", h)
        self.assertIn('<div><div class="perf-fold-t" data-perf-fold="marginForward"', h)
        self.assertIn('<div><div class="perf-fold-t" data-perf-fold="marginExceptions"', h)

    def test_failed_backtest_is_said_on_the_line(self):
        out = run(r"""
          var f = """ + FWD + r""";
          f.backtest.passed = false;
          P.forward_margin = f;
          console.log(JSON.stringify({h: perfMarginCard(P)}));
        """)
        self.assertIn("treat with care", out["h"])

    def test_unavailable_says_so_and_draws_a_labelled_zone(self):
        out = run(r"""
          P.forward_margin = {unavailable: true, error: 'pricing rules unavailable: x'};
          var s = perfMarginSeries(P, 'dollars');
          console.log(JSON.stringify({h: perfMarginCard(P), svg: perfMarginChartSvg(s, '#d4af37')}));
        """)
        self.assertIn("Forward estimate unavailable — pricing rules unavailable: x", out["h"])
        self.assertIn("unavailable", out["svg"])
        self.assertNotIn("est. all seats", out["h"])

    def test_gm_tile_ignores_the_estimate(self):
        out = run(r"""
          _perfCost = P; var a = perfGmTileHtml();
          P.forward_margin = """ + FWD + r"""; var b = perfGmTileHtml();
          console.log(JSON.stringify({same: a === b}));
        """)
        self.assertTrue(out["same"])

    def test_short_date(self):
        out = run(r"""console.log(JSON.stringify([perfShortDate('2026-11-07'), perfShortDate('x'), perfShortDate(null)]));""")
        self.assertEqual(out, ["7 Nov", "x", ""])


if __name__ == "__main__":
    unittest.main()
