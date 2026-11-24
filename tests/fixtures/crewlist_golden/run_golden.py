#!/usr/bin/env python3
"""Golden test for the PRINTED crew list (BRIEF-crewlist-export-and-doc-schedule §5).

Each case in cases/ holds a made-up get-booking.php booking and the opts the
Create Crew Lists dialog would pass. golden/ holds the HTML that
templates/index.html's own _clBuildHtml produced for it at 5.120.0, recorded
BEFORE the on-site rows and the model/renderer split went in. This repo is
public, so every name, phone and EIN in cases/ is invented.

    python3 tests/fixtures/crewlist_golden/run_golden.py --write   # record golden/
    python3 tests/fixtures/crewlist_golden/run_golden.py           # compare

THE POINT: the CSV work of 5.121.0 split _clBuildHtml into _clBuildModel plus a
renderer, and the printed list had to come out byte-identical apart from the two
new header rows (D5). So the comparison strips the On-site and On-site phone
rows from today's output and expects exactly the 5.120.0 bytes back. Any other
drift in the printed list — a column, a style, the hint, the QR — is a diff here
and a failure.

Re-record a golden ONLY when the printed list is meant to change, and say in the
release notes what changed. Do not re-record to make a red test green.
"""
import argparse
import difflib
import io
import glob
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, ROOT)
import js_harness as H                               # noqa: E402

CASES = os.path.join(HERE, "cases")
GOLDEN = os.path.join(HERE, "golden")
QR_LIB = os.path.join(ROOT, "static", "qrcode-generator.js")

# The printed list's own helpers. _clBuildModel only exists from 5.121.0, so it
# is optional — the same runner records the 5.120.0 golden and checks today's.
FUNCS = (
    "_clBuildHtml", "_clKeepCrew", "_clWhen", "_clTime", "_clName", "_clHdrRow",
    "_clQrSvg", "_clCallLine", "crewStatusIsWithdrawn", "_clDietaryCell",
    "_clDietarySummary", "dietarySortedCodes", "ceDietaryLabel",
)
# 5.121.0 and later only, so the same runner can re-record from an older
# checkout: it rendered the 5.120.0 golden before the split existed.
OPTIONAL_FUNCS = ("_clBuildModel", "_clBuildCsvRows", "_clNameText",
                  "_clDietaryCounts", "_clDietarySummaryText", "_clCsvText")

# Declarations the helpers close over, lifted from the page rather than retyped.
DECLS = (
    ("var", "CREW_WITHDRAWN_WORDS"),
    ("var", "DIETARY_CODES"),
    ("const", "CL_GP_FALLBACK"),      # 5.121.0 and later only
    ("const", "CL_HINT"),             # 5.121.0 and later only
)

# The two rows D5 added. Stripping them from today's output must give back the
# 5.120.0 bytes exactly.
ONSITE_ROW_RE = re.compile(
    r"<tr><th>On-site(?: phone)?:</th><td>[^<]*</td></tr>")


def _decl(kind, name):
    """`var NAME = …;` / `const NAME = …;` as written in index.html, or ''."""
    src = H.page()
    m = re.search(r"^%s %s\s*=" % (kind, re.escape(name)), src, re.M)
    if not m:
        return ""
    # To the first semicolon at brace/bracket depth 0 — covers objects and arrays.
    i, depth = m.end(), 0
    while i < len(src):
        c = src[i]
        if c in "{[":
            depth += 1
        elif c in "}]":
            depth -= 1
        elif c == ";" and depth == 0:
            return src[m.start():i + 1]
        i += 1
    raise ValueError("unterminated declaration for " + name)


def _funcs():
    out = [H.extract(*FUNCS)]
    for name in OPTIONAL_FUNCS:
        try:
            out.append(H.extract(name))
        except LookupError:
            pass
    return "\n\n".join(out)


def _qr_lib():
    with io.open(QR_LIB, encoding="utf-8") as fh:
        return fh.read()


def render(case):
    """_clBuildHtml(data, opts) under node, with the real QR encoder loaded."""
    js = "\n".join([
        "process.env.TZ = 'Australia/Melbourne';",
        H.DOM_PRELUDE,
        _qr_lib(),
        "\n".join(_decl(k, n) for k, n in DECLS),
        _funcs(),
        "var opts = %s;" % json.dumps(case["opts"]),
        "var html = _clBuildHtml(%s, opts);" % json.dumps(case["data"]),
        "console.log(JSON.stringify({html: html}));",
    ])
    return H.run(js)["html"]


def strip_onsite(html):
    return ONSITE_ROW_RE.sub("", html)


def case_paths():
    return sorted(glob.glob(os.path.join(CASES, "*.json")))


def golden_path(path):
    return os.path.join(GOLDEN, os.path.basename(path)[:-5] + ".html")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--write", action="store_true", help="record golden/ from today's output")
    args = ap.parse_args()

    if not H.NODE:
        print("node not installed: crew-list golden skipped")
        return 0

    failed = []
    for path in case_paths():
        name = os.path.basename(path)[:-5]
        with open(path, encoding="utf-8") as f:
            case = json.load(f)
        html = render(case)
        gp = golden_path(path)
        if args.write:
            with open(gp, "w", encoding="utf-8") as f:
                f.write(html)
            print("wrote %s (%d bytes)" % (os.path.relpath(gp, ROOT), len(html)))
            continue
        with open(gp, encoding="utf-8") as f:
            want = f.read()
        got = strip_onsite(html)
        if got == want:
            print("ok   %s" % name)
        else:
            failed.append(name)
            print("FAIL %s" % name)
            for line in difflib.unified_diff(
                    want.replace("><", ">\n<").splitlines(),
                    got.replace("><", ">\n<").splitlines(),
                    "golden", "today", lineterm="", n=2):
                print("     " + line)
    if failed:
        print("\n%d case(s) differ from the 5.120.0 printed list: %s" % (len(failed), ", ".join(failed)))
        return 1
    print("\nprinted list unchanged apart from the On-site rows.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
