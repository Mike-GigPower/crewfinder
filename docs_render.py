"""Markdown rendering for THE GOAT's Docs Centre.

A deliberately small markdown subset, hand-rolled rather than pulled in as a
dependency. The reasoning is in DESIGN-docs-centre: the corpus is markdown we
write ourselves, so a renderer covering what we use is complete by definition,
and it adds nothing to the PyInstaller build that can fail in the bundle but
not from source.

Supported: headings (h1-h4), paragraphs, **bold**, *italic*, `code`, fenced
code blocks, bullet and numbered lists, [links](url), pipe tables, > quotes,
and --- rules. Anything else is rendered as plain text rather than guessed at.

Three public functions:
    parse_front_matter(text) -> (dict, body)
    render(markdown_text)    -> (html, headings)
    plain_text(markdown_text)-> str      # what search indexes

SAFETY: every piece of source text is HTML-escaped BEFORE any tag is added,
and raw HTML in a document is escaped rather than passed through. Getting this
order backwards is the classic way a renderer starts emitting live markup, and
nothing in these docs needs raw HTML.
"""

import html
import re

# Only these schemes are allowed in a link. Docs are ours, but a renderer that
# will emit any href at all is one bad paste away from emitting javascript:.
_SAFE_SCHEME = re.compile(r"^(?:https?:|mailto:|#|/|\.{0,2}/)", re.I)

_FENCE       = re.compile(r"^\s*```+\s*([A-Za-z0-9_+-]*)\s*$")
_HEADING     = re.compile(r"^(#{1,4})\s+(.*?)\s*#*\s*$")
_RULE        = re.compile(r"^\s*(?:-{3,}|\*{3,}|_{3,})\s*$")
_BULLET      = re.compile(r"^\s*[-*+]\s+(.*)$")
_NUMBERED    = re.compile(r"^\s*\d+[.)]\s+(.*)$")
_QUOTE       = re.compile(r"^\s*>\s?(.*)$")
_TABLE_ROW   = re.compile(r"^\s*\|.*\|\s*$")
_TABLE_SEP   = re.compile(r"^\s*\|(?:\s*:?-{2,}:?\s*\|)+\s*$")

_CODE_TOKEN  = "\x00CODE%d\x00"      # placeholder while inline markup runs


# ── FRONT MATTER ─────────────────────────────────────────────────────────────

def parse_front_matter(text):
    """Split a leading `---` block into a dict, and return the rest.

    Deliberately NOT YAML. Six string keys do not justify a dependency, a new
    PyInstaller hidden-import, and a new way for the build to break. Values are
    read as everything after the FIRST colon, so `summary: Times: what changed`
    keeps its second colon. Surrounding quotes are stripped if present.

    A file with no front matter returns ({}, text) rather than raising — a doc
    that forgot its header should render, just without a title.
    """
    if text is None:
        return {}, ""
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    if not text.startswith("---\n"):
        return {}, text

    end = text.find("\n---", 3)
    if end == -1:
        return {}, text                       # unterminated: treat as body

    block = text[4:end]
    rest = text[end + 4:].lstrip("\n")

    meta = {}
    for line in block.split("\n"):
        line = line.strip()
        if not line or line.startswith("#") or ":" not in line:
            continue
        key, _, value = line.partition(":")
        key = key.strip().lower()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        if key:
            meta[key] = value
    return meta, rest


# ── HELPERS ──────────────────────────────────────────────────────────────────

def slugify(text, taken=None):
    """Heading text -> anchor id. Collisions get -2, -3, ... so two sections
    called "Notes" are both linkable."""
    s = re.sub(r"<[^>]+>", "", text or "")
    s = html.unescape(s).lower()
    s = re.sub(r"[^a-z0-9]+", "-", s).strip("-")
    s = s or "section"
    if taken is None:
        return s
    base, n = s, 2
    while s in taken:
        s = "%s-%d" % (base, n)
        n += 1
    taken.add(s)
    return s


def _inline(text):
    """Inline markup for one run of text. Escape first, decorate second."""
    # 1. Code spans come out before escaping so their contents are never
    #    treated as markup — a literal ** inside `code` must stay literal.
    codes = []

    def _stash(m):
        codes.append(m.group(1))
        return _CODE_TOKEN % (len(codes) - 1)

    text = re.sub(r"`([^`]+)`", _stash, text)

    # 2. Escape EVERYTHING. From here on, any < > & in the string is ours.
    text = html.escape(text, quote=False)

    # 3. Links: [label](url). The label is already escaped; the url is checked
    #    against the scheme allow-list and escaped for attribute context.
    def _link(m):
        label, url = m.group(1), m.group(2).strip()
        raw = html.unescape(url)
        if not _SAFE_SCHEME.match(raw):
            return label                      # not a scheme we emit: plain text
        return '<a href="%s" target="_blank" rel="noopener">%s</a>' % (
            html.escape(raw, quote=True), label)

    text = re.sub(r"\[([^\]]*)\]\(([^)\s]+)\)", _link, text)

    # 4. Bold before italic, or **x** is read as *(*x*)*.
    text = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", text, flags=re.S)
    text = re.sub(r"(?<![\w*])\*([^*\n]+)\*(?!\w)", r"<em>\1</em>", text)
    # _italic_ only at word boundaries. Underscores INSIDE a word are left
    # alone on purpose — `call_crew_map` and `APP_VERSION` are words in the
    # technical guides, and italicising their middles would be worse than not
    # supporting underscore emphasis at all.
    text = re.sub(r"(?<![\w_])_([^_\n]+)_(?![\w_])", r"<em>\1</em>", text)

    # 5. Restore code spans, escaped.
    for i, c in enumerate(codes):
        text = text.replace(_CODE_TOKEN % i, "<code>%s</code>" % html.escape(c, quote=False))
    return text


def _is_block_start(line):
    """True if this line begins a new block, so it can't be a continuation of
    the paragraph or list item above it."""
    return bool(
        not line.strip()
        or _FENCE.match(line) or _HEADING.match(line) or _RULE.match(line)
        or _BULLET.match(line) or _NUMBERED.match(line)
        or _QUOTE.match(line) or _TABLE_ROW.match(line)
    )


# ── RENDER ───────────────────────────────────────────────────────────────────

def render(md):
    """Markdown -> (html, headings). `headings` is [{'id','text','level'}, ...]
    in document order, which drives the on-page contents and lets a search hit
    jump to a section."""
    md = (md or "").replace("\r\n", "\n").replace("\r", "\n")
    lines = md.split("\n")
    out, headings, taken = [], [], set()
    i, n = 0, len(lines)

    while i < n:
        line = lines[i]

        if not line.strip():
            i += 1
            continue

        # Fenced code block ------------------------------------------------
        m = _FENCE.match(line)
        if m:
            lang = m.group(1)
            body, i = [], i + 1
            while i < n and not _FENCE.match(lines[i]):
                body.append(lines[i])
                i += 1
            i += 1                              # step over the closing fence
            cls = ' class="lang-%s"' % html.escape(lang, quote=True) if lang else ""
            out.append("<pre><code%s>%s</code></pre>"
                       % (cls, html.escape("\n".join(body), quote=False)))
            continue

        # Heading -----------------------------------------------------------
        m = _HEADING.match(line)
        if m:
            level = len(m.group(1))
            text = m.group(2)
            hid = slugify(text, taken)
            headings.append({"id": hid, "text": html.unescape(re.sub(r"[*`]", "", text)).strip(),
                             "level": level})
            out.append('<h%d id="%s">%s</h%d>' % (level, hid, _inline(text), level))
            i += 1
            continue

        # Horizontal rule ---------------------------------------------------
        if _RULE.match(line):
            out.append("<hr>")
            i += 1
            continue

        # Table -------------------------------------------------------------
        if _TABLE_ROW.match(line) and i + 1 < n and _TABLE_SEP.match(lines[i + 1]):
            head = _split_row(line)
            i += 2
            body = []
            while i < n and _TABLE_ROW.match(lines[i]):
                body.append(_split_row(lines[i]))
                i += 1
            cells = "".join("<th>%s</th>" % _inline(c) for c in head)
            rows = "".join(
                "<tr>%s</tr>" % "".join("<td>%s</td>" % _inline(c) for c in r)
                for r in body)
            out.append("<div class=\"doc-table-wrap\"><table><thead><tr>%s</tr></thead>"
                       "<tbody>%s</tbody></table></div>" % (cells, rows))
            continue

        # Blockquote --------------------------------------------------------
        if _QUOTE.match(line):
            body = []
            while i < n and _QUOTE.match(lines[i]):
                body.append(_QUOTE.match(lines[i]).group(1))
                i += 1
            out.append("<blockquote><p>%s</p></blockquote>" % _inline("\n".join(body).strip()))
            continue

        # Lists ---------------------------------------------------------------
        # A non-blank line that doesn't start a block is a continuation of the
        # item above it. This matters: the backfilled patch notes are hard
        # wrapped at ~78 characters, so most bullets in them span two or three
        # source lines and would otherwise each become a separate item.
        if _BULLET.match(line) or _NUMBERED.match(line):
            ordered = bool(_NUMBERED.match(line))
            pat = _NUMBERED if ordered else _BULLET
            items = []
            while i < n:
                m = pat.match(lines[i])
                if m:
                    items.append(m.group(1))
                    i += 1
                elif items and lines[i].strip() and not _is_block_start(lines[i]):
                    items[-1] += "\n" + lines[i].strip()
                    i += 1
                else:
                    break
            tag = "ol" if ordered else "ul"
            out.append("<%s>%s</%s>" % (
                tag, "".join("<li>%s</li>" % _inline(x.strip()) for x in items), tag))
            continue

        # Paragraph -----------------------------------------------------------
        body = [line]
        i += 1
        while i < n and lines[i].strip() and not _is_block_start(lines[i]):
            body.append(lines[i])
            i += 1
        out.append("<p>%s</p>" % _inline("\n".join(body).strip()))

    return "\n".join(out), headings


def _split_row(line):
    """'| a | b |' -> ['a', 'b']"""
    return [c.strip() for c in line.strip().strip("|").split("|")]


# ── SEARCHABLE TEXT ──────────────────────────────────────────────────────────

def plain_text(md):
    """Markdown stripped to the words, for the search index.

    Markers become spaces rather than nothing: collapsing `**a**b` to `ab`
    would invent a word that isn't in the document and can't be searched for.
    """
    text = (md or "").replace("\r\n", "\n").replace("\r", "\n")
    text = re.sub(r"```[A-Za-z0-9_+-]*\n?", "\n", text)      # fence markers
    text = re.sub(r"!\[([^\]]*)\]\([^)]*\)", r" \1 ", text)  # images -> alt
    text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r" \1 ", text)   # links -> label
    text = re.sub(r"^\s{0,3}#{1,6}\s*", " ", text, flags=re.M)
    text = re.sub(r"^\s*[-*+]\s+", " ", text, flags=re.M)
    text = re.sub(r"^\s*\d+[.)]\s+", " ", text, flags=re.M)
    text = re.sub(r"^\s*>\s?", " ", text, flags=re.M)
    text = re.sub(r"^\s*(?:-{3,}|\*{3,}|_{3,})\s*$", " ", text, flags=re.M)
    # NOT underscores in general: APP_VERSION and call_crew_map are single
    # searchable words, and splitting them would make them unfindable by the
    # exact string someone types. Only boundary underscores (_emphasis_) go.
    text = re.sub(r"(?<![\w_])_([^_\n]+)_(?![\w_])", r" \1 ", text)
    text = re.sub(r"[|`*~]", " ", text)
    text = html.unescape(text)
    return re.sub(r"[ \t]+", " ", text)
