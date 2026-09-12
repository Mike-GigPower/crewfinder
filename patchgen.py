"""Generate docs/patches/<version>.md from GitHub release data.

Run once to backfill THE GOAT's patch history. From 5.44.0 onward each
release writes its own file as part of the release.
"""
import os
import re
import datetime

# Relative to wherever this is run from — the repo root in normal use.
# Override with patchgen.OUT = "..." if you need somewhere else.
OUT = os.path.join("docs", "patches")
MONTHS = ["January", "February", "March", "April", "May", "June", "July",
          "August", "September", "October", "November", "December"]

_VERSION_RE = re.compile(r"^\d+\.\d+(\.\d+)?$")


def norm_version(tag):
    """'v5.43.0' -> '5.43.0'; '2.1.0' -> '2.1.0'. Returns None if not a version."""
    v = (tag or "").strip()
    if v[:1].lower() == "v":
        v = v[1:]
    v = v.strip()
    return v if _VERSION_RE.match(v) else None


def file_stem(version):
    """5.43.0 -> 5_43_0, matching the CHANGELOG-x_y_z.md house convention."""
    return version.replace(".", "_")


def pretty_date(iso):
    """'2026-09-12T12:48:22Z' -> ('2026-09-12', '12 September 2026')."""
    d = datetime.datetime.strptime(iso[:10], "%Y-%m-%d").date()
    return d.isoformat(), "%d %s %d" % (d.day, MONTHS[d.month - 1], d.year)


def first_sentence(body, limit=140):
    """First sentence of the body, for the front-matter summary."""
    text = " ".join(body.split())
    if not text:
        return ""
    # Split on sentence end, avoiding version numbers like 3.14.0.
    m = re.search(r"(?<![0-9])[.!?](?:\s|$)", text)
    s = text[:m.start() + 1] if m else text
    if len(s) > limit:
        s = s[:limit].rsplit(" ", 1)[0].rstrip(",;:") + "…"
    return s


def yaml_escape(s):
    """Front matter is parsed by a simple key: value reader, so keep values
    on one line and quote anything that would confuse it."""
    s = " ".join(s.split())
    if s.startswith(("'", '"', "[", "{", "&", "*", "!", "|", ">", "%", "@", "`")) or ": " in s or s.endswith(":"):
        return '"' + s.replace('\\', '\\\\').replace('"', '\\"') + '"'
    return s


def write_release(tag, published_at, body):
    """Write one patch note. Returns (version, is_stub) or None if skipped."""
    version = norm_version(tag)
    if not version:
        return None

    body = (body or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    stub = not body
    if stub:
        body = ("_No release notes were recorded for this version._\n\n"
                "See the repository history for what changed.")

    iso, human = pretty_date(published_at)
    summary = first_sentence(body) if not stub else "No release notes recorded for this version."

    doc = (
        "---\n"
        "title: %s\n"
        "summary: %s\n"
        "updated: %s\n"
        "---\n\n"
        "# %s\n\n"
        "*Released %s*\n\n"
        "%s\n"
    ) % (version, yaml_escape(summary), iso, version, human, body)

    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, file_stem(version) + ".md")
    with open(path, "w", encoding="utf-8") as f:
        f.write(doc)
    return version, stub


def run(releases):
    written, stubs, skipped = [], [], []
    for r in releases:
        res = write_release(r["tag_name"], r["published_at"], r.get("body") or "")
        if res is None:
            skipped.append(r["tag_name"])
            continue
        version, is_stub = res
        written.append(version)
        if is_stub:
            stubs.append(version)
    print("wrote %d  stubs %d  skipped %d %s"
          % (len(written), len(stubs), len(skipped), skipped or ""))
    return written, stubs, skipped
