"""Printed crew list: QR code, Call #, break start/minutes and Notes columns
(BRIEF-timesheet-scan-slice1 §2, §4.2; design T1, T2, T6).

Runs the page's own _clBuildHtml under node (js_harness) with the vendored
encoder (static/qrcode-generator.js) loaded, on made-up data only: booking and
call 90001, crew 90001+. Skips when node isn't installed.

The QR is checked by DECODING it, not by re-encoding and comparing: the SVG is
rasterised here (it is only module squares) and read by macOS Vision, the same
reader as the iPhone Camera app, through JavaScript for Automation (osascript),
which needs no compiler. That test skips off macOS.
"""

import datetime
import os
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import unittest
import zlib

import js_harness as H

HERE = os.path.dirname(os.path.abspath(__file__))
QR_LIB = os.path.join(HERE, "static", "qrcode-generator.js")

FUNCS = H.extract(
    "_clBuildHtml", "_clKeepCrew", "_clWhen", "_clTime", "_clName", "_clHdrRow",
    "_clQrSvg", "_clCallLine", "crewStatusIsWithdrawn",
) if H.NODE else ""


def _decl(name, open_c, close_c):
    """`var NAME = <literal>;` lifted from index.html, as test_reoffer_left_standby_js."""
    src = H.page()
    i = src.index("var %s = %s" % (name, open_c))
    j = src.index("%s;" % close_c, i)
    return src[i:j + 2]


CONSTS = _decl("CREW_WITHDRAWN_WORDS", "{", "}") if H.NODE else ""

# Midnight 20 Nov 2026 in Melbourne (AEDT, UTC+11) — SmartStaff's start_date is
# the day; start_time carries the clock. The script pins TZ so the printed
# date is the same whatever the test machine is set to.
START_DATE = int(datetime.datetime(2026, 11, 19, 13, 0, tzinfo=datetime.timezone.utc).timestamp())


def _crew(n):
    return [{"id": 90001 + i, "firstname": "Test%d" % i, "lastname": "Person",
             "status": "confirmed", "mobile": "0400 000 %03d" % i} for i in range(n)]


def _data(crew):
    return {
        "name": "Booking 90001",
        "customer": {"name": "Test Customer"},
        "venue": {"name": "Test Venue"},
        "contact": {"name": "Test Contact"},
        "calls": [{
            "call_id": 90001, "call_name": "Load In", "start_date": START_DATE,
            "start_time": "22:00:00", "est_length": "8", "required": len(crew),
            "confirmed": len(crew), "notes": "", "crew": crew,
        }],
    }


def build(data, opts, with_encoder=True):
    """_clBuildHtml(data, opts) under node → (html, opts after the build)."""
    lib = open(QR_LIB, encoding="utf-8").read() if with_encoder else ""
    js = "\n".join([
        "process.env.TZ = 'Australia/Melbourne';",
        H.DOM_PRELUDE, lib, CONSTS, FUNCS,
        "var opts = %s;" % _json(opts),
        "var html = _clBuildHtml(%s, opts);" % _json(data),
        "console.log(JSON.stringify({html: html, qrMissing: opts.qrMissing || 0}));",
    ])
    out = H.run(js)
    return out["html"], out["qrMissing"]


def _json(v):
    import json
    return json.dumps(v)


def thead_rows(html):
    thead = re.search(r"<thead>(.*?)</thead>", html, re.S).group(1)
    return re.findall(r"<tr[^>]*>(.*?)</tr>", thead, re.S)


def header_cells(row):
    return [re.sub(r"<[^>]+>", "", c).strip() for c in re.findall(r"<th[^>]*>(.*?)</th>", row, re.S)]


# ── QR decode: rasterise the SVG, read it with macOS Vision ─────────────────

OSASCRIPT = shutil.which("osascript") if sys.platform == "darwin" else None

VISION_SRC = r"""
ObjC.import('Foundation');
ObjC.import('Vision');
function run(argv){
  var req = $.VNDetectBarcodesRequest.alloc.init;
  var h = $.VNImageRequestHandler.alloc.initWithURLOptions($.NSURL.fileURLWithPath(argv[0]), $({}));
  h.performRequestsError($([req]), null);
  var res = req.results, out = [];
  for (var i = 0; i < res.count; i++) out.push(ObjC.unwrap(res.objectAtIndex(i).payloadStringValue));
  return out.join('\n');
}
"""


def svg_to_png(svg, path, scale=10):
    """The encoder's SVG is a white rect plus one path of unit squares
    ('M x,y l1,0 0,1 -1,0 0,-1z'). Draw those squares into a greyscale PNG."""
    size = int(re.search(r'viewBox="0 0 (\d+) (\d+)"', svg).group(1))
    dark = {(int(x), int(y)) for x, y in re.findall(r"M(\d+),(\d+)l", svg)}
    px = size * scale
    rows = []
    for py in range(px):
        y = py // scale
        rows.append(b"\x00" + bytes(0 if (x // scale, y) in dark else 255 for x in range(px)))

    def chunk(tag, data):
        return struct.pack(">I", len(data)) + tag + data + struct.pack(">I", zlib.crc32(tag + data) & 0xffffffff)

    png = (b"\x89PNG\r\n\x1a\n"
           + chunk(b"IHDR", struct.pack(">IIBBBBB", px, px, 8, 0, 0, 0, 0))
           + chunk(b"IDAT", zlib.compress(b"".join(rows)))
           + chunk(b"IEND", b""))
    with open(path, "wb") as f:
        f.write(png)


def decode_qr(svg):
    with tempfile.TemporaryDirectory() as d:
        img = os.path.join(d, "qr.png")
        src = os.path.join(d, "read.js")
        svg_to_png(svg, img)
        with open(src, "w") as f:
            f.write(VISION_SRC)
        p = subprocess.run([OSASCRIPT, "-l", "JavaScript", src, img],
                           capture_output=True, text=True, timeout=60)
        if p.returncode != 0:
            raise AssertionError("Vision (osascript) failed:\n" + p.stderr)
        return [l for l in p.stdout.splitlines() if l.strip()]


@unittest.skipUnless(H.NODE, "node not installed")
class CrewListQr(unittest.TestCase):

    def setUp(self):
        self.html, self.missing = build(_data(_crew(3)), {"phone": True, "who": "confirmed"})
        self.rows = thead_rows(self.html)

    def test_first_thead_row_carries_call_and_svg(self):
        first = self.rows[0]
        self.assertIn('class="callrow"', self.html)
        self.assertIn("Call 90001", first)
        self.assertIn("<svg", first)
        self.assertIn("Call 90001 · Load In · 20/11/2026 22:00", first)
        self.assertEqual(self.missing, 0)

    @unittest.skipUnless(OSASCRIPT, "needs macOS for the Vision QR reader")
    def test_qr_decodes_to_exact_payload(self):
        svg = re.search(r"<svg.*?</svg>", self.rows[0], re.S).group(0)
        self.assertEqual(decode_qr(svg), ["GP1;c=90001"])

    def test_write_in_headers_in_order(self):
        cells = header_cells(self.rows[1])
        self.assertEqual(cells, ["Name", "Phone", "On", "Brk start", "Brk mins", "Off", "Notes"])
        # one write-in cell per write-in header on every crew row
        tbody = re.search(r"<tbody>(.*?)</tbody>", self.html, re.S).group(1)
        for tr in re.findall(r"<tr>(.*?)</tr>", tbody, re.S):
            self.assertEqual(tr.count('class="wr"'), 5)

    def test_call_row_spans_every_column(self):
        span = int(re.search(r'<th colspan="(\d+)">', self.rows[0]).group(1))
        self.assertEqual(span, len(header_cells(self.rows[1])))

    def test_hdr_has_call_number_and_hint(self):
        self.assertIn("<tr><th>Call #:</th><td>90001</td></tr>", self.html)
        self.assertIn("A second break: write it in Notes.", self.html)

    def test_empty_crew_colspan_matches_header(self):
        html, _ = build(_data([]), {"phone": True, "ein": True, "who": "confirmed"})
        rows = thead_rows(html)
        n = len(header_cells(rows[1]))
        span = int(re.search(r'<td colspan="(\d+)"[^>]*>No crew to list', html).group(1))
        self.assertEqual(span, n)
        self.assertEqual(n, 8)  # Name, EIN, Phone + the five write-ins

    def test_no_encoder_still_prints_call_number(self):
        html, missing = build(_data(_crew(2)), {"phone": False, "who": "confirmed"}, with_encoder=False)
        first = thead_rows(html)[0]
        self.assertNotIn("<svg", html)
        self.assertIn("Call 90001", first)
        self.assertEqual(missing, 1)

    def test_payload_has_no_people(self):
        # The SVG holds only the call: rebuilding with different crew gives the same QR.
        other, _ = build(_data(_crew(7)), {"phone": True, "who": "confirmed"})
        svg = lambda h: re.search(r"<svg.*?</svg>", h, re.S).group(0)
        self.assertEqual(svg(self.html), svg(other))


if __name__ == "__main__":
    unittest.main()
