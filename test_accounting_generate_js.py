#!/usr/bin/env python3
"""The Generate button, run for real under node (BRIEF-accounting-slice-b §7).

THREE GUARANTEES, taken from index.html's own code rather than described:

  1. THE BUTTON CANNOT FIRE TWICE. This is the one that matters: two requests
     means two invoices, and an invoice cannot be rolled back. The test clicks
     it twice — and a third time while the first request is still in flight —
     and asserts exactly ONE request was made.
  2. A BLOCKED BOOKING HAS NO GENERATE BUTTON. Not a disabled one (§5.2).
  3. A FAILURE READS AS A FAILURE. An error is rendered as an error, never as
     an empty panel, and the half-failed case does not invite a second press.

A small DOM is built here rather than in js_harness, because invGenerateModal
creates its own overlay and the shared DOM_PRELUDE has no createElement worth
the name. The shim is deliberately thin: it stores innerHTML as a string and
resolves querySelector by scanning it, which is enough to find the two buttons
and nothing like enough to fake behaviour.

Skips when node isn't installed.

Run:  python3 test_accounting_generate_js.py
"""
import json
import unittest
from html.parser import HTMLParser

import accounting_invoices as AI
import js_harness as H

FUNCS = H.extract("escapeHtml", "invMoney", "invSafe", "invGenerateModal",
                  "invGenBookingUsable", "invGenCallsHtml",
                  "rdyBookingHtml", "rdyCallsHtml", "rdyError") if H.NODE else ""

# A DOM just rich enough for the modal: elements whose innerHTML is a string,
# and a querySelector that finds ids and data- attributes inside it.
PRELUDE = r"""
var window = globalThis;
function decodeEntities(s){ return s == null ? '' : String(s); }

var _fetchCalls = [], _toasts = [], _opened = [], _refreshed = 0;
function goatInfoToast(m){ _toasts.push(m); }
function openInvoiceDialog(id){ _opened.push(Number(id)); }
function rdyRefreshAfterGenerate(){ _refreshed++; }
var _rdyState = { loaded:false, all:false, data:null, open:{} };

function mkStub(id, attrs){
  var s = { id:id, disabled:false, textContent:'', onclick:null, _attrs:attrs||{},
            getAttribute:function(n){ return (n in s._attrs) ? s._attrs[n] : null; },
            style:{}, innerHTML:'' };
  return s;
}

function mkEl(tag){
  var el = { tagName:tag, style:{cssText:''}, _kids:[], _byId:{}, _data:[],
             _removed:false, _html:'', _listeners:[] };
  el.appendChild   = function(c){ el._kids.push(c); return c; };
  el.remove        = function(){ el._removed = true; };
  el.addEventListener = function(k, f){ el._listeners.push([k, f]); };
  el.querySelector = function(sel){
    if(sel.charAt(0) === '#') return el._byId[sel.slice(1)] || null;
    var m = sel.match(/^\[([a-zA-Z-]+)\]$/);
    if(m){
      for(var i = 0; i < el._data.length; i++)
        if(m[1] in el._data[i]._attrs) return el._data[i];
    }
    return null;
  };
  Object.defineProperty(el, 'innerHTML', {
    get: function(){ return el._html; },
    set: function(v){
      el._html = String(v);
      el._byId = {}; el._data = [];
      var re = /id="([^"]+)"/g, m;
      while((m = re.exec(el._html))) el._byId[m[1]] = mkStub(m[1]);
      var re2 = /data-openinv="([0-9]+)"/g;
      while((m = re2.exec(el._html)))
        el._data.push(mkStub('openinv', {'data-openinv': m[1]}));
    }
  });
  return el;
}

var _overlays = [];
var document = {
  body: { appendChild: function(c){ _overlays.push(c); return c; },
          removeChild: function(){} },
  createElement: mkEl,
  getElementById: function(){ return null; },
  querySelector: function(){ return null; },
  querySelectorAll: function(){ return []; },
  addEventListener: function(){},
  removeEventListener: function(){},
};

// The overlay the modal just made, and the box inside it.
function lastBox(){ return _overlays[_overlays.length - 1]._kids[0]; }
function btn(id){ return lastBox().querySelector('#' + id); }

// Everything the person can read: the box's own markup, plus whatever msg()
// put into #gen-msg. The shim keeps a child's innerHTML on the child (a real
// browser would reflect it in the parent), so both are read and joined.
function shownHtml(){
  var box = lastBox();
  var m   = box.querySelector('#gen-msg');
  return box.innerHTML + (m ? m.innerHTML : '');
}

// fetch: records every call and answers from _answer. `_hold` keeps the
// promise unresolved so a second click can be tried mid-flight.
var _answer = {ok:true, status:200, body:{}};
var _hold = false, _release = null;
function fetch(url, opts){
  _fetchCalls.push({url:url, opts:opts, body: JSON.parse((opts||{}).body || '{}')});
  var resp = { ok:_answer.ok, status:_answer.status,
               json: function(){ return Promise.resolve(_answer.body); } };
  if(_hold) return new Promise(function(res){ _release = function(){ res(resp); }; });
  return Promise.resolve(resp);
}
function sleep(){ return new Promise(function(r){ setTimeout(r, 0); }); }
"""

BOOKING = {
    "booking_id": 90001, "booking_name": "Test Show @ Test Venue",
    "booking_reference": "TEST-1", "customer_id": 90024, "customer_name": "Test Customer Pty Ltd",
    "ready_calls": [{"call_id": 37598, "call_name": "Show", "start_date": 1748131200,
                     "crew_rows": 9, "hours": "72.00", "expected_charge": "32783.95",
                     "date_text": "Sat 23 May", "expected_ex_gst": 32783.95}],
    "stuck_calls": [], "unlocked_calls": 0, "locked_no_crew_calls": 0,
    "blocked": False, "expected_exact": True, "notes": [], "block_reason": "",
    "ready_count": 1, "date_range": "Sat 23 May",
    "expected": {"ex_gst": 32783.95, "gst": 3278.40, "inc_gst": 36062.35},
    "fingerprint": "a" * 40,
}


def run(js, booking=None):
    b = json.dumps(booking if booking is not None else BOOKING)
    return H.run(PRELUDE + FUNCS + "\nvar B = " + b + ";\n" + js)


@unittest.skipUnless(H.NODE, "node not installed")
class CannotFireTwice(unittest.TestCase):
    """GUARANTEE 1. Two requests would be two invoices."""

    def test_two_clicks_in_a_row_make_one_request(self):
        out = run(r"""
        _answer = {ok:true, status:200,
                   body:{ok:true, invoice_id:34783, totals:{inc_gst:36062.35}}};
        invGenerateModal(B);
        var go = btn('gen-go');
        go.onclick();              // the real click
        go.onclick();              // the impatient second one
        (async function(){
          await sleep(); await sleep();
          console.log(JSON.stringify({n:_fetchCalls.length, opened:_opened,
                                      toasts:_toasts, refreshed:_refreshed}));
        })();
        """)
        self.assertEqual(out["n"], 1)
        self.assertEqual(out["opened"], [34783])
        self.assertEqual(out["refreshed"], 1)
        self.assertEqual(len(out["toasts"]), 1)
        self.assertIn("34783", out["toasts"][0])

    def test_a_click_while_the_request_is_in_flight_makes_no_request(self):
        """The strongest version: the first request has NOT come back yet."""
        out = run(r"""
        _hold = true;
        _answer = {ok:true, status:200, body:{ok:true, invoice_id:1, totals:{}}};
        invGenerateModal(B);
        var go = btn('gen-go');
        go.onclick();
        (async function(){
          await sleep();
          var midLabel = go.textContent, midDisabled = go.disabled;
          go.onclick(); go.onclick();        // three clicks, one request
          await sleep();
          var n = _fetchCalls.length;
          _release();
          await sleep(); await sleep();
          console.log(JSON.stringify({n:n, midLabel:midLabel,
                                      midDisabled:midDisabled,
                                      after:_fetchCalls.length}));
        })();
        """)
        self.assertEqual(out["n"], 1)
        self.assertEqual(out["after"], 1)
        self.assertTrue(out["midDisabled"])
        self.assertEqual(out["midLabel"], "Generating…")

    def test_the_button_comes_back_after_a_refusal_so_it_can_be_retried(self):
        """A `busy` refusal wrote nothing — pressing again is the right move."""
        out = run(r"""
        _answer = {ok:false, status:409, body:{error:'Someone is generating this '
                   + 'booking right now. Try again in a moment.', reason:'busy'}};
        invGenerateModal(B);
        var go = btn('gen-go');
        go.onclick();
        (async function(){
          await sleep(); await sleep();
          console.log(JSON.stringify({n:_fetchCalls.length, disabled:go.disabled,
                                      label:go.textContent,
                                      html:shownHtml()}));
        })();
        """)
        self.assertEqual(out["n"], 1)
        self.assertFalse(out["disabled"])
        self.assertEqual(out["label"], "Generate invoice")

    def test_the_posted_body_is_the_booking_and_its_fingerprint(self):
        out = run(r"""
        _answer = {ok:true, status:200, body:{ok:true, invoice_id:1, totals:{}}};
        invGenerateModal(B);
        btn('gen-go').onclick();
        (async function(){
          await sleep(); await sleep();
          console.log(JSON.stringify({body:_fetchCalls[0].body,
                                      url:_fetchCalls[0].url,
                                      method:_fetchCalls[0].opts.method}));
        })();
        """)
        self.assertEqual(out["method"], "POST")
        self.assertEqual(out["url"], "/api/accounting/invoices/generate")
        self.assertEqual(out["body"], {"booking_id": 90001, "fingerprint": "a" * 40})


@unittest.skipUnless(H.NODE, "node not installed")
class BlockedBooking(unittest.TestCase):
    """GUARANTEE 2. §5.2: no Generate button, and the reason on the row."""

    def html(self, booking):
        return run("console.log(JSON.stringify({h: rdyBookingHtml(B)}));",
                   booking)["h"]

    def test_a_blocked_booking_has_no_generate_button_at_all(self):
        b = dict(BOOKING, blocked=True,
                 block_reason="Call #37598 is on invoice #34012 but was never "
                              "marked invoiced")
        h = self.html(b)
        self.assertNotIn("rdy-gen", h)
        self.assertNotIn("Generate invoice", h)

    def test_a_blocked_booking_shows_the_chip_and_the_reason(self):
        b = dict(BOOKING, blocked=True,
                 block_reason="Call #37598 is on invoice #34012 but was never "
                              "marked invoiced")
        h = self.html(b)
        self.assertIn("rdy-blocked", h)
        self.assertIn("Blocked", h)
        self.assertIn("#34012", h)

    def test_a_normal_booking_does_have_the_button(self):
        """Otherwise the first test would pass by rendering nothing at all."""
        h = self.html(BOOKING)
        self.assertIn("rdy-gen", h)
        self.assertIn("Generate invoice", h)

    def test_a_booking_name_is_escaped_not_rendered(self):
        h = self.html(dict(BOOKING,
                           booking_name='<img src=x onerror=alert(1)>Sneaky'))
        self.assertNotIn("<img", h)
        self.assertIn("&lt;img", h)

    def test_the_notes_are_escaped_too(self):
        h = self.html(dict(BOOKING, notes=["<b>2</b> calls not locked yet"]))
        self.assertNotIn("<b>", h)

    def test_an_estimate_is_labelled_on_the_money(self):
        h = self.html(dict(BOOKING, expected_exact=False))
        self.assertIn("(estimate)", h)

    def test_an_exact_figure_is_not(self):
        self.assertNotIn("(estimate)", self.html(BOOKING))


@unittest.skipUnless(H.NODE, "node not installed")
class FailureReadsAsFailure(unittest.TestCase):
    """GUARANTEE 3."""

    def modal(self, answer):
        return run("""
        _answer = %s;
        invGenerateModal(B);
        btn('gen-go').onclick();
        (async function(){
          await sleep(); await sleep();
          console.log(JSON.stringify({html: shownHtml(),
                                      disabled: btn('gen-go').disabled,
                                      opened:_opened, closed:_overlays[0]._removed}));
        })();
        """ % json.dumps(answer))

    def test_a_missing_php_file_says_so_in_the_modal(self):
        out = self.modal({"ok": False, "status": 404, "body": {
            "error": "SmartStaff doesn't have generate-invoice.php (404). "
                     "It may not be deployed on this server."}})
        self.assertIn("not be deployed", out["html"])
        self.assertIn("inv-error", out["html"])
        self.assertFalse(out["closed"])

    def test_a_lapsed_session_says_sign_out_and_back_in(self):
        out = self.modal({"ok": False, "status": 502, "body": {
            "error": "SmartStaff session expired. Sign out and back in."}})
        self.assertIn("session expired", out["html"])
        self.assertIn("Sign out", out["html"])

    def test_the_half_failed_case_lists_the_checks_and_blocks_the_button(self):
        """The invoice EXISTS. A second press is the one thing that must not
        be easy, so the button stays disabled and an Open-invoice link is
        offered instead."""
        out = self.modal({"ok": False, "status": 502, "body": {
            "error": "SmartStaff created the invoice but these checks failed. "
                     "Don't generate again — open the invoice and check it in "
                     "SmartStaff.",
            "invoice_id": 34783,
            "failed": ["the invoice has no priced lines",
                       "the invoice came to 100.00 but 32783.95 was expected"]}})
        self.assertIn("no priced lines", out["html"])
        self.assertIn("32783.95 was expected", out["html"])
        self.assertIn("Don&#39;t generate again", out["html"])
        self.assertIn("Open invoice #34783", out["html"])
        self.assertTrue(out["disabled"])
        self.assertFalse(out["closed"])
        self.assertEqual(out["opened"], [])      # not opened behind their back

    def test_nothing_to_invoice_offers_the_invoice_it_named(self):
        out = self.modal({"ok": False, "status": 409, "body": {
            "error": "Nothing to invoice: the calls were invoiced since you "
                     "looked (invoice #34783)",
            "reason": "nothing_to_invoice", "latest_invoice": 34783}})
        self.assertIn("invoiced since you looked", out["html"])
        self.assertIn("Open invoice #34783", out["html"])
        self.assertTrue(out["disabled"])         # nothing left to generate

    def test_changed_asks_again_against_the_new_call_list(self):
        newb = dict(BOOKING, ready_count=3,
                    expected={"ex_gst": 1.0, "gst": 0.1, "inc_gst": 1.1})
        out = self.modal({"ok": False, "status": 409, "body": {
            "error": "The calls on this booking changed since you looked. "
                     "Refresh and check before generating.",
            "reason": "changed", "booking": newb}})
        self.assertIn("changed since you looked", out["html"])
        self.assertIn("3 calls", out["html"])    # redrawn from the new booking
        self.assertFalse(out["disabled"])        # they can agree and go again
        self.assertFalse(out["closed"])

    def test_a_dead_network_does_not_claim_it_failed_cleanly(self):
        out = run(r"""
        fetch = function(){ return Promise.reject(new Error('network down')); };
        invGenerateModal(B);
        btn('gen-go').onclick();
        (async function(){
          await sleep(); await sleep();
          console.log(JSON.stringify({html: shownHtml(),
                                      disabled: btn('gen-go').disabled}));
        })();
        """)
        self.assertIn("network down", out["html"])
        self.assertIn("check Invoices before generating again", out["html"])

    def test_the_ready_list_error_is_an_error_not_an_empty_panel(self):
        out = run(r"""
        var el = { innerHTML: '' }, cnt = { textContent: 'x' };
        document.getElementById = function(id){
          return id === 'rdy-list' ? el : (id === 'rdy-count' ? cnt : null); };
        rdyError('the query failed');
        console.log(JSON.stringify({html: el.innerHTML, count: cnt.textContent}));
        """)
        self.assertIn("inv-error", out["html"])
        self.assertIn("the query failed", out["html"])
        self.assertIn("Try again", out["html"])
        self.assertEqual(out["count"], "")


@unittest.skipUnless(H.NODE, "node not installed")
class ConfirmStatesTheConsequence(unittest.TestCase):
    """§5.3 / review R5 — in the modal the person actually reads."""

    def body(self, booking=None):
        return run("invGenerateModal(B);"
                   "console.log(JSON.stringify({h: lastBox().innerHTML}));",
                   booking)["h"]

    def test_it_names_the_booking_and_the_money_both_ways(self):
        h = self.body()
        self.assertIn("Test Show @ Test Venue", h)
        self.assertIn("#90001", h)
        self.assertIn("$32,783.95", h)
        self.assertIn("$36,062.35", h)

    def test_it_says_smartstaff_assigns_the_number_now(self):
        self.assertIn("assigns its number now", self.body())

    def test_it_says_the_xero_export_picks_it_up(self):
        self.assertIn("next Xero export", self.body())

    def test_it_says_lines_cannot_be_edited_here(self):
        self.assertIn("edited in THE GOAT yet", self.body())

    def test_unlocked_calls_get_the_amber_warning(self):
        h = self.body(dict(BOOKING, unlocked_calls=2))
        self.assertIn("2 calls", h)
        # A JS literal, not escaped data — unlike the server's own messages,
        # which arrive through escapeHtml (see the half-failed test).
        self.assertIn("aren't locked yet", h)
        self.assertIn("second invoice", h)

    def test_no_warning_when_everything_is_locked(self):
        self.assertNotIn("second invoice", self.body())

    def test_an_estimate_is_called_an_estimate(self):
        self.assertIn("estimate", self.body(dict(BOOKING, expected_exact=False)))


# ──────────────────────────────────────────────────────────────────────────────
# FIX 1 (test, 19 Nov): a `changed` refusal redrew "0 calls · about — ex GST"
# with Generate still live. generate-invoice.php sends the booking as its RAW
# accounting-ready-lib.php row, which has no ready_count or expected; app.py
# now runs it through ready_view, the same as the list. These tests feed the
# modal exactly that: a PHP-shaped row, put through ready_view.

CHANGED_MSG = ("The calls on this booking changed since you looked. "
               "Refresh and check before generating.")

# generate-invoice.php's `booking`, key for key as goat_ready_scan() builds it
# (accounting-ready-lib.php, the $out[$bid] array): one call locked since the
# list was read, so 11 unlocked, two ready, and a new fingerprint.
PHP_CHANGED_BOOKING = {
    "booking_id": 90001, "booking_name": "Test Show @ Test Venue",
    "booking_reference": "TEST-1", "customer_id": 90024,
    "customer_name": "Test Customer Pty Ltd",
    "ready_calls": [
        {"call_id": 37598, "call_name": "Load in", "start_date": 1748131200,
         "crew_rows": 9, "hours": "72.000000", "expected_charge": "1000.0000"},
        {"call_id": 37599, "call_name": "Show call", "start_date": 1748217600,
         "crew_rows": 4, "hours": "20.000000", "expected_charge": "442.4000"},
    ],
    "stuck_calls": [], "unlocked_calls": 11, "locked_no_crew_calls": 0,
    "blocked": False, "expected_exact": True, "mixed_groups": 0, "null_calc": 0,
    "fingerprint": "b" * 40,
}


@unittest.skipUnless(H.NODE, "node not installed")
class ChangedRefusal(unittest.TestCase):

    def press_then_press_again(self, booking_in_409):
        """One press answered `changed`, then a second press, answered OK."""
        return run(r"""
        _answer = {ok:false, status:409, body:%s};
        invGenerateModal(B);
        btn('gen-go').onclick();
        (async function(){
          await sleep(); await sleep();
          var after = {html: shownHtml(), disabled: btn('gen-go').disabled,
                       closed: _overlays[0]._removed};
          _answer = {ok:true, status:200, body:{ok:true, invoice_id:34337, totals:{}}};
          if(!btn('gen-go').disabled) btn('gen-go').onclick();
          await sleep(); await sleep();
          after.posts = _fetchCalls.map(function(c){ return c.body; });
          console.log(JSON.stringify(after));
        })();
        """ % json.dumps(dict({"error": CHANGED_MSG, "reason": "changed"},
                              **({} if booking_in_409 is None
                                 else {"booking": booking_in_409}))))

    def test_the_redrawn_calls_and_amount_are_what_came_back(self):
        shaped = AI.ready_view(json.loads(json.dumps(PHP_CHANGED_BOOKING)))
        out = self.press_then_press_again(shaped)
        h = out["html"]
        self.assertIn("changed since you looked", h)
        self.assertIn("2 calls", h)
        self.assertNotIn("0 calls", h)
        self.assertNotIn("about <b>— ex GST", h)
        self.assertIn("$1,442.40 ex GST", h)     # 1000.00 + 442.40, from ready_view
        self.assertIn("$1,586.64 inc GST", h)
        self.assertIn("Load in", h)
        self.assertIn("Show call", h)
        self.assertIn("$1,000.00", h)
        self.assertIn("$442.40", h)
        self.assertIn("11 calls on this booking", h)   # the unlocked warning
        self.assertFalse(out["disabled"])
        self.assertFalse(out["closed"])

    def test_the_second_press_sends_the_new_fingerprint(self):
        shaped = AI.ready_view(json.loads(json.dumps(PHP_CHANGED_BOOKING)))
        out = self.press_then_press_again(shaped)
        self.assertEqual(len(out["posts"]), 2)
        self.assertEqual(out["posts"][0]["fingerprint"], "a" * 40)
        self.assertEqual(out["posts"][1], {"booking_id": 90001, "fingerprint": "b" * 40})

    def assert_refuses_to_redraw(self, booking_in_409):
        out = self.press_then_press_again(booking_in_409)
        self.assertTrue(out["disabled"], "Generate must be disabled")
        self.assertIn("Refresh list", out["html"])
        self.assertIn("data-rdyrefresh", out["html"])
        self.assertIn("changed since you looked", out["html"])
        self.assertEqual(len(out["posts"]), 1)    # no second request
        self.assertFalse(out["closed"])

    def test_no_booking_at_all_disables_generate(self):
        self.assert_refuses_to_redraw(None)

    def test_an_empty_call_list_disables_generate(self):
        self.assert_refuses_to_redraw(AI.ready_view(dict(PHP_CHANGED_BOOKING, ready_calls=[])))

    def test_the_raw_php_row_unshaped_disables_generate(self):
        """What the browser got before the fix: no `expected`. Never again
        "0 calls · about — ex GST" with a live button."""
        self.assert_refuses_to_redraw(json.loads(json.dumps(PHP_CHANGED_BOOKING)))

    def test_a_call_list_that_is_not_a_list_disables_generate(self):
        self.assert_refuses_to_redraw(dict(BOOKING, ready_calls={"37598": {}}))

    def test_a_different_booking_disables_generate(self):
        self.assert_refuses_to_redraw(dict(BOOKING, booking_id=90002))

    def test_a_missing_fingerprint_disables_generate(self):
        self.assert_refuses_to_redraw(dict(BOOKING, fingerprint=""))


# ──────────────────────────────────────────────────────────────────────────────
# FIX 2 (test, 19 Nov): on #34336 and #34337 the lines table, the totals and
# the calls filled half the 1,000 px invoice dialog. The Customer block never
# closed and nor did .inv-head-grid, so every section below became a cell of
# that two-column grid. Checked on the real markup invRenderDialog writes.

DIALOG_FUNCS = H.extract("escapeHtml", "invMoney", "invSafe", "invDate",
                         "invDescHtml", "invChip", "invRenderDialog") if H.NODE else ""

DIALOG_PRELUDE = r"""
var window = globalThis;
function decodeEntities(s){ return s == null ? '' : String(s); }
var _els = {};
var document = { getElementById: function(id){
  return _els[id] || (_els[id] = {id:id, innerHTML:'', textContent:'',
                                  addEventListener:function(){}}); } };
function entitySetCrumb(){}
function entityStackTop(){ return null; }
function entityBtn(label){ return '<button>' + label + '</button>'; }
function ssUrl(p){ return p; }
function invDialogClick(){}
"""

DIALOG_DATA = {
    "invoice": {"id": 90100, "generated": 1748131200, "exported": 0},
    "booking": {"id": 90001, "name": "Test Show @ Test Venue", "reference": "TEST-1"},
    "customer": {"name": "Test Customer Pty Ltd", "address": "1 Test St",
                 "suburb": "Testville", "state": "VIC", "postcode": "3000",
                 "phone": "03 9000 0000", "email": "accounts@example.com"},
    "contact": {"first_name": "Test", "last_name": "Person"},
    "venue": {"name": "Test Venue"},
    "lines": [{"is_spacer": False, "kind": "hourly", "qty": 2, "desc_main": "Crew",
               "desc_note": "", "date": "23/05/26", "weekday": "Saturday",
               "start": "09:00", "end": "17:00", "hours": 8, "break_time": "0:30",
               "rate": 45.0, "per_worker_display": 360.0, "line_total_display": 720.0}],
    "totals": {"ex_gst": 720.0, "gst": 72.0, "inc_gst": 792.0},
    "calls": [{"call_id": 37598, "call_name": "Show call", "found": True,
               "booking_id": 90001, "has_lines": 1, "current_invoice_id": 90100,
               "start_date": 1748131200}],
}


class _DivStack(HTMLParser):
    """Records which divs are open when each .inv-sec / .inv-tot starts."""
    def __init__(self):
        super().__init__()
        self.stack, self.seen = [], []
    def handle_starttag(self, tag, attrs):
        if tag != "div":
            return
        cls = dict(attrs).get("class") or ""
        if cls in ("inv-sec", "inv-tot", "inv-ln-wrap", "inv-call"):
            self.seen.append((cls, list(self.stack)))
        self.stack.append(cls)
    def handle_endtag(self, tag):
        if tag == "div" and self.stack:
            self.stack.pop()


@unittest.skipUnless(H.NODE, "node not installed")
class InvoiceDialogLayout(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        out = H.run(DIALOG_PRELUDE + DIALOG_FUNCS + "\ninvRenderDialog(90100, "
                    + json.dumps(DIALOG_DATA) + ");\n"
                    "console.log(JSON.stringify({h: _els['entity-modal-body'].innerHTML}));")
        cls.html = out["h"]
        cls.p = _DivStack()
        cls.p.feed(cls.html)

    def test_it_rendered_the_sections(self):
        names = [c for c, _ in self.p.seen]
        for c in ("inv-sec", "inv-tot", "inv-ln-wrap", "inv-call"):
            self.assertIn(c, names)

    def test_no_section_sits_inside_the_head_grid(self):
        for cls, open_divs in self.p.seen:
            self.assertNotIn("inv-head-grid", open_divs, cls + " is inside .inv-head-grid")
            self.assertNotIn("inv-head-block", open_divs, cls + " is inside .inv-head-block")

    def test_every_section_is_a_direct_child_of_the_dialog(self):
        for cls, open_divs in self.p.seen:
            if cls in ("inv-sec", "inv-tot", "inv-ln-wrap"):
                self.assertEqual(open_divs, ["inv-dlg"], cls)

    def test_every_div_is_closed(self):
        self.assertEqual(self.p.stack, [])

    def test_the_lines_keep_their_scroll_fallback(self):
        self.assertIn('<div class="inv-ln-wrap"><table class="inv-ln">', self.html)
        self.assertIn(".inv-ln-wrap{overflow-x:auto}", H.page())


if __name__ == "__main__":
    unittest.main(verbosity=2)
