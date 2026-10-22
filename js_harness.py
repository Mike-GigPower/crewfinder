"""Run named functions from templates/index.html under node, for tests of the
dialogs' behaviour without a browser.

extract(names) pulls each top-level `function NAME(` / `async function NAME(`
out of the page's script, brace-matched with strings, template literals,
comments and regex literals skipped. run(js) executes a script under node and
returns what it printed as JSON on its last line. The test supplies the stubs
(document, fetch, confirm…); nothing here fakes behaviour.

Tests using it skip when node isn't installed."""

import json
import os
import re
import shutil
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
INDEX = os.path.join(HERE, "templates", "index.html")
NODE = shutil.which("node")

_page = None


def page():
    global _page
    if _page is None:
        with open(INDEX, encoding="utf-8") as f:
            _page = f.read()
    return _page


def _skip_string(src, i, quote):
    i += 1
    while i < len(src):
        c = src[i]
        if c == "\\":
            i += 2
            continue
        if quote == "`" and src.startswith("${", i):
            i = _match_brace(src, i + 1) + 1
            continue
        if c == quote:
            return i + 1
        i += 1
    raise ValueError("unterminated string")


def _regex_allowed(src, i):
    j = i - 1
    while j >= 0 and src[j] in " \t\r\n":
        j -= 1
    return j < 0 or src[j] in "(,=:[!&|?{};+-*%<>~^" or src[j - 5:j + 1] == "return"


def _skip_regex(src, i):
    i += 1
    in_class = False
    while i < len(src):
        c = src[i]
        if c == "\\":
            i += 2
            continue
        if c == "[":
            in_class = True
        elif c == "]":
            in_class = False
        elif c == "/" and not in_class:
            i += 1
            while i < len(src) and src[i].isalpha():
                i += 1
            return i
        elif c == "\n":
            raise ValueError("unterminated regex")
        i += 1
    raise ValueError("unterminated regex")


def _match_brace(src, i):
    """Index of the } closing the { at src[i]."""
    depth = 0
    while i < len(src):
        c = src[i]
        if c in "'\"`":
            i = _skip_string(src, i, c)
            continue
        if src.startswith("//", i):
            i = src.index("\n", i)
            continue
        if src.startswith("/*", i):
            i = src.index("*/", i) + 2
            continue
        if c == "/" and _regex_allowed(src, i):
            i = _skip_regex(src, i)
            continue
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise ValueError("unbalanced braces")


def extract(*names):
    src = page()
    out = []
    for name in names:
        m = re.search(r"^(?:async )?function " + re.escape(name) + r"\(", src, re.M)
        if not m:
            raise LookupError(f"function {name} not found in index.html")
        end = _match_brace(src, src.index("{", m.end()))
        out.append(src[m.start():end + 1])
    return "\n\n".join(out)


def run(js, timeout=20):
    """Run js under node; return the JSON on the last line it printed."""
    p = subprocess.run([NODE, "-e", js], capture_output=True, text=True, timeout=timeout)
    if p.returncode != 0:
        raise AssertionError(f"node failed:\n{p.stderr}\n{p.stdout}")
    lines = [l for l in p.stdout.splitlines() if l.strip()]
    return json.loads(lines[-1])


# A minimal document: elements by id, created on first lookup, with innerHTML,
# value, checked, style and isConnected. Enough for the dialogs' render code.
DOM_PRELUDE = r"""
var window = globalThis;
var _els = {};
function _el(id){
  if(!_els[id]) _els[id] = { id: id, innerHTML: '', value: '', checked: false, style: {},
                             isConnected: true, textContent: '', setAttribute: function(){},
                             getAttribute: function(){ return null; } };
  return _els[id];
}
var document = {
  getElementById: function(id){ return _els[id] || null; },
  querySelector: function(){ return null; },
  querySelectorAll: function(){ return []; },
  createElement: function(){ return { innerHTML: '', value: '' }; },
};
function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function decodeEntities(s){ return s == null ? '' : String(s); }
function entityRow(label, value){ return '<row ' + label + '>' + value + '</row>'; }
var EF_INPUT_STYLE = '';
var CT_HONESTY = 'honesty';
var _feedback = [];
function showFeedback(m){ _feedback.push(m); }
function confirm(){ return true; }
"""
