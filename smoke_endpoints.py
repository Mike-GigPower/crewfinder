#!/usr/bin/env python3
"""
Smoke test for THE GOAT's SmartStaff data contract.

Hits the bulk + self + identity endpoints with a real authenticated session and
asserts the response *shape and types* the GOAT client depends on. This is cheap
insurance against the silent field drops / type flips that bit us with `status`:

    missing        -> client sees None  -> zero utilization, no conflicts
    constant 5     -> everything "confirmed" -> every timeline bar red
    real (5/6/1/8) -> correct red vs blue                          (the fix)

It reuses app.py's real login + BASE_URL so the test can't drift from production,
and it GETs the PHP endpoints DIRECTLY (not via the app's parsers) so it sees the
raw wire values — a PHP-side `(int)` cast regression won't be masked by the
client's _coerce_status().

Run:
    python3 smoke_endpoints.py

Credentials (admin recommended, so the bulk endpoints are exercised):
    SS_USER / SS_PASS environment variables, else config.json username/password.

Exit code 0 on all-pass, 1 on any failure, 2 on setup/login error (CI-friendly).
"""

import os
import sys
from datetime import datetime, timedelta

try:
    from app import create_ss_session, BASE_URL, load_config
except Exception as e:  # pragma: no cover
    print(f"FATAL: could not import from app.py: {e}")
    sys.exit(2)

VALID_STATUS = {0, 1, 5, 6, 8}  # 5=confirmed,1=pending,6=declined,8=noshow,0=unset

_PASS, _FAIL = [], []


def check(name, cond, detail=""):
    if cond:
        _PASS.append(name)
        print(f"  \u2713 {name}")
    else:
        _FAIL.append(name)
        print(f"  \u2717 {name}" + (f"  \u2014 {detail}" if detail else ""))
    return cond


def get_json(ss, path):
    """GET an endpoint; treat a non-JSON (HTML) body as a failure rather than
    letting json() raise the cryptic 'Unexpected token <' the UI showed."""
    try:
        resp = ss.get(BASE_URL + path, allow_redirects=True, timeout=20)
    except Exception as e:
        return None, f"request error: {e}"
    body = (resp.text or "").strip()
    ctype = resp.headers.get("Content-Type", "")
    looks_json = "application/json" in ctype or body[:1] in "{["
    if not looks_json:
        return None, f"HTTP {resp.status_code}, non-JSON body starts: {body[:60]!r}"
    try:
        return resp.json(), None
    except Exception as e:
        return None, f"JSON parse failed: {e}; body starts {body[:60]!r}"


def main():
    cfg = load_config()
    user = os.environ.get("SS_USER") or cfg.get("username", "")
    pw = os.environ.get("SS_PASS") or cfg.get("password", "")
    if not user or not pw:
        print("FATAL: no credentials (set SS_USER/SS_PASS or config.json username/password)")
        sys.exit(2)

    ss, err = create_ss_session(user, pw)
    if err or not ss:
        print(f"FATAL: login failed: {err}")
        sys.exit(2)
    print(f"Logged in to {BASE_URL} as {user}\n")

    today = datetime.now().date()
    start = today.strftime("%Y-%m-%d")
    end = (today + timedelta(days=30)).strftime("%Y-%m-%d")
    win = f"?start={start}&end={end}"

    # ── whoami ────────────────────────────────────────────────────────────────
    print("whoami.php")
    d, e = get_json(ss, "/ajax/crew/whoami.php")
    if check("whoami returns JSON", d is not None, e) and d:
        check("whoami cohort in {admin,leadership,crew}",
              d.get("cohort") in ("admin", "leadership", "crew"),
              f"got {d.get('cohort')!r}")
        for k in ("user_id", "ein", "name", "usergroupID"):
            check(f"whoami has '{k}'", k in d)

    # ── list-crew-bulk ─────────────────────────────────────────────────────────
    print("list-crew-bulk.php")
    d, e = get_json(ss, "/ajax/crew/list-crew-bulk.php")
    if check("list-crew-bulk returns JSON", d is not None, e):
        crew = (d or {}).get("crew", [])
        if check("list-crew-bulk has crew rows", len(crew) > 0):
            row = crew[0]
            for k in ("id", "name", "ein", "postcode", "rating", "groups", "inductions"):
                check(f"crew row carries '{k}'", k in row)
            check("crew rating is int", isinstance(row.get("rating"), int),
                  f"got {type(row.get('rating')).__name__}")
            check("crew groups is list", isinstance(row.get("groups"), list))
            check("crew inductions is dict", isinstance(row.get("inductions"), dict))

    # ── get-shifts-bulk  +  cross-check against get-booked-crew-bulk ───────────
    print("get-shifts-bulk.php / get-booked-crew-bulk.php")
    shifts_doc, e1 = get_json(ss, "/ajax/crew/get-shifts-bulk.php" + win)
    booked_doc, e2 = get_json(ss, "/ajax/crew/get-booked-crew-bulk.php" + win)
    check("get-shifts-bulk returns JSON", shifts_doc is not None, e1)
    check("get-booked-crew-bulk returns JSON", booked_doc is not None, e2)

    shifts = (shifts_doc or {}).get("shifts", [])
    if shifts:
        check("every shift carries a 'status' field",
              all("status" in s for s in shifts),
              "a shift is missing 'status' (the field-drop regression)")
        check("shift status is int (not '5' string)",
              all(isinstance(s.get("status"), int) for s in shifts),
              "a status isn't an int \u2014 missing (int) cast in PHP?")
        check("shift status within valid enum",
              all(s.get("status") in VALID_STATUS for s in shifts),
              f"a status is outside {sorted(VALID_STATUS)}")
        check("at least one confirmed (status==5) shift in window",
              any(s.get("status") == 5 for s in shifts),
              "no confirmed shifts \u2014 if you expect some, status may be zeroed")
    else:
        print("  (no shifts in window \u2014 status checks skipped)")

    # Self-validating status contract, no manual fixtures required:
    #   get-booked-crew-bulk returns ONLY confirmed assignments (ccm.status=5).
    #   So in get-shifts-bulk, the status==5 shifts must be exactly that set:
    #     * a status==5 shift NOT in the booked set  -> a non-confirmed shift was
    #       mislabeled 5 (the 'constant 5 / everything red' regression).
    #     * a booked assignment NOT showing status==5 -> status missing/zeroed.
    #   (call_id, user_id) is the join key, shared by both payloads.
    #   Note: relies on non-confirmed shifts existing in the window to exercise
    #   the constant-5 guard; with confirmed-only data it passes vacuously.
    if shifts and booked_doc is not None:
        booked = {(a.get("call_id"), a.get("user_id"))
                  for a in (booked_doc or {}).get("assignments", [])}
        confirmed5 = [s for s in shifts if s.get("status") == 5]
        nonconf_as5 = [s for s in confirmed5
                       if (s.get("call_id"), s.get("user_id")) not in booked]
        confirmed5_keys = {(s.get("call_id"), s.get("user_id")) for s in confirmed5}
        missing_conf = booked - confirmed5_keys

        check("no non-confirmed shift mislabeled status==5 (constant-5 guard)",
              len(nonconf_as5) == 0,
              f"{len(nonconf_as5)} shift(s) read 5 but aren't confirmed in booked-crew")
        check("every booked-confirmed assignment reads status==5 (missing-status guard)",
              len(missing_conf) == 0,
              f"{len(missing_conf)} confirmed assignment(s) not status==5 in shifts")

    # ── self endpoints (shape only \u2014 admin's own data may legitimately be empty) ──
    print("my-inductions.php / my-shifts.php")
    d, e = get_json(ss, "/ajax/crew/my-inductions.php")
    if check("my-inductions returns JSON", d is not None, e):
        check("my-inductions has 'inductions' dict",
              isinstance((d or {}).get("inductions"), dict))
    d, e = get_json(ss, "/ajax/crew/my-shifts.php" + win)
    if check("my-shifts returns JSON", d is not None, e):
        check("my-shifts has 'shifts' list", isinstance((d or {}).get("shifts"), list))
        check("my-shifts has 'unavails' list", isinstance((d or {}).get("unavails"), list))

    # ── summary ────────────────────────────────────────────────────────────────
    print("\n" + "=" * 50)
    print(f"PASS {len(_PASS)}   FAIL {len(_FAIL)}")
    if _FAIL:
        print("FAILED: " + ", ".join(_FAIL))
        sys.exit(1)
    print("All endpoint contract checks passed.")
    sys.exit(0)


if __name__ == "__main__":
    main()
