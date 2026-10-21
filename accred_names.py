"""File names for the accreditation photo export.

Pure functions, no Flask and no network, so test_accred_names.py can drive them
directly. See DESIGN-accreditation-photo-export-v0_3.md §5.2.

A pattern is free text with tokens in braces. The case of the token sets the case
of what it produces:

    {first} {last}   lower case          mary-jane / obrien
    {First} {Last}   each part capped    Mary-Jane / Obrien
    {FIRST} {LAST}   upper case          MARY-JANE / OBRIEN
    {f} {l}          initial, lower      m / o
    {F} {L}          initial, upper      M / O
    {ein}            EIN as stored       3315

Anything outside a token is copied as typed, then the whole result is cleaned
so it is a safe file name on macOS and Windows.
"""
import re
import unicodedata

DEFAULT_PATTERN = "{first}_{last}"
MAX_PATTERN_LEN = 60

PRESETS = [
    "{first}_{last}",
    "{f}.{last}",
    "{First} {Last}",
    "{LAST}_{First}",
    "{first}_{last}_{ein}",
]

_TOKENS = ("first", "First", "FIRST", "last", "Last", "LAST",
           "f", "F", "l", "L", "ein")
_TOKEN_RE = re.compile(r"\{([^{}]*)\}")
_APOSTROPHES = ("'", "’", "‘", "`")
_UNSAFE_RE = re.compile(r"[^A-Za-z0-9._\- ]")


def _strip_accents(s):
    return "".join(c for c in unicodedata.normalize("NFKD", s)
                   if not unicodedata.combining(c))


def clean_part(s):
    """One name part: accents off, apostrophes dropped, unsafe characters to _."""
    s = _strip_accents(str(s or "")).strip()
    for a in _APOSTROPHES:
        s = s.replace(a, "")
    s = re.sub(r"\s+", " ", s)
    return _UNSAFE_RE.sub("_", s)


def tidy(s):
    """Whole-result cleanup: unsafe characters to _, collapse runs of _, trim edges."""
    s = _UNSAFE_RE.sub("_", s)
    s = re.sub(r"_+", "_", s)
    return s.strip("._- ")


def _cap(s):
    # Capitalise each space- and hyphen-separated part: "mary-jane" -> "Mary-Jane",
    # "van der berg" -> "Van Der Berg". Simple and predictable beats clever.
    return re.sub(r"[A-Za-z0-9]+", lambda m: m.group(0)[:1].upper() + m.group(0)[1:].lower(), s)


def validate_pattern(pattern):
    """None when the pattern is usable, else a short message for the operator."""
    if not isinstance(pattern, str) or not pattern.strip():
        return "Enter a file name format."
    if len(pattern) > MAX_PATTERN_LEN:
        return f"The format can be at most {MAX_PATTERN_LEN} characters."
    if pattern.count("{") != pattern.count("}"):
        return "Every { needs a matching }."
    for tok in _TOKEN_RE.findall(pattern):
        if tok not in _TOKENS:
            return ("Unknown part {" + tok + "}. Use {first} {last} {f} {l} {ein} "
                    "(capitalise for Upper or UPPER).")
    if not _TOKEN_RE.search(pattern):
        return "The format must include at least one name part, e.g. {first}."
    return None


def build_name(pattern, first, last, ein):
    """File name WITHOUT extension. Assumes validate_pattern(pattern) is None."""
    first = clean_part(first)
    last = clean_part(last)
    ein = clean_part(ein)

    def sub(m):
        t = m.group(1)
        if t == "first": return first.lower()
        if t == "First": return _cap(first)
        if t == "FIRST": return first.upper()
        if t == "last":  return last.lower()
        if t == "Last":  return _cap(last)
        if t == "LAST":  return last.upper()
        if t == "f":     return first[:1].lower()
        if t == "F":     return first[:1].upper()
        if t == "l":     return last[:1].lower()
        if t == "L":     return last[:1].upper()
        if t == "ein":   return ein
        return ""

    out = tidy(_TOKEN_RE.sub(sub, pattern))
    if not out:
        out = tidy("crew_" + ein) or "crew"
    return out


def assign_names(people, pattern):
    """people: list of dicts with first, last, ein (and anything else).

    Returns a NEW list sorted by last then first name, each item a copy with
    `stem` (no extension) and `clash` (bool) added. On a case-insensitive clash
    the 2nd and later people get _<ein> appended; if that still clashes, a
    counter is added so two files can never share a name in the zip.
    """
    ordered = sorted(people, key=lambda p: (clean_part(p.get("last")).lower(),
                                            clean_part(p.get("first")).lower(),
                                            str(p.get("ein") or "")))
    used, out = set(), []
    for p in ordered:
        stem = build_name(pattern, p.get("first"), p.get("last"), p.get("ein"))
        clash = False
        if stem.lower() in used:
            clash = True
            ein = clean_part(p.get("ein"))
            stem = tidy(stem + "_" + ein) if ein else stem
            n = 2
            base = stem
            while stem.lower() in used:
                stem = f"{base}_{n}"
                n += 1
        used.add(stem.lower())
        q = dict(p)
        q["stem"] = stem
        q["clash"] = clash
        out.append(q)
    return out


def slug(s, limit=30):
    """Lower-case, file-safe fragment for the zip name (customer)."""
    s = tidy(clean_part(s).lower().replace(" ", "_"))
    return s[:limit].strip("._- ")
