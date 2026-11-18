"""Invoice money and times — the arithmetic behind Accounting › Invoicing.

BRIEF-accounting-slice-a.md §5.3, DESIGN-accounting-pages-v0_3.md §3.6.

PURE. No I/O, no network, no clock, no Flask. `sums` are list-invoices.php's
unrounded SUM()s and `lines` are get-invoice.php's raw rows. That is what makes
every number here testable without a server (the invoice_expect.py shape).

WHAT THIS IS FOR. SmartStaff's invoice PDF is the invoice (design D2) — THE
GOAT never re-renders it. But Mike needs the list and the detail panel to agree
with that PDF TO THE CENT, or they are worse than nothing: a figure that is
nearly right is one nobody can act on. So the rules below are the PDF's rules,
not a tidier version of them.

THE THREE THINGS MOST EASILY GOT WRONG
--------------------------------------
1. A FLAT-RATE LINE IGNORES QTY — in the PDF. invoice.php charges `flatrate`
   for a flat line however many workers the line names, while the Xero export
   charges `flatrate * qty` (class.accounting.php l. 1276). On most invoices
   every flat line has qty 1 and the two agree; where they do not, the PDF and
   the Xero file disagree about the invoice, and that is a real disagreement in
   SmartStaff, not an error here. Slice A SHOWS it (`pdf_xero_differ`,
   `xero_ex_gst`) and picks no winner — design Q11 is still open.

2. ROUNDING ONCE. Every sum accumulates in Decimal with no rounding along the
   way and is rounded ONCE, ROUND_HALF_UP, at the end. invoice_expect.py learnt
   this the expensive way: rounding per row put booking #12220 three cents
   high. The `*_display` values are for drawing only — they do not add up to
   the total and must never be summed.

   GST is Σ(line × 0.10) unrounded, which is how invoice.tpl builds it (per
   line, then added), rather than 10% of the rounded total. In exact Decimal
   the two are the SAME NUMBER — 0.10 × Σ(line) ≡ Σ(0.10 × line) — so this
   module can and does compute it either way without a cent of difference.
   What would differ is rounding each line's GST to cents first and adding
   those, which is why nothing here quantizes before the end. See
   test_accounting_invoices.py.

3. DESCRIPTIONS CONTAIN MARKUP. The generator writes
   `Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>`, and the PDF
   renders that as "Crew Boss" with a small italic "(Night/Crew Boss)" after
   it. So the splitting is done HERE, into two PLAIN-TEXT fields, and the page
   escapes both and supplies its own markup. Nothing stored ever reaches the
   browser as markup, and nothing readable is thrown away. See
   description_parts().

4. TIMES ARE NOT ALL THE SAME SHAPE. `invoice_lines.start_time` holds either a
   unix timestamp as text, or "HH:MM", or "HH:MM:SS" — class.accounting.php
   writes date('H:i') on the `calculated` path and SEC_TO_TIME() on the legacy
   one, and SmartStaff's own Edit page writes whatever was typed into it.
   _time_text() reads all three and returns ANYTHING ELSE UNCHANGED, marked
   raw, so an unrecognised value is visibly odd rather than quietly wrong.
"""

import re
from datetime import datetime
from decimal import Decimal, ROUND_HALF_UP
from html import unescape
from zoneinfo import ZoneInfo


# The GST rate SmartStaff's invoice.tpl applies to every line. A constant, not
# a literal 0.1 scattered through the file, so a change is one edit — but it is
# NOT configurable: the PDF hard-codes 10% and this module exists to match it.
GST_RATE = Decimal("0.10")

# The generator writes one of these after each call's lines as a layout spacer
# (class.accounting.php l. 494). It is not a line: no money, no date, no count.
SPACER_DESCRIPTION = "<< BLANK_LINE >>"

MELBOURNE = ZoneInfo("Australia/Melbourne")

_CENT = Decimal("0.01")

# "8:30", "08:30", "28:00:00" — an hour (one or two digits) and a minute. The
# hour is deliberately NOT bounded to 23: SEC_TO_TIME() emits 28:00:00 for a
# shift that ran past midnight and that is a real stored value.
_HHMM = re.compile(r"^(\d{1,2}):(\d{2})")


def _dec(v):
    """A Decimal from whatever MySQL and json gave us, via str().

    str() and not Decimal(float): Decimal(0.1) is 0.1000000000000000055511…,
    and the point of doing this in Decimal is not to carry that into a total.
    None and "" are 0 — a NULL rate is no money, not a crash.
    """
    if v is None or v == "":
        return Decimal("0")
    if isinstance(v, Decimal):
        return v
    return Decimal(str(v))


def _money(d):
    """Round to cents, ROUND_HALF_UP — the LAST thing done to a figure."""
    return d.quantize(_CENT, rounding=ROUND_HALF_UP)


def _f(d):
    """A Decimal as a float, for JSON. Only ever called on rounded values."""
    return float(d)


# ──────────────────────────────────────────────────────────────────────────────
# Times
# ──────────────────────────────────────────────────────────────────────────────

def _time_text(v):
    """One stored time as "HH:MM" for display. Returns (text, raw).

    raw is True when the value was none of the three known shapes and is being
    handed back untouched. Never guesses: a value this cannot read is shown as
    it is stored, so it looks wrong on screen instead of looking plausible.

      "1794567600" -> ("09:00", False)   unix seconds as text, Melbourne
      "08:00"      -> ("08:00", False)
      "8:30"       -> ("08:30", False)   zero-padded; the house clock is 24-hour
      "28:00:00"   -> ("28:00", False)   SEC_TO_TIME past midnight, kept as-is
      ""           -> ("", False)
      "noon"       -> ("noon", True)
    """
    if v is None:
        return "", False

    s = str(v).strip()

    if s == "":
        return "", False

    # All digits means unix seconds. Checked BEFORE the HH:MM pattern because
    # a timestamp has no colon and would otherwise fall through to raw.
    if s.isdigit():
        try:
            return datetime.fromtimestamp(int(s), MELBOURNE).strftime("%H:%M"), False
        except (OverflowError, OSError, ValueError):
            # A stored value too large to be a date. Raw, not a guess.
            return s, True

    m = _HHMM.match(s)
    if m:
        return "%02d:%s" % (int(m.group(1)), m.group(2)), False

    return s, True


def _date_text(ts):
    """A unix date as ("dd/mm/yy", "Weekday") in Melbourne, or ("", "").

    0 is not a date. Generated spacer rows carry start_date 0, and so do some
    hand-added lines, so this has to answer "no date" rather than 1 Jan 1970.
    """
    try:
        n = int(ts or 0)
    except (TypeError, ValueError):
        return "", ""
    if n <= 0:
        return "", ""
    try:
        d = datetime.fromtimestamp(n, MELBOURNE)
    except (OverflowError, OSError, ValueError):
        return "", ""
    return d.strftime("%d/%m/%y"), d.strftime("%A")


# ──────────────────────────────────────────────────────────────────────────────
# Descriptions
# ──────────────────────────────────────────────────────────────────────────────

# <script> and <style> go WITH THEIR CONTENT: the text inside them is code,
# not a description, and showing "alert(1)" as though it were part of a line
# would be worse than showing nothing. Every other tag is stripped and its
# text kept. The unterminated forms are matched too -- "<script>alert(1)" with
# no closing tag must not leave the payload behind as text.
_SCRIPTISH      = re.compile(r"<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>", re.I | re.S)
_SCRIPTISH_OPEN = re.compile(r"<\s*(script|style)\b[^>]*>.*\Z", re.I | re.S)
_ANY_TAG        = re.compile(r"<[^>]*>")
_WS             = re.compile(r"\s+")


def _plain(text):
    """Tags stripped, entities decoded, whitespace collapsed. PLAIN TEXT.

    In that order, and the order matters: decoding first would turn a stored
    `&lt;i&gt;` into a real-looking tag for the stripper to eat, losing text
    that was never markup in the first place.

    Decoded ONCE, here. The page must NOT call decodeEntities() on these
    fields as well, or a customer stored as `&amp;amp;` would come out as `&`.
    """
    text = _ANY_TAG.sub("", text)
    text = unescape(text)
    return _WS.sub(" ", text).strip()


def description_parts(desc):
    """One stored description as (desc_main, desc_note), both plain text.

      'Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>'
                                        -> ('Crew Boss', '(Night/Crew Boss)')
      'Vehicle hire'                    -> ('Vehicle hire', '')
      'Smith &amp; Co <i>(Day/T1)</i>'  -> ('Smith & Co', '(Day/T1)')
      '<script>alert(1)</script>Fork'   -> ('Fork', '')

    desc_main is everything before the first tag; desc_note is everything from
    the first tag onward, with the tags taken out.

    For every description SmartStaff generates that is exactly the brief's
    rule -- the text of the leading `<i>` element -- because the markup IS one
    trailing `<i>`. It is written as "from the first tag onward" rather than
    "the text of the first <i> element" so that a line carrying some other tag,
    or text after the italic, keeps its words instead of having them silently
    dropped: on a screen about money, text that vanishes is the worst failure
    available.

    Hand-added lines carry no markup at all, so they come back whole in
    desc_main with an empty desc_note.
    """
    if desc is None:
        return "", ""

    s = str(desc)

    # THE SPACER IS A SENTINEL, NOT A DESCRIPTION. '<< BLANK_LINE >>' has
    # nothing in it to show, and running it through the tag stripper leaves a
    # stray '>' behind, because `<[^>]*>` reads '<< BLANK_LINE >' as a tag.
    # Harmless on screen -- a spacer draws as a divider and never shows its
    # text -- but a field that holds '>' for no reason is one a later change
    # could put somewhere visible. Answered here, where is_spacer() still
    # tests the RAW description and so cannot be affected.
    if s.strip() == SPACER_DESCRIPTION:
        return "", ""

    # Before the split, so a description that OPENS with a script tag still
    # yields its real text ("<script>...</script>Fork" -> "Fork") rather than
    # an empty desc_main and the payload in desc_note.
    s = _SCRIPTISH.sub("", s)
    s = _SCRIPTISH_OPEN.sub("", s)

    cut = s.find("<")
    if cut < 0:
        return _plain(s), ""

    main, note = _plain(s[:cut]), _plain(s[cut:])

    # A description that BEGINS with a tag has no leading plain text, so the
    # words after the tag are its name, not a suffix: returning them as the
    # note would draw the whole line muted and italic, as though the name were
    # missing. A suffix only means anything when there is a name in front of
    # it.
    if not main:
        return note, ""

    return main, note


# ──────────────────────────────────────────────────────────────────────────────
# One line
# ──────────────────────────────────────────────────────────────────────────────

def is_spacer(line):
    """True for the generator's layout spacer. Not a line: no money, no count."""
    desc = line.get("description")
    return desc is not None and str(desc).strip() == SPACER_DESCRIPTION


def line_view(line):
    """One get-invoice.php line, with everything the panel draws.

    `line_total` and `per_worker` are UNROUNDED Decimals-as-floats so the
    caller can still add them exactly; `*_display` are the rounded copies for
    the screen. Summing the displayed values would be the §5.3 rounding
    mistake — detail_totals() adds the unrounded ones.
    """
    spacer = is_spacer(line)

    qty      = _dec(line.get("qty"))
    hours    = _dec(line.get("hours"))
    rate     = _dec(line.get("chargeout_rate"))
    flatrate = _dec(line.get("flatrate"))

    flat = flatrate > 0

    if spacer:
        # Belt and braces: a spacer carries qty 1 and zeroes, so the hourly
        # formula already gives 0. Stated anyway, so a future spacer that
        # arrives carrying a rate cannot quietly enter a total.
        total      = Decimal("0")
        xero_total = Decimal("0")
        per_worker = None
    elif flat:
        # THE PDF IGNORES QTY ON A FLAT LINE. The Xero export does not (§1).
        total      = flatrate
        xero_total = flatrate * qty
        per_worker = None
    else:
        total      = qty * rate * hours
        xero_total = total
        per_worker = rate * hours

    start, start_raw = _time_text(line.get("start_time"))
    end,   end_raw   = _time_text(line.get("end_time"))
    date, weekday    = _date_text(line.get("start_date"))

    desc_main, desc_note = description_parts(line.get("description"))

    view = {
        "id":          line.get("id"),
        "call_id":     line.get("call_id"),
        # The RAW stored description, kept so it can be compared with
        # SmartStaff. THE PAGE NEVER DRAWS IT: it draws the two plain-text
        # fields below and supplies its own markup for them.
        "description": line.get("description"),
        "desc_main":   desc_main,
        "desc_note":   desc_note,
        "is_spacer":   spacer,
        "kind":        "spacer" if spacer else ("flat" if flat else "hourly"),
        "qty":         int(qty) if qty == qty.to_integral_value() else _f(qty),
        "hours":       _f(hours),
        "rate":        _f(rate),
        "flatrate":    _f(flatrate),
        "break_time":  line.get("break_time"),
        "date":        date,
        "weekday":     weekday,
        "start":       start,
        "end":         end,
        # True when the stored time was none of the known shapes and is being
        # shown exactly as stored (§3).
        "start_raw":   start_raw,
        "end_raw":     end_raw,
        "line_total":          _f(total),
        "line_total_display":  _f(_money(total)),
        "per_worker":          None if per_worker is None else _f(per_worker),
        "per_worker_display":  None if per_worker is None else _f(_money(per_worker)),
        # What the Xero export would charge for this line, and whether it
        # disagrees with the PDF. Only a flat line with qty <> 1 can differ.
        "xero_line_total":         _f(xero_total),
        "xero_line_total_display": _f(_money(xero_total)),
        "xero_differs":            xero_total != total,
    }
    return view


# ──────────────────────────────────────────────────────────────────────────────
# Totals
# ──────────────────────────────────────────────────────────────────────────────

def invoice_totals(hourly_sum, flat_sum_pdf, flat_sum_qty):
    """The totals for ONE invoice, from list-invoices.php's three sums.

      ex_gst  = hourly + flat (the PDF's flat sum, qty ignored)
      gst     = ex_gst x 0.10
      inc_gst = ex_gst + gst

    Each is rounded ONCE, ROUND_HALF_UP, at the end (§2).

    `xero_ex_gst` is the same invoice under the Xero export's formula
    (flat x qty), and `pdf_xero_differ` says whether the two flat sums
    disagree at all — compared UNROUNDED, so a disagreement smaller than a
    cent is still reported rather than rounded into silence.
    """
    hourly   = _dec(hourly_sum)
    flat_pdf = _dec(flat_sum_pdf)
    flat_qty = _dec(flat_sum_qty)

    ex_gst = hourly + flat_pdf
    gst    = ex_gst * GST_RATE

    xero_ex = hourly + flat_qty

    return {
        "ex_gst":  _f(_money(ex_gst)),
        "gst":     _f(_money(gst)),
        "inc_gst": _f(_money(ex_gst + gst)),
        # The Xero view of the same invoice, ex GST, and its GST, so the amber
        # line can quote a complete figure rather than half of one.
        "xero_ex_gst":  _f(_money(xero_ex)),
        "xero_inc_gst": _f(_money(xero_ex + xero_ex * GST_RATE)),
        "pdf_xero_differ": flat_pdf != flat_qty,
        # The unrounded ex-GST figure, for a caller that needs to add invoices
        # together (slice B's Ready-to-invoice totals). Rounded sums do not add.
        "ex_gst_exact": _f(ex_gst),
    }


def detail_totals(views):
    """The totals for ONE invoice, from its OWN lines — not from the list sums.

    `views` are line_view() dicts. Spacers are excluded from the money and
    from line_count.

    GST is built the way invoice.tpl builds it, Σ(line x 0.10) unrounded, then
    rounded once (§2) — identical in Decimal to 0.10 x Σ(line), and the test
    file proves it on a case where rounding each line's GST first would differ.

    The list's sums come from SQL and these come from the rows; they must
    agree, and test_accounting_invoices.py checks that they do on the same
    data. The DETAIL panel shows these, because they are the ones Mike can see
    the workings of.
    """
    ex_gst   = Decimal("0")
    gst      = Decimal("0")
    xero_ex  = Decimal("0")
    count    = 0
    differs  = False

    for v in views:
        if v.get("is_spacer"):
            continue
        count += 1
        line = _dec(v.get("line_total"))
        xero = _dec(v.get("xero_line_total"))
        ex_gst  += line
        gst     += line * GST_RATE
        xero_ex += xero
        if v.get("xero_differs"):
            differs = True

    return {
        "ex_gst":  _f(_money(ex_gst)),
        "gst":     _f(_money(gst)),
        "inc_gst": _f(_money(ex_gst + gst)),
        "xero_ex_gst":  _f(_money(xero_ex)),
        "xero_inc_gst": _f(_money(xero_ex + xero_ex * GST_RATE)),
        "pdf_xero_differ": differs,
        "line_count": count,
        "ex_gst_exact": _f(ex_gst),
    }


def invoice_detail(lines):
    """Every line as a view, plus the totals built from them.

    The one entry point the Flask route needs: {"lines": [...], "totals": {...}}.
    """
    views = [line_view(l) for l in (lines or [])]
    return {"lines": views, "totals": detail_totals(views)}
