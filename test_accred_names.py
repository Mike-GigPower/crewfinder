#!/usr/bin/env python3
"""Tests for accred_names.py — accreditation photo file names.

No network, no Flask. Run:
    python3 test_accred_names.py
"""
import sys

import accred_names as A

fails = []
def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ((" — " + str(detail)) if detail else ""))
    if not cond: fails.append(label)

def eq(label, got, want):
    check(label, got == want, f"got {got!r}, want {want!r}")

print("presets (Jane Smith, 3315)")
want = {
    "{first}_{last}":       "jane_smith",
    "{f}.{last}":           "j.smith",
    "{First} {Last}":       "Jane Smith",
    "{LAST}_{First}":       "SMITH_Jane",
    "{first}_{last}_{ein}": "jane_smith_3315",
}
eq("preset list matches the design", A.PRESETS, list(want))
for p, w in want.items():
    eq(p, A.build_name(p, "Jane", "Smith", "3315"), w)

print("awkward names")
eq("O'Brien apostrophe dropped", A.build_name("{first}_{last}", "Sean", "O'Brien", "1"), "sean_obrien")
eq("curly apostrophe dropped",   A.build_name("{first}_{last}", "Sean", "O’Brien", "1"), "sean_obrien")
eq("accent stripped",            A.build_name("{first}_{last}", "José", "Núñez", "1"), "jose_nunez")
eq("hyphen kept, capped",        A.build_name("{First} {Last}", "mary-jane", "smith", "1"), "Mary-Jane Smith")
eq("multi-word surname",         A.build_name("{First}_{Last}", "Pieter", "van der Berg", "1"), "Pieter_Van Der Berg")
eq("multi-word lower",           A.build_name("{first}_{last}", "Pieter", "van der Berg", "1"), "pieter_van der berg")
eq("initial of accented name",   A.build_name("{f}.{last}", "Émile", "Zola", "1"), "e.zola")
eq("upper initials",             A.build_name("{F}{L}_{ein}", "jane", "smith", "77"), "JS_77")
eq("unsafe chars become _",      A.build_name("{first}/{last}", "Jane", "Smith", "1"), "jane_smith")
eq("slash inside a name",        A.build_name("{first}_{last}", "Jane", "Smith/Jones", "1"), "jane_smith_jones")
eq("empty first name, {f}.{last}", A.build_name("{f}.{last}", "", "Smith", "9"), "smith")
eq("everything empty -> crew_ein", A.build_name("{first}_{last}", "", "", "9"), "crew_9")
eq("literal text kept",          A.build_name("GP-{LAST}-{f}", "Jane", "Smith", "1"), "GP-SMITH-j")
eq("leading/trailing junk trimmed", A.build_name("_{first}_", "Jane", "Smith", "1"), "jane")

print("pattern validation")
check("presets all valid", all(A.validate_pattern(p) is None for p in A.PRESETS))
check("custom {LAST}-{f} valid", A.validate_pattern("{LAST}-{f}") is None)
bad = A.validate_pattern("{nme}_{last}")
check("bad token refused and named", bad is not None and "{nme}" in bad, bad)
check("60 chars ok",  A.validate_pattern("{first}" + "x" * 53) is None)
check("61 chars refused", A.validate_pattern("{first}" + "x" * 54) is not None)
check("empty refused", A.validate_pattern("  ") is not None)
check("non-string refused", A.validate_pattern(None) is not None)
check("unbalanced brace refused", A.validate_pattern("{first_{last}") is not None)
check("no token refused", A.validate_pattern("photo") is not None)

print("assign_names: sort + clashes")
people = [
    {"id": 3, "first": "Jane", "last": "Smith", "ein": "200"},
    {"id": 1, "first": "Adam", "last": "Brown", "ein": "100"},
    {"id": 2, "first": "Jane", "last": "Smith", "ein": "150"},
    {"id": 4, "first": "JANE", "last": "SMITH", "ein": "300"},
]
got = A.assign_names(people, "{first}_{last}")
eq("sorted by last, first, ein", [p["id"] for p in got], [1, 2, 3, 4])
eq("stems", [p["stem"] for p in got], ["adam_brown", "jane_smith", "jane_smith_200", "jane_smith_300"])
eq("clash flags", [p["clash"] for p in got], [False, False, True, True])
got2 = A.assign_names(people, "{First} {Last}")
eq("case-insensitive clash with {First}", [p["stem"] for p in got2],
   ["Adam Brown", "Jane Smith", "Jane Smith_200", "Jane Smith_300"])
dup_ein = [{"first": "A", "last": "B", "ein": "5"}, {"first": "A", "last": "B", "ein": "5"}]
st = [p["stem"] for p in A.assign_names(dup_ein, "{first}_{last}_{ein}")]
check("never two identical stems", len(set(s.lower() for s in st)) == 2, st)
check("input list not mutated", "stem" not in people[0])

print("slug")
eq("customer slug", A.slug("Live Nation Australia Pty Ltd"), "live_nation_australia_pty_ltd")
eq("slug limit", len(A.slug("x" * 80)) <= 30, True)
eq("slug of &amp;-free name with accents", A.slug("Frontier Touring & Co"), "frontier_touring_co")

print()
if fails:
    print(f"{len(fails)} FAILED"); sys.exit(1)
print("ALL PASS")
