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

Run (the venv interpreter — the system python3 has none of the deps):
    venv/bin/python3 smoke_endpoints.py

Credentials (admin recommended, so the bulk endpoints are exercised):
    SS_USER / SS_PASS environment variables, else config.json username/password.

Exit code 0 on all-pass, 1 on any failure, 2 on setup/login error (CI-friendly).
"""

import os
import sys
from datetime import datetime, timedelta

try:
    import requests
    from app import (create_ss_session, BASE_URL, load_config,
                     GOAT_RECRUITMENT_KEY, INDUCTION_SESSIONS_ADMIN_URL,
                     SESSION_ACTION_TIMEOUTS)
except Exception as e:  # pragma: no cover
    # Inside the guard on purpose: this file promises exit code 2 and a readable
    # line on a setup problem, not a traceback. A bare `import requests` above
    # the guard broke that promise the first time it was run (31 Aug 2026).
    print(f"FATAL: could not import dependencies: {e}")
    print("       Run it with the venv interpreter: venv/bin/python3 smoke_endpoints.py")
    sys.exit(2)

VALID_STATUS = {0, 1, 5, 6, 8}  # 5=confirmed,1=pending,6=declined,8=noshow,0=unset

# Sessions actions this harness is permitted to call. THIS IS A SAFETY GATE, not
# documentation: three of the endpoint's actions email real people —
# send_reminders and cancel_session mail every booked candidate, cancel_on_behalf
# mails one. On 31 Aug 2026 four presses of send_reminders put 302 emails into
# candidates' inboxes in nine minutes. post_sessions() asserts against this set
# at the call site so a future edit cannot quietly widen a smoke run into a
# mailing run by passing a different action string.
SESSIONS_READ_ONLY_ACTIONS = {"list", "roster", "no_suitable_list"}

# Only "list" is actually exercised below. roster and no_suitable_list return
# candidate names and email addresses; a failing assertion would print them into
# CI output, so they stay unused unless someone needs them and handles that.

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


def post_sessions(action, **payload):
    """POST a read-only action to the induction-sessions-admin edge function.

    Talks to the edge function directly with the service key — the same call
    app.py's /api/recruitment/sessions makes, minus the cohort gate, which needs
    a browser session this harness does not have. What is worth asserting here
    is the CONTRACT the Sessions UI renders from.
    """
    if action not in SESSIONS_READ_ONLY_ACTIONS:
        raise AssertionError(
            "smoke tests may only call read-only session actions; refusing "
            f"{action!r}. send_reminders and cancel_session email every booked "
            "candidate."
        )
    body = dict(payload, action=action)
    try:
        r = requests.post(
            INDUCTION_SESSIONS_ADMIN_URL,
            headers={"X-Goat-Service-Key": GOAT_RECRUITMENT_KEY},
            json=body,
            timeout=SESSION_ACTION_TIMEOUTS.get(action, 20),
        )
    except Exception as e:
        return None, f"request error: {e}"
    text = (r.text or "").strip()
    if not text:
        return None, f"HTTP {r.status_code}, EMPTY body"
    try:
        return r.json(), None
    except Exception as e:
        return None, f"HTTP {r.status_code}, JSON parse failed: {e}; starts {text[:60]!r}"


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

    # ── ops-times-outstanding (the Times outstanding lane) ─────────────────────
    # A BACKWARD window: this is the only lane that asks what has not been
    # finished off, so the checks run over the last 14 days rather than `win`.
    print("ops-times-outstanding.php")
    back = (today - timedelta(days=14)).strftime("%Y-%m-%d")
    twin = f"?start={back}&end={start}"
    times_doc, e = get_json(ss, "/ajax/crew/ops-times-outstanding.php" + twin)
    tcalls = []
    if check("ops-times-outstanding returns JSON", times_doc is not None, e) and times_doc:
        check("no error body (Ops gate accepts this session)",
              "error" not in times_doc, times_doc.get("error", ""))
        tcalls = times_doc.get("calls", [])
        counts = times_doc.get("counts") or {}
        check("has 'calls' list", isinstance(tcalls, list))
        check("has counts.total", isinstance(counts.get("total"), int))
        # The identities the lane's donuts depend on: every row lands in exactly
        # one status and exactly one age bucket. A drift here shows up as a donut
        # whose centre disagrees with its own segments.
        check("counts.total == len(calls)", counts.get("total") == len(tcalls),
              f"{counts.get('total')} vs {len(tcalls)}")
        check("sum(status) == total",
              sum((counts.get("status") or {}).values()) == counts.get("total"))
        check("sum(age) == total",
              sum((counts.get("age") or {}).values()) == counts.get("total"))
        now_str = datetime.now().strftime("%Y-%m-%d %H:%M")
        if tcalls:
            for k in ("call_id", "booking_id", "booking_name", "call_name", "venue",
                      "start", "end", "confirmed", "needing", "submitted",
                      "status", "age_bucket", "bosses", "sibling_times_pending"):
                check(f"times row carries '{k}'", all(k in c for c in tcalls))
            check("status is one of the three segments",
                  all(c.get("status") in ("awaiting_review", "not_submitted", "no_boss")
                      for c in tcalls))
            check("age_bucket uses the shared buckets",
                  all(c.get("age_bucket") in ("under24", "from24to72", "over72")
                      for c in tcalls))
            # Every row is work: at least one confirmed person with no times by
            # either route, and never more of them than are on the call.
            check("needing >= 1 on every row",
                  all(int(c.get("needing") or 0) >= 1 for c in tcalls))
            check("needing <= confirmed on every row",
                  all(int(c.get("needing") or 0) <= int(c.get("confirmed") or 0)
                      for c in tcalls))
            check("every row has finished", all((c.get("end") or "") <= now_str for c in tcalls),
                  "a call that has not ended yet is being asked for times")
            # no_boss is the segment Ops act on differently — it must mean exactly
            # what it says, in both directions.
            check("no_boss rows carry no boss, and only those",
                  all((len(c.get("bosses") or []) == 0) == (c.get("status") == "no_boss")
                      for c in tcalls if c.get("status") != "awaiting_review"),
                  "a bossless call is not flagged no_boss, or a flagged one has a boss")
            bosses = [b for c in tcalls for b in (c.get("bosses") or [])]
            if bosses:
                check("every boss carries a name",
                      all((b.get("name") or "").strip() for b in bosses))
                check("every boss carries how it was resolved",
                      all(b.get("how") in ("direct", "container", "supervisory") for b in bosses))
        else:
            print("  (nothing outstanding in the last 14 days — row checks skipped)")

    # ── my-call-times gate, widened for the Ops review surface ────────────────
    # Slice 4 refused anyone who was not the crew boss of the call. The times
    # grid opens it as Ops, so a read-all session must now get the roster back —
    # and this admin session is almost certainly not the boss of that call.
    if tcalls:
        cid = tcalls[0].get("call_id")
        print(f"my-call-times.php (Ops gate, callID={cid})")
        d, e = get_json(ss, f"/ajax/crew/my-call-times.php?callID={cid}")
        if check("my-call-times returns JSON", d is not None, e) and d:
            check("Ops are not refused (widened gate)", "error" not in d, d.get("error", ""))
            check("my-call-times has 'crew' list", isinstance(d.get("crew"), list))
            check("my-call-times has 'call' object", isinstance(d.get("call"), dict))

    # ── induction sessions (READ-ONLY) ────────────────────────────────────────
    # No mutating action is reachable from here; see SESSIONS_READ_ONLY_ACTIONS.
    print("induction-sessions-admin (read-only)")
    if not GOAT_RECRUITMENT_KEY:
        print("  (GOAT_RECRUITMENT_KEY not configured \u2014 sessions checks skipped)")
    else:
        d, e = post_sessions("list")
        if check("sessions list returns JSON", d is not None, e) and d:
            check("sessions list reports ok",
                  d.get("ok") is True,
                  f"ok={d.get('ok')!r} error={d.get('error')!r}")
            sessions = d.get("sessions")
            check("sessions is a list", isinstance(sessions, list),
                  f"got {type(sessions).__name__}")
            check("intakes is a list", isinstance(d.get("intakes"), list),
                  f"got {type(d.get('intakes')).__name__}")

            # Everything below reads fields off a row. An assertion with nothing
            # to inspect is not a pass, it is a silent skip wearing a tick, so
            # the emptiness is itself a failure rather than a reason to move on.
            if check("sessions list is non-empty",
                     isinstance(sessions, list) and len(sessions) > 0,
                     "no rows returned \u2014 the field checks below would pass on nothing"):
                row = sessions[0]
                for k in ("id", "label", "intake_label", "state", "mode",
                          "booked", "capacity", "waitlist_count", "starts_at"):
                    check(f"session row carries '{k}'", k in row)
                check("booked is int", isinstance(row.get("booked"), int),
                      f"got {type(row.get('booked')).__name__}")
                check("capacity is int", isinstance(row.get("capacity"), int),
                      f"got {type(row.get('capacity')).__name__}")
                check("waitlist_count is int", isinstance(row.get("waitlist_count"), int),
                      f"got {type(row.get('waitlist_count')).__name__}")
                states = {s.get("state") for s in sessions}
                check("every state is one of draft/open/closed/cancelled",
                      states <= {"draft", "open", "closed", "cancelled"},
                      f"unexpected: {sorted(states - {'draft','open','closed','cancelled'})}")

                # The reason 5.23.1 exists. Reminders send one email at a time at
                # ~0.55s each, so the wall clock scales with the booked count and
                # the proxy timeout has to stay ahead of the biggest session. Raise
                # the session capacity cap without raising the timeout and this is
                # the check that says so, instead of ops seeing a false failure.
                SECS_PER_EMAIL = 0.6           # 0.53-0.59 measured 31 Aug 2026
                budget = SESSION_ACTION_TIMEOUTS.get("send_reminders", 20)
                biggest = max(int(s.get("booked") or 0) for s in sessions)
                check("largest session still fits the send_reminders timeout",
                      biggest * SECS_PER_EMAIL < budget,
                      f"{biggest} booked x {SECS_PER_EMAIL}s = "
                      f"{biggest * SECS_PER_EMAIL:.0f}s against a {budget}s budget")
                check("the two emailing actions both carry a raised timeout",
                      SESSION_ACTION_TIMEOUTS.get("send_reminders", 0) > 20
                      and SESSION_ACTION_TIMEOUTS.get("cancel_session", 0) > 20,
                      f"got {SESSION_ACTION_TIMEOUTS!r}")

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
