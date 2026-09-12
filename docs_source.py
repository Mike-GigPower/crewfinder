"""Where documentation comes from, and how it is searched.

The routes in app.py never touch the filesystem. They hold a DocsSource and
ask it for things. That is the whole point of this module: when docs move from
the bundle into Supabase, a second subclass appears here and one line in app.py
changes. Routes, search, renderer and the entire front end are untouched.

    DocsSource      the interface
    BundledDocs     reads docs/ out of the PyInstaller bundle (v1)
    SupabaseDocs    later

Search lives here rather than in the routes because it needs the index, and the
index is the source's business.
"""

import os
import re
import time

import docs_render


# ── CATEGORIES ───────────────────────────────────────────────────────────────
# Fixed and ordered. A folder that is not one of these is ignored rather than
# guessed at — an unexpected folder in docs/ is a mistake, not a new category,
# and silently inventing a section for it would hide the mistake.
CATEGORIES = [
    {"key": "user",      "label": "User Guides",      "icon": "\U0001F4D8"},
    {"key": "technical", "label": "Technical Guides", "icon": "\U0001F527"},
    {"key": "patches",   "label": "Patch History",    "icon": "\U0001F3F7️"},
]
CATEGORY_ORDER = {c["key"]: i for i, c in enumerate(CATEGORIES)}
CATEGORY_BY_KEY = {c["key"]: c for c in CATEGORIES}

# Search weights. Body is capped so a long document cannot out-rank a short one
# purely by repeating the word — 20 mentions is not 20 times more relevant.
# A tag is the author saying "people who type this word want this document",
# which is nearly as strong a signal as the title — and it is the only lever
# that puts a guide above a patch note that merely mentions the word. Set to 6
# originally; raised after "utilisation" (the AU spelling, a tag on the
# Utilization guide) ranked below a release note that happened to say it twice.
W_TITLE, W_TAGS, W_HEADING, W_SUMMARY, W_BODY = 10, 8, 5, 4, 1
BODY_CAP = 5
BONUS_PHRASE = 8            # the whole query appears verbatim
BONUS_TITLE_PREFIX = 4      # title starts with the query
MAX_SNIPPETS = 3
SNIPPET_CHARS = 180

_WORD = re.compile(r"[^\W_]+(?:[_-][^\W_]+)*", re.UNICODE)


def _version_key(name):
    """'5.43.0' -> (5, 43, 0) so patches sort numerically.

    String sort puts 5.9.0 above 5.43.0, which does not read as a sort bug —
    it reads as missing releases, because the eye stops at the first wrong row.
    """
    parts = re.split(r"[._]", name)
    out = []
    for p in parts[:4]:
        try:
            out.append(int(p))
        except ValueError:
            out.append(0)
    while len(out) < 3:
        out.append(0)
    return tuple(out)


def _split_sections(body):
    """Body -> [(heading_text, anchor, text), ...].

    Text before the first heading is kept under an empty heading rather than
    dropped — in a patch note that is the whole document.
    """
    _, headings = docs_render.render(body)
    lines = body.split("\n")
    marks = []
    hi = 0
    for idx, line in enumerate(lines):
        m = docs_render._HEADING.match(line)
        if m and hi < len(headings):
            marks.append((idx, headings[hi]))
            hi += 1
    sections = []
    if not marks or marks[0][0] > 0:
        end = marks[0][0] if marks else len(lines)
        lead = "\n".join(lines[:end]).strip()
        if lead:
            sections.append(("", "", docs_render.plain_text(lead)))
    for n, (idx, h) in enumerate(marks):
        end = marks[n + 1][0] if n + 1 < len(marks) else len(lines)
        chunk = "\n".join(lines[idx + 1:end]).strip()
        sections.append((h["text"], h["id"], docs_render.plain_text(chunk)))
    return sections


# ── INTERFACE ────────────────────────────────────────────────────────────────

class DocsSource(object):
    """What app.py is allowed to depend on."""

    def index(self):
        """[{slug, title, summary, category, updated, order}, ...]"""
        raise NotImplementedError

    def get(self, slug):
        """One doc as {slug, title, ..., body} with raw markdown, or None."""
        raise NotImplementedError

    def search(self, query, category=None):
        raise NotImplementedError


class BundledDocs(DocsSource):
    """Docs read from a folder on disk — the copy inside the app bundle.

    `docs_dir` is passed in rather than computed here. That is what keeps this
    class swappable: app.py decides where docs live, not the source.
    """

    def __init__(self, docs_dir, frozen=False):
        self.docs_dir = docs_dir
        self.frozen = bool(frozen)
        self._docs = None          # slug -> record
        self._built_at = 0.0

    # -- index ---------------------------------------------------------------

    def _newest_mtime(self):
        newest = 0.0
        for cat in CATEGORY_ORDER:
            d = os.path.join(self.docs_dir, cat)
            if not os.path.isdir(d):
                continue
            for name in os.listdir(d):
                if name.endswith(".md"):
                    try:
                        newest = max(newest, os.path.getmtime(os.path.join(d, name)))
                    except OSError:
                        pass
        return newest

    def _ensure(self):
        """Build the index on first use, and rebuild it when running from
        source if a file has changed. Inside a frozen bundle the files cannot
        change, so it is built exactly once."""
        if self._docs is not None:
            if self.frozen or self._newest_mtime() <= self._built_at:
                return
        self._build()

    def _build(self):
        docs = {}
        for cat in CATEGORY_ORDER:
            d = os.path.join(self.docs_dir, cat)
            if not os.path.isdir(d):
                continue
            for name in sorted(os.listdir(d)):
                if not name.endswith(".md") or name.startswith((".", "_")):
                    continue
                path = os.path.join(d, name)
                try:
                    with open(path, "r", encoding="utf-8") as f:
                        raw = f.read()
                except (IOError, OSError):
                    continue
                meta, body = docs_render.parse_front_matter(raw)
                stem = name[:-3]
                slug = "%s/%s" % (cat, stem)
                try:
                    order = int(meta.get("order", ""))
                except ValueError:
                    order = 9999
                docs[slug] = {
                    "slug": slug,
                    "category": cat,
                    "title": meta.get("title") or stem.replace("_", " "),
                    "summary": meta.get("summary", ""),
                    "updated": meta.get("updated", ""),
                    "audience": meta.get("audience", ""),   # parsed, unused in v1
                    "tags": meta.get("tags", ""),
                    "order": order,
                    "stem": stem,
                    "body": body,
                    "sections": _split_sections(body),
                }
        self._docs = docs
        self._built_at = self._newest_mtime() or time.time()

    def _sorted(self, cat):
        rows = [d for d in self._docs.values() if d["category"] == cat]
        if cat == "patches":
            rows.sort(key=lambda d: _version_key(d["stem"]), reverse=True)
        else:
            rows.sort(key=lambda d: (d["order"], d["title"].lower()))
        return rows

    @staticmethod
    def _card(d):
        return {k: d[k] for k in ("slug", "title", "summary", "category", "updated")}

    def index(self):
        self._ensure()
        cats = []
        for c in CATEGORIES:
            rows = self._sorted(c["key"])
            cats.append({"key": c["key"], "label": c["label"], "icon": c["icon"],
                         "docs": [self._card(d) for d in rows]})
        return {"categories": cats, "count": len(self._docs)}

    def get(self, slug):
        """Looked up in the index, never joined onto a path. A slug of
        '../../config.json' finds no entry and returns None — which is the
        difference between a lookup and a file open."""
        self._ensure()
        d = self._docs.get(slug)
        if not d:
            return None
        html, headings = docs_render.render(d["body"])
        out = self._card(d)
        out["html"] = html
        out["headings"] = headings
        out["label"] = CATEGORY_BY_KEY[d["category"]]["label"]
        return out

    # -- search --------------------------------------------------------------

    def search(self, query, category=None):
        self._ensure()
        q = (query or "").strip().lower()
        if not q:
            return {"query": query or "", "results": [], "count": 0}
        terms = [t for t in _WORD.findall(q) if t]
        if not terms:
            return {"query": query, "results": [], "count": 0}

        results = []
        for d in self._docs.values():
            if category and d["category"] != category:
                continue
            score, hits = self._score(d, q, terms)
            if score <= 0:
                continue
            row = self._card(d)
            row["label"] = CATEGORY_BY_KEY[d["category"]]["label"]
            row["score"] = score
            row["hits"] = hits
            results.append(row)

        # Ties break toward guides. Someone searching "induction" wants the
        # guide, not the release note that mentions inductions in passing —
        # and with 164 patch notes to 8 guides, ties are not rare.
        results.sort(key=lambda r: (-r["score"],
                                    CATEGORY_ORDER[r["category"]],
                                    r["title"].lower()))
        return {"query": query, "results": results, "count": len(results)}

    def _score(self, d, q, terms):
        title_l = d["title"].lower()
        tags_l = d["tags"].lower()
        summ_l = d["summary"].lower()
        heads_l = " ".join(s[0] for s in d["sections"]).lower()
        body_l = " ".join(s[2] for s in d["sections"]).lower()

        score = 0
        for t in terms:
            here = 0
            if t in title_l:
                here += W_TITLE
            if t in tags_l:
                here += W_TAGS
            if t in heads_l:
                here += W_HEADING
            if t in summ_l:
                here += W_SUMMARY
            body_hits = min(body_l.count(t), BODY_CAP)
            here += body_hits * W_BODY
            if here == 0:
                return 0, []          # AND: every term must appear somewhere
            score += here

        if len(terms) > 1 and q in (title_l + " " + summ_l + " " + body_l):
            score += BONUS_PHRASE
        if title_l.startswith(q):
            score += BONUS_TITLE_PREFIX

        return score, self._snippets(d, terms)

    def _snippets(self, d, terms):
        hits = []
        for heading, anchor, text in d["sections"]:
            if len(hits) >= MAX_SNIPPETS:
                break
            low = text.lower()
            pos = -1
            for t in terms:
                p = low.find(t)
                if p != -1 and (pos == -1 or p < pos):
                    pos = p
            if pos == -1:
                continue
            hits.append({"heading": heading, "anchor": anchor,
                         "snippet": _make_snippet(text, pos, terms)})
        return hits


def _make_snippet(text, pos, terms):
    """~180 characters around the match, cut at word boundaries, with the
    matched terms marked.

    ESCAPE ORDER: the text is escaped BEFORE <mark> is inserted. Escaping
    afterwards would escape the marks themselves and print &lt;mark&gt; on the
    page — the same ordering rule the renderer follows.
    """
    half = SNIPPET_CHARS // 2
    start = max(0, pos - half)
    end = min(len(text), start + SNIPPET_CHARS)
    if start > 0:
        sp = text.find(" ", start)
        start = sp + 1 if 0 <= sp < start + 30 else start
    if end < len(text):
        sp = text.rfind(" ", start, end)
        end = sp if sp > start else end

    frag = text[start:end].strip()
    frag = (frag.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))

    for t in sorted(set(terms), key=len, reverse=True):
        frag = re.sub("(?i)(?<!<mark>)(" + re.escape(t) + ")",
                      r"<mark>\1</mark>", frag)

    return ("…" if start > 0 else "") + frag + ("…" if end < len(text) else "")
