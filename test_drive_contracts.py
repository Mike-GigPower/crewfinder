"""Tests for drive_contracts: title parsing, crew matching, grouping.

Titles are real ones from the Completed/Signed folder (9 Oct 2026 inventory).
No Google access needed.
"""

import pytest

from drive_contracts import (parse_contract_title, match_crew, group_files,
                             contract_version, surname_token)


# ─── parse_contract_title ────────────────────────────────────────────────────

@pytest.mark.parametrize("title, name, signed_at", [
    ("Steven Menzies - Casual Employment Agreement 2025 - 16/03/2026, 15:50",
     "Steven Menzies", "2026-03-16 15:50:00"),
    ("Talia Smith- Casual Employment Agreement 2025 - 21/10/2025, 09:05",
     "Talia Smith", "2025-10-21 09:05:00"),
    ("Livio Pignataro  - Casual Employment Agreement 2025 - 02/11/2025, 18:40",
     "Livio Pignataro", "2025-11-02 18:40:00"),
    ("Ethan Warren - Casual Employment Agreement 2025 - 03_10_2025, 14_21.pdf",
     "Ethan Warren", "2025-10-03 14:21:00"),
    ("James Harrington - Casual Employment Agreement 2026 - 110/12/2025, 11:57",
     "James Harrington", None),
    ("Koby Heerah Gig Power - Casual Employment Agreement 2025 - 10/12/2025, 13:04",
     "Koby Heerah", "2025-12-10 13:04:00"),
    ("samuel hinton casual employment agreement 2025.pdf", "Samuel Hinton", None),
    ("Bowman Jarod Signed Page.pdf", "Bowman Jarod", None),
    ("Cassidy Liddle Employment(signed) - Copy.pdf", "Cassidy Liddle", None),
    ("Adam Brennan - Casual Employment Agreement 2025.pdf", "Adam Brennan", None),
])
def test_parse_real_titles(title, name, signed_at):
    p = parse_contract_title(title)
    assert p is not None
    assert p["name"] == name
    assert p["signed_at"] == signed_at


@pytest.mark.parametrize("title", [
    "Stephen Law TFN.pdf",
    "VEVO Visa Details Check - LEWIS FISHER WHEATLEY.pdf",
    "Visa Grant Notice - Someone.pdf",
    "IMMI acknowledgement.pdf",
])
def test_noise_is_none(title):
    assert parse_contract_title(title) is None


def test_names_that_contain_noise_words_are_not_noise():
    # Live-folder regressions: plain-substring "grant" and "immi" skipped these.
    p = parse_contract_title("Grant Miller - Casual Employment Agreement 2025 - 10/10/2025, 11:35")
    assert p is not None and p["name"] == "Grant Miller"
    assert parse_contract_title("Kimmi Lee - Casual Employment Agreement 2025")["name"] == "Kimmi Lee"
    assert parse_contract_title("Zane Simmill - Casual Employment Agreement 2025 - 16/03/2026, 17:13")["name"] == "Zane Simmill"
    assert parse_contract_title("Cameron Kimmins - Casual Employment Agreement 2025.pdf")["name"] == "Cameron Kimmins"


def test_year_is_agreement_year_not_signing_year():
    p = parse_contract_title("Steven Menzies - Casual Employment Agreement 2025 - 16/03/2026, 15:50")
    assert p["year"] == "2025"
    p = parse_contract_title("James Harrington - Casual Employment Agreement 2026 - 110/12/2025, 11:57")
    assert p["year"] == "2026"
    assert parse_contract_title("Bowman Jarod Signed Page.pdf")["year"] is None


def test_title_starting_with_date_has_no_name():
    p = parse_contract_title("10 Dec 2025 Gig Power - Casual Employment Agreement.pdf")
    assert p is not None and p["name"] == ""


def test_title_with_no_person_has_no_name():
    p = parse_contract_title("Gig Power - Casual Employment Agreement 2025 - 03/10/2025, 10:00")
    assert p["name"] == ""
    assert p["signed_at"] == "2025-10-03 10:00:00"


def test_pdf_export_tail_is_stripped():
    p = parse_contract_title(
        "Ethan Warren - Casual Employment Agreement 2025.pdf_20251005_135542_0000.pdf")
    assert p["name"] == "Ethan Warren"
    assert p["signed_at"] is None
    assert p["year"] == "2025"


def test_invalid_calendar_date_is_none():
    p = parse_contract_title("Ann Lee - Casual Employment Agreement 2025 - 31/02/2025, 10:00")
    assert p["signed_at"] is None


def test_mixed_case_word_kept():
    p = parse_contract_title("Liam McDonald - Casual Employment Agreement 2025")
    assert p["name"] == "Liam McDonald"


def test_contract_version():
    assert contract_version("2025") == "drive-import 2025"
    assert contract_version(None) == "drive-import"


# ─── match_crew ──────────────────────────────────────────────────────────────

ROSTER = [
    {"id": "101", "name": "Bowman, Jarod", "ein": "101"},
    {"id": "2210", "name": "Evans, Ziggy", "ein": "2210"},
    {"id": "301", "name": "Smith, John", "ein": "301"},
    {"id": "302", "name": "Smith, John", "ein": "302"},
    {"id": "400", "name": "Menzies, Steven", "ein": "400"},
]


def test_exact_reversed_order():
    m = match_crew("Bowman Jarod", ROSTER)
    assert m["bucket"] == "exact"
    assert m["suggestions"][0]["id"] == "101"
    m = match_crew("Jarod Bowman", ROSTER)
    assert m["bucket"] == "exact"


def test_likely_with_middle_name():
    m = match_crew("Ziggy Roy Evans", ROSTER)
    assert m["bucket"] == "likely"
    assert [s["id"] for s in m["suggestions"]] == ["2210"]


def test_ambiguous_identical_names():
    m = match_crew("John Smith", ROSTER)
    assert m["bucket"] == "ambiguous"
    assert sorted(s["id"] for s in m["suggestions"]) == ["301", "302"]


def test_none():
    assert match_crew("Nobody Atall", ROSTER) == {"bucket": "none", "suggestions": []}


def test_empty_name():
    assert match_crew("", ROSTER) == {"bucket": "none", "suggestions": []}


def test_typo_is_likely():
    m = match_crew("Steven Menzie", ROSTER)
    assert m["bucket"] == "likely"
    assert m["suggestions"][0]["id"] == "400"


def test_surname_token():
    assert surname_token("Evans, Ziggy") == "evans"
    assert surname_token("de Silva, Ana") == "silva"


# ─── group_files ─────────────────────────────────────────────────────────────

def _f(fid, bucket, crew_id, signed_at):
    return {"drive_file_id": fid, "title": fid, "web_view_link": "",
            "bucket": bucket, "signed_at": signed_at,
            "suggestions": [{"id": crew_id, "name": "x", "ein": crew_id}] if crew_id else []}


def test_group_keeps_latest_signed_as_primary():
    rows = group_files([
        _f("a", "exact", "1", "2025-10-01 10:00:00"),
        _f("b", "likely", "1", "2026-01-01 10:00:00"),
        _f("c", "exact", "1", None),
        _f("d", "exact", "2", None),
    ])
    assert [r["drive_file_id"] for r in rows] == ["b", "d"]
    assert [c["drive_file_id"] for c in rows[0]["other_copies"]] == ["a", "c"]
    assert rows[1]["other_copies"] == []


def test_ambiguous_and_none_never_grouped():
    rows = group_files([
        _f("a", "ambiguous", "1", None),
        _f("b", "ambiguous", "1", None),
        _f("c", "none", None, None),
        _f("d", "none", None, None),
    ])
    assert len(rows) == 4
