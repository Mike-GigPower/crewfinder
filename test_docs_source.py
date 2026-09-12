"""Tests for docs_source — index, sorting and search.

Run:  python3 -m pytest test_docs_source.py -q
"""

import os
import shutil
import tempfile

import docs_source as S


def _fixture():
    """A small docs/ tree, built fresh for each test that needs one."""
    root = tempfile.mkdtemp(prefix="goatdocs")
    for cat in ("user", "technical", "patches"):
        os.makedirs(os.path.join(root, cat))

    def write(cat, name, text):
        with open(os.path.join(root, cat, name), "w", encoding="utf-8") as f:
            f.write(text)

    write("user", "crew-finder.md",
          "---\ntitle: Crew Finder\nsummary: Finding available crew.\n"
          "tags: cf, availability, conflicts\norder: 20\nupdated: 2026-09-01\n---\n\n"
          "# Crew Finder\n\nFinds crew for a call.\n\n"
          "## Conflicts\n\nA crew member already on an overlapping call is blocked.\n")
    write("user", "getting-started.md",
          "---\ntitle: Getting Started\nsummary: Installing and signing in.\n"
          "order: 10\n---\n\n# Getting Started\n\nInstall the DMG.\n")
    write("technical", "smartstaff.md",
          "---\ntitle: SmartStaff integration\nsummary: The ajax endpoints.\n---\n\n"
          "# SmartStaff integration\n\nCalls reach SmartStaff through call_crew_map.\n")
    write("patches", "5_43_0.md",
          "---\ntitle: 5.43.0\nsummary: Hide from results fix.\nupdated: 2026-09-12\n---\n\n"
          "# 5.43.0\n\nThe filter now reads booking statuses. Conflicts unchanged.\n")
    write("patches", "5_9_0.md",
          "---\ntitle: 5.9.0\nsummary: Recruitment tabs.\nupdated: 2026-08-20\n---\n\n"
          "# 5.9.0\n\nRecruitment views became tabs.\n")
    write("patches", "5_10_0.md",
          "---\ntitle: 5.10.0\nsummary: Licence taxonomy.\nupdated: 2026-08-20\n---\n\n"
          "# 5.10.0\n\nThe licence taxonomy moved to a table.\n")
    # Ignored: not a category folder, and an underscore-prefixed file.
    os.makedirs(os.path.join(root, "scratch"))
    write("scratch", "notes.md", "---\ntitle: Scratch\n---\n\nnot a category\n")
    write("user", "_draft.md", "---\ntitle: Draft\n---\n\nnot ready\n")
    return root


def src():
    return S.BundledDocs(_fixture())


# ── INDEX ────────────────────────────────────────────────────────────────────

def test_index_has_three_categories_in_order():
    idx = src().index()
    assert [c["key"] for c in idx["categories"]] == ["user", "technical", "patches"]
    assert [c["label"] for c in idx["categories"]][0] == "User Guides"


def test_index_counts_only_real_docs():
    idx = src().index()
    assert idx["count"] == 6           # scratch/ and _draft.md excluded


def test_unknown_folder_is_ignored():
    idx = src().index()
    keys = {c["key"] for c in idx["categories"]}
    assert "scratch" not in keys


def test_guides_sort_by_order_then_title():
    idx = src().index()
    user = [c for c in idx["categories"] if c["key"] == "user"][0]
    assert [d["title"] for d in user["docs"]] == ["Getting Started", "Crew Finder"]


def test_patches_sort_numerically_not_as_strings():
    """5.9.0 above 5.10.0 is the bug this exists to catch."""
    idx = src().index()
    patches = [c for c in idx["categories"] if c["key"] == "patches"][0]
    assert [d["title"] for d in patches["docs"]] == ["5.43.0", "5.10.0", "5.9.0"]


def test_index_carries_no_bodies():
    idx = src().index()
    for c in idx["categories"]:
        for d in c["docs"]:
            assert "html" not in d and "body" not in d


# ── GET ──────────────────────────────────────────────────────────────────────

def test_get_returns_rendered_html_and_headings():
    d = src().get("user/crew-finder")
    assert d["title"] == "Crew Finder"
    assert "<h1" in d["html"]
    assert [h["text"] for h in d["headings"]] == ["Crew Finder", "Conflicts"]
    assert d["label"] == "User Guides"


def test_get_unknown_slug_returns_none():
    assert src().get("user/nope") is None


def test_get_cannot_traverse_out_of_the_docs_folder():
    s = src()
    for evil in ("../../config.json", "user/../../../etc/passwd",
                 "/etc/passwd", "patches/../../app"):
        assert s.get(evil) is None


# ── SEARCH ───────────────────────────────────────────────────────────────────

def test_search_empty_query_is_empty_not_an_error():
    r = src().search("")
    assert r["results"] == [] and r["count"] == 0


def test_search_requires_every_term():
    s = src()
    assert s.search("crew finder")["count"] >= 1
    # 'crew' appears; 'zebra' does not — AND means no result at all.
    assert s.search("crew zebra")["count"] == 0


def test_title_match_outranks_body_match():
    r = src().search("conflicts")
    assert r["results"][0]["slug"] == "user/crew-finder"


def test_tags_are_searchable():
    r = src().search("availability")
    assert any(x["slug"] == "user/crew-finder" for x in r["results"])


def test_guide_beats_patch_note_on_a_tie():
    """With 164 patch notes to 8 guides, tie-breaking matters."""
    r = src().search("conflicts")
    cats = [x["category"] for x in r["results"]]
    assert cats.index("user") < cats.index("patches")


def test_category_filter():
    r = src().search("conflicts", category="patches")
    assert all(x["category"] == "patches" for x in r["results"])


def test_underscore_identifier_is_searchable():
    r = src().search("call_crew_map")
    assert r["count"] == 1
    assert r["results"][0]["slug"] == "technical/smartstaff"


def test_snippets_mark_the_match_and_are_escaped():
    r = src().search("overlapping")
    hit = r["results"][0]["hits"][0]
    assert "<mark>overlapping</mark>" in hit["snippet"]
    assert "&lt;mark&gt;" not in hit["snippet"]


def test_snippet_carries_its_section_anchor():
    r = src().search("overlapping")
    hit = r["results"][0]["hits"][0]
    assert hit["anchor"] == "conflicts"
    assert hit["heading"] == "Conflicts"


def test_search_is_case_insensitive():
    assert src().search("CREW FINDER")["count"] >= 1


# ── VERSION KEY ──────────────────────────────────────────────────────────────

def test_version_key_orders_correctly():
    vs = ["5_9_0", "5_43_0", "5_10_0", "2_0_0", "3_4_10", "3_4_9"]
    ordered = sorted(vs, key=S._version_key, reverse=True)
    assert ordered == ["5_43_0", "5_10_0", "5_9_0", "3_4_10", "3_4_9", "2_0_0"]


def test_version_key_tolerates_a_two_part_version():
    assert S._version_key("2_1") == (2, 1, 0)


if __name__ == "__main__":
    import sys
    fails = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
            except AssertionError as e:
                fails += 1
                print("FAIL %s: %s" % (name, e or "assertion"))
            except Exception as e:
                fails += 1
                print("ERROR %s: %r" % (name, e))
    print("done — %d failure(s)" % fails)
    sys.exit(1 if fails else 0)
