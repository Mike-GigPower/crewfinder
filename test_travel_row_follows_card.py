"""Booking dialog, Stage 3 acceptance (booking 12214): after the rate card
changed to M&OP the Travel row stayed until the dialog was reopened. Changing
the card now re-reads the pricing context, so the row goes on M&OP and comes
back off it.

Runs the page's own functions under node (js_harness) against a fake server
whose pricing-context read says travel_allowed only on Standard."""

import json
import unittest

import js_harness as H

FUNCS = H.extract(
    "bkSaveRateCard", "bkLoadRateCard", "bkRateCardMarkup", "bkRateCardHead", "rcPickerValue",
    "bkLoadPricingContext", "bkPctxReload", "bkRenderTravel", "bkRenderEvent", "bkPctxMsg",
    "bkPctxLink", "pcFmtMinutes",
) if H.NODE else ""

SCRIPT = H.DOM_PRELUDE + r"""
var _bkRateCardBusy = {}, _bkRateCardState = {};
var _bkPctx = { bookingId: null, data: null }, _bkPctxBusy = false;
function bkRerenderCalls(){}
window._cohort = 'admin';

// The server: one booking's card, and the pricing-context read that follows it.
var card = 'standard', cardPostOk = true, pctxReads = 0;
async function fetch(url, opts){
  var reply = function(body, ok){ return { ok: ok !== false, status: ok === false ? 502 : 200,
                                           json: async function(){ return body; } }; };
  if(/\/rate-card$/.test(url) && opts && opts.method === 'POST'){
    if(!cardPostOk) return reply({ error: 'refused' }, false);
    var b = JSON.parse(opts.body);
    card = !b.mopt ? 'standard' : (b.australian_open ? 'mopt-ao' : 'mopt');
    return reply({ set_code: card, label: card });
  }
  if(/\/rate-card/.test(url)) return reply({ is_set: true, label: card, can_change: true, mopt: card !== 'standard' });
  if(/\/pricing-context/.test(url)){
    pctxReads++;
    return reply({ travel_allowed: card === 'standard', card_ok: true, can_change: true,
                   venue_travel: { venue_id: 431, return_minutes: 180 }, call_flags: {}, event: null });
  }
  throw new Error('unexpected ' + url);
}

// The card editor's controls.
var moptBox = _el('rc-bk-ratecard-wrap-mopt'), ao = null;
document.querySelector = function(sel){ return /-ao"\]:checked/.test(sel) && ao !== null ? { value: ao } : null; };
_el('bk-ratecard-wrap'); _el('bk-travel-wrap'); _el('bk-event-wrap');

var d = { venue: { id: 431, name: 'ZZ Venue' }, calls: [] };
window._curBookingEdit = d;

(async function(){
  var out = {};
  await bkLoadPricingContext('12214', d);
  out.standard = _el('bk-travel-wrap').innerHTML;

  moptBox.checked = true; ao = 'no';
  await bkSaveRateCard('12214', 'bk-ratecard-wrap');
  out.mopt = _el('bk-travel-wrap').innerHTML;

  ao = 'yes';
  await bkSaveRateCard('12214', 'bk-ratecard-wrap');
  out.mopt_ao = _el('bk-travel-wrap').innerHTML;

  moptBox.checked = false; ao = null;
  await bkSaveRateCard('12214', 'bk-ratecard-wrap');
  out.back = _el('bk-travel-wrap').innerHTML;

  var before = pctxReads;
  cardPostOk = false; moptBox.checked = true; ao = 'no';
  await bkSaveRateCard('12214', 'bk-ratecard-wrap');
  out.refused_reads = pctxReads - before;
  out.refused = _el('bk-travel-wrap').innerHTML;
  console.log(JSON.stringify(out));
})().catch(function(e){ console.error(e); process.exit(1); });
"""


@unittest.skipUnless(H.NODE, "node not installed")
class TravelRowFollowsCard(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.out = H.run(FUNCS + "\n" + SCRIPT)

    def test_standard_shows_travel(self):
        self.assertIn("3 h return", self.out["standard"])

    def test_changing_to_mopt_hides_it_straight_away(self):
        self.assertEqual(self.out["mopt"], "")
        self.assertEqual(self.out["mopt_ao"], "")

    def test_changing_back_off_mopt_shows_it_again(self):
        self.assertIn("3 h return", self.out["back"])

    def test_a_refused_card_change_leaves_it_alone(self):
        self.assertEqual(self.out["refused_reads"], 0)
        self.assertIn("3 h return", self.out["refused"])


if __name__ == "__main__":
    unittest.main()
