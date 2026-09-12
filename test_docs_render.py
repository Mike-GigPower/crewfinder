"""Tests for docs_render — the Docs Centre markdown subset.

Run:  python3 -m pytest test_docs_render.py -q
"""

import docs_render as R


# ── FRONT MATTER ─────────────────────────────────────────────────────────────

def test_front_matter_basic():
    meta, body = R.parse_front_matter(
        "---\ntitle: Crew Finder\norder: 20\n---\n\n# Crew Finder\n")
    assert meta["title"] == "Crew Finder"
    assert meta["order"] == "20"
    assert body.startswith("# Crew Finder")


def test_front_matter_splits_on_first_colon_only():
    meta, _ = R.parse_front_matter("---\nsummary: Times: what changed\n---\nx\n")
    assert meta["summary"] == "Times: what changed"


def test_front_matter_strips_quotes():
    meta, _ = R.parse_front_matter("---\nsummary: \"A: b\"\n---\nx\n")
    assert meta["summary"] == "A: b"


def test_no_front_matter_returns_whole_text():
    meta, body = R.parse_front_matter("# Just a heading\n")
    assert meta == {}
    assert body == "# Just a heading\n"


def test_unterminated_front_matter_is_not_swallowed():
    text = "---\ntitle: x\nstill going\n"
    meta, body = R.parse_front_matter(text)
    assert meta == {}
    assert body == text


def test_crlf_is_normalised():
    meta, body = R.parse_front_matter("---\r\ntitle: x\r\n---\r\n\r\nbody\r\n")
    assert meta["title"] == "x"
    assert "\r" not in body


# ── SAFETY ───────────────────────────────────────────────────────────────────

def test_raw_html_is_escaped_not_passed_through():
    html_out, _ = R.render("A <script>alert(1)</script> tag.")
    assert "<script>" not in html_out
    assert "&lt;script&gt;" in html_out


def test_javascript_url_is_not_emitted_as_a_link():
    html_out, _ = R.render("[click](javascript:alert(1))")
    assert "javascript:" not in html_out.lower()
    assert "href" not in html_out


def test_https_link_is_emitted():
    html_out, _ = R.render("[Crew Hub](https://crew.gigpower.com)")
    assert '<a href="https://crew.gigpower.com"' in html_out
    assert 'rel="noopener"' in html_out


def test_code_span_contents_are_not_treated_as_markup():
    html_out, _ = R.render("Use `a ** b` here.")
    assert "<code>a ** b</code>" in html_out
    assert "<strong>" not in html_out


# ── BLOCKS ───────────────────────────────────────────────────────────────────

def test_headings_and_anchor_ids():
    html_out, heads = R.render("# Title\n\n## Filters\n")
    assert '<h1 id="title">Title</h1>' in html_out
    assert heads == [{"id": "title", "text": "Title", "level": 1},
                     {"id": "filters", "text": "Filters", "level": 2}]


def test_duplicate_headings_get_unique_ids():
    _, heads = R.render("## Notes\n\n## Notes\n\n## Notes\n")
    assert [h["id"] for h in heads] == ["notes", "notes-2", "notes-3"]


def test_bold_and_italic():
    html_out, _ = R.render("A **bold** and an *italic* word.")
    assert "<strong>bold</strong>" in html_out
    assert "<em>italic</em>" in html_out


def test_fenced_code_block_is_escaped():
    html_out, _ = R.render("```python\nif a < b:\n    pass\n```")
    assert '<pre><code class="lang-python">' in html_out
    assert "a &lt; b" in html_out


def test_bullet_list():
    html_out, _ = R.render("- one\n- two\n")
    assert html_out == "<ul><li>one</li><li>two</li></ul>"


def test_numbered_list():
    html_out, _ = R.render("1. first\n2. second\n")
    assert html_out.startswith("<ol>")
    assert "<li>first</li>" in html_out


def test_wrapped_bullet_stays_one_item():
    """The backfilled patch notes are hard wrapped, so continuation lines are
    the common case, not an edge case."""
    md = ("- Unanswered offer an open offer sitting with someone who was\n"
          "  never told we asked. It is already dead.\n"
          "- Booked only confirmed on a call.\n")
    html_out, _ = R.render(md)
    assert html_out.count("<li>") == 2
    assert "never told we asked" in html_out


def test_paragraph_joins_wrapped_lines():
    html_out, _ = R.render("one line\nand its continuation\n\nsecond para\n")
    assert html_out.count("<p>") == 2


def test_table():
    html_out, _ = R.render("| a | b |\n|---|---|\n| 1 | 2 |\n")
    assert "<th>a</th>" in html_out and "<td>2</td>" in html_out
    assert "doc-table-wrap" in html_out       # tables scroll rather than overflow


def test_blockquote():
    html_out, _ = R.render("> a note\n> continued\n")
    assert html_out.startswith("<blockquote><p>")


def test_horizontal_rule():
    html_out, _ = R.render("above\n\n---\n\nbelow\n")
    assert "<hr>" in html_out


def test_pipe_table_separator_is_not_a_rule():
    html_out, _ = R.render("| a |\n|---|\n| 1 |\n")
    assert "<hr>" not in html_out


# ── PLAIN TEXT ───────────────────────────────────────────────────────────────

def test_plain_text_strips_markup_without_joining_words():
    out = R.plain_text("A **bold**word and `code` here")
    assert "boldword" not in out
    assert "bold" in out and "code" in out


def test_plain_text_keeps_link_label_drops_url():
    out = R.plain_text("see [Crew Hub](https://crew.gigpower.com) for that")
    assert "Crew Hub" in out
    assert "gigpower.com" not in out


def test_plain_text_keeps_code_block_contents():
    out = R.plain_text("```\nAPP_VERSION\n```")
    assert "APP_VERSION" in out


def test_underscore_identifiers_survive_plain_text():
    """APP_VERSION must stay one searchable word, or nobody can find it."""
    out = R.plain_text("Bump APP_VERSION in app.py; see call_crew_map.")
    assert "APP_VERSION" in out
    assert "call_crew_map" in out


def test_underscore_emphasis_at_word_boundaries_only():
    html_out, _ = R.render("_No notes recorded._ but call_crew_map is untouched")
    assert "<em>No notes recorded.</em>" in html_out
    assert "call_crew_map" in html_out
    assert "call<em>" not in html_out


def test_body_has_no_leading_blank_line():
    _, body = R.parse_front_matter("---\ntitle: x\n---\n\n# Heading\n")
    assert body.startswith("# Heading")


if __name__ == "__main__":
    import sys
    fails = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
            except AssertionError as e:
                fails += 1
                print("FAIL %s: %s" % (name, e))
    print("done — %d failure(s)" % fails)
    sys.exit(1 if fails else 0)
