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
)
FUNCS = H.extract(*NAMES) if H.NODE else ""

PRELUDE = H.DOM_PRELUDE + r"""
var PERF_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var PERF_SIGNAL_COLORS = { good: '#10b981', watch: '#f59e0b', bad: '#e31e24', none: '#666666' };
var PERF_MARGIN_TARGET_PCT = 30;
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

    def test_tile_redrawn_when_cost_arrives(self):
        src = H.extract("renderPerfMarginSlot")
        self.assertIn("perfGmTileHtml()", src)

    def test_delegation_handles_margin_controls(self):
        page = H.page()
        self.assertIn("[data-perf-margin-unit],[data-perf-call]", page)
        self.assertIn("perfSetMarginUnit(t.getAttribute('data-perf-margin-unit'))", page)
        self.assertIn("openCallDialog(parts[0], parts[1])", page)


if __name__ == "__main__":
    unittest.main()
