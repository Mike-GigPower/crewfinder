"""Booking Documents tab (BRIEF-booking-documents-figures §7.2).

Runs the page's own functions under node (js_harness) against a fake document
list. Skips when node isn't installed.

The bug these were written for: clicking a LINK row called
/documents/<id>/open, which only ever signs a stored object, so it answered
"Document not found" — true, and useless — and then showed it in a browser
alert(). A link has nothing to sign: it opens its own url.
"""

import unittest

import js_harness as H

FUNCS = H.extract(
    "bkSafe", "bkDocMsg", "bkDocFind", "bkDocOpen", "bkDocIcon",
    "bkDocCatLabel", "bkDocWhen", "bkDocsRender", "bkDocsCount",
) if H.NODE else ""


def _const(name):
    """Lift a `var NAME = [...];` declaration out of index.html.

    js_harness.extract() only knows about functions, and bkDocCatLabel reads
    BK_DOC_CATEGORIES. Taking the real declaration rather than retyping the six
    categories here keeps one source of truth — a copy would go stale the first
    time Q3's list changes and the test would still pass.
    """
    src = H.page()
    i = src.index("var %s = [" % name)
    j = src.index("];", i)
    return src[i:j + 2]


CONSTS = _const("BK_DOC_CATEGORIES") if H.NODE else ""

# Two rows: one link, one file. window.open and fetch are both recorded rather
# than performed, so the test can say which route each row took.
PRELUDE = r"""
var _opened = [];
var _fetched = [];
window.open = function(u, t){ _opened.push(u); };
window.fetch = function(u){
  _fetched.push(u);
  return Promise.resolve({ ok: false, json: function(){
    return Promise.resolve({ error: 'Document not found' }); } });
};
var fetch = window.fetch;
window._cohort = 'admin';
window._bkDocs = { 12220: { documents: [
  { id: 'aaaaaaaa-0000-0000-0000-000000000001', kind: 'link', category: 'quote',
    title: 'Venue plan', note: null, url: 'https://example.com/plan.pdf',
    mime_type: null, size_bytes: null, added_by_user_id: 10261,
    added_at: '2026-11-11T02:00:00Z' },
  { id: 'bbbbbbbb-0000-0000-0000-000000000002', kind: 'file', category: 'invoice',
    title: 'Invoice 34741', note: null, url: null,
    mime_type: 'application/pdf', size_bytes: 1024, added_by_user_id: 4211,
    added_at: '2026-11-11T03:00:00Z' }
], quote: null } };
_el('bk-docs-host');
_el('bk-doc-msg');
"""

LINK_ID = "aaaaaaaa-0000-0000-0000-000000000001"
FILE_ID = "bbbbbbbb-0000-0000-0000-000000000002"


class LinkRowTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_link_row_opens_its_url_and_never_calls_open(self):
        """The bug: a link row went to /documents/<id>/open and was told
        "Document not found"."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        bkDocOpen(12220, '%s');
        console.log(JSON.stringify({opened: _opened, fetched: _fetched,
                                    msg: _els['bk-doc-msg'].innerHTML}));
        """ % LINK_ID)
        self.assertEqual(out["opened"], ["https://example.com/plan.pdf"])
        self.assertEqual(out["fetched"], [], "a link must not call /open")
        self.assertEqual(out["msg"], "", "nothing went wrong, so say nothing")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_file_row_still_goes_through_open(self):
        """The other half: files DO need a signed URL, so they still call it."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        bkDocOpen(12220, '%s');
        console.log(JSON.stringify({opened: _opened, fetched: _fetched}));
        """ % FILE_ID)
        self.assertEqual(len(out["fetched"]), 1)
        self.assertIn("/documents/%s/open" % FILE_ID, out["fetched"][0])
        self.assertEqual(out["opened"], [], "nothing opens until the URL is signed")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_link_with_no_address_says_so_in_the_tab(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        window._bkDocs[12220].documents[0].url = null;
        bkDocOpen(12220, '%s');
        console.log(JSON.stringify({opened: _opened, fetched: _fetched,
                                    msg: _els['bk-doc-msg'].innerHTML}));
        """ % LINK_ID)
        self.assertEqual(out["opened"], [])
        self.assertEqual(out["fetched"], [])
        self.assertIn("no address", out["msg"])


class ErrorsStayInTheTabTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_failed_file_open_writes_into_the_tab(self):
        """The failure path must reach #bk-doc-msg, not a browser alert. The
        stubbed fetch above always fails, which is what makes this a test."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        var _alerted = [];
        alert = function(m){ _alerted.push(m); };
        bkDocOpen(12220, '%s');
        setTimeout(function(){
          console.log(JSON.stringify({alerted: _alerted,
                                      msg: _els['bk-doc-msg'].innerHTML}));
        }, 0);
        """ % FILE_ID)
        self.assertEqual(out["alerted"], [], "no alert() in the Documents tab")
        self.assertIn("Document not found", out["msg"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_render_always_lays_down_the_message_area(self):
        """bkDocMsg needs somewhere to write for EVERY cohort, not only for
        Admins (the Add/Remove form host is admin-only)."""
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        window._cohort = 'operations';
        bkDocsRender(12220);
        console.log(JSON.stringify({html: _els['bk-docs-host'].innerHTML}));
        """)
        self.assertIn('id="bk-doc-msg"', out["html"])
        self.assertNotIn("Add file", out["html"], "read-only cohorts get no Add")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_tab_source_has_no_alert_confirm_or_prompt(self):
        """§7: errors are shown inside the tab. This reads the real block out
        of index.html, so it fails if one creeps back in later."""
        src = H.page()
        start = src.index("// ─── BOOKING DOCUMENTS & FIGURES TABS")
        end = src.index("function crewNotesInit", start)
        block = src[start:end]
        for bad in ("alert(", "confirm(", "prompt("):
            self.assertNotIn(bad, block,
                             "%s found in the booking documents/figures tab code" % bad)


class AddedByTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_added_by_uses_the_name_when_the_route_sent_one(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        window._bkDocs[12220].documents[0].added_by_name = 'Mike Lack';
        bkDocsRender(12220);
        console.log(JSON.stringify({html: _els['bk-docs-host'].innerHTML}));
        """)
        self.assertIn("added by Mike Lack", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_added_by_falls_back_to_the_id(self):
        out = H.run(H.DOM_PRELUDE + CONSTS + FUNCS + PRELUDE + r"""
        bkDocsRender(12220);
        console.log(JSON.stringify({html: _els['bk-docs-host'].innerHTML}));
        """)
        self.assertIn("added by #10261", out["html"])
        self.assertIn("added by #4211", out["html"])


if __name__ == "__main__":
    unittest.main()
