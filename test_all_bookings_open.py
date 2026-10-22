"""All Bookings, Stage 3 acceptance (booking 12215): a booking with no calls
couldn't be opened, so its Details tab (event, travel) was unreachable. The
list's rows only expand; calls open the call dialog, and the calendar opens a
booking from its bar, which a booking with no calls doesn't have. The
expanded row now has "Open booking", with calls or without.

Runs the page's own abToggle under node (js_harness), then runs the onclick
it rendered and checks it opens the booking dialog for that booking."""

import unittest

import js_harness as H

FUNCS = H.extract("abToggle") if H.NODE else ""

SCRIPT = H.DOM_PRELUDE + r"""
function _abCallWhen(){ return 'Thu 22 Oct 08:00'; }
function bkLoadCheckinMode(){}
function gsheetLoadStatus(){}
var opened = [];
function openBookingDialog(id){ opened.push(String(id)); }
function openCallDialog(){}

var bookings = { '12215': { calls: [] },
                 '12214': { calls: [{ call_id: 39322, call_name: 'Load In', confirmed: 1, required: 2 }] } };
async function fetch(url){
  var id = url.split('/').pop();
  return { ok: true, status: 200, json: async function(){ return bookings[id]; } };
}

// Run the onclick of the "Open booking" button rendered into the row body.
function clickOpen(id){
  var html = _el('ab-calls-' + id).innerHTML;
  var m = /<div onclick="(openBookingDialog\([^"]*\))"[^>]*>[^<]*Open booking<\/div>/.exec(html);
  if(!m) return false;
  eval(m[1].replace(/&#39;/g, "'"));
  return true;
}

(async function(){
  var out = {};
  for(var id of ['12215', '12214']){
    var body = _el('ab-calls-' + id); body.style.display = 'none';
    _el('ab-chev-' + id);
    await abToggle(id);
    out[id] = { button: clickOpen(id), html: body.innerHTML };
  }
  out.opened = opened;
  console.log(JSON.stringify(out));
})().catch(function(e){ console.error(e); process.exit(1); });
"""


@unittest.skipUnless(H.NODE, "node not installed")
class AllBookingsOpen(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.out = H.run(FUNCS + "\n" + SCRIPT)

    def test_a_booking_with_no_calls_can_be_opened(self):
        self.assertTrue(self.out["12215"]["button"], self.out["12215"]["html"])
        self.assertIn("No calls on this booking.", self.out["12215"]["html"])

    def test_a_booking_with_calls_can_be_opened_too(self):
        self.assertTrue(self.out["12214"]["button"], self.out["12214"]["html"])
        self.assertIn("Load In", self.out["12214"]["html"])

    def test_each_opens_its_own_booking(self):
        self.assertEqual(self.out["opened"], ["12215", "12214"])


if __name__ == "__main__":
    unittest.main()
