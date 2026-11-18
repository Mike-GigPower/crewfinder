#!/usr/bin/env python3
"""The invoice dialog draws descriptions as TEXT (fix 1 §2, JS-side test).

THE WHOLE CHAIN, in one test: a raw SmartStaff description goes through
accounting_invoices.line_view() in Python, the two plain-text fields it
produces are handed to index.html's own invDescHtml() under node, and the HTML
that comes back is inspected.

What is being proved is a property, not a rendering: THE ONLY MARKUP IN THE
OUTPUT IS THE GOAT'S OWN <span>. Whatever tags, entities or payloads the
description held, nothing from the data arrives as markup. Neither half of the
chain can be checked alone — Python could strip perfectly and the page could
still put the raw column into innerHTML, which is what §6.2 originally said to
do and what this file exists to stop.

Skips when node isn't installed.

Run:  python3 test_accounting_invoices_js.py
"""
import json
import re
import unittest

import accounting_invoices as AI
import js_harness as H
from test_accounting_invoices import load_34334, needs_34334

FUNCS = H.extract("escapeHtml", "invDescHtml") if H.NODE else ""

_TAG = re.compile(r"</?\s*([a-zA-Z0-9]+)")


def line_with(description):
    """One line view, exactly as /api/accounting/invoices/<id> would send it."""
    return AI.line_view({
        "id": 1, "call_id": 9, "description": description, "qty": 2,
        "chargeout_rate": 89.95, "hours": 8, "flatrate": 0,
        "start_date": 1794952800, "start_time": "08:00", "end_time": "16:00",
        "break_time": "00:30",
    })


def render(description):
    """invDescHtml()'s output for that description, from the real page."""
    view = line_with(description)
    out = H.run(H.DOM_PRELUDE + FUNCS + r"""
    var line = %s;
    console.log(JSON.stringify({html: invDescHtml(line),
                                main: line.desc_main, note: line.desc_note}));
    """ % json.dumps(view))
    return out


def tags_in(html):
    return sorted(set(m.group(1).lower() for m in _TAG.finditer(html)))


@unittest.skipUnless(H.NODE, "node not installed")
class DescriptionRendering(unittest.TestCase):

    GENERATED = 'Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>'

    def test_a_generated_line_draws_the_name_and_a_muted_italic_suffix(self):
        out = render(self.GENERATED)
        self.assertEqual(out["main"], "Crew Boss")
        self.assertEqual(out["note"], "(Night/Crew Boss)")
        self.assertIn("Crew Boss", out["html"])
        self.assertIn("(Night/Crew Boss)", out["html"])
        # The span is THE GOAT's, and it is the only tag.
        self.assertIn('<span class="inv-desc-note">', out["html"])
        self.assertEqual(tags_in(out["html"]), ["span"])

    def test_the_stored_italic_never_reaches_the_page(self):
        """The literal thing fix 1 §2 asks for."""
        out = render(self.GENERATED)
        self.assertNotIn("<i style", out["html"])
        self.assertNotIn("<i ", out["html"])
        self.assertNotIn("font-size", out["html"])

    def test_a_script_payload_reaches_the_page_as_nothing_at_all(self):
        out = render("<script>alert(1)</script>Fork")
        self.assertNotIn("<script", out["html"])
        self.assertNotIn("alert", out["html"])
        self.assertEqual(out["html"], "Fork")

    def test_an_attribute_payload_is_text_not_an_attribute(self):
        """If the splitter ever stopped stripping, escapeHtml is the second
        line of defence: the page would show the tag, not run it."""
        out = render('<img src=x onerror=alert(1)>Fork')
        self.assertNotIn("onerror", out["html"])
        self.assertNotIn("<img", out["html"])
        # And "Fork" is the NAME, not a muted suffix: a description that begins
        # with a tag has no leading text, so its words are the name.
        self.assertEqual(out["html"], "Fork")
        self.assertEqual(tags_in(out["html"]), [])

    def test_a_hand_added_line_is_drawn_whole_with_no_suffix(self):
        out = render("Vehicle hire")
        self.assertEqual(out["html"], "Vehicle hire")
        self.assertEqual(tags_in(out["html"]), [])

    def test_an_ampersand_in_a_description_is_escaped_once_and_only_once(self):
        """Python decoded `&amp;` to `&`; the page escapes it back to `&amp;`
        for the HTML and does NOT decodeEntities() first. Decoding twice would
        turn a stored `&amp;amp;` into a bare `&`."""
        out = render("Smith &amp; Co <i>(Day/T1)</i>")
        self.assertEqual(out["main"], "Smith & Co")
        self.assertIn("Smith &amp; Co", out["html"])
        self.assertNotIn("Smith & Co", out["html"])

    def test_a_quote_in_a_description_cannot_break_out_of_the_span(self):
        out = render('Pete\'s "Big" Rig <i>(Day/T1)</i>')
        self.assertEqual(tags_in(out["html"]), ["span"])
        self.assertNotIn('"Big"', out["html"])     # escaped to &quot;
        self.assertIn("&quot;", out["html"])

    def test_a_spacer_draws_nothing(self):
        """Spacers render as a divider, so their text is never drawn — and the
        sentinel must not leak a stray angle bracket into the markup either."""
        out = render(AI.SPACER_DESCRIPTION)
        self.assertEqual(out["html"], "")
        self.assertEqual(tags_in(out["html"]), [])

    @needs_34334
    def test_every_real_description_on_invoice_34334(self):
        """THE REAL ONES. #34334's 18 distinct descriptions, every one through
        Python and then through the page, in a single node run.

        The generated shape this whole fix is about is line 1 of that invoice:
        'Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>'. So this
        is not a test about contrived markup \u2014 it is the markup SmartStaff
        actually stores, drawn the way Mike will actually see it."""
        fix = load_34334()
        raws = sorted({l["description"] for l in fix["lines"]
                       if not AI.is_spacer(l)})
        self.assertEqual(len(raws), 18)

        views = [line_with(r) for r in raws]
        out = H.run(H.DOM_PRELUDE + FUNCS + r"""
        var lines = %s;
        console.log(JSON.stringify(lines.map(function(l){ return invDescHtml(l); })));
        """ % json.dumps(views))

        self.assertEqual(len(out), 18)
        for raw, html in zip(raws, out):
            # Our span, and nothing else.
            self.assertEqual(tags_in(html), ["span"], raw)
            for banned in ("<i ", "<i>", "font-size", "<script", "onerror"):
                self.assertNotIn(banned, html, (raw, banned))
            # The name and the grade suffix both survive, as text.
            main, note = AI.description_parts(raw)
            self.assertIn(main, html, raw)
            self.assertIn(note, html, raw)

    def test_no_markup_from_the_data_over_every_shape(self):
        """The property, swept. The ONLY tag the page may emit is its span."""
        for raw in (self.GENERATED,
                    'Load Out <i style="font-size: 9px;">(Night/Sun/Pub HolB)</i>',
                    "Vehicle hire",
                    "<script>alert(1)</script>Fork",
                    "Smith &amp; Co <i>(Day/T1)</i>",
                    "<img src=x onerror=alert(1)>Fork",
                    "Load In <b>urgent</b>",
                    AI.SPACER_DESCRIPTION,
                    ""):
            out = render(raw)
            self.assertIn(tags_in(out["html"]), ([], ["span"]), raw)
            for banned in ("<i ", "<i>", "<script", "<img", "<b>", "onerror", "alert"):
                self.assertNotIn(banned, out["html"], (raw, banned))


if __name__ == "__main__":
    unittest.main(verbosity=2)
