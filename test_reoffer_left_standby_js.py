"""The re-offer prompt for a LEFT STANDBY row (slice 2, D6).

Runs the page's own reofferDeclinedFlow() under node (js_harness) with the
dry-run preview stubbed. Skips when node isn't installed.

The bug these were written for: 13 -> 0 was wired into reofferDeclinedFlow for
D6 (re-offerable, like 6 and 10) but the prompt's wording was not, so ops
confirming a re-offer for someone who took themselves off standby read

    They declined this call on Sat 7 Nov, 09:12.

which is wrong twice over.

  * "declined" — slice 2 D1 is explicit that leaving standby is NOT a decline
    and must not read like one anywhere in the UI.
  * the date — on a 13 row, responded_at is when they ACCEPTED standby, not
    when they left it. 0 -> 7 fires arm 3 of ccm_responded_at_upd and stamps
    responded_at; 7 -> 13 fires no arm, so the stamp is never touched again.
    The declined wording therefore named the wrong event AND dated it from an
    unrelated one.

The figure for when they left is left_at, from get-booking.php (unix int),
carried to the flow on the select as data-left-at.

The same function's two 409 race alerts had the same fault from the other
direction: `prev === 10 ? 'No response' : 'Declined'`, written out twice, so a
13 row that moved under ops was reported as "no longer Declined". Both now read
one hoisted goatStatusLabel(prev).
"""

import unittest

import js_harness as H

FUNCS = H.extract(
    "fmtStamp24", "reofferWhen", "reofferRefusalText", "reofferDeclinedFlow",
    "crewStatusIsWithdrawn", "crewStatusBadge", "crewStatusInt",
    "crewStatusColors", "crewLeftStandbyTitle", "crewIsPromoPending",
    "goatStatusLabel", "crewStatusControl",
) if H.NODE else ""


def _decl(name, open_c, close_c):
    """Lift a `var NAME = <literal>;` declaration out of index.html.

    js_harness.extract() only knows about functions, and these are the real
    label/option sources the control and its prompt read. Taking the
    declaration rather than retyping it keeps one source of truth: a copy here
    would go stale the first time a status is added and the test would still
    pass.
    """
    src = H.page()
    i = src.index("var %s = %s" % (name, open_c))
    j = src.index("%s;" % close_c, i)
    return src[i:j + 2]


CONSTS = "\n".join([
    _decl("CREW_WITHDRAWN_WORDS", "{", "}"),
    _decl("CREW_WITHDRAWN_LABEL", "{", "}"),
    _decl("CREW_STATUS_OPTS", "[", "]"),
    _decl("GOAT_STATUS_LABEL", "{", "}"),
]) if H.NODE else ""

# 19 Nov 2026 14:30 Melbourne (AEDT, UTC+11). fmtStamp24 pins the zone
# explicitly, so this is the same figure whatever the test machine is set to.
LEFT_AT = 1795059000

# Everything reofferDeclinedFlow touches, recorded rather than performed: the
# prompt's html is captured and 'Cancel' is answered, so no second fetch runs
# and nothing is written.
# Everything reofferDeclinedFlow touches, recorded rather than performed. The
# two fetches are answered separately (the dry run carries dry_run:true), so a
# test can make either one race: _previewStatus/_preview for the preview,
# _applyStatus/_apply for the write. goatChoice captures the prompt and answers
# _answer, so nothing is sent unless a test says 'send'.
PRELUDE = r"""
var _shown = [];
var _alerted = [];
var _reloaded = [];
var _answer = 'cancel';
var _preview = {ok: true, dry_run: true, calls: [], responded_at: null};
var _apply   = {ok: true, reopened: [55501], calls: [], push: 'sent'};
var _previewStatus = 200, _applyStatus = 200;
window.fetch = function(u, o){
  var body = {};
  try{ body = JSON.parse((o && o.body) || '{}'); }catch(e){}
  var dry = !!body.dry_run;
  var st  = dry ? _previewStatus : _applyStatus;
  var j   = dry ? _preview : _apply;
  return Promise.resolve({ ok: (st >= 200 && st < 300), status: st,
                           json: function(){ return Promise.resolve(j); } });
};
var fetch = window.fetch;
function goatChoice(spec){ _shown.push(spec); return Promise.resolve(_answer); }
function openCallDialog(b, c){ _reloaded.push([b, c]); }
function goatInfoToast(m){ }
function alert(m){ _alerted.push(m); }
function _sel(attrs){
  return { disabled: false, value: '', _a: attrs,
           getAttribute: function(k){ return this._a.hasOwnProperty(k) ? this._a[k] : null; },
           setAttribute: function(k, v){ this._a[k] = v; } };
}
function _ask(prev, attrs, done){
  var sel = _sel(attrs);
  reofferDeclinedFlow('12220', '55501', 4211, sel, prev).then(function(r){
    done({html: _shown.length ? _shown[0].html : '', title: _shown.length ? _shown[0].title : '',
          result: r, alerted: _alerted, reloaded: _reloaded});
  });
}
"""


class LeftStandbyPromptTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_13_row_is_not_described_as_a_decline(self):
        """The fix. D1: leaving standby must not read like a decline."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _ask(13, {'data-prev': '13', 'data-crew-name': 'Joe Blow',
                  'data-left-at': '%d'},
             function(o){ console.log(JSON.stringify(o)); });
        """ % LEFT_AT)
        self.assertIn("They took themselves off standby on ", out["html"])
        self.assertNotIn("declined", out["html"])
        # The right figure, from left_at — not responded_at.
        self.assertIn("19 Nov 2026", out["html"])
        self.assertIn("14:30", out["html"])
        self.assertEqual(out["alerted"], [])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_no_figure_gives_the_bare_sentence(self):
        """left_at is null on a row written before MIGRATION-leave-standby.sql
        ran, so there is no data-left-at. Drop the clause, not the sentence."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _ask(13, {'data-prev': '13', 'data-crew-name': 'Joe Blow'},
             function(o){ console.log(JSON.stringify(o)); });
        """)
        self.assertIn("They took themselves off standby.", out["html"])
        self.assertNotIn(" on ", out["html"].split("</div>")[0])
        self.assertNotIn("NaN", out["html"])
        self.assertNotIn("Invalid Date", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_13_row_never_shows_the_standby_accept_stamp(self):
        """The sharper half of the bug: a 13 row DOES carry responded_at (the
        moment they accepted standby). It must not reach the prompt."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _preview.responded_at = '2026-11-07T09:12:00';
        _ask(13, {'data-prev': '13', 'data-crew-name': 'Joe Blow',
                  'data-left-at': '%d'},
             function(o){ console.log(JSON.stringify(o)); });
        """ % LEFT_AT)
        self.assertNotIn("7 Nov", out["html"])
        self.assertNotIn("09:12", out["html"])
        self.assertIn("19 Nov 2026", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_prompt_still_offers_to_send(self):
        """Wording only — 13 is re-offerable (D6), so the rest of the prompt is
        unchanged: Sending re-opens it and pushes."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _ask(13, {'data-prev': '13', 'data-crew-name': 'Joe Blow',
                  'data-left-at': '%d'},
             function(o){ console.log(JSON.stringify(o)); });
        """ % LEFT_AT)
        self.assertIn("Sending re-opens it", out["html"])
        self.assertIn("Joe Blow", out["title"])


class TheOtherTwoPrevsAreUnchangedTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_declined_still_reads_declined_and_dates_from_responded_at(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _preview.responded_at = '2026-11-07T09:12:00';
        _ask(6, {'data-prev': '6', 'data-crew-name': 'Joe Blow'},
             function(o){ console.log(JSON.stringify(o)); });
        """)
        self.assertIn("They declined this call on ", out["html"])
        self.assertIn("7 Nov", out["html"])
        self.assertIn("09:12", out["html"])
        self.assertNotIn("off standby", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_no_response_still_reads_no_response(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _ask(10, {'data-prev': '10', 'data-crew-name': 'Joe Blow'},
             function(o){ console.log(JSON.stringify(o)); });
        """)
        self.assertIn("didn't respond to this offer", out["html"])
        self.assertNotIn("off standby", out["html"])
        self.assertNotIn("declined", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_race_alert_names_left_standby_not_declined(self):
        """The 409 race on the PREVIEW. The ternary here was written out twice
        and neither copy learned 13, so a row that had left standby was
        reported as "no longer Declined". One goatStatusLabel() call now."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _previewStatus = 409;
        _preview = {error: 'not_declined'};
        _ask(13, {'data-prev': '13', 'data-crew-name': 'Joe Blow'},
             function(o){ console.log(JSON.stringify(o)); });
        """)
        self.assertEqual(out["alerted"],
                         ["They're no longer Left standby on this call "
                          "— someone changed it. Reloading."])
        self.assertEqual(out["result"], "handled")
        self.assertEqual(len(out["reloaded"]), 1, "the stale dialog is reloaded")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_race_alert_on_the_write_names_left_standby_too(self):
        """The SECOND copy, after Send: the row moved between the dry run and
        the write. Same sentence, and the one the first fix missed."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        _answer = 'send';
        _applyStatus = 409;
        _apply = {error: 'not_declined'};
        _ask(13, {'data-prev': '13', 'data-crew-name': 'Joe Blow',
                  'data-left-at': '%d'},
             function(o){ console.log(JSON.stringify(o)); });
        """ % LEFT_AT)
        self.assertEqual(out["alerted"],
                         ["They're no longer Left standby on this call "
                          "— someone changed it. Reloading."])
        self.assertEqual(out["result"], "handled")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_race_alert_still_names_declined_and_no_response(self):
        """The other two prevs keep their own words. goatStatusLabel covers
        all three, so this is what stops the shared label drifting."""
        for prev, word in ((6, "Declined"), (10, "No response")):
            out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
            _previewStatus = 409;
            _preview = {error: 'not_declined'};
            _ask(%d, {'data-prev': '%d', 'data-crew-name': 'Joe Blow'},
                 function(o){ console.log(JSON.stringify(o)); });
            """ % (prev, prev))
            self.assertEqual(len(out["alerted"]), 1)
            self.assertIn("no longer %s on this call" % word, out["alerted"][0])


class TheAttributeIsActuallyEmittedTests(unittest.TestCase):
    """The prompt can only read data-left-at if crewStatusControl writes it.
    Without these the fix passes its own tests and does nothing in the app."""

    CREW = (r"""
        window._cohort = 'admin';
        var c = {id: 4211, status: '%s', firstname: 'Joe', lastname: 'Blow',
                 left_at: %s};
        console.log(JSON.stringify({html: crewStatusControl(c, '12220', '55501')}));
    """)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_13_row_carries_left_at(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS
                    + (self.CREW % ("leftstandby", LEFT_AT)))
        self.assertIn('data-left-at="%d"' % LEFT_AT, out["html"])
        self.assertIn('data-prev="13"', out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_13_row_with_no_figure_carries_no_attribute(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS
                    + (self.CREW % ("leftstandby", "null")))
        self.assertNotIn("data-left-at", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_declined_row_carries_no_left_at(self):
        """Belt and braces: left_at is null on every row but a 13, but if one
        ever arrived on a 6 the prompt must not start dating declines from it."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS
                    + (self.CREW % ("declined", LEFT_AT)))
        self.assertNotIn("data-left-at", out["html"])


if __name__ == "__main__":
    unittest.main()
